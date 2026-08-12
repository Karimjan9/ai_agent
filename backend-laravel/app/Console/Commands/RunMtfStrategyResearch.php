<?php

namespace App\Console\Commands;

use App\Models\ModelMarketPerformance;
use App\Models\MtfAblationRun;
use App\Models\MtfStrategyResearchRun;
use App\Services\ExecutionContractService;
use App\Services\MarketData\CandlePayloadService;
use App\Services\MtfStrategyResearchService;
use App\Services\MultiTimeframePilotService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class RunMtfStrategyResearch extends Command
{
    protected $signature = 'trading:mtf-strategy-research
        {--candidate= : Valid XAUUSD M15 near-miss candidate; defaults to the newest valid candidate}
        {--symbol=XAUUSD : Lighthouse symbol}
        {--hypothesis= : Run one catalog hypothesis by key}
        {--hypotheses= : Run comma-separated catalog hypotheses on one immutable candle snapshot}
        {--bootstrap-control : Create the exact frozen four-lane control when this snapshot has no matching control}
        {--validate-forward : Add bounded cost/exit stress and chronological forward diagnostics to each immutable row}
        {--limit=4 : Maximum number of bounded hypotheses, up to twelve}
        {--json : Print the immutable research rows as JSON}';

    protected $description = 'Run bounded XAUUSD H1/M15 strategy hypotheses under the sealed four-lane contract';

    public function handle(
        CandlePayloadService $candles,
        MtfStrategyResearchService $research,
        MultiTimeframePilotService $pilot,
        StrategyParameterSchemaService $schemas,
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
            $experiments = $research->select(
                $this->option('hypothesis') ? (string) $this->option('hypothesis') : null,
                max(1, (int) $this->option('limit')),
            );
        }
        if ($experiments === []) {
            $this->error('So‘ralgan MTF hypothesis catalog’da topilmadi.');
            return self::FAILURE;
        }

        $m15 = $candles->candlesForBacktest($symbol, 'M15', 5000);
        $h1 = $candles->candlesForBacktest($symbol, 'H1', 2000);
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
        ]);
        $executionHash = (string) data_get($execution, 'execution_hash', '');
        $frozenControl = MtfAblationRun::query()
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
                    'parameters' => $model->parameters ?? [], 'initial_balance' => 10000, 'risk_per_trade' => 1,
                    'execution' => $controlExecution['parameters'], 'execution_contract' => $controlExecution,
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
                $frozenControl = MtfAblationRun::firstOrCreate(
                    ['run_key' => $controlRunKey],
                    [
                        'model_market_performance_id' => $candidate->id,
                        'pilot_id' => (string) data_get($controlPilot, 'pilot_id', config('services.mtf_pilot.pilot_id')),
                        'symbol' => $symbol, 'regime_timeframe' => 'H1', 'entry_timeframe' => 'M15',
                        'run_key' => $controlRunKey, 'data_hash' => $dataHash, 'execution_hash' => $executionHash,
                        'status' => 'completed', 'variants' => (array) ($controlResult['variants'] ?? []),
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
        $rows = [];
        $successful = 0;

        foreach ($experiments as $experiment) {
            $strategy = (string) $experiment['strategy'];
            $baseStrategy = $schemas->runtimeBaseStrategy($strategy, null, (string) $experiment['family']);
            $parameters = $research->parametersFor($candidate, $experiment);
            $parameterHash = $pilot->hash([
                'strategy' => $strategy,
                'base_strategy' => $baseStrategy,
                'parameters' => $parameters,
            ]);
            $contract = $research->contract($experiment, $symbol, (int) $candidate->id, $dataHash, $parameterHash, $executionHash, $frozenControl?->id);
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

            $requestBody = [
                'base_request' => $payload,
                'h1_candles' => $h1,
                'm15_candles' => $m15,
                'lightweight' => true,
            ];
            if ($this->option('validate-forward')) {
                $requestBody['validation'] = $this->targetedValidationPayload($m15, $h1);
            }

            $response = Http::timeout((int) config('services.ai_service.backtest_timeout_seconds', 900))
                ->acceptJson()
                ->withHeaders(['X-Internal-Token' => (string) config('services.internal_api.token')])
                ->post(rtrim(config('services.ai_service.url'), '/').'/api/backtest/mtf-ablation', [
                    ...$requestBody,
                ]);

            if ($response->failed()) {
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
                        'http_status' => $response->status(),
                        'body' => substr((string) $response->body(), 0, 1000),
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
