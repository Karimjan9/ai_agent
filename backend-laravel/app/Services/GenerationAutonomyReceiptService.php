<?php

namespace App\Services;

use App\Models\GenerationAutonomyReceipt;
use App\Models\LabGeneration;
use App\Models\ResearchLoopDecision;
use Illuminate\Support\Facades\Schema;

/** Seals a clean terminal generation only after the arbiter selects its successor. */
class GenerationAutonomyReceiptService
{
    public const PROTOCOL = 'generation_autonomy_receipt_v1';

    private const SUCCESSOR_WRITER_COMMANDS = [
        'trading:run-lifecycle-cycle',
        'trading:lab-generation',
        'trading:advance-learning-progress',
        'trading:consume-research-work',
        'trading:process-targeted-generations',
    ];

    public function __construct(
        private GenerationAutonomyAuditService $audits,
        private GenerationSnapshotAdmissionService $snapshots,
        private LabGenerationContextService $generationContext,
    ) {}

    /** @return array<string,mixed> */
    public function recordSuccessorDecision(ResearchLoopDecision $decision): array
    {
        if (! Schema::hasTable('generation_autonomy_receipts') || (string) $decision->status !== 'completed') {
            return ['status' => 'not_applicable', 'promotion_evidence' => false];
        }
        if (data_get($decision->contract, 'owner') !== ResearchLoopArbiterService::OWNER
            || (int) data_get($decision->contract, 'selection_cardinality', 0) !== 1
            || ! in_array((string) $decision->command, self::SUCCESSOR_WRITER_COMMANDS, true)) {
            return ['status' => 'decision_not_arbiter_successor_authority', 'promotion_evidence' => false];
        }
        $sourceId = (int) data_get($decision->evidence_snapshot, 'generation.id', 0);
        $source = $sourceId > 0
            ? LabGeneration::query()->with('laboratory', 'agents.modelVersion')->find($sourceId)
            : null;
        if (! $source || ! in_array((string) $source->status, ['screened', 'completed'], true)) {
            return ['status' => 'source_not_cleanly_terminal', 'promotion_evidence' => false];
        }
        $existing = GenerationAutonomyReceipt::query()->where('lab_generation_id', $source->id)->first();
        if ($existing) {
            $successor = $existing->successor()->first();
            if ($successor) {
                $receiptDecision = $existing->arbiterDecision()->first() ?: $decision;
                $this->writeSuccessorProvenance($successor, $receiptDecision, $source, $existing);
            }

            return [
                'status' => 'already_recorded',
                'receipt_id' => (int) $existing->id,
                'receipt_hash' => (string) $existing->receipt_hash,
                'promotion_evidence' => false,
            ];
        }
        $successor = LabGeneration::query()
            ->where('ai_laboratory_id', $source->ai_laboratory_id)
            ->where('generation', (int) $source->generation + 1)
            ->where('created_at', '>=', $decision->dispatched_at ?: $decision->created_at)
            ->where('created_at', '<=', ($decision->completed_at ?: now())->copy()->addSecond())
            ->orderBy('generation')->orderBy('id')->first();
        if (! $successor) {
            return ['status' => 'successor_not_created', 'promotion_evidence' => false];
        }
        // Creation provenance belongs to the arbiter's completed writer
        // decision, even when the predecessor's strict autonomy audit fails.
        // Otherwise a clean successor after a technical generation loses the
        // evidence that it was actually created without a manual writer.
        $this->writeSuccessorProvenance($successor, $decision, $source);
        $audit = $this->audits->audit($source);
        if (! in_array((string) data_get($audit, 'state'), ['passed', 'passed_with_scientific_abstention'], true)) {
            return [
                'status' => 'audit_not_clean',
                'generation_id' => (int) $source->id,
                'audit_state' => data_get($audit, 'state'),
                'failed_checks' => (array) data_get($audit, 'failed_checks', []),
                'promotion_evidence' => false,
            ];
        }
        $snapshot = $this->snapshots->inspect($source);
        if (($snapshot['allowed'] ?? false) !== true) {
            return [
                'status' => 'mtf_bundle_not_valid',
                'generation_id' => (int) $source->id,
                'reason_codes' => (array) ($snapshot['reasons'] ?? []),
                'promotion_evidence' => false,
            ];
        }
        $creationDecision = $this->verifiedCreationDecision($source);
        if (! $creationDecision) {
            return [
                'status' => 'source_creation_not_arbiter_verified',
                'generation_id' => (int) $source->id,
                'reason_codes' => ['GENERATION_CREATION_ARBITER_PROVENANCE_MISSING_OR_INVALID'],
                'promotion_evidence' => false,
            ];
        }
        $checks = collect((array) data_get($audit, 'checks', []))->keyBy('name');
        $technical = (array) $checks->get('technical_integrity', []);
        $population = (array) $checks->get('population_terminal', []);
        $immutable = (array) $checks->get('immutable_evidence', []);
        $learning = (array) $checks->get('terminal_learning_order', []);
        $payload = [
            'protocol' => self::PROTOCOL,
            'arbiter_decision_id' => (int) $decision->id,
            'arbiter_decision_key' => (string) $decision->decision_key,
            'creation_arbiter_decision_id' => (int) $creationDecision->id,
            'manual_generation_writer' => false,
            'generation_id' => (int) $source->id,
            'generation_number' => (int) $source->generation,
            'population' => [
                'planned' => (int) data_get($population, 'metrics.planned', 0),
                'actual' => (int) data_get($population, 'metrics.actual', 0),
                'terminal' => (int) data_get($population, 'metrics.terminal_agents', 0),
            ],
            'mtf_bundle_valid' => true,
            'mtf_bundle_hash' => (string) data_get($source->trigger_context, 'mtf_bundle_hash', ''),
            'technical_runs' => count((array) data_get($technical, 'metrics.technical_run_ids', [])),
            'immutable_evidence_complete' => data_get($immutable, 'status') === 'passed',
            'learning_settlement_terminal' => data_get($learning, 'status') === 'passed',
            'generation_terminal' => true,
            'successor_selected_by_arbiter' => true,
            'successor_generation_id' => (int) $successor->id,
            'successor_generation_number' => (int) $successor->generation,
            'state' => (string) $audit['state'],
            'audit_protocol' => (string) data_get($audit, 'protocol'),
            'audit_observed_at' => (string) data_get($audit, 'observed_at'),
            'promotion_evidence' => false,
        ];
        $hash = $this->hash($payload);
        $receipt = GenerationAutonomyReceipt::query()->firstOrCreate([
            'lab_generation_id' => $source->id,
        ], [
            'receipt_key' => hash('sha256', self::PROTOCOL.'|'.$source->id),
            'arbiter_decision_id' => $decision->id,
            'successor_generation_id' => $successor->id,
            'state' => $audit['state'],
            'receipt_hash' => $hash,
            'payload' => $payload,
            'observed_at' => now(),
        ]);
        $this->writeSuccessorProvenance($successor, $decision, $source, $receipt);

        return [
            'status' => 'recorded',
            'receipt_id' => (int) $receipt->id,
            'receipt_hash' => (string) $receipt->receipt_hash,
            'generation_id' => (int) $source->id,
            'successor_generation_id' => (int) $successor->id,
            'state' => (string) $receipt->state,
            'promotion_evidence' => false,
        ];
    }

