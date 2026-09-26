$ErrorActionPreference = 'Stop'

$scriptDirectory = Split-Path -Parent $MyInvocation.MyCommand.Path
$backendRoot = Split-Path -Parent $scriptDirectory
$phpBinary = 'C:\x_programs\xamp\php\php.exe'
$pythonBinary = (Get-Command python.exe -ErrorAction Stop).Source
$artisanPath = Join-Path $backendRoot 'artisan'
$aiServiceScript = Join-Path $scriptDirectory 'run-ai-service.py'
$logicalProcessorCount = [int](Get-CimInstance Win32_ComputerSystem -ErrorAction Stop).NumberOfLogicalProcessors
$screeningWorkerCount = if ($logicalProcessorCount -le 4) { 1 } else { 2 }

& powershell.exe -NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File (Join-Path $scriptDirectory 'start-redis.ps1')
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

# PM2 normally owns the Python API. The local fallback must provide the same
# complete runtime, otherwise ai:start can enable admission while every replay
# waits on an absent service. The Python entry point is itself duplicate-safe;
# this ownership check avoids creating idle standby supervisors unnecessarily.
$aiServiceRunning = @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
    $_.Name -match '^python(?:w)?\.exe$' -and $_.CommandLine -like "*$aiServiceScript*"
}).Count -gt 0
if (-not $aiServiceRunning) {
    Start-Process -FilePath $pythonBinary `
        -ArgumentList @($aiServiceScript) `
        -WorkingDirectory $backendRoot `
        -WindowStyle Hidden `
        -RedirectStandardOutput (Join-Path $backendRoot 'storage\logs\fallback-ai-service.out.log') `
        -RedirectStandardError (Join-Path $backendRoot 'storage\logs\fallback-ai-service.err.log')
}

# Do not create a second runtime when PM2 already owns the project. This
# fallback is intentionally safe to double-click and safe to run after PM2.
$projectProcesses = @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
    $_.Name -in @('php.exe', 'php-cgi.exe') -and $_.CommandLine -like "*$artisanPath*"
})

$specifications = @(
    @{ Name = 'scheduler'; Pattern = 'schedule:headless-work'; Instances = 1; Arguments = @('artisan', 'schedule:headless-work') },
    @{ Name = 'replay'; Pattern = 'queue:work.*lab-full-validation,lab-frontier'; Instances = 1; Arguments = @('artisan', 'queue:work', 'redis', '--queue=lab-full-validation,lab-frontier', '--sleep=1', '--tries=0', '--timeout=4200', '--memory=2048', '--max-time=6000') },
    # Match ecosystem.config.cjs: a four-core host has one CPU-heavy replay
    # slot, so a second PHP consumer would only reserve work that Python
    # cannot execute concurrently and create avoidable retry/release churn.
    @{ Name = 'screening'; Pattern = 'queue:work.*lab-screening'; Instances = $screeningWorkerCount; Arguments = @('artisan', 'queue:work', 'redis', '--queue=lab-screening,lab-xauusd,lab-eurusd,lab-gbpusd', '--sleep=1', '--tries=0', '--timeout=2400', '--memory=2048', '--max-time=4200') },
    @{ Name = 'learning'; Pattern = 'queue:work.*lab-learning'; Instances = 1; Arguments = @('artisan', 'queue:work', 'redis', '--queue=lab-learning', '--sleep=1', '--tries=0', '--timeout=900', '--memory=1024', '--max-time=3600') },
    # Keep the fallback topology aligned with ecosystem.config.cjs. Without
    # these lanes the headless scheduler can enqueue durable work forever
    # while no process is able to reserve it.
    @{ Name = 'market-maintenance'; Pattern = 'queue:work.*market-maintenance'; Instances = 1; Arguments = @('artisan', 'queue:work', 'redis', '--queue=market-maintenance', '--sleep=1', '--tries=0', '--timeout=900', '--memory=2048', '--max-time=3600') },
    @{ Name = 'scheduler-critical'; Pattern = 'queue:work.*scheduler-critical'; Instances = 1; Arguments = @('artisan', 'queue:work', 'redis', '--queue=scheduler-critical', '--sleep=1', '--tries=0', '--timeout=300', '--memory=1024', '--max-time=3600') },
    @{ Name = 'scheduler-ops'; Pattern = 'queue:work.*scheduler-ops'; Instances = 2; Arguments = @('artisan', 'queue:work', 'redis', '--queue=scheduler-ops', '--sleep=1', '--tries=0', '--timeout=900', '--memory=1024', '--max-time=3600') },
    @{ Name = 'scheduler-constructor'; Pattern = 'queue:work.*scheduler-constructor'; Instances = 1; Arguments = @('artisan', 'queue:work', 'redis', '--queue=scheduler-constructor', '--sleep=1', '--tries=0', '--timeout=2700', '--memory=2048', '--max-time=4500') },
    # Research remains long-running, but population construction has its own
    # serial lane above so this backlog cannot starve lifecycle recovery.
    @{ Name = 'scheduler-research'; Pattern = 'queue:work.*scheduler-research'; Instances = 1; Arguments = @('artisan', 'queue:work', 'redis', '--queue=scheduler-research', '--sleep=1', '--tries=0', '--timeout=2700', '--memory=2048', '--max-time=4500') },
    @{ Name = 'strategy-lab'; Pattern = 'queue:work.*strategy-lab'; Instances = 1; Arguments = @('artisan', 'queue:work', 'redis', '--queue=strategy-lab', '--sleep=1', '--tries=0', '--timeout=2400', '--memory=2048', '--max-time=4200') },
    @{ Name = 'backtests'; Pattern = 'queue:work.*backtests'; Instances = 1; Arguments = @('artisan', 'queue:work', 'redis', '--queue=backtests', '--sleep=1', '--tries=0', '--timeout=900', '--memory=1024', '--max-time=3600') }
)

foreach ($specification in $specifications) {
    $runningCount = @($projectProcesses | Where-Object { $_.CommandLine -match $specification.Pattern }).Count
    for ($instance = $runningCount + 1; $instance -le $specification.Instances; $instance++) {
        $safeName = if ($specification.Instances -gt 1) { "$($specification.Name)-$instance" } else { $specification.Name }
        $stdout = Join-Path $backendRoot "storage\logs\fallback-$safeName.out.log"
        $stderr = Join-Path $backendRoot "storage\logs\fallback-$safeName.err.log"
        # An absolute artisan path makes ownership visible in Win32_Process,
        # so a second launch cannot mistake another Laravel project's worker
        # for this one or create a duplicate local scheduler.
        $processArguments = @($artisanPath) + @($specification.Arguments | Select-Object -Skip 1)
        Start-Process -FilePath $phpBinary `
            -ArgumentList $processArguments `
            -WorkingDirectory $backendRoot `
            -WindowStyle Hidden `
            -RedirectStandardOutput $stdout `
            -RedirectStandardError $stderr
    }
}
