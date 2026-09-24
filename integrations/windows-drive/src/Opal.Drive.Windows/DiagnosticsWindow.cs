using System.Diagnostics;
using System.Net;
using System.Runtime.InteropServices;
using System.Text;
using Opal.Client;

namespace Opal.Drive.Windows;

internal sealed class DiagnosticsWindow : Form
{
    private readonly DriveDiagnostics journal;
    private readonly Func<string> status;
    private readonly Func<AppSettings> connection;
    private readonly Func<(string Mount, bool Mounted)> mount;
    private readonly TextBox statusText = new() { Multiline = true, ReadOnly = true, Dock = DockStyle.Fill, ScrollBars = ScrollBars.Vertical };
    private readonly TextBox history = new() { Multiline = true, ReadOnly = true, Dock = DockStyle.Fill, ScrollBars = ScrollBars.Both, WordWrap = false };
    private readonly ListView checks = new() { Dock = DockStyle.Fill, View = View.Details, FullRowSelect = true };
    private readonly Button run = new() { Text = "Run checks", AutoSize = true };
    private readonly Button cancel = new() { Text = "Cancel checks", AutoSize = true, Enabled = false };
    private readonly System.Windows.Forms.Timer timer = new() { Interval = 1000 };
    private CancellationTokenSource? checking;
    private bool closeWhenDone;

    public DiagnosticsWindow(DriveDiagnostics journal, Func<string> status, Func<AppSettings> connection, Func<(string, bool)> mount)
    {
        this.journal = journal; this.status = status; this.connection = connection; this.mount = mount;
        SuspendLayout(); AutoScaleDimensions = new(96, 96); AutoScaleMode = AutoScaleMode.Dpi;
        Text = "OPAL Drive — Status & diagnostics"; Width = 860; Height = 620; MinimumSize = new(650, 480);
        StartPosition = FormStartPosition.CenterParent;
        var tabs = new TabControl { Dock = DockStyle.Fill };
        var statusTab = new TabPage("Status"); statusTab.Controls.Add(statusText);
        var checksTab = new TabPage("Connection checks"); checksTab.Controls.Add(checks);
        var historyTab = new TabPage("Recent activity"); historyTab.Controls.Add(history);
        tabs.TabPages.AddRange([statusTab, checksTab, historyTab]);
        checks.Columns.Add("Check", 165); checks.Columns.Add("Result", 70); checks.Columns.Add("Time", 75); checks.Columns.Add("Details", 650);
        var buttons = new FlowLayoutPanel { Dock = DockStyle.Bottom, AutoSize = true, Padding = new(8) };
        var copy = new Button { Text = "Copy report", AutoSize = true };
        var save = new Button { Text = "Save report…", AutoSize = true };
        buttons.Controls.AddRange([run, cancel, copy, save]);
        var privacy = new Label { Dock = DockStyle.Bottom, Height = 48, Padding = new(10, 4, 10, 4), Text = "Reports include app/Windows versions, server address, device ID and error codes. Tokens, sign-in codes, email addresses and material filenames are excluded. Automatic error reporting is controlled in the main window. Manual checks stay local." };
        Controls.Add(tabs); Controls.Add(privacy); Controls.Add(buttons);
        run.Click += async (_, _) => { tabs.SelectedTab = checksTab; await RunChecksAsync(); };
        cancel.Click += (_, _) => checking?.Cancel();
        copy.Click += (_, _) => { try { Clipboard.SetText(Report()); } catch (ExternalException) { MessageBox.Show(this, "The clipboard is busy. Use Save report instead.", "OPAL Drive"); } };
        save.Click += (_, _) =>
        {
            using var dialog = new SaveFileDialog { Filter = "Text report (*.txt)|*.txt", FileName = $"OPAL-Drive-diagnostics-{DateTime.Now:yyyyMMdd-HHmmss}.txt" };
            if (dialog.ShowDialog(this) != DialogResult.OK) return;
            try { File.WriteAllText(dialog.FileName, Report()); }
            catch (Exception e) when (e is IOException or UnauthorizedAccessException) { MessageBox.Show(this, "Could not save the report there. Choose another folder.", "OPAL Drive"); }
        };
        timer.Tick += (_, _) => RefreshStatus();
        Shown += (_, _) => { RefreshStatus(); timer.Start(); };
        FormClosed += (_, _) => { timer.Stop(); timer.Dispose(); checking?.Cancel(); };
        FormClosing += (_, e) => { if (checking is not null) { e.Cancel = true; closeWhenDone = true; checking.Cancel(); } };
        ResumeLayout(true);
    }

