# Market-data history

## 2026-07 to 2026-08 — Continuity became an explicit safety gate

Gap ranges, provider outage state and the shared FX session calendar were made
durable so a stale or partial feed cannot silently seed new research. Detailed
reasoning is retained in `docs/project-memory/market-data-continuity.md`.

## 2026-09 — Project Map adoption

The compact continuity flow and state model were added as navigation aids; the
provider policy itself is unchanged.

## 2026-09 — Historical volume provenance boundary

Volume-capable foundation and closed-MTF snapshots now carry an explicit
`volume_available` column plus a receipt bound to their own SHA-256. Rolling
live coverage no longer attests pre-2026 replay data, while price-only controls
report `not_requested` instead of a false capability failure.

## 2026-09 — Research-only historical quote sidecar

An offline bounded freezer verifies synchronized pre-2026 Dukascopy BID/ASK
ticks against immutable M5 BID closes, with maximum quote age 60 seconds and
integrity-checked hourly checkpoints. The M1 bar-close exporter is diagnostic
only. New agent-owned bundles admit matching tick artifacts before sealing,
preserve quote availability and provenance in the CSV/manifest, and Python
checks as-of alignment before using the observation. Existing generation
evidence and sealed cost assumptions are never rewritten (ADR-018).

## 2026-10-03 — Prospective sparse historical M5 recovery

Actual native sparse M5 bars may be recovered from agreeing provider/minute/tick
observations into a new content-addressed training archive. Selection requires
the existing MTF owner's native receipt, old/new CSV hashes and actual economic
row digest; selected-window and full-archive continuity are separate. New bytes
require new quote sidecars. Old generations, price rows, hypothesis caps,
independence policy and authority remain unchanged (ADR-022).
