# ADR-013: Sealed research-window authority for instrument learning

- Status: accepted
- Date: 2026-09-27

## Context

Instrument posterior status previously counted distinct `independent_window_key`
strings and positive observations separately. Ordinary learning pairs had no
window key at all. A new generation on the same archive would not create
independent validation, while arbitrary labels could falsely appear to do so.
The 2026 epoch is reserved for paper observation, not research posterior updates.

## Decision

No instrument research window is authorized by default. An operator-owned
manifest must name a post-2026 UTC interval, research epoch, purpose, and exact
dataset SHA-256. The replay manifest must independently declare an authorized
research-validation source and first/last candles inside that interval. A
pre-2026 foundation replay cannot borrow the future window even if its hash is
misconfigured. The pair freezes the matching server-issued receipt by dataset
hash, including when its exact control finishes later. A
posterior observation without that receipt remains provisional. Confirmation
requires complete evidence-key coverage, distinct dataset hashes,
non-overlapping intervals and positive results in at least two of the required
three independent windows. Negative/forbidden authority likewise needs
independent negative windows. Historical receipts are not rewritten.

The successor model stores a separate policy-consumption receipt only when an
actual parameter difference matches a confirmed isolated component and exact
bundle source with the same full state key, family and venue-phase context. This is research
mutation guidance, not paper or live execution authority.

## Consequences

- Repeating an old replay or relabeling windows cannot manufacture confirmation.
- An authorized future research dataset can flow through the existing pair,
  posterior and constructor services without a second scheduler.
- No current market improvement is asserted: real post-fix independent research
  windows, exact powered pairs and a later natural successor remain acceptance
  work. The 2026 paper-only boundary stays closed to research learning.
