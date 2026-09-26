# Canonical market-data continuity recovery

## Trigger

The scheduler or an operator audit detects stale, missing or inconsistent
canonical-provider candles for a symbol/timeframe.

## Sequence

1. Read the persisted sync state and shared market-session calendar.
2. Decide whether the interval is a valid closure, a pending gap or an outage.
3. Request only the configured canonical provider for the bounded pending range.
4. Validate persistence, range continuity and provider attribution.
5. Mark the state healthy only after validation; otherwise persist retry/offline
   evidence and retain the pending range.
6. Let dependent laboratory admission read this state rather than guessing data quality.

For historical volume replay, a separate freeze path starts from an immutable
Dukascopy training archive, audits the exact rows, writes an explicit
`volume_available` marker and seals source/snapshot hashes in
`historical_volume_snapshot_provenance_v1`. It never imports the rolling/live
coverage result into the historical receipt.

## Rules

- The canonical provider is the only automatic repair source.
- Secondary provider data is discrepancy/archive evidence, not a silent replacement.
- A continuity failure blocks new affected research construction but does not
  manufacture or rewrite historical evidence.
- Price-only controls remain `not_requested`; historical `volume_unavailable`
  is emitted only when a replay actually declares a volume dependency.
