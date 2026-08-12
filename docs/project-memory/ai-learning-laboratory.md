---
aliases:
  - AI Laboratory
  - Learning Loop
tags:
  - ai-learning
  - laboratory
  - champion-challenger
updated: 2026-08-11
---

# AI Learning Laboratory

## Primary objective

Prove that agents improve safely across generations before expanding the conceptual AI modules. Each market owns its models: a successful XAUUSD model never becomes an EURUSD or GBPUSD champion automatically. A market may promote either one forward-valid agent or a sealed complementary portfolio; a portfolio is not a shortcut around independent member passports and combined replay.

## XAUUSD Multi-Timeframe Pilot

XAUUSD is the only official multi-timeframe pilot. H1 and M15 are separate
populations and separate genetic lineages:

```text
closed H1 candle
  -> H1 regime + direction + volatility context
  -> closed M15 candle
  -> M15 entry/timing specialist
  -> risk sentinel veto or WAIT
  -> next M15 open execution
```

The canonical contract is `xauusd_h1_m15_mtf_v1`. H1 is never an M15 parent,
and an open H1 candle is never available to an earlier M15 decision. Missing,
stale, uncertain, transition, or direction-conflicting H1 context resolves to
`WAIT`; range and high-volatility context may only reduce risk. The contract is
present in screening, full replay, incremental health checks, paper signal,
execution-contract and paper outcome requests, so a paper result cannot be
stronger than its replay evidence.

Every official XAUUSD M15 paper signal receives one immutable passport with
`h1_context_hash`, `h1_closed_at`, `m15_decision_at`, `m15_strategy`,
`data_hash`, `code_hash`, `parameter_hash`, `execution_hash`, risk/gate
decisions and counterfactual metadata. The `paper_mtf_shadow_observations`
ledger records executable M15-only and H1-veto-removed counterfactuals with
`promotion_evidence=false`; the H1-only lane remains a context/ablation
benchmark because it has no M15 entry topology. The ledger is idempotent and
cannot promote a candidate.

The controlled ablation command is research-only and writes an immutable run record:

```powershell
php artisan trading:mtf-ablation --candidate=<M15_PERFORMANCE_ID>
```

It compares H1-only, M15-only (frozen control), H1-regime+M15-entry and
H1-veto+M15-risk under the same data, cost and next-candle execution contract.
Each completed run is stored immutably in `mtf_ablation_runs` with its data
and execution hashes. The monitor does not treat a control advantage as
promotion evidence.
The bounded strategy catalog is run manually with
`trading:mtf-strategy-research --symbol=XAUUSD --limit=4`. It tests declared
regime-ensemble, unknown-regime consensus, breakout/range weighting, and
one-lane differential hypotheses under the same frozen candidate, data hash,
and execution hash. The run is sequential and idempotent; technical failure
is recorded as evidence recovery, never as strategy failure. The read-only
`trading:mtf-research-report` compares each result with the M15-only control,
compiles failure-to-mutation actions, and pauses a family in the report after
three attempts without a gate improvement. It never changes a gate or paper
state.
For the top three rejected near-miss candidates, use
`trading:mtf-shadow-candidates`; settle their non-promotional outcomes with
`trading:reconcile-mtf-shadow`.

The operational monitor is `trading:monitor-mtf-pilot`. It checks that the
latest H1 and M15 candles are closed/fresh, every official passport has a
causal H1 context, Risk Sentinel WAIT/veto behavior is not starving entries,
shadow outcomes are being settled, paper lifecycle evidence is visible, and
the four-lane ablation is not stale. Each run is an immutable
`mtf_pilot_monitor_runs` snapshot and updates the `mtf_pilot:XAUUSD` service
health row. Warning/critical transitions create system events and logs; no
monitor action changes strategies, gates, or promotion state.

| Laboratory | Families | Scope key |
| --- | --- | --- |
| XAUUSD Lab | Trend, Breakout, Volatility, Hybrid, Regime Ensemble, Differential Router | `XAUUSD + H1 + family` |
| EURUSD Lab | Trend, Mean Reversion, Session, Hybrid, Regime Ensemble | `EURUSD + H1 + family` |
| GBPUSD Lab | Breakout, Momentum, Volatility, Hybrid, Regime Ensemble | `GBPUSD + H1 + family` |

## Learning loop

```text
complete historical data
  -> 20-agent generation
  -> screening reason ledger + diagnostic rescue replay plan
  -> full rolling replay + CSCV/PBO and Deflated Sharpe selection checks + untouched holdout
  -> Monte Carlo and risk gates
  -> forward-validated challenger
  -> paper orders and outcomes
  -> sealed holdout
  -> same-market champion replacement
  -> mutation memory informs the next generation
```

