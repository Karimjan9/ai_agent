<?php

namespace Tests\Feature;

use App\Models\ModelVersion;
use App\Services\LabPopulationService;
use App\Services\LabTrialLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabTrialLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_run_is_counted_and_prior_sharpes_are_returned_without_holdout(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'trial_ledger_test', true);
        $agent = $generation->agents->first()->fresh(['modelVersion']);
        $model = $agent->modelVersion;
        $result = [
            'forward_score' => 72,
            'equity_curve' => [10000, 10100, 10050, 10200],
            'data_manifest' => ['sha256' => str_repeat('a', 64)],
            'execution_contract' => ['execution_hash' => str_repeat('b', 64)],
            'total_trades' => 3,
            'profit_factor' => 1.4,
        ];

        $service = app(LabTrialLedgerService::class);
        $first = $service->record($agent, $model, 'XAUUSD', 'H1', 'screening', $result, 'trial-ledger-1');
        $second = $service->record($agent, $model, 'XAUUSD', 'H1', 'full_replay', $result, 'trial-ledger-2');
        $context = $service->selectionContext('XAUUSD', 'H1');

        $this->assertSame(1, $first['trial_count']);
        $this->assertSame(2, $second['trial_count']);
        $this->assertSame(2, $context['trial_count']);
        $this->assertCount(2, $context['trial_sharpes']);
        $this->assertFalse($context['holdout_included']);
        $this->assertDatabaseCount('lab_trial_ledger', 2);
    }
}
