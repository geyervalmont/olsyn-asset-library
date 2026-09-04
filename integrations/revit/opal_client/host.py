# -*- coding: utf-8 -*-
"""
Hosts: what a client application looks like to the workflow.

`RevitHost` (in the pyRevit extension) wraps the Revit API. `UnixHost`
stores a project as JSON so the same workflow runs and is tested on Linux.
"""

import json
import os


class HostMaterial(object):
    """A material as the host knows it."""

    def __init__(self, host_id, name, parameters=None, textures=None, scale_mm=None):
        self.host_id = host_id
        self.name = name
        self.parameters = parameters or {}
        self.textures = textures or {}
        self.scale_mm = scale_mm

    def references(self):
        """Strings that may identify the library variant, most specific first."""
        candidates = [self.host_id]
        for key in ("Description", "Keywords", "Model", "Manufacturer"):
            value = self.parameters.get(key)
            if value:
                candidates.append(value)
        candidates.append(self.name)
        return [c for c in candidates if c]

    def to_dict(self):
        return {
            "id": self.host_id,
            "name": self.name,
            "parameters": self.parameters,
            "textures": self.textures,
            "scale_mm": self.scale_mm,
        }


class Host(object):
    """The operations a workflow needs from the client application."""

    platform = "unknown"

    def document_name(self):
        raise NotImplementedError

    def materials(self):
        raise NotImplementedError

    def material(self, host_id):
        for material in self.materials():
            if material.host_id == host_id:
                return material
        return None

    def selected_material(self):
        raise NotImplementedError

    def apply_textures(self, material, textures, scale_mm):
        """textures: role → absolute local path (base_color, bump, glossiness ...)."""
        raise NotImplementedError

    def write_parameters(self, material, parameters):
        raise NotImplementedError

    def save(self):
        pass


class UnixHost(Host):
    """
    A JSON project file standing in for a Revit document, so the whole
    sync/apply loop runs on a Unix box against a real drive mount.
    """

    platform = "revit"

    def __init__(self, project_path):
        self.project_path = project_path
        if os.path.isfile(project_path):
            with open(project_path, "r") as handle:
                self.data = json.load(handle)
        else:
            self.data = {"name": os.path.basename(project_path), "materials": [], "selected": None}

    def document_name(self):
        return self.data.get("name", "project")

    def materials(self):
        return [
            HostMaterial(m["id"], m["name"], m.get("parameters"), m.get("textures"), m.get("scale_mm"))
            for m in self.data.get("materials", [])
        ]

    def selected_material(self):
        return self.material(self.data.get("selected"))

    def add_material(self, host_id, name, parameters=None):
        self.data.setdefault("materials", []).append({
            "id": host_id, "name": name, "parameters": parameters or {}, "textures": {}, "scale_mm": None,
        })
        self.save()

    def select(self, host_id):
        self.data["selected"] = host_id
        self.save()

    def apply_textures(self, material, textures, scale_mm):
        record = self._record(material.host_id)
        record["textures"] = dict(textures)
        record["scale_mm"] = scale_mm
        material.textures = dict(textures)
        material.scale_mm = scale_mm

    def write_parameters(self, material, parameters):
        record = self._record(material.host_id)
        record.setdefault("parameters", {}).update(parameters)
        material.parameters.update(parameters)

    def save(self):
        with open(self.project_path, "w") as handle:
            json.dump(self.data, handle, indent=2, sort_keys=True)

    def _record(self, host_id):
        for record in self.data.get("materials", []):
            if record["id"] == host_id:
                return record
        raise KeyError(host_id)


class SnapshotHost(Host):
    """
    Plain data captured on the host's own thread (Revit's), so lookups can run
    elsewhere. Read-only: apply must go through the real host.
    """

    def __init__(self, platform, document_name, materials):
        self.platform = platform
        self._document_name = document_name
        self._materials = list(materials)

    def document_name(self):
        return self._document_name

    def materials(self):
        return list(self._materials)

    def selected_material(self):
        return None

    def apply_textures(self, material, textures, scale_mm):
        raise RuntimeError("a snapshot cannot apply textures")

    def write_parameters(self, material, parameters):
        raise RuntimeError("a snapshot cannot write parameters")
