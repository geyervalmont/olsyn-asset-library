# -*- coding: utf-8 -*-
from __future__ import print_function

import hashlib
import json
import os
import random
import sys
import traceback

import clr
import System
clr.AddReference("PresentationFramework")
clr.AddReference("PresentationCore")

from Autodesk.Revit import DB
from System.ComponentModel import INotifyPropertyChanged
from System.ComponentModel import PropertyChangedEventArgs
from System.Windows.Controls import DataGridRow
from System.Windows.Data import CollectionViewSource
from System.Windows.Data import PropertyGroupDescription
from System.Windows.Media import VisualTreeHelper
from pyrevit import forms
from pyrevit import revit
from pyrevit import script


COMMAND_TITLE = "Edit Visible Materials"
CACHE_VERSION = 25
DEFAULT_MATERIAL_NAME = "Default"
BY_CATEGORY = "<By Category>"
NO_MATERIAL = "<No material>"
PARAMETER_NOT_FOUND = "<Parameter not found>"
REPORT_ONLY_STATUS = "Parameter not exposed at project level"
IN_PLACE_REPORT_STATUS = "Model-in-place material; edit manually"
BAKED_IN_PLACE_STATUS = "Baked model-in-place face material"
CATEGORY_REPORT_STATUS = "Category material; no element/type parameter"
STRUCTURE_LAYER_STATUS = "Structure layers hidden from finish review"
EXPOSURE_CAN_EXPOSE = "Can expose: matching family material parameter is unassociated"
EXPOSURE_ALREADY_EXPOSED = "Already exposed in family parameter"
EXPOSURE_MANUAL_CATEGORY = "Manual: category/object style material"
EXPOSURE_MANUAL_IN_PLACE = "Manual: model-in-place material must be changed with Edit In-Place"
EXPOSURE_BAKED_IN_PLACE = "Manual: baked model-in-place face material has no project API material owner"
EXPOSURE_MANUAL_NOT_LOADABLE = "Manual: not a loadable family"
EXPOSURE_MANUAL_FAMILY_NOT_EDITABLE = "Manual: loadable family not editable"
EXPOSURE_MANUAL_NESTED = "Manual: material appears in nested family"
EXPOSURE_MANUAL_IMPORT = "Manual: imported/CAD/SAT geometry"
EXPOSURE_MANUAL_NO_PARAMETER = "Manual: no associable material parameter found"
EXPOSURE_PROJECT_PARAMETER_MATCH = "Editable: exposed project parameter matches visible material"
EXPOSURE_OBJECT_PARAMETER_MATCH = "Editable: object material parameter matches visible material"
EXPOSURE_OBJECT_STYLE_MATCH = "Editable: object style/subcategory material matches visible material"
EXPOSURE_UNKNOWN = "Exposure not checked"

TAB_LOADABLE = "loadable"
TAB_WALLS = "walls"
TAB_CEILINGS = "ceilings"
TAB_FLOORS = "floors"
TAB_OTHER = "other"
TAB_NEEDS = "needs"
TAB_REPORT = "report"

TAB_LABELS = {
    TAB_LOADABLE: "Loadable Families",
    TAB_WALLS: "Walls",
    TAB_CEILINGS: "Ceilings",
    TAB_FLOORS: "Floors",
    TAB_OTHER: "Other",
    TAB_NEEDS: "Needs Amendment",
    TAB_REPORT: "Report Only",
}

TAB_ORDER = [TAB_LOADABLE, TAB_WALLS, TAB_CEILINGS, TAB_FLOORS, TAB_OTHER]
DISPLAY_TAB_ORDER = [TAB_NEEDS, TAB_REPORT] + TAB_ORDER

ROUTE_PAINT = "paint"
ROUTE_INSTANCE_PARAM = "instance_param"
ROUTE_TYPE_PARAM = "type_param"
ROUTE_COMPOUND_LAYER = "compound_layer"
ROUTE_REPORT_ONLY = "report_only"
ROUTE_EXPOSE_TYPE_PARAM = "expose_type_param"
ROUTE_SUBELEMENT_PARAM = "subelement_param"
ROUTE_IN_PLACE_PARAM = "in_place_param"
ROUTE_OBJECT_STYLE_MATERIAL = "object_style_material"

doc = revit.doc
uidoc = revit.uidoc
output = script.get_output()
PROJECT_MATERIAL_OPTIONS_CACHE = {}


class MaterialTarget(object):
    def __init__(
        self,
        route,
        element_id=None,
        type_id=None,
        parameter_name="",
        parameter_id=None,
        subelement_uid="",
        layer_index=None,
        face=None,
        material_id=None,
        source="",
        key_extra="",
        editable=True,
    ):
        self.route = route
        self.element_id = element_id
        self.type_id = type_id
        self.parameter_name = parameter_name or ""
        self.parameter_id = parameter_id
        self.subelement_uid = subelement_uid or ""
        self.layer_index = layer_index
        self.face = face
        self.material_id = material_id
        self.source = source or ""
        self.key_extra = key_extra or ""
        self.editable = bool(editable)

    def key(self):
        return "|".join([
            self.route,
            element_id_text(self.element_id),
            element_id_text(self.type_id),
            self.parameter_name,
            element_id_text(self.parameter_id),
            self.subelement_uid,
            "" if self.layer_index is None else str(self.layer_index),
            material_key(self.material_id),
            self.key_extra,
        ])

    def to_cache(self):
        return {
            "route": self.route,
            "element_id": element_id_value(self.element_id),
            "type_id": element_id_value(self.type_id),
            "parameter_name": self.parameter_name,
            "parameter_id": element_id_value(self.parameter_id),
            "subelement_uid": self.subelement_uid,
            "layer_index": self.layer_index,
            "material_id": element_id_value(self.material_id),
            "source": self.source,
            "key_extra": self.key_extra,
            "editable": self.editable,
        }

    @staticmethod
    def from_cache(data):
        data = data or {}
        return MaterialTarget(
            data.get("route"),
            element_id=make_element_id(data.get("element_id")),
            type_id=make_element_id(data.get("type_id")),
            parameter_name=data.get("parameter_name") or "",
            parameter_id=make_element_id(data.get("parameter_id")),
            subelement_uid=data.get("subelement_uid") or "",
            layer_index=data.get("layer_index"),
            material_id=make_element_id(data.get("material_id")),
            source=data.get("source") or "",
            key_extra=data.get("key_extra") or "",
            editable=bool(data.get("editable", True)),
        )


class VisibleMaterialRow(INotifyPropertyChanged):
    def __init__(self, tab_key, element_group, category, material_source, material_id, edit_scope, sort_key):
        self._property_changed_handlers = []
        self.ElementGroup = element_group
        self.Category = category
        self.MaterialSource = material_source
        self.CurrentMaterial = ""
        self.PendingMaterial = ""
        self.Editable = "No"
        self.EditScope = edit_scope
        self.VisibleCount = 0
        self.Targets = ""
        self.Status = ""
        self.Exposure = ""
        self.GroupHasByCategory = False
        self.GroupBackground = "#EEF2F7"
        self.GroupMarker = ""
        self.Diagnostics = ""

        self._tab_key = tab_key
        self._current_material_id = material_id
        self._pending_material_id = None
        self._changed = False
        self._sort_key = sort_key
        self._targets = []
        self._target_keys = set()
        self._occurrence_keys = set()
        self._examples = []
        self._diagnostics = []

    def add_PropertyChanged(self, handler):
        self._property_changed_handlers.append(handler)

    def remove_PropertyChanged(self, handler):
        try:
            self._property_changed_handlers.remove(handler)
        except ValueError:
            pass

    def notify_properties(self, *property_names):
        if not self._property_changed_handlers:
            return
        for property_name in property_names:
            args = PropertyChangedEventArgs(property_name)
            for handler in list(self._property_changed_handlers):
                try:
                    handler(self, args)
                except Exception:
                    pass

    def add_occurrence(self, element, source):
        key = element_id_text(safe_attr(element, "Id", None))
        if key and key not in self._occurrence_keys:
            self._occurrence_keys.add(key)
            self.VisibleCount = len(self._occurrence_keys)
        if source and source not in self._examples and len(self._examples) < 10:
            self._examples.append(source)

    def add_target(self, target):
        if target is None:
            return
        key = target.key()
        if key in self._target_keys:
            return
        self._target_keys.add(key)
        self._targets.append(target)

    def add_diagnostic(self, message):
        message = to_text(message).strip()
        if not message:
            return
        if message in self._diagnostics:
            return
        self._diagnostics.append(message)
        self.Diagnostics = "\n".join(self._diagnostics)

    def is_editable(self):
        for target in self._targets:
            if target.editable:
                return True
        return False

    def has_route(self, route):
        for target in self._targets:
            if target.route == route:
                return True
        return False

    def remove_targets_by_route(self, route):
        self._targets = [target for target in self._targets if target.route != route]
        self._target_keys = set([target.key() for target in self._targets])

    def report_only_status(self):
        if self.Exposure == EXPOSURE_BAKED_IN_PLACE:
            return BAKED_IN_PLACE_STATUS
        if self.Exposure == EXPOSURE_MANUAL_IN_PLACE:
            return IN_PLACE_REPORT_STATUS
        if self.MaterialSource == "Category Material":
            return CATEGORY_REPORT_STATUS
        return REPORT_ONLY_STATUS

    def finalize_display(self):
        self.CurrentMaterial = material_name_from_id(doc, self._current_material_id)
        editable_targets = [target for target in self._targets if target.editable]
        self.Editable = "Yes" if editable_targets else "No"
        if not editable_targets and not self.Status:
            self.Status = self.report_only_status()

        target_count = len(editable_targets)
        visible_count = len(self._occurrence_keys)
        if self.has_route(ROUTE_PAINT):
            lazy_targets = [target for target in editable_targets if target.face is None]
            if lazy_targets:
                element_label = "element" if target_count == 1 else "elements"
                self.Targets = "%s painted %s; faces resolve on apply" % (target_count, element_label)
                return
            face_label = "face" if target_count == 1 else "faces"
            element_label = "element" if visible_count == 1 else "elements"
            self.Targets = "%s painted %s / %s visible %s" % (
                target_count,
                face_label,
                visible_count,
                element_label,
            )
        elif self.has_route(ROUTE_TYPE_PARAM) or self.has_route(ROUTE_COMPOUND_LAYER):
            type_label = "type target" if target_count == 1 else "type targets"
            element_label = "element" if visible_count == 1 else "elements"
            self.Targets = "%s visible %s / %s %s" % (
                visible_count,
                element_label,
                target_count,
                type_label,
            )
        elif self.has_route(ROUTE_INSTANCE_PARAM):
            element_label = "element" if target_count == 1 else "elements"
            self.Targets = "%s instance %s" % (target_count, element_label)
        elif self.has_route(ROUTE_IN_PLACE_PARAM):
            object_label = "object" if target_count == 1 else "objects"
            self.Targets = "%s in-place %s" % (target_count, object_label)
        elif self.has_route(ROUTE_SUBELEMENT_PARAM):
            object_label = "object" if target_count == 1 else "objects"
            self.Targets = "%s in-place %s" % (target_count, object_label)
        elif self.has_route(ROUTE_OBJECT_STYLE_MATERIAL):
            style_label = "style target" if target_count == 1 else "style targets"
            self.Targets = "%s object %s" % (target_count, style_label)
        elif self.has_route(ROUTE_EXPOSE_TYPE_PARAM):
            parameter_label = "parameter" if target_count == 1 else "parameters"
            self.Targets = "%s family material %s to expose" % (target_count, parameter_label)
        else:
            element_label = "element" if visible_count == 1 else "elements"
            self.Targets = "%s visible %s" % (visible_count, element_label)

    def set_pending(self, material_name, material_id):
        self.PendingMaterial = material_name
        self._pending_material_id = material_id
        self._changed = True
        self.Status = "Pending"
        self.notify_properties("PendingMaterial", "Status")

    def mark_applied(self):
        if self._changed:
            self.CurrentMaterial = self.PendingMaterial
            self._current_material_id = self._pending_material_id
        self.PendingMaterial = ""
        self._pending_material_id = None
        self._changed = False
        self.Status = "Applied"
        self.finalize_display()
        self.Status = "Applied"
        self.notify_properties("CurrentMaterial", "PendingMaterial", "Status", "Editable", "EditScope", "Targets")

    def clear_pending(self):
        if self.has_route(ROUTE_EXPOSE_TYPE_PARAM):
            self.remove_targets_by_route(ROUTE_EXPOSE_TYPE_PARAM)
            self.EditScope = "Report only"
        self.PendingMaterial = ""
        self._pending_material_id = None
        self._changed = False
        self.Status = "" if self.is_editable() else self.report_only_status()
        self.finalize_display()
        self.notify_properties("PendingMaterial", "Status", "Editable", "EditScope", "Targets")

    def to_cache(self):
        return {
            "tab_key": self._tab_key,
            "element_group": self.ElementGroup,
            "category": self.Category,
            "material_source": self.MaterialSource,
            "current_material_id": element_id_value(self._current_material_id),
            "edit_scope": self.EditScope,
            "sort_key": self._sort_key,
            "status": "" if self._changed else self.Status,
            "exposure": self.Exposure,
            "group_has_by_category": self.GroupHasByCategory,
            "diagnostics": list(self._diagnostics),
            "occurrence_keys": sorted(list(self._occurrence_keys)),
            "examples": list(self._examples),
            "targets": [target.to_cache() for target in self._targets],
        }

    @staticmethod
    def from_cache(data):
        data = data or {}
        row = VisibleMaterialRow(
            data.get("tab_key") or TAB_OTHER,
            data.get("element_group") or "",
            data.get("category") or "",
            data.get("material_source") or "",
            make_element_id(data.get("current_material_id")),
            data.get("edit_scope") or "",
            data.get("sort_key") or "",
        )
        row.Status = data.get("status") or ""
        row.Exposure = data.get("exposure") or ""
        row.GroupHasByCategory = bool(data.get("group_has_by_category", False))
        row.GroupBackground = "#FFF2A8" if row.GroupHasByCategory else "#EEF2F7"
        row.GroupMarker = "Needs amendment" if row.GroupHasByCategory else ""
        row._diagnostics = list(data.get("diagnostics") or [])
        row.Diagnostics = "\n".join(row._diagnostics)
        row._occurrence_keys = set([to_text(value) for value in data.get("occurrence_keys") or []])
        row.VisibleCount = len(row._occurrence_keys)
        row._examples = list(data.get("examples") or [])
        for target_data in data.get("targets") or []:
            row.add_target(MaterialTarget.from_cache(target_data))
        row.finalize_display()
        return row


class MaterialOption(object):
    def __init__(self, name, element_id):
        self.Name = name
        self.element_id = element_id

    @property
    def description(self):
        return self.Name


class MaterialPickerWindow(forms.WPFWindow):
    def __init__(self, project_doc):
        self.project_doc = project_doc
        self.selected_option = None
        self._all_options = []
        self._shown_options = []
        xaml_path = os.path.join(os.path.dirname(__file__), "material_picker.xaml")
        forms.WPFWindow.__init__(self, xaml_path)
        self.reload_materials()
        self.set_status("%s project materials" % max(0, len(self._all_options) - 1))
        try:
            self.filter_box.Focus()
        except Exception:
            pass

    def set_status(self, message):
        self.status_text.Text = message or ""

    def reload_materials(self):
        self._all_options = get_project_material_options(self.project_doc)
        self.apply_filter()

    def apply_filter(self):
        try:
            filter_text = to_text(self.filter_box.Text).strip().lower()
        except Exception:
            filter_text = ""
        tokens = [token for token in filter_text.split() if token]
        shown = []
        for option in self._all_options:
            name = to_text(option.Name).lower()
            if not tokens or all(token in name for token in tokens):
                shown.append(option)
        self._shown_options = shown
        self.materials_list.ItemsSource = shown
        if shown:
            try:
                self.materials_list.SelectedIndex = 0
            except Exception:
                pass
        self.set_status("%s shown" % len(shown))

    def selected_material_option(self):
        try:
            selected = self.materials_list.SelectedItem
            if isinstance(selected, MaterialOption):
                return selected
        except Exception:
            pass
        return None

    def on_filter_changed(self, sender, args):
        self.apply_filter()

    def on_use_material(self, sender, args):
        selected = self.selected_material_option()
        if selected is None:
            self.set_status("Select a material first.")
            return
        self.selected_option = selected
        try:
            self.DialogResult = True
        except Exception:
            pass
        self.Close()

    def on_quick_add(self, sender, args):
        material_name = forms.ask_for_string(
            default="",
            prompt="New project material name",
            title="Quick Add New Material"
        )
        if material_name is None:
            self.set_status("Quick add cancelled.")
            return

        material_name = clean_material_name(material_name)
        if not material_name:
            forms.alert("Enter a material name before creating a material.", title=COMMAND_TITLE, warn_icon=True)
            self.set_status("No material was created.")
            return

        try:
            material, created = create_project_material_with_random_color(self.project_doc, material_name)
        except Exception as err:
            forms.alert("Could not create material:\n\n%s" % to_text(err), title=COMMAND_TITLE, warn_icon=True)
            self.set_status("Material creation failed.")
            return

        if not created:
            forms.alert(
                "A project material named '%s' already exists. The existing material will be used." % material_name,
                title=COMMAND_TITLE,
                warn_icon=False
            )

        self.selected_option = MaterialOption(safe_name(material, material_name), material.Id)
        try:
            self.DialogResult = True
        except Exception:
            pass
        self.Close()

    def on_cancel(self, sender, args):
        self.selected_option = None
        try:
            self.DialogResult = False
        except Exception:
            pass
        self.Close()


