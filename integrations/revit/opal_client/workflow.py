# -*- coding: utf-8 -*-
"""
The operations behind the ribbon buttons, independent of Revit:

  sync     resolve every host material to a library variant and report
  plan     what applying a variant to a host material would do
  apply    execute the plan: textures from the drive, parameters, identity
"""

# Revit reads the "revit" target; fall back to the canonical maps.
TARGET_PREFERENCE = ("revit", "pbr")

# Revit appearance slots, in the order we try roles for them.
SLOT_ROLES = {
    "base_color": ("base_color",),
    "bump": ("bump", "normal", "height"),
    "glossiness": ("glossiness", "roughness"),
}


class SyncReport(object):
    def __init__(self):
        self.matched = []      # (HostMaterial, variant dict, reference)
        self.unmatched = []    # HostMaterial
        self.ambiguous = []    # reserved: the resolver never guesses

    def summary(self):
        return "%d matched, %d unmatched" % (len(self.matched), len(self.unmatched))


class ApplyPlan(object):
    def __init__(self, material, variant, drive_slug):
        self.material = material
        self.variant = variant
        self.drive_slug = drive_slug
        self.target = None
        self.quality = None
        self.textures = {}        # slot → local path
        self.missing = []         # entries not present on the mount
        self.mismatched = []      # entries whose bytes differ from the library
        self.scale_mm = None
        self.parameters = {}
        self.problems = []

    def ready(self):
        return bool(self.textures) and not self.missing and not self.mismatched and not self.problems

    def describe(self):
        lines = ["Apply %s → %s (%s)" % (self.variant["code"], self.material.name, self.material.host_id)]
        lines.append("  drive: %s  target: %s  quality: %s" % (self.drive_slug, self.target, self.quality))
        for slot, path in sorted(self.textures.items()):
            lines.append("  %-11s %s" % (slot, path))
        if self.scale_mm:
            lines.append("  real-world size: %s mm" % self.scale_mm)
        for key, value in sorted(self.parameters.items()):
            lines.append("  %-11s = %s" % (key, value))
        for entry in self.missing:
            lines.append("  MISSING    %s" % entry["path"])
        for entry in self.mismatched:
            lines.append("  MISMATCH   %s" % entry["path"])
        for problem in self.problems:
            lines.append("  PROBLEM    %s" % problem)
        lines.append("  ready: %s" % ("yes" if self.ready() else "no"))
        return "\n".join(lines)


class Workflow(object):
    def __init__(self, api, host, drive):
        self.api = api
        self.host = host
        self.drive = drive

    # -- sync --------------------------------------------------------------

    def resolve_material(self, material):
        """Try the material's references in order; return (variant, reference) or (None, None)."""
        for reference in material.references():
            variant = self.api.resolve(self.host.platform, reference)
            if variant is not None:
                return variant, reference
        return None, None

    def sync(self):
        report = SyncReport()
        for material in self.host.materials():
            variant, reference = self.resolve_material(material)
            if variant is None:
                report.unmatched.append(material)
            else:
                report.matched.append((material, variant, reference))
        return report

    # -- apply -------------------------------------------------------------

    def plan(self, material, variant_code, quality=None):
        variant = self.api.variant(variant_code)
        plan = ApplyPlan(material, variant, self.drive.slug)

        if not self.drive.mounted():
            plan.problems.append("drive %s is not mounted: %s" % (self.drive.slug, self.drive.mount_error()))
            return plan

        paths = self.api.variant_paths(variant["code"], self.drive.slug)
        if not paths["published"]:
            plan.problems.append("%s has no published version; nothing is on the drive yet" % variant["material_code"])
            return plan

        chosen = self._choose_representation(paths["files"], quality)
        if chosen is None:
            plan.problems.append("no revit or pbr files for %s on drive %s" % (variant["code"], self.drive.slug))
            return plan

        plan.target, plan.quality, entries = chosen
        for entry in self.drive.check(entries):
            if not entry["present"]:
                plan.missing.append(entry)
            elif entry["hash_matches"] is False:
                plan.mismatched.append(entry)

        by_role = dict((e["role"], e) for e in entries)
        for slot, roles in SLOT_ROLES.items():
            for role in roles:
                if role in by_role:
                    plan.textures[slot] = self.drive.local_path(by_role[role]["path"])
                    break

        plan.scale_mm = variant.get("tile_width_mm") or None
        plan.parameters = {
            "Description": "%s [%s]" % (variant["name"], variant["code"]),
            "Model": variant["code"],
            "Manufacturer": variant.get("material_code") or "",
            "Keywords": "opal, %s" % variant["code"],
        }
        return plan

    def apply(self, plan, external_id=None):
        if not plan.ready():
            raise RuntimeError("plan is not ready:\n" + plan.describe())

        self.host.apply_textures(plan.material, plan.textures, plan.scale_mm)
        self.host.write_parameters(plan.material, plan.parameters)
        self.host.save()

        return self.api.register_identity(
            plan.variant["code"],
            self.host.platform,
            external_id=external_id or plan.material.host_id,
            external_name=plan.material.name,
            payload={
                "document": self.host.document_name(),
                "drive": plan.drive_slug,
                "target": plan.target,
                "quality": plan.quality,
                "textures": plan.textures,
            },
        )

    def _choose_representation(self, files, quality=None):
        """(target, quality, entries) for the preferred target; highest quality unless asked."""
        groups = {}
        for entry in files:
            key = (entry["target"], entry["quality"])
            groups.setdefault(key, []).append(entry)

        for target in TARGET_PREFERENCE:
            candidates = [(q, entries) for (t, q), entries in groups.items() if t == target]
            if not candidates:
                continue
            if quality:
                for q, entries in candidates:
                    if q == quality:
                        return target, q, entries
            candidates.sort(key=lambda item: _pixels(item[0]), reverse=True)
            q, entries = candidates[0]
            return target, q, entries
        return None


def _pixels(quality_slug):
    slug = quality_slug.lower()
    if slug.endswith("k"):
        try:
            return int(slug[:-1]) * 1024
        except ValueError:
            return 0
    if slug.endswith("px"):
        try:
            return int(slug[:-2])
        except ValueError:
            return 0
    return {"preview": 512}.get(slug, 0)
