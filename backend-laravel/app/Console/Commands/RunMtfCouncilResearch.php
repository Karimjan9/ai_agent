<?php

namespace App\Console\Commands;

use App\Models\ModelMarketPerformance;
use App\Models\MtfAblationRun;
use App\Models\MtfStrategyResearchRun;
use App\Services\ExecutionContractService;
use App\Services\MarketData\CandlePayloadService;
use App\Services\MarketData\MarketVolumeService;
use App\Services\MtfStrategyResearchService;
use App\Services\MtfResearchSnapshotService;
use App\Services\MultiTimeframePilotService;
use App\Services\MtfCouncilGateService;
use App\Services\MtfShadowCouncilSandboxService;
use App\Services\CouncilAblationService;
use App\Services\LabQueueJobInspector;
use App\Services\LearningProtocolSafetyService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Run the four role-complete XAUUSD MTF council seats on one sealed snapshot.
 *
 * This is deliberately separate from ordinary strategy research: a council
 * seat owns a declared regime/volatility cell and is first observed alone,
 * then in the fixed-route combined council. A second pass applies exactly
 * one declared mutation per seat. Nothing here creates a paper passport.
 */
class RunMtfCouncilResearch extends Command
{
    private ?string $lastCouncilTransportError = null;

    protected $signature = 'trading:mtf-council-research
        {--candidate= : Valid XAUUSD M15 near-miss candidate}
        {--symbol=XAUUSD : Lighthouse symbol}
        {--bootstrap-control : Create the exact frozen four-lane control when needed}
        {--control-run= : Reuse the exact immutable snapshot from an existing MTF ablation run id}
        {--no-mutate : Run only the sealed council hypotheses, without the one-gene mutation pass}
        {--allow-busy-queue : Explicitly override the lab-screening queue idle guard}
        {--json : Print the immutable council evidence as JSON}';

    protected $description = 'Run role-complete XAUUSD H1/M15 council specialists and one-gene mutations';

    public function handle(
        CandlePayloadService $candles,
        MtfStrategyResearchService $research,
        MultiTimeframePilotService $pilot,
        StrategyParameterSchemaService $schemas,
        MarketVolumeService $volumes,
        MtfResearchSnapshotService $snapshots,
        MtfCouncilGateService $councilGates,
        MtfShadowCouncilSandboxService $shadowSandbox,
        CouncilAblationService $councilAblations,
        LabQueueJobInspector $queueState,
    ): int {
        $symbol = strtoupper(str_replace(['/', '_', '-'], '', (string) $this->option('symbol')));
        if ($symbol !== LearningProtocolSafetyService::LIGHTHOUSE_SYMBOL) {
            $this->error('Council research faqat XAUUSD H1 lighthouse uchun ruxsat etiladi; boshqa lablar shadow/research rejimida qoladi.');

            return self::FAILURE;
        }
        if (! $this->option('allow-busy-queue')) {
            $queueSnapshot = $queueState->queueSnapshot(['lab-screening']);
            if (($queueSnapshot['available'] ?? true) === false) {
                $this->warn('Council queue state unavailable; research refused fail-closed.');
                return self::FAILURE;
            }
            $busyJobs = (int) data_get($queueSnapshot, 'stats.lab-screening.pending', 0)
                + (int) data_get($queueSnapshot, 'stats.lab-screening.reserved', 0);
            if ($busyJobs > 0) {
                $this->warn("Council replay queue guard: {$busyJobs} lab-screening job(s) are pending or reserved; council was not started.");
                $this->line('Run again after the queue is idle, or explicitly pass --allow-busy-queue after operator review.');
                return self::FAILURE;
            }
        }
        $candidate = $this->candidate($symbol);
        if (! $candidate || ! $candidate->modelVersion) {
            $this->error("{$symbol} M15 uchun valid research candidate topilmadi.");
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
                $this->error('Ko\'rsatilgan council control run immutable snapshotga ega emas yoki snapshot integrity tekshiruvidan o\'tmadi.');
                return self::FAILURE;
            }
        }

