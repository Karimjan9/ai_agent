<?php

namespace App\Services;

use App\Models\Candle;
use App\Models\MarketDataSyncState;
use App\Models\MtfAblationRun;
use App\Models\MtfPilotMonitorRun;
use App\Models\PaperMtfShadowObservation;
use App\Models\PaperSignalOutcome;
use App\Models\PaperSignalPassport;
use App\Models\ServiceHealthCheck;
use App\Models\Symbol;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only operational monitor for the XAUUSD H1 -> M15 pilot.
 *
 * It records what happened and raises health signals; it never changes a
 * candidate, strategy, gate threshold, paper order, or promotion decision.
 */
class MtfPilotMonitoringService
{
    public const PROTOCOL = 'mtf_pilot_monitor_v1';

    public function __construct(
        private PaperEvidenceReadinessService $paperReadiness,
        private SystemLogService $logs,
        private PhaseTwoFoundationService $foundation,
        private MtfStrategyResearchReportService $researchReport,
    ) {}

    /** @return array<string, mixed> */
    public function inspect(?string $symbol = null, ?int $lookbackHours = null): array
    {
        $config = (array) config('services.mtf_pilot', []);
        $symbol = $this->normalizeSymbol($symbol ?: (string) ($config['symbol'] ?? 'XAUUSD'));
        $pilotId = (string) ($config['pilot_id'] ?? 'xauusd_h1_m15_v1');
        $lookbackHours = max(1, $lookbackHours ?? (int) ($config['monitor_lookback_hours'] ?? 24));
        $now = CarbonImmutable::now('UTC');
        $from = $now->subHours($lookbackHours);
        $checks = [];
        $addCheck = static function (array &$target, string $code, string $status, string $message, array $metrics = []): void {
            $target[] = compact('code', 'status', 'message', 'metrics');
        };

        $expectedRegime = strtoupper((string) ($config['regime_timeframe'] ?? 'H1'));
        $expectedEntry = strtoupper((string) ($config['entry_timeframe'] ?? 'M15'));
        $contractOk = (bool) ($config['enabled'] ?? false)
            && $symbol === $this->normalizeSymbol((string) ($config['symbol'] ?? 'XAUUSD'))
            && $expectedRegime === 'H1'
            && $expectedEntry === 'M15'
            && (string) ($config['mode'] ?? '') === 'h1_veto_m15_risk';
        $addCheck(
            $checks,
            'MTF_CONTRACT',
            $contractOk ? 'ok' : 'critical',
            $contractOk
                ? 'XAUUSD H1/M15 closed-context contract is enabled.'
                : 'MTF contract drift or disabled pilot; no promotion decision is allowed.',
            [
                'enabled' => (bool) ($config['enabled'] ?? false),
                'symbol' => $symbol,
                'regime_timeframe' => $expectedRegime,
                'entry_timeframe' => $expectedEntry,
                'mode' => $config['mode'] ?? null,
                'genetic_parent_transfer' => false,
            ],
        );

        $symbolModel = Schema::hasTable('symbols')
            ? Symbol::query()->where('code', $symbol)->first()
            : null;
        $h1 = null;
        $m15 = null;
        if (! $symbolModel || ! Schema::hasTable('candles')) {
            $addCheck($checks, 'CANDLE_SCHEMA', 'critical', 'XAUUSD candle stream is unavailable.', []);
        } else {
            $h1Cutoff = $now->subHour();
            $m15Cutoff = $now->subMinutes(15);
            $h1 = Candle::query()
                ->where('symbol_id', $symbolModel->id)
                ->where('timeframe', 'H1')
                ->where('time', '<=', $h1Cutoff)
                ->latest('time')
                ->first();
            $m15 = Candle::query()
                ->where('symbol_id', $symbolModel->id)
                ->where('timeframe', 'M15')
                ->where('time', '<=', $m15Cutoff)
                ->latest('time')
                ->first();
        }

        $h1OpenAt = $this->timestamp($h1?->time);
        $h1ClosedAt = $h1OpenAt?->addHour();
        $m15OpenAt = $this->timestamp($m15?->time);
        $m15ClosedAt = $m15OpenAt?->addMinutes(15);
        $h1Age = $this->ageSeconds($h1ClosedAt, $now);
        $m15Age = $this->ageSeconds($m15ClosedAt, $now);
        $h1MaxAge = max(1, (int) ($config['max_h1_staleness_seconds'] ?? 7200));
        $m15MaxAge = max(900, (int) ($config['monitor_max_m15_staleness_seconds'] ?? 1800));
        $dataOk = $h1ClosedAt !== null && $m15ClosedAt !== null && $h1Age <= $h1MaxAge && $m15Age <= $m15MaxAge;
        $addCheck(
            $checks,
            'CLOSED_CANDLE_ALIGNMENT',
            $dataOk ? 'ok' : 'critical',
            $dataOk
                ? 'Latest H1 and M15 candles are closed and inside the freshness contract.'
                : 'H1/M15 data is missing, open, or stale; MTF execution must remain WAIT.',
            [
                'h1_open_at' => $h1OpenAt?->toIso8601String(),
                'h1_closed_at' => $h1ClosedAt?->toIso8601String(),
                'h1_age_seconds' => $h1Age,
                'm15_open_at' => $m15OpenAt?->toIso8601String(),
                'm15_closed_at' => $m15ClosedAt?->toIso8601String(),
                'm15_age_seconds' => $m15Age,
                'h1_max_age_seconds' => $h1MaxAge,
                'm15_max_age_seconds' => $m15MaxAge,
            ],
        );

        $this->appendFeedChecks($checks, $symbol, $addCheck);

        $passports = Schema::hasTable('paper_signal_passports')
            ? PaperSignalPassport::query()
                ->where('symbol', $symbol)
                ->where('entry_timeframe', 'M15')
                ->where('m15_decision_at', '>=', $from)
                ->oldest('m15_decision_at')
                ->get()
            : collect();
        $passportStats = $this->passportStats($passports, $now, $config);
        $passportStatus = $passportStats['invalid_count'] > 0 ? 'critical' : ($passports->isEmpty() ? 'warning' : 'ok');
        $addCheck(
            $checks,
            'CLOSED_H1_CONTEXT',
            $passportStatus,
            $passportStats['invalid_count'] > 0
                ? 'At least one MTF passport has missing, stale, future, or inconsistent H1 context.'
                : ($passports->isEmpty() ? 'No official MTF passport has been captured in the lookback window.' : 'Official passports use valid closed H1 context.'),
            $passportStats,
        );

        $vetoRate = $passportStats['total'] > 0
            ? round($passportStats['wait_count'] / $passportStats['total'], 4)
            : null;
        $vetoWarningRate = (float) ($config['monitor_veto_warning_rate'] ?? 0.80);
        $vetoStatus = $passportStats['total'] >= (int) ($config['monitor_min_decisions_for_veto_warning'] ?? 20)
            && $vetoRate !== null && $vetoRate > $vetoWarningRate
            ? 'warning'
            : 'ok';
        $addCheck(
            $checks,
            'RISK_SENTINEL_BEHAVIOR',
            $vetoStatus,
            $vetoStatus === 'warning'
                ? 'Risk Sentinel WAIT/veto rate is unusually high; inspect regime and entry starvation without relaxing gates.'
                : 'Risk Sentinel veto/WAIT behavior is within the monitoring envelope.',
            [
                'decision_counts' => $passportStats['decision_counts'],
                'wait_rate' => $vetoRate,
                'warning_rate' => $vetoWarningRate,
                'minimum_sample' => (int) ($config['monitor_min_decisions_for_veto_warning'] ?? 20),
            ],
        );

        $shadow = $this->shadowStats($symbol, $from);
        $shadowStatus = $shadow['observation_count'] === 0 ? 'warning' : 'ok';
        $addCheck(
            $checks,
            'SHADOW_TWIN',
            $shadowStatus,
            $shadowStatus === 'warning'
                ? 'No executable MTF shadow observation exists in the lookback window.'
                : 'MTF shadow observations are being collected; they remain research-only.',
            $shadow,
        );

        $paper = $this->paperStats($passports);
        $addCheck(
            $checks,
            'PAPER_LIFECYCLE',
            $paper['passport_count'] === 0 ? 'warning' : 'ok',
            $paper['passport_count'] === 0
                ? 'Official MTF paper lifecycle has not started; forward gate remains authoritative.'
                : 'Official MTF paper passport/outcome lifecycle is observable.',
            $paper,
        );

        $ablation = $this->ablationStats($symbol, $config, $now);
        $addCheck(
            $checks,
            'CONTROLLED_ABLATION',
            $ablation['status'],
            $ablation['message'],
            $ablation,
        );

        $research = $this->strategyResearchStats($symbol, $lookbackHours);
        $addCheck(
            $checks,
            'STRATEGY_RESEARCH',
            $research['status'],
            $research['message'],
            $research,
        );

        $readiness = $this->paperReadiness->inspect();
        $critical = collect($checks)->where('status', 'critical')->count();
        $warning = collect($checks)->where('status', 'warning')->count();
        $status = $critical > 0 ? 'critical' : ($warning > 0 ? 'warning' : 'ok');
        $score = max(0, 100 - ($critical * 45) - ($warning * 12));
        $report = [
            'protocol' => self::PROTOCOL,
            'pilot_id' => $pilotId,
            'symbol' => $symbol,
            'checked_at' => $now->toIso8601String(),
            'lookback_hours' => $lookbackHours,
            'status' => $status,
            'health_score' => $score,
            'checks' => $checks,
            'paper_readiness' => $readiness,
            'promotion_evidence' => false,
            'operator_rule' => 'Monitor records and alerts only; it never changes strategy, gates, or paper promotion.',
        ];

        $run = null;
        if (Schema::hasTable('mtf_pilot_monitor_runs')) {
            $run = MtfPilotMonitorRun::create([
                'pilot_id' => $pilotId,
                'symbol' => $symbol,
                'status' => $status,
                'health_score' => $score,
                'lookback_hours' => $lookbackHours,
                'latest_h1_open_at' => $h1OpenAt,
                'latest_h1_closed_at' => $h1ClosedAt,
                'latest_m15_open_at' => $m15OpenAt,
                'latest_m15_closed_at' => $m15ClosedAt,
                'report' => $report,
                'checked_at' => $now,
            ]);
        }

        $this->upsertHealth($pilotId, $symbol, $status, $score, $report, $previousStatus = $this->previousStatus($pilotId, $symbol));
        if ($previousStatus !== $status) {
            $level = $status === 'critical' ? 'critical' : ($status === 'warning' ? 'warning' : 'info');
            $this->logs->write(
                'mtf_pilot_status_changed',
                "MTF pilot {$symbol} status changed to {$status}.",
                ['previous_status' => $previousStatus, 'status' => $status, 'health_score' => $score, 'checks' => $checks],
                $level,
                'mtf_pilot',
                'monitor',
                $status,
                MtfPilotMonitorRun::class,
                $run?->id,
            );
            $this->foundation->recordEvent([
                'event_type' => 'mtf_pilot_status_changed',
                'source_type' => MtfPilotMonitorRun::class,
                'source_id' => $run?->id,
                'symbol' => $symbol,
                'timeframe' => 'M15',
                'severity' => $level,
                'summary' => "MTF pilot {$symbol} status changed to {$status}.",
                'payload' => ['previous_status' => $previousStatus, 'health_score' => $score, 'checks' => $checks],
            ]);
        }

        return $report + ['monitor_run_id' => $run?->id];
    }

