const test = require('node:test');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const { observationsForHour, boundedInterval, checkpointForTicks, validateTickCheckpoint, fetchVerifiedTickCheckpoint } = require('./freeze-historical-tick-spread.cjs');
const start = Date.parse('2025-12-22T13:05:00.000Z');
const hour = Math.floor(start / 3600000) * 3600000;
const fixtureTick = (timestamp = start + 299999) => ({ timestamp, bidPrice: 4400, askPrice: 4400.67 });
const hash = (value) => crypto.createHash('sha256').update(JSON.stringify(value)).digest('hex');

test('last synchronized quote before M5 close matches frozen BID without future leakage', () => {
  const rows = observationsForHour(new Map([[start, 4400]]), [
    { timestamp: start + 299999, bidPrice: 4400, askPrice: 4400.67 },
    { timestamp: start + 300000, bidPrice: 4500, askPrice: 4501 },
  ]);
  assert.equal(rows[0].spread_available, 1);
  assert.equal(rows[0].spread, 0.67);
  assert.equal(rows[0].quote_age_ms, 1);
  assert.equal(rows[0].available_after_utc, '2025-12-22T13:10:00.000Z');
});

test('missing, stale, mismatched and invalid quotes remain unavailable', () => {
  const candles = new Map([[start, 4400]]);
  for (const [ticks, status] of [
    [[], 'missing_quote'],
    [[{ timestamp: start, bidPrice: 4400, askPrice: 4401 }], 'stale_quote'],
    [[{ timestamp: start + 299999, bidPrice: 4401, askPrice: 4402 }], 'bid_mismatch'],
    [[{ timestamp: start + 299999, bidPrice: 4400, askPrice: 4399 }], 'negative_spread'],
    [[{ timestamp: start + 299999, bidPrice: NaN, askPrice: 4401 }], 'invalid_quote'],
  ]) {
    const row = observationsForHour(candles, ticks)[0];
    assert.equal(row.observation_status, status);
    assert.equal(row.spread_available, 0);
    assert.equal(row.spread, null);
  }
});

test('out-of-order tick history is rejected', () => {
  assert.throws(() => observationsForHour(new Map([[start, 4400]]), [
    { timestamp: start + 2 }, { timestamp: start + 1 },
  ]), /ordered/);
});

test('research boundaries reject paper-2026, invalid dates and unbounded downloads', () => {
  assert.equal(boundedInterval('2025-12-01', '2026-01-01').end, Date.parse('2026-01-01T00:00:00.000Z'));
  for (const [from, to] of [['2026-01-01', '2026-01-02'], ['2025-11-01', '2026-01-01'], ['2025-02-30', '2025-03-02']]) {
    assert.throws(() => boundedInterval(from, to));
  }
});

test('positive provider ticks produce a validated checkpoint and synchronized quote', async () => {
  const ticks = [fixtureTick(start + 1), fixtureTick()];
  let requested;
  const provider = {
    Timeframe: { tick: 'tick' }, Format: { json: 'json' }, Price: { bid: 'bid' },
    getHistoricalRates: async (options) => { requested = options; return ticks; },
  };
  const checkpoint = await fetchVerifiedTickCheckpoint(provider, hour, { sleep: async () => assert.fail('No retry needed') });
  assert.equal(requested.retryCount, 1);
  assert.equal(requested.failAfterRetryCount, true);
  assert.equal(requested.useCache, false);
  assert.equal(checkpoint.source_sha256, hash(ticks));
  assert.equal(checkpoint.source_tick_count, 2);
  assert.equal(checkpoint.last_ticks.length, 1);
  assert.equal(observationsForHour(new Map([[start, 4400]]), checkpoint.last_ticks)[0].spread_available, 1);
});

test('actual zero-retry library HTTP 429 behavior cannot create a checkpoint', async () => {
  const native = require('dukascopy-node');
  const originalFetch = global.fetch;
  const pauses = [];
  let requests = 0;
  let attempts = 0;
  global.fetch = async () => { requests++; return { status: 429 }; };
  const provider = {
    ...native,
    getHistoricalRates: async (options) => {
      attempts++;
      assert.equal(options.retryCount, 1);
      assert.equal(options.failAfterRetryCount, true);
      return native.getHistoricalRates({ ...options, pauseBetweenRetriesMs: 0 });
    },
  };
  try {
    await assert.rejects(fetchVerifiedTickCheckpoint(provider, hour, {
      sleep: async (milliseconds) => pauses.push(milliseconds), onBackoff: (rateLimit) => assert.equal(rateLimit, true),
    }), /status 429/);
  } finally {
    global.fetch = originalFetch;
  }
  assert.equal(attempts, 4);
  assert.equal(requests, 8, 'Four bounded outer attempts, each with one provider retry');
  assert.deepEqual(pauses, [30000, 30000, 30000]);
});

test('empty success-shaped provider hours are bounded failures, not durable checkpoints', async () => {
  const pauses = [];
  let attempts = 0;
  const provider = {
    Timeframe: { tick: 'tick' }, Format: { json: 'json' }, Price: { bid: 'bid' },
    getHistoricalRates: async () => { attempts++; return []; },
  };
  await assert.rejects(fetchVerifiedTickCheckpoint(provider, hour, {
    sleep: async (milliseconds) => pauses.push(milliseconds), onBackoff: () => {},
  }), /empty or unattested/);
  assert.equal(attempts, 4);
  assert.deepEqual(pauses, [5000, 5000, 5000]);
  assert.throws(() => checkpointForTicks(hour, [fixtureTick(hour - 1)]), /empty or unattested/);
});

test('valid legacy checkpoints remain reusable, but empty or invalid checkpoints cannot be evidence', () => {
  const checkpoint = checkpointForTicks(hour, [fixtureTick()]);
  const { source_tick_count, ...legacy } = checkpoint;
  assert.equal(validateTickCheckpoint(legacy, hour), legacy);
  for (const invalid of [
    { ...legacy, last_ticks: [] },
    { ...legacy, source_sha256: hash([]) },
    { ...legacy, source_sha256: '' },
    { ...legacy, hour: hour + 3600000 },
    { ...legacy, source_tick_count: 0 },
    { ...legacy, last_ticks: [fixtureTick(hour + 3600000)] },
    { ...legacy, last_ticks: [fixtureTick(), fixtureTick()] },
  ]) assert.throws(() => validateTickCheckpoint(invalid, hour));
  assert.throws(() => checkpointForTicks(hour, [fixtureTick(), fixtureTick(start)]), /content is invalid/);
  assert.throws(() => checkpointForTicks(hour, [{ ...fixtureTick(), timestamp: NaN }]), /content is invalid/);
  assert.throws(() => checkpointForTicks(hour, [{ ...fixtureTick(), bidPrice: '4400' }]), /content is invalid/);
});
