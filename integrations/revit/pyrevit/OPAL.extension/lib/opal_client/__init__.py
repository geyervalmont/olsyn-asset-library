# -*- coding: utf-8 -*-
"""
OPAL client: the Revit-independent half of the Revit extension.

Runs on IronPython 2.7 (pyRevit) and CPython 3. Nothing here imports the
Revit API; hosts (Revit, or a JSON project on Unix) implement `Host`.
"""

from .api import OpalApi, ApiError
from .drive import Drive
from .host import Host, UnixHost, HostMaterial
from .workflow import Workflow, SyncReport, ApplyPlan
from .realtime import RealtimeClient, PurePythonWebSocket, DotNetWebSocket, WebSocketClosed
from .agent import Agent, CommandExecutor
from .link import link, Config, LinkExpired

__all__ = [
    "OpalApi", "ApiError", "Drive", "Host", "UnixHost", "HostMaterial",
    "Workflow", "SyncReport", "ApplyPlan",
    "RealtimeClient", "PurePythonWebSocket", "DotNetWebSocket", "WebSocketClosed",
    "Agent", "CommandExecutor", "link", "Config", "LinkExpired",
]
