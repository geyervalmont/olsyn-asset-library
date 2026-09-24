using System.Security.Cryptography;
using System.Text.Json;
using Autodesk.Revit.DB;
using Autodesk.Revit.DB.Visual;
using Autodesk.Revit.UI;
using Autodesk.Revit.UI.Selection;
using Opal.Client;

namespace Opal.Revit;

public static class RevitWorkflow
{
    private static readonly string[] TargetPreference = ["revit", "pbr"];
    private static readonly IReadOnlyDictionary<string, string[]> SlotRoles = new Dictionary<string, string[]>
    {
        ["base_color"] = ["base_color"],
        ["bump"] = ["bump", "normal", "height"],
        ["glossiness"] = ["glossiness", "roughness"],
    };

    public static object Execute(UIApplication application, AppSettings settings, ClientCommandEnvelope command) => command.Type switch
    {
        "apply" => ApplyCommand(application, settings, command.Payload),
        "sync" => Sync(application, settings),
        "resolve" => ResolveCommand(application, settings, command.Payload),
        _ => throw new InvalidOperationException($"OPAL sent an unsupported command: {command.Type}"),
    };

    public static ApplyResult Apply(
        UIApplication application,
        AppSettings settings,
        RevitMaterial material,
        string variantCode,
        string? requestedQuality = null)
    {
        settings.MountPath = DriveMountDiscovery.Find(settings) ?? settings.MountPath;
        var host = new RevitMaterialHost(application);
        using var api = new OpalApiClient(settings);
        var variant = api.VariantAsync(variantCode).GetAwaiter().GetResult();
        var uuid = variant.GetProperty("uuid").GetString()!;
        var resolved = api.ResolveMaterialAsync(uuid).GetAwaiter().GetResult();
        var byRole = MaterialAssets.PrepareAsync(api, resolved, settings.MountPath,
            System.IO.Path.Combine(ClientPaths.SharedRoot, "materials-cache")).GetAwaiter().GetResult();
        var textures = new Dictionary<string, string>();
        AddFirst(textures, "base_color", byRole, "base_color");
        AddFirst(textures, "bump", byRole, "bump", "height");
        AddFirst(textures, "glossiness", byRole, "glossiness");
        if (textures.Count == 0) throw new InvalidOperationException("This material has no compatible Revit textures.");
        var target = resolved.GetProperty("target").GetString()!;
        var quality = resolved.GetProperty("quality").GetString()!;
        var identity = JsonSerializer.SerializeToElement(new {
            material_uuid = resolved.GetProperty("material_uuid").GetString(),
            variant_uuid = uuid, material_version = resolved.GetProperty("material_version").GetInt32(),
            source_package_sha256 = resolved.GetProperty("source_package_sha256").GetString(),
            code = variantCode,
        });

        var scale = variant.TryGetProperty("tile_width_mm", out var width) && width.ValueKind == JsonValueKind.Number
            ? width.GetDouble()
            : (double?)null;
        var variantName = variant.GetProperty("name").GetString() ?? variantCode;
        var materialCode = variant.TryGetProperty("material_code", out var parent) ? parent.GetString() ?? string.Empty : string.Empty;
        var parameters = new Dictionary<string, string>
        {
            ["Description"] = $"{variantName} [{variantCode}]",
            ["Model"] = variantCode,
            ["Manufacturer"] = materialCode,
            ["Keywords"] = $"opal, {variantCode}, opal:variant_uuid={uuid}",
        };

        var scaleHeight = variant.TryGetProperty("tile_height_mm", out var height) && height.ValueKind == JsonValueKind.Number ? height.GetDouble() : scale;
        host.Apply(material, textures, scale, parameters, identity, scaleHeight);
        api.RegisterIdentityAsync(variantCode, new
        {
            platform = "revit",
            external_id = material.UniqueId,
            external_name = material.Name,
            payload = new
            {
                document = host.Document.Title,
                drive = settings.DriveSlug,
                target,
                quality,
                identity,
                textures,
            },
        }).GetAwaiter().GetResult();

        return new ApplyResult(variantCode, material.Name, target, quality, textures.Count);
    }

