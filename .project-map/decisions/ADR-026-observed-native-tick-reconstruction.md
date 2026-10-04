# ADR-026: Historical M5 reconstruction from observed native ticks

Date: 2026-10-04
Status: accepted

## Decision

Add an explicit `frozen_m5_gap_recovery_v3` offline recovery receipt for
content-addressed Dukascopy forks. A candle endpoint can omit a minute while
the native synchronized BID/ASK tick resource contains actual observations.
The recovery owner may aggregate those observations without inventing prices
or copying a secondary provider into the Dukascopy identity.

The new proof binds the native instrument/hour URL, exact raw file SHA-256,
decoder schema and decoder implementation. Jetta delta JSON and legacy LZMA
BI5 records are separate validated formats. The BI5 decoder bounds decompression,
record size, chronology, hour membership, finite quotes/volumes and ASK >= BID.
Every observed native M1 price must agree with its tick aggregate. Native
volume comparison uses the existing six-decimal training storage identity.
Missing candle-endpoint minutes are explicitly aggregated from real ticks;
the resulting observed-minute coverage does not certify uninterrupted tick
history. Sparse candle-endpoint M5 remains a diagnostic, not a substitute for
the complete observed-tick aggregate.

A sparse BI5 bucket is also admissible when every observed tick minute is
represented by an agreeing, positive-volume native M1 row and the supplied
M5 is explicitly a local aggregate of exactly those rows. Its clock-minute
coverage stays incomplete. Zero-volume carried-forward minutes are excluded;
conflicting or unmatched native minutes refuse the proof.

The existing strict v1/v2 proofs remain unchanged. Raw-tick proofs cannot be
inserted into an old v2 receipt. Fork/readiness/resume reopens the hash-bound
raw evidence and revalidates the proof. A new fork preserves every original
economic row, its SQL digest and old source hash, and records residual calendar
gaps. The MTF owner admits only verified receipts and actual stored rows.

## Consequences

Unavailable raw ticks and flat carry-forward zero-volume provider bars do not
become invented observed candles. Provider mirrors require separately verified
provenance; Twelve composite mid-prices cannot become native Dukascopy BID.
Full-archive continuity, exact-hash quote availability and independent research
remain distinct checks. Recovery does not alter old generations, scientific
attempt budgets, the 2026 paper boundary or promotion/trading authority.
