<?php

namespace App\Services;

use App\Models\ModelMarketPerformance;
use App\Models\PaperExecutionEvent;
use App\Models\PaperOrder;
use App\Models\PaperSignal;
use App\Models\SmartDisciplineDecision;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Deterministic process-integrity authority for paper execution.
 *
 * It may veto or shrink a trade, but it can never increase risk, create
 * promotion evidence, or reinterpret a strategy signal after the fact.
 */
class SmartDisciplineEngineService
{
    public const PROTOCOL = 'smart_discipline_engine_v2';

    public const AUTHORIZATION_PROTOCOL = 'risk_authorized_execution_contract_v1';

    public function __construct(private RiskHysteresisControllerService $hysteresis) {}

    /** @return array<string, mixed> */
    public function assessEntry(
        ModelMarketPerformance $candidate,
        PaperSignal $signal,
        array $executionSignal,
        array $contract,
        ?CarbonInterface $at = null,
        array $authorityResults = [],
    ): array {
        $at = CarbonImmutable::instance($at ?? now())->utc();

        if (! (bool) config('services.discipline.enabled', true)) {
            $sentinel = isset($authorityResults['risk_sentinel']) && is_array($authorityResults['risk_sentinel'])
                ? $authorityResults['risk_sentinel']
                : null;
            $accountRisk = isset($authorityResults['account_risk']) && is_array($authorityResults['account_risk'])
                ? $authorityResults['account_risk']
                : null;
            $gates = [
                'risk_sentinel_authority' => $this->gate(
                    $sentinel === null || (bool) ($sentinel['approved'] ?? false),
                    0,
                    $sentinel['reason_code'] ?? ($sentinel === null ? 'not_supplied' : null),
                    'independent risk sentinel approval',
                    $this->canonicalNoTradeCode('RISK_SENTINEL', (string) ($sentinel['reason_code'] ?? 'VETO')),
                ),
                'account_risk_authority' => $this->gate(
                    $accountRisk === null || (bool) ($accountRisk['allowed'] ?? false),
                    0,
                    $accountRisk['reason_code'] ?? ($accountRisk === null ? 'not_supplied' : null),
                    'canonical account/portfolio risk approval',
                    $this->canonicalNoTradeCode('RISK', (string) ($accountRisk['reason_code'] ?? 'VETO')),
                ),
            ];
            $externalVetoes = collect($gates)->where('passed', false)->pluck('reason_code')->values()->all();

            return $this->storeEntry($candidate, $signal, [
                'state' => $externalVetoes === [] ? 'BYPASSED' : 'LOCKED',
                'decision' => $externalVetoes === [] ? 'APPROVE' : 'VETO',
                'setup_quality_score' => $this->setupQualityScore($signal, $executionSignal, $contract),
                'process_adherence_score' => 100.0,
                'risk_multiplier' => $externalVetoes === [] ? 1.0 : 0.0,
                'reason_codes' => $externalVetoes === [] ? ['DISCIPLINE_ENGINE_DISABLED'] : $externalVetoes,
                'gate_results' => $gates,
                'metrics' => [
                    'enabled' => false,
                    'external_authorities' => [
                        'risk_sentinel' => $sentinel,
                        'account_risk' => $accountRisk,
                    ],
                ],
            ], $at);
        }

        $entry = (float) ($contract['entry_price'] ?? $executionSignal['price'] ?? 0);
        $stop = (float) ($contract['stop_loss'] ?? $executionSignal['stop_loss'] ?? 0);
        $target = (float) ($contract['take_profit'] ?? $executionSignal['take_profit'] ?? 0);
        $direction = strtoupper((string) $signal->decision);
        $stopDistance = abs($entry - $stop);
        $rewardDistance = abs($target - $entry);
        $rewardRisk = $stopDistance > 0 ? $rewardDistance / $stopDistance : 0.0;
        $geometryValid = $entry > 0 && match ($direction) {
            'BUY' => $stop < $entry && $target > $entry,
            'SELL' => $stop > $entry && $target < $entry,
            default => false,
        };
        $identityLocked = (int) $signal->model_market_performance_id === (int) $candidate->id
            && (int) $signal->model_version_id === (int) $candidate->model_version_id
            && strtoupper((string) ($contract['decision'] ?? $direction)) === $direction;

        $plannedPrice = max(0.0000001, (float) $signal->price);
        $plannedStopDistance = abs($plannedPrice - (float) ($signal->stop_loss ?? $stop));
        $adverseDrift = $direction === 'BUY'
            ? max(0, $entry - $plannedPrice)
            : max(0, $plannedPrice - $entry);
        $lateEntryStopUnits = $plannedStopDistance > 0 ? $adverseDrift / $plannedStopDistance : INF;

        [$sessionName, $sessionStart] = $this->sessionWindow($at);
        $dayStart = $at->startOfDay();
        $weekStart = $at->startOfWeek(CarbonInterface::MONDAY);
        $dailyProfit = $this->closedProfitBetween($dayStart, $at);
        $weeklyProfit = $this->closedProfitBetween($weekStart, $at);
        $dailyLoss = abs(min(0, $dailyProfit));
        $weeklyLoss = abs(min(0, $weeklyProfit));
        $dailyTrades = $this->tradeCountBetween($dayStart, $at);
        $sessionTrades = $this->tradeCountBetween($sessionStart, $at);
        $recentClosed = $this->closedOrdersAt($at, 50);
        $consecutiveLosses = $this->consecutiveLosses($recentClosed);
        $latestLossAt = $consecutiveLosses > 0 ? $recentClosed->first()?->closed_at : null;
        $cooldownMinutes = max(0, (int) config('services.discipline.loss_cooldown_minutes', 20));
        $lossLimit = max(1, (int) config('services.discipline.max_consecutive_losses', 4));
        $lossCooldownActive = $consecutiveLosses >= $lossLimit
            && $latestLossAt !== null
            && CarbonImmutable::instance($latestLossAt)->greaterThan($at->subMinutes($cooldownMinutes));

        $limits = [
            'minimum_reward_risk' => max(0, (float) config('services.discipline.minimum_reward_risk', 1.0)),
            'late_entry_max_stop_units' => max(0, (float) config('services.discipline.late_entry_max_stop_units', .5)),
            'daily_loss_limit_percent' => max(0, (float) config('services.risk.daily_loss_limit_percent', 2)),
            'weekly_loss_limit_percent' => max(0, (float) config('services.discipline.weekly_loss_limit_percent', 5)),
            'max_trades_per_session' => max(1, (int) config('services.discipline.max_trades_per_session', 4)),
            'max_trades_per_day' => max(1, (int) config('services.discipline.max_trades_per_day', 8)),
            'max_consecutive_losses' => $lossLimit,
            'loss_cooldown_minutes' => $cooldownMinutes,
        ];

        $sentinel = isset($authorityResults['risk_sentinel']) && is_array($authorityResults['risk_sentinel'])
            ? $authorityResults['risk_sentinel']
            : null;
        $accountRisk = isset($authorityResults['account_risk']) && is_array($authorityResults['account_risk'])
            ? $authorityResults['account_risk']
            : null;
        $sentinelPassed = $sentinel === null || (bool) ($sentinel['approved'] ?? false);
        $accountRiskPassed = $accountRisk === null || (bool) ($accountRisk['allowed'] ?? false);

        $gates = [
            // External risk owners have higher authority than Discipline. They
            // carry zero adherence weight because a risk veto is a correct
            // process outcome, not a trader/process violation.
            'risk_sentinel_authority' => $this->gate(
                $sentinelPassed,
                0,
                $sentinel['reason_code'] ?? ($sentinel === null ? 'not_supplied' : null),
                'independent risk sentinel approval',
                $this->canonicalNoTradeCode('RISK_SENTINEL', (string) ($sentinel['reason_code'] ?? 'VETO')),
            ),
            'account_risk_authority' => $this->gate(
                $accountRiskPassed,
                0,
                $accountRisk['reason_code'] ?? ($accountRisk === null ? 'not_supplied' : null),
                'canonical account/portfolio risk approval',
                $this->canonicalNoTradeCode('RISK', (string) ($accountRisk['reason_code'] ?? 'VETO')),
            ),
            'identity_lock' => $this->gate($identityLocked, 15, [
                'candidate_id' => $candidate->id, 'signal_candidate_id' => $signal->model_market_performance_id,
                'candidate_model_version_id' => $candidate->model_version_id, 'signal_model_version_id' => $signal->model_version_id,
            ], 'exact candidate, version and direction identity', 'NO_TRADE_STRATEGY_VERSION_OR_IDENTITY_DRIFT'),
            'exit_geometry' => $this->gate($geometryValid, 20, compact('direction', 'entry', 'stop', 'target'), 'directional stop < entry < target geometry', 'NO_TRADE_INVALID_EXIT_GEOMETRY'),
            'minimum_reward_risk' => $this->gate($rewardRisk >= $limits['minimum_reward_risk'], 15, round($rewardRisk, 6), $limits['minimum_reward_risk'], 'NO_TRADE_REWARD_RISK_TOO_LOW'),
            'late_entry_chase' => $this->gate($lateEntryStopUnits <= $limits['late_entry_max_stop_units'], 15, is_finite($lateEntryStopUnits) ? round($lateEntryStopUnits, 6) : null, $limits['late_entry_max_stop_units'], 'NO_TRADE_LATE_ENTRY_CHASE'),
            'daily_loss' => $this->gate($dailyLoss < $limits['daily_loss_limit_percent'], 10, round($dailyLoss, 6), $limits['daily_loss_limit_percent'], 'NO_TRADE_DAILY_LOSS_LOCK'),
            'weekly_loss' => $this->gate($weeklyLoss < $limits['weekly_loss_limit_percent'], 10, round($weeklyLoss, 6), $limits['weekly_loss_limit_percent'], 'NO_TRADE_WEEKLY_LOSS_LOCK'),
            'session_trade_limit' => $this->gate($sessionTrades < $limits['max_trades_per_session'], 5, $sessionTrades, $limits['max_trades_per_session'], 'NO_TRADE_SESSION_TRADE_LIMIT'),
            'daily_trade_limit' => $this->gate($dailyTrades < $limits['max_trades_per_day'], 5, $dailyTrades, $limits['max_trades_per_day'], 'NO_TRADE_DAILY_TRADE_LIMIT'),
            'loss_streak_cooldown' => $this->gate(! $lossCooldownActive, 5, [
                'consecutive_losses' => $consecutiveLosses,
                'latest_loss_at' => $latestLossAt?->toIso8601String(),
            ], ['maximum_losses' => $lossLimit, 'cooldown_minutes' => $cooldownMinutes], 'NO_TRADE_LOSS_STREAK_COOLDOWN'),
        ];
        $reasonCodes = collect($gates)->where('passed', false)->pluck('reason_code')->values()->all();
        $weightedGates = collect($gates)->where('weight', '>', 0);
        $availableWeight = max(1, (int) $weightedGates->sum('weight'));
        $gatePassScore = round((float) $weightedGates->where('passed', true)->sum('weight') / $availableWeight * 100, 2);
        $hysteresisMetrics = $this->hysteresisMetrics($recentClosed, $consecutiveLosses, $reasonCodes === [], $signal);
        $riskState = $this->hysteresis->persist($candidate->symbol, $candidate->timeframe, $hysteresisMetrics);
        $vetoed = $reasonCodes !== [];
        $riskMultiplier = $vetoed ? 0.0 : min(1.0, max(0.0, (float) $riskState['risk_multiplier']));
        $state = $vetoed ? 'LOCKED' : match ((string) $riskState['state']) {
            'NORMAL' => 'GREEN',
            'CAUTION', 'RECOVERY' => 'YELLOW',
            default => 'RED',
        };
        $decision = $vetoed ? 'VETO' : ($riskMultiplier < 1 ? 'SHRINK' : 'APPROVE');
        if ($reasonCodes === []) {
            $reasonCodes[] = $decision === 'SHRINK'
                ? 'RISK_HYSTERESIS_'.strtoupper((string) $riskState['state'])
                : 'PROCESS_APPROVED';
        }

        return $this->storeEntry($candidate, $signal, [
            'state' => $state,
            'decision' => $decision,
            'setup_quality_score' => $this->setupQualityScore($signal, $executionSignal, $contract),
            // The engine obeying a veto is disciplined behavior. Candidate
            // gate quality is reported separately so a correct NO_TRADE does
            // not masquerade as a process violation.
            'process_adherence_score' => 100.0,
            'risk_multiplier' => $riskMultiplier,
            'reason_codes' => $reasonCodes,
            'gate_results' => $gates,
            'metrics' => [
                'session' => $sessionName,
                'daily_net_profit_percent' => round($dailyProfit, 6),
                'weekly_net_profit_percent' => round($weeklyProfit, 6),
                'daily_trades_before_entry' => $dailyTrades,
                'session_trades_before_entry' => $sessionTrades,
                'consecutive_losses' => $consecutiveLosses,
                'reward_risk' => round($rewardRisk, 6),
                'late_entry_stop_units' => is_finite($lateEntryStopUnits) ? round($lateEntryStopUnits, 6) : null,
                'gate_pass_score' => $gatePassScore,
                'external_authorities' => [
                    'risk_sentinel' => $sentinel,
                    'account_risk' => $accountRisk,
                ],
                'hysteresis' => $riskState,
                'limits' => $limits,
            ],
        ], $at);
    }

