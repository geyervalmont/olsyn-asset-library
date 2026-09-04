# -*- coding: utf-8 -*-
import os
import sys
import threading
import time
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from opal_client import RealtimeClient, PurePythonWebSocket  # noqa: E402
from fakes import FakeApi  # noqa: E402
from wsserver import PusherTestServer  # noqa: E402


def wait_for(predicate, timeout=5):
    deadline = time.time() + timeout
    while time.time() < deadline:
        if predicate():
            return True
        time.sleep(0.02)
    return False


class RealtimeTest(unittest.TestCase):
    def setUp(self):
        self.server = PusherTestServer()
        self.api = FakeApi()
        self.commands = []
        self.connects = []
        self.client = RealtimeClient(
            self.api, self.server.realtime(), "revit-session.7",
            on_command=self.commands.append, on_connect=lambda: self.connects.append(time.time()),
            transport_factory=lambda url, verify: PurePythonWebSocket(url, verify),
            ping_interval=0.3, backoff=(0.2,),
        )
        self.thread = threading.Thread(target=self.client.run)
        self.thread.daemon = True

    def tearDown(self):
        self.client.stop()
        self.server.stop()
        if self.thread.is_alive():
            self.thread.join(2)

    def test_connects_authenticates_and_subscribes(self):
        self.thread.start()
        self.assertTrue(wait_for(lambda: self.client.connected))
        self.assertEqual(self.server.paths[0], "/app/testkey?protocol=7&client=opal&version=1.0")
        self.assertEqual(self.api.auth_calls[0], {"socket_id": self.client.socket_id, "channel_name": "private-revit-session.7"})
        self.assertEqual(self.server.subscriptions[0][0], "private-revit-session.7")
        self.assertEqual(len(self.connects), 1)

    def test_delivers_commands_and_answers_pings(self):
        self.thread.start()
        self.assertTrue(wait_for(lambda: self.client.connected))
        self.server.push("command.queued", {"id": 5, "type": "apply", "payload": {"variant": "X"}}, "private-revit-session.7")
        self.assertTrue(wait_for(lambda: self.commands))
        self.assertEqual(self.commands[0], {"id": 5, "type": "apply", "payload": {"variant": "X"}})
        self.assertTrue(wait_for(lambda: self.server.pings >= 2, timeout=3))

    def test_reconnects_after_the_server_drops_it(self):
        self.thread.start()
        self.assertTrue(wait_for(lambda: self.client.connected))
        self.server.drop_clients()
        self.assertTrue(wait_for(lambda: not self.client.connected))
        self.assertTrue(wait_for(lambda: self.client.connected and self.client.connections == 2, timeout=5))
        self.assertEqual(len(self.connects), 2)
        self.server.push("command.queued", {"id": 6, "type": "sync", "payload": {}}, "private-revit-session.7")
        self.assertTrue(wait_for(lambda: self.commands))

    def test_url_uses_wss_for_https(self):
        client = RealtimeClient(self.api, {"scheme": "https", "host": "opal.example", "port": 443, "key": "k", "auth_endpoint": "/x"}, "c", lambda c: None)
        self.assertEqual(client.url(), "wss://opal.example:443/app/k?protocol=7&client=opal&version=1.0")


if __name__ == "__main__":
    unittest.main()