    public static SyncReport Sync(UIApplication application, AppSettings settings)
    {
        var host = new RevitMaterialHost(application);
        var materials = host.Materials();
        var references = materials.SelectMany(item => item.References).Distinct(StringComparer.Ordinal).Take(500).ToArray();
        using var api = new OpalApiClient(settings);
        var resolved = references.Length == 0
            ? JsonSerializer.SerializeToElement(new Dictionary<string, object?>())
            : api.ResolveManyAsync(references).GetAwaiter().GetResult();

        var matched = new List<MatchedMaterial>();
        var unmatched = new List<string>();
        foreach (var material in materials)
        {
            var match = material.References.FirstOrDefault(reference =>
                resolved.TryGetProperty(reference, out var value) && value.ValueKind == JsonValueKind.Object);
            if (match is null)
            {
                unmatched.Add(material.Name);
                continue;
            }
            var variant = resolved.GetProperty(match);
            matched.Add(new MatchedMaterial(
                material.UniqueId,
                material.Name,
                variant.GetProperty("code").GetString() ?? string.Empty,
                match));
        }
        return new SyncReport(matched, unmatched);
    }

    private static ApplyResult ApplyCommand(UIApplication application, AppSettings settings, JsonElement payload)
    {
        var host = new RevitMaterialHost(application);
        var material = payload.TryGetProperty("material_id", out var id) && id.ValueKind == JsonValueKind.String
            ? host.Material(id.GetString()!)
            : host.SelectedMaterial();
        if (material is null)
        {
            throw new InvalidOperationException("No Revit material is selected. Select a material element or a face, then try again.");
        }

        if (payload.TryGetProperty("studio", out var studio) && studio.ValueKind == JsonValueKind.Object)
        {
            return ApplyStudioDraft(host, settings, material, studio);
        }

        var code = payload.GetProperty("variant").GetString()
            ?? throw new InvalidOperationException("The OPAL command has no variant code.");
        var quality = payload.TryGetProperty("quality", out var qualityElement) && qualityElement.ValueKind == JsonValueKind.String
            ? qualityElement.GetString()
            : null;
        return Apply(application, settings, material, code, quality);
    }

    private static ApplyResult ApplyStudioDraft(RevitMaterialHost host, AppSettings settings, RevitMaterial material, JsonElement studio)
    {
        var preview = studio.GetProperty("id").GetString() ?? string.Empty;
        if (!Guid.TryParse(preview, out _))
        {
            throw new InvalidOperationException("The OPAL Studio draft has an invalid identifier.");
        }

        var requestedLabel = studio.TryGetProperty("label", out var labelElement) ? labelElement.GetString() : null;
        var label = string.IsNullOrWhiteSpace(requestedLabel) ? "Studio material" : requestedLabel!;
        var scale = studio.TryGetProperty("tile_width_mm", out var width) && width.ValueKind == JsonValueKind.Number
            ? width.GetDouble()
            : (double?)null;
        var root = System.IO.Path.Combine(ClientPaths.SharedRoot, "studio-previews", preview);
        System.IO.Directory.CreateDirectory(root);

        using var api = new OpalApiClient(settings);
        var entries = studio.GetProperty("maps").EnumerateArray().Select(item => item.Clone()).ToArray();
        var localByRole = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        foreach (var entry in entries)
        {
            var role = entry.GetProperty("role").GetString() ?? string.Empty;
            if (!new[] { "base_color", "normal", "bump", "height", "roughness", "glossiness" }.Contains(role, StringComparer.Ordinal))
            {
                continue;
            }
            var extension = entry.GetProperty("extension").GetString() ?? string.Empty;
            if (extension.Length == 0 || extension.Any(character => !char.IsLetterOrDigit(character)))
            {
                throw new InvalidOperationException("The OPAL Studio draft contains an unsafe file extension.");
            }
            var url = entry.GetProperty("url").GetString() ?? string.Empty;
            var expected = entry.GetProperty("sha256").GetString() ?? string.Empty;
            var destination = System.IO.Path.Combine(root, role + "." + extension);
            var temporary = destination + ".part";
            using (var source = api.DownloadAsync(url).GetAwaiter().GetResult())
            using (var output = new System.IO.FileStream(temporary, System.IO.FileMode.Create, System.IO.FileAccess.Write, System.IO.FileShare.None))
            {
                source.CopyTo(output);
            }
            VerifySha256(temporary, expected, "A downloaded OPAL Studio texture failed verification.");
            MoveReplace(temporary, destination);
            localByRole[role] = destination;
        }

        var textures = new Dictionary<string, string>();
        AddFirst(textures, "base_color", localByRole, "base_color");
        AddFirst(textures, "bump", localByRole, "bump", "normal", "height");
        AddFirst(textures, "glossiness", localByRole, "glossiness", "roughness");
        if (textures.Count == 0)
        {
            throw new InvalidOperationException("The OPAL Studio draft has no texture maps Revit can use.");
        }

        host.Apply(material, textures, scale, new Dictionary<string, string>
        {
            ["Description"] = $"{label} [OPAL Studio draft]",
            ["Model"] = "OPAL-STUDIO-DRAFT",
            ["Manufacturer"] = "OPAL Studio",
            ["Keywords"] = "opal, studio, draft",
        });

        return new ApplyResult(label, material.Name, "studio", "draft", textures.Count);
    }

