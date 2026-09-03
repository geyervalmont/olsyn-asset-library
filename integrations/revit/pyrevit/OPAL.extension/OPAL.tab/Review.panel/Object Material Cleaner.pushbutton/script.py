# -*- coding: utf-8 -*-
from __future__ import print_function

import os
import re
import traceback

from Autodesk.Revit import DB
from System.ComponentModel import INotifyPropertyChanged
from System.ComponentModel import PropertyChangedEventArgs
from pyrevit import forms
from pyrevit import revit
from pyrevit import script


COMMAND_TITLE = "Object Material Cleaner"
NO_MATERIAL = "<No material>"
BY_CATEGORY = "<By Category>"

doc = revit.doc
uidoc = revit.uidoc
output = script.get_output()


def to_text(value):
    if value is None:
        return ""
    try:
        return unicode(value)
    except NameError:
        return str(value)
    except Exception:
        return str(value)


def safe_attr(obj, attr_name, fallback=None):
    try:
        value = getattr(obj, attr_name)
        return value if value is not None else fallback
    except Exception:
        return fallback


def element_id_int(element_id):
    if element_id is None:
        return None
    for attr_name in ("IntegerValue", "Value"):
        try:
            value = getattr(element_id, attr_name)
            if value is not None:
                return int(value)
        except Exception:
            pass
    try:
        return int(element_id)
    except Exception:
        return None


def make_element_id(value):
    try:
        return DB.ElementId(int(value))
    except Exception:
        return DB.ElementId.InvalidElementId


def element_id_text(element_id):
    value = element_id_int(element_id)
    return "" if value is None else str(value)


def is_valid_element_id(element_id):
    value = element_id_int(element_id)
    return value is not None and value > 0


def safe_name(element, fallback=""):
    if element is None:
        return fallback
    for attr_name in ("Name",):
        try:
            value = safe_attr(element, attr_name, None)
            if value:
                return to_text(value)
        except Exception:
            pass
    try:
        return to_text(element)
    except Exception:
        return fallback


def category_name(element):
    try:
        category = element.Category
        if category is not None:
            return to_text(category.Name)
    except Exception:
        pass
    return ""


def get_element(project_doc, element_id):
    if element_id is None:
        return None
    try:
        return project_doc.GetElement(element_id)
    except Exception:
        pass
    try:
        return project_doc.GetElement(make_element_id(element_id))
    except Exception:
        return None


def is_valid_material_id(project_doc, material_id):
    if not is_valid_element_id(material_id):
        return False
    try:
        return isinstance(project_doc.GetElement(material_id), DB.Material)
    except Exception:
        return False


def material_name_from_id(project_doc, material_id):
    if material_id is None:
        return NO_MATERIAL
    if element_id_int(material_id) == element_id_int(DB.ElementId.InvalidElementId):
        return BY_CATEGORY
    try:
        material = project_doc.GetElement(material_id)
    except Exception:
        material = None
    if isinstance(material, DB.Material):
        return safe_name(material, "<Unnamed material>")
    if is_valid_element_id(material_id):
        return "<Missing material %s>" % element_id_text(material_id)
    return NO_MATERIAL


def get_material_spec_id():
    for path in (
        ("SpecTypeId", "Reference", "Material"),
        ("SpecTypeIdReference", "Material"),
        ("SpecTypeId", "Material"),
    ):
        try:
            value = DB
            for attr_name in path:
                value = getattr(value, attr_name)
            if value is not None:
                return value
        except Exception:
            pass
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


def parameter_definition_name(param):
    definition = safe_attr(param, "Definition", None)
    return to_text(safe_attr(definition, "Name", None))


def parameter_data_type_text(param):
    definition = safe_attr(param, "Definition", None)
    if definition is None:
        return ""
    for method_name in ("GetDataType", "GetSpecTypeId", "ParameterType"):
        try:
            method_or_value = getattr(definition, method_name)
            value = method_or_value() if callable(method_or_value) else method_or_value
            if value is not None:
                return to_text(value)
        except Exception:
            pass
    return ""


def is_material_parameter(project_doc, param):
    if param is None:
        return False
    try:
        if param.StorageType != DB.StorageType.ElementId:
            return False
    except Exception:
        return False

    name = parameter_definition_name(param).lower()
    data_type_text = parameter_data_type_text(param).lower()
    try:
        definition = param.Definition
        data_type = definition.GetDataType() if definition is not None else None
    except Exception:
        data_type = None

    if MATERIAL_SPEC_ID is not None and same_forge_type_id(data_type, MATERIAL_SPEC_ID):
        return True
    if "material" in data_type_text or "material" in name:
        return True

    # Some content uses material-like names but still stores real material ElementIds.
    if any(token in name for token in ("finish", "fabric", "upholstery", "veneer", "laminate", "timber", "wood")):
        try:
            return is_valid_material_id(project_doc, param.AsElementId())
        except Exception:
            return True

    try:
        return is_valid_material_id(project_doc, param.AsElementId())
    except Exception:
        return False


def parameter_material_id(param):
    try:
        return param.AsElementId()
    except Exception:
        return DB.ElementId.InvalidElementId


def parameter_has_material_value(project_doc, param):
    try:
        return is_valid_material_id(project_doc, param.AsElementId())
    except Exception:
        return False


def iter_material_parameters(project_doc, element):
    if element is None:
        return []
    results = []
    seen = set()
    try:
        parameters = list(element.Parameters)
    except Exception:
        parameters = []
    for param in parameters:
        try:
            name = parameter_definition_name(param)
            if not name:
                continue
            key = (name.lower(), to_text(parameter_data_type_text(param)).lower())
            if key in seen:
                continue
            if not is_material_parameter(project_doc, param):
                continue
            seen.add(key)
            results.append(param)
        except Exception:
            continue
    return results


def collect_element_material_parameters(project_doc, element):
    params = []
    seen = set()

    def add_param(param):
        if param is None:
            return
        try:
            name = parameter_definition_name(param)
            key = (
                name.lower(),
                element_id_int(safe_attr(param, "Id", None)),
                to_text(parameter_data_type_text(param)).lower(),
            )
            if key in seen:
                return
            if not is_material_parameter(project_doc, param):
                return
            seen.add(key)
            params.append(param)
        except Exception:
            pass

    try:
        for param in list(element.Parameters):
            add_param(param)
    except Exception:
        pass

    built_in = safe_attr(DB, "BuiltInParameter", None)
    for built_in_name in ("MATERIAL_ID_PARAM", "MATERIAL_PARAM", "STRUCTURAL_MATERIAL_PARAM"):
        built_in_value = safe_attr(built_in, built_in_name, None)
        if built_in_value is None:
            continue
        try:
            add_param(element.get_Parameter(built_in_value))
        except Exception:
            pass

    return params


