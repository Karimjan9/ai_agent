"""Run one bounded child without creating a Windows console window."""

import argparse
import json
import math
import os
import subprocess
import sys
import tempfile


# A successful self-assignment owns this handle until process teardown. Closing
# it in main/finally would also terminate the broker before its exit/status can
# be published. The handle is non-inheritable, so abrupt broker termination
# closes the last owner and terminates its complete helper process tree.
_windows_job_handle = None


def _ensure_windows_kill_on_close_job():
    global _windows_job_handle
    if _windows_job_handle is not None:
        return

    import ctypes
    from ctypes import wintypes

    class _BasicLimitInformation(ctypes.Structure):
        _fields_ = [
            ("PerProcessUserTimeLimit", ctypes.c_int64),
            ("PerJobUserTimeLimit", ctypes.c_int64),
            ("LimitFlags", wintypes.DWORD),
            ("MinimumWorkingSetSize", ctypes.c_size_t),
            ("MaximumWorkingSetSize", ctypes.c_size_t),
            ("ActiveProcessLimit", wintypes.DWORD),
            ("Affinity", ctypes.c_size_t),
            ("PriorityClass", wintypes.DWORD),
            ("SchedulingClass", wintypes.DWORD),
        ]

    class _IoCounters(ctypes.Structure):
        _fields_ = [(name, ctypes.c_uint64) for name in (
            "ReadOperationCount", "WriteOperationCount", "OtherOperationCount",
            "ReadTransferCount", "WriteTransferCount", "OtherTransferCount",
        )]

    class _ExtendedLimitInformation(ctypes.Structure):
        _fields_ = [
            ("BasicLimitInformation", _BasicLimitInformation),
            ("IoInfo", _IoCounters),
            ("ProcessMemoryLimit", ctypes.c_size_t),
            ("JobMemoryLimit", ctypes.c_size_t),
            ("PeakProcessMemoryUsed", ctypes.c_size_t),
            ("PeakJobMemoryUsed", ctypes.c_size_t),
        ]

    kernel32 = ctypes.WinDLL("kernel32", use_last_error=True)
    create_job = kernel32.CreateJobObjectW
    create_job.argtypes = [wintypes.LPVOID, wintypes.LPCWSTR]
    create_job.restype = wintypes.HANDLE
    set_information = kernel32.SetInformationJobObject
    set_information.argtypes = [wintypes.HANDLE, ctypes.c_int, wintypes.LPVOID,
                               wintypes.DWORD]
    set_information.restype = wintypes.BOOL
    assign_process = kernel32.AssignProcessToJobObject
    assign_process.argtypes = [wintypes.HANDLE, wintypes.HANDLE]
    assign_process.restype = wintypes.BOOL
    get_current_process = kernel32.GetCurrentProcess
    get_current_process.argtypes = []
    get_current_process.restype = wintypes.HANDLE
    close_handle = kernel32.CloseHandle
    close_handle.argtypes = [wintypes.HANDLE]
    close_handle.restype = wintypes.BOOL

    # NULL security attributes create a non-inheritable unnamed job handle.
    handle = create_job(None, None)
    if not handle:
        raise OSError("job ownership unavailable")
    information = _ExtendedLimitInformation()
    information.BasicLimitInformation.LimitFlags = 0x00002000  # KILL_ON_JOB_CLOSE
    if not set_information(handle, 9, ctypes.byref(information),
                           ctypes.sizeof(information)):
        close_handle(handle)
        raise OSError("job ownership unavailable")
    # Assignment must succeed before any helper is spawned. An unsupported
    # containing/nested job is a refusal, never an unowned fallback launch.
    if not assign_process(handle, get_current_process()):
        close_handle(handle)
        raise OSError("job ownership unavailable")
    _windows_job_handle = handle


class _InvocationParser(argparse.ArgumentParser):
    def error(self, message):
        # argparse errors can contain supplied arguments; keep diagnostics fixed.
        raise ValueError("invalid arguments")


