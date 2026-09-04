# -*- coding: utf-8 -*-
"""A canned OPAL API for tests: no HTTP, records what the client did."""

import hashlib

try:
    from urllib.parse import unquote_plus
except ImportError:
    from urllib import unquote_plus

from opal_client import ApiError, OpalApi


def sha(data):
    return hashlib.sha256(data).hexdigest()


VARIANT = {"code": "CPT-TARKETT-ACADEMIX-ASHEN", "name": "Ashen", "material_code": "CPT-TARKETT-ACADEMIX", "tile_width_mm": 500.0}


class FakeApi(OpalApi):
    """OpalApi with the transport replaced by canned responses."""

    def __init__(self, drive_root="/materials"):
        OpalApi.__init__(self, "https://opal.test", "token", fetch=self._fake)
        self.identities = []
        self.drive_root = drive_root
        self.files = []
        self.requests = []
        # linking
        self.link_states = []          # what successive polls return
        self.link_started = None
        # sessions and commands
        self.sessions = {}
        self.queued = []               # commands the next queued_commands call returns
        self.acks = []
        self.results = []
        self.heartbeats = []
        self.ended = []
        self.auth_calls = []
        self.realtime_block = {"scheme": "http", "host": "127.0.0.1", "port": 6001, "key": "testkey", "auth_endpoint": "/broadcasting/auth"}

    def _fake(self, method, url, body):
        self.requests.append((method, url, body))
        path = url.split("https://opal.test", 1)[1] if url.startswith("https://opal.test") else url
        query = {}
        if "?" in path:
            path, raw = path.split("?", 1)
            query = dict(part.split("=", 1) for part in raw.split("&"))

        if path == "/api/v1/variants/resolve":
            reference = unquote_plus(query["reference"])
            registered = [i.get("external_id") for i in self.identities] + [i.get("external_name") for i in self.identities]
            if "CPT-TARKETT-ACADEMIX-ASHEN" in reference.upper() or reference in registered or reference == "Carpet - Academix Ashen":
                return {"data": VARIANT}
            raise ApiError(404, "No variant matches that reference.")
        if path == "/api/v1/variants/CPT-TARKETT-ACADEMIX-ASHEN":
            return {"data": VARIANT}
        if path == "/api/v1/variants/CPT-TARKETT-ACADEMIX-ASHEN/paths":
            return {"data": {"variant": VARIANT["code"], "drive": "studio-share", "root_path": self.drive_root, "published": True, "files": self.files}}
        if path == "/api/v1/drives":
            return {"data": [{"slug": "studio-share", "name": "Studio share", "root_path": self.drive_root, "target": None}]}
        if method == "POST" and path == "/api/v1/variants/CPT-TARKETT-ACADEMIX-ASHEN/identities":
            self.identities.append(body)
            return {"data": {"variant": VARIANT["code"], "platform": body["platform"], "external_id": body.get("external_id"), "external_name": body.get("external_name")}}

        if method == "POST" and path == "/api/v1/link":
            self.link_started = body
            return {"code": "ABCD-1234", "secret": "s3cret", "expires_at": "2026-09-04T00:10:00Z", "verify_url": "https://opal.test/link/ABCD-1234", "poll_interval": 1}
        if path == "/api/v1/link/ABCD-1234":
            if query.get("secret") != "s3cret":
                raise ApiError(404, "unknown link")
            state = self.link_states.pop(0) if self.link_states else {"status": "pending"}
            if state == 410:
                raise ApiError(410, "expired")
            return state
        if path == "/api/v1/realtime":
            return {"data": self.realtime_block}

        if method == "POST" and path == "/api/v1/sessions":
            session_id = len(self.sessions) + 1
            self.sessions[session_id] = body
            return {"data": {"id": session_id, "channel": "revit-session.%d" % session_id}}
        if method == "POST" and path.endswith("/heartbeat"):
            self.heartbeats.append(body)
            return {}
        if method == "DELETE" and path.startswith("/api/v1/sessions/"):
            self.ended.append(path)
            return {}
        if path.startswith("/api/v1/sessions/") and path.endswith("/commands"):
            queued, self.queued = self.queued, []
            return {"data": queued}
        if method == "POST" and path.startswith("/api/v1/commands/") and path.endswith("/ack"):
            self.acks.append(int(path.split("/")[4]))
            return {}
        if method == "POST" and path.startswith("/api/v1/commands/") and path.endswith("/result"):
            self.results.append((int(path.split("/")[4]), body))
            return {}
        if method == "POST" and path.endswith("/broadcasting/auth"):
            self.auth_calls.append(body)
            return {"auth": "testkey:signed-%s" % body["socket_id"]}
        raise ApiError(404, "unexpected %s %s" % (method, path))
