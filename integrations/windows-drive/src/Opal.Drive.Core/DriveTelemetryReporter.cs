using System.Text.Json;
using System.Threading.Channels;
using Opal.Client;

namespace Opal.Drive;

// This service never performs network or disk I/O on the filesystem/UI callbacks.
// Reporting errors are deliberately NOT sent back to DriveDiagnostics.
public sealed class DriveTelemetryReporter : IAsyncDisposable
{
    private sealed record Connection(string Server, string Token, Guid Device, CancellationTokenSource Cancel);
    private readonly string path;
    private readonly string version, osVersion;
    private readonly Func<AppSettings, OpalDriveClient> createClient;
    private readonly Channel<(Connection Context, DriveDiagnosticEvent Event)> pending = Channel.CreateBounded<(Connection, DriveDiagnosticEvent)>(new BoundedChannelOptions(256) { FullMode = BoundedChannelFullMode.DropOldest, SingleReader = true });
    private readonly CancellationTokenSource stop = new();
    private readonly Task worker;
    private volatile Connection? connection;
    private volatile bool configured;
    private volatile string status = "Waiting for a connected account";
    public string Status => status;

    public DriveTelemetryReporter(string directory, string version, string osVersion, Func<AppSettings, OpalDriveClient>? createClient = null)
    {
        path = Path.Combine(directory, "telemetry-outbox.json"); this.version = version; this.osVersion = osVersion;
        this.createClient = createClient ?? (settings => new OpalDriveClient(settings));
        worker = Task.Run(RunAsync);
    }
    public void Configure(string server, string token, Guid device, bool enabled)
    {
        var previous = connection;
        if (enabled && token.Length > 0 && previous is not null && previous.Server == server && previous.Token == token && previous.Device == device) return;
        previous?.Cancel.Cancel();
        connection = enabled && token.Length > 0 ? new(server, token, device, new()) : null;
        configured = true;
        status = !enabled ? "Disabled; pending reports will be cleared" : token.Length == 0 ? "Waiting for a connected account" : "Reporting enabled";
    }
    public void Record(DriveDiagnosticEvent entry)
    {
        if (entry.Result != "error" && !(entry.Stage == DriveStage.Ready && entry.Result == "ok")) return;
        var current = connection;
        if (current is not null) pending.Writer.TryWrite((current, entry));
    }
    private async Task RunAsync()
    {
        Connection? active = null;
        DriveTelemetryOutbox? outbox = null;
        OpalDriveClient? client = null;
        var nextSend = DateTimeOffset.MinValue;
        int attempts = 0;
        DateTimeOffset? lastSent = null;
        try
        {
            using var timer = new PeriodicTimer(TimeSpan.FromSeconds(1));
            while (!stop.IsCancellationRequested)
            {
                try
                {
                    var current = connection;
                    if (!ReferenceEquals(active, current))
                    {
                        client?.Dispose(); client = null; active?.Cancel.Dispose(); active = current;
                        outbox = null; attempts = 0; nextSend = DateTimeOffset.MinValue; lastSent = null;
                    }
                    if (active is null)
                    {
                        while (pending.Reader.TryRead(out _)) { }
                        if (configured) { File.Delete(path); File.Delete(path + ".new"); }
                    }
                    else
                    {
                        outbox ??= new(path, DriveTelemetryOutbox.Scope(active.Server, active.Token, active.Device));
                        while (pending.Reader.TryRead(out var item))
                        {
                            if (ReferenceEquals(item.Context, active)) outbox.Record(item.Event, version, osVersion);
                            else if (ReferenceEquals(item.Context, connection)) { pending.Writer.TryWrite(item); break; }
                        }
                        var batch = outbox.Batch();
                        if (batch.Length > 0 && DateTimeOffset.UtcNow >= nextSend)
                        {
                            nextSend = DateTimeOffset.UtcNow.AddSeconds(Math.Min(900, 15 * Math.Pow(2, Math.Min(attempts++, 6))) + Random.Shared.Next(0, 6));
                            client ??= createClient(new AppSettings { UseCustomServer = true, ServerUrl = active.Server, Token = active.Token });
                            using var timeout = CancellationTokenSource.CreateLinkedTokenSource(stop.Token, active.Cancel.Token);
                            timeout.CancelAfter(TimeSpan.FromSeconds(5));
                            var response = await client.TelemetryAsync(active.Device, JsonSerializer.SerializeToElement(batch, DriveTelemetryOutbox.Json), timeout.Token).ConfigureAwait(false);
                            var accepted = response.GetProperty("accepted").EnumerateArray().Select(e => e.GetGuid()).ToHashSet();
                            // Unknown IDs cannot acknowledge our records; partial acknowledgement is safe.
                            outbox.Acknowledge(batch.Where(e => accepted.Contains(e.EventId)).Select(e => e.EventId));
                            if (batch.All(e => accepted.Contains(e.EventId))) { attempts = 0; nextSend = DateTimeOffset.UtcNow.AddSeconds(15); lastSent = DateTimeOffset.UtcNow; }
                        }
                        status = $"Enabled · {outbox.Count} queued · last delivered (UTC): {lastSent?.ToString("O") ?? "Not yet"}" + (outbox.StorageAvailable ? "" : " · local queue storage unavailable");
                    }
                }
                catch (Exception error) when (error is not OutOfMemoryException)
                {
                    if (stop.IsCancellationRequested) break;
                    if (ReferenceEquals(active, connection)) status = $"Delivery pending · {outbox?.Count ?? 0} queued · retrying automatically";
                }
                if (!await timer.WaitForNextTickAsync(stop.Token).ConfigureAwait(false)) break;
            }
        }
        catch (OperationCanceledException) when (stop.IsCancellationRequested) { }
        finally
        {
            // Persist records received just before normal shutdown without delaying it for a network call.
            if (outbox is not null && ReferenceEquals(active, connection))
                while (pending.Reader.TryRead(out var item))
                    if (ReferenceEquals(item.Context, active)) outbox.Record(item.Event, version, osVersion);
            client?.Dispose(); active?.Cancel.Dispose();
        }
    }
    public async ValueTask DisposeAsync()
    {
        stop.Cancel(); await worker.ConfigureAwait(false); stop.Dispose();
    }
}
