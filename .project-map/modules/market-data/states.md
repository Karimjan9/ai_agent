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

The persisted `market_data_sync_states` rows and their migrations are the exact
state authority. Add a transition only together with its storage, service and
test behavior.
