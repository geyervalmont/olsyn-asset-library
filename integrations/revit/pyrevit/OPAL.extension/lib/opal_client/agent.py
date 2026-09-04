# -*- coding: utf-8 -*-
"""
The agent: a client session that executes commands the library sends.

Registers a session, subscribes to its realtime channel, catches up on
queued commands, executes each through the workflow and reports the result.
Hosts that must run on a particular thread (Revit) pass `dispatch`, which
only enqueues; they call `handle()` from their own thread later.
"""

import os
import socket
import threading

from .api import ApiError
from .workflow import Workflow
from .realtime import RealtimeClient


class CommandExecutor(object):
    """Maps command types onto workflow calls."""

    def __init__(self, workflow):
        self.workflow = workflow

    def execute(self, command):
        kind = command.get("type")
        payload = command.get("payload") or {}
        if kind == "apply":
            return self.apply(payload)
        if kind == "sync":
            return self.sync()
        if kind == "resolve":
            return self.resolve(payload)
        raise RuntimeError("unknown command type %r" % kind)

    def apply(self, payload):
        material = self._material(payload)
        plan = self.workflow.plan(material, payload["variant"], payload.get("quality"))
        if not plan.ready():
            raise RuntimeError(plan.describe())
        self.workflow.apply(plan)
        return {
            "variant": plan.variant["code"],
            "material": {"id": material.host_id, "name": material.name},
            "target": plan.target,
            "quality": plan.quality,
            "textures": plan.textures,
        }

    def sync(self):
        report = self.workflow.sync()
        return {
            "matched": [
                {"material": material.name, "material_id": material.host_id, "variant": variant["code"], "reference": reference}
                for material, variant, reference in report.matched
            ],
            "unmatched": [material.name for material in report.unmatched],
        }

    def resolve(self, payload):
        material = self._material(payload)
        variant, reference = self.workflow.resolve_material(material)
        return {
            "material": {"id": material.host_id, "name": material.name},
            "variant": variant["code"] if variant else None,
            "reference": reference,
        }

    def _material(self, payload):
        host = self.workflow.host
        material = host.material(payload["material_id"]) if payload.get("material_id") else host.selected_material()
        if material is None:
            raise RuntimeError("no material selected in %s" % (host.document_name() or "the document"))
        return material


class Agent(object):
    def __init__(self, api, host, drive=None, realtime=None, realtime_factory=None, executor=None,
                 machine=None, app_version="opal-client/1.0", heartbeat_interval=30, dispatch=None, log=None):
        self.api = api
        self.host = host
        self.drive = drive
        self.realtime = realtime
        self.realtime_factory = realtime_factory or RealtimeClient
        self.executor = executor or (CommandExecutor(Workflow(api, host, drive)) if drive is not None else None)
        self.machine = machine or os.environ.get("COMPUTERNAME") or socket.gethostname()
        self.app_version = app_version
        self.heartbeat_interval = heartbeat_interval
        self.dispatch = dispatch or self.handle
        self.log = log or (lambda message: None)
        self.session_id = None
        self.channel = None
        self.client = None
        self.last_command = None
        self.handled = 0
        self._stop = threading.Event()

    # -- lifecycle -----------------------------------------------------------

    def start(self):
        session = self.api.create_session(self.host.platform, self.machine, self.app_version, self.host.document_name())
        self.session_id = session["id"]
        self.channel = session["channel"]
        if self.realtime is None:
            self.realtime = self.api.realtime()
        self.log("agent: session %s on %s" % (self.session_id, self.channel))
        return session

    def catch_up(self):
        for command in self.api.queued_commands(self.session_id):
            self.dispatch(command)

    def run(self):
        """Blocks until stop(); realtime runs on its own thread, heartbeats here."""
        self.start()
        self.client = self.realtime_factory(self.api, self.realtime, self.channel, self.dispatch, on_connect=self.catch_up, log=self.log)
        thread = threading.Thread(target=self.client.run)
        thread.daemon = True
        thread.start()
        try:
            while not self._stop.is_set():
                self._stop.wait(self.heartbeat_interval)
                if self._stop.is_set():
                    break
                try:
                    self.api.heartbeat(self.session_id, self.host.document_name())
                except ApiError as error:
                    self.log("agent: heartbeat failed: %s" % error)
        finally:
            self.client.stop()
            try:
                self.api.end_session(self.session_id)
            except Exception:
                pass

    def stop(self):
        self._stop.set()

    def connected(self):
        return bool(self.client is not None and self.client.connected)

    def status(self):
        return {
            "session_id": self.session_id,
            "channel": self.channel,
            "connected": self.connected(),
            "connections": self.client.connections if self.client else 0,
            "last_error": self.client.last_error if self.client else None,
            "handled": self.handled,
            "last_command": self.last_command,
        }

    # -- commands ------------------------------------------------------------

    def handle(self, command, executor=None):
        """Ack, execute, report. Never raises; failures go back as results."""
        executor = executor or self.executor
        command_id = command["id"]
        self.log("agent: command %s %s" % (command_id, command.get("type")))
        try:
            self.api.ack_command(command_id)
        except ApiError as error:
            self.log("agent: ack failed: %s" % error)
        try:
            if executor is None:
                raise RuntimeError("no executor for commands")
            result = executor.execute(command)
            self.api.command_result(command_id, "done", result)
            outcome = ("done", result)
        except Exception as error:
            message = str(error)
            try:
                self.api.command_result(command_id, "failed", None, message)
            except ApiError as report_error:
                self.log("agent: result report failed: %s" % report_error)
            outcome = ("failed", message)
        self.handled += 1
        self.last_command = {"id": command_id, "type": command.get("type"), "status": outcome[0], "detail": outcome[1]}
        self.log("agent: command %s %s" % (command_id, outcome[0]))
        return outcome
