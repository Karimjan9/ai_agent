const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const readline = require('node:readline');
const { parseArgs } = require('node:util');

const sha256 = (value) => crypto.createHash('sha256').update(value).digest('hex');
const utc = (value) => new Date(value).toISOString();
const candleTime = (value) => utc(value).slice(0, 19).replace('T', ' ');

function dateBoundary(value) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) throw new Error('Expected UTC YYYY-MM-DD date.');
  const timestamp = Date.parse(`${value}T00:00:00.000Z`);
  if (!Number.isFinite(timestamp) || utc(timestamp).slice(0, 10) !== value) throw new Error('Invalid UTC date.');
  return timestamp;
}

function boundedInterval(from, to) {
  const start = dateBoundary(from);
  const end = dateBoundary(to);
  if (end <= start || end - start > 31 * 86400000 || end > dateBoundary('2026-01-01')) {
    throw new Error('Choose at most 31 complete pre-2026 UTC days; 2026 remains paper-only.');
  }
  return { start, end };
}

// A single tick carries both sides at one timestamp. Never combine two
// independently closing BID/ASK bars and call the difference a quote spread.
function observationsForHour(candles, ticks, maxAgeMs = 60000) {
  const latest = new Map();
  let previousTimestamp = -Infinity;
  for (const tick of ticks) {
    const timestamp = Number(tick.timestamp);
    if (!Number.isFinite(timestamp) || timestamp < previousTimestamp) throw new Error('Tick timestamps must be finite and ordered.');
    previousTimestamp = timestamp;
    const bucket = Math.floor(timestamp / 300000) * 300000;
    if (candles.has(bucket)) latest.set(bucket, tick);
  }
  return [...candles].sort((a, b) => a[0] - b[0]).map(([start, expectedBid]) => {
    const quote = latest.get(start);
    const end = start + 300000;
    const timestamp = quote ? Number(quote.timestamp) : null;
    const bid = quote ? Number(quote.bidPrice) : null;
    const ask = quote ? Number(quote.askPrice) : null;
    const age = quote ? end - timestamp : null;
    const status = !quote ? 'missing_quote'
      : (!Number.isFinite(bid) || !Number.isFinite(ask) || bid <= 0 || ask <= 0 ? 'invalid_quote'
        : (ask < bid ? 'negative_spread'
          : (age <= 0 || age > maxAgeMs ? 'stale_quote'
            : (Math.abs(bid - expectedBid) > 0.000001 ? 'bid_mismatch' : 'paired'))));
    return {
      time: candleTime(start), spread: status === 'paired' ? Number((ask - bid).toFixed(6)) : null,
      spread_available: status === 'paired' ? 1 : 0,
      bid_close: Number.isFinite(bid) ? bid : null, ask_close: Number.isFinite(ask) ? ask : null,
      quote_time_utc: timestamp === null ? '' : utc(timestamp), available_after_utc: utc(end),
      quote_age_ms: age, observation_status: status,
    };
  });
}

function validateTickCheckpoint(checkpoint, hour) {
  if (!checkpoint || checkpoint.hour !== hour || !/^[a-f0-9]{64}$/.test(checkpoint.source_sha256 || '')
    || checkpoint.source_sha256 === sha256(JSON.stringify([]))
    || !Array.isArray(checkpoint.last_ticks) || !checkpoint.last_ticks.length) {
    throw new Error('Tick checkpoint is empty or unattested.');
  }
  let previous = -Infinity;
  const buckets = new Set();
  for (const tick of checkpoint.last_ticks) {
    if (!tick || !Number.isSafeInteger(tick.timestamp) || tick.timestamp < hour || tick.timestamp >= hour + 3600000
      || tick.timestamp < previous || !Number.isFinite(tick.bidPrice) || tick.bidPrice <= 0
      || !Number.isFinite(tick.askPrice) || tick.askPrice <= 0) throw new Error('Tick checkpoint content is invalid.');
    previous = tick.timestamp;
    const bucket = Math.floor(tick.timestamp / 300000) * 300000;
    if (buckets.has(bucket)) throw new Error('Tick checkpoint contains duplicate buckets.');
    buckets.add(bucket);
  }
  if (checkpoint.source_tick_count !== undefined
    && (!Number.isSafeInteger(checkpoint.source_tick_count) || checkpoint.source_tick_count < checkpoint.last_ticks.length)) {
    throw new Error('Tick checkpoint source count is invalid.');
  }
  return checkpoint;
}

