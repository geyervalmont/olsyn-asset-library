using System.Reflection;
using System.Runtime.Loader;
using System.Text.Json;
using Autodesk.Revit.UI;

namespace Opal.Revit.Bootstrap;

public sealed class BootstrapApplication : IExternalApplication
{
    private const string EntryType = "Opal.Revit.EntryPoint";
    private static Type? entryPoint;
    private static AssemblyDependencyResolver? dependencyResolver;
    private static bool resolverRegistered;

    public Result OnStartup(UIControlledApplication application)
    {
        try
        {
            var root = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "Olsyn",
                "OPAL",
                "revit",
                application.ControlledApplication.VersionNumber);
            Directory.CreateDirectory(root);
            PromotePending(root);

            var current = ReadState(Path.Combine(root, "current.json"));
            var assemblyPath = ResolveInside(root, current.EntryAssembly);
            if (!File.Exists(assemblyPath))
            {
                throw new FileNotFoundException("The active OPAL Revit client is missing.", assemblyPath);
            }

            dependencyResolver = new AssemblyDependencyResolver(assemblyPath);
            if (!resolverRegistered)
            {
                AssemblyLoadContext.Default.Resolving += ResolveDependency;
                resolverRegistered = true;
            }

            // Revit later resolves IExternalCommand types from the assembly path stored on
            // each ribbon button. Keep the entry assembly in the default load context so
            // those commands share the ClientRuntime initialized during startup.
            var assembly = AssemblyLoadContext.Default.LoadFromAssemblyPath(assemblyPath);
            entryPoint = assembly.GetType(EntryType, throwOnError: true);
            entryPoint!.GetMethod("Start", BindingFlags.Public | BindingFlags.Static)!
                .Invoke(null, [application]);
            Log(root, $"started {current.Version} from {assemblyPath}");
            return Result.Succeeded;
        }
        catch (Exception exception)
        {
            var error = Unwrap(exception);
            TaskDialog.Show("OPAL", $"OPAL could not start.\n\n{error.Message}\n\nRun the OPAL installer again to repair it.");
            return Result.Failed;
        }
    }

    public Result OnShutdown(UIControlledApplication application)
    {
        try
        {
            entryPoint?.GetMethod("Stop", BindingFlags.Public | BindingFlags.Static)?.Invoke(null, [application]);
        }
        catch
        {
            // Revit is already closing; never make shutdown fail for telemetry.
        }
        return Result.Succeeded;
    }

    private static void PromotePending(string root)
    {
        var pendingPath = Path.Combine(root, "pending.json");
        if (!File.Exists(pendingPath))
        {
            return;
        }

        var pending = ReadState(pendingPath);
        var entry = ResolveInside(root, pending.EntryAssembly);
        if (!File.Exists(entry))
        {
            File.Move(pendingPath, pendingPath + ".invalid", true);
            throw new FileNotFoundException("The staged OPAL update is incomplete.", entry);
        }

        File.Move(pendingPath, Path.Combine(root, "current.json"), true);
        Log(root, $"activated {pending.Version}");
    }

    private static ClientState ReadState(string path)
    {
        if (!File.Exists(path))
        {
            throw new FileNotFoundException("OPAL has no active client version.", path);
        }

        return JsonSerializer.Deserialize<ClientState>(File.ReadAllText(path), new JsonSerializerOptions
        {
            PropertyNameCaseInsensitive = true,
        }) ?? throw new InvalidDataException("The OPAL client state is invalid.");
    }

    private static string ResolveInside(string root, string relative)
    {
        var canonicalRoot = Path.GetFullPath(root) + Path.DirectorySeparatorChar;
        var path = Path.GetFullPath(Path.Combine(root, relative));
        if (!path.StartsWith(canonicalRoot, StringComparison.OrdinalIgnoreCase))
        {
            throw new InvalidDataException("The OPAL client state points outside its installation.");
        }
        return path;
    }

    private static Exception Unwrap(Exception exception) =>
        exception is TargetInvocationException { InnerException: not null } invocation ? invocation.InnerException! : exception;

    private static Assembly? ResolveDependency(AssemblyLoadContext context, AssemblyName assemblyName)
    {
        if (assemblyName.Name?.StartsWith("Autodesk.Revit", StringComparison.Ordinal) == true)
        {
            return null;
        }

        var path = dependencyResolver?.ResolveAssemblyToPath(assemblyName);
        return path is null ? null : context.LoadFromAssemblyPath(path);
    }

    private static void Log(string root, string message)
    {
        try
        {
            File.AppendAllText(Path.Combine(root, "client.log"), $"{DateTimeOffset.Now:O} bootstrap: {message}{Environment.NewLine}");
        }
        catch
        {
            // Logging must never prevent Revit from opening.
        }
    }

    private sealed record ClientState(string Version, string EntryAssembly);
}
