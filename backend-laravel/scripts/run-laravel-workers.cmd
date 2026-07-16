@echo off
cd /d "%~dp0.."
start "NeuroTrader Scheduler" /b "C:\x_programs\xamp\php\php.exe" artisan schedule:work
start "NeuroTrader XAU Screening" /b "C:\x_programs\xamp\php\php.exe" artisan queue:work database --queue=lab-xauusd --sleep=1 --tries=2 --timeout=2400
start "NeuroTrader EUR Screening" /b "C:\x_programs\xamp\php\php.exe" artisan queue:work database --queue=lab-eurusd --sleep=1 --tries=2 --timeout=2400
start "NeuroTrader GBP Screening" /b "C:\x_programs\xamp\php\php.exe" artisan queue:work database --queue=lab-gbpusd --sleep=1 --tries=2 --timeout=2400
start "NeuroTrader Full Validation" /b "C:\x_programs\xamp\php\php.exe" artisan queue:work database --queue=lab-full-validation --sleep=1 --tries=2 --timeout=2400
