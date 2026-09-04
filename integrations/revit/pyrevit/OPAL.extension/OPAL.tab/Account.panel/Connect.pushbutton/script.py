# -*- coding: utf-8 -*-
"""Link this Revit to an OPAL account and connect to the library."""
__title__ = "Connect"
__doc__ = "Shows a short code to enter in the OPAL web app while signed in. Once claimed, saves the token here and connects this Revit so Apply and Sync from the web app run in it."

import threading

from pyrevit import forms
from opal_client import Config, LinkExpired, link
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

# The ExternalEvent must be created here, on the Revit thread, before the
# background link thread starts the agent.
opal_revit.runner.ensure_event()


def show_code(started):
    forms.alert(
        "Enter this code in OPAL while signed in:\n\n        %s\n\n%s\n\nThe code expires in a few minutes. A browser tab opens now; this dialog can be closed."
        % (started["code"], started.get("verify_url", "")),
        title="OPAL link",
    )


def finish():
    try:
        result = link(api, "revit", machine=opal_revit.machine_name(), app_version=opal_revit.app_version(),
                      open_browser=opal_revit.open_url, poll=show_code, verify_tls=current.get("verify_tls", True))
    except LinkExpired as error:
        forms.toast("OPAL link failed: %s" % error, title="OPAL")
        return
    except Exception as error:
        forms.toast("OPAL link failed: %s" % error, title="OPAL")
        return
    saved = config.update(api=api, drive=drive, mount=mount, token=result["token"], user=result["user"],
                          realtime=result["realtime"], verify_tls=current.get("verify_tls", True))
    try:
        opal_revit.runner.stop()
        opal_revit.runner.thread = None
        opal_revit.runner.start(saved)
        forms.toast("Linked as %s. Connected to the library." % ((result["user"] or {}).get("email", "?")), title="OPAL")
    except Exception as error:
        forms.toast("Linked, but the agent did not start: %s" % error, title="OPAL")


# `show_code` uses forms.alert, which is a modal dialog; it is raised from the
# link thread so Revit's UI stays responsive while the user claims the code.
worker = threading.Thread(target=finish)
worker.daemon = True
worker.start()