def to_text(value):
    if value is None:
        return ""
    try:
        return unicode(value)
    except NameError:
        return str(value)
    except Exception:
        return str(value)


def safe_attr(element, attr_name, fallback=None):
    if element is None:
        return fallback
    try:
        return getattr(element, attr_name)
    except Exception:
        return fallback


def visual_parent_of_type(start_element, parent_type):
    current = start_element
    while current is not None:
        try:
            if isinstance(current, parent_type):
                return current
        except Exception:
            pass
        try:
            current = VisualTreeHelper.GetParent(current)
        except Exception:
            return None
    return None


def safe_name(element, fallback=""):
    if element is None:
        return fallback
    try:
        value = element.Name
        if value:
            return to_text(value)
    except Exception:
        pass
    try:
        return to_text(DB.Element.Name.GetValue(element))
    except Exception:
        return fallback


def document_title(project_doc):
    try:
        title = project_doc.Title
        if title:
            return to_text(title)
    except Exception:
        pass
    return "Untitled Project"


def element_id_value(element_id):
    if element_id is None:
        return None
    for attr_name in ("Value", "IntegerValue"):
        try:
            return int(getattr(element_id, attr_name))
        except Exception:
            pass
    try:
        return int(str(element_id))
    except Exception:
        return None


def element_id_text(element_id):
    value = element_id_value(element_id)
    return "" if value is None else str(value)


def element_id_equal(left, right):
    left_value = element_id_value(left)
    right_value = element_id_value(right)
    return left_value is not None and right_value is not None and left_value == right_value


def make_element_id(value):
    try:
        if value is None:
            return None
        value = int(value)
    except Exception:
        return None
    try:
        return DB.ElementId(value)
    except Exception:
        return None


def material_key(material_id):
    value = element_id_value(material_id)
    return "none" if value is None else str(value)


def is_by_category_id(material_id):
    value = element_id_value(material_id)
    return value is None or value < 0


def is_valid_material_id(project_doc, material_id):
    value = element_id_value(material_id)
    if value is None or value <= 0:
        return False
    try:
        return isinstance(project_doc.GetElement(material_id), DB.Material)
    except Exception:
        return False


def material_name_from_id(project_doc, material_id):
    if material_id is None:
        return BY_CATEGORY
    if is_by_category_id(material_id):
        return BY_CATEGORY
    try:
        material = project_doc.GetElement(material_id)
    except Exception:
        material = None
    if isinstance(material, DB.Material):
        return safe_name(material, "<Unnamed material>")
    return "<Missing material %s>" % element_id_text(material_id)


def normalized_material_name(value):
    return clean_material_name(value).strip().lower()


def clean_material_name(value):
    text = to_text(value).strip()
    for char in '<>:"/\\|?*{}[];`~':
        text = text.replace(char, "-")
    text = " ".join(text.split())
    return text[:220]


def safe_file_part(value):
    text = to_text(value).strip() or "untitled"
    for char in '<>:"/\\|?*{}[];`~':
        text = text.replace(char, "_")
    text = " ".join(text.split())
    return text[:120] or "untitled"


def get_cache_folder():
    root = os.environ.get("LOCALAPPDATA") or os.path.join(os.path.expanduser("~"), "AppData", "Local")
    folder = os.path.join(root, "GeyerDev", "EditVisibleMaterials")
    if not os.path.exists(folder):
        os.makedirs(folder)
    return folder


def document_cache_identity(project_doc):
    parts = []
    for attr_name in ("PathName", "Title"):
        try:
            value = getattr(project_doc, attr_name)
            if value:
                parts.append(to_text(value))
        except Exception:
            pass
    try:
        project_info = project_doc.ProjectInformation
        if project_info is not None:
            parts.append(to_text(project_info.UniqueId))
    except Exception:
        pass
    return "|".join(parts) or "untitled"


def cache_path_for_view(project_doc, view):
    view_id = element_id_text(safe_attr(view, "Id", None)) or "noview"
    raw_key = "%s|%s|%s" % (CACHE_VERSION, document_cache_identity(project_doc), view_id)
    digest = hashlib.md5(raw_key.encode("utf-8")).hexdigest()
    name = "%s_%s_visible_materials.json" % (safe_file_part(document_title(project_doc)), digest)
    return os.path.join(get_cache_folder(), name)


def serialize_active_family_cache(active_family_cache):
    serialized = {}
    for family_key, values in (active_family_cache or {}).items():
        serialized[family_key] = [
            {"scope": item[0], "name": item[1]} for item in sorted(list(values), key=lambda value: (value[0], value[1]))
        ]
    return serialized


def deserialize_active_family_cache(data):
    cache = {}
    for family_key, values in (data or {}).items():
        restored = set()
        for item in values or []:
            scope = item.get("scope")
            name = item.get("name")
            if scope and name:
                restored.add((scope, name))
        cache[family_key] = restored
    return cache


def load_cached_rows(project_doc, view):
    path = cache_path_for_view(project_doc, view)
    if not os.path.exists(path):
        return None
    try:
        with open(path, "r") as cache_file:
            data = json.load(cache_file)
    except Exception:
        return None
    if data.get("version") != CACHE_VERSION:
        return None
    if data.get("document_identity") != document_cache_identity(project_doc):
        return None
    if data.get("view_id") != element_id_text(safe_attr(view, "Id", None)):
        return None
    try:
        rows = [VisibleMaterialRow.from_cache(row_data) for row_data in data.get("rows") or []]
    except Exception:
        return None
    rows = sorted(
        rows,
        key=lambda row: (
            TAB_ORDER.index(row._tab_key) if row._tab_key in TAB_ORDER else 99,
            row.Category.lower(),
            row.ElementGroup.lower(),
            row.MaterialSource.lower(),
            row.CurrentMaterial.lower(),
        ),
    )
    return {
        "rows": rows,
        "visible_element_ids": data.get("visible_element_ids") or [],
        "notes": data.get("notes") or [],
        "active_family_cache": deserialize_active_family_cache(data.get("active_family_cache") or {}),
        "path": path,
    }


def save_cached_rows(project_doc, view, visible_elements, rows, notes, active_family_cache=None):
    path = cache_path_for_view(project_doc, view)
    visible_element_ids = []
    for element in visible_elements or []:
        if safe_attr(element, "Id", None) is None:
            value = element_id_value(element)
        else:
            value = element_id_value(safe_attr(element, "Id", None))
        if value is not None:
            visible_element_ids.append(value)
    data = {
        "version": CACHE_VERSION,
        "document_identity": document_cache_identity(project_doc),
        "view_id": element_id_text(safe_attr(view, "Id", None)),
        "view_name": safe_name(view, "Active 3D View"),
        "visible_element_ids": visible_element_ids,
        "notes": [
            note for note in list(notes or [])
            if not to_text(note).startswith("Loaded cached visible-material table")
        ],
        "active_family_cache": serialize_active_family_cache(active_family_cache),
        "rows": [row.to_cache() for row in rows],
    }
    try:
        with open(path, "w") as cache_file:
            json.dump(data, cache_file, indent=2, sort_keys=True)
    except Exception:
        pass
    return path


def invalidate_material_options_cache(project_doc):
    key = document_cache_identity(project_doc)
    if key in PROJECT_MATERIAL_OPTIONS_CACHE:
        del PROJECT_MATERIAL_OPTIONS_CACHE[key]


def find_project_material_by_name(project_doc, material_name):
    requested = clean_material_name(material_name).lower()
    if not requested:
        return None
    try:
        materials = DB.FilteredElementCollector(project_doc).OfClass(DB.Material).ToElements()
    except Exception:
        return None
    for material in materials:
        try:
            if safe_name(material).strip().lower() == requested:
                return material
        except Exception:
            continue
    return None


def random_graphics_color():
    return DB.Color(
        random.randint(35, 230),
        random.randint(35, 230),
        random.randint(35, 230)
    )


def create_project_material_with_random_color(project_doc, material_name):
    clean_name = clean_material_name(material_name)
    if not clean_name:
        raise Exception("Material name is blank.")

    existing = find_project_material_by_name(project_doc, clean_name)
    if existing is not None:
        return existing, False

    transaction = DB.Transaction(project_doc, "Quick add project material")
    transaction.Start()
    try:
        material_id = DB.Material.Create(project_doc, clean_name)
        material = project_doc.GetElement(material_id)
        if material is None:
            raise Exception("Material was created, but could not be retrieved.")

        color = random_graphics_color()
        try:
            material.Color = color
        except Exception:
            pass
        try:
            material.UseRenderAppearanceForShading = False
        except Exception:
            pass

        transaction.Commit()
        invalidate_material_options_cache(project_doc)
        return material, True
    except Exception:
        transaction.RollBack()
        raise


def same_element_id(left, right):
    return element_id_value(left) == element_id_value(right)


def get_material_spec_id():
    try:
        return DB.SpecTypeId.Reference.Material
    except Exception:
        pass
    try:
        return DB.SpecTypeIdReference.Material
    except Exception:
        return None


MATERIAL_SPEC_ID = get_material_spec_id()


def same_forge_type_id(left, right):
    if left is None or right is None:
        return False
    try:
        if left == right:
            return True
    except Exception:
        pass
    try:
        return bool(left.Equals(right))
    except Exception:
        return False


def parameter_name(parameter):
    try:
        definition = parameter.Definition
        if definition is not None:
            return to_text(definition.Name)
    except Exception:
        pass
    return ""


def parameter_identity(parameter):
    return (
        parameter_name(parameter),
        element_id_text(safe_attr(parameter, "Id", None)),
    )


def material_builtin_parameter_names():
    return (
        "MATERIAL_ID_PARAM",
        "MATERIAL_PARAM",
        "STRUCTURAL_MATERIAL_PARAM",
        "OBJECT_STYLE_MATERIAL_ID_PARAM",
        "DPART_MATERIAL_ID_PARAM",
    )


def material_parameter_type_id_names():
    return (
        "MaterialIdParam",
        "ObjectStyleMaterialIdParam",
        "DpartMaterialIdParam",
        "MassSurfacedataMaterial",
    )


def collect_candidate_material_parameters(element):
    parameters = []

    def add_parameter(parameter):
        if parameter is not None:
            parameters.append(parameter)

    try:
        for parameter in list(element.Parameters):
            add_parameter(parameter)
    except Exception:
        pass

    built_in = safe_attr(DB, "BuiltInParameter", None)
    for built_in_name in material_builtin_parameter_names():
        built_in_value = safe_attr(built_in, built_in_name, None)
        if built_in_value is None:
            continue
        try:
            add_parameter(element.get_Parameter(built_in_value))
        except Exception:
            pass

    parameter_type_id = safe_attr(DB, "ParameterTypeId", None)
    for type_id_name in material_parameter_type_id_names():
        type_id = safe_attr(parameter_type_id, type_id_name, None)
        if type_id is None:
            continue
        try:
            add_parameter(element.GetParameter(type_id))
        except Exception:
            pass

    return parameters


def is_material_parameter(project_doc, parameter):
    if parameter is None:
        return False
    try:
        if parameter.StorageType != DB.StorageType.ElementId:
            return False
    except Exception:
        return False

    name = parameter_name(parameter).lower()
    try:
        definition = parameter.Definition
        data_type = definition.GetDataType() if definition is not None else None
    except Exception:
        data_type = None

    if MATERIAL_SPEC_ID is not None and same_forge_type_id(data_type, MATERIAL_SPEC_ID):
        return True

    try:
        if data_type is not None and "material" in to_text(data_type.TypeId).lower():
            return True
    except Exception:
        pass

    if "material" in name:
        return True

    try:
        candidate_id = parameter.AsElementId()
        if is_valid_material_id(project_doc, candidate_id):
            return True
    except Exception:
        pass

    return False


def get_parameter_material_id(parameter):
    try:
        return parameter.AsElementId()
    except Exception:
        return DB.ElementId.InvalidElementId


def element_id_from_parameter_value(parameter_value):
    if parameter_value is None:
        return None
    try:
        if isinstance(parameter_value, DB.ElementIdParameterValue):
            return parameter_value.Value
    except Exception:
        pass
    try:
        value = parameter_value.Value
        if isinstance(value, DB.ElementId):
            return value
    except Exception:
        pass
    return None


def parameter_name_from_id(project_doc, parameter_id):
    try:
        parameter_element = project_doc.GetElement(parameter_id)
    except Exception:
        parameter_element = None
    try:
        definition = parameter_element.GetDefinition()
        name = safe_attr(definition, "Name", None)
        if name:
            return to_text(name)
    except Exception:
        pass
    value = element_id_value(parameter_id)
    if value is not None and value < 0:
        try:
            built_in_parameter = System.Enum.ToObject(DB.BuiltInParameter, value)
            label = DB.LabelUtils.GetLabelFor(built_in_parameter)
            if label:
                return to_text(label)
        except Exception:
            pass
    return "Parameter %s" % element_id_text(parameter_id)


def is_parameter_read_only(parameter):
    try:
        return bool(parameter.IsReadOnly)
    except Exception:
        return False


def iter_material_parameters(project_doc, element):
    if element is None:
        return []
    results = []
    seen = set()
    for parameter in collect_candidate_material_parameters(element):
        try:
            name = parameter_name(parameter)
            identity = parameter_identity(parameter)
            if not name or identity in seen:
                continue
            if not is_material_parameter(project_doc, parameter):
                continue
            seen.add(identity)
            results.append(parameter)
        except Exception:
            continue
    return results


def find_material_parameter(project_doc, element, param_name, parameter_id=None):
    if element is None or (not param_name and parameter_id is None):
        return None
    if parameter_id is not None:
        parameter_id_text = element_id_text(parameter_id)
        for parameter in iter_material_parameters(project_doc, element):
            try:
                if element_id_text(safe_attr(parameter, "Id", None)) == parameter_id_text:
                    return parameter
            except Exception:
                continue
    for parameter in iter_material_parameters(project_doc, element):
        if parameter_name(parameter) == param_name:
            return parameter
    try:
        parameter = element.LookupParameter(param_name)
        if is_material_parameter(project_doc, parameter):
            return parameter
    except Exception:
        pass
    return None


def get_active_3d_view(project_doc):
    view = project_doc.ActiveView
    if view is None:
        raise Exception("No active view was found. Open a 3D view and run the editor again.")
    if not isinstance(view, DB.View3D):
        raise Exception("The active view is not a 3D view. Open the 3D view you want to edit and run the tool again.")
    try:
        if view.IsTemplate:
            raise Exception("The active view is a 3D view template. Open a real 3D view and run the editor again.")
    except AttributeError:
        pass
    return view


def get_category_name(element):
    try:
        if element.Category is not None:
            return to_text(element.Category.Name)
    except Exception:
        pass
    return ""


def is_model_element(element):
    if element is None:
        return False
    try:
        if element.ViewSpecific:
            return False
    except Exception:
        pass
    try:
        category = element.Category
        if category is None:
            return False
        if category.CategoryType != DB.CategoryType.Model:
            return False
    except Exception:
        return False
    return True


def is_visible_in_view(element, view):
    try:
        if element.IsHidden(view):
            return False
    except Exception:
        pass
    return True


def builtin_category_value(builtin_category):
    try:
        return int(builtin_category)
    except Exception:
        pass
    try:
        return element_id_value(DB.ElementId(builtin_category))
    except Exception:
        return None


def is_built_in_category(element, builtin_category):
    try:
        category = element.Category
        if category is None:
            return False
        return element_id_value(category.Id) == builtin_category_value(builtin_category)
    except Exception:
        return False


def get_type_element(project_doc, element):
    if element is None:
        return None
    try:
        type_id = element.GetTypeId()
    except Exception:
        return None
    if type_id is None or is_by_category_id(type_id):
        return None
    try:
        return project_doc.GetElement(type_id)
    except Exception:
        return None


def get_type_name(project_doc, element):
    type_element = get_type_element(project_doc, element)
    if type_element is None:
        return ""
    return safe_name(type_element, element_id_text(type_element.Id))


def get_family_and_type_name(element):
    try:
        symbol = element.Symbol
        family = symbol.Family if symbol is not None else None
        family_name = safe_name(family, "")
        type_name = safe_name(symbol, "")
        if family_name and type_name:
            return "%s : %s" % (family_name, type_name)
        return family_name or type_name
    except Exception:
        return ""


