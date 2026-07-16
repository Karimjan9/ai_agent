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

## Run All With Walk Forward Validation

```http
POST /api/backtest/run-all
```

The run-all endpoint tests every submitted strategy with a 70% train, 15% validation, and 15% forward split. The final leaderboard score is based on forward performance and robustness, with an overfit penalty.

Response:

```json
{
  "symbol": "XAUUSD",
  "timeframe": "H1",
  "leaderboard": [
    {
      "strategy": "ema_rsi_v4",
      "train_score": 91,
      "validation_score": 88,
      "forward_score": 84,
      "robustness_score": 93,
      "is_overfit": false,
      "score": 87,
      "result": {
        "total_trades": 120,
        "winrate": 58.4,
        "profit_factor": 1.5,
        "max_drawdown_percent": 8.2,
        "monte_carlo": {
          "simulations": 1000,
          "worst_profit_percent": -8.4,
          "avg_profit_percent": 22.1,
          "best_profit_percent": 46.7,
          "worst_drawdown_percent": 27.3,
          "avg_drawdown_percent": 11.2,
          "risk_of_ruin_percent": 3.6,
          "worst_equity_curve": [],
          "best_equity_curve": []
        },
        "strategy_dna": {
          "aggression_score": 72,
          "trend_dependency": 91,
          "range_dependency": 18,
          "volatility_sensitivity": 42,
          "adaptability_score": 84,
          "recovery_score": 78,
          "survival_score": 88,
          "dna_summary": "EMA_RSI_V4 is a trend-focused medium-risk strategy based on 120 recent trades."
        }
      }
    }
  ]
}
```

## Monte Carlo Survival Metrics

Every simple backtest result includes `monte_carlo`. The service shuffles the strategy trade list across 1000 simulations and reports survival-focused metrics:

```json
{
  "simulations": 1000,
  "worst_profit_percent": -8.4,
  "avg_profit_percent": 22.1,
  "best_profit_percent": 46.7,
  "worst_drawdown_percent": 27.3,
  "avg_drawdown_percent": 11.2,
  "risk_of_ruin_percent": 3.6,
  "worst_equity_curve": [],
  "best_equity_curve": []
}
```

## Strategy DNA Metrics

Every simple backtest result includes `strategy_dna`. The DNA profile describes strategy personality rather than only raw performance:

```json
{
  "aggression_score": 72,
  "trend_dependency": 91,
  "range_dependency": 18,
  "volatility_sensitivity": 42,
  "adaptability_score": 84,
  "recovery_score": 78,
  "survival_score": 88,
  "dna_summary": "EMA_RSI_V4 is a trend-focused medium-risk strategy based on 120 recent trades."
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
