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
        $context = (array) data_get($agent->modelVersion?->metadata, 'academy_experiment.context', data_get($agent->modelVersion?->metadata, 'edge_genesis.context', []));
        $key = implode('|', [$context['regime'] ?? 'unknown', $context['session'] ?? 'unknown', $context['volatility'] ?? 'unknown']);
        $setup = (int) data_get($metrics,'entry_contract_funnel.stage_counts.setup',0);
        $confirmation = (int) data_get($metrics,'entry_contract_funnel.stage_counts.confirmation',0);
        $false = (int) data_get($metrics,'false_positive_events',0);
        $windows = (int) data_get($metrics,'forward_window_protocol.observed_windows',0);
        $estimate = $setup > 0 ? max(0., min(1., ($confirmation - $false) / $setup)) : 0.;
        $uncertainty = round(1 / sqrt(max(1, $setup + $windows)), 6);
        $knowledge = $setup === 0 ? 'UNKNOWN' : ($windows >= 3 && $estimate >= .6 ? 'KNOWN' : 'PARTIALLY_KNOWN');
        DB::table('agent_competency_tensor_cells')->updateOrInsert(['lab_agent_id'=>$agent->id,'skill'=>'confirmation','context_key'=>$key,'stage'=>'setup_to_confirmation'],[
            'exposed_events'=>$setup,'successful_transitions'=>$confirmation,'false_positive_events'=>$false,'independent_windows'=>$windows,
            'mastery_estimate'=>$estimate,'uncertainty'=>$uncertainty,'calibration'=>data_get($metrics,'statistical_evidence.edge_quality.confidence_calibration.calibration_score'),
            'knowledge_status'=>$knowledge,'authority_status'=>'research_only','drift_status'=>'stable','freshness_status'=>'active','last_verified_epoch'=>$epoch,
            'evidence'=>json_encode(['protocol'=>self::PROTOCOL,'promotion_evidence'=>false]),'updated_at'=>now(),'created_at'=>now()]);
        return ['protocol'=>self::PROTOCOL,'status'=>'projected','knowledge_status'=>$knowledge,'runtime_behavior'=>$knowledge==='KNOWN'?'RESEARCH_ONLY':'WAIT','promotion_evidence'=>false];
    }

    public function selfKnowledge(LabAgent $agent, string $skill, array $context): array
    {
        $key=implode('|',[$context['regime']??'unknown',$context['session']??'unknown',$context['volatility']??'unknown']);
        $cell=Schema::hasTable('agent_competency_tensor_cells')?DB::table('agent_competency_tensor_cells')->where('lab_agent_id',$agent->id)->where('skill',$skill)->where('context_key',$key)->first():null;
        $status=$cell?->knowledge_status ?? 'UNKNOWN';
        return ['protocol'=>self::PROTOCOL,'capability'=>$skill,'context'=>$key,'status'=>$status,
            'confidence_is_eligible'=>$status==='KNOWN' && $cell->authority_status!=='research_only','required_action'=>$status==='KNOWN'?'none':'request_academy_practice',
            'runtime_behavior'=>$status==='KNOWN'?'RESEARCH_ONLY':'WAIT','promotion_evidence'=>false];
    }
}
