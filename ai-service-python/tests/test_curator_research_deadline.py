"""Clock/process control fixtures; no research replay, external service or DB."""
import copy
import importlib.util
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

_SPEC = importlib.util.spec_from_file_location("curator_research_deadline",
    Path(__file__).resolve().parents[1] / "scripts/curator_research_deadline.py")
deadline_owner = importlib.util.module_from_spec(_SPEC)
sys.modules[_SPEC.name] = deadline_owner
_SPEC.loader.exec_module(deadline_owner)


class FakeClock:
    def __init__(self):
        self.mono, self.wall, self.sleeps = 100.0, 10000.0, []
        self.on_sleep = None

    def monotonic(self):
        return self.mono

    def utc(self):
        return self.wall

    def advance(self, seconds):
        self.mono += seconds
        self.wall += seconds

    def sleep(self, seconds):
        self.sleeps.append(seconds)
        self.advance(seconds)
        if self.on_sleep:
            self.on_sleep()

    def deadline(self, seconds=1, **kwargs):
        return deadline_owner.AbsoluteResearchDeadline.start(seconds,
            monotonic=self.monotonic, utc=self.utc, **kwargs)


class FakeProcess:
    stdin = stdout = stderr = None
    pid = 123456789  # Logging only; never passed to a PID/tree kill API.

    def __init__(self, clock, *, finish_after=None, resist=False):
        self.clock, self.finish_after, self.resist = clock, finish_after, resist
        self.started, self.returncode = clock.mono, None
        self.terminations = self.kills = self.polls = 0
        self.on_poll = None

    def poll(self):
        self.polls += 1
        if self.on_poll:
            action, self.on_poll = self.on_poll, None
            action()
        if self.returncode is None and self.finish_after is not None and self.clock.mono - self.started >= self.finish_after:
            self.returncode = 0
        return self.returncode

    def terminate(self):
        self.terminations += 1
        if not self.resist:
            self.returncode = -15

    def kill(self):
        self.kills += 1
        if not self.resist:
            self.returncode = -9


