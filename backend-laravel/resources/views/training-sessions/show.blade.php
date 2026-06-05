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

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Session Leaderboard</h2>
        <table class="table">
            <thead>
            <tr>
                <th>Strategy</th>
                <th>Score</th>
                <th>Trades</th>
                <th>Wins</th>
                <th>Losses</th>
                <th>Winrate</th>
                <th>Profit</th>
                <th>Drawdown</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($trainingSession->strategyScores as $score)
                <tr>
                    <td>{{ strtoupper($score->strategy) }}</td>
                    <td>{{ $score->score }}</td>
                    <td>{{ $score->total_trades }}</td>
                    <td>{{ $score->wins }}</td>
                    <td>{{ $score->losses }}</td>
                    <td>{{ $score->winrate }}%</td>
                    <td>{{ $score->net_profit_percent }}%</td>
                    <td>{{ $score->max_drawdown_percent }}%</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </article>
@endsection
