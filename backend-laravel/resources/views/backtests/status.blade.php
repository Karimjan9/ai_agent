@extends('layouts.app', [
    'heading' => 'Canonical Lab replay holati',
    'subtitle' => 'Immutable LabEvaluationRun queue orqali bajarilmoqda.',
])

@section('content')
    <article class="card" data-backtest-status data-status-url="{{ route('backtests.status', $backtestRun) }}">
        <h2 class="section-title">Lab run #{{ $backtestRun->id }}</h2>
        <p class="muted" id="backtest-status-text">Status: {{ $backtestRun->status }}</p>
        <p class="muted">Sahifa immutable evidence natijasi tayyor bo‘lguncha avtomatik tekshiradi.</p>
    </article>
    <script>
        (() => {
            const card = document.querySelector('[data-backtest-status]');
            const label = document.getElementById('backtest-status-text');
            const poll = async () => {
                const response = await fetch(card.dataset.statusUrl, {headers: {'Accept': 'application/json'}});
                if (!response.ok) return;
                const data = await response.json();
                label.textContent = `Status: ${data.status}`;
                if (data.status === 'completed' && data.result_url) window.location.href = data.result_url;
                if (['technical_error', 'skipped'].includes(data.status)) label.textContent += ` — ${data.error || 'technical queue xatosi'}`;
                if (!['completed', 'technical_error', 'skipped'].includes(data.status)) window.setTimeout(poll, 5000);
            };
            window.setTimeout(poll, 1000);
        })();
    </script>
@endsection
