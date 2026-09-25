using System.Collections.Frozen;
using System.Text.Json;
using System.Text.RegularExpressions;

namespace Opal.Drive;

public sealed record RemoteFile(string Path, long Bytes, string Sha256, string ContentUrl);
public sealed record IncomingFolder(Guid Id, string Path, string Label, DateTimeOffset? ExpiresAt);
public sealed record DriveNode(string Path, RemoteFile? File = null)
{
    public bool IsDirectory => File is null;
    public string Name => Path[(Path.LastIndexOf('\\') + 1)..];
}

public static class DrivePath
{
    public static string Normalize(string path)
    {
        if (path is "/" or "\\" or "") return "\\";
        var parts = path.Replace('/', '\\').TrimStart('\\').Split('\\');
        if (path.Length > 1024 || parts.Any(p => p.Length is 0 or > 200 || p is "." or ".." || p.EndsWith('.') || p.EndsWith(' ')
            || p.Any(c => c < 32 || c == 127 || "<>:\"|?*".Contains(c))
            || Regex.IsMatch(p, @"^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)", RegexOptions.IgnoreCase)))
            throw new InvalidDataException("The drive contains an unsafe Windows path.");
        return "\\" + string.Join('\\', parts);
    }
    public static string Parent(string path) => path[..Math.Max(1, path.LastIndexOf('\\'))];
}

/// <summary>One immutable snapshot. Handles keep their original RemoteFile, never a mutable path lookup.</summary>
public sealed class DriveTree
{
    private readonly FrozenDictionary<string, DriveNode> nodes;
    private readonly FrozenDictionary<string, DriveNode[]> children;
    public IReadOnlyList<IncomingFolder> Incoming { get; }
    public int FileCount { get; }
    public static DriveTree Empty { get; } = new([], []);

