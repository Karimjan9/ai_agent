<?php

namespace App\Services;

use App\Models\LabLearningLanePair;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Separates immutable legacy audit debt from the mandatory current control contract. */
class LegacyControlDebtFirewallService
{
    public const PROTOCOL = 'legacy_control_debt_firewall_v1';

    /** @return array<string,mixed> */
    public function reconcile(string $symbol, string $timeframe): array
    {
        if (! Schema::hasTable('legacy_control_debts')) return ['available' => false];
        $pairs = LabLearningLanePair::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->where('status', 'missing_control')->get();
        $canonicalMissing = 0;
        foreach ($pairs as $pair) {
            // Historical paired-control rows were explicitly diagnostic-only.
            // Only a post-firewall writer may label a row canonical_current;
            // absence of that marker is never retroactively made production
            // debt merely because it is old and incomplete.
            $canonical = data_get($pair->metadata, 'authority_scope') === 'canonical_current'
                || data_get($pair->metadata, 'canonical_current') === true;
            if ($canonical) $canonicalMissing++;
            $recoverable = filled($pair->candidate_data_hash) && filled($pair->candidate_execution_hash) && filled($pair->control_agent_id);
            $classification = $canonical ? 'canonical_control_debt' : ($recoverable && (bool) data_get($pair->metadata, 'expected_information_gain_high') ? 'recoverable_legacy_control_debt' : 'irrecoverable_legacy_control_debt');
            DB::table('legacy_control_debts')->updateOrInsert(['debt_key' => hash('sha256', self::PROTOCOL.'|'.$pair->id)], [
                'lab_learning_lane_pair_id' => $pair->id, 'symbol' => strtoupper($symbol), 'timeframe' => strtoupper($timeframe), 'classification' => $classification,
                'authority_allowed' => false, 'excluded_from_current_kpi' => ! $canonical, 'evidence' => json_encode(['protocol' => self::PROTOCOL,
                    'exact_frozen_parent' => $pair->control_agent_id !== null, 'data_manifest' => $pair->candidate_data_hash, 'execution_hash' => $pair->candidate_execution_hash,
                    'promotion_evidence' => false]), 'classified_at' => now(), 'updated_at' => now(), 'created_at' => now(),
            ]);
        }
        return ['protocol' => self::PROTOCOL, 'available' => true, 'legacy_pairs' => $pairs->count(), 'canonical_missing_control' => $canonicalMissing,
            'canonical_control_coverage' => $canonicalMissing === 0 ? 1.0 : 0.0, 'promotion_evidence' => false];
    }
}
