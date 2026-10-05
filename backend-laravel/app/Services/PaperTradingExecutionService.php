<?php

namespace App\Services;

use App\Models\AgentMemory;
use App\Models\Candle;
use App\Models\EliteAgentPortfolio;
use App\Models\ModelMarketPerformance;
use App\Models\PaperOrder;
use App\Models\PaperSignal;
use App\Models\PaperSignalOutcome;
use App\Models\Symbol;
use App\Models\SpecialistCouncilVersion;
use App\Services\MarketData\CandlePayloadService;
use App\Services\MarketData\MarketReadinessService;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class PaperTradingExecutionService
{
    public function __construct(
        private CandlePayloadService $candles,
        private MarketChampionService $champions,
        private PhaseTwoFoundationService $foundation,
        private MarketReadinessService $marketReadiness,
        private TradingRiskService $risk,
        private SpecialistPortfolioAllocator $allocator,
        private EliteAgentPortfolioGateService $portfolios,
        private PaperConfidenceCalibrationService $calibration,
        private EconomicCalendarService $calendar,
        private MarketSessionCalendarService $marketSessions,
        private CandidateGateDecisionService $gateDecisions,
        private PaperExecutionStateMachineService $executionState,
        private StrategyParameterSchemaService $schemas,
        private RuntimeEnsemblePolicyService $runtimeEnsembles,
        private MultiTimeframePilotService $mtfPilot,
        private PaperMtfLedgerService $mtfLedger,
        private ChampionCouncilCanaryRouterService $canaryRouter,
        private DualTrackOrchestratorService $dualTrack,
        private DualTrackOutcomeService $dualTrackOutcomes,
        private TradingInstrumentOperatingSystemService $instruments,
        private InstrumentPolicyRouterService $instrumentPolicy,
        private TradingCognitiveStackService $cognitiveStack,
        private InstrumentSettlementProjectionService $instrumentSettlements,
        private InstrumentInvocationLedgerService $instrumentInvocations,
        private ExecutionRiskSentinelService $riskSentinel,
        private SmartDisciplineEngineService $discipline,
        private ExecutionTacticSettlementService $tacticSettlements,
        private RegimeCapabilityRouter $capabilityRouter,
        private CausalAttributionService $causalAttributions,
        private ProgressScoreboardService $progressScoreboard,
        private MarketStateEstimatorService $marketStateEstimator,
        private CapabilityCellOrchestrator $capabilityCells,
        private PaperAuthorityAdmissionService $authorityAdmissions,
        private SpecialistCouncilLifecycleService $specialistCouncils,
        private SpecialistPaperAccountService $specialistAccounts,
    ) {}

    public function run(): array
    {
        $stats = ['mode' => (string) config('services.paper.mode', 'shadow'), 'broker' => 'simulated', 'captured' => 0, 'opened' => 0, 'closed' => 0, 'candidates' => 0];
        // Adoption uses this existing paper clock, before fresh candidate reads.
        // It is not a new scheduler and cannot turn a draft/research comparison
        // into authority: activateDue rechecks the original independent exam
        // and each member's native paper admission under its lifecycle lock.
        $stats['specialist_adoption'] = $this->adoptDueSpecialistCouncils();
        $allCandidates = ModelMarketPerformance::with('modelVersion')
            ->where('evidence_status', 'valid')
            ->whereHas('modelVersion', fn ($query) => $query->where('evidence_status', 'valid'))
            ->whereIn('status', ['forward_validated', 'paper'])
            ->where('paper_status', '!=', 'failed')
            ->get();

        // Rebuild the strict portfolio registry before routing any paper
        // signal. With zero forward candidates this remains a no-op; once
        // complementary members exist, routing still waits for their own
        // combined canonical replay gate.
        $portfolioStatuses = $allCandidates->groupBy(fn (ModelMarketPerformance $candidate): string => $candidate->symbol.'|'.$candidate->timeframe)
            ->map(function (Collection $marketCandidates, string $key): string {
                [$symbol, $timeframe] = explode('|', $key, 2);

                return $this->portfolios->syncMarket($symbol, $timeframe, $marketCandidates)['status'];
            })->all();
        $stats['portfolio_status'] = $portfolioStatuses;

        // Retired council entries still own their open paper positions. Management
        // is mandatory even when the active entry version or feature flag changes.
        $managedQuery = PaperOrder::query()->where('status', 'open');
        if (! (bool) config('services.paper.specialist_council_enabled', false)) $managedQuery->whereNotNull('paper_capital_reservation_id');
        $managedOwners = $managedQuery->pluck('model_market_performance_id')->unique();
        foreach ($managedOwners as $candidateId) {
            $owner = ModelMarketPerformance::with('modelVersion')->find($candidateId);
            if ($owner) $stats['closed'] += $this->reconcile($owner);
        }

        // A declared council specialist may prove its individual passport,
        // but it must never start an individual paper track. Paper evidence
        // belongs to the passed combined council proxy; otherwise a strong
        // member could silently bypass the specialist -> router -> replay
        // sequence and reintroduce the portfolio-rescues-failure problem.
        $nativeIntakeAllowed = ! (bool) config('services.paper.specialist_council_enabled', false)
            || ($stats['specialist_adoption']['intake_allowed'] ?? false) === true;
        $candidates = $allCandidates->filter(fn (ModelMarketPerformance $candidate): bool => $nativeIntakeAllowed && $this->paperTrackAllowed($candidate)
        )->values();

        foreach ($candidates as $candidate) {
            $this->gateDecisions->recordPaperAdmissionHandshake($candidate);
            if (! $this->marketReadiness->ready($candidate->symbol, $candidate->timeframe)) {
                $this->gateDecisions->recordPaperCapture($candidate, 'BLOCKED_BY_PROVIDER');

                continue;
            }

            $stats['candidates']++;
            if (! $managedOwners->contains($candidate->id)) $stats['closed'] += $this->reconcile($candidate);
            if (! PaperOrder::where('model_market_performance_id', $candidate->id)
                ->where('evidence_status', 'valid')->where('status', 'open')->exists()) {
                $stats['opened'] += $this->executePendingSignal($candidate);
            }
            $stats['captured'] += $this->captureLatestSignal($candidate, $candidates);
            $this->score($candidate);
        }

        return $stats;
    }

    /** Bounded prospective adoption; a rejected version never blocks owned position management. */
    private function adoptDueSpecialistCouncils(): array
    {
        $base = ['protocol' => 'specialist_paper_adoption_cycle_v1', 'adoptions' => [], 'promotion_evidence' => false];
        if (! (bool) config('services.paper.specialist_council_enabled', false)) {
            return [...$base, 'status' => 'disabled', 'intake_allowed' => false, 'reason_code' => 'SPECIALIST_PAPER_DISABLED'];
        }
        $symbol = (string) config('services.xauusd_organism.symbol', 'XAUUSD');
        $timeframe = (string) config('services.xauusd_organism.laboratory_storage_timeframe', 'H1');
        $mode = app(AutonomousModeService::class)->status($symbol, $timeframe);
        if (($mode['enabled'] ?? false) !== true || ($mode['state'] ?? null) !== 'running') {
            return [...$base, 'status' => 'blocked', 'intake_allowed' => false,
                'reason_code' => 'SPECIALIST_ADOPTION_AUTONOMY_FENCE', 'controller_state' => $mode['state'] ?? 'unavailable'];
        }
        if (! Schema::hasTable('specialist_council_versions')) {
            return [...$base, 'status' => 'blocked', 'intake_allowed' => false, 'reason_code' => 'SPECIALIST_COUNCIL_STORAGE_UNAVAILABLE'];
        }
        $dueQuery = SpecialistCouncilVersion::query()->where('state', 'scheduled')->where('effective_at', '<=', now()->utc());
        $dueCount = (clone $dueQuery)->distinct()->count('council_id');
        // Bounded read-only paging uses the existing monitor minute. Eight
        // corrupt versions cannot permanently shadow a ninth ready council;
        // it adds no lease, authority, persistent cache or scheduling owner.
        $pages = max(1, (int) ceil($dueCount / 8));
        $page = intdiv(now()->utc()->timestamp, 60) % $pages;
        $due = $dueQuery->select('council_id')->distinct()->orderBy('council_id')->offset($page * 8)->limit(8)->pluck('council_id');
        $adoptions = [];
        foreach ($due as $councilId) {
            try {
                $result = $this->specialistCouncils->activateDue((string) $councilId);
            } catch (\Throwable $error) {
                // A malformed/seal-drifted version remains blocked. Other due
                // councils and the old positions still reach their own owners.
                $result = ['allowed' => false, 'reason_code' => 'COUNCIL_ADOPTION_REVALIDATION_FAILED',
                    'error_class' => $error::class, 'promotion_evidence' => false];
            }
            $adoptions[] = ['council_id' => (string) $councilId, 'result' => $result];
        }
        return [...$base, 'status' => 'checked', 'intake_allowed' => true, 'maximum_councils_per_cycle' => 8,
            'due_councils' => $dueCount, 'bounded_page' => $page, 'adoptions' => $adoptions];
    }

    private function paperTrackAllowed(ModelMarketPerformance $candidate): bool
    {
        $model = $candidate->modelVersion;
        if (! $model) {
            return false;
        }
        $metadata = (array) ($model->metadata ?? []);
        if ((bool) config('services.paper.specialist_council_enabled', false) && empty($metadata['specialist_council_binding'])) return false;
        $isCouncilMember = data_get($metadata, 'council_specialist_contract.protocol') === 'agent_council_v1'
            || data_get($metadata, 'portfolio_council_lane.protocol') === 'portfolio_council_v1';
        $specialistBinding = $this->specialistBinding($candidate);
        if (($isCouncilMember && ! ($specialistBinding['allowed'] ?? false))
            || (isset($metadata['specialist_council_binding']) && ! ($specialistBinding['allowed'] ?? false))
            || ! filled($candidate->symbol) || ! filled($candidate->timeframe)) {
            return false;
        }
        $admission = $this->authorityAdmissions->admit($model, $candidate->symbol, $candidate->timeframe, $this->candidatePassport($candidate, $model));
        if (! in_array(($admission['status'] ?? null), ['e3_paper_candidate', 'e4_evidence_ready'], true)) {
            return false;
        }
        // Closing an admitted order remains mandatory after a paper period
        // ends. Observation readiness applies to NEW capture/fill only.
        if (! $this->frozenPaperGuard($candidate, null, false)['allowed']) return false;
        if ((bool) data_get($candidate->metrics, 'portfolio_proxy', false)) {
            $ready = $this->portfolios->ready($candidate->symbol, $candidate->timeframe);
            if ($ready !== null && (int) $ready->id === (int) data_get($candidate->metrics, 'elite_portfolio_id', 0)) {
                return true;
            }
            $transition = $this->portfolioTransition($candidate);
            return in_array((string) data_get($transition, 'decision'), ['HYBRID_CANARY', 'COUNCIL_CANARY'], true);
        }
        return true;
    }

    private function candidatePassport(ModelMarketPerformance $candidate, \App\Models\ModelVersion $model): array
    {
        $windowKey = data_get($model->metadata, 'paper_window_key', data_get($candidate->metrics, 'paper_window_key'));
        return [
            ...($windowKey !== null ? ['paper_window_key' => $windowKey] : []),
            'passport_hash' => data_get($model->metadata, 'elite_agent_passport.passport_hash', data_get($candidate->metrics, 'elite_agent_passport.passport_hash')),
            'execution_hash' => data_get($candidate->metrics, 'execution_contract.execution_hash'),
            'confirmation_entry_hash' => data_get($model->metadata, 'confirmation_entry.contract_hash', data_get($candidate->metrics, 'confirmation_entry.contract_hash')),
            'risk_governor_hash' => data_get($model->metadata, 'risk_governor.hash', data_get($candidate->metrics, 'risk_governor.hash')),
            'trade_management_hash' => data_get($model->metadata, 'trade_management.hash', data_get($candidate->metrics, 'trade_management.hash')),
            // The frozen-paper window is the canonical temporal proof. A
            // missing proof blocks admission rather than treating wall clock
            // year as evidence.
            'training_pre_2026' => data_get($candidate->metrics, 'training_boundary.used_for_training') === false
                && data_get($candidate->metrics, 'gold_holdout.used_for_training') === false,
        ];
    }

    private function frozenPaperGuard(ModelMarketPerformance $candidate, ?PaperSignal $signal = null, bool $newObservation = true): array
    {
        if ($candidate->exists) $candidate->refresh();
        $model = $candidate->modelVersion?->fresh();
        if (! $model) return ['allowed' => false, 'reason_code' => 'PAPER_MODEL_MISSING'];
        $candidate->setRelation('modelVersion', $model);
        $passport = $this->candidatePassport($candidate, $model);
        $passport['execution_hash'] = data_get(app(ExecutionContractService::class)->for(
            $candidate->symbol, $this->mtfPilot->decisionTimeframe($candidate)), 'execution_hash');
        $guard = $this->authorityAdmissions->verifyFrozenCandidate($model, $candidate->symbol, $candidate->timeframe,
            $passport, $signal ? (int) data_get($signal->payload, 'paper_admission.admission_id', 0) : null);
        if ($newObservation && ($guard['allowed'] ?? false)) {
            $readiness = $this->authorityAdmissions->observationReadiness($model, $candidate->symbol, $candidate->timeframe,
                $signal ? (int) data_get($signal->payload, 'paper_admission.admission_id', 0) : null);
            if (! ($readiness['allowed'] ?? false)) $guard = $readiness;
        }
        if ($signal && ($guard['allowed'] ?? false) && ! hash_equals((string) ($guard['identity_hash'] ?? ''),
            (string) data_get($signal->payload, 'paper_admission.identity_hash', ''))) {
            $guard = ['allowed' => false, 'reason_code' => 'PAPER_SIGNAL_FROZEN_IDENTITY_MISMATCH'];
        }
        if (! ($guard['allowed'] ?? false)) {
            $this->gateDecisions->recordPaperCapture($candidate, 'BLOCKED_BY_FROZEN_PAPER_IDENTITY',
                ['reason_code' => $guard['reason_code'], 'paper_signal_id' => $signal?->id]);
        }
        return $guard;
    }

    private function captureLatestSignal(ModelMarketPerformance $candidate, $universe): int
    {
        $paperAdmission = $this->frozenPaperGuard($candidate);
        if (! $paperAdmission['allowed']) return 0;
        $specialist = $this->specialistBinding($candidate);
        if ($specialist === [] && (bool) config('services.paper.specialist_council_enabled', false)) return 0;
        if ($specialist !== [] && ! ($specialist['allowed'] ?? false)) return 0;
        // A stale/invalidated portfolio proxy must stop before the AI
        // transport. Sending an empty portfolio_members payload would make
        // Python interpret the proxy as a normal `portfolio` strategy and
        // either error or, worse, lose the explicit WAIT reason.
        if (! $this->runtimePortfolioAllowed($candidate)) {
            return 0;
        }

        $decisionTimeframe = $this->mtfPilot->decisionTimeframe($candidate);
        $rows = $this->candles->candlesForBacktest($candidate->symbol, $decisionTimeframe, 1000);
        if (count($rows) < 200) {
            $this->gateDecisions->recordPaperCapture($candidate, 'NO_SIGNAL_OPPORTUNITY', ['available_candles' => count($rows)]);

            return 0;
        }
        if ($specialist !== []) {
            $latest = $rows[count($rows) - 1];
            $time = data_get($latest, 'time', data_get($latest, 'timestamp'));
            $interval = (int) data_get($specialist, 'member.horizon.decision_interval_seconds', 0);
            try {
                if ($time === null) throw new \LogicException('SPECIALIST_OBSERVATION_TIME_MISSING');
                $observed = is_numeric($time) ? \Carbon\CarbonImmutable::createFromTimestampUTC($time) : \Carbon\CarbonImmutable::parse($time);
                if ($interval <= 0 || $observed->timestamp % $interval !== 0) {
                    $this->gateDecisions->recordPaperCapture($candidate, 'SPECIALIST_DECISION_NOT_DUE', ['decision_interval_seconds' => $interval, 'clock' => 'utc_candle_boundary']);
                    return 0;
                }
            } catch (\Throwable) {
                $this->gateDecisions->recordPaperCapture($candidate, 'BLOCKED_BY_SPECIALIST_OBSERVATION_CLOCK');
                return 0;
            }
        }
        $transition = $this->portfolioTransition($candidate);
        if ($transition !== []) {
            $last = $rows[count($rows) - 1] ?? [];
            $eventKey = implode('|', [$candidate->symbol, $decisionTimeframe, $candidate->id, data_get($last, 'time', data_get($last, 'timestamp', 'latest'))]);
            $canary = $this->canaryRouter->decide($transition, $eventKey);
            if ($canary['route'] !== 'council') {
                $this->gateDecisions->recordPaperCapture($candidate, 'COUNCIL_CANARY_INCUMBENT_FALLBACK', ['canary' => $canary]);

                return 0;
            }
        }

        $model = $candidate->modelVersion;
        $request = $this->aiRequest($candidate, $rows);
        $aiUrl = rtrim(config('services.ai_service.url'), '/');
        $headers = ['X-Internal-Token' => (string) config('services.internal_api.token')];
        // Champion and Council now cross separate lane endpoints in parallel.
        // They receive the same sealed request but produce independent call,
        // context and output hashes. A missing lane fails closed; the old
        // single projection is never silently used as twin evidence.
        $responses = Http::pool(function (Pool $pool) use ($aiUrl, $headers, $request): array {
            return [
                'champion' => $pool->as('champion')->timeout(120)->acceptJson()->withHeaders($headers)->post($aiUrl.'/api/paper/twin/champion', $request),
                'council' => $pool->as('council')->timeout(120)->acceptJson()->withHeaders($headers)->post($aiUrl.'/api/paper/twin/council', $request),
            ];
        });
        $championResponse = $responses['champion'] ?? null;
        $councilResponse = $responses['council'] ?? null;
        if (! $championResponse || ! $councilResponse || $championResponse->failed() || $councilResponse->failed()) {
            $this->gateDecisions->recordPaperCapture($candidate, 'BLOCKED_BY_TWIN_INFERENCE', [
                'champion_http_status' => $championResponse?->status(),
                'council_http_status' => $councilResponse?->status(),
                'promotion_evidence' => false,
            ]);

            return 0;
        }

        $championResult = (array) $championResponse->json();
        $councilResult = (array) $councilResponse->json();
        $championTrack = (array) data_get($championResult, 'dual_track.output', []);
        $councilTrack = (array) data_get($councilResult, 'dual_track.output', []);
        $championSnapshot = (string) data_get($championResult, 'dual_track.snapshot_hash', '');
        $councilSnapshot = (string) data_get($councilResult, 'dual_track.snapshot_hash', '');
        if ($championTrack === [] || $councilTrack === [] || $championSnapshot === '' || $championSnapshot !== $councilSnapshot) {
            $this->gateDecisions->recordPaperCapture($candidate, 'BLOCKED_BY_TWIN_SNAPSHOT_MISMATCH', [
                'champion_snapshot' => $championSnapshot,
                'council_snapshot' => $councilSnapshot,
                'promotion_evidence' => false,
            ]);

            return 0;
        }
        $signal = $this->mtfPilot->enforcePaperResponse($candidate, [
            ...$councilResult,
            'dual_track' => [
                'protocol' => 'dual_track_true_twin_inference_v1',
                'snapshot_hash' => $councilSnapshot,
                'snapshot_manifest' => data_get($councilResult, 'dual_track.snapshot_manifest'),
                'champion' => $championTrack,
                'council' => $councilTrack,
                'selected_by_existing_governor' => $councilResult['signal'] ?? 'WAIT',
                'independence_status' => 'dedicated_lane_endpoint',
                'promotion_evidence' => false,
            ],
        ]);
        $entryTransport = $this->confirmationEntryTransport($model, $signal);
        if ($entryTransport['required'] && ! $entryTransport['attested']) {
            $signal['signal'] = 'WAIT';
            $signal['entry_contract_transport_reason'] = 'ENTRY_CONTRACT_ATTESTATION_FAILED';
        }
        $rawConfidence = max(0, min(1, (float) ($signal['confidence'] ?? 0)));
        $calibrated = $this->calibration->calibrate($candidate, (string) ($signal['market_regime'] ?? 'unknown'), $rawConfidence);
        $news = $this->calendar->veto($candidate->symbol);
        $signal['raw_confidence'] = $rawConfidence;
        $signal['calibration'] = $calibrated;
        $signal['economic_calendar'] = $news;
        $sessionContext = $this->instrumentSessionContext($signal);
        if (($signal['meta_agent']['decision'] ?? null) === 'WAIT') {
            $signal['signal'] = 'WAIT';
            $signal['meta_reason'] = $signal['meta_agent']['reason'] ?? 'META_AGENT_WAIT';
        } elseif (! $calibrated['allowed']) {
            $signal['signal'] = 'WAIT';
            $signal['calibration_reason'] = 'Calibrated paper probability is below the minimum execution threshold.';
        } elseif ($news['active']) {
            $signal['signal'] = 'WAIT';
            $signal['news_reason'] = 'High-impact economic event execution veto.';
        } elseif (data_get($sessionContext, 'actionability') !== 'context_observed') {
            $signal['signal'] = 'WAIT';
            $signal['session_reason'] = 'Market-session calendar or observed liquidity is not actionable.';
        } elseif ($specialist === [] && ! $this->allocator->ownsRegime(
            $candidate, $universe,
            (string) ($signal['market_regime'] ?? 'unknown'),
            (string) ($signal['volatility_regime'] ?? 'normal_volatility'),
            (string) data_get($sessionContext, 'session', 'off_session'),
            (string) data_get($sessionContext, 'venue_phase', 'calendar_quarantine'),
            (string) ($signal['signal'] ?? 'WAIT'),
            (string) data_get($sessionContext, 'spread_liquidity_state', ''),
            (string) (($signal['market_regime'] ?? 'unknown') === 'transition' ? 'transition' : 'stable'),
        )) {
            $signal['signal'] = 'WAIT';
            $signal['allocator_reason'] = 'Another independent specialist owns the current regime risk budget.';
        }

        // The raw strategy branch is the Champion lane and the typed/meta
        // governor branch is the Council lane. Both are persisted against the
        // same immutable signal snapshot. Shadow mode deliberately keeps the
        // existing incumbent decision as the only paper execution owner.
        $twinTrack = (array) data_get($signal, 'dual_track', []);
        $signal['dual_track'] = $this->dualTrack->observeSignal(
            [
                'symbol' => $candidate->symbol,
                'timeframe' => $candidate->timeframe,
                'task_type' => 'paper_signal',
                'market_regime' => $signal['market_regime'] ?? 'unknown',
                'volatility_regime' => $signal['volatility_regime'] ?? 'normal_volatility',
                'event_key' => implode('|', [$candidate->id, $signal['signal_time'] ?? 'latest']),
                'snapshot_hash' => data_get($twinTrack, 'snapshot_hash', data_get($signal, 'execution_contract.data_hash')),
                'snapshot_manifest' => data_get($twinTrack, 'snapshot_manifest'),
                'snapshot_manifest_hash' => data_get($twinTrack, 'snapshot_manifest.snapshot_hash', data_get($twinTrack, 'snapshot_hash')),
                'candidate_id' => $candidate->id,
                'risk_percent' => data_get($signal, 'execution_contract.risk_per_trade_percent', data_get($signal, 'execution_contract.risk_percent')),
                'transition' => $transition,
            ],
            [
                ...((array) data_get($twinTrack, 'champion', [])),
                'decision' => data_get($twinTrack, 'champion.decision', $signal['agent_signal'] ?? $signal['signal'] ?? 'WAIT'),
                'confidence' => data_get($twinTrack, 'champion.confidence', $signal['confidence'] ?? 0),
                'source' => 'raw_strategy_signal',
            ],
            [
                ...((array) data_get($twinTrack, 'council', [])),
                'decision' => data_get($twinTrack, 'council.decision', data_get($signal, 'meta_agent.decision', $signal['signal'] ?? 'WAIT')),
                'confidence' => data_get($twinTrack, 'council.confidence', $signal['confidence'] ?? 0),
                'committee' => data_get($twinTrack, 'council.committee', data_get($signal, 'meta_agent.council', [])),
                'source' => 'typed_agent_council',
            ],
            [
                'constitution_integrity' => true,
                'snapshot_integrity' => true,
                'incumbent_decision' => $signal['signal'] ?? 'WAIT',
                'catastrophic_regression' => false,
                'promotion_evidence' => false,
            ],
            [
                'candidate_id' => $candidate->id,
                'model_version_id' => $candidate->model_version_id,
                'paper_mode' => config('services.paper.mode', 'shadow'),
                // Durable evidence workers need the exact sealed request to
                // replay red-team and ablation challenges later.
                'twin_request' => $request,
            ],
        );

        // The instrument router is a fail-closed execution governor. It may
        // turn a candidate into WAIT, but it never replaces the sealed model
        // with a different strategy or creates promotion evidence by itself.
        if ($this->instruments->supports($candidate->symbol, $candidate->timeframe)) {
            $signal['market_state_estimator'] = $this->marketStateEstimator->estimate($candidate->symbol, $candidate->timeframe, ['spread_atr_ratio' => data_get($signal, 'execution_contract.spread_atr_ratio', data_get($signal, 'spread_atr_ratio'))], $news);
            $signal['capability_cell_orchestrator'] = $this->capabilityCells->decide($candidate->symbol, $candidate->timeframe, ['spread_atr_ratio' => data_get($signal, 'execution_contract.spread_atr_ratio', data_get($signal, 'spread_atr_ratio'))], $news, ['session' => $this->instrumentSession($signal), 'strategy_id' => (string) ($model->strategy ?? '')]);
            $instrumentRoute = $this->instrumentPolicy->route($candidate->symbol, $candidate->timeframe, [
                'decision_key' => implode('|', ['paper-instrument', $candidate->id, $signal['signal_time'] ?? 'latest']),
                'regime' => (string) ($signal['market_regime'] ?? 'unknown'),
                'm15_regime' => (string) ($signal['market_regime'] ?? 'unknown'),
                'session' => $this->instrumentSession($signal),
                'volatility' => $this->instrumentVolatility($signal),
                'spread_atr_ratio' => data_get($signal, 'execution_contract.spread_atr_ratio', data_get($signal, 'spread_atr_ratio')),
                'transition' => (bool) data_get($signal, 'transition.active', false),
                'direction' => (string) ($signal['signal'] ?? 'WAIT'),
                'strategy_family' => (string) ($candidate->strategy_family ?? app(StrategyParameterSchemaService::class)->family((string) $model->strategy)),
                'routing_mode' => 'paper',
            ]);
            $signal['trading_instrument_router'] = [
                'decision' => $instrumentRoute['decision'], 'reason_code' => $instrumentRoute['reason_code'],
                'playbook_key' => $instrumentRoute['playbook']?->playbook_key, 'state' => $instrumentRoute['state'],
                'router_decision_id' => $instrumentRoute['router_decision']->id,
                'instrument_bundle' => $instrumentRoute['instrument_bundle'], 'promotion_evidence' => false,
            ];
            $signal['instrument_bundle'] = $instrumentRoute['instrument_bundle'];
            if ($instrumentRoute['decision'] === 'ABSTAIN') {
                $signal['signal'] = 'WAIT';
                $signal['instrument_router_reason'] = $instrumentRoute['reason_code'];
            }
            $cognitivePlan = $this->cognitiveStack->planFromRoute($instrumentRoute, [
                'feed_healthy' => true,
                'news_risk' => (bool) $news['active'],
                'risk_of_ruin_percent' => (float) data_get($candidate->metrics, 'risk_of_ruin_percent', 0),
                'drawdown_percent' => (float) data_get($candidate->metrics, 'drawdown_percent', 0),
                'entry_contract' => $entryTransport['contract'],
                'entry_contract_required' => $entryTransport['required'],
                'entry_contract_attested' => $entryTransport['attested'],
                'entry_fill_admission' => $entryTransport['fill_admission'],
                'opportunity_key' => implode('|', ['paper-entry', $candidate->id, $signal['signal_time'] ?? 'latest']),
                'available_at' => $signal['signal_time'] ?? now(),
            ], [
                'strategy_id' => (string) ($model->strategy ?? ''),
                'mastery_stage' => (string) data_get($model->metadata, 'strategy_mastery.stage', 'apprentice'),
                'innovation_allowed' => (bool) data_get($model->metadata, 'strategy_mastery.innovation_allowed', false),
            ]);
            $signal['trading_cognitive_stack'] = $cognitivePlan;
            if ($cognitivePlan['decision'] === 'WAIT') {
                $signal['signal'] = 'WAIT';
                $signal['cognitive_stack_reason'] = $cognitivePlan['reason_codes'][0] ?? 'COGNITIVE_STACK_PREFLIGHT_VETO';
            }
            $capabilityRoute = $this->capabilityRouter->route($candidate->symbol, $candidate->timeframe, (array) ($instrumentRoute['state'] ?? []), [
                'strategy_id' => (string) ($model->strategy ?? ''),
                'tactic_id' => data_get($cognitivePlan, 'tactic_executor.tactic_id'),
                'research_allowed' => (bool) data_get($model->metadata, 'strategy_mastery.innovation_allowed', false),
                'risk_veto' => $cognitivePlan['decision'] === 'WAIT',
            ]);
            $signal['capability_organism'] = $capabilityRoute;
            if (! $capabilityRoute['capital_authorized']) {
                $signal['signal'] = 'WAIT';
                $signal['capability_router_reason'] = $capabilityRoute['reason_code'];
            }
        }

        $captureReason = match (true) {
            ! $calibrated['allowed'] => 'BLOCKED_BY_CALIBRATION',
            isset($signal['meta_reason']) => 'BLOCKED_BY_META_AGENT',
            $news['active'] => 'BLOCKED_BY_CALENDAR',
            isset($signal['allocator_reason']) => 'BLOCKED_BY_ALLOCATOR',
            isset($signal['entry_contract_transport_reason']) => 'BLOCKED_BY_ENTRY_CONTRACT',
            isset($signal['cognitive_stack_reason']) => 'BLOCKED_BY_COGNITIVE_STACK',
            isset($signal['capability_router_reason']) => 'BLOCKED_BY_CAPABILITY_ROUTER',
            ($signal['signal'] ?? 'WAIT') === 'WAIT' => 'NO_SIGNAL_OPPORTUNITY',
            default => 'SIGNAL_CAPTURED',
        };
        $this->gateDecisions->recordPaperCapture($candidate, $captureReason, [
            'signal_time' => $signal['signal_time'] ?? null,
            'market_regime' => $signal['market_regime'] ?? 'unknown',
            'decision' => $signal['signal'] ?? 'WAIT',
        ]);
        $candleTime = $signal['signal_time'] ?? null;
        if (! $candleTime || PaperSignal::query()
            ->where('model_market_performance_id', $candidate->id)
            ->where('symbol', $candidate->symbol)
            ->where('timeframe', $decisionTimeframe)
            ->where('candle_time', $candleTime)
            ->exists()) {
            return 0;
        }

        $verified = $this->frozenPaperGuard($candidate);
        if (! $verified['allowed'] || ! hash_equals($paperAdmission['identity_hash'], $verified['identity_hash'])) return 0;
        $signal['paper_admission'] = $verified;
        if ($specialist !== []) {
            $confirmed = $this->specialistBinding($candidate);
            if (! ($confirmed['allowed'] ?? false) || $this->bindingPin($confirmed) !== $this->bindingPin($specialist)) return 0;
            $signal['specialist_council_binding'] = $this->bindingPin($confirmed);
            $costProfile = app(ExecutionContractService::class)->parameters($candidate->symbol);
            $carryCeiling = (float) $costProfile['swap_per_day_percent'] * (int) data_get($confirmed, 'member.horizon.max_holding_seconds', 0) / 86400;
            $signal['specialist_trade_intent'] = ['protocol' => 'specialist_paper_trade_intent_v1',
                'owner_id' => $confirmed['owner_id'], 'symbol' => $candidate->symbol, 'direction' => $signal['signal'] ?? 'WAIT',
                'council_version' => $confirmed['council_version'], 'management_version' => $confirmed['management_version'],
                'horizon' => data_get($confirmed, 'member.horizon'),
                'entry_plan' => ['type' => 'next_candle_sealed_execution_contract', 'observed_price' => $signal['price'] ?? null,
                    'stop_loss' => $signal['stop_loss'] ?? null, 'take_profit' => $signal['take_profit'] ?? null],
                'capital_demand' => ['base_units' => (float) config('services.paper.units', 1), 'size_pending_native_risk_authorization' => true,
                    'allocation_weight_ceiling' => data_get($confirmed, 'member.capital_weight')],
                'risk_percent_ceiling' => min((float) config('services.risk.max_risk_per_trade_percent', 1), (float) data_get($confirmed, 'member.risk_per_trade_percent', 0)),
                'estimated_round_trip_cost_percent' => $this->risk->estimatedRoundTripCostPercent($candidate->symbol, (float) ($signal['price'] ?? 0))
                    + (float) $costProfile['commission_percent'] + $carryCeiling,
                'expires_at' => \Carbon\CarbonImmutable::parse($candleTime)->utc()->addSeconds(2 * (int) data_get($confirmed, 'member.horizon.decision_interval_seconds'))->toIso8601String(),
                'invalidation' => 'expired_or_frozen_identity_or_entry_risk_geometry_changed', 'promotion_evidence' => false];
        }
        $signalSnapshot = $this->foundation->captureSignalMarketSnapshot([
            'signal_type' => 'paper_candidate',
            'signal_key' => "paper:{$candidate->id}:{$candleTime}",
            'strategy' => $model->strategy,
            'symbol' => $candidate->symbol,
            'timeframe' => $decisionTimeframe,
            'signal' => $signal['signal'] ?? 'WAIT',
            'confidence' => round($rawConfidence * 100, 2),
            'hypothesis' => 'Forward-validated candidate emitted an immutable paper signal.',
        ]);

        $paperSignal = PaperSignal::create([
            'model_market_performance_id' => $candidate->id,
            'model_version_id' => $model->id,
            'signal_market_snapshot_id' => $signalSnapshot?->id,
            'symbol' => $candidate->symbol,
            'timeframe' => $decisionTimeframe,
            'candle_time' => $candleTime,
            'decision' => $signal['signal'] ?? 'WAIT',
            'price' => $signal['price'] ?? 0,
            'stop_loss' => $signal['stop_loss'] ?? null,
            'take_profit' => $signal['take_profit'] ?? null,
            'confidence' => round(((float) data_get($signal, 'calibration.confidence', $rawConfidence)) * 100, 2),
            'market_regime' => $signal['market_regime'] ?? 'unknown',
            'volatility_regime' => $signal['volatility_regime'] ?? 'normal_volatility',
            'payload' => $signal,
            'payload_hash' => hash('sha256', json_encode($this->canonicalize($signal), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)),
        ]);
        $signal['payload_hash'] = $paperSignal->payload_hash;
        $this->instrumentInvocations->recordDecision($paperSignal);
        $this->mtfLedger->recordOfficial($paperSignal, $signal);
        $this->mtfLedger->recordShadow($candidate, $paperSignal, $signal);
        $this->executionState->record($candidate, 'signal_created', $paperSignal, null, ['provider' => 'canonical_market_data',
            'requested_price' => $paperSignal->price, 'payload' => ['candle_time' => $paperSignal->candle_time?->toIso8601String()]]);
        $this->executionState->transition($candidate, 'locate', $paperSignal, null, null, ['market_state' => data_get($signal, 'market_state_estimator')]);
        $this->executionState->transition($candidate, 'trigger', $paperSignal, null, 'locate');
        $this->executionState->transition($candidate, 'confirm', $paperSignal, null, 'trigger', ['tactic' => data_get($signal, 'trading_cognitive_stack.tactic_executor')]);

        return 1;
    }

    private function executePendingSignal(ModelMarketPerformance $candidate): int
    {
        $signal = PaperSignal::query()
            ->where('model_market_performance_id', $candidate->id)
            ->whereIn('decision', ['BUY', 'SELL'])
            ->whereDoesntHave('order')
            ->oldest('candle_time')
            ->first();
        if (! $signal) {
            return 0;
        }
        $specialist = $this->specialistBinding($candidate, (array) data_get($signal->payload, 'specialist_council_binding', []));
        if ($specialist === [] && (bool) config('services.paper.specialist_council_enabled', false)) return 0;
        if ($specialist !== [] && ! ($specialist['allowed'] ?? false)) return 0;
        if (! $this->frozenPaperGuard($candidate, $signal)['allowed']) return 0;
        if (! $this->runtimePortfolioAllowed($candidate)) {
            return 0;
        }
        if ($this->executionState->signalInvalidatedByDisconnect($candidate, $signal)) {
            $this->executionState->record($candidate, 'cancelled', $signal, null, ['reason' => 'STALE_AFTER_PROVIDER_DISCONNECT']);

            return 0;
        }

        $symbolId = Symbol::where('code', $signal->symbol)->value('id');
        $entryCandle = $symbolId ? Candle::query()
            ->where('symbol_id', $symbolId)
            ->where('timeframe', $signal->timeframe)
            ->where('time', '>', $signal->candle_time)
            ->oldest('time')
            ->first() : null;
        if (! $entryCandle) {
            return 0;
        }

        $rows = $this->candles->candlesForBacktest($candidate->symbol, $signal->timeframe, 1000);
        $contractResponse = Http::timeout(120)->acceptJson()
            ->withHeaders(['X-Internal-Token' => (string) config('services.internal_api.token')])->post(
                rtrim(config('services.ai_service.url'), '/').'/api/paper/execution-contract',
                ['request' => $this->aiRequest($candidate, $rows), 'entry_market_price' => (float) $entryCandle->open,
                    'signal_time' => $signal->candle_time->toIso8601String()],
            );
        if ($contractResponse->failed()) {
            $this->executionState->record($candidate, 'provider_disconnected', $signal, null, ['provider' => 'ai_execution_contract', 'reason' => 'EXECUTION_CONTRACT_UNAVAILABLE', 'latency_ms' => 120000]);
            $this->gateDecisions->recordPaperCapture($candidate, 'BLOCKED_BY_EXECUTION_CONTRACT', ['paper_signal_id' => $signal->id, 'http_status' => $contractResponse->status()]);

            return 0;
        }
        $contract = (array) $contractResponse->json();
        $expectedExecution = app(ExecutionContractService::class)->for($candidate->symbol, $signal->timeframe);
        if (! app(ExecutionContractService::class)->matches(
            (array) data_get($contract, 'execution_contract', []),
            $candidate->symbol,
            $candidate->timeframe,
        )) {
            $this->executionState->record($candidate, 'provider_disconnected', $signal, null, [
                'provider' => 'ai_execution_contract', 'reason' => 'EXECUTION_CONTRACT_MISMATCH',
                'expected_execution_hash' => $expectedExecution['execution_hash'],
                'received_execution_hash' => data_get($contract, 'execution_hash'),
            ]);
            $this->gateDecisions->recordPaperCapture($candidate, 'BLOCKED_BY_EXECUTION_CONTRACT', [
                'paper_signal_id' => $signal->id, 'reason' => 'EXECUTION_CONTRACT_MISMATCH',
            ]);

            return 0;
        }
        if (($contract['decision'] ?? 'WAIT') !== $signal->decision) {
            $this->gateDecisions->recordPaperCapture($candidate, 'BLOCKED_BY_META_AGENT', ['paper_signal_id' => $signal->id, 'meta_reason' => data_get($contract, 'meta_agent.reason')]);

            return 0;
        }
        $passport = $signal->passport;
        if ($passport && $this->mtfPilot->isPilotCandidate($candidate)) {
            $contractContextHash = (string) data_get($contract, 'mtf_pilot.context.h1_context_hash', '');
            if ($contractContextHash === '' || ! hash_equals((string) $passport->h1_context_hash, $contractContextHash)) {
                $this->executionState->record($candidate, 'provider_disconnected', $signal, null, [
                    'provider' => 'ai_execution_contract', 'reason' => 'MTF_CONTEXT_HASH_MISMATCH',
                    'expected_h1_context_hash' => $passport->h1_context_hash,
                    'received_h1_context_hash' => $contractContextHash,
                ]);
                $this->gateDecisions->recordPaperCapture($candidate, 'BLOCKED_BY_EXECUTION_CONTRACT', [
                    'paper_signal_id' => $signal->id, 'reason' => 'MTF_CONTEXT_HASH_MISMATCH',
                ]);

                return 0;
            }
        }
        $entry = (float) $contract['entry_price'];
        $executionSignal = array_merge($signal->payload, [
            'signal' => $signal->decision, 'signal_time' => $signal->candle_time->toIso8601String(),
            'entry_candle_time' => $entryCandle->time->toIso8601String(), 'price' => $entry,
            'stop_loss' => (float) $contract['stop_loss'], 'take_profit' => (float) $contract['take_profit'],
            'execution_contract' => $contract,
        ]);
        $sentinelPlan = $this->riskSentinel->assess($candidate, $executionSignal, $contract);
        $this->riskSentinel->record($signal, $candidate, $sentinelPlan);
        $executionSignal['risk_sentinel'] = $sentinelPlan;
        $risk = $sentinelPlan['approved']
            ? $this->risk->canOpen($candidate, $executionSignal)
            : null;
        $authorityResults = ['risk_sentinel' => $sentinelPlan];
        if ($risk !== null) {
            $authorityResults['account_risk'] = $risk;
        }
        $disciplinePlan = $this->discipline->assessEntry(
            $candidate,
            $signal,
            $executionSignal,
            $contract,
            $entryCandle->time,
            $authorityResults,
        );
        $baseUnits = (float) config('services.paper.units', 1);

        if (! $sentinelPlan['approved']) {
            $blockedOrder = PaperOrder::create([
                'model_market_performance_id' => $candidate->id, 'paper_signal_id' => $signal->id, 'broker' => 'risk_sentinel',
                'symbol' => $candidate->symbol, 'timeframe' => $candidate->timeframe, 'direction' => $signal->decision, 'units' => 0,
                'entry_price' => $entry, 'stop_loss' => $executionSignal['stop_loss'], 'take_profit' => $executionSignal['take_profit'],
                'status' => 'blocked', 'opened_at' => $entryCandle->time,
                'signal_context' => ['signal' => $executionSignal, 'risk_sentinel' => $sentinelPlan, 'smart_discipline' => $disciplinePlan],
            ]);
            $this->executionState->record($candidate, 'rejected', $signal, $blockedOrder, [
                'provider' => 'risk_sentinel', 'requested_price' => $entry, 'reason' => $sentinelPlan['reason_code'],
                'payload' => ['smart_discipline' => $disciplinePlan],
            ]);
            $this->gateDecisions->recordPaperCapture($candidate, 'BLOCKED_BY_RISK_SENTINEL', [
                'paper_signal_id' => $signal->id,
                'reason' => $sentinelPlan['reason_code'],
                'discipline_reason_codes' => $disciplinePlan['reason_codes'],
            ]);

            return 0;
        }

        if (! $risk['allowed']) {
            $blockedOrder = PaperOrder::create([
                'model_market_performance_id' => $candidate->id,
                'paper_signal_id' => $signal->id,
                'broker' => 'risk_gate',
                'symbol' => $candidate->symbol,
                'timeframe' => $candidate->timeframe,
                'direction' => $signal->decision,
                'units' => 0,
                'entry_price' => $entry,
                'stop_loss' => $executionSignal['stop_loss'],
                'take_profit' => $executionSignal['take_profit'],
                'status' => 'blocked',
                'opened_at' => $entryCandle->time,
                'signal_context' => ['signal' => $executionSignal, 'risk' => $risk, 'smart_discipline' => $disciplinePlan],
            ]);
            $this->executionState->record($candidate, 'rejected', $signal, $blockedOrder, [
                'provider' => 'risk_gate',
                'requested_price' => $entry,
                'reason' => $risk['reason'] ?? 'RISK_VETO',
                'payload' => ['smart_discipline' => $disciplinePlan],
            ]);
            $this->foundation->recordEvent([
                'event_type' => 'paper_signal_blocked',
                'agent' => $candidate->modelVersion->strategy,
                'symbol' => $candidate->symbol,
                'timeframe' => $candidate->timeframe,
                'severity' => 'warning',
                'summary' => "Paper signal blocked: {$risk['reason']}",
                'payload' => ['paper_signal_id' => $signal->id, 'risk' => $risk],
            ]);
            $this->gateDecisions->recordPaperCapture($candidate, 'BLOCKED_BY_RISK', [
                'paper_signal_id' => $signal->id,
                'risk_reason' => $risk['reason'] ?? null,
                'discipline_reason_codes' => $disciplinePlan['reason_codes'],
            ]);

            return 0;
        }

        if (! $disciplinePlan['approved']) {
            $blockedOrder = PaperOrder::create([
                'model_market_performance_id' => $candidate->id,
                'paper_signal_id' => $signal->id,
                'broker' => 'discipline_gate',
                'symbol' => $candidate->symbol,
                'timeframe' => $candidate->timeframe,
                'direction' => $signal->decision,
                'units' => 0,
                'entry_price' => $entry,
                'stop_loss' => $executionSignal['stop_loss'],
                'take_profit' => $executionSignal['take_profit'],
                'status' => 'blocked',
                'opened_at' => $entryCandle->time,
                'signal_context' => ['signal' => $executionSignal, 'risk' => $risk, 'risk_sentinel' => $sentinelPlan, 'smart_discipline' => $disciplinePlan],
            ]);
            $this->executionState->record($candidate, 'rejected', $signal, $blockedOrder, [
                'provider' => 'discipline_gate',
                'requested_price' => $entry,
                'reason' => implode('|', (array) $disciplinePlan['reason_codes']),
                'payload' => ['smart_discipline' => $disciplinePlan],
            ]);
            $this->gateDecisions->recordPaperCapture($candidate, 'BLOCKED_BY_DISCIPLINE', [
                'paper_signal_id' => $signal->id,
                'reason_codes' => $disciplinePlan['reason_codes'],
                'discipline_state' => $disciplinePlan['state'],
            ]);

            return 0;
        }

        $authorization = $this->discipline->authorizeExecutionContract($contract, $sentinelPlan, $disciplinePlan);
        $authorizedContract = $authorization['contract'];
        $sizeMultiple = (float) $authorization['position_size_multiple'];
        $disciplinePlan['final_position_size_multiple'] = $sizeMultiple;
        $disciplinePlan['execution_authorization'] = $authorization['authorization'];
        $executionSignal['execution_contract'] = $authorizedContract;

        $broker = 'simulated';
        $units = $baseUnits * $sizeMultiple;

        if ($specialist !== []) {
            return $this->executeSpecialistOrder($candidate, $signal, $this->bindingPin($specialist), $units, $entry,
                $executionSignal, $entryCandle, $risk, $sentinelPlan, $disciplinePlan, $sizeMultiple, $authorizedContract, $authorization, $rows);
        }

        return DB::transaction(function () use ($candidate, $signal, $broker, $units, $entry, $executionSignal,
            $entryCandle, $risk, $sentinelPlan, $disciplinePlan, $sizeMultiple, $authorizedContract, $authorization): int {
        // Lock the candidate identity through order and fill publication. No stale
        // in-memory model may turn a once-valid E3 admission into a new order.
        ModelMarketPerformance::query()->whereKey($candidate->id)->lockForUpdate()->first();
        \App\Models\ModelVersion::query()->whereKey($candidate->model_version_id)->lockForUpdate()->first();
        PaperSignal::query()->whereKey($signal->id)->lockForUpdate()->first();
        if (PaperOrder::query()->where('paper_signal_id', $signal->id)->exists()
            || ! $this->frozenPaperGuard($candidate, $signal)['allowed']) return 0;
        $order = PaperOrder::create([
            'model_market_performance_id' => $candidate->id,
            'paper_signal_id' => $signal->id,
            'broker' => $broker,
            'external_order_id' => null,
            'symbol' => $candidate->symbol,
            'timeframe' => $candidate->timeframe,
            'direction' => $signal->decision,
            'units' => $units,
            'entry_price' => $entry,
            'stop_loss' => $executionSignal['stop_loss'],
            'take_profit' => $executionSignal['take_profit'],
            'status' => 'open',
            'opened_at' => $entryCandle->time,
            'signal_context' => ['signal' => $executionSignal, 'risk' => $risk, 'risk_sentinel' => $sentinelPlan, 'smart_discipline' => $disciplinePlan, 'position_size_multiple' => $sizeMultiple, 'execution_contract' => $authorizedContract],
            'broker_payload' => ['execution_contract' => $authorizedContract, 'risk_authorization' => $authorization['authorization']],
        ]);
        $this->executionState->record($candidate, 'order_submitted', $signal, $order, ['provider' => $broker, 'requested_price' => $entry, 'requested_units' => $units]);
        $order->fills()->create([
            'fill_type' => 'entry',
            'price' => $entry,
            'cost_percent' => $risk['estimated_round_trip_cost_percent'] / 2,
            'filled_at' => $entryCandle->time,
            'payload' => null,
        ]);
        $this->executionState->transition($candidate, 'open', $signal, $order, 'confirm', ['risk_sentinel' => $sentinelPlan, 'smart_discipline' => $disciplinePlan]);
        $this->executionState->record($candidate, 'filled', $signal, $order, ['provider' => $broker, 'requested_price' => $entry, 'filled_price' => $entry, 'requested_units' => $units, 'filled_units' => $units]);
        $candidate->update(['status' => 'paper', 'paper_status' => 'running']);

        return 1;
        });
    }

    private function specialistBinding(ModelMarketPerformance $candidate, ?array $pin = null): array
    {
        $declared = (array) data_get($candidate->modelVersion?->metadata, 'specialist_council_binding', []);
        if ($declared === [] && ($pin === null || $pin === [])) return [];
        if (! (bool) config('services.paper.specialist_council_enabled', false)) return ['allowed' => false, 'reason_code' => 'SPECIALIST_PAPER_DISABLED'];
        return $this->specialistCouncils->paperBinding($candidate->modelVersion, $candidate->symbol, $candidate->timeframe, $pin ?: $declared);
    }

    private function bindingPin(array $binding): array
    {
        return ['protocol' => 'specialist_council_binding_v1', 'council_id' => $binding['council_id'],
            'council_version' => (string) $binding['council_version'], 'specialist_id' => $binding['specialist_id'] ?? data_get($binding, 'member.specialist_id'),
            'management_version' => $binding['management_version'], ... (isset($binding['version_id']) ? ['version_id' => (int) $binding['version_id']] : [])];
    }

    private function executeSpecialistOrder(ModelMarketPerformance $candidate, PaperSignal $signal, array $binding,
        float $units, float $entry, array $executionSignal, Candle $entryCandle, array $risk, array $sentinelPlan,
        array $disciplinePlan, float $sizeMultiple, array $contract, array $authorization, array $rows): int
    {
        return DB::transaction(function () use ($candidate, $signal, $binding, $units, $entry, $executionSignal,
            $entryCandle, $risk, $sentinelPlan, $disciplinePlan, $sizeMultiple, $contract, $authorization, $rows): int {
            $quantity = SpecialistPaperAccountService::decimalUnits($units);
            $entryPrice = SpecialistPaperAccountService::price($entry);
            $costPolicy = (array) data_get($contract, 'execution_contract.parameters', []);
            if (! isset($costPolicy['commission_percent'], $costPolicy['swap_per_day_percent'])
                || ! is_numeric($costPolicy['commission_percent']) || ! is_numeric($costPolicy['swap_per_day_percent'])
                || $costPolicy['commission_percent'] < 0 || $costPolicy['swap_per_day_percent'] < 0) {
                $this->executionState->record($candidate, 'rejected', $signal, null, ['provider' => 'specialist_paper_account', 'reason' => 'PAPER_COST_POLICY_UNSEALED']);
                return 0;
            }
            $member = $this->specialistCouncils->paperBinding($candidate->modelVersion, $candidate->symbol, $candidate->timeframe, $binding);
            $seconds = ['M1' => 60, 'M5' => 300, 'M15' => 900, 'M30' => 1800, 'H1' => 3600, 'H4' => 14400, 'D1' => 86400][$signal->timeframe] ?? 0;
            $managementBars = data_get($contract, 'management_contract.parameters.time_stop_candles');
            if (! ($member['allowed'] ?? false) || ! is_numeric($managementBars) || $managementBars <= 0 || $seconds <= 0
                || $managementBars * $seconds > (int) data_get($member, 'member.horizon.max_holding_seconds', 0)) {
                $this->executionState->record($candidate, 'rejected', $signal, null, ['provider' => 'specialist_paper_account', 'reason' => 'SPECIALIST_FROZEN_HOLDING_CONTRACT_UNSUPPORTED']);
                return 0;
            }
            $maxHoldingDays = (float) data_get($member, 'member.horizon.max_holding_seconds', 0) / 86400;
            $roundTripPercent = (float) $risk['estimated_round_trip_cost_percent'] + (float) $costPolicy['commission_percent']
                + (float) $costPolicy['swap_per_day_percent'] * $maxHoldingDays;
            $roundTripCost = SpecialistPaperAccountService::costCents($quantity, $entryPrice, $roundTripPercent);
            $reservation = $this->specialistAccounts->reserve($candidate, $signal, $binding, $quantity, $entryPrice,
                SpecialistPaperAccountService::price((float) $executionSignal['stop_loss']), $roundTripCost);
            if (! $reservation['allowed']) {
                $this->executionState->record($candidate, 'rejected', $signal, null, ['provider' => 'specialist_paper_account',
                    'reason' => $reservation['reason_code'], 'payload' => $reservation]);
                $this->gateDecisions->recordPaperCapture($candidate, 'BLOCKED_BY_SPECIALIST_ACCOUNT', $reservation);
                return 0;
            }
            $reserved = $reservation['reservation'];
            if ($reserved['paper_order_id'] !== null || $reserved['status'] === 'released') return 0;
            // The shared account lock is held through candidate/signal checks and
            // order/fill publication; competing members cannot spend this capital.
            if (PaperOrder::where('paper_signal_id', $signal->id)->exists() || ! $this->frozenPaperGuard($candidate, $signal)['allowed']) {
                $this->specialistAccounts->release($reserved['id'], 'rejected');
                return 0;
            }
            $order = PaperOrder::create(['model_market_performance_id' => $candidate->id, 'paper_signal_id' => $signal->id,
                'broker' => 'simulated', 'symbol' => $candidate->symbol, 'timeframe' => $signal->timeframe,
                'direction' => $signal->decision, 'units' => $units, 'entry_price' => $entry,
                'stop_loss' => $executionSignal['stop_loss'], 'take_profit' => $executionSignal['take_profit'],
                'status' => 'submitted', 'opened_at' => $entryCandle->time,
                'signal_context' => ['signal' => $executionSignal, 'risk' => $risk, 'risk_sentinel' => $sentinelPlan,
                    'smart_discipline' => $disciplinePlan, 'position_size_multiple' => $sizeMultiple, 'execution_contract' => $contract,
                    'specialist_council_binding' => $binding, 'management_request' => $this->aiRequest($candidate, $rows),
                    'paper_cost_policy' => $costPolicy],
                'broker_payload' => ['execution_contract' => $contract, 'risk_authorization' => $authorization['authorization']]]);
            $this->specialistAccounts->attach($reserved['id'], $order);
            $this->executionState->record($candidate, 'order_submitted', $signal, $order, ['provider' => 'simulated', 'requested_price' => $entry, 'requested_units' => $units]);
            $costPercent = (float) $costPolicy['commission_percent'] / 2;
            $commission = SpecialistPaperAccountService::costCents($quantity, $entryPrice, $costPercent);
            $this->specialistAccounts->fill($order, 'initial-entry', 'entry', $quantity, $entryPrice,
                $commission, ['cost_percent' => $costPercent, 'filled_at' => $entryCandle->time,
                    'commission_cents' => $commission, 'carry_cents' => 0, 'spread_slippage_embedded_in_prices' => true]);
            $this->executionState->transition($candidate, 'open', $signal, $order, 'confirm', ['risk_sentinel' => $sentinelPlan, 'smart_discipline' => $disciplinePlan]);
            $this->executionState->record($candidate, 'filled', $signal, $order, ['provider' => 'simulated', 'filled_price' => $entry, 'filled_units' => $units]);
            $candidate->update(['status' => 'paper', 'paper_status' => 'running']);
            return 1;
        }, 3);
    }

    private function runtimePortfolioAllowed(ModelMarketPerformance $candidate): bool
    {
        if (! (bool) data_get($candidate->metrics, 'portfolio_proxy', false)) {
            return true;
        }

        $runtime = $this->runtimeEnsembles->requestPayload($candidate);
        if (data_get($runtime, 'runtime_action') === 'ROUTE') {
            return true;
        }

        $this->gateDecisions->recordPaperCapture($candidate, 'BLOCKED_BY_RUNTIME_PORTFOLIO_POLICY', [
            'runtime_reason' => data_get($runtime, 'runtime_ensemble_policy.reason', 'PORTFOLIO_PASSPORT_NOT_ACTIVE'),
            'runtime_policy' => data_get($runtime, 'runtime_ensemble_policy', []),
        ]);

        return false;
    }

    private function instrumentVolatility(array $signal): string
    {
        $value = strtolower((string) ($signal['volatility_regime'] ?? 'unknown'));
        if (str_contains($value, 'high')) {
            return 'high';
        }
        if (str_contains($value, 'low')) {
            return 'low';
        }

        return $value === '' ? 'unknown' : 'normal';
    }

    private function instrumentSession(array $signal): string
    {
        return (string) data_get($this->instrumentSessionContext($signal), 'session', 'off_session');
    }

    /** @return array<string, mixed> */
    private function instrumentSessionContext(array $signal): array
    {
        $time = data_get($signal, 'signal_time');

        return $this->marketSessions->resolve($time ?: null, [
                'spread_atr_ratio' => data_get($signal, 'execution_contract.spread_atr_ratio', data_get($signal, 'spread_atr_ratio')),
            ]);
    }

    /** @return array<string, mixed> */
    private function portfolioTransition(ModelMarketPerformance $candidate): array
    {
        $transition = (array) data_get($candidate->metrics, 'transition', []);
        if ($transition !== []) {
            return $transition;
        }
        $portfolioId = (int) data_get($candidate->metrics, 'elite_portfolio_id', 0);
        if ($portfolioId < 1) {
            return [];
        }

        return (array) data_get(EliteAgentPortfolio::query()->find($portfolioId)?->evidence, 'transition', []);
    }

    private function reconcile(ModelMarketPerformance $candidate): int
    {
        $closed = 0;
        $orders = PaperOrder::where('model_market_performance_id', $candidate->id)
            ->where('evidence_status', 'valid')->where('status', 'open')->get();
        foreach ($orders as $order) {
            $learningEligible = true;
            $result = $this->simulatedExit($order);
            if (! $result) {
                continue;
            }
            [$price, $profit, $exitReason, $managementAudit] = $result;

            if ($order->paper_capital_reservation_id) {
                $accounting = (array) ($result[4] ?? []);
                $closedNow = DB::transaction(function () use ($order, $price, &$profit, $exitReason, $managementAudit, $accounting): bool {
                    $order->refresh();
                    $costPercent = (float) data_get($order->signal_context, 'paper_cost_policy.commission_percent') / 2;
                    $units = (int) $order->remaining_units_micros;
                    $entryPrice = SpecialistPaperAccountService::price((string) $order->entry_price);
                    $commission = SpecialistPaperAccountService::costCents($units, $entryPrice, $costPercent);
                    // Existing sealed candle management charges carry on initial
                    // notional, including after a partial exit. Preserve and label
                    // that simulation convention; it is never broker reconciliation.
                    $carry = SpecialistPaperAccountService::costCents((int) $order->filled_units_micros, $entryPrice, (float) $accounting['carry_percent_total']);
                    $filled = $this->specialistAccounts->fill($order, 'terminal-exit', 'exit', $units,
                        SpecialistPaperAccountService::price($price),
                        $commission + $carry, ['cost_percent' => $costPercent, 'exit_reason' => $exitReason, 'management_audit' => $managementAudit,
                            'commission_cents' => $commission, 'carry_cents' => $carry, 'paper_accounting' => $accounting,
                            'spread_slippage_embedded_in_prices' => true, 'filled_at' => $accounting['exit_time']]);
                    if ($filled) {
                        $this->specialistAccounts->release((int) $order->paper_capital_reservation_id);
                        $ledger = DB::table('paper_cost_ledger')->where('paper_order_id', $order->id);
                        $netCents = (int) $ledger->sum('realized_cents') - (int) $ledger->sum('cost_cents');
                        $initialCents = (int) DB::table('paper_capital_accounts')->join('paper_capital_reservations', 'paper_capital_accounts.id', '=', 'paper_capital_reservations.paper_capital_account_id')
                            ->where('paper_capital_reservations.id', $order->paper_capital_reservation_id)->value('paper_capital_accounts.initial_balance_cents');
                        $profit = round($netCents / max(1, $initialCents) * 100, 4);
                        $order->update(['exit_price' => $price, 'profit_percent' => $profit, 'status' => 'closed', 'closed_at' => $accounting['exit_time']]);
                    }
                    return $filled;
                }, 3);
                if (! $closedNow) continue;
            } else {
                $order->update(['exit_price' => $price, 'profit_percent' => $profit, 'status' => 'closed', 'closed_at' => now()]);
                $order->fills()->create([
                'fill_type' => 'exit',
                'price' => $price,
                'cost_percent' => $this->risk->estimatedRoundTripCostPercent($order->symbol, (float) $order->entry_price) / 2,
                'filled_at' => now(),
                'payload' => ['exit_reason' => $exitReason, 'management_audit' => $managementAudit],
                ]);
            }
            $this->executionState->record($candidate, 'closed', $order->paperSignal, $order, ['provider' => $order->broker, 'filled_price' => $price, 'filled_units' => $order->units, 'reason' => $exitReason]);
            $exitStage = $exitReason === 'invalidated' || str_contains($exitReason, 'stop') ? 'abort' : 'manage';
            $this->executionState->transition($candidate, $exitStage, $order->paperSignal, $order, 'open', ['exit_reason' => $exitReason, 'management_audit' => $managementAudit]);
            $this->executionState->transition($candidate, 'review', $order->paperSignal, $order, $exitStage);
            if ($order->paper_signal_id) {
                $audit = $this->selfAudit($candidate, $order, $exitReason, $profit);
                $processIntegrity = $this->discipline->reviewOutcome($candidate, $order, $profit, $exitReason, null, $managementAudit);
                $learningEligible = (bool) $processIntegrity['learning_eligible'];
                $outcome = PaperSignalOutcome::firstOrCreate(['paper_signal_id' => $order->paper_signal_id], [
                    'paper_order_id' => $order->id,
                    'outcome' => $profit > 0 ? 'win' : ($profit < 0 ? 'loss' : 'flat'),
                    'exit_price' => $price,
                    'profit_percent' => $profit,
                    'exit_reason' => $exitReason,
                    'payload' => [
                        'broker' => $order->broker,
                        'closed_at' => now()->toIso8601String(),
                        'self_audit' => $audit,
                        'management_audit' => $managementAudit,
                        'process_integrity' => $processIntegrity,
                    ],
                ]);
                if ($outcome->wasRecentlyCreated && $order->paperSignal && $learningEligible) {
                    $this->calibration->learn($candidate, $order->paperSignal);
                }
                if ($order->paperSignal && $learningEligible) {
                    $this->dualTrackOutcomes->settlePaperOutcome($candidate, $order, $outcome);
                    $this->instrumentSettlements->settle($order, $outcome);
                    $this->instrumentInvocations->settle($order, $outcome);
                    $this->tacticSettlements->settle($order, $outcome);
                    $this->causalAttributions->attribute($order, $outcome);
                    $this->progressScoreboard->measure($order->symbol, $order->timeframe);
                    if ($order->paper_capital_reservation_id) {
                        $version = \App\Models\SpecialistCouncilVersion::where('council_id', $order->council_id)->where('version', $order->council_version)->firstOrFail();
                        app(SpecialistCouncilDataUseService::class)->recordMaturePaperFeedback($order, $version,
                            'paper:'.$order->owner_id.':'.$order->management_version, now()->toIso8601String());
                    }
                }
                if (! $learningEligible) {
                    $order->update([
                        'evidence_status' => 'invalid',
                        'invalidated_at' => now(),
                        'invalidation_reason' => 'smart_discipline_process_violation',
                    ]);
                    $this->foundation->recordEvent([
                        'event_type' => 'paper_process_integrity_violation',
                        'agent' => $candidate->modelVersion->strategy,
                        'symbol' => $candidate->symbol,
                        'timeframe' => $candidate->timeframe,
                        'severity' => 'warning',
                        'summary' => "Paper outcome quarantined: {$processIntegrity['classification']}",
                        'payload' => ['paper_order_id' => $order->id, 'process_integrity' => $processIntegrity],
                    ]);
                }
            }
            if ($learningEligible) {
                $this->recordClosedOrderMemory($candidate, $order->fresh());
            }
            $closed++;
        }

        return $closed;
    }

    private function simulatedExit(PaperOrder $order): ?array
    {
        $candidate = $order->marketPerformance()->with('modelVersion')->first();
        $contract = (array) data_get($order->signal_context, 'execution_contract', []);
        if (! $candidate || $contract === []) {
            return null;
        }
        try { $request = $this->managementRequest($candidate, $order); }
        catch (\LogicException $error) {
            if (! $order->paper_capital_reservation_id) throw $error;
            $this->executionState->record($candidate, 'management_dependency_blocked', $order->paperSignal, $order,
                ['provider' => 'simulated', 'reason' => $error->getMessage()]);
            return null;
        }
        $response = Http::timeout(120)->acceptJson()
            ->withHeaders(['X-Internal-Token' => (string) config('services.internal_api.token')])->post(
                rtrim(config('services.ai_service.url'), '/').'/api/paper/advance-contract', [
                    'request' => $request,
                    'contract' => $contract, 'entry_time' => $order->opened_at?->toIso8601String(),
                ],
            );
        if ($response->failed()) {
            if ($order->paper_capital_reservation_id) $this->executionState->record($candidate, 'management_dependency_blocked', $order->paperSignal, $order,
                ['provider' => 'simulated', 'reason' => 'PINNED_PAPER_MANAGEMENT_PROVIDER_UNAVAILABLE', 'payload' => ['http_status' => $response->status()]]);
            return null;
        }
        $result = $response->json();
        if ($order->paper_capital_reservation_id) {
            $accounting = (array) ($result['paper_accounting'] ?? []);
            if (! $this->specialistAccountingAttested($order, $accounting, (bool) ($result['closed'] ?? false))
                || (($result['closed'] ?? false) && (! is_numeric($result['exit_price'] ?? null) || abs((float) $result['exit_price'] - (float) ($accounting['exit_price'] ?? 0)) > .000001))) {
                $this->executionState->record($candidate, 'accounting_dependency_blocked', $order->paperSignal, $order,
                    ['provider' => 'simulated', 'reason' => 'PAPER_COST_ACCOUNTING_UNATTESTED']);
                return null;
            }
            $partial = $accounting['partial'] ?? null;
            if (is_array($partial)) {
                $fraction = SpecialistPaperAccountService::decimalUnits((float) $partial['fraction']);
                $units = intdiv((int) $order->filled_units_micros * $fraction, SpecialistPaperAccountService::UNIT_SCALE);
                $costPercent = (float) $accounting['commission_percent_round_trip'] / 2;
                $commission = SpecialistPaperAccountService::costCents($units, SpecialistPaperAccountService::price((string) $order->entry_price), $costPercent);
                $this->specialistAccounts->fill($order, 'partial-exit:'.$partial['exit_time'], 'exit', $units,
                    SpecialistPaperAccountService::price((float) $partial['exit_price']), $commission,
                    ['filled_at' => $partial['exit_time'], 'cost_percent' => $costPercent, 'commission_cents' => $commission,
                        'carry_cents' => 0, 'spread_slippage_embedded_in_prices' => true, 'paper_accounting' => $accounting]);
                $this->executionState->record($candidate, 'partial_fill', $order->paperSignal, $order,
                    ['provider' => 'simulated', 'filled_units' => $units / SpecialistPaperAccountService::UNIT_SCALE,
                        'filled_price' => $partial['exit_price'], 'idempotency_suffix' => $partial['exit_time']]);
            }
        }
        if (! ($result['closed'] ?? false)) return null;

        return [
            (float) $result['exit_price'],
            (float) $result['profit_percent'],
            (string) $result['exit_reason'],
            (array) ($result['management_audit'] ?? []),
            (array) ($result['paper_accounting'] ?? []),
        ];
    }

    private function specialistAccountingAttested(PaperOrder $order, array $accounting, bool $closed): bool
    {
        $contract = (array) data_get($order->signal_context, 'execution_contract', []);
        $policy = (array) data_get($order->signal_context, 'paper_cost_policy', []);
        if (($accounting['protocol'] ?? null) !== 'specialist_paper_accounting_v1'
            || ($accounting['execution_attested'] ?? false) !== true || ($accounting['management_attested'] ?? false) !== true
            || ($accounting['costs_embedded_in_prices']['spread'] ?? false) !== true || ($accounting['costs_embedded_in_prices']['slippage'] ?? false) !== true
            || ! hash_equals((string) ($contract['execution_hash'] ?? ''), (string) ($accounting['execution_hash'] ?? ''))
            || ! hash_equals((string) data_get($contract, 'management_contract.management_hash', ''), (string) ($accounting['management_hash'] ?? ''))
            || ! is_numeric($accounting['entry_price'] ?? null) || abs((float) $accounting['entry_price'] - (float) $order->entry_price) > .000001
            || ! is_numeric($accounting['commission_percent_round_trip'] ?? null) || abs((float) $accounting['commission_percent_round_trip'] - (float) ($policy['commission_percent'] ?? -1)) > .00000001
            || ! is_numeric($accounting['holding_days'] ?? null) || (float) $accounting['holding_days'] < 0
            || ! is_numeric($accounting['carry_percent_total'] ?? null) || (float) $accounting['carry_percent_total'] < 0
            || abs((float) $accounting['carry_percent_total'] - (float) ($policy['swap_per_day_percent'] ?? -1) * (float) $accounting['holding_days']) > .00000001
            || ($accounting['carry_scope'] ?? null) !== 'initial_notional_canonical_contract') return false;
        try {
            $observed = \Carbon\CarbonImmutable::parse((string) ($accounting['observed_at'] ?? ''));
            if (! is_string($accounting['observed_at'] ?? null) || $observed->greaterThan(now()) || $observed->lessThan($order->opened_at)) return false;
            $days = $order->opened_at->diffInSeconds($observed) / 86400;
            if (abs($days - (float) $accounting['holding_days']) > .00000001) return false;
            if ($closed && ($accounting['exit_time'] ?? null) !== $accounting['observed_at']) return false;
        } catch (\Throwable) { return false; }
        $partial = $accounting['partial'] ?? null;
        if ($partial !== null && (! is_array($partial) || ! is_numeric($partial['fraction'] ?? null)
            || $partial['fraction'] <= 0 || $partial['fraction'] >= 1 || ! is_numeric($partial['exit_price'] ?? null)
            || (float) $partial['exit_price'] <= 0 || ! is_string($partial['exit_time'] ?? null))) return false;
        if ($partial !== null) {
            try {
                $partialTime = \Carbon\CarbonImmutable::parse($partial['exit_time']);
                if ($partialTime->lessThan($order->opened_at) || $partialTime->greaterThan($observed)) return false;
            } catch (\Throwable) { return false; }
        }
        return ! $closed || (is_numeric($accounting['exit_price'] ?? null) && (float) $accounting['exit_price'] > 0 && is_string($accounting['exit_time'] ?? null));
    }

    private function managementRequest(ModelMarketPerformance $candidate, PaperOrder $order): array
    {
        if (! $order->paper_capital_reservation_id) {
            return $this->aiRequest($candidate, $this->candles->candlesForBacktest($candidate->symbol, $candidate->timeframe, 1000));
        }
        $binding = (array) data_get($order->signal_context, 'specialist_council_binding', []);
        $management = $this->specialistCouncils->paperBinding($candidate->modelVersion, $order->symbol, $candidate->timeframe,
            [...$binding, 'management_only' => true]);
        $request = (array) data_get($order->signal_context, 'management_request', []);
        if (! ($management['allowed'] ?? false) || $request === [] || $order->management_version !== ($binding['management_version'] ?? null)) {
            throw new \LogicException('PINNED_SPECIALIST_MANAGEMENT_UNAVAILABLE');
        }
        // Strategy, parameters, execution and management authority remain pinned.
        // Only newly available market observations advance the existing order.
        $request['candles'] = $this->candles->candlesForBacktest($order->symbol, $order->timeframe, 1000);
        foreach ((array) ($request['mtf_streams'] ?? []) as $timeframe => $stream) {
            $request['mtf_streams'][$timeframe] = $this->candles->candlesForBacktest($order->symbol, $timeframe, max(1000, count($stream)));
        }
        if (! empty($request['regime_candles'])) $request['regime_candles'] = $this->candles->candlesForBacktest($order->symbol, 'H1', 2000);
        return $request;
    }

    private function score(ModelMarketPerformance $candidate): void
    {
        $orders = PaperOrder::where('model_market_performance_id', $candidate->id)
            ->where('evidence_status', 'valid')->where('status', 'closed')->orderBy('closed_at')->get();
        if ($orders->isEmpty()) {
            return;
        }
        $wins = $orders->where('profit_percent', '>', 0)->sum('profit_percent');
        $loss = abs($orders->where('profit_percent', '<=', 0)->sum('profit_percent'));
        $balance = 10000;
        $peak = $balance;
        $drawdown = 0;
        foreach ($orders as $order) {
            $balance *= 1 + $order->profit_percent / 100;
            $peak = max($peak, $balance);
            $drawdown = max($drawdown, ($peak - $balance) / $peak * 100);
        }
        $paperContract = $this->authorityAdmissions->prospectiveOutcomeContract(
            $candidate->modelVersion,
            $candidate->symbol,
            $candidate->timeframe,
            $orders,
        );
        $this->champions->recordPaperResult($candidate, [
            'sample_count' => $orders->count(),
            'profit_factor' => $loss > 0 ? $wins / $loss : ($wins > 0 ? 99 : 0),
            'max_drawdown' => round($drawdown, 2),
            'net_profit_percent' => round(($balance - 10000) / 100, 2),
            'order_ids' => $orders->pluck('id')->all(),
            'paper_window' => $paperContract,
            'discipline_audit' => ['passed' => (bool) data_get($paperContract, 'discipline_audit_passed', false)],
        ]);
    }

    private function recordClosedOrderMemory(ModelMarketPerformance $candidate, PaperOrder $order): void
    {
        if (AgentMemory::query()->where('source_type', PaperOrder::class)->where('source_id', $order->id)->exists()) {
            return;
        }
        $profit = (float) $order->profit_percent;
        $this->foundation->writeExperienceMemory([
            'strategy' => $candidate->modelVersion?->strategy ?? $candidate->strategy_family,
            'memory_type' => 'paper_execution',
            'outcome' => $profit > 0 ? 'win' : 'loss',
            'summary' => "Paper {$order->direction} {$order->symbol} closed at {$profit}%.",
            'lesson' => $profit > 0 ? 'Retain this setup for further paper evidence.' : 'Reduce setup confidence until more evidence exists.',
            'strength' => min(100, 50 + abs($profit) * 10),
            'confidence_score' => 55,
            'source_type' => PaperOrder::class,
            'source_id' => $order->id,
            'metadata' => ['paper_signal_id' => $order->paper_signal_id, 'entry_price' => $order->entry_price, 'exit_price' => $order->exit_price, 'profit_percent' => $profit],
        ]);
    }

    /** Immutable post-trade explanation: future mutations receive a taxonomy, not a vague "PF low" label. */
    private function selfAudit(ModelMarketPerformance $candidate, PaperOrder $order, string $exitReason, float $profit): array
    {
        $signal = $order->paperSignal;
        $payload = (array) ($signal?->payload ?? []);
        $meta = (array) data_get($payload, 'meta_agent', []);
        $constitution = (array) data_get($candidate->modelVersion?->metadata, 'agent_constitution', []);
        $allowed = (array) ($constitution['allowed_regimes'] ?? []);
        $mistakes = [];
        if ($profit <= 0 && str_contains($exitReason, 'stop')) {
            $mistakes[] = 'stop_too_close';
        }
        if ($profit <= 0 && str_contains($exitReason, 'time_stop')) {
            $mistakes[] = 'target_too_far';
        }
        if ($allowed !== [] && ! in_array($signal?->market_regime, $allowed, true)) {
            $mistakes[] = 'regime_mismatch';
        }
        if ((float) data_get($meta, 'expected_value.net_expected_value_percent', 0) > 0 && $profit <= 0) {
            $mistakes[] = 'wrong_direction';
        }
        if ((float) data_get($meta, 'expected_value.net_expected_value_percent', 0) <= 0 && $profit <= 0) {
            $mistakes[] = 'cost_destroyed_edge';
        }

        return [
            'protocol' => 'professional_self_audit_v1', 'predicted_direction' => $signal?->decision,
            'predicted_confidence' => $signal?->confidence, 'expected_value' => data_get($meta, 'expected_value', []),
            'actual_profit_percent' => round($profit, 5), 'exit_reason' => $exitReason,
            'market_regime' => $signal?->market_regime, 'volatility_regime' => $signal?->volatility_regime,
            'loss_taxonomy' => $mistakes, 'primary_failure' => $mistakes[0] ?? null,
            'rule' => 'This audit is learning evidence only; it never rewrites the immutable paper order.',
        ];
    }

    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }

    /** @return array{contract:array<string,mixed>,fill_admission:array<string,mixed>,required:bool,attested:bool} */
    private function confirmationEntryTransport(?object $model, array $signal): array
    {
        $contract = (array) ($signal['entry_contract'] ?? []);
        $sealed = (array) data_get($signal, 'execution_contract_preview.entry_contract', []);
        $fillAdmission = (array) ($signal['entry_fill_admission'] ?? []);
        $sealedFillAdmission = (array) data_get($signal, 'execution_contract_preview.entry_fill_admission', []);
        $strategy = strtolower((string) ($model?->strategy ?? ''));
        $required = str_contains($strategy, 'confirmation_entry_mtf')
            || ($contract['protocol'] ?? null) === ConfirmationEntryContractService::PROTOCOL;
        if (! $required && $fillAdmission === []) {
            $fillAdmission = [
                'allowed' => true,
                'status' => 'not_applicable',
                'reason' => null,
                'promotion_evidence' => false,
            ];
        }
        $attested = ! $required || (
            ($contract['protocol'] ?? null) === ConfirmationEntryContractService::PROTOCOL
            && ($sealed['protocol'] ?? null) === ConfirmationEntryContractService::PROTOCOL
            && $this->canonicalize($contract) === $this->canonicalize($sealed)
            && $this->confirmationEntryHashValid($contract)
            && $this->confirmationEntryHashValid($sealed)
            && $fillAdmission !== []
            && $this->canonicalize($fillAdmission) === $this->canonicalize($sealedFillAdmission)
        );

        return [
            'contract' => $contract,
            'fill_admission' => $fillAdmission,
            'required' => $required,
            'attested' => $attested,
        ];
    }

    private function confirmationEntryHashValid(array $contract): bool
    {
        $supplied = strtolower((string) ($contract['contract_hash'] ?? ''));
        if (! preg_match('/^[a-f0-9]{64}$/', $supplied)) {
            return false;
        }
        unset($contract['contract_hash']);
        $encoded = json_encode($this->canonicalize($contract), JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded)) {
            return false;
        }

        return hash_equals($supplied, hash('sha256', $encoded));
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function confirmationEntryMtfStreams(?object $model, string $symbol): array
    {
        if (! str_contains(strtolower((string) ($model?->strategy ?? '')), 'confirmation_entry_mtf')) {
            return [];
        }

        return [
            'H4' => $this->candles->candlesForBacktest($symbol, 'H4', 500),
            'H1' => $this->candles->candlesForBacktest($symbol, 'H1', 500),
            'M15' => $this->candles->candlesForBacktest($symbol, 'M15', 1000),
        ];
    }

    private function aiRequest(ModelMarketPerformance $candidate, array $rows): array
    {
        $model = $candidate->modelVersion;
        $decisionTimeframe = $this->mtfPilot->decisionTimeframe($candidate);
        $executionContract = app(ExecutionContractService::class)->for($candidate->symbol, $decisionTimeframe);
        $runtime = $this->runtimeEnsembles->requestPayload($candidate);
        $portfolioMembers = (array) data_get($runtime, 'portfolio_members', []);
        $isPortfolio = count($portfolioMembers) >= 2;

        return [
            'symbol' => $candidate->symbol, 'timeframe' => $decisionTimeframe,
            'strategy' => $isPortfolio ? 'portfolio_v1' : $model->strategy,
            'base_strategy' => $isPortfolio ? 'portfolio' : $this->schemas->runtimeBaseStrategy($model->strategy, data_get($model->metadata, 'base_strategy'), $candidate->strategy_family),
            'parameters' => $isPortfolio ? (array) data_get($runtime, 'parameters', []) : ($model->parameters ?? []), 'candles' => $rows,
            'mtf_streams' => $this->confirmationEntryMtfStreams($model, $candidate->symbol),
            // M15 is the entry stream only. Keep live/paper behavior aligned
            // with screening and full replay by supplying the latest H1
            // candles; Python exposes only the last CLOSED H1 state to each
            // M15 decision, so an open H1 bar cannot leak forward.
            'regime_candles' => $decisionTimeframe === 'M15'
                ? $this->candles->candlesForBacktest($candidate->symbol, 'H1', 2000)
                : [],
            'portfolio_members' => $portfolioMembers,
            'runtime_ensemble_policy' => (array) data_get($runtime, 'runtime_ensemble_policy', []),
            'policy_context' => [
                'constitution' => data_get($model->metadata, 'agent_constitution', []),
                'sample_count' => (int) $candidate->sample_count,
                'calibration_score' => (float) data_get($model->metadata, 'capability_vector.calibration', 50),
                'stress_cost_pf' => (float) data_get($candidate->metrics, 'pf_attribution.stress_cost.profit_factor', 0),
                'coverage_passport' => data_get($model->metadata, 'elite_agent_passport.certified_coverage_passport', data_get($candidate->metrics, 'elite_agent_passport.certified_coverage_passport', [])),
            ],
            // The same execution profile is used by screening/full replay and
            // now by paper execution-contract generation.
            'initial_balance' => 10000, 'risk_per_trade' => 1,
            'execution' => $executionContract['parameters'],
            'execution_contract' => $executionContract,
            'mtf_pilot' => $this->mtfPilot->requestPayload(
                $candidate->symbol,
                $decisionTimeframe,
                $model->strategy,
            ),
        ];
    }
}
