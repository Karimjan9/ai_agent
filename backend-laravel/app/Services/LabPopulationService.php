<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\Candle;
use App\Models\LabGeneration;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\MutationMemory;
use App\Models\AgentDiagnosis;
use App\Models\Symbol;
use App\Services\MarketData\MarketDataContinuityService;
use App\Services\MarketData\HistoricalDataQualityService;
use Illuminate\Support\Facades\DB;

class LabPopulationService
{
    private const LABS = [
        'XAUUSD' => ['name' => 'XAUUSD Lab', 'families' => ['trend', 'breakout', 'volatility']],
        'EURUSD' => ['name' => 'EURUSD Lab', 'families' => ['trend', 'mean_reversion', 'session']],
        'GBPUSD' => ['name' => 'GBPUSD Lab', 'families' => ['breakout', 'momentum', 'volatility']],
    ];

    public function __construct(
        private StrategyParameterSchemaService $schemas,
        private MarketDataContinuityService $continuity,
        private HistoricalDataQualityService $historicalData,
    ) {}

    public function ensureLaboratories(): void
    {
        foreach (self::LABS as $symbol => $config) {
            AiLaboratory::updateOrCreate(['symbol' => $symbol], [
                'name' => $config['name'], 'timeframe' => 'H1',
                'strategy_families' => $config['families'], 'is_active' => true,
            ]);
        }
    }

    public function build(string $symbol, string $trigger = 'new_data', bool $force = false): ?LabGeneration
    {
        $this->ensureLaboratories();
        $lab = AiLaboratory::where('symbol', strtoupper($symbol))->firstOrFail();
        $provider = (string) config('services.market_data.provider', 'csv');
        if ($provider !== 'csv' && ! $this->continuity->isReady($provider, $lab->symbol, $lab->timeframe)) {
            return null;
        }
        if (! app()->environment('testing') && ! $this->historicalData->ready($lab->symbol, $lab->timeframe)) {
            return null;
        }
        $snapshot = $this->dataSnapshot($lab);
        $fingerprint = $snapshot['fingerprint'];
        $latest = $lab->generations()->latest('generation')->first();
        if ($trigger === 'new_data' && ModelMarketPerformance::where('symbol', $lab->symbol)
            ->where('timeframe', $lab->timeframe)->where('status', 'champion')->where('evidence_status', 'valid')
            ->where('consecutive_no_improvement', '>=', 3)->exists()) {
            $trigger = 'degradation';
        }
        if (! $force && $latest && in_array($latest->status, ['draft', 'queued', 'training'], true)) {
            return null;
        }
        $newCandles = $snapshot['count'] - (int) data_get($latest?->trigger_context, 'data_count', 0);
        // H1 learning cadence: require one full day of fresh evidence before
        // creating another population. Drift and champion degradation remain
        // immediate exceptions; an unfinished generation is protected above.
        if (! $force && $latest && $newCandles < 24 && ! in_array($trigger, ['market_drift', 'degradation'], true)) {
            return null;
        }

        return DB::transaction(function () use ($lab, $latest, $trigger, $fingerprint, $snapshot, $newCandles): LabGeneration {
            $number = (int) ($latest?->generation ?? 0) + 1;
            $generation = $lab->generations()->create([
                'generation' => $number, 'trigger_type' => $trigger,
                'trigger_context' => ['previous_generation' => $latest?->generation, 'created_by' => 'learning_trigger',
                    'data_count' => $snapshot['count'], 'latest_candle' => $snapshot['latest'], 'new_candles' => $newCandles],
                'data_fingerprint' => $fingerprint, 'population_size' => 20,
                'status' => 'draft', 'started_at' => now(),
            ]);

            $origins = [...array_fill(0, 3, 'elite'), ...array_fill(0, 10, 'mutation'), ...array_fill(0, 4, 'crossover'), ...array_fill(0, 3, 'random')];
            foreach ($origins as $index => $origin) {
                $family = $lab->strategy_families[$index % count($lab->strategy_families)];
                $this->createAgent($generation, $family, $origin, $index + 1);
            }
            return $generation->load('agents.modelVersion');
        });
    }

    private function createAgent(LabGeneration $generation, string $family, string $origin, int $slot): void
    {
        $lab = $generation->laboratory;
        $parents = ModelMarketPerformance::with('modelVersion')
            ->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)
            ->where('evidence_status', 'valid')
            ->whereHas('modelVersion', fn ($query) => $query->where('evidence_status', 'valid'))
            ->where('strategy_family', $family)->whereIn('status', ['champion', 'challenger', 'forward_validated', 'paper'])
            ->orderByDesc('forward_score')->take(3)->get()->pluck('modelVersion')->filter()->values();
        $parentA = $parents->first();
        $parentB = $parents->get(1);
        $base = $parentA?->parameters ?: $this->schemas->defaults($family);

