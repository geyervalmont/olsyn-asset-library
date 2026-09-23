using System.Diagnostics;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using System.Windows.Threading;
using Opal.Client;

namespace Opal.Revit;

public sealed class SettingsWindow : Window
{
    private readonly ClientRuntime runtime;
    private readonly CancellationTokenSource lifetime = new();
    private readonly TextBlock account = Text(string.Empty, 16, FontWeights.SemiBold);
    private readonly TextBlock connection = Text(string.Empty);
    private readonly TextBlock endpoint = Text(string.Empty);
    private readonly TextBlock document = Text(string.Empty);
    private readonly TextBlock driveStatus = Text(string.Empty);
    private readonly TextBlock activity = Text(string.Empty);
    private readonly TextBlock update = Text(string.Empty);
    private readonly TextBox drive = Input();
    private readonly TextBox mount = Input();
    private readonly CheckBox autoUpdate = new() { Content = "Download updates automatically", Margin = new Thickness(0, 8, 0, 8) };
    private readonly TextBox server = Input();
    private readonly StackPanel developer = new() { Visibility = Visibility.Collapsed };
    private readonly CheckBox customServer = new() { Content = "Use a custom server", Margin = new Thickness(0, 8, 0, 8) };
    private readonly Button accountAction = Button("Connect account");
    private readonly Button disconnectAction = Button("Disconnect");
    private readonly DispatcherTimer refresh;
    private int versionClicks;
    private bool linking;
    private bool checkingUpdate;

    public SettingsWindow(ClientRuntime runtime)
    {
        this.runtime = runtime;
        Title = "OPAL for Revit — Settings";
        Width = 560;
        Height = 680;
        MinWidth = 500;
        MinHeight = 560;
        WindowStartupLocation = WindowStartupLocation.CenterScreen;
        Background = Brushes.White;

        var settings = runtime.Settings;
        drive.Text = settings.DriveSlug;
        mount.Text = settings.MountPath;
        autoUpdate.IsChecked = settings.AutoUpdate;
        server.Text = settings.ServerUrl;
        customServer.IsChecked = settings.UseCustomServer;
        server.IsEnabled = settings.UseCustomServer;
        developer.Visibility = settings.DeveloperMode ? Visibility.Visible : Visibility.Collapsed;

        var version = Text($"OPAL {BuildInfo.Version} · Revit {runtime.RevitVersion}", 12, FontWeights.Normal);
        version.Foreground = Brushes.DimGray;
        version.Cursor = System.Windows.Input.Cursors.Hand;
        version.MouseLeftButtonUp += (_, _) => RevealDeveloper();

        var body = new StackPanel { Margin = new Thickness(28) };
        body.Children.Add(Text("OPAL for Revit", 25, FontWeights.SemiBold));
        body.Children.Add(version);
        body.Children.Add(Section("Account"));
        body.Children.Add(account);
        body.Children.Add(connection);
        var accountActions = new StackPanel { Orientation = Orientation.Horizontal, Margin = new Thickness(0, 12, 0, 0) };
        accountAction.Click += AccountAction;
        disconnectAction.Margin = new Thickness(8, 0, 0, 0);
        disconnectAction.Click += DisconnectAccount;
        accountActions.Children.Add(accountAction);
        accountActions.Children.Add(disconnectAction);
        body.Children.Add(accountActions);

        body.Children.Add(Section("Status"));
        body.Children.Add(endpoint);
        body.Children.Add(document);
        body.Children.Add(driveStatus);
        body.Children.Add(activity);
        body.Children.Add(update);

        var materialFiles = new StackPanel { Margin = new Thickness(0, 8, 0, 0) };
        materialFiles.Children.Add(Text("Use the material drive supplied by your workspace administrator.", 12));
        materialFiles.Children.Add(Label("OPAL drive"));
        materialFiles.Children.Add(drive);
        materialFiles.Children.Add(Label("Local mount or network path"));
        materialFiles.Children.Add(mount);
        body.Children.Add(new Expander
        {
            Header = "Existing material drive settings",
            Content = materialFiles,
            Margin = new Thickness(0, 16, 0, 0),
        });

        body.Children.Add(Section("Updates"));
        body.Children.Add(autoUpdate);
        var check = Button("Check for update");
        check.Click += async (_, _) => await CheckUpdate(check);
        body.Children.Add(check);

        developer.Children.Add(Section("Developer settings"));
        developer.Children.Add(Text("Production always connects to opal.olsyn.com. Select a custom server only for local or staging testing.", 12));
        customServer.Checked += (_, _) => server.IsEnabled = true;
        customServer.Unchecked += (_, _) => server.IsEnabled = false;
        developer.Children.Add(customServer);
        developer.Children.Add(Label("Custom OPAL server URL"));
        developer.Children.Add(server);
        body.Children.Add(developer);

        var actions = new StackPanel { Orientation = Orientation.Horizontal, HorizontalAlignment = HorizontalAlignment.Right, Margin = new Thickness(0, 28, 0, 0) };
        var save = Button("Save settings");
        save.IsDefault = true;
        save.Click += Save;
        var close = Button("Close");
        close.IsCancel = true;
        close.Margin = new Thickness(8, 0, 0, 0);
        actions.Children.Add(save);
        actions.Children.Add(close);
        body.Children.Add(actions);

        Content = new ScrollViewer { Content = body, VerticalScrollBarVisibility = ScrollBarVisibility.Auto };
        refresh = new DispatcherTimer(TimeSpan.FromSeconds(1), DispatcherPriority.Normal, (_, _) => Refresh(), Dispatcher);
        Closed += (_, _) =>
        {
            refresh.Stop();
            lifetime.Cancel();
        };
        Refresh();
    }

