// GUI-subsystem cmd proxy for this project's detached Windows processes.
// The original shell tail and native I/O handles pass through unchanged.
using System;
using System.IO;
using System.Runtime.InteropServices;
using System.Text;

public static class WindowsHiddenShell {
    [StructLayout(LayoutKind.Sequential)] struct StartupInfo {
        public uint cb; public IntPtr reserved, desktop, title;
        public uint x, y, xSize, ySize, xChars, yChars, fillAttribute, flags;
        public ushort showWindow, reservedSize; public IntPtr reservedBytes;
        public IntPtr stdin, stdout, stderr;
    }
    [StructLayout(LayoutKind.Sequential)] struct ProcessInfo {
        public IntPtr process, thread; public uint processId, threadId;
    }
    [StructLayout(LayoutKind.Sequential)] struct BasicLimits {
        public long processTime, jobTime; public uint flags;
        public UIntPtr minWorkingSet, maxWorkingSet; public uint activeProcesses;
        public UIntPtr affinity; public uint priorityClass, schedulingClass;
    }
    [StructLayout(LayoutKind.Sequential)] struct IoCounters {
        public ulong readCount, writeCount, otherCount, readBytes, writeBytes, otherBytes;
    }
    [StructLayout(LayoutKind.Sequential)] struct ExtendedLimits {
        public BasicLimits basic; public IoCounters io;
        public UIntPtr processMemory, jobMemory, peakProcessMemory, peakJobMemory;
    }
    [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)]
    static extern bool CreateProcessW(string application, StringBuilder command,
        IntPtr processAttributes, IntPtr threadAttributes, bool inheritHandles,
        uint flags, IntPtr environment, string directory, ref StartupInfo startup,
        out ProcessInfo process);
    [DllImport("kernel32.dll")] static extern IntPtr GetStdHandle(int kind);
    [DllImport("kernel32.dll")] static extern IntPtr GetCurrentProcess();
    [DllImport("kernel32.dll", CharSet=CharSet.Unicode)]
    static extern IntPtr CreateJobObjectW(IntPtr attributes, string name);
    [DllImport("kernel32.dll")] static extern bool SetInformationJobObject(
        IntPtr job, int kind, ref ExtendedLimits information, uint size);
    [DllImport("kernel32.dll")] static extern bool AssignProcessToJobObject(IntPtr job, IntPtr process);
    [DllImport("kernel32.dll")] static extern uint WaitForSingleObject(IntPtr handle, uint milliseconds);
    [DllImport("kernel32.dll")] static extern bool GetExitCodeProcess(IntPtr process, out uint code);
    [DllImport("kernel32.dll")] static extern bool CloseHandle(IntPtr handle);
    [DllImport("kernel32.dll")] static extern bool WriteFile(IntPtr file, byte[] bytes,
        uint length, out uint written, IntPtr overlapped);

    // Keep this non-inheritable handle until OS process teardown. Closing it
    // earlier would terminate this proxy before its original exit code returns.
    static IntPtr ownedJob;

    static string ShellTail(string commandLine) {
        int end;
        if (commandLine.StartsWith("\"", StringComparison.Ordinal)) {
            end = commandLine.IndexOf('"', 1);
            if (end < 0) throw new InvalidOperationException();
            end++;
        } else {
            end = 0;
            while (end < commandLine.Length && !Char.IsWhiteSpace(commandLine[end])) end++;
        }
        return commandLine.Substring(end).TrimStart();
    }

    static bool OwnTree() {
        ownedJob = CreateJobObjectW(IntPtr.Zero, null);
        if (ownedJob == IntPtr.Zero) return false;
        var limits = new ExtendedLimits();
        limits.basic.flags = 0x2000; // JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE
        return SetInformationJobObject(ownedJob, 9, ref limits,
            (uint)Marshal.SizeOf(typeof(ExtendedLimits)))
            && AssignProcessToJobObject(ownedJob, GetCurrentProcess());
    }

    public static int Main() {
        ProcessInfo child = new ProcessInfo();
        try {
            // Bypass PATH and ComSpec here so the proxy cannot call itself.
            string shell = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "cmd.exe");
            if (!OwnTree()) throw new InvalidOperationException();
            var startup = new StartupInfo();
            startup.cb = (uint)Marshal.SizeOf(typeof(StartupInfo));
            startup.flags = 0x100; // STARTF_USESTDHANDLES
            startup.stdin = GetStdHandle(-10);
            startup.stdout = GetStdHandle(-11);
            startup.stderr = GetStdHandle(-12);
            var command = new StringBuilder("\"" + shell + "\" " + ShellTail(Environment.CommandLine));
            if (!CreateProcessW(shell, command, IntPtr.Zero, IntPtr.Zero, true,
                0x08000000, IntPtr.Zero, null, ref startup, out child)) {
                throw new InvalidOperationException();
            }
            if (WaitForSingleObject(child.process, 0xFFFFFFFF) != 0) throw new InvalidOperationException();
            uint code;
            if (!GetExitCodeProcess(child.process, out code)) throw new InvalidOperationException();
            // A normally completed shell can deliberately launch a background
            // service (e.g. wscript / start). Preserve that cmd behavior. Abrupt
            // timeout termination never reaches this disarm, so its tree dies.
            var completedLimits = new ExtendedLimits();
            if (!SetInformationJobObject(ownedJob, 9, ref completedLimits,
                (uint)Marshal.SizeOf(typeof(ExtendedLimits)))) throw new InvalidOperationException();
            return unchecked((int)code);
        } catch {
            byte[] message = Encoding.ASCII.GetBytes("Hidden Windows shell could not execute the command.\r\n");
            uint written;
            WriteFile(GetStdHandle(-12), message, (uint)message.Length, out written, IntPtr.Zero);
            return 1;
        } finally {
            if (child.thread != IntPtr.Zero) CloseHandle(child.thread);
            if (child.process != IntPtr.Zero) CloseHandle(child.process);
            GC.KeepAlive(ownedJob);
        }
    }
}