function checkpointForTicks(hour, ticks) {
  if (!Array.isArray(ticks)) throw new Error('Provider tick hour is not an array.');
  let previous = -Infinity;
  for (const tick of ticks) {
    if (!tick || !Number.isSafeInteger(tick.timestamp) || tick.timestamp < previous
      || !Number.isFinite(tick.bidPrice) || tick.bidPrice <= 0
      || !Number.isFinite(tick.askPrice) || tick.askPrice <= 0) throw new Error('Provider tick hour content is invalid.');
    previous = tick.timestamp;
  }
  const bounded = ticks.filter((tick) => tick.timestamp >= hour && tick.timestamp < hour + 3600000);
  // An empty decoded list cannot distinguish a genuine no-tick hour from a
  // swallowed failed HTTP response. It is not durable source evidence.
  if (!bounded.length) throw new Error('Provider tick hour is empty or unattested.');
  const last = new Map();
  for (const tick of bounded) last.set(Math.floor(tick.timestamp / 300000) * 300000, tick);
  return validateTickCheckpoint({
    hour, source_sha256: sha256(JSON.stringify(bounded)), source_tick_count: bounded.length, last_ticks: [...last.values()],
  }, hour);
}

async function fetchVerifiedTickCheckpoint(provider, hour, {
  sleep = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds)),
  onBackoff = (rateLimit, attempt) => process.stderr.write(`Provider ${rateLimit ? 'rate limit' : 'transport failure'}; bounded backoff ${attempt}/3 at ${utc(hour)}\n`),
} = {}) {
  for (let attempt = 0; attempt < 4; attempt++) {
    try {
      const ticks = await provider.getHistoricalRates({
        instrument: 'xauusd', dates: { from: utc(hour), to: utc(hour + 3600000) },
        timeframe: provider.Timeframe.tick, format: provider.Format.json, price: provider.Price.bid,
        volumes: false, batchSize: 1, pauseBetweenBatchesMs: 1500, retryCount: 1, pauseBetweenRetriesMs: 1500,
        failAfterRetryCount: true, useCache: false,
      });
      return checkpointForTicks(hour, ticks);
    } catch (error) {
      if (attempt === 3) throw new Error(`${utc(hour)}: ${error.message}`);
      const rateLimit = /429/.test(error.message);
      onBackoff(rateLimit, attempt + 1);
      await sleep(rateLimit ? 30000 : 5000);
    }
  }
}

