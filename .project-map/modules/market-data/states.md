# Market-data continuity states

| From | Trigger | To | Guard / owner | Failure or compensation |
| --- | --- | --- | --- | --- |
| healthy | scheduled audit detects a bounded missing range | catching_up | `MarketDataContinuityService` creates explicit pending range | Existing valid candles remain usable only under the documented rules. |
| catching_up | canonical provider fetch succeeds and continuity validates | healthy | Provider, calendar and range checks agree | Do not accept a secondary provider as an implicit repair. |
| catching_up | provider unavailable or fetch invalid | offline or retry_pending | Continuity service records failure and retry metadata | New affected laboratory construction remains blocked. |
| offline | provider recovers | catching_up | Canonical provider remains configured | Recovery starts from recorded pending bounds. |
| any | scheduled market closure | healthy with closure-aware audit | Shared session calendar owns interpretation | A closure must not stay reported as a false outage. |
| price_foundation_ready | no volume lane requested | volume_not_requested | Replay request owns the lane decision | Missing historical volume metadata is non-blocking and must not be reported as unavailable capability. |
| price_foundation_ready | volume lane requested | historical_volume_auditing | Dataset freezer verifies provider identity, numeric usability and exact source hash | Live/rolling coverage cannot satisfy this guard. |
| historical_volume_auditing | coverage and provenance pass | historical_volume_ready | Frozen CSV contains `volume_available`; manifest binds provenance to CSV SHA-256 | Only this sealed snapshot may be replayed under the receipt. |
| historical_volume_auditing | marker, provenance or threshold fails | volume_unavailable | Historical snapshot gate owns failure | Price-only replay remains possible; volume-dependent replay fails closed. |
| price_foundation_ready | bounded pre-2026 synchronized quote fetch | quote_sidecar_frozen | Offline freezer seals M5/source/decoder/sidecar hashes and hourly checkpoints | Missing/stale quotes remain unavailable; BID/ASK bar closes are diagnostic only. |
| quote_sidecar_frozen | new agent-owned bundle freeze | quote_snapshot_bound | Exact price-only M5 SHA-256, artifact integrity, BID and as-of alignment must agree before the new manifest is sealed | Never attach to an already admitted generation; mismatch fails closed. |
| quote_snapshot_bound | replay feature preparation | observed_or_partial_liquidity | Python validates quote markers, synchronized BID/ASK, age <=60 seconds and availability at M5 close | Unavailable is unknown, not liquid; sealed execution costs and authority gates are unchanged. |
| frozen_training_source_refused | offline provider and raw-tick proof agrees for actual missing buckets | prospective_training_fork | Explicit bounded recovery operation; old CSV, rows and generation receipts remain immutable | No synthetic empty minutes or fake complete-minute coverage. Unrecovered gaps remain an explicit scope dependency. |
| prospective_training_fork | non-default training dataset selected before a new cohort | prospective_m5_verified | Existing MTF owner verifies receipt, old/new file hashes and actual database economic-row digest | Dataset labels alone fail closed; old quote sidecars cannot transfer to new price bytes. |
| prospective_m5_verified | new price-only freeze followed by exact-hash quote sidecar freeze | new_quote_snapshot_bound | Existing MTF and quote owners seal a new bundle only | Does not reopen old trial, reset hypothesis budget or create independent/paper authority. |

The persisted `market_data_sync_states` rows and their migrations are the exact
state authority. Add a transition only together with its storage, service and
test behavior.
