using Autodesk.Revit.UI;

namespace Opal.Revit;

public static class EntryPoint
{
    public static void Start(UIControlledApplication application)
    {
        Runtime.Start(application);

        try
        {
            application.CreateRibbonTab("OPAL");
        }
        catch (Autodesk.Revit.Exceptions.ArgumentException)
        {
            // A second installed OPAL version can see the existing tab.
        }

        var panel = application.GetRibbonPanels("OPAL").FirstOrDefault(item => item.Name == "Materials")
            ?? application.CreateRibbonPanel("OPAL", "Materials");
        var assembly = typeof(EntryPoint).Assembly.Location;

        AddButton(panel, "OpalSettings", "Settings", typeof(SettingsCommand), assembly, "Connection, account and update settings");
        AddButton(panel, "OpalApply", "Apply\nSelected", typeof(ApplySelectedCommand), assembly, "Apply an OPAL material to the selected Revit material");
        AddButton(panel, "OpalSync", "Sync", typeof(SyncCommand), assembly, "Audit this document against the OPAL material library");
    }

    public static void Stop(UIControlledApplication application) => Runtime.Stop();

    private static void AddButton(RibbonPanel panel, string name, string text, Type command, string assembly, string tooltip)
    {
        if (panel.GetItems().Any(item => item.Name == name))
        {
            return;
        }

        var data = new PushButtonData(name, text, assembly, command.FullName) { ToolTip = tooltip };
        panel.AddItem(data);
    }
}
