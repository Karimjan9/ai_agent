@echo off
cd /d "%~dp0.."
rem Keep this fallback launcher aligned with ecosystem.config.cjs. Numeric
rem tries are forbidden for shared-lane jobs because mutex releases count as
rem attempts; EvaluateLabAgentJob owns the bounded retryUntil() deadline.
powershell.exe -NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "%~dp0start-redis.ps1"
if errorlevel 1 exit /b 1
start "NeuroTrader Scheduler" /b "C:\x_programs\xamp\php\php.exe" artisan schedule:headless-work
rem Full validation stays on one priority coordinator; two screening workers
rem use the bounded Python screening slots and never share the full mutex.
start "NeuroTrader Replay Coordinator" /b "C:\x_programs\xamp\php\php.exe" artisan queue:work redis --queue=lab-full-validation,lab-frontier --sleep=1 --tries=0 --timeout=4200 --memory=2048 --max-time=3600
start "NeuroTrader Screening A" /b "C:\x_programs\xamp\php\php.exe" artisan queue:work redis --queue=lab-screening,lab-xauusd,lab-eurusd,lab-gbpusd --sleep=1 --tries=0 --timeout=2400 --memory=2048 --max-time=3600
start "NeuroTrader Screening B" /b "C:\x_programs\xamp\php\php.exe" artisan queue:work redis --queue=lab-screening,lab-xauusd,lab-eurusd,lab-gbpusd --sleep=1 --tries=0 --timeout=2400 --memory=2048 --max-time=3600
start "NeuroTrader Learning" /b "C:\x_programs\xamp\php\php.exe" artisan queue:work redis --queue=lab-learning --sleep=1 --tries=0 --timeout=900 --memory=1024 --max-time=3600
