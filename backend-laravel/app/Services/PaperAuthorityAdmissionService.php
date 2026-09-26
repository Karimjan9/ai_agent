<?php

namespace App\Services;

use App\Models\ModelVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Freezes an E3 candidate before the 2026 stream; paper results cannot rewrite its passport. */
class PaperAuthorityAdmissionService
{
    public const PROTOCOL = 'paper_authority_admission_v1';

    public function __construct(private ResearchPaperEpochContractService $epochs) {}

    /** @return array<string,mixed> */
    public function admit(ModelVersion $model, string $symbol, string $timeframe, array $passport): array
    {
        if (! Schema::hasTable('paper_authority_admissions')) return ['status' => 'unavailable', 'promotion_evidence' => false];
        $authority = app(EvolutionaryAuthorityFoundryService::class)->authorityFor($model);
        $pre2026 = (bool) ($passport['training_pre_2026'] ?? false);
        $hashes = ['confirmation_entry_hash', 'risk_governor_hash', 'trade_management_hash', 'execution_hash'];
        $complete = collect($hashes)->every(fn (string $key): bool => filled($passport[$key] ?? null));
        // Paper is the missing prospective rung, so requiring an Economic
        // Parent here creates a circle: parent requires paper while paper
        // requires parent. Admit only a fully incubated pre-paper economic
        // candidate; the paper outcome itself remains unable to mutate it.
        $economicChecks = (array) data_get($authority, 'evidence.economic_parent_authority.checks', []);
        $prePaperChecks = collect($economicChecks)->except([
            'forward_or_paper_evidence',
            'performance_credit_earned',
        ]);
        $prePaperEconomicCandidate = $prePaperChecks->isNotEmpty()
            && $prePaperChecks->every(fn (mixed $passed): bool => $passed === true)
            && data_get($authority, 'evidence.incubation_passed') === true
            && data_get($authority, 'evidence.passport.passed') === true;
        $status = $prePaperEconomicCandidate && $pre2026 && $complete
            ? 'e3_paper_candidate'
            : 'withheld';
        $key = hash('sha256', implode('|', [self::PROTOCOL, $model->id, strtoupper($symbol), strtoupper($timeframe), (string) ($passport['passport_hash'] ?? '')]));
        $existing = DB::table('paper_authority_admissions')->where('admission_key', $key)->first();
        if ($existing && in_array((string) $existing->status, ['e3_paper_candidate', 'e4_evidence_ready'], true)) {
            return [
                'protocol' => self::PROTOCOL,
                'status' => (string) $existing->status,
                'frozen_at' => $existing->frozen_at,
                'idempotent' => true,
                'promotion_evidence' => false,
            ];
        }
        $frozenParameters = $this->epochs->parameterHash((array) $model->parameters);
        $evidence = [
            'protocol' => self::PROTOCOL,
            'authority' => $authority,
            'pre_paper_economic_candidate' => $prePaperEconomicCandidate,
            'pre_paper_checks' => $prePaperChecks->all(),
            'training_pre_2026' => $pre2026,
            'parameter_hash' => $frozenParameters,
            'epoch_contract' => $this->epochs->contract(),
            'parameter_changes_forbidden_in_block' => true,
            'paper_is_prospective_only' => true,
            'hashes' => $hashes,
            'promotion_evidence' => false,
        ];
        DB::table('paper_authority_admissions')->updateOrInsert(['admission_key' => $key], [
            'model_version_id' => $model->id, 'symbol' => strtoupper($symbol), 'timeframe' => strtoupper($timeframe), 'status' => $status,
            'passport_hash' => $passport['passport_hash'] ?? null, 'execution_hash' => $passport['execution_hash'] ?? null,
            'evidence' => json_encode($evidence),
            'frozen_at' => $status === 'e3_paper_candidate' ? now() : null,
            'updated_at' => now(),
            'created_at' => $existing?->created_at ?? now(),
        ]);
        return ['protocol' => self::PROTOCOL, 'status' => $status,
            'reason_code' => $status === 'withheld' ? 'PRE_PAPER_ECONOMIC_CANDIDATE_INCOMPLETE' : null,
            'promotion_evidence' => false];
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
        $epochValid = data_get($outcome, 'epoch_contract.protocol') === ResearchPaperEpochContractService::PROTOCOL
            && $this->epochs->paperWindowValid(
                (array) data_get($outcome, 'paper_observation_times', []),
                (string) data_get($outcome, 'paper_window_key', ''),
            )
            && data_get($outcome, 'paper_used_for_screening') === false
            && data_get($outcome, 'paper_used_for_mutation') === false
            && data_get($outcome, 'paper_used_for_selection') === false
            && data_get($outcome, 'paper_used_for_posterior_update') === false;
        $passed = $prospective && $parameterUnchanged && $disciplinePassed && $epochValid
            && (bool) ($outcome['paper_gate_passed'] ?? false);
        DB::table('paper_authority_admissions')->where('id', $row->id)->update([
            'status' => $passed ? 'e4_evidence_ready' : 'e3_paper_candidate',
            'evidence' => json_encode([...((array) json_decode($row->evidence, true)), 'latest_paper_outcome' => $outcome,
                'e4_conditions' => compact('prospective', 'parameterUnchanged', 'disciplinePassed', 'epochValid'), 'promotion_evidence' => false]),
            'updated_at' => now(),
        ]);
        return ['protocol' => self::PROTOCOL, 'status' => $passed ? 'e4_evidence_ready' : 'e3_paper_candidate', 'promotion_evidence' => false];
    }

