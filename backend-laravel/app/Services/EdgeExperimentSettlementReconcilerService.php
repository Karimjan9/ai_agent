<?php

namespace App\Services;

use App\Models\LabAgent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Projects an already-terminal Edge Genesis passport into the common
 * experiment receipt ledger.  It is deliberately downstream-only: it never
 * creates a replay, revises Edge evidence, or grants authority.
 */
class EdgeExperimentSettlementReconcilerService
{
    public const PROTOCOL = 'edge_experiment_settlement_reconciler_v1';

    private const TERMINAL_STATUSES = [
        'invalid_edge_observability', 'invalid_hash_mismatch',
        'invalid_window_partition', 'control_settled',
        'replication_control_settled', 'replication_observed',
        'authority_observed', 'edge_replication_passed', 'edge_not_found',
        'edge_not_confirmed', 'non_controlling_axis',
        'technical_quarantine', 'quarantined',
    ];

    public function __construct(private ResearchExperimentConversionKernelService $conversion) {}

    /** @return array<string,mixed> */
    public function reconcile(string $symbol = 'XAUUSD', string $timeframe = 'H1', bool $apply = false, int $limit = 25): array
    {
        if (! Schema::hasTable('edge_genesis_passports') || ! Schema::hasTable('edge_genesis_trials')
            || ! Schema::hasTable('research_experiment_receipts')) {
            return $this->result('migration_pending');
        }

        $passports = DB::table('edge_genesis_passports')->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('research_experiment_receipts as receipt')
                    ->where('receipt.source_type', 'edge_genesis_passport')
                    ->whereColumn('receipt.source_id', 'edge_genesis_passports.id');
            })->orderBy('id')->get();
        // Filter against the versioned contract before applying the bounded
        // work limit. Otherwise old non-eligible passports could permanently
        // starve a newer eligible terminal cohort.
        $ready = $passports->filter(fn (object $passport): bool => $this->ready($passport))
            ->take(max(1, min(100, $limit)))->values();
        if ($ready->isEmpty()) return $this->result('idle', ['passport_ids' => []]);

        if (! $apply) {
            return $this->result('would_reconcile', [
                'passport_ids' => $ready->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'new_replay_allowed' => false,
            ]);
        }

        $outcomes = $ready->map(fn (object $passport): array => $this->record($passport))->all();

        return $this->result('reconciled', [
            'outcomes' => $outcomes,
            'recorded_count' => count($outcomes),
            'new_replay_allowed' => false,
        ]);
    }

    private function ready(object $passport): bool
    {
        $passportEvidence = (array) json_decode((string) $passport->evidence, true);
        // This projection deliberately starts with the new, explicit five-arm
        // contract. Older Edge rows may be terminal but must remain visible as
        // legacy evidence until an operator creates a versioned recovery.
        if (data_get($passportEvidence, 'architecture_revision')
            !== DependencyAwareEdgeGenesisFoundryService::EVIDENCE_COMPILED_REVISION) return false;
        $generation = $passport->lab_generation_id === null ? null
            : DB::table('lab_generations')->where('id', $passport->lab_generation_id)->first(['trigger_context']);
        // A revision inferred during legacy repair is useful for diagnosis but
        // cannot be retroactively treated as a preregistered contract. The
        // source generation itself must have recorded the revision before its
        // arms ran.
        if (data_get((array) json_decode((string) ($generation?->trigger_context ?? '{}'), true), 'architecture_revision')
            !== DependencyAwareEdgeGenesisFoundryService::EVIDENCE_COMPILED_REVISION) return false;
        $trials = DB::table('edge_genesis_trials')->where('edge_genesis_passport_id', $passport->id)
            ->where('packet_key', 'not like', '%:attribution')->get();

        $agents = LabAgent::query()->with('modelVersion')->whereIn('id', $trials->pluck('lab_agent_id')->filter()->all())->get()->keyBy('id');
        $attested = $trials->every(function (object $trial) use ($agents): bool {
            $attestation = (array) data_get($agents->get($trial->lab_agent_id)?->modelVersion?->metadata, 'edge_genesis.intervention_attestation', []);

            return data_get($attestation, 'protocol') === 'edge_genesis_intervention_attestation_v1'
                && filled(data_get($attestation, 'consumed_parameter_hash'));
        });

        return $trials->isNotEmpty()
            && collect($trials->pluck('arm')->all())->sort()->values()->all()
                === collect(DependencyAwareEdgeGenesisFoundryService::COMPILED_HYPOTHESIS_ARMS)->sort()->values()->all()
            && $trials->filter(function (object $trial) use ($agents): bool {
                return (bool) data_get($agents->get($trial->lab_agent_id)?->modelVersion?->metadata,
                    'edge_genesis.intervention_attestation.control_identity', false);
            })->count() === 1
            && $attested
            && $trials->every(fn (object $trial): bool => $trial->settled_at !== null
                && in_array((string) $trial->status, self::TERMINAL_STATUSES, true));
    }

    /** @return array<string,mixed> */
    private function record(object $passport): array
    {
        $trials = DB::table('edge_genesis_trials')->where('edge_genesis_passport_id', $passport->id)
            ->where('packet_key', 'not like', '%:attribution')->orderBy('id')->get();
        $agents = LabAgent::query()->with('modelVersion')
            ->whereIn('id', $trials->pluck('lab_agent_id')->filter()->all())->get()->keyBy('id');
        $passportEvidence = (array) json_decode((string) $passport->evidence, true);
        $trialEvidence = $trials->map(function (object $trial) use ($agents): array {
            $agent = $agents->get($trial->lab_agent_id);
            $contract = (array) data_get($agent?->modelVersion?->metadata, 'edge_genesis', []);
            $attestation = (array) data_get($contract, 'intervention_attestation', []);

            return [
                'trial_id' => (int) $trial->id,
                'trial_key' => (string) $trial->trial_key,
                'agent_id' => $trial->lab_agent_id === null ? null : (int) $trial->lab_agent_id,
                'model_version_id' => $trial->model_version_id === null ? null : (int) $trial->model_version_id,
                'arm' => (string) $trial->arm,
                'stage' => (string) $trial->stage,
                'status' => (string) $trial->status,
                'settled_at' => $trial->settled_at,
                'evidence_hash' => $this->hash((array) json_decode((string) $trial->evidence, true)),
                'consumed_parameter_hash' => data_get($attestation, 'consumed_parameter_hash'),
                'actual_parameter_diff' => (array) data_get($attestation, 'actual_parameter_diff', []),
                'control_identity' => (bool) data_get($attestation, 'control_identity', (string) $trial->arm === 'compiled_control'),
            ];
        })->all();
        $classification = $this->classification($trialEvidence);
        $windowPlanHash = (string) data_get($passportEvidence, 'frozen_window_plan.window_plan_hash', $this->hash([
            data_get($passportEvidence, 'frozen_window_plan'), $passport->genesis_key,
        ]));
        $baselineEpoch = $this->hash([
            'baseline_model_version_id' => $passport->baseline_model_version_id,
            'data_hash' => $passport->data_hash,
            'execution_hash' => $passport->execution_hash,
        ]);
        $contract = [
            'contract_version' => ResearchExperimentConversionKernelService::CONTRACT_VERSION,
            'source' => ['type' => 'edge_genesis_passport', 'id' => (int) $passport->id],
            'scope' => ['symbol' => strtoupper((string) $passport->symbol), 'laboratory_timeframe' => strtoupper((string) $passport->timeframe), 'execution_timeframe' => 'M5'],
            'claim' => ['target_stage' => 'edge_discovery',
                'hypothesis' => 'The preregistered Edge Genesis packet establishes a bounded after-cost edge.',
                'minimum_meaningful_effect' => ['passport_status' => 'edge_replication_passed']],
            'identity' => ['baseline_epoch_hash' => $baselineEpoch, 'data_and_mtf_hash' => (string) $passport->data_hash,
                'runtime_and_contract_hash' => (string) $passport->execution_hash,
                'intervention_hash' => $this->hash($trialEvidence), 'window_plan_hash' => $windowPlanHash,
                'evaluator_version' => (string) data_get($passportEvidence, 'protocol', DependencyAwareEdgeGenesisFoundryService::PROTOCOL)],
            'arms' => array_map(fn (array $trial): array => [
                'role' => $trial['arm'], 'agent_id' => $trial['agent_id'], 'model_version_id' => $trial['model_version_id'],
                'control_identity' => $trial['control_identity'], 'consumed_parameter_hash' => $trial['consumed_parameter_hash'],
            ], $trialEvidence),
            'revisions' => ['subject' => 1, 'evidence' => 1],
        ];
        $reason = match ($classification) {
            'TECHNICAL_QUARANTINE' => ['code' => 'EDGE_EVALUATION_TECHNICAL_QUARANTINE', 'passport_status' => $passport->status],
            'UNDERPOWERED' => ['code' => 'EDGE_DISCOVERY_DID_NOT_REACH_INDEPENDENT_REPLICATION', 'passport_status' => $passport->status],
            default => ['code' => 'NO_EDGE_IN_SEALED_DISCOVERY', 'passport_status' => $passport->status],
        };

        $result = $this->conversion->record($contract, [
            'protocol' => self::PROTOCOL, 'passport_id' => (int) $passport->id,
            'passport_key' => (string) $passport->genesis_key, 'passport_status' => (string) $passport->status,
            'passport_evidence_hash' => $this->hash($passportEvidence), 'trials' => $trialEvidence,
            'all_arms_terminal' => true, 'promotion_evidence' => false,
        ], $classification, [], $reason);

        return ['passport_id' => (int) $passport->id, 'classification' => $classification, 'receipt' => $result];
    }

    /** @param array<int,array<string,mixed>> $trials */
    private function classification(array $trials): string
    {
        $statuses = array_column($trials, 'status');
        if (array_intersect($statuses, ['technical_quarantine', 'quarantined', 'invalid_edge_observability', 'invalid_hash_mismatch', 'invalid_window_partition'])) {
            return 'TECHNICAL_QUARANTINE';
        }
        if (in_array('edge_not_confirmed', $statuses, true) || in_array('non_controlling_axis', $statuses, true)) {
            return 'UNDERPOWERED';
        }

        return 'INCONCLUSIVE';
    }

    /** @return array<string,mixed> */
    private function result(string $status, array $extra = []): array
    {
        return ['protocol' => self::PROTOCOL, 'status' => $status, ...$extra, 'promotion_evidence' => false];
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        if (! array_is_list($value)) ksort($value);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);

        return $value;
    }
}