def _positive_timeout(value):
    timeout = float(value)
    if not math.isfinite(timeout) or timeout <= 0:
        raise argparse.ArgumentTypeError("timeout must be finite and positive")
    return timeout


def _status_file(value):
    if not value or "\0" in value:
        raise argparse.ArgumentTypeError("status file must be a valid path")
    return value


def _diagnostic(message):
    line = message + "\n"
    if sys.stderr is not None:
        try:
            sys.stderr.write(line)
            sys.stderr.flush()
            return
        except (OSError, ValueError):
            pass
    encoded = line.encode("ascii")
    try:
        os.write(2, encoded)
        return
    except OSError:
        pass
    if os.name == "nt":
        # pythonw may not expose fd 2 or sys.stderr, but proc_open supplies
        # a valid native stderr file handle which child processes also inherit.
        import ctypes
        from ctypes import wintypes

        kernel32 = ctypes.WinDLL("kernel32", use_last_error=True)
        get_std_handle = kernel32.GetStdHandle
        get_std_handle.argtypes = [wintypes.DWORD]
        get_std_handle.restype = wintypes.HANDLE
        write_file = kernel32.WriteFile
        write_file.argtypes = [wintypes.HANDLE, wintypes.LPCVOID, wintypes.DWORD,
                               ctypes.POINTER(wintypes.DWORD), wintypes.LPVOID]
        write_file.restype = wintypes.BOOL
        handle = get_std_handle(-12)  # STD_ERROR_HANDLE
        if handle not in (None, ctypes.c_void_p(-1).value):
            written = wintypes.DWORD()
            write_file(handle, ctypes.create_string_buffer(encoded), len(encoded),
                       ctypes.byref(written), None)


def _finish(exit_code, status_file, timed_out):
    if status_file is None:
        return exit_code
    temporary_path = None
    try:
        # Publish only a complete record after the child outcome is known.
        # The caller supplies a unique empty file; a failed publication leaves
        # that file invalid, rather than exposing a partial success record.
        with tempfile.NamedTemporaryFile(
            mode="w", encoding="ascii", newline="\n", delete=False,
            dir=os.path.dirname(os.path.abspath(status_file)),
            prefix="hidden-process-status-",
        ) as status:
            temporary_path = status.name
            json.dump({"timed_out": timed_out}, status, separators=(",", ":"))
        os.replace(temporary_path, status_file)
        temporary_path = None
    except (OSError, ValueError):
        _diagnostic("Hidden process: unable to write status.")
        return 1
    finally:
        if temporary_path is not None:
            try:
                os.unlink(temporary_path)
            except OSError:
                pass
    return exit_code


def main(argv=None):
    parser = _InvocationParser(add_help=False, allow_abbrev=False)
    parser.add_argument("--timeout", required=True, type=_positive_timeout)
    parser.add_argument("--status-file", type=_status_file)
    parser.add_argument("command", nargs=argparse.REMAINDER)
    timed_out = False
    try:
        arguments = parser.parse_args(argv)
        command = arguments.command
        if command and command[0] == "--":
            command = command[1:]
        if not command or not command[0]:
            raise ValueError("missing executable")
    except ValueError:
        _diagnostic("Hidden process: invalid arguments.")
        return 2

    try:
        if os.name == "nt":
            _ensure_windows_kill_on_close_job()
        # Inherit native stdout/stderr handles directly, including under
        # pythonw; no pipes or output copying can delay the child's writes.
        result = subprocess.run(
            command,
            shell=False,
            stdin=subprocess.DEVNULL,
            stdout=None,
            stderr=None,
            timeout=arguments.timeout,
            check=False,
            creationflags=subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0,
        )
        exit_code = result.returncode
    except subprocess.TimeoutExpired:
        # subprocess.run kills and reaps its direct child before raising.
        _diagnostic("Hidden process: timed out.")
        timed_out = True
        exit_code = 124
    except (OSError, ValueError):
        _diagnostic("Hidden process: unable to start child.")
        exit_code = 1
    return _finish(exit_code, arguments.status_file, timed_out)


if __name__ == "__main__":
    raise SystemExit(main())
