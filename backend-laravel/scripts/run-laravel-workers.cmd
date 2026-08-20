@echo off
cd /d "%~dp0.."
rem Run the fallback through a hidden PowerShell supervisor. The old START
rem /b launcher inherited the caller's console and could flash a black window
rem for every PHP child when this file was started manually.
powershell.exe -NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "%~dp0run-laravel-workers-hidden.ps1"
exit /b %errorlevel%
