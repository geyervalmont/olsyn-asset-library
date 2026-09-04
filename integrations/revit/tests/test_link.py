# -*- coding: utf-8 -*-
import json
import os
import shutil
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from opal_client import Config, LinkExpired, link  # noqa: E402
from fakes import FakeApi  # noqa: E402


class LinkTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp()
        self.api = FakeApi()
        self.factory = lambda base, token=None, verify_tls=True: self.api

    def tearDown(self):
        shutil.rmtree(self.tmp)

    def test_polls_until_claimed_and_returns_token_and_realtime(self):
        self.api.link_states = [{"status": "pending"}, {"status": "claimed", "token": "opal_tok", "user": {"name": "H"}, "realtime": {"key": "k"}}]
        opened = []
        logs = []
        result = link("https://opal.test", "revit", machine="vm", app_version="Revit 2027", open_browser=opened.append, sleep=lambda s: None, log=logs.append, api_factory=self.factory)
        self.assertEqual(result, {"token": "opal_tok", "user": {"name": "H"}, "realtime": {"key": "k"}})
        self.assertEqual(opened, ["https://opal.test/link/ABCD-1234"])
        self.assertEqual(self.api.link_started, {"client": "revit", "machine": "vm", "app_version": "Revit 2027"})
        self.assertIn("ABCD-1234", logs[0])

    def test_expired_and_reused_codes_raise(self):
        self.api.link_states = [410]
        with self.assertRaises(LinkExpired):
            link("https://opal.test", sleep=lambda s: None, api_factory=self.factory)
        self.api.link_states = [{"status": "delivered"}]
        with self.assertRaises(LinkExpired):
            link("https://opal.test", sleep=lambda s: None, api_factory=self.factory)

    def test_config_round_trips_and_merges(self):
        path = os.path.join(self.tmp, "nested", "config.json")
        config = Config(path)
        self.assertFalse(config.exists())
        self.assertEqual(config.load(), {})
        config.save({"api": "https://opal.test", "drive": "studio-share"})
        config.update(token="opal_tok", realtime={"key": "k"})
        with open(path) as handle:
            saved = json.load(handle)
        self.assertEqual(saved["token"], "opal_tok")
        self.assertEqual(saved["drive"], "studio-share")
        self.assertTrue(config.linked())

    def test_config_path_defaults(self):
        os.environ["OPAL_CONFIG"] = os.path.join(self.tmp, "c.json")
        try:
            self.assertEqual(Config().path, os.path.join(self.tmp, "c.json"))
        finally:
            del os.environ["OPAL_CONFIG"]
        self.assertTrue(Config.default_path().endswith(os.path.join("opal", "config.json")) or Config.default_path().endswith(os.path.join("OPAL", "config.json")))


if __name__ == "__main__":
    unittest.main()
