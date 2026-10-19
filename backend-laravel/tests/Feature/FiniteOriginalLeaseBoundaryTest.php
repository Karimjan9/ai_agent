<?php

namespace Tests\Feature;

use App\Models\ResearchExperimentWorkItem;
use App\Services\AutonomousModeService;
use App\Services\FiniteOriginalComponentPreparationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FiniteOriginalLeaseBoundaryTest extends TestCase
{
    public function test_public_birth_without_outer_constructor_transaction_refuses_before_database_writes(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        try {
            app(FiniteOriginalComponentPreparationService::class)->completeBirth(
                new ResearchExperimentWorkItem(), new \App\Models\LabGeneration(), []);
            $this->fail('An inner factory transaction cannot replace the canonical outer birth transaction');
        } catch (\LogicException $error) {
            $this->assertSame('FINITE_COMPONENT_OUTER_CONSTRUCTOR_TRANSACTION_REQUIRED', $error->getMessage());
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(Schema::hasTable('model_versions'));
        $this->assertFalse(Schema::hasTable('scoped_research_certificates'));
    }

    public function test_consumer_preflight_outside_transaction_does_not_call_commit_only_permission(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        Schema::create('research_experiment_work_items', function (Blueprint $table): void {
            $table->id(); $table->string('work_key'); $table->string('symbol'); $table->string('timeframe');
            $table->string('work_type'); $table->string('status'); $table->string('lease_token'); $table->integer('fence_version');
            $table->dateTime('lease_expires_at'); $table->timestamps();
        });
        $work = ResearchExperimentWorkItem::create(['work_key' => 'conditional-live-lease', 'symbol' => 'XAUUSD',
            'timeframe' => 'H1', 'work_type' => FiniteOriginalComponentPreparationService::WORK_TYPE,
            'status' => 'leased', 'lease_token' => 'conditional-only-token',
            'fence_version' => 2, 'lease_expires_at' => now()->addMinute()]);
        $this->mock(AutonomousModeService::class, function ($mock): void {
            $mock->shouldReceive('enabled')->once()->with('XAUUSD', 'H1')->andReturnTrue();
            $mock->shouldNotReceive('enabledForCommit');
        });
        app(FiniteOriginalComponentPreparationService::class)->assertLease($work);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseCount('research_experiment_work_items', 1);
    }
}
