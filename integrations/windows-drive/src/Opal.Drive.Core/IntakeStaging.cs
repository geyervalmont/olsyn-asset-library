using System.Security.Cryptography;
using System.Text;
using System.Text.Json;

namespace Opal.Drive;

public sealed record StagedFile(Guid Session, string RelativePath, string State = "pending", string? Error = null, string? UploadRoot = null)
{
    public string Path => (UploadRoot ?? "\\Incoming\\" + Session) + "\\" + RelativePath.Replace('/', '\\');
    public string Key => Convert.ToHexStringLower(SHA256.HashData(Encoding.UTF8.GetBytes((UploadRoot is null ? Path : Session + ":" + Path).ToLowerInvariant())));
}

/// <summary>Durable local upload queue. Explorer completion means staged locally; only server acknowledgement marks Uploaded.</summary>
public sealed class IntakeStaging
{
    private readonly string root;
    private readonly DriveSession session;
    private readonly object gate = new();
    private readonly Dictionary<string, StagedFile> files = new(StringComparer.OrdinalIgnoreCase);
    private readonly HashSet<string> writers = new(StringComparer.OrdinalIgnoreCase);
    private readonly SemaphoreSlim uploadGate = new(1, 1);
    private readonly Dictionary<Guid, HashSet<string>> directories = [];
    private HashSet<string> Directories(Guid batch)
    {
        if (!directories.TryGetValue(batch, out var paths)) directories[batch] = paths = new(StringComparer.OrdinalIgnoreCase);
        return paths;
    }
    private bool Visible(StagedFile file) => session.Tree.UploadFolder(file.Path)?.Id == file.Session;
    private StagedFile? Current(string path, Guid batch) => files.Values.FirstOrDefault(f => f.Session == batch && f.Path.Equals(path, StringComparison.OrdinalIgnoreCase));
    public const long FileLimit = 256L * 1024 * 1024;
    public const long TotalLimit = 2L * 1024 * 1024 * 1024;
    public event Action? Changed;
    public event Action<Exception>? Faulted;
    public IntakeStaging(string root, DriveSession session)
    {
        this.root = root;
        this.session = session;
        Directory.CreateDirectory(root);
        foreach (var path in Directory.EnumerateFiles(root, "*.json"))
        {
            var entry = JsonSerializer.Deserialize<StagedFile>(System.IO.File.ReadAllText(path));
            if (entry is null || !System.IO.File.Exists(Data(entry))) continue;
            DrivePath.Normalize(entry.Path);
            if (entry.State == "uploading") entry = entry with { State = "pending" };
            files.Add(entry.Key, entry);
            AddParents(entry.Path, entry.Session);
        }
    }
    public (int Pending, int Uploaded, int Failed) Counts
    {
        get { lock (gate) return (files.Values.Count(f => f.State is "pending" or "uploading"), files.Values.Count(f => f.State == "uploaded"), files.Values.Count(f => f.State == "failed")); }
    }
    private string Data(StagedFile file) => System.IO.Path.Combine(root, file.Key + ".data");
    private void Save(StagedFile file)
    {
        var destination = System.IO.Path.Combine(root, file.Key + ".json");
        System.IO.File.WriteAllText(destination + ".new", JsonSerializer.Serialize(file));
        System.IO.File.Move(destination + ".new", destination, true);
        files[file.Key] = file;
        Changed?.Invoke();
    }
    private void AddParents(string path, Guid batch)
    {
        for (var parent = DrivePath.Parent(path); parent != "\\Incoming" && parent != "\\"; parent = DrivePath.Parent(parent)) Directories(batch).Add(parent);
    }
    public IReadOnlyList<DriveNode> List(string path)
    {
        path = DrivePath.Normalize(path);
        session.RequireOnline();
        lock (gate)
        {
            var folder = session.Tree.UploadFolder(path);
            if (folder is null) return [];
            return Directories(folder.Id).Where(d => DrivePath.Parent(d).Equals(path, StringComparison.OrdinalIgnoreCase)).Select(d => new DriveNode(d))
                .Concat(files.Values.Where(f => f.Session == folder.Id && DrivePath.Parent(f.Path).Equals(path, StringComparison.OrdinalIgnoreCase))
                    .Select(f => new DriveNode(f.Path, new(f.Path, new FileInfo(Data(f)).Length, "", "")))).ToArray();
        }
    }
    public DriveNode? Find(string path)
    {
        path = DrivePath.Normalize(path);
        var folder = session.Tree.UploadFolder(path);
        if (folder is null) return null;
        lock (gate)
        {
            if (Directories(folder.Id).Contains(path)) return new(path);
            var file = Current(path, folder.Id);
            return file is not null ? new(path, new(path, new FileInfo(Data(file)).Length, "", "")) : null;
        }
    }
    public void CreateDirectory(string path)
    {
        path = DrivePath.Normalize(path);
        var folder = session.Tree.UploadFolder(path) ?? throw new UnauthorizedAccessException("This upload folder is unavailable. Check the ingestion page.");
        lock (gate)
        {
            if (Current(path, folder.Id) is not null) throw new IOException("A file already exists here.");
            Directories(folder.Id).Add(path); AddParents(path, folder.Id);
        }
    }
    public StageHandle Open(string path, FileMode mode, bool write)
    {
        path = DrivePath.Normalize(path);
        var folder = session.Tree.UploadFolder(path) ?? throw new UnauthorizedAccessException("This upload folder is closed.");
        var prefix = DrivePath.Normalize(folder.Path) + "\\";
        if (!path.StartsWith(prefix, StringComparison.OrdinalIgnoreCase)) throw new IOException("Open a file inside the upload folder.");
        lock (gate)
        {
            if (Directories(folder.Id).Contains(path)) throw new IOException("A directory already exists here.");
            var entry = Current(path, folder.Id);
            var exists = entry is not null;
            if (exists && writers.Contains(entry!.Key)) throw new IOException("This file is already being written.");
            if (exists && mode == FileMode.CreateNew) throw new IOException("This upload already exists.");
            if (!exists && (mode == FileMode.Open || !write)) throw new FileNotFoundException();
            if (write && exists && entry!.State is "uploaded" or "uploading" or "failed")
                throw new UnauthorizedAccessException("Reserved uploads cannot be replaced. Use a new file name or batch.");
            if (!exists)
            {
                if (files.Values.Count(f => f.Session == folder.Id) >= 500) throw new IOException("This upload batch is full.");
                entry = new(folder.Id, path[prefix.Length..].Replace('\\', '/'), UploadRoot: folder.Path == "/upload" ? "\\upload" : null);
                if (entry.RelativePath.Length > 512) throw new IOException("The upload path is too long.");
            }
            var stream = new FileStream(Data(entry!), mode, write ? FileAccess.ReadWrite : FileAccess.Read, FileShare.Read, 1, FileOptions.RandomAccess);
            if (write) writers.Add(entry!.Key);
            Save(entry!); AddParents(path, folder.Id);
            return new StageHandle(this, entry!, stream, write);
        }
    }
    public void Delete(string path)
    {
        path = DrivePath.Normalize(path);
        var folder = session.Tree.UploadFolder(path) ?? throw new UnauthorizedAccessException();
        lock (gate)
        {
            var file = Current(path, folder.Id);
            if (file is not null && writers.Contains(file.Key)) throw new IOException("Close the file before deleting it.");
            if (file is not null)
            {
                if (file.State != "pending") throw new UnauthorizedAccessException("Reserved uploads cannot be deleted. Close the batch on the ingestion page.");
                System.IO.File.Delete(Data(file));
                System.IO.File.Delete(System.IO.Path.Combine(root, file.Key + ".json"));
                files.Remove(file.Key);
            }
            else
            {
                if (files.Values.Where(f => f.Session == folder.Id).Select(f => f.Path).Concat(Directories(folder.Id)).Any(p => p.StartsWith(path + "\\", StringComparison.OrdinalIgnoreCase))) throw new IOException("The folder is not empty.");
                Directories(folder.Id).Remove(path);
            }
            Changed?.Invoke();
        }
    }
    public async Task UploadPendingAsync(CancellationToken cancel = default)
    {
        if (!await uploadGate.WaitAsync(0, cancel).ConfigureAwait(false)) return;
        try
        {
            StagedFile[] pending;
            lock (gate) pending = files.Values.Where(f => f.State is "pending" or "failed").ToArray();
            foreach (var candidate in pending)
            {
                cancel.ThrowIfCancellationRequested();
                if (!Visible(candidate)) continue;
                StagedFile active;
                lock (gate)
                {
                    if (writers.Contains(candidate.Key) || !files.TryGetValue(candidate.Key, out var current) || current.State is "uploaded" or "uploading") continue;
                    if (new FileInfo(Data(current)).Length == 0) continue;
                    active = current with { State = "uploading", Error = null };
                    Save(active);
                }
                try
                {
                    using var digestSource = System.IO.File.OpenRead(Data(active));
                    var hash = Convert.ToHexStringLower(await SHA256.HashDataAsync(digestSource, cancel).ConfigureAwait(false));
                    var reservation = await session.Client.ReserveAsync(active.Session, active.RelativePath, digestSource.Length, hash, cancel).ConfigureAwait(false);
                    await session.Client.UploadAsync(active.Session, reservation.GetProperty("id").GetGuid(), System.IO.File.OpenRead(Data(active)), digestSource.Length, cancel).ConfigureAwait(false);
                    lock (gate) Save(active with { State = "uploaded" });
                }
                catch (Exception error)
                {
                    if (error is not OperationCanceledException) Faulted?.Invoke(error);
                    lock (gate) Save(active with { State = "failed", Error = "Upload not confirmed. Keep the local copy and retry when connected." });
                    if (error is HttpRequestException { StatusCode: System.Net.HttpStatusCode.Unauthorized or System.Net.HttpStatusCode.Forbidden }) session.Invalidate(error);
                    if (error is OperationCanceledException) throw;
                }
            }
        }
        finally { uploadGate.Release(); }
    }
    public sealed class StageHandle(IntakeStaging store, StagedFile file, FileStream stream, bool writable) : IDisposable
    {
        private bool disposed;
        public bool Writable => writable;
        public long Length { get { lock (store.gate) return stream.Length; } }
        private void Check()
        {
            ObjectDisposedException.ThrowIf(disposed, this);
            if (!store.Visible(file)) throw new UnauthorizedAccessException("The upload folder has closed.");
        }
        public int Read(byte[] buffer, long offset) { lock (store.gate) { Check(); return RandomAccess.Read(stream.SafeFileHandle, buffer, offset); } }
        public void Write(byte[] buffer, long offset)
        {
            lock (store.gate)
            {
                Check(); if (!writable) throw new UnauthorizedAccessException();
                if (offset < 0) offset = stream.Length;
                var end = checked(offset + buffer.Length); Limit(end);
                RandomAccess.Write(stream.SafeFileHandle, buffer, offset);
            }
        }
        private void Limit(long length)
        {
            var used = new DirectoryInfo(store.root).EnumerateFiles("*.data").Sum(f => f.Length);
            if (length < 0 || length > FileLimit || used + Math.Max(0, length - stream.Length) > TotalLimit) throw new IOException("The local upload queue is full (256 MiB per file, 2 GiB total).");
        }
        public void SetLength(long length) { lock (store.gate) { Check(); if (!writable) throw new UnauthorizedAccessException(); Limit(length); stream.SetLength(length); } }
        public void Flush() { lock (store.gate) { Check(); stream.Flush(true); } }
        public void Dispose()
        {
            lock (store.gate)
            {
                if (disposed) return;
                disposed = true; stream.Flush(true); stream.Dispose();
                if (writable) store.writers.Remove(file.Key);
            }
        }
    }
}
