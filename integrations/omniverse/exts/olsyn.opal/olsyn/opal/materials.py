"""USD work runs on Kit's main thread; network work runs outside this module."""
import re
import json
from pxr import Gf, Sdf, Tf, UsdShade

IDENTITY_KEYS = ('material_uuid', 'variant_uuid', 'material_version', 'source_package_sha256')

def identity(prim):
    value = prim.GetCustomDataByKey('opal')
    if isinstance(value, dict) and all(k in value for k in IDENTITY_KEYS):
        return {k: value[k] for k in IDENTITY_KEYS}
    return None

def canonical_inputs(path):
    layer = Sdf.Layer.FindOrOpen(str(path))
    if not layer:
        raise ValueError('The canonical USDZ could not be opened.')
    manifest = json.loads(layer.customLayerData.get('olsyn_manifestJson', '{}'))
    if manifest.get('schema_version') != 1 or len(manifest.get('materials', [])) != 1:
        raise ValueError('Unsupported canonical material package.')
    material = manifest['materials'][0]
    source = dict(material.get('surface', {}), **material.get('geometry', {}))
    parameters = {'base_color': 'base_color', 'specular_roughness': 'roughness', 'base_metalness': 'metallic', 'normal': 'normal', 'opacity': 'opacity'}
    files, metadata, constants = {}, {}, {}
    ranks = {'preview': 512, '1k': 1024, '2k': 2048, '4k': 4096, '8k': 8192}
    for parameter, role in parameters.items():
        value = source.get(parameter) or {}
        if value.get('kind') == 'constant':
            constants[role] = value['value']
        texture = value.get('texture')
        if not texture or not texture.get('tiers'):
            continue
        tier = max(texture['tiers'], key=lambda tier: ranks.get(tier, 0))
        asset = texture['tiers'][tier]
        if not re.fullmatch('[a-f0-9]{64}', asset['hash']) or not re.fullmatch('[a-zA-Z0-9]+', asset['extension']):
            raise ValueError('Invalid canonical texture identity.')
        files[role] = str(path).replace('\\', '/') + '[textures/' + asset['hash'] + '.' + asset['extension'] + ']'
        metadata[role] = dict(texture, factor=value.get('factor'))
    return files, metadata, constants

