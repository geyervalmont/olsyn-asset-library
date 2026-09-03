# -*- coding: utf-8 -*-
"""Pick a face and show which library variant its material is."""
__title__ = "Resolve\nClicked"
__doc__ = "Look the picked material up in the library by its Revit identity, written-back code, or name, and show where its files sit on the drive."

from pyrevit import forms, script
import opal_revit

workflow, config = opal_revit.build_workflow()
material = workflow.host.selected_material()
if material is None:
    forms.alert("Pick a face with a material.", exitscript=True)

variant, reference = workflow.resolve_material(material)
output = script.get_output()
if variant is None:
    output.print_md("**%s** is not in the library. Tried: %s" % (material.name, ", ".join(material.references())))
    raise SystemExit

output.print_md("### %s → `%s`" % (material.name, variant["code"]))
output.print_md("Matched via `%s`. %s — %s" % (reference, variant.get("material_name", ""), variant["name"]))
paths = workflow.api.variant_paths(variant["code"], workflow.drive.slug)
rows = [[e["target"], e["quality"], e["role"], workflow.drive.local_path(e["path"]), "yes" if workflow.drive.exists(e["path"]) else "no"] for e in paths["files"]]
if rows:
    output.print_table(rows, columns=["Target", "Quality", "Role", "Path on drive", "Present"])
else:
    output.print_md("No published files on drive %s yet." % workflow.drive.slug)
