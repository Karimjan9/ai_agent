<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Services\LearningProtocolSafetyService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/** Replays an already-created 4/2/3 causal cohort after an execution-contract repair. */
class ReplayCooldownCausalCohort extends Command
{
    protected $signature = 'trading:replay-cooldown-causal-cohort {sourceAgent : Frozen cooldown=4 parent agent id} {generation : Existing causal 4->2/3 generation number}';

    protected $description = 'Re-screen a frozen 4/2/3 cooldown cohort under a newer execution-state contract; never promotes a candidate.';

    public function handle(LearningProtocolSafetyService $protocolSafety): int
    {
        if ($protocolSafety->generationCreationPaused()) {
            $this->info('Learning protocol paused: cooldown causal replay deferred.');

            return self::SUCCESS;
        }
        $source = LabAgent::query()->with(['modelVersion', 'generation.laboratory'])->findOrFail((int) $this->argument('sourceAgent'));
        if ((string) $source->generation?->laboratory?->lifecycle_mode !== 'lighthouse') {
            $this->info('Source laboratory shadow rejimida; cooldown causal replay qilinmadi.');

            return self::SUCCESS;
        }
        $cohort = LabGeneration::query()->with(['agents.modelVersion', 'laboratory'])
            ->where('generation', (int) $this->argument('generation'))
            ->where('ai_laboratory_id', $source->generation->ai_laboratory_id)->firstOrFail();

        $variants = $cohort->agents->filter(fn (LabAgent $agent) => data_get($agent->modelVersion?->metadata, 'causal_rescue_contract.kind') === 'loss_cooldown_single_gene'
            && (int) data_get($agent->modelVersion?->metadata, 'causal_rescue_contract.source_agent_id') === $source->id
        )->sortBy(fn (LabAgent $agent) => (int) data_get($agent->modelVersion?->parameters, 'loss_cooldown_candles'));

        if (! $source->modelVersion || $source->lifecycle_status !== 'screened' || (int) data_get($source->modelVersion->parameters, 'loss_cooldown_candles') !== 4
            || $variants->count() !== 2 || $variants->pluck('modelVersion.parameters.loss_cooldown_candles')->map(fn ($value) => (int) $value)->values()->all() !== [2, 3]) {
            $this->error('Expected one screened frozen parent at cooldown=4 and exactly its existing 4->2 / 4->3 causal variants.');

            return self::FAILURE;
        }

        $agents = collect([$source])->merge($variants)->values();
        if ($agents->contains(fn (LabAgent $agent) => in_array($agent->lifecycle_status, ['full_queued', 'training', 'challenger', 'forward_validated', 'paper', 'champion'], true))) {
            $this->error('Replay is refused once any member has entered full validation or promotion.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($agents, $cohort): void {
            foreach ($agents as $agent) {
                $model = $agent->modelVersion;
                $metadata = $model->metadata ?? [];
                $history = (array) data_get($metadata, 'screening_history', []);
                if ($previous = data_get($metadata, 'last_screen_result')) {
                    $history[] = [
                        'superseded_at' => now()->utc()->toIso8601String(),
                        'reason' => 'RISK_STATE_MACHINE_FINITE_WAIT_RECOVERY_PROBE_V1',
                        'result' => $previous,
                    ];
                }
                Arr::forget($metadata, 'last_screen_result');
                data_set($metadata, 'screening_history', $history);
                data_set($metadata, 'execution_state_contract', 'finite_wait_context_streak_recovery_probe_v1');
                data_set($metadata, 'screening_contract_required', 'two_tier_v2');
                $model->update(['metadata' => $metadata]);
                $agent->update([
                    'lifecycle_status' => 'queued', 'sample_count' => 0, 'profit_factor' => null,
                    'max_drawdown' => null, 'risk_of_ruin' => null,
                    'decision_reason' => 'Frozen cooldown causal replay queued after finite loss-streak wait/recovery-probe execution repair; screening evidence only.',
                ]);
            }
            $cohort->update(['status' => 'screening', 'completed_at' => null]);
        });

        $batch = Bus::batch($agents->map(fn (LabAgent $agent) => new EvaluateLabAgentJob($agent->id, $agent->symbol, 'screen'))->all())
            ->name("{$source->symbol} cooldown state-machine replay G{$cohort->generation}: 4/2/3")
            ->allowFailures()
            ->onConnection((string) config('queue.default', 'redis'))->onQueue((string) config('services.lab_queue.screening_queue', 'lab-screening'))->dispatch();
        $this->info("{$source->symbol} G{$cohort->generation}: {$batch->id}; frozen parent 4 plus causal variants 2/3 re-screened. No full-validation or paper promotion was dispatched.");

        return self::SUCCESS;
    }
}
