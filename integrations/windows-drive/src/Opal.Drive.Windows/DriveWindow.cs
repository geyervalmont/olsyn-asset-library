using System.Diagnostics;
using DokanNet;
using DokanNet.Logging;
using Opal.Client;

namespace Opal.Drive.Windows;

public sealed class DriveWindow : Form
{
    private readonly DriveSettings settings;
    private readonly NotifyIcon tray;
    private readonly Label state = new() { AutoSize = true, MaximumSize = new Size(540, 0), Text = "Sign in to mount your materials." };
    private readonly Label uploads = new() { AutoSize = true, MaximumSize = new Size(540, 0) };
    private readonly TextBox server = new() { Width = 360 };
    private readonly ComboBox mount = new() { Width = 100, DropDownStyle = ComboBoxStyle.DropDownList };
    private readonly Button connect = new() { Text = "Connect account", AutoSize = true };
    private readonly Button disconnect = new() { Text = "Sign out", AutoSize = true };
    private readonly Button toggle = new() { Text = "Mount drive", AutoSize = true };
    private readonly CheckBox automatic = new() { Text = "Mount automatically when I sign in to Windows", AutoSize = true };
    private readonly CheckBox reporting = new() { Text = "Send error codes and app versions to OPAL support", AutoSize = true };
    private readonly DriveTelemetryReporter telemetry = new(Path.Combine(DriveSettings.Root, "logs"), BuildInfo.Version, Environment.OSVersion.Version.ToString());
    private readonly System.Windows.Forms.Timer timer = new() { Interval = 1000 };
    private readonly CancellationTokenSource lifetime = new();
    private OpalDriveClient? client;
    private DriveSession? session;
    private IntakeStaging? staging;
    private Dokan? dokan;
    private DokanInstance? instance;
    private MaterialFileSystem? filesystem;
    private CancellationTokenSource? mountCancellation;
    private Task? uploadTask;
    private bool busy, ticking, quitting;
    private long nextRefresh, nextUpload;
    private string? lastError;
    private readonly DriveDiagnostics diagnostics = new(Path.Combine(DriveSettings.Root, "logs"));
    private DiagnosticsWindow? diagnosticsWindow;
    private DriveStage phase = DriveStage.Startup;
    private readonly Stopwatch stepTime = new();
    private DateTimeOffset? lastLibraryRefresh, lastHeartbeat;
    private DriveFailure? failure;
    private DriveStage failedPhase;
    private int fileCount;

