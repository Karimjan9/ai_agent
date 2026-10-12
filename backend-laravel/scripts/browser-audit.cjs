const fs = require('node:fs');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

(async () => {
  const fixture = JSON.parse(fs.readFileSync(process.argv[2], 'utf8').replace(/^\uFEFF/, ''));
  const target = new URL(fixture.base_url);
  if (target.hostname !== '127.0.0.1' || target.port !== '8765' || fixture.isolated_database !== true) {
    throw new Error('ISOLATED_BROWSER_FIXTURE_REQUIRED');
  }
  const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_BROWSER_BINARY || undefined });
  const page = await browser.newPage();
  const results = [];
  try {
    await page.goto(fixture.base_url + '/login');
    await page.locator('input[name=email]').fill(fixture.email);
    await page.locator('input[name=password]').fill(fixture.password);
    await Promise.all([page.waitForURL(fixture.base_url + '/'), page.locator('button[type=submit]').click()]);
    for (const route of fixture.routes) {
      const errors = [];
      const listener = error => errors.push(error.message);
      page.on('pageerror', listener);
      try {
        const response = await page.goto(fixture.base_url + route, { waitUntil: 'domcontentloaded', timeout: 60000 });
        await page.waitForTimeout(100);
        results.push({ route, status: response.status(), title: await page.title(), page_errors: errors });
      } catch (error) {
        results.push({ route, status: null, reason_code: error.name, page_errors: errors });
      } finally {
        page.off('pageerror', listener);
      }
    }
  } finally {
    await browser.close();
  }
  const receipt = { protocol: 'isolated_authenticated_browser_audit_v1', observed_at: new Date().toISOString(),
    isolated_database: true, production_database_writes: false,
    fixture_scope: fixture.fixture_scope || 'static_GET_routes_with_empty_history',
    dynamic_detail_routes: fixture.dynamic_routes, covered_dynamic_routes: fixture.covered_dynamic_routes || [], results,
    passed: results.every(result => result.status >= 200 && result.status < 400 && result.page_errors.length === 0) };
  fs.writeFileSync(process.argv[3], JSON.stringify(receipt, null, 2) + '\n');
  process.stdout.write(JSON.stringify({ passed: receipt.passed, pages: results.length,
    failures: results.filter(result => !result.status || result.status >= 400 || result.page_errors.length) }) + '\n');
  process.exitCode = receipt.passed ? 0 : 1;
})().catch(error => { process.stderr.write(error.name + ': BROWSER_AUDIT_FAILED\n'); process.exitCode = 1; });
