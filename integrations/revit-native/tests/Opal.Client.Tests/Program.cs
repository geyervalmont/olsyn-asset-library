using System.IO.Compression;
using System.Security.Cryptography;
using System.Text;
using Opal.Client;

var failures = new List<string>();

Check("versions compare numerically", () =>
{
    Require(UpdateService.Compare("0.1.0.10", "0.1.0.9") > 0);
    Require(UpdateService.Compare("1.0.0.0", "1.0.0.0") == 0);
});

Check("config round trips account, server and dev mode", () =>
{
    var root = Path.Combine(Path.GetTempPath(), "opal-client-tests", Guid.NewGuid().ToString("N"));
    var store = new ConfigStore(root);
    store.Save(new AppSettings
    {
        ServerUrl = "http://asset-library.test",
        Token = "secret",
        AccountEmail = "dev@example.com",
        DeveloperMode = true,
        UpdateChannel = "development",
    });
    var loaded = store.Load();
    Require(loaded.ServerUrl == "http://asset-library.test");
    Require(loaded.Token == "secret");
    Require(loaded.AccountEmail == "dev@example.com");
    Require(loaded.DeveloperMode);
    Directory.Delete(root, recursive: true);
});

Check("release models deserialize OPAL manifest names", () =>
{
    var json = """
        {"version":"0.1.0.12","published_at":"2026-09-09T00:00:00Z","minimum_revit":2027,"commit":"abc","notes":"test","channel":"development","installer":{"name":"setup.exe","sha256":"aa","bytes":1,"url":"https://opal.test/i"},"package":{"name":"package.zip","sha256":"bb","bytes":2,"url":"https://opal.test/p"}}
        """;
    var release = System.Text.Json.JsonSerializer.Deserialize<ReleaseManifest>(json)!;
    Require(release.Version == "0.1.0.12");
    Require(release.Package.Name == "package.zip");
    Require(release.MinimumRevit == 2027);
});

if (failures.Count > 0)
{
    Console.Error.WriteLine(string.Join(Environment.NewLine, failures));
    return 1;
}

Console.WriteLine("3 native client tests passed");
return 0;

void Check(string name, Action test)
{
    try
    {
        test();
        Console.WriteLine($"PASS {name}");
    }
    catch (Exception exception)
    {
        failures.Add($"FAIL {name}: {exception.Message}");
    }
}

static void Require(bool condition)
{
    if (!condition)
    {
        throw new InvalidOperationException("assertion failed");
    }
}
