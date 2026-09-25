using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;

namespace Opal.Client;

/// <summary>
/// Account-scoped HTTPS transport shared by the Windows filesystem adapter and clients.
/// Uses the system proxy/certificate trust and never redirects bearer credentials.
/// </summary>
public sealed class OpalDriveClient : IDisposable
{
    public const int MaximumReadBytes = 4 * 1024 * 1024;
    private readonly HttpClient http;
    private readonly Uri origin;

    public OpalDriveClient(AppSettings settings, HttpMessageHandler? handler = null)
    {
        origin = new Uri(settings.EffectiveServerUrl.TrimEnd('/') + "/", UriKind.Absolute);
        if (origin.Scheme != Uri.UriSchemeHttps && !(origin.Scheme == Uri.UriSchemeHttp && origin.IsLoopback))
            throw new ArgumentException("OPAL Drive requires HTTPS (HTTP is allowed on localhost for development).");
        if (!string.IsNullOrEmpty(origin.UserInfo) || origin.AbsolutePath != "/" || !string.IsNullOrEmpty(origin.Query) || !string.IsNullOrEmpty(origin.Fragment))
            throw new ArgumentException("Use the OPAL server origin without a path or credentials.");

        http = new HttpClient(handler ?? new HttpClientHandler { AllowAutoRedirect = false, DefaultProxyCredentials = CredentialCache.DefaultCredentials }, disposeHandler: true)
        {
            BaseAddress = origin,
            Timeout = TimeSpan.FromMinutes(5),
        };
        http.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        http.DefaultRequestHeaders.UserAgent.ParseAdd($"OPAL-Drive/{BuildInfo.Version}");
        http.DefaultRequestHeaders.Add("X-Opal-Drive-Layout", "2");
        if (settings.IsLinked)
            http.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", settings.Token);
    }

    public async Task<LinkStart> StartLinkAsync(string machine, CancellationToken cancellationToken = default)
    {
        var response = await JsonAsync(HttpMethod.Post, "/api/v1/link", new { client = "prismfs", machine, app_version = BuildInfo.Version }, cancellationToken).ConfigureAwait(false);
        var link = response.Deserialize<LinkStart>() ?? throw new JsonException("Missing device link.");
        return link with { VerifyUrl = new Uri(origin, $"link?code={Uri.EscapeDataString(link.Code)}").ToString() };
    }

    public async Task<LinkPoll> PollLinkAsync(LinkStart link, CancellationToken cancellationToken = default) =>
        (await JsonAsync(HttpMethod.Get, $"/api/v1/link/{Uri.EscapeDataString(link.Code)}?secret={Uri.EscapeDataString(link.Secret)}", null, cancellationToken).ConfigureAwait(false))
            .Deserialize<LinkPoll>() ?? throw new JsonException("Missing device link response.");

    public Task<JsonElement> BootstrapAsync(CancellationToken cancellationToken = default) =>
        JsonAsync(HttpMethod.Get, "/api/v1/drive/bootstrap", null, cancellationToken);

    public async Task RevokeAsync(CancellationToken cancellationToken = default)
    {
        using var request = new HttpRequestMessage(HttpMethod.Delete, ApiUri("/api/v1/account/token"));
        using var response = await http.SendAsync(request, cancellationToken).ConfigureAwait(false);
        response.EnsureSuccessStatusCode();
    }

