using Autodesk.Revit.Attributes;
using Autodesk.Revit.DB;
using Autodesk.Revit.UI;

namespace Opal.Revit;

[Transaction(TransactionMode.Manual)]
public sealed class SettingsCommand : IExternalCommand
{
    public Result Execute(ExternalCommandData commandData, ref string message, ElementSet elements)
    {
        Runtime.Current.SetDocument(commandData.Application.ActiveUIDocument?.Document?.Title);
        new SettingsWindow(Runtime.Current).ShowDialog();
        return Result.Succeeded;
    }
}

[Transaction(TransactionMode.Manual)]
public sealed class ApplySelectedCommand : IExternalCommand
{
    public Result Execute(ExternalCommandData commandData, ref string message, ElementSet elements)
    {
        try
        {
            Runtime.Current.SetDocument(commandData.Application.ActiveUIDocument?.Document?.Title);
            if (!Runtime.Current.Settings.IsLinked)
            {
                new SettingsWindow(Runtime.Current).ShowDialog();
                if (!Runtime.Current.Settings.IsLinked)
                {
                    return Result.Cancelled;
                }
            }

            var host = new RevitMaterialHost(commandData.Application);
            var material = host.SelectedMaterial();
            if (material is null)
            {
                return Result.Cancelled;
            }

            var picker = new MaterialPickerWindow(Runtime.Current.Settings, material.Name);
            if (picker.ShowDialog() != true || string.IsNullOrWhiteSpace(picker.VariantCode))
            {
                return Result.Cancelled;
            }

            var result = RevitWorkflow.Apply(commandData.Application, Runtime.Current.Settings, material, picker.VariantCode!, picker.Quality);
            TaskDialog.Show("OPAL", $"Applied {result.Variant} to {result.MaterialName}.\n\n{result.TextureCount} texture slots updated at {result.Quality}.");
            return Result.Succeeded;
        }
        catch (Autodesk.Revit.Exceptions.OperationCanceledException)
        {
            return Result.Cancelled;
        }
        catch (Exception exception)
        {
            message = exception.Message;
            TaskDialog.Show("OPAL", exception.Message);
            return Result.Failed;
        }
    }
}

[Transaction(TransactionMode.Manual)]
public sealed class SyncCommand : IExternalCommand
{
    public Result Execute(ExternalCommandData commandData, ref string message, ElementSet elements)
    {
        try
        {
            Runtime.Current.SetDocument(commandData.Application.ActiveUIDocument?.Document?.Title);
            if (!Runtime.Current.Settings.IsLinked)
            {
                new SettingsWindow(Runtime.Current).ShowDialog();
                if (!Runtime.Current.Settings.IsLinked)
                {
                    return Result.Cancelled;
                }
            }

            var report = RevitWorkflow.Sync(commandData.Application, Runtime.Current.Settings);
            var unmatched = report.Unmatched.Count == 0
                ? string.Empty
                : $"\n\nUnmatched ({report.Unmatched.Count}):\n{string.Join("\n", report.Unmatched.Take(20))}";
            TaskDialog.Show("OPAL material audit", $"{report.Matched.Count} matched, {report.Unmatched.Count} unmatched.{unmatched}");
            return Result.Succeeded;
        }
        catch (Exception exception)
        {
            message = exception.Message;
            TaskDialog.Show("OPAL", exception.Message);
            return Result.Failed;
        }
    }
}

[Transaction(TransactionMode.Manual)]
public sealed class BrowseCommand : IExternalCommand
{
    public Result Execute(ExternalCommandData commandData, ref string message, ElementSet elements)
    {
        try
        {
            if (!Runtime.Current.Settings.IsLinked) new SettingsWindow(Runtime.Current).ShowDialog();
            if (!Runtime.Current.Settings.IsLinked) return Result.Cancelled;
            var picker = new MaterialPickerWindow(Runtime.Current.Settings, "Browse the OPAL library");
            if (picker.ShowDialog() != true || picker.VariantCode == null) return Result.Cancelled;
            var document = commandData.Application.ActiveUIDocument?.Document;
            if (document == null) { TaskDialog.Show("OPAL", "Open a Revit project to import this material."); return Result.Cancelled; }
            using var group = new TransactionGroup(document, "Import OPAL material"); group.Start();
            Material material;
            using (var transaction = new Autodesk.Revit.DB.Transaction(document, "Create OPAL material"))
            {
                transaction.Start();
                material = new FilteredElementCollector(document).OfClass(typeof(Material)).Cast<Material>().FirstOrDefault(item => item.Name == picker.VariantCode)
                    ?? (Material)document.GetElement(Material.Create(document, picker.VariantCode));
                transaction.Commit();
            }
            RevitWorkflow.Apply(commandData.Application, Runtime.Current.Settings, new RevitMaterial(material.UniqueId, material.Name, new[] { material.Name }), picker.VariantCode, "preview");
            group.Assimilate();
            TaskDialog.Show("OPAL", "Material imported. Assign it from Revit’s Materials browser.");
            return Result.Succeeded;
        }
        catch (Exception ex) { message = ex.Message; TaskDialog.Show("OPAL", ex.Message); return Result.Failed; }
    }
}

[Transaction(TransactionMode.ReadOnly)]
public sealed class ExportMaterialMapCommand : IExternalCommand
{
    public Result Execute(ExternalCommandData commandData, ref string message, ElementSet elements)
    {
        var document = commandData.Application.ActiveUIDocument?.Document;
        if (document == null) return Result.Cancelled;
        var dialog = new Microsoft.Win32.SaveFileDialog { Filter = "OPAL material map|*.opal-materials.json", FileName = document.Title + ".opal-materials.json" };
        if (dialog.ShowDialog() != true) return Result.Cancelled;
        try { MaterialIdentity.Export(document, dialog.FileName); return Result.Succeeded; }
        catch (Exception ex) { message = ex.Message; return Result.Failed; }
    }
}
