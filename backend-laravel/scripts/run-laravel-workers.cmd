@echo off
cd /d "%~dp0.."
rem Keep this fallback launcher aligned with ecosystem.config.cjs. Numeric
rem tries are forbidden for shared-lane jobs because mutex releases count as
rem attempts; EvaluateLabAgentJob owns the bounded retryUntil() deadline.
start "NeuroTrader Scheduler" /b "C:\x_programs\xamp\php\php.exe" artisan schedule:headless-work
rem A single priority coordinator owns the shared AI replay lane. Running
rem separate screen/full workers causes repeated mutex releases and queue
rem attempt churn while one replay is active.
start "NeuroTrader Replay Coordinator" /b "C:\x_programs\xamp\php\php.exe" artisan queue:work database --queue=lab-full-validation,lab-frontier,lab-screening,lab-xauusd,lab-eurusd,lab-gbpusd --sleep=1 --tries=0 --timeout=4200 --memory=2048 --max-time=3600
