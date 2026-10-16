"""Absolute dual-clock bounds for one offline owned child; not runtime policy.

Deadlines are serialized once and never renewed by polling or worker startup.
Output files preserve bytes; they are not accepted products until both clocks
pass the final checkpoint. A host suspend/jump can therefore make a completed
child unassessable rather than silently extending its research budget.
"""
from __future__ import annotations

from dataclasses import dataclass
import copy
import math
import os
from pathlib import Path
import subprocess
import threading
import time

PROTOCOL = "curator_research_absolute_deadline_v1"
MAX_POLL_SECONDS = .25
_SERIAL_LAUNCH = threading.Lock()


def _finite(value):
    return type(value) in (int, float) and math.isfinite(value)


class ResearchDeadlineError(RuntimeError):
    def __init__(self, facts):
        self.facts = copy.deepcopy(facts)
        super().__init__(facts["reason"])


class OwnedChildNotReaped(ResearchDeadlineError):
    """The caller must persist facts and stop the serial study, not launch more."""


@dataclass(frozen=True)
class _Limits:
    started_at_monotonic: float
    started_at_unix: float
    deadline_monotonic: float
    deadline_unix: float
    budget_seconds: float


class AbsoluteResearchDeadline:
    def __init__(self, limits, *, monotonic=time.monotonic, utc=time.time):
        self._limits = limits
        self.monotonic, self.utc = monotonic, utc
        self._last_mono = limits.started_at_monotonic
        self._last_utc = limits.started_at_unix
        self._failure = None

    @classmethod
    def start(cls, seconds, *, parent=None, monotonic=time.monotonic, utc=time.time):
        if not _finite(seconds) or seconds <= 0:
            raise ValueError("FINITE_POSITIVE_RESEARCH_BUDGET_REQUIRED")
        if parent is not None:
            parent.checkpoint("before_child_deadline_creation")
        mono, wall = monotonic(), utc()
        if not all(_finite(value) for value in (mono, wall)):
            raise ValueError("FINITE_RESEARCH_CLOCKS_REQUIRED")
        mono_end, wall_end = mono + seconds, wall + seconds
        if not all(_finite(value) for value in (mono_end, wall_end)):
            raise ValueError("FINITE_ABSOLUTE_RESEARCH_DEADLINES_REQUIRED")
        if parent is not None:
            mono_end = min(mono_end, parent._limits.deadline_monotonic)
            wall_end = min(wall_end, parent._limits.deadline_unix)
        deadline = cls(_Limits(mono, wall, mono_end, wall_end, float(seconds)), monotonic=monotonic, utc=utc)
        deadline.checkpoint("created")
        return deadline

    @classmethod
    def from_record(cls, record, *, monotonic=time.monotonic, utc=time.time):
        names = {"started_at_monotonic", "started_at_unix", "deadline_monotonic", "deadline_unix", "budget_seconds"}
        if not isinstance(record, dict) or set(record) != names | {"protocol"} or record["protocol"] != PROTOCOL:
            raise ValueError("EXACT_ABSOLUTE_RESEARCH_DEADLINE_REQUIRED")
        if not all(_finite(record[name]) for name in names) or record["budget_seconds"] <= 0:
            raise ValueError("FINITE_POSITIVE_RESEARCH_DEADLINE_REQUIRED")
        for suffix in ("monotonic", "unix"):
            elapsed = record["deadline_" + suffix] - record["started_at_" + suffix]
            if not 0 < elapsed <= record["budget_seconds"] + 1e-7:
                raise ValueError("BOUNDED_ORIGINAL_ABSOLUTE_DEADLINE_REQUIRED")
        result = cls(_Limits(**{name: float(record[name]) for name in names}), monotonic=monotonic, utc=utc)
        result.checkpoint("worker_original_deadline_admission")
        return result

    def record(self):
        return {"protocol": PROTOCOL, **self._limits.__dict__}

    def checkpoint(self, stage):
        """Use before/after calculation AND immediately before publishing bytes."""
        if self._failure is not None:
            raise ResearchDeadlineError({**self._failure, "stage": str(stage),
                "first_failure_stage": self._failure["stage"], "failure_latched": True})
        mono, wall = self.monotonic(), self.utc()
        valid = all(_finite(value) for value in (mono, wall))
        mono_regressed = valid and mono < self._last_mono
        utc_regressed = valid and wall < self._last_utc
        mono_expired = valid and mono >= self._limits.deadline_monotonic
        utc_expired = valid and wall >= self._limits.deadline_unix
        reason = ("RESEARCH_CLOCK_INVALID" if not valid else
            "RESEARCH_CLOCK_REGRESSED" if mono_regressed or utc_regressed else
            "RESEARCH_ABSOLUTE_DEADLINE_EXPIRED" if mono_expired or utc_expired else None)
        facts = {"protocol": PROTOCOL, "stage": str(stage), "limits": self.record(),
            "observed_monotonic": mono if valid else None, "observed_unix": wall if valid else None,
            "elapsed_monotonic_seconds": mono - self._limits.started_at_monotonic if valid else None,
            "elapsed_utc_seconds": wall - self._limits.started_at_unix if valid else None,
            "monotonic_expired": bool(mono_expired), "utc_expired": bool(utc_expired),
            "monotonic_regressed": bool(mono_regressed), "utc_regressed": bool(utc_regressed),
            "deadline_renewed": False, "publication_allowed": reason is None,
            "reason": reason, "research_only": True}
        if valid:
            self._last_mono, self._last_utc = mono, wall
        if reason is not None:
            self._failure = copy.deepcopy(facts)
            raise ResearchDeadlineError(facts)
        return facts


