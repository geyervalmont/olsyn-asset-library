# -*- coding: utf-8 -*-
"""Show the state of the live connection to the library."""
__title__ = "Status"
__doc__ = "Session, connection, last command handled and any error from the OPAL agent."

from pyrevit import forms, script
from opal_client import Config
import opal_revit

config = Config().load()
status = opal_revit.runner.status()
output = script.get_output()
output.print_md("### OPAL connection")
rows = [
    ["Linked as", (config.get("user") or {}).get("email") or "not linked"],
    ["API", config.get("api") or "-"],
    ["Drive", "%s at %s" % (config.get("drive") or "-", config.get("mount") or "-")],
    ["Agent running", "yes" if status.get("running") else "no"],
    ["Realtime connected", "yes" if status.get("connected") else "no"],
    ["Session", str(status.get("session_id") or "-")],
    ["Channel", status.get("channel") or "-"],
    ["Document", status.get("document") or "-"],
    ["Commands handled", str(status.get("handled") or 0)],
    ["Queued", str(status.get("queued") or 0)],
    ["Last command", str(status.get("last_command") or "-")],
    ["Last error", str(status.get("last_error") or "-")],
    ["Config", status.get("config") or "-"],
]
output.print_table(rows, columns=["", ""])
if not status.get("running") and config.get("token"):
    if forms.alert("The agent is not running. Start it now?", yes=True, no=True):
        opal_revit.runner.ensure_event()
        opal_revit.runner.thread = None
        opal_revit.runner.start(config, document_title=opal_revit.current_document_title())
        forms.toast("OPAL agent started.", title="OPAL")
