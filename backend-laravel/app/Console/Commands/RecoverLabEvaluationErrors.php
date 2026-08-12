<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\LabAgent;
use App\Services\LabQueueJobInspector;
use App\Services\LabReplayRecoveryService;
use App\Services\OperatorApprovalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Requeues bounded evaluator failures without turning them into strategy evidence. */
class RecoverLabEvaluationErrors extends Command
{
    protected $signature = 'trading:recover-lab-evaluation-errors {symbol?} {--timeframe=H1} {--generation= : Restrict recovery to one laboratory generation} {--limit=20} {--mode=screen : Recovery queue mode: screen or full} {--after-auth-repair : Retry only agents whose previous evaluator error was an invalid internal API token} {--after-service-repair : Retry only transport errors after the AI service was restarted} {--after-code-repair : Retry only bounded application-code errors after an explicit code repair; generation is required} {--after-runtime-schema-repair : Retry only bounded schema/runtime errors after the evaluator process was restarted} {--after-ipc-repair : Retry only bounded replay timeouts caused by the evaluator evidence transport containment fix} {--after-retry-budget-repair : Retry only a named generation whose jobs exhausted the old shared-lane retry budget} {--apply : Dispatch the bounded recovery after operator approval} {--approved-by=} {--approval-reason=}';

    protected $description = 'Requeue transport/evaluator failures after a clean AI service restart';

