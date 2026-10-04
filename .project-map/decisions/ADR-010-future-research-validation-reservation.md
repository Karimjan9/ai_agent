# ADR-010: Preregister activation validation without crossing the paper epoch

- Status: accepted
- Date: 2026-09-26

## Context

A four-arm activation result can identify a behavioral hypothesis, but the
existing conversion work item was blocked with no window reserved. Replaying
the discovery archive is not independent validation. The project constitution
also makes 2026 paper-only and forbids using those candles for mutation or
research selection.

## Decision

Before constructing C/A/B/AB, seal a deterministic validation plan tied to
the source response, dataset, MTF and execution hashes. The plan fixes the
first six calendar months after the 2026 paper epoch, six independent monthly
windows, the configured power thresholds, exact control, blinded comparator,
and identical cost/risk contracts. Bind its hash into every arm manifest and
the eventual conversion receipt. The future interval is a reservation of a
question, not an authorized dataset or replay. Keep its durable work item
blocked until a separate research epoch and dataset can be approved and
content-addressed. Never reinterpret an old paper or discovery response as
the missing validation receipt.

## Consequences

- Current generations cannot gain causal, economic or parent authority from
  a preregistered interval alone.
- A future executor must verify authorized data provenance, disjoint time,
  frozen MTF/execution hashes and complete paired receipts before releasing
  this work item. That executor and new data do not yet exist.
- The 2026 paper-only boundary remains intact.
