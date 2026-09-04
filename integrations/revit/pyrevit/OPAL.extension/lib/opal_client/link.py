# -*- coding: utf-8 -*-
"""
Account linking (device-code flow) and the on-disk client config.

The client shows a short code; the user enters it in the web app while
signed in; the client polls until the link is claimed and receives a token
plus the realtime connection details. No credentials pass through the client.
"""

import io
import json
import os
import time

from .api import ApiError, OpalApi


class LinkExpired(Exception):
    pass


def start_link(api_base, client="revit", machine=None, app_version=None, verify_tls=True, log=None, api_factory=None):
    """Asks the library for a code. Returns (api, started); nothing here needs a UI."""
    log = log or (lambda message: None)
    api = (api_factory or OpalApi)(api_base, None, verify_tls=verify_tls)
    started = api.start_link(client, machine, app_version)
    log("Link code %s — enter it at %s" % (started["code"], started.get("verify_url")))
    return api, started


def wait_for_link(api, started, sleep=time.sleep, timeout=600):
    """Polls until the code is claimed. Safe on a background thread: no UI, no host calls."""
    code, secret = started["code"], started["secret"]
    interval = max(1, int(started.get("poll_interval") or 3))
    deadline = time.time() + timeout
    while time.time() < deadline:
        sleep(interval)
        try:
            state = api.poll_link(code, secret)
        except ApiError as error:
            if error.status == 410:
                raise LinkExpired("link code %s expired" % code)
            raise
        if state.get("status") == "claimed":
            return {"token": state["token"], "user": state.get("user"), "realtime": state.get("realtime")}
        if state.get("status") == "delivered":
            raise LinkExpired("link code %s was already used" % code)
    raise LinkExpired("nobody claimed link code %s" % code)


def link(api_base, client="revit", machine=None, app_version=None, open_browser=None,
         poll=None, verify_tls=True, sleep=time.sleep, timeout=600, log=None, api_factory=None):
    """Runs the whole flow on the calling thread; returns `{token, user, realtime}`."""
    log = log or (lambda message: None)
    api, started = start_link(api_base, client, machine, app_version, verify_tls, log, api_factory)
    if poll:
        poll(started)
    if open_browser and started.get("verify_url"):
        try:
            open_browser(started["verify_url"])
        except Exception as error:
            log("could not open a browser: %s" % error)
    return wait_for_link(api, started, sleep=sleep, timeout=timeout)


class Config(object):
    """`%APPDATA%\\OPAL\\config.json` on Windows, `~/.config/opal/config.json` elsewhere."""

    FIELDS = ("api", "token", "drive", "mount", "realtime", "verify_tls", "user")

    def __init__(self, path=None):
        self.path = path or os.environ.get("OPAL_CONFIG") or self.default_path()

    @staticmethod
    def default_path():
        if os.name == "nt":
            return os.path.join(os.environ.get("APPDATA", os.path.expanduser("~")), "OPAL", "config.json")
        base = os.environ.get("XDG_CONFIG_HOME") or os.path.join(os.path.expanduser("~"), ".config")
        return os.path.join(base, "opal", "config.json")

    def exists(self):
        return os.path.isfile(self.path)

    def load(self):
        if not self.exists():
            return {}
        # utf-8-sig: tolerate the BOM PowerShell's Set-Content writes.
        with io.open(self.path, "r", encoding="utf-8-sig") as handle:
            return json.loads(handle.read())

    def save(self, values):
        directory = os.path.dirname(self.path)
        if directory and not os.path.isdir(directory):
            os.makedirs(directory)
        with open(self.path, "w") as handle:
            json.dump(values, handle, indent=2, sort_keys=True)

    def update(self, **changes):
        values = self.load()
        values.update(changes)
        self.save(values)
        return values

    def linked(self):
        return bool(self.load().get("token"))
