using System.Text.Json;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using Opal.Client;

namespace Opal.Revit;

public sealed class MaterialPickerWindow : Window
{
    private readonly AppSettings settings;
    private readonly TextBox query = new() { MinHeight = 32, MinWidth = 250, Margin = new Thickness(0, 0, 8, 0) };
    private readonly ComboBox category = new() { MinWidth = 140, Margin = new Thickness(0, 0, 8, 0), DisplayMemberPath = "Name" };
    private readonly ListBox results = new() { MinWidth = 390 };
    private readonly TextBlock status = new() { Margin = new Thickness(0, 12, 0, 10), TextWrapping = TextWrapping.Wrap };
    private readonly TextBlock details = new() { TextWrapping = TextWrapping.Wrap, Margin = new Thickness(0, 12, 0, 0) };
    private readonly Image preview = new() { Height = 240, Stretch = Stretch.Uniform };
    private readonly Button previous = new() { Content = "Previous", MinWidth = 80 };
    private readonly Button next = new() { Content = "Next", MinWidth = 80, Margin = new Thickness(8, 0, 16, 0) };
    private CancellationTokenSource searchCancellation = new();
    private CancellationTokenSource previewCancellation = new();
    private int page = 1;
    private int lastPage = 1;

    public MaterialPickerWindow(AppSettings settings, string materialName)
    {
        this.settings = settings;
        Title = "OPAL material library"; Width = 970; Height = 650;
        WindowStartupLocation = WindowStartupLocation.CenterScreen;
        var root = new DockPanel { Margin = new Thickness(22) };
        var top = new StackPanel();
        top.Children.Add(new TextBlock { Text = materialName, FontSize = 18, FontWeight = FontWeights.SemiBold });
        top.Children.Add(new TextBlock { Text = "Browse published materials. Revit uses 512 px textures; the material identity carries through to USD.", Margin = new Thickness(0, 6, 0, 16) });
        var searchRow = new StackPanel { Orientation = Orientation.Horizontal, Margin = new Thickness(0, 0, 0, 16) };
        searchRow.Children.Add(query); searchRow.Children.Add(category);
        var search = new Button { Content = "Search", MinWidth = 90 };
        search.Click += async (_, _) => { page = 1; await Search(); };
        query.KeyDown += async (_, args) => { if (args.Key == System.Windows.Input.Key.Enter) { page = 1; await Search(); } };
        searchRow.Children.Add(search); top.Children.Add(searchRow);
        DockPanel.SetDock(top, Dock.Top); root.Children.Add(top);
        var bottom = new StackPanel(); bottom.Children.Add(status);
        var actions = new StackPanel { Orientation = Orientation.Horizontal };
        previous.Click += async (_, _) => { if (page > 1) { page--; await Search(); } };
        next.Click += async (_, _) => { if (page < lastPage) { page++; await Search(); } };
        actions.Children.Add(previous); actions.Children.Add(next);
        var apply = new Button { Content = "Use selected material", MinWidth = 150, MinHeight = 34 };
        apply.Click += (_, _) => Accept(); actions.Children.Add(apply);
        actions.Children.Add(new Button { Content = "Close", IsCancel = true, MinWidth = 90, Margin = new Thickness(8, 0, 0, 0) });
        bottom.Children.Add(actions); DockPanel.SetDock(bottom, Dock.Bottom); root.Children.Add(bottom);
        var detailPanel = new StackPanel { Width = 310, Margin = new Thickness(20, 0, 0, 0) };
        detailPanel.Children.Add(preview); detailPanel.Children.Add(details);
        DockPanel.SetDock(detailPanel, Dock.Right); root.Children.Add(detailPanel);
        results.SelectionChanged += async (_, _) => await Preview(); root.Children.Add(results); Content = root;
        Loaded += async (_, _) =>
        {
            category.Items.Add(new CategoryChoice("", "All categories")); category.SelectedIndex = 0;
            try
            {
                using var api = new OpalApiClient(settings);
                var facets = await api.FacetsAsync(searchCancellation.Token);
                foreach (var item in facets.GetProperty("categories").EnumerateArray())
                    category.Items.Add(new CategoryChoice(item.GetProperty("code").GetString()!, item.GetProperty("name").GetString()!));
            }
            catch (Exception ex) { status.Text = ex.Message; }
            await Search(); query.Focus();
        };
        Closed += (_, _) => { searchCancellation.Cancel(); previewCancellation.Cancel(); };
    }
    public string? VariantCode { get; private set; }
    public string? Quality => "preview";
    private async Task Search()
    {
        searchCancellation.Cancel(); searchCancellation = new CancellationTokenSource();
        var cancellation = searchCancellation.Token;
        previous.IsEnabled = false; next.IsEnabled = false; status.Text = "Loading materials…";
        try
        {
            using var api = new OpalApiClient(settings);
            var response = await api.BrowseAsync(query.Text.Trim(), page, (category.SelectedItem as CategoryChoice)?.Code ?? "", cancellation);
            cancellation.ThrowIfCancellationRequested();
            var meta = response.GetProperty("meta"); lastPage = meta.GetProperty("last_page").GetInt32();
            results.ItemsSource = response.GetProperty("data").EnumerateArray().Select(item => new Choice(item.Clone())).ToArray();
            status.Text = $"Page {page} of {lastPage} · {meta.GetProperty("total").GetInt32()} variants";
            previous.IsEnabled = page > 1; next.IsEnabled = page < lastPage;
            if (results.Items.Count > 0) results.SelectedIndex = 0;
        }
        catch (OperationCanceledException) { }
        catch (Exception ex) { status.Text = ex.Message; }
    }
    private async Task Preview()
    {
        previewCancellation.Cancel(); previewCancellation = new CancellationTokenSource();
        var cancellation = previewCancellation.Token; preview.Source = null; details.Text = "";
        if (results.SelectedItem is not Choice choice) return;
        var item = choice.Data;
        details.Text = $"{choice}\n\n{item.GetProperty("description").GetString()}\n\nVersion {item.GetProperty("material_version")} · {item.GetProperty("tile_width_mm")} × {item.GetProperty("tile_height_mm")} mm";
        try
        {
            using var api = new OpalApiClient(settings);
            using var source = await api.DownloadMaterialAsync(item.GetProperty("preview_url").GetString()!, cancellation);
            using var memory = new System.IO.MemoryStream();
            var buffer = new byte[65536]; int read;
            while ((read = await source.ReadAsync(buffer, 0, buffer.Length, cancellation)) > 0)
            {
                if (memory.Length + read > 8 * 1024 * 1024) throw new System.IO.InvalidDataException("Preview exceeds the size limit.");
                memory.Write(buffer, 0, read);
            }
            cancellation.ThrowIfCancellationRequested(); memory.Position = 0;
            var bitmap = new BitmapImage(); bitmap.BeginInit(); bitmap.CacheOption = BitmapCacheOption.OnLoad; bitmap.DecodePixelWidth = 512; bitmap.StreamSource = memory; bitmap.EndInit(); bitmap.Freeze(); preview.Source = bitmap;
        }
        catch (OperationCanceledException) { }
        catch (Exception) { if (!cancellation.IsCancellationRequested) details.Text += "\n\nPreview unavailable."; }
    }
    private void Accept()
    {
        if (results.SelectedItem is not Choice choice) { status.Text = "Select a material first."; return; }
        VariantCode = choice.Data.GetProperty("code").GetString(); DialogResult = true;
    }
    private sealed record Choice(JsonElement Data)
    {
        public override string ToString() => $"{Data.GetProperty("material_name").GetString()} — {Data.GetProperty("name").GetString()}\n{Data.GetProperty("supplier").GetString()} · {Data.GetProperty("code").GetString()}";
    }
    private sealed record CategoryChoice(string Code, string Name);
}