    private void Refresh()
    {
        var settings = runtime.Settings;
        if (!linking)
        {
            account.Text = settings.IsLinked
                ? (string.IsNullOrWhiteSpace(settings.AccountName) ? settings.AccountEmail : settings.AccountName)
                : "No account connected";
            connection.Text = settings.IsLinked
                ? $"{settings.AccountEmail}\n{(runtime.Status.Connected ? "Connected to OPAL" : "Link saved; reconnecting…")}"
                : "Connect in your browser to let Revit receive materials from OPAL.";
            connection.Foreground = runtime.Status.Connected ? Brushes.SeaGreen : Brushes.DimGray;
            accountAction.Content = settings.IsLinked ? "Change account" : "Connect account";
            disconnectAction.Visibility = settings.IsLinked ? Visibility.Visible : Visibility.Collapsed;
        }
        endpoint.Text = $"Server: {settings.EffectiveServerUrl}";
        endpoint.Foreground = settings.UseCustomServer ? Brushes.DarkGoldenrod : Brushes.DimGray;
        document.Text = string.IsNullOrWhiteSpace(runtime.Status.Document) ? "No Revit document is active" : $"Document: {runtime.Status.Document}";
        driveStatus.Text = System.IO.Directory.Exists(settings.MountPath)
            ? $"Material drive: {settings.MountPath} is available"
            : $"Material drive: {settings.MountPath} is not available";
        driveStatus.Foreground = System.IO.Directory.Exists(settings.MountPath) ? Brushes.SeaGreen : Brushes.DarkGoldenrod;
        activity.Text = string.IsNullOrWhiteSpace(runtime.Status.LastCommand)
            ? "No material commands handled in this Revit session"
            : $"Last command: {runtime.Status.LastCommand}{(string.IsNullOrWhiteSpace(runtime.Status.LastError) ? string.Empty : $" — {runtime.Status.LastError}")}";
        if (!checkingUpdate)
        {
            update.Text = runtime.Status.StagedVersion is not null
                ? $"Version {runtime.Status.StagedVersion} is ready and will activate when Revit restarts."
                : runtime.Status.UpdateVersion is not null
                    ? $"Version {runtime.Status.UpdateVersion} is available."
                    : $"Installed version: {BuildInfo.Version}";
            if (!string.IsNullOrWhiteSpace(runtime.Status.UpdateError))
            {
                update.Text += $"\nLast update check: {runtime.Status.UpdateError}";
            }
        }
    }