def family_parameter_name(family_param):
    definition = safe_attr(family_param, "Definition", None)
    return to_text(safe_attr(definition, "Name", None))


def family_parameter_key(family_param):
    return (
        "I" if bool(safe_attr(family_param, "IsInstance", False)) else "T",
        family_parameter_name(family_param).strip().lower(),
    )


def family_parameter_data_type_text(family_param):
    definition = safe_attr(family_param, "Definition", None)
    if definition is None:
        return ""
    for method_name in ("GetDataType", "GetSpecTypeId", "ParameterType"):
        try:
            method_or_value = getattr(definition, method_name)
            value = method_or_value() if callable(method_or_value) else method_or_value
            if value is not None:
                return to_text(value)
        except Exception:
            pass
    return ""


def family_parameter_current_material_id(family_manager, family_param):
    current_type = safe_attr(family_manager, "CurrentType", None)
    if current_type is None or family_param is None:
        return None
    try:
        return current_type.AsElementId(family_param)
    except Exception:
        return None


def is_family_material_parameter(family_doc, family_manager, family_param):
    if family_param is None:
        return False
    name = family_parameter_name(family_param).lower()
    data_type_text = family_parameter_data_type_text(family_param).lower()
    if "material" in data_type_text or "material" in name:
        return True
    try:
        definition = family_param.Definition
        data_type = definition.GetDataType() if definition is not None else None
        if MATERIAL_SPEC_ID is not None and same_forge_type_id(data_type, MATERIAL_SPEC_ID):
            return True
    except Exception:
        pass
    try:
        current_material_id = family_parameter_current_material_id(family_manager, family_param)
        if is_valid_material_id(family_doc, current_material_id):
            return True
    except Exception:
        pass
    if any(token in name for token in ("finish", "fabric", "upholstery", "veneer", "laminate", "timber", "wood")):
        return True
    return False


def family_parameter_by_name(family_manager, name):
    requested = (to_text(name) or "").strip().lower()
    if not requested:
        return None
    try:
        for family_param in family_manager.Parameters:
            if family_parameter_name(family_param).strip().lower() == requested:
                return family_param
    except Exception:
        pass
    try:
        return family_manager.get_Parameter(name)
    except Exception:
        return None


def family_material_parameter_options(family_doc, family_manager):
    options = []
    seen = set()
    try:
        family_params = list(family_manager.Parameters)
    except Exception:
        family_params = []
    for family_param in family_params:
        if not is_family_material_parameter(family_doc, family_manager, family_param):
            continue
        name = family_parameter_name(family_param)
        if not name:
            continue
        key = name.strip().lower()
        if key in seen:
            continue
        seen.add(key)
        is_instance = bool(safe_attr(family_param, "IsInstance", False))
        scope = "Instance" if is_instance else "Type"
        options.append({
            "name": name,
            "scope": scope,
            "is_instance": is_instance,
            "display": "%s: %s" % (scope, name),
        })
    return sorted(options, key=lambda item: (item["scope"], item["name"].lower()))


def safe_revit_parameter_name(value, fallback):
    text = to_text(value)
    text = re.sub(r"[\r\n\t]+", " ", text)
    text = re.sub(r"[\\/:*?\"<>|{}\[\];`~]+", "_", text)
    text = re.sub(r"\s+", " ", text).strip(" ._-")
    if not text:
        text = fallback
    if len(text) > 90:
        text = text[:90].strip(" ._-")
    return text or fallback


def proposed_parameter_name(row):
    raw = "MAL_%s_%s" % (row.ElementName or "Object", row.MaterialSlot or "Material")
    raw = re.sub(r"[^A-Za-z0-9]+", "_", raw).strip("_")
    if not raw:
        raw = "MAL_Object_Material"
    if "material" not in raw.lower():
        raw += "_Material"
    return safe_revit_parameter_name(raw, "MAL_Object_Material")


def unique_target_name(base_name, used_names):
    clean = safe_revit_parameter_name(base_name, "MAL_Object_Material")
    if clean.lower() not in used_names:
        used_names.add(clean.lower())
        return clean
    for index in range(2, 100):
        candidate = safe_revit_parameter_name("%s_%02d" % (clean, index), "MAL_Object_Material")
        if candidate.lower() not in used_names:
            used_names.add(candidate.lower())
            return candidate
    candidate = safe_revit_parameter_name("%s_%s" % (clean, len(used_names) + 1), "MAL_Object_Material")
    used_names.add(candidate.lower())
    return candidate


def material_family_parameter_scope_label(is_instance):
    return "Instance" if bool(is_instance) else "Type"


def add_or_get_material_family_parameter(family_manager, parameter_name, is_instance):
    existing = family_parameter_by_name(family_manager, parameter_name)
    if existing is not None:
        existing_is_instance = bool(safe_attr(existing, "IsInstance", False))
        if existing_is_instance == bool(is_instance):
            return existing, "reused_existing_%s_family_parameter" % material_family_parameter_scope_label(is_instance).lower()
        suffix = "INSTANCE" if bool(is_instance) else "TYPE"
        parameter_name = safe_revit_parameter_name("%s_%s" % (parameter_name, suffix), "MAL_Object_Material")

    attempts = []
    group_candidates = []
    spec_candidates = []

    for path in (("GroupTypeId", "Materials"), ("GroupTypeId", "General")):
        try:
            value = DB
            for attr_name in path:
                value = getattr(value, attr_name)
            group_candidates.append(value)
        except Exception:
            pass
    for attr_name in ("PG_MATERIALS", "PG_GENERAL"):
        try:
            group_candidates.append(getattr(DB.BuiltInParameterGroup, attr_name))
        except Exception:
            pass

    for path in (("SpecTypeId", "Reference", "Material"), ("SpecTypeId", "Material")):
        try:
            value = DB
            for attr_name in path:
                value = getattr(value, attr_name)
            spec_candidates.append(value)
        except Exception:
            pass
    try:
        spec_candidates.append(DB.ParameterType.Material)
    except Exception:
        pass

    for group in group_candidates:
        for spec in spec_candidates:
            try:
                family_param = family_manager.AddParameter(parameter_name, group, spec, bool(is_instance))
                return family_param, "created_%s_family_parameter" % material_family_parameter_scope_label(is_instance).lower()
            except Exception as exc:
                attempts.append("%s / %s / %s" % (to_text(group), to_text(spec), to_text(exc)))

    raise Exception("Could not add material family parameter '%s'. Attempts: %s" % (parameter_name, "; ".join(attempts)))


