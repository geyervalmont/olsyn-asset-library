# -*- coding: utf-8 -*-
"""An in-process WebSocket server speaking just enough Pusher for tests."""

import base64
import hashlib
import json
import socket
import struct
import threading

WS_GUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"


class PusherTestServer(object):
    def __init__(self, key="testkey"):
        self.key = key
        self.sock = socket.socket()
        self.sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        self.sock.bind(("127.0.0.1", 0))
        self.sock.listen(5)
        self.port = self.sock.getsockname()[1]
        self.clients = []
        self.subscriptions = []
        self.pings = 0
        self.paths = []
        self.connected = threading.Event()
        self.subscribed = threading.Event()
        self._running = True
        self._thread = threading.Thread(target=self._accept_loop)
        self._thread.daemon = True
        self._thread.start()

    def realtime(self):
        return {"scheme": "http", "host": "127.0.0.1", "port": self.port, "key": self.key, "auth_endpoint": "/broadcasting/auth"}

    def push(self, event, data, channel):
        for client in list(self.clients):
            self._send(client, {"event": event, "channel": channel, "data": json.dumps(data)})

    def drop_clients(self):
        for client in list(self.clients):
            try:
                client.shutdown(socket.SHUT_RDWR)
                client.close()
            except Exception:
                pass
        self.clients = []
        self.subscribed.clear()

    def stop(self):
        self._running = False
        self.drop_clients()
        try:
            self.sock.close()
        except Exception:
            pass

    # -- internals -----------------------------------------------------------

    def _accept_loop(self):
        while self._running:
            try:
                conn, _ = self.sock.accept()
            except Exception:
                return
            thread = threading.Thread(target=self._serve, args=(conn,))
            thread.daemon = True
            thread.start()

    def _serve(self, conn):
        try:
            head = b""
            while b"\r\n\r\n" not in head:
                chunk = conn.recv(4096)
                if not chunk:
                    return
                head += chunk
            head, rest = head.split(b"\r\n\r\n", 1)
            lines = head.decode("iso-8859-1").split("\r\n")
            self.paths.append(lines[0].split(" ")[1])
            key = [l.split(":", 1)[1].strip() for l in lines if l.lower().startswith("sec-websocket-key")][0]
            accept = base64.b64encode(hashlib.sha1((key + WS_GUID).encode("ascii")).digest()).decode("ascii")
            conn.sendall(("HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: %s\r\n\r\n" % accept).encode("ascii"))
            self.clients.append(conn)
            socket_id = "%d.%d" % (len(self.paths), 42)
            self._send(conn, {"event": "pusher:connection_established", "data": json.dumps({"socket_id": socket_id, "activity_timeout": 30})})
            self.connected.set()

            buffer = rest
            while self._running:
                frame, buffer = self._read_frame(conn, buffer)
                if frame is None:
                    return
                opcode, payload = frame
                if opcode == 0x8:
                    return
                if opcode == 0x9:
                    self._send_raw(conn, 0xA, payload)
                    continue
                if opcode != 0x1:
                    continue
                event = json.loads(payload.decode("utf-8"))
                if event["event"] == "pusher:subscribe":
                    self.subscriptions.append((event["data"]["channel"], event["data"]["auth"], socket_id))
                    if event["data"]["auth"] != "%s:signed-%s" % (self.key, socket_id):
                        self._send(conn, {"event": "pusher:error", "data": json.dumps({"message": "bad auth"})})
                        continue
                    self._send(conn, {"event": "pusher_internal:subscription_succeeded", "channel": event["data"]["channel"], "data": "{}"})
                    self.subscribed.set()
                elif event["event"] == "pusher:ping":
                    self.pings += 1
                    self._send(conn, {"event": "pusher:pong", "data": "{}"})
        finally:
            if conn in self.clients:
                self.clients.remove(conn)
            try:
                conn.close()
            except Exception:
                pass

    def _read_frame(self, conn, buffer):
        def need(count):
            data = buffer[0]
            while len(data) < count:
                chunk = conn.recv(4096)
                if not chunk:
                    return None
                data += chunk
            buffer[0] = data
            return True

        buffer = [buffer]
        if not need(2):
            return None, b""
        head = bytearray(buffer[0][:2])
        opcode = head[0] & 0x0F
        length = head[1] & 0x7F
        offset = 2
        if length == 126:
            if not need(4):
                return None, b""
            length = struct.unpack("!H", bytes(buffer[0][2:4]))[0]
            offset = 4
        elif length == 127:
            if not need(10):
                return None, b""
            length = struct.unpack("!Q", bytes(buffer[0][2:10]))[0]
            offset = 10
        if not need(offset + 4 + length):
            return None, b""
        mask = bytearray(buffer[0][offset:offset + 4])
        payload = bytearray(buffer[0][offset + 4:offset + 4 + length])
        for i in range(len(payload)):
            payload[i] ^= mask[i % 4]
        return (opcode, bytes(payload)), buffer[0][offset + 4 + length:]

    def _send(self, conn, event):
        self._send_raw(conn, 0x1, json.dumps(event).encode("utf-8"))

    def _send_raw(self, conn, opcode, payload):
        header = bytearray([0x80 | opcode])
        if len(payload) < 126:
            header.append(len(payload))
        elif len(payload) < 65536:
            header.append(126)
            header += struct.pack("!H", len(payload))
        else:
            header.append(127)
            header += struct.pack("!Q", len(payload))
        try:
            conn.sendall(bytes(header) + payload)
        except Exception:
            pass
