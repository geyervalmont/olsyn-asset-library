using System.Text.Json;
using System.Windows;
using System.Windows.Controls;
using Opal.Client;

namespace Opal.Revit;

public sealed class MaterialPickerWindow : Window
{
    private readonly AppSettings settings;
    private readonly TextBox query = new() { MinHeight = 31, Margin = new Thickness(0, 5, 8, 10), Padding = new Thickness(6, 3, 6, 3) };
    private readonly ListBox results = new() { MinHeight = 330 };
    private readonly TextBlock status = new() { Margin = new Thickness(0, 8, 0, 8) };

    public MaterialPickerWindow(AppSettings settings, string materialName)
    {
        this.settings = settings;
        Title = $"Apply OPAL material to {materialName}";
        Width = 680;
        Height = 560;
        WindowStartupLocation = WindowStartupLocation.CenterScreen;

        var root = new DockPanel { Margin = new Thickness(22) };
        var top = new StackPanel();
        top.Children.Add(new TextBlock { Text = $"Target: {materialName}", FontSize = 17, FontWeight = FontWeights.SemiBold });
        top.Children.Add(new TextBlock { Text = "Search by material, supplier, product code, colourway or OPAL code.", Margin = new Thickness(0, 3, 0, 10) });
        var row = new DockPanel();
        var search = new Button { Content = "Search", MinWidth = 90, MinHeight = 31 };
        search.Click += async (_, _) => await Search(search);
        DockPanel.SetDock(search, Dock.Right);
        row.Children.Add(search);
        row.Children.Add(query);
        top.Children.Add(row);
        DockPanel.SetDock(top, Dock.Top);
        root.Children.Add(top);

        var bottom = new StackPanel { Margin = new Thickness(0, 8, 0, 0) };
        bottom.Children.Add(status);
        var actions = new StackPanel { Orientation = Orientation.Horizontal, HorizontalAlignment = HorizontalAlignment.Right };
        var apply = new Button { Content = "Apply selected", IsDefault = true, MinWidth = 125, MinHeight = 32 };
        apply.Click += (_, _) => Accept();
        var cancel = new Button { Content = "Cancel", IsCancel = true, MinWidth = 90, MinHeight = 32, Margin = new Thickness(8, 0, 0, 0) };
        actions.Children.Add(apply);
        actions.Children.Add(cancel);
        bottom.Children.Add(actions);
        DockPanel.SetDock(bottom, Dock.Bottom);
        root.Children.Add(bottom);

        results.MouseDoubleClick += (_, _) => Accept();
        root.Children.Add(results);
        Content = root;
        Loaded += (_, _) => query.Focus();
        query.KeyDown += async (_, args) =>
        {
            if (args.Key == System.Windows.Input.Key.Enter)
            {
                await Search(search);
            }
        };
    }

    public string? VariantCode { get; private set; }
    public string? Quality { get; private set; }

    private async Task Search(Button button)
    {
        button.IsEnabled = false;
        status.Text = "Searching OPAL…";
        try
        {
            using var api = new OpalApiClient(settings);
            var response = await api.SearchAsync(query.Text.Trim());
            var choices = new List<VariantChoice>();
            foreach (var material in response.EnumerateArray())
            {
                var materialName = material.GetProperty("name").GetString() ?? string.Empty;
                var supplier = material.TryGetProperty("supplier", out var supplierElement) && supplierElement.ValueKind == JsonValueKind.Object
                    ? supplierElement.GetProperty("name").GetString() ?? string.Empty
                    : string.Empty;
                if (!material.TryGetProperty("variants", out var variants) || variants.ValueKind != JsonValueKind.Array)
                {
                    continue;
                }
                foreach (var variant in variants.EnumerateArray())
                {
                    choices.Add(new VariantChoice(
                        variant.GetProperty("code").GetString() ?? string.Empty,
                        materialName,
                        variant.GetProperty("name").GetString() ?? string.Empty,
                        supplier));
                }
            }
            results.ItemsSource = choices;
            if (choices.Count > 0)
            {
                results.SelectedIndex = 0;
            }
            status.Text = choices.Count == 0 ? "No variants matched that search." : $"{choices.Count} variants found. The highest available Revit quality will be used.";
        }
        catch (Exception exception)
        {
            status.Text = exception.Message;
        }
        finally
        {
            button.IsEnabled = true;
        }
    }

    private void Accept()
    {
        if (results.SelectedItem is not VariantChoice choice)
        {
            status.Text = "Select a material variant first.";
            return;
        }
        VariantCode = choice.Code;
        DialogResult = true;
    }

    private sealed record VariantChoice(string Code, string Material, string Variant, string Supplier)
    {
        public override string ToString() => $"{Material} — {Variant}  ·  {Supplier}  ·  {Code}";
    }
}
