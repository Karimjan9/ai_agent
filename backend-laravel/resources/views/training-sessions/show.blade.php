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
        $profitData = $chartScores->pluck('net_profit_percent')->values();
        $drawdownData = $chartScores->pluck('max_drawdown_percent')->values();
        $stabilityData = $chartScores->pluck('stability_score')->values();
        $winsData = $chartScores->pluck('wins')->values();
        $lossesData = $chartScores->pluck('losses')->values();
        $bestScore = $chartScores->sortByDesc('score')->first();
        $equityCurve = $bestScore?->equity_curve ?? [];
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
            <h2 class="section-title">Profit vs Drawdown</h2>
            <canvas id="profitDrawdownChart" height="160"></canvas>
        </article>
    </section>

    <section class="split">
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
                <tr>
                    <td>{{ strtoupper($score->strategy) }}</td>
                    <td>
                        <details>
                            <summary style="cursor:pointer; color: var(--blue); font-weight: 800;">Korish</summary>
                            <pre class="code" style="min-height: auto; margin-top: 8px;">{{ json_encode($score->parameters ?? $score->raw_result['parameters'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </details>
                    </td>
                    <td>{{ $score->score }}</td>
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
            const profitData = @json($profitData);
            const drawdownData = @json($drawdownData);
            const stabilityData = @json($stabilityData);
            const winsData = @json($winsData);
            const lossesData = @json($lossesData);
            const equityCurve = @json($equityCurve);
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
        </script>
    @endpush
@endsection
