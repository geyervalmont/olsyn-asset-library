# -*- coding: utf-8 -*-
"""HTTP client for the OPAL API v1, using only the standard library."""

import json
import ssl

try:  # CPython 3
    from urllib.request import Request, urlopen
    from urllib.error import HTTPError, URLError
    from urllib.parse import urlencode, quote
except ImportError:  # IronPython 2.7
    from urllib2 import Request, urlopen, HTTPError, URLError  # noqa: F401
    from urllib import urlencode, quote  # noqa: F401


class ApiError(Exception):
    def __init__(self, status, message, body=None):
        Exception.__init__(self, "%s: %s" % (status, message))
        self.status = status
        self.message = message
        self.body = body


def _unwrap(response):
    """Endpoints answer either `{data: {...}}` or a bare object."""
    if isinstance(response, dict) and isinstance(response.get("data"), dict) and len(response) == 1:
        return response["data"]
    return response


class OpalApi(object):
    """
    A thin, explicit client. `fetch` can be replaced for tests.
    """

    def __init__(self, base_url, token=None, fetch=None, verify_tls=True, timeout=60):
        self.base_url = base_url.rstrip("/")
        self.token = token
        self.timeout = timeout
        self.verify_tls = verify_tls
        self._fetch = fetch or self._http_fetch

    # -- library ---------------------------------------------------------

    def me(self):
        return self._get("/api/v1/me")

    def search(self, query="", category=None, supplier=None, status=None, per_page=25, page=1):
        params = {"q": query, "per_page": per_page, "page": page}
        if category:
            params["category"] = category
        if supplier:
            params["supplier"] = supplier
        if status:
            params["status"] = status
        return self._get("/api/v1/materials", params)

    def material(self, code):
        return self._get("/api/v1/materials/%s" % quote(code, safe=""))["data"]

    def variant(self, code):
        return self._get("/api/v1/variants/%s" % quote(code, safe=""))["data"]

    def resolve(self, platform, reference):
        try:
            return self._get("/api/v1/variants/resolve", {"platform": platform, "reference": reference})["data"]
        except ApiError as error:
            if error.status == 404:
                return None
            raise

    def drives(self):
        return self._get("/api/v1/drives")["data"]

    def variant_paths(self, code, drive):
        return self._get("/api/v1/variants/%s/paths" % quote(code, safe=""), {"drive": drive})["data"]

    def register_identity(self, code, platform, external_id=None, external_name=None, payload=None):
        body = {"platform": platform}
        if external_id:
            body["external_id"] = external_id
        if external_name:
            body["external_name"] = external_name
        if payload:
            body["payload"] = payload
        return self._post("/api/v1/variants/%s/identities" % quote(code, safe=""), body)["data"]

    # -- account linking ---------------------------------------------------

    def start_link(self, client, machine=None, app_version=None):
        """Begin the device-code flow; no token needed."""
        body = {"client": client, "machine": machine or "", "app_version": app_version or ""}
        return _unwrap(self._post("/api/v1/link", body))

    def poll_link(self, code, secret):
        """`{status: pending|claimed|delivered, ...}`; raises ApiError(410) once expired."""
        return _unwrap(self._get("/api/v1/link/%s" % quote(code, safe=""), {"secret": secret}))

    def realtime(self):
        """Where the realtime server is and how to authenticate channels."""
        return _unwrap(self._get("/api/v1/realtime"))

    # -- sessions and commands ---------------------------------------------

    def create_session(self, platform, machine=None, app_version=None, document=None):
        body = {"platform": platform, "machine": machine or "", "app_version": app_version or "", "document": document or ""}
        return _unwrap(self._post("/api/v1/sessions", body))

    def heartbeat(self, session_id, document=None):
        return _unwrap(self._post("/api/v1/sessions/%s/heartbeat" % session_id, {"document": document or ""}))

    def end_session(self, session_id):
        return self._fetch("DELETE", self.base_url + "/api/v1/sessions/%s" % session_id, None)

    def queued_commands(self, session_id):
        return self._get("/api/v1/sessions/%s/commands" % session_id, {"status": "queued"}).get("data", [])

    def ack_command(self, command_id):
        return self._post("/api/v1/commands/%s/ack" % command_id, {})

    def command_result(self, command_id, status, result=None, message=None):
        body = {"status": status, "result": result or {}}
        if message:
            body["message"] = message
        return self._post("/api/v1/commands/%s/result" % command_id, body)

    def resolve_endpoint(self, endpoint):
        """
        A saved absolute endpoint on the API's own host is re-based onto
        base_url, so a scheme change (http → https) after linking still works;
        relative paths are joined; other hosts are used as given.
        """
        if "://" not in endpoint:
            return self.base_url + "/" + endpoint.lstrip("/")
        base_host = self.base_url.split("://", 1)[1].split("/", 1)[0].lower()
        rest = endpoint.split("://", 1)[1]
        host, _, path = rest.partition("/")
        if host.lower() == base_host or host.lower().split(":")[0] == base_host.split(":")[0]:
            return self.base_url + "/" + path
        return endpoint

    def channel_auth(self, auth_endpoint, socket_id, channel_name):
        """Signs a private-channel subscription; returns the `auth` string."""
        url = self.resolve_endpoint(auth_endpoint)
        response = self._fetch("POST", url, {"socket_id": socket_id, "channel_name": channel_name})
        auth = response.get("auth") if isinstance(response, dict) else None
        if not auth:
            raise ApiError(0, "channel auth for %s returned no signature" % channel_name, response)
        return auth

    # -- transport -------------------------------------------------------

    def _get(self, path, params=None):
        url = self.base_url + path
        if params:
            url += "?" + urlencode(params)
        return self._fetch("GET", url, None)

    def _post(self, path, body):
        return self._fetch("POST", self.base_url + path, body)

    def _http_fetch(self, method, url, body):
        data = None
        headers = {
            "Accept": "application/json",
            "User-Agent": "opal-client/1.0",
        }
        if self.token:
            headers["Authorization"] = "Bearer " + self.token
        if body is not None:
            data = json.dumps(body).encode("utf-8")
            headers["Content-Type"] = "application/json"

        request = Request(url, data=data, headers=headers)
        if hasattr(request, "get_method"):
            request.get_method = lambda: method  # IronPython 2.7

        context = None
        if not self.verify_tls and hasattr(ssl, "_create_unverified_context"):
            context = ssl._create_unverified_context()

        try:
            if context is not None:
                response = urlopen(request, timeout=self.timeout, context=context)
            else:
                response = urlopen(request, timeout=self.timeout)
            raw = response.read()
        except HTTPError as error:
            raw = error.read()
            try:
                parsed = json.loads(raw.decode("utf-8"))
            except Exception:
                parsed = None
            message = (parsed or {}).get("message") if isinstance(parsed, dict) else None
            raise ApiError(error.code, message or "HTTP %s" % error.code, parsed)
        except URLError as error:
            raise ApiError(0, "Cannot reach %s: %s" % (url, error.reason))

        if not raw:
            return {}
        return json.loads(raw.decode("utf-8"))
