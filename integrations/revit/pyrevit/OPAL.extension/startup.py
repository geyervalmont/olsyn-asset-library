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

import opal_revit  # noqa: E402

opal_revit.runner.start_if_configured()
