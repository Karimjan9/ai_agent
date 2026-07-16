@extends('layouts.app', [
    'heading' => $lab->name,
    'subtitle' => 'Pair-owned population learning, forward validation, paper gate va champion lifecycle.',
])

@section('content')
    <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;">
        @foreach($labs as $item)
            <a class="badge" style="{{ $item->id === $lab->id ? 'background:var(--blue);color:white;' : '' }}" href="{{ route('ai-laboratory.show', $item->symbol) }}">{{ $item->symbol }} Lab</a>
        @endforeach
    </div>

    <section class="grid metrics">
        <article class="card tone-blue"><div class="metric-label">Generation</div><div class="metric-value">{{ $generation?->generation ?? 0 }}</div></article>
        <article class="card tone-green"><div class="metric-label">Champions</div><div class="metric-value">{{ $champions->count() }}</div></article>
        <article class="card tone-yellow"><div class="metric-label">Challengers</div><div class="metric-value">{{ $challengers->count() }}</div></article>
        <article class="card tone-blue"><div class="metric-label">Population</div><div class="metric-value">{{ $generation?->agents->count() ?? 0 }}/20</div></article>
        <article class="card tone-green"><div class="metric-label">Mutation Memory</div><div class="metric-value">{{ $memories->count() }}</div></article>
        <article class="card tone-red"><div class="metric-label">Trigger</div><div class="metric-value" style="font-size:18px;">{{ $generation?->trigger_type ?? '-' }}</div></article>
    </section>

    <article class="card" style="margin-top:14px;">
        <h2 class="section-title">Generation bo‘yicha forward performance</h2>
        <canvas id="generationForwardChart" height="90"></canvas>
    </article>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Amaldagi champions</h2>
            <table class="table"><thead><tr><th>Family</th><th>Model</th><th>Forward</th><th>Paper</th></tr></thead><tbody>
            @forelse($champions as $item)
                <tr><td>{{ $item->strategy_family }}</td><td>{{ $item->modelVersion?->name }}</td><td>{{ $item->forward_score }}</td><td>{{ $item->paper_status }} / {{ $item->paper_sample_count }}</td></tr>
            @empty <tr><td colspan="4">Paper orqali tasdiqlangan champion hali yo‘q.</td></tr> @endforelse
            </tbody></table>
        </article>
        <article class="card">
            <h2 class="section-title">Forward/Paper challengers</h2>
            <table class="table"><thead><tr><th>Family</th><th>Model</th><th>Status</th><th>Δ champion</th></tr></thead><tbody>
            @forelse($challengers as $item)
                <tr><td>{{ $item->strategy_family }}</td><td>{{ $item->modelVersion?->name }}</td><td>{{ $item->status }}</td><td>{{ optional($item->modelVersion?->labAgents->last())->champion_improvement ?? '-' }}</td></tr>
            @empty <tr><td colspan="4">Challenger yo‘q.</td></tr> @endforelse
            </tbody></table>
        </article>
    </section>

    <article class="card" style="margin-top:14px;">
        <h2 class="section-title">Generation population</h2>
        <table class="table"><thead><tr><th>Agent</th><th>Family / origin</th><th>Parents</th><th>Lifecycle</th><th>Train / Val / Forward</th><th>PF / DD / Ruin</th><th>Rolling wins</th><th>Qaror</th></tr></thead><tbody>
        @forelse($generation?->agents ?? [] as $agent)
            <tr>
                <td>{{ $agent->modelVersion?->name }}</td><td>{{ $agent->strategy_family }} / {{ $agent->origin }}</td>
                <td>{{ $agent->parentA?->name ?? '-' }}{{ $agent->parentB ? ' + '.$agent->parentB->name : '' }}</td>
                <td>{{ $agent->lifecycle_status }}</td><td>{{ $agent->train_score ?? '-' }} / {{ $agent->validation_score ?? '-' }} / {{ $agent->forward_score ?? '-' }}</td>
                <td>{{ $agent->profit_factor ?? '-' }} / {{ $agent->max_drawdown ?? '-' }} / {{ $agent->risk_of_ruin ?? '-' }}</td>
                <td>{{ $agent->rolling_wins }}</td><td>{{ $agent->decision_reason ?? 'Training kutilmoqda.' }}</td>
            </tr>
        @empty <tr><td colspan="8">Generation hali yaratilmagan. `php artisan trading:lab-generation {{ $lab->symbol }}` ishlating.</td></tr> @endforelse
        </tbody></table>
    </article>

    <article class="card" style="margin-top:14px;">
        <h2 class="section-title">Mutation learning memory</h2>
        <table class="table"><thead><tr><th>Family</th><th>Mutation</th><th>Forward Δ</th><th>Regime</th><th>Outcome</th><th>Keyingi qaror</th></tr></thead><tbody>
        @forelse($memories as $memory)
            <tr><td>{{ $memory->strategy_family }}</td><td>{{ $memory->parameter_key }}: {{ data_get($memory->old_value, 'value') }} → {{ data_get($memory->new_value, 'value') }}</td><td>{{ $memory->forward_delta }}</td><td>{{ $memory->market_regime ?? '-' }}</td><td>{{ $memory->outcome }}</td><td>{{ $memory->decision }}</td></tr>
        @empty <tr><td colspan="6">Baholangan mutation xotirasi hali yo‘q.</td></tr> @endforelse
        </tbody></table>
    </article>
@endsection

@push('scripts')
<script>
const generationPerformance = @json($generationPerformance);
new Chart(document.getElementById('generationForwardChart'), {
    type: 'line',
    data: { labels: generationPerformance.map(x => `G${x.generation}`), datasets: [
        { label: 'Average forward', data: generationPerformance.map(x => x.forward), borderColor: '#3b82f6', tension: .25 },
        { label: 'Best forward', data: generationPerformance.map(x => x.best), borderColor: '#22c55e', tension: .25 }
    ]},
    options: { responsive: true, scales: { y: { suggestedMin: 0, suggestedMax: 100 } } }
});
</script>
@endpush
