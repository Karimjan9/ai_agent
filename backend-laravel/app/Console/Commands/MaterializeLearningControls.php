<?php

namespace App\Console\Commands;

use App\Models\LabLearningLanePair;
use App\Services\LearningLaneService;
use App\Services\OperatorApprovalService;
use Illuminate\Console\Command;

/** Repairs pair coverage from immutable, contract-matched control observations. */
class MaterializeLearningControls extends Command
{
    protected $signature = 'trading:materialize-learning-controls {symbol?} {--timeframe=H1} {--family=} {--limit=500} {--apply} {--approved-by=} {--approval-reason=} {--json}';

    protected $description = 'Pair missing-control observations when a matching immutable control exists';

    public function handle(LearningLaneService $learning, OperatorApprovalService $approvals): int
    {
        $symbol = strtoupper((string) ($this->argument('symbol') ?: 'XAUUSD'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $family = (string) $this->option('family') ?: null;
        // The preview is intentionally shallow. Calling the full learning
        // dashboard here also scans dojo, council, memory and frontier rows,
        // which made a diagnostic control count compete with live workers.
        $beforeMissingControl = LabLearningLanePair::query()
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->where('status', 'missing_control')
            ->when($family, fn ($query) => $query->where('strategy_family', $family))
            ->count();
        $preview = $learning->controlMaterializationPreview($symbol, $timeframe, $family, max(1, (int) $this->option('limit')));
        $result = [
            'status' => 'dry_run',
            'before_missing_control' => $beforeMissingControl,
            'pairable_missing_control' => $preview['pairable'] ?? 0,
            'preview_limit' => $preview['limit'] ?? null,
            'requested_limit' => $preview['requested_limit'] ?? (int) $this->option('limit'),
            'preview_truncated' => (bool) ($preview['truncated'] ?? false),
            'paired_candidates_scanned' => 0,
            'promotion_evidence' => false,
        ];
        if ($this->option('apply')) {
            $approval = $approvals->requireForApply('materialize-learning-controls', $this->option('approved-by'), $this->option('approval-reason'), [
                'symbol' => $symbol, 'timeframe' => $timeframe, 'family' => $family, 'limit' => (int) $this->option('limit'),
            ]);
            $result['approval_event_id'] = $approval['event_id'];
            $result['paired_candidates_scanned'] = $learning->pairUnpairedScreeningObservations($symbol, $timeframe, $family, max(1, (int) $this->option('limit')));
            $result['status'] = 'applied';
            $result['after_missing_control'] = LabLearningLanePair::query()
                ->where('symbol', $symbol)
                ->where('timeframe', $timeframe)
                ->where('status', 'missing_control')
                ->when($family, fn ($query) => $query->where('strategy_family', $family))
                ->count();
            $result['after_paired'] = LabLearningLanePair::query()
                ->where('symbol', $symbol)
                ->where('timeframe', $timeframe)
                ->whereIn('status', ['screen_paired', 'provisional', 'learning_queued', 'learning_observed', 'confirmed'])
                ->when($family, fn ($query) => $query->where('strategy_family', $family))
                ->count();
        }
        if ($this->option('json')) $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
        else $this->info($result['status'].'; missing control before='.($result['before_missing_control'] ?? 'n/a').'. Use --apply only after queue review and operator approval.');
        return self::SUCCESS;
    }
}
