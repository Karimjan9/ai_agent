@extends('layouts.app', [
    'heading' => 'Market Data',
    'subtitle' => 'Candle data holati, provider mapping va update workflow.',
])

@section('content')
    @if (session('success'))
        <article class="card tone-green" style="margin-bottom: 14px;">
            <p class="muted">{{ session('success') }}</p>
        </article>
    @endif

    @if (session('error'))
        <article class="card tone-red" style="margin-bottom: 14px;">
            <p class="muted">{{ session('error') }}</p>
        </article>
    @endif

    <article class="card" style="margin-bottom: 14px;">
        <div class="topbar" style="margin-bottom: 0;">
            <div>
                <h2 class="section-title">Data update</h2>
                <p class="muted">{{ strtoupper(config('services.market_data.provider')) }} provider orqali aktiv instrumentlarning H1 candle ma'lumotlarini yangilang.</p>
            </div>
            <form method="post" action="{{ route('market-data.update') }}" style="display:flex; gap:10px; flex-wrap:wrap;">
                @csrf
                <select name="symbol">
                    @foreach ($symbols as $item)
                        <option value="{{ $item['symbol']->symbol }}">{{ $item['symbol']->symbol }}</option>
                    @endforeach
                </select>
                <input type="hidden" name="timeframe" value="H1">
                <input type="hidden" name="limit" value="5000">
                <button type="submit">Update H1</button>
            </form>
        </div>
    </article>

    <article class="card">
        <h2 class="section-title">Candle status</h2>
        <table class="table">
            <thead>
            <tr>
                <th>Symbol</th>
                <th>Name</th>
                <th>Provider Symbol</th>
                <th>Market Type</th>
                <th>Candles</th>
                <th>Last Candle</th>
                <th>Sync Status</th>
                <th>Pending gap / retry</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($symbols as $item)
                <tr>
                    <td>{{ $item['symbol']->symbol }}</td>
                    <td>{{ $item['symbol']->name ?? '-' }}</td>
                    <td>{{ $item['symbol']->provider_symbol ?? '-' }}</td>
                    <td>{{ $item['symbol']->market_type }}</td>
                    <td>{{ $item['count'] }}</td>
                    <td class="muted">{{ $item['last_candle']?->time?->format('Y-m-d H:i') ?? '-' }}</td>
                    <td>{{ $item['sync_state']?->status ?? 'unknown' }}</td>
                    <td class="muted">
                        @if ($item['sync_state']?->pending_from_at)
                            {{ $item['sync_state']->pending_from_at->format('Y-m-d H:i') }} → {{ $item['sync_state']->pending_to_at?->format('Y-m-d H:i') ?? 'now' }}
                            / {{ $item['sync_state']->retry_count }}
                        @else
                            -
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">Active market symbol topilmadi.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </article>
@endsection
