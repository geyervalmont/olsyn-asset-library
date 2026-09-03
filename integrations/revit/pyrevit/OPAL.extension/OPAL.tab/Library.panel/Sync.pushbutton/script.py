# -*- coding: utf-8 -*-
"""Match every material in the document against the library and report."""
__title__ = "Sync"
__doc__ = "Resolve each project material to an OPAL variant (by written-back code, identity, or name) and list what matched."

from pyrevit import script
import opal_revit

workflow, config = opal_revit.build_workflow()
report = workflow.sync()

output = script.get_output()
output.print_md("### OPAL sync — %s" % workflow.host.document_name())
rows = []
for material, variant, reference in report.matched:
    rows.append([material.name, variant["code"], variant.get("material_name", ""), reference])
if rows:
    output.print_table(rows, columns=["Revit material", "Variant", "Material", "Matched via"], title="Matched")
if report.unmatched:
    output.print_table([[m.name, m.parameters.get("Description", "")] for m in report.unmatched], columns=["Revit material", "Description"], title="Unmatched")
output.print_md("**%s**" % report.summary())