    private static void AddFirst(
        IDictionary<string, string> textures,
        string slot,
        IReadOnlyDictionary<string, string> roles,
        params string[] candidates)
    {
        foreach (var candidate in candidates)
        {
            if (roles.TryGetValue(candidate, out var path))
            {
                textures[slot] = path;
                return;
            }
        }
    }

    private static void VerifySha256(string path, string expected, string message)
    {
        using var stream = System.IO.File.OpenRead(path);
        using var algorithm = SHA256.Create();
        var actual = BitConverter.ToString(algorithm.ComputeHash(stream)).Replace("-", string.Empty).ToLowerInvariant();
        if (!actual.Equals(expected, StringComparison.OrdinalIgnoreCase))
        {
            throw new System.IO.InvalidDataException(message);
        }
    }

    private static void MoveReplace(string source, string destination)
    {
        if (System.IO.File.Exists(destination))
        {
            System.IO.File.Replace(source, destination, null);
            return;
        }
        System.IO.File.Move(source, destination);
    }

    private static object ResolveCommand(UIApplication application, AppSettings settings, JsonElement payload)
    {
        var host = new RevitMaterialHost(application);
        var material = payload.TryGetProperty("material_id", out var id) && id.ValueKind == JsonValueKind.String
            ? host.Material(id.GetString()!)
            : host.SelectedMaterial();
        if (material is null)
        {
            throw new InvalidOperationException("No Revit material is selected.");
        }
        using var api = new OpalApiClient(settings);
        var resolved = api.ResolveManyAsync(material.References).GetAwaiter().GetResult();
        foreach (var reference in material.References)
        {
            if (resolved.TryGetProperty(reference, out var variant) && variant.ValueKind == JsonValueKind.Object)
            {
                return new
                {
                    material = new { id = material.UniqueId, name = material.Name },
                    variant = variant.Clone(),
                    reference,
                };
            }
        }
        return new { material = new { id = material.UniqueId, name = material.Name }, variant = (object?)null, reference = (string?)null };
    }

    private static Representation? ChooseRepresentation(JsonElement files, string? requestedQuality)
    {
        var groups = files.EnumerateArray()
            .GroupBy(file => new
            {
                Target = file.GetProperty("target").GetString() ?? string.Empty,
                Quality = file.GetProperty("quality").GetString() ?? string.Empty,
            });
        foreach (var target in TargetPreference)
        {
            var candidates = groups.Where(group => group.Key.Target == target).ToList();
            if (candidates.Count == 0)
            {
                continue;
            }
            var chosen = !string.IsNullOrWhiteSpace(requestedQuality)
                ? candidates.FirstOrDefault(group => group.Key.Quality.Equals(requestedQuality, StringComparison.OrdinalIgnoreCase))
                : null;
            chosen ??= candidates.OrderByDescending(group => Pixels(group.Key.Quality)).First();
            return new Representation(chosen.Key.Target, chosen.Key.Quality, chosen.Select(item => item.Clone()).ToArray());
        }
        return null;
    }

