---
aliases:
  - Dukascopy Continuity
  - Candle Recovery
tags:
  - market-data
  - dukascopy
  - reliability
updated: 2026-07-12
---

# Market Data Continuity

## Scope

Live candle internet access is supplied by Dukascopy. `market_data_sync_states` is the persistent source of truth for each `provider + symbol + timeframe` recovery lifecycle.

## States

- `healthy` — latest requested live range has no missing market-open H1 candle.
- `offline` — provider fetch raised an error; the requested range is retained for retry.
- `catching_up` — provider responded, but an open-market hour remains missing and must be recovered before learning continues.

Each state stores last confirmed candle, pending start/end, retry count, last error, attempt/success times, and metrics.

## Recovery flow

```text
hourly Dukascopy sync
  -> pending range exists? fetch it first : fetch after latest candle
  -> idempotent candle upsert
  -> verify every open-market H1 hour
  -> healthy OR catching_up
  -> next hourly run retries pending range first
```

Only trading-session hours are expected: Saturday is closed, Sunday begins at 22:00 UTC, Friday closes at 22:00 UTC. This prevents weekend gaps from becoming false outages.

When a Dukascopy fetch fails, it is explicitly reported as `offline`, not silently treated as an empty response. While a Dukascopy state is `offline` or `catching_up`, `LabPopulationService` refuses to create a new AI generation for that pair. Existing completed data and paper monitoring remain intact.

## Main files

- `backend-laravel/app/Services/MarketData/MarketDataContinuityService.php`
- `backend-laravel/app/Services/MarketData/MarketDataService.php`
- `backend-laravel/app/Models/MarketDataSyncState.php`
- `backend-laravel/resources/views/market-data/index.blade.php`
