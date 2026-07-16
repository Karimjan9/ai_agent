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

(async () => {
  const instrument = args.instrument || 'xauusd';
  const timeframe = timeframeMap[String(args.timeframe || 'H1').toUpperCase()];

  if (!timeframe) {
    throw new Error(`Unsupported timeframe: ${args.timeframe}`);
  }

  if (!args.from || !args.to) {
    throw new Error('Both --from and --to are required.');
  }

  const data = await getHistoricalRates({
    instrument,
    dates: {
      from: new Date(args.from),
      to: new Date(args.to),
    },
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

  process.stdout.write(JSON.stringify(data));
})().catch((error) => {
  process.stderr.write(error.stack || error.message || String(error));
  process.exit(1);
});
