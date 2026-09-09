using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;

namespace Opal.Client;

public sealed class UpdateService(ConfigStore config, string installedVersion)
{
    private static readonly JsonSerializerOptions Json = new(JsonSerializerDefaults.Web) { WriteIndented = true };

    public async Task<UpdateCheck> CheckAsync(AppSettings settings, int revitVersion, CancellationToken cancellationToken = default)
    {
        using var api = new OpalApiClient(settings);
        var release = await api.LatestReleaseAsync(settings.UpdateChannel, cancellationToken).ConfigureAwait(false);
        var comparisonVersion = PendingVersion() is { } pending && Compare(pending, installedVersion) > 0
            ? pending
            : installedVersion;
        return new UpdateCheck(
            release,
            Compare(release.Version, comparisonVersion) > 0,
            release.MinimumRevit <= revitVersion);
    }

    public async Task<StagedUpdate> StageAsync(AppSettings settings, ReleaseManifest release, CancellationToken cancellationToken = default)
    {
        var updates = Path.Combine(config.Root, "updates");
        var versions = Path.Combine(config.Root, "versions");
        Directory.CreateDirectory(updates);
        Directory.CreateDirectory(versions);

        var archive = Path.Combine(updates, $"{release.Version}.zip.part");
        using (var api = new OpalApiClient(settings))
        await using (var source = await api.DownloadAsync(release.Package.Url, cancellationToken).ConfigureAwait(false))
        await using (var destination = new FileStream(archive, FileMode.Create, FileAccess.Write, FileShare.None))
        {
            await source.CopyToAsync(destination, cancellationToken).ConfigureAwait(false);
        }

        var actual = await Sha256Async(archive, cancellationToken).ConfigureAwait(false);
        if (!actual.Equals(release.Package.Sha256, StringComparison.OrdinalIgnoreCase))
        {
            File.Delete(archive);
            throw new InvalidDataException("The downloaded OPAL update did not match its published SHA-256.");
        }

        var destinationRoot = Path.Combine(versions, release.Version);
        var stagingRoot = destinationRoot + ".new";
        if (Directory.Exists(stagingRoot))
        {
            Directory.Delete(stagingRoot, recursive: true);
        }
        Directory.CreateDirectory(stagingRoot);
        ExtractSafely(archive, stagingRoot);

        var entryAssembly = Path.Combine(stagingRoot, "Opal.Revit.dll");
        if (!File.Exists(entryAssembly))
        {
            throw new InvalidDataException("The OPAL update contains no Revit entry assembly.");
        }

        if (Directory.Exists(destinationRoot))
        {
            Directory.Delete(destinationRoot, recursive: true);
        }
        Directory.Move(stagingRoot, destinationRoot);
        File.Delete(archive);

        var staged = new StagedUpdate(release.Version, Path.Combine("versions", release.Version, "Opal.Revit.dll"), DateTimeOffset.UtcNow);
        WriteAtomic(Path.Combine(config.Root, "pending.json"), JsonSerializer.Serialize(staged, Json));
        return staged;
    }

    public static int Compare(string left, string right)
    {
        if (!Version.TryParse(left, out var leftVersion) || !Version.TryParse(right, out var rightVersion))
        {
            return string.Compare(left, right, StringComparison.OrdinalIgnoreCase);
        }
        return leftVersion.CompareTo(rightVersion);
    }

    private static async Task<string> Sha256Async(string path, CancellationToken cancellationToken)
    {
        await using var stream = File.OpenRead(path);
        var hash = await SHA256.HashDataAsync(stream, cancellationToken).ConfigureAwait(false);
        return Convert.ToHexStringLower(hash);
    }

    private static void ExtractSafely(string archivePath, string destination)
    {
        var root = Path.GetFullPath(destination) + Path.DirectorySeparatorChar;
        using var archive = ZipFile.OpenRead(archivePath);
        foreach (var entry in archive.Entries)
        {
            var output = Path.GetFullPath(Path.Combine(destination, entry.FullName));
            if (!output.StartsWith(root, StringComparison.OrdinalIgnoreCase))
            {
                throw new InvalidDataException("The OPAL update contains an unsafe path.");
            }
            if (string.IsNullOrEmpty(entry.Name))
            {
                Directory.CreateDirectory(output);
                continue;
            }
            Directory.CreateDirectory(Path.GetDirectoryName(output)!);
            entry.ExtractToFile(output, overwrite: true);
        }
    }

    private static void WriteAtomic(string path, string contents)
    {
        var temporary = path + ".new";
        File.WriteAllText(temporary, contents);
        File.Move(temporary, path, true);
    }

    private string? PendingVersion()
    {
        var path = Path.Combine(config.Root, "pending.json");
        if (!File.Exists(path))
        {
            return null;
        }

        try
        {
            return JsonSerializer.Deserialize<StagedUpdate>(File.ReadAllText(path), Json)?.Version;
        }
        catch (JsonException)
        {
            return null;
        }
    }
}