    /** @param callable(array, string, string, string, array): void $addCheck */
    private function appendFeedChecks(array &$checks, string $symbol, callable $addCheck): void
    {
        if (! Schema::hasTable('market_data_sync_states')) {
            $addCheck($checks, 'FEED_STATE', 'warning', 'Per-stream feed state is unavailable; candle freshness is the fallback.', []);
            return;
        }

        $states = MarketDataSyncState::query()
            ->where('symbol', $symbol)
            ->whereIn('timeframe', ['H1', 'M15'])
            ->get()
            ->keyBy(fn (MarketDataSyncState $state): string => strtoupper($state->timeframe));
        $bad = $states->filter(fn (MarketDataSyncState $state): bool => in_array(strtolower((string) $state->status), ['stale', 'lost', 'failed'], true));
        $missing = collect(['H1', 'M15'])->diff($states->keys())->values()->all();
        $status = $bad->contains(fn (MarketDataSyncState $state): bool => strtolower((string) $state->status) === 'lost') ? 'critical' : ($bad->isNotEmpty() || $missing !== [] ? 'warning' : 'ok');
        $addCheck(
            $checks,
            'FEED_STATE',
            $status,
            $status === 'ok' ? 'H1 and M15 feed states are healthy.' : 'One or more H1/M15 feed states are stale, lost, or missing.',
            [
                'states' => $states->map(fn (MarketDataSyncState $state): array => [
                    'status' => $state->status,
                    'last_confirmed_candle_at' => $state->last_confirmed_candle_at?->toIso8601String(),
                    'last_error' => $state->last_error,
                ])->all(),
                'missing_timeframes' => $missing,
            ],
        );
    }

