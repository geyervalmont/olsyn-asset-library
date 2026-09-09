using System.Text.Json;
using Opal.Client;

namespace Opal.Revit;

public sealed class NativeAgent : IDisposable
{
    private readonly AppSettings settings;
    private readonly string revitVersion;
    private readonly ClientStatus status;
    private readonly Action<ClientCommandEnvelope> dispatch;
    private readonly CancellationTokenSource lifetime = new();
    private Task? loop;
    private OpalApiClient? api;

    public NativeAgent(AppSettings settings, string revitVersion, ClientStatus status, Action<ClientCommandEnvelope> dispatch)
    {
        this.settings = settings;
        this.revitVersion = revitVersion;
        this.status = status;
        this.dispatch = dispatch;
    }

    public void Start() => loop = Task.Run(() => RunAsync(lifetime.Token));

    public void Report(int commandId, bool success, object? result, string? message)
    {
        try
        {
            api?.ResultAsync(commandId, success, result, message, lifetime.Token).GetAwaiter().GetResult();
        }
        catch (Exception exception)
        {
            status.LastError = $"Could not report command {commandId}: {exception.Message}";
        }
    }

    public void Dispose()
    {
        lifetime.Cancel();
        try
        {
            loop?.Wait(TimeSpan.FromSeconds(2));
        }
        catch (AggregateException)
        {
            // Cancellation during Revit shutdown is expected.
        }
        api?.Dispose();
        lifetime.Dispose();
    }

    private async Task RunAsync(CancellationToken cancellationToken)
    {
        var backoff = TimeSpan.FromSeconds(2);
        while (!cancellationToken.IsCancellationRequested)
        {
            try
            {
                await RunSessionAsync(cancellationToken).ConfigureAwait(false);
                backoff = TimeSpan.FromSeconds(2);
            }
            catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
            {
                break;
            }
            catch (Exception exception)
            {
                status.LastError = exception.Message;
                status.Connected = false;
                status.SessionId = null;
                await Task.Delay(backoff, cancellationToken).ConfigureAwait(false);
                backoff = TimeSpan.FromSeconds(Math.Min(backoff.TotalSeconds * 2, 60));
            }
        }
    }

    private async Task RunSessionAsync(CancellationToken cancellationToken)
    {
        using var sessionApi = new OpalApiClient(settings);
        api = sessionApi;
        var session = await sessionApi.StartSessionAsync(Environment.MachineName, revitVersion, status.Document, cancellationToken).ConfigureAwait(false);
        var sessionId = session.GetProperty("id").GetInt32();
        status.SessionId = sessionId;
        status.Connected = true;
        status.LastError = string.Empty;
        var heartbeatAt = DateTimeOffset.MinValue;

        try
        {
            while (!cancellationToken.IsCancellationRequested)
            {
                if (DateTimeOffset.UtcNow - heartbeatAt > TimeSpan.FromSeconds(30))
                {
                    await sessionApi.HeartbeatAsync(sessionId, status.Document, cancellationToken).ConfigureAwait(false);
                    heartbeatAt = DateTimeOffset.UtcNow;
                }

                var commands = await sessionApi.CommandsAsync(sessionId, cancellationToken).ConfigureAwait(false);
                foreach (var item in commands.EnumerateArray())
                {
                    var id = item.GetProperty("id").GetInt32();
                    await sessionApi.AckAsync(id, cancellationToken).ConfigureAwait(false);
                    dispatch(new ClientCommandEnvelope(
                        id,
                        item.GetProperty("type").GetString() ?? "unknown",
                        item.GetProperty("payload").Clone()));
                }

                await Task.Delay(TimeSpan.FromSeconds(2), cancellationToken).ConfigureAwait(false);
            }
        }
        finally
        {
            status.Connected = false;
            status.SessionId = null;
            api = null;
            try
            {
                await sessionApi.EndSessionAsync(sessionId, CancellationToken.None).ConfigureAwait(false);
            }
            catch
            {
                // OPAL expires the session if Revit closes without a network connection.
            }
        }
    }
}