async function main() {
  const { values } = parseArgs({ options: {
    m5: { type: 'string' }, from: { type: 'string' }, to: { type: 'string' }, freeze: { type: 'boolean', default: false },
  }});
  const { start, end } = boundedInterval(values.from, values.to);
  const root = fs.realpathSync(path.join(__dirname, '../storage/app/lab-datasets/mtf'));
  const source = fs.realpathSync(values.m5 || '');
  const relative = path.relative(root, source);
  if (!relative || relative.startsWith('..') || path.isAbsolute(relative) || path.extname(source).toLowerCase() !== '.csv') {
    throw new Error('--m5 must name an existing frozen MTF CSV.');
  }
  const hours = new Map();
  let timeColumn, closeColumn;
  for await (const line of readline.createInterface({ input: fs.createReadStream(source), crlfDelay: Infinity })) {
    // The frozen writer quotes timestamps containing whitespace. These
    // numeric-only datasets have no embedded commas; unquote each field.
    const row = line.split(',').map((field) => field.replace(/^"(.*)"$/, '$1'));
    if (timeColumn === undefined) {
      timeColumn = row.indexOf('time'); closeColumn = row.indexOf('close');
      if (timeColumn < 0 || closeColumn < 0) throw new Error('M5 time/close columns required.');
      continue;
    }
    if (!line.trim()) continue;
    const timestamp = Date.parse(`${row[timeColumn].replace(' ', 'T')}Z`);
    if (!Number.isFinite(timestamp)) throw new Error('Invalid M5 timestamp.');
    if (timestamp < start || timestamp >= end) continue;
    const bid = Number(row[closeColumn]);
    if (timestamp % 300000 !== 0 || !Number.isFinite(bid) || bid <= 0) throw new Error('Invalid M5 close/time.');
    const hour = Math.floor(timestamp / 3600000) * 3600000;
    if (!hours.has(hour)) hours.set(hour, new Map());
    if (hours.get(hour).has(timestamp)) throw new Error('Duplicate M5 candle timestamp.');
    hours.get(hour).set(timestamp, bid);
  }
  if (!hours.size) throw new Error('No frozen M5 rows in this interval.');
  const provider = require('dukascopy-node');
  const decoderHash = sha256(fs.readFileSync(require.resolve('dukascopy-node')));
  const cacheRoot = path.join(__dirname, '../storage/app/lab-datasets/quote-spread/.tick-checkpoints', decoderHash);
  fs.mkdirSync(cacheRoot, { recursive: true });
  const fields = ['time', 'spread', 'spread_available', 'bid_close', 'ask_close', 'quote_time_utc', 'available_after_utc', 'quote_age_ms', 'observation_status'];
  let csv = `${fields.join(',')}\n`;
  const sourceHashes = {};
  const counts = { paired: 0, missing_quote: 0, invalid_quote: 0, negative_spread: 0, stale_quote: 0, bid_mismatch: 0 };
  let completed = 0;
  for (const [hour, candles] of [...hours].sort((a, b) => a[0] - b[0])) {
    const cachePath = path.join(cacheRoot, `${hour}.json`);
    let checkpoint;
    if (fs.existsSync(cachePath)) {
      const saved = JSON.parse(fs.readFileSync(cachePath, 'utf8'));
      if (saved.sha256 !== sha256(JSON.stringify(saved.payload)) || saved.payload.hour !== hour) throw new Error('Tick checkpoint integrity mismatch.');
      checkpoint = validateTickCheckpoint(saved.payload, hour);
    } else {
      checkpoint = await fetchVerifiedTickCheckpoint(provider, hour);
      const temporary = `${cachePath}.${crypto.randomBytes(6).toString('hex')}.tmp`;
      fs.writeFileSync(temporary, JSON.stringify({ payload: checkpoint, sha256: sha256(JSON.stringify(checkpoint)) }));
      fs.renameSync(temporary, cachePath);
      await new Promise((resolve) => setTimeout(resolve, 1500));
    }
    sourceHashes[utc(hour)] = checkpoint.source_sha256;
    for (const observation of observationsForHour(candles, checkpoint.last_ticks)) {
      counts[observation.observation_status]++;
      csv += `${fields.map((field) => observation[field] ?? '').join(',')}\n`;
    }
    completed++;
    if (completed % 24 === 0) process.stderr.write(`Quote hours ${completed}/${hours.size}\n`);
  }
  if (!counts.paired) throw new Error('No synchronized BID/ASK ticks matched the frozen M5 closes.');
  const total = Object.values(counts).reduce((a, b) => a + b, 0);
  const identity = {
    protocol: 'research_quote_spread_ticks_v1', symbol: 'XAUUSD', timeframe: 'M5',
    from_utc: values.from, to_utc_exclusive: values.to, m5_sha256: sha256(fs.readFileSync(source)),
    freezer_sha256: sha256(fs.readFileSync(__filename)),
    decoder_sha256: decoderHash,
    dependency_lock_sha256: sha256(fs.readFileSync(path.join(__dirname, '../package-lock.json'))),
    provider: 'dukascopy_historical_synchronized_tick_v1',
    observation: 'last_synchronized_bid_ask_tick_strictly_before_m5_close',
    maximum_quote_age_ms: 60000, timestamp_semantics: 'M5 open; quote available only at M5 close',
    source_hour_content_sha256: sourceHashes, sidecar_sha256: sha256(csv), counts,
    coverage: Number((counts.paired / total).toFixed(8)),
    paper_2026_included: false, promotion_evidence: false, automatic_replay_authority: false,
  };
  const identityHash = sha256(JSON.stringify(identity));
  const result = { identity_hash: identityHash, identity, frozen: false };
  if (values.freeze) {
    const base = path.join(__dirname, '../storage/app/lab-datasets/quote-spread');
    const target = path.join(base, identityHash);
    fs.mkdirSync(base, { recursive: true });
    if (fs.existsSync(target)) {
      const existing = JSON.parse(fs.readFileSync(path.join(target, 'manifest.json'), 'utf8'));
      if (existing.identity_hash !== identityHash || sha256(fs.readFileSync(path.join(target, 'm5-spread.csv'))) !== identity.sidecar_sha256) {
        throw new Error('Existing artifact differs; refusing overwrite.');
      }
    } else {
      const stage = fs.mkdtempSync(path.join(base, '.staging-'));
      fs.writeFileSync(path.join(stage, 'm5-spread.csv'), csv);
      fs.writeFileSync(path.join(stage, 'manifest.json'), JSON.stringify({ identity_hash: identityHash, identity }, null, 2));
      fs.renameSync(stage, target);
    }
    result.frozen = true; result.artifact_path = target;
  }
  process.stdout.write(`${JSON.stringify(result)}\n`);
}

module.exports = { observationsForHour, boundedInterval, checkpointForTicks, validateTickCheckpoint, fetchVerifiedTickCheckpoint };
if (require.main === module) main().catch((error) => { process.stderr.write(`${error.message}\n`); process.exitCode = 2; });
