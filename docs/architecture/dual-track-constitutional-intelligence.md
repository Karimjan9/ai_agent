# Dual-Track Constitutional Intelligence

The paper runtime now observes two explicit decision lanes over the same
immutable market snapshot:

```text
frozen snapshot + constitution
          ├── Champion: raw strategy / execution lane
          └── Council: typed specialist / risk-governor lane
                         ↓
                 deterministic adjudicator
                         ↓
             incumbent-owned shadow routing
```

## Safety contract

- `DUAL_TRACK_MODE=shadow` is the default.
- Both lane outputs are stored in `dual_track_runs`.
- The incumbent remains the only paper execution owner in shadow mode.
- Opposite actionable decisions and actionable-vs-WAIT disagreements resolve to
  `WAIT` in the dual-track projection.
- Missing constitution/snapshot integrity or catastrophic-regression evidence
  blocks the projection.
- No dual-track projection can set `promotion_evidence=true`.

## Capability cells

Evidence is grouped by:

```text
symbol | timeframe | market_regime | volatility_regime | task_type
```

This allows a later active router to select Champion, Council or hybrid per
cell instead of declaring one global winner. Active routing must still pass the
existing Champion Council transition, canary, baseline-parity and anchor-
ablation contracts.

## Outcome and evolution control plane

The projection is connected to a post-trade evidence loop:

```text
dual_track_runs
      ↓ settlement
dual_track_outcomes
      ├── cell policy + Wilson lower bound
      ├── evaluator calibration / reputation
      ├── layered memory lesson
      └── evolution island event
```

- `dual_track_outcomes` records Champion and Council counterfactual outcomes,
  including avoided loss, missed opportunity, regret and risk.
- `dual_track_cell_policies` learns a per-cell recommendation. A cell is only
  certified after minimum samples, a conservative lower-bound margin and risk
  checks pass.
- `DualTrackRiskShieldService` is an independent fail-closed admission layer.
  In active mode it can return `WAIT` or reduce size when constitution,
  snapshot, calibration, confidence or drawdown evidence is insufficient.
- `dual_track_evaluator_calibrations` prevents an uncalibrated judge from
  authorising active routing.
- `dual_track_memory_lessons` and `dual_track_evolution_events` are research
  outputs only. They cannot mutate parent constitution, model versions or
  promotion gates automatically.

The system evolves through bounded capability cells and isolated research
islands rather than uncontrolled global self-modification. Promotion-relevant
records remain explicitly marked `promotion_evidence=false` until an external
operator-reviewed promotion workflow exists.

## Twin Intelligence Operating System

Champion and Council are now treated as two organisms inside one governed
system:

```text
Shared Constitution / Safety / Snapshot
       ├── Champion: execution organism
       │     ├── execution_robustness objective
       │     ├── execution-scoped memory and reward
       │     └── bounded execution evolution
       └── Council: reasoning-governance organism
             ├── collective_reasoning_quality objective
             ├── institutional disagreement memory and reward
             └── role/composition evolution
                         ↓
              typed controlled exchange packets
                         ↓
                adjudicator + risk shield
```

The `TwinIntelligenceProfileService` defines immutable lane identity,
curriculum, lifecycle, reward weights, error taxonomy, transfer policy and
promotion policy. `LaneSpecificRewardService` ensures that a Champion execution
loss and a Council oversight failure are not learned as the same error.

`DualTrackExchangeService` allows only versioned capability packets to cross
the boundary: risk warnings, regime constraints, abstention rules and
execution feasibility may transfer, but agent status, promotion evidence and
private memory never transfer automatically. `DualTrackLaneCreditService`
assigns counterfactual credit per lane, while `DualTrackDiversityService`
detects behavioral collapse and records productive dissent.

## Independent inference and evolution evidence plane

Each paper observation now carries two lane contracts from the Python service:

- Champion receives execution parameters, execution policy and a bounded fast
  reasoning budget.
- Council receives regime, volatility, specialist-role, falsification and risk
  context with a larger governance budget.
