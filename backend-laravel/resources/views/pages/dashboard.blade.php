@extends('layouts.app', [
    'heading' => 'Dashboard',
    'subtitle' => 'Bugungi strategy training, backtest natijalari va AI xulosalarining qisqa holati.',
])

@section('content')
    <section class="grid metrics">
        @foreach ($metrics as $metric)
            <article class="card tone-{{ $metric['tone'] }}">
                <div class="metric-label">{{ $metric['label'] }}</div>
                <div class="metric-value">{{ $metric['value'] }}</div>
            </article>
        @endforeach
    </section>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Oxirgi xulosa</h2>
            <p class="muted">
                Period: {{ $backtestSummary['period'] }}
            </p>
            <p class="muted">{{ $latestConclusion }}</p>
        </article>
        <article class="card">
            <h2 class="section-title">MVP pipeline</h2>
            <p class="muted">Market data -> LabAgent -> EvaluationRun -> Evidence -> Champion/Paper -> Daily report</p>
        </article>
    </section>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Bugungi AI Training Report</h2>
        @if ($latestDailyReport)
            <table class="table">
                <tbody>
                <tr>
                    <th>Strategiya</th>
                    <td>{{ $latestDailyReport->strategy ?? '-' }}</td>
                </tr>
                <tr>
                    <th>Symbol</th>
                    <td>{{ $latestDailyReport->symbol ?? '-' }}</td>
                </tr>
                <tr>
                    <th>Timeframe</th>
                    <td>{{ $latestDailyReport->timeframe ?? '-' }}</td>
                </tr>
                <tr>
                    <th>Total trades</th>
                    <td>{{ $latestDailyReport->total_trades }}</td>
                </tr>
                <tr>
                    <th>Wins / Losses</th>
                    <td>{{ $latestDailyReport->total_wins }} / {{ $latestDailyReport->total_losses }}</td>
                </tr>
                <tr>
                    <th>Average winrate</th>
                    <td>{{ $latestDailyReport->average_winrate }}%</td>
                </tr>
                </tbody>
            </table>

            <h2 class="section-title" style="margin-top: 18px;">Eng ko'p xato</h2>
            @if (!empty($latestDailyReport->top_mistakes))
                <table class="table">
                    <tbody>
                    @foreach ($latestDailyReport->top_mistakes as $mistake)
                        <tr>
                            <td>{{ $mistake['type'] }}</td>
                            <td>{{ $mistake['count'] }} ta</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @else
                <p class="muted">Hozircha xato klassifikatsiyasi yo'q.</p>
            @endif

            <h2 class="section-title" style="margin-top: 18px;">AI xulosasi</h2>
            <p class="muted">{{ $latestDailyReport->ai_conclusion }}</p>
        @else
            <p class="muted">Hali daily report yaratilmagan. Backtest run qilib, `php artisan trading:daily-report` buyrug'ini ishga tushiring.</p>
        @endif
    </article>
@endsection