def associated_family_parameter_object(family_manager, param):
    try:
        return family_manager.GetAssociatedFamilyParameter(param)
    except Exception:
        return None


def can_associate(family_manager, param, family_param):
    for method_name in ("CanElementParameterBeAssociatedWithFamilyParameter", "CanElementParameterBeAssociated"):
        method = safe_attr(family_manager, method_name, None)
        if method is None:
            continue
        for args in ((param, family_param), (param,)):
            try:
                return bool(method(*args))
            except Exception:
                pass
    return True


def associate_parameter(family_manager, param, family_param):
    if not can_associate(family_manager, param, family_param):
        raise Exception("Revit reported that this material slot cannot be associated to the selected family parameter.")
    family_manager.AssociateElementParameterToFamilyParameter(param, family_param)


def get_type_element(project_doc, element):
    if element is None:
        return None
    try:
        type_id = element.GetTypeId()
    except Exception:
        return None
    if type_id is None or not is_valid_element_id(type_id):
        return None
    try:
        return project_doc.GetElement(type_id)
    except Exception:
        return None


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
        if family is None or family.IsInPlace:
            return False
    except Exception:
        return False
    return True


def is_family_model_object(element):
    if element is None:
        return False
    try:
        category = element.Category
        if category is not None and category.CategoryType == DB.CategoryType.Model:
            return True
    except Exception:
        pass

    # Some family geometry classes do not expose a useful model category.
    type_name = to_text(type(element)).lower()
    geometry_tokens = (
        "extrusion",
        "sweep",
        "sweptblend",
        "blend",
        "revolution",
        "form",
        "freeform",
        "importinstance",
    )
    return any(token in type_name for token in geometry_tokens)


class SlotRow(INotifyPropertyChanged):
    def __init__(
        self,
        element_id,
        element_name,
        category,
        material_slot,
        current_material,
        current_parameter,
        is_missing,
        is_read_only,
        notes,
        can_associate=True,
    ):
        self.ElementId = element_id
        self.ElementIdText = str(element_id)
        self.ElementName = element_name or "<Unnamed object>"
        self.Category = category or ""
        self.MaterialSlot = material_slot or "Material"
        self.CurrentMaterial = current_material or NO_MATERIAL
        self.CurrentParameter = current_parameter or ""
        self.IsMissing = bool(is_missing)
        self.IsReadOnly = bool(is_read_only)
        self.CanAssociate = bool(can_associate)
        self.TargetParameter = ""
        self.Scope = "Type"
        self.Notes = notes or ""
        if self.CurrentMaterial == BY_CATEGORY:
            self.MaterialState = "By Category"
        elif self.CurrentMaterial == NO_MATERIAL:
            self.MaterialState = "No material"
        else:
            self.MaterialState = "Material set"
        if self.IsMissing and self.CanAssociate:
            self.Status = "Missing"
            self.Action = "Needs parameter"
        elif self.IsMissing:
            self.Status = "No material slot"
            self.Action = "Review object"
        elif self.IsReadOnly:
            self.Status = "Linked"
            self.Action = "Parameter linked"
        else:
            self.Status = "OK"
            self.Action = "Parameter linked"
        self._property_changed_handlers = []

    def add_PropertyChanged(self, handler):
        self._property_changed_handlers.append(handler)

    def remove_PropertyChanged(self, handler):
        if handler in self._property_changed_handlers:
            self._property_changed_handlers.remove(handler)

    def notify_properties(self, *property_names):
        for property_name in property_names:
            args = PropertyChangedEventArgs(property_name)
            for handler in list(self._property_changed_handlers):
                try:
                    handler(self, args)
                except Exception:
                    pass

    def set_target(self, parameter_name, action, scope):
        self.TargetParameter = safe_revit_parameter_name(parameter_name, "MAL_Object_Material") if parameter_name else ""
        self.Action = action or ("Create or reuse" if self.TargetParameter else "Needs parameter")
        self.Scope = scope or self.Scope
        if self.IsMissing and self.CanAssociate and self.TargetParameter:
            self.Status = "Ready"
        elif self.IsMissing and self.CanAssociate:
            self.Status = "Missing"
        elif self.IsMissing:
            self.Status = "No material slot"
        self.notify_properties("TargetParameter", "Action", "Scope", "Status")

    def clear_target(self):
        self.TargetParameter = ""
        if self.IsMissing and self.CanAssociate:
            self.Action = "Needs parameter"
            self.Status = "Missing"
        elif self.IsMissing:
            self.Action = "Review object"
            self.Status = "No material slot"
        else:
            self.Action = "Parameter linked"
            self.Status = "Linked" if self.IsReadOnly else "OK"
        self.notify_properties("TargetParameter", "Action", "Status")


class ParameterRow(object):
    def __init__(
        self,
        name,
        scope,
        is_in_use,
        used_count,
        current_material,
        associated_objects,
        can_delete,
        notes,
    ):
        self.Name = name or ""
        self.Scope = scope or ""
        self.Status = "In use" if bool(is_in_use) else "Unused"
        self.InUse = "Yes" if bool(is_in_use) else "No"
        self.UsedCount = int(used_count or 0)
        self.CurrentMaterial = current_material or NO_MATERIAL
        self.AssociatedObjects = associated_objects or ""
        self.CanDelete = "Yes" if bool(can_delete) else "No"
        self.Notes = notes or ""
        self.IsInUse = bool(is_in_use)
        self.CanDeleteParameter = bool(can_delete)


