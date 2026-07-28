const assert = require('node:assert/strict');
const test = require('node:test');

const {
  decodeJettaCandles,
  jettaInstrument,
} = require('./fetch-dukascopy.cjs');

test('maps configured symbols to Jetta instrument codes', () => {
  assert.equal(jettaInstrument('eurusd'), 'EUR-USD');
  assert.equal(jettaInstrument('XAU/USD'), 'XAU-USD');
});

test('decodes delta-compressed H1 candles and keeps the requested range', () => {
  const hour = 3_600_000;
  const rows = decodeJettaCandles({
    timestamp: Date.UTC(2020, 0, 1),
    shift: hour,
    multiplier: 0.00001,
    open: 1.1,
    high: 1.2,
    low: 1.0,
    close: 1.15,
    times: [1, 1, 1],
    opens: [1, 2, 3],
    highs: [1, 2, 3],
    lows: [1, 2, 3],
    closes: [1, 2, 3],
    volumes: [10.5, 11.5, 12.5],
  }, Date.UTC(2020, 0, 1, 2), Date.UTC(2020, 0, 1, 3));

  assert.deepEqual(rows, [{
    timestamp: Date.UTC(2020, 0, 1, 2),
    open: 1.10003,
    high: 1.20003,
    low: 1.00003,
    close: 1.15003,
    volume: 11.5,
  }]);
});

test('rejects inconsistent Jetta arrays', () => {
  assert.throws(() => decodeJettaCandles({
    times: [1], opens: [], highs: [0], lows: [0], closes: [0], volumes: [1],
  }, 0, 10), /inconsistent OHLCV/);
});
