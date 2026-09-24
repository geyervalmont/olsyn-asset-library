using System.IO.MemoryMappedFiles;
using System.Diagnostics;
using System.Net;
using System.Net.Sockets;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using DokanNet;
using DokanNet.Logging;
using Opal.Client;
using Opal.Drive;
using Opal.Drive.Windows;

var root = Path.Combine(Path.GetTempPath(), "opal-mounted-test-" + Guid.NewGuid());
Directory.CreateDirectory(root);
await using var server = new Fixture();
using var client = new OpalDriveClient(new AppSettings { UseCustomServer = true, ServerUrl = server.Origin, Token = "mount-test" });
var session = new DriveSession(client, new ContentCache(Path.Combine(root, "cache")));
await session.RefreshAsync();
var staging = new IntakeStaging(Path.Combine(root, "uploads"), session);
var filesystem = new MaterialFileSystem(session, staging);
using var dokan = new Dokan(new NullLogger());
var letter = Enumerable.Range('P', 11).Select(c => (char)c + @":\").First(p => !System.IO.DriveInfo.GetDrives().Any(d => d.Name.Equals(p, StringComparison.OrdinalIgnoreCase)));
Console.WriteLine($"Dokan {dokan.Version}, driver {dokan.DriverVersion}, mount {letter}");
// Exercise the application's exact native options, including its per-user ACL.
using (var instance = new DokanInstanceBuilder(dokan).ConfigureOptions(o => DriveMountOptions.Configure(o, letter)).Build(filesystem))
{
    for (int i = 0; i < 100 && !filesystem.IsMounted; i++) await Task.Delay(50);
    if (!filesystem.IsMounted) throw new Exception("Mount never confirmed");
    var path = Path.Combine(letter, "materials", "by-id", "texture.bin");
    var driverStatus = DriveDriverProbe.Read();
    if (driverStatus.Driver == 0 || driverStatus.Library == 0) throw new Exception("Driver diagnostic check failed");
    if (!Directory.GetDirectories(letter).Any(d => d.EndsWith("materials", StringComparison.OrdinalIgnoreCase))) throw new Exception("Enumeration failed");
    var bytes = File.ReadAllBytes(path); if (!bytes.SequenceEqual(server.Bytes)) throw new Exception("Mounted read differs");
    var namedPath = Path.Combine(letter, "materials", "by-name", "Stone", "Limestone", "Warm grey", "revit", "512", "base_color.bin");
    if (!File.ReadAllBytes(namedPath).SequenceEqual(bytes) || Directory.GetFiles(Path.Combine(root, "cache")).Length != 1) throw new Exception("Name alias differs or duplicates cached bytes");
    Console.WriteLine("PASS by-name browses and reads the same bytes through the shared content cache");
    using (var stream = File.OpenRead(path))
    { stream.Position = 4001; var data = new byte[9007]; stream.ReadExactly(data); if (!data.SequenceEqual(server.Bytes.Skip(4001).Take(data.Length))) throw new Exception("Seek failed"); }
    using (var mapped = MemoryMappedFile.CreateFromFile(path, FileMode.Open, null, 0, MemoryMappedFileAccess.Read))
    using (var view = mapped.CreateViewAccessor(8192, 4096, MemoryMappedFileAccess.Read))
    { var data = new byte[4096]; view.ReadArray(0, data, 0, data.Length); if (!data.SequenceEqual(server.Bytes.Skip(8192).Take(data.Length))) throw new Exception("Memory mapped read failed"); }
    Console.WriteLine("PASS read-only driver diagnostics preserve the mount; enumeration, sequential read, random seek and memory mapping");
    bool denied = false;
    try { File.WriteAllText(path, "overwrite"); } catch (UnauthorizedAccessException) { denied = true; } catch (IOException) { denied = true; }
    if (!denied) throw new Exception("Published material was writable");
    denied = false;
    try { File.WriteAllText(Path.Combine(letter, "forbidden.txt"), "write"); } catch (UnauthorizedAccessException) { denied = true; } catch (IOException) { denied = true; }
    if (!denied) throw new Exception("Drive root was writable");
    Console.WriteLine("PASS published files and drive root reject writes");
    var incoming = Path.Combine(letter, "Incoming", server.Batch.ToString(), "textures");
    Directory.CreateDirectory(incoming);
    var source = Path.Combine(root, "source.png"); await File.WriteAllBytesAsync(source, server.Bytes[..2048]);
    File.Copy(source, Path.Combine(incoming, "albedo.png"));
    if (!File.ReadAllBytes(Path.Combine(incoming, "albedo.png")).SequenceEqual(server.Bytes[..2048])) throw new Exception("Staged readback failed");
    await staging.UploadPendingAsync();
    if (server.Uploads != 1 || staging.Counts.Uploaded != 1) throw new Exception("Upload not acknowledged");
    Console.WriteLine("PASS Explorer-style directory creation, file copy, readback and upload acknowledgement");
    server.Denied = true;
    try { await session.RefreshAsync(); } catch (HttpRequestException) { }
    denied = false;
    try { using var newHandle = File.OpenRead(path); newHandle.ReadByte(); } catch (IOException) { denied = true; } catch (UnauthorizedAccessException) { denied = true; }
    if (!denied) throw new Exception("Revoked mount still allowed a new file open");
    Console.WriteLine("PASS revoked account blocks new Windows file handles");
}
for (int i = 0; i < 100 && filesystem.IsMounted; i++) await Task.Delay(50);
if (filesystem.IsMounted) throw new Exception("Drive did not unmount");
Directory.Delete(root, true);
Console.WriteLine("PASS clean unmount; all Windows mount smoke checks passed");
if (args.Length == 2 && args[0] == "--application")
{
    // Test the installed app's saved-account startup, not just its filesystem adapter.
    if (Process.GetProcessesByName("OPAL-Drive").Length > 0 || DriveSettings.Load().ProtectedToken.Length > 0)
        throw new InvalidOperationException("Use a test profile without a running or connected OPAL Drive.");
    var settingsPath = Path.Combine(DriveSettings.Root, "settings.json");
    var discoveryPath = Path.Combine(Directory.GetParent(DriveSettings.Root)!.FullName, "drive-connection.json");
    var telemetryPath = Path.Combine(DriveSettings.Root, "logs", "telemetry-outbox.json");
    var telemetrySaved = File.Exists(telemetryPath) ? File.ReadAllBytes(telemetryPath) : null;
    var saved = File.Exists(settingsPath) ? File.ReadAllBytes(settingsPath) : null;
    var discovery = File.Exists(discoveryPath) ? File.ReadAllBytes(discoveryPath) : null;
    Process? app = null;
    var partition = Path.Combine(DriveSettings.Root, "accounts", ContentCache.AccountPartition(server.Origin, "fixture-user"));
    try
    {
        server.Denied = false;
        var settings = new DriveSettings { Server = server.Origin, Mount = letter, AutoMount = true };
        settings.SetToken("mount-test"); settings.Save();
        app = Process.Start(new ProcessStartInfo(Path.GetFullPath(args[1]), "--background") { UseShellExecute = false })!;
        var deadline = DateTime.UtcNow.AddSeconds(30);
        bool mounted = false;
        while (DateTime.UtcNow < deadline && !app.HasExited)
        {
            if (File.Exists(discoveryPath))
            {
                using var advertised = JsonDocument.Parse(File.ReadAllText(discoveryPath));
                if (advertised.RootElement.GetProperty("device_id").GetGuid() == settings.DeviceId) { mounted = true; break; }
            }
            await Task.Delay(100);
        }
        if (!mounted) throw new Exception("Installed app did not complete account/bootstrap/manifest/driver/mount startup. Inspect its structured diagnostics log.");
        if (!File.ReadAllBytes(Path.Combine(letter, "materials", "by-id", "texture.bin")).SequenceEqual(server.Bytes)) throw new Exception("Installed app returned different bytes.");
        Console.WriteLine("PASS installed app decrypts saved credentials, validates account/library, mounts and reads through the production startup path");
        await server.ReadyReported.Task.WaitAsync(TimeSpan.FromSeconds(10));
        server.FailManifest = true;
        await server.ErrorReported.Task.WaitAsync(TimeSpan.FromSeconds(55));
        server.FailManifest = false;
        await server.Recovered.Task.WaitAsync(TimeSpan.FromSeconds(55));
        if (!File.ReadAllBytes(Path.Combine(letter, "materials", "by-id", "texture.bin")).SequenceEqual(server.Bytes)) throw new Exception("Drive did not recover after the injected failure.");
        Console.WriteLine("PASS installed app automatically reports a library HTTP 503 and subsequent ready state, then resumes material reads");
    }
    finally
    {
        if (app is not null) { if (!app.HasExited) app.Kill(entireProcessTree: true); await app.WaitForExitAsync(); app.Dispose(); }
        if (saved is null) File.Delete(settingsPath); else File.WriteAllBytes(settingsPath, saved);
        if (discovery is null) File.Delete(discoveryPath); else File.WriteAllBytes(discoveryPath, discovery);
        if (telemetrySaved is null) File.Delete(telemetryPath); else File.WriteAllBytes(telemetryPath, telemetrySaved);
        File.Delete(telemetryPath + ".new");
        File.Delete(settingsPath + ".new"); File.Delete(discoveryPath + ".new");
        if (Directory.Exists(partition)) Directory.Delete(partition, true);
    }
}

sealed class Fixture : IAsyncDisposable
{
    private readonly HttpListener listener = new();
    private readonly Task loop;
    public byte[] Bytes { get; } = Enumerable.Range(0, 1200000).Select(i => (byte)(i % 239)).ToArray();
    public string Origin { get; }
    public Guid Batch { get; } = Guid.NewGuid();
    public volatile bool Denied, FailManifest;
    public TaskCompletionSource ReadyReported { get; } = new(TaskCreationOptions.RunContinuationsAsynchronously);
    public TaskCompletionSource ErrorReported { get; } = new(TaskCreationOptions.RunContinuationsAsynchronously);
    public TaskCompletionSource Recovered { get; } = new(TaskCreationOptions.RunContinuationsAsynchronously);
    public int Uploads;
    private string Hash => Convert.ToHexStringLower(SHA256.HashData(Bytes));
    public Fixture()
    {
        var socket = new TcpListener(IPAddress.Loopback, 0); socket.Start(); var port = ((IPEndPoint)socket.LocalEndpoint).Port; socket.Stop();
        Origin = $"http://localhost:{port}"; listener.Prefixes.Add(Origin + "/"); listener.Start(); loop = Run();
    }
    private async Task Run()
    {
        while (listener.IsListening)
        {
            HttpListenerContext context;
            try { context = await listener.GetContextAsync(); } catch (HttpListenerException) { break; } catch (ObjectDisposedException) { break; }
            try { await Respond(context); }
            catch (Exception error) { Console.WriteLine("Fixture failure: " + error.GetType().Name); context.Response.Abort(); }
        }
    }
    private async Task Respond(HttpListenerContext c)
    {
        var r = c.Response;
        if (c.Request.Headers["Authorization"] != "Bearer mount-test" || Denied) { r.StatusCode = 403; r.Close(); return; }
        if (c.Request.Url!.AbsolutePath.EndsWith("telemetry"))
        {
            using var payload = await JsonDocument.ParseAsync(c.Request.InputStream);
            var events = payload.RootElement.GetProperty("events").EnumerateArray().ToArray();
            foreach (var entry in events)
            {
                if (entry.GetProperty("kind").GetString() == "ready")
                { if (ErrorReported.Task.IsCompleted) Recovered.TrySetResult(); ReadyReported.TrySetResult(); }
                if (entry.GetProperty("kind").GetString() == "error" && entry.GetProperty("stage").GetString() == "Library"
                    && entry.GetProperty("code").GetString() == "server" && entry.GetProperty("details").GetProperty("http_status").GetInt32() == 503) ErrorReported.TrySetResult();
            }
            await Json(r, new { data = new { accepted = events.Select(e => e.GetProperty("event_id").GetGuid()).ToArray() } }); return;
        }
        if (c.Request.Url!.AbsolutePath.EndsWith("bootstrap"))
        { await Json(r, new { data = new { contract = "opal-drive/1", account = new { id = "fixture-user", email = "fixture@example.invalid" } } }); return; }
        if (c.Request.Url!.AbsolutePath.EndsWith("manifest"))
        {
            if (FailManifest) { r.StatusCode = 503; r.Close(); return; }
            var manifest = new { contract = "opal-drive/1", library_writable = false,
                files = new[] { "/materials/by-id/texture.bin", "/materials/by-name/Stone/Limestone/Warm grey/revit/512/base_color.bin" }
                    .Select(path => new { path, bytes = Bytes.Length, sha256 = Hash, content_url = "/api/v1/drive/files/01951234-1234-7000-8000-000000000001/1" }).ToArray(),
                incoming = new[] { new { id = Batch, path = "/Incoming/" + Batch, label = "Mount test", expires_at = DateTimeOffset.UtcNow.AddHours(1) } } };
            await Json(r, manifest); return;
        }
        if (c.Request.HttpMethod == "POST") { await Json(r, new { data = new { id = "01951234-1234-7000-8000-000000000002" } }); return; }
        if (c.Request.HttpMethod == "PUT")
        { using var data = new MemoryStream(); await c.Request.InputStream.CopyToAsync(data); if (!data.ToArray().SequenceEqual(Bytes[..2048])) throw new Exception("Bad upload"); Interlocked.Increment(ref Uploads); r.StatusCode = 204; r.Close(); return; }
        r.Headers["ETag"] = '"' + Hash + '"'; r.Headers["Accept-Ranges"] = "bytes";
        if (c.Request.HttpMethod == "HEAD") { r.ContentLength64 = Bytes.Length; r.Close(); return; }
        var range = c.Request.Headers["Range"]![6..].Split('-'); var start = int.Parse(range[0]); var end = int.Parse(range[1]);
        r.StatusCode = 206; r.ContentLength64 = end - start + 1; r.Headers["Content-Range"] = $"bytes {start}-{end}/{Bytes.Length}";
        await r.OutputStream.WriteAsync(Bytes.AsMemory(start, end - start + 1)); r.Close();
    }
    private static async Task Json(HttpListenerResponse response, object value)
    { var data = Encoding.UTF8.GetBytes(JsonSerializer.Serialize(value)); response.ContentType = "application/json"; response.ContentLength64 = data.Length; await response.OutputStream.WriteAsync(data); response.Close(); }
    public async ValueTask DisposeAsync() { listener.Close(); await loop; }
}