    private static Dictionary<string, string> BuildTextures(string mount, IReadOnlyList<JsonElement> entries)
    {
        foreach (var entry in entries)
        {
            var local = LocalPath(mount, entry.GetProperty("path").GetString() ?? string.Empty);
            if (!System.IO.File.Exists(local))
            {
                throw new System.IO.FileNotFoundException("A published OPAL texture is missing from the mounted drive.", local);
            }
            var expected = entry.GetProperty("sha256").GetString() ?? string.Empty;
            using var stream = System.IO.File.OpenRead(local);
            using var algorithm = SHA256.Create();
            var actual = BitConverter.ToString(algorithm.ComputeHash(stream)).Replace("-", string.Empty).ToLowerInvariant();
            if (!actual.Equals(expected, StringComparison.OrdinalIgnoreCase))
            {
                throw new System.IO.InvalidDataException($"A mounted OPAL texture does not match the library: {local}");
            }
        }

        var byRole = entries.ToDictionary(entry => entry.GetProperty("role").GetString() ?? string.Empty, StringComparer.OrdinalIgnoreCase);
        var textures = new Dictionary<string, string>();
        foreach (var slotRoles in SlotRoles)
        {
            foreach (var role in slotRoles.Value)
            {
                if (!byRole.TryGetValue(role, out var entry))
                {
                    continue;
                }
                textures[slotRoles.Key] = LocalPath(mount, entry.GetProperty("path").GetString() ?? string.Empty);
                break;
            }
        }
        return textures;
    }

    private static string LocalPath(string mount, string drivePath)
    {
        var relative = drivePath.TrimStart('/', '\\').Replace('/', System.IO.Path.DirectorySeparatorChar);
        return System.IO.Path.GetFullPath(System.IO.Path.Combine(mount, relative));
    }

    private static int Pixels(string quality)
    {
        var slug = quality.ToLowerInvariant();
        if (slug.EndsWith("k", StringComparison.Ordinal) && int.TryParse(slug.Substring(0, slug.Length - 1), out var thousands))
        {
            return thousands * 1024;
        }
        if (slug.EndsWith("px", StringComparison.Ordinal) && int.TryParse(slug.Substring(0, slug.Length - 2), out var pixels))
        {
            return pixels;
        }
        return slug == "preview" ? 512 : 0;
    }

    private sealed record Representation(string Target, string Quality, IReadOnlyList<JsonElement> Entries);
}

public sealed class RevitMaterialHost
{
    private static readonly IReadOnlyDictionary<string, BuiltInParameter[]> ParameterIds = new Dictionary<string, BuiltInParameter[]>
    {
        ["Description"] = [BuiltInParameter.ALL_MODEL_DESCRIPTION],
        ["Model"] = [BuiltInParameter.ALL_MODEL_MODEL],
        ["Manufacturer"] = [BuiltInParameter.ALL_MODEL_MANUFACTURER],
        ["Keywords"] = [],
        ["Comments"] = [BuiltInParameter.ALL_MODEL_INSTANCE_COMMENTS],
    };
    private static readonly IReadOnlyDictionary<string, string[]> SlotNames = new Dictionary<string, string[]>
    {
        ["base_color"] = [Generic.GenericDiffuse, "generic_diffuse"],
        ["bump"] = [Generic.GenericBumpMap, "generic_bump_map"],
        ["glossiness"] = [Generic.GenericGlossiness, "generic_glossiness"],
    };
    private static readonly string[] BitmapSource = [UnifiedBitmap.UnifiedbitmapBitmap, "unifiedbitmap_Bitmap"];
    private static readonly string[] BitmapScaleX = [UnifiedBitmap.TextureRealWorldScaleX, "texture_RealWorldScaleX"];
    private static readonly string[] BitmapScaleY = [UnifiedBitmap.TextureRealWorldScaleY, "texture_RealWorldScaleY"];
    private static readonly string[] BitmapRepeatU = [UnifiedBitmap.TextureURepeat, "texture_URepeat"];
    private static readonly string[] BitmapRepeatV = [UnifiedBitmap.TextureVRepeat, "texture_VRepeat"];

    private readonly UIApplication application;

    public RevitMaterialHost(UIApplication application)
    {
        this.application = application;
        Document = application.ActiveUIDocument?.Document
            ?? throw new InvalidOperationException("Open a Revit document before using OPAL materials.");
    }

    public Document Document { get; }

    public IReadOnlyList<RevitMaterial> Materials() => new FilteredElementCollector(Document)
        .OfClass(typeof(Material))
        .Cast<Material>()
        .Select(Wrap)
        .ToArray();

