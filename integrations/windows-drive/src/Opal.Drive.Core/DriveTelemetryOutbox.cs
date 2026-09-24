using System.Security.Cryptography;
using System.Text;
using System.Text.Json;

namespace Opal.Drive;

public sealed record DriveTelemetryDetails(string OsVersion, string? Hresult, int? HttpStatus, int? NativeError, int? DriverStatus, long? ElapsedMs);
public sealed record DriveTelemetryEvent(Guid EventId, string Kind, string Stage, string? Code, string Version, DateTimeOffset OccurredAt, DriveTelemetryDetails Details);

// Used only by the background reporter. The disk contains no credentials, messages,
// URLs or paths: just the payload and a one-way credential-scope hash.
public sealed class DriveTelemetryOutbox
{
    public const int Capacity = 200;
    public static readonly JsonSerializerOptions Json = new() { PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower };
    private readonly string path;
    private readonly Func<DateTimeOffset> clock;
    private OutboxState state;
    public bool StorageAvailable { get; private set; } = true;
    public int Count => state.Events.Count;
    public static string Scope(string server, string token, Guid device) => Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(server.TrimEnd('/').ToLowerInvariant() + "\n" + token + "\n" + device)));

    public DriveTelemetryOutbox(string path, string scope, Func<DateTimeOffset>? clock = null)
    {
        this.path = path; this.clock = clock ?? (() => DateTimeOffset.UtcNow);
        state = new(scope, [], []);
        try
        {
            if (File.Exists(path) && new FileInfo(path).Length <= 256 * 1024)
            {
                var saved = JsonSerializer.Deserialize<OutboxState>(File.ReadAllText(path), Json);
                if (saved?.Scope == scope && saved.Events is not null && saved.Recent is not null) state = saved;
            }
        }
        catch (Exception e) when (e is IOException or UnauthorizedAccessException or JsonException) { StorageAvailable = false; }
        Prune(); Save(); // Also replaces data belonging to a previous credential scope.
    }

    public void Record(DriveDiagnosticEvent entry, string version, string osVersion)
    {
        Prune();
        string kind;
        if (entry.Result == "error" && entry.Failure is not null)
        {
            state.ReadyReported = false;
            var key = $"{entry.Stage}:{entry.Failure.Code}:{entry.Failure.HResult}:{entry.Failure.HttpStatus}";
            if (state.Recent.TryGetValue(key, out var previous) && clock() - previous < TimeSpan.FromMinutes(5)) return;
            state.Recent[key] = clock(); kind = "error";
        }
        else if (entry.Stage == DriveStage.Ready && entry.Result == "ok")
        {
            if (state.ReadyReported) return;
            state.ReadyReported = true; kind = "ready";
        }
        else return;
        var failure = entry.Failure;
        state.Events.Add(new(Guid.NewGuid(), kind, entry.Stage.ToString(), failure?.Code, version, entry.Utc,
            new(osVersion, failure?.HResult, failure?.HttpStatus, failure?.NativeError, failure?.DriverStatus,
                entry.ElapsedMs is long elapsed ? Math.Clamp(elapsed, 0, 86400000) : null)));
        Prune(); Save();
    }

    public DriveTelemetryEvent[] Batch() { Prune(); return state.Events.Take(20).ToArray(); }
    public void Acknowledge(IEnumerable<Guid> ids)
    {
        var accepted = ids.ToHashSet(); state.Events.RemoveAll(e => accepted.Contains(e.EventId)); Save();
    }
    private void Prune()
    {
        var now = clock();
        state.Events.RemoveAll(e => e.OccurredAt < now.AddDays(-14));
        if (state.Events.Count > Capacity) state.Events.RemoveRange(0, state.Events.Count - Capacity);
        foreach (var key in state.Recent.Where(e => e.Value < now.AddMinutes(-5)).Select(e => e.Key).ToArray()) state.Recent.Remove(key);
        while (state.Recent.Count > 128) state.Recent.Remove(state.Recent.MinBy(e => e.Value).Key);
    }
    private void Save()
    {
        try
        {
            Directory.CreateDirectory(Path.GetDirectoryName(path)!);
            File.WriteAllText(path + ".new", JsonSerializer.Serialize(state, Json)); File.Move(path + ".new", path, true); StorageAvailable = true;
        }
        catch (Exception e) when (e is IOException or UnauthorizedAccessException or System.Security.SecurityException) { StorageAvailable = false; }
    }
    public sealed record OutboxState(string Scope, List<DriveTelemetryEvent> Events, Dictionary<string, DateTimeOffset> Recent)
    {
        public bool ReadyReported { get; set; } = false;
    }
}