def active_family_parameter_keys_from_doc(family_doc, family_manager):
    active_keys = set()
    try:
        elements = list(DB.FilteredElementCollector(family_doc).WhereElementIsNotElementType().ToElements())
    except Exception:
        elements = []
    for element in elements:
        if not is_family_model_object(element):
            continue
        for param in collect_element_material_parameters(family_doc, element):
            family_param = associated_family_parameter_object(family_manager, param)
            if family_param is None:
                continue
            active_keys.add(family_parameter_key(family_param))
    return active_keys


def build_parameter_rows(family_doc, family_manager, usage_by_key):
    rows = []
    try:
        family_params = list(family_manager.Parameters)
    except Exception:
        family_params = []

    for family_param in family_params:
        if not is_family_material_parameter(family_doc, family_manager, family_param):
            continue
        name = family_parameter_name(family_param)
        if not name:
            continue
        key = family_parameter_key(family_param)
        used_objects = usage_by_key.get(key, [])
        used_count = len(used_objects)
        is_in_use = used_count > 0
        is_instance = bool(safe_attr(family_param, "IsInstance", False))
        scope = material_family_parameter_scope_label(is_instance)
        current_material = material_name_from_id(
            family_doc,
            family_parameter_current_material_id(family_manager, family_param),
        )
        formula = to_text(safe_attr(family_param, "Formula", ""))
        notes = "Used by family geometry." if is_in_use else "Unused material parameter."
        if formula:
            notes += " Formula: %s" % formula
        associated_text = "; ".join(used_objects[:8])
        if len(used_objects) > 8:
            associated_text += "; +%s more" % (len(used_objects) - 8)
        rows.append(ParameterRow(
            name,
            scope,
            is_in_use,
            used_count,
            current_material,
            associated_text,
            not is_in_use,
            notes,
        ))

    return sorted(rows, key=lambda row: (row.IsInUse, row.Name.lower(), row.Scope.lower()))


def scan_selected_family(project_doc, family):
    family_doc = None
    try:
        family_doc = project_doc.EditFamily(family)
        family_manager = safe_attr(family_doc, "FamilyManager", None)
        if family_manager is None:
            raise Exception("Family document has no FamilyManager.")

        rows = []
        seen = set()
        usage_by_key = {}
        model_object_count = 0
        try:
            elements = list(DB.FilteredElementCollector(family_doc).WhereElementIsNotElementType().ToElements())
        except Exception:
            elements = []

        for element in elements:
            if not is_family_model_object(element):
                continue
            model_object_count += 1
            element_id = element_id_int(safe_attr(element, "Id", None))
            if element_id is None:
                continue
            material_params = collect_element_material_parameters(family_doc, element)
            if not material_params:
                continue
            for param in material_params:
                param_name = parameter_definition_name(param) or "Material"
                key = (element_id, param_name.lower())
                if key in seen:
                    continue
                seen.add(key)
                family_param = associated_family_parameter_object(family_manager, param)
                family_param_name = family_parameter_name(family_param) if family_param is not None else ""
                material_id = family_parameter_current_material_id(family_manager, family_param) if family_param is not None else parameter_material_id(param)
                material_name = material_name_from_id(family_doc, material_id)
                read_only = bool(safe_attr(param, "IsReadOnly", False))
                missing = family_param is None
                notes = ""
                if missing:
                    notes = "Material slot exists in the family but is not linked to a family parameter."
                    if read_only:
                        notes += " The Revit slot reports read-only; the tool will still try the family-parameter association API."
                elif read_only:
                    notes = "The Revit slot is read-only, but it is already linked to a family parameter."
                row = SlotRow(
                    element_id,
                    safe_name(element, "Family object %s" % element_id),
                    category_name(element),
                    param_name,
                    material_name,
                    family_param_name,
                    missing,
                    read_only,
                    notes,
                    can_associate=True,
                )
                rows.append(row)
                if family_param is not None:
                    param_key = family_parameter_key(family_param)
                    label = "%s [%s] %s" % (row.ElementName, row.ElementIdText, row.MaterialSlot)
                    usage_by_key.setdefault(param_key, []).append(label)

        existing_options = family_material_parameter_options(family_doc, family_manager)
        parameter_rows = build_parameter_rows(family_doc, family_manager, usage_by_key)
        family_title = safe_name(family, "<Unnamed family>")
        return {
            "family_name": family_title,
            "rows": sorted(rows, key=lambda row: (row.Status != "Missing", row.ElementName.lower(), row.MaterialSlot.lower(), row.ElementId)),
            "existing_options": existing_options,
            "parameter_rows": parameter_rows,
            "model_object_count": model_object_count,
        }
    finally:
        if family_doc is not None:
            try:
                family_doc.Close(False)
            except Exception:
                pass


class FamilyReloadOptions(DB.IFamilyLoadOptions):
    def OnFamilyFound(self, familyInUse, overwriteParameterValues):
        try:
            overwriteParameterValues.Value = True
        except Exception:
            pass
        return True

    def OnSharedFamilyFound(self, sharedFamily, familyInUse, source, overwriteParameterValues):
        try:
            source.Value = DB.FamilySource.Family
        except Exception:
            pass
        try:
            overwriteParameterValues.Value = True
        except Exception:
            pass
        return True


def resolve_family_material_slot(family_doc, element_id, material_slot):
    element = family_doc.GetElement(make_element_id(element_id))
    if element is None:
        return None, None
    requested = (material_slot or "").strip().lower()
    for param in collect_element_material_parameters(family_doc, element):
        if (parameter_definition_name(param) or "").strip().lower() == requested:
            return element, param
    return element, None


