using System.Collections.Concurrent;
using System.Text.Json;
using Autodesk.Revit.UI;
using Opal.Client;

namespace Opal.Revit;

public sealed class ClientRuntime : IExternalEventHandler, IDisposable
{
    private readonly ConcurrentQueue<ClientCommandEnvelope> commands = new();
    private readonly CancellationTokenSource lifetime = new();
    private readonly ExternalEvent externalEvent;
    private NativeAgent? agent;
    private Task? updateLoop;

    public ClientRuntime(string revitVersion)
    {
        RevitVersion = revitVersion;
        var stateRoot = ClientPaths.ActiveRevitRoot(revitVersion, typeof(ClientRuntime).Assembly.Location);
        Config = new ConfigStore(stateRoot, ClientPaths.SharedRoot);
        Settings = Config.Load();
        Status = new ClientStatus();
        externalEvent = ExternalEvent.Create(this);
    }

    public string RevitVersion { get; }
    public ConfigStore Config { get; }
    public AppSettings Settings { get; private set; }
    public ClientStatus Status { get; }

    public void SetDocument(string? document) => Status.Document = document ?? string.Empty;

    public void StartServices()
    {
        RestartAgent();
        updateLoop = Task.Run(() => RunUpdateLoopAsync(lifetime.Token));
    }

    public void Save(AppSettings settings)
    {
        Config.Save(settings);
        Settings = settings;
        RestartAgent();
    }

    public void Disconnect()
    {
        Settings.Token = string.Empty;
        Settings.AccountEmail = string.Empty;
        Settings.AccountName = string.Empty;
        Save(Settings);
    }

    public async Task<UpdateCheck> CheckForUpdateAsync(CancellationToken cancellationToken = default)
    {
        var updater = new UpdateService(Config, BuildInfo.Version);
        var check = await updater.CheckAsync(Settings, int.Parse(RevitVersion), cancellationToken).ConfigureAwait(false);
        Status.UpdateVersion = check.Available ? check.Release.Version : null;
        return check;
    }

    public async Task<StagedUpdate?> CheckAndStageUpdateAsync(bool silent, CancellationToken cancellationToken = default)
    {
        try
        {
            var check = await CheckForUpdateAsync(cancellationToken).ConfigureAwait(false);
            if (!check.Available || !check.Compatible)
            {
                return null;
            }

            var updater = new UpdateService(Config, BuildInfo.Version);
            var staged = await updater.StageAsync(Settings, check.Release, cancellationToken).ConfigureAwait(false);
            Status.StagedVersion = staged.Version;
            return staged;
        }
        catch (Exception exception)
        {
            Status.UpdateError = exception.Message;
            if (!silent)
            {
                throw;
            }
            return null;
        }
    }

    public void Enqueue(ClientCommandEnvelope command)
    {
        commands.Enqueue(command);
        externalEvent.Raise();
    }

    public void Execute(UIApplication application)
    {
        Status.Document = application.ActiveUIDocument?.Document?.Title ?? string.Empty;
        while (commands.TryDequeue(out var command))
        {
            try
            {
                var result = RevitWorkflow.Execute(application, Settings, command);
                agent?.Report(command.Id, true, result, null);
                Status.LastCommand = $"{command.Type} completed";
            }
            catch (Exception exception)
            {
                agent?.Report(command.Id, false, null, exception.Message);
                Status.LastError = exception.Message;
                Status.LastCommand = $"{command.Type} failed";
            }
        }
    }

    public string GetName() => "OPAL command executor";

    public void Dispose()
    {
        lifetime.Cancel();
        agent?.Dispose();
        try
        {
            updateLoop?.Wait(TimeSpan.FromSeconds(2));
        }
        catch (AggregateException)
        {
            // Cancellation during Revit shutdown is expected.
        }
        lifetime.Dispose();
        externalEvent.Dispose();
    }

    private void RestartAgent()
    {
        agent?.Dispose();
        agent = null;
        Status.Connected = false;
        Status.SessionId = null;

        if (!Settings.IsLinked)
        {
            return;
        }

        agent = new NativeAgent(Settings, RevitVersion, Status, Enqueue);
        agent.Start();
    }

    private async Task RunUpdateLoopAsync(CancellationToken cancellationToken)
    {
        while (!cancellationToken.IsCancellationRequested)
        {
            if (Settings.AutoUpdate)
            {
                await CheckAndStageUpdateAsync(silent: true, cancellationToken).ConfigureAwait(false);
            }

            try
            {
                await Task.Delay(TimeSpan.FromHours(4), cancellationToken).ConfigureAwait(false);
            }
            catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
            {
                break;
            }
        }
    }
}

public static class Runtime
{
    public static ClientRuntime Current { get; private set; } = null!;

    public static void Start(UIControlledApplication application)
    {
        Current = new ClientRuntime(application.ControlledApplication.VersionNumber);
        Current.StartServices();
    }

    public static void Stop()
    {
        Current?.Dispose();
    }
}

public sealed class ClientStatus
{
    public bool Connected { get; set; }
    public int? SessionId { get; set; }
    public string Document { get; set; } = string.Empty;
    public string LastCommand { get; set; } = string.Empty;
    public string LastError { get; set; } = string.Empty;
    public string? UpdateVersion { get; set; }
    public string? StagedVersion { get; set; }
    public string? UpdateError { get; set; }
}

public sealed record ClientCommandEnvelope(int Id, string Type, JsonElement Payload);
