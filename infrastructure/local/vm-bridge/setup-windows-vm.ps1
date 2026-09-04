# One-shot setup of the Windows dev VM for OPAL + Revit. Run as the desktop
# user over SSH (the account must be a local administrator for the hosts
# entry); everything else is per-user. Re-runnable.
#
#   ssh harrison@192.168.122.82 'powershell -NoProfile -ExecutionPolicy Bypass -Command -' < setup-windows-vm.ps1
param(
  [string]$HostAddress = "192.168.122.1",
  [string]$Share = "opal",
  [string]$DriveLetter = "M",
  [string]$PyRevitVersion = "6.5.5.26237",
  [string]$RepoOnShare = "olsyn-asset-library\integrations\revit\pyrevit\OPAL.extension",
  [switch]$SkipPyRevit
)
$ErrorActionPreference = "Stop"
$report = [ordered]@{}

# 1. hosts: the control plane and its websocket host resolve to the bridge.
$hosts = "$env:SystemRoot\System32\drivers\etc\hosts"
$wanted = "$HostAddress asset-library.test ws.asset-library.test # opal-dev"
$lines = Get-Content $hosts -ErrorAction SilentlyContinue | Where-Object { $_ -notmatch "# opal-dev$" }
try {
  Set-Content -Path $hosts -Value (@($lines) + $wanted) -Encoding ASCII
  $report.hosts = "written"
} catch {
  $report.hosts = "FAILED (not elevated?): $($_.Exception.Message)"
}

# 2. the drive: map the PrismFS share persistently.
$target = "\\$HostAddress\$Share"
if (Get-PSDrive -Name $DriveLetter -ErrorAction SilentlyContinue) { net use "${DriveLetter}:" /delete /y | Out-Null }
$mapped = $false
try {
  net use "${DriveLetter}:" $target /persistent:yes 2>&1 | Out-Null
  $mapped = Test-Path "${DriveLetter}:\materials"
} catch {}
$report.drive = if ($mapped) { "${DriveLetter}: -> $target" } else { "NOT mapped ($target unreachable?)" }

# 3. the repo checkout via the virtiofs share (tag 'codeshare').
$share = Get-CimInstance Win32_LogicalDisk | Where-Object { $_.VolumeName -eq "codeshare" -or $_.ProviderName -like "*codeshare*" } | Select-Object -First 1
if (-not $share) {
  $share = Get-CimInstance Win32_LogicalDisk | Where-Object { Test-Path (Join-Path $_.DeviceID $RepoOnShare) } | Select-Object -First 1
}
$extensionPath = $null
if ($share) {
  $extensionPath = Join-Path $share.DeviceID $RepoOnShare
  $report.share = "$($share.DeviceID) ($($share.FileSystem))"
} elseif (Test-Path "$env:USERPROFILE\opal\pyrevit\OPAL.extension") {
  $extensionPath = "$env:USERPROFILE\opal\pyrevit\OPAL.extension"
  $report.share = "virtiofs share not mounted; using the copy pushed by make revit-sync"
} else {
  $report.share = "virtiofs share not found and nothing synced; run make revit-sync or install WinFsp + virtiofs from the virtio-win ISO"
}

# 4. pyRevit (per-user installer from the official GitHub release).
$pyrevit = "$env:LOCALAPPDATA\pyRevit-Master"
if (-not $SkipPyRevit -and -not (Test-Path $pyrevit) -and -not (Get-Command pyrevit -ErrorAction SilentlyContinue)) {
  $asset = "pyRevit_${PyRevitVersion}_signed.exe"
  $url = "https://github.com/pyrevitlabs/pyRevit/releases/download/v$PyRevitVersion+2044/$asset"
  $installer = Join-Path $env:TEMP $asset
  $ProgressPreference = "SilentlyContinue"
  if (-not (Test-Path $installer)) { Invoke-WebRequest -Uri $url -OutFile $installer -UseBasicParsing }
  # Run in the interactive desktop session so any prompt is visible; an SSH
  # session cannot show dialogs and the installer would hang.
  $cmd = "`"$installer`" /VERYSILENT /NORESTART /SUPPRESSMSGBOXES /LOG=`"$env:TEMP\pyrevit-install.log`""
  schtasks /Create /TN "OPAL pyRevit install" /TR $cmd /SC ONCE /ST 00:00 /RL HIGHEST /IT /F | Out-Null
  schtasks /Run /TN "OPAL pyRevit install" | Out-Null
  $report.pyrevit = "installer started on the desktop from $url; rerun this script once it finishes to register the extension"
} else {
  $report.pyrevit = "present or skipped"
}

# 5. Register the OPAL extension straight from the share (edit on Linux, Reload in Revit).
$cli = Get-Command pyrevit -ErrorAction SilentlyContinue
if (-not $cli) { $cli = Get-ChildItem "$env:LOCALAPPDATA\pyRevit-Master", "$env:APPDATA\pyRevit-Master", "$env:ProgramFiles\pyRevit-Master" -Recurse -Filter pyrevit.exe -ErrorAction SilentlyContinue | Select-Object -First 1 }
if ($cli -and $extensionPath) {
  $exe = if ($cli.Path) { $cli.Path } else { $cli.FullName }
  $parent = Split-Path $extensionPath -Parent
  & $exe extensions paths add "$parent" 2>&1 | Out-Null
  $report.extension = "path registered: $parent"
} else {
  $report.extension = "not registered (pyrevit cli: $([bool]$cli), extension: $extensionPath)"
}

# 6. OPAL client config (token arrives via the Connect button / device link).
$configDir = Join-Path $env:APPDATA "OPAL"
New-Item -ItemType Directory -Path $configDir -Force | Out-Null
$configPath = Join-Path $configDir "config.json"
$config = if (Test-Path $configPath) { Get-Content $configPath -Raw | ConvertFrom-Json } else { [pscustomobject]@{} }
$config | Add-Member -NotePropertyName api -NotePropertyValue "http://asset-library.test" -Force
$config | Add-Member -NotePropertyName drive -NotePropertyValue "studio-share" -Force
$config | Add-Member -NotePropertyName mount -NotePropertyValue "${DriveLetter}:\" -Force
$config | Add-Member -NotePropertyName verify_tls -NotePropertyValue $false -Force
$config | ConvertTo-Json | Set-Content -Path $configPath -Encoding UTF8
$report.config = $configPath

$report.GetEnumerator() | ForEach-Object { "{0,-10} {1}" -f $_.Key, $_.Value }
