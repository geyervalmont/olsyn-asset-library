# -*- coding: utf-8 -*-
"""Pick a face, choose a library variant, apply its files from the OPAL drive."""
__title__ = "Apply\nSelected"
__doc__ = "Search the library, then set the picked material's appearance textures to the drive paths of the chosen variant, write its code into the material identity, and register the Revit identity with the library."

from pyrevit import forms
import opal_revit

workflow, config = opal_revit.build_workflow()
material = workflow.host.selected_material()
if material is None:
    forms.alert("Pick a face with a material, or select one material in the browser.", exitscript=True)

query = forms.ask_for_string(default=material.name, prompt="Search the library for", title="OPAL")
if not query:
    raise SystemExit

results = workflow.api.search(query, per_page=50)["data"]
options = {}
for item in results:
    for variant in item.get("variants", []):
        options["%s — %s  [%s]" % (item["name"], variant["name"], variant["code"])] = variant["code"]
if not options:
    forms.alert("Nothing in the library matches '%s'." % query, exitscript=True)

choice = forms.SelectFromList.show(sorted(options), title="Apply to %s" % material.name, button_name="Plan")
if not choice:
    raise SystemExit

plan = workflow.plan(material, options[choice])
opal_revit.print_plan(plan)
if not plan.ready():
    forms.alert("Cannot apply yet; see the output window.", exitscript=True)
if forms.alert("Apply %s to %s?" % (plan.variant["code"], material.name), yes=True, no=True):
    identity = workflow.apply(plan)
    forms.alert("Applied. Registered %s as %s." % (material.name, identity["variant"]))
