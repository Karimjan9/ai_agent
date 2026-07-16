@extends('layouts.app', [
    'heading' => 'Training Sessions',
    'subtitle' => 'Run All Agents natijasida yaratilgan AI training sessiyalar tarixi.',
])

@section('content')
    @if (session('success'))
        <article class="card tone-green" style="margin-bottom: 14px;">
            <p class="muted">{{ session('success') }}</p>
        </article>
    @endif

    <article class="card" style="margin-bottom: 14px;">
        <div class="topbar" style="margin-bottom: 0;">
            <div>
                <h2 class="section-title">Yangi trening</h2>
                <p class="muted">Tanlangan instrument uchun barcha agentlarni H1 tarixiy ma'lumotlarda qayta test qiling.</p>
            </div>
            <form method="post" action="{{ route('strategy-lab.run-all') }}">
                @csrf
                <label>Symbol
                    <select name="symbol">
                        <option value="XAUUSD">XAUUSD</option>
                        <option value="EURUSD">EURUSD</option>
                        <option value="GBPUSD">GBPUSD</option>
                    </select>
                </label>
                <input type="hidden" name="timeframe" value="H1">
                <input type="hidden" name="initial_balance" value="10000">
                <input type="hidden" name="risk_per_trade" value="1">
                <button type="submit">Start New Training Session</button>
            </form>
        </div>
    </article>

    <article class="card">
        <h2 class="section-title">Sessiyalar</h2>
        <table class="table">
            <thead>
            <tr>
                <th>ID</th>
                <th>Title</th>
                <th>Best Agent</th>
                <th>Worst Agent</th>
                <th>Agents</th>
                <th>Avg Winrate</th>
                <th>Avg Profit</th>
                <th>Action</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($sessions as $session)
                <tr>
                    <td>#{{ $session->id }}</td>
                    <td>{{ $session->title }}</td>
                    <td>{{ strtoupper($session->best_strategy ?? '-') }} ({{ $session->best_score }})</td>
                    <td>{{ strtoupper($session->worst_strategy ?? '-') }} ({{ $session->worst_score }})</td>
                    <td>{{ $session->agents_count }}</td>
                    <td>{{ $session->average_winrate }}%</td>
                    <td>{{ $session->average_profit }}%</td>
                    <td>
                        <a class="muted" href="{{ route('training-sessions.show', $session) }}">Ko'rish</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">Hali training session yaratilmagan.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </article>

    @if ($sessions->hasPages())
        <article class="card" style="margin-top: 14px;">
            {{ $sessions->links() }}
        </article>
    @endif
@endsection
