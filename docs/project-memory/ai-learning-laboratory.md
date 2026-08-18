---
aliases:
  - AI Laboratory
  - Learning Loop
tags:
  - ai-learning
  - laboratory
  - champion-challenger
updated: 2026-08-14
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
The four-lane `h1_veto_m15_risk` result is a frozen control/reference, not an automatic
winner. The default selector rotates an unseen challenger frontier across
families, excludes council seats from ordinary research, skips completed
identities on the current snapshot, and respects report-only family pauses.
An explicit `--hypothesis` remains available for a deliberate retest or technical
recovery. The frozen control is never replaced by a screening result; a
challenger must still pass the normal full replay, stress, independent
forward, paper, holdout, and champion gates.
The optional `--validate-forward` path is staged: a shared core batch is
completed first, then only a challenger with a measurable core-gate
improvement receives cost/exit/chronological diagnostics. Those diagnostics
replay only the challenger MTF lane; the immutable M15 control is referenced
from the sealed ablation snapshot and is not recomputed. Transport timeouts
are recorded as technical evidence recovery, never as strategy failure.
The target gate is explicit: stress hypotheses must improve drawdown while
preserving PF, while PF/coverage hypotheses use their declared PF/coverage
comparison. The combined council proxy has a separate admission gate and its
members cannot paper-trade individually. Control replacement is a separate
read-only check: only an official paper candidate that beats the frozen
control after cost-adjusted PF, net return and drawdown can reach operator
review; the monitor never applies replacement automatically.
Council replay also has a lab-screening queue-idle guard so its multi-seat
replay does not compete with active screening workers. An operator may use
`--allow-busy-queue` only after explicitly reviewing the queue.
Volume hypotheses add a second safety contract: the canonical volume quality
pass must also be fresh against the closed M15/H1 windows. A stale volume
context blocks a new volume replay but never invalidates an already sealed
snapshot.
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

Failure learning follows an explicit repair-anchor protocol: complete strategy failure -> technical/strategy classification -> target compiler -> immutable failed parameter snapshot -> one declared gene -> paired screen -> full replay -> independent forward confirmation. Technical or incomplete evidence never creates a mutation lesson. A failed model is therefore a repair baseline, not a genetic parent; only a confirmed repair may earn mutation credit and later parent eligibility. A normal generation is held when a terminal cohort has screening decisions but no screen pass, preventing repeated cold restarts while the failure curriculum is repaired.

The repair lifecycle has four explicit evolutionary tiers. `Repair Anchor` is
the immutable failed vector only. A passing screen creates a
`Screen-validated Seed`, which can enter full replay but cannot be a parent.
Paired full replay plus independent forward confirmation can create a
`Skill Mentor`; the mentor contributes only its compatible declared gene to a
bounded probe and never contributes genetic parent identity. Only a complete
passport and unchanged council/forward gates create a `Full Parent`.

Each anchor compiles a bounded four-member cohort: primary direction, reverse
direction, alternative gene (or architecture escape after the escape rule),
and frozen control. The control is the exact canonical anchor vector and is
research-only. All siblings share one snapshot and execution contract. A
failed sibling remains attached to the original anchor; it does not fork a new
cold anchor. Three complete cohorts without target improvement trigger an
architecture/specialist escape, while two independent forward failures
quarantine the lineage. Technical evidence consumes neither budget nor
mutation credit.

Every complete screening/full-replay observation is written to the immutable
`mutation_response_map_v1` surface: target delta, non-target regression,
regime result, direction, cohort and forward confirmation. The compiler may
reuse only a role-compatible confirmed mentor gene, and only one bounded seat
per research group receives that mentor probe; other seats remain independent
challengers. Anchor-derived mentors are normalized into the canonical
`edge_quality`, `cost_stability`, `temporal_stability`, `regime_coverage`,
`non_target_regression` or `architecture_control` role envelope before reuse.
Reports expose seed/mentor/full-parent births, repeat failures,
target deltas, zero-diff rate, response-map status and role-specific council
frontiers. These projections never relax a gate or create paper evidence.

The laboratory also has a separate two-speed learning protocol. The official
Promotion lane remains `screen pass -> full replay -> cost stress -> independent
forward -> paper -> parent`. A failed but causally useful near-miss may enter
the research-only `learning_lane_v1` only after it is paired with a same
generation control, compatible parent baseline or immutable repair anchor. The
pair ledger stores candidate/control identity, snapshot and execution hashes,
target delta, non-target regression and a state-aware failure signature. At
most one bounded near-miss per specialist role (and four per generation by
default) is dispatched to the serialized full-replay queue. Its result can
create a provisional skill lesson and, after independent confirmation, a Skill
Mentor, but it can never create forward/paper evidence or a genetic parent.
Learning and promotion candidates never share a sealed cohort cache. Monitor
the lane with `trading:monitor-learning-lane XAUUSD --timeframe=H1 --json`;
dispatch is `trading:dispatch-learning-lane XAUUSD --timeframe=H1`, and
duplicate pair projections are superseded without deleting immutable response
maps. The global generation-creation pause does not disable this existing-
candidate research lane; it only prevents new generation construction.

