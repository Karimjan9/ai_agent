---
aliases:
  - System Architecture
tags:
  - architecture
  - laravel
  - fastapi
updated: 2026-08-10
---

# Architecture

```text
Browser
  -> Laravel routes/controllers
  -> Laravel services + database
  -> HTTP call when strategy calculation is needed
  -> Python FastAPI service
  -> dataset + strategy registry + backtester
  -> result/signal returned to Laravel
  -> persisted records and dashboard/report pages
```

## Ownership boundaries

| Area | Owner | Primary entry point |
| --- | --- | --- |
| Web UI, workflows, persistence | Laravel | `backend-laravel/routes/web.php` |
| Scheduled/console workflows | Laravel | `backend-laravel/routes/console.php` and `app/Console/Commands/` |
| Strategy execution and scoring | Python/FastAPI | `ai-service-python/app/main.py` |
| Historical data | Dataset files and Laravel providers | `datasets/`, `app/Services/MarketData/` |
| Service contract | Documentation | `docs/ai-service-contract.md` |

## Important flows

- **Backtest:** Laravel BacktestController -> Python `/api/backtest/run` -> result persistence/UI.
- **Backtest safety:** Laravel hashes the normalized payload, binds an optional `Idempotency-Key` to that hash, and rejects reuse with a different payload before dispatching another run.
- **Strategy Lab:** Laravel StrategyLabController -> Python `/api/backtest/run-all` -> leaderboard and strategy scores.
- **Paper signal:** Laravel PaperTradingExecutionService -> Python `/api/paper/signal` -> paper-trading records/evaluations.
- **COT intelligence (read-only):** Laravel scheduler -> official CFTC Disaggregated Futures-Only endpoint -> immutable `cot_reports` -> `cot_feature_snapshots` -> Market Intelligence dashboard. This flow does not currently influence strategies, scores, or orders.
- **Market Reality:** canonical market-data update -> candles -> bounded `MarketRealityService::analyzeSymbol()` rolling snapshots. This Phase 2 foundation flow has its own `MARKET_REALITY_ENABLED` switch and is not disabled by the frozen secondary-intelligence modules.
- **Research engines:** dashboard controllers -> dedicated Laravel service -> models/migrations -> dashboard views.
- **Runtime monitoring:** headless scheduler -> `system:scheduler-heartbeat` cache key -> Agent Health service; feed health checks inspect only the configured provider, never a research fallback.

Detailed module map: [[modules]]. Operational checks: [[operations]].
