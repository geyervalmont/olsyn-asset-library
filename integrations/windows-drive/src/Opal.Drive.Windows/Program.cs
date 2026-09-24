namespace Opal.Drive.Windows;

internal static class Program
{
    [STAThread]
    private static void Main(string[] args)
    {
        using var single = new Mutex(true, @"Local\Olsyn.OPAL.Drive", out var owner);
        if (!owner)
        {
            if (!args.Contains("--background")) MessageBox.Show("OPAL Drive is already running. Open it from the system tray.", "OPAL Drive");
            return;
        }
        ApplicationConfiguration.Initialize();
        Application.ThreadException += (_, e) => MessageBox.Show("OPAL Drive could not complete that action. Reopen the drive window and reconnect.\n" + e.Exception.GetType().Name, "OPAL Drive");
        Application.Run(new DriveWindow(args.Contains("--background")));
    }
}