    /** @param array<int,\App\Models\PaperOrder> $orders */
    public function prospectiveOutcomeContract(ModelVersion $model, string $symbol, string $timeframe, iterable $orders): array
    {
        $row = DB::table('paper_authority_admissions')->where('model_version_id', $model->id)
            ->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))
            ->where('status', 'e3_paper_candidate')->latest('id')->first();
        if (! $row || ! $row->frozen_at) {
            return ['prospective_after_freeze' => false, 'parameter_hash_matches_passport' => false,
                'discipline_audit_passed' => false, 'epoch_contract' => $this->epochs->contract(),
                'promotion_evidence' => false];
        }
        $orders = collect($orders);
        $evidence = (array) json_decode((string) $row->evidence, true);
        $observationTimes = $orders->map(fn ($order): ?string => $order->opened_at?->copy()->utc()->toIso8601String())
            ->filter()->values()->all();
        $prospective = $orders->isNotEmpty() && $orders->every(
            fn ($order): bool => $order->created_at !== null && $order->created_at->greaterThanOrEqualTo($row->frozen_at),
        );
        $parameterUnchanged = filled(data_get($evidence, 'parameter_hash'))
            && hash_equals(
                (string) data_get($evidence, 'parameter_hash'),
                $this->epochs->parameterHash((array) $model->parameters),
            );
        $disciplinePassed = $orders->isNotEmpty() && $orders->every(
            fn ($order): bool => data_get($order->signal_context, 'smart_discipline.approved') === true,
        );

        return [
            'paper_window_key' => ResearchPaperEpochContractService::PAPER_WINDOW_KEY,
            'paper_observation_times' => $observationTimes,
            'prospective_after_freeze' => $prospective,
            'parameter_hash_matches_passport' => $parameterUnchanged,
            'discipline_audit_passed' => $disciplinePassed,
            'paper_used_for_screening' => false,
            'paper_used_for_mutation' => false,
            'paper_used_for_selection' => false,
            'paper_used_for_posterior_update' => false,
            'epoch_contract' => $this->epochs->contract(),
            'promotion_evidence' => false,
        ];
    }

    public function championEligible(ModelVersion $model, string $symbol, string $timeframe): bool
    {
        if (! Schema::hasTable('paper_authority_admissions')) return false;
        return DB::table('paper_authority_admissions')->where('model_version_id', $model->id)
            ->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))
            ->where('status', 'e4_evidence_ready')->exists();
    }
}
