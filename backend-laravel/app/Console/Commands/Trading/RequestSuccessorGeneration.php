<?php

namespace App\Console\Commands\Trading;

use App\Models\SystemEvent;
use App\Services\OperatorApprovalService;
use Illuminate\Console\Command;

class RequestSuccessorGeneration extends Command
{
    protected $signature = 'trading:request-successor-generation {symbol=XAUUSD} {--timeframe=H1} {--approved-by=} {--approval-reason=}';
    protected $description = 'Persist an operator-approved drain-then-successor request; no PM2 process is stopped.';

    public function handle(OperatorApprovalService $approvals): int
    {
        $symbol = strtoupper((string) $this->argument('symbol'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $approval = $approvals->requireForApply('drain_then_successor_generation', $this->option('approved-by'), $this->option('approval-reason'), compact('symbol', 'timeframe'));
        SystemEvent::updateOrCreate(['event_key' => "lifecycle:successor-request:{$symbol}:{$timeframe}"], [
            'event_type' => 'lifecycle_successor_request', 'agent' => $approval['approved_by'], 'severity' => 'warning',
            'summary' => 'Operator requested: wait for the active generation to finish, then start exactly one successor.',
            'payload' => ['status' => 'pending', 'mode' => 'drain_then_successor', 'symbol' => $symbol, 'timeframe' => $timeframe,
                'approval_event_id' => $approval['event_id'], 'promotion_evidence' => false], 'occurred_at' => now(),
        ]);
        $this->info("{$symbol}/{$timeframe}: successor request saved; the orchestrator will wait for terminal work and then create one generation.");
        return self::SUCCESS;
    }
}
