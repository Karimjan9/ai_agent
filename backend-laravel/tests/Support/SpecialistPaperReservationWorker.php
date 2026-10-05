<?php

// A test process only: boot the supplied isolated application and a temporary
// SQLite copy. It cannot infer a production database or create authority.
if (count($argv) !== 7 || ! is_file($argv[1]) || ! is_file($argv[2])) exit(2);
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE='.$argv[2]);
$testRoot = dirname(__DIR__, 2);
putenv('APP_BASE_PATH='.$testRoot);
$_ENV['APP_BASE_PATH'] = $_SERVER['APP_BASE_PATH'] = $testRoot;
$loader = require $argv[1];
$map = [];
foreach (['App' => 'app', 'Tests' => 'tests', 'Database'.chr(92).'Factories' => 'database/factories', 'Database'.chr(92).'Seeders' => 'database/seeders'] as $prefix => $directory) {
    $namespace = $prefix.chr(92);
    $originalRoots = $loader->getPrefixesPsr4()[$namespace] ?? [];
    foreach ($loader->getClassMap() as $class => $source) {
        if (! str_starts_with($class, $namespace)) continue;
        foreach ($originalRoots as $originalRoot) {
            $normalized = str_replace(chr(92), '/', $source);
            $original = rtrim(str_replace(chr(92), '/', $originalRoot), '/');
            if (str_starts_with(strtolower($normalized), strtolower($original).'/')) $map[$class] = $testRoot.'/'.$directory.substr($normalized, strlen($original));
        }
    }
    $loader->setPsr4($namespace, [$testRoot.'/'.$directory]);
}
$loader->addClassMap($map);
if (realpath((new ReflectionClass(App\Services\SpecialistPaperAccountService::class))->getFileName()) !== realpath($testRoot.'/app/Services/SpecialistPaperAccountService.php')) {
    throw new RuntimeException('ISOLATED_PAPER_WORKER_AUTOLOAD_REQUIRED');
}
$application = require $testRoot.'/bootstrap/app.php';
$application->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$settings = json_decode($argv[6], true, 512, JSON_THROW_ON_ERROR);
config()->set(['app.env' => 'testing', 'database.default' => 'sqlite', 'database.connections.sqlite.database' => $argv[2],
    'database.connections.sqlite.busy_timeout' => 10000, 'services.paper' => $settings['paper'],
    'services.risk' => $settings['risk'], 'services.execution_contract' => $settings['execution']]);
Illuminate\Support\Facades\DB::purge('sqlite');
Illuminate\Support\Facades\DB::connection()->getPdo()->exec('PRAGMA busy_timeout = 10000');
Carbon\CarbonImmutable::setTestNow(Carbon\CarbonImmutable::parse($settings['now']));
Carbon\Carbon::setTestNow(Carbon\CarbonImmutable::parse($settings['now']));
$candidate = App\Models\ModelMarketPerformance::with('modelVersion')->findOrFail((int) $argv[3]);
$signal = App\Models\PaperSignal::findOrFail((int) $argv[4]);
$binding = (array) data_get($signal->payload, 'specialist_council_binding');
$result = app(App\Services\SpecialistPaperAccountService::class)->reserve($candidate, $signal, $binding, (int) $argv[5], 100000000, 99990000, 2);
echo json_encode(['allowed' => $result['allowed'], 'reason_code' => $result['reason_code'] ?? null]);
