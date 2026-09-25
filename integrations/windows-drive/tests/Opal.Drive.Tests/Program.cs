using System.Net;
using System.Net.Http.Headers;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Opal.Client;
using Opal.Drive;

var count = 0;
async Task Test(string name, Func<Task> test) { await test(); Console.WriteLine("PASS " + name); count++; }
void Check(bool value) { if (!value) throw new Exception("Assertion failed"); }
async Task Throws(Func<Task> action) { try { await action(); } catch { return; } throw new Exception("Expected failure"); }
var temp = Path.Combine(Path.GetTempPath(), "opal-drive-tests-" + Guid.NewGuid()); Directory.CreateDirectory(temp);
try
{
    await Test("diagnostics distinguish transport, authorization, data and local failures", () =>
    {
        Check(DriveFailure.From(new HttpRequestException(HttpRequestError.NameResolutionError, "private"), DriveStage.Account).Code == "dns");
        Check(DriveFailure.From(new HttpRequestException(HttpRequestError.SecureConnectionError, "private"), DriveStage.Account).Code == "tls");
        Check(DriveFailure.From(new HttpRequestException("private", null, HttpStatusCode.ProxyAuthenticationRequired), DriveStage.Account).Code == "proxy");
        Check(DriveFailure.From(new HttpRequestException("private", null, HttpStatusCode.Unauthorized), DriveStage.Library).Code == "sign_in");
        Check(DriveFailure.From(new HttpRequestException("private", null, HttpStatusCode.ServiceUnavailable), DriveStage.Library).Code == "server");
        Check(DriveFailure.From(new JsonException("private"), DriveStage.Library).Code == "invalid_response");
        Check(DriveFailure.From(new InvalidOperationException("private"), DriveStage.Account).Code == "invalid_response");
        Check(DriveFailure.From(new IOException("private"), DriveStage.LocalStorage).Code == "local_storage");
        Check(DriveFailure.From(new Exception("private"), DriveStage.Ready).Code == "unknown");
        Check(DriveFailure.From(new DriveDriverException(-5, new Exception("private")), DriveStage.Mount).DriverStatus == -5);
        Check(DriveFailure.From(new DriveMountInUseException(), DriveStage.Mount).HeartbeatCode == "mount_in_use");
        return Task.CompletedTask;
    });
    await Test("reports persist structured errors without credentials, personal paths or response bodies", () =>
    {
        const string secret = "secret-token-and-email@example.test";
        var root = Path.Combine(temp, "diagnostics");
        var journal = new DriveDiagnostics(root);
        journal.Record(DriveStage.Library, "error", new HttpRequestException("https://opal.test?token=" + secret, new IOException("C:\\Users\\" + secret), HttpStatusCode.BadGateway), 123);
        var reopened = new DriveDiagnostics(root);
        var report = reopened.ExportHistory();
        Check(!report.Contains(secret) && report.Contains("502") && report.Contains("123") && report.Contains("Library"));
        Check(!File.ReadAllText(Path.Combine(root, "diagnostics.jsonl")).Contains(secret));
        Check(DriveDiagnostics.SafeOrigin("https://user:password@opal.test/path?secret=token#fragment") == "https://opal.test");
        Check(DriveDiagnostics.SafeOrigin("invalid secret input") == "Invalid server address");
        return Task.CompletedTask;
    });
    await Test("diagnostic history is bounded and a log write failure cannot stop the drive", () =>
    {
        var root = Path.Combine(temp, "bounded-diagnostics"); var journal = new DriveDiagnostics(root);
        for (int i = 0; i < 1600; i++) journal.Record(DriveStage.Account, "error", new HttpRequestException("private", null, HttpStatusCode.BadGateway));
        Check(journal.Events().Length == 200 && Directory.GetFiles(root).Length == 2);
        Check(Directory.GetFiles(root).All(p => new FileInfo(p).Length <= DriveDiagnostics.FileLimit + 8192));
        Check(journal.ExportHistory().Split(Environment.NewLine).Length <= 200);
        var blocked = Path.Combine(temp, "blocked-log"); File.WriteAllText(blocked, "existing file");
        journal = new DriveDiagnostics(blocked); journal.Record(DriveStage.Library, "error", new JsonException("secret"));
        Check(!journal.LogAvailable && journal.Events().Length == 1 && !journal.ExportHistory().Contains("secret"));
        return Task.CompletedTask;
    });
    await Test("telemetry survives restart, samples repeats and acknowledges only confirmed IDs", () =>
    {
        var now = DateTimeOffset.UtcNow;
        var path = Path.Combine(temp, "telemetry", "queue.json");
        var scope = DriveTelemetryOutbox.Scope("https://opal.test", "secret-token", Guid.Empty);
        var error = new DriveDiagnosticEvent(now, DriveStage.Mount, "error", DriveFailure.From(new ArgumentException("private-email@example.test C:\\secret"), DriveStage.Mount), 120);
        var queue = new DriveTelemetryOutbox(path, scope, () => now);
        queue.Record(error, "0.1.3.0", "10.0.26200.0"); var id = queue.Batch().Single().EventId;
        for (int n = 0; n < 100; n++) queue.Record(error, "0.1.3.0", "10.0.26200.0");
        Check(queue.Count == 1);
        var content = File.ReadAllText(path); Check(!content.Contains("private-email") && !content.Contains("secret-token") && !content.Contains("C:"));
        queue = new(path, scope, () => now); Check(queue.Batch().Single().EventId == id);
        queue.Acknowledge([Guid.NewGuid()]); Check(queue.Count == 1);
        queue.Record(new(now, DriveStage.Ready, "ok"), "0.1.3.0", "10.0.26200.0");
        queue.Record(new(now, DriveStage.Ready, "ok"), "0.1.3.0", "10.0.26200.0"); Check(queue.Count == 2);
        queue.Acknowledge([id]); Check(queue.Count == 1 && queue.Batch()[0].Kind == "ready");
        now = now.AddMinutes(6); queue.Record(error with { Utc = now }, "0.1.3.0", "10.0.26200.0"); Check(queue.Count == 2);
        return Task.CompletedTask;
    });
    await Test("telemetry is bounded, expires offline records and cannot replay to a different account", () =>
    {
        var now = DateTimeOffset.UtcNow; var path = Path.Combine(temp, "telemetry-bounded", "queue.json");
        var scope = DriveTelemetryOutbox.Scope("https://opal.test", "one", Guid.Empty);
        var queue = new DriveTelemetryOutbox(path, scope, () => now);
        for (int n = 0; n < 240; n++)
        {
            queue.Record(new(now, DriveStage.Library, "error", DriveFailure.From(new HttpRequestException(), DriveStage.Library)), "0.1.3.0", "10.0.1");
            now = now.AddMinutes(6);
        }
        Check(queue.Count == 200 && queue.Batch().Length == 20 && new FileInfo(path).Length < 256 * 1024);
        queue = new(path, DriveTelemetryOutbox.Scope("https://opal.test", "two", Guid.Empty), () => now); Check(queue.Count == 0);
        queue.Record(new(now, DriveStage.Ready, "ok"), "0.1.3.0", "10.0.1"); now = now.AddDays(15);
        Check(new DriveTelemetryOutbox(path, DriveTelemetryOutbox.Scope("https://opal.test", "two", Guid.Empty), () => now).Count == 0);
        var blocked = Path.Combine(temp, "telemetry-blocked"); File.WriteAllText(blocked, "file");
        queue = new(Path.Combine(blocked, "queue.json"), scope); queue.Record(new(now, DriveStage.Ready, "ok"), "0.1.3.0", "10.0.1");
        Check(!queue.StorageAvailable && queue.Count == 1);
        return Task.CompletedTask;
    });
    await Test("background telemetry retries transport failures with the same event ID and never changes drive diagnostics", async () =>
    {
        var transport = new TelemetryFake { FailFirst = true }; var root = Path.Combine(temp, "telemetry-retry");
        await using var reporter = new DriveTelemetryReporter(root, "0.1.3.0", "10.0.26200.0", settings => new(settings, transport));
        var journal = new DriveDiagnostics(Path.Combine(temp, "telemetry-log")); journal.Recorded += reporter.Record;
        reporter.Configure("https://opal.test", "telemetry-test", Guid.Empty, true);
        journal.Record(DriveStage.Library, "error", new HttpRequestException("NEVER-UPLOAD-THIS", null, HttpStatusCode.ServiceUnavailable));
        await transport.Accepted.Task.WaitAsync(TimeSpan.FromSeconds(30));
        Check(transport.Ids.Count == 2 && transport.Ids.Distinct().Count() == 1 && !transport.Payload.Contains("NEVER-UPLOAD-THIS"));
        Check(journal.Events().Length == 1);
        reporter.Configure("https://opal.test", "telemetry-test", Guid.Empty, false);
        await Task.Delay(1500);
        Check(!File.Exists(Path.Combine(root, "telemetry-outbox.json")));
        journal.Record(DriveStage.Mount, "error", new DriveMountInUseException());
        await Task.Delay(1200); Check(transport.Ids.Count == 2);
    });
    await Test("manual connection checks stay local and disabling reporting cancels an active request", async () =>
    {
        var transport = new TelemetryFake { Block = true };
        await using var reporter = new DriveTelemetryReporter(Path.Combine(temp, "telemetry-cancel"), "0.1.3.0", "10.0.1", settings => new(settings, transport));
        var journal = new DriveDiagnostics(Path.Combine(temp, "manual-log")); journal.Recorded += reporter.Record;
        reporter.Configure("https://opal.test", "telemetry-test", Guid.Empty, true);
        journal.Record(DriveStage.Library, "error", new HttpRequestException(), report: false);
        await Task.Delay(1200); Check(transport.Ids.Count == 0);
        journal.Record(DriveStage.Mount, "error", new DriveMountInUseException());
        await transport.Started.Task.WaitAsync(TimeSpan.FromSeconds(3));
        reporter.Configure("https://opal.test", "", Guid.Empty, false);
        await transport.Cancelled.Task.WaitAsync(TimeSpan.FromSeconds(3));
    });
    await Test("namespace handles nested folders and case-insensitive lookup", () =>
    {
        var source = new Fake(); var tree = new DriveTree([source.Entry], []);
        Check(tree.Find(source.Entry.Path.ToUpperInvariant())?.File?.Sha256 == source.Entry.Sha256);
        Check(tree.List("\\").Single().Name == "materials");
        Check(tree.List("\\materials\\by-id").Single().Name == "texture.bin"); return Task.CompletedTask;
    });
    await Test("unsafe paths, collisions and content URL escapes are rejected", async () =>
    {
        var f = new Fake().Entry;
        foreach (var path in new[] { "/materials/../secret", "/materials/NUL.png", "/materials/file:stream", "/materials/folder./file", "/materials/a//file" })
            await Throws(() => Task.FromResult(new DriveTree([f with { Path = path }], [])));
        await Throws(() => Task.FromResult(new DriveTree([f, f with { Path = f.Path.ToUpperInvariant() }], [])));
        await Throws(() => Task.FromResult(new DriveTree([f, f with { Path = f.Path + "/child" }], [])));
        await Throws(() => Task.FromResult(new DriveTree([f with { ContentUrl = "https://elsewhere.test/secret" }], [])));
    });
    await Test("random reads, EOF and concurrent handles return exact verified bytes", async () =>
    {
        var remote = new Fake(); using var client = remote.Client(); var state = new DriveSession(client, new ContentCache(Path.Combine(temp,"read")));
        await state.RefreshAsync(); var file = state.Tree.Find(remote.Entry.Path)!.File!;
        using var a = await state.OpenAsync(file); using var b = await state.OpenAsync(file);
        var one = new byte[193]; var two = new byte[4096];
        await Task.WhenAll(a.ReadAsync(one, 127), b.ReadAsync(two, 907));
        Check(one.SequenceEqual(remote.Bytes.Skip(127).Take(193))); Check(two.SequenceEqual(remote.Bytes.Skip(907).Take(4096)));
        Check(await a.ReadAsync(one, remote.Bytes.Length) == 0); Check(remote.Reads == 1); Check(remote.Heads == 2);
    });
    await Test("a warm cache still checks authorization and revocation invalidates existing handles", async () =>
    {
        var remote = new Fake(); using var client = remote.Client(); var state = new DriveSession(client, new ContentCache(Path.Combine(temp,"auth")));
        await state.RefreshAsync(); var file = state.Tree.Find(remote.Entry.Path)!.File!;
        using var handle = await state.OpenAsync(file); await handle.ReadAsync(new byte[32], 0);
        remote.Denied = true;
        await Throws(() => state.OpenAsync(file)); Check(!state.Online);
        await Throws(() => handle.ReadAsync(new byte[32], 0));
        Check(remote.Reads == 1);
    });
    await Test("network failures never expose an empty successful namespace", async () =>
    {
        var remote = new Fake(); using var client = remote.Client(); var state = new DriveSession(client, new ContentCache(Path.Combine(temp,"network")));
        await state.RefreshAsync(); remote.Offline = true;
        await Throws(() => state.RefreshAsync()); Check(!state.Online);
        Check(state.LastFault is HttpRequestException);
        await Throws(() => Task.FromResult(state.Tree)); remote.Offline = false; await state.RefreshAsync(); Check(state.Tree.FileCount == 1 && state.LastFault is null);
    });
    await Test("unmount cancels pending network reads and leaves no partial cache file", async () =>
    {
        var remote = new Fake { SlowRead = true }; using var client = remote.Client(); var root = Path.Combine(temp,"stop");
        var state = new DriveSession(client,new ContentCache(root)); await state.RefreshAsync();
        using var handle = await state.OpenAsync(state.Tree.Find(remote.Entry.Path)!.File!);
        var pending = handle.ReadAsync(new byte[32],0);
        await remote.ReadStarted.Task.WaitAsync(TimeSpan.FromSeconds(2));
        state.Stop();
        await Throws(async () => await pending.WaitAsync(TimeSpan.FromSeconds(2)));
        Check(pending.IsCompleted && !state.Online && !Directory.EnumerateFiles(root).Any());
    });
    await Test("checksum mismatch cannot enter the cache", async () =>
    {
        var remote = new Fake { Corrupt = true }; using var client = remote.Client(); var root = Path.Combine(temp,"corrupt"); var state = new DriveSession(client, new ContentCache(root));
        await state.RefreshAsync(); using var handle = await state.OpenAsync(state.Tree.Find(remote.Entry.Path)!.File!);
        await Throws(() => handle.ReadAsync(new byte[32], 0)); Check(!Directory.EnumerateFiles(root).Any());
    });
    await Test("cache verifies existing disk content after restart", async () =>
    {
        var remote = new Fake(); using var client = remote.Client(); var root = Path.Combine(temp,"restart"); Directory.CreateDirectory(root);
        await File.WriteAllBytesAsync(Path.Combine(root,remote.Entry.Sha256), new byte[remote.Bytes.Length]);
        var state = new DriveSession(client,new ContentCache(root)); await state.RefreshAsync(); using var handle = await state.OpenAsync(state.Tree.Find(remote.Entry.Path)!.File!);
        var bytes = new byte[23]; await handle.ReadAsync(bytes,0); Check(bytes.SequenceEqual(remote.Bytes.Take(23))); Check(remote.Reads == 1);
    });
    await Test("cache capacity is bounded and cannot evict an open handle", async () =>
    {
        var remote = new Fake(); using var client = remote.Client(); var root = Path.Combine(temp,"bounded"); var cache = new ContentCache(root,remote.Bytes.Length);
        using (var pin = await cache.OpenAsync(remote.Entry,client))
        {
            remote.ReplaceContent(); await Throws(() => cache.OpenAsync(remote.Entry,client));
            var read = new byte[10]; Check(pin.Read(read,0) == 10);
        }
        using var next = await cache.OpenAsync(remote.Entry,client); Check(new DirectoryInfo(root).GetFiles().Sum(f=>f.Length) == remote.Bytes.Length);
    });
    await Test("cache partition binds server and account", () =>
    {
        Check(ContentCache.AccountPartition("https://one.test", "1") != ContentCache.AccountPartition("https://one.test", "2"));
        Check(ContentCache.AccountPartition("https://one.test", "1") != ContentCache.AccountPartition("https://two.test", "1")); return Task.CompletedTask;
    });
    await Test("Incoming writes stay private, durable and explicitly acknowledged", async () =>
    {
        var remote = new Fake { Incoming = true }; using var client = remote.Client(); var state = new DriveSession(client,new ContentCache(Path.Combine(temp,"stage-cache")));
        await state.RefreshAsync(); var root = Path.Combine(temp,"staging"); var queue = new IntakeStaging(root,state); var path = "\\Incoming\\" + remote.Batch + "\\textures\\oak.png";
        queue.CreateDirectory(DrivePath.Parent(path));
        using (var file = queue.Open(path,FileMode.CreateNew,true))
        { file.Write([1,2,3,4],0); await queue.UploadPendingAsync(); Check(remote.Uploads == 0); await Throws(()=>Task.Run(()=>file.SetLength(IntakeStaging.FileLimit+1))); }
        Check(queue.Counts.Pending == 1);
        queue = new IntakeStaging(root,state); Check(queue.Find(path)?.File?.Bytes == 4);
        await queue.UploadPendingAsync(); Check(queue.Counts.Uploaded == 1); Check(remote.Uploads == 1);
        await queue.UploadPendingAsync(); Check(remote.Uploads == 1);
        await Throws(()=>Task.FromResult(queue.Open(path,FileMode.Create,true)));
        using (var read = queue.Open(path,FileMode.Open,false)) { var data=new byte[4]; Check(read.Read(data,0)==4 && data.SequenceEqual(new byte[]{1,2,3,4})); }
        remote.Incoming = false; await state.RefreshAsync(); Check(queue.Find(path) is null);
        await Throws(()=>Task.FromResult(queue.Open(path,FileMode.Open,false)));
    });
    await Test("failed uploads remain recoverable and retry the same bytes", async () =>
    {
        var remote = new Fake { Incoming = true, FailUpload = true }; using var client=remote.Client(); var state=new DriveSession(client,new ContentCache(Path.Combine(temp,"retry-cache")));
        await state.RefreshAsync(); var queue=new IntakeStaging(Path.Combine(temp,"retry"),state); var path="\\Incoming\\"+remote.Batch+"\\sample.png";
        using(var write=queue.Open(path,FileMode.CreateNew,true)) write.Write([8,7,6],0);
        await queue.UploadPendingAsync(); Check(queue.Counts.Failed==1);
        remote.FailUpload=false; await queue.UploadPendingAsync(); Check(queue.Counts.Uploaded==1);
    });
    await Test("root upload starts automatically and retains isolated payloads when batches rotate", async () =>
    {
        var remote = new Fake { UploadRoot = true, NeedsInbox = true };
        using var client = remote.Client();
        var state = new DriveSession(client, new ContentCache(Path.Combine(temp, "inbox-cache")));
        await state.RefreshAsync();
        Check(remote.InboxCalls == 1 && state.Tree.Find("/upload")?.IsDirectory == true);
        await state.RefreshAsync(); Check(remote.InboxCalls == 1);
        var root = Path.Combine(temp, "inbox");
        var queue = new IntakeStaging(root, state);
        const string path = @"\upload\supplier\nested\material.mdl";
        using (var handle = queue.Open(path, FileMode.CreateNew, true)) handle.Write([1, 2, 3], 0);
        await queue.UploadPendingAsync(); Check(remote.Uploads == 1);
        queue = new IntakeStaging(root, state);
        Check(queue.Find(path)?.File?.Bytes == 3);
        using var oldHandle = queue.Open(path, FileMode.Open, false);
        remote.Batch = Guid.NewGuid(); remote.NeedsInbox = true;
        await state.RefreshAsync();
        Check(remote.InboxCalls == 2 && queue.Find(path) is null && queue.List(@"\upload").Count == 0);
        await Throws(() => Task.Run(() => oldHandle.Read(new byte[3], 0)));
        using (var handle = queue.Open(path, FileMode.CreateNew, true)) handle.Write([4, 5], 0);
        await queue.UploadPendingAsync(); Check(remote.Uploads == 2);
        queue = new IntakeStaging(root, state);
        Check(queue.Find(path)?.File?.Bytes == 2 && Directory.GetFiles(root, "*.data").Length == 2);
        remote.UploadRoot = false; await state.RefreshAsync();
        Check(state.Tree.Find("/upload") is null && queue.Find(path) is null);
        await Throws(() => Task.FromResult(queue.Open(path, FileMode.CreateNew, true)));
    });
    await Test("confirmed uploads from other clients are visible, range readable, immutable and revoked on rotation", async () =>
    {
        var remote = new Fake { UploadRoot = true, RemoteIntake = true };
        using var client = remote.Client();
        var state = new DriveSession(client, new ContentCache(Path.Combine(temp, "remote-intake-cache")));
        await state.RefreshAsync();
        var file = state.Tree.Find("/upload/nested/stone.mdl")!.File!;
        using var handle = await state.OpenAsync(file);
        var buffer = new byte[100];
        Check(await handle.ReadAsync(buffer, 123) == 100 && buffer.SequenceEqual(remote.Bytes.Skip(123).Take(100)));
        var staging = new IntakeStaging(Path.Combine(temp, "remote-intake-staging"), state);
        await Throws(() => Task.FromResult(staging.Open(file.Path, FileMode.Create, true)));
        remote.RemoteIntake = false; remote.Batch = Guid.NewGuid(); await state.RefreshAsync();
        Check(state.Tree.Find(file.Path) is null);
        await Throws(() => handle.ReadAsync(buffer, 0));
    });
    await Test("intake manifests cannot escape their authorized folder or substitute another batch", async () =>
    {
        var folder = new IncomingFolder(Guid.NewGuid(), "/upload", "Upload", null);
        var file = new Fake().Entry with { Path = "/upload/nested/stone.mdl", ContentUrl = $"/api/v1/drive/intake/{folder.Id}/files/{Guid.NewGuid()}/content" };
        Check(new DriveTree([], [folder], [file]).Find(file.Path)?.File is not null);
        await Throws(() => Task.FromResult(new DriveTree([], [], [file])));
        await Throws(() => Task.FromResult(new DriveTree([], [folder], [file with { Path = "/materials/stolen.mdl" }])));
        await Throws(() => Task.FromResult(new DriveTree([], [folder], [file with { ContentUrl = file.ContentUrl.Replace(folder.Id.ToString(), Guid.NewGuid().ToString()) }])));
        await Throws(() => Task.FromResult(new DriveTree([], [folder], [file, file])));
        await Throws(() => Task.FromResult(new DriveTree([], [folder with { ExpiresAt = DateTimeOffset.UtcNow.AddMinutes(-1) }], [file])));
    });
    await Test("layout upgrade resumes durable uploads without changing payload keys or replaying a closed batch", async () =>
    {
        var remote = new Fake { UploadRoot = true, FailUpload = true };
        using var client = remote.Client();
        var state = new DriveSession(client, new ContentCache(Path.Combine(temp, "layout-cache")));
        await state.RefreshAsync();
        var root = Path.Combine(temp, "layout-staging");
        var queue = new IntakeStaging(root, state);
        using (var write = queue.Open(@"\upload\supplier\stone.png", FileMode.CreateNew, true)) write.Write([9, 8, 7], 0);
        await queue.UploadPendingAsync(); Check(queue.Counts.Failed == 1);
        var payload = Directory.GetFiles(root, "*.data").Single();
        remote.NestedUpload = true; remote.FailUpload = false; await state.RefreshAsync();
        queue = new IntakeStaging(root, state);
        const string path = @"\ingestion\upload\supplier\stone.png";
        Check(state.Tree.Find("/upload") is null && state.Tree.Find("/ingestion/workspace")?.IsDirectory == true);
        Check(state.Tree.List("/ingestion").Count == 2 && queue.Find(path)?.File?.Bytes == 3);
        Check(queue.List(@"\ingestion\upload").Single().Name == "supplier");
        Check(state.Tree.UploadFolder("/ingestion/workspace") is null);
        await Throws(() => Task.FromResult(queue.Open(@"\ingestion\workspace\unsafe.png", FileMode.CreateNew, true)));
        await queue.UploadPendingAsync(); Check(remote.Uploads == 1 && queue.Counts.Uploaded == 1);
        Check(Directory.GetFiles(root, "*.data").Single() == payload);
        queue = new IntakeStaging(root, state); Check(queue.Find(path)?.File?.Bytes == 3);
        remote.Batch = Guid.NewGuid(); await state.RefreshAsync();
        await queue.UploadPendingAsync(); Check(remote.Uploads == 1 && queue.Find(path) is null);
    });
    await Test("reserved directories and nested inboxes cannot grant arbitrary drive writes", async () =>
    {
        var folder = new IncomingFolder(Guid.NewGuid(), "/ingestion/upload", "Upload", null);
        Check(new DriveTree([], [folder], directories: ["/ingestion/workspace"]).List("/ingestion").Count == 2);
        await Throws(() => Task.FromResult(new DriveTree([], [folder, folder with { Path = "/upload" }])));
        await Throws(() => Task.FromResult(new DriveTree([], [folder with { Path = "/ingestion/workspace" }])));
        await Throws(() => Task.FromResult(new DriveTree([], [], directories: ["/arbitrary"])));
    });
    Console.WriteLine($"{count} drive core tests passed");
}
finally { Directory.Delete(temp,true); }

