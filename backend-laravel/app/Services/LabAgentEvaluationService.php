<?php
namespace App\Services;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\StrategyScore;
use App\Models\TrainingSession;
use App\Services\MarketData\CandlePayloadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;
use RuntimeException;
class LabAgentEvaluationService
{
    public function __construct(private CandlePayloadService $candles,private MarketChampionService $champions,private LabDatasetExportService $datasets,private ScreeningLearningOutboxService $screeningOutbox, private CandidateGateDecisionService $gateDecisions, private ShadowVetoLedgerService $shadowVetoLedger, private CandidateHandoffService $handoffs, private CounterfactualBlameGraphService $blameGraph, private LearningProtocolSafetyService $protocolSafety, private LabImmutableEvidenceService $evidence, private StrategyParameterSchemaService $schemas){}
    public function evaluate(LabAgent $agent, ?LabEvaluationRun $run = null):void
    {
        $run ??= $this->evidence->beginRun($agent, 'full_validation', 'full', ['source' => 'direct_evaluation']);
        $agent->load('modelVersion','generation');$model=$agent->modelVersion;
        $rawResponse = null;
        $cacheHit = false;
        $cached=data_get($model->metadata,'full_validation_batch');
        if((int)data_get($cached,'generation_id')===(int)$agent->lab_generation_id && is_array(data_get($cached,'item'))){
            $item=$cached['item'];
            $cacheHit = true;
        }else{
            // Evaluate the selected generation cohort together.  This gives CSCV
            // and DSR a real candidate distribution instead of a meaningless
            // one-strategy batch.  The first serialized job caches each peer's
            // result; following jobs persist their own cached result without
            // repeating the expensive Python replay.
            $cohort=LabAgent::query()->with('modelVersion')->where('lab_generation_id',$agent->lab_generation_id)
                ->whereIn('lifecycle_status',['full_queued','training'])->orderBy('id')->get();
            if($cohort->isEmpty())$cohort=collect([$agent]);
            // Portfolio members are independent sealed hypotheses. Replaying
            // several of them in one Python cohort multiplies the worst-case
            // runtime and can turn useful full evidence into a transport
            // timeout. The later combined portfolio replay remains the place
            // where complementary members are evaluated together.
            if (data_get($model->metadata, 'portfolio_research_contract.protocol') === 'portfolio_member_research_v1') {
                $cohort = collect([$agent]);
            }
            // A full replay must never inherit a stale archive from an older
            // generation. Export the immutable canonical snapshot at dispatch
            // time; the first serialized cohort job then caches that exact
            // result for every peer in the batch.
            $dataset=$this->datasets->export($agent->symbol,$agent->timeframe);
            $manifest = is_file($dataset.'.manifest.json') ? json_decode(File::get($dataset.'.manifest.json'), true) : [];
            $request=[
                'symbol'=>$agent->symbol,'timeframe'=>$agent->timeframe,'strategy'=>'all','evaluation_mode'=>'replay',
                'strategies'=>$cohort->map(fn(LabAgent $peer)=>['strategy'=>$peer->modelVersion->strategy,'base_strategy'=>$this->schemas->runtimeBaseStrategy($peer->modelVersion->strategy, data_get($peer->modelVersion->metadata,'base_strategy'), $peer->strategy_family),'version'=>$peer->modelVersion->version,'parameters'=>$peer->modelVersion->parameters??[]])->all(),
                'initial_balance'=>10000,'risk_per_trade'=>1,'dataset_path'=>$dataset,
                'execution'=>$this->executionAssumptions($agent->symbol),
                'emit_decision_trace' => true,
            ];
            // A council seat is not only a label on the model version.  Its
            // standalone passport must be replayed inside the sealed niche
            // it owns, otherwise a trend-up child can borrow range/trend-down
            // outcomes before the combined council replay.  The singleton
            // member route uses the same deterministic router as the later
            // portfolio replay; it is still subject to every normal full,
            // forward and paper gate and never creates promotion evidence by
            // itself.
            $researchMembers = $cohort->filter(fn (LabAgent $peer): bool =>
                data_get($peer->modelVersion->metadata, 'portfolio_research_contract.protocol') === 'portfolio_member_research_v1'
            );
            if ($researchMembers->isNotEmpty()) {
                $request['portfolio_members'] = $researchMembers->map(fn (LabAgent $peer): array => [
                    'strategy' => $peer->modelVersion->strategy,
                    'base_strategy' => $this->schemas->runtimeBaseStrategy($peer->modelVersion->strategy, data_get($peer->modelVersion->metadata, 'base_strategy'), $peer->strategy_family),
                    'version' => $peer->modelVersion->version,
                    'parameters' => $peer->modelVersion->parameters ?? [],
                    'member_key' => 'lab_agent:'.$peer->id,
                    'role' => data_get($peer->modelVersion->metadata, 'portfolio_council_lane.role', 'specialist'),
                    'target_regime' => $this->normalizeCouncilTarget(data_get($peer->modelVersion->metadata, 'portfolio_research_contract.target_regime'), ['trend_up', 'trend_down', 'range']),
                    'target_volatility' => $this->normalizeCouncilTarget(data_get($peer->modelVersion->metadata, 'portfolio_research_contract.target_volatility'), ['high_volatility', 'normal_volatility', 'low_volatility']),
                    'target_direction' => $this->normalizeCouncilTarget(data_get($peer->modelVersion->metadata, 'portfolio_research_contract.target_direction'), ['BUY', 'SELL']),
                ])->values()->all();
            }
            // M15 entries must use only a completed H1 market regime. The
            // Python engine delays that state by one H1 bar before merging.
            if(strtoupper($agent->timeframe)==='M15'){
                $regimeDataset=$this->datasets->export($agent->symbol,'H1');
                $request['regime_dataset_path']=$regimeDataset;
            }
            $timeout = min(2280, max(60, (int) config('services.lab_selection.full_replay_timeout_seconds', 2280)));
            $requestId = 'full-'.$agent->id.'-'.bin2hex(random_bytes(6));
            $this->evidence->attachRequest($run, $request, ['request_id' => $requestId, 'dataset_manifest' => $manifest]);
            $this->assertAiReplayHealthy($requestId, $run);
            $response=Http::connectTimeout(15)->timeout($timeout)->withOptions([
                // Keep the transport limit explicit for Windows/cURL too;
                // Http::timeout() maps to this option, but the duplicate
                // declaration makes the bounded contract visible in traces.
                'connect_timeout' => 15,
                'timeout' => $timeout,
                // Guzzle's high-level timeout is not consistently enforced by
                // the Windows cURL handler when a synchronous AI replay is
                // abandoned. Pin the native millisecond limits as well.
                'curl' => [
                    CURLOPT_CONNECTTIMEOUT => 15,
                    CURLOPT_CONNECTTIMEOUT_MS => 15000,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_TIMEOUT_MS => $timeout * 1000,
                ],
            ])->acceptJson()->withHeaders([
                'X-Internal-Token'=>(string)config('services.internal_api.token'),
                'X-Lab-Request-Id' => $requestId,
            ])->post(rtrim(config('services.ai_service.url'),'/').'/api/backtest/run-all',$request);
            if($response->failed())throw new RuntimeException($response->body());
            $rawResponse = $response->json();
            $items=collect($rawResponse['leaderboard'] ?? [])->keyBy('strategy');
            foreach($cohort as $peer){$peerItem=$items->get($peer->modelVersion->strategy);if(!$peerItem)throw new RuntimeException('Missing cohort lab agent result.');$peerItem['result']['data_manifest']=$manifest;$items->put($peer->modelVersion->strategy,$peerItem);$peerModel=$peer->modelVersion;$peerModel->update(['metadata'=>array_merge($peerModel->metadata??[],['full_validation_batch'=>['generation_id'=>$agent->lab_generation_id,'item'=>$peerItem]])]);}
            $item=$items->get($model->strategy);if(!$item)throw new RuntimeException('Empty lab agent result.');
        }
        DB::transaction(function()use($agent,$model,$item,$run){$fullResult=$item['result']??[];$fullResult['evidence_run_id']=$run->run_id;$result=$this->evidence->projectionPayload($fullResult);$session=TrainingSession::create([
            'title'=>"{$agent->symbol} Lab G{$agent->generation->generation} {$model->name}",'symbol'=>$agent->symbol,'timeframe'=>$agent->timeframe,
            'agents_count'=>1,'best_strategy'=>$model->strategy,'best_score'=>$item['score'],'worst_strategy'=>$model->strategy,'worst_score'=>$item['score'],
            'total_trades'=>$result['total_trades']??0,'average_winrate'=>$result['winrate']??0,'average_profit'=>$result['net_profit_percent']??0,
            'average_drawdown'=>$result['max_drawdown_percent']??$result['max_drawdown']??0,
            'average_profit_factor'=>$result['profit_factor']??0,
            'average_stability_score'=>$result['stability_score']??0,
            'status'=>'completed','started_at'=>now(),'finished_at'=>now(),'raw_leaderboard'=>[$this->evidence->projectionPayload($item)]]);
            StrategyScore::create(['training_session_id'=>$session->id,'symbol'=>$agent->symbol,'timeframe'=>$agent->timeframe,'strategy'=>$model->strategy,
                'parameters'=>$model->parameters??[],'score'=>$item['score'],'train_score'=>$item['train_score']??0,'validation_score'=>$item['validation_score']??0,
                'forward_score'=>$item['forward_score']??0,'robustness_score'=>$item['robustness_score']??0,'is_overfit'=>$item['is_overfit']??false,
                'mc_worst_drawdown_percent'=>data_get($result,'monte_carlo.worst_drawdown_percent'),'mc_risk_of_ruin_percent'=>data_get($result,'monte_carlo.risk_of_ruin_percent'),
                'total_trades'=>$result['total_trades']??0,'wins'=>$result['wins']??0,'losses'=>$result['losses']??0,'winrate'=>$result['winrate']??0,
                'net_profit_percent'=>$result['net_profit_percent']??0,'max_drawdown_percent'=>$result['max_drawdown_percent']??0,'profit_factor'=>$result['profit_factor']??0,
                'stability_score'=>$result['stability_score']??0,'equity_curve'=>$result['equity_curve']??[],'regime_performance'=>$result['regime_performance']??[],
                'volatility_performance'=>$result['volatility_performance']??[],'raw_result'=>$result]);
            $result['forward_score']=$item['forward_score']??0;$result['forward_window_scores']=$item['forward_window_scores']??[];$result['rolling_windows_count']=$item['rolling_windows_count']??0;
            $result['train_score']=$item['train_score']??0;$result['validation_score']=$item['validation_score']??0;$result['is_overfit']=$item['is_overfit']??false;
            $model->update(['best_score'=>max((float)$model->best_score,(float)$item['score']),'best_winrate'=>$result['winrate']??0,'best_profit'=>$result['net_profit_percent']??0,'best_drawdown'=>$result['max_drawdown_percent']??0,'metadata'=>array_merge($model->metadata??[],['last_result'=>$result])]);
            $this->shadowVetoLedger->record($agent, $result, 'full_replay');
            // Preserve the sealed niche contract on the full-replay result.
            // It is used only by the separate portfolio-member gate; it must
            // never be interpreted as standalone forward evidence.
            if ($portfolioContract = data_get($model->metadata, 'portfolio_research_contract')) {
                $result['portfolio_research_contract'] = $portfolioContract;
            }
            // Pass the sealed model instance, not only its runtime strategy
            // label. Multiple generations can use the same family/label;
            // evidence must remain attributed to this exact lab agent.
            $performance = $this->champions->evaluate($model->strategy,$agent->symbol,$agent->timeframe,(int)$item['score'],$result,$model);
            if ((int) $performance->model_version_id !== (int) $model->getKey()) {
                throw new RuntimeException('Full replay evidence attribution mismatch.');
            }
            $this->blameGraph->sync($performance, $agent, $result);
            $this->handoffs->record($agent->generation, $agent, 'full_validation_completed', 'completed', null, [
                'performance_id' => $performance->id, 'result_hash' => hash('sha256', json_encode($result)),
                'evidence_run_id' => $run->run_id,
            ]);
        });
        $generation=$agent->generation()->with('agents')->first();
        if($generation->agents->whereIn('lifecycle_status',['draft','queued','training','full_queued'])->isEmpty()){
            $generation->update(['status'=>'completed','completed_at'=>now()]);
            $generation = $generation->fresh(['agents']);
            app(LabGenerationReportService::class)->record($generation, 'full_completed');
            if ($generation->agents->whereIn('lifecycle_status', ['forward_validated', 'paper', 'champion'])->isEmpty()) {
                $this->handoffs->noForwardCandidate($generation);
            }
        }
        // A cohort response is a separate immutable artifact. The run itself
        // must be attributed to this exact agent's result so its response
        // hash, trace and ledger completeness are not diluted by peer rows.
        if ($rawResponse !== null) {
            $this->evidence->recordArtifact($run, 'cohort_response', (array) $rawResponse, [
                'cohort_result_count' => is_array($rawResponse['leaderboard'] ?? null) ? count($rawResponse['leaderboard']) : 0,
                'source' => 'full_validation_cohort',
            ]);
        }
        $evidenceResponse = $item['result'] ?? [];
        $this->evidence->finishRun($run, 'completed', $evidenceResponse, [
            'agent_result' => $item['result'] ?? [],
            'cache_hit' => $cacheHit,
            'cohort_result_count' => is_array($rawResponse['leaderboard'] ?? null) ? count($rawResponse['leaderboard']) : 1,
        ], ['cache_hit' => $cacheHit, 'cohort_generation_id' => $agent->lab_generation_id]);
    }