def author(stage, resolved, textures):
    metadata, constants = {}, {}
    if 'package' in textures:
        textures, metadata, constants = canonical_inputs(textures['package'])
    uuid = resolved['variant_uuid']
    path = '/Looks/OPAL/v_' + uuid.replace('-', '_') + '_v' + str(int(resolved['material_version']))
    material = UsdShade.Material.Define(stage, path)
    shader = UsdShade.Shader.Define(stage, path + '/Surface')
    shader.CreateIdAttr('UsdPreviewSurface')
    material.CreateSurfaceOutput().ConnectToSource(shader.ConnectableAPI(), 'surface')
    reader = UsdShade.Shader.Define(stage, path + '/UV')
    reader.CreateIdAttr('UsdPrimvarReader_float2')
    reader.CreateInput('varname', Sdf.ValueTypeNames.Token).Set('st')
    reader.CreateOutput('result', Sdf.ValueTypeNames.Float2)
    slots = {
        'base_color': ('diffuseColor', Sdf.ValueTypeNames.Color3f, 'rgb'),
        'normal': ('normal', Sdf.ValueTypeNames.Normal3f, 'rgb'),
        'roughness': ('roughness', Sdf.ValueTypeNames.Float, 'r'),
        'metallic': ('metallic', Sdf.ValueTypeNames.Float, 'r'),
        'opacity': ('opacity', Sdf.ValueTypeNames.Float, 'r'),
    }
    shader.CreateInput('roughness', Sdf.ValueTypeNames.Float).Set(0.5)
    shader.CreateInput('metallic', Sdf.ValueTypeNames.Float).Set(0.0)
    for role, value in constants.items():
        if role in slots:
            name, kind, _ = slots[role]
            shader.CreateInput(name, kind).Set(Gf.Vec3f(*value[:3]) if isinstance(value, list) else float(value))
    for role, file in textures.items():
        if role not in slots:
            continue
        name, kind, channel = slots[role]
        meta = metadata.get(role, {})
        channel = meta.get('channel', channel)
        if channel not in ('r', 'g', 'b', 'a', 'rgb'): channel = slots[role][2]
        texture = UsdShade.Shader.Define(stage, path + '/' + role)
        texture.CreateIdAttr('UsdUVTexture')
        texture.CreateInput('file', Sdf.ValueTypeNames.Asset).Set(Sdf.AssetPath(file.replace('\\', '/')))
        texture.CreateInput('st', Sdf.ValueTypeNames.Float2).ConnectToSource(reader.ConnectableAPI(), 'result')
        texture.CreateInput('sourceColorSpace', Sdf.ValueTypeNames.Token).Set('sRGB' if meta.get('color_space', 'srgb' if role == 'base_color' else 'linear') == 'srgb' else 'raw')
        texture.CreateInput('wrapS', Sdf.ValueTypeNames.Token).Set('repeat')
        texture.CreateInput('wrapT', Sdf.ValueTypeNames.Token).Set('repeat')
        if role == 'normal':
            direct_x = meta.get('normal_convention') == 'direct_x'
            texture.CreateInput('scale', Sdf.ValueTypeNames.Float4).Set(Gf.Vec4f(2, -2 if direct_x else 2, 2, 1))
            texture.CreateInput('bias', Sdf.ValueTypeNames.Float4).Set(Gf.Vec4f(-1, 1 if direct_x else -1, -1, 0))
        elif meta.get('factor') is not None:
            factor = meta['factor']
            values = (factor + [1])[:4] if isinstance(factor, list) else [factor, factor, factor, 1]
            texture.CreateInput('scale', Sdf.ValueTypeNames.Float4).Set(Gf.Vec4f(*values))
        texture.CreateOutput(channel, Sdf.ValueTypeNames.Float3 if channel == 'rgb' else Sdf.ValueTypeNames.Float)
        shader.CreateInput(name, kind).ConnectToSource(texture.ConnectableAPI(), channel)
    material.GetPrim().SetCustomDataByKey('opal', {k: resolved[k] for k in IDENTITY_KEYS})
    material.GetPrim().SetDisplayName(resolved.get('material_name', '') + ' — ' + resolved.get('name', uuid))
    return material

def bind(stage, material, prim_paths):
    for path in prim_paths:
        prim = stage.GetPrimAtPath(path)
        if prim and prim != material.GetPrim():
            UsdShade.MaterialBindingAPI.Apply(prim).Bind(material)

def find_upgrades(stage, mapping=None):
    """Use UUID metadata first; a sidecar can resolve unambiguous exported names."""
    names = {}
    for item in (mapping or {}).get('materials', []):
        for name in {item['revit_name'], Tf.MakeValidIdentifier(item['revit_name'])}:
            names.setdefault(name, []).append(item['identity'])
    found = []
    for prim in stage.Traverse():
        if not prim.IsA(UsdShade.Material):
            continue
        value = identity(prim)
        if value is None:
            matches = names.get(prim.GetDisplayName(), []) or names.get(prim.GetName(), [])
            unique = {(m['variant_uuid'], m['material_version']): m for m in matches}
            if len(unique) == 1:
                value = next(iter(unique.values()))
        if value:
            found.append((str(prim.GetPath()), value))
    return found

def replace_bindings(stage, old_path, material):
    # Replacing relationship targets preserves subset membership and strength.
    old = Sdf.Path(old_path)
    new = material.GetPath()
    if old == new:
        return 0
    changed = 0
    for prim in stage.Traverse():
        for relationship in prim.GetRelationships():
            if relationship.GetName().startswith('material:binding'):
                targets = relationship.GetTargets()
                if old in targets:
                    relationship.SetTargets([new if target == old else target for target in targets])
                    changed += 1
    return changed