sealed class Fake : HttpMessageHandler
{
    public byte[] Bytes = Enumerable.Range(0, 300000).Select(i=>(byte)(i%251)).ToArray();
    public bool Denied, Offline, Corrupt, Incoming, FailUpload, SlowRead, UploadRoot, NeedsInbox, RemoteIntake, NestedUpload;
    public string UploadPath => NestedUpload ? "/ingestion/upload" : "/upload";
    public TaskCompletionSource ReadStarted = new(TaskCreationOptions.RunContinuationsAsynchronously);
    public int Reads, Heads, Uploads, InboxCalls;
    public Guid Batch = Guid.NewGuid();
    public RemoteFile Entry => new("/materials/by-id/texture.bin", Bytes.Length, Convert.ToHexStringLower(SHA256.HashData(Bytes)), "/api/v1/drive/files/01951234-1234-7000-8000-000000000001/1");
    public OpalDriveClient Client() => new(new AppSettings { UseCustomServer=true, ServerUrl="https://opal.test", Token="test" }, this);
    public void ReplaceContent() => Bytes = Bytes.Select(b=>(byte)(b^255)).ToArray();
    protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request,CancellationToken cancellationToken)
    {
        if (!request.Headers.TryGetValues("X-Opal-Drive-Layout", out var layouts) || layouts.Single() != "2") throw new Exception("Missing layout negotiation");
        if(Offline) throw new HttpRequestException("Offline");
        if(Denied) return new(HttpStatusCode.Forbidden);
        if(request.RequestUri!.AbsolutePath.EndsWith("manifest"))
            return Json(new { contract="opal-drive/1", library_writable=false, directories=NestedUpload && UploadRoot ? new[]{"/ingestion", "/ingestion/workspace"} : [], intake_files=RemoteIntake ? new[]{new{path=UploadPath+"/nested/stone.mdl",bytes=Entry.Bytes,sha256=Entry.Sha256,content_url=$"/api/v1/drive/intake/{Batch}/files/01951234-1234-7000-8000-000000000003/content"}} : [], upload_enabled=UploadRoot, upload=UploadRoot && !NeedsInbox ? new { id=Batch, path=UploadPath, label="Drive upload", writable=true } : null, files=new[]{new{path=Entry.Path,bytes=Entry.Bytes,sha256=Entry.Sha256,content_url=Entry.ContentUrl}},incoming=Incoming?new[]{new{id=Batch,path="/Incoming/"+Batch,label="Textures",expires_at=DateTimeOffset.UtcNow.AddHours(1)}}:[] });
        if(request.RequestUri.AbsolutePath.EndsWith("intake/inbox")) { InboxCalls++; NeedsInbox=false; return Json(new { data = new { id=Batch } }); }
        if(request.Method==HttpMethod.Head)
        { Heads++;var result=new HttpResponseMessage(HttpStatusCode.OK){Content=new ByteArrayContent([])};result.Content.Headers.ContentLength=Bytes.Length;result.Headers.ETag=new EntityTagHeaderValue('"'+Entry.Sha256+'"');return result; }
        if(request.Method==HttpMethod.Get)
        { Reads++; ReadStarted.TrySetResult(); if (SlowRead) await Task.Delay(Timeout.Infinite,cancellationToken);
          var range=request.Headers.Range!.Ranges.Single();var data=Bytes[(int)range.From!.Value..((int)range.To!.Value+1)].ToArray();if(Corrupt)data[0]^=255;
          var result=new HttpResponseMessage(HttpStatusCode.PartialContent){Content=new ByteArrayContent(data)};result.Content.Headers.ContentRange=new ContentRangeHeaderValue(range.From.Value,range.To.Value,Bytes.Length);result.Headers.ETag=new EntityTagHeaderValue('"'+Entry.Sha256+'"');return result; }
        if(request.Method==HttpMethod.Post) return Json(new{data=new{id="01951234-1234-7000-8000-000000000002"}});
        if(request.Method==HttpMethod.Put)
        { if(FailUpload)return new(HttpStatusCode.ServiceUnavailable);var data=await request.Content!.ReadAsByteArrayAsync(cancellationToken);if(data.Length==0)throw new Exception("Empty upload");Uploads++;return new(HttpStatusCode.NoContent); }
        throw new Exception("Unexpected request");
    }
    private static HttpResponseMessage Json(object value)=>new(HttpStatusCode.OK){Content=new StringContent(JsonSerializer.Serialize(value),Encoding.UTF8,"application/json")};
}

