<?php

namespace App\Services;

use App\Models\CanonicalLearningOutbox;
use App\Models\ResearchExperimentReceipt;
use App\Models\ResearchExperimentWorkItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Truth projection for the research state-machine boundary.
 *
 * A receipt is closed only when it has exactly one durable continuation or
 * one explicit terminal reason. This service never invents economic evidence;
 * it merely exposes missing projection work to the single arbiter.
 */
class ResearchClosureInvariantService
{
    public const PROTOCOL = 'research_closure_invariant_v1';

    public function __construct(
        private ResearchExperimentConversionKernelService $conversion,
        private EdgeExperimentSettlementReconcilerService $edgeReceipts,
    ) {}

    /** @return array<string,mixed> */
    public function inspect(string $symbol = 'XAUUSD', string $timeframe = 'H1', bool $repairMetadata = false): array
    {
        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);
        if (! Schema::hasTable('research_experiment_receipts')
            || ! Schema::hasTable('research_experiment_work_items')) {
            return $this->blocked('RESEARCH_CONVERSION_TABLES_UNAVAILABLE');
        }

        $metadata = $repairMetadata
            ? $this->conversion->reconcileOwnershipAndDependencies()
            : ['normalized' => 0, 'released' => 0, 'blocked' => 0];
        $receipts = ResearchExperimentReceipt::query()
            ->with('workItems')
            ->where('symbol', $symbol)
            ->where('laboratory_timeframe', $timeframe)
            ->get();
        $missingClosure = [];
        $ambiguousClosure = [];
        foreach ($receipts as $receipt) {
            $hasWork = $receipt->workItems->isNotEmpty();
            $hasTerminal = (array) $receipt->terminal_reason !== [];
            if (! $hasWork && ! $hasTerminal) $missingClosure[] = (int) $receipt->id;
            if ($hasWork && $hasTerminal) $ambiguousClosure[] = (int) $receipt->id;
        }

        $open = ResearchExperimentWorkItem::query()
            ->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->whereIn('status', ['ready', 'blocked', 'leased'])->get();
        $ownerless = $open->filter(fn (ResearchExperimentWorkItem $item): bool =>
            ! filled(data_get($item->payload, 'owner'))
            || ! filled(data_get($item->payload, 'retry_condition.code'))
        )->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        $expiredLeases = $open->filter(fn (ResearchExperimentWorkItem $item): bool =>
            (string) $item->status === 'leased'
            && $item->lease_expires_at !== null
            && $item->lease_expires_at->lte(now())
        )->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        $duplicates = $open->groupBy(fn (ResearchExperimentWorkItem $item): string =>
            $item->research_experiment_receipt_id.'|'.$item->work_type
        )->filter(fn ($items): bool => $items->count() > 1)
            ->map(fn ($items): array => $items->pluck('id')->map(fn ($id): int => (int) $id)->all())
            ->values()->all();

        $academyMissing = 0;
        if (Schema::hasTable('edge_academy_trials')) {
            $academyMissing = DB::table('edge_academy_trials as trial')
                ->join('edge_academy_passports as passport', 'passport.id', '=', 'trial.edge_academy_passport_id')
                ->where('passport.symbol', $symbol)->where('passport.timeframe', $timeframe)
                ->whereNotNull('trial.settled_at')
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')->from('research_experiment_receipts as receipt')
                        ->where('receipt.source_type', 'edge_academy_trial')
                        ->whereColumn('receipt.source_id', 'trial.id');
                })->count();
        }
        $canonicalMissing = 0;
        if (Schema::hasTable('canonical_learning_outbox')) {
            $projection = app(CanonicalLearningOutboxService::class);
            // Historical completed outboxes predate the conversion kernel.
            // They are not an executable closure debt merely because no v2
            // receipt exists. Use the same authority predicate as the
            // reconciliation command so the arbiter cannot loop forever on
            // rows that the repair command will (correctly) refuse to touch.
            CanonicalLearningOutbox::query()
                ->where('status', 'completed')
                ->whereHas('pair', fn ($query) => $query
                    ->where('symbol', $symbol)->where('timeframe', $timeframe))
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')->from('research_experiment_receipts as receipt')
                        ->whereColumn('receipt.canonical_learning_outbox_id', 'canonical_learning_outbox.id');
                })
                ->orderBy('id')
                ->chunkById(100, function ($rows) use ($projection, &$canonicalMissing): void {
                    foreach ($rows as $row) {
                        if ($projection->requiresReprojection($row)) {
                            $canonicalMissing++;
                        }
                    }
                });
        }
        $edge = $this->edgeReceipts->reconcile($symbol, $timeframe, false, 25);
        $edgeMissing = count((array) ($edge['passport_ids'] ?? []));

        $structuralDebt = count($missingClosure) + count($ambiguousClosure)
            + count($ownerless) + count($expiredLeases) + count($duplicates);
        $projectionDebt = $academyMissing + $canonicalMissing + $edgeMissing;
        $nextRepair = match (true) {
            $canonicalMissing > 0 => [
                'action' => 'RECONCILE_CANONICAL_RECEIPTS',
                'command' => 'trading:reconcile-canonical-learning',
                'arguments' => [0 => $symbol, '--timeframe' => $timeframe, '--limit' => 25, '--reproject-completed' => true],
            ],
            $academyMissing > 0 => [
                'action' => 'RECONCILE_ACADEMY_RECEIPTS',
                'command' => 'trading:reconcile-academy-experiments',
                'arguments' => [0 => $symbol, '--timeframe' => $timeframe, '--apply' => true, '--limit' => 25, '--json' => true],
            ],
            $edgeMissing > 0 => [
                'action' => 'RECONCILE_EDGE_RECEIPTS',
                'command' => 'trading:reconcile-edge-experiment-receipts',
                'arguments' => [0 => $symbol, '--timeframe' => $timeframe, '--apply' => true, '--limit' => 25, '--json' => true],
            ],
            $structuralDebt > 0 => [
                'action' => 'QUARANTINE_INVALID_RESEARCH_CLOSURE',
                'command' => null,
                'arguments' => [],
            ],
            default => null,
        };

        return [
            'protocol' => self::PROTOCOL,
            'status' => $structuralDebt === 0 && $projectionDebt === 0 ? 'closed' : 'repair_required',
            'healthy' => $structuralDebt === 0 && $projectionDebt === 0,
            'receipt_count' => $receipts->count(),
            'missing_closure_receipt_ids' => $missingClosure,
            'ambiguous_closure_receipt_ids' => $ambiguousClosure,
            'owner_or_retry_missing_work_ids' => $ownerless,
            'expired_lease_work_ids' => $expiredLeases,
            'duplicate_open_work_groups' => $duplicates,
            'projection_debt' => [
                'canonical_completed_without_receipt' => $canonicalMissing,
                'academy_terminal_without_receipt' => $academyMissing,
                'edge_terminal_without_receipt' => $edgeMissing,
            ],
            'work' => [
                'ready' => $open->where('status', 'ready')->count(),
                'leased' => $open->where('status', 'leased')->count(),
                'blocked_with_explicit_retry' => max(0, $open->where('status', 'blocked')->count()
                    - $open->where('status', 'blocked')->whereIn('id', $ownerless)->count()),
            ],
            'metadata_reconciliation' => $metadata,
            'next_repair' => $nextRepair,
            'closure_rule' => 'terminal_receipt = exactly_one(next_owned_work, explicit_terminal_reason)',
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function blocked(string $reason): array
    {
        return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'healthy' => false,
            'reason' => $reason, 'promotion_evidence' => false];
    }
}