        $m15 = $snapshot
            ? array_values((array) ($snapshot['m15_candles'] ?? []))
            : $candles->candlesForBacktest($symbol, 'M15', 5000, true);
        $h1 = $snapshot
            ? array_values((array) ($snapshot['h1_candles'] ?? []))
            : $candles->candlesForBacktest($symbol, 'H1', 2000, true);
        if (count($m15) < 200 || count($h1) < 200) {
            $this->error('Council research uchun mustaqil M15 va H1 candle stream yetarli emas.');
            return self::FAILURE;
        }

        $execution = app(ExecutionContractService::class)->for($symbol, 'M15');
        $volumeContext = $snapshot
            ? (array) ($snapshot['volume_context'] ?? [])
            : $volumes->mtfContext($symbol);
        $dataHash = $pilot->hash([
            'symbol' => $symbol,
            'h1_count' => count($h1),
            'm15_count' => count($m15),
            'h1_first' => data_get($h1[0] ?? [], 'time'),
            'h1_last' => data_get($h1[array_key_last($h1)] ?? [], 'time'),
            'm15_first' => data_get($m15[0] ?? [], 'time'),
            'm15_last' => data_get($m15[array_key_last($m15)] ?? [], 'time'),
            'volume_context_hash' => $pilot->hash($volumeContext),
        ]);
        $executionHash = (string) data_get($execution, 'execution_hash', '');
        if ($snapshotRun && ((string) $snapshotRun->data_hash !== $dataHash || (string) $snapshotRun->execution_hash !== $executionHash)) {
            $this->error('Council control snapshot hashlari qayta hisoblangan contract bilan mos emas; replay fail-closed qilindi.');
            return self::FAILURE;
        }
        $control = $snapshotRun ?: $this->frozenControl($candidate, $symbol, $dataHash, $executionHash);
        if (! $control && $this->option('bootstrap-control')) {
            $control = $this->bootstrapControl($candidate, $symbol, $m15, $h1, $dataHash, $executionHash, $volumeContext, $pilot, $schemas, $snapshots);
        }
        if (! $control) {
            $this->error('Council uchun ayni data/execution hashda frozen control topilmadi; avval --bootstrap-control bilan ishga tushiring.');
            return self::FAILURE;
        }

        $experiments = $research->councilCatalog();
        $passes = ['baseline' => ['mutated' => false, 'volume' => false]];
        if (! $this->option('no-mutate')) {
            $passes['one_gene_mutation'] = ['mutated' => true, 'volume' => false];
            if ($research->volumeResearchFreshness($volumeContext)['ready'] ?? false) {
                $passes['volume_context_mutation'] = ['mutated' => false, 'volume' => true];
            }
        }
        $passResults = [];
        $memberRows = [];
        $compositeRows = [];