    private async void AccountAction(object sender, RoutedEventArgs eventArgs)
    {
        if (runtime.Settings.IsLinked)
        {
            var choice = MessageBox.Show(
                "Disconnect this OPAL account? You can connect a different account immediately afterwards.",
                "Change OPAL account",
                MessageBoxButton.YesNo,
                MessageBoxImage.Question);
            if (choice != MessageBoxResult.Yes)
            {
                return;
            }
            runtime.Disconnect();
            Refresh();
        }

        accountAction.IsEnabled = false;
        linking = true;
        try
        {
            SaveFields();
            using var api = new OpalApiClient(runtime.Settings);
            var link = await api.StartLinkAsync(Environment.MachineName, runtime.RevitVersion, lifetime.Token);
            Clipboard.SetText(link.Code);
            Process.Start(new ProcessStartInfo(link.VerifyUrl) { UseShellExecute = true });
            connection.Text = $"Enter code {link.Code} in the browser. It has also been copied to the clipboard.";

            while (!lifetime.IsCancellationRequested)
            {
                await Task.Delay(TimeSpan.FromSeconds(Math.Max(1, link.PollInterval)), lifetime.Token);
                var result = await api.PollLinkAsync(link, lifetime.Token);
                if (result.Status is "pending")
                {
                    continue;
                }
                if (result.Status is not "claimed" || string.IsNullOrWhiteSpace(result.Token))
                {
                    throw new InvalidOperationException("The OPAL account link expired. Start it again.");
                }

                var linked = CopyFields();
                linked.Token = result.Token!;
                linked.AccountName = result.User?.Name ?? string.Empty;
                linked.AccountEmail = result.User?.Email ?? string.Empty;
                runtime.Save(linked);
                Refresh();
                return;
            }
        }
        catch (OperationCanceledException) when (lifetime.IsCancellationRequested)
        {
            // Closing the settings window cancels linking.
        }
        catch (Exception exception)
        {
            MessageBox.Show(exception.Message, "Could not connect OPAL", MessageBoxButton.OK, MessageBoxImage.Error);
        }
        finally
        {
            linking = false;
            accountAction.IsEnabled = true;
            Refresh();
        }
    }

    private void DisconnectAccount(object sender, RoutedEventArgs eventArgs)
    {
        var choice = MessageBox.Show(
            $"Disconnect {runtime.Settings.AccountEmail} from this Revit installation?",
            "Disconnect OPAL account",
            MessageBoxButton.YesNo,
            MessageBoxImage.Question);
        if (choice != MessageBoxResult.Yes)
        {
            return;
        }

        runtime.Disconnect();
        Refresh();
    }

    private async Task CheckUpdate(Button button)
    {
        button.IsEnabled = false;
        checkingUpdate = true;
        try
        {
            SaveFields();
            var check = await runtime.CheckForUpdateAsync(lifetime.Token);
            if (!check.Compatible)
            {
                MessageBox.Show($"OPAL {check.Release.Version} requires Revit {check.Release.MinimumRevit} or later.", "OPAL update");
            }
            else if (!check.Available)
            {
                MessageBox.Show("This is the latest OPAL version.", "OPAL update");
            }
            else
            {
                update.Text = $"Downloading and verifying OPAL {check.Release.Version}…";
                var staged = await runtime.CheckAndStageUpdateAsync(silent: false, lifetime.Token);
                MessageBox.Show($"OPAL {staged?.Version} is ready. Restart Revit to activate it.", "OPAL update");
            }
            Refresh();
        }
        catch (OperationCanceledException) when (lifetime.IsCancellationRequested)
        {
            // Window closed.
        }
        catch (Exception exception)
        {
            MessageBox.Show(exception.Message, "Could not update OPAL", MessageBoxButton.OK, MessageBoxImage.Error);
        }
        finally
        {
            checkingUpdate = false;
            button.IsEnabled = true;
            Refresh();
        }
    }

