---
aliases:
  - Dukascopy Continuity
  - Candle Recovery
tags:
  - market-data
  - dukascopy
  - reliability
updated: 2026-10-04
---

# Market Data Continuity

## Scope

`MARKET_DATA_CANONICAL_PROVIDER=twelve` is the current promotion-evidence owner; Dukascopy is retained as secondary archive and discrepancy evidence and cannot silently replace a missing canonical candle. Long-history foundation training is a separate immutable CSV lane with its own hash and `promotion_evidence=false`; it never repairs or backfills the canonical candle table. `market_data_sync_states` is the persistent source of truth for each `provider + symbol + timeframe` recovery lifecycle.

The database-backed training lane is stored separately in
`market_training_archives` and `market_training_candles`. It is resumable via
an explicit backfill cursor and remains `foundation_training_only`; it must not
silently replace the canonical Twelve stream. Use
`market-data:training-coverage` to inspect it and
`market-data:export-training` to create an agent-ready CSV.

For the current XAUUSD ten-year lane:

```text
php artisan market-data:backfill-training --symbol=XAUUSD --timeframe=M15 --max-chunks=1
php artisan market-data:backfill-training --symbol=XAUUSD --timeframe=H1 --max-chunks=1
php artisan market-data:training-coverage --symbol=XAUUSD --json
php artisan market-data:export-training XAUUSD --timeframe=M15
```

`market-data:backfill-training` and `market-data:backfill-intraday-training`
are native Dukascopy fetch paths, not a provider registry. They accept only the
exact `--provider=dukascopy` identity and reject another label before provider
I/O or archive/cursor writes. Passing `--provider=twelve` does not select Twelve
Data. Keep a different provider under a new, separately attributable dataset;
do not reuse the Dukascopy foundation identity or splice its candles into a
native recovery fork.

Existing Twelve Data M1/M5 API observations can establish that a particular
historical window is available, not that the entire archive is continuous or
that synchronized BID/ASK ticks were observed. The generic training service
can store explicitly attributed pre-2026 provider rows, but
`market-data:import-training-csv` currently accepts only H1/M15. M1/M5 ingestion
needs an explicit owner; the native backfill commands are not a substitute.
The current prospective clean-discovery freezer requires a verified native
Dukascopy recovery receipt and Dukascopy context streams. A separate Twelve
archive/import is therefore not automatic repair or new research admission;
it grants no independent, paper, promotion or trading authority. Re-exposure
does not reset the existing scientific attempt budget.

`recover-frozen-m5-gap.php --batch --raw-tick-evidence=MANIFEST
--raw-tick-evidence-sha256=SHA --resume-dataset=DATASET` is the explicit offline
v3 native tick reconstruction owner. It accepts only hash-bound official
Dukascopy Jetta/BI5 resources, checks observed native M1 against actual
synchronized tick aggregates, and creates a new archive. Omitted candle
minutes come from actual ticks; flat carry-forward zero-volume bars never
become observed price proof. Keep referenced raw evidence under durable
storage because future fork/readiness/resume reopens it. Neither observed
minute coverage nor a new fork certifies uninterrupted tick history, inherited
quotes or independent evidence (ADR-026).

Laravel agents can read the same rows through
`CandlePayloadService::candlesForTraining(...)`; the dataset and provider are
explicit arguments, so a training archive is never confused with live candles.

## States

Explicit alternative-source authorization may also use
`scripts/recover-secondary-m5-research.php` to create a separately attributed
`mixed` archive from a verified native fork and actual Twelve Data responses.
This owner preserves native prices, reopens raw evidence and verifies the
entire residual calendar inventory, derivative CSV and SQL economic digest.
Actual sparse provider M5 remains distinct from complete five-minute M1
coverage. The existing clean-discovery freezer seals provider/basis/response
metadata into M5 and keeps volume and unobserved quotes unavailable. Only the
typed bounded pre-2026 discovery path consumes it; native full validation is
unchanged. The original verified native discovery bundle anchors the existing
Academy question cap, so the new mixed hashes do not create fresh attempts
(ADR-027).

- `healthy` — latest requested live range has no missing market-open H1 candle.
- `offline` — provider fetch raised an error; the requested range is retained for retry.
- `catching_up` — provider responded, but an open-market hour remains missing and must be recovered before learning continues.

Each state stores last confirmed candle, pending start/end, retry count, last error, attempt/success times, and metrics.

## Recovery flow

```text
hourly canonical-provider sync
  -> pending range exists? fetch it first : fetch after latest candle
  -> idempotent candle upsert
  -> verify every open-market H1 hour
  -> healthy OR catching_up
  -> next hourly run retries pending range first
```

Only trading-session hours are expected: Saturday is closed, Sunday begins at 22:00 UTC, Friday closes at 22:00 UTC. This prevents weekend gaps from becoming false outages.

## Historical repair

Run `php artisan market-data:quality --json` before dispatching a full lab
evaluation. If it reports a bounded hole, repair only that interval with
`php artisan market-data:repair-gaps SYMBOL --max-ranges=1`; the command is
restricted to the configured canonical provider. A non-canonical provider is
rejected so a secondary archive cannot pollute the promotion stream. Build a
separate foundation archive through `LabDatasetExportService::ensureFoundationDataset`
when the long baseline is unavailable; that archive remains training-only.

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
