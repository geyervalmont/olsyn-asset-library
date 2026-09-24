param(
  [Parameter(Mandatory=$true)][string] $Version,
  [Parameter(Mandatory=$true)][string] $Commit,
  [Parameter(Mandatory=$true)][string] $DriverInstaller,
  [string] $SigningCertificateBase64 = '',
  [string] $SigningPassword = ''
)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$output = Join-Path $repo 'artifacts/drive'
$publish = Join-Path $env:RUNNER_TEMP 'opal-drive-publish'
New-Item -ItemType Directory -Force $output, $publish | Out-Null
& dotnet publish (Join-Path $PSScriptRoot 'src/Opal.Drive.Windows') -c Release -r win-x64 --self-contained true -o $publish -p:Version=$Version -p:SourceRevisionId=$Commit
if ($LASTEXITCODE -ne 0) { throw 'Drive publish failed' }
Copy-Item (Join-Path $PSScriptRoot 'README.md') $publish
Copy-Item (Join-Path $PSScriptRoot 'THIRD-PARTY-NOTICES.txt') $publish
$certificate = $null
try {
  if ($SigningCertificateBase64) {
    $certificate = Join-Path $env:RUNNER_TEMP 'opal-drive-signing.pfx'
    [IO.File]::WriteAllBytes($certificate,[Convert]::FromBase64String($SigningCertificateBase64))
    $signTool = Get-ChildItem "${env:ProgramFiles(x86)}/Windows Kits/10/bin" -Filter signtool.exe -Recurse | Where-Object { $_.FullName -match '\\x64\\signtool.exe$' } | Sort-Object FullName -Descending | Select-Object -First 1
    if (!$signTool) { throw 'signtool is unavailable' }
    & $signTool.FullName sign /fd SHA256 /tr 'http://timestamp.digicert.com' /td SHA256 /f $certificate /p $SigningPassword (Join-Path $publish 'OPAL-Drive.exe')
    if ($LASTEXITCODE -ne 0) { throw 'Drive signing failed' }
  }
  $env:OPAL_DRIVE_VERSION=$Version
  $env:OPAL_DRIVE_PUBLISH=$publish
  $env:OPAL_DRIVE_OUTPUT=$output
  $env:OPAL_DRIVE_DRIVER=(Resolve-Path $DriverInstaller).Path
  & "${env:ProgramFiles(x86)}/Inno Setup 6/ISCC.exe" (Join-Path $PSScriptRoot 'installer/OPAL.Drive.iss')
  if ($LASTEXITCODE -ne 0) { throw 'Drive installer compilation failed' }
  $installer=Join-Path $output 'OPAL-Drive-Setup.exe'
  if ($certificate) {
    & $signTool.FullName sign /fd SHA256 /tr 'http://timestamp.digicert.com' /td SHA256 /f $certificate /p $SigningPassword $installer
    if ($LASTEXITCODE -ne 0) { throw 'Installer signing failed' }
  }
  $manifest=@{
    client='drive'; version=$Version; commit=$Commit; published_at=[DateTime]::UtcNow.ToString('o');
    host='Windows 10/11 x64'; driver='Dokany 2.3.1.1000'; signed=[bool]$certificate;
    installer=@{name='OPAL-Drive-Setup.exe';bytes=(Get-Item $installer).Length;sha256=(Get-FileHash $installer -Algorithm SHA256).Hash.ToLowerInvariant()}
  }
  $manifest | ConvertTo-Json -Depth 5 | Set-Content (Join-Path $output 'release-manifest.json') -Encoding utf8
} finally { if ($certificate) { Remove-Item $certificate -Force } }
