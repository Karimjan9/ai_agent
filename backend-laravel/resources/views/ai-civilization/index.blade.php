@extends('layouts.app', [
    'heading' => 'AI Civilization',
    'subtitle' => 'Artificial Quant Organization: role agents, council decisions, research credits, collective memory va civilization goals.',
])

@section('content')
    <section class="grid metrics">
        <article class="card tone-blue">
            <div class="metric-label">Members</div>
            <div class="metric-value">{{ $metrics['agents'] }}</div>
        </article>
        <article class="card tone-green">
            <div class="metric-label">Research Credits</div>
            <div class="metric-value">{{ $metrics['credits'] }}</div>
        </article>
        <article class="card tone-yellow">
            <div class="metric-label">Avg Reputation</div>
            <div class="metric-value">{{ $metrics['avg_reputation'] }}%</div>
        </article>
        <article class="card tone-red">
            <div class="metric-label">Council Decisions</div>
            <div class="metric-value">{{ $metrics['decisions'] }}</div>
        </article>
        <article class="card tone-blue">
            <div class="metric-label">Collective Memory</div>
            <div class="metric-value">{{ $metrics['memories'] }}</div>
        </article>
        <article class="card tone-green">
            <div class="metric-label">Institutional Knowledge</div>
            <div class="metric-value">{{ $metrics['knowledge'] }}</div>
        </article>
    </section>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Council Sync</h2>
        <form class="form-grid" method="POST" action="{{ route('ai-civilization.sync') }}">
            @csrf
            <button type="submit">Sync Civilization</button>
        </form>
        @if ($latestDecision)
            <p class="muted" style="margin-top: 12px;">
                Latest council #{{ $latestDecision->id }}: {{ $latestDecision->final_decision }} · {{ $latestDecision->rationale }}
            </p>
        @else
            <p class="muted" style="margin-top: 12px;">Hali council decision yo'q.</p>
        @endif
    </article>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Agent Society</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Member</th>
                    <th>Role</th>
                    <th>Credits</th>
                    <th>Reputation</th>
                    <th>Vote</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($agents as $agent)
                    <tr>
                        <td>{{ $agent->display_name }}</td>
                        <td>{{ $agent->role_label }}</td>
                        <td>{{ round((float) $agent->credits_balance, 2) }}</td>
                        <td>{{ round((float) $agent->reputation_score, 2) }}%</td>
                        <td>{{ round((float) $agent->vote_weight, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">Hali civilization member yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Civilization Goals</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Goal</th>
                    <th>Priority</th>
                    <th>Progress</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($goals as $goal)
                    <tr>
                        <td>
                            {{ $goal->title }}<br>
                            <span class="muted">{{ $goal->owner?->display_name ?? '-' }}</span>
                        </td>
                        <td>{{ round((float) $goal->priority_score, 2) }}%</td>
                        <td>{{ round((float) $goal->progress_score, 2) }}%</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">Hali goal yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Council Decisions</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Proposal</th>
                    <th>Decision</th>
                    <th>Consensus</th>
                    <th>Votes</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($decisions as $decision)
                    <tr>
                        <td>{{ $decision->title }}</td>
                        <td>{{ $decision->final_decision }}</td>
                        <td>{{ round((float) $decision->consensus_score, 2) }}%</td>
                        <td>{{ $decision->votes_count }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">Hali council decision yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Council Votes</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Agent</th>
                    <th>Vote</th>
                    <th>Confidence</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($votes as $vote)
                    <tr>
                        <td>{{ $vote->agent?->display_name ?? '-' }}</td>
                        <td>{{ strtoupper($vote->vote) }}</td>
                        <td>{{ round((float) $vote->confidence_score, 2) }}%</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">Hali vote yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Internal Economy</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Agent</th>
                    <th>Credits</th>
                    <th>Reason</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($creditEvents as $event)
                    <tr>
                        <td>{{ $event->agent?->display_name ?? '-' }}</td>
                        <td>{{ round((float) $event->amount, 2) }}</td>
                        <td>{{ $event->reason }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">Hali credit event yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="card">
            <h2 class="section-title">Collective Memory</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Memory</th>
                    <th>Type</th>
                    <th>Impact</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($memories as $memory)
                    <tr>
                        <td>{{ $memory->title }}</td>
                        <td>{{ $memory->memory_type }}</td>
                        <td>{{ round((float) $memory->impact_score, 2) }}%</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">Hali collective memory yo'q.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </section>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Institutional Knowledge</h2>
        <table class="table">
            <thead>
            <tr>
                <th>Knowledge</th>
                <th>Type</th>
                <th>Confidence</th>
                <th>Evidence</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($knowledge as $item)
                <tr>
                    <td>{{ $item->title }}</td>
                    <td>{{ $item->knowledge_type }}</td>
                    <td>{{ round((float) $item->confidence_score, 2) }}%</td>
                    <td>{{ $item->evidence_count }}</td>
                    <td>{{ $item->preservation_status }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">Hali institutional knowledge yo'q.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </article>
@endsection
