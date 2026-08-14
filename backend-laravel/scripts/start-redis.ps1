$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$redisRoot = 'C:\x_programs\OSPanel\modules\redis\Redis-7.0'
$redisExe = Join-Path $redisRoot 'redis-server.exe'
$redisCli = Join-Path $redisRoot 'redis-cli.exe'
$redisConfig = Join-Path $projectRoot 'config\redis-local.conf'
$redisData = Join-Path $projectRoot 'storage\framework\redis'

if (-not (Test-Path -LiteralPath $redisExe)) {
    throw "Redis server binary not found: $redisExe"
}
if (-not (Test-Path -LiteralPath $redisCli)) {
    throw "Redis CLI binary not found: $redisCli"
}
if (-not (Test-Path -LiteralPath $redisConfig)) {
    throw "Redis project config not found: $redisConfig"
}

New-Item -ItemType Directory -Force -Path $redisData | Out-Null

$existing = Get-CimInstance Win32_Process -Filter "Name = 'redis-server.exe'" |
    Where-Object { $_.CommandLine -like "*$redisConfig*" }

if (-not $existing) {
    Start-Process -FilePath $redisExe -ArgumentList @($redisConfig) -WindowStyle Hidden
}

$deadline = (Get-Date).AddSeconds(15)
do {
    try {
        $ping = (& $redisCli -h 127.0.0.1 -p 6379 ping 2>$null | Out-String).Trim()
        if ($ping -eq 'PONG') {
            exit 0
        }
    } catch {
        # Redis may need a moment to bind its loopback socket.
    }
    Start-Sleep -Milliseconds 250
} while ((Get-Date) -lt $deadline)

throw 'Project Redis did not answer PING on 127.0.0.1:6379 within 15 seconds.'
