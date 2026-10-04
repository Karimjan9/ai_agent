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

For a spread-aware research replay, the bounded offline tick-sidecar
tool selects one synchronized Dukascopy BID/ASK tick strictly before each
frozen M5 close. It checks BID equality and age <=60 seconds, records unavailable
rows, seals source-content/M5/dependency hashes, and excludes 2026 paper data.
Completed hours have integrity-checked checkpoints and bounded retries. The
sidecar is not attached to an existing generation and does not itself grant
replay, instrument, causal or promotion authority (ADR-018).
The separate BID/ASK M1 bar-close exporter remains diagnostic only.
Before freezing a new agent-owned M5/H4/H1/M15 bundle,
`HistoricalQuoteSpreadService::attach` validates exact price-only M5 SHA-256,
artifact/sidecar hashes, research interval and quote as-of. All matching
immutable artifacts enter the new manifest; overlapping observations must
agree exactly. The M5 CSV carries explicit `spread_available`, spread,
synchronized BID/ASK, quote timestamp, availability-at-close and age. Python
validates these before feature construction; missing rows remain unknown,
not zero spread. The execution cost contract remains separately sealed.

An immutable training M5 source refused by the replay calendar is not repaired
in place. The bounded offline recovery operation compares the missing bucket
against native Dukascopy M5/M1 observations and actual raw ticks, preserves every
existing economic row, and creates a separate content-addressed training fork.
Sparse observed minutes remain explicitly incomplete; no missing minute is
filled. The receipt records full-archive and selected-screening continuity
separately, never calling a one-window repair a complete historical archive.
`LAB_RESEARCH_M5_DATASET` selects this prospective fork only after the existing
MTF owner validates native receipt, immutable source/new CSV hashes and actual
stored economic rows. A new price-only MTF freeze must precede new exact-base-SHA
quote sidecars and the final quoted freeze. Old source, sidecars and trial
receipts remain unchanged; the same-data scientific attempt cap is not reset.

Recovery resumes only successful proofs from an owner-verified prior archive:
original source, receipt, fork bytes and SQL digest must agree. Refetch unresolved
native targets only and publish a new fork. HTTP failure/empty unattested tick
responses never become permanent absence checkpoints, including quote fetches.

Explicit clean discovery is a separate contract when full continuity remains
unresolved. The shared Python calendar chooses the latest sufficient clean
segment without strategy outcomes: 15000 evaluated plus 512 warmup M5 rows,
closed H4/H1/M15, new hashes and reverified parent/SQL evidence. Missing quotes
remain unavailable; old parent quotes cannot attest the slice. The stored
`LAB_CLEAN_DISCOVERY_BUNDLE_HASH` admits bounded Academy screening only (ADR-024).

## Rules

- The canonical provider is the only automatic repair source.
- `market-data:backfill-training` and `market-data:backfill-intraday-training`
  are native Dukascopy training owners. They reject a different `--provider`
  before archive creation or fetch; an arbitrary label cannot replace their
  actual provider. Existing Twelve Data responses remain separate-provider
  observations, not native repair or independent validation proof.
- Secondary provider data is discrepancy/archive evidence, not a silent replacement.
- A continuity failure blocks new affected research construction but does not
  manufacture or rewrite historical evidence.
- Price-only controls remain `not_requested`; historical `volume_unavailable`
  is emitted only when a replay actually declares a volume dependency.
