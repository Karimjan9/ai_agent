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
        <h2 class="section-title">Full replay funnel</h2>
        <table class="table"><thead><tr>
            @foreach(['generated', 'screened', 'diagnostic_replay', 'full_replay_eligible', 'full_evaluated', 'forward_validated', 'paper_eligible', 'paper_signals', 'closed_outcomes', 'calibrated', 'holdout_passed', 'champion'] as $stage)
                <th>{{ str_replace('_', ' ', $stage) }}</th>
            @endforeach
        </tr></thead><tbody><tr>
            @foreach(['generated', 'screened', 'diagnostic_replay', 'full_replay_eligible', 'full_evaluated', 'forward_validated', 'paper_eligible', 'paper_signals', 'closed_outcomes', 'calibrated', 'holdout_passed', 'champion'] as $stage)
                <td>{{ $funnel[$stage] ?? 0 }}</td>
            @endforeach
        </tr></tbody></table>
        <p style="margin:12px 0 0;color:var(--muted);">
            Paper: {{ $paperReadiness['status'] === 'ready' ? 'READY' : 'WAITING_FOR_EVIDENCE' }} ·
            Signals: {{ data_get($paperReadiness, 'metrics.signal_count', 0) }} / {{ config('services.paper_observation.min_signals', 1000) }} ·
            Closed trades: {{ data_get($paperReadiness, 'metrics.closed_trades', 0) }} / {{ config('services.paper_observation.min_closed_trades', 200) }} ·
            Observation: {{ data_get($paperReadiness, 'metrics.observation_days', 0) }} / {{ config('services.paper_observation.min_days', 90) }} days
        </p>
    </article>

    <article class="card" style="margin-top:14px;">
        <h2 class="section-title">Generation result packet</h2>
        @if($generationReport)
            <p style="margin-top:0;color:var(--muted);">Phase: <code>{{ $generationReport['phase'] ?? '-' }}</code> · Next action: <strong>{{ $generationReport['next_action'] ?? '-' }}</strong></p>
            <section class="grid metrics">
                <article class="card tone-blue"><div class="metric-label">Technical completion</div><div class="metric-value">{{ data_get($generationReport, 'kpis.technical_completion_rate', 0) }}%</div></article>
                <article class="card tone-yellow"><div class="metric-label">Screen pass rate</div><div class="metric-value">{{ data_get($generationReport, 'kpis.screening_pass_rate', 0) }}%</div></article>
                <article class="card tone-blue"><div class="metric-label">Full completion</div><div class="metric-value">{{ data_get($generationReport, 'kpis.full_validation_completion_rate', 0) }}%</div></article>
                <article class="card tone-green"><div class="metric-label">Forward-valid</div><div class="metric-value">{{ data_get($generationReport, 'kpis.forward_valid_agents', 0) }}</div></article>
            </section>
            <table class="table"><tbody>
                <tr><th>Best agent</th><td>{{ data_get($generationReport, 'best_agent.id', '-') }} / {{ data_get($generationReport, 'best_agent.performance_status', '-') }} / PF {{ data_get($generationReport, 'best_agent.profit_factor', '-') }}</td></tr>
                <tr><th>Parent delta</th><td>{{ collect($generationReport['parent_delta'] ?? [])->map(fn($value, $key) => $key.' '.(is_numeric($value) ? number_format((float)$value, 3) : '-'))->implode(', ') ?: 'Parent evidence yo‘q' }}</td></tr>
                <tr><th>Improved gates</th><td>{{ implode(', ', $generationReport['gate_improvements'] ?? []) ?: 'Hali tasdiqlangan yaxshilanish yo‘q' }}</td></tr>
                <tr><th>Failed gates</th><td>{{ collect($generationReport['gate_failures'] ?? [])->map(fn($value, $key) => $key.' ('.$value.')')->implode(', ') ?: 'Failure yo‘q' }}</td></tr>
                <tr><th>Technical errors</th><td>{{ count($generationReport['technical_errors'] ?? []) }}</td></tr>
                <tr><th>Mutation targets</th><td>{{ implode(', ', $generationReport['mutation_targets'] ?? []) ?: '-' }}</td></tr>
            </tbody></table>
        @else
            <p style="margin:0;color:var(--muted);">Generation report hali yozilmadi; current phase yakunlangach avtomatik yaratiladi.</p>
        @endif
    </article>

    <article class="card" style="margin-top:14px;">
        <h2 class="section-title">Forward-gate diagnostics</h2>
        <p style="margin-top:0;color:var(--muted);">Paper monitor faqat barcha gate'dan o'tgan <code>forward_validated</code> candidate uchun signal yaratadi. Bu jadval har model qayerda to'xtaganini ko'rsatadi.</p>
        <table class="table"><thead><tr><th>Family / model</th><th>Status</th><th>Edge claim / falsifier</th><th>Gross / normal / stress PF</th><th>Cost / gross profit</th><th>PF attribution</th><th>Ruin</th><th>PBO / DSR</th><th>Failed gates</th></tr></thead><tbody>
        @forelse($gateDiagnostics as $diagnostic)
            @php($candidate = $diagnostic['candidate'])
            <tr>
                <td>{{ $candidate->strategy_family }} / {{ $candidate->modelVersion?->name }}</td>
                <td>{{ $candidate->status }}</td>
                <td>{{ data_get($diagnostic, 'edge_claim.target_regime', 'legacy / unproven') }} / {{ data_get($diagnostic, 'edge_claim.falsification_report.status', 'not replayed') }}</td>
                <td>{{ $diagnostic['cost_profiles']['gross'] !== null ? number_format((float) $diagnostic['cost_profiles']['gross'], 2).' / '.number_format((float) $diagnostic['cost_profiles']['normal'], 2).' / '.number_format((float) $diagnostic['cost_profiles']['stress'], 2) : $diagnostic['gates']['PF >= 1.30'][1] }}</td>
                <td>{{ $diagnostic['cost_profiles']['cost_ratio'] !== null ? number_format((float) $diagnostic['cost_profiles']['cost_ratio'], 1).'%' : 'legacy / not replayed' }}</td>
                <td>
                    @if(data_get($diagnostic, 'attribution.by_direction'))
                        <details><summary>direction / regime / exit</summary>
                            @foreach(data_get($diagnostic, 'attribution.by_direction', []) as $name => $item) {{ $name }} PF {{ number_format((float) data_get($item, 'net_pf', 0), 2) }} ({{ data_get($item, 'trades', 0) }})@if(! $loop->last), @endif @endforeach
                            <br>Regime: @foreach(data_get($diagnostic, 'attribution.by_regime', []) as $name => $item) {{ $name }} {{ number_format((float) data_get($item, 'net_pf', 0), 2) }}@if(! $loop->last), @endif @endforeach
                            <br>Exit: @foreach(data_get($diagnostic, 'attribution.by_exit_reason', []) as $name => $item) {{ $name }} {{ number_format((float) data_get($item, 'net_pf', 0), 2) }}@if(! $loop->last), @endif @endforeach
                        </details>
                    @else legacy / not replayed @endif
                </td>
                <td>{{ $diagnostic['gates']['Ruin <= 10%'][1] }}</td>
                <td>{{ $diagnostic['gates']['CSCV PBO <= 50%'][1] ?? 'not assessed' }} / {{ $diagnostic['gates']['Deflated Sharpe >= 95%'][1] ?? 'not assessed' }}</td>
                <td>{{ $diagnostic['failed']->isEmpty() ? 'Forward validated - paper signal eligible.' : $diagnostic['failed']->implode(', ') }}</td>
            </tr>
        @empty <tr><td colspan="9">Baholangan valid candidate hali yo'q.</td></tr> @endforelse
        </tbody></table>
    </article>

    <article class="card" style="margin-top:14px;">
        <h2 class="section-title">Candidate gate decision ledger</h2>
        <p style="margin-top:0;color:var(--muted);">Generic failure o‘rniga har bir lifecycle stage uchun aniq machine-readable sabab saqlanadi.</p>
        <table class="table"><thead><tr><th>Model / agent</th><th>Stage</th><th>Decision</th><th>Reason codes</th><th>At</th></tr></thead><tbody>
        @forelse($gateDecisions as $gateDecision)
            <tr>
                <td>{{ $gateDecision->performance?->modelVersion?->name ?? $gateDecision->labAgent?->modelVersion?->name ?? '-' }}</td>
                <td>{{ $gateDecision->stage }}</td><td>{{ $gateDecision->decision }}</td>
                <td>{{ implode(', ', $gateDecision->reason_codes ?? []) ?: 'PASSED' }}</td>
                <td>{{ optional($gateDecision->evaluated_at)->format('Y-m-d H:i') }}</td>
            </tr>
        @empty <tr><td colspan="5">Gate decision hali yozilmagan.</td></tr> @endforelse
        </tbody></table>
    </article>

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