Generation composition is fixed and gate-targeted: 8 gate-targeted mutations, 4 risk/exit mutations, 3 architecture mutations, 3 robust crossovers, and 2 random explorers. A generation starts with `draft` agents and cannot be duplicated while it is queued or training. It ranks family budget by explicit deficits: `trade_deficit = max(0, 30 - trades)`, `pf_deficit = max(0, 1.30 - PF)`, `rolling_deficit = max(0, 3 - rolling_wins)`, `drawdown_excess = max(0, DD - 15)`, and `ruin_excess = max(0, ruin - 10)`.

Composite runtimes are identity-safe: `differential_router` and `regime_ensemble` own their execution function even when a frozen parent architecture is recorded as `base_strategy`. This same canonical identity is sent to screening, full replay, paper signal/order contracts, holdout, incremental health checks, and combined portfolio replay.

Trade deficit targets entry filters; PF deficit targets stop/target/trailing/exit topology; drawdown and ruin target risk multiplier and loss cooldown; rolling deficit targets regime/session-adaptive topology; starvation targets lookback, confirmation, and confidence; overfit targets architecture diversification. Each mutation stores its parent-to-child gate transition, including improved and worsened gates. Trade milestones are `15 -> 24 -> 34`, PF milestones are `1.05 -> 1.18 -> 1.36`, and rolling-win milestones are `1 -> 2 -> 3`. A family or architecture with no gate improvement across three completed generations is temporarily excluded until new evidence changes that state.

Parent eligibility is stricter than a forward-score sort: a reusable parent must independently meet PF >= 1.3, risk of ruin <= 10%, drawdown <= 15%, 30 trades, and three rolling forward wins. Regime mutations record and reuse a `market:*` or `volatility:*` scope, prioritising the weakest sufficiently sampled regime rather than applying one global parameter change.

The parent layer is adaptive rather than champion-only. Read [[adaptive-evolution]] for the full contract. `EvolutionGovernorService` observes recent progress, stagnation and diversity; `AdaptiveParentFrontierService` then selects a dynamic K from the exact semantic cell. Causal/G98/differential repair remains one-parent, robust crossover can use 2-5 contributors, architecture/curiosity can revive young/archive lineages, and runtime ensemble policy can hold 3-8 sealed specialists. `EvolutionArchiveService` keeps convergence, diversity, young and failure archives separate. Failure evidence is preserved for diagnosis but never reintroduced as a parent. Every selected ID, module source and dynamic-K decision is recorded with `promotion_evidence=false` and all children repeat the normal replay/statistical/holdout/paper gates.

## Market-adaptive replay protocol

Full laboratory evaluation uses `market_adaptive_replay`: 2005-01-02 through
2025-12-31 is foundation training input; 2026-01-01 through the start of the
last six weeks is delivered candle-by-candle from the immutable generation
rolling snapshot. A decision is available only after a
candle closes and is executed at the next candle open. Each closed trade adds
regime/volatility-scoped fitness, mistake evidence, and a mutation recommendation.
The final six weeks are sealed: they are excluded from training, evolution, and
selection until the paper gate has passed. Dates define only the experiment
boundary; all learned evidence remains scoped to `symbol + timeframe + regime`.
The primary Twelve stream owns rolling, forward, paper, and holdout evidence.
When Twelve does not expose the long baseline, `foundation_dataset_path` points
to a separate `foundation_training_archive_v1` snapshot assembled from an
explicit historical archive or Dukascopy source. Its SHA-256 and
`promotion_evidence=false` flag are recorded per generation; it is never copied
into canonical candles or used as promotion evidence. Provider archives that
begin on the supported 2005-01-02 baseline are valid foundation inputs; a
weekend/market-open delay is not treated as an invented missing-candle
requirement.

### Full-replay runtime budget

The foundation archive is intentionally large and computationally expensive.
When its row count reaches the configured
LAB_FULL_REPLAY_BOUNDED_COHORT_FOUNDATION_ROWS threshold (default 100,000),
the evaluator selects at most LAB_FULL_REPLAY_MAX_COHORT_SIZE candidates
(default 2) for one bounded cohort. The current job is always retained in the
selected cohort, and previously sealed exact peers may be reused only when
generation, code, parameter, rolling-file, foundation-file, and runtime-policy
hashes all match. The request, result, cache, and immutable finish metadata
carry full_replay_runtime_budget_v1; the policy is operational telemetry and
always has promotion_evidence=false. A runtime cap never changes CSCV/PBO,
Deflated Sharpe, forward, paper, holdout, or champion thresholds.

