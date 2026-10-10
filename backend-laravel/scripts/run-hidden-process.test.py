"""Process-boundary checks without application imports or runtime data."""

import contextlib
import importlib.util
import io
import json
import os
import pathlib
import subprocess
import sys
import tempfile
import time
import unittest
from unittest import mock

MODULE_PATH = pathlib.Path(__file__).with_name("run-hidden-process.py")
SPEC = importlib.util.spec_from_file_location("hidden_process_broker", MODULE_PATH)
BROKER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(BROKER)
PYTHONW_PATH = pathlib.Path(sys.executable).with_name("pythonw.exe")
HAS_PYTHONW = os.name == "nt" and PYTHONW_PATH.is_file()


class HiddenProcessBrokerTest(unittest.TestCase):
    def test_windows_uses_no_window_without_shell_or_output_pipes(self):
        command = ["tool.exe", "--leading-option", "space here", 'quote"here',
                   "& | > $(literal)", "--", "last\\"]
        with mock.patch.object(BROKER.os, "name", "nt"), \
                mock.patch.object(BROKER.subprocess, "CREATE_NO_WINDOW", 0x08000000, create=True), \
                mock.patch.object(BROKER.subprocess, "run") as run:
            run.return_value.returncode = 23
            self.assertEqual(BROKER.main(["--timeout", "3.5", "--", *command]), 23)
        run.assert_called_once_with(
            command, shell=False, stdin=subprocess.DEVNULL, stdout=None, stderr=None,
            timeout=3.5, check=False, creationflags=0x08000000,
        )

    def test_non_windows_uses_no_windows_creation_flags(self):
        with mock.patch.object(BROKER.os, "name", "posix"), \
                mock.patch.object(BROKER.subprocess, "run") as run:
            run.return_value.returncode = 0
            self.assertEqual(BROKER.main(["--timeout", "1", "--", "tool"]), 0)
        self.assertEqual(run.call_args.kwargs["creationflags"], 0)

    def test_invalid_arguments_do_not_launch_or_echo_the_supplied_command(self):
        invocations = [["--timeout", value, "--", "private-command"]
                       for value in ["0", "-1", "nan", "inf", "nope"]]
        invocations += [[], ["--timeout", "1"], ["--timeout", "1", "--"],
                        ["--timeout", "1", "--", ""],
                        ["--timeout", "1", "--status-file", "", "--", "tool"],
                        ["--timeout", "1", "--status-file", "bad\0path", "--", "tool"],
                        ["--unknown-private-option", "--timeout", "1", "--", "tool"]]
        for invocation in invocations:
            with self.subTest(invocation=invocation), \
                    mock.patch.object(BROKER.subprocess, "run") as run, \
                    contextlib.redirect_stderr(io.StringIO()) as stderr:
                self.assertEqual(BROKER.main(invocation), 2)
                run.assert_not_called()
                self.assertEqual(stderr.getvalue(), "Hidden process: invalid arguments.\n")

    def test_launch_failures_and_timeouts_have_fixed_diagnostics(self):
        cases = [(OSError("private executable or argument"), 1,
                  "Hidden process: unable to start child.\n"),
                 (ValueError("private NUL-containing argument"), 1,
                  "Hidden process: unable to start child.\n"),
                 (subprocess.TimeoutExpired(["private-command"], 1), 124,
                  "Hidden process: timed out.\n")]
        for exception, exit_code, diagnostic in cases:
            with self.subTest(exception=type(exception).__name__), \
                    mock.patch.object(BROKER.subprocess, "run", side_effect=exception), \
                    contextlib.redirect_stderr(io.StringIO()) as stderr:
                self.assertEqual(BROKER.main(["--timeout", "1", "--", "tool"]), exit_code)
                self.assertEqual(stderr.getvalue(), diagnostic)

    def test_real_child_preserves_argv_output_exit_cwd_and_environment(self):
        arguments = ["--leading-option", "space here", 'quote"here', "",
                     "& | > $(literal)", "--", "last\\"]
        code = ("import json, os, sys; "
                "sys.stdout.buffer.write(json.dumps([sys.argv[1:], os.getcwd(), "
                "os.environ.get('HIDDEN_PROCESS_TEST_VALUE')]).encode('ascii')); "
                "sys.stderr.buffer.write(b'child stderr\\x00\\r\\n'); "
                "raise SystemExit(23)")
        broker_executable = str(PYTHONW_PATH) if HAS_PYTHONW else sys.executable
        with tempfile.TemporaryDirectory(prefix="hidden-process-test-") as directory, \
                tempfile.TemporaryFile() as stdout, tempfile.TemporaryFile() as stderr, \
                mock.patch.dict(os.environ, {"HIDDEN_PROCESS_TEST_VALUE": "inherited-test-value"}):
            result = subprocess.run(
                [broker_executable, "-B", str(MODULE_PATH), "--timeout", "5", "--",
                 sys.executable, "-c", code, *arguments],
                shell=False, stdin=subprocess.DEVNULL, stdout=stdout, stderr=stderr,
                cwd=directory, timeout=10,
                creationflags=subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0,
            )
            stdout.seek(0)
            stderr.seek(0)
            self.assertEqual(result.returncode, 23)
            output = json.loads(stdout.read())
            self.assertEqual(output[0], arguments)
            self.assertEqual(pathlib.Path(output[1]), pathlib.Path(directory))
            self.assertEqual(output[2], "inherited-test-value")
            self.assertEqual(stderr.read(), b"child stderr\x00\r\n")

    def test_real_timeout_kills_and_reaps_the_direct_child(self):
        children = []
        real_popen = subprocess.Popen

        def track_child(*args, **kwargs):
            child = real_popen(*args, **kwargs)
            children.append(child)
            return child

        started = time.monotonic()
        try:
            with mock.patch.object(BROKER.subprocess, "Popen", side_effect=track_child), \
                    contextlib.redirect_stderr(io.StringIO()) as stderr:
                exit_code = BROKER.main([
                    "--timeout", "0.3", "--", sys.executable, "-c",
                    "import time; time.sleep(30)",
                ])
            self.assertEqual(exit_code, 124)
            self.assertEqual(stderr.getvalue(), "Hidden process: timed out.\n")
            self.assertLess(time.monotonic() - started, 5)
            self.assertEqual(len(children), 1)
            self.assertIsNotNone(children[0].returncode)
            self.assertIsNotNone(children[0].poll(), "timed-out child survived")
        finally:
            for child in children:
                if child.poll() is None:
                    child.kill()
                    child.wait(timeout=5)

    def test_status_distinguishes_real_exit_124_from_a_broker_timeout(self):
        broker_executable = str(PYTHONW_PATH) if HAS_PYTHONW else sys.executable
        output = b"child output\x00\r\n"
        error = b"child stderr\x00\r\n"
        code = ("import sys, time; "
                "sys.stdout.buffer.write(b'child output\\x00\\r\\n'); sys.stdout.flush(); "
                "sys.stderr.buffer.write(b'child stderr\\x00\\r\\n'); sys.stderr.flush(); ")
        for timed_out in [False, True]:
            with self.subTest(timed_out=timed_out), \
                    tempfile.TemporaryDirectory(prefix="hidden-process-status-test-") as directory, \
                    tempfile.TemporaryFile() as stdout, tempfile.TemporaryFile() as stderr:
                status_path = pathlib.Path(directory) / "status.json"
                status_path.touch()
                result = subprocess.run(
                    [broker_executable, "-B", str(MODULE_PATH), "--status-file", str(status_path),
                     "--timeout", "0.4" if timed_out else "5", "--", sys.executable, "-c",
                     code + ("time.sleep(30)" if timed_out else "raise SystemExit(124)")],
                    shell=False, stdin=subprocess.DEVNULL, stdout=stdout, stderr=stderr,
                    timeout=10,
                    creationflags=subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0,
                )
                stdout.seek(0)
                stderr.seek(0)
                self.assertEqual(result.returncode, 124)
                self.assertEqual(json.loads(status_path.read_text(encoding="ascii")),
                                 {"timed_out": timed_out})
                diagnostic = ("Hidden process: timed out." + os.linesep).encode("ascii")
                captured_output = stdout.read()
                captured_error = stderr.read()
                if timed_out:
                    # The deadline can expire before the child reaches either
                    # write. Timeout provenance must not depend on startup speed.
                    self.assertIn(captured_output, [b"", output])
                    self.assertTrue(captured_error.endswith(diagnostic))
                    self.assertIn(captured_error[:-len(diagnostic)], [b"", error])
                else:
                    self.assertEqual(captured_output, output)
                    self.assertEqual(captured_error, error)
                self.assertEqual(list(pathlib.Path(directory).iterdir()), [status_path])

    def test_launch_failure_records_false_without_exposing_the_error(self):
        with tempfile.TemporaryDirectory(prefix="hidden-process-status-test-") as directory, \
                mock.patch.object(BROKER.subprocess, "run", side_effect=OSError("private command")), \
                contextlib.redirect_stderr(io.StringIO()) as stderr:
            status_path = pathlib.Path(directory) / "status.json"
            status_path.touch()
            self.assertEqual(BROKER.main([
                "--timeout", "1", "--status-file", str(status_path), "--", "tool",
            ]), 1)
            self.assertEqual(json.loads(status_path.read_text(encoding="ascii")), {"timed_out": False})
            self.assertEqual(stderr.getvalue(), "Hidden process: unable to start child.\n")

    def test_status_publication_failure_keeps_child_output_and_reports_failure(self):
        broker_executable = str(PYTHONW_PATH) if HAS_PYTHONW else sys.executable
        with tempfile.TemporaryDirectory(prefix="hidden-process-status-test-") as directory, \
                tempfile.TemporaryFile() as stdout, tempfile.TemporaryFile() as stderr:
            result = subprocess.run(
                [broker_executable, "-B", str(MODULE_PATH), "--status-file", directory,
                 "--timeout", "5", "--", sys.executable, "-c",
                 "import sys; sys.stdout.buffer.write(b'child output\\x00\\r\\n')"],
                shell=False, stdin=subprocess.DEVNULL, stdout=stdout, stderr=stderr,
                timeout=10,
                creationflags=subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0,
            )
            stdout.seek(0)
            stderr.seek(0)
            self.assertEqual(result.returncode, 1)
            self.assertEqual(stdout.read(), b"child output\x00\r\n")
            self.assertEqual(stderr.read(), ("Hidden process: unable to write status." + os.linesep).encode("ascii"))
            self.assertEqual(list(pathlib.Path(directory).iterdir()), [])

    def test_status_write_failure_leaves_the_unique_status_file_invalid(self):
        with tempfile.TemporaryDirectory(prefix="hidden-process-status-test-") as directory, \
                mock.patch.object(BROKER.subprocess, "run") as run, \
                mock.patch.object(BROKER.json, "dump", side_effect=OSError("private status path")), \
                contextlib.redirect_stderr(io.StringIO()) as stderr:
            run.return_value.returncode = 0
            status_path = pathlib.Path(directory) / "status.json"
            status_path.touch()
            self.assertEqual(BROKER.main([
                "--timeout", "1", "--status-file", str(status_path), "--", "tool",
            ]), 1)
            self.assertEqual(status_path.read_bytes(), b"")
            self.assertEqual(stderr.getvalue(), "Hidden process: unable to write status.\n")
            self.assertEqual(list(pathlib.Path(directory).iterdir()), [status_path])

    @unittest.skipUnless(HAS_PYTHONW, "Windows GUI Python is unavailable")
    def test_pythonw_errors_reach_the_inherited_stderr_file_handle(self):
        code = "\n".join([
            "import os, runpy, sys",
            "broker = runpy.run_path(sys.argv[1])",
            "def unavailable_fd(*args):",
            "    raise OSError('unavailable fd')",
            "sys.stderr = None",
            "os.write = unavailable_fd",
            "raise SystemExit(broker['main'](['--timeout', 'nan', '--', 'private-command']))",
        ])
        with tempfile.TemporaryFile() as stdout, tempfile.TemporaryFile() as stderr:
            result = subprocess.run(
                [str(PYTHONW_PATH), "-B", "-c", code, str(MODULE_PATH)],
                shell=False, stdin=subprocess.DEVNULL, stdout=stdout, stderr=stderr,
                timeout=5, creationflags=subprocess.CREATE_NO_WINDOW,
            )
            stdout.seek(0)
            stderr.seek(0)
            self.assertEqual(result.returncode, 2)
            self.assertEqual(stdout.read(), b"")
            self.assertEqual(stderr.read(), b"Hidden process: invalid arguments.\n")


if __name__ == "__main__":
    unittest.main()
