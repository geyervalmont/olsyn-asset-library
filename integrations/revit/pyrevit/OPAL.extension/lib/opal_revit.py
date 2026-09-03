# -*- coding: utf-8 -*-
"""
Revit host for the OPAL workflow (IronPython 2.7 under pyRevit).

Configuration lives in %APPDATA%\\OPAL\\config.json:

    {
      "api": "https://asset-library.test",
      "token": "opal_...",              # Settings → API tokens in the web app
      "drive": "studio-share",
      "mount": "M:\\\\",                  # or "\\\\\\\\server\\\\opal"
      "verify_tls": true
    }
"""

import json
import os

from Autodesk.Revit import DB  # noqa: E402
from pyrevit import forms, revit, script  # noqa: E402

from opal_client import Drive, OpalApi, Workflow  # noqa: E402
from opal_client.host import Host, HostMaterial  # noqa: E402

CONFIG_PATH = os.path.join(os.environ.get("APPDATA", os.path.expanduser("~")), "OPAL", "config.json")

# Identity parameters we read to recognise a material and write on apply.
PARAMETER_NAMES = {
    "Description": ("ALL_MODEL_DESCRIPTION",),
    "Model": ("ALL_MODEL_MODEL",),
    "Manufacturer": ("ALL_MODEL_MANUFACTURER",),
    "Keywords": ("ALL_MODEL_KEYWORDS", "MATERIAL_KEYWORDS"),
    "Comments": ("ALL_MODEL_INSTANCE_COMMENTS",),
}

# Appearance schema property names, with static-name lookups as Matt's scripts did.
SLOT_PROPERTIES = {
    "base_color": ("Generic", "GenericDiffuse", "generic_diffuse"),
    "bump": ("Generic", "GenericBumpMap", "generic_bump_map"),
    "glossiness": ("Generic", "GenericGlossiness", "generic_glossiness"),
}
BITMAP_SOURCE = ("UnifiedBitmap", "UnifiedbitmapBitmap", "unifiedbitmap_Bitmap")
BITMAP_SCALE_X = ("UnifiedBitmap", "TextureRealWorldScaleX", "texture_RealWorldScaleX")
BITMAP_SCALE_Y = ("UnifiedBitmap", "TextureRealWorldScaleY", "texture_RealWorldScaleY")
BITMAP_U_REPEAT = ("UnifiedBitmap", "TextureURepeat", "texture_URepeat")
BITMAP_V_REPEAT = ("UnifiedBitmap", "TextureVRepeat", "texture_VRepeat")


def load_config():
    if not os.path.isfile(CONFIG_PATH):
        forms.alert("OPAL is not configured.\n\nCreate %s with api, token, drive and mount." % CONFIG_PATH, exitscript=True)
    with open(CONFIG_PATH, "r") as handle:
        config = json.load(handle)
    for key in ("api", "token", "drive", "mount"):
        if not config.get(key):
            forms.alert("OPAL config is missing '%s' (%s)." % (key, CONFIG_PATH), exitscript=True)
    return config


def build_workflow(doc=None):
    config = load_config()
    api = OpalApi(config["api"], config["token"], verify_tls=config.get("verify_tls", True))
    drives = dict((d["slug"], d) for d in api.drives())
    if config["drive"] not in drives:
        forms.alert("Drive '%s' is not known to the library. Available: %s" % (config["drive"], ", ".join(sorted(drives))), exitscript=True)
    drive = Drive(config["drive"], config["mount"], drives[config["drive"]]["root_path"])
    host = RevitHost(doc or revit.doc)
    return Workflow(api, host, drive), config


def _visual_name(class_name, static_name, fallback):
    try:
        value = getattr(getattr(DB.Visual, class_name), static_name)
        if value:
            return value
    except Exception:
        pass
    return fallback


def _find_property(asset, spec):
    names = [_visual_name(*spec), spec[2]]
    for name in names:
        try:
            prop = asset.FindByName(name)
            if prop is not None:
                return prop
        except Exception:
            pass
    return None


def _to_property_units(mm_value, prop):
    try:
        return DB.UnitUtils.Convert(mm_value, DB.UnitTypeId.Millimeters, prop.GetUnitTypeId())
    except Exception:
        return mm_value / 25.4  # texture scale properties are inches when unitless