def is_editable_loadable_family(family):
    if family is None:
        return False
    try:
        return bool(family.IsEditable) and not bool(family.IsInPlace)
    except Exception:
        return False


def is_loadable_family_instance(element):
    if not isinstance(element, DB.FamilyInstance):
        return False
    try:
        family = element.Symbol.Family
        if family is None:
            return False
        if family.IsInPlace:
            return False
    except Exception:
        return False
    return True


def is_in_place_family_instance(element):
    if not isinstance(element, DB.FamilyInstance):
        return False
    try:
        family = element.Symbol.Family
        return family is not None and bool(family.IsInPlace)
    except Exception:
        return False


def is_in_place_form_element(element):
    return is_generic_form_element(element)


def is_model_in_place_review_only_element(element):
    return is_in_place_family_instance(element) or is_in_place_form_element(element)


def active_family_parameter_key(family_parameter):
    return (
        "I" if bool(family_parameter.IsInstance) else "T",
        to_text(family_parameter.Definition.Name),
    )


def get_active_loadable_family_parameter_keys(project_doc, family):
    family_doc = None
    active_keys = set()
    try:
        family_doc = project_doc.EditFamily(family)
        family_manager = family_doc.FamilyManager
        family_elements = DB.FilteredElementCollector(family_doc) \
            .WhereElementIsNotElementType() \
            .ToElements()

        for family_element in family_elements:
            try:
                parameters = family_element.Parameters
            except Exception:
                continue
            for parameter in parameters:
                if not is_material_parameter(family_doc, parameter):
                    continue
                try:
                    family_parameter = family_manager.GetAssociatedFamilyParameter(parameter)
                except Exception:
                    family_parameter = None
                if family_parameter is None:
                    continue
                active_keys.add(active_family_parameter_key(family_parameter))
        return active_keys
    finally:
        if family_doc is not None:
            family_doc.Close(False)


def get_loadable_active_parameter_cache(project_doc, element, cache, notes):
    if not is_loadable_family_instance(element):
        return None
    try:
        family = element.Symbol.Family
    except Exception:
        return set()
    family_key = element_id_text(safe_attr(family, "Id", None))
    if family_key in cache:
        return cache[family_key]
    if not is_editable_loadable_family(family):
        cache[family_key] = set()
        return cache[family_key]
    try:
        cache[family_key] = get_active_loadable_family_parameter_keys(project_doc, family)
    except Exception as err:
        cache[family_key] = set()
        notes.append("%s active material parameter scan skipped: %s" % (safe_name(family, family_key), to_text(err)))
    return cache[family_key]


def material_id_matches_name(project_doc, material_id, material_name_key):
    if not material_name_key:
        return False
    material_name = material_name_from_id(project_doc, material_id)
    return normalized_material_name(material_name) == material_name_key


def family_element_uses_material_name(family_doc, family_element, material_name_key):
    for include_paint in (False, True):
        for material_value in material_ids_from_element(family_element, include_paint):
            if material_id_matches_name(family_doc, DB.ElementId(material_value), material_name_key):
                return True
    return False


def family_element_material_parameter_matches(family_doc, family_element, material_name_key):
    matches = []
    for parameter in iter_material_parameters(family_doc, family_element):
        try:
            material_id = get_parameter_material_id(parameter)
        except Exception:
            continue
        if material_id_matches_name(family_doc, material_id, material_name_key):
            matches.append(parameter)
    return matches


def diagnose_family_material_exposure(project_doc, element, material_id, exposure_cache, notes):
    if is_in_place_family_instance(element):
        return EXPOSURE_MANUAL_IN_PLACE
    if not is_loadable_family_instance(element):
        return EXPOSURE_MANUAL_NOT_LOADABLE

    material_name = material_name_from_id(project_doc, material_id)
    material_name_key = normalized_material_name(material_name)
    if not material_name_key or material_name == BY_CATEGORY or material_name.startswith("<Missing material"):
        return EXPOSURE_MANUAL_NO_PARAMETER

    try:
        family = element.Symbol.Family
    except Exception:
        return EXPOSURE_MANUAL_NOT_LOADABLE

    family_key = element_id_text(safe_attr(family, "Id", None)) or safe_name(family, "")
    cache_key = "%s|%s" % (family_key, material_name_key)
    if cache_key in exposure_cache:
        return exposure_cache[cache_key]

    if not is_editable_loadable_family(family):
        exposure_cache[cache_key] = EXPOSURE_MANUAL_FAMILY_NOT_EDITABLE
        return exposure_cache[cache_key]

    family_doc = None
    result = EXPOSURE_MANUAL_NO_PARAMETER
    saw_nested = False
    saw_import = False
    try:
        family_doc = project_doc.EditFamily(family)
        family_manager = family_doc.FamilyManager
        family_elements = DB.FilteredElementCollector(family_doc) \
            .WhereElementIsNotElementType() \
            .ToElements()

        for family_element in family_elements:
            element_matches = family_element_uses_material_name(family_doc, family_element, material_name_key)
            if element_matches:
                try:
                    if isinstance(family_element, DB.ImportInstance):
                        saw_import = True
                except Exception:
                    pass
                try:
                    if isinstance(family_element, DB.FamilyInstance):
                        saw_nested = True
                except Exception:
                    pass

            for parameter in family_element_material_parameter_matches(family_doc, family_element, material_name_key):
                try:
                    family_parameter = family_manager.GetAssociatedFamilyParameter(parameter)
                except Exception:
                    family_parameter = None
                if family_parameter is not None:
                    result = EXPOSURE_ALREADY_EXPOSED
                    continue
                try:
                    if parameter.IsReadOnly:
                        continue
                except AttributeError:
                    pass
                result = EXPOSURE_CAN_EXPOSE
                exposure_cache[cache_key] = result
                return result

        if result == EXPOSURE_ALREADY_EXPOSED:
            exposure_cache[cache_key] = result
            return result
        if saw_nested:
            result = EXPOSURE_MANUAL_NESTED
        elif saw_import:
            result = EXPOSURE_MANUAL_IMPORT
    except Exception as err:
        result = "Manual: exposure check failed (%s)" % to_text(err)
        try:
            notes.append("%s material exposure check skipped for %s: %s" % (
                safe_name(family, family_key),
                material_name,
                to_text(err),
            ))
        except Exception:
            pass
    finally:
        if family_doc is not None:
            try:
                family_doc.Close(False)
            except Exception:
                pass

    exposure_cache[cache_key] = result
    return result


class FamilyReloadOptions(DB.IFamilyLoadOptions):
    def OnFamilyFound(self, familyInUse, overwriteParameterValues):
        overwriteParameterValues = False
        return True

    def OnSharedFamilyFound(self, sharedFamily, familyInUse, source, overwriteParameterValues):
        source = DB.FamilySource.Family
        overwriteParameterValues = False
        return True


def iter_family_types(family_manager):
    types = []
    if family_manager is None:
        return types
    try:
        iterator = family_manager.Types.ForwardIterator()
        iterator.Reset()
        while iterator.MoveNext():
            types.append(iterator.Current)
        return types
    except Exception:
        pass
    try:
        return list(family_manager.Types)
    except Exception:
        return types


def family_parameter_name(family_parameter):
    try:
        return to_text(family_parameter.Definition.Name)
    except Exception:
        return ""


def family_parameter_by_name(family_manager, name):
    if family_manager is None or not name:
        return None
    wanted = to_text(name).strip().lower()
    try:
        parameters = family_manager.GetParameters()
    except Exception:
        parameters = []
    for family_parameter in parameters:
        if family_parameter_name(family_parameter).strip().lower() == wanted:
            return family_parameter
    return None


def unique_family_parameter_name(family_manager, preferred_name):
    base = clean_material_name(preferred_name) or "Exposed Material"
    if "material" not in base.lower():
        base = "%s Material" % base
    candidate = base[:90]
    index = 2
    while family_parameter_by_name(family_manager, candidate) is not None:
        suffix = " %s" % index
        candidate = ("%s%s" % (base[:90 - len(suffix)], suffix)).strip()
        index += 1
    return candidate


def add_family_material_parameter(family_manager, parameter_name, is_instance=False):
    try:
        return family_manager.AddParameter(
            parameter_name,
            DB.GroupTypeId.Materials,
            DB.SpecTypeId.Reference.Material,
            is_instance,
        )
    except Exception:
        return family_manager.AddParameter(
            parameter_name,
            DB.BuiltInParameterGroup.PG_MATERIALS,
            DB.ParameterType.Material,
            is_instance,
        )


def can_associate_family_element_parameter(family_manager, parameter):
    try:
        return bool(family_manager.CanElementParameterBeAssociated(parameter))
    except Exception:
        return True


def find_exposable_family_material_parameters(family_doc, material_name_key):
    family_manager = family_doc.FamilyManager
    candidates = []
    family_elements = DB.FilteredElementCollector(family_doc) \
        .WhereElementIsNotElementType() \
        .ToElements()

    for family_element in family_elements:
        for parameter in family_element_material_parameter_matches(family_doc, family_element, material_name_key):
            try:
                family_parameter = family_manager.GetAssociatedFamilyParameter(parameter)
            except Exception:
                family_parameter = None
            if family_parameter is not None:
                continue
            if is_parameter_read_only(parameter):
                continue
            if not can_associate_family_element_parameter(family_manager, parameter):
                continue
            candidates.append({
                "element": family_element,
                "parameter": parameter,
                "material_id": get_parameter_material_id(parameter),
            })
    return candidates


def family_candidate_label(candidate):
    element = candidate.get("element")
    parameter = candidate.get("parameter")
    category = get_category_name(element) or to_text(type(element).__name__)
    return "%s %s / %s" % (
        category,
        element_id_text(safe_attr(element, "Id", None)),
        parameter_name(parameter),
    )


def set_family_parameter_all_types(family_manager, family_parameter, material_id):
    changed = 0
    old_type = None
    try:
        old_type = family_manager.CurrentType
    except Exception:
        pass

    family_types = iter_family_types(family_manager)
    if not family_types:
        try:
            family_manager.Set(family_parameter, material_id)
            return 1
        except Exception:
            return 0

    for family_type in family_types:
        try:
            family_manager.CurrentType = family_type
            family_manager.Set(family_parameter, material_id)
            changed += 1
        except Exception:
            continue

    if old_type is not None:
        try:
            family_manager.CurrentType = old_type
        except Exception:
            pass
    return changed


def expose_and_set_family_material_parameter(project_doc, target, pending_material_id):
    if project_doc.IsModifiable:
        raise Exception("project document is modifiable; family reload must run outside a project transaction")
    element = project_doc.GetElement(target.element_id)
    if not is_loadable_family_instance(element):
        raise Exception("selected row is not a loadable family instance")
    if not is_valid_material_id(project_doc, target.material_id):
        raise Exception("original report-only material is not a valid project material")
    if not is_valid_material_id(project_doc, pending_material_id):
        raise Exception("pending material is not a valid project material")
    parameter_name_to_create = to_text(target.parameter_name).strip()
    if not parameter_name_to_create:
        raise Exception("new material parameter name is blank")

    try:
        family = element.Symbol.Family
    except Exception:
        raise Exception("could not resolve the family from the selected row")
    if not is_editable_loadable_family(family):
        raise Exception("family is not editable or is in-place")
    original_family_id = element_id_text(safe_attr(family, "Id", None))
    original_family_name = safe_name(family, "")

    source_material_name = material_name_from_id(project_doc, target.material_id)
    source_material_key = normalized_material_name(source_material_name)
    pending_material_name = material_name_from_id(project_doc, pending_material_id)
    family_doc = None
    loaded_family = None

    try:
        family_doc = project_doc.EditFamily(family)
        family_manager = family_doc.FamilyManager
        candidates = find_exposable_family_material_parameters(family_doc, source_material_key)
        if not candidates:
            raise Exception("no unassociated family material parameter still matches %s" % source_material_name)

        existing_parameter = family_parameter_by_name(family_manager, parameter_name_to_create)
        if existing_parameter is not None:
            raise Exception("family already contains a parameter named '%s'; choose a unique name" % parameter_name_to_create)

        transaction = DB.Transaction(family_doc, "Expose material parameter")
        transaction.Start()
        try:
            family_parameter = add_family_material_parameter(
                family_manager,
                parameter_name_to_create,
                is_instance=False,
            )

            initial_family_material_id = candidates[0].get("material_id")
            set_family_parameter_all_types(family_manager, family_parameter, initial_family_material_id)

            associated = 0
            for candidate in candidates:
                family_manager.AssociateElementParameterToFamilyParameter(
                    candidate.get("parameter"),
                    family_parameter,
                )
                associated += 1
            transaction.Commit()
        except Exception:
            transaction.RollBack()
            raise

        loaded_family = family_doc.LoadFamily(project_doc, FamilyReloadOptions())
        if loaded_family is None:
            raise Exception("family reload did not return a project family")
        loaded_family_id = element_id_text(safe_attr(loaded_family, "Id", None))
        loaded_family_name = safe_name(loaded_family, "")
        if original_family_id and loaded_family_id and loaded_family_id != original_family_id:
            raise Exception(
                "family reload returned a different family id (%s instead of %s); update stopped before setting the new material"
                % (loaded_family_id, original_family_id)
            )
        if original_family_name and loaded_family_name and loaded_family_name != original_family_name:
            raise Exception(
                "family reload returned a different family name ('%s' instead of '%s'); update stopped before setting the new material"
                % (loaded_family_name, original_family_name)
            )
    finally:
        if family_doc is not None:
            try:
                family_doc.Close(False)
            except Exception:
                pass

    project_target = MaterialTarget(
        ROUTE_TYPE_PARAM,
        type_id=safe_attr(get_type_element(project_doc, element), "Id", None),
        parameter_name=parameter_name_to_create,
        source="Reloaded family %s" % safe_name(loaded_family or family, ""),
        editable=True,
    )
    transaction = DB.Transaction(project_doc, "Set exposed material parameter")
    transaction.Start()
    try:
        set_parameter_target(project_doc, project_target, pending_material_id)
        transaction.Commit()
    except Exception:
        transaction.RollBack()
        raise

    return "Created type parameter '%s', associated %s family material parameter(s), set to '%s'." % (
        parameter_name_to_create,
        associated,
        pending_material_name,
    )


def tab_key_for_element(element):
    if is_loadable_family_instance(element):
        return TAB_LOADABLE
    if is_built_in_category(element, DB.BuiltInCategory.OST_Walls):
        return TAB_WALLS
    if is_built_in_category(element, DB.BuiltInCategory.OST_Ceilings):
        return TAB_CEILINGS
    if is_built_in_category(element, DB.BuiltInCategory.OST_Floors):
        return TAB_FLOORS
    return TAB_OTHER


def element_group_name(project_doc, element):
    if is_loadable_family_instance(element):
        name = get_family_and_type_name(element)
        if name:
            return name
    type_name = get_type_name(project_doc, element)
    if type_name:
        return type_name
    name = safe_name(element, "")
    if name:
        return name
    return "Element %s" % element_id_text(safe_attr(element, "Id", None))


def element_example_text(project_doc, element):
    return "%s %s" % (
        get_category_name(element) or "Element",
        element_id_text(safe_attr(element, "Id", None)),
    )


def get_element_bbox(element, view=None):
    if element is None:
        return None
    for candidate_view in (view, None):
        try:
            bbox = element.get_BoundingBox(candidate_view)
            if bbox is not None:
                return bbox
        except Exception:
            pass
    return None


def bboxes_intersect(first, second, tolerance=0.01):
    if first is None or second is None:
        return False
    try:
        return not (
            first.Max.X < second.Min.X - tolerance or first.Min.X > second.Max.X + tolerance or
            first.Max.Y < second.Min.Y - tolerance or first.Min.Y > second.Max.Y + tolerance or
            first.Max.Z < second.Min.Z - tolerance or first.Min.Z > second.Max.Z + tolerance
        )
    except Exception:
        return False


def is_generic_form_element(element):
    try:
        return isinstance(element, DB.GenericForm)
    except Exception:
        return False


def is_material_scan_candidate(project_doc, element, allow_parameter_probe=False):
    if element is None:
        return False
    if is_model_element(element):
        return True
    if is_in_place_form_element(element):
        return True
    if allow_parameter_probe:
        try:
            if iter_material_parameters(project_doc, element):
                return True
        except Exception:
            pass
        try:
            if material_ids_from_element(element, False) or material_ids_from_element(element, True):
                return True
        except Exception:
            pass
    return False


def get_selected_material_scan_elements(project_doc):
    elements = []
    if uidoc is None:
        return elements
    try:
        selected_ids = list(uidoc.Selection.GetElementIds())
    except Exception:
        selected_ids = []
    for element_id in selected_ids:
        try:
            element = project_doc.GetElement(element_id)
        except Exception:
            element = None
        if not is_material_scan_candidate(project_doc, element, allow_parameter_probe=True):
            continue
        elements.append(element)
    return elements


