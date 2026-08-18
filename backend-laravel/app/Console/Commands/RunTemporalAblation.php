<?php

namespace App\Console\Commands;

use App\Models\AiLaboratory;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\TemporalAblationRunnerService;
use Illuminate\Console\Command;
use RuntimeException;

class RunTemporalAblation extends Command
{
    protected $signature = 'trading:temporal-ablation
        {symbol=XAUUSD}
        {--timeframe=H1}
        {--source-generation=}
        {--model-version=}
        {--manifest= : JSON manifest under storage/app with three independent windows}
        {--execute : Execute an admitted plan through the research-only replay lane}
        {--json}';

    protected $description = 'Plan or execute the four-variant clean temporal ablation without changing promotion state';

    public function handle(TemporalAblationRunnerService $runner): int
    {
        $symbol = strtoupper((string) $this->argument('symbol'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $lab = AiLaboratory::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->first();
        if (! $lab) return $this->failCommand("{$symbol} {$timeframe}: laboratory topilmadi.");

        $source = $lab->generations()
            ->when($this->option('source-generation') !== null, fn ($query) => $query->where('generation', (int) $this->option('source-generation')))
            ->latest('generation')
            ->first();
        $model = $this->option('model-version') !== null
            ? ModelVersion::query()->find((int) $this->option('model-version'))
            : null;
        if ($this->option('model-version') !== null && ! $model) {
            return $this->failCommand('Model version topilmadi: '.$this->option('model-version'));
        }

        try {
            $manifest = $this->readManifest();
            $plan = $runner->plan($lab, $source, $model, $manifest);
            $result = $plan;
            if ((bool) $this->option('execute')) {
                if (! (bool) data_get($plan, 'allowed', false)) {
                    $result['execution'] = ['status' => 'blocked', 'reason_codes' => data_get($plan, 'reason_codes', [])];
                } else {
                    $result['execution'] = $runner->execute($plan['run'], $model);
                }
            }
            $this->render($result);

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            return $this->failCommand($exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);
            return $this->failCommand('Temporal ablation command failed: '.$exception->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function readManifest(): array
    {
        $path = trim((string) $this->option('manifest'));
        if ($path === '') return [];
        $resolved = $path;
        if (! str_starts_with(strtolower($resolved), strtolower(storage_path('app')))) {
            $resolved = storage_path('app/'.ltrim($path, '/\\'));
        }
        if (! is_file($resolved)) throw new RuntimeException('Temporal ablation manifest topilmadi: '.$resolved);
        $decoded = json_decode((string) file_get_contents($resolved), true);
        if (! is_array($decoded)) throw new RuntimeException('Temporal ablation manifest JSON noto‘g‘ri.');
        return $decoded;
    }

    private function render(array $payload): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($this->serializable($payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->info((string) data_get($payload, 'decision', 'unknown'));
        $this->line('allowed='.(data_get($payload, 'allowed', false) ? 'true' : 'false'));
        $this->line('reason_codes='.implode(',', (array) data_get($payload, 'reason_codes', [])));
        $this->line('promotion_evidence=false; mutation_credit_allowed=false');
    }

    private function serializable(array $payload): array
    {
        if (isset($payload['run']) && $payload['run'] instanceof \JsonSerializable) {
            $payload['run'] = $payload['run']->toArray();
        }
        return $payload;
    }

    private function failCommand(string $message): int
    {
        $this->error($message);
        return self::FAILURE;
    }
}