The learning lane now has an explicit evolution loop. `tri_memory_bandit_v1`
stores positive, negative and uncertainty observations. Three repeated causal
failures down-rank a gene, five quarantine it for the current lineage, while a
new architecture/state escape may test it again. Multi-gene observations remain
diagnostic-only and cannot create causal credit or a mentor. The mutation
compiler consumes the bandit recommendation after the existing safety and
professional-budget filters.

The final research allocation is controlled by
`risk_bounded_exploration_governor_v1`. After history exists, the mutable
frontier is assigned bounded roles: one frozen control, three targeted repairs,
one screen/proven-gene refinement, one bold explorer, one regime/volume shadow
explorer and one adversarial red-team seat. A screen pass or independent
confirmation can increase the next numeric step only inside the declared gene;
uncertainty reduces it. Bold, volume and red-team seats are research-only until
the unchanged replay and forward gates pass. Monitor the admission/backpressure
state with `trading:monitor-learning-velocity XAUUSD --timeframe=H1 --json`.

`learning_velocity_gate_v1` prevents a normal new cohort from multiplying while
a screen pass has no full-replay/forward observation. Technical errors produce a
recovery status, never a strategy lesson. Explicit recovery, data-edge audit,
controlled rescue and council handoff commands remain available because they
consume or repair the backlog. Failure fingerprints are contextual across
regime, volatility, transition, spread/liquidity, session and volume quality;
the same gene is therefore not globally quarantined from a different market
state.

The MTF council has a `mtf_shadow_council_sandbox_v1` layer. Provisional
specialists may be compared together for compatibility, but each keeps its own
hypothesis and passport boundary. The sandbox is always research-only;
combined proxy eligibility begins only after individual specialist validation.

Parent evolution is now governed by `parent_mentor_broker_v1`. A parent is a
contextual mentor, not a parameter-vector owner: it may propose one compatible
gene with a source context, expected effect and evidence hash, while the child
keeps an autonomous branch and must make a child-specific mutation. Trust is
stored by `(parent, skill, regime, session, volume, cost, snapshot-age)` in
`parent_context_trust_matrix_v1`; old evidence decays toward the neutral prior
and a failure down-ranks only its matching context.

Mentored candidates carry an immutable three-branch contract:
`autonomous`, `mentored` and `ablated`. Parent incremental value is
`mentored - autonomous`; parent credit is blocked until all branches share the
same snapshot and execution contract. Evolution credit is separated into
performance, learning and discovery events, so a clean falsification can teach
the next mutation without being mistaken for a promotion pass. Monitor this
with `trading:monitor-parent-evolution XAUUSD --timeframe=H1 --json`.

Council composition also has a leave-one-out contract. Every declared
specialist receives a same-snapshot ablation plan, and the combined proxy stays
research-only until the full council and every required exclusion are observed.
Technical recovery may keep this sandbox planned, but it cannot create
strategy credit or paper evidence.

`micro_replay_v1` expands the three sealed chronological screening windows into
a cheap 2-of-3 confirmation exam. A hard failure blocks the expensive full
replay; a deferred/failed pair is frozen for the snapshot and can only be
reopened by a new evidence cell. `trading:pump-learning-lane` is the single-seat
retry pump: it fail-closes when AI replay status is unknown, checks the shared
queue/mutex, and invokes the existing serialized dispatch only when idle.

`failure_dojo_v1` records the exact failure signature, state and expected repair
action for focused counterfactual work. `council_disagreement_memory_v1`
records specialist votes, H1 context, M15 decision, Risk Sentinel and council
decision as research memory. `gene_interaction_lab_v1` only prepares pairwise
interaction probes after two independent single-gene mentors exist; it never
mixes genes into the promotion lane automatically. Historical control coverage
can be previewed with `trading:materialize-learning-controls`; applying it is an
operator-approved ledger action and requires the queue to be reviewed first.

If the response-map migration is deployed after historical runs, first run
`trading:backfill-mutation-response-map XAUUSD --timeframe=H1 --generation=12`
as a dry-run, then repeat with `--apply`. It projects only complete immutable
response artifacts into the append-only map; it never replays a strategy,
changes a gate, attaches a parent, or opens paper promotion.

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
php artisan queue:work redis --queue=lab-full-validation,lab-screening,lab-xauusd,lab-eurusd,lab-gbpusd --sleep=1 --tries=0 --timeout=4200 --max-time=3600
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