    public async Task AuthorizeFileAsync(string contentUrl, string sha256, long bytes, CancellationToken cancellationToken = default)
    {
        using var request = new HttpRequestMessage(HttpMethod.Head, ApiUri(contentUrl));
        using var response = await http.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, cancellationToken).ConfigureAwait(false);
        response.EnsureSuccessStatusCode();
        if (response.StatusCode != HttpStatusCode.OK || response.Content.Headers.ContentLength != bytes || response.Headers.ETag?.Tag != $"\"{sha256}\"")
            throw new IOException("The published file changed. Refresh the drive before retrying.");
    }

    // A null manifest means 304. The adapter must partition its cache by account
    // and discard the namespace on sign-out, 401 or 403; no offline authorization.
    public async Task<DriveManifestResponse> ManifestAsync(string? etag = null, CancellationToken cancellationToken = default)
    {
        using var request = new HttpRequestMessage(HttpMethod.Get, ApiUri("/api/v1/drive/manifest"));
        if (etag is not null) request.Headers.IfNoneMatch.Add(EntityTagHeaderValue.Parse(etag));
        using var response = await http.SendAsync(request, cancellationToken).ConfigureAwait(false);
        if (response.StatusCode == HttpStatusCode.NotModified)
            return new DriveManifestResponse(response.Headers.ETag?.ToString() ?? etag, null);
        response.EnsureSuccessStatusCode();
        return new DriveManifestResponse(response.Headers.ETag?.ToString(), await ReadJsonAsync(response, cancellationToken).ConfigureAwait(false));
    }

    public Task<JsonElement> HeartbeatAsync(Guid deviceId, string machine, string state, string? mountPath, string? errorCode = null, CancellationToken cancellationToken = default) =>
        JsonAsync(HttpMethod.Post, "/api/v1/drive/heartbeat", new { device_id = deviceId, machine, version = BuildInfo.Version, state, mount_path = mountPath, error_code = errorCode }, cancellationToken);

    public Task<JsonElement> TelemetryAsync(Guid deviceId, JsonElement events, CancellationToken cancellationToken = default) =>
        JsonAsync(HttpMethod.Post, "/api/v1/drive/telemetry", new { device_id = deviceId, events }, cancellationToken);

    public Task<JsonElement> EnsureUploadInboxAsync(CancellationToken cancellationToken = default) =>
        JsonAsync(HttpMethod.Post, "/api/v1/drive/intake/inbox", new { }, cancellationToken);

    public Task<JsonElement> CreateIntakeAsync(string name, CancellationToken cancellationToken = default) =>
        JsonAsync(HttpMethod.Post, "/api/v1/drive/intake", new { name }, cancellationToken);

    public Task<JsonElement> IntakeAsync(Guid session, CancellationToken cancellationToken = default) =>
        JsonAsync(HttpMethod.Get, $"/api/v1/drive/intake/{session:D}", null, cancellationToken);

    public Task<JsonElement> ReserveAsync(Guid session, string path, long bytes, string sha256, CancellationToken cancellationToken = default) =>
        JsonAsync(HttpMethod.Post, $"/api/v1/drive/intake/{session:D}/files", new { path, bytes, sha256 }, cancellationToken);

    /// <summary>Uploads a reserved file; consumes and disposes the provided stream. Retry the entire file after a failure.</summary>
    public async Task UploadAsync(Guid session, Guid file, Stream content, long bytes, CancellationToken cancellationToken = default)
    {
        if (bytes < 1) throw new ArgumentOutOfRangeException(nameof(bytes));
        using var request = new HttpRequestMessage(HttpMethod.Put, ApiUri($"/api/v1/drive/intake/{session:D}/files/{file:D}"));
        request.Content = new StreamContent(content);
        request.Content.Headers.ContentLength = bytes;
        request.Content.Headers.ContentType = new MediaTypeWithQualityHeaderValue("application/octet-stream");
        using var response = await http.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, cancellationToken).ConfigureAwait(false);
        response.EnsureSuccessStatusCode();
    }

    public Task<JsonElement> SubmitAsync(Guid session, CancellationToken cancellationToken = default) =>
        JsonAsync(HttpMethod.Post, $"/api/v1/drive/intake/{session:D}/submit", new { }, cancellationToken);

    public async Task CancelAsync(Guid session, CancellationToken cancellationToken = default)
    {
        using var request = new HttpRequestMessage(HttpMethod.Delete, ApiUri($"/api/v1/drive/intake/{session:D}"));
        using var response = await http.SendAsync(request, cancellationToken).ConfigureAwait(false);
        response.EnsureSuccessStatusCode();
    }

    /// <summary>Read a bounded range from an immutable manifest entry. Rejects stale content and unexpected full responses.</summary>
    public async Task<byte[]> ReadAsync(string contentUrl, string sha256, long fileBytes, long offset, int count, CancellationToken cancellationToken = default)
    {
        if (fileBytes < 1 || offset < 0 || offset >= fileBytes || count < 1 || count > MaximumReadBytes)
            throw new ArgumentOutOfRangeException(nameof(count));
        if (sha256.Length != 64 || sha256.Any(c => !((c >= '0' && c <= '9') || (c >= 'a' && c <= 'f'))))
            throw new ArgumentException("A manifest SHA-256 is required.", nameof(sha256));
        var uri = ApiUri(contentUrl);
        if (!uri.AbsolutePath.StartsWith("/api/v1/drive/files/", StringComparison.Ordinal) && !uri.AbsolutePath.StartsWith("/api/v1/drive/packages/", StringComparison.Ordinal)
            && !System.Text.RegularExpressions.Regex.IsMatch(uri.AbsolutePath, @"^/api/v1/drive/intake/[a-fA-F0-9-]{36}/files/[a-fA-F0-9-]{36}/content$"))
            throw new ArgumentException("Content must be an OPAL drive file.", nameof(contentUrl));
        var length = (int)Math.Min(count, fileBytes - offset);
        var end = offset + length - 1;
        using var request = new HttpRequestMessage(HttpMethod.Get, uri);
        request.Headers.Range = new RangeHeaderValue(offset, end);
        request.Headers.IfRange = new RangeConditionHeaderValue(new EntityTagHeaderValue($"\"{sha256}\""));
        using var response = await http.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, cancellationToken).ConfigureAwait(false);
        response.EnsureSuccessStatusCode();
        var range = response.Content.Headers.ContentRange;
        if (response.StatusCode != HttpStatusCode.PartialContent || range?.Unit != "bytes" || range.From != offset || range.To != end || range.Length != fileBytes
            || response.Content.Headers.ContentLength != length || response.Headers.ETag?.Tag != $"\"{sha256}\"")
            throw new IOException("OPAL returned a different file or byte range. Refresh the manifest before retrying.");