class CuratorResearchDeadlineTest(unittest.TestCase):
    def test_normal_completion_uses_short_polling_not_communicate(self):
        clock = FakeClock()
        process = FakeProcess(clock, finish_after=.3)
        result = deadline_owner.wait_owned_child(process, clock.deadline(), sleep=clock.sleep)
        self.assertEqual(result["status"], "completed")
        self.assertTrue(result["publication_allowed"])
        self.assertFalse(result["timeout"])
        self.assertTrue(all(0 < value <= .25 for value in clock.sleeps))
        self.assertEqual((process.terminations, process.kills), (0, 0))

    def test_forward_utc_jump_after_wake_rejects_finished_child(self):
        clock = FakeClock()
        process = FakeProcess(clock, finish_after=.05)
        clock.on_sleep = lambda: setattr(clock, "wall", clock.wall + 3600)
        result = deadline_owner.wait_owned_child(process, clock.deadline(), sleep=clock.sleep)
        self.assertEqual(result["status"], "technical_deadline_terminal")
        self.assertTrue(result["utc_expired"])
        self.assertFalse(result["monotonic_expired"])
        self.assertFalse(result["publication_allowed"])
        self.assertEqual(result["exit_code"], 0)  # Completion did not override expiry.
        self.assertFalse(result["terminate_sent"])

    def test_monotonic_expiry_alone_wins_over_completed_returncode(self):
        clock = FakeClock()
        deadline = clock.deadline()
        process = FakeProcess(clock, finish_after=0)
        clock.mono += 5
        result = deadline_owner.wait_owned_child(process, deadline, sleep=clock.sleep)
        self.assertTrue(result["monotonic_expired"])
        self.assertFalse(result["utc_expired"])
        self.assertTrue(result["timeout"])
        self.assertEqual(result["exit_code"], 0)

    def test_completion_that_crosses_deadline_during_poll_is_rejected(self):
        clock = FakeClock()
        process = FakeProcess(clock, finish_after=0)
        process.on_poll = lambda: clock.advance(2)
        result = deadline_owner.wait_owned_child(process, clock.deadline(), sleep=clock.sleep)
        self.assertEqual(result["stage"], "parent_after_owned_child_poll")
        self.assertFalse(result["publication_allowed"])
        self.assertTrue(result["timeout"])

    def test_utc_backward_jump_is_sticky_fail_closed_not_budget_extension(self):
        clock = FakeClock()
        deadline = clock.deadline()
        clock.wall -= 5
        with self.assertRaises(deadline_owner.ResearchDeadlineError) as caught:
            deadline.checkpoint("after_calculation")
        self.assertTrue(caught.exception.facts["utc_regressed"])
        clock.wall += 5
        with self.assertRaises(deadline_owner.ResearchDeadlineError) as again:
            deadline.checkpoint("before_publication")
        self.assertTrue(again.exception.facts["failure_latched"])
        self.assertFalse(again.exception.facts["publication_allowed"])

    def test_worker_original_limits_are_never_renewed_after_calculation(self):
        clock = FakeClock()
        original = clock.deadline().record()
        worker = deadline_owner.AbsoluteResearchDeadline.from_record(original,
            monotonic=clock.monotonic, utc=clock.utc)
        worker.checkpoint("before_calculation")
        clock.advance(2)
        with self.assertRaises(deadline_owner.ResearchDeadlineError) as caught:
            worker.checkpoint("after_calculation_before_product_publication")
        self.assertFalse(caught.exception.facts["deadline_renewed"])
        self.assertFalse(caught.exception.facts["publication_allowed"])
        self.assertEqual(worker.record(), original)
        with self.assertRaises(deadline_owner.ResearchDeadlineError):
            deadline_owner.AbsoluteResearchDeadline.from_record(original,
                monotonic=clock.monotonic, utc=clock.utc)

    def test_child_cap_clamps_to_original_parent_axes_without_renewal(self):
        clock = FakeClock()
        parent = clock.deadline(.5)
        parent_record = parent.record()
        clock.advance(.2)
        child = clock.deadline(1, parent=parent)
        self.assertEqual(child.record()["deadline_monotonic"], parent_record["deadline_monotonic"])
        self.assertEqual(child.record()["deadline_unix"], parent_record["deadline_unix"])
        clock.advance(.31)
        with self.assertRaises(deadline_owner.ResearchDeadlineError):
            child.checkpoint("late_child")
        self.assertEqual(parent.record(), parent_record)

    def test_timeout_terminates_only_supplied_handle_and_retains_facts(self):
        clock = FakeClock()
        process = FakeProcess(clock)
        result = deadline_owner.wait_owned_child(process, clock.deadline(.2), sleep=clock.sleep)
        self.assertTrue(result["timeout"])
        self.assertTrue(result["terminate_sent"])
        self.assertTrue(result["owned_child_reaped"])
        self.assertEqual((process.terminations, process.kills), (1, 0))
        self.assertEqual(json.loads(json.dumps(result))["limits"]["budget_seconds"], .2)

    def test_unreaped_child_blocks_serial_progress_with_bounded_cleanup(self):
        clock = FakeClock()
        process = FakeProcess(clock, resist=True)
        deadline = clock.deadline(.2)
        clock.advance(.3)
        with self.assertRaises(deadline_owner.OwnedChildNotReaped) as caught:
            deadline_owner.wait_owned_child(process, deadline, sleep=clock.sleep,
                terminate_grace_seconds=.1, kill_grace_seconds=.1)
        self.assertEqual((process.terminations, process.kills), (1, 1))
        self.assertFalse(caught.exception.facts["owned_child_reaped"])
        self.assertLessEqual(caught.exception.facts["cleanup_poll_count"], 8)

    def test_frozen_cleanup_clock_cannot_create_unbounded_wait(self):
        clock = FakeClock()
        process = FakeProcess(clock, resist=True)
        deadline = clock.deadline(.2)
        clock.advance(.3)
        with self.assertRaises(deadline_owner.OwnedChildNotReaped) as caught:
            deadline_owner.wait_owned_child(process, deadline, sleep=lambda seconds: None,
                terminate_grace_seconds=.1, kill_grace_seconds=.1)
        self.assertLessEqual(caught.exception.facts["cleanup_poll_count"], 8)
        self.assertEqual((process.terminations, process.kills), (1, 1))

    def test_unreaped_exception_is_a_durable_serial_abort_not_another_arm_timeout(self):
        clock = FakeClock()
        process = FakeProcess(clock, resist=True)
        deadline = clock.deadline(.1)
        clock.advance(.2)
        with self.assertRaises(deadline_owner.ResearchDeadlineError) as caught:
            deadline_owner.wait_owned_child(process, deadline, sleep=clock.sleep,
                terminate_grace_seconds=0, kill_grace_seconds=0)
        self.assertIsInstance(caught.exception, deadline_owner.OwnedChildNotReaped)
        facts = json.loads(json.dumps(caught.exception.facts))
        self.assertTrue(facts["abort_serial_study"])
        self.assertFalse(facts["serial_progress_allowed"])
        self.assertFalse(facts["owned_child_reaped"])
        self.assertFalse(facts["publication_allowed"])
        self.assertFalse(facts["deadline_renewed"])

    def test_backwards_parent_utc_is_technical_refusal_not_success_or_budget_retry(self):
        clock = FakeClock()
        process = FakeProcess(clock)
        deadline = clock.deadline()
        clock.wall -= .1
        result = deadline_owner.wait_owned_child(process, deadline, sleep=clock.sleep)
        self.assertEqual(result["reason"], "RESEARCH_CLOCK_REGRESSED")
        self.assertTrue(result["utc_regressed"])
        self.assertFalse(result["timeout"])
        self.assertFalse(result["publication_allowed"])
        self.assertEqual(process.terminations, 1)

    def test_deadline_failure_facts_cannot_be_mutated_to_reopen_publication(self):
        clock = FakeClock()
        deadline = clock.deadline()
        clock.advance(2)
        with self.assertRaises(deadline_owner.ResearchDeadlineError) as caught:
            deadline.checkpoint("late")
        caught.exception.facts["publication_allowed"] = True
        caught.exception.facts["reason"] = None
        with self.assertRaises(deadline_owner.ResearchDeadlineError) as again:
            deadline.checkpoint("retry_publication")
        self.assertFalse(again.exception.facts["publication_allowed"])
        self.assertEqual(again.exception.facts["reason"], "RESEARCH_ABSOLUTE_DEADLINE_EXPIRED")

    def test_pipe_and_long_poll_are_refused_before_waiting(self):
        clock = FakeClock()
        process = FakeProcess(clock)
        process.stdout = object()
        with self.assertRaisesRegex(ValueError, "FILE_BACKED"):
            deadline_owner.wait_owned_child(process, clock.deadline())
        process.stdout = None
        with self.assertRaisesRegex(ValueError, "SHORT_BOUNDED"):
            deadline_owner.wait_owned_child(process, clock.deadline(), poll_interval=.3)

    def test_invalid_or_extended_record_cannot_create_replacement_deadline(self):
        clock = FakeClock()
        for changed in ("extension", "extra", "nan", "negative"):
            record = copy.deepcopy(clock.deadline().record())
            if changed == "extension":
                record["deadline_unix"] += 1
            elif changed == "extra":
                record["renew"] = True
            elif changed == "nan":
                record["started_at_unix"] = float("nan")
            else:
                record["budget_seconds"] = -1
            with self.subTest(changed=changed), self.assertRaises(ValueError):
                deadline_owner.AbsoluteResearchDeadline.from_record(record,
                    monotonic=clock.monotonic, utc=clock.utc)

    def test_cached_windows_exit_code_is_not_native_process_completion(self):
        clock = FakeClock()
        process = FakeProcess(clock, finish_after=0)
        process._handle = object()
        with patch.object(deadline_owner, "_wait_for_process_handle", return_value=258) as native_wait:
            self.assertIsNone(deadline_owner._owned_child_exit_code(process))
            self.assertEqual(process.returncode, 0)
            native_wait.assert_called_once_with(process._handle, 0)
        with patch.object(deadline_owner, "_wait_for_process_handle", return_value=deadline_owner._PROCESS_SIGNALED):
            self.assertEqual(deadline_owner._owned_child_exit_code(process), 0)

    def test_expired_cached_exit_waits_for_owned_native_handle_without_reopening_budget(self):
        clock = FakeClock()
        deadline = clock.deadline(.1)
        process = FakeProcess(clock, finish_after=0)
        process._handle = object()
        clock.advance(.2)

        def native_status(handle, milliseconds):
            self.assertIs(handle, process._handle)
            self.assertEqual(milliseconds, 0)
            return deadline_owner._PROCESS_SIGNALED if clock.mono >= 100.3 else 258

        with patch.object(deadline_owner, "_wait_for_process_handle", side_effect=native_status):
            result = deadline_owner.wait_owned_child(process, deadline, sleep=clock.sleep)
        self.assertTrue(result["owned_child_reaped"])
        self.assertGreaterEqual(clock.mono, 100.3)
        self.assertTrue(result["timeout"])
        self.assertFalse(result["publication_allowed"])
        self.assertFalse(result["deadline_renewed"])
        self.assertEqual(deadline.record()["budget_seconds"], .1)

    def test_cached_exit_with_unsignaled_owned_handle_aborts_after_bounded_cleanup(self):
        clock = FakeClock()
        deadline = clock.deadline(.1)
        process = FakeProcess(clock, finish_after=0)
        process._handle = object()
        clock.advance(.2)
        with patch.object(deadline_owner, "_wait_for_process_handle", return_value=258) as native_wait:
            with self.assertRaises(deadline_owner.OwnedChildNotReaped) as caught:
                deadline_owner.wait_owned_child(process, deadline, sleep=clock.sleep,
                    terminate_grace_seconds=.1, kill_grace_seconds=.1)
        self.assertTrue(all(call.args == (process._handle, 0) for call in native_wait.call_args_list))
        self.assertFalse(caught.exception.facts["owned_child_reaped"])
        self.assertTrue(caught.exception.facts["abort_serial_study"])
        self.assertFalse(caught.exception.facts["publication_allowed"])
        self.assertLessEqual(caught.exception.facts["cleanup_poll_count"], 8)

    def test_real_hidden_child_has_file_backed_large_output_without_pipe_deadlock(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            source, output, error = (root / name for name in ("input", "stdout", "stderr"))
            source.write_bytes(b"original-input")
            command = [sys.executable, "-c", "import sys; data=sys.stdin.buffer.read(); sys.stdout.buffer.write(data+b'x'*262144); sys.stderr.buffer.write(b'y'*262144)"]
            result = deadline_owner.run_hidden_serial(command, input_path=source, stdout_path=output,
                stderr_path=error, deadline=deadline_owner.AbsoluteResearchDeadline.start(10))
            self.assertEqual(result["status"], "completed")
            self.assertEqual(output.stat().st_size, len(b"original-input") + 262144)
            self.assertEqual(error.stat().st_size, 262144)

    def test_real_owned_sleeping_child_is_reaped_on_short_absolute_cap(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            source, output, error = (root / name for name in ("input", "stdout", "stderr"))
            source.write_bytes(b"")
            result = deadline_owner.run_hidden_serial([sys.executable, "-c", "import time; time.sleep(30)"],
                input_path=source, stdout_path=output, stderr_path=error,
                deadline=deadline_owner.AbsoluteResearchDeadline.start(.2), poll_interval=.05)
            self.assertTrue(result["timeout"])
            self.assertTrue(result["owned_child_reaped"])
            self.assertFalse(result["publication_allowed"])


if __name__ == "__main__":
    unittest.main()
