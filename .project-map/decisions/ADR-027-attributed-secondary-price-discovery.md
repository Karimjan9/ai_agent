# ADR-027: Attributed secondary prices for bounded discovery

Date: 2026-10-04
Status: accepted

## Decision

When the user explicitly authorizes alternative historical sources, a separate
`mixed` research archive may supplement unresolved native M5 buckets with actual
Twelve Data observations. It preserves the verified native parent's economic
rows and native recovery proofs, seals raw secondary responses, and recomputes
each added M5 from complete observed M1 or an actual provider M5 corroborated by
its sparse observed M1. Sparse M1 coverage remains explicit; missing minutes
are never generated.

The secondary recovery owner verifies the native parent, exact calendar target
inventory, source bytes, row attribution, derivative CSV and SQL economic digest.
Frozen discovery M5 rows carry their provider, price basis and source response
hash. Composite mid-prices remain attributed to Twelve Data and do not attest
native Dukascopy BID/ASK, volume or observed spread.

Only the existing typed clean-discovery contract may consume this archive:
15000 evaluated M5 rows plus 512 warmup, closed H4/H1/M15 and pre-2026 discovery
authority. Ordinary native full-validation readiness remains native-only.
Missing volume and quote observations cannot inherit the parent sidecars.

The verified original native discovery bundle anchors the question's existing
physical-data budget. New source or mixed-bundle hashes cannot reset its cap.
Original CSVs, generations, trial receipts and the 2026 paper boundary remain
immutable.

## Consequences

The research archive may have zero structural calendar gaps while the pure
native archive still has unavailable observations. These are separately
reported facts. Mixed discovery supplies usable research input without
claiming full, independent, promotion, paper or live-trading authority.
