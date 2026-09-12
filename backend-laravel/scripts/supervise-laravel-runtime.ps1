$ErrorActionPreference = 'Continue'

$scriptDirectory = Split-Path -Parent $MyInvocation.MyCommand.Path
$launcher = Join-Path $scriptDirectory 'run-laravel-workers-hidden.ps1'
$mutex = [System.Threading.Mutex]::new($false, 'Local\NeuroTraderFallbackRuntimeSupervisor')
$ownsMutex = $false

try {
    try {
        $ownsMutex = $mutex.WaitOne(0)
    } catch [System.Threading.AbandonedMutexException] {
        $ownsMutex = $true
    }

    # Double-clicking the launcher is safe. The existing hidden supervisor
    # remains the sole owner and this duplicate exits immediately.
    if (-not $ownsMutex) {
        exit 0
    }

    while ($true) {
        try {
            & powershell.exe -NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File $launcher
        } catch {
            # Runtime health remains observable through ai:status. Keep the
            # supervisor alive so a transient Redis/filesystem outage can heal
            # on the next bounded reconciliation tick.
        }

        Start-Sleep -Seconds 15
    }
} finally {
    if ($ownsMutex) {
        $mutex.ReleaseMutex()
    }
    $mutex.Dispose()
}
