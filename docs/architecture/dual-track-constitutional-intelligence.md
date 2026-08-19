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

## Operations

```text
php artisan migrate
php artisan trading:monitor-dual-track XAUUSD --timeframe=H1 --json
```

The monitor reports run-level disagreement, settled outcomes, cell-policy
status, evaluator calibration, memory lessons and evolution-island activity.

The active mode is intentionally not enabled by this change. It requires
independent forward/paper evidence and an operator-reviewed cell policy.
