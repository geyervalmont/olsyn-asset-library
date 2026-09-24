from pathlib import Path
import sys
import json
import tempfile
import unittest
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'exts' / 'olsyn.opal'))
from pxr import Sdf, Usd, UsdGeom, UsdShade
from olsyn.opal import materials

class MaterialsTest(unittest.TestCase):
    def setUp(self):
        self.stage = Usd.Stage.CreateInMemory()
        self.resolved = {'variant_uuid': '0195bd23-9123-7000-8000-000000000001', 'material_uuid': '0195bd23-9123-7000-8000-000000000002', 'material_version': 1, 'source_package_sha256': 'a' * 64, 'name': 'Oak', 'material_name': 'Floor'}

    def test_upgrade_keeps_face_subsets_uvs_and_pinned_identity(self):
        mesh = UsdGeom.Mesh.Define(self.stage, '/Model/Floor')
        st = UsdGeom.PrimvarsAPI(mesh).CreatePrimvar('st', Sdf.ValueTypeNames.TexCoord2fArray, UsdGeom.Tokens.faceVarying)
        st.Set([(0, 0), (1, 0), (1, 1)])
        old = UsdShade.Material.Define(self.stage, '/Looks/RevitOak')
        old.GetPrim().SetCustomDataByKey('opal', self.resolved)
        api = UsdShade.MaterialBindingAPI.Apply(mesh.GetPrim())
        subset = api.CreateMaterialBindSubset('Finish', [0])
        UsdShade.MaterialBindingAPI.Apply(subset.GetPrim()).Bind(old)
        candidates = materials.find_upgrades(self.stage)
        self.assertEqual(len(candidates), 1)
        new = materials.author(self.stage, self.resolved, {'base_color': '/cache/base.png', 'normal': '/cache/n.png', 'roughness': '/cache/r.png'})
        self.assertEqual(materials.replace_bindings(self.stage, str(old.GetPath()), new), 1)
        self.assertEqual(UsdShade.MaterialBindingAPI(subset.GetPrim()).ComputeBoundMaterial()[0].GetPath(), new.GetPath())
        self.assertEqual(list(subset.GetIndicesAttr().Get()), [0])
        self.assertEqual(len(st.Get()), 3)
        self.assertEqual(materials.identity(new.GetPrim())['variant_uuid'], self.resolved['variant_uuid'])
        self.assertTrue(new.ComputeSurfaceSource()[0])
        layer = self.stage.GetRootLayer().ExportToString()
        self.assertIn('sourceColorSpace = "raw"', layer)
        self.assertIn('scale = (2, 2, 2, 1)', layer)

    def test_canonical_metadata_selects_highest_tier_and_preserves_constants_and_normal_convention(self):
        with tempfile.TemporaryDirectory() as root:
            path = Path(root) / 'material.usda'
            layer = Sdf.Layer.CreateNew(str(path))
            source = {'hash': 'b' * 64, 'extension': 'png'}
            texture = {'tiers': {'preview': {'hash': 'a' * 64, 'extension': 'png'}, '4k': source}, 'channel': 'rgb', 'color_space': 'srgb'}
            manifest = {'schema_version': 1, 'materials': [{'surface': {'base_color': {'kind': 'texture', 'texture': texture}, 'base_metalness': {'kind': 'constant', 'value': 0.7}}, 'geometry': {'normal': {'kind': 'texture', 'texture': dict(texture, color_space='raw', normal_convention='direct_x')}}}]}
            layer.customLayerData = {'olsyn_manifestJson': json.dumps(manifest)}
            layer.Save()
            result = materials.author(self.stage, self.resolved, {'package': str(path)})
            shader = UsdShade.Shader(self.stage.GetPrimAtPath(str(result.GetPath()) + '/Surface'))
            self.assertAlmostEqual(shader.GetInput('metallic').Get(), 0.7)
            base = UsdShade.Shader(self.stage.GetPrimAtPath(str(result.GetPath()) + '/base_color'))
            self.assertIn('b' * 64, base.GetInput('file').Get().path)
            normal = UsdShade.Shader(self.stage.GetPrimAtPath(str(result.GetPath()) + '/normal'))
            self.assertEqual(normal.GetInput('scale').Get()[1], -2)

    def test_sidecar_only_matches_unique_names(self):
        old = UsdShade.Material.Define(self.stage, '/Looks/Oak_Wood')
        mapping = {'materials': [{'revit_name': 'Oak Wood', 'identity': self.resolved}]}
        self.assertEqual(len(materials.find_upgrades(self.stage, mapping)), 1)
        mapping['materials'].append({'revit_name': 'Oak_Wood', 'identity': {**self.resolved, 'variant_uuid': 'other'}})
        self.assertEqual(materials.find_upgrades(self.stage, mapping), [])

if __name__ == '__main__':
    unittest.main()
