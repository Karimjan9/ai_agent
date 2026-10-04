const path = require('node:path');
const crypto = require('node:crypto');
async function fetchVerifiedTickHour(from, adapter) {
  const to = new Date(from.getTime()+3600000);
  // This provider's zero-retry branch swallows failed HTTP responses. One
  // bounded retry plus failAfterRetryCount preserves transport failure as
  // failure; an empty response cannot attest that no market ticks existed.
  const ticks = await adapter.getHistoricalRates({instrument:'xauusd',dates:{from,to},timeframe:adapter.Timeframe.tick,format:adapter.Format.json,price:adapter.Price.bid,volumes:false,batchSize:1,pauseBetweenBatchesMs:0,retryCount:1,failAfterRetryCount:true,useCache:false});
  const hour = ticks.filter(row => row.timestamp >= from.getTime() && row.timestamp < to.getTime());
  if (!hour.length) throw new Error('RECOVERY_TICK_HOUR_EMPTY_UNATTESTED');
  return hour;
}

async function main() {
  const provider = require(path.resolve(__dirname, '../node_modules/dukascopy-node'));
  const input = process.argv.find(arg => arg.startsWith('--hour='));
  const from = new Date(input ? input.slice(7) : '2025-12-17T23:00:00Z');
  if (!Number.isFinite(from.getTime()) || from.getUTCMinutes() || from.getUTCSeconds() || from.getUTCMilliseconds()
    || from >= new Date('2026-01-01T00:00:00Z')) throw new Error('RECOVERY_TICK_HOUR_INVALID');
  const hour = await fetchVerifiedTickHour(from, provider);
  const bucket = hour.filter(row => row.timestamp < from.getTime()+300000); const minuteCounts={};
  for (const row of bucket) { const minute=new Date(row.timestamp).toISOString().slice(0,16); minuteCounts[minute]=(minuteCounts[minute]||0)+1; }
  if (input) {
    const groups = new Map(); const minutes = new Map();
    let prior = -Infinity;
    for (const row of hour) {
      if (!Number.isFinite(row.timestamp) || row.timestamp<prior) throw new Error('RECOVERY_TICK_CHRONOLOGY_INVALID'); prior=row.timestamp;
      if (!Number.isFinite(row.bidPrice) || row.bidPrice<=0 || !Number.isFinite(row.askPrice) || row.askPrice<row.bidPrice) throw new Error('RECOVERY_TICK_QUOTE_INVALID');
      for (const [map,duration] of [[groups,300000],[minutes,60000]]) { const key=Math.floor(row.timestamp/duration)*duration; if(!map.has(key))map.set(key,[]); map.get(key).push(row); }
    }
    const summary=(map)=>Object.fromEntries([...map].map(([timestamp,rows])=>[new Date(timestamp).toISOString(),{count:rows.length,open:rows[0].bidPrice,high:rows.reduce((max,row)=>Math.max(max,row.bidPrice),-Infinity),low:rows.reduce((min,row)=>Math.min(min,row.bidPrice),Infinity),close:rows.at(-1).bidPrice,first_tick_utc:new Date(rows[0].timestamp).toISOString(),last_tick_utc:new Date(rows.at(-1).timestamp).toISOString()}]));
    console.log(JSON.stringify({protocol:'actual_provider_tick_hour_v1',hour:from.toISOString(),whole_hour_count:hour.length,source_hour_sha256:crypto.createHash('sha256').update(JSON.stringify(hour)).digest('hex'),buckets:summary(groups),minutes:summary(minutes)}));
    return;
  }
  if (!bucket.length) throw new Error('RECOVERY_TICK_BUCKET_MISSING');
  console.log(JSON.stringify({whole_hour_count:hour.length,source_hour_sha256:crypto.createHash('sha256').update(JSON.stringify(hour)).digest('hex'),first_bucket_count:bucket.length,minute_counts:minuteCounts,
    first_bucket_ohlc:{open:bucket[0].bidPrice,high:Math.max(...bucket.map(row=>row.bidPrice)),low:Math.min(...bucket.map(row=>row.bidPrice)),close:bucket.at(-1).bidPrice},first_tick_utc:new Date(bucket[0].timestamp).toISOString(),last_tick_utc:new Date(bucket.at(-1).timestamp).toISOString()}));
}

module.exports = { fetchVerifiedTickHour };
if (require.main === module) {
  const timer = setTimeout(() => { process.stderr.write('TICK_VERIFICATION_TIMEOUT\n'); process.exit(2); }, 60000);
  main().catch(error=>{console.error(error.message);process.exitCode=1;}).finally(()=>clearTimeout(timer));
}
