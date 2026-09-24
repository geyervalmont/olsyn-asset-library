using Microsoft.VisualBasic.ApplicationServices;

namespace Opal.Drive.Windows;

internal static class Program
{
    [STAThread]
    private static void Main(string[] args)
    {
        ApplicationConfiguration.Initialize();
        Application.ThreadException += (_, e) => ReportUnexpected(e.Exception);
        try { new DriveApplication().Run(args); }
        catch (Exception error) { ReportUnexpected(error); }
    }
    private static void ReportUnexpected(Exception error)
    {
        new DriveDiagnostics(Path.Combine(DriveSettings.Root, "logs")).Record(DriveStage.Startup, "error", error);
        MessageBox.Show("OPAL Drive could not complete that action. Open Status & diagnostics and save a report.\n" + error.GetType().Name, "OPAL Drive");
    }
}

// The Windows application framework forwards a second launch to the existing
// user's instance, including permission to bring its window to the foreground.
internal sealed class DriveApplication : WindowsFormsApplicationBase
{
    public DriveApplication() => IsSingleInstance = true;

    protected override void OnCreateMainForm() => MainForm = new DriveWindow(CommandLineArgs.Contains("--background"));

    protected override void OnStartupNextInstance(StartupNextInstanceEventArgs eventArgs)
    {
        eventArgs.BringToForeground = !eventArgs.CommandLine.Contains("--background");
        if (eventArgs.BringToForeground && MainForm is DriveWindow window) window.ShowWindow();
        base.OnStartupNextInstance(eventArgs);
    }
}
