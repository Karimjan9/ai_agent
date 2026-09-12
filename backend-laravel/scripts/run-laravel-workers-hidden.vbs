Option Explicit

' Double-click-safe launcher for the fallback runtime supervisor. WScript
' starts PowerShell with window style 0, and the named mutex prevents duplicate
' supervisors when this launcher is invoked more than once.
Dim shell, fileSystem, scriptDirectory, supervisor, command
Set shell = CreateObject("WScript.Shell")
Set fileSystem = CreateObject("Scripting.FileSystemObject")
scriptDirectory = fileSystem.GetParentFolderName(WScript.ScriptFullName)
supervisor = scriptDirectory & "\supervise-laravel-runtime.ps1"
command = "powershell.exe -NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File """ & supervisor & """"
shell.Run command, 0, False
