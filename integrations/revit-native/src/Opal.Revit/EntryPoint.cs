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

        AddButton(panel, "OpalApply", "Apply\nMaterial", typeof(ApplySelectedCommand), assembly, "Choose an OPAL material and apply it to the selected Revit material", RibbonIcon.Apply);
        AddButton(panel, "OpalSync", "Audit\nMaterials", typeof(SyncCommand), assembly, "Audit this document against the OPAL material library", RibbonIcon.Audit);
        AddButton(panel, "OpalSettings", "Settings", typeof(SettingsCommand), assembly, "Account, connection, material drive and update settings", RibbonIcon.Settings);
    }

    public static void Stop(UIControlledApplication application) => Runtime.Stop();

    private static void AddButton(RibbonPanel panel, string name, string text, Type command, string assembly, string tooltip, RibbonIcon icon)
    {
        if (panel.GetItems().Any(item => item.Name == name))
        {
            return;
        }

        var smallImage = RibbonIcons.Create(icon);
        var data = new PushButtonData(name, text, assembly, command.FullName)
        {
            ToolTip = tooltip,
            Image = smallImage,
            LargeImage = smallImage,
        };
        panel.AddItem(data);
    }
}