    public RevitMaterial? Material(string uniqueId)
    {
        try
        {
            return Document.GetElement(uniqueId) is Material material ? Wrap(material) : null;
        }
        catch (Autodesk.Revit.Exceptions.ArgumentException)
        {
            return null;
        }
    }

    public RevitMaterial? SelectedMaterial()
    {
        var uidoc = application.ActiveUIDocument;
        if (uidoc is null)
        {
            return null;
        }
        var selected = uidoc.Selection.GetElementIds()
            .Select(Document.GetElement)
            .OfType<Material>()
            .ToArray();
        if (selected.Length == 1)
        {
            return Wrap(selected[0]);
        }

        var reference = uidoc.Selection.PickObject(ObjectType.Face, "Click a face to choose its Revit material");
        var element = Document.GetElement(reference);
        var face = element?.GetGeometryObjectFromReference(reference) as Face;
        return face is not null && face.MaterialElementId != ElementId.InvalidElementId && Document.GetElement(face.MaterialElementId) is Material material
            ? Wrap(material)
            : null;
    }

    public void Apply(RevitMaterial material, IReadOnlyDictionary<string, string> textures, double? scaleMm, IReadOnlyDictionary<string, string> parameters, JsonElement? identity = null, double? scaleHeightMm = null)
    {
        var element = Document.GetElement(material.UniqueId) as Material
            ?? throw new InvalidOperationException($"Revit material {material.Name} no longer exists.");
        using (var transaction = new Transaction(Document, $"OPAL apply textures to {material.Name}"))
        {
            transaction.Start();
            var appearanceId = element.AppearanceAssetId;
            if (appearanceId == ElementId.InvalidElementId || SchemaOf(Document.GetElement(appearanceId) as AppearanceAssetElement) != "GenericSchema")
            {
                appearanceId = CreateGenericAppearance(element);
            }
            else
            {
                appearanceId = OwnAppearance(element, appearanceId);
            }

            var applied = new List<string>();
            using var scope = new AppearanceAssetEditScope(Document);
            try
            {
                var editable = scope.Start(appearanceId);
                foreach (var texture in textures)
                {
                    var slot = texture.Key;
                    var property = Find(editable, SlotNames[slot]);
                    if (property is null)
                    {
                        continue;
                    }
                    var bitmap = property.GetSingleConnectedAsset();
                    if (bitmap is null)
                    {
                        property.AddConnectedAsset("UnifiedBitmapSchema");
                        bitmap = property.GetSingleConnectedAsset();
                    }
                    if (bitmap is null)
                    {
                        continue;
                    }
                    if (Find(bitmap, BitmapSource) is not AssetPropertyString source)
                    {
                        continue;
                    }
                    source.Value = texture.Value;
                    if (scaleMm is not null)
                    {
                        SetScale(bitmap, BitmapScaleX, scaleMm.Value);
                        SetScale(bitmap, BitmapScaleY, scaleHeightMm ?? scaleMm.Value);
                    }
                    SetBoolean(bitmap, BitmapRepeatU, true);
                    SetBoolean(bitmap, BitmapRepeatV, true);
                    applied.Add(slot);
                }
                if (applied.Count == 0)
                {
                    scope.Cancel();
                    throw new InvalidOperationException($"No compatible texture slot was found on {material.Name}.");
                }
                scope.Commit(updateOpenViews: false);
            }
            catch
            {
                if (scope.IsActive)
                {
                    scope.Cancel();
                }
                throw;
            }

            element.UseRenderAppearanceForShading = true;
            transaction.Commit();
        }

        using (var transaction = new Transaction(Document, $"OPAL identity on {material.Name}"))
        {
            transaction.Start();
            if (identity.HasValue) MaterialIdentity.Write(element, identity.Value);
            foreach (var parameterValue in parameters)
            {
                var parameter = Parameter(element, parameterValue.Key);
                if (parameter is not null && !parameter.IsReadOnly)
                {
                    parameter.Set(parameterValue.Value);
                }
            }
            transaction.Commit();
        }
    }

