const test = require('node:test');
const assert = require('node:assert/strict');
const { collectSummary, safeReport } = require('./pm2-summary.cjs');

test('uses the local PM2 node entry without a shell and returns only compact status', () => {
  const result = collectSummary((binary, args, options) => {
    assert.equal(binary, process.execPath);
    assert.match(args[0], /node_modules[\\/]pm2[\\/]bin[\\/]pm2$/);
    assert.equal(options.shell, false);
    assert.equal(options.windowsHide, true);
    assert.equal(Object.keys(options.env).some(k => k.startsWith('OPENAI_') || k.startsWith('CODEX_') || k === 'INTERNAL_API_TOKEN'), false);
    return JSON.stringify([{name:'lab-replay', pid:12, pm2_env:{status:'online', env:{sentinel_secret:'never-print-this'}}, monit:{memory:1048576}}]);
  });
  assert.equal(result.items[0].memory_mb, 1);
  assert.equal(JSON.stringify(result).includes('never-print-this'), false);
});

test('truncated jlist and child failures never echo secret-bearing diagnostics', () => {
  for (const run of [() => '[{"env":{"secret":"never-print-this"}', () => { throw new Error('never-print-this'); }]) {
    const result = safeReport(run);
    assert.equal(result.exitCode, 2);
    assert.equal(result.output.includes('never-print-this'), false);
    assert.equal(JSON.parse(result.output).reason, 'PM2_COMPACT_STATUS_UNAVAILABLE');
  }
});
