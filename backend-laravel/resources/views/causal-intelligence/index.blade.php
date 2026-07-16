@extends('layouts.app', [
    'heading' => 'Causal Intelligence',
    'subtitle' => 'Pattern va lawlardan sabab-oqibat candidate, counterfactual, intervention va experiment layer yaratadi.',
])

@section('content')
    <section class="grid metrics">
        <article class="card tone-blue"><div class="metric-label">Runs</div><div class="metric-value">{{ $metrics['runs'] }}</div></article>
        <article class="card tone-green"><div class="metric-label">Causal Nodes</div><div class="metric-value">{{ $metrics['nodes'] }}</div></article>
        <article class="card tone-green"><div class="metric-label">Causal Edges</div><div class="metric-value">{{ $metrics['edges'] }}</div></article>
        <article class="card tone-yellow"><div class="metric-label">Counterfactuals</div><div class="metric-value">{{ $metrics['counterfactuals'] }}</div></article>
        <article class="card tone-red"><div class="metric-label">Interventions</div><div class="metric-value">{{ $metrics['interventions'] }}</div></article>
        <article class="card tone-blue"><div class="metric-label">Experiments</div><div class="metric-value">{{ $metrics['experiments'] }}</div></article>
    </section>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Causal Discovery</h2>
        <form class="form-grid" method="POST" action="{{ route('causal-intelligence.discover') }}">
            @csrf
            <button type="submit">Discover Causes</button>
        </form>
        <p class="muted" style="margin-top: 12px;">
            {{ $latestRun?->summary ?? "Hali causal discovery yo'q." }}
        </p>
    </article>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Causal Graph</h2>
            <table class="table">
                <thead><tr><th>Cause</th><th>Effect</th><th>Causality</th><th>Status</th></tr></thead>
                <tbody>
                @forelse ($edges as $edge)
                    <tr>
                        <td>{{ $edge->sourceNode?->label }}</td>
                        <td>{{ $edge->targetNode?->label }}</td>
                        <td>{{ round((float) $edge->causality_score, 2) }}%</td>
                        <td>{{ $edge->identification_status }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">Hali causal edge yo'q.</td></tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Root Causes</h2>
            <table class="table">
                <thead><tr><th>#</th><th>Cause</th><th>Impact</th></tr></thead>
                <tbody>
                @forelse ($rootCauses as $cause)
                    <tr>
                        <td>{{ $cause->rank }}</td>
                        <td>{{ $cause->title }}</td>
                        <td>{{ round((float) $cause->impact_score, 2) }}%</td>
                    </tr>
                @empty
                    <tr><td colspan="3">Hali root cause yo'q.</td></tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Counterfactual Laboratory</h2>
            <table class="table">
                <thead><tr><th>Question</th><th>Delta</th><th>Confidence</th></tr></thead>
                <tbody>
                @forelse ($counterfactuals as $counterfactual)
                    <tr>
                        <td>{{ $counterfactual->question }}</td>
                        <td>{{ round((float) $counterfactual->estimated_delta, 2) }}%</td>
                        <td>{{ round((float) $counterfactual->confidence_score, 2) }}%</td>
                    </tr>
                @empty
                    <tr><td colspan="3">Hali counterfactual yo'q.</td></tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Interventions</h2>
            <table class="table">
                <thead><tr><th>Intervention</th><th>Impact</th><th>Risk</th></tr></thead>
                <tbody>
                @forelse ($interventions as $intervention)
                    <tr>
                        <td>{{ $intervention->title }}</td>
                        <td>{{ round((float) $intervention->expected_impact_score, 2) }}%</td>
                        <td>{{ round((float) $intervention->risk_score, 2) }}%</td>
                    </tr>
                @empty
                    <tr><td colspan="3">Hali intervention yo'q.</td></tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Experiments</h2>
            <table class="table">
                <thead><tr><th>Experiment</th><th>Status</th><th>Info Gain</th></tr></thead>
                <tbody>
                @forelse ($experiments as $experiment)
                    <tr>
                        <td>{{ $experiment->title }}</td>
                        <td>{{ $experiment->status }}</td>
                        <td>{{ round((float) $experiment->expected_information_gain, 2) }}%</td>
                    </tr>
                @empty
                    <tr><td colspan="3">Hali experiment yo'q.</td></tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Discovery Quality</h2>
            <table class="table">
                <thead><tr><th>Discovery</th><th>Correlation</th><th>Causality</th><th>Verdict</th></tr></thead>
                <tbody>
                @forelse ($qualityScores as $score)
                    <tr>
                        <td>{{ $score->title }}</td>
                        <td>{{ round((float) $score->correlation_score, 2) }}%</td>
                        <td>{{ round((float) $score->causality_score, 2) }}%</td>
                        <td>{{ $score->verdict }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">Hali quality score yo'q.</td></tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>
@endsection