    private RevitMaterial Wrap(Material material)
    {
        var references = new List<string> { material.UniqueId };
        var identity = MaterialIdentity.Read(material);
        if (identity.HasValue) references.Insert(0, identity.Value.GetProperty("variant_uuid").GetString()!);
        foreach (var name in ParameterIds.Keys)
        {
            var value = Parameter(material, name)?.AsString();
            if (!string.IsNullOrWhiteSpace(value))
            {
                references.Add(value!);
            }
        }
        references.Add(material.Name);
        return new RevitMaterial(material.UniqueId, material.Name, references.Distinct(StringComparer.Ordinal).ToArray());
    }

    private static Parameter? Parameter(Material element, string name)
    {
        var parameter = element.LookupParameter(name);
        if (parameter is not null)
        {
            return parameter;
        }
        if (!ParameterIds.TryGetValue(name, out var parameterIds))
        {
            return null;
        }
        foreach (var id in parameterIds)
        {
            parameter = element.get_Parameter(id);
            if (parameter is not null)
            {
                return parameter;
            }
        }
        return null;
    }

    private ElementId CreateGenericAppearance(Material material)
    {
        var name = UniqueAppearanceName(material.Name);
        AppearanceAssetElement? created = null;
        foreach (var candidate in new FilteredElementCollector(Document).OfClass(typeof(AppearanceAssetElement)).Cast<AppearanceAssetElement>())
        {
            if (SchemaOf(candidate) == "GenericSchema")
            {
                created = candidate.Duplicate(name);
                break;
            }
        }
        if (created is null)
        {
            foreach (Asset asset in Document.Application.GetAssets(AssetType.Appearance))
            {
                if (SchemaOf(asset) == "GenericSchema")
                {
                    created = AppearanceAssetElement.Create(Document, name, asset);
                    break;
                }
            }
        }
        if (created is null)
        {
            throw new InvalidOperationException("Revit has no Generic appearance asset from which OPAL can create this material.");
        }
        material.AppearanceAssetId = created.Id;
        return created.Id;
    }

    private ElementId OwnAppearance(Material material, ElementId appearanceId)
    {
        var shared = new FilteredElementCollector(Document).OfClass(typeof(Material)).Cast<Material>()
            .Any(candidate => candidate.Id != material.Id && candidate.AppearanceAssetId == appearanceId);
        if (!shared)
        {
            return appearanceId;
        }
        var current = (AppearanceAssetElement)Document.GetElement(appearanceId);
        var copy = current.Duplicate(UniqueAppearanceName(material.Name));
        material.AppearanceAssetId = copy.Id;
        return copy.Id;
    }

    private string UniqueAppearanceName(string materialName)
    {
        var baseName = $"{materialName} (OPAL)";
        var name = baseName;
        var suffix = 1;
        while (AppearanceAssetElement.GetAppearanceAssetElementByName(Document, name) is not null)
        {
            suffix++;
            name = $"{materialName} (OPAL {suffix})";
        }
        return name;
    }

    private static string SchemaOf(AppearanceAssetElement? element) => element is null ? string.Empty : SchemaOf(element.GetRenderingAsset());

    private static string SchemaOf(Asset asset) => Find(asset, ["BaseSchema"]) is AssetPropertyString schema ? schema.Value : string.Empty;

    private static AssetProperty? Find(Asset asset, IEnumerable<string> names)
    {
        foreach (var name in names.Distinct(StringComparer.Ordinal))
        {
            var property = asset.FindByName(name);
            if (property is not null)
            {
                return property;
            }
        }
        return null;
    }

    private static void SetScale(Asset asset, IEnumerable<string> names, double millimeters)
    {
        if (Find(asset, names) is AssetPropertyDistance distance)
        {
            distance.Value = UnitUtils.Convert(millimeters, UnitTypeId.Millimeters, distance.GetUnitTypeId());
        }
    }

    private static void SetBoolean(Asset asset, IEnumerable<string> names, bool value)
    {
        if (Find(asset, names) is AssetPropertyBoolean boolean)
        {
            boolean.Value = value;
        }
    }
}

public sealed record RevitMaterial(string UniqueId, string Name, IReadOnlyList<string> References);
public sealed record ApplyResult(string Variant, string MaterialName, string Target, string Quality, int TextureCount);
public sealed record MatchedMaterial(string MaterialId, string Material, string Variant, string Reference);
public sealed record SyncReport(IReadOnlyList<MatchedMaterial> Matched, IReadOnlyList<string> Unmatched);