    public DriveTree(IEnumerable<RemoteFile> files, IEnumerable<IncomingFolder> incoming, IEnumerable<RemoteFile>? intakeFiles = null, IEnumerable<string>? directories = null)
    {
        var entries = new Dictionary<string, DriveNode>(StringComparer.OrdinalIgnoreCase) { ["\\"] = new("\\") };
        void Directory(string path)
        {
            if (entries.TryGetValue(path, out var existing))
            {
                if (!existing.IsDirectory) throw new InvalidDataException("A file collides with a directory.");
                return;
            }
            Directory(DrivePath.Parent(path));
            entries.Add(path, new(path));
        }
        Directory("\\materials");
        foreach (var directory in directories ?? [])
        {
            if (directory is not ("/ingestion" or "/ingestion/workspace")) throw new InvalidDataException("Invalid reserved directory.");
            Directory(DrivePath.Normalize(directory));
        }
        foreach (var file in files)
        {
            var path = DrivePath.Normalize(file.Path);
            if (!path.StartsWith("\\materials\\", StringComparison.OrdinalIgnoreCase) || file.Bytes < 1
                || !Regex.IsMatch(file.Sha256, "^[a-f0-9]{64}$")
                || !Regex.IsMatch(file.ContentUrl, @"^/api/v1/drive/(?:files/[a-fA-F0-9-]{36}/[0-9]+|packages/[0-9]+)$"))
                throw new InvalidDataException("The drive manifest contains an invalid file.");
            Directory(DrivePath.Parent(path));
            if (!entries.TryAdd(path, new(path, file with { Path = path }))) throw new InvalidDataException("Duplicate drive path.");
            FileCount++;
        }
        Incoming = incoming.Where(i => i.ExpiresAt is null || i.ExpiresAt > DateTimeOffset.UtcNow).ToArray();
        if (Incoming.Count(i => IsInbox(i.Path)) > 1) throw new InvalidDataException("Duplicate upload root.");
        foreach (var folder in Incoming)
        {
            var expected = IsInbox(folder.Path) ? DrivePath.Normalize(folder.Path) : "\\Incoming\\" + folder.Id.ToString("D");
            if (!DrivePath.Normalize(folder.Path).Equals(expected, StringComparison.OrdinalIgnoreCase)) throw new InvalidDataException("Invalid upload folder.");
            Directory(expected);
        }
        foreach (var file in intakeFiles ?? [])
        {
            var path = DrivePath.Normalize(file.Path);
            var folder = Incoming.SingleOrDefault(i => path.StartsWith(DrivePath.Normalize(i.Path) + "\\", StringComparison.OrdinalIgnoreCase));
            if (folder is null || file.Bytes < 1 || !Regex.IsMatch(file.Sha256, "^[a-f0-9]{64}$")
                || !Regex.IsMatch(file.ContentUrl, "^/api/v1/drive/intake/" + folder.Id.ToString("D") + @"/files/[a-fA-F0-9-]{36}/content$"))
                throw new InvalidDataException("The drive manifest contains an unauthorized intake file.");
            Directory(DrivePath.Parent(path));
            if (!entries.TryAdd(path, new(path, file with { Path = path }))) throw new InvalidDataException("Duplicate drive path.");
            FileCount++;
        }
        nodes = entries.ToFrozenDictionary(StringComparer.OrdinalIgnoreCase);
        children = entries.Values.Where(n => n.Path != "\\").GroupBy(n => DrivePath.Parent(n.Path), StringComparer.OrdinalIgnoreCase)
            .ToFrozenDictionary(g => g.Key, g => g.OrderBy(n => n.Name, StringComparer.OrdinalIgnoreCase).ToArray(), StringComparer.OrdinalIgnoreCase);
    }
    public DriveNode? Find(string path) => nodes.GetValueOrDefault(DrivePath.Normalize(path));
    private static bool IsInbox(string path) => path.Equals("/upload", StringComparison.OrdinalIgnoreCase) || path.Equals("/ingestion/upload", StringComparison.OrdinalIgnoreCase);
    public IReadOnlyList<DriveNode> List(string path) => children.GetValueOrDefault(DrivePath.Normalize(path)) ?? [];
    public bool Contains(RemoteFile file) => Find(file.Path)?.File == file;
    public IncomingFolder? UploadFolder(string path)
    {
        var normalized = DrivePath.Normalize(path);
        return Incoming.FirstOrDefault(i => (i.ExpiresAt is null || i.ExpiresAt > DateTimeOffset.UtcNow)
            && (normalized.Equals(DrivePath.Normalize(i.Path), StringComparison.OrdinalIgnoreCase)
            || normalized.StartsWith(DrivePath.Normalize(i.Path) + "\\", StringComparison.OrdinalIgnoreCase)));
    }
    public static DriveTree Parse(JsonElement json)
    {
        if (json.GetProperty("contract").GetString() != "opal-drive/1" || json.GetProperty("library_writable").GetBoolean())
            throw new InvalidDataException("Unsupported OPAL drive contract.");
        var incoming = json.GetProperty("incoming").EnumerateArray().Select(i => new IncomingFolder(
            i.GetProperty("id").GetGuid(), i.GetProperty("path").GetString()!, i.GetProperty("label").GetString()!, i.GetProperty("expires_at").GetDateTimeOffset())).ToList();
        if (json.TryGetProperty("upload", out var upload) && upload.ValueKind != JsonValueKind.Null)
        {
            var uploadPath = upload.GetProperty("path").GetString()!;
            if (!IsInbox(uploadPath) || !upload.GetProperty("writable").GetBoolean())
                throw new InvalidDataException("Invalid root upload folder.");
            incoming.Add(new(upload.GetProperty("id").GetGuid(), uploadPath, upload.GetProperty("label").GetString()!, null));
        }
        return new(json.GetProperty("files").EnumerateArray().Select(f => new RemoteFile(
            f.GetProperty("path").GetString()!, f.GetProperty("bytes").GetInt64(), f.GetProperty("sha256").GetString()!, f.GetProperty("content_url").GetString()!)),
            incoming, json.TryGetProperty("intake_files", out var intakeFiles) ? intakeFiles.EnumerateArray().Select(f => new RemoteFile(
                f.GetProperty("path").GetString()!, f.GetProperty("bytes").GetInt64(), f.GetProperty("sha256").GetString()!, f.GetProperty("content_url").GetString()!)) : [],
            json.TryGetProperty("directories", out var dirs) ? dirs.EnumerateArray().Select(d => d.GetString()!) : []);
    }
}
