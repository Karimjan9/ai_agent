<?php

namespace App\Services;

use App\Models\CompositionComponentPosterior;
use App\Models\FoundryEmitterReward;
use App\Models\FoundryMultipleTestingLedger;
use Illuminate\Support\Facades\Schema;

/** Exactly-once, research-only component/selector settlement from a sealed passport. */
class CompositionSettlementFanoutService
{
    public const PROTOCOL = 'xauusd_composition_settlement_fanout_v1';

    /** @return array<string,mixed> */
    public function settle(array $packet): array
    {
        $passport = (array) ($packet['composition_passport'] ?? []);
        if ((string) data_get($passport, 'protocol') !== CompositionAuthorityKernelService::PROTOCOL) return ['status' => 'missing_frozen_passport'];
        $symbol = strtoupper((string) ($packet['symbol'] ?? 'XAUUSD')); $timeframe = strtoupper((string) ($packet['timeframe'] ?? 'H1'));
        $stateKey = (string) ($packet['state_key'] ?? data_get($passport, 'market_state.state_key', 'unknown'));
        $net = (float) ($packet['after_cost_r'] ?? $packet['net_r'] ?? 0); $components = (array) data_get($passport, 'components', []);
        $entries = ['strategy' => $components['strategy_id'] ?? null, 'tactic' => $components['tactic_id'] ?? null, 'risk' => $components['risk_id'] ?? null, 'management' => $components['management_id'] ?? null];
        $rows = [];
        if (Schema::hasTable('composition_component_posteriors')) foreach ($entries as $type => $id) {
            if (! is_string($id) || $id === '') continue;
            $key = hash('sha256', implode('|', [self::PROTOCOL, $symbol, $timeframe, $stateKey, $type, $id]));
            $row = CompositionComponentPosterior::query()->firstOrNew(['posterior_key' => $key]); $n = (int) $row->observations + 1;
            $row->fill(['symbol'=>$symbol,'timeframe'=>$timeframe,'state_key'=>$stateKey,'component_type'=>$type,'component_id'=>$id,'observations'=>$n,'after_cost_value'=>(((float) $row->after_cost_value * ($n-1)) + $net) / $n,'uncertainty'=>max(.05, 1 / sqrt($n)),'evidence'=>['last_source'=>$packet['source_key'] ?? null,'passport'=>$passport['composition_id'] ?? null,'promotion_evidence'=>false],'last_settled_at'=>now()])->save(); $rows[$type]=$row->id;
        }
        $emitter = (string) data_get($packet, 'causal_packet.packet_emitter', 'unknown');
        if (Schema::hasTable('foundry_emitter_rewards')) FoundryEmitterReward::query()->updateOrCreate(['reward_key'=>hash('sha256', implode('|',[self::PROTOCOL,$packet['source_key'] ?? '',$emitter]))], ['symbol'=>$symbol,'timeframe'=>$timeframe,'emitter'=>$emitter,'trial_family_id'=>data_get($packet,'causal_packet.trial_family_id'),'reward'=>max(0,$net),'penalty'=>max(0,-$net),'status'=>'settled_research_only','evidence'=>['net_r'=>$net,'promotion_evidence'=>false],'settled_at'=>now()]);
        $experimentId = (string) ($packet['experiment_id'] ?? data_get($packet, 'causal_packet.packet_id', ''));
        if ($experimentId !== '' && Schema::hasTable('foundry_multiple_testing_ledgers')) FoundryMultipleTestingLedger::query()->updateOrCreate(['symbol'=>$symbol,'timeframe'=>$timeframe,'experiment_id'=>$experimentId], ['ledger_key'=>hash('sha256', implode('|',[self::PROTOCOL,$symbol,$timeframe,$experimentId])),'trial_family_id'=>(string) data_get($packet,'causal_packet.trial_family_id',$experimentId),'status'=>(string) ($packet['statistical_status'] ?? 'pending'),'deflated_sharpe_probability'=>data_get($packet,'statistical_evidence.deflated_sharpe.deflated_sharpe_probability'),'pbo_probability'=>data_get($packet,'statistical_evidence.pbo.probability'),'evidence'=>['source_key'=>$packet['source_key'] ?? null,'promotion_evidence'=>false],'recorded_at'=>now()]);
        return ['protocol'=>self::PROTOCOL,'status'=>'settled_research_only','component_posteriors'=>$rows,'promotion_evidence'=>false];
    }
}