def _cleanup_owned_child(process, *, monotonic, sleep, terminate_grace, kill_grace):
    """Bounded polling only; never communicate(), wait(full cap), or kill a PID tree."""
    facts = {"terminate_sent": False, "kill_sent": False, "owned_child_reaped": False,
        "cleanup_errors": [], "cleanup_poll_count": 0}

    def polled_exit(grace):
        started = monotonic()
        # The count also bounds cleanup if a fake/broken clock stops advancing.
        for _ in range(math.ceil(grace / .05) + 2):
            facts["cleanup_poll_count"] += 1
            if process.poll() is not None:
                return True
            now = monotonic()
            if not _finite(now) or now < started or now - started >= grace:
                return False
            sleep(min(.05, max(0, grace - (now - started))))
        return False

    if process.poll() is not None:
        facts["owned_child_reaped"] = True
        return facts
    for operation, grace in (("terminate", terminate_grace), ("kill", kill_grace)):
        try:
            getattr(process, operation)()
            facts[operation + "_sent"] = True
        except (OSError, ProcessLookupError) as error:
            facts["cleanup_errors"].append(type(error).__name__)
        if polled_exit(grace):
            facts["owned_child_reaped"] = True
            break
    return facts


def wait_owned_child(process, deadline, *, poll_interval=.1, terminate_grace_seconds=.5,
                     kill_grace_seconds=.5, sleep=time.sleep):
    """Wait for this supplied process handle, rejecting any late completion.

    Parent stdout/stderr/stdin must be file-backed, not PIPEs. A timed-out or
    regressed clock returns durable technical facts; an unreaped owned child
    raises so a serial caller cannot treat cleanup as permission to continue.
    """
    if not _finite(poll_interval) or not 0 < poll_interval <= MAX_POLL_SECONDS:
        raise ValueError("SHORT_BOUNDED_RESEARCH_POLL_REQUIRED")
    if any(not _finite(value) or not 0 <= value <= 2 for value in (terminate_grace_seconds, kill_grace_seconds)):
        raise ValueError("BOUNDED_OWNED_CHILD_CLEANUP_REQUIRED")
    if any(getattr(process, field, None) is not None for field in ("stdin", "stdout", "stderr")):
        raise ValueError("FILE_BACKED_CHILD_STREAMS_REQUIRED")
    try:
        while True:
            deadline.checkpoint("parent_before_owned_child_poll")
            exit_code = process.poll()
            observed = deadline.checkpoint("parent_after_owned_child_poll")
            if exit_code is not None:
                return {**observed, "status": "completed" if exit_code == 0 else "technical_child_exit",
                    "exit_code": exit_code, "timeout": False, "owned_child_reaped": True,
                    "owned_child_pid": getattr(process, "pid", None), "terminate_sent": False, "kill_sent": False}
            limits = deadline._limits
            remaining = min(limits.deadline_monotonic - observed["observed_monotonic"],
                            limits.deadline_unix - observed["observed_unix"])
            sleep(min(poll_interval, remaining))
            # Loop checks before even polling. A long host suspend cannot turn
            # a now-completed child into an in-budget accepted result.
    except ResearchDeadlineError as failure:
        cleanup = _cleanup_owned_child(process, monotonic=deadline.monotonic, sleep=sleep,
            terminate_grace=terminate_grace_seconds, kill_grace=kill_grace_seconds)
        facts = {**failure.facts, **cleanup, "status": "technical_deadline_terminal",
            "timeout": bool(failure.facts["monotonic_expired"] or failure.facts["utc_expired"]),
            "exit_code": getattr(process, "returncode", None), "owned_child_pid": getattr(process, "pid", None),
            "publication_allowed": False}
        if not cleanup["owned_child_reaped"]:
            raise OwnedChildNotReaped({**facts, "reason": "OWNED_CHILD_NOT_REAPED_SERIAL_STUDY_MUST_STOP",
                "abort_serial_study": True, "serial_progress_allowed": False}) from failure
        return facts
    except BaseException as failure:
        cleanup = _cleanup_owned_child(process, monotonic=deadline.monotonic, sleep=sleep,
            terminate_grace=terminate_grace_seconds, kill_grace=kill_grace_seconds)
        if not cleanup["owned_child_reaped"]:
            raise OwnedChildNotReaped({"protocol": PROTOCOL, "limits": deadline.record(), **cleanup,
                "reason": "OWNED_CHILD_NOT_REAPED_SERIAL_STUDY_MUST_STOP", "publication_allowed": False,
                "abort_serial_study": True, "serial_progress_allowed": False}) from failure
        raise


