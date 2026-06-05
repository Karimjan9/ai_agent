# AI Service Contract

The Python service exposes strategy and backtest endpoints for the Laravel backend.

Base URL in local development:

```text
http://127.0.0.1:9000
```

## Health

```http
GET /health
```

Response:

```json
{
  "status": "ok",
  "service": "neurotrader-ai-service"
}
```

## Run Backtest

Simple EMA/RSI MVP endpoint:

```http
POST /api/backtest/run
```

Request:

```json
{
  "symbol": "XAUUSD",
  "timeframe": "H1",
  "strategy": "ema_rsi_v1",
  "from_date": "2023-01-01",
  "to_date": "2025-12-31",
  "initial_balance": 10000,
  "risk_per_trade": 1,
  "dataset_path": "../datasets/XAUUSD_H1.csv"
}
```

Response:

```json
{
  "strategy": "EMA_RSI_V1",
  "instrument": "XAU/USD",
  "timeframe": "H1",
  "period": "2023-01-01 - 2025-12-31",
  "initial_balance": 10000,
  "final_balance": 11850,
  "net_profit_percent": 18.5,
  "total_trades": 248,
  "wins": 140,
  "losses": 108,
  "winrate": 56.4,
  "profit_factor": 1.42,
  "max_drawdown": 8.7,
  "trades": [],
  "conclusion": "Trend paytida yaxshi, flat bozorda ko'p xato qiladi."
}
```

Detailed strategy research endpoint:

```http
POST /backtests/run
```

Request with dataset path:

```json
{
  "symbol": "XAU/USD",
  "timeframe": "M15",
  "strategy_name": "ema_rsi_v1",
  "from_date": "2023-01-01",
  "to_date": "2025-12-31",
  "dataset_path": "../datasets/xauusd_sample_m15.csv",
  "strategy": {
    "ema_fast": 50,
    "ema_slow": 200,
    "rsi_period": 14,
    "atr_period": 14,
    "atr_stop_multiplier": 1.5,
    "risk_reward": 2.0,
    "swing_lookback": 80
  }
}
```

Request with inline candles:

```json
{
  "symbol": "XAU/USD",
  "timeframe": "M15",
  "candles": [
    {
      "time": "2026-01-01T00:00:00Z",
      "open": 2050.0,
      "high": 2054.0,
      "low": 2048.0,
      "close": 2052.0,
      "volume": 1000
    }
  ]
}
```

Response:

```json
{
  "symbol": "XAU/USD",
  "timeframe": "M15",
  "metrics": {
    "total_trades": 0,
    "wins": 0,
    "losses": 0,
    "win_rate": 0.0,
    "net_pnl": 0.0,
    "profit_factor": 0.0,
    "max_drawdown": 0.0
  },
  "trades": [],
  "mistake_journal": [],
  "daily_report": {
    "summary": "No trades were generated.",
    "days": []
  }
}
```
