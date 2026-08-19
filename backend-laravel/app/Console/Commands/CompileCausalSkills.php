<?php

namespace App\Console\Commands;

use App\Models\LabFailureDojoRun;
use App\Models\LabLearningLanePair;
use App\Services\CausalSkillCompilerService;
use Illuminate\Console\Command;

/** Materializes research-only causal skill contracts for existing pairs. */
class CompileCausalSkills extends Command
{
    protected $signature = 'trading:compile-causal-skills {symbol?} {--timeframe=} {--family=} {--limit=500} {--dry-run}';

    protected $description = 'Compile failure-to-hypothesis, behavior and counterfactual research contracts';

    public function handle(CausalSkillCompilerService $compiler): int
    {
        $pairs = LabLearningLanePair::query()
            ->with('candidateAgent.modelVersion')
            ->when($this->argument('symbol'), fn ($query, $symbol) => $query->where('symbol', strtoupper((string) $symbol)))
            ->when($this->option('timeframe'), fn ($query, $timeframe) => $query->where('timeframe', strtoupper((string) $timeframe)))
            ->when($this->option('family'), fn ($query, $family) => $query->where('strategy_family', (string) $family))
            ->whereNotNull('candidate_agent_id')
            ->latest('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $compiled = 0;
        foreach ($pairs as $pair) {
            if (! $pair->candidateAgent) continue;
            $metadata = (array) $pair->metadata;
            $result = [
                ...((array) $pair->candidate_metrics),
                'control_pair_available' => $pair->control_agent_id !== null,
                'same_snapshot' => (bool) data_get($metadata, 'same_snapshot', false),
                'same_execution_contract' => (bool) data_get($metadata, 'same_execution_contract', false),
                'mutation_observability' => ['behavioral_delta' => $pair->target_delta],
            ];
            $contract = $compiler->compile($pair->candidateAgent, (array) $pair->failure_signature, $result, (array) $pair->target_delta);
            $priority = $compiler->informationGainPriority((array) $pair->failure_signature, [
                'novelty' => data_get($contract, 'behavioral_delta.observable_effect', false) ? .75 : .35,
                'causal_leverage' => $pair->control_agent_id !== null ? .80 : .20,
                'replay_readiness' => data_get($contract, 'prediction_contract.status') === 'declared' ? .80 : .35,
                'repeat_count' => (int) data_get($metadata, 'repeat_count', 0),
            ]);
            $metadata = [
                ...$metadata,
                'causal_skill_compiler' => $contract,
                'information_gain_priority' => $priority,
                'behavioral_fingerprint' => data_get($contract, 'behavioral_fingerprint'),
                'promotion_evidence' => false,
            ];
            if (! $this->option('dry-run')) {
                $pair->update(['metadata' => $metadata]);
                LabFailureDojoRun::query()->where('pair_id', $pair->id)->get()->each(function (LabFailureDojoRun $run) use ($contract, $priority): void {
                    $run->update(['evidence' => [
                        ...((array) $run->evidence),
                        'causal_skill_compiler' => $contract,
                        'information_gain_priority' => $priority,
                        'promotion_evidence' => false,
                    ]]);
                });
            }
            $compiled++;
        }

        $this->info(($this->option('dry-run') ? 'Would compile ' : 'Compiled ').$compiled.' causal skill contract(s); promotion evidence=false.');
        return self::SUCCESS;
    }
}
