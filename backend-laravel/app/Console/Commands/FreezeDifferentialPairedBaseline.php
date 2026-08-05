<?php

namespace App\Console\Commands;

use App\Models\CandidateGateDecision;
use App\Models\LabGeneration;
use App\Models\LearningProtocolBaseline;
use App\Services\LearningProtocolSafetyService;
use Illuminate\Console\Command;

class FreezeDifferentialPairedBaseline extends Command
{
    protected $signature = 'trading:freeze-differential-baseline {--generations=63,64,65,66,67,68,69}';
    protected $description = 'Append immutable G63-G69 evidence baselines and pause new generation creation';

    public function handle(LearningProtocolSafetyService $safety): int
    {
        $numbers = collect(explode(',', (string) $this->option('generations')))
            ->map(fn (string $value) => (int) trim($value))->filter()->unique()->values();
        $generations = LabGeneration::query()->with(['laboratory', 'agents.modelVersion'])
            ->whereIn('generation', $numbers)->orderBy('id')->get();

        if ($generations->count() !== $numbers->count()) {
            $this->error('Every requested generation number must exist before baseline freezing.');
            return self::FAILURE;
        }

        foreach ($generations as $generation) {
            $agentIds = $generation->agents->pluck('id');
            $snapshot = [
                'generation' => $generation->only(['id', 'generation', 'trigger_type', 'trigger_context', 'data_fingerprint', 'population_size', 'status', 'started_at', 'completed_at']),
                'laboratory' => $generation->laboratory?->only(['id', 'symbol', 'timeframe']),
                'agents' => $generation->agents->map(fn ($agent) => [
                    'agent' => $agent->only(['id', 'model_version_id', 'parent_a_model_version_id', 'parent_b_model_version_id', 'symbol', 'timeframe', 'strategy_family', 'origin', 'lifecycle_status', 'parameter_diff', 'sample_count', 'profit_factor', 'max_drawdown', 'risk_of_ruin', 'decision_reason']),
                    'model' => $agent->modelVersion?->only(['id', 'strategy', 'parameters', 'metadata', 'generation']),
                ])->values()->all(),
                'gate_decisions' => CandidateGateDecision::query()->whereIn('lab_agent_id', $agentIds)->orderBy('id')
                    ->get()->map->only(['id', 'stage', 'decision', 'reason_codes', 'metrics', 'evaluated_at'])->all(),
                'frozen_execution_contract' => LearningProtocolSafetyService::EXECUTION_CONTRACT,
            ];
            $json = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $baseline = LearningProtocolBaseline::firstOrCreate([
                'protocol_version' => LearningProtocolSafetyService::EXECUTION_CONTRACT,
                'lab_generation_id' => $generation->id,
            ], [
                'snapshot_hash' => hash('sha256', $json),
                'snapshot' => $snapshot,
                'frozen_at' => now(),
            ]);
            $this->line(sprintf('G%d (%s %s): %s', $generation->generation, $generation->laboratory?->symbol, $generation->laboratory?->timeframe, $baseline->wasRecentlyCreated ? 'frozen' : 'already frozen'));
        }

        $safety->pauseGenerationCreation('G63-G69 immutable baseline frozen before differential paired-lane v4 rollout.');
        return self::SUCCESS;
    }
}
