# ADR-018: Historical observed spread is a separate research artifact

- Status: accepted
- Date: 2026-09-28

## Context

Specialist cells can require `liquid`, but the current immutable M5 CSV carries
OHLCV and volume availability only. A modeled execution cost is not an
observed bid-ask spread. G240's sealed 20-seat cohort cannot be modified after
queue admission, even if a new source becomes available.

## Decision

Freeze bounded, pre-2026 Dukascopy synchronized BID/ASK ticks as a separate
research-only sidecar. For each M5 candle use the last tick strictly before
its close, require age <=60 seconds and BID equality with the frozen M5 BID
close. Missing, stale, mismatched, invalid or negative rows are unavailable.
Seal the immutable M5 hash, normalized tick-content hashes by hour, freezer,
decoder/dependency hashes, sidecar hash, interval and coverage. Bounded
backoff and integrity-checked hourly checkpoints avoid repeated downloads
after interruption. The freezer never rewrites an existing M5 CSV or
generation request and never reads 2026 paper-only data.

The earlier Jetta BID/ASK M1-close sidecar is diagnostic only. Separately
closing bars do not establish a simultaneous executable quote and cannot
satisfy the runtime's observed-liquidity requirement.

This artifact is a prerequisite, not a replay permission. New agent-owned
bundles admit it only when its source hash equals the exact price-only M5 CSV.
The new manifest seals the provenance and the M5 CSV preserves row markers,
synchronized BID/ASK, quote timestamp, close-time availability and quote age.
Overlapping immutable sidecars must agree; drifted bytes or alignment reject
the freeze. Python rechecks the provenance and as-of row semantics before
features. Missing observations remain a fail-closed data dependency and
cannot borrow the modeled execution spread. The execution cost contract is
unchanged. Neither the sidecar nor a synthetic fixture earns causal, paper or
live authority.

## Consequences

- The current G240 evidence remains exactly as sealed.
- The quote-source feasibility can be audited without weakening `liquid`.
- Full historical/independent validation still requires corresponding
  authorized, non-overlapping research data and separate confirmation gates.
