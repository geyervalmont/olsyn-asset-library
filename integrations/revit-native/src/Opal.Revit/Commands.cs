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

            var result = RevitWorkflow.Apply(commandData.Application, Runtime.Current.Settings, material, picker.VariantCode, picker.Quality);
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
