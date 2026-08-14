Option Explicit

' Double-click-safe launcher for the fallback worker script. WScript starts
' the console script with window style 0 so the PHP/Redis workers stay in the
' background when PM2 is not being used.
Dim shell, fileSystem, scriptDirectory, command
Set shell = CreateObject("WScript.Shell")
Set fileSystem = CreateObject("Scripting.FileSystemObject")
scriptDirectory = fileSystem.GetParentFolderName(WScript.ScriptFullName)
command = """" & scriptDirectory & "\run-laravel-workers.cmd"""
shell.Run command, 0, False
