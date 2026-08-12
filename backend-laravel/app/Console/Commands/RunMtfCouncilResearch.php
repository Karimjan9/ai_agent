<?php

namespace App\Console\Commands;

use App\Models\ModelMarketPerformance;
use App\Models\MtfAblationRun;
use App\Models\MtfStrategyResearchRun;
use App\Services\ExecutionContractService;
use App\Services\MarketData\CandlePayloadService;
use App\Services\MtfStrategyResearchService;
use App\Services\MultiTimeframePilotService;
use App\Services\LearningProtocolSafetyService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

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
    protected $signature = 'trading:mtf-council-research
        {--candidate= : Valid XAUUSD M15 near-miss candidate}
        {--symbol=XAUUSD : Lighthouse symbol}
        {--bootstrap-control : Create the exact frozen four-lane control when needed}
        {--no-mutate : Run only the sealed council hypotheses, without the one-gene mutation pass}
        {--json : Print the immutable council evidence as JSON}';

    protected $description = 'Run role-complete XAUUSD H1/M15 council specialists and one-gene mutations';

    public function handle(
        CandlePayloadService $candles,
        MtfStrategyResearchService $research,
        MultiTimeframePilotService $pilot,
        StrategyParameterSchemaService $schemas,
    ): int {
        $symbol = strtoupper(str_replace(['/', '_', '-'], '', (string) $this->option('symbol')));
        if ($symbol !== LearningProtocolSafetyService::LIGHTHOUSE_SYMBOL) {
            $this->error('Council research faqat XAUUSD H1 lighthouse uchun ruxsat etiladi; boshqa lablar shadow/research rejimida qoladi.');

            return self::FAILURE;
        }
        $candidate = $this->candidate($symbol);
        if (! $candidate || ! $candidate->modelVersion) {
            $this->error("{$symbol} M15 uchun valid research candidate topilmadi.");
            return self::FAILURE;
        }

        $m15 = $candles->candlesForBacktest($symbol, 'M15', 5000);
        $h1 = $candles->candlesForBacktest($symbol, 'H1', 2000);
        if (count($m15) < 200 || count($h1) < 200) {
            $this->error('Council research uchun mustaqil M15 va H1 candle stream yetarli emas.');
            return self::FAILURE;
        }

        $execution = app(ExecutionContractService::class)->for($symbol, 'M15');
        $dataHash = $pilot->hash([
            'symbol' => $symbol,
            'h1_count' => count($h1),
            'm15_count' => count($m15),
            'h1_first' => data_get($h1[0] ?? [], 'time'),
            'h1_last' => data_get($h1[array_key_last($h1)] ?? [], 'time'),
            'm15_first' => data_get($m15[0] ?? [], 'time'),
            'm15_last' => data_get($m15[array_key_last($m15)] ?? [], 'time'),
        ]);
        $executionHash = (string) data_get($execution, 'execution_hash', '');
        $control = $this->frozenControl($candidate, $symbol, $dataHash, $executionHash);
        if (! $control && $this->option('bootstrap-control')) {
            $control = $this->bootstrapControl($candidate, $symbol, $m15, $h1, $dataHash, $executionHash, $pilot, $schemas);
        }
        if (! $control) {
            $this->error('Council uchun ayni data/execution hashda frozen control topilmadi; avval --bootstrap-control bilan ishga tushiring.');
            return self::FAILURE;
        }

        $experiments = $research->councilCatalog();
        $passes = ['baseline' => false];
        if (! $this->option('no-mutate')) $passes['one_gene_mutation'] = true;
        $passResults = [];
        $memberRows = [];
        $compositeRows = [];

        foreach ($passes as $pass => $mutated) {
            $specs = $this->memberSpecs($experiments, $candidate, $research, $mutated);
            $parameterHash = $pilot->hash([
                'protocol' => 'xauusd_mtf_council_research_v1',
                'pass' => $pass,
                'members' => $specs,
            ]);
            $compositeKey = $pilot->hash([
                'protocol' => 'xauusd_mtf_council_research_v1',
                'candidate_id' => $candidate->id,
                'pass' => $pass,
                'data_hash' => $dataHash,
                'execution_hash' => $executionHash,
                'parameter_hash' => $parameterHash,
                'control_run_id' => $control->id,
            ]);
            $existingComposite = MtfStrategyResearchRun::query()->where('run_key', $compositeKey)->first();
            if ($existingComposite) {
                $passResults[$pass] = (array) $existingComposite->result;
                $compositeRows[] = $this->compositeRow($existingComposite, $passResults[$pass]);
                continue;
            }

            $response = $this->callCouncil($symbol, $execution, $pilot, $specs, $h1, $m15);
            if ($response === null) {
                $this->error("Council {$pass} AI replay transport xatosi; evidence yozilmadi.");
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
                $parameters = $this->parametersForPass($candidate, $research, $experiment, $mutated);
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
                );
                $contract['council_protocol'] = 'role_complete_council_v1';
                $contract['council_role'] = $experiment['council_role'];
                $contract['target_regime'] = $experiment['target_regime'];
                $contract['target_volatility'] = $experiment['target_volatility'];
                $contract['mutation'] = (array) ($experiment['mutation'] ?? []);
                $contract['mutation_applied'] = $mutated;
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
            $compositeRows[] = $this->compositeRow($composite, $compositeResult);
        }

        $output = [
            'protocol' => 'xauusd_mtf_council_research_v1',
            'symbol' => $symbol,
            'candidate_id' => $candidate->id,
            'data_hash' => $dataHash,
            'execution_hash' => $executionHash,
            'frozen_control' => $this->controlPayload($control),
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
        MultiTimeframePilotService $pilot,
        StrategyParameterSchemaService $schemas,
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
            'parameters' => $model->parameters ?? [],
            'initial_balance' => 10000,
            'risk_per_trade' => 1,
            'execution' => $execution['parameters'],
            'execution_contract' => $execution,
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
                'promotion_evidence' => false,
                'completed_at' => now(),
            ],
        );
    }

    /** @return list<array<string, mixed>> */
    private function memberSpecs(array $experiments, ModelMarketPerformance $candidate, MtfStrategyResearchService $research, bool $mutated): array
    {
        return array_map(function (array $experiment) use ($candidate, $research, $mutated): array {
            $parameters = $this->parametersForPass($candidate, $research, $experiment, $mutated);
            $version = $mutated ? 'council-mutation-v1' : 'council-v1';
            return [
                'strategy' => $experiment['strategy'],
                'base_strategy' => $experiment['strategy'],
                'version' => $version,
                'parameters' => $parameters,
                'member_key' => 'council:'.(string) $experiment['council_role'].':'.($mutated ? 'mutation' : 'baseline'),
                'role' => $experiment['council_role'],
                'target_regime' => $experiment['target_regime'],
                'target_volatility' => $experiment['target_volatility'],
                'target_direction' => null,
            ];
        }, $experiments);
    }

    private function parametersForPass(ModelMarketPerformance $candidate, MtfStrategyResearchService $research, array $experiment, bool $mutated): array
    {
        $parameters = $research->parametersFor($candidate, $experiment);
        $mutation = (array) ($experiment['mutation'] ?? []);
        if ($mutated && filled($mutation['parameter'] ?? null) && array_key_exists('to', $mutation)) {
            $parameters[(string) $mutation['parameter']] = $mutation['to'];
        }
        return $parameters;
    }

    private function callCouncil(string $symbol, array $execution, MultiTimeframePilotService $pilot, array $specs, array $h1, array $m15): ?array
    {
        $base = [
            'symbol' => $symbol,
            'timeframe' => 'M15',
            'strategy' => 'portfolio_v1',
            'base_strategy' => 'portfolio',
            'version' => 'xauusd-mtf-council-v1',
            'parameters' => [],
            'portfolio_members' => $specs,
            'initial_balance' => 10000,
            'risk_per_trade' => 1,
            'execution' => $execution['parameters'],
            'execution_contract' => $execution,
            'mtf_pilot' => $pilot->requestPayload($symbol, 'M15', 'portfolio_v1'),
        ];
        $response = Http::timeout((int) config('services.ai_service.backtest_timeout_seconds', 900))
            ->acceptJson()
            ->withHeaders(['X-Internal-Token' => (string) config('services.internal_api.token')])
            ->post(rtrim(config('services.ai_service.url'), '/').'/api/backtest/mtf-council', [
                'base_request' => $base,
                'h1_candles' => $h1,
                'm15_candles' => $m15,
                'lightweight' => true,
            ]);
        return $response->failed() ? null : (array) $response->json();
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
    private function compositeRow(MtfStrategyResearchRun $run, array $result): array
    {
        $council = (array) data_get($result, 'council', []);
        return [
            'research_run_id' => $run->id,
            'pass' => (string) data_get($result, 'council_pass', 'unknown'),
            'hypothesis_key' => $run->hypothesis_key,
            'council_pf' => (float) data_get($council, 'profit_factor', 0),
            'council_net_percent' => (float) data_get($council, 'net_profit_percent', 0),
            'council_dd_percent' => (float) data_get($council, 'max_drawdown_percent', 0),
            'council_trades' => (int) data_get($council, 'total_trades', 0),
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