sealed class TelemetryFake : HttpMessageHandler
{
    public bool FailFirst, Block;
    public List<Guid> Ids { get; } = [];
    public string Payload = "";
    public TaskCompletionSource Accepted { get; } = new(TaskCreationOptions.RunContinuationsAsynchronously);
    public TaskCompletionSource Started { get; } = new(TaskCreationOptions.RunContinuationsAsynchronously);
    public TaskCompletionSource Cancelled { get; } = new(TaskCreationOptions.RunContinuationsAsynchronously);
    protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
    {
        if (request.RequestUri?.AbsolutePath != "/api/v1/drive/telemetry" || request.Headers.Authorization?.Parameter != "telemetry-test") throw new Exception("Unexpected telemetry destination/credential");
        Payload = await request.Content!.ReadAsStringAsync(cancellationToken);
        using var json = JsonDocument.Parse(Payload);
        var events = json.RootElement.GetProperty("events").EnumerateArray().Select(e => e.GetProperty("event_id").GetGuid()).ToArray();
        Ids.AddRange(events); Started.TrySetResult();
        if (Block)
        {
            try { await Task.Delay(Timeout.Infinite, cancellationToken); }
            catch (OperationCanceledException) { Cancelled.TrySetResult(); throw; }
        }
        if (FailFirst) { FailFirst = false; return new(HttpStatusCode.ServiceUnavailable); }
        Accepted.TrySetResult();
        return new(HttpStatusCode.OK) { Content = new StringContent(JsonSerializer.Serialize(new { data = new { accepted = events } }), Encoding.UTF8, "application/json") };
    }
}
