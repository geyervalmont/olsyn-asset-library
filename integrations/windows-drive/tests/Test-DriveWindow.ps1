param([Parameter(Mandatory = $true)][string]$ApplicationPath)
$ErrorActionPreference = 'Stop'

# Run in the desktop user's session. This tests actual visible windows, not just
# whether the process survives. Use a fresh Windows profile without an account.
$settingsPath = Join-Path $env:LOCALAPPDATA 'Olsyn/OPAL/Drive/settings.json'
if ((Test-Path $settingsPath) -and (Get-Content $settingsPath -Raw | ConvertFrom-Json).ProtectedToken) {
    throw 'Run this test in a profile without a connected OPAL Drive account.'
}
if (Get-Process OPAL-Drive -ErrorAction SilentlyContinue) { throw 'Close OPAL Drive before running its window test.' }

Add-Type @'
using System;
using System.Runtime.InteropServices;
using System.Text;
public static class DriveWindowTest {
    private delegate bool WindowCallback(IntPtr window, IntPtr parameter);
    [DllImport("user32.dll")] private static extern bool EnumWindows(WindowCallback callback, IntPtr parameter);
    [DllImport("user32.dll")] private static extern uint GetWindowThreadProcessId(IntPtr window, out int processId);
    [DllImport("user32.dll", CharSet = CharSet.Unicode)] private static extern int GetWindowText(IntPtr window, StringBuilder text, int count);
    [DllImport("user32.dll")] public static extern bool IsWindowVisible(IntPtr window);
    [DllImport("user32.dll")] public static extern bool IsIconic(IntPtr window);
    [DllImport("user32.dll")] public static extern bool PostMessage(IntPtr window, uint message, IntPtr wParam, IntPtr lParam);
    public static IntPtr Find(int processId) { return FindNamed(processId, "OPAL Drive"); }
    public static IntPtr FindNamed(int processId, string caption) {
        IntPtr result = IntPtr.Zero;
        EnumWindows((window, parameter) => {
            int owner;
            GetWindowThreadProcessId(window, out owner);
            var title = new StringBuilder(256);
            GetWindowText(window, title, title.Capacity);
            if (owner == processId && (caption == "OPAL Drive" ? title.ToString() == caption : title.ToString().Contains(caption))) { result = window; return false; }
            return true;
        }, IntPtr.Zero);
        return result;
    }
}
'@

function Wait-Until([scriptblock]$Condition, [string]$Failure) {
    $timeout = [DateTime]::UtcNow.AddSeconds(15)
    do {
        if (& $Condition) { return }
        Start-Sleep -Milliseconds 100
    } while ([DateTime]::UtcNow -lt $timeout)
    throw $Failure
}
function Start-Another([string[]]$Arguments = @()) {
    $options = @{ FilePath = $ApplicationPath; PassThru = $true }
    if ($Arguments.Count) { $options.ArgumentList = $Arguments }
    $second = Start-Process @options
    if (!$second.WaitForExit(15000)) {
        Stop-Process -Id $second.Id -ErrorAction SilentlyContinue
        throw 'A second launch did not return to the existing instance.'
    }
    if ($second.ExitCode -ne 0) { throw "A second launch failed: $($second.ExitCode)" }
}

$app = Start-Process $ApplicationPath -ArgumentList '--background' -PassThru
try {
    Wait-Until { [DriveWindowTest]::IsWindowVisible([DriveWindowTest]::Find($app.Id)) } 'First sign-in startup did not show the connection window.'
    $window = [DriveWindowTest]::Find($app.Id)
    [DriveWindowTest]::PostMessage($window, 0x0010, [IntPtr]::Zero, [IntPtr]::Zero) | Out-Null
    Wait-Until { ![DriveWindowTest]::IsWindowVisible($window) } 'Closing the window did not hide it to the tray.'
    if ($app.HasExited) { throw 'Closing the window stopped the tray application.' }

    Start-Another @('--background')
    if ([DriveWindowTest]::IsWindowVisible($window)) { throw 'A repeated background launch unexpectedly opened the window.' }
    Start-Another
    Wait-Until { [DriveWindowTest]::IsWindowVisible($window) } 'Reopening OPAL Drive did not restore the existing window.'

    # Queue a normal minimize action after the activation handler has returned.
    [DriveWindowTest]::PostMessage($window, 0x0112, [IntPtr]0xF020, [IntPtr]::Zero) | Out-Null
    Wait-Until { [DriveWindowTest]::IsIconic($window) } 'Could not minimize the test window.'
    Start-Another
    Wait-Until { [DriveWindowTest]::IsWindowVisible($window) -and ![DriveWindowTest]::IsIconic($window) } 'Reopening OPAL Drive did not restore its minimized window.'
    if ($app.HasExited) { throw 'The original application stopped during activation.' }
    Write-Output 'PASS: first sign-in window, close to tray, background relaunch, reopen, and restore minimized window.'
    Add-Type -AssemblyName UIAutomationClient,UIAutomationTypes,System.Windows.Forms
    $root = [System.Windows.Automation.AutomationElement]::FromHandle($window)
    $all = [System.Windows.Automation.Condition]::TrueCondition
    $descendants = [System.Windows.Automation.TreeScope]::Descendants
    $server = $root.FindAll($descendants,$all) | Where-Object {$_.Current.ControlType -eq [System.Windows.Automation.ControlType]::Edit} | Select-Object -First 1
    $server.GetCurrentPattern([System.Windows.Automation.ValuePattern]::Pattern).SetValue('https://private-user:private-password@opal.test/private-path?secret=private-secret')
    $diagnostics = $root.FindAll($descendants,$all) | Where-Object {$_.Current.Name -like 'Status*diagnostics'} | Select-Object -First 1
    $diagnostics.GetCurrentPattern([System.Windows.Automation.InvokePattern]::Pattern).Invoke()
    $script:diagnosticWindow = $null
    Wait-Until {
        $handle = [DriveWindowTest]::FindNamed($app.Id,'Status')
        if ($handle -ne [IntPtr]::Zero) { $script:diagnosticWindow = [System.Windows.Automation.AutomationElement]::FromHandle($handle) }
        $null -ne $script:diagnosticWindow
    } 'Status and diagnostics did not open.'
    $copy = $script:diagnosticWindow.FindAll($descendants,$all) | Where-Object {$_.Current.Name -eq 'Copy report'} | Select-Object -First 1
    $oldClipboard = [System.Windows.Forms.Clipboard]::GetDataObject()
    try {
        $copy.GetCurrentPattern([System.Windows.Automation.InvokePattern]::Pattern).Invoke()
        $script:report = ''
        Wait-Until { $script:report = [System.Windows.Forms.Clipboard]::GetText(); $script:report.Contains('OPAL Drive diagnostic report') } 'Copy report did not put a report on the clipboard.'
        if (!$script:report.Contains('https://opal.test') -or $script:report.Contains('private-user') -or $script:report.Contains('private-password') -or $script:report.Contains('private-secret') -or $script:report.Contains('private-path')) { throw 'Report exposed private URL components or omitted the safe server origin.' }
    } finally {
        if ($null -ne $oldClipboard) { [System.Windows.Forms.Clipboard]::SetDataObject($oldClipboard,$true) } else { [System.Windows.Forms.Clipboard]::Clear() }
    }
    Write-Output 'PASS: diagnostic window opens and its copyable report excludes private URL components.'
} finally {
    Stop-Process -Id $app.Id -ErrorAction SilentlyContinue
}