    /**
     * Seal the position-size decision into the exact contract consumed by
     * paper reconciliation. A ledger-only shrink is not risk control: the
     * final multiplier must be the value used to calculate realized P&L.
     *
     * @return array{contract:array<string,mixed>,position_size_multiple:float,authorization:array<string,mixed>}
     */
    public function authorizeExecutionContract(array $contract, array $sentinelPlan, array $disciplinePlan): array
    {
        if (! (bool) ($sentinelPlan['approved'] ?? false) || ! (bool) ($disciplinePlan['approved'] ?? false)) {
            throw new LogicException('A vetoed risk/discipline plan cannot authorize an execution contract.');
        }

        $strategyRequested = max(0, (float) ($contract['position_size_multiple'] ?? 0));
        $sentinelCap = max(0, (float) ($sentinelPlan['position_size_multiple'] ?? 0));
        $disciplineMultiplier = min(1.0, max(0.0, (float) ($disciplinePlan['risk_multiplier'] ?? 0)));
        $final = round(min($strategyRequested, $sentinelCap) * $disciplineMultiplier, 8);
        if ($final <= 0) {
            throw new LogicException('Authorized execution position size must be positive.');
        }

        $authorization = [
            'protocol' => self::AUTHORIZATION_PROTOCOL,
            'discipline_protocol' => self::PROTOCOL,
            'discipline_decision_id' => $disciplinePlan['decision_id'] ?? null,
            'policy_hash' => data_get($disciplinePlan, 'metrics.policy_hash'),
            'strategy_requested_multiple' => $strategyRequested,
            'sentinel_cap_multiple' => $sentinelCap,
            'discipline_multiplier' => $disciplineMultiplier,
            'final_position_size_multiple' => $final,
            'invariants' => [
                'may_increase_risk' => false,
                'sentinel_cap_preserved' => $final <= $sentinelCap + .00000001,
                'strategy_cap_preserved' => $final <= $strategyRequested + .00000001,
                'promotion_evidence' => false,
            ],
        ];
        $authorization['authorization_hash'] = $this->hash($authorization);

        $authorized = $contract;
        $authorized['position_size_multiple'] = $final;
        $authorized['risk_authorization'] = $authorization;

        return [
            'contract' => $authorized,
            'position_size_multiple' => $final,
            'authorization' => $authorization,
        ];
    }

