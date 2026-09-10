using System.Text.Json;
using System.Text.Json.Serialization;

namespace Opal.Client;

public sealed class AppSettings
{
    public string ServerUrl { get; set; } = "https://opal.olsyn.com";
    public string Token { get; set; } = string.Empty;
    public string AccountName { get; set; } = string.Empty;
    public string AccountEmail { get; set; } = string.Empty;
    public string DriveSlug { get; set; } = "studio-share";
    public string MountPath { get; set; } = @"M:\";
    public string UpdateChannel { get; set; } = "development";
    public bool AutoUpdate { get; set; } = true;
    public bool DeveloperMode { get; set; }

    [JsonIgnore]
    public bool IsLinked => !string.IsNullOrWhiteSpace(Token);
}

public sealed class ConfigStore
{
    private static readonly JsonSerializerOptions Json = new()
    {
        PropertyNamingPolicy = JsonNamingPolicy.CamelCase,
        PropertyNameCaseInsensitive = true,
        WriteIndented = true,
    };

    public ConfigStore(string? root = null, string? settingsRoot = null)
    {
        Root = root ?? ClientPaths.SharedRoot;
        SettingsRoot = settingsRoot ?? Root;
    }

    public string Root { get; }
    public string SettingsRoot { get; }
    public string Path => System.IO.Path.Combine(SettingsRoot, "config.json");

    public AppSettings Load()
    {
        if (!File.Exists(Path))
        {
            return ImportLegacy() ?? new AppSettings();
        }

        try
        {
            return JsonSerializer.Deserialize<AppSettings>(File.ReadAllText(Path), Json) ?? new AppSettings();
        }
        catch (JsonException)
        {
            return new AppSettings();
        }
    }

    public void Save(AppSettings settings)
    {
        Directory.CreateDirectory(SettingsRoot);
        var temporary = Path + ".new";
        File.WriteAllText(temporary, JsonSerializer.Serialize(settings, Json));
        File.Move(temporary, Path, true);
    }

    private AppSettings? ImportLegacy()
    {
        var legacy = System.IO.Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData),
            "OPAL",
            "config.json");

        if (!File.Exists(legacy))
        {
            return null;
        }

        try
        {
            using var document = JsonDocument.Parse(File.ReadAllText(legacy));
            var root = document.RootElement;
            var imported = new AppSettings
            {
                ServerUrl = Read(root, "api") ?? "https://opal.olsyn.com",
                Token = Read(root, "token") ?? string.Empty,
                DriveSlug = Read(root, "drive") ?? "studio-share",
                MountPath = Read(root, "mount") ?? @"M:\",
                AccountEmail = root.TryGetProperty("user", out var user) ? Read(user, "email") ?? string.Empty : string.Empty,
                AccountName = root.TryGetProperty("user", out user) ? Read(user, "name") ?? string.Empty : string.Empty,
            };
            Save(imported);
            return imported;
        }
        catch (JsonException)
        {
            return null;
        }
    }

    private static string? Read(JsonElement element, string property) =>
        element.TryGetProperty(property, out var value) && value.ValueKind == JsonValueKind.String
            ? value.GetString()
            : null;
}

public static class ClientPaths
{
    public static string SharedRoot => System.IO.Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "Olsyn",
        "OPAL");

    public static string RevitRoot(string revitVersion) => System.IO.Path.Combine(
        SharedRoot,
        "revit",
        revitVersion);

    public static string ActiveRevitRoot(string revitVersion, string assemblyLocation)
    {
        var versionRoot = System.IO.Path.GetFullPath(RevitRoot(revitVersion)) + System.IO.Path.DirectorySeparatorChar;
        var assemblyPath = System.IO.Path.GetFullPath(assemblyLocation);
        return assemblyPath.StartsWith(versionRoot, StringComparison.OrdinalIgnoreCase)
            ? RevitRoot(revitVersion)
            : SharedRoot;
    }
}