def apply_family_fixes(project_doc, family_id, rows_to_fix):
    family = project_doc.GetElement(make_element_id(family_id))
    if family is None:
        raise Exception("The selected family could not be found in the project.")
    if not is_editable_loadable_family(family):
        raise Exception("The selected family is not an editable loadable family.")

    family_doc = None
    transaction = None
    results = []
    try:
        family_doc = project_doc.EditFamily(family)
        family_manager = safe_attr(family_doc, "FamilyManager", None)
        if family_manager is None:
            raise Exception("Family document has no FamilyManager.")

        transaction = DB.Transaction(family_doc, "Object material parameter cleanup")
        transaction.Start()
        for row in rows_to_fix:
            try:
                target_name = safe_revit_parameter_name(row.TargetParameter, "MAL_Object_Material")
                if not target_name:
                    results.append({
                        "status": "skipped_no_target_parameter",
                        "row": row,
                    })
                    continue
                element, param = resolve_family_material_slot(family_doc, row.ElementId, row.MaterialSlot)
                if element is None or param is None:
                    results.append({
                        "status": "failed_slot_not_found",
                        "row": row,
                    })
                    continue
                is_instance = (row.Scope or "").strip().lower().startswith("inst")
                family_param, parameter_action = add_or_get_material_family_parameter(family_manager, target_name, is_instance)
                associate_parameter(family_manager, param, family_param)
                results.append({
                    "status": "associated",
                    "row": row,
                    "parameter_name": family_parameter_name(family_param),
                    "parameter_action": parameter_action,
                    "scope": material_family_parameter_scope_label(is_instance),
                })
            except Exception as exc:
                results.append({
                    "status": "failed",
                    "row": row,
                    "error": to_text(exc),
                })

        success_count = len([item for item in results if item.get("status") == "associated"])
        if success_count <= 0:
            transaction.RollBack()
            transaction = None
            return {
                "success_count": 0,
                "failed_count": len(results),
                "load_family_result": False,
                "results": results,
                "rolled_back": True,
            }

        transaction.Commit()
        transaction = None
        load_result = family_doc.LoadFamily(project_doc, FamilyReloadOptions())
        return {
            "success_count": success_count,
            "failed_count": len([item for item in results if item.get("status") not in ("associated",)]),
            "load_family_result": bool(load_result),
            "results": results,
            "rolled_back": False,
        }
    except Exception:
        try:
            if transaction is not None:
                transaction.RollBack()
        except Exception:
            pass
        raise
    finally:
        if family_doc is not None:
            try:
                family_doc.Close(False)
            except Exception:
                pass


def get_active_loadable_family_parameter_keys(project_doc, family):
    family_doc = None
    try:
        family_doc = project_doc.EditFamily(family)
        family_manager = safe_attr(family_doc, "FamilyManager", None)
        if family_manager is None:
            raise Exception("Family document has no FamilyManager.")
        return active_family_parameter_keys_from_doc(family_doc, family_manager)
    finally:
        if family_doc is not None:
            try:
                family_doc.Close(False)
            except Exception:
                pass


def cleanup_candidate_key(owner, param):
    return (
        element_id_int(safe_attr(owner, "Id", None)),
        parameter_definition_name(param).strip().lower(),
    )


def collect_unused_project_material_parameter_values(project_doc):
    candidates = []
    skipped_families = []
    active_cache = {}
    seen_instance = set()
    seen_type = set()

    try:
        instances = list(DB.FilteredElementCollector(project_doc).OfClass(DB.FamilyInstance).WhereElementIsNotElementType().ToElements())
    except Exception:
        instances = []

    for instance in instances:
        if not is_loadable_family_instance(instance):
            continue
        try:
            symbol = instance.Symbol
            family = symbol.Family
        except Exception:
            continue
        family_id = element_id_int(safe_attr(family, "Id", None))
        if family_id is None:
            continue
        if family_id not in active_cache:
            if not is_editable_loadable_family(family):
                active_cache[family_id] = None
                skipped_families.append("%s (not editable)" % safe_name(family, str(family_id)))
            else:
                try:
                    active_cache[family_id] = get_active_loadable_family_parameter_keys(project_doc, family)
                except Exception as exc:
                    active_cache[family_id] = None
                    skipped_families.append("%s (%s)" % (safe_name(family, str(family_id)), to_text(exc)))
        active_keys = active_cache.get(family_id)
        if active_keys is None:
            continue

        for param in iter_material_parameters(project_doc, instance):
            name = parameter_definition_name(param)
            if not name:
                continue
            key = cleanup_candidate_key(instance, param)
            if key in seen_instance:
                continue
            seen_instance.add(key)
            if ("I", name.strip().lower()) in active_keys:
                continue
            if bool(safe_attr(param, "IsReadOnly", False)):
                continue
            if not parameter_has_material_value(project_doc, param):
                continue
            candidates.append({
                "owner": instance,
                "parameter": param,
                "scope": "Instance",
                "family_name": safe_name(family, ""),
                "type_name": safe_name(symbol, ""),
                "owner_id": element_id_int(safe_attr(instance, "Id", None)),
                "parameter_name": name,
                "material_name": material_name_from_id(project_doc, parameter_material_id(param)),
            })

        type_element = get_type_element(project_doc, instance)
        for param in iter_material_parameters(project_doc, type_element):
            name = parameter_definition_name(param)
            if not name:
                continue
            key = cleanup_candidate_key(type_element, param)
            if key in seen_type:
                continue
            seen_type.add(key)
            if ("T", name.strip().lower()) in active_keys:
                continue
            if bool(safe_attr(param, "IsReadOnly", False)):
                continue
            if not parameter_has_material_value(project_doc, param):
                continue
            candidates.append({
                "owner": type_element,
                "parameter": param,
                "scope": "Type",
                "family_name": safe_name(family, ""),
                "type_name": safe_name(symbol, safe_name(type_element, "")),
                "owner_id": element_id_int(safe_attr(type_element, "Id", None)),
                "parameter_name": name,
                "material_name": material_name_from_id(project_doc, parameter_material_id(param)),
            })

    return {
        "candidates": candidates,
        "skipped_families": skipped_families,
        "audited_family_count": len([key for key, value in active_cache.items() if value is not None]),
    }


def clear_unused_project_material_parameter_values(project_doc, candidates):
    transaction = DB.Transaction(project_doc, "Clean unused loadable family material parameters")
    cleared = []
    failed = []
    try:
        transaction.Start()
        for candidate in candidates:
            param = candidate.get("parameter")
            try:
                param.Set(DB.ElementId.InvalidElementId)
                cleared.append(candidate)
            except Exception as exc:
                failed.append({
                    "candidate": candidate,
                    "error": to_text(exc),
                })
        transaction.Commit()
    except Exception:
        try:
            transaction.RollBack()
        except Exception:
            pass
        raise
    return {
        "cleared": cleared,
        "failed": failed,
    }