        foreach ($passes as $pass => $passConfig) {
            $mutated = (bool) ($passConfig['mutated'] ?? false);
            $volumeMutation = (bool) ($passConfig['volume'] ?? false);
            $specs = $this->memberSpecs($experiments, $candidate, $research, $mutated, $volumeMutation, $pass);
            $parameterHash = $pilot->hash([
                'protocol' => 'xauusd_mtf_council_research_v1',
                'pass' => $pass,
                'members' => $specs,
            ]);
            $baseCompositeKey = $pilot->hash([
                'protocol' => 'xauusd_mtf_council_research_v1',
                'candidate_id' => $candidate->id,
                'pass' => $pass,
                'data_hash' => $dataHash,
                'execution_hash' => $executionHash,
                'parameter_hash' => $parameterHash,
                'control_run_id' => $control->id,
            ]);
            $compositeKey = $baseCompositeKey;
            $existingComposite = MtfStrategyResearchRun::query()->where('run_key', $compositeKey)->first();
            $technicalRecoveryOf = null;
            if ($existingComposite?->status === 'technical_error') {
                $technicalRecoveryOf = $existingComposite->id;
                $compositeKey = $pilot->hash([
                    'base_composite_key' => $baseCompositeKey,
                    'technical_recovery_protocol' => 'mtf_council_technical_retry_v1',
                    'technical_recovery_of_run_id' => $technicalRecoveryOf,
                ]);
                $existingComposite = MtfStrategyResearchRun::query()->where('run_key', $compositeKey)->first();
            }
            if ($existingComposite) {
                $passResults[$pass] = (array) $existingComposite->result;
                $compositeRows[] = $this->compositeRow($existingComposite, $passResults[$pass], $councilGates);
                continue;
            }

            $response = $this->callCouncil($symbol, $execution, $pilot, $specs, $h1, $m15, $volumeContext);
            if ($response === null) {
                $contract = [
                    'protocol' => 'xauusd_mtf_council_research_v1',
                    'council_protocol' => 'role_complete_council_v1',
                    'symbol' => $symbol,
                    'regime_timeframe' => 'H1',
                    'entry_timeframe' => 'M15',
                    'frozen_candidate_id' => $candidate->id,
                    'frozen_control_run_id' => $control->id,
                    'data_hash' => $dataHash,
                    'execution_hash' => $executionHash,
                    'parameter_hash' => $parameterHash,
                    'pass' => $pass,
                    'members' => $specs,
                    'same_data_contract' => true,
                    'same_execution_contract' => true,
                    'promotion_evidence' => false,
                ];
                if ($technicalRecoveryOf !== null) {
                    $contract['technical_recovery'] = [
                        'protocol' => 'mtf_council_technical_retry_v1',
                        'recovery_of_run_id' => $technicalRecoveryOf,
                        'promotion_evidence' => false,
                    ];
                }
                MtfStrategyResearchRun::firstOrCreate(
                    ['run_key' => $compositeKey],
                    [
                        'model_market_performance_id' => $candidate->id,
                        'pilot_id' => (string) data_get($pilot->requestPayload($symbol, 'M15'), 'pilot_id'),
                        'symbol' => $symbol,
                        'regime_timeframe' => 'H1',
                        'entry_timeframe' => 'M15',
                        'hypothesis_key' => 'council_composite_v1@'.$pass,
                        'strategy_identity' => 'portfolio_v1',
                        'strategy_family' => 'council',
                        'run_key' => $compositeKey,
                        'data_hash' => $dataHash,
                        'parameter_hash' => $parameterHash,
                        'execution_hash' => $executionHash,
                        'status' => 'technical_error',
                        'failure_class' => 'evidence_recovery',
                        'research_contract' => $contract,
                        'parameters' => ['members' => $specs],
                        'result' => [
                            'endpoint' => '/api/backtest/mtf-council',
                            'error' => $this->lastCouncilTransportError ?? 'missing council response',
                            'technical_recovery_of_run_id' => $technicalRecoveryOf,
                            'promotion_evidence' => false,
                        ],
                        'promotion_evidence' => false,
                        'completed_at' => now(),
                    ],
                );
                $this->error("Council {$pass} AI replay transport xatosi; technical_error evidence saqlandi.");
                return self::FAILURE;
            }
            $passResults[$pass] = $response;

            foreach ($experiments as $experiment) {
                $memberKey = (string) data_get(
                    collect($specs)->first(fn (array $spec): bool => $spec['role'] === $experiment['council_role']),
                    'member_key',
                );
                $member = (array) data_get($response, "member_results.{$memberKey}", []);
                if ($member === []) continue;
                $parameters = $this->parametersForPass($candidate, $research, $experiment, $mutated, $volumeMutation);
                $memberParameterHash = $pilot->hash([
                    'strategy' => $experiment['strategy'],
                    'parameters' => $parameters,
                    'pass' => $pass,
                ]);
                $contract = $research->contract(
                    $experiment,
                    $symbol,
                    (int) $candidate->id,
                    $dataHash,
                    $memberParameterHash,
                    $executionHash,
                    $control->id,
                    $volumeContext,
                );
                $contract['council_protocol'] = 'role_complete_council_v1';
                $contract['council_role'] = $experiment['council_role'];
                $contract['target_regime'] = $experiment['target_regime'];
                $contract['target_volatility'] = $experiment['target_volatility'];
                $contract['mutation'] = (array) ($experiment['mutation'] ?? []);
                $contract['mutation_applied'] = $mutated;
                $contract['volume_mutation_applied'] = $volumeMutation;
                $memberRunKey = $pilot->hash([
                    'composite_run_key' => $compositeKey,
                    'member_key' => $memberKey,
                    'member_parameter_hash' => $memberParameterHash,
                ]);
                $memberResult = [
                    'variants' => [
                        'h1_veto_m15_risk' => (array) data_get($member, 'official_mtf', []),
                    ],
                    'member' => $member,
                    'council_pass' => $pass,
                    'promotion_evidence' => false,
                ];
                $memberRun = MtfStrategyResearchRun::firstOrCreate(
                    ['run_key' => $memberRunKey],
                    [
                        'model_market_performance_id' => $candidate->id,
                        'pilot_id' => (string) data_get($pilot->requestPayload($symbol, 'M15'), 'pilot_id'),
                        'symbol' => $symbol,
                        'regime_timeframe' => 'H1',
                        'entry_timeframe' => 'M15',
                        'hypothesis_key' => $experiment['key'].'@'.$pass,
                        'strategy_identity' => $experiment['strategy'],
                        'strategy_family' => $experiment['family'],
                        'run_key' => $memberRunKey,
                        'data_hash' => $dataHash,
                        'parameter_hash' => $memberParameterHash,
                        'execution_hash' => $executionHash,
                        'status' => 'completed',
                        'research_contract' => $contract,
                        'parameters' => $parameters,
                        'result' => $memberResult,
                        'promotion_evidence' => false,
                        'completed_at' => now(),
                    ],
                );
                $memberRows[] = $this->memberRow($memberRun, $experiment, $pass, $memberResult);
            }

            $compositeResult = [
                'protocol' => 'xauusd_mtf_council_screen_v1',
                'variants' => [
                    'm15_only' => (array) data_get($control->variants, 'm15_only', []),
                    'h1_veto_m15_risk' => (array) data_get($control->variants, 'h1_veto_m15_risk', []),
                ],
                'council' => (array) data_get($response, 'council', []),
                'member_results' => (array) data_get($response, 'member_results', []),
                'declared_members' => $specs,
                'council_pass' => $pass,
                'promotion_evidence' => false,
            ];
            $compositeResult['combined_gate'] = $councilGates->evaluate(
                (array) data_get($compositeResult, 'council', []),
                (array) data_get($control->variants, 'h1_veto_m15_risk', []),
                $specs,
            );
            // The combined row is explicitly a shadow sandbox until every
            // specialist has its own independent passport. This metadata is
            // immutable research context; it cannot open paper promotion.
            $compositeResult['shadow_sandbox'] = $shadowSandbox->contract($specs, [
                'pass' => $pass,
                'data_hash' => $dataHash,
                'execution_hash' => $executionHash,
                'control_run_id' => $control->id,
            ]);
            $compositeResult['council_ablation'] = $councilAblations->plan($specs, [
                'symbol' => $symbol,
                'timeframe' => 'H1',
                'data_hash' => $dataHash,
                'snapshot_hash' => $dataHash,
                'execution_hash' => $executionHash,
                'pass' => $pass,
                'control_run_id' => $control->id,
            ]);
            $contract = [
                'protocol' => 'xauusd_mtf_council_research_v1',
                'council_protocol' => 'role_complete_council_v1',
                'symbol' => $symbol,
                'regime_timeframe' => 'H1',
                'entry_timeframe' => 'M15',
                'frozen_candidate_id' => $candidate->id,
                'frozen_control_run_id' => $control->id,
                'data_hash' => $dataHash,
                'execution_hash' => $executionHash,
                'volume_context' => $volumeContext,
                'parameter_hash' => $parameterHash,
                'pass' => $pass,
                'volume_mutation' => $volumeMutation,
                'members' => $specs,
                'same_data_contract' => true,
                'same_execution_contract' => true,
                'genetic_parent_transfer' => false,
                'promotion_evidence' => false,
                'rule' => 'Every seat is observed alone before combined fixed-route council replay; no member or council may self-promote.',
            ];
            $composite = MtfStrategyResearchRun::firstOrCreate(
                ['run_key' => $compositeKey],
                [
                    'model_market_performance_id' => $candidate->id,
                    'pilot_id' => (string) data_get($pilot->requestPayload($symbol, 'M15'), 'pilot_id'),
                    'symbol' => $symbol,
                    'regime_timeframe' => 'H1',
                    'entry_timeframe' => 'M15',
                    'hypothesis_key' => 'council_composite_v1@'.$pass,
                    'strategy_identity' => 'portfolio_v1',
                    'strategy_family' => 'council',
                    'run_key' => $compositeKey,
                    'data_hash' => $dataHash,
                    'parameter_hash' => $parameterHash,
                    'execution_hash' => $executionHash,
                    'status' => 'completed',
                    'research_contract' => $contract,
                    'parameters' => ['members' => $specs],
                    'result' => $compositeResult,
                    'promotion_evidence' => false,
                    'completed_at' => now(),
                ],
            );
            $compositeRows[] = $this->compositeRow($composite, $compositeResult, $councilGates);
        }

