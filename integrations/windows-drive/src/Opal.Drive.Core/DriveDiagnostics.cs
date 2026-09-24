using System.ComponentModel;
using System.Net;
using System.Net.Sockets;
using System.Security.Authentication;
using System.Security.Cryptography;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Opal.Drive;

public enum DriveStage { Startup, Settings, SignIn, Browser, Credentials, Account, Heartbeat, Library, LocalStorage, Driver, Mount, Ready, FileRead, Uploads, Unmount }

public sealed class DriveMountInUseException : IOException;
public sealed class DriveDriverException(int status, Exception inner) : Exception("Windows drive mount failed.", inner)
{
    public int DriverStatus { get; } = status;
}

// Only structured error metadata is retained. Exception messages, stack traces,
// request URLs, headers, response bodies, filenames and account names are never logged.
public sealed record DriveFailure(string Code, string Advice, string ExceptionTypes, string HResult, int? HttpStatus, string? TransportError, int? NativeError, int? DriverStatus)
{
    public string HeartbeatCode => Code switch
    {
        "sign_in" or "access_denied" or "mount_in_use" => Code,
        "driver_missing" or "driver_mount" => "driver_missing",
        "dns" or "tls" or "proxy" or "network" or "timeout" or "server" => "network",
        _ => "unknown",
    };

    public static DriveFailure From(Exception error, DriveStage stage)
    {
        var chain = new List<Exception>();
        for (Exception? e = error; e is not null && chain.Count < 8; e = e.InnerException) chain.Add(e);
        var http = chain.OfType<HttpRequestException>().FirstOrDefault();
        var socket = chain.OfType<SocketException>().FirstOrDefault();
        var driver = chain.OfType<DriveDriverException>().FirstOrDefault();
        var native = chain.OfType<Win32Exception>().FirstOrDefault()?.NativeErrorCode;
        var code = error switch
        {
            DriveMountInUseException => "mount_in_use",
            DriveDriverException => "driver_mount",
            DllNotFoundException or BadImageFormatException or EntryPointNotFoundException => "driver_missing",
            CryptographicException => "credentials",
            FormatException when stage == DriveStage.Credentials => "credentials",
            UriFormatException => "settings",
            ArgumentException when stage is DriveStage.Credentials or DriveStage.Settings => "settings",
            OperationCanceledException => stage == DriveStage.SignIn ? "sign_in_timeout" : "timeout",
            _ when http?.StatusCode == HttpStatusCode.Unauthorized => "sign_in",
            _ when http?.StatusCode == HttpStatusCode.Forbidden => "access_denied",
            _ when http?.StatusCode == HttpStatusCode.ProxyAuthenticationRequired || http?.HttpRequestError == HttpRequestError.ProxyTunnelError => "proxy",
            _ when (int?)http?.StatusCode == 429 => "rate_limited",
            _ when (int?)http?.StatusCode >= 500 => "server",
            _ when http?.HttpRequestError == HttpRequestError.NameResolutionError || socket?.SocketErrorCode is SocketError.HostNotFound or SocketError.NoData or SocketError.TryAgain => "dns",
            _ when http?.HttpRequestError == HttpRequestError.SecureConnectionError || chain.Any(e => e is AuthenticationException) => "tls",
            _ when http?.StatusCode is not null => "http_response",
            _ when http is not null || socket is not null => "network",
            _ when (error.HResult & 0xffff) is 112 or 39 => "disk_full",
            UnauthorizedAccessException => "local_permissions",
            JsonException or InvalidDataException => stage is DriveStage.FileRead ? "content_integrity" : "invalid_response",
            _ when stage is DriveStage.Library or DriveStage.Account && error is InvalidOperationException or KeyNotFoundException or FormatException => "invalid_response",
            _ when stage is DriveStage.Settings => "settings",
            _ when stage is DriveStage.LocalStorage => "local_storage",
            _ when stage is DriveStage.Browser => "browser",
            _ when stage is DriveStage.Driver or DriveStage.Mount => "driver_mount",
            _ => "unknown",
        };
        var advice = code switch
        {
            "mount_in_use" => "That drive letter is in use. Choose another letter and retry.",
            "driver_missing" => "The Windows filesystem component could not load. Run the OPAL Drive installer; restart if it requests it.",
            "driver_mount" => "Windows could not mount the drive. Run diagnostics to check the driver and drive letter.",
            "credentials" => "Windows could not unlock this account's saved credentials. Sign out and connect again.",
            "sign_in" => "Your connection has expired or was revoked. Connect your account again.",
            "access_denied" => "This connection cannot access the library. Check your workspace access with an administrator.",
            "sign_in_timeout" => "Browser approval timed out. Connect your account again.",
            "proxy" => "The company proxy rejected the connection. Ask IT to check Windows proxy authentication and HTTPS access to OPAL.",
            "dns" => "Windows could not resolve the OPAL server name. Check the server address and DNS connection.",
            "tls" => "Windows could not establish a trusted TLS connection. Ask IT to check certificate trust or HTTPS inspection.",
            "network" => "The connection to OPAL failed. Check network or proxy connectivity, then retry.",
            "timeout" => "The operation timed out. Retry or run diagnostics to identify the slow step.",
            "server" => "OPAL returned a server error. Retry shortly and share a diagnostic report if it continues.",
            "rate_limited" => "OPAL is limiting requests. Wait briefly before retrying.",
            "http_response" => "The server returned an unexpected HTTP response. Check the server address and share the diagnostic report.",
            "invalid_response" => "The library response could not be read or validated. Share the diagnostic report with OPAL support.",
            "content_integrity" => "The material failed content validation. Retry and share the diagnostic report if it continues.",
            "disk_full" => "The local disk is full. Free space before retrying.",
            "local_permissions" => "Windows denied access to local drive data. Check folder permissions or endpoint protection.",
            "local_storage" => "OPAL could not use its local cache or upload queue. Check disk space and local folder access.",
            "settings" => "The saved settings or server address could not be read. Check the server address before connecting.",
            "browser" => "Windows could not open your browser. Check your default browser and connect again.",
            _ => "OPAL encountered an unexpected error. Open Status & diagnostics and share the report.",
        };
        return new(code, advice, string.Join(" > ", chain.Select(e => e.GetType().Name)), $"0x{error.HResult:X8}",
            (int?)http?.StatusCode, http?.HttpRequestError.ToString(), native, driver?.DriverStatus);
    }

