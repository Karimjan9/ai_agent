@extends('layouts.app', [
    'heading' => 'Canonical Lab Result',
    'subtitle' => 'LabEvaluationRun immutable evidence plane qaytargan replay natijasi.',
])

@section('content')
    <section class="grid metrics">
        <article class="card tone-blue">
            <div class="metric-label">Strategiya</div>
            <div class="metric-value">{{ $result['strategy'] ?? $payload['strategy'] }}</div>
        </article>
        <article class="card tone-green">
            <div class="metric-label">Instrument</div>
            <div class="metric-value">{{ $result['instrument'] ?? $payload['symbol'] }}</div>
        </article>
        <article class="card tone-yellow">
            <div class="metric-label">Timeframe</div>
            <div class="metric-value">{{ $result['timeframe'] ?? $payload['timeframe'] }}</div>
        </article>
        <article class="card tone-blue">
            <div class="metric-label">Trades</div>
            <div class="metric-value">{{ $result['total_trades'] ?? 0 }}</div>
        </article>
        <article class="card tone-green">
            <div class="metric-label">Winrate</div>
            <div class="metric-value">{{ $result['winrate'] ?? 0 }}%</div>
        </article>
        <article class="card tone-red">
            <div class="metric-label">Max Drawdown</div>
            <div class="metric-value">{{ $result['max_drawdown'] ?? 0 }}%</div>
        </article>
        <article class="card tone-green">
            <div class="metric-label">Profit Factor</div>
            <div class="metric-value">{{ $result['profit_factor'] ?? 0 }}</div>
        </article>
        <article class="card tone-green">
            <div class="metric-label">Net Profit</div>
            <div class="metric-value">{{ $result['net_profit_percent'] ?? 0 }}%</div>
        </article>
        <article class="card tone-blue">
            <div class="metric-label">Period</div>
            <div class="metric-value">{{ $result['period'] ?? '-' }}</div>
        </article>
        @isset($backtestRun)
            <article class="card tone-yellow">
                <div class="metric-label">Canonical run</div>
                <div class="metric-value">#{{ $backtestRun->id }}</div>
            </article>
        @endisset
    </section>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Xulosa</h2>
            <p class="muted">{{ $result['conclusion'] ?? 'Xulosa qaytmadi.' }}</p>
        </article>
        <article class="card">
            <h2 class="section-title">Eng ko'p xatolar</h2>
            @if (!empty($result['top_mistakes']))
                <table class="table">
                    <thead>
                    <tr>
                        <th>Type</th>
                        <th>Count</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($result['top_mistakes'] as $mistake)
                        <tr>
                            <td>{{ $mistake['type'] }}</td>
                            <td>{{ $mistake['count'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @else
                <p class="muted">Bu run uchun xato klassifikatsiyasi topilmadi.</p>
            @endif
        </article>
    </section>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Payload</h2>
        <pre class="code">{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    </article>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Python response</h2>
        <pre class="code">{{ json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    </article>
@endsection
