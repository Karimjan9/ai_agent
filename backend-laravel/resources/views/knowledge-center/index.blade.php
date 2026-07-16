@extends('layouts.app', [
    'heading' => 'Knowledge Center',
    'subtitle' => 'Strategy, agent, market, genome, hypothesis, discovery va failure evidence bitta graphga boglangan.',
])

@section('content')
    <section class="grid metrics">
        <article class="card tone-blue">
            <div class="metric-label">Nodes</div>
            <div class="metric-value">{{ $metrics['nodes'] }}</div>
        </article>
        <article class="card tone-green">
            <div class="metric-label">Edges</div>
            <div class="metric-value">{{ $metrics['edges'] }}</div>
        </article>
        <article class="card tone-yellow">
            <div class="metric-label">Claims</div>
            <div class="metric-value">{{ $metrics['claims'] }}</div>
        </article>
        <article class="card tone-blue">
            <div class="metric-label">Queries</div>
            <div class="metric-value">{{ $metrics['queries'] }}</div>
        </article>
        <article class="card tone-green">
            <div class="metric-label">Mining Runs</div>
            <div class="metric-value">{{ $metrics['runs'] }}</div>
        </article>
        <article class="card tone-red">
            <div class="metric-label">Failure Claims</div>
            <div class="metric-value">{{ $failureClaims->count() }}</div>
        </article>
    </section>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Research Assistant</h2>
        <form class="form-grid" method="GET" action="{{ route('knowledge-center.index') }}" style="grid-template-columns: 1fr auto;">
            <label>
                Question
                <input name="q" value="{{ $question }}" placeholder="Why did ema_rsi_v1 become successful?">
            </label>
            <button type="submit">Ask Graph</button>
        </form>
        @if ($answer)
            <div class="card tone-green" style="margin-top: 12px; box-shadow: none;">
                <div class="metric-label">Answer confidence: {{ $answer->confidence_score }}%</div>
                <div>{{ $answer->answer }}</div>
            </div>
        @endif
    </article>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Knowledge Graph</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Node</th>
                    <th>Type</th>
                    <th>Confidence</th>
                    <th>Links</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($nodes as $node)
                    <tr>
                        <td>{{ $node->label }}</td>
                        <td>{{ $node->node_type }}</td>
                        <td>{{ $node->confidence_score }}%</td>
                        <td>{{ $node->outgoing_edges_count + $node->incoming_edges_count }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">Hali graph node yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Graph Edges</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Source</th>
                    <th>Relation</th>
                    <th>Target</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($edges as $edge)
                    <tr>
                        <td>{{ $edge->sourceNode?->label ?? '-' }}</td>
                        <td>{{ $edge->relation_type }} / {{ $edge->confidence_score }}%</td>
                        <td>{{ $edge->targetNode?->label ?? '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">Hali graph edge yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Discoveries</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Claim</th>
                    <th>Type</th>
                    <th>Confidence</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($claims as $claim)
                    <tr>
                        <td>{{ $claim->title }}</td>
                        <td>{{ $claim->claim_type }}</td>
                        <td>{{ $claim->confidence_score }}%</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">Hali claim yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Failure Analysis</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Cause</th>
                    <th>Evidence</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($failureClaims as $claim)
                    <tr>
                        <td>{{ $claim->title }}</td>
                        <td>{{ $claim->evidence_count }}</td>
                        <td>{{ $claim->status }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">Hali failure claim yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Pattern Explorer</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Pattern</th>
                    <th>Type</th>
                    <th>Confidence</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($patternClaims as $claim)
                    <tr>
                        <td>{{ $claim->title }}</td>
                        <td>{{ $claim->claim_type }}</td>
                        <td>{{ $claim->confidence_score }}%</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">Hali pattern claim yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Knowledge Timeline</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Run</th>
                    <th>Status</th>
                    <th>New</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($runs as $run)
                    <tr>
                        <td>{{ $run->created_at->format('Y-m-d H:i') }}</td>
                        <td>{{ $run->status }}</td>
                        <td>{{ $run->nodes_created }} / {{ $run->edges_created }} / {{ $run->claims_created }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">Hali mining run yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Recent Research Questions</h2>
        <table class="table">
            <thead>
            <tr>
                <th>Question</th>
                <th>Answer</th>
                <th>Confidence</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($queries as $query)
                <tr>
                    <td>{{ $query->question }}</td>
                    <td>{{ $query->answer }}</td>
                    <td>{{ $query->confidence_score }}%</td>
                </tr>
            @empty
                <tr>
                    <td colspan="3">Hali query yo'q.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </article>
@endsection