    /** Fast, pair-local filter. Promotion never happens from this result. */
    public function screen(LabAgent $agent, ?LabEvaluationRun $run = null): void
    {
        $run ??= $this->evidence->beginRun($agent, 'screening', 'incremental', ['source' => 'direct_screen']);
        $agent->load('modelVersion', 'generation');
        $model = $agent->modelVersion;
        $rows = $this->candles->candlesForBacktest($agent->symbol, $agent->timeframe, 5000);
        if (count($rows) < 500) {
            throw new RuntimeException('Screening uchun yetarli recent candle topilmadi.');
        }

        $request = [
            'symbol' => $agent->symbol, 'timeframe' => $agent->timeframe,
            'strategy' => $model->strategy, 'evaluation_mode' => 'incremental',
            'strategies' => [[
                'strategy' => $model->strategy,
                'base_strategy' => $this->schemas->runtimeBaseStrategy($model->strategy, data_get($model->metadata, 'base_strategy'), $agent->strategy_family),
                'version' => $model->version, 'parameters' => $model->parameters ?? [],
            ]],
            'initial_balance' => 10000, 'risk_per_trade' => 1, 'candles' => $rows,
            // Screening must rank candidates after the same normal execution
            // costs as full replay; otherwise cheap-turnover strategies are
            // incorrectly promoted into the scarce full-validation cohort.
            'execution' => $this->executionAssumptions($agent->symbol),
            'emit_decision_trace' => true,
        ];
        if (strtoupper($agent->timeframe) === 'M15') {
            // Screen only needs the recent H1 context; full replay uses the
            // audited H1 CSV above.
            $request['regime_candles'] = $this->candles->candlesForBacktest($agent->symbol, 'H1', 2000);
        }
        // The HTTP budget must end before the job/worker budget.  A timeout
        // becomes evaluation_error, never a retry-derived strategy verdict.
        $isDifferential = $agent->strategy_family === 'differential_router'
            || data_get($model->metadata, 'differential_router_contract') !== null
            || str_contains((string) data_get($model->metadata, 'base_strategy', ''), 'differential_router');
        $configuredScreenTimeout = $isDifferential
            ? (int) config('services.lab_selection.differential_screen_timeout_seconds', 900)
            : (int) config('services.lab_selection.screen_timeout_seconds', 300);
        // Differential screening contains four paired ledgers. Keep its
        // longer transport budget explicit; ordinary screening remains capped
        // at five minutes so a provider hang cannot stall a market queue.
        // The bounded Python worker keeps a 30-second margin below this
        // transport deadline: ordinary screening may use up to 330 seconds,
        // differential screening up to 840 seconds.
        $screenTimeout = min($isDifferential ? 900 : 360, max(30, $configuredScreenTimeout));
        $requestId = 'screen-'.$agent->id.'-'.bin2hex(random_bytes(6));
        $manifest = ['candle_count' => count($rows), 'data_hash' => $this->evidence->hash($rows)];
        $this->evidence->attachRequest($run, $request, ['request_id' => $requestId, 'data_hash' => $manifest['data_hash'], 'dataset_manifest' => $manifest]);
        $this->assertAiReplayHealthy($requestId, $run);
        $response = Http::connectTimeout(15)->timeout($screenTimeout)->withOptions([
            // Explicitly bound the cURL transfer on Windows as well as in
            // Laravel's PendingRequest abstraction. A provider/replay hang
            // must become an operational error, never an unbounded queue
            // lease or a strategy verdict.
            'connect_timeout' => 15,
            'timeout' => $screenTimeout,
            'curl' => [
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT_MS => 15000,
                CURLOPT_TIMEOUT => $screenTimeout,
                CURLOPT_TIMEOUT_MS => $screenTimeout * 1000,
            ],
        ])->acceptJson()->withHeaders([
            'X-Internal-Token'=>(string)config('services.internal_api.token'),
            'X-Lab-Request-Id' => $requestId,
        ])->post(rtrim(config('services.ai_service.url'), '/').'/api/backtest/run-all', $request);
        if ($response->failed()) throw new RuntimeException($response->body());
        $item = data_get($response->json(), 'leaderboard.0');
        if (! $item) throw new RuntimeException('Empty screening result.');
        $result = $item['result'] ?? [];
        $screenResult = array_merge($result, [
            'forward_score' => $item['forward_score'] ?? $item['score'] ?? 0,
            'train_score' => $item['train_score'] ?? $item['score'] ?? 0,
            'validation_score' => $item['validation_score'] ?? $item['score'] ?? 0,
            'evidence_run_id' => $run->run_id,
        ]);
        $screenResult['execution_contract'] = [
            'version' => LearningProtocolSafetyService::EXECUTION_CONTRACT,
            'execution_hash' => hash('sha256', json_encode($request['execution'], JSON_PRESERVE_ZERO_FRACTION)),
        ];
        $screenResult = $this->appendDifferentialNoRegressionEvidence($model, $screenResult);
        // Keep the complete result for the immutable response artifact, but
        // expose only a bounded projection to gate/learning selectors.
        $screenProjection = $this->evidence->projectionPayload($screenResult);
        // Coverage rescue is a preservation experiment. A non-target identity
        // mismatch invalidates the experiment itself, not the parent edge;
        // quarantine it before any quality gate or mutation learner sees it.
        if (data_get($model->metadata, 'coverage_rescue_contract.protocol') === CoverageRescueAuditService::PROTOCOL
            && data_get($screenResult, 'differential_no_regression.status') !== 'passed') {
            $model->update(['metadata' => array_merge($model->metadata ?? [], ['last_screen_result' => $screenProjection])]);
            $agent->update(['lifecycle_status' => 'technical_quarantine', 'decision_reason' => 'Coverage-rescue differential invariant breached; child quarantined without strategy-quality verdict.']);
            $this->evidence->recordLifecycle($agent, 'coverage_rescue_invariant_quarantine', [
                'reason_code' => 'COVERAGE_RESCUE_NON_TARGET_IDENTITY_BREACH', 'quality_verdict' => 'withheld',
                'differential_no_regression' => data_get($screenResult, 'differential_no_regression'),
            ], 'screening', $run->run_id, $run->attempt, self::class);
            $this->evidence->finishRun($run, 'completed', $screenResult, [], ['technical_quarantine' => true]);
            return;
        }
        // The fact/gate path is primary. Mutation learning runs through an
        // outbox after it, so a schema or learning-write fault cannot strand
        // the candidate in `screening` or erase its evidence.
        $this->shadowVetoLedger->record($agent, $screenProjection, 'screening');
        $screenDecision = $this->gateDecisions->recordScreening($agent, $screenProjection);
        $model->update(['metadata' => array_merge($model->metadata ?? [], [
            'last_screen_result' => $screenProjection,
            'execution_contract' => LearningProtocolSafetyService::EXECUTION_CONTRACT,
        ])]);
        $agent->update([
            'lifecycle_status' => 'screened',
            'train_score' => $item['train_score'] ?? $item['score'] ?? 0,
            'validation_score' => $item['validation_score'] ?? $item['score'] ?? 0,
            'forward_score' => $item['forward_score'] ?? $item['score'] ?? 0,
            'sample_count' => $result['total_trades'] ?? 0,
            'profit_factor' => $result['profit_factor'] ?? 0,
            'max_drawdown' => $result['max_drawdown_percent'] ?? $result['max_drawdown'] ?? 0,
            'risk_of_ruin' => data_get($result, 'monte_carlo.risk_of_ruin_percent'),
            'decision_reason' => data_get($model->metadata, 'causal_rescue_contract.kind') === 'loss_cooldown_single_gene'
                ? ($screenDecision->decision === 'passed'
                    ? 'Cooldown causal rescue passed its strict screen contract; awaiting global full-validation selection.'
                    : 'Cooldown causal rescue rejected by its strict screen contract; no promotion path opened.')
                : 'Incremental screening completed; awaiting global full-validation selection.',
        ]);

        $this->handoffs->record($agent->generation, $agent, 'screened', 'completed', null, [
            'screen_result_hash' => hash('sha256', json_encode($screenProjection)), 'sample_count' => $agent->sample_count,
            'evidence_run_id' => $run->run_id,
        ]);
        $this->screeningOutbox->enqueue($agent->fresh(), $screenProjection, (float) ($item['forward_score'] ?? $item['score'] ?? 0));

        $generation = $agent->generation()->with('agents')->first();
        // An evaluator/transport failure is not a screen verdict. Keep the
        // generation open until the failed agent is recovered or explicitly
        // quarantined; otherwise the last successful peer could close an
        // incomplete generation as if every candidate had evidence.
        if ($generation->agents->whereIn('lifecycle_status', ['draft', 'queued', 'screening', 'evaluation_error'])->isEmpty()) {
            $generation->update(['status' => 'screened']);
            app(LabGenerationReportService::class)->record($generation->fresh(), 'screening_completed');
        }
        $this->evidence->finishRun($run, 'completed', $screenResult, [
            'screen_decision' => $screenDecision->decision,
            'total_trades' => $result['total_trades'] ?? 0,
            'profit_factor' => $result['profit_factor'] ?? 0,
            'stress_profit_factor' => data_get($result, 'screening_survival.stress_cost_pf'),
        ], ['screen_decision_id' => $screenDecision->id]);
    }

