@extends('layouts.app', [
    'heading' => 'Agent Health',
    'subtitle' => 'Phase 2 foundation: event store, service health, market snapshot va agent memory readiness.',
])

@section('content')
    <section class="grid metrics">
        <article class="card tone-blue"><div class="metric-label">Services</div><div class="metric-value">{{ $metrics['services'] }}</div></article>
        <article class="card tone-red"><div class="metric-label">Critical</div><div class="metric-value">{{ $metrics['critical'] }}</div></article>
        <article class="card tone-yellow"><div class="metric-label">Warnings</div><div class="metric-value">{{ $metrics['warnings'] }}</div></article>
        <article class="card tone-green"><div class="metric-label">Events</div><div class="metric-value">{{ $metrics['events'] }}</div></article>
        <article class="card tone-blue"><div class="metric-label">Snapshots</div><div class="metric-value">{{ $metrics['signal_snapshots'] }}</div></article>
        <article class="card tone-green"><div class="metric-label">Avg Health</div><div class="metric-value">{{ $metrics['avg_health'] }}%</div></article>
        <article class="card tone-green"><div class="metric-label">Champions</div><div class="metric-value">{{ $metrics['champions'] }}</div></article>
        <article class="card tone-blue"><div class="metric-label">Forward Validated</div><div class="metric-value">{{ $metrics['forward_validated'] }}</div></article>
        <article class="card tone-yellow"><div class="metric-label">Paper Closed</div><div class="metric-value">{{ $metrics['paper_closed_orders'] }}</div></article>
        <article class="card tone-blue"><div class="metric-label">Paper PnL</div><div class="metric-value">{{ $metrics['paper_pnl'] }}%</div></article>
        <article class="card tone-yellow"><div class="metric-label">Avg Fill Cost</div><div class="metric-value">{{ $metrics['paper_fill_cost'] }}%</div></article>
    </section>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Health Check</h2>
        <form class="form-grid" method="POST" action="{{ route('agent-health.check') }}">
            @csrf
            <button type="submit">Run Health Check</button>
        </form>
    </article>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Market Truth Panel</h2>
        <table class="table">
            <thead><tr><th>Market</th><th>State</th><th>Confirmed candle</th><th>Pending from</th><th>Source</th><th>Reason</th></tr></thead>
            <tbody>
            @forelse ($feedStates as $state)
                <tr>
                    <td>{{ $state->symbol }} {{ $state->timeframe }}</td>
                    <td>{{ $state->status }}</td>
                    <td>{{ $state->last_confirmed_candle_at?->format('Y-m-d H:i') ?? '-' }}</td>
                    <td>{{ $state->pending_from_at?->format('Y-m-d H:i') ?? '-' }}</td>
                    <td>{{ data_get($state->metrics, 'last_provider', $state->provider) }}</td>
                    <td>{{ $state->last_error ?? '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="6">Per-market feed state hali yozilmagan.</td></tr>
            @endforelse
            </tbody>
        </table>
    </article>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Champion Gate</h2>
        @if ($metrics['promotion_ready'])
            <p>Market data barcha active instrumentlar uchun healthy. Champion promotion faqat forward, paper va sealed-holdout gate’laridan keyin mumkin.</p>
        @else
            <p>Promotion bloklangan. Healthy bo‘lishi kerak bo‘lgan marketlar: {{ implode(', ', $metrics['promotion_blocked_markets']) }}.</p>
        @endif
        <table class="table">
            <thead><tr><th>Model</th><th>Market</th><th>Status</th><th>Forward</th><th>Holdout</th><th>Paper PF / DD</th><th>Paper trades</th><th>Benchmark edge</th></tr></thead>
            <tbody>
            @forelse ($champions as $candidate)
                <tr>
                    <td>{{ $candidate->modelVersion?->name ?? $candidate->model_version_id }}</td>
                    <td>{{ $candidate->symbol }} {{ $candidate->timeframe }}</td>
                    <td>{{ $candidate->status }} / {{ $candidate->paper_status }}</td>
                    <td>{{ round((float) $candidate->forward_score, 2) }}</td>
                    <td>{{ $candidate->holdout_status }} / {{ round((float) $candidate->holdout_score, 2) }}</td>
                    <td>{{ round((float) $candidate->paper_profit_factor, 2) }} / {{ round((float) $candidate->paper_max_drawdown, 2) }}%</td>
                    <td>{{ $candidate->paper_sample_count }}</td>
                    <td>{{ round((float) data_get($candidate->metrics, 'benchmark.edge_vs_buy_and_hold_percent', 0), 2) }}%</td>
                </tr>
            @empty
                <tr><td colspan="6">Hali forward-validated yoki champion model yo‘q.</td></tr>
            @endforelse
            </tbody>
        </table>
    </article>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Service Health</h2>
            <table class="table">
                <thead><tr><th>Service</th><th>Status</th><th>Score</th><th>Message</th></tr></thead>
                <tbody>
                @forelse ($checks as $check)
                    <tr>
                        <td>{{ $check->service_label }}</td>
                        <td>{{ $check->status }}</td>
                        <td>{{ round((float) $check->health_score, 2) }}%</td>
                        <td>{{ $check->message }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">Hali health check yo'q.</td></tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Event Store</h2>
            <table class="table">
                <thead><tr><th>Type</th><th>Agent</th><th>Summary</th></tr></thead>
                <tbody>
                @forelse ($events as $event)
                    <tr>
                        <td>{{ $event->event_type }}</td>
                        <td>{{ $event->agent ?? '-' }}</td>
                        <td>{{ $event->summary }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3">Hali event yo'q.</td></tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Market Snapshot</h2>
            <table class="table">
                <thead><tr><th>Strategy</th><th>Signal</th><th>Species</th><th>Memory</th></tr></thead>
                <tbody>
                @forelse ($signals as $signal)
                    <tr>
                        <td>{{ $signal->strategy }}</td>
                        <td>{{ $signal->signal }} / {{ round((float) $signal->confidence, 2) }}%</td>
                        <td>{{ $signal->market_species ?? '-' }}</td>
                        <td>{{ round((float) $signal->memory_match_score, 2) }}%</td>
                    </tr>
                @empty
                    <tr><td colspan="4">Hali signal snapshot yo'q.</td></tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Agent Memory Matches</h2>
            <table class="table">
                <thead><tr><th>Strategy</th><th>Similarity</th><th>Lesson</th></tr></thead>
                <tbody>
                @forelse ($memoryMatches as $match)
                    <tr>
                        <td>{{ $match->strategy }}</td>
                        <td>{{ round((float) $match->similarity_score, 2) }}%</td>
                        <td>{{ $match->lesson ?? $match->memory?->lesson }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3">Hali memory match yo'q.</td></tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>
@endsection