class RevitHost(Host):
    platform = "revit"

    def __init__(self, doc):
        self.doc = doc

    # -- reading -----------------------------------------------------------

    def document_name(self):
        return self.doc.Title

    def materials(self):
        collector = DB.FilteredElementCollector(self.doc).OfClass(DB.Material)
        return [self._wrap(material) for material in collector]

    def material(self, host_id):
        element = self._element(host_id)
        return self._wrap(element) if element is not None else None

    def selected_material(self):
        """The material of a picked face, or the single selected material element."""
        uidoc = revit.uidoc
        selection = [self.doc.GetElement(i) for i in uidoc.Selection.GetElementIds()]
        materials = [e for e in selection if isinstance(e, DB.Material)]
        if len(materials) == 1:
            return self._wrap(materials[0])
        try:
            from Autodesk.Revit.UI.Selection import ObjectType
            reference = uidoc.Selection.PickObject(ObjectType.Face, "Click a face to pick its material")
        except Exception:
            return None
        element = self.doc.GetElement(reference)
        face = element.GetGeometryObjectFromReference(reference)
        material_id = face.MaterialElementId if face is not None else DB.ElementId.InvalidElementId
        if material_id == DB.ElementId.InvalidElementId:
            return None
        return self._wrap(self.doc.GetElement(material_id))

    # -- writing -----------------------------------------------------------

    def apply_textures(self, material, textures, scale_mm):
        element = self._element(material.host_id)
        appearance_id = element.AppearanceAssetId
        if appearance_id == DB.ElementId.InvalidElementId:
            raise RuntimeError("%s has no appearance asset; assign a Generic appearance first." % material.name)

        with revit.Transaction("OPAL apply textures to %s" % material.name):
            appearance_id = self._own_appearance(element, appearance_id)
            scope = DB.Visual.AppearanceAssetEditScope(self.doc)
            try:
                editable = scope.Start(appearance_id)
                for slot, path in textures.items():
                    prop = _find_property(editable, SLOT_PROPERTIES[slot])
                    if prop is None:
                        continue
                    bitmap = prop.GetSingleConnectedAsset()
                    if bitmap is None:
                        bitmap = prop.AddConnectedAsset("UnifiedBitmapSchema")
                    source = _find_property(bitmap, BITMAP_SOURCE)
                    source.Value = path
                    if scale_mm:
                        for spec in (BITMAP_SCALE_X, BITMAP_SCALE_Y):
                            scale = _find_property(bitmap, spec)
                            if scale is not None:
                                scale.Value = _to_property_units(float(scale_mm), scale)
                    for spec in (BITMAP_U_REPEAT, BITMAP_V_REPEAT):
                        repeat = _find_property(bitmap, spec)
                        if repeat is not None:
                            repeat.Value = True
                scope.Commit(False)
            except Exception:
                if scope.IsActive:
                    scope.Cancel()
                raise

        material.textures = dict(textures)
        material.scale_mm = scale_mm

    def write_parameters(self, material, parameters):
        element = self._element(material.host_id)
        with revit.Transaction("OPAL identity on %s" % material.name):
            for name, value in parameters.items():
                parameter = self._parameter(element, name)
                if parameter is not None and not parameter.IsReadOnly:
                    parameter.Set(value)
        material.parameters.update(parameters)

    # -- helpers -----------------------------------------------------------

    def _wrap(self, element):
        parameters = {}
        for name in PARAMETER_NAMES:
            parameter = self._parameter(element, name)
            if parameter is not None and parameter.HasValue:
                parameters[name] = parameter.AsString() or ""
        return HostMaterial(element.UniqueId, element.Name, parameters)

    def _element(self, host_id):
        try:
            return self.doc.GetElement(host_id)
        except Exception:
            return None

    def _parameter(self, element, name):
        parameter = element.LookupParameter(name)
        if parameter is not None:
            return parameter
        for built_in in PARAMETER_NAMES.get(name, ()):
            try:
                parameter = element.get_Parameter(getattr(DB.BuiltInParameter, built_in))
                if parameter is not None:
                    return parameter
            except Exception:
                continue
        return None

    def _own_appearance(self, element, appearance_id):
        """Duplicate a shared appearance asset so edits don't leak into other materials."""
        others = [
            m for m in DB.FilteredElementCollector(self.doc).OfClass(DB.Material)
            if m.AppearanceAssetId == appearance_id and m.Id != element.Id
        ]
        if not others:
            return appearance_id
        asset = self.doc.GetElement(appearance_id)
        copy = asset.Duplicate("%s (OPAL)" % element.Name)
        element.AppearanceAssetId = copy.Id
        return copy.Id


def print_plan(plan):
    output = script.get_output()
    output.print_md("```\n%s\n```" % plan.describe())
