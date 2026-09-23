using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Text;
using Opal.Client;

internal static class DriveTransportTests
{
    public static void Run(Action<string, Action> check)
    {
        var hash = new string('a', 64);
        const string path = "/api/v1/drive/files/uuid/1";
        var settings = new AppSettings { ServerUrl = "https://opal.test", UseCustomServer = true, Token = "test-token" };
        check("drive reads validate the requested range and immutable identity", () =>
        {
            using var client = new OpalDriveClient(settings, new Handler(request =>
            {
                Require(request.RequestUri?.Host == "opal.test");
                Require(request.Headers.Authorization?.Parameter == "test-token");
                Require(request.Headers.Range?.ToString() == "bytes=2-5");
                Require(request.Headers.IfRange?.EntityTag?.Tag == $"\"{hash}\"");
                return Range("2345", 2, 5, 10, hash);
            }));
            var bytes = client.ReadAsync(path, hash, 10, 2, 4).GetAwaiter().GetResult();
            Require(Encoding.UTF8.GetString(bytes) == "2345");
        });
        check("drive rejects foreign origins and bounds memory before issuing requests", () =>
        {
            using var client = new OpalDriveClient(settings, new Handler(_ => throw new Exception("Must not send a request")));
            Reject<ArgumentException>(() => client.ReadAsync("https://foreign.test" + path, hash, 10, 0, 1).GetAwaiter().GetResult());
            Reject<ArgumentException>(() => client.ReadAsync("http://opal.test" + path, hash, 10, 0, 1).GetAwaiter().GetResult());
            Reject<ArgumentException>(() => client.ReadAsync("/api/v1/me", hash, 10, 0, 1).GetAwaiter().GetResult());
            Reject<ArgumentOutOfRangeException>(() => client.ReadAsync(path, hash, 10, 0, OpalDriveClient.MaximumReadBytes + 1).GetAwaiter().GetResult());
        });
        check("drive rejects full responses, changed identities, wrong ranges and truncated bodies", () =>
        {
            var full = new HttpResponseMessage(HttpStatusCode.OK) { Content = new StringContent("0123456789") };
            var wrongHash = Range("2345", 2, 5, 10, new string('b', 64));
            var wrongRange = Range("1234", 1, 4, 10, hash);
            var truncated = Range("23", 2, 5, 10, hash);
            truncated.Content.Headers.ContentLength = 4;
            foreach (var response in new[] { full, wrongHash, wrongRange, truncated })
            {
                using var client = new OpalDriveClient(settings, new Handler(_ => response));
                Reject<IOException>(() => client.ReadAsync(path, hash, 10, 2, 4).GetAwaiter().GetResult());
            }
        });
        check("drive respects manifest ETags and device links request scoped access", () =>
        {
            using var client = new OpalDriveClient(settings, new Handler(request =>
            {
                if (request.RequestUri!.AbsolutePath.EndsWith("manifest", StringComparison.Ordinal))
                {
                    Require(request.Headers.IfNoneMatch.Single().Tag == "\"manifest\"");
                    return new HttpResponseMessage(HttpStatusCode.NotModified);
                }
                Require(request.Content!.ReadAsStringAsync().GetAwaiter().GetResult().Contains("prismfs"));
                return new HttpResponseMessage(HttpStatusCode.Created)
                {
                    Content = new StringContent("{\"code\":\"ABCD-EFGH\",\"secret\":\"test\",\"verify_url\":\"https://foreign.test/link\",\"poll_interval\":2,\"expires_at\":\"2026-09-23T00:00:00Z\"}"),
                };
            }));
            var manifest = client.ManifestAsync("\"manifest\"").GetAwaiter().GetResult();
            Require(manifest.Manifest is null && manifest.ETag == "\"manifest\"");
            var link = client.StartLinkAsync("DESIGN").GetAwaiter().GetResult();
            Require(link.VerifyUrl == "https://opal.test/link?code=ABCD-EFGH");
        });
        check("Revit reports folder availability in its heartbeat", () =>
        {
            using var client = new OpalApiClient(settings, new Handler(request =>
            {
                var body = request.Content!.ReadAsStringAsync().GetAwaiter().GetResult();
                Require(body.Contains("\"drive_status\":\"missing\""));
                Require(body.Contains("\"mount_path\""));
                return new HttpResponseMessage(HttpStatusCode.OK) { Content = new StringContent("{\"data\":{}}") };
            }));
            client.HeartbeatWithDriveAsync(1, "Project", "O:\\", false).GetAwaiter().GetResult();
        });
    }

    private static HttpResponseMessage Range(string body, long start, long end, long total, string hash)
    {
        var response = new HttpResponseMessage(HttpStatusCode.PartialContent) { Content = new ByteArrayContent(Encoding.UTF8.GetBytes(body)) };
        response.Content.Headers.ContentRange = new ContentRangeHeaderValue(start, end, total);
        response.Headers.ETag = new EntityTagHeaderValue($"\"{hash}\"");
        return response;
    }

    private static void Require(bool value) { if (!value) throw new Exception("Drive transport assertion failed"); }
    private static void Reject<T>(Action action) where T : Exception
    {
        try { action(); } catch (T) { return; }
        throw new Exception($"Expected {typeof(T).Name}");
    }
    private sealed class Handler(Func<HttpRequestMessage, HttpResponseMessage> respond) : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken) => Task.FromResult(respond(request));
    }
}
