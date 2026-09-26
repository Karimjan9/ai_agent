# ADR-008: A confirmed causal component owns its credit handoff

- Status: accepted
- Date: 2026-09-24

## Context

The three-arm confirmation path deliberately bypasses ordinary per-agent
mutation credit until all counterfactual arms settle. That protection also
left no writer for the `causal_skill_credit` required by Research Mentor.
Synthetic golden worlds injected the credit count and could not reveal this
missing live handoff. Existing ledger status strings also exceeded the
original MySQL column width.

## Decision

The confirmed post-v2 experiment is the only source of causal-skill credit.
Before issuing it, reconcile the persisted experiment with its component
escrow, guided agent, exact frozen-control pair, canonical settlement, data,
execution, gene and context hashes. The component lattice must be confirmed
and proof-carrying, including risk and source/replay-context safety. Write the
implied bounded repair credit and causal-skill credit in one idempotent
transaction. Repeated callbacks reuse the same event fingerprints. Project
Research Mentor only after credit is present. Widen the ledger status column
to preserve existing descriptive codes without truncation.
The transactional golden-world persistence proof exercises the actual bridge
and rolls its synthetic credit rows back; it remains separate from market
replay proof.

## Consequences

- A selected lesson, provisional experiment, missing settlement, risk veto or
  context mismatch earns no repair/skill credit.
- A confirmed component remains research-only; paper, performance,
  inheritance and Economic Parent require their separate prospective proof.
- Historical G226-G234 outcomes are not reclassified or backfilled.
