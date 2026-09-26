# ADR-005: Historical volume is attested per frozen replay snapshot

- Status: accepted
- Date: 2026-09-22

## Context

The rolling/live H1 audit and immutable pre-2026 replay archives cover different
time populations. A live coverage percentage cannot prove that an older CSV has
canonical volume semantics. Conversely, a price-only control does not require
volume and must not look technically blocked merely because its CSV lacks a
volume marker.

## Decision

Historical volume admission is bound to the exact replay snapshot. Laravel
audits the frozen rows, writes an explicit `volume_available` column and seals
provider identity, quality counts, source hash and snapshot hash in
`historical_volume_snapshot_provenance_v1`. MTF bundles carry the receipt for
M5/H4/H1/M15; legacy price foundations receive a separate content-addressed
volume view so prior evidence hashes remain immutable.

Laravel supplies Python with this snapshot receipt instead of live coverage.
Python reports `volume_lane=none` as `not_requested` and non-blocking. A declared
volume lane still fails closed when the historical receipt or marker is absent.

## Consequences

- Live and historical coverage numbers are never silently mixed.
- Existing price-only snapshots and generation hashes are preserved.
- Volume replay has explicit row-level availability and reproducible provenance.
- Missing historical volume isolates the volume capability without blocking the
  no-volume baseline.
