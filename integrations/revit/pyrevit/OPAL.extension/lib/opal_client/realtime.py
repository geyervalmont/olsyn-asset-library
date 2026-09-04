# -*- coding: utf-8 -*-
"""
Realtime commands from the control plane over WebSockets.

The control plane runs Laravel Reverb, which speaks the Pusher protocol.
`RealtimeClient` keeps one connection, subscribes to the session's private
channel, answers pings, and hands `command.queued` events to a callback.

Two transports: a pure-stdlib RFC 6455 client (CPython, Linux proxy) and
`System.Net.WebSockets.ClientWebSocket` (IronPython inside Revit).
"""

import base64
import hashlib
import json
import os
import socket
import ssl
import struct
import threading
import time

try:  # CPython 3
    from urllib.parse import urlparse
except ImportError:  # IronPython 2.7
    from urlparse import urlparse

from .api import ApiError

WS_GUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"


class WebSocketClosed(Exception):
    pass


class RealtimeError(Exception):
    pass


# -- transports -------------------------------------------------------------


class PurePythonWebSocket(object):
    """A minimal RFC 6455 client: text frames, ping/pong, close."""

    def __init__(self, url, verify_tls=True, timeout=10):
        parsed = urlparse(url)
        secure = parsed.scheme in ("wss", "https")
        host = parsed.hostname
        port = parsed.port or (443 if secure else 80)
        path = parsed.path or "/"
        if parsed.query:
            path += "?" + parsed.query

        sock = socket.create_connection((host, port), timeout)
        if secure:
            context = ssl.create_default_context()
            if not verify_tls:
                context.check_hostname = False
                context.verify_mode = ssl.CERT_NONE
            sock = context.wrap_socket(sock, server_hostname=host)

        key = base64.b64encode(os.urandom(16)).decode("ascii")
        request = (
            "GET %s HTTP/1.1\r\nHost: %s:%d\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            "Sec-WebSocket-Key: %s\r\nSec-WebSocket-Version: 13\r\n\r\n"
        ) % (path, host, port, key)
        sock.sendall(request.encode("ascii"))

        self.sock = sock
        self._buffer = b""
        self._lock = threading.Lock()
        head = self._read_headers()
        status = head.split("\r\n", 1)[0]
        if " 101 " not in status:
            raise WebSocketClosed("handshake refused: %s" % status)
        expected = base64.b64encode(hashlib.sha1((key + WS_GUID).encode("ascii")).digest()).decode("ascii")
        if expected not in head:
            raise WebSocketClosed("handshake accept key mismatch")

    def send_text(self, text):
        self._send_frame(0x1, text.encode("utf-8"))

    def close(self):
        try:
            self._send_frame(0x8, struct.pack("!H", 1000))
        except Exception:
            pass
        try:
            self.sock.close()
        except Exception:
            pass

    def receive(self, timeout=None):
        """The next text message, or None when `timeout` passes quietly."""
        while True:
            try:
                head = self._read(2, timeout)
            except socket.timeout:
                return None
            opcode = head[0] & 0x0F
            masked = head[1] & 0x80
            length = head[1] & 0x7F
            if length == 126:
                length = struct.unpack("!H", bytes(self._read(2, 10)))[0]
            elif length == 127:
                length = struct.unpack("!Q", bytes(self._read(8, 10)))[0]
            mask = self._read(4, 10) if masked else None
            payload = self._read(length, 30) if length else bytearray()
            if mask:
                for i in range(len(payload)):
                    payload[i] ^= mask[i % 4]

            if opcode == 0x8:
                raise WebSocketClosed("closed by server")
            if opcode == 0x9:
                self._send_frame(0xA, bytes(payload))
                continue
            if opcode in (0x1, 0x0):
                return bytes(payload).decode("utf-8")
            # pong (0xA) and binary (0x2) are ignored

    def _send_frame(self, opcode, payload):
        header = bytearray([0x80 | opcode])
        length = len(payload)
        if length < 126:
            header.append(0x80 | length)
        elif length < 65536:
            header.append(0x80 | 126)
            header += struct.pack("!H", length)
        else:
            header.append(0x80 | 127)
            header += struct.pack("!Q", length)
        mask = bytearray(os.urandom(4))
        masked = bytearray(payload)
        for i in range(len(masked)):
            masked[i] ^= mask[i % 4]
        with self._lock:
            self.sock.sendall(bytes(header + mask + masked))

    def _read(self, count, timeout):
        self.sock.settimeout(timeout)
        while len(self._buffer) < count:
            chunk = self.sock.recv(max(count - len(self._buffer), 4096))
            if not chunk:
                raise WebSocketClosed("connection closed")
            self._buffer += chunk
        data = self._buffer[:count]
        self._buffer = self._buffer[count:]
        return bytearray(data)

    def _read_headers(self):
        self.sock.settimeout(10)
        while b"\r\n\r\n" not in self._buffer:
            chunk = self.sock.recv(4096)
            if not chunk:
                raise WebSocketClosed("connection closed during handshake")
            self._buffer += chunk
        head, self._buffer = self._buffer.split(b"\r\n\r\n", 1)
        return head.decode("iso-8859-1")


