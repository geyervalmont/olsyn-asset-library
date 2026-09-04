param(
  [string]$Destination = (Join-Path $env:APPDATA "pyRevit\Extensions\OPAL.extension"),
  # Register this checkout's pyrevit folder as an extension search path (needs the pyRevit CLI)
  # instead of copying, so edits are live on the next Reload.
  [switch]$Register
)
$ErrorActionPreference = "Stop"
$source = Join-Path $PSScriptRoot "pyrevit\OPAL.extension"
if (-not (Test-Path -LiteralPath $source)) { throw "Extension not found: $source" }

if ($Register) {
  if (-not (Get-Command pyrevit -ErrorAction SilentlyContinue)) { throw "pyrevit CLI not found; install pyRevit_CLI or run without -Register" }
  $paths = (Resolve-Path (Join-Path $PSScriptRoot "pyrevit")).Path
  pyrevit extensions paths add $paths
  Write-Host "Registered $paths as an extension search path. In Revit, run pyRevit → Reload."
  exit 0
}

New-Item -ItemType Directory -Path (Split-Path $Destination -Parent) -Force | Out-Null
if (Test-Path -LiteralPath $Destination) { Remove-Item -LiteralPath $Destination -Recurse -Force }
Copy-Item -LiteralPath $source -Destination $Destination -Recurse -Force

# The extension ships its own copy of opal_client; keep it in step with the package in this folder.
Remove-Item -LiteralPath (Join-Path $Destination "lib\opal_client") -Recurse -Force
Copy-Item -LiteralPath (Join-Path $PSScriptRoot "opal_client") -Destination (Join-Path $Destination "lib\opal_client") -Recurse -Force
Get-ChildItem -LiteralPath $Destination -Recurse -Directory -Filter "__pycache__" | Remove-Item -Recurse -Force

Write-Host "Installed to $Destination. In Revit, run pyRevit → Reload, then OPAL → Connect."