def merge_elements_by_id(primary_elements, extra_elements):
    elements = []
    seen = set()
    for element in list(primary_elements or []) + list(extra_elements or []):
        element_id = safe_attr(element, "Id", None)
        key = element_id_value(element_id)
        if key is None or key in seen:
            continue
        seen.add(key)
        elements.append(element)
    return elements


def collect_project_generic_forms(project_doc, view):
    forms_by_id = {}

    def add_form(element):
        if element is None or not is_generic_form_element(element):
            return
        element_id = safe_attr(element, "Id", None)
        key = element_id_value(element_id)
        if key is None or key in forms_by_id:
            return
        forms_by_id[key] = element

    form_class_names = (
        "GenericForm",
        "FreeFormElement",
        "Form",
        "Extrusion",
        "Blend",
        "Sweep",
        "SweptBlend",
        "Revolution",
    )
    form_classes = []
    for class_name in form_class_names:
        form_class = safe_attr(DB, class_name, None)
        if form_class is not None and form_class not in form_classes:
            form_classes.append(form_class)

    for form_class in form_classes:
        for use_view in (True, False):
            try:
                if use_view:
                    collector = DB.FilteredElementCollector(project_doc, view.Id)
                else:
                    collector = DB.FilteredElementCollector(project_doc)
                collector = collector.OfClass(form_class).WhereElementIsNotElementType()
                for element in collector.ToElements():
                    add_form(element)
            except Exception:
                pass

    if not forms_by_id:
        try:
            collector = DB.FilteredElementCollector(project_doc).WhereElementIsNotElementType()
            for element in collector.ToElements():
                add_form(element)
        except Exception:
            pass

    return list(forms_by_id.values())


def get_visible_model_elements(project_doc, view):
    elements = []
    try:
        collector = DB.FilteredElementCollector(project_doc, view.Id).WhereElementIsNotElementType()
    except Exception as err:
        raise Exception("Could not collect elements visible in the active 3D view: %s" % to_text(err))

    for element in collector.ToElements():
        if not is_material_scan_candidate(project_doc, element):
            continue
        if not is_visible_in_view(element, view):
            continue
        elements.append(element)

    return sorted(
        elements,
        key=lambda element: (
            (get_category_name(element) or "").lower(),
            element_id_value(safe_attr(element, "Id", None)) or 0,
        ),
    )


def material_ids_from_element(element, include_paint):
    values = []
    if element is None:
        return values
    try:
        material_ids = element.GetMaterialIds(include_paint)
        for material_id in material_ids:
            value = element_id_value(material_id)
            if value is not None and value > 0 and value not in values:
                values.append(value)
    except Exception:
        pass
    return sorted(values)


def get_geometry_options(view):
    options = DB.Options()
    try:
        options.ComputeReferences = True
    except Exception:
        pass
    try:
        options.IncludeNonVisibleObjects = False
    except Exception:
        pass
    try:
        options.View = view
    except Exception:
        try:
            options.DetailLevel = DB.ViewDetailLevel.Fine
        except Exception:
            pass
    return options


def get_painted_material_id(project_doc, element, face):
    if project_doc is None or element is None or face is None:
        return None
    element_id = safe_attr(element, "Id", None)
    for method_name in ("GetPaintedMaterial", "GetPaintedMaterialId"):
        method = safe_attr(project_doc, method_name, None)
        if method is None:
            continue
        try:
            material_id = method(element_id, face)
            if is_valid_material_id(project_doc, material_id):
                return material_id
        except Exception:
            pass
    return None


def collect_painted_face_targets(project_doc, view, element):
    targets_by_value = {}
    counter = [0]

    def add_face(face):
        material_id = get_painted_material_id(project_doc, element, face)
        if not is_valid_material_id(project_doc, material_id):
            return
        counter[0] += 1
        value = element_id_value(material_id)
        targets_by_value.setdefault(value, []).append(MaterialTarget(
            ROUTE_PAINT,
            element_id=safe_attr(element, "Id", None),
            face=face,
            material_id=material_id,
            source="Painted face on element %s" % element_id_text(safe_attr(element, "Id", None)),
            key_extra=str(counter[0]),
            editable=True,
        ))

    def visit_geometry(geometry_element, depth):
        if geometry_element is None or depth > 8:
            return
        try:
            iterator = iter(geometry_element)
        except Exception:
            return
        for geometry_object in iterator:
            if geometry_object is None:
                continue
            try:
                if isinstance(geometry_object, DB.Solid):
                    faces = safe_attr(geometry_object, "Faces", None)
                    if faces is not None:
                        for face in faces:
                            add_face(face)
                    continue
            except Exception:
                pass
            try:
                if isinstance(geometry_object, DB.GeometryInstance):
                    try:
                        visit_geometry(geometry_object.GetInstanceGeometry(), depth + 1)
                    except Exception:
                        pass
                    continue
            except Exception:
                pass

    try:
        geometry = element.get_Geometry(get_geometry_options(view))
        visit_geometry(geometry, 0)
    except Exception:
        pass

    return targets_by_value


def compound_layer_function_name(layer):
    try:
        return to_text(layer.Function)
    except Exception:
        return "Layer"


def is_finish_layer(layer):
    function_name = compound_layer_function_name(layer).lower()
    return "finish" in function_name


def is_host_finish_review_element(element):
    tab_key = tab_key_for_element(element)
    return tab_key in (TAB_WALLS, TAB_CEILINGS, TAB_FLOORS)


def get_compound_layers(project_doc, element):
    type_element = get_type_element(project_doc, element)
    if type_element is None:
        return []
    get_compound = safe_attr(type_element, "GetCompoundStructure", None)
    if get_compound is None:
        return []
    try:
        compound = get_compound()
    except Exception:
        return []
    if compound is None:
        return []
    try:
        layers = list(compound.GetLayers())
    except Exception:
        return []
    results = []
    for index, layer in enumerate(layers):
        if is_host_finish_review_element(element) and not is_finish_layer(layer):
            continue
        try:
            material_id = compound.GetMaterialId(index)
        except Exception:
            material_id = safe_attr(layer, "MaterialId", DB.ElementId.InvalidElementId)
        results.append({
            "type_element": type_element,
            "layer_index": index,
            "layer": layer,
            "material_id": material_id,
            "source": "Layer %s: %s" % (index + 1, compound_layer_function_name(layer)),
        })
    return results


def category_material_id(element):
    try:
        category = element.Category
        material = category.Material if category is not None else None
        if isinstance(material, DB.Material):
            return material.Id
    except Exception:
        pass
    return None


def category_name(category):
    try:
        value = category.Name
        if value:
            return to_text(value)
    except Exception:
        pass
    return ""


def category_id_text(category):
    return element_id_text(safe_attr(category, "Id", None))


def category_parent(category):
    try:
        return category.Parent
    except Exception:
        return None


def is_subcategory_of(category, parent_category):
    parent = category_parent(category)
    if parent is None or parent_category is None:
        return False
    return element_id_text(safe_attr(parent, "Id", None)) == element_id_text(safe_attr(parent_category, "Id", None))


def category_material_element_id(category):
    try:
        material = category.Material
        if isinstance(material, DB.Material):
            return material.Id
    except Exception:
        pass
    return None


def form_subcategory(form_element):
    try:
        return form_element.Subcategory
    except Exception:
        return None


def graphics_style_category(project_doc, graphics_style_id):
    if not graphics_style_id or is_by_category_id(graphics_style_id):
        return None
    try:
        graphics_style = project_doc.GetElement(graphics_style_id)
    except Exception:
        graphics_style = None
    try:
        if isinstance(graphics_style, DB.GraphicsStyle):
            return graphics_style.GraphicsStyleCategory
    except Exception:
        pass
    return None


def category_by_id(project_doc, category_id_text_value):
    wanted = to_text(category_id_text_value).strip()
    if not wanted:
        return None

    def check_category(category):
        if category is None:
            return None
        if category_id_text(category) == wanted:
            return category
        try:
            for subcategory in category.SubCategories:
                found = check_category(subcategory)
                if found is not None:
                    return found
        except Exception:
            pass
        return None

    try:
        for category in project_doc.Settings.Categories:
            found = check_category(category)
            if found is not None:
                return found
    except Exception:
        pass
    return None


def row_key(tab_key, element_group, category, material_source, material_id, edit_scope, identity):
    return "|".join([
        tab_key,
        element_group,
        category,
        material_source,
        material_key(material_id),
        edit_scope,
        identity,
    ])


def get_or_create_row(rows_by_key, tab_key, element_group, category, material_source, material_id, edit_scope, identity):
    key = row_key(tab_key, element_group, category, material_source, material_id, edit_scope, identity)
    if key not in rows_by_key:
        rows_by_key[key] = VisibleMaterialRow(
            tab_key,
            element_group,
            category,
            material_source,
            material_id,
            edit_scope,
            key,
        )
    return rows_by_key[key]


def add_report_only_material(rows_by_key, project_doc, element, material_id, source_name, identity, exposure_cache=None, notes=None):
    tab_key = tab_key_for_element(element)
    category = get_category_name(element) or "No Category"
    element_group = element_group_name(project_doc, element)
    row = get_or_create_row(
        rows_by_key,
        tab_key,
        element_group,
        category,
        source_name,
        material_id,
        "Report only",
        identity,
    )
    row.add_occurrence(element, element_example_text(project_doc, element))
    if is_model_in_place_review_only_element(element):
        row.Status = IN_PLACE_REPORT_STATUS
        row.Exposure = EXPOSURE_MANUAL_IN_PLACE
    elif source_name == "Category Material":
        row.Status = CATEGORY_REPORT_STATUS
        row.Exposure = EXPOSURE_MANUAL_CATEGORY
    else:
        row.Status = REPORT_ONLY_STATUS
        if exposure_cache is None:
            exposure_cache = {}
        if notes is None:
            notes = []
        row.Exposure = diagnose_family_material_exposure(project_doc, element, material_id, exposure_cache, notes)
    return row


def add_model_in_place_manual_rows(rows_by_key, project_doc, view, element):
    represented_values = set()
    manual_note = (
        "Model-in-place materials are review-only in this tool. "
        "Select the element and use Edit In-Place to change the material manually."
    )

    def add_manual_material(material_id, source_name, identity_prefix):
        value = element_id_value(material_id)
        if value is None or value <= 0:
            return
        if not is_valid_material_id(project_doc, material_id):
            return
        row = add_report_only_material(
            rows_by_key,
            project_doc,
            element,
            material_id,
            source_name,
            "%s|%s" % (identity_prefix, value),
        )
        row.add_diagnostic(manual_note)
        represented_values.add(value)

    try:
        painted_values = sorted(collect_painted_face_targets(project_doc, view, element).keys())
    except Exception:
        painted_values = []
    for material_value in painted_values:
        add_manual_material(
            DB.ElementId(material_value),
            "Painted Surface (priority)",
            "manual_model_in_place_paint",
        )

    try:
        model_values = sorted(material_ids_from_element(element, False))
    except Exception:
        model_values = []
    for material_value in model_values:
        if material_value in represented_values:
            continue
        add_manual_material(
            DB.ElementId(material_value),
            "Element Material",
            "manual_model_in_place_element",
        )

    if not represented_values:
        material_id = category_material_id(element)
        if material_id is not None and is_valid_material_id(project_doc, material_id):
            add_manual_material(
                material_id,
                "Category Material",
                "manual_model_in_place_category",
            )

    return represented_values


def add_painted_rows(rows_by_key, project_doc, view, element, paint_values):
    if not paint_values:
        return set()

    tab_key = tab_key_for_element(element)
    category = get_category_name(element) or "No Category"
    element_group = element_group_name(project_doc, element)

    for material_value in paint_values:
        material_id = DB.ElementId(material_value)
        row = get_or_create_row(
            rows_by_key,
            tab_key,
            element_group,
            category,
            "Painted Surface (priority)",
            material_id,
            "Painted face",
            "paint|%s" % material_value,
        )
        row.add_occurrence(element, element_example_text(project_doc, element))
        row.add_target(MaterialTarget(
            ROUTE_PAINT,
            element_id=safe_attr(element, "Id", None),
            material_id=material_id,
            source="Painted material on element %s" % element_id_text(safe_attr(element, "Id", None)),
            key_extra=str(material_value),
            editable=True,
        ))

    return set(paint_values)


def get_subelement_identity(project_doc, subelement):
    value = to_text(safe_attr(subelement, "UniqueId", "") or "")
    if value:
        return value
    try:
        reference = subelement.GetReference()
        if reference is not None:
            value = reference.ConvertToStableRepresentation(project_doc)
            if value:
                return to_text(value)
    except Exception:
        pass
    return ""


def get_subelements(element):
    try:
        return list(element.GetSubelements())
    except Exception:
        return []


def get_dependent_elements(project_doc, element):
    elements = []
    seen = set()
    child_ids = []
    try:
        child_ids.extend(list(element.GetDependentElements(None)))
    except Exception:
        pass
    try:
        child_ids.extend(list(element.GetSubComponentIds()))
    except Exception:
        pass
    for dependent_id in child_ids:
        value = element_id_value(dependent_id)
        if value is None or value in seen:
            continue
        seen.add(value)
        try:
            dependent = project_doc.GetElement(dependent_id)
        except Exception:
            dependent = None
        if dependent is not None:
            elements.append(dependent)
    return elements


def add_in_place_object_parameter_rows(rows_by_key, project_doc, element):
    material_values = set()
    if not is_in_place_family_instance(element):
        return material_values

    tab_key = tab_key_for_element(element)
    category = get_category_name(element) or "No Category"
    element_group = element_group_name(project_doc, element)
    parent_id = safe_attr(element, "Id", None)
    candidates = get_dependent_elements(project_doc, element)
    seen_targets = set()

    for candidate in candidates:
        candidate_id = safe_attr(candidate, "Id", None)
        candidate_id_text = element_id_text(candidate_id)
        if not candidate_id_text:
            continue
        for parameter in iter_material_parameters(project_doc, candidate):
            if is_parameter_read_only(parameter):
                continue
            name = parameter_name(parameter)
            if not name:
                continue
            material_id = get_parameter_material_id(parameter)
            material_value = element_id_value(material_id)
            if material_value is not None and material_value > 0:
                material_values.add(material_value)
            parameter_id = safe_attr(parameter, "Id", None)
            target_key = "%s|%s|%s" % (
                candidate_id_text,
                name,
                element_id_text(parameter_id),
            )
            if target_key in seen_targets:
                continue
            seen_targets.add(target_key)
            row = get_or_create_row(
                rows_by_key,
                tab_key,
                element_group,
                category,
                "Object Parameter: %s" % name,
                material_id,
                "Object material",
                "in_place_param|%s|%s|%s" % (
                    element_id_text(parent_id),
                    name,
                    material_key(material_id),
                ),
            )
            row.Status = ""
            row.Exposure = EXPOSURE_OBJECT_PARAMETER_MATCH
            row.add_occurrence(element, element_example_text(project_doc, element))
            row.add_target(MaterialTarget(
                ROUTE_IN_PLACE_PARAM,
                element_id=candidate_id,
                parameter_name=name,
                parameter_id=parameter_id,
                material_id=material_id,
                source="In-place object %s / %s" % (candidate_id_text, name),
                key_extra="%s:%s" % (candidate_id_text, element_id_text(parameter_id)),
                editable=True,
            ))

    return material_values


