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