    private function writeSuccessorProvenance(
        LabGeneration $successor,
        ResearchLoopDecision $decision,
        LabGeneration $source,
        ?GenerationAutonomyReceipt $receipt = null,
    ): void {
        $this->generationContext->update($successor, function (array $context) use ($decision, $source, $receipt): array {
            $previous = (array) ($context['arbiter_provenance'] ?? []);
            if ((int) ($previous['decision_id'] ?? 0) === (int) $decision->id
                && (int) ($previous['predecessor_autonomy_receipt_id'] ?? 0) === (int) ($receipt?->id ?? 0)) {
                return $context;
            }
            $context['arbiter_provenance'] = [
                'protocol' => self::PROTOCOL,
                'decision_id' => (int) $decision->id,
                'decision_key' => (string) $decision->decision_key,
                'predecessor_generation_id' => (int) $source->id,
                'predecessor_autonomy_receipt_id' => $receipt ? (int) $receipt->id : null,
                'manual_generation_writer' => false,
                'recorded_at' => now()->utc()->toIso8601String(),
                'promotion_evidence' => false,
            ];

            return $context;
        });
    }

    private function verifiedCreationDecision(LabGeneration $generation): ?ResearchLoopDecision
    {
        $provenance = (array) data_get($generation->trigger_context, 'arbiter_provenance', []);
        $previous = LabGeneration::query()
            ->where('ai_laboratory_id', $generation->ai_laboratory_id)
            ->where('generation', (int) $generation->generation - 1)
            ->first();
        $decision = ResearchLoopDecision::query()->find((int) ($provenance['decision_id'] ?? 0));
        if (! $previous || ! $decision
            || (string) ($provenance['protocol'] ?? '') !== self::PROTOCOL
            || ($provenance['manual_generation_writer'] ?? true) !== false
            || (int) ($provenance['predecessor_generation_id'] ?? 0) !== (int) $previous->id
            || ! hash_equals((string) $decision->decision_key, (string) ($provenance['decision_key'] ?? ''))
            || (string) $decision->status !== 'completed'
            || data_get($decision->contract, 'owner') !== ResearchLoopArbiterService::OWNER
            || (int) data_get($decision->contract, 'selection_cardinality', 0) !== 1
            || ! in_array((string) $decision->command, self::SUCCESSOR_WRITER_COMMANDS, true)
            || (int) data_get($decision->evidence_snapshot, 'generation.id', 0) !== (int) $previous->id
            || ! $generation->created_at
            || ! ($decision->dispatched_at ?: $decision->created_at)?->lessThanOrEqualTo($generation->created_at)
            || ! $decision->completed_at?->copy()->addSecond()->greaterThanOrEqualTo($generation->created_at)) {
            return null;
        }

        return $decision;
    }