    /** @return array<string, mixed> */
    private function passportStats($passports, CarbonImmutable $now, array $config): array
    {
        $maxStale = max(1, (int) ($config['max_h1_staleness_seconds'] ?? 7200));
        $invalid = 0;
        $missingHash = 0;
        $futureContext = 0;
        $staleContext = 0;
        foreach ($passports as $passport) {
            $closed = $this->timestamp($passport->h1_closed_at);
            $decision = $this->timestamp($passport->m15_decision_at);
            if (! filled($passport->h1_context_hash)) $missingHash++;
            if (! $closed || ! $decision) {
                $invalid++;
                continue;
            }
            $ageAtDecision = $decision->timestamp - $closed->timestamp;
            if ($ageAtDecision < 0) {
                $futureContext++;
                $invalid++;
            } elseif ($ageAtDecision > $maxStale) {
                $staleContext++;
                $invalid++;
            }
        }
        $invalid += $missingHash;
        $decisionCounts = $passports->groupBy(fn ($passport): string => strtoupper((string) ($passport->mtf_decision ?: 'UNKNOWN')))
            ->map->count()->all();
        $contextVariants = $passports
            ->groupBy(fn ($passport): string => $this->timestamp($passport->h1_closed_at)?->toIso8601String() ?: 'missing')
            ->map(fn ($rows): int => $rows->pluck('h1_context_hash')->filter()->unique()->count())
            ->filter(fn (int $count): bool => $count > 1)
            ->all();
        if ($contextVariants !== []) $invalid++;

        return [
            'total' => $passports->count(),
            'invalid_count' => $invalid,
            'missing_hash_count' => $missingHash,
            'future_context_count' => $futureContext,
            'stale_context_count' => $staleContext,
            'context_hash_variants_by_h1_close' => $contextVariants,
            'decision_counts' => $decisionCounts,
            'wait_count' => (int) ($decisionCounts['WAIT'] ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    private function shadowStats(string $symbol, CarbonImmutable $from): array
    {
        if (! Schema::hasTable('paper_mtf_shadow_observations')) {
            return ['observation_count' => 0, 'outcome_count' => 0, 'status' => 'missing'];
        }
        $rows = PaperMtfShadowObservation::query()
            ->where('symbol', $symbol)
            ->where('timeframe', 'M15')
            ->where('observed_at', '>=', $from)
            ->oldest('candle_time')
            ->get();
        $outcomes = $rows->filter(fn ($row): bool => $row->outcome !== null && $row->profit_percent !== null);
        return [
            'observation_count' => $rows->count(),
            'executable_count' => $rows->whereIn('decision', ['BUY', 'SELL'])->count(),
            'outcome_count' => $outcomes->count(),
            'pending_count' => $rows->whereNull('outcome')->whereIn('decision', ['BUY', 'SELL'])->count(),
            'decision_counts' => $rows->groupBy('decision')->map->count()->all(),
            ...$this->performanceMetrics($outcomes->pluck('profit_percent')->map(fn ($value): float => (float) $value)->all()),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function paperStats($passports): array
    {
        $outcomes = $passports
            ->loadMissing('signal.outcome')
            ->map(fn ($passport) => $passport->signal?->outcome)
            ->filter();
        return [
            'passport_count' => $passports->count(),
            'outcome_count' => $outcomes->count(),
            ...$this->performanceMetrics($outcomes->pluck('profit_percent')->map(fn ($value): float => (float) $value)->all()),
        ];
    }

    /** @return array<string, mixed> */
    private function ablationStats(string $symbol, array $config, CarbonImmutable $now): array
    {
        if (! Schema::hasTable('mtf_ablation_runs')) {
            return ['status' => 'warning', 'message' => 'MTF ablation history table is unavailable.', 'run_id' => null];
        }
        $run = MtfAblationRun::query()
            ->where('symbol', $symbol)
            ->where('entry_timeframe', 'M15')
            ->latest('completed_at')
            ->first();
        $staleAfter = max(1, (int) ($config['monitor_ablation_stale_hours'] ?? 36));
        if (! $run || ! $run->completed_at || $run->completed_at->lt($now->subHours($staleAfter))) {
            return [
                'status' => 'warning',
                'message' => 'Controlled four-lane ablation is missing or stale; run it before interpreting MTF progress.',
                'run_id' => $run?->id,
                'stale_after_hours' => $staleAfter,
                'completed_at' => $run?->completed_at?->toIso8601String(),
                'promotion_evidence' => false,
            ];
        }
        $variants = (array) $run->variants;
        $h1 = (array) ($variants['h1_only'] ?? []);
        $m15 = (array) ($variants['m15_only'] ?? []);
        $official = (array) ($variants['h1_veto_m15_risk'] ?? []);
        $required = $h1 !== [] && $m15 !== [] && $official !== [];
        $officialPf = (float) ($official['profit_factor'] ?? 0);
        $h1Pf = (float) ($h1['profit_factor'] ?? 0);
        $m15Pf = (float) ($m15['profit_factor'] ?? 0);
        $officialDd = (float) ($official['max_drawdown_percent'] ?? 100);
        $m15Dd = (float) ($m15['max_drawdown_percent'] ?? 100);
        $edge = $required && $officialPf > $h1Pf && ($officialPf >= $m15Pf || $officialDd <= $m15Dd);
        return [
            'status' => $required && $edge ? 'ok' : 'warning',
            'message' => $required && $edge
                ? 'Latest costed ablation shows the H1-veto/risk lane with a measured control advantage.'
                : 'Latest ablation does not yet demonstrate the required control advantage; keep MTF research-only.',
            'run_id' => $run->id,
            'candidate_id' => $run->model_market_performance_id,
            'completed_at' => $run->completed_at?->toIso8601String(),
            'data_hash' => $run->data_hash,
            'execution_hash' => $run->execution_hash,
            'required_variants_present' => $required,
            'control_advantage' => $edge,
            'h1_only' => ['profit_factor' => $h1Pf, 'max_drawdown_percent' => (float) ($h1['max_drawdown_percent'] ?? 100)],
            'm15_only' => ['profit_factor' => $m15Pf, 'max_drawdown_percent' => $m15Dd],
            'h1_veto_m15_risk' => ['profit_factor' => $officialPf, 'max_drawdown_percent' => $officialDd],
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function strategyResearchStats(string $symbol, int $lookbackHours): array
    {
        if (! Schema::hasTable('mtf_strategy_research_runs')) {
            return [
                'status' => 'warning',
                'message' => 'Bounded MTF strategy research history table is unavailable.',
                'run_count' => 0,
                'current_cohort_run_count' => 0,
                'promotion_evidence' => false,
            ];
        }

        $report = $this->researchReport->report($symbol, $lookbackHours * 30);
        $currentCount = (int) ($report['current_cohort_run_count'] ?? 0);
        $technical = collect((array) ($report['runs'] ?? []))
            ->filter(fn (array $run): bool => ($run['data_hash'] ?? null) === ($report['current_cohort_data_hash'] ?? null))
            ->where('status', '!=', 'completed')
            ->count();
        $paused = collect((array) ($report['family_budget'] ?? []))
            ->filter(fn (array $budget): bool => ($budget['status'] ?? null) === 'pause_research_family')
            ->keys()
            ->values()
            ->all();
        $status = $currentCount === 0 || $technical > 0 || $paused !== [] ? 'warning' : 'ok';
        $message = $currentCount === 0
            ? 'No completed bounded MTF strategy research exists for the current data cohort.'
            : ($technical > 0
                ? 'Current MTF strategy cohort has technical recovery rows; they are excluded from learning.'
                : ($paused !== []
                    ? 'Evidence budget paused one or more MTF families in the report; architecture review is required.'
                    : 'Current MTF strategy research cohort is complete and auditable.'));

        return [
            'status' => $status,
            'message' => $message,
            'run_count' => (int) ($report['run_count'] ?? 0),
            'current_cohort_data_hash' => $report['current_cohort_data_hash'] ?? null,
            'current_cohort_run_count' => $currentCount,
            'technical_recovery_count' => $technical,
            'paused_families' => $paused,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, float|int> */
    private function performanceMetrics(array $profits): array
    {
        $grossProfit = array_sum(array_filter($profits, fn (float $value): bool => $value > 0));
        $grossLoss = abs(array_sum(array_filter($profits, fn (float $value): bool => $value <= 0)));
        $balance = 10000.0;
        $peak = $balance;
        $drawdown = 0.0;
        foreach ($profits as $profit) {
            $balance *= 1 + $profit / 100;
            $peak = max($peak, $balance);
            $drawdown = max($drawdown, $peak > 0 ? (($peak - $balance) / $peak) * 100 : 100);
        }
        return [
            'total_trades' => count($profits),
            'profit_factor' => round($grossLoss > 0 ? $grossProfit / $grossLoss : ($grossProfit > 0 ? 99.0 : 0.0), 4),
            'net_profit_percent' => round(($balance - 10000) / 100, 4),
            'max_drawdown_percent' => round($drawdown, 4),
        ];
    }

    private function upsertHealth(string $pilotId, string $symbol, string $status, float $score, array $report, ?string $previousStatus): void
    {
        if (! Schema::hasTable('service_health_checks')) return;
        ServiceHealthCheck::updateOrCreate(
            ['service_key' => "mtf_pilot:{$symbol}"],
            [
                'service_label' => "MTF Pilot {$symbol} H1/M15",
                'status' => $status,
                'health_score' => $score,
                'last_ok_at' => $status === 'ok' ? now() : ServiceHealthCheck::query()->where('service_key', "mtf_pilot:{$symbol}")->value('last_ok_at'),
                'last_checked_at' => now(),
                'stale_after_seconds' => 1200,
                'message' => (string) data_get(collect($report['checks'])->firstWhere('status', $status), 'message', "MTF pilot status: {$status}."),
                'metrics' => $report,
            ],
        );
    }

    private function previousStatus(string $pilotId, string $symbol): ?string
    {
        if (! Schema::hasTable('service_health_checks')) return null;
        return ServiceHealthCheck::query()->where('service_key', "mtf_pilot:{$symbol}")->value('status');
    }

    private function timestamp($value): ?CarbonImmutable
    {
        return $value ? CarbonImmutable::parse((string) $value)->utc() : null;
    }

    private function ageSeconds(?CarbonImmutable $closedAt, CarbonImmutable $now): ?int
    {
        return $closedAt ? max(0, $now->timestamp - $closedAt->timestamp) : null;
    }

    private function normalizeSymbol(string $symbol): string
    {
        return strtoupper(str_replace(['/', '_', '-'], '', trim($symbol)));
    }
}
