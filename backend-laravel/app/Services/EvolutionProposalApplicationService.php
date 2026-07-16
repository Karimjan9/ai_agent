<?php

namespace App\Services;

use App\Models\EvolutionProposal;
use App\Models\ModelVersion;
use Illuminate\Support\Facades\DB;

class EvolutionProposalApplicationService
{
    public function __construct(
        private EvolutionGenomeService $evolutionGenome,
        private StrategyParameterSchemaService $parameterSchemas,
    ) {}

    public function apply(EvolutionProposal $proposal): ?ModelVersion
    {
        return DB::transaction(function () use ($proposal): ?ModelVersion {
            $proposal = EvolutionProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            if ($proposal->status === 'applied' && $proposal->applied_model_version_id) {
                return ModelVersion::find($proposal->applied_model_version_id);
            }
            if (! in_array($proposal->status, ['pending', 'approved'], true)) {
                return null;
            }

            $parameters = $this->parameterSchemas->validate($proposal->strategy, $proposal->new_parameters ?? []);

        $strategyName = $this->nextStrategyName($proposal);

            $modelVersion = ModelVersion::firstOrCreate([
                'name' => $strategyName,
            ], [
            'strategy' => $strategyName,
            'version' => $proposal->proposed_version,
            'generation' => $this->nextGeneration($proposal),
            'status' => 'testing',
            'best_score' => 0,
            'best_winrate' => 0,
            'best_profit' => 0,
            'best_drawdown' => 0,
            'description' => $proposal->proposal,
            'change_log' => $proposal->reason,
            'parameters' => $parameters,
            'metadata' => [
                'source_proposal_id' => $proposal->id,
                'parent_strategy' => $proposal->strategy,
                'parent_version' => $proposal->current_version,
                'lab_symbol' => $proposal->symbol,
                'timeframe' => $proposal->timeframe,
                'auto_applied' => true,
            ],
            ]);

            $this->evolutionGenome->recordAppliedProposal($proposal, $modelVersion);

            $proposal->update([
                'status' => 'applied', 'open_status' => null,
                'applied_model_version_id' => $modelVersion->id,
                'applied_at' => now(),
            ]);

            return $modelVersion;
        });
    }

    private function nextStrategyName(EvolutionProposal $proposal): string
    {
        $strategy = $proposal->strategy;
        if ($proposal->symbol) {
            $family = $proposal->strategy_family ?: $this->parameterSchemas->family($strategy);
            return strtolower($proposal->symbol).'_'.$family.'_'.$proposal->proposed_version;
        }

        $next = preg_replace('/_v\d+$/', '_'.$proposal->proposed_version, $strategy);

        return $next ?: $strategy.'_'.$proposal->proposed_version;
    }

    private function nextGeneration(EvolutionProposal $proposal): int
    {
        $model = $proposal->modelVersion;

        if (! $model) {
            return 1;
        }

        return (int) $model->generation + 1;
    }
}
