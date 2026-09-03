param(
  [string]$Destination = (Join-Path $env:APPDATA "pyRevit\Extensions\OPAL.extension")
)
$ErrorActionPreference = "Stop"
$source = Join-Path $PSScriptRoot "pyrevit\OPAL.extension"
if (-not (Test-Path -LiteralPath $source)) { throw "Extension not found: $source" }

New-Item -ItemType Directory -Path (Split-Path $Destination -Parent) -Force | Out-Null
if (Test-Path -LiteralPath $Destination) { Remove-Item -LiteralPath $Destination -Recurse -Force }
Copy-Item -LiteralPath $source -Destination $Destination -Recurse -Force

# The extension ships its own copy of opal_client; keep it in step with the package in this folder.
Remove-Item -LiteralPath (Join-Path $Destination "lib\opal_client") -Recurse -Force
Copy-Item -LiteralPath (Join-Path $PSScriptRoot "opal_client") -Destination (Join-Path $Destination "lib\opal_client") -Recurse -Force

$config = Join-Path $env:APPDATA "OPAL\config.json"
if (-not (Test-Path -LiteralPath $config)) {
  New-Item -ItemType Directory -Path (Split-Path $config -Parent) -Force | Out-Null
  Copy-Item -LiteralPath (Join-Path $PSScriptRoot "config.example.json") -Destination $config
  Write-Host "Wrote a starter config to $config — set your API token, drive and mount letter."
}
Write-Host "Installed to $Destination. In Revit, run pyRevit → Reload."
