using System.Text.Json;
using Autodesk.Revit.DB;
using Autodesk.Revit.DB.ExtensibleStorage;

namespace Opal.Revit;

public static class MaterialIdentity
{
    private static readonly Guid SchemaId = new("52448970-d402-4a6a-9614-158c971f1d28");
    private static Schema GetSchema()
    {
        var existing = Schema.Lookup(SchemaId);
        if (existing != null) return existing;
        var builder = new SchemaBuilder(SchemaId);
        builder.SetSchemaName("OpalMaterialIdentityV1");
        builder.SetReadAccessLevel(AccessLevel.Public);
        builder.SetWriteAccessLevel(AccessLevel.Public);
        builder.AddSimpleField("IdentityJson", typeof(string));
        return builder.Finish();
    }
    public static void Write(Material material, JsonElement identity)
    {
        var schema = GetSchema();
        var entity = new Entity(schema);
        entity.Set(schema.GetField("IdentityJson"), identity.GetRawText());
        material.SetEntity(entity);
    }
    public static JsonElement? Read(Material material)
    {
        var schema = Schema.Lookup(SchemaId);
        if (schema == null) return null;
        var entity = material.GetEntity(schema);
        if (!entity.IsValid()) return null;
        try { using var json = JsonDocument.Parse(entity.Get<string>(schema.GetField("IdentityJson"))); return json.RootElement.Clone(); }
        catch (JsonException) { return null; }
    }
    public static void Export(Document document, string path)
    {
        var entries = new FilteredElementCollector(document).OfClass(typeof(Material)).Cast<Material>()
            .Select(material => new { material, identity = Read(material) }).Where(item => item.identity.HasValue)
            .Select(item => new { revit_unique_id = item.material.UniqueId, revit_name = item.material.Name, identity = item.identity!.Value }).ToArray();
        System.IO.File.WriteAllText(path, JsonSerializer.Serialize(new { contract = "opal-revit-material-map/1", document = document.Title, materials = entries }, new JsonSerializerOptions { WriteIndented = true }));
    }
}