def delete_family_material_parameters(project_doc, family_id, parameter_rows):
    family = project_doc.GetElement(make_element_id(family_id))
    if family is None:
        raise Exception("The selected family could not be found in the project.")
    if not is_editable_loadable_family(family):
        raise Exception("The selected family is not an editable loadable family.")

    target_keys = set()
    for row in parameter_rows or []:
        name = (row.Name or "").strip().lower()
        if not name:
            continue
        prefix = "I" if (row.Scope or "").strip().lower().startswith("inst") else "T"
        target_keys.add((prefix, name))

    family_doc = None
    transaction = None
    deleted = []
    skipped = []
    failed = []
    try:
        family_doc = project_doc.EditFamily(family)
        family_manager = safe_attr(family_doc, "FamilyManager", None)
        if family_manager is None:
            raise Exception("Family document has no FamilyManager.")
        active_keys = active_family_parameter_keys_from_doc(family_doc, family_manager)

        transaction = DB.Transaction(family_doc, "Delete unused material family parameters")
        transaction.Start()
        try:
            family_params = list(family_manager.Parameters)
        except Exception:
            family_params = []
        for family_param in family_params:
            if not is_family_material_parameter(family_doc, family_manager, family_param):
                continue
            key = family_parameter_key(family_param)
            if key not in target_keys:
                continue
            name = family_parameter_name(family_param)
            scope = material_family_parameter_scope_label(bool(safe_attr(family_param, "IsInstance", False)))
            if key in active_keys:
                skipped.append({
                    "name": name,
                    "scope": scope,
                    "reason": "still_used_by_family_geometry",
                })
                continue
            try:
                family_manager.RemoveParameter(family_param)
                deleted.append({
                    "name": name,
                    "scope": scope,
                })
            except Exception as exc:
                failed.append({
                    "name": name,
                    "scope": scope,
                    "error": to_text(exc),
                })

        if not deleted:
            transaction.RollBack()
            transaction = None
            return {
                "deleted": deleted,
                "skipped": skipped,
                "failed": failed,
                "load_family_result": False,
                "rolled_back": True,
            }

        transaction.Commit()
        transaction = None
        load_result = family_doc.LoadFamily(project_doc, FamilyReloadOptions())
        return {
            "deleted": deleted,
            "skipped": skipped,
            "failed": failed,
            "load_family_result": bool(load_result),
            "rolled_back": False,
        }
    except Exception:
        try:
            if transaction is not None:
                transaction.RollBack()
        except Exception:
            pass
        raise
    finally:
        if family_doc is not None:
            try:
                family_doc.Close(False)
            except Exception:
                pass


def selected_loadable_family_instance(project_doc, ui_doc):
    try:
        selected_ids = list(ui_doc.Selection.GetElementIds())
    except Exception:
        selected_ids = []
    selected_instances = []
    for element_id in selected_ids:
        element = project_doc.GetElement(element_id)
        if is_loadable_family_instance(element):
            selected_instances.append(element)
    if len(selected_instances) != 1:
        raise Exception("Select exactly one loadable family instance in the model space, then run this tool.")
    instance = selected_instances[0]
    family = instance.Symbol.Family
    if not is_editable_loadable_family(family):
        raise Exception("The selected family is not editable or is an in-place family.")
    return instance, family


def print_apply_report(report):
    output.print_md("### Object Material Cleaner Apply Report")
    output.print_md("- Associated rows: `%s`" % report.get("success_count"))
    output.print_md("- Failed/skipped rows: `%s`" % report.get("failed_count"))
    output.print_md("- Family reload result: `%s`" % report.get("load_family_result"))
    for item in report.get("results") or []:
        row = item.get("row")
        label = "%s / %s / %s" % (row.ElementName, row.ElementIdText, row.MaterialSlot) if row is not None else ""
        if item.get("status") == "associated":
            output.print_md("- OK `%s` -> `%s` (%s)" % (label, item.get("parameter_name"), item.get("parameter_action")))
        elif item.get("error"):
            output.print_md("- Failed `%s`: %s" % (label, item.get("error")))
        else:
            output.print_md("- Skipped `%s`: %s" % (label, item.get("status")))


def print_cleanup_report(scan_report, clean_report):
    output.print_md("### Object Material Cleaner Project Cleanup")
    output.print_md("- Audited loadable families: `%s`" % scan_report.get("audited_family_count"))
    output.print_md("- Cleared stale material values: `%s`" % len(clean_report.get("cleared") or []))
    output.print_md("- Failed clears: `%s`" % len(clean_report.get("failed") or []))
    if scan_report.get("skipped_families"):
        output.print_md("- Skipped families: `%s`" % len(scan_report.get("skipped_families")))
    for candidate in (clean_report.get("cleared") or [])[:40]:
        output.print_md("- Cleared `%s` | `%s : %s` | `%s` = `%s`" % (
            candidate.get("scope"),
            candidate.get("family_name"),
            candidate.get("type_name"),
            candidate.get("parameter_name"),
            candidate.get("material_name"),
        ))
    for failure in clean_report.get("failed") or []:
        candidate = failure.get("candidate") or {}
        output.print_md("- Failed `%s : %s` `%s`: %s" % (
            candidate.get("family_name"),
            candidate.get("type_name"),
            candidate.get("parameter_name"),
            failure.get("error"),
        ))


def print_delete_parameter_report(report):
    output.print_md("### Object Material Cleaner Parameter Delete Report")
    output.print_md("- Deleted parameters: `%s`" % len(report.get("deleted") or []))
    output.print_md("- Skipped parameters: `%s`" % len(report.get("skipped") or []))
    output.print_md("- Failed parameters: `%s`" % len(report.get("failed") or []))
    output.print_md("- Family reload result: `%s`" % report.get("load_family_result"))
    for item in report.get("deleted") or []:
        output.print_md("- Deleted `%s` `%s`" % (item.get("scope"), item.get("name")))
    for item in report.get("skipped") or []:
        output.print_md("- Skipped `%s` `%s`: %s" % (item.get("scope"), item.get("name"), item.get("reason")))
    for item in report.get("failed") or []:
        output.print_md("- Failed `%s` `%s`: %s" % (item.get("scope"), item.get("name"), item.get("error")))