    /** Diagnostic replay is a learning-only re-evaluation; it never creates a full-replay or paper candidate. */
    public function diagnosticReplay(LabAgent $agent): void
    {
        $this->screen($agent);
        $agent->refresh()->load('modelVersion');
        $result = (array) data_get($agent->modelVersion?->metadata, 'last_screen_result', []);
        $this->gateDecisions->recordDiagnosticReplay($agent, $result);
        $agent->update(['decision_reason' => 'Diagnostic rescue replay completed; excluded from promotion evidence.']);
    }

    /**
     * Fail fast when the single Python replay lane is unavailable or already
     * owned by another caller. This is operational containment only; it never
     * writes a gate decision and is recovered through the bounded evaluator
     * recovery command after a clean service restart.
     */
    private function assertAiReplayHealthy(string $requestId, ?LabEvaluationRun $run = null): void
    {
        try {
            $response = Http::connectTimeout(3)->timeout(5)->withOptions([
                'connect_timeout' => 3,
                'timeout' => 5,
                'curl' => [
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_CONNECTTIMEOUT_MS => 3000,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_TIMEOUT_MS => 5000,
                ],
            ])->acceptJson()->withHeaders([
                'X-Internal-Token' => (string) config('services.internal_api.token'),
                'X-Lab-Request-Id' => $requestId.'-preflight',
            ])->get(rtrim(config('services.ai_service.url'), '/').'/api/replay-status');
        } catch (\Throwable $error) {
            if ($run) $this->evidence->recordLifecycle($run->agent, 'ai_health_preflight_error', [
                'request_id' => $requestId, 'request_type' => 'GET /api/replay-status',
            ], $run->phase, $run->run_id, $run->attempt, self::class, $error);
            throw new RuntimeException('AI replay health preflight failed: '.$error->getMessage(), 0, $error);
        }

        if ($run) $this->evidence->recordArtifact($run, 'ai_health_preflight', [
            'request_id' => $requestId, 'http_status' => $response->status(), 'body' => $response->json(),
        ], ['request_type' => 'GET /api/replay-status', 'promotion_evidence' => false]);

        if ($response->failed()) {
            if ($run) $this->evidence->recordLifecycle($run->agent, 'ai_health_preflight_failed', [
                'request_id' => $requestId, 'http_status' => $response->status(),
            ], $run->phase, $run->run_id, $run->attempt, self::class);
            throw new RuntimeException('AI replay health preflight returned HTTP '.$response->status().'.');
        }
        $status = $response->json();
        if (! is_array($status) || data_get($status, 'protocol') !== 'replay_liveness_v2_bounded_worker') {
            if ($run) $this->evidence->recordLifecycle($run->agent, 'ai_health_protocol_error', [
                'request_id' => $requestId, 'protocol' => data_get($status, 'protocol'),
            ], $run->phase, $run->run_id, $run->attempt, self::class);
            throw new RuntimeException('AI replay health preflight returned an unknown liveness protocol.');
        }
        if ((int) data_get($status, 'active_requests', 0) > 0) {
            if ($run) $this->evidence->recordLifecycle($run->agent, 'ai_health_lane_busy', [
                'request_id' => $requestId, 'active_requests' => data_get($status, 'active_requests'),
            ], $run->phase, $run->run_id, $run->attempt, self::class);
            throw new RuntimeException('AI replay lane is busy; strategy verdict withheld for bounded retry.');
        }
    }