        $parameters = match ($origin) {
            'elite' => $base,
            'mutation' => $this->mutate($lab->symbol, $lab->timeframe, $family, $base, $slot),
            'crossover' => $this->crossover($family, $base, $parentB?->parameters ?: $this->randomParameters($family, $slot)),
            default => $this->randomParameters($family, $slot),
        };
        $parameters = $this->schemas->validate($family, $parameters);
        $strategy = strtolower($lab->symbol).'_'.$family.'_g'.$generation->generation.'_a'.str_pad((string) $slot, 2, '0', STR_PAD_LEFT);
        $model = ModelVersion::create([
            'name' => $strategy, 'strategy' => $strategy, 'version' => 'v'.$generation->generation,
            'generation' => $generation->generation, 'status' => 'testing', 'parameters' => $parameters,
            'description' => "{$lab->name} generation {$generation->generation} {$origin} agent",
            'metadata' => ['base_strategy' => $family.'_v1', 'lab_symbol' => $lab->symbol, 'origin' => $origin],
        ]);
        $generation->agents()->create([
            'model_version_id' => $model->id, 'parent_a_model_version_id' => $parentA?->id,
            'parent_b_model_version_id' => $origin === 'crossover' ? $parentB?->id : null,
            'symbol' => $lab->symbol, 'timeframe' => $lab->timeframe, 'strategy_family' => $family,
            'origin' => $origin, 'lifecycle_status' => 'draft',
            'parameter_diff' => $this->diff($base, $parameters),
        ]);
    }

    private function mutate(string $symbol, string $timeframe, string $family, array $base, int $seed): array
    {
        $schema = $this->schemas->schema($family);
        $beneficial = MutationMemory::where(compact('symbol', 'timeframe'))->where('strategy_family', $family)
            ->where('outcome', 'beneficial')->orderByDesc('confidence')->first();
        $harmful = MutationMemory::where(compact('symbol', 'timeframe'))->where('strategy_family', $family)
            ->where('outcome', 'harmful')->orderByDesc('confidence')->first();
        $keys = array_keys($schema);
        $diagnosedKey = AgentDiagnosis::whereHas('modelMarketPerformance', fn($q)=>$q->where('symbol',$symbol)->where('timeframe',$timeframe)->where('strategy_family',$family))
            ->latest()->get()->flatMap(fn($item)=>$item->recommended_mutations??[])->first(fn($key)=>isset($schema[$key]));
        if (! $beneficial && $harmful && count($keys) > 1) {
            $keys = array_values(array_diff($keys, [$harmful->parameter_key]));
        }
        $key = $beneficial?->parameter_key && isset($schema[$beneficial->parameter_key])
            ? $beneficial->parameter_key : ($diagnosedKey ?: $keys[$seed % count($keys)]);
        [$type, $min, $max] = array_pad($schema[$key], 3, null);
        if ($type === 'boolean') { $base[$key] = ! (bool) ($base[$key] ?? false); return $base; }
        $current = (float) ($base[$key] ?? (($min + $max) / 2));
        $direction = $beneficial && is_numeric(data_get($beneficial->new_value, 'value')) && is_numeric(data_get($beneficial->old_value, 'value'))
            ? (data_get($beneficial->new_value, 'value') >= data_get($beneficial->old_value, 'value') ? 1 : -1)
            : ($seed % 2 === 0 ? 1 : -1);
        $value = max($min, min($max, $current + $direction * (($max - $min) * 0.1)));
        $base[$key] = $type === 'integer' ? (int) round($value) : round($value, 4);
        return $base;
    }

    private function crossover(string $family, array $a, array $b): array
    {
        $child = [];
        foreach (array_keys($this->schemas->schema($family)) as $index => $key) $child[$key] = $index % 2 === 0 ? ($a[$key] ?? $b[$key]) : ($b[$key] ?? $a[$key]);
        return $child;
    }

    private function randomParameters(string $family, int $seed): array
    {
        $values = [];
        $index = 0;
        foreach ($this->schemas->schema($family) as $key => $rule) {
            [$type, $min, $max] = array_pad($rule, 3, null);
            if ($type === 'boolean') { $values[$key] = ($seed + $index) % 2 === 0; $index++; continue; }
            $ratio = (($seed * 37 + $index * 17) % 101) / 100;
            $value = $min + ($max - $min) * $ratio;
            $values[$key] = $type === 'integer' ? (int) round($value) : round($value, 4);
            $index++;
        }
        return $values;
    }

    private function dataSnapshot(AiLaboratory $lab): array
    {
        $symbolId = Symbol::where('code', $lab->symbol)->value('id');
        if (! $symbolId) return ['fingerprint' => 'no-data', 'count' => 0, 'latest' => null];
        $query = Candle::where('symbol_id', $symbolId)->where('timeframe', $lab->timeframe);
        $count = $query->count();
        $latest = $query->max('time');
        return ['fingerprint' => sha1($count.'|'.($latest ?? 'none')), 'count' => $count, 'latest' => $latest];
    }

    private function diff(array $old, array $new): array
    {
        return collect($new)->filter(fn ($value, $key) => ! array_key_exists($key, $old) || $old[$key] !== $value)
            ->map(fn ($value, $key) => ['old' => $old[$key] ?? null, 'new' => $value])->all();
    }
}