    /** @return array<string,mixed> */
    public function consecutiveProof(string $symbol = 'XAUUSD', string $timeframe = 'H1', int $required = 2): array
    {
        $required = max(2, min(10, $required));
        if (! Schema::hasTable('generation_autonomy_receipts')) {
            return [
                'protocol' => 'consecutive_generation_autonomy_acceptance_v1',
                'status' => 'unavailable',
                'passed' => false,
                'required' => $required,
                'observed' => 0,
                'generation_numbers' => [],
                'receipt_hashes' => [],
                'reason_codes' => ['GENERATION_AUTONOMY_RECEIPTS_UNAVAILABLE'],
                'observed_at' => now()->utc()->toIso8601String(),
                'promotion_evidence' => false,
            ];
        }
        $receipts = GenerationAutonomyReceipt::query()->with('generation.laboratory', 'successor', 'arbiterDecision')
            ->whereHas('generation.laboratory', fn ($query) => $query
                ->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe)))
            ->latest('lab_generation_id')->take($required)->get()->sortBy('generation.generation')->values();
        $reasons = [];
        if ($receipts->count() !== $required) {
            $reasons[] = 'AUTONOMY_RECEIPT_STREAK_INCOMPLETE';
        }
        foreach ($receipts as $index => $receipt) {
            if (! in_array((string) $receipt->state, ['passed', 'passed_with_scientific_abstention'], true)) {
                $reasons[] = 'AUTONOMY_RECEIPT_NOT_CLEAN';
            }
            $payload = (array) $receipt->payload;
            $decision = $receipt->arbiterDecision;
            $integrityValid = filled($receipt->receipt_hash)
                && hash_equals((string) $receipt->receipt_hash, $this->hash($payload))
                && (int) data_get($payload, 'generation_id', 0) === (int) $receipt->lab_generation_id
                && (int) data_get($payload, 'successor_generation_id', 0) === (int) $receipt->successor_generation_id
                && (int) data_get($payload, 'arbiter_decision_id', 0) === (int) $receipt->arbiter_decision_id
                && (int) data_get($payload, 'creation_arbiter_decision_id', 0)
                    === (int) ($this->verifiedCreationDecision($receipt->generation)?->id ?? 0)
                && (string) data_get($payload, 'state') === (string) $receipt->state
                && $decision !== null
                && (string) $decision->status === 'completed'
                && data_get($decision->contract, 'owner') === ResearchLoopArbiterService::OWNER;
            if (! $integrityValid) {
                $reasons[] = 'AUTONOMY_RECEIPT_INTEGRITY_INVALID';
            }
            if ($index > 0) {
                $previous = $receipts[$index - 1];
                if ((int) $receipt->generation->generation !== (int) $previous->generation->generation + 1
                    || (int) $previous->successor_generation_id !== (int) $receipt->lab_generation_id) {
                    $reasons[] = 'AUTONOMY_RECEIPT_CHAIN_NOT_CONSECUTIVE';
                }
            }
        }

        return [
            'protocol' => 'consecutive_generation_autonomy_acceptance_v1',
            'status' => $reasons === [] ? 'passed' : 'incomplete',
            'passed' => $reasons === [],
            'required' => $required,
            'observed' => $receipts->count(),
            'generation_numbers' => $receipts->map(fn (GenerationAutonomyReceipt $receipt): int => (int) $receipt->generation->generation)->all(),
            'receipt_hashes' => $receipts->pluck('receipt_hash')->all(),
            'reason_codes' => array_values(array_unique($reasons)),
            'observed_at' => now()->utc()->toIso8601String(),
            'promotion_evidence' => false,
        ];
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', (string) json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
