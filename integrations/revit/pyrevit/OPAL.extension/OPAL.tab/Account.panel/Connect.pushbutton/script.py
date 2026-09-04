# -*- coding: utf-8 -*-
"""Link this Revit to an OPAL account and connect to the library."""
__title__ = "Connect"
__doc__ = "Shows a short code to enter in the OPAL web app while signed in. Once claimed, saves the token here and connects this Revit so Apply and Sync from the web app run in it."

import threading

from pyrevit import forms
from opal_client.link import Config, start_link, wait_for_link
import opal_revit

config = Config()
current = config.load()

if current.get("token") and opal_revit.runner.running():
    if not forms.alert("This Revit is already linked and connected.\n\nLink again as a different account?", yes=True, no=True):
        raise SystemExit

api = current.get("api") or forms.ask_for_string(default="https://asset-library.test", prompt="OPAL address", title="OPAL")
if not api:
    raise SystemExit
drive = current.get("drive") or forms.ask_for_string(default="studio-share", prompt="Drive slug (Drives page)", title="OPAL")
mount = current.get("mount") or forms.ask_for_string(default="M:\\", prompt="Where that drive is mapped on this machine", title="OPAL")
if not drive or not mount:
    raise SystemExit

# Everything that touches Revit or a dialog happens here, on the Revit
# thread. The background thread below only polls the library over HTTP and
# reports back through runner.notify(), which shows its toast on the Revit
# thread via the ExternalEvent. WPF from a worker thread kills Revit.
opal_revit.runner.ensure_event()
document_title = opal_revit.current_document_title()
verify_tls = current.get("verify_tls", True)

try:
    link_api, started = start_link(api, "revit", machine=opal_revit.machine_name(),
                                   app_version=opal_revit.app_version(), verify_tls=verify_tls, log=opal_revit.log_line)
except Exception as error:
    forms.alert("Could not reach OPAL at %s:\n\n%s" % (api, error), title="OPAL")
    raise SystemExit


def finish():
    try:
        result = wait_for_link(link_api, started)
    except Exception as error:
        opal_revit.runner.last_error = "link failed: %s" % error
        opal_revit.runner.notify("OPAL link failed: %s" % error)
        return
    try:
        saved = config.update(api=api, drive=drive, mount=mount, token=result["token"], user=result["user"],
                              realtime=result["realtime"], verify_tls=verify_tls)
        opal_revit.runner.stop()
        opal_revit.runner.thread = None
        opal_revit.runner.start(saved, document_title=document_title)
        opal_revit.runner.notify("Linked as %s. Connected to the library." % ((result["user"] or {}).get("email", "?")))
    except Exception as error:
        opal_revit.runner.last_error = "linked, but the agent did not start: %s" % error
        opal_revit.runner.notify("OPAL: linked, but the agent did not start: %s" % error)


worker = threading.Thread(target=finish)
worker.daemon = True
worker.start()

try:
    opal_revit.open_url(started.get("verify_url", ""))
except Exception:
    pass

forms.alert(
    "Enter this code in OPAL while signed in:\n\n        %s\n\n%s\n\nA browser tab has opened. Close this dialog; a toast confirms the link."
    % (started["code"], started.get("verify_url", "")),
    title="OPAL link",
)