class ObjectMaterialCleanerWindow(forms.WPFWindow):
    def __init__(self, project_doc, selected_instance, family):
        xaml_path = os.path.join(os.path.dirname(__file__), "ui.xaml")
        forms.WPFWindow.__init__(self, xaml_path)
        self.project_doc = project_doc
        self.selected_instance_id = element_id_int(safe_attr(selected_instance, "Id", None))
        self.family_id = element_id_int(safe_attr(family, "Id", None))
        self.family_name = safe_name(family, "")
        self.existing_parameter_options = []
        self.rows = []
        self.parameter_rows = []
        self.model_object_count = 0
        self.reload_rows()

    def selected_new_scope(self):
        try:
            if bool(self.scope_instance_radio.IsChecked):
                return "Instance"
        except Exception:
            pass
        return "Type"

    def set_status(self, message):
        self.status_text.Text = to_text(message)

    def get_selected_target_rows(self):
        rows = []
        try:
            for item in self.rows_grid.SelectedItems:
                if isinstance(item, SlotRow):
                    rows.append(item)
        except Exception:
            pass
        rows = [row for row in rows if row.IsMissing and row.CanAssociate]
        if rows:
            return rows
        return [row for row in self.rows if row.IsMissing and row.CanAssociate]

    def get_ready_rows(self, selected_only=False):
        source_rows = []
        if selected_only:
            try:
                source_rows = [item for item in self.rows_grid.SelectedItems if isinstance(item, SlotRow)]
            except Exception:
                source_rows = []
        else:
            source_rows = self.rows
        return [
            row for row in source_rows
            if row.IsMissing
            and row.CanAssociate
            and (row.TargetParameter or "").strip()
        ]

    def get_selected_parameter_rows(self):
        rows = []
        try:
            for item in self.parameters_grid.SelectedItems:
                if isinstance(item, ParameterRow):
                    rows.append(item)
        except Exception:
            pass
        return rows

    def get_deletable_parameter_rows(self, selected_only=True):
        rows = self.get_selected_parameter_rows() if selected_only else self.parameter_rows
        return [row for row in rows if row.CanDeleteParameter]

    def reload_rows(self):
        family = self.project_doc.GetElement(make_element_id(self.family_id))
        if family is None:
            raise Exception("The selected family could not be found after reload.")
        audit = scan_selected_family(self.project_doc, family)
        self.family_name = audit.get("family_name") or self.family_name
        self.rows = audit.get("rows") or []
        self.existing_parameter_options = audit.get("existing_options") or []
        self.parameter_rows = audit.get("parameter_rows") or []
        self.model_object_count = audit.get("model_object_count") or 0
        self.rows_grid.ItemsSource = self.rows
        self.rows_grid.Items.Refresh()
        self.parameters_grid.ItemsSource = self.parameter_rows
        self.parameters_grid.Items.Refresh()
        self.update_summary()
        missing_count = len([row for row in self.rows if row.IsMissing and row.CanAssociate])
        unused_count = len([row for row in self.parameter_rows if not row.IsInUse])
        self.set_status("%s missing object material slots need a parameter. %s material parameters are unused." % (missing_count, unused_count))

    def update_summary(self):
        missing = len([row for row in self.rows if row.IsMissing])
        read_only = len([row for row in self.rows if row.IsReadOnly])
        associated = len([row for row in self.rows if not row.IsMissing])
        unused_params = len([row for row in self.parameter_rows if not row.IsInUse])
        used_params = len([row for row in self.parameter_rows if row.IsInUse])
        self.summary_text.Text = (
            "Selected family: %s | Model objects scanned: %s | Object material slots: %s | Associated: %s | Missing parameter: %s | Read-only linked slots: %s | Material parameters: %s used / %s unused"
            % (self.family_name, self.model_object_count, len(self.rows), associated, missing, read_only, used_params, unused_params)
        )

    def on_use_existing_parameter(self, sender, args):
        target_rows = self.get_selected_target_rows()
        if not target_rows:
            self.set_status("No missing editable rows are selected.")
            return
        if not self.existing_parameter_options:
            forms.alert("This family has no existing material family parameters to reuse.", title=COMMAND_TITLE, warn_icon=True)
            return
        display_to_option = dict((item["display"], item) for item in self.existing_parameter_options)
        selected = forms.SelectFromList.show(
            sorted(display_to_option.keys()),
            title="Use Existing Family Material Parameter",
            multiselect=False,
            button_name="Use Parameter",
        )
        if not selected:
            self.set_status("Existing parameter selection cancelled.")
            return
        option = display_to_option.get(selected)
        if option is None:
            return
        for row in target_rows:
            row.set_target(option.get("name"), "Reuse existing", option.get("scope"))
        self.rows_grid.Items.Refresh()
        self.set_status("Existing parameter assigned to %s rows." % len(target_rows))

    def on_new_parameter(self, sender, args):
        target_rows = self.get_selected_target_rows()
        if not target_rows:
            self.set_status("No missing editable rows are selected.")
            return
        default_name = proposed_parameter_name(target_rows[0])
        parameter_name = forms.ask_for_string(
            default=default_name,
            prompt="New family material parameter name for the selected missing object material slots.",
            title=COMMAND_TITLE,
        )
        if parameter_name is None:
            self.set_status("New parameter entry cancelled.")
            return
        parameter_name = safe_revit_parameter_name(parameter_name, default_name)
        if not parameter_name:
            self.set_status("No parameter name was entered.")
            return
        scope = self.selected_new_scope()
        for row in target_rows:
            row.set_target(parameter_name, "Create or reuse", scope)
        self.rows_grid.Items.Refresh()
        self.set_status("New target parameter set on %s rows." % len(target_rows))

    def on_auto_name(self, sender, args):
        target_rows = self.get_selected_target_rows()
        if not target_rows:
            self.set_status("No missing editable rows are available.")
            return
        used_names = set([item.get("name", "").lower() for item in self.existing_parameter_options])
        for row in self.rows:
            if row.TargetParameter:
                used_names.add(row.TargetParameter.lower())
        scope = self.selected_new_scope()
        for row in target_rows:
            row.set_target(unique_target_name(proposed_parameter_name(row), used_names), "Create new", scope)
        self.rows_grid.Items.Refresh()
        self.set_status("Automatic parameter names assigned to %s rows." % len(target_rows))

    def on_clear_target(self, sender, args):
        target_rows = []
        try:
            target_rows = [item for item in self.rows_grid.SelectedItems if isinstance(item, SlotRow)]
        except Exception:
            target_rows = []
        if not target_rows:
            target_rows = self.rows
        for row in target_rows:
            if row.IsMissing:
                row.clear_target()
        self.rows_grid.Items.Refresh()
        self.set_status("Cleared target parameter names.")

    def on_apply_fixes(self, sender, args):
        selected_ready = self.get_ready_rows(selected_only=True)
        ready_rows = selected_ready or self.get_ready_rows(selected_only=False)
        if not ready_rows:
            self.set_status("No missing rows have a target parameter yet.")
            return
        message = "Apply family parameter associations for %s missing material slots in %s?" % (len(ready_rows), self.family_name)
        result = forms.alert(
            message,
            title=COMMAND_TITLE,
            options=["Apply", "Cancel"],
            warn_icon=True,
        )
        if result != "Apply":
            self.set_status("Apply cancelled.")
            return
        report = apply_family_fixes(self.project_doc, self.family_id, ready_rows)
        print_apply_report(report)
        forms.alert(
            "Associated %s material slots. Failed or skipped: %s." % (report.get("success_count"), report.get("failed_count")),
            title=COMMAND_TITLE,
        )
        self.reload_rows()

    def on_refresh(self, sender, args):
        self.reload_rows()

    def on_delete_selected_parameters(self, sender, args):
        selected_rows = self.get_selected_parameter_rows()
        if not selected_rows:
            self.set_status("No material parameters are selected.")
            return
        deletable_rows = [row for row in selected_rows if row.CanDeleteParameter]
        used_rows = [row for row in selected_rows if row.IsInUse]
        if not deletable_rows:
            self.set_status("Selected parameters are in use by family geometry; nothing was deleted.")
            return
        message = "Delete %s selected unused material parameters from %s?" % (len(deletable_rows), self.family_name)
        if used_rows:
            message += "\n\n%s selected parameters are in use and will be skipped." % len(used_rows)
        message += "\n\nThis removes the family parameters, not just their material values."
        result = forms.alert(
            message,
            title=COMMAND_TITLE,
            options=["Delete Parameters", "Cancel"],
            warn_icon=True,
        )
        if result != "Delete Parameters":
            self.set_status("Parameter deletion cancelled.")
            return
        report = delete_family_material_parameters(self.project_doc, self.family_id, deletable_rows)
        print_delete_parameter_report(report)
        forms.alert(
            "Deleted %s parameters. Skipped: %s. Failed: %s." % (
                len(report.get("deleted") or []),
                len(report.get("skipped") or []),
                len(report.get("failed") or []),
            ),
            title=COMMAND_TITLE,
        )
        self.reload_rows()

    def on_delete_all_unused_parameters(self, sender, args):
        deletable_rows = self.get_deletable_parameter_rows(selected_only=False)
        if not deletable_rows:
            self.set_status("No unused material parameters are available to delete.")
            return
        preview = ["%s: %s" % (row.Scope, row.Name) for row in deletable_rows[:12]]
        message = (
            "Delete all %s unused material parameters from %s?\n\n%s"
            % (len(deletable_rows), self.family_name, "\n".join(preview))
        )
        if len(deletable_rows) > len(preview):
            message += "\n...and %s more." % (len(deletable_rows) - len(preview))
        message += "\n\nParameters still associated to family geometry will be skipped."
        result = forms.alert(
            message,
            title=COMMAND_TITLE,
            options=["Delete All Unused", "Cancel"],
            warn_icon=True,
        )
        if result != "Delete All Unused":
            self.set_status("Delete all unused cancelled.")
            return
        report = delete_family_material_parameters(self.project_doc, self.family_id, deletable_rows)
        print_delete_parameter_report(report)
        forms.alert(
            "Deleted %s unused parameters. Skipped: %s. Failed: %s." % (
                len(report.get("deleted") or []),
                len(report.get("skipped") or []),
                len(report.get("failed") or []),
            ),
            title=COMMAND_TITLE,
        )
        self.reload_rows()

    def on_clean_project_unused(self, sender, args):
        scan_report = collect_unused_project_material_parameter_values(self.project_doc)
        candidates = scan_report.get("candidates") or []
        if not candidates:
            message = "No assigned unused loadable-family material parameter values were found."
            if scan_report.get("skipped_families"):
                message += "\n\nSkipped families: %s" % len(scan_report.get("skipped_families"))
            forms.alert(message, title=COMMAND_TITLE)
            self.set_status(message)
            return

        preview_lines = []
        for candidate in candidates[:12]:
            preview_lines.append("%s | %s : %s | %s = %s" % (
                candidate.get("scope"),
                candidate.get("family_name"),
                candidate.get("type_name"),
                candidate.get("parameter_name"),
                candidate.get("material_name"),
            ))
        message = (
            "Found %s assigned material parameter values that are not associated to family geometry across %s audited families.\n\n"
            "Clear these values to <none>/<By Category> in the project?\n\n%s"
            % (len(candidates), scan_report.get("audited_family_count"), "\n".join(preview_lines))
        )
        if len(candidates) > len(preview_lines):
            message += "\n...and %s more." % (len(candidates) - len(preview_lines))
        if scan_report.get("skipped_families"):
            message += "\n\nSkipped families that could not be confidently audited: %s" % len(scan_report.get("skipped_families"))

        result = forms.alert(
            message,
            title=COMMAND_TITLE,
            options=["Clear Unused", "Cancel"],
            warn_icon=True,
        )
        if result != "Clear Unused":
            self.set_status("Project cleanup cancelled.")
            return

        clean_report = clear_unused_project_material_parameter_values(self.project_doc, candidates)
        print_cleanup_report(scan_report, clean_report)
        forms.alert(
            "Cleared %s stale material parameter values. Failed: %s." % (
                len(clean_report.get("cleared") or []),
                len(clean_report.get("failed") or []),
            ),
            title=COMMAND_TITLE,
        )
        self.set_status("Project cleanup complete.")

    def on_close(self, sender, args):
        self.Close()


def main():
    output.set_title(COMMAND_TITLE)
    selected_instance, family = selected_loadable_family_instance(doc, uidoc)
    window = ObjectMaterialCleanerWindow(doc, selected_instance, family)
    window.ShowDialog()


try:
    main()
except Exception as exc:
    details = traceback.format_exc()
    output.print_md("### Object Material Cleaner Error")
    output.print_md("```text\n%s\n```" % details)
    forms.alert("%s\n\nSee pyRevit output for details." % to_text(exc), title=COMMAND_TITLE, warn_icon=True)
