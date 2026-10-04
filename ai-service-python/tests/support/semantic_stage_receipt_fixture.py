"""Deterministic wiring fixture, not market/authority evidence.

PHP integration tests consume receipts emitted by the real Python source
loader, closed-MTF compiler and semantic receipt owner. The controlled location
opportunity is synthetic; neither this fixture nor its hash earns credit.
"""
import hashlib
import json
import argparse
from pathlib import Path
import sys
import tempfile
import base64

import numpy as np
import pandas as pd

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
if not (Path(__file__).resolve().parents[2] / "app").is_dir():
    sys.path.insert(0, str(Path.cwd()))  # Isolated test staging, never a runtime import override.

from app.schemas import SimpleBacktestRequest, ExecutionConfig
from app.services import backtester, research_release
from app.services.volume_features import apply_volume_policy
from app.services.composition_runtime import validate_composition_runtime_contract


def produce(control_limit: float = 1.0, candidate_limit: float = 1.1, blinded_limit: float = 0.9,
            full_runtime_source_hash: str | None = None, python_source_hash: str | None = None,
            start_date: str | None = None, include_sources: bool = False,
            confirmed_volume_trait: bool = False, confirmation_source: bool = False,
            arm_inputs: dict | None = None) -> dict:
    with tempfile.TemporaryDirectory(prefix="stage-receipt-fixture-") as directory:
        streams = {}
        for timeframe, start, rows, frequency in (
            ("M5", "2025-12-22T13:00", 230, "5min"),
            ("H4", "2025-12-19", 40, "4h"),
            ("H1", "2025-12-19", 150, "h"),
            ("M15", "2025-12-22", 150, "15min"),
        ):
            if start_date is not None:
                anchor = pd.Timestamp(start_date, tz="UTC")
                start = anchor + (pd.Timedelta(hours=13) if timeframe == "M5" else
                                  pd.Timedelta(0) if timeframe == "M15" else -pd.Timedelta(days=3))
            prices = 2000.0 + np.arange(rows) * 0.1
            path = Path(directory) / (timeframe + ".csv")
            volumes = np.full(rows, 100.0)
            if confirmed_volume_trait and timeframe == "M5":
                volumes[210] = 1.0
            pd.DataFrame({"time": pd.date_range(start, periods=rows, freq=frequency, tz="UTC"),
                "open": prices, "high": prices + 1.0, "low": prices - 1.0,
                "close": prices, "volume": volumes, "volume_available": True}).to_csv(path, index=False)
            streams[timeframe] = {"path": str(path), "sha256": hashlib.sha256(path.read_bytes()).hexdigest()}
        bundle_hash = hashlib.sha256(json.dumps({key: record["sha256"] for key, record in streams.items()},
            sort_keys=True, separators=(",", ":")).encode()).hexdigest() if include_sources else "b" * 64
        baseline = {"differential_pair_replay_enabled": False, "location_tolerance_atr": control_limit,
            **({"volume_lane": "none"} if confirmed_volume_trait else {})}
        if confirmation_source:
            original = Path.cwd().parent / "backend-laravel/tests/Support/academy_confirmation_source.json"
            baseline = json.loads(original.read_text(encoding="utf-8"))["parameters"]
        request = SimpleBacktestRequest(timeframe="M5", dataset_path=streams["M5"]["path"],
            replay_dataset_hash=bundle_hash, execution_contract={"execution_hash": "e" * 64},
            parameters=baseline,
            strategy="confirmation_entry_mtf_v1" if confirmation_source else "ema_rsi_v1",
            base_strategy="confirmation_entry_mtf_v1" if confirmation_source else None,
            mtf_dataset_paths={key: record["path"] for key, record in streams.items() if key != "M5"},
            mtf_snapshot_manifest={"protocol": "closed_h4_h1_m15_m5_snapshot_v1",
                "validation_bundle_protocol": "agent_owned_mtf_foundation_bundle_v1", "bundle_hash": bundle_hash,
                "streams": streams}, mtf_pilot={"enabled": True, "mode": "m15_only",
                    "activation_status": "execution_stream_bound", "execution_timeframe": "M5"})
        if arm_inputs is not None and "execution_parameters" in arm_inputs:
            request.execution = ExecutionConfig.model_validate(arm_inputs["execution_parameters"])
        actual_execution = backtester.execution_contract_metadata(request)["execution_hash"]
        request.execution_contract = {"protocol": "canonical_market_execution_v1", "version": "canonical_market_execution_v1",
            "parameters": request.execution.model_dump(), "execution_hash": actual_execution}
        health = research_release.health_receipt()
        if python_source_hash is not None and python_source_hash != health["source_hash"]:
            raise ValueError("FIXTURE_SUPPLIED_PYTHON_SOURCE_MISMATCH")
        aggregate = full_runtime_source_hash if full_runtime_source_hash is not None else "3" * 64
        if len(aggregate) != 64 or any(character not in "0123456789abcdef" for character in aggregate):
            raise ValueError("FIXTURE_FULL_RUNTIME_SOURCE_HASH_REQUIRED")
        aggregate_origin = "supplied_authorized_claim" if full_runtime_source_hash is not None else "synthetic_test_descriptor_not_runtime_proof"
        release_identity = {"protocol": "prospective_research_release_v1", "source_hash": aggregate,
            "python_source_hash": health["source_hash"], "dataset_hash": request.replay_dataset_hash,
            "agent_execution_hashes": {"1": actual_execution, "2": actual_execution, "3": actual_execution}}
        request.research_release = {**release_identity,
            "release_hash": hashlib.sha256(research_release._release_json(release_identity).encode()).hexdigest(),
            "promotion_evidence": False}
        # No attestation mock or binding rewrite: this calls the same sealed
        # request/actual source/boot checker as a prospective replay.
        features = backtester.prepare_feature_snapshot(request, backtester._load_simple_candles(request))
        result = {"synthetic_fixture": True, "market_replay_proven": False, "promotion_evidence": False,
            "source_date": start_date or "2025-12-22",
            "full_runtime_source_origin": aggregate_origin, "research_release": request.research_release,
            "control_parameters": request.parameters,
            "candidate_parameters": {**request.parameters, **({"volume_lane": "low_volume_risk_firewall"} if confirmed_volume_trait else
                {"location_tolerance_atr": candidate_limit})},
            "blinded_parameters": {**request.parameters, "location_tolerance_atr": blinded_limit}}
        if include_sources:
            result["source_request"] = request.model_dump(mode="json")
            result["source_files_base64"] = {key: base64.b64encode(Path(record["path"]).read_bytes()).decode()
                for key, record in streams.items()}
        for arm, limit in (("control", control_limit), ("candidate", candidate_limit), ("blinded", blinded_limit)):
            frame = features.frame.copy()
            frame.attrs["data_quality"] = features.data_quality
            frame["signal"] = "BUY" if confirmed_volume_trait else "WAIT"
            frame["entry_contract_direction"] = "BUY"
            frame["entry_context_valid"] = True
            frame["entry_location_distance_atr"] = np.where(np.arange(len(frame)) % 2 == 0,
                min(control_limit, candidate_limit, blinded_limit) / 2, (control_limit + candidate_limit) / 2)
            frame["entry_location_valid"] = frame["entry_location_distance_atr"] <= limit
            frame["entry_setup_detected"] = frame["entry_location_valid"]
            frame["entry_confirmation_valid"] = frame["entry_setup_detected"]
            frame["entry_trigger_valid"] = frame["entry_confirmation_valid"]
            parameters = result[arm + "_parameters"]
            if confirmed_volume_trait:
                frame = apply_volume_policy(frame, parameters, request.strategy)
            arm_request = request.model_copy(update={"parameters": parameters})
            if arm_inputs is not None and arm in arm_inputs:
                owner = arm_inputs[arm]
                arm_request = SimpleBacktestRequest.model_validate(owner["source_request"]) if "source_request" in owner else request.model_copy(update={"parameters": parameters,
                    "composition_runtime_contract": owner["composition_runtime_contract"], "instrument_research_assignment": owner["instrument_research_assignment"]})
                if (arm_request.policy_context or {}).get("authorized_research_transport") is not None:
                    research_release._research_data_roots = lambda: (Path(owner["test_data_root"]),)
                    research_release._research_transport_now = lambda: pd.Timestamp(owner["fixture_clock"])
                    signed = research_release.verify_research_transport(arm_request, owner["fixture_only_internal_key"])
                    if signed is None:
                        raise ValueError("FIXTURE_REAL_AUTHORIZED_SOURCE_REQUIRED")
                actual_features = backtester.prepare_feature_snapshot(arm_request, backtester._load_simple_candles(arm_request))
                frame.attrs["data_quality"] = actual_features.data_quality
                validate_composition_runtime_contract(arm_request.composition_runtime_contract,
                    base_strategy=arm_request.base_strategy, parameters=parameters, execution_timeframe="M5",
                    runtime_authority=backtester._composition_runtime_authority(arm_request))
            receipt = backtester._decision_identity_receipt(arm_request, frame, [], set())
            scope = frame.iloc[199:-1]
            result[arm] = {"value": limit, "total_trades": 0,
                "data_hash": receipt["bindings"]["dataset_identity"], "execution_hash": receipt["bindings"]["execution_hash"],
                "data_quality": {"decision_identity_receipt": receipt,
                    "research_release_receipt": frame.attrs["data_quality"]["research_release_receipt"]},
                "entry_contract_funnel": {"stage_counts": {"opportunity": len(scope),
                    **{stage: int(scope[column].sum()) for stage, column in (
                        ("location", "entry_location_valid"), ("setup", "entry_setup_detected"),
                        ("confirmation", "entry_confirmation_valid"), ("trigger", "entry_trigger_valid"))},
                    "entry_ready": 0}},
                **({"source_request": arm_request.model_dump(mode="json")} if include_sources else {}),
                **({"volume_fixture_execution": {"lane": parameters.get("volume_lane"),
                    "actionable_signals": int(frame["signal"].isin(["BUY", "SELL"]).sum()),
                    "vetoes": int(frame["volume_policy_rejection"].eq("low_volume_wait").sum())}}
                    if confirmed_volume_trait else {})}
        return result


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--control-limit", type=float, default=1.0)
    parser.add_argument("--candidate-limit", type=float, default=1.1)
    parser.add_argument("--blinded-limit", type=float, default=0.9)
    parser.add_argument("--full-runtime-source-hash")
    parser.add_argument("--python-source-hash")
    parser.add_argument("--start-date")
    parser.add_argument("--include-sources", action="store_true")
    parser.add_argument("--confirmed-volume-trait", action="store_true")
    parser.add_argument("--confirmation-source", action="store_true")
    parser.add_argument("--arm-inputs-stdin", action="store_true")
    args = parser.parse_args()
    print(json.dumps(produce(args.control_limit, args.candidate_limit, args.blinded_limit,
        args.full_runtime_source_hash, args.python_source_hash, args.start_date, args.include_sources, args.confirmed_volume_trait,
        args.confirmation_source, json.load(sys.stdin) if args.arm_inputs_stdin else None),
        sort_keys=True, separators=(",", ":"), ensure_ascii=False))
