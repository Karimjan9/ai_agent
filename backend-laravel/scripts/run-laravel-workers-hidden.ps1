$ErrorActionPreference = 'Stop'

$scriptDirectory = Split-Path -Parent $MyInvocation.MyCommand.Path
$backendRoot = Split-Path -Parent $scriptDirectory
$phpBinary = 'C:\x_programs\xamp\php\php.exe'

& powershell.exe -NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File (Join-Path $scriptDirectory 'start-redis.ps1')
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

# Do not create a second runtime when PM2 already owns the project. This
# fallback is intentionally safe to double-click and safe to run after PM2.
$projectProcesses = @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
    $_.Name -in @('php.exe', 'php-cgi.exe') -and $_.CommandLine -like "*$backendRoot*artisan*"
})

$specifications = @(
    @{ Name = 'scheduler'; Pattern = 'schedule:headless-work'; Arguments = @('artisan', 'schedule:headless-work') },
    @{ Name = 'replay'; Pattern = 'queue:work.*lab-full-validation,lab-frontier'; Arguments = @('artisan', 'queue:work', 'redis', '--queue=lab-full-validation,lab-frontier', '--sleep=1', '--tries=0', '--timeout=4200', '--memory=2048', '--max-time=3600') },
    @{ Name = 'screening'; Pattern = 'queue:work.*lab-screening'; Arguments = @('artisan', 'queue:work', 'redis', '--queue=lab-screening,lab-xauusd,lab-eurusd,lab-gbpusd', '--sleep=1', '--tries=0', '--timeout=2400', '--memory=2048', '--max-time=3600') },
    @{ Name = 'learning'; Pattern = 'queue:work.*lab-learning'; Arguments = @('artisan', 'queue:work', 'redis', '--queue=lab-learning', '--sleep=1', '--tries=0', '--timeout=900', '--memory=1024', '--max-time=3600') }
)

foreach ($specification in $specifications) {
    $alreadyRunning = $projectProcesses | Where-Object { $_.CommandLine -match $specification.Pattern }
    if ($alreadyRunning) {
        continue
    }

    $safeName = $specification.Name
    $stdout = Join-Path $backendRoot "storage\logs\fallback-$safeName.out.log"
    $stderr = Join-Path $backendRoot "storage\logs\fallback-$safeName.err.log"
    Start-Process -FilePath $phpBinary `
        -ArgumentList $specification.Arguments `
        -WorkingDirectory $backendRoot `
        -WindowStyle Hidden `
        -RedirectStandardOutput $stdout `
        -RedirectStandardError $stderr
}
