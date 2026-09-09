param(
    [Parameter(Mandatory = $true)]
    [string] $Version,
    [string] $Configuration = "Release",
    [string] $OutputDirectory = "artifacts/revit",
    [string] $Commit = "local",
    [string] $Channel = "development",
    [string] $SigningCertificateBase64 = "",
    [string] $SigningPassword = ""
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$repository = Resolve-Path (Join-Path $root "../..")
$output = Join-Path $repository $OutputDirectory
$stage = Join-Path $output "stage"
$bootstrap = Join-Path $stage "bootstrap"
$versionFiles = Join-Path $stage "version"
$certificate = $null

function Invoke-DotNet {
    param([Parameter(ValueFromRemainingArguments = $true)][string[]] $Arguments)
    & dotnet @Arguments
    if ($LASTEXITCODE -ne 0) { throw "dotnet failed with exit code $LASTEXITCODE" }
}

function Find-SignTool {
    $kits = Join-Path ${env:ProgramFiles(x86)} "Windows Kits\10\bin"
    $tool = Get-ChildItem $kits -Filter signtool.exe -Recurse -ErrorAction SilentlyContinue |
        Where-Object { $_.FullName -match '\\x64\\signtool.exe$' } |
        Sort-Object FullName -Descending |
        Select-Object -First 1
    if ($null -eq $tool) { throw "signtool.exe is not installed" }
    return $tool.FullName
}

function Sign-File {
    param([string] $Path)
    if ($null -eq $certificate) { return }
    & $script:signTool sign /fd SHA256 /tr "http://timestamp.digicert.com" /td SHA256 /f $certificate /p $SigningPassword $Path
    if ($LASTEXITCODE -ne 0) { throw "Code signing failed for $Path" }
}

if (Test-Path $output) {
    Remove-Item $output -Recurse -Force
}
New-Item $bootstrap -ItemType Directory -Force | Out-Null
New-Item $versionFiles -ItemType Directory -Force | Out-Null

try {
    if ($SigningCertificateBase64) {
        $certificate = Join-Path $env:RUNNER_TEMP "opal-revit-signing.pfx"
        [IO.File]::WriteAllBytes($certificate, [Convert]::FromBase64String($SigningCertificateBase64))
        $script:signTool = Find-SignTool
    }

    Invoke-DotNet @("run", "--project", (Join-Path $root "tests/Opal.Client.Tests/Opal.Client.Tests.csproj"), "-c", $Configuration, "-p:Version=$Version")
    Invoke-DotNet @("publish", (Join-Path $root "src/Opal.Revit.Bootstrap/Opal.Revit.Bootstrap.csproj"), "-c", $Configuration, "-p:Version=$Version", "--no-self-contained", "-o", $bootstrap)
    Invoke-DotNet @("publish", (Join-Path $root "src/Opal.Revit/Opal.Revit.csproj"), "-c", $Configuration, "-p:Version=$Version", "--no-self-contained", "-o", $versionFiles)

    Get-ChildItem $bootstrap -Filter "*.dll" | ForEach-Object { Sign-File $_.FullName }
    Get-ChildItem $versionFiles -Filter "*.dll" | ForEach-Object { Sign-File $_.FullName }

    $state = [ordered]@{
        version = $Version
        entryAssembly = "versions\$Version\Opal.Revit.dll"
    }
    $state | ConvertTo-Json | Set-Content (Join-Path $stage "current.json") -Encoding utf8

    $package = Join-Path $output "OPAL-Revit-Package.zip"
    Compress-Archive -Path (Join-Path $versionFiles "*") -DestinationPath $package -CompressionLevel Optimal

    $env:OPAL_VERSION = $Version
    $env:OPAL_ARTIFACT_ROOT = $stage
    $env:OPAL_RELEASE_OUTPUT = $output
    $iscc = (Get-Command ISCC.exe -ErrorAction SilentlyContinue).Source
    if (-not $iscc) {
        $iscc = Join-Path ${env:ProgramFiles(x86)} "Inno Setup 6\ISCC.exe"
    }
    if (-not (Test-Path $iscc)) { throw "Inno Setup 6 is not installed" }
    & $iscc (Join-Path $root "installer/OPAL.Revit.iss")
    if ($LASTEXITCODE -ne 0) { throw "Inno Setup failed with exit code $LASTEXITCODE" }

    $installer = Join-Path $output "OPAL-Revit-Setup.exe"
    Sign-File $installer

    $manifest = [ordered]@{
        version = $Version
        published_at = [DateTimeOffset]::UtcNow.ToString("o")
        minimum_revit = 2027
        commit = $Commit
        notes = "Native OPAL connector for Revit 2027 with account settings and verified automatic updates."
        channel = $Channel
        installer = [ordered]@{
            name = "OPAL-Revit-Setup.exe"
            sha256 = (Get-FileHash $installer -Algorithm SHA256).Hash.ToLowerInvariant()
            bytes = (Get-Item $installer).Length
        }
        package = [ordered]@{
            name = "OPAL-Revit-Package.zip"
            sha256 = (Get-FileHash $package -Algorithm SHA256).Hash.ToLowerInvariant()
            bytes = (Get-Item $package).Length
        }
    }
    $manifest | ConvertTo-Json -Depth 5 | Set-Content (Join-Path $output "release-manifest.json") -Encoding utf8
}
finally {
    if ($certificate -and (Test-Path $certificate)) {
        Remove-Item $certificate -Force
    }
}
