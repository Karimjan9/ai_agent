@extends('layouts.app', [
    'heading' => 'Evolution Proposal #' . $evolutionProposal->id,
    'subtitle' => strtoupper($evolutionProposal->strategy) . ' ' . $evolutionProposal->current_version . ' -> ' . $evolutionProposal->proposed_version,
])

@section('content')
    @if (session('success'))
        <article class="card tone-green" style="margin-bottom: 14px;">
            {{ session('success') }}
        </article>
    @endif

    @if (session('error'))
        <article class="card tone-red" style="margin-bottom: 14px;">
            {{ session('error') }}
        </article>
    @endif

    <section class="grid metrics">
        <article class="card tone-blue">
            <div class="metric-label">Strategy</div>
            <div class="metric-value">{{ strtoupper($evolutionProposal->strategy) }}</div>
        </article>
        <article class="card tone-yellow">
            <div class="metric-label">Current Score</div>
            <div class="metric-value">{{ $evolutionProposal->current_score }}</div>
        </article>
        <article class="card tone-green">
            <div class="metric-label">Version Change</div>
            <div class="metric-value">{{ $evolutionProposal->current_version }} -> {{ $evolutionProposal->proposed_version }}</div>
        </article>
    </section>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Main Problem</h2>
            <p class="muted">{{ $evolutionProposal->main_problem ?? '-' }}</p>
        </article>
        <article class="card">
            <h2 class="section-title">Status</h2>
            <p class="muted">{{ $evolutionProposal->status }}</p>
        </article>
    </section>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Reason</h2>
            <p class="muted">{{ $evolutionProposal->reason ?? '-' }}</p>
        </article>
        <article class="card">
            <h2 class="section-title">Proposal</h2>
            <p class="muted">{{ $evolutionProposal->proposal ?? '-' }}</p>
        </article>
    </section>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Old Parameters</h2>
            <pre class="code">{{ json_encode($evolutionProposal->old_parameters ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </article>
        <article class="card">
            <h2 class="section-title">New Parameters</h2>
            <pre class="code">{{ json_encode($evolutionProposal->new_parameters ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </article>
    </section>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Actions</h2>
        <div style="display:flex; flex-wrap:wrap; gap:10px;">
            @if ($evolutionProposal->status === 'pending')
                <form method="post" action="{{ route('evolution-proposals.approve', $evolutionProposal) }}">
                    @csrf
                    <button type="submit">Approve</button>
                </form>
            @endif

            @if (in_array($evolutionProposal->status, ['pending', 'approved'], true))
                <form method="post" action="{{ route('evolution-proposals.apply', $evolutionProposal) }}">
                    @csrf
                    <button type="submit">Apply & Create Version</button>
                </form>
            @endif

            @if ($evolutionProposal->status !== 'applied')
                <form method="post" action="{{ route('evolution-proposals.reject', $evolutionProposal) }}">
                    @csrf
                    <button type="submit" style="background: var(--red);">Reject</button>
                </form>
            @endif

            <a href="{{ route('evolution-proposals.index') }}" class="badge">Back to proposals</a>
        </div>
    </article>
@endsection