#if NETFRAMEWORK
        using var stream = await response.Content.ReadAsStreamAsync().ConfigureAwait(false);
#else
        using var stream = await response.Content.ReadAsStreamAsync(cancellationToken).ConfigureAwait(false);
#endif
        var result = new byte[length];
        var read = 0;
        while (read < length)
        {
            var received = await stream.ReadAsync(result, read, length - read, cancellationToken).ConfigureAwait(false);
            if (received == 0) throw new EndOfStreamException("The drive read was interrupted. Retry the range.");
            read += received;
        }
        return result;
    }

    private Uri ApiUri(string path)
    {
        var uri = new Uri(origin, path);
        if (uri.Scheme != origin.Scheme || uri.Host != origin.Host || uri.Port != origin.Port || !string.IsNullOrEmpty(uri.UserInfo)
            || !uri.AbsolutePath.StartsWith("/api/v1/", StringComparison.Ordinal) || !string.IsNullOrEmpty(uri.Fragment))
            throw new ArgumentException("OPAL requests must stay on the selected server.", nameof(path));
        return uri;
    }

    private async Task<JsonElement> JsonAsync(HttpMethod method, string path, object? body, CancellationToken cancellationToken)
    {
        using var request = new HttpRequestMessage(method, ApiUri(path));
        if (body is not null) request.Content = JsonContent.Create(body);
        using var response = await http.SendAsync(request, cancellationToken).ConfigureAwait(false);
        response.EnsureSuccessStatusCode();
        return await ReadJsonAsync(response, cancellationToken).ConfigureAwait(false);
    }

    private static async Task<JsonElement> ReadJsonAsync(HttpResponseMessage response, CancellationToken cancellationToken)
    {
#if NETFRAMEWORK
        using var stream = await response.Content.ReadAsStreamAsync().ConfigureAwait(false);
#else
        using var stream = await response.Content.ReadAsStreamAsync(cancellationToken).ConfigureAwait(false);
#endif
        using var document = await JsonDocument.ParseAsync(stream, cancellationToken: cancellationToken).ConfigureAwait(false);
        var root = document.RootElement;
        return (root.TryGetProperty("data", out var data) ? data : root).Clone();
    }

    public void Dispose() => http.Dispose();
}

public sealed record DriveManifestResponse(string? ETag, JsonElement? Manifest);
