@extends('layouts.app', [
    'heading' => 'Evolution Proposals',
    'subtitle' => 'Past natija olgan agentlar uchun yangi model version takliflari.',
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

    <article class="card">
        <h2 class="section-title">Proposal Queue</h2>
        <table class="table">
            <thead>
            <tr>
                <th>Strategy</th>
                <th>Version</th>
                <th>Score</th>
                <th>Problem</th>
                <th>Status</th>
                <th>Date</th>
                <th>Action</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($proposals as $proposal)
                <tr>
                    <td>{{ strtoupper($proposal->strategy) }}</td>
                    <td>{{ $proposal->current_version }} -> {{ $proposal->proposed_version }}</td>
                    <td>{{ $proposal->current_score }}</td>
                    <td>{{ $proposal->main_problem ?? '-' }}</td>
                    <td>
                        @php
                            $statusTone = match ($proposal->status) {
                                'approved' => 'tone-blue',
                                'applied' => 'tone-green',
                                'rejected' => 'tone-red',
                                default => 'tone-yellow',
                            };
                        @endphp
                        <span class="{{ $statusTone }}" style="display:inline-block; border-radius:8px; padding:4px 8px;">
                            {{ $proposal->status }}
                        </span>
                    </td>
                    <td>{{ $proposal->created_at->format('Y-m-d H:i') }}</td>
                    <td>
                        <a href="{{ route('evolution-proposals.show', $proposal) }}" style="color: var(--blue); font-weight: 800;">
                            Korish
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">Hali evolution proposal yaratilmagan.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </article>

    @if ($proposals->hasPages())
        <article class="card" style="margin-top: 14px;">
            {{ $proposals->links() }}
        </article>
    @endif
@endsection
