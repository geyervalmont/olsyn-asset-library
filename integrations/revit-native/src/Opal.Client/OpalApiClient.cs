using System.Net;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;
using System.Text.Json.Nodes;

namespace Opal.Client;

public sealed class OpalApiException(HttpStatusCode statusCode, string message) : Exception(message)
{
    public HttpStatusCode StatusCode { get; } = statusCode;
}

public sealed class OpalApiClient : IDisposable
{
    private static readonly JsonSerializerOptions Json = new(JsonSerializerDefaults.Web)
    {
        PropertyNameCaseInsensitive = true,
    };

    private readonly HttpClient http;

    public OpalApiClient(AppSettings settings, HttpMessageHandler? handler = null)
    {
        var server = settings.ServerUrl.Trim().TrimEnd('/');
        if (!Uri.TryCreate(server + "/", UriKind.Absolute, out var baseAddress) ||
            (baseAddress.Scheme != Uri.UriSchemeHttps && baseAddress.Scheme != Uri.UriSchemeHttp))
        {
            throw new ArgumentException("OPAL server must be an absolute HTTP or HTTPS address.", nameof(settings));
        }

        http = handler is null ? new HttpClient() : new HttpClient(handler, disposeHandler: true);
        http.BaseAddress = baseAddress;
        http.Timeout = TimeSpan.FromSeconds(30);
        http.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        http.DefaultRequestHeaders.UserAgent.ParseAdd($"OPAL-Revit/{BuildInfo.Version}");
        if (settings.IsLinked)
        {
            http.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", settings.Token);
        }
    }

    public Task<LinkStart> StartLinkAsync(string machine, string revitVersion, CancellationToken cancellationToken = default) =>
        SendAsync<LinkStart>(HttpMethod.Post, "api/v1/link", new
        {
            client = "revit",
            machine,
            app_version = $"{BuildInfo.Version} / Revit {revitVersion}",
        }, dataEnvelope: false, cancellationToken);

    public Task<LinkPoll> PollLinkAsync(LinkStart link, CancellationToken cancellationToken = default) =>
        SendAsync<LinkPoll>(HttpMethod.Get, $"api/v1/link/{Uri.EscapeDataString(link.Code)}?secret={Uri.EscapeDataString(link.Secret)}", null, dataEnvelope: false, cancellationToken);

    public Task<ReleaseManifest> LatestReleaseAsync(string channel, int revitVersion, CancellationToken cancellationToken = default) =>
        SendAsync<ReleaseManifest>(HttpMethod.Get, $"api/v1/client-releases/revit/{revitVersion}/{Uri.EscapeDataString(channel)}", null, dataEnvelope: false, cancellationToken);

    public Task<JsonElement> MeAsync(CancellationToken cancellationToken = default) =>
        SendElementAsync(HttpMethod.Get, "api/v1/me", null, dataEnvelope: false, cancellationToken);

    public Task<JsonElement> DrivesAsync(CancellationToken cancellationToken = default) =>
        SendElementAsync(HttpMethod.Get, "api/v1/drives", null, dataEnvelope: true, cancellationToken);

    public Task<JsonElement> SearchAsync(string query, CancellationToken cancellationToken = default) =>
        SendElementAsync(HttpMethod.Get, $"api/v1/materials?q={Uri.EscapeDataString(query)}&per_page=50", null, dataEnvelope: true, cancellationToken);

    public Task<JsonElement> VariantAsync(string code, CancellationToken cancellationToken = default) =>
        SendElementAsync(HttpMethod.Get, $"api/v1/variants/{Uri.EscapeDataString(code)}", null, dataEnvelope: true, cancellationToken);

    public Task<JsonElement> VariantPathsAsync(string code, string drive, CancellationToken cancellationToken = default) =>
        SendElementAsync(HttpMethod.Get, $"api/v1/variants/{Uri.EscapeDataString(code)}/paths?drive={Uri.EscapeDataString(drive)}", null, dataEnvelope: true, cancellationToken);

    public Task<JsonElement> ResolveManyAsync(IEnumerable<string> references, CancellationToken cancellationToken = default) =>
        SendElementAsync(HttpMethod.Post, "api/v1/variants/resolve", new { platform = "revit", references }, dataEnvelope: true, cancellationToken);

    public Task<JsonElement> RegisterIdentityAsync(string code, object payload, CancellationToken cancellationToken = default) =>
        SendElementAsync(HttpMethod.Post, $"api/v1/variants/{Uri.EscapeDataString(code)}/identities", payload, dataEnvelope: true, cancellationToken);

    public Task<JsonElement> StartSessionAsync(string machine, string revitVersion, string? document, CancellationToken cancellationToken = default) =>
        SendElementAsync(HttpMethod.Post, "api/v1/sessions", new
        {
            platform = "revit",
            machine,
            app_version = $"OPAL {BuildInfo.Version} / Revit {revitVersion}",
            document,
        }, dataEnvelope: true, cancellationToken);

    public Task<JsonElement> CommandsAsync(int sessionId, CancellationToken cancellationToken = default) =>
        SendElementAsync(HttpMethod.Get, $"api/v1/sessions/{sessionId}/commands", null, dataEnvelope: true, cancellationToken);