    private string Header() => $"OPAL Drive diagnostic report\r\nGenerated (UTC): {DateTimeOffset.UtcNow:O}\r\nApp: {Application.ProductVersion}\r\nWindows: {Environment.OSVersion.VersionString}\r\nRuntime: {RuntimeInformation.FrameworkDescription}\r\nArchitecture: {RuntimeInformation.ProcessArchitecture} (OS {RuntimeInformation.OSArchitecture})\r\n\r\n";
    private void RefreshStatus()
    {
        statusText.Text = Header() + status() + $"\r\nDiagnostic log writable: {journal.LogAvailable}\r\n";
        var events = string.Join(Environment.NewLine, journal.Events().Select(e => $"{e.Utc:O}  {DriveFailure.StageName(e.Stage)}  {e.Result}  {e.ElapsedMs?.ToString() ?? "-"} ms  {e.Failure?.Code}  {e.Failure?.ExceptionTypes}"));
        if (history.Text != events) history.Text = events;
    }
    private string Report()
    {
        var report = new StringBuilder(Header()).AppendLine(status()).AppendLine($"Diagnostic log writable: {journal.LogAvailable}").AppendLine().AppendLine("Connection checks (manual, read-only):");
        foreach (ListViewItem row in checks.Items) report.AppendLine(string.Join(" | ", row.SubItems.Cast<ListViewItem.ListViewSubItem>().Select(c => c.Text)));
        return report.AppendLine().AppendLine("Recent structured activity:").AppendLine(journal.ExportHistory()).ToString();
    }
    private async Task<bool> CheckAsync(string name, DriveStage stage, Func<CancellationToken, Task<string>> check, int seconds = 20)
    {
        var row = new ListViewItem([name, "Running", "", ""]); checks.Items.Add(row); row.EnsureVisible();
        using var timeout = CancellationTokenSource.CreateLinkedTokenSource(checking!.Token);
        timeout.CancelAfter(TimeSpan.FromSeconds(seconds));
        var elapsed = Stopwatch.StartNew();
        try
        {
            row.SubItems[3].Text = await check(timeout.Token);
            row.SubItems[1].Text = "Pass";
            journal.Record(stage, "ok", elapsedMs: elapsed.ElapsedMilliseconds, report: false);
            return true;
        }
        catch (OperationCanceledException) when (checking.IsCancellationRequested)
        { row.SubItems[1].Text = "Cancelled"; throw; }
        catch (Exception e)
        {
            var failure = DriveFailure.From(e, stage);
            row.SubItems[1].Text = "Fail"; row.SubItems[3].Text = $"{failure.Code}: {failure.Advice} ({failure.ExceptionTypes}; HTTP {failure.HttpStatus?.ToString() ?? "-"}; {failure.HResult})";
            journal.Record(stage, "error", e, elapsed.ElapsedMilliseconds, report: false);
            return false;
        }
        finally { if (!IsDisposed) row.SubItems[2].Text = $"{elapsed.ElapsedMilliseconds:N0} ms"; }
    }
    private void Skip(string name, string reason) => checks.Items.Add(new ListViewItem([name, "Skipped", "", reason]));

