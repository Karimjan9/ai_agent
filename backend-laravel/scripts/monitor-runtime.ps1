param(
    [int]$IntervalSeconds = 30,
    [switch]$Once
)

$ErrorActionPreference = 'SilentlyContinue'
$backendRoot = Split-Path -Parent $PSScriptRoot
$php = 'C:\x_programs\xamp\php\php.exe'
$pm2 = Join-Path $backendRoot 'node_modules\.bin\pm2.cmd'
$redisCli = 'C:\x_programs\OSPanel\modules\redis\Redis-7.0\redis-cli.exe'
$logPath = Join-Path $backendRoot 'storage\logs\runtime-monitor.ndjson'
$interval = [Math]::Max(5, [Math]::Min(3600, $IntervalSeconds))

New-Item -ItemType Directory -Force -Path (Split-Path -Parent $logPath) | Out-Null
$mutex = New-Object System.Threading.Mutex($false, 'Global\NeuroTraderRuntimeMonitor')
if (-not $mutex.WaitOne(0)) {
    exit 0
}

function Get-Pm2Summary {
    if (-not (Test-Path -LiteralPath $pm2)) { return @() }
    # PowerShell's case-insensitive JSON converter rejects PM2's inherited
    # Windows environment because it contains both USERNAME and username.
    # Parse with Node, then return only non-sensitive supervisor metrics.
    $node = Get-Command node -ErrorAction SilentlyContinue
    if (-not $node) { return @() }
    $summaryScript = Join-Path $backendRoot 'scripts\pm2-summary.cjs'
    $summaryJson = & $node.Source $summaryScript $pm2 2>$null
    try {
        $parsed = ConvertFrom-Json -InputObject ($summaryJson -join '')
        return @($parsed.items)
    } catch { return @() }
}

function Get-ProcessSummary {
    $items = Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -in @('php.exe', 'python.exe', 'node.exe', 'redis-server.exe') }
    return @($items | ForEach-Object {
        $command = [string]$_.CommandLine
        $role = if ($_.Name -eq 'redis-server.exe') { 'redis' }
            elseif ($_.Name -eq 'python.exe' -and $command -match 'run-ai-service') { 'ai-service' }
            elseif ($_.Name -eq 'python.exe' -and $command -match 'app\.replay_worker') { 'ai-replay-worker' }
            elseif ($_.Name -eq 'node.exe' -and $command -match 'pm2') { 'pm2' }
            elseif ($command -match 'schedule:headless-work') { 'scheduler' }
            elseif ($command -match 'queue:work') { 'queue-worker' }
            elseif ($command -match 'artisan') { 'artisan' }
            else { 'other' }
        [ordered]@{ role = $role; pid = $_.ProcessId; name = $_.Name }
    } | Where-Object { $_.role -ne 'other' })
}

function Invoke-RuntimeSnapshot {
    $timestamp = (Get-Date).ToUniversalTime().ToString('o')
    $redis = if (Test-Path -LiteralPath $redisCli) { ((& $redisCli -h 127.0.0.1 -p 6379 ping 2>$null | Out-String).Trim()) } else { 'cli_missing' }
    $laravelRaw = & $php artisan system:runtime-monitor --json --persist --no-ansi 2>$null | Select-Object -Last 1
    $laravel = $null
    try { $laravel = $laravelRaw | ConvertFrom-Json } catch { }
    $ai = $null
    try { $ai = Invoke-RestMethod -Uri 'http://127.0.0.1:9000/health' -TimeoutSec 4 } catch { }
    $snapshot = [ordered]@{
        protocol = 'runtime_monitor_shell_v1'
        checked_at = $timestamp
        redis_ping = $redis
        laravel = $laravel
        ai_service = if ($ai) { [ordered]@{ status = $ai.status; service = $ai.service; replay = $ai.replay_liveness } } else { [ordered]@{ status = 'unreachable' } }
        pm2 = Get-Pm2Summary
        processes = Get-ProcessSummary
    }
    $line = $snapshot | ConvertTo-Json -Depth 12 -Compress
    Add-Content -LiteralPath $logPath -Value $line
    Write-Output $line
}

try {
    do {
        Invoke-RuntimeSnapshot
        if ($Once) { break }
        Start-Sleep -Seconds $interval
    } while ($true)
} finally {
    $mutex.ReleaseMutex() | Out-Null
    $mutex.Dispose()
}
