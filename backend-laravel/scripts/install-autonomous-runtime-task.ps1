[CmdletBinding()]
param(
    [switch] $Remove,
    [switch] $StartNow
)

$ErrorActionPreference = 'Stop'

$taskName = 'NeuroTrader Autonomous Runtime'
$scriptDirectory = Split-Path -Parent $MyInvocation.MyCommand.Path
$launcher = Join-Path $scriptDirectory 'run-laravel-workers-hidden.vbs'

if ($Remove) {
    $existing = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
    if ($null -ne $existing) {
        Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
    }

    Write-Output "Removed scheduled task: $taskName"
    exit 0
}

if (-not (Test-Path -LiteralPath $launcher -PathType Leaf)) {
    throw "Autonomous runtime launcher is missing: $launcher"
}

$currentIdentity = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$wscript = Join-Path $env:SystemRoot 'System32\wscript.exe'
$action = New-ScheduledTaskAction -Execute $wscript -Argument ('"{0}"' -f $launcher)
$trigger = New-ScheduledTaskTrigger -AtLogOn -User $currentIdentity
$principal = New-ScheduledTaskPrincipal `
    -UserId $currentIdentity `
    -LogonType Interactive `
    -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew `
    -RestartCount 3 `
    -RestartInterval (New-TimeSpan -Minutes 1)

Register-ScheduledTask `
    -TaskName $taskName `
    -Action $action `
    -Trigger $trigger `
    -Principal $principal `
    -Settings $settings `
    -Description 'Keeps Redis, AI replay, scheduler, and bounded Laravel queue lanes available without an interactive curator.' `
    -Force | Out-Null

if ($StartNow) {
    Start-ScheduledTask -TaskName $taskName
}

$registered = Get-ScheduledTask -TaskName $taskName -ErrorAction Stop
Write-Output ([pscustomobject]@{
    task_name = $registered.TaskName
    state = [string] $registered.State
    launcher = $launcher
    starts_now = [bool] $StartNow
} | ConvertTo-Json -Compress)