Execution-contract hashes are cross-language canonical: Python normalizes
float exponent spelling to the Laravel JSON contract before hashing, and a
candidate cache is reusable only when its returned contract hash and parameter
map still match the current payload. A legacy numeric-serialization cache is
diagnostic only and is recomputed before full evidence can be accepted.

The queue WithoutOverlapping middleware uses the same LAB_REPLAY_MUTEX_KEY as
direct portfolio replay and the recovery command. After a worker interruption,
recovery is valid only when the AI service is idle, no replay child exists,
and every reserved full job is demonstrably stale. Recovered attempts are
retry_released operational evidence, not strategy evidence.

Screen jobs have a bounded six-hour retry lifetime because full validation has
priority on the shared AI lane; this is long enough to survive a large full
replay backlog without converting queue contention into `MaxAttemptsExceeded`.
The shared mutex handoff is one minute, so a completed or recovered replay does
not leave the screen lane asleep for ten minutes. Existing serialized
90-minute screen jobs are interpreted with the same six-hour bound from their
original enqueue time.

## Lifecycle and gates

`draft -> training -> challenger -> forward_validated -> paper -> champion`

Terminal states: `overfit`, `rejected`, `stagnated`, `archived`.

A challenger replaces a champion only in the same `symbol + timeframe + strategy_family` slot after all of these pass:

- forward score is at least 5 points higher than the current champion;
- it wins all 3 required rolling forward windows;
- forward PF >= 1.3, drawdown <= 15%, risk of ruin <= 10%, and at least 30 trades;
- it is not overfit;
- when a full-validation cohort contains enough evidence, its replay-only CSCV probability of backtest overfitting (PBO) is at most 50% and its per-trade Deflated Sharpe probability is at least 95%;
- paper results have at least 50 real-time samples, PF >= 1.3, positive return, and drawdown <= 15%;
- the untouched sealed holdout passes equivalent risk/profit gates.

The old champion is archived only at promotion time; it remains active while a challenger is being proven.

For a complementary council, the combined replay creates a separate portfolio
proxy. That proxy receives its own passed portfolio passport and sealed forward
ledger only after the individual specialist passports, router evidence,
leave-one-member-out/weight-perturbation checks, and disagreement-to-WAIT
invariant pass. Individual council members never start their own paper track;
paper signals belong to the active proxy and are blocked if any member or
membership hash drifts.

Every stage writes a `candidate_gate_decisions` ledger row rather than only a generic rejected status. The decision is `passed`, `failed`, or `waiting` and uses machine-readable codes such as `FAILED_TRADE_COUNT`, `FAILED_PROFIT_FACTOR`, `FAILED_DRAWDOWN`, `FAILED_REGIME_COVERAGE`, `FAILED_STRESS_COST`, `FAILED_CALIBRATION`, `FAILED_FEED_UPTIME`, and `WAITING_FOR_SAMPLE`. A screening failure additionally creates a `diagnostic_rescue_replay` waiting record so the next targeted generation has a specific remediation objective.

The certified coverage passport keeps the fine `regime × volatility × session × direction` cells for diagnosis, then uses only a declared, evidence-backed hierarchy (`regime|volatility|session|direction` → `regime|volatility|direction` → `regime|direction` → `regime`) when a finite sample is too sparse. Every observed cell must map to trade or abstain evidence; unobserved cells remain `WAIT`. Paper signal generation consumes the same sealed effective cells, so an unseen paper envelope cannot quietly create an order. This is a statistical evidence repair, never a lower promotion threshold.

Fresh parentless cohorts use the explicit `exact_group_root_default` lineage
contract. Failure-context/archive labels remain diagnostic metadata and never
turn a legacy candidate into genetic material. The historical quality gate and
live continuity state consume one canonical market-session calendar; scheduled
FX holiday closures (including the Christmas-Eve afternoon boundary) are not
reported as missing candles or left in `catching_up`.

## Statistical selection controls

Full validation submits the selected cohort from one generation in a single AI-service request. Four chronological replay checkpoints (all before the sealed six-week holdout) form the candidate-by-checkpoint score matrix. CSCV selects on one half of those checkpoints and ranks the selected candidate on the complementary half; PBO is the fraction of splits where the selected candidate finishes below the out-of-sample median. Deflated Sharpe uses cost-inclusive per-trade equity returns, the cohort's observed Sharpe distribution, skewness, and excess kurtosis. A missing cohort or insufficient checkpoint count is explicitly recorded as `insufficient_data`/`not_applicable_single_trial`; it is never represented as a successful statistical test.

