@echo off
cd /d "%~dp0.."
rem Keep one duplicate-safe fallback supervisor alive. It reconciles missing
rem Redis, AI, scheduler and queue workers without opening child consoles.
powershell.exe -NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "%~dp0supervise-laravel-runtime.ps1"
exit /b %errorlevel%
