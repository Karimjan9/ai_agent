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

`MARKET_DATA_CANONICAL_PROVIDER=twelve` is the current promotion-evidence owner; Dukascopy is retained as secondary archive and discrepancy evidence and cannot silently replace a missing canonical candle. `market_data_sync_states` is the persistent source of truth for each `provider + symbol + timeframe` recovery lifecycle.

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

## Historical repair

Run `php artisan market-data:quality --json` before dispatching a full lab
evaluation.  If it reports a bounded archive hole, repair only that interval
with `php artisan market-data:repair-gaps SYMBOL --max-ranges=1`.  This command
uses the legacy Dukascopy archive transport by default: it preserves historical
flat candles and avoids accepting a sparse Jetta monthly response as a repaired
range.  The command may use `--transport=jetta` or `--transport=auto` only for
diagnostics; it never invents candles when the selected provider has no data.

The PHP historical gate and Python backtest calendar share the same XAU/USD
maintenance and US market-holiday closures.  An ordinary weekday hole remains
a hard block, while provider-confirmed closure windows do not become false
training failures.

Lab dataset exports use a per-market non-blocking lock.  A concurrent export
fails quickly and is retried by the normal scheduler rather than leaving a
queue worker blocked indefinitely.

When a Dukascopy fetch fails, it is explicitly reported as `offline`, not silently treated as an empty response. While a Dukascopy state is `offline` or `catching_up`, `LabPopulationService` refuses to create a new AI generation for that pair. Existing completed data and paper monitoring remain intact.

## Main files

- `backend-laravel/app/Services/MarketData/MarketDataContinuityService.php`
- `backend-laravel/app/Services/MarketData/MarketDataService.php`
- `backend-laravel/app/Models/MarketDataSyncState.php`
- `backend-laravel/resources/views/market-data/index.blade.php`
