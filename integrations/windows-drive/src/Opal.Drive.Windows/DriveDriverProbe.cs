using System.Runtime.InteropServices;

namespace Opal.Drive.Windows;

public static class DriveDriverProbe
{
    // Version queries do not initialize or shut down Dokany's process-wide
    // resources. Diagnostics must not disturb an active filesystem instance.
    [DllImport("dokan2.dll", ExactSpelling = true)]
    [DefaultDllImportSearchPaths(DllImportSearchPath.System32)]
    private static extern uint DokanVersion();
    [DllImport("dokan2.dll", ExactSpelling = true)]
    [DefaultDllImportSearchPaths(DllImportSearchPath.System32)]
    private static extern uint DokanDriverVersion();
    public static (uint Library, uint Driver) Read()
    {
        var library = DokanVersion(); var driver = DokanDriverVersion();
        if (driver == 0) throw new DllNotFoundException("The Dokany driver is unavailable.");
        return (library, driver);
    }
}
