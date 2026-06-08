@extends('layouts.app', [
    'heading' => 'Training Log #' . $trainingLog->id,
    'subtitle' => $trainingLog->type . ' / ' . $trainingLog->status,
])

@section('content')
    <section class="grid metrics">
        <article class="card tone-blue">
            <div class="metric-label">Type</div>
            <div class="metric-value">{{ $trainingLog->type }}</div>
        </article>
        <article class="card {{ $trainingLog->status === 'failed' ? 'tone-red' : 'tone-green' }}">
            <div class="metric-label">Status</div>
            <div class="metric-value">{{ $trainingLog->status }}</div>
        </article>
        <article class="card tone-yellow">
            <div class="metric-label">Training Session</div>
            <div class="metric-value">
                @if ($trainingLog->trainingSession)
                    #{{ $trainingLog->trainingSession->id }}
                @else
                    -
                @endif
            </div>
        </article>
    </section>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Message</h2>
            <p class="muted">{{ $trainingLog->message ?? '-' }}</p>
        </article>
        <article class="card">
            <h2 class="section-title">Error</h2>
            <p class="muted">{{ $trainingLog->error_message ?? '-' }}</p>
        </article>
    </section>

    <section class="split">
        <article class="card">
            <h2 class="section-title">Timing</h2>
            <p class="muted">Started: {{ $trainingLog->started_at?->format('Y-m-d H:i:s') ?? '-' }}</p>
            <p class="muted">Finished: {{ $trainingLog->finished_at?->format('Y-m-d H:i:s') ?? '-' }}</p>
        </article>
        <article class="card">
            <h2 class="section-title">Links</h2>
            <div style="display:flex; flex-wrap:wrap; gap:10px;">
                <a href="{{ route('training-logs.index') }}" class="badge">Back to logs</a>
                @if ($trainingLog->trainingSession)
                    <a href="{{ route('training-sessions.show', $trainingLog->trainingSession) }}" class="badge">Open Session</a>
                @endif
            </div>
        </article>
    </section>

    <article class="card" style="margin-top: 14px;">
        <h2 class="section-title">Context</h2>
        <pre class="code">{{ json_encode($trainingLog->context ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    </article>
@endsection
