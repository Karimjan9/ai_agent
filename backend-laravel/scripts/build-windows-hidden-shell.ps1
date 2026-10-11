$ErrorActionPreference = 'Stop'
$scriptDirectory = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectRoot = Split-Path -Parent (Split-Path -Parent $scriptDirectory)
$outputDirectory = Join-Path $projectRoot '.runtime\headless-shell'
$source = Join-Path $scriptDirectory 'windows-hidden-shell.cs'
$compiler = Join-Path $env:SystemRoot 'Microsoft.NET\Framework64\v4.0.30319\csc.exe'
if (-not (Test-Path -LiteralPath $compiler -PathType Leaf)) {
    throw 'The Windows .NET Framework C# compiler is unavailable.'
}
New-Item -ItemType Directory -Path $outputDirectory -Force | Out-Null
$binary = Join-Path $outputDirectory 'cmd.exe'
$compilerArguments = @('/nologo', '/target:winexe', '/optimize+',
    ('/out:"{0}"' -f $binary), ('"{0}"' -f $source))
$compilerProcess = Start-Process -FilePath $compiler -ArgumentList $compilerArguments `
    -WindowStyle Hidden -Wait -PassThru
if ($compilerProcess.ExitCode -ne 0 -or -not (Test-Path -LiteralPath $binary -PathType Leaf)) {
    throw 'The hidden Windows shell could not be compiled.'
}
# PHP's implicit shell lookup searches the interpreter directory before PATH.
# Keep byte-identical private interpreters beside the GUI shell; load the
# original ini and its absolute extension directory through PHPRC.
$originalPhp = if ($env:PHP_BINARY) { $env:PHP_BINARY } else { (Get-Command php.exe -ErrorAction Stop).Source }
$originalDirectory = Split-Path -Parent $originalPhp
if ([IO.Path]::GetFullPath($originalDirectory) -eq [IO.Path]::GetFullPath($outputDirectory)) {
    throw 'Build from the original PHP installation, not the existing private runtime.'
}
$originalIni = Join-Path $originalDirectory 'php.ini'
if (-not (Test-Path -LiteralPath $originalIni -PathType Leaf)) {
    throw 'The original PHP ini is unavailable.'
}
$runtimeFiles = @((Join-Path $originalDirectory 'php.exe'), (Join-Path $originalDirectory 'php-win.exe'))
$runtimeFiles += @(Get-ChildItem -LiteralPath $originalDirectory -File -Filter '*.dll' | ForEach-Object { $_.FullName })
$hashes = @{}
foreach ($runtimeFile in $runtimeFiles) {
    $target = Join-Path $outputDirectory (Split-Path -Leaf $runtimeFile)
    $sourceHash = (Get-FileHash -LiteralPath $runtimeFile -Algorithm SHA256).Hash
    # Identical interpreters/DLLs may already be in use by live workers.
    if (-not (Test-Path -LiteralPath $target -PathType Leaf) -or
        (Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash -ne $sourceHash) {
        Copy-Item -LiteralPath $runtimeFile -Destination $target -Force
    }
    if ((Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash -ne $sourceHash) {
        throw 'Private PHP runtime bytes differ from the original installation.'
    }
    $hashes[(Split-Path -Leaf $runtimeFile)] = $sourceHash
}
[pscustomobject]@{
    original_php = $originalPhp
    original_ini = $originalIni
    files = $hashes
    shell_source_sha256 = (Get-FileHash -LiteralPath $source -Algorithm SHA256).Hash
    shell_sha256 = (Get-FileHash -LiteralPath $binary -Algorithm SHA256).Hash
} | ConvertTo-Json -Depth 3 | Set-Content -LiteralPath (Join-Path $outputDirectory 'build.json') -Encoding UTF8
Write-Output $binary