class DotNetWebSocket(object):
    """`System.Net.WebSockets.ClientWebSocket`, for IronPython under Revit."""

    def __init__(self, url, verify_tls=True, timeout=10):
        import clr  # noqa: F401
        from System import Array, ArraySegment, Byte, Uri
        from System.Net.WebSockets import ClientWebSocket, WebSocketMessageType
        from System.Text import Encoding
        from System.Threading import CancellationToken, CancellationTokenSource

        self._Array, self._ArraySegment, self._Byte = Array, ArraySegment, Byte
        self._Text, self._Close = WebSocketMessageType.Text, WebSocketMessageType.Close
        self._Encoding = Encoding
        self._CancellationTokenSource = CancellationTokenSource
        self._none = getattr(CancellationToken, "None")

        self._ws = ClientWebSocket()
        connect = self._ws.ConnectAsync(Uri(url), self._none)
        if not connect.Wait(int(timeout * 1000)):
            raise WebSocketClosed("connect timed out")
        self._lock = threading.Lock()

    def send_text(self, text):
        data = self._Encoding.UTF8.GetBytes(text)
        with self._lock:
            self._ws.SendAsync(self._ArraySegment[self._Byte](data), self._Text, True, self._none).Wait()

    def close(self):
        try:
            from System.Net.WebSockets import WebSocketCloseStatus
            self._ws.CloseAsync(WebSocketCloseStatus.NormalClosure, "bye", self._none).Wait(2000)
        except Exception:
            pass

    def receive(self, timeout=None):
        buffer = self._Array.CreateInstance(self._Byte, 65536)
        chunks = []
        while True:
            source = self._CancellationTokenSource(int((timeout or 3600) * 1000))
            task = self._ws.ReceiveAsync(self._ArraySegment[self._Byte](buffer), source.Token)
            try:
                result = task.Result
            except Exception as error:  # AggregateException wraps cancellation
                if "cancel" in str(error).lower() and not chunks:
                    return None
                raise WebSocketClosed(str(error))
            if result.MessageType == self._Close:
                raise WebSocketClosed("closed by server")
            chunks.append(self._Encoding.UTF8.GetString(buffer, 0, result.Count))
            if result.EndOfMessage:
                return "".join(chunks)


def default_transport(url, verify_tls=True):
    try:
        import clr  # noqa: F401
        return DotNetWebSocket(url, verify_tls)
    except ImportError:
        return PurePythonWebSocket(url, verify_tls)


# -- client -----------------------------------------------------------------