- Both lanes share the same immutable market snapshot identity, but persist
  separate process IDs, context hashes, prompt hashes and output hashes in
  `dual_track_inference_observations`.

The remaining evolution evidence is durable and queryable:

```text
inference observations
        ├── member leave-one-out credit
        ├── behavioral distance + memory overlap + Council redundancy
        ├── organism health vector
        ├── MAP-Elites genome archive
        ├── gene cemetery / regime-aware resurrection candidates
        ├── provisional → confirmed reflection lessons
        └── bounded red-team stress trials
```

`dual_track_member_credits` measures the marginal value of each Council seat,
not only the lane result. `dual_track_genome_archives` keeps one elite and a
novel frontier per `lane × capability cell × behavior cell`; it does not erase
different high-quality regimes. Failed genomes are recorded in
`dual_track_gene_cemeteries` and can only be reconsidered as a new research
hypothesis. Reflection lessons require independent repeated outcomes before
they become confirmed. Red-team trials are holdout/lookahead-free challenges;
their presence can never directly promote a model.

The `OrganismHealthService` exposes edge, risk, calibration, diversity,
recovery speed, learning velocity and regret control separately. A scalar
health score is only a dashboard summary: promotion remains blocked unless the
cell has the required outcomes, evaluator calibration, forward evidence,
diversity and completed red-team trials.

## Operations

```text
php artisan migrate
php artisan trading:monitor-dual-track XAUUSD --timeframe=H1 --json
```

The monitor reports run-level disagreement, settled outcomes, independent
inference hashes, member credits, genome archive/cemetery status, organism
health, reflection maturity, red-team trials, cell-policy status, evaluator
calibration, memory lessons and evolution-island activity.

## Research principles applied

- Multi-agent debate research supports independent proposals plus adjudication,
  but controlled studies warn that diversity and intrinsic strength matter more
  than simply increasing debate structure; this is why Council has role
  passports, dissent and anti-collapse metrics. See [Multiagent Debate](https://arxiv.org/abs/2305.14325)
  and [Controlled Debate Study](https://arxiv.org/abs/2511.07784).
- Reflexion shows how external feedback can become episodic reflective memory;
  here that idea is constrained by lane namespaces, verified outcomes and no
  automatic promotion. See [Reflexion](https://arxiv.org/abs/2303.11366).
- Counterfactual credit assignment motivates comparing each lane against the
  other lane rather than attributing a shared result equally. See [COMA](https://arxiv.org/abs/1705.08926).
- MAP-Elites motivates preserving high-quality solutions across behavioral
  cells instead of searching for one global winner. See [Illuminating Search
  Spaces by Mapping Elites](https://arxiv.org/abs/1504.04909).
- Confidence is not treated as truth: evaluator calibration is measured before
  active trust, following the calibration findings in [On Calibration of
  Modern Neural Networks](https://proceedings.mlr.press/v70/guo17a.html).

The active mode is intentionally not enabled by this change. It requires
independent forward/paper evidence and an operator-reviewed cell policy.

## Evolution runtime v2

The runtime now exposes dedicated Python lane endpoints:

```text
/api/paper/twin/champion
/api/paper/twin/council
/api/paper/twin/red-team
/api/paper/twin/ablation
```

Laravel invokes Champion and Council concurrently with the same sealed request
and rejects the pair if either lane fails or their snapshot hashes differ.
Evidence work is persisted in `dual_track_evidence_work_items` and processed
by `trading:process-dual-track-evidence`; retries, leases and blocked reasons
are durable.

`dual_track_cell_statistics` is the O(1) materialized aggregate used by cell
policy updates. `dual_track_drift_states` runs lane-scoped CUSUM with the
`healthy -> risk_reduce -> quarantine -> recover` state machine. Parent
symbol/timeframe/regime evidence is exposed as research guidance only and can
never authorize promotion. Memory lessons enter a priority queue based on
failure, regret, dissent and uncertainty. Strong gene proof records bootstrap
profit-factor bounds, deflated-Sharpe probability and PBO before forward
promotion can pass.
