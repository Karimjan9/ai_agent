#!/usr/bin/env bash
set -euo pipefail

LOG_FILE="${MT5_RESTART_LOG:-/tmp/neurotrader_mt5_restart.log}"
MT5_COMMAND="${MT5_COMMAND:-}"
WINEPREFIX="${WINEPREFIX:-$HOME/.wine}"

echo "$(date -Is) restarting MT5/Wine with WINEPREFIX=$WINEPREFIX" >> "$LOG_FILE"

pkill -f terminal64.exe || true
pkill -f terminal.exe || true
pkill -f wineserver || true
wineserver -k || true

sleep 3

if [ -n "$MT5_COMMAND" ]; then
    nohup bash -lc "$MT5_COMMAND" >> "$LOG_FILE" 2>&1 &
    echo "$(date -Is) MT5_COMMAND started" >> "$LOG_FILE"
else
    echo "$(date -Is) MT5_COMMAND not configured; skipped MT5 start" >> "$LOG_FILE"
fi
