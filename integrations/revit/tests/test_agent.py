# -*- coding: utf-8 -*-
import os
import shutil
import sys
import tempfile
import threading
import time
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from opal_client import Agent, CommandExecutor, Drive, UnixHost, Workflow  # noqa: E402
from fakes import FakeApi, VARIANT, sha  # noqa: E402


class FakeRealtime(object):
    """Stands in for RealtimeClient: connects at once, delivers scripted commands."""

    script = []

    def __init__(self, api, realtime, channel, on_command, on_connect=None, log=None):
        self.channel = channel
        self.on_command = on_command
        self.on_connect = on_connect
        self.connected = False
        self.connections = 0
        self.last_error = None
        self.stopped = threading.Event()

    def run(self):
        self.connected = True
        self.connections += 1
        if self.on_connect:
            self.on_connect()
        for command in FakeRealtime.script:
            self.on_command(command)
        self.stopped.wait(5)

    def stop(self):
        self.connected = False
        self.stopped.set()


class AgentTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp()
        self.mount = os.path.join(self.tmp, "mnt")
        self.api = FakeApi()
        self.drive = Drive("studio-share", self.mount, "/materials")
        data = b"base-bytes"
        entry = {"path": "/materials/Carpet/Academix/Ashen/revit/CPT-TARKETT-ACADEMIX-ASHEN_base_color.png", "target": "revit", "quality": "2k", "role": "base_color", "sha256": sha(data), "bytes": len(data), "mime_type": "image/png"}
        self.api.files = [entry]
        local = self.drive.local_path(entry["path"])
        os.makedirs(os.path.dirname(local))
        with open(local, "wb") as handle:
            handle.write(data)
        self.host = UnixHost(os.path.join(self.tmp, "project.json"))
        self.host.add_material("mat-1", "Carpet - Academix Ashen")
        self.host.add_material("mat-2", "Concrete generic")
        self.host.select("mat-2")
        FakeRealtime.script = []

    def tearDown(self):
        shutil.rmtree(self.tmp)

    def agent(self, **kwargs):
        return Agent(self.api, self.host, self.drive, realtime_factory=FakeRealtime, machine="linux-box", heartbeat_interval=0.05, **kwargs)

    def test_start_registers_a_session_and_fetches_realtime_details(self):
        agent = self.agent()
        agent.start()
        self.assertEqual(agent.session_id, 1)
        self.assertEqual(agent.channel, "revit-session.1")
        self.assertEqual(self.api.sessions[1], {"platform": "revit", "machine": "linux-box", "app_version": "opal-client/1.0", "document": "project.json"})
        self.assertEqual(agent.realtime["key"], "testkey")

    def test_apply_command_applies_and_reports(self):
        agent = self.agent()
        agent.start()
        status, result = agent.handle({"id": 11, "type": "apply", "payload": {"variant": VARIANT["code"], "material_id": "mat-1"}})
        self.assertEqual(status, "done")
        self.assertEqual(result["material"], {"id": "mat-1", "name": "Carpet - Academix Ashen"})
        self.assertEqual(result["target"], "revit")
        self.assertEqual(self.api.acks, [11])
        self.assertEqual(self.api.results[0][0], 11)
        self.assertEqual(self.api.results[0][1]["status"], "done")
        self.assertEqual(self.api.identities[0]["external_id"], "mat-1")

    def test_apply_without_material_uses_the_selection_and_failures_are_reported(self):
        agent = self.agent()
        agent.start()
        status, detail = agent.handle({"id": 12, "type": "apply", "payload": {"variant": VARIANT["code"]}})
        self.assertEqual(status, "done")
        self.assertEqual(detail["material"]["id"], "mat-2")

        os.remove(self.drive.local_path(self.api.files[0]["path"]))
        status, detail = agent.handle({"id": 13, "type": "apply", "payload": {"variant": VARIANT["code"], "material_id": "mat-1"}})
        self.assertEqual(status, "failed")
        self.assertIn("MISSING", detail)
        self.assertEqual(self.api.results[-1][1], {"status": "failed", "result": {}, "message": detail})

        status, detail = agent.handle({"id": 14, "type": "dance", "payload": {}})
        self.assertEqual(status, "failed")
        self.assertIn("unknown command type", detail)

    def test_sync_and_resolve_commands(self):
        agent = self.agent()
        agent.start()
        status, result = agent.handle({"id": 20, "type": "sync", "payload": {}})
        self.assertEqual(status, "done")
        self.assertEqual(result["matched"][0]["variant"], VARIANT["code"])
        self.assertEqual(result["unmatched"], ["Concrete generic"])
        status, result = agent.handle({"id": 21, "type": "resolve", "payload": {"material_id": "mat-1"}})
        self.assertEqual(result["variant"], VARIANT["code"])

    def test_run_catches_up_then_takes_realtime_commands_and_heartbeats(self):
        self.api.queued = [{"id": 30, "type": "sync", "payload": {}}]
        FakeRealtime.script = [{"id": 31, "type": "resolve", "payload": {"material_id": "mat-1"}}]
        agent = self.agent()
        thread = threading.Thread(target=agent.run)
        thread.daemon = True
        thread.start()
        deadline = time.time() + 5
        while time.time() < deadline and (agent.handled < 2 or not self.api.heartbeats):
            time.sleep(0.02)
        agent.stop()
        thread.join(3)
        self.assertEqual(self.api.acks, [30, 31])
        self.assertTrue(self.api.heartbeats)
        self.assertEqual(self.api.ended, ["/api/v1/sessions/1"])
        self.assertEqual(agent.status()["last_command"]["id"], 31)

    def test_dispatch_lets_a_host_defer_execution_to_its_own_thread(self):
        queued = []
        agent = self.agent(dispatch=queued.append)
        agent.start()
        self.api.queued = [{"id": 40, "type": "sync", "payload": {}}]
        agent.catch_up()
        self.assertEqual([c["id"] for c in queued], [40])
        self.assertEqual(self.api.acks, [])
        executor = CommandExecutor(Workflow(self.api, self.host, self.drive))
        status, _ = agent.handle(queued[0], executor)
        self.assertEqual(status, "done")
        self.assertEqual(self.api.acks, [40])


if __name__ == "__main__":
    unittest.main()


class EndpointResolutionTest(unittest.TestCase):
    def test_saved_endpoint_follows_the_api_scheme(self):
        from opal_client import OpalApi
        api = OpalApi("https://asset-library.test", "t")
        self.assertEqual(api.resolve_endpoint("http://asset-library.test/broadcasting/auth"), "https://asset-library.test/broadcasting/auth")
        self.assertEqual(api.resolve_endpoint("/broadcasting/auth"), "https://asset-library.test/broadcasting/auth")
        self.assertEqual(api.resolve_endpoint("https://other.example/auth"), "https://other.example/auth")


class BatchSyncTest(unittest.TestCase):
    def test_sync_resolves_everything_in_one_request(self):
        import os, tempfile
        from opal_client import Drive, UnixHost, Workflow
        from tests.fakes import FakeApi
        api = FakeApi()
        project = os.path.join(tempfile.mkdtemp(), "p.json")
        host = UnixHost(project)
        for n in range(30):
            host.add_material("m%d" % n, "Concrete %d" % n)
        host.add_material("carpet", "Carpet - Academix Ashen")
        report = Workflow(api, host, Drive("studio-share", tempfile.mkdtemp())).sync()
        self.assertEqual(report.summary(), "1 matched, 30 unmatched")
        self.assertEqual(getattr(api, "batch_resolves", 0), 1)
