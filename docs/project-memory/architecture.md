---
aliases:
  - System Architecture
tags:
  - architecture
  - laravel
  - fastapi
updated: 2026-08-28
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
- **Paper signal:** Laravel PaperTradingExecutionService -> Python `/api/paper/signal` -> immutable signal -> Risk Sentinel -> Smart Discipline assessment + account-risk veto priority -> `APPROVE|SHRINK|VETO` -> risk- and management-sealed paper order -> Python partial/trailing/time-stop reconciliation -> R/MFE/MAE process-integrity review. `BAD_*`, unattested, drifted or stop-widened outcomes are retained for audit but quarantined from learning. See [Smart Discipline](../architecture/smart-discipline-engine.md).
- **Confirmation/entry research:** sealed M5 + independently supplied closed H4/H1/M15 streams -> H1-owned context/location -> setup -> independent confirmation -> exact trigger -> logical invalidation -> close-time admission -> fill-time R:R/chase re-admission -> signal or explicit WAIT. Paper and replay call the same compiler; Laravel attests the top-level contract against its execution-preview copy before the cognitive funnel, and every model is compared to the same frozen M5 control. See [Confirmation & Entry System](../architecture/trading-confirmation-entry-system.md).
- **COT intelligence (read-only):** Laravel scheduler -> official CFTC Disaggregated Futures-Only endpoint -> immutable `cot_reports` -> `cot_feature_snapshots` -> Market Intelligence dashboard. This flow does not currently influence strategies, scores, or orders.
- **Market Reality:** canonical market-data update -> candles -> bounded `MarketRealityService::analyzeSymbol()` rolling snapshots. This Phase 2 foundation flow has its own `MARKET_REALITY_ENABLED` switch and is not disabled by the frozen secondary-intelligence modules.
- **Research engines:** dashboard controllers -> dedicated Laravel service -> models/migrations -> dashboard views.
- **Autonomous Edge-to-Mastery:** one-minute critical scheduler tick -> strict runtime/queue/Failure-Dojo admission -> frozen pre-2026 dataset, MTF and M5 execution identities -> exactly-once cohort -> disjoint 2-fold discovery, 3-fold causal replication and 9-fold authority -> attribution -> cartridge/mentor/mastery. Fixed repairs exhausted without Edge produce one bounded evidence-compiled structural hypothesis, not premature risk/exit optimization; 2026 remains paper-only.
- **Runtime monitoring:** headless scheduler -> `system:scheduler-heartbeat` cache key -> Agent Health service; feed health checks inspect only the configured provider, never a research fallback.

Detailed module map: [[modules]]. Operational checks: [[operations]].