    private async Task RunChecksAsync()
    {
        if (checking is not null) return;
        checking = new(); run.Enabled = false; cancel.Enabled = true; checks.Items.Clear();
        try
        {
            await CheckAsync("Local storage", DriveStage.LocalStorage, token => Task.Run(() =>
            {
                Directory.CreateDirectory(DriveSettings.Root);
                var probe = Path.Combine(DriveSettings.Root, ".diagnostic-" + Guid.NewGuid().ToString("N"));
                try { File.WriteAllText(probe, "OPAL storage check"); if (File.ReadAllText(probe) != "OPAL storage check") throw new IOException(); }
                finally { if (File.Exists(probe)) File.Delete(probe); }
                var disk = new DriveInfo(Path.GetPathRoot(DriveSettings.Root)!);
                return $"Read/write OK; {disk.AvailableFreeSpace / (1024 * 1024):N0} MiB free on profile disk.";
            }, token));
            await CheckAsync("Windows driver", DriveStage.Driver, token => Task.Run(() =>
            {
                var version = DriveDriverProbe.Read();
                return $"Dokany library {version.Library}; driver {version.Driver}.";
            }, token));
            await CheckAsync("Drive letter", DriveStage.Mount, _ =>
            {
                var current = mount();
                if (!current.Mounted && DriveInfo.GetDrives().Any(d => d.Name.Equals(current.Mount, StringComparison.OrdinalIgnoreCase))) throw new DriveMountInUseException();
                return Task.FromResult(current.Mounted ? "The OPAL drive is mounted." : "The selected drive letter is available.");
            });

            AppSettings? config = null;
            if (!await CheckAsync("Saved connection", DriveStage.Credentials, _ =>
            {
                config = connection();
                using var validate = new OpalDriveClient(config);
                return Task.FromResult(config.IsLinked ? "Saved credentials unlocked; checking server access next." : "No connected account; sign-in will be required.");
            })) return;
            var origin = new Uri(config!.EffectiveServerUrl);
            await CheckAsync("DNS", DriveStage.Account, async token =>
            {
                var addresses = await Dns.GetHostAddressesAsync(origin.DnsSafeHost, token);
                return $"Resolved {addresses.Length} address(es). HTTPS checks also run if a proxy resolves DNS remotely.";
            }, 10);
            await CheckAsync("System proxy", DriveStage.Account, async token => await Task.Run(() =>
            {
                var proxy = HttpClient.DefaultProxy.GetProxy(origin);
                return proxy is null || proxy == origin ? "Direct connection selected by Windows/system proxy settings." : "System proxy selected; current Windows proxy credentials are used.";
            }, token).WaitAsync(token), 10);
            var reached = await CheckAsync("HTTPS / API", DriveStage.Account, async token =>
            {
                using var probe = new OpalDriveClient(new AppSettings { UseCustomServer = true, ServerUrl = config.EffectiveServerUrl });
                try { await probe.BootstrapAsync(token); }
                catch (HttpRequestException e) when (e.StatusCode == HttpStatusCode.Unauthorized) { return "HTTPS responds; HTTP 401 is expected before sign-in."; }
                return "HTTPS API responded successfully.";
            });
            if (!config.IsLinked) { Skip("Account and library", "Connect your account, then run checks again."); return; }
            if (!reached) { Skip("Account and library", "The HTTPS/API check failed."); return; }
            using var client = new OpalDriveClient(config);
            if (!await CheckAsync("Account access", DriveStage.Account, async token =>
            {
                var response = await client.BootstrapAsync(token);
                if (response.GetProperty("contract").GetString() != "opal-drive/1") throw new InvalidDataException();
                return "Authenticated drive access confirmed.";
            })) { Skip("Library and file read", "Account check failed."); return; }
            DriveTree? tree = null;
            if (!await CheckAsync("Library manifest", DriveStage.Library, async token =>
            {
                var result = await client.ManifestAsync(cancellationToken: token);
                tree = DriveTree.Parse(result.Manifest ?? throw new InvalidDataException());
                return $"Validated {tree.FileCount:N0} files and {tree.Incoming.Count:N0} Incoming folders.";
            }, 90)) return;
            RemoteFile? Sample(string path)
            {
                foreach (var node in tree!.List(path))
                { if (node.File is { } file) return file; if (Sample(node.Path) is { } child) return child; }
                return null;
            }
            var sample = Sample("\\materials");
            if (sample is null) { Skip("Sample file read", "The account has no published material files."); return; }
            await CheckAsync("Sample file read", DriveStage.FileRead, async token =>
            {
                await client.AuthorizeFileAsync(sample.ContentUrl, sample.Sha256, sample.Bytes, token);
                var bytes = await client.ReadAsync(sample.ContentUrl, sample.Sha256, sample.Bytes, 0, 64, token);
                return $"Authorized HEAD and HTTPS range read OK ({bytes.Length} bytes); no material data included in report.";
            });
        }
        catch (OperationCanceledException) when (checking.IsCancellationRequested) { }
        finally
        {
            checking.Dispose(); checking = null;
            if (!IsDisposed) { run.Enabled = true; cancel.Enabled = false; RefreshStatus(); }
            if (closeWhenDone) Close();
        }
    }
}
