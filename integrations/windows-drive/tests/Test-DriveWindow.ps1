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
    public static IntPtr Find(int processId) {
        IntPtr result = IntPtr.Zero;
        EnumWindows((window, parameter) => {
            int owner;
            GetWindowThreadProcessId(window, out owner);
            var title = new StringBuilder(256);
            GetWindowText(window, title, title.Capacity);
            if (owner == processId && title.ToString() == "OPAL Drive") { result = window; return false; }
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
} finally {
    Stop-Process -Id $app.Id -ErrorAction SilentlyContinue
}
