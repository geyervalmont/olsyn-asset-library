# -*- coding: utf-8 -*-
"""
Runs once when pyRevit loads the extension. If this machine is linked to an
OPAL account, connect to the library so Apply / Sync sent from the web app
reach this Revit. Errors are kept for the Status button, never raised.
"""
import os
import sys

LIB = os.path.join(os.path.dirname(__file__), "lib")
if LIB not in sys.path:
    sys.path.append(LIB)

try:
    import opal_revit  # noqa: E402

    opal_revit.log_line("startup: extension loaded (python %s)" % sys.version.split()[0])
    opal_revit.runner.start_if_configured()
    opal_revit.log_line("startup: agent %s" % ("running" if opal_revit.runner.running() else "not started: %s" % opal_revit.runner.last_error))
except Exception as error:
    try:
        import traceback

        path = os.path.join(os.environ.get("APPDATA", os.path.expanduser("~")), "OPAL", "agent.log")
        with open(path, "a") as handle:
            handle.write("startup failed: %s\n%s\n" % (error, traceback.format_exc()))
    except Exception:
        pass
    raise
