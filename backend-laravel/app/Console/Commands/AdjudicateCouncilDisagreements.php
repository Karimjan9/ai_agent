<?php

namespace App\Console\Commands;

use App\Models\LabCouncilDisagreement;
use App\Services\CouncilAdjudicationService;
use App\Services\OperatorApprovalService;
use Illuminate\Console\Command;
use RuntimeException;

class AdjudicateCouncilDisagreements extends Command
{
    protected $signature = 'trading:adjudicate-council-disagreements {symbol?} {--timeframe=H1} {--event-key=} {--decision=WAIT} {--evidence-run=} {--replay-hash=} {--windows=} {--limit=20} {--apply} {--approved-by=} {--approval-reason=} {--json}';

    protected $description = 'Resolve council disagreements through append-only evidence-backed adjudication; unresolved defaults to WAIT';

    public function handle(CouncilAdjudicationService $adjudications, OperatorApprovalService $approvals): int
    {
        $symbol = strtoupper((string) ($this->argument('symbol') ?: 'XAUUSD'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $eventKey = trim((string) $this->option('event-key'));
        if ($eventKey === '') {
            $result = $adjudications->preview($symbol, $timeframe, (int) $this->option('limit'));
            return $this->outputResult($result);
        }

        $disagreement = LabCouncilDisagreement::query()
            ->where('event_key', $eventKey)
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->first();
        if (! $disagreement) {
            $this->error('COUNCIL_DISAGREEMENT_NOT_FOUND');
            return self::FAILURE;
        }
        if (! $this->option('apply')) {
            return $this->outputResult([
                'status' => 'dry_run', 'event_key' => $eventKey,
                'decision' => strtoupper((string) $this->option('decision')),
                'requires' => ['--apply', '--approved-by', '--approval-reason', '--evidence-run', '--replay-hash', '--windows'],
                'promotion_evidence' => false,
            ]);
        }
        try {
            $approval = $approvals->requireForApply('adjudicate-council-disagreements', $this->option('approved-by'), $this->option('approval-reason'), [
                'symbol' => $symbol, 'timeframe' => $timeframe, 'event_key' => $eventKey,
            ]);
            $result = $adjudications->adjudicate(
                $disagreement,
                (string) $this->option('decision'),
                (string) $this->option('evidence-run'),
                (string) $this->option('replay-hash'),
                array_values(array_filter(array_map('trim', explode(',', (string) $this->option('windows'))))),
                $approval['approved_by'],
                $approval['reason'],
                ['operator_approval_event_id' => $approval['event_id']],
            );
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        return $this->outputResult($result);
    }

    private function outputResult(array $result): int
    {
        if ($this->option('json')) $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
        else $this->info(json_encode($result, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