class RealtimeClient(object):
    """
    One subscription to `private-{channel}` with automatic reconnects.

    `on_command(dict)` receives the parsed data of `command.queued` events;
    `on_connect()` fires after every successful subscription so callers can
    catch up on anything queued while offline.
    """

    def __init__(self, api, realtime, channel, on_command, on_connect=None,
                 transport_factory=None, ping_interval=25, backoff=(1, 2, 5, 10, 30),
                 verify_tls=True, log=None):
        self.api = api
        self.realtime = realtime
        self.channel = channel
        self.on_command = on_command
        self.on_connect = on_connect
        self.transport_factory = transport_factory or default_transport
        self.ping_interval = ping_interval
        self.backoff = backoff
        self.verify_tls = verify_tls
        self.log = log or (lambda message: None)
        self.connected = False
        self.socket_id = None
        self.last_error = None
        self.connections = 0
        self._stop = threading.Event()
        self._ws = None

    def url(self):
        scheme = "wss" if self.realtime.get("scheme") in ("https", "wss") else "ws"
        host = self.realtime["host"]
        port = self.realtime.get("port") or (443 if scheme == "wss" else 80)
        return "%s://%s:%s/app/%s?protocol=7&client=opal&version=1.0" % (scheme, host, port, self.realtime["key"])

    def run(self):
        attempt = 0
        while not self._stop.is_set():
            try:
                self._session()
                attempt = 0
            except (WebSocketClosed, RealtimeError, ApiError, socket.error, IOError, OSError) as error:
                if not self._stop.is_set():
                    self.last_error = str(error)
                    self.log("realtime: %s" % error)
            finally:
                self.connected = False
                self._close()
            if self._stop.is_set():
                break
            delay = self.backoff[min(attempt, len(self.backoff) - 1)]
            attempt += 1
            self._stop.wait(delay)

    def stop(self):
        self._stop.set()
        self._close()

    # -- internals -----------------------------------------------------------

    def _session(self):
        ws = self.transport_factory(self.url(), self.verify_tls)
        self._ws = ws
        established = self._await(ws, "pusher:connection_established")
        self.socket_id = _data(established)["socket_id"]

        channel = "private-" + self.channel
        auth = self.api.channel_auth(self.realtime["auth_endpoint"], self.socket_id, channel)
        ws.send_text(json.dumps({"event": "pusher:subscribe", "data": {"channel": channel, "auth": auth}}))
        self._await(ws, "pusher_internal:subscription_succeeded")

        self.connected = True
        self.connections += 1
        self.log("realtime: subscribed to %s" % channel)
        if self.on_connect:
            self.on_connect()

        last_ping = time.time()
        while not self._stop.is_set():
            message = ws.receive(timeout=1.0)
            if message is not None:
                self._handle(json.loads(message))
            if time.time() - last_ping >= self.ping_interval:
                ws.send_text(json.dumps({"event": "pusher:ping", "data": {}}))
                last_ping = time.time()

    def _await(self, ws, name, timeout=10):
        deadline = time.time() + timeout
        while time.time() < deadline:
            message = ws.receive(timeout=1.0)
            if message is None:
                continue
            event = json.loads(message)
            if event.get("event") == name:
                return event
            if event.get("event") == "pusher:error":
                raise RealtimeError("server error: %s" % _data(event).get("message"))
            self._handle(event)
        raise RealtimeError("timed out waiting for %s" % name)

    def _handle(self, event):
        name = event.get("event")
        if name == "command.queued":
            self.on_command(_data(event))
        elif name == "pusher:ping":
            self._ws.send_text(json.dumps({"event": "pusher:pong", "data": {}}))
        elif name == "pusher:error":
            raise RealtimeError("server error: %s" % _data(event).get("message"))

    def _close(self):
        ws, self._ws = self._ws, None
        if ws is not None:
            ws.close()


def _data(event):
    """Pusher sends event data as a JSON string; tolerate a dict too."""
    data = event.get("data")
    if isinstance(data, dict):
        return data
    if not data:
        return {}
    return json.loads(data)
