<?php

namespace Tests\Feature;

use App\Services\AgentMethodGenerationService;
use App\Services\AgentMethodLearningService;
use App\Services\AgentMethodVerificationScope;
use App\Services\InstrumentMethodLibraryService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AgentMethodVerificationScopeTest extends TestCase
{
    use RefreshDatabase;

    private function policyFixture(): array
    {
        $contract = ['protocol' => 'instrument_method_library_v1', 'instrument_inventory_hash' => str_repeat('a', 64),
            'method_hashes' => [], 'recipe_hashes' => []];
        $library = ['valid' => true, 'library_hash' => $this->hash($contract), 'library_contract' => $contract];
        $owner = Mockery::mock(InstrumentMethodLibraryService::class);
        $owner->shouldReceive('verifyLibrary')->andReturn($library);
        $this->app->instance(InstrumentMethodLibraryService::class, $owner);
        $body = ['protocol' => AgentMethodLearningService::POLICY_PROTOCOL, 'agent_key' => 'scope-fixture',
            'revision' => 1, 'library_hash' => $library['library_hash'], 'library_contract' => $contract,
            'rules' => [], 'default_action' => 'BASELINE'];
        $hash = $this->hash($body);
        DB::table('agent_method_policy_revisions')->insert(['policy_hash' => $hash, 'agent_key' => $body['agent_key'],
            'revision' => 1, 'library_hash' => $body['library_hash'], 'contract' => $this->policyJson($body),
            'created_at' => now(), 'updated_at' => now()]);

        return [$hash, $body];
    }

    public function test_transient_and_nested_owners_share_only_one_outer_inspection_and_preserve_contract(): void
    {
        [$hash, $body] = $this->policyFixture();
        $queries = 0;
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'agent_method_policy_revisions')) $queries++;
        });
        $owner = app(AgentMethodLearningService::class);
        $level = DB::connection()->transactionLevel();
        $original = $owner->withVerificationScope(function () use ($owner, $hash): array {
            $one = $owner->policy($hash);
            $two = app(AgentMethodLearningService::class)->withVerificationScope(
                fn (): array => app(AgentMethodLearningService::class)->policy($hash));
            $this->assertSame($one, $two);
            $changed = $two; $changed['default_action'] = 'WAIT';
            $this->assertSame('BASELINE', app(AgentMethodLearningService::class)->policy($hash)['default_action']);

            return $one;
        });
        $this->assertSame(1, $queries);
        $this->assertSame($this->policyJson($body), $original['policy_json']);
        $this->assertSame($hash, $original['policy_hash']);
        $this->assertSame($level, DB::connection()->transactionLevel());
        $this->assertFalse($this->app->bound(AgentMethodVerificationScope::class));
        $this->assertSame($original, $owner->withVerificationScope(fn (): array => $owner->policy($hash)));
        $this->assertSame(2, $queries);
        $this->assertDatabaseCount('agent_method_policy_revisions', 1);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_nested_exception_clears_scope_and_next_inspection_refuses_corruption_then_reopens_repair(): void
    {
        [$hash, $body] = $this->policyFixture();
        $owner = app(AgentMethodLearningService::class);
        try {
            $owner->withVerificationScope(function () use ($owner, $hash): void {
                $this->assertSame($hash, $owner->policy($hash)['policy_hash']);
                app(AgentMethodLearningService::class)->withVerificationScope(function (): never {
                    throw new RuntimeException('fixture-inspection-interrupted');
                });
            });
            $this->fail('Nested exception must leave the inspection.');
        } catch (RuntimeException $error) {
            $this->assertSame('fixture-inspection-interrupted', $error->getMessage());
        }
        $this->assertFalse($this->app->bound(AgentMethodVerificationScope::class));
        DB::table('agent_method_policy_revisions')->where('policy_hash', $hash)->update(['contract' => '{"corrupted":true}']);
        $this->assertSame('POLICY_CONTENT_MISMATCH', $owner->withVerificationScope(fn (): array => $owner->policy($hash))['reason']);
        DB::table('agent_method_policy_revisions')->where('policy_hash', $hash)->update(['contract' => $this->policyJson($body)]);
        $this->assertSame($hash, $owner->withVerificationScope(fn (): array => $owner->policy($hash))['policy_hash']);
        $this->assertFalse($this->app->bound(AgentMethodVerificationScope::class));
    }

    public function test_cycle_detection_is_shared_across_transient_services_and_cannot_be_hidden_by_nested_catch(): void
    {
        [$old, $body] = $this->policyFixture();
        $body['learning_provenance'] = ['protocol' => AgentMethodGenerationService::PROTOCOL];
        $hash = $this->hash($body);
        DB::table('agent_method_policy_revisions')->where('policy_hash', $old)->update(['policy_hash' => $hash, 'contract' => $this->policyJson($body)]);
        $generation = Mockery::mock(AgentMethodGenerationService::class);
        $generation->shouldReceive('verifyProvenance')->andReturnUsing(function () use ($hash): bool {
            $nested = app(AgentMethodLearningService::class)->policy($hash);

            return ($nested['status'] ?? null) !== 'blocked';
        });
        $this->app->instance(AgentMethodGenerationService::class, $generation);
        try {
            app(AgentMethodLearningService::class)->withVerificationScope(
                fn (): array => app(AgentMethodLearningService::class)->policy($hash));
            $this->fail('A recursive provenance graph cannot pass an outer inspection.');
        } catch (LogicException $error) {
            $this->assertSame('METHOD_POLICY_VERIFICATION_CYCLE_DETECTED', $error->getMessage());
        }
        $this->assertFalse($this->app->bound(AgentMethodVerificationScope::class));
    }

    public function test_scope_bounds_attempts_and_retained_bytes_and_always_disposes_failure(): void
    {
        foreach (['attempts', 'bytes'] as $guard) {
            try {
                app(AgentMethodLearningService::class)->withVerificationScope(function () use ($guard): void {
                    $scope = app(AgentMethodVerificationScope::class);
                    if ($guard === 'bytes') {
                        $scope->policy('large', fn (): array => ['policy_hash' => 'large', 'body' => str_repeat('x', 16777216)]);
                    } else {
                        foreach (range(1, 201) as $index) {
                            $hash = hash('sha256', 'scope-fixture-'.$index);
                            $scope->policy($hash, fn (): array => ['policy_hash' => $hash]);
                        }
                    }
                });
                $this->fail('Verification budget must fail closed.');
            } catch (LogicException $error) {
                $this->assertSame($guard === 'bytes' ? 'METHOD_POLICY_VERIFICATION_MEMORY_EXCEEDED' : 'METHOD_POLICY_VERIFICATION_BUDGET_EXCEEDED', $error->getMessage());
            }
            $this->assertFalse($this->app->bound(AgentMethodVerificationScope::class));
        }
    }

    public function test_changed_transaction_context_is_refused_without_changing_parent_transaction(): void
    {
        [$hash] = $this->policyFixture();
        $level = DB::connection()->transactionLevel();
        try {
            app(AgentMethodLearningService::class)->withVerificationScope(function () use ($hash): void {
                DB::beginTransaction();
                try {
                    $result = app(AgentMethodLearningService::class)->policy($hash);
                    $this->assertSame('METHOD_POLICY_VERIFICATION_SNAPSHOT_CHANGED', $result['reason']);
                } finally {
                    DB::rollBack();
                }
            });
            $this->fail('A changed transaction cannot reuse the outer facts.');
        } catch (LogicException $error) {
            $this->assertSame('METHOD_POLICY_VERIFICATION_SNAPSHOT_CHANGED', $error->getMessage());
        }
        $this->assertSame($level, DB::connection()->transactionLevel());
        $this->assertFalse($this->app->bound(AgentMethodVerificationScope::class));
        $this->assertSame($hash, app(AgentMethodLearningService::class)->policy($hash)['policy_hash']);
    }

    public function test_commit_then_begin_at_the_same_depth_cannot_reuse_the_old_root_context(): void
    {
        // RefreshDatabase's empty read context contains no committed fixture
        // rows here, so this deliberate rollover leaves the in-memory DB clean.
        $level = DB::connection()->transactionLevel();
        $this->assertSame(1, $level);
        try {
            app(AgentMethodLearningService::class)->withVerificationScope(function (): void {
                DB::commit();
                DB::beginTransaction();
                $this->assertSame(1, DB::connection()->transactionLevel());
                app(AgentMethodVerificationScope::class)->assertCurrent();
            });
            $this->fail('A new root transaction must reopen original facts.');
        } catch (LogicException $error) {
            $this->assertSame('METHOD_POLICY_VERIFICATION_SNAPSHOT_CHANGED', $error->getMessage());
        }
        $this->assertSame($level, DB::connection()->transactionLevel());
        $this->assertFalse($this->app->bound(AgentMethodVerificationScope::class));
        $this->assertSame('fresh', app(AgentMethodLearningService::class)->withVerificationScope(fn (): string => 'fresh'));
    }

    public function test_completed_nested_transaction_preserves_the_parent_read_context_and_memo(): void
    {
        [$hash] = $this->policyFixture();
        $owner = app(AgentMethodLearningService::class);
        $level = DB::connection()->transactionLevel();
        $result = $owner->withVerificationScope(function () use ($owner, $hash): array {
            $original = $owner->policy($hash);
            DB::transaction(fn (): array => DB::select('select 1 as fixture_value'));
            $this->assertSame($original, app(AgentMethodLearningService::class)->policy($hash));

            return $original;
        });
        $this->assertSame($hash, $result['policy_hash']);
        $this->assertSame($level, DB::connection()->transactionLevel());
        $this->assertFalse($this->app->bound(AgentMethodVerificationScope::class));
    }

    private function policyJson(array $value): string
    {
        $sort = function ($item) use (&$sort) {
            if (! is_array($item)) return $item;
            if (! array_is_list($item)) ksort($item);

            return array_map($sort, $item);
        };

        return json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private function hash(array $value): string
    {
        return hash('sha256', $this->policyJson($value));
    }
}
