@extends('layouts.app', [
    'heading' => 'Training Session #' . $trainingSession->id,
    'subtitle' => $trainingSession->symbol . ' · ' . $trainingSession->timeframe . ' agent training tafsilotlari.',
])

@section('content')
    <section class="grid metrics">
        <article class="card tone-green">
            <div class="metric-label">Best Agent</div>
            <div class="metric-value">{{ strtoupper($trainingSession->best_strategy ?? '-') }}</div>
        </article>
        <article class="card tone-red">
            <div class="metric-label">Worst Agent</div>
            <div class="metric-value">{{ strtoupper($trainingSession->worst_strategy ?? '-') }}</div>
        </article>
        <article class="card tone-blue">
            <div class="metric-label">Agents</div>
            <div class="metric-value">{{ $trainingSession->agents_count }}</div>
        </article>
        <article class="card tone-yellow">
            <div class="metric-label">Total Trades</div>
            <div class="metric-value">{{ $trainingSession->total_trades }}</div>
        </article>
        <article class="card tone-green">
            <div class="metric-label">Avg Winrate</div>
            <div class="metric-value">{{ $trainingSession->average_winrate }}%</div>
        </article>
        <article class="card tone-blue">
            <div class="metric-label">Avg Profit</div>
            <div class="metric-value">{{ $trainingSession->average_profit }}%</div>
        </article>
        <article class="card tone-red">
            <div class="metric-label">Avg Drawdown</div>
            <div class="metric-value">{{ $trainingSession->average_drawdown ?? 0 }}%</div>
        </article>
        <article class="card tone-yellow">
            <div class="metric-label">Avg Profit Factor</div>
            <div class="metric-value">{{ $trainingSession->average_profit_factor ?? 0 }}</div>
        </article>
        <article class="card tone-green">
            <div class="metric-label">Avg Stability</div>
            <div class="metric-value">{{ $trainingSession->average_stability_score ?? 0 }}</div>
        </article>
    </section>

    <section class="split">
        <article class="card">
            <h2 class="section-title">AI xulosasi</h2>
            <p class="muted">{{ $trainingSession->ai_conclusion }}</p>
        </article>
        <article class="card">
            <h2 class="section-title">Keyingi trening rejasi</h2>
            <p class="muted">{{ $trainingSession->next_training_plan }}</p>
        </article>
    </section>

    @php
        $chartScores = $trainingSession->strategyScores;
        $labels = $chartScores->pluck('strategy')->map(fn ($strategy) => strtoupper($strategy))->values();
        $scoreData = $chartScores->pluck('score')->values();
        $trainScoreData = $chartScores->pluck('train_score')->values();
        $validationScoreData = $chartScores->pluck('validation_score')->values();
        $forwardScoreData = $chartScores->pluck('forward_score')->values();
        $robustnessScoreData = $chartScores->pluck('robustness_score')->values();
        $profitData = $chartScores->pluck('net_profit_percent')->values();
        $drawdownData = $chartScores->pluck('max_drawdown_percent')->values();
        $stabilityData = $chartScores->pluck('stability_score')->values();
        $winsData = $chartScores->pluck('wins')->values();
        $lossesData = $chartScores->pluck('losses')->values();
        $bestScore = $chartScores->sortByDesc('score')->first();
        $bestDnaProfile = $bestScore?->dnaProfile;
        $bestDnaData = $bestDnaProfile ? [
            (float) $bestDnaProfile->aggression_score,
            (float) $bestDnaProfile->trend_dependency,
            (float) $bestDnaProfile->range_dependency,
            (float) $bestDnaProfile->volatility_sensitivity,
            (float) $bestDnaProfile->adaptability_score,
            (float) $bestDnaProfile->recovery_score,
            (float) $bestDnaProfile->survival_score,
        ] : [];
        $equityCurve = $bestScore?->equity_curve ?? [];
        $bestMcWorstCurve = $bestScore?->mc_worst_equity_curve ?? [];
        $bestMcBestCurve = $bestScore?->mc_best_equity_curve ?? [];
        $bestRegimePerformance = $bestScore?->regime_performance ?? [];
        $regimeLabels = collect($bestRegimePerformance)->keys()->values();
        $regimeProfitData = collect($bestRegimePerformance)->map(fn ($item) => $item['profit_percent'] ?? 0)->values();
        $regimeWinrateData = collect($bestRegimePerformance)->map(fn ($item) => $item['winrate'] ?? 0)->values();
    @endphp

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Equity Curve</h2>
        @if (! empty($equityCurve))
            <canvas id="equityCurveChart" height="100"></canvas>
        @else
            <p class="muted">Bu session uchun equity curve ma'lumoti yo'q.</p>
        @endif
    </article>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Agent Score Comparison</h2>
            <canvas id="scoreChart" height="160"></canvas>
        </article>
        <article class="card">
            <h2 class="section-title">Walk Forward Validation</h2>
            <canvas id="walkForwardChart" height="160"></canvas>
        </article>
    </section>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Profit vs Drawdown</h2>
            <canvas id="profitDrawdownChart" height="160"></canvas>
        </article>
        <article class="card">
            <h2 class="section-title">Win / Loss Distribution</h2>
            <canvas id="winLossChart" height="160"></canvas>
        </article>
        <article class="card">
            <h2 class="section-title">Stability Score</h2>
            <canvas id="stabilityChart" height="160"></canvas>
        </article>
    </section>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Best Agent Regime Profit</h2>
        @if (! empty($bestRegimePerformance))
            <canvas id="regimeProfitChart" height="140"></canvas>
        @else
            <p class="muted">Bu session uchun market regime analytics hali mavjud emas.</p>
        @endif
    </article>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Monte Carlo Survival Test</h2>
        @if (! empty($bestMcWorstCurve) || ! empty($bestMcBestCurve))
            <canvas id="mcWorstBestChart" height="120"></canvas>
        @else
            <p class="muted">Bu session uchun Monte Carlo equity path ma'lumoti yo'q.</p>
        @endif
    </article>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Strategy DNA Radar</h2>
            @if ($bestDnaProfile)
                <canvas id="strategyDnaChart" height="170"></canvas>
            @else
                <p class="muted">Bu session uchun Strategy DNA profile hali mavjud emas.</p>
            @endif
        </article>
        <article class="card">
            <h2 class="section-title">Best Agent DNA</h2>
            @if ($bestDnaProfile)
                <section class="grid metrics" style="grid-template-columns: repeat(2, minmax(0, 1fr));">
                    <div>
                        <div class="metric-label">Aggression</div>
                        <div class="metric-value">{{ $bestDnaProfile->aggression_score }}</div>
                    </div>
                    <div>
                        <div class="metric-label">Adaptability</div>
                        <div class="metric-value">{{ $bestDnaProfile->adaptability_score }}</div>
                    </div>
                    <div>
                        <div class="metric-label">Recovery</div>
                        <div class="metric-value">{{ $bestDnaProfile->recovery_score }}</div>
                    </div>
                    <div>
                        <div class="metric-label">Survival</div>
                        <div class="metric-value">{{ $bestDnaProfile->survival_score }}</div>
                    </div>
                </section>
                <p class="muted" style="margin-top: 12px;">{{ $bestDnaProfile->dna_summary }}</p>
            @else
                <p class="muted">DNA summary mavjud emas.</p>
            @endif
        </article>
    </section>

    <section class="grid metrics">
        @foreach ($trainingSession->strategyScores as $score)
            @php
                $ruin = (float) ($score->mc_risk_of_ruin_percent ?? 0);
                if ($ruin <= 5) {
                    $riskGrade = 'LOW';
                    $riskTone = 'tone-green';
                } elseif ($ruin <= 15) {
                    $riskGrade = 'MEDIUM';
                    $riskTone = 'tone-yellow';
                } else {
                    $riskGrade = 'HIGH';
                    $riskTone = 'tone-red';
                }
            @endphp
            <article class="card {{ $riskTone }}">
                <div class="metric-label">{{ strtoupper($score->strategy) }}</div>
                <div class="metric-value">{{ $riskGrade }}</div>
                <p class="muted" style="margin-top: 8px;">
                    Worst {{ $score->mc_worst_profit_percent ?? 0 }}% /
                    Avg {{ $score->mc_avg_profit_percent ?? 0 }}% /
                    Best {{ $score->mc_best_profit_percent ?? 0 }}%
                </p>
                <p class="muted">
                    Worst DD {{ $score->mc_worst_drawdown_percent ?? 0 }}% ·
                    Ruin {{ $score->mc_risk_of_ruin_percent ?? 0 }}%
                </p>
            </article>
        @endforeach
    </section>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Market Regime Performance</h2>

        @foreach ($trainingSession->strategyScores as $score)
            <div style="border-top: 1px solid var(--border); padding-top: 14px; margin-top: 14px;">
                <h3 style="font-size: 14px; margin-bottom: 10px;">{{ strtoupper($score->strategy) }}</h3>

                @if (! empty($score->regime_performance))
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Regime</th>
                            <th>Trades</th>
                            <th>Wins</th>
                            <th>Losses</th>
                            <th>Winrate</th>
                            <th>Profit</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($score->regime_performance as $regime => $data)
                            <tr>
                                <td>{{ $regime }}</td>
                                <td>{{ $data['trades'] ?? 0 }}</td>
                                <td>{{ $data['wins'] ?? 0 }}</td>
                                <td>{{ $data['losses'] ?? 0 }}</td>
                                <td>{{ $data['winrate'] ?? 0 }}%</td>
                                <td>{{ $data['profit_percent'] ?? 0 }}%</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="muted">Regime analytics mavjud emas.</p>
                @endif
            </div>
        @endforeach
    </article>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Session Leaderboard</h2>
        <table class="table">
            <thead>
            <tr>
                <th>Strategy</th>
                <th>Parameters</th>
                <th>Score</th>
                <th>Train</th>
                <th>Valid</th>
                <th>Forward</th>
                <th>Robust</th>
                <th>Status</th>
                <th>MC Worst</th>
                <th>MC Avg</th>
                <th>MC Best</th>
                <th>Ruin Risk</th>
                <th>Risk Grade</th>
                <th>Trades</th>
                <th>Wins</th>
                <th>Losses</th>
                <th>Winrate</th>
                <th>Profit</th>
                <th>Drawdown</th>
                <th>PF</th>
                <th>Avg Win</th>
                <th>Avg Loss</th>
                <th>R/R</th>
                <th>Loss Streak</th>
                <th>Stability</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($trainingSession->strategyScores as $score)
                @php
                    $ruin = (float) ($score->mc_risk_of_ruin_percent ?? 0);
                    if ($ruin <= 5) {
                        $riskGrade = 'LOW';
                        $riskTone = 'tone-green';
                    } elseif ($ruin <= 15) {
                        $riskGrade = 'MEDIUM';
                        $riskTone = 'tone-yellow';
                    } else {
                        $riskGrade = 'HIGH';
                        $riskTone = 'tone-red';
                    }
                @endphp
                <tr>
                    <td>{{ strtoupper($score->strategy) }}</td>
                    <td>
                        <details>
                            <summary style="cursor:pointer; color: var(--blue); font-weight: 800;">Korish</summary>
                            <pre class="code" style="min-height: auto; margin-top: 8px;">{{ json_encode($score->parameters ?? $score->raw_result['parameters'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </details>
                    </td>
                    <td>{{ $score->score }}</td>
                    <td>{{ $score->train_score ?? '-' }}</td>
                    <td>{{ $score->validation_score ?? '-' }}</td>
                    <td>{{ $score->forward_score ?? '-' }}</td>
                    <td>{{ $score->robustness_score ?? '-' }}</td>
                    <td>
                        <span class="{{ $score->is_overfit ? 'tone-red' : 'tone-green' }}" style="display:inline-block; border-radius:8px; padding:4px 8px;">
                            {{ $score->is_overfit ? 'OVERFIT' : 'OK' }}
                        </span>
                    </td>
                    <td>{{ $score->mc_worst_profit_percent ?? '-' }}%</td>
                    <td>{{ $score->mc_avg_profit_percent ?? '-' }}%</td>
                    <td>{{ $score->mc_best_profit_percent ?? '-' }}%</td>
                    <td>{{ $score->mc_risk_of_ruin_percent ?? '-' }}%</td>
                    <td>
                        <span class="{{ $riskTone }}" style="display:inline-block; border-radius:8px; padding:4px 8px;">
                            {{ $riskGrade }}
                        </span>
                    </td>
                    <td>{{ $score->total_trades }}</td>
                    <td>{{ $score->wins }}</td>
                    <td>{{ $score->losses }}</td>
                    <td>{{ $score->winrate }}%</td>
                    <td>{{ $score->net_profit_percent }}%</td>
                    <td>{{ $score->max_drawdown_percent }}%</td>
                    <td>{{ $score->profit_factor }}</td>
                    <td>{{ $score->average_win_percent }}%</td>
                    <td>{{ $score->average_loss_percent }}%</td>
                    <td>{{ $score->risk_reward_ratio }}</td>
                    <td>{{ $score->max_consecutive_losses }}</td>
                    <td>{{ $score->stability_score }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </article>

    @push('scripts')
        <script>
            const labels = @json($labels);
            const scoreData = @json($scoreData);
            const trainScoreData = @json($trainScoreData);
            const validationScoreData = @json($validationScoreData);
            const forwardScoreData = @json($forwardScoreData);
            const robustnessScoreData = @json($robustnessScoreData);
            const profitData = @json($profitData);
            const drawdownData = @json($drawdownData);
            const stabilityData = @json($stabilityData);
            const winsData = @json($winsData);
            const lossesData = @json($lossesData);
            const bestDnaData = @json($bestDnaData);
            const equityCurve = @json($equityCurve);
            const bestMcWorstCurve = @json($bestMcWorstCurve);
            const bestMcBestCurve = @json($bestMcBestCurve);
            const regimeLabels = @json($regimeLabels);
            const regimeProfitData = @json($regimeProfitData);
            const regimeWinrateData = @json($regimeWinrateData);

            const equityCanvas = document.getElementById('equityCurveChart');
            if (equityCanvas && equityCurve.length > 0) {
                new Chart(equityCanvas, {
                    type: 'line',
                    data: {
                        labels: equityCurve.map((_, index) => index),
                        datasets: [{
                            label: 'Best Agent Equity Curve',
                            data: equityCurve,
                            tension: 0.3
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: false
                            }
                        }
                    }
                });
            }

            const scoreCanvas = document.getElementById('scoreChart');
            if (scoreCanvas) {
                new Chart(scoreCanvas, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Score',
                            data: scoreData
                        }]
                    },
                    options: {
                        responsive: true,
                        indexAxis: 'y',
                        scales: {
                            x: {
                                beginAtZero: true,
                                max: 100
                            }
                        }
                    }
                });
            }

            const profitDrawdownCanvas = document.getElementById('profitDrawdownChart');
            if (profitDrawdownCanvas) {
                new Chart(profitDrawdownCanvas, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [
                            {
                                label: 'Profit %',
                                data: profitData
                            },
                            {
                                label: 'Drawdown %',
                                data: drawdownData
                            }
                        ]
                    },
                    options: {
                        responsive: true
                    }
                });
            }

            const walkForwardCanvas = document.getElementById('walkForwardChart');
            if (walkForwardCanvas) {
                new Chart(walkForwardCanvas, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [
                            {
                                label: 'Train',
                                data: trainScoreData
                            },
                            {
                                label: 'Validation',
                                data: validationScoreData
                            },
                            {
                                label: 'Forward',
                                data: forwardScoreData
                            },
                            {
                                label: 'Robust',
                                data: robustnessScoreData
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 100
                            }
                        }
                    }
                });
            }

            const winLossCanvas = document.getElementById('winLossChart');
            if (winLossCanvas) {
                new Chart(winLossCanvas, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [
                            {
                                label: 'Wins',
                                data: winsData
                            },
                            {
                                label: 'Losses',
                                data: lossesData
                            }
                        ]
                    },
                    options: {
                        responsive: true
                    }
                });
            }

            const stabilityCanvas = document.getElementById('stabilityChart');
            if (stabilityCanvas) {
                new Chart(stabilityCanvas, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Stability Score',
                            data: stabilityData
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 100
                            }
                        }
                    }
                });
            }

            const regimeProfitCanvas = document.getElementById('regimeProfitChart');
            if (regimeProfitCanvas && regimeLabels.length > 0) {
                new Chart(regimeProfitCanvas, {
                    type: 'bar',
                    data: {
                        labels: regimeLabels,
                        datasets: [
                            {
                                label: 'Profit %',
                                data: regimeProfitData
                            },
                            {
                                label: 'Winrate %',
                                data: regimeWinrateData
                            }
                        ]
                    },
                    options: {
                        responsive: true
                    }
                });
            }

            const mcWorstBestCanvas = document.getElementById('mcWorstBestChart');
            if (mcWorstBestCanvas && (bestMcWorstCurve.length > 0 || bestMcBestCurve.length > 0)) {
                const mcLength = Math.max(bestMcWorstCurve.length, bestMcBestCurve.length);
                new Chart(mcWorstBestCanvas, {
                    type: 'line',
                    data: {
                        labels: Array.from({length: mcLength}, (_, index) => index + 1),
                        datasets: [
                            {
                                label: 'Worst Path',
                                data: bestMcWorstCurve,
                                borderWidth: 2,
                                tension: 0.25
                            },
                            {
                                label: 'Best Path',
                                data: bestMcBestCurve,
                                borderWidth: 2,
                                tension: 0.25
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: false
                            }
                        }
                    }
                });
            }

            const strategyDnaCanvas = document.getElementById('strategyDnaChart');
            if (strategyDnaCanvas && bestDnaData.length > 0) {
                new Chart(strategyDnaCanvas, {
                    type: 'radar',
                    data: {
                        labels: [
                            'Aggression',
                            'Trend',
                            'Range',
                            'Volatility',
                            'Adaptability',
                            'Recovery',
                            'Survival'
                        ],
                        datasets: [{
                            label: 'DNA',
                            data: bestDnaData,
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            r: {
                                beginAtZero: true,
                                max: 100
                            }
                        }
                    }
                });
            }
        </script>
    @endpush
@endsection