    private function executionAssumptions(string $symbol): array
    {
        return [
            'spread_points' => str_starts_with($symbol, 'XAU') ? 20 : 12,
            'point_size' => str_starts_with($symbol, 'XAU') ? 0.01 : 0.00001,
            'commission_percent' => 0.01, 'slippage_points' => 2,
            'swap_per_day_percent' => 0.002, 'allowed_sessions_utc' => ['1-22'],
            'intrabar_policy' => 'conservative', 'max_gap_multiple' => 96,
            // LabDatasetExportService is the canonical full-history gate.
            'reject_unexpected_gaps' => false, 'stop_loss_percent' => 0.5,
            'take_profit_percent' => 1.0, 'max_leverage' => 5,
        ];
    }

    /** Convert Laravel's open-ended research marker (`any`) to Python null. */
    private function normalizeCouncilTarget(mixed $value, array $allowed): ?string
    {
        $candidate = trim((string) $value);
        foreach ($allowed as $option) {
            if (strcasecmp($candidate, (string) $option) === 0) return (string) $option;
        }
        return null;
    }

    /** A differential child may improve only its declared target lane. */
    private function appendDifferentialNoRegressionEvidence($model, array $result): array
    {
        $contract = (array) data_get($model->metadata, 'differential_router_contract', []);
        $router = (array) data_get($result, 'differential_router', []);
        // A paired non-target contract belongs only to an explicitly
        // differential-router experiment.  Previously an empty contract fell
        // through to the default trend_down target and every ordinary
        // regime_ensemble/hybrid/breakout candidate was falsely rejected with
        // FAILED_NON_TARGET_REGRESSION.
        $isDifferential = $contract !== []
            || data_get($router, 'enabled') === true
            || str_contains((string) data_get($model->metadata, 'base_strategy', ''), 'differential_router');
        if (! $isDifferential) return $result;
        $targetRegime = (string) data_get($contract, 'target_regime', data_get($result, 'differential_router.target_regime', 'trend_down'));
        if (! in_array($targetRegime, ['trend_up', 'range', 'trend_down'], true)) return $result;
        $parent = \App\Models\ModelVersion::find((int) data_get($contract, 'parent_model_version_id'));
        $parentResult = (array) data_get($parent?->metadata, 'last_screen_result', []);
        $paired = (array) data_get($router, 'paired_lane', []);
        $parentRegimes = (array) data_get($parentResult, 'regime_performance', []);
        $childRegimes = (array) data_get($result, 'regime_performance', []);
        $hasPairedLane = data_get($paired, 'protocol') === LearningProtocolSafetyService::EXECUTION_CONTRACT;
        $parentNonTargetTrades = $hasPairedLane
            ? (int) data_get($paired, 'parent_non_target.trades', -1)
            : collect($parentRegimes)->reject(fn ($row, $regime) => $regime === $targetRegime)->sum(fn ($row) => (int) data_get($row, 'trades', 0));
        $childNonTargetTrades = $hasPairedLane
            ? (int) data_get($paired, 'child_non_target.trades', -1)
            : (int) data_get($router, 'non_target_trade_count', -1);
        $parentNonTargetNet = $hasPairedLane
            ? (float) data_get($paired, 'parent_non_target.net_profit_percent', 0)
            : collect($parentRegimes)->reject(fn ($row, $regime) => $regime === $targetRegime)->sum(fn ($row) => (float) data_get($row, 'profit_percent', 0));
        $childNonTargetNet = $hasPairedLane
            ? (float) data_get($paired, 'child_non_target.net_profit_percent', 0)
            : collect($childRegimes)->reject(fn ($row, $regime) => $regime === $targetRegime)->sum(fn ($row) => (float) data_get($row, 'profit_percent', 0));
        $parentFrozenHash = (string) data_get($contract, 'parent_frozen_hash');
        $parentHashMatches = $parentResult !== [] && hash_equals($parentFrozenHash, hash('sha256', json_encode($parentResult, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)));
        $sameExecutionContract = (string) data_get($contract, 'execution_contract') === LearningProtocolSafetyService::EXECUTION_CONTRACT
            && (string) data_get($result, 'execution_contract.version') === LearningProtocolSafetyService::EXECUTION_CONTRACT;
        $entryIdentity = data_get($paired, 'non_target_entry_times_identity') === true;
        $status = $hasPairedLane
            && $parentHashMatches && $sameExecutionContract
            && data_get($paired, 'status') === 'passed'
            && data_get($paired, 'non_target_signal_identity') === true
            && data_get($paired, 'non_target_confidence_identity') === true
            && data_get($paired, 'non_target_ledger_identity') === true
            && $entryIdentity ? 'passed' : 'failed';
        $result['differential_no_regression'] = [
            'status' => $status, 'target_regime' => $targetRegime,
            'protocol' => LearningProtocolSafetyService::EXECUTION_CONTRACT,
            'non_target_signal_identity' => (bool) data_get($paired, 'non_target_signal_identity', data_get($router, 'non_target_signal_identity', false)),
            'non_target_confidence_identity' => (bool) data_get($paired, 'non_target_confidence_identity', data_get($router, 'non_target_confidence_identity', false)),
            'non_target_ledger_identity' => (bool) data_get($paired, 'non_target_ledger_identity', false),
            'non_target_entry_times_identity' => $entryIdentity,
            'parent_frozen_hash_matches' => $parentHashMatches,
            'execution_contract_matches' => $sameExecutionContract,
            'branch_hashes' => data_get($paired, 'non_target_branch_hashes', []),
            'parent_non_target_trade_count' => $parentNonTargetTrades, 'child_non_target_trade_count' => $childNonTargetTrades,
            'parent_non_target_net_profit_percent' => round($parentNonTargetNet, 5), 'child_non_target_net_profit_percent' => round($childNonTargetNet, 5),
            'portfolio_interaction_delta_net_profit_percent' => data_get($paired, 'portfolio_interaction_delta_net_profit_percent'),
            'target_delta_net_profit_percent' => data_get($paired, 'target_delta_net_profit_percent'),
            'epsilon' => .01, 'promotion_evidence' => false,
        ];
        return $result;
    }
}
