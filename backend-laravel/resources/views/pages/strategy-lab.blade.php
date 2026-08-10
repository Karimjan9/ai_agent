@extends('layouts.app', [
    'heading' => 'Strategy Lab',
    'subtitle' => 'Ko\'p strategiyalarni bir xil datasetda test qilish va agentlarni solishtirish.',
])

@section('content')
    <article class="card">
        <h2 class="section-title">Run backtest</h2>
        <form class="form-grid" method="post" action="{{ route('backtests.run') }}">
            @csrf
            <label>Symbol
                <select name="symbol">
                    <option value="XAU_USD">XAU_USD</option>
                </select>
            </label>
            <label>Timeframe
                <select name="timeframe">
                    <option value="H1">H1</option>
                    <option value="M15">M15</option>
                </select>
            </label>
            <label>Strategy
                <select name="strategy">
                    @forelse ($strategies as $strategy)
                        <option value="{{ $strategy['strategy'] }}">{{ $strategy['label'] }}</option>
                    @empty
                        <option value="" disabled selected>Strategy registry unavailable</option>
                    @endforelse
                </select>
            </label>
            <label>From
                <input name="from_date" type="date" value="2023-01-01">
            </label>
            <label>To
                <input name="to_date" type="date" value="2025-12-31">
            </label>
            <button type="submit">Run</button>
        </form>
    </article>

    <section class="split">
        <article class="card">
            <h2 class="section-title">API response</h2>
            <pre class="code" id="backtest-output">Replay queuega yuboriladi; natija status polling sahifasida ko‘rinadi.</pre>
        </article>
        <article class="card">
            <h2 class="section-title">Preset</h2>
            <p class="muted">Strategy nomlari AI registry’dan olinadi; replay bir xil canonical dataset manifesti bilan queue’da bajariladi.</p>
        </article>
    </section>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Agent Leaderboard</h2>
        <table class="table">
            <thead>
            <tr>
                <th>Agent / Strategy</th>
                <th>Trades</th>
                <th>Winrate</th>
                <th>Profit</th>
                <th>Drawdown</th>
                <th>Score</th>
            </tr>
            </thead>
            <tbody>
            @if (($labSummary['run_id'] ?? null) !== null)
                <tr>
                    <td>{{ $labSummary['strategy'] }}</td>
                    <td>{{ $labSummary['trades'] }}</td>
                    <td>{{ $labSummary['winrate'] === null ? '—' : $labSummary['winrate'].'%' }}</td>
                    <td>{{ $labSummary['net_profit'] }}%</td>
                    <td>{{ $labSummary['max_drawdown'] }}%</td>
                    <td>—</td>
                </tr>
            @else
                <tr>
                    <td colspan="6">Canonical lab evidence hali mavjud emas.</td>
                </tr>
            @endif
            </tbody>
        </table>
    </article>

@endsection