    /** @return array<string, mixed> */
    public function reviewOutcome(
        ModelMarketPerformance $candidate,
        PaperOrder $order,
        float $profitPercent,
        string $exitReason,
        ?CarbonInterface $at = null,
        array $managementAudit = [],
    ): array {
        $at = CarbonImmutable::instance($at ?? now())->utc();
        $pre = SmartDisciplineDecision::query()
            ->where('paper_signal_id', $order->paper_signal_id)
            ->where('phase', 'pre_trade')
            ->first();
        $signal = $order->paperSignal;
        $context = (array) $order->signal_context;
        $plannedStop = data_get($context, 'signal.stop_loss');
        $plannedTarget = data_get($context, 'signal.take_profit');
        $plannedMultiple = (float) data_get($context, 'smart_discipline.final_position_size_multiple', data_get($context, 'position_size_multiple', 0));
        $baseUnits = max(0, (float) config('services.paper.units', 1));
        $invalidTransitions = PaperExecutionEvent::query()
            ->where('paper_order_id', $order->id)
            ->where('reason', 'INVALID_STAGE_TRANSITION')
            ->count();
        $expectedManagementHash = (string) data_get($context, 'execution_contract.management_contract.management_hash', '');
        $auditedManagementHash = (string) ($managementAudit['management_hash'] ?? '');
        $managementAttested = $expectedManagementHash !== ''
            && $auditedManagementHash !== ''
            && hash_equals($expectedManagementHash, $auditedManagementHash)
            && ($managementAudit['management_attested'] ?? false) === true
            && ($managementAudit['execution_attested'] ?? false) === true
            && ($managementAudit['strategy_attested'] ?? false) === true;
        $auditedInitialStop = $managementAudit['initial_stop_loss'] ?? null;
        $auditedFinalStop = $managementAudit['final_stop_loss'] ?? null;
        $auditStopMatches = is_numeric($plannedStop)
            && is_numeric($auditedInitialStop)
            && $this->samePrice((float) $plannedStop, (float) $auditedInitialStop);
        $stopNotWidened = $auditStopMatches
            && is_numeric($auditedFinalStop)
            && ($managementAudit['stop_widened'] ?? true) === false
            && match (strtoupper((string) $order->direction)) {
                'BUY' => (float) $auditedFinalStop >= (float) $auditedInitialStop
                    || $this->samePrice((float) $auditedFinalStop, (float) $auditedInitialStop),
                'SELL' => (float) $auditedFinalStop <= (float) $auditedInitialStop
                    || $this->samePrice((float) $auditedFinalStop, (float) $auditedInitialStop),
                default => false,
            };

        $gates = [
            'pre_trade_authorization' => $this->gate($pre !== null && $pre->decision !== 'VETO', 20, $pre?->decision, 'APPROVE or SHRINK', 'MISSING_OR_VETOED_PRE_TRADE_DECISION'),
            'strategy_version_lock' => $this->gate($signal !== null && (int) $signal->model_version_id === (int) $candidate->model_version_id, 10, $signal?->model_version_id, $candidate->model_version_id, 'POST_TRADE_VERSION_DRIFT'),
            'stop_contract_held' => $this->gate(is_numeric($plannedStop) && $this->samePrice((float) $order->stop_loss, (float) $plannedStop), 10, (float) $order->stop_loss, $plannedStop, 'STOP_CONTRACT_CHANGED'),
            'target_contract_held' => $this->gate(is_numeric($plannedTarget) && $this->samePrice((float) $order->take_profit, (float) $plannedTarget), 10, (float) $order->take_profit, $plannedTarget, 'TARGET_CONTRACT_CHANGED'),
            'size_ceiling_held' => $this->gate($plannedMultiple > 0 && (float) $order->units <= ($baseUnits * $plannedMultiple) + .000001, 15, (float) $order->units, $baseUnits * $plannedMultiple, 'POSITION_SIZE_OVERRIDE'),
            'state_machine_valid' => $this->gate($invalidTransitions === 0, 10, $invalidTransitions, 0, 'INVALID_EXECUTION_STATE_TRANSITION'),
            'system_execution_only' => $this->gate($order->broker === 'simulated', 5, $order->broker, 'simulated', 'MANUAL_OR_UNKNOWN_EXECUTION_OVERRIDE'),
            'management_contract_attested' => $this->gate($managementAttested, 10, [
                'expected_hash' => $expectedManagementHash,
                'audited_hash' => $auditedManagementHash,
                'management_attested' => $managementAudit['management_attested'] ?? false,
                'execution_attested' => $managementAudit['execution_attested'] ?? false,
                'strategy_attested' => $managementAudit['strategy_attested'] ?? false,
            ], 'all frozen execution contracts attested', 'UNATTESTED_TRADE_MANAGEMENT'),
            'stop_never_widened' => $this->gate($stopNotWidened, 10, [
                'initial_stop_loss' => $auditedInitialStop,
                'final_stop_loss' => $auditedFinalStop,
                'stop_widened' => $managementAudit['stop_widened'] ?? null,
            ], 'stop may stay fixed or reduce risk only', 'STOP_WIDENING_VIOLATION'),
        ];
        $violations = collect($gates)->where('passed', false)->pluck('reason_code')->values()->all();
        $rulesFollowed = $violations === [];
        $errorTaxonomy = $this->processErrorTaxonomy($violations);
        $classification = match (true) {
            $profitPercent > 0 && $rulesFollowed => 'GOOD_WIN',
            $profitPercent > 0 => 'BAD_WIN',
            $profitPercent < 0 && $rulesFollowed => 'GOOD_LOSS',
            $profitPercent < 0 => 'BAD_LOSS',
            $rulesFollowed => 'GOOD_FLAT',
            default => 'BAD_FLAT',
        };
        $processScore = round((float) collect($gates)->where('passed', true)->sum('weight'), 2);
        $reasonCodes = $rulesFollowed ? ['PROCESS_COMPLIANT'] : $violations;

        $decision = SmartDisciplineDecision::firstOrCreate(
            ['decision_key' => 'post_trade:'.$order->id],
            [
                'model_market_performance_id' => $candidate->id,
                'paper_signal_id' => $order->paper_signal_id,
                'paper_order_id' => $order->id,
                'symbol' => strtoupper($order->symbol),
                'timeframe' => strtoupper($order->timeframe),
                'phase' => 'post_trade',
                'state' => (string) ($pre?->state ?? 'UNKNOWN'),
                'decision' => $rulesFollowed ? 'COMPLIANT' : 'VIOLATION',
                'classification' => $classification,
                'setup_quality_score' => $pre?->setup_quality_score,
                'process_adherence_score' => $processScore,
                'risk_multiplier' => (float) ($pre?->risk_multiplier ?? 0),
                'reason_codes' => $reasonCodes,
                'gate_results' => $gates,
                'metrics' => [
                    'profit_percent' => round($profitPercent, 6),
                    'exit_reason' => $exitReason,
                    'rules_followed' => $rulesFollowed,
                    'learning_eligible' => $rulesFollowed,
                    'error_taxonomy' => $errorTaxonomy,
                    'policy_hash_at_entry' => data_get($pre?->metrics, 'policy_hash'),
                    'policy_hash_at_review' => $this->policyHash(),
                    'management_audit' => $managementAudit,
                    'management_contract_attested' => $managementAttested,
                    'stop_not_widened' => $stopNotWidened,
                    'realized_r_multiple' => $managementAudit['realized_r_multiple'] ?? null,
                    'mfe_r' => $managementAudit['mfe_r'] ?? null,
                    'mae_r' => $managementAudit['mae_r'] ?? null,
                ],
                'decided_at' => $at,
            ],
        );

        return $this->contract($decision);
    }

