<?php

namespace App\Services;

use App\Models\LabCouncilDisagreement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Produces frozen, role-by-role counterfactual work items; a disagreement never closes by a flag. */
class CounterfactualCouncilCourtService
{
    public const PROTOCOL = 'counterfactual_council_court_v1';

    /** @return array<string,mixed> */
    public function plan(string $symbol, string $timeframe, int $limit = 100): array
    {
        if (! Schema::hasTable('council_counterfactual_cases')) return ['available' => false];
        $rows = LabCouncilDisagreement::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->where('outcome_status', 'unresolved')->limit($limit)->get();
        foreach ($rows as $row) {
            $signature = ['market_state' => $row->regime, 'composition' => data_get($row->evidence, 'composition_hash'), 'role_votes' => $row->specialist_votes,
                'disagreement_type' => data_get($row->disagreement, 'type', 'vote_conflict'), 'risk_state' => $row->risk_decision, 'temporal_snapshot' => $row->h1_context_hash];
            $cluster = hash('sha256', json_encode($signature, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
            DB::table('council_counterfactual_cases')->updateOrInsert(['case_key' => hash('sha256', self::PROTOCOL.'|'.$row->id)], [
                'cluster_key' => $cluster, 'lab_council_disagreement_id' => $row->id, 'symbol' => strtoupper($symbol), 'timeframe' => strtoupper($timeframe), 'status' => 'awaiting_frozen_role_replays',
                'evidence' => json_encode(['protocol' => self::PROTOCOL, 'signature' => $signature, 'required_replays' => ['each_role', 'risk_veto', 'majority_counterfactual'],
                    'safe_default' => 'WAIT', 'calibration_required' => true, 'promotion_evidence' => false]), 'planned_at' => now(), 'updated_at' => now(), 'created_at' => now(),
            ]);
        }
        return ['protocol' => self::PROTOCOL, 'available' => true, 'planned_cases' => $rows->count(), 'safe_default' => 'WAIT', 'promotion_evidence' => false];
    }
}
