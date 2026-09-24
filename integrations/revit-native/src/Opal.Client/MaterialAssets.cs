using System.Security.Cryptography;
using System.Text.Json;

namespace Opal.Client;

public static class MaterialAssets
{
    public static string SafePath(string root, string relative)
    {
        var parts = relative.TrimStart('/').Split('/');
        if (parts.Length == 0 || parts.Any(part => string.IsNullOrWhiteSpace(part) || part == "." || part == ".." || part.IndexOfAny(new[] { '\\', ':', '\0' }) >= 0))
            throw new InvalidDataException("An OPAL material contains an unsafe path.");
        var fullRoot = Path.GetFullPath(root).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
        var path = Path.GetFullPath(Path.Combine(fullRoot, Path.Combine(parts)));
        if (!path.StartsWith(fullRoot, StringComparison.OrdinalIgnoreCase)) throw new InvalidDataException("Material path escaped its root.");
        return path;
    }

    public static bool Verified(string path, string sha, long bytes)
    {
        if (!File.Exists(path) || new FileInfo(path).Length != bytes) return false;
        using var input = File.OpenRead(path);
        using var hash = SHA256.Create();
        return BitConverter.ToString(hash.ComputeHash(input)).Replace("-", "").Equals(sha, StringComparison.OrdinalIgnoreCase);
    }

    public static async Task<Dictionary<string, string>> PrepareAsync(OpalApiClient api, JsonElement material, string mount, string cache, CancellationToken cancellationToken = default)
    {
        var result = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        foreach (var file in material.GetProperty("files").EnumerateArray())
        {
            var role = file.GetProperty("role").GetString()!;
            if (!new[] { "base_color", "bump", "height", "glossiness", "roughness" }.Contains(role)) continue;
            var relative = file.GetProperty("path").GetString()!;
            var hash = file.GetProperty("sha256").GetString()!;
            var bytes = file.GetProperty("bytes").GetInt64();
            if (bytes < 0 || bytes > 256L * 1024 * 1024) throw new InvalidDataException("Material texture exceeds the client limit.");
            var mounted = string.IsNullOrWhiteSpace(mount) ? null : SafePath(mount, relative);
            if (mounted != null && Verified(mounted, hash, bytes)) { result[role] = mounted; continue; }
            var local = SafePath(cache, relative);
            if (!Verified(local, hash, bytes))
            {
                Directory.CreateDirectory(Path.GetDirectoryName(local)!);
                var temporary = local + "." + Guid.NewGuid().ToString("N") + ".part";
                try
                {
                    using (var source = await api.DownloadMaterialAsync(file.GetProperty("url").GetString()!, cancellationToken).ConfigureAwait(false))
                    using (var output = File.Create(temporary))
                    {
                        var buffer = new byte[65536]; long total = 0; int read;
                        while ((read = await source.ReadAsync(buffer, 0, buffer.Length, cancellationToken).ConfigureAwait(false)) > 0)
                        {
                            total += read;
                            if (total > bytes) throw new InvalidDataException("Material download exceeded its declared size.");
                            await output.WriteAsync(buffer, 0, read, cancellationToken).ConfigureAwait(false);
                        }
                    }
                    if (!Verified(temporary, hash, bytes)) throw new InvalidDataException("Downloaded material checksum does not match OPAL.");
                    if (File.Exists(local)) File.Replace(temporary, local, null); else File.Move(temporary, local);
                }
                finally { if (File.Exists(temporary)) File.Delete(temporary); }
            }
            result[role] = local;
        }
        return result;
    }
}
