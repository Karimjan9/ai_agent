# Cross-module flow: market-data recovery

## Purpose

Restore a bounded canonical candle gap without silently changing data source,
falsifying health or allowing new research on unverified continuity.

```text
Scheduler / operator audit
  -> Market-data continuity state
  -> Session-calendar interpretation
  -> Canonical-provider bounded fetch
  -> Persistence and continuity validation
  -> Healthy state or explicit retry/offline evidence
  -> Laboratory admission reads the resulting state
```

## Modules

- `operations`: detects and verifies runtime/feed health.
- `market-data`: owns provider, pending range, recovery state and validation.
- `laboratory`: consumes continuity state as an admission precondition.

## Invariants

- Only the configured canonical provider can auto-repair a canonical gap.
- A scheduled closure is not an outage.
- A failed recovery remains observable and blocks affected new construction.

Explicit historical recovery may publish a new native fork using revalidated
observed ticks. If the user authorizes alternative sources, the separate
secondary recovery owner can seal an attributed mixed price archive and the
existing typed clean-discovery bundle. This affects pre-2026 bounded research
only; native full-validation continuity stays separate and the original
verified native discovery bundle retains the Academy question budget.

Read `modules/market-data/flows/continuity-recovery.md` and the detailed
continuity document before editing this flow.
