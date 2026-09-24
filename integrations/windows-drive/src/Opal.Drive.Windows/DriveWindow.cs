using System.Diagnostics;
using System.Net;
using System.Security.AccessControl;
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

    public DriveWindow(bool background)
    {
        try { settings = DriveSettings.Load(); }
        catch { settings = new(); }
        Text = "OPAL Drive"; Width = 640; Height = 490; MinimumSize = new Size(600, 450);
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
        var buttons = new FlowLayoutPanel { AutoSize = true, Margin = new Padding(0, 14, 0, 12) };
        buttons.Controls.Add(connect); buttons.Controls.Add(toggle); buttons.Controls.Add(disconnect);
        layout.Controls.Add(buttons);
        layout.Controls.Add(uploads);
        var links = new FlowLayoutPanel { AutoSize = true, Margin = new Padding(0, 12, 0, 0) };
        var open = new Button { Text = "Open drive", AutoSize = true };
        var website = new Button { Text = "Manage uploads & devices", AutoSize = true };
        links.Controls.Add(open); links.Controls.Add(website); layout.Controls.Add(links);
        layout.Controls.Add(new Label { Text = "Materials are read-only. Drop textures into a prepared Incoming folder.\nKeep OPAL Drive running while your design tools use the drive.", AutoSize = true, MaximumSize = new Size(530, 0), Margin = new Padding(0, 14, 0, 0) });
        Controls.Add(layout);
        var menu = new ContextMenuStrip();
        menu.Items.Add("Open OPAL Drive", null, (_, _) => ShowWindow());
        menu.Items.Add("Open material folder", null, (_, _) => OpenDrive());
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
            if (background) Hide();
            UpdateControls();
            if (settings.ProtectedToken.Length > 0 && settings.AutoMount) await ActionAsync(MountAsync);
            timer.Start();
        };
        timer.Tick += async (_, _) => await TickAsync();
    }
    private void ShowWindow() { Show(); WindowState = FormWindowState.Normal; Activate(); }
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
        SaveInputs();
        using var linkClient = new OpalDriveClient(ApiSettings());
        var link = await linkClient.StartLinkAsync(Environment.MachineName, lifetime.Token);
        state.Text = $"Approve code {link.Code} in your browser. Waiting for sign-in…";
        OpenUrl(link.VerifyUrl);
        using var poll = CancellationTokenSource.CreateLinkedTokenSource(lifetime.Token);
        poll.CancelAfter(TimeSpan.FromMinutes(10));
        while (true)
        {
            await Task.Delay(TimeSpan.FromSeconds(Math.Max(2, link.PollInterval)), poll.Token);
            var result = await linkClient.PollLinkAsync(link, poll.Token);
            if (result.Status == "delivered") throw new InvalidOperationException("The connection code was already collected. Start again.");
            if (result.Status != "claimed" || string.IsNullOrWhiteSpace(result.Token)) continue;
            settings.SetToken(result.Token); settings.AccountEmail = result.User?.Email ?? ""; settings.Save();
            await MountAsync(); return;
        }
    }
    private async Task MountAsync()
    {
        if (instance is not null) return;
        SaveInputs();
        if (settings.Token().Length == 0) { state.Text = "Connect your account first."; return; }
        state.Text = "Connecting and loading your material library…";
        client?.Dispose(); client = new OpalDriveClient(ApiSettings(settings.Token()));
        var bootstrap = await client.BootstrapAsync(lifetime.Token);
        if (bootstrap.GetProperty("contract").GetString() != "opal-drive/1") throw new InvalidDataException("Unsupported drive service.");
        settings.AccountId = bootstrap.GetProperty("account").GetProperty("id").GetString()!;
        settings.AccountEmail = bootstrap.GetProperty("account").GetProperty("email").GetString()!; settings.Save();
        await client.HeartbeatAsync(settings.DeviceId, Environment.MachineName, "connecting", null, cancellationToken: lifetime.Token);
        if (System.IO.DriveInfo.GetDrives().Any(d => d.Name.Equals(settings.Mount, StringComparison.OrdinalIgnoreCase)))
            throw new MountInUseException();
        var partition = Path.Combine(DriveSettings.Root, "accounts", ContentCache.AccountPartition(settings.Server, settings.AccountId));
        session = new(client, new ContentCache(Path.Combine(partition, "cache")));
        await session.RefreshAsync(lifetime.Token);
        staging = new IntakeStaging(Path.Combine(partition, "uploads"), session);
        filesystem = new MaterialFileSystem(session, staging);
        var descriptor = new RawSecurityDescriptor(MaterialFileSystem.OwnerSddl());
        var descriptorBytes = new byte[descriptor.BinaryLength]; descriptor.GetBinaryForm(descriptorBytes, 0);
        await Task.Run(() =>
        {
            dokan = new Dokan(new NullLogger());
            instance = new DokanInstanceBuilder(dokan).ConfigureOptions(options =>
            {
                options.MountPoint = settings.Mount;
                // No MountManager fallback: silently picking another letter would break material paths.
                options.Options = DokanOptions.CurrentSession;
                options.TimeOut = TimeSpan.FromMinutes(5);
                options.VolumeSecurityDescriptor = descriptorBytes;
                options.VolumeSecurityDescriptorLength = descriptorBytes.Length;
            }).Build(filesystem);
        });
        for (var attempt = 0; attempt < 100 && !filesystem.IsMounted; attempt++) await Task.Delay(50, lifetime.Token);
        if (!filesystem.IsMounted) throw new IOException("Windows did not confirm the mount.");
        mountCancellation = CancellationTokenSource.CreateLinkedTokenSource(lifetime.Token);
        settings.PublishMount(true); lastError = null; nextRefresh = 0; nextUpload = 0;
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
                await session!.RefreshAsync(lifetime.Token);
                await client!.HeartbeatAsync(settings.DeviceId, Environment.MachineName, "mounted", settings.Mount, cancellationToken: lifetime.Token);
                settings.PublishMount(true); lastError = null;
                state.Text = $"Mounted {settings.Mount} · {settings.AccountEmail} · {session.Tree.FileCount:N0} files";
            }
            if (uploadTask is { IsCompleted: true }) { await uploadTask; uploadTask = null; }
            if (Environment.TickCount64 >= nextUpload && uploadTask is null)
            { nextUpload = Environment.TickCount64 + 15000; uploadTask = staging!.UploadPendingAsync(mountCancellation!.Token); }
            if (session is { Online: false }) throw new IOException("OPAL is offline.");
            var count = staging!.Counts;
            uploads.Text = $"Uploads: {count.Pending} pending · {count.Uploaded} confirmed · {count.Failed} awaiting retry\nProcessing and publishing are not enabled yet.";
        }
        catch (Exception error) { await HandleErrorAsync(error); }
        finally { ticking = false; UpdateControls(); }
    }
    private async Task HandleErrorAsync(Exception error)
    {
        if (quitting) return;
        var code = error switch
        {
            MountInUseException => "mount_in_use",
            DllNotFoundException or BadImageFormatException => "driver_missing",
            DokanException => "driver_missing",
            HttpRequestException { StatusCode: HttpStatusCode.Unauthorized } => "sign_in",
            HttpRequestException { StatusCode: HttpStatusCode.Forbidden } => "access_denied",
            System.Security.Cryptography.CryptographicException => "sign_in",
            _ => "network",
        };
        session?.Invalidate(); settings.PublishMount(false);
        var text = code switch
        {
            "mount_in_use" => "That drive letter is in use. Choose a free letter, then Mount drive.",
            "driver_missing" => "The Windows drive component is unavailable. Run the OPAL Drive installer or ask IT to install it.",
            "sign_in" or "access_denied" => "Your connection is no longer authorized. Sign in again or contact your workspace administrator.",
            _ => "Cannot reach your OPAL drive. Check your network or company proxy. OPAL will retry automatically.",
        };
        state.Text = text;
        if (lastError != code) { tray.ShowBalloonTip(5000, "OPAL Drive needs attention", text, ToolTipIcon.Warning); lastError = code; }
        if (client is not null)
        {
            try { using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(5)); await client.HeartbeatAsync(settings.DeviceId, Environment.MachineName, "error", settings.Mount, code, timeout.Token); } catch { }
        }
        if (code is "sign_in" or "access_denied")
        {
            await UnmountAsync(); settings.SetToken(""); settings.Save(); state.Text = text;
        }
        else if (instance is not null && filesystem?.IsMounted != true) await UnmountAsync();
        nextRefresh = Environment.TickCount64 + 30000;
    }
    private async Task UnmountAsync()
    {
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
        finally { settings.SetToken(""); settings.AccountId = ""; settings.AccountEmail = ""; settings.Save(); client?.Dispose(); client = null; }
        state.Text = "Signed out. This computer retains previously downloaded and staged files.";
    }
    private async Task QuitAsync()
    {
        if (quitting) return;
        quitting = true; timer.Stop(); lifetime.Cancel();
        while (busy || ticking) await Task.Delay(50);
        await UnmountAsync(); client?.Dispose(); tray.Visible = false; tray.Dispose(); Close(); Application.Exit();
    }
    private void UpdateControls()
    {
        var linked = settings.ProtectedToken.Length > 0;
        connect.Enabled = !busy && !ticking && !linked; disconnect.Enabled = !busy && !ticking && linked;
        toggle.Enabled = !busy && !ticking && linked; toggle.Text = instance is null ? "Mount drive" : "Unmount";
        server.Enabled = !busy && !ticking && !linked; mount.Enabled = !busy && !ticking && instance is null;
        tray.Text = session?.Online == true && filesystem?.IsMounted == true ? $"OPAL Drive · {settings.Mount}" : "OPAL Drive · Disconnected";
    }
    private sealed class MountInUseException : IOException { }
}