    /** @return array<string, mixed> */
    public function summary(?string $symbol = null, ?string $timeframe = null, ?CarbonInterface $since = null): array
    {
        $query = SmartDisciplineDecision::query();
        $this->applyReportFilters($query, $symbol, $timeframe, $since);
        $rows = $query->orderBy('decided_at')->get();
        $pre = $rows->where('phase', 'pre_trade');
        $post = $rows->where('phase', 'post_trade');
        $bad = $post->whereIn('classification', ['BAD_WIN', 'BAD_LOSS', 'BAD_FLAT'])->count();
        $vetoes = $pre->where('decision', 'VETO');
        $reasonCounts = $vetoes->flatMap(fn (SmartDisciplineDecision $row): array => (array) $row->reason_codes)
            ->countBy()->sortDesc()->all();
        $processViolationCounts = $post->where('decision', 'VIOLATION')
            ->flatMap(fn (SmartDisciplineDecision $row): array => (array) $row->reason_codes)
            ->countBy()->sortDesc()->all();
        $errorTaxonomyCounts = $post->where('decision', 'VIOLATION')
            ->flatMap(fn (SmartDisciplineDecision $row): array => (array) data_get($row->metrics, 'error_taxonomy', []))
            ->countBy()->sortDesc()->all();
        $managementAttested = $post->filter(fn (SmartDisciplineDecision $row): bool => data_get($row->metrics, 'management_contract_attested') === true
        )->count();
        $stopWidened = $post->filter(fn (SmartDisciplineDecision $row): bool => data_get($row->metrics, 'management_audit.stop_widened') === true
        )->count();

        return [
            'protocol' => self::PROTOCOL,
            'filters' => [
                'symbol' => $symbol ? strtoupper($symbol) : null,
                'timeframe' => $timeframe ? strtoupper($timeframe) : null,
                'since' => $since?->toIso8601String(),
            ],
            'entries_assessed' => $pre->count(),
            'approved' => $pre->where('decision', 'APPROVE')->count(),
            'shrunk' => $pre->where('decision', 'SHRINK')->count(),
            'vetoed' => $vetoes->count(),
            'veto_rate_percent' => $this->percent($vetoes->count(), $pre->count()),
            'settled_reviews' => $post->count(),
            'average_setup_quality_score' => $this->average($pre, 'setup_quality_score'),
            'average_process_adherence_score' => $this->average($post->isNotEmpty() ? $post : $pre, 'process_adherence_score'),
            'average_gate_pass_score' => $this->averageMetric($pre, 'gate_pass_score'),
            'invalid_trade_rate_percent' => $this->percent($bad, $post->count()),
            'management_attestation_rate_percent' => $this->percent($managementAttested, $post->count()),
            'stop_widening_rate_percent' => $this->percent($stopWidened, $post->count()),
            'average_realized_r_multiple' => $this->averageMetric($post, 'realized_r_multiple', 4),
            'average_mfe_r' => $this->averageMetric($post, 'mfe_r', 4),
            'average_mae_r' => $this->averageMetric($post, 'mae_r', 4),
            'classifications' => $post->countBy('classification')->sortDesc()->all(),
            'risk_states' => $pre->countBy('state')->sortDesc()->all(),
            'policy_hashes' => $pre->map(fn (SmartDisciplineDecision $row): ?string => data_get($row->metrics, 'policy_hash'))
                ->filter()->countBy()->sortDesc()->all(),
            'top_veto_reasons' => $reasonCounts,
            'top_process_violations' => $processViolationCounts,
            'process_error_taxonomy' => $errorTaxonomyCounts,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function storeEntry(ModelMarketPerformance $candidate, PaperSignal $signal, array $plan, CarbonImmutable $at): array
    {
        $decision = SmartDisciplineDecision::firstOrCreate(
            ['decision_key' => 'pre_trade:'.$signal->id],
            [
                'model_market_performance_id' => $candidate->id,
                'paper_signal_id' => $signal->id,
                'paper_order_id' => null,
                'symbol' => strtoupper($candidate->symbol),
                'timeframe' => strtoupper($candidate->timeframe),
                'phase' => 'pre_trade',
                'state' => $plan['state'],
                'decision' => $plan['decision'],
                'classification' => null,
                'setup_quality_score' => $plan['setup_quality_score'],
                'process_adherence_score' => $plan['process_adherence_score'],
                'risk_multiplier' => $plan['risk_multiplier'],
                'reason_codes' => $plan['reason_codes'],
                'gate_results' => $plan['gate_results'],
                'metrics' => [
                    'protocol' => self::PROTOCOL,
                    'policy_hash' => $this->policyHash(),
                    'policy_contract' => $this->policyContract(),
                    'authority_order' => ['SAFETY', 'RISK', 'DISCIPLINE', 'STRATEGY', 'AI_CONFIDENCE'],
                    'promotion_evidence' => false,
                    ...$plan['metrics'],
                ],
                'decided_at' => $at,
            ],
        );

        return $this->contract($decision);
    }

    /** @return array<string, mixed> */
    private function contract(SmartDisciplineDecision $decision): array
    {
        return [
            'protocol' => self::PROTOCOL,
            'decision_id' => $decision->id,
            'phase' => $decision->phase,
            'state' => $decision->state,
            'decision' => $decision->decision,
            'approved' => ! in_array($decision->decision, ['VETO', 'VIOLATION'], true),
            'classification' => $decision->classification,
            'setup_quality_score' => $decision->setup_quality_score,
            'process_adherence_score' => $decision->process_adherence_score,
            'risk_multiplier' => min(1.0, max(0.0, (float) $decision->risk_multiplier)),
            'reason_codes' => (array) $decision->reason_codes,
            'gate_results' => (array) $decision->gate_results,
            'metrics' => (array) $decision->metrics,
            'learning_eligible' => (bool) data_get($decision->metrics, 'learning_eligible', false),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function policyContract(): array
    {
        return [
            'protocol' => self::PROTOCOL,
            'enabled' => (bool) config('services.discipline.enabled', true),
            'mode' => 'deterministic_hard_gates_plus_risk_only_adaptation',
            'authority_order' => ['SAFETY', 'RISK', 'DISCIPLINE', 'STRATEGY', 'AI_CONFIDENCE'],
            'limits' => [
                'minimum_reward_risk' => max(0, (float) config('services.discipline.minimum_reward_risk', 1.0)),
                'late_entry_max_stop_units' => max(0, (float) config('services.discipline.late_entry_max_stop_units', .5)),
                'daily_loss_limit_percent' => max(0, (float) config('services.risk.daily_loss_limit_percent', 2)),
                'weekly_loss_limit_percent' => max(0, (float) config('services.discipline.weekly_loss_limit_percent', 5)),
                'max_trades_per_session' => max(1, (int) config('services.discipline.max_trades_per_session', 4)),
                'max_trades_per_day' => max(1, (int) config('services.discipline.max_trades_per_day', 8)),
                'max_consecutive_losses' => max(1, (int) config('services.discipline.max_consecutive_losses', 4)),
                'loss_cooldown_minutes' => max(0, (int) config('services.discipline.loss_cooldown_minutes', 20)),
            ],
            'invariants' => [
                'external_risk_veto_has_priority' => true,
                'setup_score_cannot_bypass_hard_gate' => true,
                'adaptive_layer_may_increase_risk' => false,
                'production_rule_change_requires_new_policy_hash' => true,
                'promotion_evidence' => false,
            ],
        ];
    }

    public function policyHash(): string
    {
        return $this->hash($this->policyContract());
    }

    /** @return array<string, mixed> */
    private function gate(bool $passed, int $weight, mixed $observed, mixed $limit, string $reasonCode): array
    {
        return [
            'passed' => $passed,
            'mode' => 'hard',
            'weight' => $weight,
            'observed' => $observed,
            'limit' => $limit,
            'reason_code' => $reasonCode,
        ];
    }

    private function setupQualityScore(PaperSignal $signal, array $executionSignal, array $contract): float
    {
        $entry = (float) ($contract['entry_price'] ?? $executionSignal['price'] ?? 0);
        $stop = (float) ($contract['stop_loss'] ?? $executionSignal['stop_loss'] ?? 0);
        $target = (float) ($contract['take_profit'] ?? $executionSignal['take_profit'] ?? 0);
        $rr = abs($entry - $stop) > 0 ? abs($target - $entry) / abs($entry - $stop) : 0;
        $payload = (array) $signal->payload;
        $score = 0;
        $score += in_array($signal->decision, ['BUY', 'SELL'], true) ? 15 : 0;
        $score += $signal->market_regime && $signal->market_regime !== 'unknown' ? 15 : 0;
        $score += $this->hasAny($payload, ['mtf_pilot', 'trading_cognitive_stack.htf_context', 'higher_timeframe']) ? 15 : 0;
        $score += $this->hasAny($payload, ['trading_cognitive_stack.location_thesis', 'location_thesis', 'location']) ? 15 : 0;
        $score += $this->hasAny($payload, ['trading_cognitive_stack.tactic_executor', 'tactical_contract', 'trigger']) ? 15 : 0;
        $score += (float) $signal->confidence > 0 ? 10 : 0;
        $score += $rr >= (float) config('services.discipline.minimum_reward_risk', 1) ? 15 : 0;

        return round(min(100, $score), 2);
    }

    private function hasAny(array $payload, array $paths): bool
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);
            if ($value !== null && $value !== [] && $value !== '') {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function hysteresisMetrics(Collection $recentClosed, int $consecutiveLosses, bool $currentExecutionQuality, PaperSignal $signal): array
    {
        $returns = $recentClosed->take(5)->pluck('profit_percent')->map(fn ($value): float => (float) $value);
        $recentDrawdownAcceleration = abs(min(0, (float) $returns->take(3)->sum())) / 100;

        return [
            'consecutive_losses' => $consecutiveLosses,
            'drawdown_velocity' => round($recentDrawdownAcceleration, 8),
            'settled_trades' => $returns->count(),
            'after_cost_expectancy' => round((float) ($returns->average() ?? 0), 8),
            'regime_aligned' => $signal->market_regime !== null && $signal->market_regime !== 'unknown',
            'execution_quality_normal' => $currentExecutionQuality,
            'evidence_fingerprint' => $this->hash($recentClosed->take(5)->map(fn (PaperOrder $order): array => [
                'id' => $order->id,
                'closed_at' => $order->closed_at?->toIso8601String(),
                'profit_percent' => (float) $order->profit_percent,
            ])->values()->all()),
        ];
    }

    /** @return array{0:string,1:CarbonImmutable} */
    private function sessionWindow(CarbonImmutable $at): array
    {
        [$name, $startHour] = match (true) {
            $at->hour <= 6 => ['ASIA', 0],
            $at->hour <= 11 => ['LONDON', 7],
            $at->hour <= 16 => ['LONDON_NEW_YORK_OVERLAP', 12],
            $at->hour <= 21 => ['NEW_YORK', 17],
            default => ['LATE_SESSION', 22],
        };
        $start = $at->startOfDay()->addHours($startHour);

        return [$name, $start];
    }

    private function closedProfitBetween(CarbonImmutable $start, CarbonImmutable $end): float
    {
        return (float) PaperOrder::query()
            ->where('evidence_status', 'valid')
            ->where('status', 'closed')
            ->where('closed_at', '>=', $start)
            ->where('closed_at', '<=', $end)
            ->sum('profit_percent');
    }

    private function tradeCountBetween(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return PaperOrder::query()
            ->where('evidence_status', 'valid')
            ->whereIn('status', ['open', 'closed'])
            ->where('opened_at', '>=', $start)
            ->where('opened_at', '<=', $end)
            ->count();
    }

    private function closedOrdersAt(CarbonImmutable $at, int $limit): Collection
    {
        return PaperOrder::query()
            ->where('evidence_status', 'valid')
            ->where('status', 'closed')
            ->where('closed_at', '<=', $at)
            ->latest('closed_at')
            ->limit($limit)
            ->get();
    }

    private function consecutiveLosses(Collection $orders): int
    {
        $losses = 0;
        foreach ($orders as $order) {
            if ((float) $order->profit_percent >= 0) {
                break;
            }
            $losses++;
        }

        return $losses;
    }

    private function samePrice(float $actual, float $planned): bool
    {
        return abs($actual - $planned) <= max(.000001, abs($planned) * .00000001);
    }

    private function applyReportFilters(Builder $query, ?string $symbol, ?string $timeframe, ?CarbonInterface $since): void
    {
        if ($symbol) {
            $query->where('symbol', strtoupper($symbol));
        }
        if ($timeframe) {
            $query->where('timeframe', strtoupper($timeframe));
        }
        if ($since) {
            $query->where('decided_at', '>=', $since);
        }
    }

    private function average(Collection $rows, string $field): ?float
    {
        $values = $rows->pluck($field)->filter(fn ($value): bool => $value !== null);

        return $values->isEmpty() ? null : round((float) $values->average(), 2);
    }

    private function averageMetric(Collection $rows, string $field, int $precision = 2): ?float
    {
        $values = $rows->map(fn (SmartDisciplineDecision $row): mixed => data_get($row->metrics, $field))
            ->filter(fn (mixed $value): bool => is_numeric($value));

        return $values->isEmpty() ? null : round((float) $values->average(), $precision);
    }

    private function percent(int $part, int $whole): float
    {
        return $whole > 0 ? round($part / $whole * 100, 2) : 0.0;
    }

    private function canonicalNoTradeCode(string $owner, string $reason): string
    {
        $reason = strtoupper(trim($reason));
        $normalized = trim((string) preg_replace('/[^A-Z0-9]+/', '_', $reason), '_');
        if (str_starts_with($normalized, 'NO_TRADE_')) {
            return $normalized;
        }

        return 'NO_TRADE_'.trim(strtoupper($owner), '_').'_'.($normalized !== '' ? $normalized : 'VETO');
    }

    /** @param array<int,string> $violations @return array<int,string> */
    private function processErrorTaxonomy(array $violations): array
    {
        $taxonomy = [];
        foreach ($violations as $violation) {
            $taxonomy[] = match ($violation) {
                'POST_TRADE_VERSION_DRIFT' => 'strategy_error',
                'INVALID_EXECUTION_STATE_TRANSITION', 'MANUAL_OR_UNKNOWN_EXECUTION_OVERRIDE' => 'execution_error',
                'STOP_CONTRACT_CHANGED', 'TARGET_CONTRACT_CHANGED', 'UNATTESTED_TRADE_MANAGEMENT' => 'management_error',
                'POSITION_SIZE_OVERRIDE', 'STOP_WIDENING_VIOLATION' => 'risk_error',
                default => 'discipline_error',
            };
        }

        return array_values(array_unique($taxonomy));
    }

    private function hash(array $value): string
    {
        $this->canonicalize($value);

        return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonicalize(array &$value): void
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->canonicalize($item);
            }
        }
        unset($item);
    }
}
