---
aliases:
  - AI Laboratory
  - Learning Loop
tags:
  - ai-learning
  - laboratory
  - champion-challenger
updated: 2026-07-12
---

# AI Learning Laboratory

## Primary objective

Prove that agents improve safely across generations before expanding the conceptual AI modules. Each market owns its models: a successful XAUUSD model never becomes an EURUSD or GBPUSD champion automatically.

| Laboratory | Families | Scope key |
| --- | --- | --- |
| XAUUSD Lab | Trend, Breakout, Volatility | `XAUUSD + H1 + family` |
| EURUSD Lab | Trend, Mean Reversion, Session | `EURUSD + H1 + family` |
| GBPUSD Lab | Breakout, Momentum, Volatility | `GBPUSD + H1 + family` |

## Learning loop

```text
complete historical data
  -> 20-agent generation
  -> full rolling walk-forward + untouched holdout
  -> Monte Carlo and risk gates
  -> forward-validated challenger
  -> paper orders and outcomes
  -> sealed holdout
  -> same-market champion replacement
  -> mutation memory informs the next generation
```

Generation composition is fixed: 3 elites (one per family), 10 bounded mutations, 4 crossovers, and 3 random explorers. A generation starts with `draft` agents and cannot be duplicated while it is queued or training.

## Lifecycle and gates

`draft -> training -> challenger -> forward_validated -> paper -> champion`

Terminal states: `overfit`, `rejected`, `stagnated`, `archived`.

A challenger replaces a champion only in the same `symbol + timeframe + strategy_family` slot after all of these pass:

- forward score is at least 5 points higher than the current champion;
- it wins all 3 required rolling forward windows;
- forward PF >= 1.3, drawdown <= 15%, risk of ruin <= 10%, and at least 30 trades;
- it is not overfit;
- paper results have at least 30 trades, PF >= 1.3, positive return, and drawdown <= 15%;
- the untouched sealed holdout passes equivalent risk/profit gates.

The old champion is archived only at promotion time; it remains active while a challenger is being proven.

## Cadence

- Hourly: candle import.
- Hourly: `trading:lab-incremental` checks existing champions on recent candles and records degradation.
- After 24 new closed H1 candles, market drift, or three consecutive degraded checks: `trading:lab-generation` creates at most one pending generation per laboratory. The hourly incremental command creates a degradation-triggered generation immediately once the third consecutive poor check is recorded. Both paths wait for the previous generation to finish rather than overlapping populations.
- Weekly: `trading:dispatch-lab` sends every draft agent to its own pair queue for full historical rolling walk-forward and Monte Carlo evaluation.
- Every five minutes: `trading:paper-monitor` opens/reconciles simulated or configured practice-broker paper orders.
- Hourly: `trading:release-holdouts` releases a paper-passed finalist's untouched holdout exactly once.

## Required workers

Full evaluations are database-queue jobs. Keep the scheduler and one worker for each pair queue running so the three laboratories evaluate independently and in parallel:

```powershell
php artisan schedule:work
php artisan queue:work database --queue=lab-xauusd --sleep=1 --tries=2 --timeout=2400
php artisan queue:work database --queue=lab-eurusd --sleep=1 --tries=2 --timeout=2400
php artisan queue:work database --queue=lab-gbpusd --sleep=1 --tries=2 --timeout=2400
```

The Python AI service must also be available at `AI_SERVICE_URL` before a full evaluation, incremental check, paper signal, or holdout can run.

## Main files

- Population and mutation selection: `backend-laravel/app/Services/LabPopulationService.php`
- Full evaluation: `LabAgentEvaluationService.php`
- Daily incremental health: `LabIncrementalEvaluationService.php`
- Champion gates and mutation memory: `MarketChampionService.php`
- Paper-order execution: `PaperTradingExecutionService.php`
- UI: `resources/views/ai-laboratory/show.blade.php`