def add_in_place_geometry_parameter_rows(rows_by_key, project_doc, view, element):
    material_values = set()
    if not is_in_place_family_instance(element):
        return material_values

    tab_key = tab_key_for_element(element)
    category = get_category_name(element) or "No Category"
    element_group = element_group_name(project_doc, element)
    parent_id = safe_attr(element, "Id", None)
    seen_targets = set()
    seen_subelements = set()

    def add_candidate(candidate, face_material_id):
        if candidate is None:
            return
        candidate_id = safe_attr(candidate, "Id", None)
        candidate_id_text = element_id_text(candidate_id)
        if not candidate_id_text:
            return
        face_material_value = element_id_value(face_material_id)
        if face_material_value is None or face_material_value <= 0:
            return
        for parameter in iter_material_parameters(project_doc, candidate):
            if is_parameter_read_only(parameter):
                continue
            parameter_material_id = get_parameter_material_id(parameter)
            if not element_id_equal(parameter_material_id, face_material_id):
                continue
            name = parameter_name(parameter)
            if not name:
                continue
            parameter_id = safe_attr(parameter, "Id", None)
            target_key = "%s|%s|%s|%s" % (
                candidate_id_text,
                name,
                element_id_text(parameter_id),
                material_key(face_material_id),
            )
            if target_key in seen_targets:
                continue
            seen_targets.add(target_key)
            material_values.add(face_material_value)
            row = get_or_create_row(
                rows_by_key,
                tab_key,
                element_group,
                category,
                "Object Parameter: %s" % name,
                face_material_id,
                "Object material",
                "in_place_geometry_param|%s|%s|%s" % (
                    element_id_text(parent_id),
                    name,
                    material_key(face_material_id),
                ),
            )
            row.Status = ""
            row.Exposure = EXPOSURE_OBJECT_PARAMETER_MATCH
            row.add_occurrence(element, element_example_text(project_doc, element))
            row.add_target(MaterialTarget(
                ROUTE_IN_PLACE_PARAM,
                element_id=candidate_id,
                parameter_name=name,
                parameter_id=parameter_id,
                material_id=face_material_id,
                source="In-place generated object %s / %s" % (candidate_id_text, name),
                key_extra="%s:%s:%s" % (
                    candidate_id_text,
                    element_id_text(parameter_id),
                    material_key(face_material_id),
                ),
                editable=True,
            ))

    def add_subelement_candidate(subelement, face_material_id):
        if subelement is None:
            return
        subelement_key = get_subelement_identity(project_doc, subelement)
        if not subelement_key:
            return
        face_material_value = element_id_value(face_material_id)
        if face_material_value is None or face_material_value <= 0:
            return
        parameter_ids = []
        seen_parameter_ids = set()

        def add_parameter_id(parameter_id):
            key = element_id_text(parameter_id)
            if not key or key in seen_parameter_ids:
                return
            seen_parameter_ids.add(key)
            parameter_ids.append(parameter_id)

        try:
            for parameter_id in list(subelement.GetAllParameters()):
                add_parameter_id(parameter_id)
        except Exception:
            pass
        built_in = safe_attr(DB, "BuiltInParameter", None)
        for built_in_name in material_builtin_parameter_names():
            built_in_value = safe_attr(built_in, built_in_name, None)
            if built_in_value is None:
                continue
            try:
                parameter_id = DB.ElementId(built_in_value)
            except Exception:
                continue
            try:
                if bool(subelement.HasParameter(parameter_id)):
                    add_parameter_id(parameter_id)
            except Exception:
                pass
        if not parameter_ids:
            return
        for parameter_id in parameter_ids:
            try:
                parameter_value = subelement.GetParameterValue(parameter_id)
            except Exception:
                continue
            material_id = element_id_from_parameter_value(parameter_value)
            if not element_id_equal(material_id, face_material_id):
                continue
            if not is_valid_material_id(project_doc, material_id):
                continue
            try:
                if not bool(subelement.IsParameterModifiable(parameter_id)):
                    continue
            except Exception:
                continue
            parameter_label = parameter_name_from_id(project_doc, parameter_id)
            target_key = "subelement|%s|%s|%s" % (
                subelement_key,
                element_id_text(parameter_id),
                material_key(material_id),
            )
            if target_key in seen_subelements:
                continue
            seen_subelements.add(target_key)
            material_values.add(face_material_value)
            row = get_or_create_row(
                rows_by_key,
                tab_key,
                element_group,
                category,
                "Object Parameter: %s" % parameter_label,
                material_id,
                "Object material",
                "face_subelement_param|%s|%s|%s|%s" % (
                    element_id_text(parent_id),
                    subelement_key,
                    element_id_text(parameter_id),
                    material_key(material_id),
                ),
            )
            row.Status = ""
            row.Exposure = EXPOSURE_OBJECT_PARAMETER_MATCH
            row.add_occurrence(element, element_example_text(project_doc, element))
            row.add_target(MaterialTarget(
                ROUTE_SUBELEMENT_PARAM,
                element_id=parent_id,
                parameter_name=parameter_label,
                parameter_id=parameter_id,
                subelement_uid=subelement_key,
                material_id=material_id,
                source="In-place face object %s / %s" % (
                    subelement_key,
                    parameter_label,
                ),
                key_extra="%s:%s:%s" % (
                    subelement_key,
                    element_id_text(parameter_id),
                    material_key(material_id),
                ),
                editable=True,
            ))

    def add_face(face):
        try:
            face_material_id = face.MaterialElementId
        except Exception:
            face_material_id = None
        if not is_valid_material_id(project_doc, face_material_id):
            return
        try:
            reference = safe_attr(face, "Reference", None)
            if reference is not None:
                try:
                    subelement = DB.Subelement.Create(project_doc, reference)
                except Exception:
                    subelement = None
                add_subelement_candidate(subelement, face_material_id)
                try:
                    candidate = project_doc.GetElement(reference.ElementId)
                except Exception:
                    candidate = None
                add_candidate(candidate, face_material_id)
        except Exception:
            pass
        try:
            generating_ids = list(element.GetGeneratingElementIds(face))
        except Exception:
            generating_ids = []
        for generating_id in generating_ids:
            try:
                candidate = project_doc.GetElement(generating_id)
            except Exception:
                candidate = None
            add_candidate(candidate, face_material_id)

    def visit_geometry(geometry_element, depth):
        if geometry_element is None or depth > 8:
            return
        try:
            iterator = iter(geometry_element)
        except Exception:
            return
        for geometry_object in iterator:
            if geometry_object is None:
                continue
            try:
                if isinstance(geometry_object, DB.Solid):
                    faces = safe_attr(geometry_object, "Faces", None)
                    if faces is not None:
                        for face in faces:
                            add_face(face)
                    continue
            except Exception:
                pass
            try:
                if isinstance(geometry_object, DB.GeometryInstance):
                    try:
                        visit_geometry(geometry_object.GetSymbolGeometry(), depth + 1)
                    except Exception:
                        pass
                    try:
                        visit_geometry(geometry_object.GetInstanceGeometry(), depth + 1)
                    except Exception:
                        pass
                    continue
            except Exception:
                pass

    try:
        geometry = element.get_Geometry(get_geometry_options(view))
        visit_geometry(geometry, 0)
    except Exception:
        pass

    return material_values


def add_subelement_material_rows(rows_by_key, project_doc, element):
    material_values = set()
    if not is_in_place_family_instance(element):
        return material_values

    tab_key = tab_key_for_element(element)
    category = get_category_name(element) or "No Category"
    element_group = element_group_name(project_doc, element)

    for subelement in get_subelements(element):
        subelement_key = get_subelement_identity(project_doc, subelement)
        if not subelement_key:
            continue
        try:
            parameter_ids = list(subelement.GetAllParameters())
        except Exception:
            continue
        for parameter_id in parameter_ids:
            try:
                parameter_value = subelement.GetParameterValue(parameter_id)
            except Exception:
                continue
            material_id = element_id_from_parameter_value(parameter_value)
            material_value = element_id_value(material_id)
            if material_value is None or material_value <= 0:
                continue
            if not is_valid_material_id(project_doc, material_id):
                continue
            try:
                if not bool(subelement.IsParameterModifiable(parameter_id)):
                    continue
            except Exception:
                continue
            parameter_label = parameter_name_from_id(project_doc, parameter_id)
            material_values.add(material_value)
            row = get_or_create_row(
                rows_by_key,
                tab_key,
                element_group,
                category,
                "Object Parameter: %s" % parameter_label,
                material_id,
                "Object material",
                "subelement_param|%s|%s|%s" % (
                    element_id_text(safe_attr(element, "Id", None)),
                    subelement_key,
                    element_id_text(parameter_id),
                ),
            )
            row.Status = ""
            row.Exposure = EXPOSURE_OBJECT_PARAMETER_MATCH
            row.add_occurrence(element, element_example_text(project_doc, element))
            row.add_target(MaterialTarget(
                ROUTE_SUBELEMENT_PARAM,
                element_id=safe_attr(element, "Id", None),
                parameter_name=parameter_label,
                parameter_id=parameter_id,
                subelement_uid=subelement_key,
                material_id=material_id,
                source="In-place object %s / %s" % (
                    subelement_key,
                    parameter_label,
                ),
                key_extra="%s:%s" % (subelement_key, element_id_text(parameter_id)),
                editable=True,
            ))

    return material_values


def add_compound_layer_rows(rows_by_key, project_doc, element):
    material_values = set()
    tab_key = tab_key_for_element(element)
    if tab_key not in (TAB_WALLS, TAB_CEILINGS, TAB_FLOORS):
        return material_values

    category = get_category_name(element) or "No Category"
    element_group = element_group_name(project_doc, element)

    for layer_info in get_compound_layers(project_doc, element):
        type_element = layer_info["type_element"]
        layer_index = layer_info["layer_index"]
        material_id = layer_info["material_id"]
        source = layer_info["source"]
        value = element_id_value(material_id)
        if value is not None and value > 0:
            material_values.add(value)

        row = get_or_create_row(
            rows_by_key,
            tab_key,
            element_group,
            category,
            source,
            material_id,
            "Type layer",
            "layer|%s|%s" % (element_id_text(type_element.Id), layer_index),
        )
        row.add_occurrence(element, element_example_text(project_doc, element))
        row.add_target(MaterialTarget(
            ROUTE_COMPOUND_LAYER,
            type_id=type_element.Id,
            layer_index=layer_index,
            source="%s / %s" % (safe_name(type_element, element_id_text(type_element.Id)), source),
            key_extra="%s:%s" % (element_id_text(type_element.Id), layer_index),
            editable=True,
        ))

    return material_values


def add_parameter_rows(rows_by_key, project_doc, element, active_family_cache, notes):
    material_values = set()
    tab_key = tab_key_for_element(element)
    category = get_category_name(element) or "No Category"
    element_group = element_group_name(project_doc, element)
    active_family_keys = get_loadable_active_parameter_cache(project_doc, element, active_family_cache, notes)

    for parameter in iter_material_parameters(project_doc, element):
        name = parameter_name(parameter)
        if active_family_keys is not None and ("I", name) not in active_family_keys:
            continue
        material_id = get_parameter_material_id(parameter)
        value = element_id_value(material_id)
        if value is not None and value > 0:
            material_values.add(value)
        source_label = "Instance Parameter: %s" % name
        scope_label = "Instance parameter"
        if is_in_place_family_instance(element) or is_in_place_form_element(element):
            source_label = "Object Parameter: %s" % name
            scope_label = "Object material"
        row = get_or_create_row(
            rows_by_key,
            tab_key,
            element_group,
            category,
            source_label,
            material_id,
            scope_label,
            "instance_param|%s|%s|%s" % (
                element_group,
                name,
                material_key(material_id),
            ),
        )
        if is_in_place_family_instance(element) or is_in_place_form_element(element):
            row.Exposure = EXPOSURE_OBJECT_PARAMETER_MATCH
        row.add_occurrence(element, element_example_text(project_doc, element))
        row.add_target(MaterialTarget(
            ROUTE_INSTANCE_PARAM,
            element_id=safe_attr(element, "Id", None),
            parameter_name=name,
            source="Element %s" % element_id_text(safe_attr(element, "Id", None)),
            editable=True,
        ))

    type_element = get_type_element(project_doc, element)
    for parameter in iter_material_parameters(project_doc, type_element):
        name = parameter_name(parameter)
        if active_family_keys is not None and ("T", name) not in active_family_keys:
            continue
        material_id = get_parameter_material_id(parameter)
        value = element_id_value(material_id)
        if value is not None and value > 0:
            material_values.add(value)
        row = get_or_create_row(
            rows_by_key,
            tab_key,
            element_group,
            category,
            "Type Parameter: %s" % name,
            material_id,
            "Type parameter",
            "type_param|%s|%s" % (
                element_id_text(safe_attr(type_element, "Id", None)),
                name,
            ),
        )
        row.add_occurrence(element, element_example_text(project_doc, element))
        row.add_target(MaterialTarget(
            ROUTE_TYPE_PARAM,
            type_id=safe_attr(type_element, "Id", None),
            parameter_name=name,
            source="Type %s" % safe_name(type_element, element_id_text(safe_attr(type_element, "Id", None))),
            editable=True,
        ))

    return material_values


def add_matching_project_parameter_rows(rows_by_key, project_doc, element, material_values):
    matched_values = set()
    if not material_values:
        return matched_values

    tab_key = tab_key_for_element(element)
    category = get_category_name(element) or "No Category"
    element_group = element_group_name(project_doc, element)
    positive_values = set([value for value in material_values if value is not None and value > 0])

    for parameter in iter_material_parameters(project_doc, element):
        material_id = get_parameter_material_id(parameter)
        value = element_id_value(material_id)
        if value not in positive_values:
            continue
        if is_parameter_read_only(parameter):
            continue
        name = parameter_name(parameter)
        source_label = "Instance Parameter: %s" % name
        scope_label = "Instance parameter"
        if is_in_place_family_instance(element) or is_in_place_form_element(element):
            source_label = "Object Parameter: %s" % name
            scope_label = "Object material"
        row = get_or_create_row(
            rows_by_key,
            tab_key,
            element_group,
            category,
            source_label,
            material_id,
            scope_label,
            "instance_param|%s|%s|%s" % (
                element_group,
                name,
                material_key(material_id),
            ),
        )
        row.Status = ""
        if is_in_place_family_instance(element) or is_in_place_form_element(element):
            row.Exposure = EXPOSURE_OBJECT_PARAMETER_MATCH
        else:
            row.Exposure = EXPOSURE_PROJECT_PARAMETER_MATCH
        row.add_occurrence(element, element_example_text(project_doc, element))
        row.add_target(MaterialTarget(
            ROUTE_INSTANCE_PARAM,
            element_id=safe_attr(element, "Id", None),
            parameter_name=name,
            source="Element %s" % element_id_text(safe_attr(element, "Id", None)),
            editable=True,
        ))
        matched_values.add(value)

    type_element = get_type_element(project_doc, element)
    for parameter in iter_material_parameters(project_doc, type_element):
        material_id = get_parameter_material_id(parameter)
        value = element_id_value(material_id)
        if value not in positive_values:
            continue
        if is_parameter_read_only(parameter):
            continue
        name = parameter_name(parameter)
        row = get_or_create_row(
            rows_by_key,
            tab_key,
            element_group,
            category,
            "Type Parameter: %s" % name,
            material_id,
            "Type parameter",
            "type_param|%s|%s" % (
                element_id_text(safe_attr(type_element, "Id", None)),
                name,
            ),
        )
        row.Status = ""
        row.Exposure = EXPOSURE_PROJECT_PARAMETER_MATCH
        row.add_occurrence(element, element_example_text(project_doc, element))
        row.add_target(MaterialTarget(
            ROUTE_TYPE_PARAM,
            type_id=safe_attr(type_element, "Id", None),
            parameter_name=name,
            source="Type %s" % safe_name(type_element, element_id_text(safe_attr(type_element, "Id", None))),
            editable=True,
        ))
        matched_values.add(value)

    return matched_values


