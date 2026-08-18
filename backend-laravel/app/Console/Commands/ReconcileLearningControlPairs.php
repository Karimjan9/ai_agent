<?php

namespace App\Console\Commands;

use App\Services\LearningLaneService;
use App\Services\OperatorApprovalService;
use Illuminate\Console\Command;

/** Keep legacy learning rows honest under the strict control-pair contract. */
class ReconcileLearningControlPairs extends Command
{
    protected $signature = 'trading:reconcile-learning-control-pairs
        {symbol?}
        {--timeframe=H1}
        {--family=}
        {--limit=1000}
        {--apply}
        {--approved-by=}
        {--approval-reason=}
        {--json}';

    protected $description = 'Demote unverified learning pairs until a hash-matched frozen control exists';

    public function handle(LearningLaneService $learning, OperatorApprovalService $approvals): int
    {
        $symbol = strtoupper((string) ($this->argument('symbol') ?: 'XAUUSD'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $family = (string) $this->option('family') ?: null;
        $limit = max(1, (int) $this->option('limit'));
        $apply = (bool) $this->option('apply');

        if ($apply) {
            $approval = $approvals->requireForApply(
                'reconcile-learning-control-pairs',
                $this->option('approved-by'),
                $this->option('approval-reason'),
                ['symbol' => $symbol, 'timeframe' => $timeframe, 'family' => $family, 'limit' => $limit],
            );
        }

        $result = $learning->reconcileControlPairs($symbol, $timeframe, $family, $limit, $apply);
        if ($apply) $result['approval_event_id'] = $approval['event_id'];

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf(
                '%s control pairs: inspected=%d invalid=%d reconciled=%d apply=%s.',
                $apply ? 'Applied' : 'Preview',
                (int) ($result['inspected'] ?? 0),
                (int) ($result['invalid'] ?? 0),
                (int) ($result['reconciled'] ?? 0),
                $apply ? 'yes' : 'no',
            ));
        }

        return self::SUCCESS;
    }
}
