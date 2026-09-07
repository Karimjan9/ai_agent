<?php

namespace App\Services;

use App\Models\LabAgent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-model of evidence-bounded competency; it never grants runtime authority. */
class AgentCompetencyTensorService
{
    public const PROTOCOL = 'agent_competency_tensor_v1';

    public function project(LabAgent $agent, array $metrics, string $epoch): array
    {
        if (! Schema::hasTable('agent_competency_tensor_cells')) return ['status'=>'unavailable','promotion_evidence'=>false];
        $context = (array) data_get($agent->modelVersion?->metadata, 'academy_experiment.context', data_get($agent->modelVersion?->metadata, 'edge_genesis.context', data_get($metrics, 'context', [])));
        $key = $this->contextKey($context);
        $setup = (int) data_get($metrics,'entry_contract_funnel.stage_counts.setup',0);
        $confirmation = (int) data_get($metrics,'entry_contract_funnel.stage_counts.confirmation',0);
        $false = (int) data_get($metrics,'false_positive_events',0);
        $windows = (int) data_get($metrics,'forward_window_protocol.observed_windows',0);
        $estimate = $setup > 0 ? max(0., min(1., ($confirmation - $false) / $setup)) : 0.;
        $uncertainty = round(1 / sqrt(max(1, $setup + $windows)), 6);
        $drift = strtolower((string) data_get($metrics, 'drift.status', data_get($metrics, 'drift_status', 'stable')));
        $freshness = strtolower((string) data_get($metrics, 'competency.freshness_status', data_get($metrics, 'freshness_status', 'active')));
        $contraindicated = (bool) data_get($metrics, 'competency.contraindicated', false)
            || (bool) data_get($metrics, 'contraindications.active', false)
            || ($setup > 0 && $false > $confirmation);
        $stale = in_array($drift, ['suspected', 'drift_suspected', 'revoked'], true)
            || in_array($freshness, ['stale', 'hibernating', 'revoked'], true);
        $knowledge = $contraindicated ? 'CONTRAINDICATED' : ($stale ? 'STALE'
            : ($setup === 0 ? 'UNKNOWN' : ($windows >= 3 && $estimate >= .6 ? 'KNOWN' : 'PARTIALLY_KNOWN')));
        DB::table('agent_competency_tensor_cells')->updateOrInsert(['lab_agent_id'=>$agent->id,'skill'=>'confirmation','context_key'=>$key,'stage'=>'setup_to_confirmation'],[
            'exposed_events'=>$setup,'successful_transitions'=>$confirmation,'false_positive_events'=>$false,'independent_windows'=>$windows,
            'mastery_estimate'=>$estimate,'uncertainty'=>$uncertainty,'calibration'=>data_get($metrics,'statistical_evidence.edge_quality.confidence_calibration.calibration_score'),
            'knowledge_status'=>$knowledge,'authority_status'=>'research_only','drift_status'=>$drift,'freshness_status'=>$freshness,'last_verified_epoch'=>$epoch,
            'evidence'=>json_encode(['protocol'=>self::PROTOCOL, 'context'=>$context, 'estimate'=>['effect'=>$estimate,'uncertainty'=>$uncertainty],
                'metrics_hash'=>$this->hash($metrics),'promotion_evidence'=>false]),'updated_at'=>now(),'created_at'=>now()]);
        return ['protocol'=>self::PROTOCOL,'status'=>'projected','knowledge_status'=>$knowledge,
            // A tensor cell is evidence, never a live-trading permission.
            'runtime_behavior'=>'WAIT','promotion_evidence'=>false];
    }

    public function selfKnowledge(LabAgent $agent, string $skill, array $context): array
    {
        $key=$this->contextKey($context);
        $cell=Schema::hasTable('agent_competency_tensor_cells')?DB::table('agent_competency_tensor_cells')->where('lab_agent_id',$agent->id)->where('skill',$skill)->where('context_key',$key)->first():null;
        $status=$cell?->knowledge_status ?? 'UNKNOWN';
        $requestedEpoch = (string) ($context['epoch'] ?? '');
        if ($cell && $requestedEpoch !== '' && ! hash_equals((string) $cell->last_verified_epoch, $requestedEpoch)) $status='STALE';
        $action = match ($status) {
            'KNOWN' => 'none', 'STALE' => 'request_regression_challenge', 'CONTRAINDICATED' => 'abstain_and_open_counterfactual',
            default => 'request_academy_practice',
        };
        return ['protocol'=>self::PROTOCOL,'capability'=>$skill,'context'=>$key,'status'=>$status,
            'confidence_is_eligible'=>false,'mastery_evidence_status'=>$cell?->knowledge_status ?? 'UNKNOWN',
            'required_action'=>$action,'runtime_behavior'=>'WAIT','promotion_evidence'=>false];
    }

    private function contextKey(array $context): string
    {
        if (filled($context['context_key'] ?? null)) return (string) $context['context_key'];
        return implode('|', [$context['regime'] ?? 'unknown', $context['session'] ?? 'unknown', $context['volatility'] ?? 'unknown']);
    }

    private function hash(array $value): string
    {
        ksort($value);
        return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }
}