## Cadence

- Hourly: candle import.
- Hourly: `trading:lab-incremental` checks existing champions on recent candles and records degradation.
- After 24 new closed H1 candles or 96 new closed M15 candles, market drift, or three consecutive degraded checks: `trading:lab-generation` creates at most one pending generation per laboratory. H1 remains the baseline/regime lane; M15 has its own price/volume foundation and uses only the last closed H1 regime as context. Both paths wait for the previous generation to finish rather than overlapping populations.
- Every five minutes: `trading:dispatch-lab` screens draft agents in the shared FIFO screening lane; `trading:dispatch-full-validation --timeframe=H1` and `--timeframe=M15` select only screened candidates for the sealed full replay/council gates.
- Every five minutes: `trading:paper-monitor` opens/reconciles simulated or configured practice-broker paper orders.
- Every fifteen minutes: `trading:reconcile-mtf-shadow` settles executable shadows, then `trading:monitor-mtf-pilot` records closed-H1 alignment, M15 freshness, veto/WAIT behavior, passport integrity, paper lifecycle, and ablation-control health.
- Hourly: `trading:mtf-shadow-candidates --limit=3` refreshes the top rejected near-miss shadow twin; observations remain research-only.
- Daily: `trading:mtf-ablation` runs the four controlled XAUUSD lanes with the sealed cost/next-candle contract; its immutable result is research-only.
- Daily: `trading:mtf-research-report` summarizes the bounded MTF hypothesis history and evidence budget; expensive hypotheses remain operator-triggered.
- Hourly: `trading:release-holdouts` releases a paper-passed finalist's untouched holdout exactly once.
- Every five minutes: `trading:watch-lab-lifecycle` audits abandoned evaluator runs, missing forward ledgers, paper capture gaps, and invalid paper-order identities; repairs are bounded and never create quality evidence.
- The same watchdog also audits active portfolio proxy/member contracts. Its repeated archive checks reuse a lean snapshot and bulk lookups; a drift finding is critical observability only, and routing stays fail-closed until the sealed portfolio replay is refreshed. Missing evidence tables are surfaced as a schema finding instead of allowing the scheduler to crash.

## Required workers

Lab evaluations are database-queue jobs. Keep the headless scheduler and one
priority replay coordinator running. It reads sealed full-validation work before
the shared FIFO screening lane; the old symbol queues remain accepted only while
draining legacy rows:

```powershell
php artisan schedule:headless-work
# One coordinator prevents separate workers from polling the same replay mutex.
php artisan queue:work database --queue=lab-full-validation,lab-screening,lab-xauusd,lab-eurusd,lab-gbpusd --sleep=1 --tries=0 --timeout=4200 --max-time=3600
```

The M15 foundation is stored as `storage/app/lab-datasets/foundation/*_M15_2025-foundation.csv`; it is separate from the rolling snapshot and is never treated as promotion evidence. H1 regime data is passed separately and delayed until the H1 candle is closed. A screen without the generation-frozen H1 regime hash is automatically rescreened before full selection.

The Python AI service must also be available at `AI_SERVICE_URL` before a full evaluation, incremental check, paper signal, or holdout can run.

## Main files

- Population and mutation selection: `backend-laravel/app/Services/LabPopulationService.php`
- Adaptive parent/governor/archive layer: `backend-laravel/app/Services/AdaptiveParentFrontierService.php`, `EvolutionGovernorService.php`, `EvolutionArchiveService.php`, [[adaptive-evolution]]
- Full evaluation: `LabAgentEvaluationService.php`
- Daily incremental health: `LabIncrementalEvaluationService.php`
- Champion gates and mutation memory: `MarketChampionService.php`
- Paper-order execution: `PaperTradingExecutionService.php`
- MTF contract and fail-closed response guard: `MultiTimeframePilotService.php`
- Immutable MTF passport/shadow ledger: `PaperMtfLedgerService.php`, `PaperSignalPassport.php`, `PaperMtfShadowObservation.php`
- MTF monitoring, ablation and strategy research history: `MtfPilotMonitoringService.php`, `MtfPilotMonitorRun.php`, `MtfAblationRun.php`, `MtfStrategyResearchService.php`, `MtfStrategyResearchRun.php`, `MtfStrategyResearchReportService.php`
- Python H1/M15 routing: `ai-service-python/app/services/multitimeframe.py`
- UI: `resources/views/ai-laboratory/show.blade.php`
