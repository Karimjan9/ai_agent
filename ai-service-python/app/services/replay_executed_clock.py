"""Observes actual next-open iterations; never changes a strategy's evaluation clock."""

import hashlib

import pandas as pd

from app.services.research_program_tasks import canonical_hash, canonical_json

PROTOCOL = "replay_executed_clock_v1"
INDEX_DOMAIN = b"replay-executed-clock-v1:indices\n"
SCHEDULE_DOMAIN = b"replay-executed-clock-v1:schedule\n"


def stamp(value) -> str:
    timestamp = pd.Timestamp(value)
    if pd.isna(timestamp) or timestamp.tzinfo is None:
        raise ValueError("EXECUTED_CLOCK_UTC_REQUIRED")
    return timestamp.tz_convert("UTC").isoformat()


class ReplayExecutedClock:
    def __init__(self, *, owner: str, input_rows: int, evaluation_offset: int,
                 timeframe: str, duration_seconds: int, dataset_hash, execution_hash,
                 policy: dict | None, probe: dict | None):
        self.identity = {"protocol": PROTOCOL, "owner": owner,
            "semantics": "previous_closed_candle_next_open_v1",
            "index_basis": "evaluated_frame_zero_based_v1", "input_rows": input_rows,
            "evaluation_offset_rows": evaluation_offset, "execution_timeframe": timeframe,
            "duration_seconds": duration_seconds, "dataset_hash": dataset_hash,
            "execution_hash": execution_hash, "policy_hash": canonical_hash(policy) if isinstance(policy, dict) else None,
            "probe_contract_hash": probe.get("contract_hash") if isinstance(probe, dict) else None}
        self.indices = hashlib.sha256(INDEX_DOMAIN)
        self.schedule = hashlib.sha256(SCHEDULE_DOMAIN)
        self.count = 0
        self.first = self.last = None
        self.signal_start = self.signal_end = None
        self.execution_start = self.execution_end = None
        self.valid = True

    def observe(self, index: int, signal_time, execution_time) -> None:
        relative = index - self.identity["evaluation_offset_rows"]
        try:
            signal = stamp(signal_time)
            execution = stamp(execution_time)
            if (type(index) is not int or relative < 1 or index >= self.identity["input_rows"]
                    or (self.last is not None and relative != self.last + 1)
                    or pd.Timestamp(signal) + pd.Timedelta(seconds=self.identity["duration_seconds"]) > pd.Timestamp(execution)
                    or (self.execution_end is not None and pd.Timestamp(execution) <= pd.Timestamp(self.execution_end))):
                self.valid = False
            self.indices.update(f"{relative}\n".encode("ascii"))
            self.schedule.update((canonical_json([relative, signal, execution]) + "\n").encode("utf-8"))
            if self.first is None:
                self.first, self.signal_start, self.execution_start = relative, signal, execution
            self.last, self.signal_end, self.execution_end = relative, signal, execution
        except (TypeError, ValueError, OverflowError):
            self.valid = False
        self.count += 1

    def finish(self) -> dict:
        body = {**self.identity, "complete": self.valid and self.count > 0,
            "decision_rows": self.count, "first_evaluation_index": self.first,
            "last_evaluation_index": self.last, "signal_start": self.signal_start,
            "signal_end": self.signal_end, "execution_start": self.execution_start,
            "execution_end": self.execution_end, "index_set_hash": self.indices.hexdigest(),
            "schedule_hash": self.schedule.hexdigest(), "promotion_evidence": False}
        return {**body, "receipt_hash": canonical_hash(body), "receipt_json": canonical_json(body)}