    public DriveWindow(bool background)
    {
        SuspendLayout();
        AutoScaleDimensions = new SizeF(96, 96);
        AutoScaleMode = AutoScaleMode.Dpi;
        try { settings = DriveSettings.Load(); }
        catch (Exception error) { settings = new(); diagnostics.Record(DriveStage.Settings, "error", error); }
        diagnostics.Recorded += telemetry.Record;
        diagnostics.Record(DriveStage.Startup, "ok");
        Text = "OPAL Drive"; Width = 680; Height = 600; MinimumSize = new Size(600, 540);
        StartPosition = FormStartPosition.CenterScreen; Icon = SystemIcons.Application;
        var layout = new FlowLayoutPanel { Dock = DockStyle.Fill, FlowDirection = FlowDirection.TopDown, WrapContents = false, Padding = new Padding(24), AutoScroll = true };
        layout.Controls.Add(new Label { Text = "Your material library, mounted.", Font = new Font(Font.FontFamily, 18, FontStyle.Bold), AutoSize = true, Margin = new Padding(0, 0, 0, 16) });
        layout.Controls.Add(state);
        layout.Controls.Add(new Label { Text = "OPAL server", AutoSize = true, Margin = new Padding(0, 18, 0, 4) });
        server.Text = settings.Server; layout.Controls.Add(server);
        var driveRow = new FlowLayoutPanel { AutoSize = true, Margin = new Padding(0, 12, 0, 8) };
        driveRow.Controls.Add(new Label { Text = "Drive letter", AutoSize = true, Padding = new Padding(0, 6, 8, 0) });
        for (char letter = 'D'; letter <= 'Z'; letter++) mount.Items.Add($"{letter}:\\");
        mount.SelectedItem = settings.Mount; if (mount.SelectedIndex < 0) mount.SelectedItem = @"O:\";
        driveRow.Controls.Add(mount); layout.Controls.Add(driveRow);
        automatic.Checked = settings.AutoMount; layout.Controls.Add(automatic);
        reporting.Checked = settings.TelemetryEnabled; layout.Controls.Add(reporting);
        var buttons = new FlowLayoutPanel { AutoSize = true, Margin = new Padding(0, 14, 0, 12) };
        buttons.Controls.Add(connect); buttons.Controls.Add(toggle); buttons.Controls.Add(disconnect);
        layout.Controls.Add(buttons);
        layout.Controls.Add(uploads);
        var links = new FlowLayoutPanel { AutoSize = true, Margin = new Padding(0, 12, 0, 0) };
        var open = new Button { Text = "Open drive", AutoSize = true };
        var website = new Button { Text = "Manage uploads && devices", AutoSize = true };
        links.Controls.Add(open); links.Controls.Add(website); layout.Controls.Add(links);
        var diagnosticButton = new Button { Text = "Status && diagnostics", AutoSize = true };
        diagnosticButton.Click += (_, _) => ShowDiagnostics(); layout.Controls.Add(diagnosticButton);
        layout.Controls.Add(new Label { Text = "Materials are read-only. Drop textures into a prepared Incoming folder.\nKeep OPAL Drive running while your design tools use the drive.", AutoSize = true, MaximumSize = new Size(530, 0), Margin = new Padding(0, 14, 0, 0) });
        Controls.Add(layout);
        var menu = new ContextMenuStrip();
        menu.Items.Add("Open OPAL Drive", null, (_, _) => ShowWindow());
        menu.Items.Add("Open material folder", null, (_, _) => OpenDrive());
        menu.Items.Add("Status && diagnostics", null, (_, _) => ShowDiagnostics());
        menu.Items.Add("Quit and unmount", null, async (_, _) => await QuitAsync());
        tray = new NotifyIcon { Icon = Icon, Text = "OPAL Drive", ContextMenuStrip = menu, Visible = true };
        tray.DoubleClick += (_, _) => ShowWindow();
        connect.Click += async (_, _) => await ActionAsync(LinkAsync);
        toggle.Click += async (_, _) => await ActionAsync(async () =>
        {
            if (instance is null) { settings.AutoMount = true; automatic.Checked = true; SaveInputs(); await MountAsync(); }
            else { settings.AutoMount = false; automatic.Checked = false; settings.Save(); await UnmountAsync(); }
        });
        disconnect.Click += async (_, _) => await ActionAsync(SignOutAsync);
        automatic.CheckedChanged += (_, _) => { settings.AutoMount = automatic.Checked; settings.Save(); };
        reporting.CheckedChanged += (_, _) =>
        {
            settings.TelemetryEnabled = reporting.Checked; settings.Save(); ConfigureTelemetry();
        };
        open.Click += (_, _) => OpenDrive();
        website.Click += (_, _) => OpenUrl(settings.Server.TrimEnd('/') + "/connect");
        FormClosing += (_, e) =>
        {
            if (e.CloseReason == CloseReason.WindowsShutDown)
            {
                quitting = true; settings.PublishMount(false); session?.Stop(); lifetime.Cancel(); tray.Visible = false;
            }
            if (!quitting) { e.Cancel = true; Hide(); }
        };
        Shown += async (_, _) =>
        {
            // A first-time user needs the connection window even when Windows
            // sign-in started the app with --background.
            if (background && settings.ProtectedToken.Length > 0) Hide();
            ConfigureTelemetry(); UpdateControls();
            if (settings.ProtectedToken.Length > 0 && settings.AutoMount) await ActionAsync(MountAsync);
            timer.Start();
        };
        timer.Tick += async (_, _) => await TickAsync();
        ResumeLayout(true);
    }
    private void ConfigureTelemetry()
    {
        try { telemetry.Configure(settings.Server, settings.Token(), settings.DeviceId, settings.TelemetryEnabled); }
        catch (System.Security.Cryptography.CryptographicException) { telemetry.Configure(settings.Server, "", settings.DeviceId, settings.TelemetryEnabled); }
        catch (FormatException) { telemetry.Configure(settings.Server, "", settings.DeviceId, settings.TelemetryEnabled); }
    }
    internal void ShowWindow() { Show(); WindowState = FormWindowState.Normal; Activate(); }
    private void ShowDiagnostics()
    {
        if (diagnosticsWindow is null || diagnosticsWindow.IsDisposed)
            diagnosticsWindow = new(diagnostics, DiagnosticStatus,
                () => new AppSettings { UseCustomServer = true, ServerUrl = settings.ProtectedToken.Length > 0 ? settings.Server : server.Text.Trim(), Token = settings.Token() },
                () => ((string)mount.SelectedItem!, filesystem?.IsMounted == true));
        diagnosticsWindow.Show(this); diagnosticsWindow.Activate();
    }
    private string DiagnosticStatus()
    {
        var count = staging?.Counts;
        return $"Server: {DriveDiagnostics.SafeOrigin(settings.ProtectedToken.Length > 0 ? settings.Server : server.Text.Trim())}\r\nDevice ID: {settings.DeviceId}\r\nAccount connected: {settings.ProtectedToken.Length > 0}\r\nDrive letter: {mount.SelectedItem}\r\nWindows mount active: {filesystem?.IsMounted == true}\r\nLibrary access current: {session?.Online == true}\r\nAutomatic reconnect: {settings.AutoMount}\r\nError reporting: {telemetry.Status}\r\nCurrent step: {DriveFailure.StageName(phase)}{(busy || ticking ? $" ({stepTime.Elapsed.TotalSeconds:N0} s)" : "")}\r\nLast library refresh (UTC): {lastLibraryRefresh?.ToString("O") ?? "Never"}\r\nLast heartbeat (UTC): {lastHeartbeat?.ToString("O") ?? "Never"}\r\nLast library file count: {fileCount:N0}\r\nUploads: {count?.Pending ?? 0} pending, {count?.Uploaded ?? 0} confirmed, {count?.Failed ?? 0} awaiting retry\r\nNext automatic retry: {(settings.AutoMount && settings.ProtectedToken.Length > 0 && failure is not null ? Math.Max(0, (nextRefresh - Environment.TickCount64) / 1000) + " s" : "None")}\r\nLast failure: {(failure is null ? "None" : DriveFailure.StageName(failedPhase) + " / " + failure.Code + "\r\n" + failure.Advice + $"\r\nType: {failure.ExceptionTypes}; HTTP: {failure.HttpStatus?.ToString() ?? "-"}; HRESULT: {failure.HResult}")}";
    }
    private void BeginStep(DriveStage stage, string? message = null)
    {
        phase = stage; stepTime.Restart(); diagnostics.Record(stage, "started");
        if (message is not null) state.Text = message;
    }
    private void StepSucceeded() => diagnostics.Record(phase, "ok", elapsedMs: stepTime.ElapsedMilliseconds);
    private static void OpenUrl(string url) => Process.Start(new ProcessStartInfo(url) { UseShellExecute = true });
    private void OpenDrive() { if (filesystem?.IsMounted == true && session?.Online == true) OpenUrl(settings.Mount); else ShowWindow(); }
    private void SaveInputs()
    {
        if (settings.ProtectedToken.Length == 0) settings.Server = server.Text.Trim().TrimEnd('/');
        settings.Mount = (string)mount.SelectedItem!; settings.Save();
    }
    private AppSettings ApiSettings(string token = "") => new() { UseCustomServer = true, ServerUrl = settings.Server, Token = token };
    private async Task ActionAsync(Func<Task> action)
    {
        if (busy || ticking || quitting) return;
        busy = true; UpdateControls();
        try { await action(); }
        catch (Exception error) { await HandleErrorAsync(error); }
        finally { busy = false; UpdateControls(); }
    }
    private async Task LinkAsync()
    {
        BeginStep(DriveStage.Settings);
        SaveInputs();
        using var linkClient = new OpalDriveClient(ApiSettings());
        BeginStep(DriveStage.SignIn, "Requesting browser sign-in…");
        var link = await linkClient.StartLinkAsync(Environment.MachineName, lifetime.Token);
        StepSucceeded();
        state.Text = $"Approve code {link.Code} in your browser. Waiting for sign-in…";
        BeginStep(DriveStage.Browser);
        OpenUrl(link.VerifyUrl);
        StepSucceeded(); BeginStep(DriveStage.SignIn);
        using var poll = CancellationTokenSource.CreateLinkedTokenSource(lifetime.Token);
        poll.CancelAfter(TimeSpan.FromMinutes(10));
        while (true)
        {
            await Task.Delay(TimeSpan.FromSeconds(Math.Max(2, link.PollInterval)), poll.Token);
            var result = await linkClient.PollLinkAsync(link, poll.Token);
            if (result.Status == "delivered") throw new InvalidOperationException("The connection code was already collected. Start again.");
            if (result.Status != "claimed" || string.IsNullOrWhiteSpace(result.Token)) continue;
            StepSucceeded(); BeginStep(DriveStage.Credentials);
            settings.SetToken(result.Token); settings.AccountEmail = result.User?.Email ?? ""; settings.Save();
            await MountAsync(); return;
        }
    }
    private async Task MountAsync()
    {
        if (instance is not null) return;
        BeginStep(DriveStage.Settings);
        SaveInputs();
        BeginStep(DriveStage.Credentials);
        if (settings.Token().Length == 0) { state.Text = "Connect your account first."; return; }
        ConfigureTelemetry();
        client?.Dispose(); client = new OpalDriveClient(ApiSettings(settings.Token()));
        BeginStep(DriveStage.Account, "Checking your account access…");
        var bootstrap = await client.BootstrapAsync(lifetime.Token);
        if (bootstrap.GetProperty("contract").GetString() != "opal-drive/1") throw new InvalidDataException("Unsupported drive service.");
        settings.AccountId = bootstrap.GetProperty("account").GetProperty("id").GetString()!;
        settings.AccountEmail = bootstrap.GetProperty("account").GetProperty("email").GetString()!; settings.Save();
        StepSucceeded(); BeginStep(DriveStage.Heartbeat);
        await client.HeartbeatAsync(settings.DeviceId, Environment.MachineName, "connecting", null, cancellationToken: lifetime.Token);
        lastHeartbeat = DateTimeOffset.UtcNow; StepSucceeded(); BeginStep(DriveStage.Mount);
        if (System.IO.DriveInfo.GetDrives().Any(d => d.Name.Equals(settings.Mount, StringComparison.OrdinalIgnoreCase)))
            throw new DriveMountInUseException();
        BeginStep(DriveStage.LocalStorage, "Preparing your local material cache…");
        var partition = Path.Combine(DriveSettings.Root, "accounts", ContentCache.AccountPartition(settings.Server, settings.AccountId));
        session = new(client, new ContentCache(Path.Combine(partition, "cache")));
        session.Faulted += error => { if (!quitting && phase is not (DriveStage.Library or DriveStage.Unmount)) diagnostics.Record(DriveStage.FileRead, "error", error); };
        StepSucceeded(); BeginStep(DriveStage.Library, "Loading and validating your material library…");
        await session.RefreshAsync(lifetime.Token);
        lastLibraryRefresh = DateTimeOffset.UtcNow; fileCount = session.Tree.FileCount; StepSucceeded();
        BeginStep(DriveStage.LocalStorage);
        staging = new IntakeStaging(Path.Combine(partition, "uploads"), session);
        staging.Faulted += error => diagnostics.Record(DriveStage.Uploads, "error", error);
        filesystem = new MaterialFileSystem(session, staging);
        StepSucceeded(); BeginStep(DriveStage.Driver, "Starting the Windows filesystem driver…");
        dokan = await Task.Run(() => new Dokan(new NullLogger()));
        StepSucceeded(); BeginStep(DriveStage.Mount, $"Mounting {settings.Mount}…");
        try { await Task.Run(() =>
        {
            instance = new DokanInstanceBuilder(dokan).ConfigureOptions(options => DriveMountOptions.Configure(options, settings.Mount)).Build(filesystem);
        }); }
        catch (DokanException error) { throw new DriveDriverException((int)error.ErrorStatus, error); }
        for (var attempt = 0; attempt < 100 && !filesystem.IsMounted; attempt++) await Task.Delay(50, lifetime.Token);
        if (!filesystem.IsMounted) throw new IOException("Windows did not confirm the mount.");
        mountCancellation = CancellationTokenSource.CreateLinkedTokenSource(lifetime.Token);
        settings.PublishMount(true); lastError = null; nextRefresh = 0; nextUpload = 0;
        StepSucceeded(); phase = DriveStage.Ready; failure = null; diagnostics.Record(DriveStage.Ready, "ok");
        state.Text = $"Mounted {settings.Mount} · {settings.AccountEmail} · {session.Tree.FileCount:N0} files";
        tray.ShowBalloonTip(4000, "OPAL Drive connected", $"Your materials are available at {settings.Mount}", ToolTipIcon.Info);
    }
    private async Task TickAsync()
    {
        if (busy || ticking || quitting) return;
        ticking = true; UpdateControls();
        try
        {
            if (instance is null)
            {
                if (settings.AutoMount && settings.ProtectedToken.Length > 0 && Environment.TickCount64 >= nextRefresh)
                { nextRefresh = Environment.TickCount64 + 30000; await MountAsync(); }
                return;
            }
            if (filesystem?.IsMounted != true) throw new IOException("The drive was unmounted by Windows.");
            if (Environment.TickCount64 >= nextRefresh)
            {
                nextRefresh = Environment.TickCount64 + 30000;
                BeginStep(DriveStage.Library);
                await session!.RefreshAsync(lifetime.Token);
                lastLibraryRefresh = DateTimeOffset.UtcNow; fileCount = session.Tree.FileCount; StepSucceeded();
                BeginStep(DriveStage.Heartbeat);
                await client!.HeartbeatAsync(settings.DeviceId, Environment.MachineName, "mounted", settings.Mount, cancellationToken: lifetime.Token);
                lastHeartbeat = DateTimeOffset.UtcNow; StepSucceeded(); phase = DriveStage.Ready; failure = null; diagnostics.Record(DriveStage.Ready, "ok");
                settings.PublishMount(true); lastError = null;
                state.Text = $"Mounted {settings.Mount} · {settings.AccountEmail} · {session.Tree.FileCount:N0} files";
            }
            if (uploadTask is { IsCompleted: true }) { phase = DriveStage.Uploads; var completed = uploadTask; uploadTask = null; await completed; phase = DriveStage.Ready; }
            if (Environment.TickCount64 >= nextUpload && uploadTask is null)
            { nextUpload = Environment.TickCount64 + 15000; uploadTask = staging!.UploadPendingAsync(mountCancellation!.Token); }
            // An already reported outage waits for the scheduled refresh. Re-reporting
            // it every tick would continually postpone nextRefresh and prevent recovery.
            if (session is { Online: false } && failure is null) { phase = DriveStage.FileRead; throw session.LastFault ?? new IOException("OPAL is offline."); }
            var count = staging!.Counts;
            uploads.Text = $"Uploads: {count.Pending} pending · {count.Uploaded} confirmed · {count.Failed} awaiting retry\nProcessing and publishing are not enabled yet.";
        }
        catch (Exception error) { await HandleErrorAsync(error); }
        finally { ticking = false; UpdateControls(); }
    }
    private async Task HandleErrorAsync(Exception error)
    {
        if (quitting) return;
        failure = DriveFailure.From(error, phase); failedPhase = phase;
        diagnostics.Record(phase, "error", error, stepTime.ElapsedMilliseconds);
        var code = failure.Code;
        session?.Invalidate();
        try { settings.PublishMount(false); } catch (Exception cleanup) { diagnostics.Record(DriveStage.LocalStorage, "error", cleanup); }
        var text = $"{DriveFailure.StageName(failedPhase)}: {failure.Advice} [{code}]";
        state.Text = text;
        if (lastError != code) { tray.ShowBalloonTip(5000, "OPAL Drive needs attention", text, ToolTipIcon.Warning); lastError = code; }
        if (client is not null)
        {
            try { using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(5)); await client.HeartbeatAsync(settings.DeviceId, Environment.MachineName, "error", settings.Mount, failure.HeartbeatCode, timeout.Token); lastHeartbeat = DateTimeOffset.UtcNow; }
            catch (Exception heartbeatError) { diagnostics.Record(DriveStage.Heartbeat, "error", heartbeatError); }
        }
        if (code is "sign_in" or "access_denied")
        {
            await UnmountAsync(); settings.SetToken(""); settings.Save(); ConfigureTelemetry(); state.Text = text;
        }
        else if (instance is not null && filesystem?.IsMounted != true) { await UnmountAsync(); state.Text = text; }
        else if (instance is null)
        {
            // Failed mount attempts must release the native driver/cache session before retrying.
            session?.Stop(); dokan?.Dispose(); dokan = null; filesystem = null; staging = null; session = null;
        }
        nextRefresh = Environment.TickCount64 + 30000;
    }
    private async Task UnmountAsync()
    {
        BeginStep(DriveStage.Unmount);
        settings.PublishMount(false); session?.Stop(); mountCancellation?.Cancel();
        if (uploadTask is not null) { try { await uploadTask; } catch (OperationCanceledException) { } uploadTask = null; }
        var old = instance; instance = null;
        if (old is not null) await Task.Run(old.Dispose);
        dokan?.Dispose(); dokan = null; filesystem = null; staging = null; session = null;
        mountCancellation?.Dispose(); mountCancellation = null;
        if (client is not null)
        {
            try { using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(3)); await client.HeartbeatAsync(settings.DeviceId, Environment.MachineName, "offline", null, cancellationToken: timeout.Token); } catch { }
        }
        state.Text = "Drive unmounted. Your staged uploads are retained on this computer.";
        StepSucceeded();
    }
    private async Task SignOutAsync()
    {
        await UnmountAsync();
        try
        {
            client ??= new OpalDriveClient(ApiSettings(settings.Token()));
            await client.RevokeAsync(lifetime.Token);
        }
        catch (HttpRequestException) { MessageBox.Show("The local connection was removed. If OPAL is unreachable, revoke this device from Connect when you are online.", "OPAL Drive"); }
        finally { settings.SetToken(""); settings.AccountId = ""; settings.AccountEmail = ""; settings.Save(); ConfigureTelemetry(); client?.Dispose(); client = null; }
        state.Text = "Signed out. This computer retains previously downloaded and staged files.";
    }
    private async Task QuitAsync()
    {
        if (quitting) return;
        quitting = true; timer.Stop(); lifetime.Cancel();
        while (busy || ticking) await Task.Delay(50);
        await UnmountAsync(); await telemetry.DisposeAsync(); client?.Dispose(); tray.Visible = false; tray.Dispose(); Close(); Application.Exit();
    }
    private void UpdateControls()
    {
        var linked = settings.ProtectedToken.Length > 0;
        connect.Enabled = !busy && !ticking && !linked; disconnect.Enabled = !busy && !ticking && linked;
        toggle.Enabled = !busy && !ticking && linked; toggle.Text = instance is null ? "Mount drive" : "Unmount";
        server.Enabled = !busy && !ticking && !linked; mount.Enabled = !busy && !ticking && instance is null;
        tray.Text = session?.Online == true && filesystem?.IsMounted == true ? $"OPAL Drive · {settings.Mount}" : "OPAL Drive · Disconnected";
    }
}
