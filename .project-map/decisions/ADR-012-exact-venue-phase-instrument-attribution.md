# ADR-012: Exact venue-phase instrument attribution

- Status: accepted
- Date: 2026-09-27

## Context

Instrument runtime admission already checked the decision candle's exact
`venue_phase`, but paired research outcomes were grouped only by regime,
volatility, session and direction. A London-fix-only activation could therefore
be matched to trades from another London phase when assigning local value.
The old four-axis receipts remain immutable and useful as diagnostics.

## Decision

Prospective replay retains a five-axis trade-ledger envelope and exact
instrument activation key: regime, volatility, session, venue phase and
direction. The instrument trace labels this as `venue_phase_v1` while keeping
the older session slice for diagnostics. Laravel compares candidate and frozen
control only when both arms provide the same exact key and context. A
phase-scoped instrument cannot derive posterior evidence from legacy
session-only slices; an exact/legacy mixed pair also fails closed. Exact bundle
value requires the same exact activation intersection across its instruments.
Even after independent confirmation, a phase-local posterior may influence a
successor mutation only when the requested successor context explicitly owns
the same phase; a session-only request is not an exact match.

## Consequences

- Phase-local posterior identity no longer borrows trades from a neighboring
  phase in the same session. Finer slices may be underpowered more often; that
  is an honest research outcome, not a reason to relax the trade threshold.
- Existing G226-G236 receipts are not rewritten or newly promoted.
- A local paired posterior is still provisional without separately sealed,
  independent research windows. The frozen 2026 paper-only epoch remains out of
  research validation and no market edge or execution authority is conferred.