def add_in_place_geometry_object_style_rows(rows_by_key, project_doc, view, element, material_values, diagnostics_by_material=None):
    matched_values = set()
    if not is_in_place_family_instance(element):
        return matched_values
    if diagnostics_by_material is None:
        diagnostics_by_material = {}

    material_value_set = set([
        value for value in material_values
        if value is not None and value > 0
    ])
    if not material_value_set:
        return matched_values

    def add_diagnostic(material_value, message):
        message = to_text(message).strip()
        if not message:
            return
        existing = diagnostics_by_material.setdefault(material_value, [])
        if message not in existing:
            existing.append(message)

    tab_key = tab_key_for_element(element)
    parent_category = get_category_name(element) or "No Category"
    parent_category_object = safe_attr(element, "Category", None)
    parent_group = element_group_name(project_doc, element)
    parent_id = safe_attr(element, "Id", None)
    seen_targets = set()
    counters = {
        "geometry_objects": 0,
        "solids": 0,
        "faces": 0,
        "material_geometry": {},
        "missing_style": {},
        "missing_style_category": {},
        "style_category_no_material": {},
        "style_category_material_mismatch": {},
        "style_pairs": 0,
        "unsafe_matches": {},
        "style_matches": {},
    }

    def add_style_target(graphics_style_id, face_material_id):
        material_value = element_id_value(face_material_id)
        if material_value not in material_value_set:
            return
        counters["material_geometry"][material_value] = counters["material_geometry"].get(material_value, 0) + 1
        if graphics_style_id is None or is_by_category_id(graphics_style_id):
            counters["missing_style"][material_value] = counters["missing_style"].get(material_value, 0) + 1
            return
        subcategory = graphics_style_category(project_doc, graphics_style_id)
        if subcategory is None:
            counters["missing_style_category"][material_value] = counters["missing_style_category"].get(material_value, 0) + 1
            return
        subcategory_material_id = category_material_element_id(subcategory)
        if not is_valid_material_id(project_doc, subcategory_material_id):
            counters["style_category_no_material"][material_value] = counters["style_category_no_material"].get(material_value, 0) + 1
            return
        if not element_id_equal(subcategory_material_id, face_material_id):
            counters["style_category_material_mismatch"][material_value] = counters["style_category_material_mismatch"].get(material_value, 0) + 1
            return
        counters["style_pairs"] += 1
        counters["style_matches"][material_value] = counters["style_matches"].get(material_value, 0) + 1
        subcategory_label = category_name(subcategory) or category_id_text(subcategory) or "Subcategory"
        if not is_subcategory_of(subcategory, parent_category_object):
            counters["unsafe_matches"][material_value] = counters["unsafe_matches"].get(material_value, 0) + 1
            return
        target_key = "geometry_style|%s|%s|%s" % (
            element_id_text(graphics_style_id),
            category_id_text(subcategory),
            material_key(subcategory_material_id),
        )
        if target_key in seen_targets:
            return
        seen_targets.add(target_key)
        matched_values.add(material_value)
        row = get_or_create_row(
            rows_by_key,
            tab_key,
            parent_group,
            parent_category,
            "Object Style/Subcategory: %s" % subcategory_label,
            subcategory_material_id,
            "Object style",
            "in_place_geometry_object_style|%s|%s|%s|%s" % (
                element_id_text(parent_id),
                element_id_text(graphics_style_id),
                category_id_text(subcategory),
                material_key(subcategory_material_id),
            ),
        )
        row.Status = ""
        row.Exposure = EXPOSURE_OBJECT_STYLE_MATCH
        row.add_occurrence(element, element_example_text(project_doc, element))
        row.add_target(MaterialTarget(
            ROUTE_OBJECT_STYLE_MATERIAL,
            element_id=graphics_style_id,
            parameter_name=subcategory_label,
            material_id=subcategory_material_id,
            source="Geometry style %s / subcategory %s" % (
                element_id_text(graphics_style_id),
                subcategory_label,
            ),
            key_extra=category_id_text(subcategory),
            editable=True,
        ))

    def add_geometry_object(geometry_object, fallback_material_id=None, fallback_graphics_style_id=None):
        if geometry_object is None:
            return
        counters["geometry_objects"] += 1
        material_id = safe_attr(geometry_object, "MaterialElementId", None)
        if not is_valid_material_id(project_doc, material_id):
            material_id = fallback_material_id
        if not is_valid_material_id(project_doc, material_id):
            return
        graphics_style_id = safe_attr(geometry_object, "GraphicsStyleId", None)
        if graphics_style_id is None or is_by_category_id(graphics_style_id):
            graphics_style_id = fallback_graphics_style_id
        add_style_target(graphics_style_id, material_id)

    def visit_geometry(geometry_element, depth):
        if geometry_element is None or depth > 8:
            return
        try:
            iterator = iter(geometry_element)
        except Exception:
            return
        for geometry_object in iterator:
            if geometry_object is None:
                continue
            try:
                if isinstance(geometry_object, DB.Solid):
                    counters["solids"] += 1
                    solid_style_id = safe_attr(geometry_object, "GraphicsStyleId", None)
                    add_geometry_object(geometry_object, fallback_graphics_style_id=solid_style_id)
                    faces = safe_attr(geometry_object, "Faces", None)
                    if faces is not None:
                        for face in faces:
                            counters["faces"] += 1
                            try:
                                face_material_id = face.MaterialElementId
                            except Exception:
                                face_material_id = None
                            add_geometry_object(face, fallback_material_id=face_material_id, fallback_graphics_style_id=solid_style_id)
                    continue
            except Exception:
                pass
            try:
                if isinstance(geometry_object, DB.GeometryInstance):
                    try:
                        visit_geometry(geometry_object.GetInstanceGeometry(), depth + 1)
                    except Exception:
                        pass
                    continue
            except Exception:
                pass
            add_geometry_object(geometry_object)

    try:
        geometry = element.get_Geometry(get_geometry_options(view))
        visit_geometry(geometry, 0)
    except Exception as err:
        for material_value in material_value_set:
            add_diagnostic(material_value, "Geometry style resolver failed: %s" % to_text(err))

    for material_value in sorted(list(material_value_set)):
        if material_value in matched_values:
            continue
        if counters["unsafe_matches"].get(material_value):
            add_diagnostic(
                material_value,
                "Geometry style resolver found %s matching category material(s), but they were not safe in-place subcategories." % counters["unsafe_matches"].get(material_value)
            )
        elif counters["style_matches"].get(material_value):
            add_diagnostic(
                material_value,
                "Geometry style resolver found matching object-style material, but could not convert it into an editable target."
            )
        else:
            pieces = [
                "Geometry style resolver inspected %s geometry objects, %s solids, and %s faces." % (
                    counters["geometry_objects"],
                    counters["solids"],
                    counters["faces"],
                )
            ]
            if counters["material_geometry"].get(material_value):
                pieces.append("%s geometry item(s) reported this material." % counters["material_geometry"].get(material_value))
            else:
                pieces.append("No traversed geometry item reported this material directly.")
            if counters["missing_style"].get(material_value):
                pieces.append("%s matching geometry item(s) had no graphics style id." % counters["missing_style"].get(material_value))
            if counters["missing_style_category"].get(material_value):
                pieces.append("%s matching graphics style id(s) had no category." % counters["missing_style_category"].get(material_value))
            if counters["style_category_no_material"].get(material_value):
                pieces.append("%s matching style categor(ies) had no project material." % counters["style_category_no_material"].get(material_value))
            if counters["style_category_material_mismatch"].get(material_value):
                pieces.append("%s style categor(ies) had a different material than the visible geometry." % counters["style_category_material_mismatch"].get(material_value))
            add_diagnostic(material_value, " ".join(pieces))

    return matched_values


def add_overlapping_in_place_form_rows(rows_by_key, project_doc, view, element, material_values, in_place_forms, diagnostics_by_material=None):
    matched_values = set()
    if not is_in_place_family_instance(element):
        return matched_values
    if diagnostics_by_material is None:
        diagnostics_by_material = {}

    material_value_set = set([
        value for value in material_values
        if value is not None and value > 0
    ])
    if not material_value_set:
        return matched_values

    def add_diagnostic(material_value, message):
        message = to_text(message).strip()
        if not message:
            return
        existing = diagnostics_by_material.setdefault(material_value, [])
        if message not in existing:
            existing.append(message)

    if not in_place_forms:
        for material_value in material_value_set:
            add_diagnostic(
                material_value,
                "Model-in-place resolver: Revit did not expose any internal GenericForm/object elements in the project document."
            )
        return matched_values

    parent_bbox = get_element_bbox(element, view)
    if parent_bbox is None:
        for material_value in material_value_set:
            add_diagnostic(
                material_value,
                "Model-in-place resolver: parent element had no bounding box, so internal forms could not be matched."
            )
        return matched_values

    parent_category = get_category_name(element) or "No Category"
    parent_category_object = safe_attr(element, "Category", None)
    parent_group = element_group_name(project_doc, element)
    parent_id = safe_attr(element, "Id", None)
    tab_key = tab_key_for_element(element)
    seen_targets = set()
    forms_seen = 0
    overlapping_forms = 0
    parameters_checked = 0
    read_only_parameter_matches = {}
    unsafe_category_matches = {}
    subcategory_matches = {}

    for form_element in in_place_forms:
        form_id = safe_attr(form_element, "Id", None)
        if element_id_equal(form_id, parent_id):
            continue
        forms_seen += 1
        form_bbox = get_element_bbox(form_element, view)
        if not bboxes_intersect(parent_bbox, form_bbox):
            continue
        overlapping_forms += 1

        for parameter in iter_material_parameters(project_doc, form_element):
            parameters_checked += 1
            material_id = get_parameter_material_id(parameter)
            material_value = element_id_value(material_id)
            if material_value not in material_value_set:
                continue
            if is_parameter_read_only(parameter):
                read_only_parameter_matches[material_value] = read_only_parameter_matches.get(material_value, 0) + 1
                continue
            name = parameter_name(parameter)
            if not name:
                continue
            parameter_id = safe_attr(parameter, "Id", None)
            target_key = "%s|%s|%s|%s" % (
                element_id_text(form_id),
                name,
                element_id_text(parameter_id),
                material_key(material_id),
            )
            if target_key in seen_targets:
                continue
            seen_targets.add(target_key)
            matched_values.add(material_value)
            row = get_or_create_row(
                rows_by_key,
                tab_key,
                parent_group,
                parent_category,
                "Object Parameter: %s" % name,
                material_id,
                "Object material",
                "in_place_form_param|%s|%s|%s|%s" % (
                    element_id_text(parent_id),
                    element_id_text(form_id),
                    element_id_text(parameter_id),
                    material_key(material_id),
                ),
            )
            row.Status = ""
            row.Exposure = EXPOSURE_OBJECT_PARAMETER_MATCH
            row.add_occurrence(element, element_example_text(project_doc, element))
            row.add_target(MaterialTarget(
                ROUTE_IN_PLACE_PARAM,
                element_id=form_id,
                parameter_name=name,
                parameter_id=parameter_id,
                material_id=material_id,
                source="In-place form %s / %s" % (
                    element_id_text(form_id),
                    name,
                ),
                key_extra="%s:%s:%s" % (
                    element_id_text(form_id),
                    element_id_text(parameter_id),
                    material_key(material_id),
                ),
                editable=True,
            ))

        subcategory = form_subcategory(form_element)
        subcategory_material_id = category_material_element_id(subcategory)
        subcategory_material_value = element_id_value(subcategory_material_id)
        if subcategory_material_value in material_value_set:
            subcategory_matches[subcategory_material_value] = subcategory_matches.get(subcategory_material_value, 0) + 1
            subcategory_label = category_name(subcategory) or category_id_text(subcategory) or "Subcategory"
            if not is_subcategory_of(subcategory, parent_category_object):
                unsafe_category_matches[subcategory_material_value] = unsafe_category_matches.get(subcategory_material_value, 0) + 1
                continue
            target_key = "object_style|%s|%s|%s" % (
                element_id_text(form_id),
                category_id_text(subcategory),
                material_key(subcategory_material_id),
            )
            if target_key in seen_targets:
                continue
            seen_targets.add(target_key)
            matched_values.add(subcategory_material_value)
            row = get_or_create_row(
                rows_by_key,
                tab_key,
                parent_group,
                parent_category,
                "Object Style/Subcategory: %s" % subcategory_label,
                subcategory_material_id,
                "Object style",
                "in_place_form_object_style|%s|%s|%s|%s" % (
                    element_id_text(parent_id),
                    element_id_text(form_id),
                    category_id_text(subcategory),
                    material_key(subcategory_material_id),
                ),
            )
            row.Status = ""
            row.Exposure = EXPOSURE_OBJECT_STYLE_MATCH
            row.add_occurrence(element, element_example_text(project_doc, element))
            row.add_target(MaterialTarget(
                ROUTE_OBJECT_STYLE_MATERIAL,
                element_id=form_id,
                parameter_name=subcategory_label,
                material_id=subcategory_material_id,
                source="In-place form %s / subcategory %s" % (
                    element_id_text(form_id),
                    subcategory_label,
                ),
                key_extra=category_id_text(subcategory),
                editable=True,
            ))

    for material_value in sorted(list(material_value_set)):
        if material_value in matched_values:
            continue
        pieces = [
            "Model-in-place resolver checked %s GenericForm/object candidates and %s overlapping forms." % (
                forms_seen,
                overlapping_forms,
            )
        ]
        if overlapping_forms:
            pieces.append("%s material parameters were inspected." % parameters_checked)
        if read_only_parameter_matches.get(material_value):
            pieces.append("%s matching material parameter(s) were read-only." % read_only_parameter_matches.get(material_value))
        if subcategory_matches.get(material_value):
            if unsafe_category_matches.get(material_value):
                pieces.append(
                    "%s matching subcategory/category material(s) were found, but not as a safe subcategory of the in-place family category." % unsafe_category_matches.get(material_value)
                )
            else:
                pieces.append("Matching subcategory material was found but could not be converted into an editable target.")
        if not overlapping_forms:
            pieces.append("No internal form bounding boxes overlapped the model-in-place instance.")
        if len(pieces) == 1:
            pieces.append("No writable object parameter or safe subcategory material owner matched this material.")
        add_diagnostic(material_value, " ".join(pieces))

    return matched_values


def scan_element(rows_by_key, project_doc, view, element, active_family_cache, notes, exposure_cache=None, in_place_forms=None):
    starting_row_count = len(rows_by_key)
    if is_model_in_place_review_only_element(element):
        add_model_in_place_manual_rows(rows_by_key, project_doc, view, element)
        return

    if is_host_finish_review_element(element):
        try:
            paint_values = sorted(collect_painted_face_targets(project_doc, view, element).keys())
        except Exception:
            paint_values = []
    else:
        paint_values = material_ids_from_element(element, True)
    paint_values_set = add_painted_rows(rows_by_key, project_doc, view, element, paint_values)

    if is_host_finish_review_element(element) and paint_values_set:
        compound_values = set()
    else:
        compound_values = add_compound_layer_rows(rows_by_key, project_doc, element)

    parameter_values = add_parameter_rows(rows_by_key, project_doc, element, active_family_cache, notes)
    in_place_object_values = add_in_place_object_parameter_rows(rows_by_key, project_doc, element)
    in_place_geometry_values = add_in_place_geometry_parameter_rows(rows_by_key, project_doc, view, element)
    subelement_values = add_subelement_material_rows(rows_by_key, project_doc, element)

    represented_values = set()
    represented_values.update(paint_values_set)
    represented_values.update(compound_values)
    represented_values.update(parameter_values)
    represented_values.update(in_place_object_values)
    represented_values.update(in_place_geometry_values)
    represented_values.update(subelement_values)

    model_values = material_ids_from_element(element, False)
    unrepresented_model_values = set([
        material_value for material_value in model_values
        if material_value not in represented_values
    ])
    in_place_diagnostics = {}
    in_place_style_values = add_in_place_geometry_object_style_rows(
        rows_by_key,
        project_doc,
        view,
        element,
        unrepresented_model_values,
        diagnostics_by_material=in_place_diagnostics,
    )
    represented_values.update(in_place_style_values)
    unrepresented_model_values = set([
        material_value for material_value in model_values
        if material_value not in represented_values
    ])
    in_place_form_values = add_overlapping_in_place_form_rows(
        rows_by_key,
        project_doc,
        view,
        element,
        unrepresented_model_values,
        in_place_forms,
        diagnostics_by_material=in_place_diagnostics,
    )
    represented_values.update(in_place_form_values)
    unrepresented_model_values = set([
        material_value for material_value in model_values
        if material_value not in represented_values
    ])
    matched_parameter_values = add_matching_project_parameter_rows(
        rows_by_key,
        project_doc,
        element,
        unrepresented_model_values,
    )
    represented_values.update(matched_parameter_values)

    for material_value in model_values:
        if material_value in represented_values:
            continue
        material_id = DB.ElementId(material_value)
        row = add_report_only_material(
            rows_by_key,
            project_doc,
            element,
            material_id,
            "Element Material",
            "element_material|%s" % material_value,
            exposure_cache=exposure_cache,
            notes=notes,
        )
        for diagnostic in in_place_diagnostics.get(material_value, []):
            row.add_diagnostic(diagnostic)
        diagnostics_text = to_text(row.Diagnostics)
        if (
            is_in_place_family_instance(element) and
            "geometry item(s) reported this material" in diagnostics_text and
            "matching geometry item(s) had no graphics style id" in diagnostics_text and
            "did not expose any internal GenericForm/object elements" in diagnostics_text
        ):
            row.Status = BAKED_IN_PLACE_STATUS
            row.Exposure = EXPOSURE_BAKED_IN_PLACE

    if len(rows_by_key) == starting_row_count:
        material_id = category_material_id(element)
        if material_id is not None and is_valid_material_id(project_doc, material_id):
            add_report_only_material(
                rows_by_key,
                project_doc,
                element,
                material_id,
                "Category Material",
                "category_material|%s" % material_key(material_id),
                exposure_cache=exposure_cache,
                notes=notes,
            )


def build_edit_rows(project_doc, force_rescan=False, active_family_cache=None):
    if project_doc.IsModifiable:
        raise Exception("The project document is currently modifiable. Close any active transaction and run the editor again.")
    if project_doc.IsReadOnly:
        raise Exception("The project document is read-only. Revit may block material updates.")

    view = get_active_3d_view(project_doc)
    if not force_rescan:
        cached = load_cached_rows(project_doc, view)
        if cached is not None:
            if active_family_cache is not None:
                active_family_cache.update(cached.get("active_family_cache") or {})
            notes = list(cached.get("notes") or [])
            notes.append("Loaded cached visible-material table. Use Refresh for a full rescan.")
            return view, cached.get("visible_element_ids") or [], cached.get("rows") or [], notes

    selected_elements = get_selected_material_scan_elements(project_doc)
    visible_elements = merge_elements_by_id(
        get_visible_model_elements(project_doc, view),
        selected_elements,
    )
    rows_by_key = {}
    if active_family_cache is None:
        active_family_cache = {}
    exposure_cache = {}
    notes = []
    in_place_forms = collect_project_generic_forms(project_doc, view)
    in_place_forms = merge_elements_by_id(in_place_forms, selected_elements)

    if not visible_elements:
        notes.append("No visible model elements were found in the active 3D view.")
    if selected_elements:
        notes.append("Included %s currently selected material-capable element(s); this supports Model In-Place edit mode objects." % len(selected_elements))

    if visible_elements:
        progress_title = "Scanning visible materials {value} of {max_value}"
        with forms.ProgressBar(title=progress_title, cancellable=True, step=1) as progress:
            for index, element in enumerate(visible_elements):
                progress.update_progress(index + 1, len(visible_elements))
                if progress.cancelled:
                    notes.append("Scan cancelled by operator after %s of %s elements." % (index, len(visible_elements)))
                    break
                try:
                    scan_element(
                        rows_by_key,
                        project_doc,
                        view,
                        element,
                        active_family_cache,
                        notes,
                        exposure_cache=exposure_cache,
                        in_place_forms=in_place_forms,
                    )
                except Exception as err:
                    notes.append("%s: %s" % (element_example_text(project_doc, element), to_text(err)))

    rows = list(rows_by_key.values())
    for row in rows:
        row.finalize_display()

    rows = sorted(
        rows,
        key=lambda row: (
            TAB_ORDER.index(row._tab_key) if row._tab_key in TAB_ORDER else 99,
            row.Category.lower(),
            row.ElementGroup.lower(),
            row.MaterialSource.lower(),
            row.CurrentMaterial.lower(),
        ),
    )

    save_cached_rows(project_doc, view, visible_elements, rows, notes, active_family_cache=active_family_cache)
    return view, visible_elements, rows, notes


