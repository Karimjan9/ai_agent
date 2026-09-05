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

## Paper execution and immutable management

`POST /api/paper/execution-contract` returns the strategy-owned entry contract.
In addition to entry, stop, target, position size and execution hashes, it now
contains `management_contract` with protocol `paper_trade_management_v1`.
The management hash seals partial-profit, trailing-stop and time-stop settings
at entry.

`POST /api/paper/advance-contract` must receive that original contract and the
same immutable strategy/execution request. A present-but-different strategy,
execution or management hash is rejected. A closed response includes:

```json
{
  "closed": true,
  "exit_price": 110.0,
  "profit_percent": 5.55,
  "exit_reason": "partial_target+intrabar_target",
  "management_audit": {
    "protocol": "paper_management_audit_v1",
    "management_attested": true,
    "execution_attested": true,
    "strategy_attested": true,
    "contract_followed": true,
    "stop_widened": false,
    "partial_closed": true,
    "holding_bars": 3,
    "realized_r_multiple": 5.55,
    "mfe_r": 10.5,
    "mae_r": 0.5,
    "promotion_evidence": false
  }
}
```

Legacy contracts without `management_contract` may be settled but are marked
unattested; Laravel quarantines them from calibration and promotion evidence.

## Confirmation and entry contract

`confirmation_entry_mtf_v1` is M5-only and accepts sealed M5 candles plus
independent closed H4/H1/M15 streams and one declared `entry_model`:

```json
{
  "strategy": "confirmation_entry_mtf_v1",
  "parameters": {
    "entry_model": "trend_continuation",
    "entry_mode": "balanced",
    "minimum_independent_confirmations": 3,
    "minimum_reward_space_r": 1.5,
    "max_chase_atr": 1.25
  },
  "mtf_dataset_paths": {"H4": "...", "H1": "...", "M15": "..."}
}
```

Allowed models are `trend_continuation`, `breakout_retest`,
`false_break_reversal`, `range_sweep` and `htf_reversal`. Allowed modes are
`aggressive`, `balanced` and `conservative`.

`POST /api/paper/signal` and replay use the same feature/signal compiler. The
paper result and `execution_contract_preview` expose an `entry_contract` with
separate context, location, setup, confirmation, trigger, invalidation,
reward-space, chase and event checks. A missing/stale MTF stream returns WAIT.
Invalid OHLC geometry or negative volume invalidates the entire M5 entry stream
or affected context stream.

The projection additionally exposes `reference_price`, `invalidation_price`,
`target_reference_price`, `trigger_anchor_price`, `structure_atr`,
`causal_context` and `contract_hash`. Laravel requires the top-level projection
and `entry_fill_admission` to equal their copies sealed in
`execution_contract_preview` for this strategy, and recomputes the contract's
canonical SHA-256. Missing, mismatched or hash-invalid evidence cannot use the
legacy route fallback.

`entry_fill_admission` reports the execution-boundary recheck. It recalculates
effective R:R and chase distance after gaps, both spread/slippage legs and
round-trip commission, uses the structural target in both replay and paper
execution, and returns an explicit WAIT reason
such as `entry_contract_fill_reward_space` or `entry_contract_fill_chase` when
the signal-close contract is no longer executable.

Backtest responses expose `entry_contract_funnel`; it records WAIT opportunities,
stage conversion, no-trade reasons and confirmation-cost diagnostics. Neither
this funnel nor the A+/A/B score has risk, live or promotion authority.

Replay observability protocol version 2 also emits `management_evidence` and
per-trade `initial_risk_distance`, `initial_risk_percent`, `mfe_r`, `mae_r`,
`realized_r_multiple` and `mfe_capture_ratio`. `initial_risk_percent` includes
spread, slippage and commission at the initial stop, so realized-R is tied to
executable account risk rather than a raw chart distance. Aggregate management
evidence is powered at eight observed paths plus five winner paths. Exit-bar
OHLC order is unknown and declared explicitly; stop efficiency and premature
stop rate remain null until a same-entry counterfactual supplies that evidence.
All fields are learning telemetry with `promotion_evidence=false`.

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
