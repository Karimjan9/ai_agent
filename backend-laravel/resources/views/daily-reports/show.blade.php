@extends('layouts.app', [
    'heading' => 'AI Daily Report',
    'subtitle' => $dailyReport->report_date->format('Y-m-d') . ' training report tafsilotlari.',
])

@section('content')
    <section class="grid metrics">
        <article class="card tone-blue">
            <div class="metric-label">Backtests</div>
            <div class="metric-value">{{ $dailyReport->total_backtests }}</div>
        </article>
        <article class="card tone-green">
            <div class="metric-label">Trades</div>
            <div class="metric-value">{{ $dailyReport->total_trades }}</div>
        </article>
        <article class="card tone-yellow">
            <div class="metric-label">Winrate</div>
            <div class="metric-value">{{ $dailyReport->average_winrate }}%</div>
        </article>
        <article class="card tone-green">
            <div class="metric-label">Profit</div>
            <div class="metric-value">{{ $dailyReport->average_profit }}%</div>
        </article>
        <article class="card tone-blue">
            <div class="metric-label">Symbol</div>
            <div class="metric-value">{{ $dailyReport->symbol ?? '-' }}</div>
        </article>
        <article class="card tone-yellow">
            <div class="metric-label">Timeframe</div>
            <div class="metric-value">{{ $dailyReport->timeframe ?? '-' }}</div>
        </article>
    </section>

    <section class="split">
        <article class="card">
            <h2 class="section-title">AI Xulosasi</h2>
            <p class="muted">{{ $dailyReport->ai_conclusion ?? 'Xulosa yozilmagan.' }}</p>
        </article>
        <article class="card">
            <h2 class="section-title">Keyingi trening rejasi</h2>
            <p class="muted">{{ $dailyReport->next_training_plan ?? 'Reja yozilmagan.' }}</p>
        </article>
    </section>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Eng ko'p xatolar</h2>
        @if (!empty($dailyReport->top_mistakes))
            <table class="table">
                <thead>
                <tr>
                    <th>Type</th>
                    <th>Count</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($dailyReport->top_mistakes as $mistake)
                    <tr>
                        <td>{{ $mistake['type'] }}</td>
                        <td>{{ $mistake['count'] }} ta</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @else
            <p class="muted">Xatolar topilmadi.</p>
        @endif
    </article>
@endsection
