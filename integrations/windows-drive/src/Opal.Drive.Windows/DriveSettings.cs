using System.Security.Cryptography;
using System.Text;
using System.Text.Json;

namespace Opal.Drive.Windows;

public sealed class DriveSettings
{
    public string Server { get; set; } = "https://opal.olsyn.com";
    public string Mount { get; set; } = @"O:\";
    public Guid DeviceId { get; set; } = Guid.NewGuid();
    public string AccountId { get; set; } = "";
    public string AccountEmail { get; set; } = "";
    public string ProtectedToken { get; set; } = "";
    public bool AutoMount { get; set; } = true;
    public static string Root => Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "Olsyn", "OPAL", "Drive");
    private byte[] Entropy => SHA256.HashData(Encoding.UTF8.GetBytes(Server.TrimEnd('/').ToLowerInvariant()));
    public string Token()
    {
        if (ProtectedToken.Length == 0) return "";
        return Encoding.UTF8.GetString(ProtectedData.Unprotect(Convert.FromBase64String(ProtectedToken), Entropy, DataProtectionScope.CurrentUser));
    }
    public void SetToken(string token) => ProtectedToken = token.Length == 0 ? "" : Convert.ToBase64String(ProtectedData.Protect(Encoding.UTF8.GetBytes(token), Entropy, DataProtectionScope.CurrentUser));
    public void Save()
    {
        Directory.CreateDirectory(Root);
        var path = Path.Combine(Root, "settings.json");
        File.WriteAllText(path + ".new", JsonSerializer.Serialize(this));
        File.Move(path + ".new", path, true);
    }
    public static DriveSettings Load()
    {
        var path = Path.Combine(Root, "settings.json");
        return File.Exists(path) ? JsonSerializer.Deserialize<DriveSettings>(File.ReadAllText(path)) ?? new() : new();
    }
    public void PublishMount(bool mounted)
    {
        // Non-secret discovery shared with Revit and Kit; never changes their account credentials.
        var path = Path.Combine(Directory.GetParent(Root)!.FullName, "drive-connection.json");
        if (!mounted) { File.Delete(path); return; }
        File.WriteAllText(path + ".new", JsonSerializer.Serialize(new { server = Server, account_id = AccountId, account_email = AccountEmail, mount_path = Mount, device_id = DeviceId }));
        File.Move(path + ".new", path, true);
    }
}