    public static string StageName(DriveStage stage) => stage switch
    {
        DriveStage.SignIn => "Browser approval", DriveStage.LocalStorage => "Local cache and uploads",
        DriveStage.Account => "Account access", DriveStage.Library => "Loading library",
        DriveStage.Driver => "Windows driver", DriveStage.Mount => "Mounting drive",
        DriveStage.FileRead => "Reading material", _ => stage.ToString(),
    };
}

public sealed record DriveDiagnosticEvent(DateTimeOffset Utc, DriveStage Stage, string Result, DriveFailure? Failure = null, long? ElapsedMs = null);

public sealed class DriveDiagnostics(string directory)
{
    public const int FileLimit = 256 * 1024;
    private readonly object gate = new();
    private readonly Queue<DriveDiagnosticEvent> recent = new();
    private readonly string path = Path.Combine(directory, "diagnostics.jsonl");
    private static readonly JsonSerializerOptions Json = new() { Converters = { new JsonStringEnumConverter() } };
    public bool LogAvailable { get; private set; } = true;
    public event Action<DriveDiagnosticEvent>? Recorded;
    public void Record(DriveStage stage, string result, Exception? error = null, long? elapsedMs = null, bool report = true)
    {
        // Callers supply fixed result codes, never server/user-provided text.
        if (result is not ("started" or "ok" or "error" or "stopped")) throw new ArgumentException("Unknown diagnostic event.");
        var entry = new DriveDiagnosticEvent(DateTimeOffset.UtcNow, stage, result, error is null ? null : DriveFailure.From(error, stage), elapsedMs);
        lock (gate)
        {
            recent.Enqueue(entry);
            while (recent.Count > 200) recent.Dequeue();
            try
            {
                Directory.CreateDirectory(directory);
                if (File.Exists(path) && new FileInfo(path).Length >= FileLimit) File.Move(path, path + ".previous", true);
                File.AppendAllText(path, JsonSerializer.Serialize(entry, Json) + Environment.NewLine);
                LogAvailable = true;
            }
            catch (Exception e) when (e is IOException or UnauthorizedAccessException or System.Security.SecurityException) { LogAvailable = false; }
        }
        if (report) Recorded?.Invoke(entry);
    }
    public DriveDiagnosticEvent[] Events() { lock (gate) return recent.ToArray(); }
    public string ExportHistory()
    {
        // Include only this application's bounded structured logs, never settings or cache files.
        lock (gate)
        {
            var events = new List<DriveDiagnosticEvent>();
            foreach (var file in new[] { path + ".previous", path })
            {
                try
                {
                    if (!File.Exists(file) || new FileInfo(file).Length > FileLimit + 8192) continue;
                    foreach (var line in File.ReadLines(file))
                    {
                        var entry = JsonSerializer.Deserialize<DriveDiagnosticEvent>(line, Json);
                        if (entry is not null) events.Add(entry);
                    }
                }
                catch (Exception e) when (e is IOException or UnauthorizedAccessException or JsonException) { }
            }
            // Persisted logs were produced solely from structured metadata above.
            return string.Join(Environment.NewLine, events.Concat(recent).Distinct().OrderBy(e => e.Utc).TakeLast(200).Select(e => JsonSerializer.Serialize(e, Json)));
        }
    }
    public static string SafeOrigin(string server) => Uri.TryCreate(server, UriKind.Absolute, out var uri) && uri.Scheme is "https" or "http"
        ? new UriBuilder(uri.Scheme, uri.Host, uri.Port).Uri.GetLeftPart(UriPartial.Authority) : "Invalid server address";
}
