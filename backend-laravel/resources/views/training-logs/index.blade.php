@extends('layouts.app', [
    'heading' => 'Auto Training Logs',
    'subtitle' => 'Har kuni avtomatik ishlaydigan AI training workflow loglari.',
])

@section('content')
    <article class="card">
        <h2 class="section-title">Workflow loglari</h2>
        <table class="table">
            <thead>
            <tr>
                <th>ID</th>
                <th>Type</th>
                <th>Status</th>
                <th>Message</th>
                <th>Started</th>
                <th>Finished</th>
                <th>Action</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($logs as $log)
                <tr>
                    <td>#{{ $log->id }}</td>
                    <td>{{ $log->type }}</td>
                    <td>
                        @php
                            $tone = match ($log->status) {
                                'success' => 'tone-green',
                                'running' => 'tone-blue',
                                'failed' => 'tone-red',
                                default => 'tone-yellow',
                            };
                        @endphp
                        <span class="badge {{ $tone }}">{{ $log->status }}</span>
                    </td>
                    <td>{{ \Illuminate\Support\Str::limit($log->message ?? $log->error_message ?? '-', 80) }}</td>
                    <td class="muted">{{ $log->started_at?->format('Y-m-d H:i:s') ?? '-' }}</td>
                    <td class="muted">{{ $log->finished_at?->format('Y-m-d H:i:s') ?? '-' }}</td>
                    <td>
                        <a class="muted" href="{{ route('training-logs.show', $log) }}">Ko'rish</a>
                        @if ($log->training_session_id)
                            <a class="muted" style="margin-left: 10px;" href="{{ route('training-sessions.show', $log->training_session_id) }}">Session</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">Hali auto training log yaratilmagan.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </article>

    @if ($logs->hasPages())
        <article class="card" style="margin-top: 14px;">
            {{ $logs->links() }}
        </article>
    @endif
@endsection