        $output = [
            'protocol' => 'xauusd_mtf_council_research_v1',
            'symbol' => $symbol,
            'candidate_id' => $candidate->id,
            'data_hash' => $dataHash,
            'execution_hash' => $executionHash,
            'frozen_control' => $this->controlPayload($control),
            'reference_lane' => (array) data_get($control->variants, 'h1_veto_m15_risk', []),
            // Compatibility alias for existing consumers; this is not an
            // official champion or promotion decision.
            'champion_lane' => (array) data_get($control->variants, 'h1_veto_m15_risk', []),
            'passes' => array_keys($passes),
            'member_rows' => $memberRows,
            'composite_rows' => $compositeRows,
            'promotion_evidence' => false,
        ];
        if ($this->option('json')) {
            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("{$symbol} role-complete MTF council research (research-only)");
            $this->table(['Pass', 'Council PF', 'Council Net %', 'Council DD %', 'Trades'], array_map(
                fn (array $row): array => [$row['pass'], $row['council_pf'], $row['council_net_percent'], $row['council_dd_percent'], $row['council_trades']],
                $compositeRows,
            ));
            $this->comment('Champion va council immutable shadow evidence sifatida saqlandi; paper/promotion avtomatik emas.');
        }

        return self::SUCCESS;
    }

    private function candidate(string $symbol): ?ModelMarketPerformance
    {
        $query = ModelMarketPerformance::with('modelVersion')
            ->where('symbol', $symbol)
            ->where('timeframe', 'M15')
            ->where('evidence_status', 'valid')
            ->whereHas('modelVersion', fn ($builder) => $builder->where('evidence_status', 'valid'))
            ->whereIn('status', ['forward_validated', 'paper', 'rejected'])
            ->latest('id');
        if (filled($this->option('candidate'))) $query->whereKey((int) $this->option('candidate'));
        return $query->first();
    }

    private function frozenControl(ModelMarketPerformance $candidate, string $symbol, string $dataHash, string $executionHash): ?MtfAblationRun
    {
        return MtfAblationRun::query()
            ->where('model_market_performance_id', $candidate->id)
            ->where('symbol', $symbol)
            ->where('data_hash', $dataHash)
            ->where('execution_hash', $executionHash)
            ->where('status', 'completed')
            ->latest('completed_at')
            ->first();
    }

    private function bootstrapControl(
        ModelMarketPerformance $candidate,
        string $symbol,
        array $m15,
        array $h1,
        string $dataHash,
        string $executionHash,
        array $volumeContext,
        MultiTimeframePilotService $pilot,
        StrategyParameterSchemaService $schemas,
        MtfResearchSnapshotService $snapshots,
    ): ?MtfAblationRun {
        $model = $candidate->modelVersion;
        $pilotPayload = $pilot->requestPayload($symbol, 'M15', $model->strategy);
        $execution = app(ExecutionContractService::class)->for($symbol, 'M15');
        $runKey = $pilot->hash([
            'candidate_id' => $candidate->id,
            'pilot_id' => data_get($pilotPayload, 'pilot_id'),
            'data_hash' => $dataHash,
            'execution_hash' => $executionHash,
            'contract_hash' => data_get($pilotPayload, 'contract_hash'),
        ]);
        $existing = MtfAblationRun::query()->where('run_key', $runKey)->first();
        if ($existing) return $existing;
        $payload = [
            'symbol' => $symbol,
            'timeframe' => 'M15',
            'strategy' => $model->strategy,
            'base_strategy' => $schemas->runtimeBaseStrategy($model->strategy, data_get($model->metadata, 'base_strategy'), $candidate->strategy_family),
            'parameters' => [...((array) ($model->parameters ?? [])), 'volume_lane' => 'none'],
            'initial_balance' => 10000,
            'risk_per_trade' => 1,
            'execution' => $execution['parameters'],
            'execution_contract' => $execution,
            'volume_context' => $volumeContext,
            'mtf_pilot' => $pilotPayload,
        ];
        $response = Http::timeout((int) config('services.ai_service.backtest_timeout_seconds', 900))
            ->acceptJson()
            ->withHeaders(['X-Internal-Token' => (string) config('services.internal_api.token')])
            ->post(rtrim(config('services.ai_service.url'), '/').'/api/backtest/mtf-ablation', [
                'base_request' => $payload,
                'h1_candles' => $h1,
                'm15_candles' => $m15,
                'lightweight' => true,
            ]);
        if ($response->failed()) return null;
        $snapshotReference = $snapshots->store(
            $runKey,
            $symbol,
            $h1,
            $m15,
            $volumeContext,
            $execution['parameters'],
            $dataHash,
            $executionHash,
            $pilotPayload,
        );
        return MtfAblationRun::firstOrCreate(
            ['run_key' => $runKey],
            [
                'model_market_performance_id' => $candidate->id,
                'pilot_id' => (string) data_get($pilotPayload, 'pilot_id'),
                'symbol' => $symbol,
                'regime_timeframe' => 'H1',
                'entry_timeframe' => 'M15',
                'run_key' => $runKey,
                'data_hash' => $dataHash,
                'execution_hash' => $executionHash,
                'status' => 'completed',
                'variants' => (array) data_get($response->json(), 'variants', []),
                'snapshot_reference' => $snapshotReference,
                'promotion_evidence' => false,
                'completed_at' => now(),
            ],
        );
    }

    /** @return list<array<string, mixed>> */
    private function memberSpecs(
        array $experiments,
        ModelMarketPerformance $candidate,
        MtfStrategyResearchService $research,
        bool $mutated,
        bool $volumeMutation,
        string $pass,
    ): array
    {
        return array_map(function (array $experiment) use ($candidate, $research, $mutated, $volumeMutation, $pass): array {
            $parameters = $this->parametersForPass($candidate, $research, $experiment, $mutated, $volumeMutation);
            $version = $volumeMutation ? 'council-volume-v1' : ($mutated ? 'council-mutation-v1' : 'council-v1');
            return [
                'strategy' => $experiment['strategy'],
                'base_strategy' => $experiment['strategy'],
                'version' => $version,
                'parameters' => $parameters,
                'member_key' => 'council:'.(string) $experiment['council_role'].':'.$pass,
                'role' => $experiment['council_role'],
                'target_regime' => $experiment['target_regime'],
                'target_volatility' => $experiment['target_volatility'],
                'target_direction' => null,
            ];
        }, $experiments);
    }

    private function parametersForPass(
        ModelMarketPerformance $candidate,
        MtfStrategyResearchService $research,
        array $experiment,
        bool $mutated,
        bool $volumeMutation,
    ): array
    {
        $parameters = $research->parametersFor($candidate, $experiment);
        $mutation = (array) ($experiment['mutation'] ?? []);
        if ($mutated && filled($mutation['parameter'] ?? null) && array_key_exists('to', $mutation)) {
            $parameters[(string) $mutation['parameter']] = $mutation['to'];
        }
        if ($volumeMutation) {
            $parameters['volume_lane'] = (string) ($experiment['volume_lane'] ?? 'none');
        }
        return $parameters;
    }

    private function callCouncil(
        string $symbol,
        array $execution,
        MultiTimeframePilotService $pilot,
        array $specs,
        array $h1,
        array $m15,
        array $volumeContext,
    ): ?array
    {
        $this->lastCouncilTransportError = null;
        $base = [
            'symbol' => $symbol,
            'timeframe' => 'M15',
            'strategy' => 'portfolio_v1',
            'base_strategy' => 'portfolio',
            'version' => 'xauusd-mtf-council-v1',
            // Pydantic distinguishes an empty JSON object from an empty
            // array; portfolio-level policy has no scalar genes here.
            'parameters' => (object) [],
            'portfolio_members' => $specs,
            'initial_balance' => 10000,
            'risk_per_trade' => 1,
            'execution' => $execution['parameters'],
            'execution_contract' => $execution,
            'volume_context' => $volumeContext,
            'mtf_pilot' => $pilot->requestPayload($symbol, 'M15', 'portfolio_v1'),
        ];
        try {
            $response = Http::timeout((int) config('services.ai_service.backtest_timeout_seconds', 900))
                ->acceptJson()
                ->withHeaders(['X-Internal-Token' => (string) config('services.internal_api.token')])
                ->post(rtrim(config('services.ai_service.url'), '/').'/api/backtest/mtf-council', [
                    'base_request' => $base,
                    'h1_candles' => $h1,
                    'm15_candles' => $m15,
                    'lightweight' => true,
                ]);
        } catch (ConnectionException $exception) {
            $this->lastCouncilTransportError = $exception->getMessage();
            $this->error('AI council transport timeout: '.substr($exception->getMessage(), 0, 1000));
            return null;
        }
        if ($response->failed()) {
            $this->lastCouncilTransportError = 'http_'.$response->status().': '.substr((string) $response->body(), 0, 1000);
            $this->error('AI council response '.$response->status().': '.substr((string) $response->body(), 0, 1500));
            return null;
        }
        return (array) $response->json();
    }

    /** @return array<string, mixed> */
    private function memberRow(MtfStrategyResearchRun $run, array $experiment, string $pass, array $result): array
    {
        $lane = (array) data_get($result, 'variants.h1_veto_m15_risk', []);
        return [
            'research_run_id' => $run->id,
            'role' => $experiment['council_role'],
            'pass' => $pass,
            'hypothesis_key' => $experiment['key'],
            'pf' => (float) data_get($lane, 'profit_factor', 0),
            'net_percent' => (float) data_get($lane, 'net_profit_percent', 0),
            'dd_percent' => (float) data_get($lane, 'max_drawdown_percent', 0),
            'trades' => (int) data_get($lane, 'total_trades', 0),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function compositeRow(MtfStrategyResearchRun $run, array $result, MtfCouncilGateService $councilGates): array
    {
        $council = (array) data_get($result, 'council', []);
        $gate = (array) data_get($result, 'combined_gate', []);
        if ($gate === []) {
            $gate = $councilGates->evaluate(
                $council,
                (array) data_get($result, 'variants.h1_veto_m15_risk', []),
                (array) data_get($result, 'declared_members', []),
            );
        }
        return [
            'research_run_id' => $run->id,
            'pass' => (string) data_get($result, 'council_pass', 'unknown'),
            'hypothesis_key' => $run->hypothesis_key,
            'council_pf' => (float) data_get($council, 'profit_factor', 0),
            'council_net_percent' => (float) data_get($council, 'net_profit_percent', 0),
            'council_dd_percent' => (float) data_get($council, 'max_drawdown_percent', 0),
            'council_trades' => (int) data_get($council, 'total_trades', 0),
            'combined_gate' => $gate,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function controlPayload(MtfAblationRun $control): array
    {
        return [
            'run_id' => $control->id,
            'data_hash' => $control->data_hash,
            'execution_hash' => $control->execution_hash,
            'm15_only' => (array) data_get($control->variants, 'm15_only', []),
            'h1_veto_m15_risk' => (array) data_get($control->variants, 'h1_veto_m15_risk', []),
            'promotion_evidence' => false,
        ];
    }
}
