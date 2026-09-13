<?php

namespace App\Services;

use App\Models\ModelVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Freezes an E3 candidate before the 2026 stream; paper results cannot rewrite its passport. */
class PaperAuthorityAdmissionService
{
    public const PROTOCOL = 'paper_authority_admission_v1';

    /** @return array<string,mixed> */
    public function admit(ModelVersion $model, string $symbol, string $timeframe, array $passport): array
    {
        if (! Schema::hasTable('paper_authority_admissions')) return ['status' => 'unavailable', 'promotion_evidence' => false];
        $authority = app(EvolutionaryAuthorityFoundryService::class)->authorityFor($model);
        $pre2026 = (bool) ($passport['training_pre_2026'] ?? false);
        $hashes = ['confirmation_entry_hash', 'risk_governor_hash', 'trade_management_hash', 'execution_hash'];
        $complete = collect($hashes)->every(fn (string $key): bool => filled($passport[$key] ?? null));
        $status = data_get($authority, 'stage') === 'eligible_parent'
            && data_get($authority, 'authority_tier') === EvolutionaryAuthorityLadderService::ECONOMIC_PARENT
            && data_get($authority, 'parent_eligible') === true
            && $pre2026 && $complete ? 'e3_paper_candidate' : 'withheld';
        $key = hash('sha256', implode('|', [self::PROTOCOL, $model->id, strtoupper($symbol), strtoupper($timeframe), (string) ($passport['passport_hash'] ?? '')]));
        DB::table('paper_authority_admissions')->updateOrInsert(['admission_key' => $key], [
            'model_version_id' => $model->id, 'symbol' => strtoupper($symbol), 'timeframe' => strtoupper($timeframe), 'status' => $status,
            'passport_hash' => $passport['passport_hash'] ?? null, 'execution_hash' => $passport['execution_hash'] ?? null,
            'evidence' => json_encode(['protocol' => self::PROTOCOL, 'authority' => $authority, 'training_pre_2026' => $pre2026,
                'parameter_changes_forbidden_in_block' => true, 'paper_is_prospective_only' => true, 'hashes' => $hashes, 'promotion_evidence' => false]),
            'frozen_at' => $status === 'e3_paper_candidate' ? now() : null, 'updated_at' => now(), 'created_at' => now(),
        ]);
        return ['protocol' => self::PROTOCOL, 'status' => $status, 'promotion_evidence' => false];
    }

    /** Paper evidence is E4-eligible only when it is chronologically after the frozen E3 passport. */
    public function recordProspectiveOutcome(ModelVersion $model, string $symbol, string $timeframe, array $outcome): array
    {
        if (! Schema::hasTable('paper_authority_admissions')) return ['status' => 'unavailable', 'promotion_evidence' => false];
        $row = DB::table('paper_authority_admissions')->where('model_version_id', $model->id)
            ->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->where('status', 'e3_paper_candidate')->latest('id')->first();
        if (! $row || ! $row->frozen_at) return ['protocol' => self::PROTOCOL, 'status' => 'withheld', 'reason_code' => 'E3_ADMISSION_MISSING', 'promotion_evidence' => false];
        $prospective = (bool) ($outcome['prospective_after_freeze'] ?? false);
        $parameterUnchanged = (bool) ($outcome['parameter_hash_matches_passport'] ?? false);
        $disciplinePassed = (bool) ($outcome['discipline_audit_passed'] ?? false);
        $passed = $prospective && $parameterUnchanged && $disciplinePassed && (bool) ($outcome['paper_gate_passed'] ?? false);
        DB::table('paper_authority_admissions')->where('id', $row->id)->update([
            'status' => $passed ? 'e4_evidence_ready' : 'e3_paper_candidate',
            'evidence' => json_encode([...((array) json_decode($row->evidence, true)), 'latest_paper_outcome' => $outcome,
                'e4_conditions' => compact('prospective', 'parameterUnchanged', 'disciplinePassed'), 'promotion_evidence' => false]),
            'updated_at' => now(),
        ]);
        return ['protocol' => self::PROTOCOL, 'status' => $passed ? 'e4_evidence_ready' : 'e3_paper_candidate', 'promotion_evidence' => false];
    }

    public function championEligible(ModelVersion $model, string $symbol, string $timeframe): bool
    {
        if (! Schema::hasTable('paper_authority_admissions')) return false;
        return DB::table('paper_authority_admissions')->where('model_version_id', $model->id)
            ->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))
            ->where('status', 'e4_evidence_ready')->exists();
    }
}
