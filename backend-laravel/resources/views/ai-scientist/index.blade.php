@extends('layouts.app', [
    'heading' => 'AI Scientist',
    'subtitle' => "Training sessionlardan hypothesis, belief, journal, knowledge va counterfactual xotira.",
])

@section('content')
    <section class="grid metrics">
        <article class="card tone-blue">
            <div class="metric-label">Hypotheses</div>
            <div class="metric-value">{{ $metrics['hypotheses'] }}</div>
        </article>
        <article class="card tone-green">
            <div class="metric-label">Confirmed</div>
            <div class="metric-value">{{ $metrics['confirmed'] }}</div>
        </article>
        <article class="card tone-red">
            <div class="metric-label">Failed</div>
            <div class="metric-value">{{ $metrics['failed'] }}</div>
        </article>
        <article class="card tone-yellow">
            <div class="metric-label">Beliefs</div>
            <div class="metric-value">{{ $metrics['beliefs'] }}</div>
        </article>
        <article class="card tone-green">
            <div class="metric-label">Knowledge Facts</div>
            <div class="metric-value">{{ $metrics['knowledge_facts'] }}</div>
        </article>
        <article class="card tone-blue">
            <div class="metric-label">Counterfactuals</div>
            <div class="metric-value">{{ $metrics['counterfactuals'] }}</div>
        </article>
    </section>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Hypotheses</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Strategy</th>
                    <th>Decision</th>
                    <th>Confidence</th>
                    <th>Status</th>
                    <th>Regime</th>
                    <th>Outcome</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($hypotheses as $hypothesis)
                    <tr>
                        <td>{{ strtoupper($hypothesis->strategy) }}</td>
                        <td>{{ $hypothesis->decision }}</td>
                        <td>{{ $hypothesis->confidence }}%</td>
                        <td>{{ strtoupper($hypothesis->status) }}</td>
                        <td>{{ $hypothesis->market_regime ?? '-' }}</td>
                        <td>{{ data_get($hypothesis->actual_outcome, 'profit_percent', '-') }}%</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">Hali hypothesis yozilmagan.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Beliefs</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Strategy</th>
                    <th>Belief</th>
                    <th>Score</th>
                    <th>Samples</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($beliefs as $belief)
                    <tr>
                        <td>{{ strtoupper($belief->strategy) }}</td>
                        <td>{{ $belief->belief_label }}</td>
                        <td>{{ $belief->score }}</td>
                        <td>{{ $belief->sample_size }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">Hali belief update yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Scientist Journals</h2>
        <table class="table">
            <thead>
            <tr>
                <th>Session</th>
                <th>Summary</th>
                <th>Most Failed</th>
                <th>Conclusion</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($journals as $journal)
                <tr>
                    <td>
                        <a style="color: var(--blue); font-weight: 800;" href="{{ route('training-sessions.show', $journal->training_session_id) }}">
                            #{{ $journal->training_session_id }}
                        </a>
                    </td>
                    <td>{{ $journal->summary }}</td>
                    <td>{{ $journal->most_failed_hypothesis ?? '-' }}</td>
                    <td>{{ $journal->conclusion }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">Hali scientist journal yo'q.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </article>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Knowledge Base</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Fact</th>
                    <th>Confidence</th>
                    <th>Status</th>
                    <th>Evidence</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($knowledgeFacts as $fact)
                    <tr>
                        <td>{{ $fact->fact }}</td>
                        <td>{{ $fact->confidence_score }}%</td>
                        <td>{{ strtoupper($fact->status) }}</td>
                        <td>{{ $fact->evidence_count }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">Hali knowledge fact yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Counterfactuals</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Scenario</th>
                    <th>Strategy</th>
                    <th>Delta</th>
                    <th>Verdict</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($counterfactualRuns as $run)
                    <tr>
                        <td>{{ $run->scenario_name }}</td>
                        <td>{{ strtoupper($run->agentHypothesis?->strategy ?? '-') }}</td>
                        <td>{{ $run->delta_percent }}%</td>
                        <td>{{ strtoupper($run->verdict) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">Hali counterfactual run yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>
@endsection
