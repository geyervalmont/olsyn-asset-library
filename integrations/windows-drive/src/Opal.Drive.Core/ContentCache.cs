using System.Security.Cryptography;
using System.Text;
using Opal.Client;

namespace Opal.Drive;

/// <summary>Bounded content-addressed cache. Only complete, SHA-256 verified files become visible.</summary>
public sealed class ContentCache
{
    private readonly string root;
    private readonly long capacity;
    private readonly SemaphoreSlim gate = new(1, 1);
    private readonly HashSet<string> verified = new(StringComparer.Ordinal);
    private readonly Dictionary<string, int> pinned = new(StringComparer.Ordinal);
    public const long DefaultCapacity = 4L * 1024 * 1024 * 1024;
    public ContentCache(string root, long capacity = DefaultCapacity)
    {
        this.root = root;
        this.capacity = capacity;
        Directory.CreateDirectory(root);
        foreach (var file in Directory.EnumerateFiles(root, "*.partial")) File.Delete(file);
    }
    public static string AccountPartition(string origin, string accountId) =>
        Convert.ToHexStringLower(SHA256.HashData(Encoding.UTF8.GetBytes(origin.TrimEnd('/').ToLowerInvariant() + "\n" + accountId)));

    public async Task<Lease> OpenAsync(RemoteFile entry, OpalDriveClient client, CancellationToken cancel = default)
    {
        if (entry.Bytes > Math.Min(capacity, 1024L * 1024 * 1024)) throw new IOException("This file exceeds the drive cache limit.");
        await gate.WaitAsync(cancel).ConfigureAwait(false);
        try
        {
            var path = Path.Combine(root, entry.Sha256);
            if (File.Exists(path) && !verified.Contains(entry.Sha256))
            {
                using var stream = File.OpenRead(path);
                if (stream.Length == entry.Bytes && Convert.ToHexStringLower(await SHA256.HashDataAsync(stream, cancel).ConfigureAwait(false)) == entry.Sha256)
                    verified.Add(entry.Sha256);
            }
            if (!verified.Contains(entry.Sha256))
            {
                if (File.Exists(path)) File.Delete(path);
                Evict(entry.Bytes);
                var temporary = path + ".partial";
                try
                {
                    using (var output = new FileStream(temporary, FileMode.CreateNew, FileAccess.Write, FileShare.None, 65536, true))
                    using (var digest = IncrementalHash.CreateHash(HashAlgorithmName.SHA256))
                    {
                        for (long offset = 0; offset < entry.Bytes;)
                        {
                            var bytes = await client.ReadAsync(entry.ContentUrl, entry.Sha256, entry.Bytes, offset, OpalDriveClient.MaximumReadBytes, cancel).ConfigureAwait(false);
                            await output.WriteAsync(bytes, cancel).ConfigureAwait(false);
                            digest.AppendData(bytes);
                            offset += bytes.Length;
                        }
                        if (Convert.ToHexStringLower(digest.GetHashAndReset()) != entry.Sha256) throw new InvalidDataException("Material checksum verification failed.");
                        await output.FlushAsync(cancel).ConfigureAwait(false);
                    }
                    File.Move(temporary, path);
                    verified.Add(entry.Sha256);
                }
                finally { if (File.Exists(temporary)) File.Delete(temporary); }
            }
            File.SetLastWriteTimeUtc(path, DateTime.UtcNow);
            var read = new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.Read, 1, FileOptions.RandomAccess);
            pinned[entry.Sha256] = pinned.GetValueOrDefault(entry.Sha256) + 1;
            return new Lease(read, () => Release(entry.Sha256));
        }
        finally { gate.Release(); }
    }
    private void Evict(long needed)
    {
        var files = new DirectoryInfo(root).EnumerateFiles().Where(f => !f.Name.EndsWith(".partial", StringComparison.Ordinal)).OrderBy(f => f.LastWriteTimeUtc).ToArray();
        var used = files.Sum(f => f.Length);
        foreach (var file in files)
        {
            if (used + needed <= capacity) return;
            if (pinned.ContainsKey(file.Name)) continue;
            used -= file.Length;
            file.Delete();
            verified.Remove(file.Name);
        }
        if (used + needed > capacity) throw new IOException("The OPAL cache is full. Close documents using materials and retry.");
    }
    private void Release(string hash)
    {
        gate.Wait();
        try { if (--pinned[hash] == 0) pinned.Remove(hash); }
        finally { gate.Release(); }
    }
    public sealed class Lease(FileStream stream, Action release) : IDisposable
    {
        private readonly object gate = new();
        private bool disposed;
        public int Read(byte[] buffer, long offset)
        {
            lock (gate)
            {
                ObjectDisposedException.ThrowIf(disposed, this);
                return RandomAccess.Read(stream.SafeFileHandle, buffer, offset);
            }
        }
        public void Dispose()
        {
            lock (gate)
            {
                if (disposed) return;
                disposed = true;
                stream.Dispose();
                release();
            }
        }
    }
}