def get_project_material_options(project_doc):
    cache_key = document_cache_identity(project_doc)
    if cache_key in PROJECT_MATERIAL_OPTIONS_CACHE:
        return PROJECT_MATERIAL_OPTIONS_CACHE[cache_key]

    materials = []
    for material in DB.FilteredElementCollector(project_doc).OfClass(DB.Material).ToElements():
        materials.append(material)
    materials = sorted(materials, key=lambda material: safe_name(material).lower())

    options = [MaterialOption(BY_CATEGORY, DB.ElementId.InvalidElementId)]
    for material in materials:
        options.append(MaterialOption(safe_name(material, "<Unnamed material>"), material.Id))
    PROJECT_MATERIAL_OPTIONS_CACHE[cache_key] = options
    return options


def pick_project_material(project_doc):
    picker = MaterialPickerWindow(project_doc)
    picker.ShowDialog()
    return picker.selected_option


def set_parameter_target(project_doc, target, material_id):
    owner = None
    if target.route == ROUTE_INSTANCE_PARAM or target.route == ROUTE_IN_PLACE_PARAM:
        owner = project_doc.GetElement(target.element_id)
    elif target.route == ROUTE_TYPE_PARAM:
        owner = project_doc.GetElement(target.type_id)
    if owner is None:
        raise Exception("target element not found")

    parameter = find_material_parameter(project_doc, owner, target.parameter_name, target.parameter_id)
    if parameter is None:
        raise Exception("parameter not found: %s" % target.parameter_name)
    try:
        if parameter.IsReadOnly:
            raise Exception("parameter is read-only: %s" % target.parameter_name)
    except AttributeError:
        pass
    parameter.Set(material_id)


def set_compound_layer_target(project_doc, target, material_id):
    type_element = project_doc.GetElement(target.type_id)
    if type_element is None:
        raise Exception("type element not found")
    get_compound = safe_attr(type_element, "GetCompoundStructure", None)
    set_compound = safe_attr(type_element, "SetCompoundStructure", None)
    if get_compound is None or set_compound is None:
        raise Exception("type has no editable compound structure")
    compound = get_compound()
    if compound is None:
        raise Exception("compound structure not found")
    compound.SetMaterialId(target.layer_index, material_id)
    set_compound(compound)


def apply_material_to_painted_face(project_doc, element, face, material_id):
    element_id = safe_attr(element, "Id", None)
    if is_by_category_id(material_id):
        project_doc.RemovePaint(element_id, face)
        return

    try:
        project_doc.RemovePaint(element_id, face)
    except Exception:
        pass
    project_doc.Paint(element_id, face, material_id)


def set_painted_face_target(project_doc, target, material_id, current_material_id=None):
    element = project_doc.GetElement(target.element_id)
    if element is None:
        raise Exception("painted element not found")
    if target.face is not None:
        apply_material_to_painted_face(project_doc, element, target.face, material_id)
        return 1

    source_material_id = current_material_id or target.material_id
    if not is_valid_material_id(project_doc, source_material_id):
        raise Exception("painted source material is not available for lazy face resolution")

    view = get_active_3d_view(project_doc)
    targets_by_value = collect_painted_face_targets(project_doc, view, element)
    value = element_id_value(source_material_id)
    matching_targets = targets_by_value.get(value) or []
    if not matching_targets:
        raise Exception("no painted faces using %s were found on element %s" % (
            material_name_from_id(project_doc, source_material_id),
            element_id_text(target.element_id),
        ))

    applied = 0
    for resolved_target in matching_targets:
        if resolved_target.face is None:
            continue
        apply_material_to_painted_face(project_doc, element, resolved_target.face, material_id)
        applied += 1
    if not applied:
        raise Exception("painted faces could not be resolved on element %s" % element_id_text(target.element_id))
    return applied


def stable_reference_from_subelement(project_doc, subelement):
    try:
        reference = subelement.GetReference()
        if reference is not None:
            return to_text(reference.ConvertToStableRepresentation(project_doc))
    except Exception:
        pass
    return ""


def resolve_subelement(project_doc, element, subelement_key):
    if not subelement_key:
        return None
    try:
        reference = DB.Reference.ParseFromStableRepresentation(project_doc, subelement_key)
        subelement = DB.Subelement.Create(project_doc, reference)
        if subelement is not None:
            return subelement
    except Exception:
        pass
    for subelement in get_subelements(element):
        if get_subelement_identity(project_doc, subelement) == subelement_key:
            return subelement
        if stable_reference_from_subelement(project_doc, subelement) == subelement_key:
            return subelement
    return None


def set_subelement_parameter_target(project_doc, target, material_id):
    element = project_doc.GetElement(target.element_id)
    if element is None:
        raise Exception("in-place parent element not found")
    subelement = resolve_subelement(project_doc, element, target.subelement_uid)
    if subelement is None:
        raise Exception("in-place object material target not found")
    if target.parameter_id is None:
        raise Exception("in-place object material parameter id is missing")
    try:
        if not bool(subelement.IsParameterModifiable(target.parameter_id)):
            raise Exception("in-place object material parameter is not modifiable")
    except Exception as err:
        raise Exception(to_text(err))
    subelement.SetParameterValue(target.parameter_id, DB.ElementIdParameterValue(material_id))
    return 1


def set_object_style_material_target(project_doc, target, material_id):
    owner = project_doc.GetElement(target.element_id)
    subcategory = None
    try:
        if isinstance(owner, DB.GraphicsStyle):
            subcategory = owner.GraphicsStyleCategory
    except Exception:
        pass
    if subcategory is None:
        subcategory = form_subcategory(owner)
    if subcategory is None and target.key_extra:
        subcategory = category_by_id(project_doc, target.key_extra)
    if subcategory is None:
        raise Exception("in-place object style material target not found")
    expected_category_id = to_text(target.key_extra).strip()
    actual_category_id = category_id_text(subcategory)
    if expected_category_id and actual_category_id and expected_category_id != actual_category_id:
        raise Exception("in-place object subcategory changed since scan")
    if is_by_category_id(material_id):
        try:
            subcategory.Material = None
            return 1
        except Exception:
            raise Exception("object style material cannot be cleared to <By Category>; choose a project material")
    material = project_doc.GetElement(material_id)
    if not isinstance(material, DB.Material):
        raise Exception("pending material is not a project material")
    subcategory.Material = material
    return 1


def apply_changed_rows(project_doc, rows):
    changed_rows = [row for row in rows if row._changed and row.is_editable()]
    if not changed_rows:
        return 0, ["No pending editable material changes."]

    updated = 0
    errors = []
    applied_rows = []
    ordinary_rows = []

    for row in changed_rows:
        expose_targets = [
            target for target in row._targets
            if target.editable and target.route == ROUTE_EXPOSE_TYPE_PARAM
        ]
        ordinary_targets = [
            target for target in row._targets
            if target.editable and target.route != ROUTE_EXPOSE_TYPE_PARAM
        ]
        if ordinary_targets:
            ordinary_rows.append(row)
        if not expose_targets:
            continue

        row_updated = False
        for target in expose_targets:
            try:
                expose_and_set_family_material_parameter(project_doc, target, row._pending_material_id)
                updated += 1
                row_updated = True
            except Exception as err:
                errors.append("%s / %s / %s: %s" % (
                    row.ElementGroup,
                    row.MaterialSource,
                    target.source,
                    to_text(err),
                ))
        if row_updated and row not in applied_rows:
            applied_rows.append(row)

    if not ordinary_rows:
        return updated, errors, applied_rows

    transaction = DB.Transaction(project_doc, "Edit visible materials")
    transaction.Start()
    try:
        for row in ordinary_rows:
            row_updated = False
            for target in row._targets:
                if not target.editable:
                    continue
                if target.route == ROUTE_EXPOSE_TYPE_PARAM:
                    continue
                try:
                    if target.route == ROUTE_PAINT:
                        updated += set_painted_face_target(
                            project_doc,
                            target,
                            row._pending_material_id,
                            current_material_id=row._current_material_id,
                        )
                    elif target.route in (ROUTE_INSTANCE_PARAM, ROUTE_TYPE_PARAM, ROUTE_IN_PLACE_PARAM):
                        set_parameter_target(project_doc, target, row._pending_material_id)
                        updated += 1
                    elif target.route == ROUTE_SUBELEMENT_PARAM:
                        updated += set_subelement_parameter_target(
                            project_doc,
                            target,
                            row._pending_material_id,
                        )
                    elif target.route == ROUTE_OBJECT_STYLE_MATERIAL:
                        updated += set_object_style_material_target(
                            project_doc,
                            target,
                            row._pending_material_id,
                        )
                    elif target.route == ROUTE_COMPOUND_LAYER:
                        set_compound_layer_target(project_doc, target, row._pending_material_id)
                        updated += 1
                    else:
                        continue
                    row_updated = True
                except Exception as err:
                    errors.append("%s / %s / %s: %s" % (
                        row.ElementGroup,
                        row.MaterialSource,
                        target.source,
                        to_text(err),
                    ))
            if row_updated and row not in applied_rows:
                applied_rows.append(row)
        try:
            project_doc.Regenerate()
        except Exception:
            pass
        transaction.Commit()
    except Exception:
        transaction.RollBack()
        raise

    return updated, errors, applied_rows


