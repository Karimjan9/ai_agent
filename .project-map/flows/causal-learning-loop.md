# Cross-module flow: causal learning loop

## Purpose

Close a research experiment as an auditable unit of knowledge: it either earns
only the authority supported by evidence, opens one bounded next action, or
records a truthful terminal/deferred result.

```text
Laboratory diagnosis / Academy plan
  -> Sealed source-context experiment contract and frozen control
  -> Cohort construction + nine durable three-arm fold jobs
  -> 9/9 immutable receipts + no-replay aggregate
  -> Evidence, parity and claim settlement
  -> Conversion receipt + transactional outbox
  -> Next work, quarantine/archive or bounded paper observation
```

## Modules

- `causal-learning`: experiment interpretation, receipt and next-work routing.
- `laboratory`: cohort lifecycle, provenance and research gates.
- `laravel-python-contract`: deterministic computation/attestation boundary.
- `confirmation-entry`: stage-specific executable behavior under study.
- `paper-execution`: paper-only observation after the separately earned gate.

## Invariants

- Work, evidence, causal claim, economic claim and authority remain orthogonal.
- A result is not promotion evidence merely because it is terminal or positive.
- Conversion must be idempotent and must not leave terminal work orphaned.
- A completed fold is never replayed after restart; partial fold sets cannot
  settle or create causal credit.
- A confirmed local lesson cannot leak into another context or be forgotten by
  a later receipt that merely omits already sealed window counts.
- Performance/Economic Parent authority is a separate 2026 paper transition,
  not a side effect of historical forward replay.
- Current code/tests decide exact behavior; this flow is the cross-module map.

Explicit historical first-exposure questions seal their model and selection
before new exposure, using today's actual clock and pre-2026 market data.
Registration then capture precede any admitted run. The original provenance
audit remains a separate issuance guard; a new registration or capture never
proves that historical data was untouched. Legacy seals retain their contract
(ADR-038). All events from 2026 onward remain paper-only (ADR-037).
