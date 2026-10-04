const test = require('node:test');
const assert = require('node:assert/strict');
const { fetchVerifiedTickHour } = require('./verify-sparse-reopen-ticks.cjs');
const from = new Date('2025-12-17T23:00:00Z');
const adapter = getHistoricalRates => ({getHistoricalRates, Timeframe:{tick:'tick'}, Format:{json:'json'}, Price:{bid:'bid'}});

test('native tick fetch enables bounded fail-after-retry and ignores cached responses', async () => {
  const rows = [{timestamp:from.getTime()+1000,bidPrice:100,askPrice:101}];
  const hour = await fetchVerifiedTickHour(from, adapter(async options => {
    assert.equal(options.retryCount,1);
    assert.equal(options.failAfterRetryCount,true);
    assert.equal(options.useCache,false);
    assert.equal(options.dates.to.getTime()-options.dates.from.getTime(),3600000);
    return rows;
  }));
  assert.deepEqual(hour,rows);
});

test('HTTP error is never classified as an observed empty market', async () => {
  await assert.rejects(fetchVerifiedTickHour(from,adapter(async () => {throw new Error('HTTP429');})),/HTTP429/);
});

test('empty or out-of-hour response is unattested instead of proof of absent ticks', async () => {
  await assert.rejects(fetchVerifiedTickHour(from,adapter(async () => [])),/EMPTY_UNATTESTED/);
  const outside = adapter(async () => [{timestamp:from.getTime()-1}]);
  await assert.rejects(fetchVerifiedTickHour(from,outside), /EMPTY_UNATTESTED/);
});
