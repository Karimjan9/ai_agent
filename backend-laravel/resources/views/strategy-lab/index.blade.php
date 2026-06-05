@extends('layouts.app', [
    'heading' => 'Strategy Lab',
    'subtitle' => "Agent strategiyalarni bir xil market data'da test qilish va solishtirish.",
])

@section('content')
    <article class="card">
        <div class="topbar" style="margin-bottom: 0;">
            <div>
                <h2 class="section-title">Agent musobaqasi</h2>
                <p class="muted">EMA RSI, MACD Trend, Fibonacci Pullback va Breakout agentlari XAU/USD H1 datasetda test qilinadi.</p>
            </div>
            <form method="post" action="{{ route('strategy-lab.run-all') }}">
                @csrf
                <input type="hidden" name="symbol" value="XAUUSD">
                <input type="hidden" name="timeframe" value="H1">
                <input type="hidden" name="initial_balance" value="10000">
                <input type="hidden" name="risk_per_trade" value="1">
                <button type="submit">Start New Training Session</button>
            </form>
        </div>
    </article>

    @if (session('success'))
        <article class="card tone-green" style="margin-top: 14px;">
            <p class="muted">{{ session('success') }}</p>
        </article>
    @endif

    @if (session('error'))
        <article class="card tone-red" style="margin-top: 14px;">
            <p class="muted">{{ session('error') }}</p>
        </article>
    @endif

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Agent Leaderboard</h2>
        <table class="table">
            <thead>
            <tr>
                <th>Rank</th>
                <th>Strategy</th>
                <th>Score</th>
                <th>Trades</th>
                <th>Winrate</th>
                <th>Profit</th>
                <th>Drawdown</th>
                <th>Created</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($scores as $index => $score)
                <tr>
                    <td>#{{ $scores->firstItem() + $index }}</td>
                    <td>{{ strtoupper($score->strategy) }}</td>
                    <td>{{ $score->score }}</td>
                    <td>{{ $score->total_trades }}</td>
                    <td>{{ $score->winrate }}%</td>
                    <td>{{ $score->net_profit_percent }}%</td>
                    <td>{{ $score->max_drawdown_percent }}%</td>
                    <td>{{ $score->created_at->format('Y-m-d H:i') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">Hali leaderboard natijasi yo'q. Run All Agents tugmasini bosing.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </article>

    @if ($scores->hasPages())
        <article class="card" style="margin-top: 14px;">
            {{ $scores->links() }}
        </article>
    @endif
@endsection
