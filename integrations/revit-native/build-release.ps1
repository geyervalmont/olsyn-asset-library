param(
    [Parameter(Mandatory = $true)]
    [string] $Version,
    [string] $Configuration = "Release",
    [string] $OutputDirectory = "artifacts/revit",
    [string] $Commit = "local",
    [string] $SigningCertificateBase64 = "",
    [string] $SigningPassword = ""
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$repository = Resolve-Path (Join-Path $root "../..")
$output = Join-Path $repository $OutputDirectory
$stage = Join-Path $output "stage"
$matrixPath = Join-Path $repository "apps/web/config/revit-versions.json"
$matrix = Get-Content $matrixPath -Raw | ConvertFrom-Json
$targets = @($matrix.versions)
$certificate = $null

if ($targets.Count -eq 0) {
    throw "$matrixPath contains no build targets"
}

function Invoke-DotNet {
    param([string[]] $Arguments)
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
New-Item $stage -ItemType Directory -Force | Out-Null

try {
    if ($SigningCertificateBase64) {
        $certificate = Join-Path $env:RUNNER_TEMP "opal-revit-signing.pfx"
        [IO.File]::WriteAllBytes($certificate, [Convert]::FromBase64String($SigningCertificateBase64))
        $script:signTool = Find-SignTool
    }

    Invoke-DotNet -Arguments @(
        "run",
        "--project", (Join-Path $root "tests/Opal.Client.Tests/Opal.Client.Tests.csproj"),
        "-c", $Configuration,
        "-p:Version=$Version"
    )

    foreach ($target in $targets) {
        $year = [string] $target.year
        $yearStage = Join-Path $stage "revit/$year"
        $bootstrap = Join-Path $yearStage "bootstrap"
        $versionFiles = Join-Path $yearStage "versions/$Version"
        New-Item $bootstrap -ItemType Directory -Force | Out-Null
        New-Item $versionFiles -ItemType Directory -Force | Out-Null

        $properties = @(
            "-p:Version=$Version",
            "-p:RevitYear=$year",
            "-p:RevitApiVersion=$($target.api_version)",
            "-p:RevitTargetFramework=$($target.revit_target_framework)",
            "-p:OpalClientTargetFramework=$($target.client_target_framework)"
        )

        Invoke-DotNet -Arguments (@(
            "publish", (Join-Path $root "src/Opal.Revit.Bootstrap/Opal.Revit.Bootstrap.csproj"),
            "-c", $Configuration,
            "--no-self-contained",
            "-o", $bootstrap
        ) + $properties)
        Invoke-DotNet -Arguments (@(
            "publish", (Join-Path $root "src/Opal.Revit/Opal.Revit.csproj"),
            "-c", $Configuration,
            "--no-self-contained",
            "-o", $versionFiles
        ) + $properties)

        Get-ChildItem $bootstrap -Filter "*.dll" -Recurse | ForEach-Object { Sign-File $_.FullName }
        Get-ChildItem $versionFiles -Filter "*.dll" -Recurse | ForEach-Object { Sign-File $_.FullName }

        $state = [ordered]@{
            version = $Version
            entryAssembly = "versions\$Version\Opal.Revit.dll"
        }
        $state | ConvertTo-Json | Set-Content (Join-Path $yearStage "current.json") -Encoding utf8

        $package = Join-Path $output "OPAL-Revit-$year-Package.zip"
        Compress-Archive -Path (Join-Path $versionFiles "*") -DestinationPath $package -CompressionLevel Optimal
    }

    $targets.year | ForEach-Object { [string] $_ } |
        Set-Content (Join-Path $stage "supported-versions.txt") -Encoding utf8

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
    $publishedAt = [DateTimeOffset]::UtcNow.ToString("o")

    foreach ($target in $targets) {
        $year = [int] $target.year
        $package = Join-Path $output "OPAL-Revit-$year-Package.zip"
        $manifest = [ordered]@{
            version = $Version
            published_at = $publishedAt
            revit_version = $year
            minimum_revit = $year
            target_framework = [string] $target.revit_target_framework
            runtime = [string] $target.runtime
            verification = [string] $target.verification
            commit = $Commit
            notes = "Native OPAL connector for Revit $year. Feature code is shared across every supported Revit build."
            channel = "production"
            installer = [ordered]@{
                name = "OPAL-Revit-Setup.exe"
                sha256 = (Get-FileHash $installer -Algorithm SHA256).Hash.ToLowerInvariant()
                bytes = (Get-Item $installer).Length
            }
            package = [ordered]@{
                name = "OPAL-Revit-$year-Package.zip"
                sha256 = (Get-FileHash $package -Algorithm SHA256).Hash.ToLowerInvariant()
                bytes = (Get-Item $package).Length
            }
        }
        $manifestPath = Join-Path $output "release-manifest-$year.json"
        $manifest | ConvertTo-Json -Depth 5 | Set-Content $manifestPath -Encoding utf8

        if ($year -eq [int] $matrix.default_year) {
            Copy-Item $manifestPath (Join-Path $output "release-manifest.json")
        }
    }
}
finally {
    if ($certificate -and (Test-Path $certificate)) {
        Remove-Item $certificate -Force
    }
}
