const { getHistoricalRates, Format, Price, Timeframe } = require('dukascopy-node');

const args = process.argv.slice(2).reduce((carry, value, index, values) => {
  if (value.startsWith('--')) {
    carry[value.slice(2)] = values[index + 1];
  }

  return carry;
}, {});

const timeframeMap = {
  H1: Timeframe.h1,
  M15: Timeframe.m15,
};

function precisionForMultiplier(multiplier) {
  if (!multiplier) {
    return 1;
  }

  const exponent = Math.floor(Math.log10(multiplier));

  return exponent > 0 ? multiplier : 10 ** Math.abs(exponent);
}

function applyDelta(value, delta, multiplier, precision) {
  return Math.round((value + delta * multiplier) * precision) / precision;
}

function decodeJettaCandles(payload, fromMs, toMs) {
  const fields = ['times', 'opens', 'highs', 'lows', 'closes', 'volumes'];
  const arrays = fields.map((field) => payload[field] || []);
  const length = arrays[0].length;

  if (arrays.some((values) => values.length !== length)) {
    throw new Error('Dukascopy Jetta returned inconsistent OHLCV history.');
  }

  const [times, opens, highs, lows, closes, volumes] = arrays;
  const shift = payload.shift || 1;
  const multiplier = payload.multiplier || 1;
  const precision = precisionForMultiplier(multiplier);
  let timestamp = payload.timestamp || 0;
  let open = payload.open || 0;
  let high = payload.high || 0;
  let low = payload.low || 0;
  let close = payload.close || 0;
  const candles = [];

  for (let index = 0; index < length; index += 1) {
    timestamp += shift * times[index];
    open = applyDelta(open, opens[index], multiplier, precision);
    high = applyDelta(high, highs[index], multiplier, precision);
    low = applyDelta(low, lows[index], multiplier, precision);
    close = applyDelta(close, closes[index], multiplier, precision);

    if (timestamp >= fromMs && timestamp < toMs) {
      candles.push({
        timestamp,
        open,
        high,
        low,
        close,
        // dukascopy-node defaults to millions. Keep that unit so a Jetta
        // backfill does not introduce a 1,000,000x volume discontinuity in
        // the existing training dataset.
        volume: Number(volumes[index]),
      });
    }
  }

  return candles;
}

function jettaInstrument(instrument) {
  const normalized = String(instrument).toUpperCase().replace(/[^A-Z0-9]/g, '');

  if (normalized.length !== 6) {
    throw new Error(`Unsupported Dukascopy Jetta instrument: ${instrument}`);
  }

  return `${normalized.slice(0, 3)}-${normalized.slice(3)}`;
}

async function fetchJson(url, timeoutMs, retryAttempts) {
  let lastError;

  for (let attempt = 1; attempt <= retryAttempts; attempt += 1) {
    try {
      const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        signal: AbortSignal.timeout(timeoutMs),
      });

      if (!response.ok) {
        throw new Error(`HTTP ${response.status} ${response.statusText}`);
      }

      return await response.json();
    } catch (error) {
      lastError = error;
      if (attempt < retryAttempts) {
        await new Promise((resolve) => setTimeout(resolve, attempt * 500));
      }
    }
  }

  throw new Error(`Dukascopy Jetta request failed: ${lastError?.message || lastError}`);
}

async function fetchJettaH1({ instrument, from, to, baseUrl, timeoutMs, retryAttempts }) {
  const fromMs = from.getTime();
  const toMs = to.getTime();
  const code = jettaInstrument(instrument);
  const root = String(baseUrl || 'https://jetta.dukascopy.com').replace(/\/$/, '');
  const cursor = new Date(Date.UTC(from.getUTCFullYear(), from.getUTCMonth(), 1));
  const candles = [];

  while (cursor.getTime() < toMs) {
    const year = cursor.getUTCFullYear();
    const month = cursor.getUTCMonth() + 1;
    const url = `${root}/v1/candles/trade/hour/${code}/BID/${year}/${month}`;
    const payload = await fetchJson(url, timeoutMs, retryAttempts);
    candles.push(...decodeJettaCandles(payload, fromMs, toMs));
    cursor.setUTCMonth(cursor.getUTCMonth() + 1);
  }

  return candles;
}

async function fetchLegacy({ instrument, timeframe, from, to }) {
  return getHistoricalRates({
    instrument,
    dates: { from, to },
    timeframe,
    format: Format.json,
    price: Price.bid,
    volumes: true,
    // Continuity checks need one record for every published hour. Dropping
    // flat candles creates artificial gaps and keeps an otherwise healthy
    // feed permanently in `catching_up`.
    ignoreFlats: false,
    batchSize: Number(args.batchSize || 1),
    pauseBetweenBatchesMs: Number(args.pauseMs || 1000),
    useCache: true,
    cacheFolderPath: '.dukascopy-cache',
  });
}

async function main() {
  const instrument = args.instrument || 'xauusd';
  const timeframeName = String(args.timeframe || 'H1').toUpperCase();
  const timeframe = timeframeMap[timeframeName];
  const transport = String(args.transport || 'auto').toLowerCase();

  if (!timeframe) {
    throw new Error(`Unsupported timeframe: ${args.timeframe}`);
  }

  if (!args.from || !args.to) {
    throw new Error('Both --from and --to are required.');
  }

  const from = new Date(args.from);
  const to = new Date(args.to);
  if (!Number.isFinite(from.getTime()) || !Number.isFinite(to.getTime())) {
    throw new Error('Invalid --from or --to date.');
  }

  let data;
  if (timeframeName === 'H1' && transport !== 'legacy') {
    try {
      data = await fetchJettaH1({
        instrument,
        from,
        to,
        baseUrl: args.baseUrl,
        timeoutMs: Math.max(1000, Number(args.httpTimeoutMs || 20000)),
        retryAttempts: Math.max(1, Number(args.httpRetries || 3)),
      });
    } catch (error) {
      if (transport !== 'auto') {
        throw error;
      }

      process.stderr.write(`Dukascopy Jetta failed; trying legacy datafeed: ${error.message}\n`);
      data = await fetchLegacy({ instrument, timeframe, from, to });
    }
  } else {
    data = await fetchLegacy({ instrument, timeframe, from, to });
  }

  process.stdout.write(JSON.stringify(data));
}

if (require.main === module) {
  main().catch((error) => {
    process.stderr.write(error.stack || error.message || String(error));
    process.exit(1);
  });
}

module.exports = {
  applyDelta,
  decodeJettaCandles,
  fetchJettaH1,
  jettaInstrument,
  precisionForMultiplier,
};
