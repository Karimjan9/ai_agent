---
aliases:
  - Module Map
tags:
  - modules
  - navigation
updated: 2026-08-10
---

# Module Map

| Module | What it does | Start here |
| --- | --- | --- |
| Dashboard and reports | Main pages, daily report, mistake journal, result views | `backend-laravel/routes/web.php`, `app/Http/Controllers/DashboardController.php` |
| Backtests | Submits a backtest, maps result, persists it | `app/Http/Controllers/BacktestController.php`, `ai-service-python/app/main.py` |
| Backtest safety and idempotency | Authenticated/manual backtest submission, durable idempotency identity, payload-conflict rejection and security response headers | `CanonicalManualBacktestService.php`, `Api/BacktestController.php`, `SecurityHeaders.php` |
| Strategies | Strategy implementations and registration | `ai-service-python/app/strategies/`, `app/strategies/registry.py` |
| Backtest engine | Candle loading, indicators, scores, walk-forward and Monte Carlo | `ai-service-python/app/services/backtester.py`, `walk_forward.py`, `monte_carlo.py` |
| AI Learning Laboratory | Pair-owned populations, adaptive parent frontier, semantic islands, lineage, mutation memory, champion/challenger lifecycle | `LabPopulationService.php`, `AdaptiveParentFrontierService.php`, `EvolutionArchiveService.php`, `MarketChampionService.php`, [[ai-learning-laboratory]], [[adaptive-evolution]] |
| Strategy Lab and DNA | Batch comparisons, parameter schema, DNA profiles | `StrategyLabController.php`, `StrategyParameterSchemaService.php`, `strategy_dna.py` |
| Training workflow | Sessions, logs, automatic/daily training commands | `TrainingSessionController.php`, `app/Console/Commands/Run*Training*.php` |
| Market data and reality | Provider selection, continuity-protected candle updates, rolling Market Reality snapshots and analysis cadence | `app/Services/MarketData/`, `MarketRealityService.php`, `MarketDataController.php` |
| COT market intelligence | Immutable official CFTC Gold reports and descriptive weekly positioning features; no trade/scoring effect in Phase 1 | `CotMarketIntelligenceService.php`, `SyncCotMarketIntelligence.php`, `MarketIntelligenceController.php` |
| Data continuity | Offline/recovery state, pending gaps, market-open H1 validation, learning safety gate | `MarketDataContinuityService.php`, `MarketDataSyncState.php`, [[market-data-continuity]] |
| Evolution and scientist | Proposals, model versions, scientist/mind/genome workflows | `AgentEvolutionService.php`, `TradingScientistService.php`, `AgentMindService.php` |
| Intelligence dashboards | Knowledge, future, meta, civilization, laws, causal, theory, reality | Corresponding controller and service names in `app/Http/Controllers/` and `app/Services/` |
| Agent/system health | Health checks, recovery/logging, profiles and market health | `AgentHealthController.php`, `MarketHealthService.php`, `SystemLogService.php` |
| Runtime monitoring | Scheduler heartbeat, strict health exit codes, canonical-provider-only feed checks and Market Reality freshness | `RunHeadlessScheduler.php`, `PhaseTwoFoundationService.php`, `RunSystemHealthCheck.php`, `CheckMarketHealth.php` |
| Paper trading foundation | Signal requests and paper order/fill/evaluation state | `PaperTradingExecutionService.php`, `MonitorPaperTrading.php` |

## Naming rule

For the intelligence dashboards, controller and service names match: for example `CausalIntelligenceController` delegates to `CausalIntelligenceService`. Search the target name directly rather than scanning all models.

## Data model rule

Each persistent concept normally has its model in `backend-laravel/app/Models/` and its creation/change migration in `backend-laravel/database/migrations/`. Open only the pair relevant to the requested concept.
