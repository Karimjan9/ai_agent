const test = require('node:test');
const assert = require('node:assert/strict');
const { observationsForHour, boundedInterval } = require('./freeze-historical-tick-spread.cjs');
const start = Date.parse('2025-12-22T13:05:00.000Z');

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
