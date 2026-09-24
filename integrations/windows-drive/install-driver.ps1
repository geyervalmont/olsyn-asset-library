param([string] $Destination = (Join-Path $env:TEMP 'opal-dokan'))
$ErrorActionPreference = 'Stop'
New-Item -ItemType Directory -Force $Destination | Out-Null
$installer = Join-Path $Destination 'DokanSetup.exe'
Invoke-WebRequest 'https://github.com/dokan-dev/dokany/releases/download/v2.3.1.1000/DokanSetup.exe' -OutFile $installer
if ((Get-FileHash $installer -Algorithm SHA256).Hash.ToLowerInvariant() -ne 'bf602263a594f595b4fdd8c4e822172b103de93f07fd6a51a8ff69569bfd1460') { throw 'Dokan installer checksum mismatch' }
$signature = Get-AuthenticodeSignature $installer
if ($signature.Status -ne 'Valid') { throw "Dokan installer signature is not valid: $($signature.Status)" }
$process = Start-Process $installer -ArgumentList '/install /quiet /norestart' -Wait -PassThru
if ($process.ExitCode -notin @(0, 3010, 1638)) { throw "Dokan installation failed: $($process.ExitCode)" }
if ($process.ExitCode -eq 3010) { Write-Host 'Dokan requested a restart. Mount tests will confirm driver availability.' }
