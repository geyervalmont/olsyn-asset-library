using System.Text.Json;
using System.Text.RegularExpressions;

namespace Opal.Client;

public static class DriveMountDiscovery
{
    public static string? Find(AppSettings settings, string? root = null, Func<string, bool>? available = null)
    {
        if (string.IsNullOrWhiteSpace(settings.AccountEmail)) return null;
        try
        {
            var path = Path.Combine(root ?? ClientPaths.SharedRoot, "drive-connection.json");
            if (!File.Exists(path)) return null;
            using var json = JsonDocument.Parse(File.ReadAllText(path));
            var data = json.RootElement;
            var server = data.GetProperty("server").GetString();
            var email = data.GetProperty("account_email").GetString();
            var mount = data.GetProperty("mount_path").GetString();
            if (!string.Equals(server?.TrimEnd('/'), settings.EffectiveServerUrl.TrimEnd('/'), StringComparison.OrdinalIgnoreCase)
                || !string.Equals(email, settings.AccountEmail, StringComparison.OrdinalIgnoreCase)
                || mount is null || !Regex.IsMatch(mount, @"^[D-Z]:\\$", RegexOptions.IgnoreCase)) return null;
            return (available ?? Directory.Exists)(mount) ? mount : null;
        }
        catch (Exception error) when (error is IOException or UnauthorizedAccessException or JsonException or KeyNotFoundException or InvalidOperationException)
        { return null; }
    }
}
