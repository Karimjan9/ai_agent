<!doctype html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'NeuroTrader Lab' }}</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --bg: #f6f7f4;
            --panel: #ffffff;
            --ink: #16201b;
            --muted: #65736b;
            --line: #dce3dd;
            --blue: #2f6f9f;
            --green: #2e7d59;
            --red: #ad3e45;
            --yellow: #a77819;
            --teal: #247a7a;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            letter-spacing: 0;
        }

        a { color: inherit; text-decoration: none; }
        .shell { display: grid; grid-template-columns: 244px 1fr; min-height: 100vh; }
        .sidebar {
            background: #17231d;
            color: #eef5ef;
            padding: 22px 16px;
            border-right: 1px solid #21352a;
        }
        .brand { display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 18px; margin-bottom: 26px; }
        .brand-mark { width: 34px; height: 34px; display: grid; place-items: center; border-radius: 8px; background: #f0b84b; color: #17231d; }
        .nav { display: grid; gap: 6px; }
        .nav a {
            display: flex;
            align-items: center;
            gap: 10px;
            min-height: 42px;
            padding: 10px 12px;
            border-radius: 8px;
            color: #b9c8be;
            font-size: 14px;
        }
        .nav a.active, .nav a:hover { background: #24372d; color: #ffffff; }
        .main { padding: 28px; }
        .topbar { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; margin-bottom: 24px; }
        h1 { margin: 0; font-size: 30px; line-height: 1.15; }
        .subtitle { color: var(--muted); margin-top: 8px; max-width: 780px; line-height: 1.55; }
        .badge { border: 1px solid var(--line); background: var(--panel); border-radius: 8px; padding: 9px 12px; color: var(--muted); font-size: 13px; white-space: nowrap; }
        .grid { display: grid; gap: 14px; }
        .metrics { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 18px;
            box-shadow: 0 8px 20px rgba(25, 36, 29, 0.04);
        }
        .metric-label { color: var(--muted); font-size: 13px; margin-bottom: 10px; }
        .metric-value { font-size: 25px; font-weight: 800; overflow-wrap: anywhere; }
        .tone-blue { border-top: 4px solid var(--blue); }
        .tone-green { border-top: 4px solid var(--green); }
        .tone-red { border-top: 4px solid var(--red); }
        .tone-yellow { border-top: 4px solid var(--yellow); }
        .split { display: grid; grid-template-columns: 1.25fr 0.75fr; gap: 14px; margin-top: 14px; }
        .section-title { font-size: 16px; font-weight: 800; margin: 0 0 12px; }
        .table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .table th, .table td { padding: 12px 10px; border-bottom: 1px solid var(--line); text-align: left; }
        .table th { color: var(--muted); font-size: 12px; text-transform: uppercase; }
        .form-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; }
        label { display: grid; gap: 7px; color: var(--muted); font-size: 12px; font-weight: 700; }
        input, select {
            min-height: 42px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fff;
            color: var(--ink);
            padding: 9px 10px;
            font: inherit;
        }
        button {
            min-height: 42px;
            border: 0;
            border-radius: 8px;
            background: var(--teal);
            color: white;
            padding: 10px 14px;
            font-weight: 800;
            cursor: pointer;
            align-self: end;
        }
        .code {
            background: #17231d;
            color: #eaf3ed;
            border-radius: 8px;
            padding: 14px;
            overflow: auto;
            min-height: 120px;
            font-size: 13px;
        }
        .muted { color: var(--muted); }

        @media (max-width: 900px) {
            .shell { grid-template-columns: 1fr; }
            .sidebar { position: static; }
            .metrics, .split, .form-grid { grid-template-columns: 1fr; }
            .topbar { display: grid; }
            .main { padding: 18px; }
        }
    </style>
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <div class="brand">
            <span class="brand-mark">NT</span>
            <span>NeuroTrader Lab</span>
        </div>
        <nav class="nav">
            <a class="{{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">Dashboard</a>
            <a class="{{ request()->routeIs('market-data') ? 'active' : '' }}" href="{{ route('market-data') }}">Market Data</a>
            <a class="{{ request()->routeIs('strategy-lab.*') ? 'active' : '' }}" href="{{ route('strategy-lab.index') }}">Strategy Lab</a>
            <a class="{{ request()->routeIs('training-sessions.*') ? 'active' : '' }}" href="{{ route('training-sessions.index') }}">Training Sessions</a>
            <a class="{{ request()->routeIs('training-logs.*') ? 'active' : '' }}" href="{{ route('training-logs.index') }}">Training Logs</a>
            <a class="{{ request()->routeIs('model-versions.*') ? 'active' : '' }}" href="{{ route('model-versions.index') }}">Model Versions</a>
            <a class="{{ request()->routeIs('evolution-proposals.*') ? 'active' : '' }}" href="{{ route('evolution-proposals.index') }}">Evolution Proposals</a>
            <a class="{{ request()->routeIs('backtests.*') ? 'active' : '' }}" href="{{ route('backtests.index') }}">Run Backtest</a>
            <a class="{{ request()->routeIs('backtest-results') ? 'active' : '' }}" href="{{ route('backtest-results') }}">Backtest Results</a>
            <a class="{{ request()->routeIs('mistake-journal') ? 'active' : '' }}" href="{{ route('mistake-journal') }}">Mistake Journal</a>
            <a class="{{ request()->routeIs('ai-daily-report') ? 'active' : '' }}" href="{{ route('ai-daily-report') }}">AI Daily Report</a>
            <a class="{{ request()->routeIs('daily-reports.*') ? 'active' : '' }}" href="{{ route('daily-reports.index') }}">Daily Reports</a>
        </nav>
    </aside>
    <main class="main">
        <div class="topbar">
            <div>
                <h1>{{ $heading ?? 'Dashboard' }}</h1>
                @isset($subtitle)
                    <div class="subtitle">{{ $subtitle }}</div>
                @endisset
            </div>
            <div class="badge">XAU/USD · M15/H1 · Paper backtest</div>
        </div>
        @yield('content')
    </main>
</div>
@stack('scripts')
</body>
</html>
