<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\LearningProtocolBaseline;
use Illuminate\Support\Facades\Schema;

/** A non-trading benchmark envelope used only before the first genuine E4. */
class GenesisBenchmarkBundleService
{
    public const PROTOCOL = 'genesis_benchmark_bundle_v1';

    /** @return array<string,mixed> */
    public function freeze(string $symbol, string $timeframe): array
    {
        if (! Schema::hasTable('learning_protocol_baselines')) return ['status' => 'blocked', 'reason_code' => 'BASELINE_MIGRATION_PENDING', 'promotion_evidence' => false];
        $lab = AiLaboratory::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->first();
        $generation = $lab?->generations()->latest('id')->first();
        if (! $generation) return ['status' => 'blocked', 'reason_code' => 'BASELINE_GENERATION_MISSING', 'promotion_evidence' => false];
        $bundle = ['protocol' => self::PROTOCOL, 'scope' => ['symbol' => strtoupper($symbol), 'timeframe' => strtoupper($timeframe)],
            'benchmarks' => [
                ['id' => 'wait', 'trade_authority' => false, 'purpose' => 'abstention opportunity-cost control'],
                ['id' => 'deterministic_trend', 'trade_authority' => false, 'purpose' => 'minimal trend comparator'],
                ['id' => 'minimal_range', 'trade_authority' => false, 'purpose' => 'minimal range comparator'],
                ['id' => 'risk_only', 'trade_authority' => false, 'purpose' => 'frozen risk control'],
                ['id' => 'negative_no_edge', 'trade_authority' => false, 'purpose' => 'negative control'],
            ],
            'required_e4_envelope' => ['expectancy', 'worst_window', 'drawdown_cvar', 'realistic_costs', 'discipline_violations', 'regime_coverage', 'abstention_opportunity_cost', 'selection_bias_adjusted_performance'],
            'promotion_evidence' => false];
        $json = json_encode($bundle, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $record = LearningProtocolBaseline::firstOrCreate(['protocol_version' => self::PROTOCOL, 'lab_generation_id' => $generation->id], [
            'snapshot_hash' => hash('sha256', $json), 'snapshot' => $bundle, 'frozen_at' => now(),
        ]);
        return ['status' => 'frozen', 'baseline_id' => $record->id, 'generation_id' => $generation->id, 'snapshot_hash' => $record->snapshot_hash, 'promotion_evidence' => false];
    }
}
