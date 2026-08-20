<?php

namespace App\Console\Commands;

use App\Models\ModelMarketPerformance;
use App\Models\MtfAblationRun;
use App\Models\MtfStrategyResearchRun;
use App\Services\ExecutionContractService;
use App\Services\MarketData\CandlePayloadService;
use App\Services\MarketData\MarketVolumeService;
use App\Services\MtfStrategyResearchService;
use App\Services\MtfStrategyResearchReportService;
use App\Services\MtfResearchSnapshotService;
use App\Services\MultiTimeframePilotService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class RunMtfStrategyResearch extends Command
{
    protected $signature = 'trading:mtf-strategy-research
        {--candidate= : Valid XAUUSD M15 near-miss candidate; defaults to the newest valid candidate}
        {--symbol=XAUUSD : Lighthouse symbol}
        {--hypothesis= : Run one catalog hypothesis by key}
        {--hypotheses= : Run comma-separated catalog hypotheses on one immutable candle snapshot}
        {--bootstrap-control : Create the exact frozen four-lane control when this snapshot has no matching control}
        {--control-run= : Reuse the exact immutable snapshot from an existing MTF ablation run id}
        {--validate-forward : Add bounded cost/exit stress and chronological forward diagnostics to each immutable row}
        {--limit=4 : Maximum number of bounded hypotheses, up to twelve}
        {--json : Print the immutable research rows as JSON}';

    protected $description = 'Run bounded XAUUSD H1/M15 strategy hypotheses under the sealed four-lane contract';

    public function handle(
        CandlePayloadService $candles,
        MtfStrategyResearchService $research,
        MultiTimeframePilotService $pilot,
        StrategyParameterSchemaService $schemas,
        MarketVolumeService $volumes,
        MtfStrategyResearchReportService $researchReport,
        MtfResearchSnapshotService $snapshots,
    ): int {
        $symbol = strtoupper(str_replace(['/', '_', '-'], '', (string) $this->option('symbol')));
        $candidateQuery = ModelMarketPerformance::with('modelVersion')
            ->where('symbol', $symbol)
            ->where('timeframe', 'M15')
            ->where('evidence_status', 'valid')
            ->whereHas('modelVersion', fn ($query) => $query->where('evidence_status', 'valid'))
            ->whereIn('status', ['forward_validated', 'paper', 'rejected'])
            ->latest('id');
        if (filled($this->option('candidate'))) {
            $candidateQuery->whereKey((int) $this->option('candidate'));
        }
        $candidate = $candidateQuery->first();
        if (! $candidate || ! $candidate->modelVersion) {
            $this->error("{$symbol} M15 uchun valid research candidate topilmadi.");
            return self::FAILURE;
        }

        $batch = trim((string) $this->option('hypotheses'));
        if ($batch !== '') {
            $requested = array_values(array_filter(array_map('trim', explode(',', $batch))));
            $catalog = $research->catalog();
            $experiments = array_values(array_filter(
                $catalog,
                fn (array $item): bool => in_array((string) $item['key'], $requested, true),
            ));
            if (count($experiments) !== count(array_unique($requested))) {
                $known = array_map(fn (array $item): string => (string) $item['key'], $catalog);
                $unknown = array_values(array_diff(array_unique($requested), $known));
                $this->error('Noma’lum MTF hypothesis key: '.implode(', ', $unknown));
                return self::FAILURE;
            }
        } else {
            $limit = max(1, (int) $this->option('limit'));
            if ($this->option('hypothesis')) {
                $experiments = $research->select((string) $this->option('hypothesis'), $limit);
            } else {
                // Keep the four-lane control frozen, but rotate the bounded
                // challenger frontier across still-unobserved families.
                $report = $researchReport->report($symbol, 720);
                $currentDataHash = (string) data_get($report, 'current_cohort_data_hash', '');
                $observations = collect((array) data_get($report, 'runs', []))
                    ->when($currentDataHash !== '', fn ($rows) => $rows->where('data_hash', $currentDataHash))
                    ->values()
                    ->all();
                $experiments = $research->selectFrontier(
                    $observations,
                    (array) data_get($report, 'family_budget', []),
                    $limit,
                    $currentDataHash !== '' ? $currentDataHash : null,
                );
            }
        }
        if ($experiments === []) {
            $this->error('So‘ralgan MTF hypothesis catalog’da topilmadi.');
            return self::FAILURE;
        }

        $snapshotRun = null;
        $snapshot = null;
        if (filled($this->option('control-run'))) {
            $snapshotRun = MtfAblationRun::query()
                ->whereKey((int) $this->option('control-run'))
                ->where('model_market_performance_id', $candidate->id)
                ->where('symbol', $symbol)
                ->where('status', 'completed')
                ->first();
            $snapshot = $snapshots->load($snapshotRun);
            if (! $snapshotRun || ! $snapshot) {
                $this->error('Ko\'rsatilgan MTF control run immutable snapshotga ega emas yoki snapshot integrity tekshiruvidan o\'tmadi.');
                return self::FAILURE;
            }
        }

        $volumeContext = $snapshot
            ? (array) ($snapshot['volume_context'] ?? [])
            : $volumes->mtfContext($symbol);
        $volumeHypotheses = array_values(array_filter(
            $experiments,
            fn (array $item): bool => (string) ($item['volume_lane'] ?? 'none') !== 'none',
        ));
        $volumeFreshness = $research->volumeResearchFreshness($volumeContext);
        if ($volumeHypotheses !== [] && ! (bool) ($volumeFreshness['ready'] ?? false)) {
            $this->error('Volume hypothesis replay bloklandi: canonical volume freshness contract bajarilmadi: '.json_encode($volumeFreshness, JSON_UNESCAPED_SLASHES));
            return self::FAILURE;
        }

        // Every comparison now uses the same canonical price+volume snapshot.
        // The no-volume control remains explicit through volume_lane=none.
        $m15 = $snapshot
            ? array_values((array) ($snapshot['m15_candles'] ?? []))
            : $candles->candlesForTraining($symbol, 'M15', limit: 5000, includeVolume: true);
        $h1 = $snapshot
            ? array_values((array) ($snapshot['h1_candles'] ?? []))
            : $candles->candlesForTraining($symbol, 'H1', limit: 2000, includeVolume: true);
        if (count($m15) < 200 || count($h1) < 200) {
            $this->error('Strategy research uchun mustaqil M15 va H1 candle stream yetarli emas.');
            return self::FAILURE;
        }

        $execution = app(ExecutionContractService::class)->for($symbol, 'M15');
        $latestH1 = $h1[array_key_last($h1)] ?? [];
        $latestM15 = $m15[array_key_last($m15)] ?? [];
        $dataHash = $pilot->hash([
            'symbol' => $symbol,
            'h1_count' => count($h1),
            'm15_count' => count($m15),
            'h1_first' => data_get($h1[0] ?? [], 'time'),
            'h1_last' => data_get($latestH1, 'time'),
            'm15_first' => data_get($m15[0] ?? [], 'time'),
            'm15_last' => data_get($latestM15, 'time'),
            'volume_context_hash' => $pilot->hash($volumeContext),
        ]);
        $executionHash = (string) data_get($execution, 'execution_hash', '');
        if ($snapshotRun && (string) $snapshotRun->data_hash !== $dataHash) {
            $this->error('Control snapshot data hash qayta hisoblangan hash bilan mos emas; replay fail-closed qilindi.');
            return self::FAILURE;
        }
        if ($snapshotRun && (string) $snapshotRun->execution_hash !== $executionHash) {
            $this->error('Control snapshot execution hash joriy execution contract bilan mos emas; replay fail-closed qilindi.');
            return self::FAILURE;
        }
        $frozenControl = $snapshotRun ?: MtfAblationRun::query()
            ->where('model_market_performance_id', $candidate->id)
            ->where('data_hash', $dataHash)
            ->where('execution_hash', $executionHash)
            ->where('status', 'completed')
            ->latest('completed_at')
            ->first();
        $bootstrappedControl = false;
        if (! $frozenControl && $this->option('bootstrap-control')) {
            $model = $candidate->modelVersion;
            $controlPilot = $pilot->requestPayload($symbol, 'M15', $model->strategy);
            $controlExecution = app(ExecutionContractService::class)->for($symbol, 'M15');
            $controlRunKey = $pilot->hash([
                'candidate_id' => $candidate->id,
                'pilot_id' => data_get($controlPilot, 'pilot_id'),
                'data_hash' => $dataHash,
                'execution_hash' => $executionHash,
                'contract_hash' => data_get($controlPilot, 'contract_hash'),
            ]);
            $frozenControl = MtfAblationRun::query()->where('run_key', $controlRunKey)->first();
            if (! $frozenControl) {
                $controlPayload = [
                    'symbol' => $symbol, 'timeframe' => 'M15', 'strategy' => $model->strategy,
                    'base_strategy' => $schemas->runtimeBaseStrategy($model->strategy, data_get($model->metadata, 'base_strategy'), $candidate->strategy_family),
                    'parameters' => [...((array) ($model->parameters ?? [])), 'volume_lane' => 'none'], 'initial_balance' => 10000, 'risk_per_trade' => 1,
                    'execution' => $controlExecution['parameters'], 'execution_contract' => $controlExecution,
                    'volume_context' => $volumeContext,
                    'mtf_pilot' => $controlPilot,
                ];
                $controlResponse = Http::timeout((int) config('services.ai_service.backtest_timeout_seconds', 900))
                    ->acceptJson()->withHeaders(['X-Internal-Token' => (string) config('services.internal_api.token')])
                    ->post(rtrim(config('services.ai_service.url'), '/').'/api/backtest/mtf-ablation', [
                        'base_request' => $controlPayload, 'h1_candles' => $h1, 'm15_candles' => $m15, 'lightweight' => true,
                    ]);
                if ($controlResponse->failed()) {
                    $this->error('Exact frozen control bootstrap AI service xatosi: '.substr((string) $controlResponse->body(), 0, 1000));
                    return self::FAILURE;
                }
                $controlResult = (array) $controlResponse->json();
                $snapshotReference = $snapshots->store(
                    $controlRunKey,
                    $symbol,
                    $h1,
                    $m15,
                    $volumeContext,
                    $controlExecution['parameters'],
                    $dataHash,
                    $executionHash,
                    $controlPilot,
                );
                $frozenControl = MtfAblationRun::firstOrCreate(
                    ['run_key' => $controlRunKey],
                    [
                        'model_market_performance_id' => $candidate->id,
                        'pilot_id' => (string) data_get($controlPilot, 'pilot_id', config('services.mtf_pilot.pilot_id')),
                        'symbol' => $symbol, 'regime_timeframe' => 'H1', 'entry_timeframe' => 'M15',
                        'run_key' => $controlRunKey, 'data_hash' => $dataHash, 'execution_hash' => $executionHash,
                        'status' => 'completed', 'variants' => (array) ($controlResult['variants'] ?? []),
                        'snapshot_reference' => $snapshotReference,
                        'promotion_evidence' => false, 'completed_at' => now(),
                    ],
                );
                $bootstrappedControl = true;
            }
        }
        if (! $frozenControl) {
            $this->error('Avval shu candidate/data/execution hash uchun trading:mtf-ablation ishlating; frozen M15 control bo‘lmasa hypothesis replay boshlanmaydi.');
            return self::FAILURE;
        }
        // A multi-hypothesis cohort is sent in one deterministic request so
        // the Python service can reuse the immutable H1/M15 feature cache.
        // Keep the single-hypothesis path below for narrow operator retries.
        if (count($experiments) > 1) {
            $batch = $this->runSharedHypothesisBatch(
                $experiments, $candidate, $symbol, $m15, $h1, $execution,
                $dataHash, $executionHash, $volumeContext, $frozenControl, $bootstrappedControl,
                $research, $pilot, $schemas, false,
            );
            if ($this->option('validate-forward')) {
                $batch = $this->runTargetedValidationForBatch(
                    $batch, $experiments, $candidate, $symbol, $m15, $h1, $execution,
                    $dataHash, $executionHash, $volumeContext, $frozenControl,
                    $research, $pilot, $schemas,
                );
            }
            $output = $batch['output'];
            if ($this->option('json')) {
                $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->info("XAUUSD MTF strategy research candidate #{$candidate->id} (research-only, shared batch)");
                $this->table(['Hypothesis', 'Strategy', 'Status', 'MTF PF', 'MTF Net %', 'MTF DD %', 'Frozen M15 PF'], array_map(
                    fn (array $row): array => [
                        $row['hypothesis_key'], $row['strategy_identity'], $row['status'],
                        $row['mtf_pf'], $row['mtf_net_percent'], $row['mtf_dd_percent'], $row['frozen_m15_pf'],
                    ],
                    $output['rows'],
                ));
                $this->comment("Shared feature/signal snapshot ishlatildi; natijalar immutable research history'ga yozildi.");
            }
            return $batch['successful'] > 0 ? self::SUCCESS : self::FAILURE;
        }

        $rows = [];
        $successful = 0;

        foreach ($experiments as $experiment) {
            $strategy = (string) $experiment['strategy'];
            $baseStrategy = $schemas->runtimeBaseStrategy(
                $strategy,
                null,
                (string) ($experiment['runtime_family'] ?? $experiment['family']),
            );
            $parameters = $research->parametersFor($candidate, $experiment);
            $parameterHash = $pilot->hash([
                'strategy' => $strategy,
                'base_strategy' => $baseStrategy,
                'parameters' => $parameters,
            ]);
            $contract = $research->contract(
                $experiment,
                $symbol,
                (int) $candidate->id,
                $dataHash,
                $parameterHash,
                $executionHash,
                $frozenControl?->id,
                $volumeContext,
            );
            if ($this->option('validate-forward')) {
                $contract['targeted_validation'] = [
                    'protocol' => 'xauusd_mtf_targeted_validation_v1',
                    'cost_profiles' => ['cost_1_5x', 'cost_2x'],
                    'exit_profiles' => ['wider_stop_tighter_target', 'tighter_stop_wider_target'],
                    'chronological_holdout' => true,
                    'promotion_evidence' => false,
                ];
            }
            $baseRunKey = $pilot->hash([
                'research_protocol' => MtfStrategyResearchService::PROTOCOL,
                'candidate_id' => $candidate->id,
                'hypothesis_key' => $experiment['key'],
                'strategy' => $strategy,
                'parameter_hash' => $parameterHash,
                'data_hash' => $dataHash,
                'execution_hash' => $executionHash,
                'frozen_control_run_id' => $frozenControl?->id,
                'mtf_contract_hash' => data_get($pilot->requestPayload($symbol, 'M15', $strategy), 'contract_hash'),
                'targeted_validation_protocol' => $this->option('validate-forward')
                    ? 'xauusd_mtf_targeted_validation_v1'
                    : null,
            ]);

            $existing = MtfStrategyResearchRun::query()->where('run_key', $baseRunKey)->first();
            $technicalRecoveryOf = null;
            $runKey = $baseRunKey;
            if ($existing?->status === 'technical_error') {
                // Immutable technical evidence must remain visible, but a
                // repaired runtime/schema needs one deterministic retry lane.
                // The retry is keyed by the original row, so it cannot create
                // an unbounded duplicate loop.
                $technicalRecoveryOf = $existing->id;
                $runKey = $pilot->hash([
                    'base_run_key' => $baseRunKey,
                    'technical_recovery_protocol' => 'mtf_research_technical_retry_v1',
                    'technical_recovery_of_run_id' => $technicalRecoveryOf,
                ]);
                $existing = MtfStrategyResearchRun::query()->where('run_key', $runKey)->first();
            }
            if ($existing) {
                $rows[] = $this->rowFromRun($existing, true);
                if ($existing->status === 'completed') $successful++;
                continue;
            }

            $payload = [
                'symbol' => $symbol,
                'timeframe' => 'M15',
                'strategy' => $strategy,
                'base_strategy' => $baseStrategy,
                'parameters' => $parameters,
                'initial_balance' => 10000,
                'risk_per_trade' => 1,
                'execution' => $execution['parameters'],
                'execution_contract' => $execution,
                'volume_context' => $volumeContext,
                'mtf_pilot' => $pilot->requestPayload($symbol, 'M15', $strategy),
            ];
            $coreRun = $this->completedCoreRun(
                $candidate,
                (string) $experiment['key'],
                $dataHash,
                $parameterHash,
                $executionHash,
            );
            if ($technicalRecoveryOf !== null) {
                $contract['technical_recovery'] = [
                    'protocol' => 'mtf_research_technical_retry_v1',
                    'recovery_of_run_id' => $technicalRecoveryOf,
                    'reason' => 'previous_run_was_technical_error',
                    'promotion_evidence' => false,
                ];
            }

            $requestBody = [
                'base_request' => $payload,
                'h1_candles' => $h1,
                'm15_candles' => $m15,
                'lightweight' => true,
            ];
            $endpoint = '/api/backtest/mtf-ablation';
            if ($this->option('validate-forward')) {
                if (! $coreRun) {
                    $this->error("{$experiment['key']} uchun completed core replay topilmadi; avval core-only hypothesis replay ishlating.");
                    return self::FAILURE;
                }
                $decision = $this->targetedValidationDecision(
                    $coreRun,
                    (string) ($experiment['target_gate'] ?? 'unknown'),
                    $research,
                );
                if (! ($decision['eligible'] ?? false)) {
                    $rows[] = [
                        ...$this->rowFromRun($coreRun, true),
                        'targeted_validation' => [
                            'status' => 'deferred_until_target_gate',
                            'admission' => $decision,
                            'promotion_evidence' => false,
                        ],
                    ];
                    $successful++;
                    continue;
                }
                // Reuse the immutable core row and send only the paired
                // diagnostic request. This prevents validation from rerunning
                // all four controlled lanes.
                $endpoint = '/api/backtest/mtf-targeted-validation';
                $requestBody['core_result'] = (array) data_get($coreRun->result, 'variants.h1_veto_m15_risk', []);
                $requestBody['validation'] = $this->targetedValidationPayload($m15, $h1);
            }

            $response = null;
            $transportError = null;
            try {
                $response = Http::timeout((int) config('services.ai_service.backtest_timeout_seconds', 900))
                    ->acceptJson()
                    ->withHeaders(['X-Internal-Token' => (string) config('services.internal_api.token')])
                    ->post(rtrim(config('services.ai_service.url'), '/').$endpoint, [
                        ...$requestBody,
                    ]);
            } catch (ConnectionException $exception) {
                $transportError = $exception->getMessage();
            }

            if ($response === null || $response->failed()) {
                $run = MtfStrategyResearchRun::create([
                    'model_market_performance_id' => $candidate->id,
                    'pilot_id' => (string) data_get($payload, 'mtf_pilot.pilot_id', config('services.mtf_pilot.pilot_id')),
                    'symbol' => $symbol,
                    'regime_timeframe' => 'H1',
                    'entry_timeframe' => 'M15',
                    'hypothesis_key' => $experiment['key'],
                    'strategy_identity' => $strategy,
                    'strategy_family' => $experiment['family'],
                    'run_key' => $runKey,
                    'data_hash' => $dataHash,
                    'parameter_hash' => $parameterHash,
                    'execution_hash' => $executionHash,
                    'status' => 'technical_error',
                    'failure_class' => 'evidence_recovery',
                    'research_contract' => $contract,
                    'parameters' => $parameters,
                    'result' => [
                        'http_status' => $response?->status(),
                        'body' => substr((string) ($transportError ?? ($response?->body() ?? 'missing response')), 0, 1000),
                        'frozen_control' => $this->frozenControlPayload($frozenControl),
                        'technical_recovery_of_run_id' => $technicalRecoveryOf,
                        'promotion_evidence' => false,
                    ],
                    'promotion_evidence' => false,
                    'completed_at' => now(),
                ]);
                $rows[] = $this->rowFromRun($run, false);
                continue;
            }

            $result = (array) $response->json();
            if ($this->option('validate-forward')) {
                $result = [
                    'variants' => (array) data_get($coreRun?->result, 'variants', []),
                    'targeted_validation' => (array) data_get($result, 'targeted_validation', []),
                    'targeted_validation_optimization' => (array) data_get($result, 'optimization', []),
                    'core_replay_reused' => true,
                    'core_research_run_id' => $coreRun?->id,
                    'promotion_evidence' => false,
                ];
            }
            $result['research_contract'] = $contract;
            $result['candidate_id'] = $candidate->id;
            $result['frozen_control'] = $this->frozenControlPayload($frozenControl);
            $result['promotion_evidence'] = false;
            $run = MtfStrategyResearchRun::create([
                'model_market_performance_id' => $candidate->id,
                'pilot_id' => (string) data_get($payload, 'mtf_pilot.pilot_id', config('services.mtf_pilot.pilot_id')),
                'symbol' => $symbol,
                'regime_timeframe' => 'H1',
                'entry_timeframe' => 'M15',
                'hypothesis_key' => $experiment['key'],
                'strategy_identity' => $strategy,
                'strategy_family' => $experiment['family'],
                'run_key' => $runKey,
                'data_hash' => $dataHash,
                'parameter_hash' => $parameterHash,
                'execution_hash' => $executionHash,
                'status' => 'completed',
                'research_contract' => $contract,
                'parameters' => $parameters,
                'result' => $result,
                'promotion_evidence' => false,
                'completed_at' => now(),
            ]);
            $successful++;
            $rows[] = $this->rowFromRun($run, false);
        }

        $output = [
            'protocol' => MtfStrategyResearchService::PROTOCOL,
            'symbol' => $symbol,
            'candidate_id' => $candidate->id,
            'data_hash' => $dataHash,
            'execution_hash' => $executionHash,
            'frozen_control_run_id' => $frozenControl?->id,
            'bootstrapped_control' => $bootstrappedControl,
            'hypothesis_budget' => count($experiments),
            'promotion_evidence' => false,
            'rows' => $rows,
        ];
        if ($this->option('json')) {
            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("XAUUSD MTF strategy research candidate #{$candidate->id} (research-only)");
            $this->table(['Hypothesis', 'Strategy', 'Status', 'MTF PF', 'MTF Net %', 'MTF DD %', 'Frozen M15 PF'], array_map(
                fn (array $row): array => [
                    $row['hypothesis_key'], $row['strategy_identity'], $row['status'],
                    $row['mtf_pf'], $row['mtf_net_percent'], $row['mtf_dd_percent'], $row['frozen_m15_pf'],
                ],
                $rows,
            ));
            $this->comment('Natijalar immutable research history’ga yozildi; promotion yoki paper avtomatik emas.');
        }

        return $successful > 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Run a bounded cohort through one Python request and one shared market
     * snapshot. Python remains serial/deterministic; only common feature and
     * signal preparation is shared across the hypotheses and stress lanes.
     *
     * @return array{output: array<string,mixed>, successful: int}
     */
    private function runSharedHypothesisBatch(
        array $experiments,
        ModelMarketPerformance $candidate,
        string $symbol,
        array $m15,
        array $h1,
        array $execution,
        string $dataHash,
        string $executionHash,
        array $volumeContext,
        ?MtfAblationRun $frozenControl,
        bool $bootstrappedControl,
        MtfStrategyResearchService $research,
        MultiTimeframePilotService $pilot,
        StrategyParameterSchemaService $schemas,
        bool $includeTargetedValidation = false,
    ): array {
        $rows = [];
        $successful = 0;
        $pending = [];

        foreach ($experiments as $experiment) {
            $strategy = (string) $experiment['strategy'];
            $baseStrategy = $schemas->runtimeBaseStrategy(
                $strategy,
                null,
                (string) ($experiment['runtime_family'] ?? $experiment['family']),
            );
            $parameters = $research->parametersFor($candidate, $experiment);
            $parameterHash = $pilot->hash([
                'strategy' => $strategy,
                'base_strategy' => $baseStrategy,
                'parameters' => $parameters,
            ]);
            $contract = $research->contract(
                $experiment,
                $symbol,
                (int) $candidate->id,
                $dataHash,
                $parameterHash,
                $executionHash,
                $frozenControl?->id,
                $volumeContext,
            );
            if ($includeTargetedValidation) {
                $contract['targeted_validation'] = [
                    'protocol' => 'xauusd_mtf_targeted_validation_v1',
                    'cost_profiles' => ['cost_1_5x', 'cost_2x'],
                    'exit_profiles' => ['wider_stop_tighter_target', 'tighter_stop_wider_target'],
                    'chronological_holdout' => true,
                    'promotion_evidence' => false,
                ];
            }
            $baseRunKey = $pilot->hash([
                'research_protocol' => MtfStrategyResearchService::PROTOCOL,
                'candidate_id' => $candidate->id,
                'hypothesis_key' => $experiment['key'],
                'strategy' => $strategy,
                'parameter_hash' => $parameterHash,
                'data_hash' => $dataHash,
                'execution_hash' => $executionHash,
                'frozen_control_run_id' => $frozenControl?->id,
                'mtf_contract_hash' => data_get($pilot->requestPayload($symbol, 'M15', $strategy), 'contract_hash'),
                'targeted_validation_protocol' => $includeTargetedValidation
                    ? 'xauusd_mtf_targeted_validation_v1'
                    : null,
            ]);

            $existing = MtfStrategyResearchRun::query()->where('run_key', $baseRunKey)->first();
            $technicalRecoveryOf = null;
            $runKey = $baseRunKey;
            if ($existing?->status === 'technical_error') {
                $technicalRecoveryOf = $existing->id;
                $runKey = $pilot->hash([
                    'base_run_key' => $baseRunKey,
                    'technical_recovery_protocol' => 'mtf_research_technical_retry_v1',
                    'technical_recovery_of_run_id' => $technicalRecoveryOf,
                ]);
                $existing = MtfStrategyResearchRun::query()->where('run_key', $runKey)->first();
            }
            if ($existing) {
                $rows[] = $this->rowFromRun($existing, true);
                if ($existing->status === 'completed') {
                    $successful++;
                }
                continue;
            }

            $payload = [
                'symbol' => $symbol,
                'timeframe' => 'M15',
                'strategy' => $strategy,
                'base_strategy' => $baseStrategy,
                'parameters' => $parameters,
                'initial_balance' => 10000,
                'risk_per_trade' => 1,
                'execution' => $execution['parameters'],
                'execution_contract' => $execution,
                'volume_context' => $volumeContext,
                'mtf_pilot' => $pilot->requestPayload($symbol, 'M15', $strategy),
            ];
            if ($technicalRecoveryOf !== null) {
                $contract['technical_recovery'] = [
                    'protocol' => 'mtf_research_technical_retry_v1',
                    'recovery_of_run_id' => $technicalRecoveryOf,
                    'reason' => 'previous_run_was_technical_error',
                    'promotion_evidence' => false,
                ];
            }

            $item = [
                'key' => (string) $experiment['key'],
                'experiment' => $experiment,
                'strategy' => $strategy,
                'parameters' => $parameters,
                'parameter_hash' => $parameterHash,
                'payload' => $payload,
                'contract' => $contract,
                'run_key' => $runKey,
                'technical_recovery_of' => $technicalRecoveryOf,
            ];
            $pending[] = $item;
        }

        $batchResponse = null;
        $batchTransportError = null;
        $batchResult = [];
        if ($pending !== []) {
            $items = array_map(function (array $item) use ($m15, $h1): array {
                $request = [
                    'key' => $item['key'],
                    'base_request' => $item['payload'],
                ];
                return $request;
            }, $pending);

            $sharedValidation = $includeTargetedValidation
                ? $this->targetedValidationPayload($m15, $h1)
                : null;

            try {
                $batchResponse = Http::timeout((int) config('services.ai_service.backtest_timeout_seconds', 900))
                    ->acceptJson()
                    ->withHeaders(['X-Internal-Token' => (string) config('services.internal_api.token')])
                    ->post(rtrim(config('services.ai_service.url'), '/').'/api/backtest/mtf-hypothesis-batch', [
                        'hypotheses' => $items,
                        'h1_candles' => $h1,
                        'm15_candles' => $m15,
                        'validation' => $sharedValidation,
                        'lightweight' => true,
                    ]);
            } catch (ConnectionException $exception) {
                // A transport timeout is technical evidence, never a strategy
                // verdict. The immutable rows below make the failure visible
                // and let the deterministic recovery identity retry it later.
                $batchResponse = null;
                $batchTransportError = $exception->getMessage();
            }
            if ($batchResponse !== null && $batchResponse->successful()) {
                $batchResult = (array) $batchResponse->json();
            }
        }

        foreach ($pending as $item) {
            $result = (array) (($batchResult['results'] ?? [])[$item['key']] ?? []);
            if ($batchResponse === null || $batchResponse->failed() || $result === []) {
                $run = MtfStrategyResearchRun::create([
                    'model_market_performance_id' => $candidate->id,
                    'pilot_id' => (string) data_get($item['payload'], 'mtf_pilot.pilot_id', config('services.mtf_pilot.pilot_id')),
                    'symbol' => $symbol,
                    'regime_timeframe' => 'H1',
                    'entry_timeframe' => 'M15',
                    'hypothesis_key' => $item['key'],
                    'strategy_identity' => $item['strategy'],
                    'strategy_family' => $item['experiment']['family'],
                    'run_key' => $item['run_key'],
                    'data_hash' => $dataHash,
                    'parameter_hash' => $item['parameter_hash'],
                    'execution_hash' => $executionHash,
                    'status' => 'technical_error',
                    'failure_class' => 'evidence_recovery',
                    'research_contract' => $item['contract'],
                    'parameters' => $item['parameters'],
                    'result' => [
                        'http_status' => $batchResponse?->status(),
                        'body' => substr((string) ($batchTransportError ?? ($batchResponse?->body() ?? 'missing batch result')), 0, 1000),
                        'batch_endpoint' => '/api/backtest/mtf-hypothesis-batch',
                        'frozen_control' => $this->frozenControlPayload($frozenControl),
                        'technical_recovery_of_run_id' => $item['technical_recovery_of'],
                        'promotion_evidence' => false,
                    ],
                    'promotion_evidence' => false,
                    'completed_at' => now(),
                ]);
                $rows[] = $this->rowFromRun($run, false);
                continue;
            }

            $result['research_contract'] = $item['contract'];
            $result['candidate_id'] = $candidate->id;
            $result['frozen_control'] = $this->frozenControlPayload($frozenControl);
            $result['batch_optimization'] = (array) ($batchResult['optimization'] ?? []);
            $result['promotion_evidence'] = false;
            $run = MtfStrategyResearchRun::create([
                'model_market_performance_id' => $candidate->id,
                'pilot_id' => (string) data_get($item['payload'], 'mtf_pilot.pilot_id', config('services.mtf_pilot.pilot_id')),
                'symbol' => $symbol,
                'regime_timeframe' => 'H1',
                'entry_timeframe' => 'M15',
                'hypothesis_key' => $item['key'],
                'strategy_identity' => $item['strategy'],
                'strategy_family' => $item['experiment']['family'],
                'run_key' => $item['run_key'],
                'data_hash' => $dataHash,
                'parameter_hash' => $item['parameter_hash'],
                'execution_hash' => $executionHash,
                'status' => 'completed',
                'research_contract' => $item['contract'],
                'parameters' => $item['parameters'],
                'result' => $result,
                'promotion_evidence' => false,
                'completed_at' => now(),
            ]);
            $successful++;
            $rows[] = $this->rowFromRun($run, false);
        }

        return [
            'output' => [
                'protocol' => MtfStrategyResearchService::PROTOCOL,
                'symbol' => $symbol,
                'candidate_id' => $candidate->id,
                'data_hash' => $dataHash,
                'execution_hash' => $executionHash,
                'frozen_control_run_id' => $frozenControl?->id,
                'bootstrapped_control' => $bootstrappedControl,
                'hypothesis_budget' => count($experiments),
                'batch_optimization' => (array) ($batchResult['optimization'] ?? [
                    'status' => 'failed_or_empty',
                    'promotion_evidence' => false,
                ]),
                'promotion_evidence' => false,
                'rows' => $rows,
            ],
            'successful' => $successful,
        ];
    }

    /**
     * Run expensive diagnostics only for core rows that beat the frozen
     * reference on at least one declared observable metric. The core batch is
     * deliberately cheap and complete before this method is entered; a
     * timeout here can therefore never erase or reinterpret the screening
     * result.
     *
     * @return array{output: array<string,mixed>, successful: int}
     */
    private function runTargetedValidationForBatch(
        array $batch,
        array $experiments,
        ModelMarketPerformance $candidate,
        string $symbol,
        array $m15,
        array $h1,
        array $execution,
        string $dataHash,
        string $executionHash,
        array $volumeContext,
        MtfAblationRun $frozenControl,
        MtfStrategyResearchService $research,
        MultiTimeframePilotService $pilot,
        StrategyParameterSchemaService $schemas,
    ): array {
        $experimentByKey = collect($experiments)->keyBy(fn (array $item): string => (string) $item['key']);
        $targetedRows = [];
        $eligible = [];
        $deferred = [];

        foreach ((array) data_get($batch, 'output.rows', []) as $row) {
            $key = (string) ($row['hypothesis_key'] ?? '');
            $experiment = $experimentByKey->get($key);
            $coreRun = MtfStrategyResearchRun::query()->find((int) ($row['research_run_id'] ?? 0));
            if (! $experiment || ! $coreRun || $coreRun->status !== 'completed') {
                $deferred[] = ['hypothesis_key' => $key, 'reason' => 'core_row_not_completed'];
                continue;
            }

            $decision = $this->targetedValidationDecision(
                $coreRun,
                (string) ($experiment['target_gate'] ?? 'unknown'),
                $research,
            );
            if (! ($decision['eligible'] ?? false)) {
                $deferred[] = ['hypothesis_key' => $key, 'reason' => $decision['reason'] ?? 'core_gate_or_target_not_improved'];
                continue;
            }
            $eligible[] = $key;

            $strategy = (string) $experiment['strategy'];
            $baseStrategy = $schemas->runtimeBaseStrategy(
                $strategy,
                null,
                (string) ($experiment['runtime_family'] ?? $experiment['family']),
            );
            $parameters = $research->parametersFor($candidate, $experiment);
            $parameterHash = $pilot->hash([
                'strategy' => $strategy,
                'base_strategy' => $baseStrategy,
                'parameters' => $parameters,
            ]);
            $contract = $research->contract(
                $experiment,
                $symbol,
                (int) $candidate->id,
                $dataHash,
                $parameterHash,
                $executionHash,
                $frozenControl->id,
                $volumeContext,
            );
            $contract['targeted_validation'] = [
                'protocol' => 'xauusd_mtf_targeted_validation_v1',
                'cost_profiles' => ['cost_1_5x', 'cost_2x'],
                'exit_profiles' => ['wider_stop_tighter_target', 'tighter_stop_wider_target'],
                'chronological_holdout' => true,
                'admission' => $decision,
                'promotion_evidence' => false,
            ];
            $baseRunKey = $pilot->hash([
                'research_protocol' => MtfStrategyResearchService::PROTOCOL,
                'candidate_id' => $candidate->id,
                'hypothesis_key' => $experiment['key'],
                'strategy' => $strategy,
                'parameter_hash' => $parameterHash,
                'data_hash' => $dataHash,
                'execution_hash' => $executionHash,
                'frozen_control_run_id' => $frozenControl->id,
                'mtf_contract_hash' => data_get($pilot->requestPayload($symbol, 'M15', $strategy), 'contract_hash'),
                'targeted_validation_protocol' => 'xauusd_mtf_targeted_validation_v1',
            ]);
            $existing = MtfStrategyResearchRun::query()->where('run_key', $baseRunKey)->first();
            $technicalRecoveryOf = null;
            $runKey = $baseRunKey;
            if ($existing?->status === 'technical_error') {
                $technicalRecoveryOf = $existing->id;
                $runKey = $pilot->hash([
                    'base_run_key' => $baseRunKey,
                    'technical_recovery_protocol' => 'mtf_research_technical_retry_v1',
                    'technical_recovery_of_run_id' => $technicalRecoveryOf,
                ]);
                $existing = MtfStrategyResearchRun::query()->where('run_key', $runKey)->first();
            }
            if ($existing) {
                $targetedRows[] = $this->rowFromRun($existing, true);
                continue;
            }

            $payload = [
                'symbol' => $symbol,
                'timeframe' => 'M15',
                'strategy' => $strategy,
                'base_strategy' => $baseStrategy,
                'parameters' => $parameters,
                'initial_balance' => 10000,
                'risk_per_trade' => 1,
                'execution' => $execution['parameters'],
                'execution_contract' => $execution,
                'volume_context' => $volumeContext,
                'mtf_pilot' => $pilot->requestPayload($symbol, 'M15', $strategy),
            ];
            if ($technicalRecoveryOf !== null) {
                $contract['technical_recovery'] = [
                    'protocol' => 'mtf_research_technical_retry_v1',
                    'recovery_of_run_id' => $technicalRecoveryOf,
                    'reason' => 'previous_targeted_validation_was_technical_error',
                    'promotion_evidence' => false,
                ];
            }

            $response = null;
            $transportError = null;
            try {
                $response = Http::timeout((int) config('services.ai_service.backtest_timeout_seconds', 900))
                    ->acceptJson()
                    ->withHeaders(['X-Internal-Token' => (string) config('services.internal_api.token')])
                    ->post(rtrim(config('services.ai_service.url'), '/').'/api/backtest/mtf-targeted-validation', [
                        'base_request' => $payload,
                        'h1_candles' => $h1,
                        'm15_candles' => $m15,
                        'lightweight' => true,
                        'core_result' => (array) data_get($coreRun->result, 'variants.h1_veto_m15_risk', []),
                        'validation' => $this->targetedValidationPayload($m15, $h1),
                    ]);
            } catch (ConnectionException $exception) {
                $transportError = $exception->getMessage();
            }

            if ($response === null || $response->failed()) {
                $run = MtfStrategyResearchRun::create([
                    'model_market_performance_id' => $candidate->id,
                    'pilot_id' => (string) data_get($payload, 'mtf_pilot.pilot_id', config('services.mtf_pilot.pilot_id')),
                    'symbol' => $symbol,
                    'regime_timeframe' => 'H1',
                    'entry_timeframe' => 'M15',
                    'hypothesis_key' => $experiment['key'],
                    'strategy_identity' => $strategy,
                    'strategy_family' => $experiment['family'],
                    'run_key' => $runKey,
                    'data_hash' => $dataHash,
                    'parameter_hash' => $parameterHash,
                    'execution_hash' => $executionHash,
                    'status' => 'technical_error',
                    'failure_class' => 'evidence_recovery',
                    'research_contract' => $contract,
                    'parameters' => $parameters,
                    'result' => [
                        'http_status' => $response?->status(),
                        'body' => substr((string) ($transportError ?? ($response?->body() ?? 'missing targeted validation result')), 0, 1000),
                        'endpoint' => '/api/backtest/mtf-targeted-validation',
                        'core_research_run_id' => $coreRun->id,
                        'frozen_control' => $this->frozenControlPayload($frozenControl),
                        'technical_recovery_of_run_id' => $technicalRecoveryOf,
                        'promotion_evidence' => false,
                    ],
                    'promotion_evidence' => false,
                    'completed_at' => now(),
                ]);
                $targetedRows[] = $this->rowFromRun($run, false);
                continue;
            }

            $result = (array) $response->json();
            $result = [
                'variants' => (array) data_get($coreRun->result, 'variants', []),
                'targeted_validation' => (array) data_get($result, 'targeted_validation', []),
                'targeted_validation_optimization' => (array) data_get($result, 'optimization', []),
                'core_replay_reused' => true,
                'core_research_run_id' => $coreRun->id,
                'research_contract' => $contract,
                'candidate_id' => $candidate->id,
                'frozen_control' => $this->frozenControlPayload($frozenControl),
                'promotion_evidence' => false,
            ];
            $run = MtfStrategyResearchRun::create([
                'model_market_performance_id' => $candidate->id,
                'pilot_id' => (string) data_get($payload, 'mtf_pilot.pilot_id', config('services.mtf_pilot.pilot_id')),
                'symbol' => $symbol,
                'regime_timeframe' => 'H1',
                'entry_timeframe' => 'M15',
                'hypothesis_key' => $experiment['key'],
                'strategy_identity' => $strategy,
                'strategy_family' => $experiment['family'],
                'run_key' => $runKey,
                'data_hash' => $dataHash,
                'parameter_hash' => $parameterHash,
                'execution_hash' => $executionHash,
                'status' => 'completed',
                'research_contract' => $contract,
                'parameters' => $parameters,
                'result' => $result,
                'promotion_evidence' => false,
                'completed_at' => now(),
            ]);
            $targetedRows[] = $this->rowFromRun($run, false);
        }

        $batch['output']['targeted_validation'] = [
            'protocol' => 'xauusd_mtf_targeted_validation_v1',
            'eligible_hypotheses' => $eligible,
            'deferred' => $deferred,
            'rows' => $targetedRows,
            'promotion_evidence' => false,
            'rule' => 'Core screening completes first; only a measurable core gate improvement receives cost/exit/forward diagnostics.',
        ];

        return $batch;
    }

    /** @return array{eligible: bool, reason: string, core_gate: array<string,mixed>} */
    private function targetedValidationDecision(
        MtfStrategyResearchRun $run,
        string $targetGate,
        MtfStrategyResearchService $research,
    ): array
    {
        $core = (array) data_get($run->result, 'variants.h1_veto_m15_risk', []);
        $gate = (array) data_get($core, 'core_replay_gate', []);
        if (! (bool) data_get($gate, 'passed', false)) {
            return ['eligible' => false, 'reason' => 'core_gate_failed', 'core_gate' => $gate];
        }
        $pf = (float) data_get($core, 'profit_factor', 0);
        $dd = (float) data_get($core, 'max_drawdown_percent', 0);
        $control = (array) data_get($run->result, 'frozen_control.official_mtf', []);
        $controlPf = (float) data_get($control, 'profit_factor', 0);
        $controlDd = (float) data_get($control, 'max_drawdown_percent', 0);
        $improved = $research->targetGateImproved($targetGate, $core, $control);
        if (! $improved) {
            return [
                'eligible' => false,
                'reason' => 'target_gate_not_improved_vs_frozen_control',
                'target_gate' => $targetGate,
                'core_gate' => $gate,
                'candidate' => ['profit_factor' => $pf, 'max_drawdown_percent' => $dd],
                'control' => ['profit_factor' => $controlPf, 'max_drawdown_percent' => $controlDd],
            ];
        }

        return [
            'eligible' => true,
            'reason' => 'core_gate_passed_and_target_metric_improved',
            'target_gate' => $targetGate,
            'core_gate' => $gate,
        ];
    }

    /**
     * Build a deterministic chronological holdout with indicator warm-up.
     * The Python evaluator starts its trade loop after 200 M15 rows, so the
     * first 200 rows here are context only and the remaining rows are the
     * research holdout. This is not the sealed project forward gate.
     *
     * @return array<string, mixed>
     */
    private function targetedValidationPayload(array $m15, array $h1): array
    {
        $total = count($m15);
        $holdoutRows = min(1200, max(400, (int) floor($total * 0.20)));
        $warmupRows = min(200, max(0, $total - $holdoutRows - 1));
        $start = max(0, $total - $holdoutRows - $warmupRows);
        $forwardM15 = array_values(array_slice($m15, $start));

        // Keep enough H1 history to classify the entire M15 holdout while
        // preserving the exact closed-H1 merge contract.
        $forwardH1 = array_values(array_slice($h1, max(0, count($h1) - 500)));
        $first = $forwardM15[0] ?? [];
        $last = $forwardM15[array_key_last($forwardM15)] ?? [];

        return [
            'protocol' => 'xauusd_mtf_targeted_validation_v1',
            'cost_profiles' => [
                ['name' => 'cost_1_5x', 'multiplier' => 1.5],
                ['name' => 'cost_2x', 'multiplier' => 2.0],
            ],
            'exit_profiles' => [
                ['name' => 'wider_stop_tighter_target', 'stop_multiplier' => 1.15, 'target_multiplier' => 0.90],
                ['name' => 'tighter_stop_wider_target', 'stop_multiplier' => 0.90, 'target_multiplier' => 1.10],
            ],
            'forward_m15_candles' => $forwardM15,
            'forward_h1_candles' => $forwardH1,
            'warmup_m15_rows' => $warmupRows,
            'holdout_rows' => max(0, count($forwardM15) - $warmupRows),
            'holdout_start' => data_get($first, 'time'),
            'holdout_end' => data_get($last, 'time'),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function rowFromRun(MtfStrategyResearchRun $run, bool $cached): array
    {
        $variants = (array) data_get($run->result, 'variants', []);
        $mtf = (array) ($variants['h1_veto_m15_risk'] ?? []);
        $m15 = (array) ($variants['m15_only'] ?? []);

        return [
            'research_run_id' => $run->id,
            'hypothesis_key' => $run->hypothesis_key,
            'strategy_identity' => $run->strategy_identity,
            'status' => $run->status,
            'cached' => $cached,
            'mtf_trades' => (int) ($mtf['total_trades'] ?? 0),
            'mtf_pf' => (float) ($mtf['profit_factor'] ?? 0),
            'mtf_net_percent' => (float) ($mtf['net_profit_percent'] ?? 0),
            'mtf_dd_percent' => (float) ($mtf['max_drawdown_percent'] ?? 0),
            'm15_pf' => (float) ($m15['profit_factor'] ?? 0),
            'frozen_m15_pf' => (float) data_get($run->result, 'frozen_control.m15_only.profit_factor', 0),
            'veto_count' => (int) data_get($mtf, 'mtf_pilot.veto_count', 0),
            'promotion_evidence' => false,
        ];
    }

    private function completedCoreRun(
        ModelMarketPerformance $candidate,
        string $hypothesisKey,
        string $dataHash,
        string $parameterHash,
        string $executionHash,
    ): ?MtfStrategyResearchRun {
        return MtfStrategyResearchRun::query()
            ->where('model_market_performance_id', $candidate->id)
            ->where('hypothesis_key', $hypothesisKey)
            ->where('data_hash', $dataHash)
            ->where('parameter_hash', $parameterHash)
            ->where('execution_hash', $executionHash)
            ->where('status', 'completed')
            ->whereNull('failure_class')
            ->latest('completed_at')
            ->first();
    }

    /** @return array<string, mixed>|null */
    private function frozenControlPayload(?MtfAblationRun $run): ?array
    {
        if (! $run) return null;

        return [
            'protocol' => 'frozen_m15_control_v1',
            'run_id' => $run->id,
            'candidate_id' => $run->model_market_performance_id,
            'data_hash' => $run->data_hash,
            'execution_hash' => $run->execution_hash,
            'm15_only' => (array) data_get($run->variants, 'm15_only', []),
            'official_mtf' => (array) data_get($run->variants, 'h1_veto_m15_risk', []),
            'promotion_evidence' => false,
        ];
    }
}