def run_hidden_serial(command, *, input_path, stdout_path, stderr_path, deadline,
                      cwd=None, env=None, poll_interval=.1):
    """Launch exactly one no-shell child with durable file-backed streams."""
    if not isinstance(command, (list, tuple)) or not command or any(not isinstance(item, str) for item in command):
        raise ValueError("EXPLICIT_NO_SHELL_CHILD_COMMAND_REQUIRED")
    if len({Path(path).resolve() for path in (input_path, stdout_path, stderr_path)}) != 3:
        raise ValueError("DISTINCT_ORIGINAL_CHILD_STREAM_PATHS_REQUIRED")
    if not _finite(poll_interval) or not 0 < poll_interval <= MAX_POLL_SECONDS:
        raise ValueError("SHORT_BOUNDED_RESEARCH_POLL_REQUIRED")
    if not _SERIAL_LAUNCH.acquire(blocking=False):
        raise ValueError("ONE_SERIAL_OWNED_RESEARCH_CHILD_REQUIRED")
    try:
        deadline.checkpoint("before_owned_child_launch")
        flags = subprocess.CREATE_NO_WINDOW | subprocess.BELOW_NORMAL_PRIORITY_CLASS if os.name == "nt" else 0
        with Path(input_path).open("rb") as source, Path(stdout_path).open("xb") as output, Path(stderr_path).open("xb") as error:
            process = subprocess.Popen(command, stdin=source, stdout=output, stderr=error,
                cwd=cwd, env=env, shell=False, creationflags=flags)
            return wait_owned_child(process, deadline, poll_interval=poll_interval)
    finally:
        _SERIAL_LAUNCH.release()
