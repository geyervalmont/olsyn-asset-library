using System.Net;
using Opal.Client;

namespace Opal.Drive;

public sealed class DriveSession(OpalDriveClient client, ContentCache cache)
{
    private DriveTree tree = DriveTree.Empty;
    private long validUntil;
    private string? etag;
    private readonly SemaphoreSlim refresh = new(1, 1);
    private readonly CancellationTokenSource stopping = new();
    private Exception? lastFault;
    public Exception? LastFault => Volatile.Read(ref lastFault);
    public OpalDriveClient Client { get; } = client;
    public DriveTree Tree { get { RequireOnline(); return Volatile.Read(ref tree); } }
    public bool Online => Environment.TickCount64 < Interlocked.Read(ref validUntil);
    public event Action<Exception>? Faulted;
    public void Stop() { Invalidate(); stopping.Cancel(); }
    public void Invalidate(Exception? error = null)
    {
        Interlocked.Exchange(ref validUntil, 0);
        Volatile.Write(ref tree, DriveTree.Empty);
        etag = null;
        if (error is not null) ReportFault(error);
    }
    private void ReportFault(Exception error) { Volatile.Write(ref lastFault, error); Faulted?.Invoke(error); }
    public async Task RefreshAsync(CancellationToken cancel = default)
    {
        using var operation = CancellationTokenSource.CreateLinkedTokenSource(cancel, stopping.Token);
        cancel = operation.Token;
        await refresh.WaitAsync(cancel).ConfigureAwait(false);
        try
        {
            var result = await Client.ManifestAsync(etag, cancel).ConfigureAwait(false);
            if (result.Manifest is { } initial && initial.TryGetProperty("upload_enabled", out var enabled) && enabled.GetBoolean()
                && (!initial.TryGetProperty("upload", out var upload) || upload.ValueKind == System.Text.Json.JsonValueKind.Null))
            {
                await Client.EnsureUploadInboxAsync(cancel).ConfigureAwait(false);
                result = await Client.ManifestAsync(null, cancel).ConfigureAwait(false);
            }
            if (result.Manifest is { } json) Volatile.Write(ref tree, DriveTree.Parse(json));
            else if (etag is null) throw new InvalidDataException("Missing initial drive manifest.");
            etag = result.ETag;
            Volatile.Write(ref lastFault, null);
            Interlocked.Exchange(ref validUntil, Environment.TickCount64 + 45000);
        }
        catch (Exception error) { Invalidate(error); throw; }
        finally { refresh.Release(); }
    }
    public void RequireOnline()
    {
        if (!Online) throw new IOException("OPAL is offline. Reconnect before accessing materials.");
    }
    public async Task<ReadHandle> OpenAsync(RemoteFile file, CancellationToken cancel = default)
    {
        using var operation = CancellationTokenSource.CreateLinkedTokenSource(cancel, stopping.Token);
        cancel = operation.Token;
        RequireFile(file);
        // Every new handle reauthorizes even when all bytes are cached.
        await AuthorizeAsync(file, cancel).ConfigureAwait(false);
        return new ReadHandle(this, file, cache);
    }
    private void RequireFile(RemoteFile file)
    {
        if (!Tree.Contains(file)) throw new UnauthorizedAccessException("This material is no longer available to your account.");
    }
    private async Task AuthorizeAsync(RemoteFile file, CancellationToken cancel)
    {
        try { await Client.AuthorizeFileAsync(file.ContentUrl, file.Sha256, file.Bytes, cancel).ConfigureAwait(false); }
        catch (Exception error) { Invalidate(error); throw; }
        RequireFile(file);
    }
    public sealed class ReadHandle(DriveSession session, RemoteFile file, ContentCache cache) : IDisposable
    {
        private readonly SemaphoreSlim gate = new(1, 1);
        private long authorizedUntil = Environment.TickCount64 + 15000;
        private ContentCache.Lease? content;
        private bool disposed;
        public RemoteFile File { get; } = file;
        public async Task<int> ReadAsync(byte[] buffer, long offset, CancellationToken cancel = default)
        {
            using var operation = CancellationTokenSource.CreateLinkedTokenSource(cancel, session.stopping.Token);
            cancel = operation.Token;
            if (offset < 0) throw new ArgumentOutOfRangeException(nameof(offset));
            await gate.WaitAsync(cancel).ConfigureAwait(false);
            try
            {
                ObjectDisposedException.ThrowIf(disposed, this);
                session.RequireFile(File);
                if (Environment.TickCount64 >= authorizedUntil)
                {
                    await session.AuthorizeAsync(File, cancel).ConfigureAwait(false);
                    authorizedUntil = Environment.TickCount64 + 15000;
                }
                if (offset >= File.Bytes || buffer.Length == 0) return 0;
                content ??= await cache.OpenAsync(File, session.Client, cancel).ConfigureAwait(false);
                session.RequireFile(File);
                return content.Read(buffer, offset);
            }
            catch (HttpRequestException error) when (error.StatusCode is HttpStatusCode.Unauthorized or HttpStatusCode.Forbidden or HttpStatusCode.NotFound)
            { session.Invalidate(error); throw; }
            catch (Exception error) when (error is not OperationCanceledException)
            { session.ReportFault(error); throw; }
            finally { gate.Release(); }
        }
        public void Dispose()
        {
            gate.Wait();
            try { if (!disposed) { disposed = true; content?.Dispose(); } }
            finally { gate.Release(); }
        }
    }
}
