using System.Net;
using System.Net.Http;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Opal.Client;

internal static class MaterialAssetsTests
{
    public static void Run(Action<string, Action> check)
    {
        check("library paging retains metadata and material downloads stay on origin", () =>
        {
            var settings = new AppSettings { ServerUrl = "https://opal.test", UseCustomServer = true, Token = "test" };
            using var api = new OpalApiClient(settings, new Handler(request =>
            {
                Require(request.RequestUri!.Query.Contains("page=3"));
                return new HttpResponseMessage(HttpStatusCode.OK) { Content = new StringContent("{\"data\":[],\"meta\":{\"current_page\":3,\"last_page\":4}}") };
            }));
            var page = api.BrowseAsync("wood", 3).GetAwaiter().GetResult();
            Require(page.GetProperty("meta").GetProperty("current_page").GetInt32() == 3);
            Reject(() => api.DownloadMaterialAsync("https://evil.test/api/v1/file").GetAwaiter().GetResult());
        });
        check("texture cache verifies downloads and rejects corrupt bytes without replacing a good file", () =>
        {
            var root = Path.Combine(Path.GetTempPath(), "opal-assets-" + Guid.NewGuid().ToString("N"));
            try
            {
                var bytes = Encoding.UTF8.GetBytes("texture");
                using var sha = SHA256.Create();
                var hash = BitConverter.ToString(sha.ComputeHash(bytes)).Replace("-", "").ToLowerInvariant();
                var material = JsonSerializer.SerializeToElement(new { files = new[] { new { role = "base_color", path = "/materials/by-id/uuid/v1/map.png", sha256 = hash, bytes = bytes.Length, url = "/api/v1/file" } } });
                using var api = new OpalApiClient(new AppSettings(), new Handler(_ => new HttpResponseMessage(HttpStatusCode.OK) { Content = new ByteArrayContent(bytes) }));
                var prepared = MaterialAssets.PrepareAsync(api, material, "", root).GetAwaiter().GetResult();
                Require(File.ReadAllText(prepared["base_color"]) == "texture");
                File.WriteAllText(prepared["base_color"], "damaged");
                using var corrupt = new OpalApiClient(new AppSettings(), new Handler(_ => new HttpResponseMessage(HttpStatusCode.OK) { Content = new StringContent("bad") }));
                Reject(() => MaterialAssets.PrepareAsync(corrupt, material, "", root).GetAwaiter().GetResult());
                Require(File.ReadAllText(prepared["base_color"]) == "damaged");
                Reject(() => MaterialAssets.SafePath(root, "/../escape"));
                Reject(() => MaterialAssets.SafePath(root, "/c:/escape"));
            }
            finally { if (Directory.Exists(root)) Directory.Delete(root, true); }
        });
    }
    private static void Require(bool value) { if (!value) throw new Exception("Material asset assertion failed."); }
    private static void Reject(Action action)
    {
        try { action(); } catch (InvalidDataException) { return; }
        throw new Exception("Expected invalid material to be rejected.");
    }
    private sealed class Handler(Func<HttpRequestMessage, HttpResponseMessage> respond) : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken) => Task.FromResult(respond(request));
    }
}
