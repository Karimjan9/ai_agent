@echo off
cd /d "%~dp0..\..\ai-service-python"
py -m uvicorn app.main:app --host 127.0.0.1 --port 9000