    private void Save(object sender, RoutedEventArgs eventArgs)
    {
        try
        {
            SaveFields();
            DialogResult = true;
        }
        catch (Exception exception)
        {
            MessageBox.Show(exception.Message, "Invalid OPAL settings", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
    }

    private void SaveFields()
    {
        var settings = CopyFields();
        if (!Uri.TryCreate(settings.EffectiveServerUrl, UriKind.Absolute, out var uri) ||
            (uri.Scheme != Uri.UriSchemeHttps && uri.Scheme != Uri.UriSchemeHttp))
        {
            throw new InvalidOperationException("The OPAL server must be a complete HTTP or HTTPS URL.");
        }
        if (settings.UseCustomServer && uri.Scheme == Uri.UriSchemeHttp && !settings.DeveloperMode)
        {
            throw new InvalidOperationException("HTTP servers are available only in developer mode.");
        }

        if (!settings.EffectiveServerUrl.Equals(runtime.Settings.EffectiveServerUrl, StringComparison.OrdinalIgnoreCase))
        {
            // Tokens belong to one server. Carrying a development token into
            // production (or the reverse) produces a confusing half-linked state.
            settings.Token = string.Empty;
            settings.AccountName = string.Empty;
            settings.AccountEmail = string.Empty;
        }

        runtime.Save(settings);
    }

    private AppSettings CopyFields()
    {
        var serverUrl = server.Text.Trim().TrimEnd('/');
        if (serverUrl.IndexOf("://", StringComparison.Ordinal) < 0)
        {
            serverUrl = "https://" + serverUrl;
        }

        return new AppSettings
        {
            ServerUrl = serverUrl,
            UseCustomServer = customServer.IsChecked == true,
            Token = runtime.Settings.Token,
            AccountName = runtime.Settings.AccountName,
            AccountEmail = runtime.Settings.AccountEmail,
            DriveSlug = drive.Text.Trim(),
            MountPath = mount.Text.Trim(),
            AutoUpdate = autoUpdate.IsChecked == true,
            DeveloperMode = developer.Visibility == Visibility.Visible,
        };
    }

    private void RevealDeveloper()
    {
        versionClicks++;
        if (versionClicks < 5)
        {
            return;
        }
        developer.Visibility = Visibility.Visible;
        runtime.Settings.DeveloperMode = true;
    }

    private static TextBlock Section(string value) => new()
    {
        Text = value,
        FontSize = 14,
        FontWeight = FontWeights.SemiBold,
        Margin = new Thickness(0, 26, 0, 8),
    };

    private static TextBlock Label(string value) => new() { Text = value, Margin = new Thickness(0, 6, 0, 0) };

    private static TextBox Input() => new() { MinHeight = 30, Margin = new Thickness(0, 4, 0, 8), Padding = new Thickness(6, 3, 6, 3) };

    private static TextBlock Text(string value, double size = 13, FontWeight? weight = null) => new()
    {
        Text = value,
        FontSize = size,
        FontWeight = weight ?? FontWeights.Normal,
        TextWrapping = TextWrapping.Wrap,
        Margin = new Thickness(0, 2, 0, 2),
    };

    private static Button Button(string value) => new()
    {
        Content = value,
        MinWidth = 120,
        MinHeight = 32,
        Padding = new Thickness(12, 4, 12, 4),
        HorizontalAlignment = HorizontalAlignment.Left,
    };
}