class VisibleMaterialsWindow(forms.WPFWindow):
    def __init__(self, project_doc):
        self.project_doc = project_doc
        self.project_title = document_title(project_doc)
        self.view = None
        self.visible_elements = []
        self.rows = []
        self.rows_by_tab = {}
        self.notes = []
        self.active_family_cache = {}
        self.by_category_filter_on = False
        self.report_filter_on = False
        self._last_full_tab_key = TAB_LOADABLE
        self._copied_material_option = None
        xaml_path = os.path.join(os.path.dirname(__file__), "ui.xaml")
        forms.WPFWindow.__init__(self, xaml_path)
        self.grids_by_tab = {
            TAB_NEEDS: self.needs_grid,
            TAB_REPORT: self.report_grid,
            TAB_LOADABLE: self.families_grid,
            TAB_WALLS: self.walls_grid,
            TAB_CEILINGS: self.ceilings_grid,
            TAB_FLOORS: self.floors_grid,
            TAB_OTHER: self.other_grid,
        }
        self.tabs_by_key = {
            TAB_NEEDS: self.needs_tab,
            TAB_REPORT: self.report_tab,
            TAB_LOADABLE: self.families_tab,
            TAB_WALLS: self.walls_tab,
            TAB_CEILINGS: self.ceilings_tab,
            TAB_FLOORS: self.floors_tab,
            TAB_OTHER: self.other_tab,
        }
        self.reload_rows()

    def reload_rows(self, force_rescan=False):
        self.view, self.visible_elements, self.rows, self.notes = build_edit_rows(
            self.project_doc,
            force_rescan=force_rescan,
            active_family_cache=self.active_family_cache,
        )
        self.rebuild_tab_rows()
        self.update_summary()
        note_text = " %s scan notes. See pyRevit output after apply for details." % len(self.notes) if self.notes else ""
        self.set_status("%s rows ready.%s" % (len(self.rows), note_text))
        self.update_by_category_filter_button()
        self.update_report_filter_button()

    def rebuild_tab_rows(self):
        self.rows_by_tab = {}
        for tab_key in DISPLAY_TAB_ORDER:
            self.rows_by_tab[tab_key] = []
        self.update_group_flags()
        for row in self.rows:
            self.rows_by_tab.setdefault(row._tab_key, []).append(row)
            if row.GroupHasByCategory:
                self.rows_by_tab.setdefault(TAB_NEEDS, []).append(row)
            if not row.is_editable():
                self.rows_by_tab.setdefault(TAB_REPORT, []).append(row)

        for tab_key in DISPLAY_TAB_ORDER:
            grid = self.grids_by_tab.get(tab_key)
            if grid is None:
                continue
            grid.ItemsSource = self.rows_by_tab.get(tab_key, [])
            self.apply_grouping(grid)

        for tab_key in DISPLAY_TAB_ORDER:
            tab = self.tabs_by_key.get(tab_key)
            if tab is None:
                continue
            tab_rows = self.rows_by_tab.get(tab_key, [])
            tab.Header = "%s (%s groups / %s rows)" % (
                TAB_LABELS.get(tab_key, tab_key),
                self.group_count(tab_rows),
                len(tab_rows),
            )

    def group_key_for_row(self, row):
        return "%s|%s" % (row._tab_key, row.ElementGroup)

    def row_is_host_structure_material(self, row):
        if row._tab_key not in (TAB_WALLS, TAB_CEILINGS, TAB_FLOORS):
            return False
        source = to_text(row.MaterialSource).strip().lower()
        return source == "type parameter: structural material"

    def row_counts_as_needs_amendment(self, row):
        if self.row_is_host_structure_material(row):
            return False
        current_material = to_text(row.CurrentMaterial).strip().lower()
        return current_material == BY_CATEGORY.lower() or current_material == DEFAULT_MATERIAL_NAME.lower()

    def update_group_flags(self):
        group_flags = {}
        for row in self.rows:
            key = self.group_key_for_row(row)
            if key not in group_flags:
                group_flags[key] = False
            if self.row_counts_as_needs_amendment(row):
                group_flags[key] = True

        for row in self.rows:
            has_by_category = bool(group_flags.get(self.group_key_for_row(row)))
            row.GroupHasByCategory = has_by_category
            row.GroupBackground = "#FFF2A8" if has_by_category else "#EEF2F7"
            row.GroupMarker = "Needs amendment" if has_by_category else ""

    def apply_grouping(self, grid):
        try:
            view = CollectionViewSource.GetDefaultView(grid.ItemsSource)
        except Exception:
            return
        try:
            view.GroupDescriptions.Clear()
            view.GroupDescriptions.Add(PropertyGroupDescription("ElementGroup"))
        except Exception:
            pass

    def group_count(self, rows):
        groups = set()
        for row in rows:
            groups.add(row.ElementGroup)
        return len(groups)

    def by_category_group_count(self):
        groups = set()
        for row in self.rows:
            if row.GroupHasByCategory:
                groups.add(self.group_key_for_row(row))
        return len(groups)

    def update_by_category_filter_button(self):
        try:
            self.by_category_highlight_button.Content = "Show Full List" if self.by_category_filter_on else "Show Needs Amendment"
        except Exception:
            pass

    def update_report_filter_button(self):
        try:
            self.report_only_button.Content = "Show Full List" if self.report_filter_on else "Show Report Only"
        except Exception:
            pass

    def remember_current_full_tab(self, selected):
        for tab_key, tab in self.tabs_by_key.items():
            if tab is selected and tab_key not in (TAB_NEEDS, TAB_REPORT):
                self._last_full_tab_key = tab_key
                break

    def restore_full_tab(self):
        target_key = self._last_full_tab_key if self._last_full_tab_key not in (TAB_NEEDS, TAB_REPORT) else TAB_LOADABLE
        self.by_category_filter_on = False
        self.report_filter_on = False
        self.tab_control.SelectedItem = self.tabs_by_key.get(target_key, self.families_tab)
        self.update_by_category_filter_button()
        self.update_report_filter_button()

    def show_needs_amendment_tab(self):
        try:
            selected = self.tab_control.SelectedItem
        except Exception:
            selected = None
        if selected is self.needs_tab or self.by_category_filter_on:
            self.restore_full_tab()
            return

        self.remember_current_full_tab(selected)
        self.by_category_filter_on = True
        self.report_filter_on = False
        self.tab_control.SelectedItem = self.needs_tab
        self.update_by_category_filter_button()
        self.update_report_filter_button()
        self.set_status("Needs Amendment tab shows %s groups with current <By Category> or Default review materials." % self.by_category_group_count())

    def show_report_only_tab(self):
        try:
            selected = self.tab_control.SelectedItem
        except Exception:
            selected = None
        if selected is self.report_tab or self.report_filter_on:
            self.restore_full_tab()
            return

        self.remember_current_full_tab(selected)
        self.report_filter_on = True
        self.by_category_filter_on = False
        self.tab_control.SelectedItem = self.report_tab
        self.update_by_category_filter_button()
        self.update_report_filter_button()
        report_rows = self.rows_by_tab.get(TAB_REPORT, [])
        self.set_status("Report Only tab shows %s groups / %s rows where material is not exposed as an editable project target." % (self.group_count(report_rows), len(report_rows)))

    def update_summary(self):
        view_name = safe_name(self.view, "Active 3D View")
        editable_rows = len([row for row in self.rows if row.is_editable()])
        self.summary_text.Text = (
            "Project: %s | Active 3D view: %s | Visible model elements: %s | Material rows: %s | Editable rows: %s"
            % (self.project_title, view_name, len(self.visible_elements), len(self.rows), editable_rows)
        )

    def set_status(self, message):
        self.status_text.Text = message

    def refresh_grid(self):
        for grid in self.grids_by_tab.values():
            try:
                grid.Items.Refresh()
            except Exception:
                pass

    def get_selected_rows(self):
        selected = []
        for grid in self.grids_by_tab.values():
            try:
                for item in grid.SelectedItems:
                    if isinstance(item, VisibleMaterialRow) and item not in selected:
                        selected.append(item)
            except Exception:
                pass
            try:
                item = grid.SelectedItem
                if isinstance(item, VisibleMaterialRow) and item not in selected:
                    selected.append(item)
            except Exception:
                pass
        return selected

    def representative_element_id_for_row(self, row):
        for value in sorted(list(row._occurrence_keys)):
            element_id = make_element_id(value)
            if element_id is None:
                continue
            element = self.project_doc.GetElement(element_id)
            if is_loadable_family_instance(element):
                return element_id
        return None

    def row_can_create_exposed_parameter(self, row):
        if row is None or row.is_editable():
            return False
        if row._tab_key != TAB_LOADABLE:
            return False
        if row.Exposure != EXPOSURE_CAN_EXPOSE:
            return False
        if not is_valid_material_id(self.project_doc, row._current_material_id):
            return False
        return self.representative_element_id_for_row(row) is not None

    def default_exposed_parameter_name(self, row):
        current_material = clean_material_name(row.CurrentMaterial)
        if not current_material or current_material.startswith("<"):
            return "Exposed Material"
        if "material" in current_material.lower():
            return current_material
        return "%s Material" % current_material

    def report_only_detail(self, row):
        if row is None:
            return ""
        detail = ""
        if row.Exposure == EXPOSURE_BAKED_IN_PLACE:
            detail = (
                "\n\nThis is a baked model-in-place face material. The scan found visible solid/face geometry using this material, "
                "but those faces have no graphics style/subcategory id and Revit did not expose the internal form object in the "
                "project document. There is no supported project-level API target for the table to set while staying in the broad "
                "project-mode scan. A true material replacement requires Revit to expose a writable internal form material "
                "parameter or subcategory; this row did not expose one."
            )
        elif row.Exposure == EXPOSURE_MANUAL_IN_PLACE:
            detail = (
                "\n\nThis is a model-in-place material. The editor reports it so users can find it, "
                "but it intentionally does not change model-in-place materials from the table. "
                "Select the element in Revit, use Edit In-Place, and change the material manually."
            )
        diagnostics = to_text(safe_attr(row, "Diagnostics", "")).strip()
        if diagnostics:
            detail += "\n\nResolver diagnostic:\n%s" % diagnostics
        return detail

    def explain_blocked_material_change(self, row):
        if row is None:
            self.set_status("Selected row cannot be changed.")
            return
        reason = row.Exposure or row.Status or "No editable project-level material target was found."
        forms.alert(
            (
                "This material cannot be changed directly from the table.\n\n"
                "Source: %s\n"
                "Material: %s\n"
                "Reason: %s%s"
            ) % (row.MaterialSource, row.CurrentMaterial, reason, self.report_only_detail(row)),
            title=COMMAND_TITLE,
            warn_icon=True,
        )
        self.set_status("Material change blocked: %s" % reason)

    def prompt_exposed_parameter_name(self, row):
        return forms.ask_for_string(
            default=self.default_exposed_parameter_name(row),
            prompt=(
                "Material Parameter not exposed.\n\n"
                "Create a new type Material parameter name for:\n%s"
            ) % row.ElementGroup,
            title="Create Material Parameter",
        )

    def add_expose_pending_target(self, row, selected_material, parameter_name):
        element_id = self.representative_element_id_for_row(row)
        if element_id is None:
            raise Exception("no representative loadable family instance found")
        parameter_name = clean_material_name(parameter_name)
        if not parameter_name:
            raise Exception("parameter name is blank")
        row.add_target(MaterialTarget(
            ROUTE_EXPOSE_TYPE_PARAM,
            element_id=element_id,
            parameter_name=parameter_name,
            material_id=row._current_material_id,
            source="Expose family material parameter",
            key_extra=parameter_name,
            editable=True,
        ))
        row.EditScope = "Create type parameter"
        row.set_pending(selected_material.Name, selected_material.element_id)
        row.Status = "Pending expose: %s" % parameter_name
        row.finalize_display()
        row.notify_properties("Editable", "EditScope", "Targets", "Status", "PendingMaterial")

    def markdown_cell(self, value):
        return to_text(value).replace("|", "\\|").replace("\r", " ").replace("\n", " ")

    def on_change_material(self, sender, args):
        selected_rows = self.get_selected_rows()
        if not selected_rows:
            self.set_status("Select one or more editable rows first.")
            return

        editable_rows = [row for row in selected_rows if row.is_editable()]
        exposable_rows = [row for row in selected_rows if self.row_can_create_exposed_parameter(row)]
        blocked_rows = [
            row for row in selected_rows
            if row not in editable_rows and row not in exposable_rows
        ]
        if not editable_rows and not exposable_rows:
            self.explain_blocked_material_change(selected_rows[0])
            return

        selected_material = pick_project_material(self.project_doc)
        self.Activate()

        if selected_material is None:
            self.set_status("Material selection cancelled.")
            return

        for row in editable_rows:
            row.set_pending(selected_material.Name, selected_material.element_id)

        exposed_pending = 0
        exposed_skipped = 0
        if exposable_rows and not is_valid_material_id(self.project_doc, selected_material.element_id):
            forms.alert(
                "Creating a new exposed family parameter requires a real project material. <By Category> cannot be used for this step.",
                title=COMMAND_TITLE,
                warn_icon=True,
            )
            exposed_skipped = len(exposable_rows)
        else:
            for row in exposable_rows:
                parameter_name = self.prompt_exposed_parameter_name(row)
                if parameter_name is None:
                    exposed_skipped += 1
                    continue
                try:
                    self.add_expose_pending_target(row, selected_material, parameter_name)
                    exposed_pending += 1
                except Exception as err:
                    forms.alert("Could not prepare exposed parameter:\n\n%s" % to_text(err), title=COMMAND_TITLE, warn_icon=True)
                    exposed_skipped += 1

        skipped = len(blocked_rows) + exposed_skipped
        if skipped:
            self.set_status(
                "Pending material set on %s editable rows and %s new parameter rows. %s rows skipped." % (
                    len(editable_rows),
                    exposed_pending,
                    skipped,
                )
            )
        else:
            self.set_status(
                "Pending material set on %s editable rows and %s new parameter rows." % (
                    len(editable_rows),
                    exposed_pending,
                )
            )

    def on_toggle_by_category_highlight(self, sender, args):
        self.show_needs_amendment_tab()

    def on_toggle_report_only(self, sender, args):
        self.show_report_only_tab()

    def on_grid_preview_mouse_right_button_down(self, sender, args):
        try:
            data_row = visual_parent_of_type(args.OriginalSource, DataGridRow)
        except Exception:
            data_row = None
        if data_row is None:
            return
        try:
            if not data_row.IsSelected:
                for grid in self.grids_by_tab.values():
                    try:
                        grid.SelectedItems.Clear()
                    except Exception:
                        pass
                data_row.IsSelected = True
        except Exception:
            pass
        try:
            sender.SelectedItem = data_row.Item
        except Exception:
            pass

    def material_option_from_row(self, row):
        if row is None:
            return None
        if row._changed:
            material_name = row.PendingMaterial
            material_id = row._pending_material_id
        else:
            material_name = row.CurrentMaterial
            material_id = row._current_material_id
        if not material_name:
            return None
        if material_name in (NO_MATERIAL, PARAMETER_NOT_FOUND):
            return None
        if material_name.startswith("<Missing material"):
            return None
        if material_name == BY_CATEGORY:
            material_id = DB.ElementId.InvalidElementId
        elif not is_valid_material_id(self.project_doc, material_id):
            return None
        return MaterialOption(material_name, material_id)

    def on_copy_material_from_row(self, sender, args):
        selected_rows = self.get_selected_rows()
        if not selected_rows:
            self.set_status("Select a row to copy a material from.")
            return
        copied = self.material_option_from_row(selected_rows[0])
        if copied is None:
            self.set_status("Selected row has no material to copy.")
            return
        self._copied_material_option = copied
        self.set_status("Copied material '%s'. Select editable target rows and right-click Paste as Pending Material." % copied.Name)

    def on_paste_material_to_selected(self, sender, args):
        if self._copied_material_option is None:
            self.set_status("Copy a material first.")
            return
        selected_rows = self.get_selected_rows()
        editable_rows = [row for row in selected_rows if row.is_editable()]
        if not editable_rows:
            self.set_status("Select editable rows before pasting.")
            return
        for row in editable_rows:
            row.set_pending(self._copied_material_option.Name, self._copied_material_option.element_id)
        changed_count = len(editable_rows)
        skipped = len(selected_rows) - changed_count
        if skipped:
            self.set_status("Pasted '%s' to %s rows. %s rows skipped." % (self._copied_material_option.Name, changed_count, skipped))
        else:
            self.set_status("Pasted '%s' to %s pending rows." % (self._copied_material_option.Name, changed_count))

    def on_explain_report_only(self, sender, args):
        selected_rows = self.get_selected_rows()
        if not selected_rows:
            self.set_status("Select a row to explain.")
            return
        row = selected_rows[0]
        if row.is_editable():
            self.set_status("Selected row is editable: %s." % row.EditScope)
            return
        message = (
            "This row is report-only because the material was found on visible geometry, "
            "but the tool could not map it to an editable project-level target.\n\n"
            "Source: %s\n"
            "Status: %s\n"
            "Exposure: %s%s\n\n"
            "Typical fix: edit the family/type so the visible geometry material is associated "
            "with an instance or type Material parameter, then reload/rescan."
            % (
                row.MaterialSource,
                row.Status or REPORT_ONLY_STATUS,
                row.Exposure or EXPOSURE_UNKNOWN,
                self.report_only_detail(row),
            )
        )
        forms.alert(message, title=COMMAND_TITLE, warn_icon=False)

    def on_explain_type_parameter_push(self, sender, args):
        selected_rows = self.get_selected_rows()
        row = selected_rows[0] if selected_rows else None
        if row is not None and row.is_editable():
            self.set_status("This row already has an editable %s target." % row.EditScope)
            return
        if row is not None and row.Exposure == EXPOSURE_CAN_EXPOSE:
            forms.alert(
                "This row looks feasible to expose.\n\n"
                "The scan found a matching material parameter inside the loadable family that is not associated to a Family parameter.\n\n"
                "Use Change Material on this row. The tool will ask for a new type Material parameter name, store it as pending, then Apply Updates will create and associate the parameter.",
                title=COMMAND_TITLE,
                warn_icon=False
            )
            return
        forms.alert(
            (
                "Automated parameter push is not enabled in this stable tool.\n\n"
                "Current scan result: %s\n\n"
                "For loadable families, the reliable workflow is to open the family, create or use a type Material parameter, "
                "associate the family geometry material to that parameter, reload the family, then rescan this table.\n\n"
                "The report-only row does not reliably expose which exact family geometry parameter should be associated, "
                "so the tool should not auto-edit/reload the family without a separate guarded workflow."
            ) % (row.Exposure if row is not None and row.Exposure else EXPOSURE_UNKNOWN),
            title=COMMAND_TITLE,
            warn_icon=False
        )

    def on_clear_pending(self, sender, args):
        selected_rows = self.get_selected_rows()
        if not selected_rows:
            selected_rows = self.rows
        for row in selected_rows:
            row.clear_pending()
        self.set_status("Pending changes cleared.")

    def on_apply(self, sender, args):
        changed_rows = [row for row in self.rows if row._changed and row.is_editable()]
        if not changed_rows:
            self.set_status("No pending editable material changes.")
            return

        type_rows = [
            row for row in changed_rows
            if row.has_route(ROUTE_TYPE_PARAM) or row.has_route(ROUTE_COMPOUND_LAYER) or row.has_route(ROUTE_EXPOSE_TYPE_PARAM)
        ]
        object_style_rows = [
            row for row in changed_rows
            if row.has_route(ROUTE_OBJECT_STYLE_MATERIAL)
        ]
        expose_rows = [
            row for row in changed_rows
            if row.has_route(ROUTE_EXPOSE_TYPE_PARAM)
        ]
        paint_remove_rows = [
            row for row in changed_rows
            if row.has_route(ROUTE_PAINT) and is_by_category_id(row._pending_material_id)
        ]

        message = "Apply %s project-editable pending material row changes?" % len(changed_rows)
        if type_rows:
            message += "\n\n%s selected rows are type-wide. This includes type material parameters or wall/floor/ceiling compound layers." % len(type_rows)
        if object_style_rows:
            message += "\n\n%s selected rows will change an in-place form subcategory/object-style material. This can affect other geometry using that same subcategory." % len(object_style_rows)
        if expose_rows:
            message += "\n\n%s report-only rows will edit and reload their loadable families to create a new type Material parameter. Undo for these rows can be slow because Revit must restore the previous family definition." % len(expose_rows)
        if paint_remove_rows:
            message += "\n\n%s painted-surface rows are set to <By Category>; this will remove paint from those faces." % len(paint_remove_rows)

        proceed = forms.alert(message, title=COMMAND_TITLE, yes=True, no=True, warn_icon=True)
        if not proceed:
            self.set_status("Apply cancelled.")
            return

        try:
            updated, errors, applied_rows = apply_changed_rows(self.project_doc, changed_rows)
        except Exception as err:
            forms.alert("Apply failed:\n\n%s" % to_text(err), title=COMMAND_TITLE, warn_icon=True)
            self.set_status("Apply failed.")
            return

        try:
            if uidoc is not None:
                uidoc.RefreshActiveView()
        except Exception:
            pass

        if errors:
            output.print_md("## %s Apply Notes" % COMMAND_TITLE)
            for err in errors:
                output.print_md("- %s" % to_text(err).replace("|", "\\|"))
            forms.alert(
                "Updated %s targets with %s notes. See pyRevit output for details." % (updated, len(errors)),
                title=COMMAND_TITLE,
                warn_icon=True,
            )
        else:
            forms.alert("Updated %s targets." % updated, title=COMMAND_TITLE, warn_icon=False)

        for row in applied_rows:
            row.mark_applied()
        applied_expose_rows = [row for row in applied_rows if row.has_route(ROUTE_EXPOSE_TYPE_PARAM)]
        if applied_expose_rows:
            self.active_family_cache = {}
            try:
                self.reload_rows(force_rescan=True)
                self.set_status("Updated %s targets. Exposed family parameters were rescanned." % updated)
                return
            except Exception as err:
                forms.alert(
                    "Updates applied, but the automatic rescan failed:\n\n%s\n\nUse Refresh to rescan the table." % to_text(err),
                    title=COMMAND_TITLE,
                    warn_icon=True,
                )
        self.rebuild_tab_rows()
        save_cached_rows(
            self.project_doc,
            self.view,
            self.visible_elements,
            self.rows,
            self.notes,
            active_family_cache=self.active_family_cache,
        )
        self.update_summary()
        self.set_status("Updated %s targets. Table kept in place; use Refresh for a full rescan." % updated)

    def on_refresh(self, sender, args):
        changed_rows = [row for row in self.rows if row._changed]
        if changed_rows:
            proceed = forms.alert(
                "Refresh will discard pending material choices. Continue?",
                title=COMMAND_TITLE,
                yes=True,
                no=True,
                warn_icon=True,
            )
            if not proceed:
                return
        self.active_family_cache = {}
        self.reload_rows(force_rescan=True)

    def on_close(self, sender, args):
        self.Close()


def main():
    if doc is None:
        forms.alert("Open a Revit project before running this editor.", title=COMMAND_TITLE, warn_icon=True)
        return
    window = VisibleMaterialsWindow(doc)
    window.ShowDialog()


try:
    main()
except Exception as err:
    details = traceback.format_exc()
    print(details)
    try:
        forms.alert("%s failed:\n\n%s" % (COMMAND_TITLE, to_text(err)), title=COMMAND_TITLE, warn_icon=True)
    except Exception:
        pass
    sys.exit(1)
