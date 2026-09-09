using System.Text.Json.Serialization;

namespace Opal.Client;

public sealed record ReleaseAsset(
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("sha256")] string Sha256,
    [property: JsonPropertyName("bytes")] long Bytes,
    [property: JsonPropertyName("url")] string Url);

public sealed record ReleaseManifest(
    [property: JsonPropertyName("version")] string Version,
    [property: JsonPropertyName("published_at")] DateTimeOffset PublishedAt,
    [property: JsonPropertyName("minimum_revit")] int MinimumRevit,
    [property: JsonPropertyName("commit")] string Commit,
    [property: JsonPropertyName("notes")] string Notes,
    [property: JsonPropertyName("channel")] string Channel,
    [property: JsonPropertyName("installer")] ReleaseAsset Installer,
    [property: JsonPropertyName("package")] ReleaseAsset Package);

public sealed record LinkStart(
    [property: JsonPropertyName("code")] string Code,
    [property: JsonPropertyName("secret")] string Secret,
    [property: JsonPropertyName("verify_url")] string VerifyUrl,
    [property: JsonPropertyName("poll_interval")] int PollInterval);

public sealed record LinkPoll(
    [property: JsonPropertyName("status")] string Status,
    [property: JsonPropertyName("token")] string? Token,
    [property: JsonPropertyName("user")] LinkedUser? User);

public sealed record LinkedUser(
    [property: JsonPropertyName("name")] string? Name,
    [property: JsonPropertyName("email")] string? Email);

public sealed record UpdateCheck(ReleaseManifest Release, bool Available, bool Compatible);

public sealed record StagedUpdate(
    [property: JsonPropertyName("version")] string Version,
    [property: JsonPropertyName("entryAssembly")] string EntryAssembly,
    [property: JsonPropertyName("stagedAt")] DateTimeOffset StagedAt);
