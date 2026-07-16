@extends('layouts.app', [
    'heading' => 'Future Intelligence',
    'subtitle' => 'Knowledge Graph va Market Genome asosida ehtimoliy market futures, scenario survival va planning bias.',
])

@section('content')
    <section class="grid metrics">
        <article class="card tone-blue">
            <div class="metric-label">Simulation Runs</div>
            <div class="metric-value">{{ $metrics['runs'] }}</div>
        </article>
        <article class="card tone-green">
            <div class="metric-label">Scenarios</div>
            <div class="metric-value">{{ $metrics['scenarios'] }}</div>
        </article>
        <article class="card tone-yellow">
            <div class="metric-label">Survival Forecasts</div>
            <div class="metric-value">{{ $metrics['survival_forecasts'] }}</div>
        </article>
        <article class="card tone-red">
            <div class="metric-label">Stress Tests</div>
            <div class="metric-value">{{ $metrics['stress_tests'] }}</div>
        </article>
        <article class="card tone-green">
            <div class="metric-label">Discoveries</div>
            <div class="metric-value">{{ $metrics['discoveries'] }}</div>
        </article>
        <article class="card tone-blue">
            <div class="metric-label">Future Bias</div>
            <div class="metric-value">{{ $latestRun?->planning_bias ?? '-' }}</div>
        </article>
    </section>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Scenario Lab</h2>
        <form class="form-grid" method="POST" action="{{ route('future-intelligence.simulate') }}">
            @csrf
            <label>
                Symbol
                <input name="symbol" value="{{ $latestRun?->symbol ?? 'XAUUSD' }}">
            </label>
            <label>
                Timeframe
                <input name="timeframe" value="{{ $latestRun?->timeframe ?? 'H1' }}">
            </label>
            <label>
                Scenarios
                <input name="scenario_count" type="number" min="100" max="10000" value="{{ $latestRun?->scenario_count ?? 1000 }}">
            </label>
            <button type="submit">Run Simulation</button>
        </form>
        @if ($latestRun)
            <p class="muted" style="margin-top: 12px;">{{ $latestRun->summary }}</p>
        @else
            <p class="muted" style="margin-top: 12px;">Hali future simulation yo'q.</p>
        @endif
    </article>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Future Map</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Scenario</th>
                    <th>Probability</th>
                    <th>Risk</th>
                    <th>Expected</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($scenarios as $scenario)
                    <tr>
                        <td>{{ $scenario->scenario_label }}</td>
                        <td>{{ round($scenario->probability * 100, 2) }}%</td>
                        <td>{{ $scenario->risk_score }}</td>
                        <td>{{ $scenario->expected_return }}%</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">Hali scenario yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Probability Tree</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Node</th>
                    <th>Parent</th>
                    <th>Probability</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($probabilityTree as $node)
                    <tr>
                        <td>{{ $node->label }}</td>
                        <td>{{ $node->parent?->label ?? '-' }}</td>
                        <td>{{ round($node->probability * 100, 2) }}%</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">Hali probability tree yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Timeline Forecast</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Horizon</th>
                    <th>Bull</th>
                    <th>Range</th>
                    <th>Panic</th>
                    <th>Reversal</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($timeline as $forecast)
                    <tr>
                        <td>{{ $forecast->horizon_candles }} candles</td>
                        <td>{{ round($forecast->bull_probability * 100, 2) }}%</td>
                        <td>{{ round($forecast->range_probability * 100, 2) }}%</td>
                        <td>{{ round($forecast->panic_probability * 100, 2) }}%</td>
                        <td>{{ round($forecast->reversal_probability * 100, 2) }}%</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">Hali timeline forecast yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Survival Forecast</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Strategy</th>
                    <th>Survival</th>
                    <th>Future Conf.</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($survivalForecasts as $forecast)
                    <tr>
                        <td>{{ strtoupper($forecast->strategy) }}</td>
                        <td>{{ round($forecast->survival_probability * 100, 2) }}%</td>
                        <td>{{ $forecast->future_confidence }}%</td>
                        <td>{{ $forecast->recommended_action }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">Hali survival forecast yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Future Stress Tests</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Stress</th>
                    <th>Impact</th>
                    <th>Survival</th>
                    <th>Risk</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($stressTests as $test)
                    <tr>
                        <td>{{ $test->stress_label }}</td>
                        <td>{{ $test->impact_score }}</td>
                        <td>{{ round($test->survival_rate * 100, 2) }}%</td>
                        <td>{{ $test->risk_level }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">Hali stress test yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Market Futures Discoveries</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Discovery</th>
                    <th>Confidence</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($discoveries as $discovery)
                    <tr>
                        <td>{{ $discovery->title }}</td>
                        <td>{{ $discovery->confidence_score }}%</td>
                        <td>{{ $discovery->status }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">Hali future discovery yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>
@endsection
