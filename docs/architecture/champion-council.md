# Champion Council architecture

## Meaning

Champion is a verified council, not a single global-PF agent. The active
council contains independently validated regime specialists and a
transition/risk router. A member cannot become active through council PF.

## Lifecycle

```text
apprentice
  -> specialist_candidate
  -> specialist_validated
  -> mentor_candidate
  -> council_candidate
  -> council_validated
  -> active_council
```

The `SpecialistPassportService` is an evidence projection. The
`CouncilCurriculumService` allocates research lessons only. The
`CouncilCompatibilityService` requires at least two distinct regime roles,
a transition/risk router, and no duplicate niche. None of these services can
grant promotion evidence.

## Hard council conditions

- Individual forward and passport gates pass for every member.
- At least two distinct regime specialists are present.
- A separately validated `transition_risk_router` is present.
- Combined replay passes unchanged economic and statistical gates.
- Leave-one-out, weight perturbation, loss correlation, router stability and
  contribution-cap evidence are recorded.
- Any unresolved disagreement routes to `WAIT`.

## Champion-to-council handoff

The incumbent champion is frozen as an anchor and fallback. It is not deleted
when the first council appears. The transition sequence is:

```text
incumbent_protected
  -> shadow_council
  -> hybrid_handoff (25% council canary)
  -> council_challenge (50% council canary)
  -> anchor_ablation
  -> council_active
```

The council must match the incumbent baseline within the configured tolerance,
avoid material worst-window regression, keep router switching below the
threshold, and prove that it still works after the anchor is removed. Drift,
catastrophic regression or an operator rollback request immediately returns
ownership to the incumbent.

This prevents the normal migration dip from becoming a production loss. The
council is rewarded for incremental coverage and complementarity, but it is
never allowed to buy that improvement by damaging the incumbent's proven
operating envelope.

## Research curriculum

- Technical/evidence failure: repair evidence; mutation is disabled.
- One-off strategy failure: one bounded role gene.
- Repeat failure: architecture escape with an independent holdout.
- Council disagreement: calibration/challenger/abstention lesson.
- Validated specialist: independent forward confirmation.
- Council candidate: combined replay and replaceability tests.

## Monitoring

```text
php artisan trading:monitor-champion-council XAUUSD --timeframe=H1 --json
```

The monitor reports council status, role coverage, member passport stage,
curriculum stage, compatibility and synergy evidence. `promotion_evidence` is
always false in the monitor projection.