    public function handle(LabReplayRecoveryService $recovery, OperatorApprovalService $approvals): int
    {
        try {
            // /health is intentionally public and cannot prove that Laravel
            // and Python share the same internal token. Recovery must probe a
            // protected read-only endpoint, otherwise an auth outage simply
            // creates another batch of evaluation_error agents.
            $health = Http::connectTimeout(5)->timeout(10)->acceptJson()
                ->withHeaders(['X-Internal-Token' => (string) config('services.internal_api.token')])
                ->get(rtrim((string) config('services.ai_service.url'), '/').'/api/strategies');
            if (! $health->successful() || ! is_array($health->json('strategies'))) {
                $this->warn('AI authenticated readiness probe is not ok; evaluation-error recovery was not dispatched.');

                return self::FAILURE;
            }
        } catch (\Throwable) {
            $this->warn('AI authenticated readiness probe is unreachable; evaluation-error recovery was not dispatched.');

            return self::FAILURE;
        }
        $symbol = $this->argument('symbol') ? strtoupper((string) $this->argument('symbol')) : null;
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $generationNumber = $this->option('generation') !== null ? (int) $this->option('generation') : null;
        $limit = max(1, min(50, (int) $this->option('limit')));
        $mode = strtolower(trim((string) $this->option('mode')));
        if (! in_array($mode, ['screen', 'full'], true)) {
            $this->error('--mode must be either screen or full.');

            return self::FAILURE;
        }
        $fullRecovery = $mode === 'full';
        $apply = (bool) $this->option('apply');
        $afterAuthRepair = (bool) $this->option('after-auth-repair');
        $afterServiceRepair = (bool) $this->option('after-service-repair');
        $afterCodeRepair = (bool) $this->option('after-code-repair');
        $afterRuntimeSchemaRepair = (bool) $this->option('after-runtime-schema-repair');
        $afterIpcRepair = (bool) $this->option('after-ipc-repair');
        $afterRetryBudgetRepair = (bool) $this->option('after-retry-budget-repair');
        if ($afterRetryBudgetRepair && $generationNumber === null) {
            $this->error('--after-retry-budget-repair requires --generation so the recovery scope is explicit.');

            return self::FAILURE;
        }
        if ($afterCodeRepair && $generationNumber === null) {
            $this->error('--after-code-repair requires --generation so the code-repair recovery scope is explicit.');

            return self::FAILURE;
        }
        if (collect([$afterAuthRepair, $afterServiceRepair, $afterCodeRepair, $afterRuntimeSchemaRepair, $afterIpcRepair, $afterRetryBudgetRepair])->filter()->count() > 1) {
            $this->error('Choose only one bounded repair mode.');

            return self::FAILURE;
        }
        $queueBacklog = $this->queueBacklog();
        if ($queueBacklog['total'] > 0) {
            $this->info(sprintf(
                'Evaluation-error recovery deferred: %d existing lab job(s) remain in %s.',
                $queueBacklog['total'],
                implode(', ', array_keys($queueBacklog['queues'])),
            ));

            return self::SUCCESS;
        }

        $agents = LabAgent::query()->with(['modelVersion', 'generation'])
            ->where('lifecycle_status', 'evaluation_error')
            ->where('timeframe', $timeframe)
            ->when($symbol, fn ($query) => $query->where('symbol', $symbol))
            ->whereHas('generation', function ($query) use ($afterRuntimeSchemaRepair, $afterRetryBudgetRepair, $generationNumber, $fullRecovery): void {
                if ($fullRecovery) {
                    // A full replay can finish the generation for its healthy
                    // peers while one candidate is quarantined as an
                    // evaluation_error. Keep that terminal generation
                    // recoverable without reopening screening evidence.
                    $query->whereIn('status', ['full_validation', 'completed', 'screened']);
                } else {
                    ($afterRuntimeSchemaRepair || $afterRetryBudgetRepair)
                            ? $query->whereIn('status', $afterRuntimeSchemaRepair ? ['screening', 'screened', 'technical_quarantine'] : ['screening', 'screened'])
                            : $query->where('status', 'screening');
                }
                if ($generationNumber !== null) {
                    $query->where('generation', $generationNumber);
                }
            })
            ->when(! $afterRuntimeSchemaRepair && ! $afterRetryBudgetRepair, fn ($query) => $query->where('updated_at', '<=', now()->subMinutes(5)))
            ->orderBy('id')
            ->get()
            ->filter(function (LabAgent $agent) use ($afterAuthRepair, $afterServiceRepair, $afterCodeRepair, $afterRuntimeSchemaRepair, $afterIpcRepair, $afterRetryBudgetRepair, $mode): bool {
                $attempts = (int) data_get($agent->modelVersion?->metadata, 'evaluator_recovery_attempts', 0);
                if ($this->hasQueuedJob($agent, $mode)) {
                    return false;
                }
                if ($afterAuthRepair) {
                    return $attempts >= 1 && str_contains((string) $agent->decision_reason, 'Invalid internal API token');
                }
                if ($afterServiceRepair) {
                    $reason = strtolower((string) $agent->decision_reason);
                    $transportFailure = str_contains($reason, 'curl error')
                        || str_contains($reason, 'failed to connect')
                        || str_contains($reason, 'timed out')
                        || str_contains($reason, 'attempted too many times');
                    // A restarted worker can also return a bounded runtime
                    // exception from stale code or a partially restarted
                    // child process.  It is still technical evidence, never
                    // a strategy verdict, and must be retryable only through
                    // this explicit post-service-repair mode.
                    $runtimeFailure = str_contains($reason, 'nameerror:')
                        || str_contains($reason, 'runtimeerror:');

                    // A queue worker can exhaust its retry budget before the
                    // recovery metadata transaction gets a chance to record
                    // attempt=1. The immutable reason is still required, so
                    // this cannot select a strategy gate failure.
                    return $transportFailure || $runtimeFailure;
                }
                if ($afterCodeRepair) {
                    $reason = strtolower((string) $agent->decision_reason);
                    // Keep the repair-specific budget independent from the
                    // generic evaluator recovery counter.  A prior bounded
                    // replay may already have consumed evaluator_recovery_
                    // attempts without ever running this repaired code path.
                    $codeRepairAttempts = (int) data_get($agent->modelVersion?->metadata, 'code_repair_recovery_attempts', 0);
                    $timestampNormalizationFailure = str_contains($reason, 'typeerror:')
                        && (str_contains($reason, 'tz-naive')
                            || str_contains($reason, 'tz-aware')
                            || str_contains($reason, 'timestamp'));
                    $pythonUndefinedName = str_contains($reason, 'nameerror:')
                        && str_contains($reason, ' is not defined');

                    return $codeRepairAttempts < 1
                        && str_contains($reason, 'strategy verdict withheld')
                        && (str_contains($reason, 'undefined variable')
                            || str_contains($reason, 'undefined method')
                            || $pythonUndefinedName
                            || $timestampNormalizationFailure);
                }
                if ($afterRuntimeSchemaRepair) {
                    $reason = strtolower((string) $agent->decision_reason);

                    // A first-run request-schema rejection has no prior
                    // recovery counter yet. It is still safe to retry once
                    // under this explicit repair flag; the later
                    // runtime_schema_recovery_attempts < 1 guard keeps it
                    // bounded. Include FastAPI's literal_error form because
                    // the error is a transport/runtime verdict, not strategy
                    // evidence.
                    return str_contains($reason, 'unknown parameter')
                        || str_contains($reason, 'noma\'lum parametr')
                        || str_contains($reason, 'schema')
                        || (str_contains($reason, 'literal_error') && str_contains($reason, 'target_direction'))
                        || (str_contains($reason, 'dict_type') && str_contains($reason, 'volume_context'));
                }
                if ($afterIpcRepair) {
                    // The old bounded worker could block on a full stdout pipe
                    // and report a timeout without ever producing a strategy
                    // verdict. A cURL reset from the controlled service
                    // reload is also operationally unresolved evidence. This
                    // mode is deliberately narrower than a generic transport
                    // recovery and can be used once after the containment
                    // fix and clean service reload are deployed.
                    $reason = strtolower((string) $agent->decision_reason);

                    return (str_contains($reason, 'bounded ai replay exceeded')
                        || str_contains($reason, 'curl error'))
                        && str_contains($reason, 'strategy verdict withheld');
                }
                if ($afterRetryBudgetRepair) {
                    $reason = strtolower((string) $agent->decision_reason);

                    return str_contains($reason, 'attempted too many times')
                        && str_contains($reason, 'strategy verdict withheld');
                }

                return $attempts < 1;
            })
            ->when($afterAuthRepair, function ($agents) {
                return $agents->filter(function (LabAgent $agent): bool {
                    $reason = (string) $agent->decision_reason;
                    $repairAttempts = (int) data_get($agent->modelVersion?->metadata, 'auth_repair_recovery_attempts', 0);

                    return str_contains($reason, 'Invalid internal API token') && $repairAttempts < 1;
                });
            })
            ->when($afterServiceRepair, function ($agents) {
                return $agents->filter(function (LabAgent $agent): bool {
                    return (int) data_get($agent->modelVersion?->metadata, 'service_repair_recovery_attempts', 0) < 1;
                });
            })
            ->when($afterRuntimeSchemaRepair, function ($agents) {
                return $agents->filter(function (LabAgent $agent): bool {
                    return (int) data_get($agent->modelVersion?->metadata, 'runtime_schema_recovery_attempts', 0) < 1;
                });
            })
            ->when($afterIpcRepair, function ($agents) {
                return $agents->filter(function (LabAgent $agent): bool {
                    return (int) data_get($agent->modelVersion?->metadata, 'ipc_repair_recovery_attempts', 0) < 1;
                });
            })
            ->when($afterRetryBudgetRepair, function ($agents) {
                return $agents->filter(function (LabAgent $agent): bool {
                    return (int) data_get($agent->modelVersion?->metadata, 'retry_budget_repair_recovery_attempts', 0) < 1;
                });
            })
            // Recovery ordering is operational only: it never creates or
            // changes strategy evidence.  Role-complete council members are
            // recovered first so the mandatory specialist/router lane cannot
            // be starved by a large backlog of ordinary challengers.
            ->sortBy(function (LabAgent $agent): array {
                $roleComplete = data_get($agent->modelVersion?->metadata, 'role_complete_council.protocol') === 'role_complete_council_v1';

                return [$roleComplete ? 0 : 1, (int) $agent->id];
            })
            ->take($limit)
            ->values();

        if ($agents->isEmpty()) {
            $this->info('No bounded evaluator failures are ready for recovery.');

            return self::SUCCESS;
        }

        // Every recovery batch carries a frozen generation/snapshot contract.
        // A hash mismatch is an infrastructure block, not a strategy failure;
        // leave the agent untouched and dispatch no replay for that row.
        $recoveryContracts = [];
        $recoverable = $agents->filter(function (LabAgent $agent) use ($recovery, $mode, &$recoveryContracts): bool {
            try {
                $recoveryContracts[$agent->id] = $recovery->prepare($agent, $mode);

                return true;
            } catch (\Throwable $exception) {
                $this->warn("A{$agent->id} G{$agent->lab_generation_id}: recovery snapshot/hash verification blocked replay: ".substr($exception->getMessage(), 0, 300));

                return false;
            }
        })->values();
        if ($recoverable->isEmpty()) {
            $this->warn('No evaluator recovery was dispatched because no same-generation dataset contract passed.');

            return self::FAILURE;
        }
        $agents = $recoverable;

        if (! $apply) {
            $this->table(['agent', 'generation', 'symbol', 'timeframe', 'mode', 'action'], $agents->map(fn (LabAgent $agent): array => [
                $agent->id, $agent->lab_generation_id, $agent->symbol, $agent->timeframe, $mode, 'would_recover_after_operator_approval',
            ])->all());
            $this->info('Dry-run only: no evaluator recovery was queued. Use --apply with --approved-by and --approval-reason after the lab queue is empty.');

            return self::SUCCESS;
        }

        try {
            $approvals->requireForApply('recover-lab-evaluation-errors', $this->option('approved-by'), $this->option('approval-reason'), [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'generation' => $generationNumber,
                'mode' => $mode,
                'agent_ids' => $agents->pluck('id')->values()->all(),
            ]);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        DB::transaction(function () use ($agents, $afterAuthRepair, $afterServiceRepair, $afterCodeRepair, $afterRuntimeSchemaRepair, $afterIpcRepair, $afterRetryBudgetRepair, $fullRecovery): void {
            foreach ($agents as $agent) {
                $metadata = $agent->modelVersion?->metadata ?? [];
                $attempts = (int) data_get($metadata, 'evaluator_recovery_attempts', 0) + 1;
                data_set($metadata, 'evaluator_recovery_attempts', $attempts);
                data_set($metadata, 'last_evaluator_recovery_at', now()->utc()->toIso8601String());
                if ($afterAuthRepair) {
                    data_set($metadata, 'auth_repair_recovery_attempts', (int) data_get($metadata, 'auth_repair_recovery_attempts', 0) + 1);
                    data_set($metadata, 'last_auth_repair_recovery_at', now()->utc()->toIso8601String());
                }
                if ($afterServiceRepair) {
                    data_set($metadata, 'service_repair_recovery_attempts', (int) data_get($metadata, 'service_repair_recovery_attempts', 0) + 1);
                    data_set($metadata, 'last_service_repair_recovery_at', now()->utc()->toIso8601String());
                }
                if ($afterCodeRepair) {
                    data_set($metadata, 'code_repair_recovery_attempts', (int) data_get($metadata, 'code_repair_recovery_attempts', 0) + 1);
                    data_set($metadata, 'last_code_repair_recovery_at', now()->utc()->toIso8601String());
                }
                if ($afterRuntimeSchemaRepair) {
                    data_set($metadata, 'runtime_schema_recovery_attempts', (int) data_get($metadata, 'runtime_schema_recovery_attempts', 0) + 1);
                    data_set($metadata, 'last_runtime_schema_recovery_at', now()->utc()->toIso8601String());
                }
                if ($afterIpcRepair) {
                    data_set($metadata, 'ipc_repair_recovery_attempts', (int) data_get($metadata, 'ipc_repair_recovery_attempts', 0) + 1);
                    data_set($metadata, 'last_ipc_repair_recovery_at', now()->utc()->toIso8601String());
                }
                if ($afterRetryBudgetRepair) {
                    data_set($metadata, 'retry_budget_repair_recovery_attempts', (int) data_get($metadata, 'retry_budget_repair_recovery_attempts', 0) + 1);
                    data_set($metadata, 'last_retry_budget_repair_recovery_at', now()->utc()->toIso8601String());
                }
                $agent->modelVersion?->update(['metadata' => $metadata]);
                $recoveryPrefix = $afterAuthRepair
                    ? 'Post-auth-repair evaluator recovery attempt '
                    : ($afterServiceRepair
                        ? 'Post-service-repair evaluator recovery attempt '
                        : ($afterCodeRepair
                            ? 'Post-code-repair evaluator recovery attempt '
                            : ($afterRuntimeSchemaRepair
                            ? 'Post-runtime-schema-repair evaluator recovery attempt '
                            : ($afterIpcRepair
                                ? 'Post-IPC-repair evaluator recovery attempt '
                                : ($afterRetryBudgetRepair
                                    ? 'Post-retry-budget-repair evaluator recovery attempt '
                                    : 'Evaluator recovery attempt ')))));
                $agent->update([
                    'lifecycle_status' => $fullRecovery ? 'full_queued' : 'queued',
                    'decision_reason' => $recoveryPrefix.$attempts.'; '.($fullRecovery ? 'full ' : '').'strategy verdict remains withheld until clean replay.',
                ]);
                $agent->generation()->update(['status' => $fullRecovery ? 'full_validation' : 'screening', 'completed_at' => null]);
            }
        });

        $batches = [];
        foreach ($agents->groupBy('symbol') as $agentSymbol => $symbolAgents) {
            $batch = Bus::batch($symbolAgents->map(fn (LabAgent $agent) => new EvaluateLabAgentJob(
                $agent->id,
                $agent->symbol,
                $mode,
                $recoveryContracts[$agent->id] ?? null,
            ))->all())
                ->name('Bounded lab evaluator recovery '.$mode.' '.$agentSymbol)
                ->allowFailures()
                ->onConnection((string) config('queue.default', 'redis'))
                ->onQueue($mode === 'full'
                    ? 'lab-full-validation'
                    : (string) config('services.lab_queue.screening_queue', 'lab-screening'))
                ->dispatch();
            $batches[] = $batch->id;
        }

        $this->info('Queued '.$agents->count().' '.$mode.' evaluator recoveries; batches '.implode(', ', $batches).'. No promotion evidence was created.');

        return self::SUCCESS;
    }

    private function hasQueuedJob(LabAgent $agent, string $mode): bool
    {
        $queue = $mode === 'full'
            ? 'lab-full-validation'
            : (string) config('services.lab_queue.screening_queue', 'lab-screening');

        $queues = $mode === 'full'
            ? [$queue]
            : array_values(array_unique(array_merge(
                [$queue],
                [(string) config('services.lab_queue.frontier_queue', 'lab-frontier')],
                (array) config('services.lab_queue.legacy_screening_queues', []),
            )));

        return app(LabQueueJobInspector::class)->hasAgentJob((int) $agent->id, $queues);
    }

    /** @return array{total: int, queues: array<string, int>} */
    private function queueBacklog(): array
    {
        $queues = array_values(array_unique(array_filter([
            (string) config('services.lab_queue.screening_queue', 'lab-screening'),
            (string) config('services.lab_queue.frontier_queue', 'lab-frontier'),
            (string) config('services.lab_queue.full_validation_queue', 'lab-full-validation'),
            ...((array) config('services.lab_queue.legacy_screening_queues', [])),
        ])));
        $counts = DB::table('jobs')
            ->whereIn('queue', $queues)
            ->selectRaw('queue, COUNT(*) as total')
            ->groupBy('queue')
            ->pluck('total', 'queue')
            ->map(fn ($count): int => (int) $count)
            ->all();

        return ['total' => array_sum($counts), 'queues' => $counts];
    }
}
