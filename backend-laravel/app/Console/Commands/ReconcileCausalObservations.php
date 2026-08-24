<?php

namespace App\Console\Commands;

use App\Models\LabGeneration;
use App\Services\CausalObservationReconciliationService;
use App\Services\OperatorApprovalService;
use Illuminate\Console\Command;

class ReconcileCausalObservations extends Command
{
    protected $signature = 'trading:reconcile-causal-observations
        {symbol?}
        {--timeframe=H1}
        {--generation-from=}
        {--generation-to=}
        {--limit=200}
        {--apply}
        {--approved-by=}
        {--approval-reason=}
        {--json}';

    protected $description = 'Append a causal projection from immutable screening artifacts without replaying or rewriting evidence';

    public function handle(
        CausalObservationReconciliationService $reconciliation,
        OperatorApprovalService $approvals,
    ): int {
        $symbol = strtoupper((string) ($this->argument('symbol') ?: 'XAUUSD'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $from = max(1, (int) ($this->option('generation-from') ?: 1));
        $to = max($from, (int) ($this->option('generation-to') ?: $from));
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $apply = (bool) $this->option('apply');

        if ($apply) {
            $approval = $approvals->requireForApply(
                'reconcile-causal-observations',
                $this->option('approved-by'),
                $this->option('approval-reason'),
                compact('symbol', 'timeframe', 'from', 'to', 'limit'),
            );
        }

        $generations = LabGeneration::query()
            ->whereHas('laboratory', fn ($query) => $query->where('symbol', $symbol)->where('timeframe', $timeframe))
            ->whereBetween('generation', [$from, $to])
            ->orderBy('generation')
            ->get();
        $rows = collect();
        foreach ($generations as $generation) {
            $agents = $generation->agents()->with('modelVersion')->get()
                ->sortByDesc(fn ($agent): int => data_get($agent->modelVersion?->metadata, 'control_contract.control_only') === true ? 1 : 0);
            foreach ($agents as $agent) {
                if ($rows->count() >= $limit) {
                    break 2;
                }
                $rows->push($apply
                    ? $reconciliation->reconcileAgent($agent)
                    : $reconciliation->previewAgent($agent));
            }
        }

        $result = [
            'protocol' => CausalObservationReconciliationService::PROTOCOL,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'generation_from' => $from,
            'generation_to' => $to,
            'apply' => $apply,
            'inspected' => $rows->count(),
            'eligible' => $rows->filter(fn (array $row): bool => ($row['eligible'] ?? false) === true
                || ($row['status'] ?? null) === 'reconciled_append_only')->count(),
            'ineligible' => $rows->filter(fn (array $row): bool => ($row['status'] ?? null) === 'not_reconciled'
                || (array_key_exists('eligible', $row) && $row['eligible'] === false))->count(),
            'reconciled' => $rows->where('status', 'reconciled_append_only')->count(),
            'statuses' => $rows->countBy(fn (array $row): string => (string) ($row['status'] ?? (($row['eligible'] ?? false) ? 'eligible' : 'ineligible')))->all(),
            'ineligible_reasons' => $rows->filter(fn (array $row): bool => ($row['status'] ?? null) === 'not_reconciled'
                || (array_key_exists('eligible', $row) && $row['eligible'] === false))
                ->flatMap(fn (array $row): array => (array) ($row['missing'] ?? []))->countBy()->all(),
            'dispatch' => false,
            'retry' => false,
            'promotion_evidence' => false,
        ];
        if ($apply) {
            $result['approval_event_id'] = $approval['event_id'];
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf(
                'Causal reconciliation: inspected=%d eligible=%d reconciled=%d apply=%s.',
                $result['inspected'],
                $result['eligible'],
                $result['reconciled'],
                $apply ? 'yes' : 'no',
            ));
        }

        return self::SUCCESS;
    }
}