    public Task AckAsync(int commandId, CancellationToken cancellationToken = default) =>
        SendElementAsync(HttpMethod.Post, $"api/v1/commands/{commandId}/ack", new { }, dataEnvelope: true, cancellationToken);

    public Task ResultAsync(int commandId, bool success, object? result, string? message, CancellationToken cancellationToken = default) =>
        SendElementAsync(HttpMethod.Post, $"api/v1/commands/{commandId}/result", new
        {
            status = success ? "done" : "failed",
            result,
            message,
        }, dataEnvelope: true, cancellationToken);

    public Task HeartbeatAsync(int sessionId, string? document, CancellationToken cancellationToken = default) =>
        SendElementAsync(HttpMethod.Post, $"api/v1/sessions/{sessionId}/heartbeat", new { document }, dataEnvelope: true, cancellationToken);

    public async Task EndSessionAsync(int sessionId, CancellationToken cancellationToken = default)
    {
        using var response = await http.DeleteAsync($"api/v1/sessions/{sessionId}", cancellationToken).ConfigureAwait(false);
        if (!response.IsSuccessStatusCode)
        {
            throw await Error(response, cancellationToken).ConfigureAwait(false);
        }
    }

    public async Task<Stream> DownloadAsync(string url, CancellationToken cancellationToken = default)
    {
        var response = await http.GetAsync(url, HttpCompletionOption.ResponseHeadersRead, cancellationToken).ConfigureAwait(false);
        if (!response.IsSuccessStatusCode)
        {
            var error = await Error(response, cancellationToken).ConfigureAwait(false);
            response.Dispose();
            throw error;
        }

        return new ResponseStream(await response.Content.ReadAsStreamAsync(cancellationToken).ConfigureAwait(false), response);
    }

    public void Dispose() => http.Dispose();

    private async Task<T> SendAsync<T>(HttpMethod method, string url, object? body, bool dataEnvelope, CancellationToken cancellationToken)
    {
        var element = await SendElementAsync(method, url, body, dataEnvelope, cancellationToken).ConfigureAwait(false);
        return element.Deserialize<T>(Json) ?? throw new JsonException($"OPAL returned no {typeof(T).Name}.");
    }

    private async Task<JsonElement> SendElementAsync(HttpMethod method, string url, object? body, bool dataEnvelope, CancellationToken cancellationToken)
    {
        using var request = new HttpRequestMessage(method, url);
        if (body is not null)
        {
            request.Content = JsonContent.Create(body, options: Json);
        }

        using var response = await http.SendAsync(request, cancellationToken).ConfigureAwait(false);
        if (!response.IsSuccessStatusCode)
        {
            throw await Error(response, cancellationToken).ConfigureAwait(false);
        }

        if (response.StatusCode == HttpStatusCode.NoContent)
        {
            return JsonSerializer.SerializeToElement(new { });
        }

        using var document = await JsonDocument.ParseAsync(
            await response.Content.ReadAsStreamAsync(cancellationToken).ConfigureAwait(false),
            cancellationToken: cancellationToken).ConfigureAwait(false);
        var root = document.RootElement;
        return (dataEnvelope && root.TryGetProperty("data", out var data) ? data : root).Clone();
    }

    private static async Task<OpalApiException> Error(HttpResponseMessage response, CancellationToken cancellationToken)
    {
        var message = response.ReasonPhrase ?? "OPAL request failed";
        try
        {
            var json = JsonNode.Parse(await response.Content.ReadAsStringAsync(cancellationToken).ConfigureAwait(false));
            message = json?["message"]?.GetValue<string>() ?? message;
        }
        catch (JsonException)
        {
            // Keep the HTTP reason when an intermediary returned non-JSON.
        }

        return new OpalApiException(response.StatusCode, message);
    }

    private sealed class ResponseStream(Stream inner, HttpResponseMessage response) : Stream
    {
        public override bool CanRead => inner.CanRead;
        public override bool CanSeek => inner.CanSeek;
        public override bool CanWrite => false;
        public override long Length => inner.Length;
        public override long Position { get => inner.Position; set => inner.Position = value; }
        public override void Flush() => inner.Flush();
        public override int Read(byte[] buffer, int offset, int count) => inner.Read(buffer, offset, count);
        public override long Seek(long offset, SeekOrigin origin) => inner.Seek(offset, origin);
        public override void SetLength(long value) => throw new NotSupportedException();
        public override void Write(byte[] buffer, int offset, int count) => throw new NotSupportedException();
        public override async ValueTask<int> ReadAsync(Memory<byte> buffer, CancellationToken cancellationToken = default) => await inner.ReadAsync(buffer, cancellationToken).ConfigureAwait(false);
        protected override void Dispose(bool disposing)
        {
            if (disposing)
            {
                inner.Dispose();
                response.Dispose();
            }
            base.Dispose(disposing);
        }
    }
}

public static class BuildInfo
{
    public static string Version => typeof(BuildInfo).Assembly.GetName().Version?.ToString() ?? "0.0.0.0";
}
