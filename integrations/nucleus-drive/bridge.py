"""Personal Nucleus inbox adapter for the OPAL drive API (stdlib + omni.client).

Nucleus is global, so each linked user gets /OPAL/upload/<username>/<batch UUID>.
The batch UUID is the server's inbox ID, shared with Windows and the website.
Files are append-only: collisions are reported, never overwritten or deleted.
"""
from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import re
import tempfile
import time
from urllib import error, parse, request
import uuid

VERSION = '0.1.0'
FILE_LIMIT = 256 * 1024 * 1024
BATCH_LIMIT = 500


class AccessLost(Exception):
    pass


class Conflict(Exception):
    pass


def portable(path: str, limit: int = 512) -> str:
    if not path or len(path) > limit or path.startswith('/') or '\\' in path:
        raise ValueError('Invalid intake path')
    for part in path.split('/'):
        if not part or len(part) > 200 or part in ('.', '..') or part.endswith(('.', ' ')):
            raise ValueError('Invalid intake path')
        if re.search(r'[\x00-\x1f\x7f<>:"|?*]', part) or re.match(r'^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(\.|$)', part, re.I):
            raise ValueError('Invalid intake path')
    return path


def identifier(value: str) -> str:
    if str(uuid.UUID(value)) != value:
        raise ValueError('Invalid OPAL identifier')
    return value


def digest(path: Path) -> tuple[int, str]:
    size = 0
    sha = hashlib.sha256()
    with path.open('rb') as source:
        for block in iter(lambda: source.read(64 * 1024), b''):
            size += len(block)
            if size > FILE_LIMIT:
                raise ValueError('File exceeds intake limit')
            sha.update(block)
    if size == 0:
        raise ValueError('Empty files are not supported')
    return size, sha.hexdigest()


class NoRedirect(request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class Api:
    def __init__(self, origin: str, token: str = ''):
        parsed = parse.urlsplit(origin)
        if parsed.scheme != 'https' or not parsed.hostname or parsed.username or parsed.password or parsed.path not in ('', '/') or parsed.query or parsed.fragment:
            raise ValueError('An HTTPS OPAL origin is required')
        self.origin, self.token = origin.rstrip('/'), token
        self.opener = request.build_opener(NoRedirect())

    def send(self, method, path, payload=None, stream=None, size=None):
        if not re.fullmatch(r'/api/v1/[a-zA-Z0-9/_?=&%.-]+', path) or '..' in path or path.startswith('//'):
            raise ValueError('Invalid API path')
        headers = {'Accept': 'application/json', 'User-Agent': 'OPAL-Nucleus-Drive/' + VERSION}
        if self.token:
            headers['Authorization'] = 'Bearer ' + self.token
        if stream is not None:
            body = stream
            headers.update({'Content-Type': 'application/octet-stream', 'Content-Length': str(size)})
        else:
            body = None if payload is None else json.dumps(payload).encode()
            if body is not None:
                headers['Content-Type'] = 'application/json'
        try:
            return self.opener.open(request.Request(self.origin + path, data=body, headers=headers, method=method), timeout=30)
        except error.HTTPError as exc:
            if exc.code in (401, 403):
                raise AccessLost() from None
            if exc.code == 409:
                raise Conflict() from None
            # Never log response bodies, tokens, usernames or filenames.
            raise RuntimeError('OPAL HTTP ' + str(exc.code)) from None

    def json(self, method, path, payload=None):
        with self.send(method, path, payload) as response:
            body = response.read(2 * 1024 * 1024 + 1)
            if len(body) > 2 * 1024 * 1024:
                raise ValueError('API response exceeds limit')
            return json.loads(body)

    def bootstrap(self):
        return self.json('GET', '/api/v1/drive/bootstrap')['data']

    def inbox(self):
        return self.json('POST', '/api/v1/drive/intake/inbox')['data']

    def upload(self, batch, path, local, size, sha):
        root = '/api/v1/drive/intake/' + identifier(batch) + '/files'
        item = self.json('POST', root, {'path': path, 'bytes': size, 'sha256': sha})['data']
        if not item['uploaded']:
            with local.open('rb') as source, self.send('PUT', root + '/' + identifier(item['id']), stream=source, size=size):
                pass

    def download(self, batch, item, local):
        url = '/api/v1/drive/intake/' + identifier(batch) + '/files/' + identifier(item['id']) + '/content'
        with self.send('GET', url) as response, local.open('xb') as target:
            total = 0
            while block := response.read(64 * 1024):
                total += len(block)
                if total > min(item['bytes'], FILE_LIMIT):
                    raise ValueError('Download exceeds declared size')
                target.write(block)
        if digest(local) != (item['bytes'], item['sha256']):
            raise ValueError('Intake checksum mismatch')

    def heartbeat(self, device, state, mount, code=None):
        self.json('POST', '/api/v1/drive/heartbeat', {'device_id': device, 'machine': 'Nucleus', 'version': VERSION,
                  'state': state, 'mount_path': mount, 'error_code': code})


class Native:
    def __init__(self, client, host, administrator):
        if not re.fullmatch(r'[a-zA-Z0-9.-]+', host):
            raise ValueError('A plain Nucleus hostname is required')
        self.oc, self.root, self.administrator = client, 'omniverse://' + host + '/OPAL/upload', administrator

    def checked(self, result):
        if result != self.oc.Result.OK:
            raise RuntimeError('Nucleus ' + result.name)

    def url(self, path):
        return self.root if path == '' else self.root + '/' + parse.quote(portable(path, 1024), safe='/')

    def mkdir(self, path):
        result = self.oc.create_folder(self.url(path))
        if result != self.oc.Result.ERROR_ALREADY_EXISTS:
            self.checked(result)

    def acl(self, path, username, access):
        self.checked(self.oc.set_acls(self.url(path), [self.oc.AclEntry('users', 0), self.oc.AclEntry('gm', 7),
                     self.oc.AclEntry(self.administrator, 7), self.oc.AclEntry(username, access)]))

    def parent_acl(self, users):
        self.checked(self.oc.set_acls(self.root, [self.oc.AclEntry('users', 0), self.oc.AclEntry('gm', 7),
                     self.oc.AclEntry(self.administrator, 7)] + [self.oc.AclEntry(name, 1) for name in users]))

    def list(self, path, recursive=False):
        result, entries = self.oc.list(self.url(path))
        self.checked(result)
        found = {}
        for entry in entries:
            name = parse.unquote(entry.relative_path)
            if '/' in name or '\\' in name:
                raise ValueError('Invalid Nucleus child')
            portable(name)
            if entry.flags & (self.oc.ItemFlags.IS_MOUNT | self.oc.ItemFlags.IS_INSIDE_MOUNT):
                raise ValueError('Nested mounts are not supported in intake')
            folder = bool(entry.flags & self.oc.ItemFlags.CAN_HAVE_CHILDREN)
            found[name] = {'directory': folder, 'bytes': entry.size, 'version': entry.version,
                           'modified': str(entry.modified_time)}
            if recursive and folder:
                found.update({name + '/' + key: value for key, value in self.list(path + '/' + name, True).items()})
            if len(found) > BATCH_LIMIT * 8:
                raise ValueError('Nucleus inbox exceeds listing limit')
        return found

    def stat(self, path):
        result, entry = self.oc.stat(self.url(path))
        self.checked(result)
        return {'directory': bool(entry.flags & self.oc.ItemFlags.CAN_HAVE_CHILDREN), 'bytes': entry.size,
                'version': entry.version, 'modified': str(entry.modified_time)}

    def download(self, path, local):
        self.checked(self.oc.copy(self.url(path), str(local), self.oc.CopyBehavior.ERROR_IF_EXISTS))

    def upload(self, local, path):
        self.checked(self.oc.copy(str(local), self.url(path), self.oc.CopyBehavior.ERROR_IF_EXISTS))


class Bridge:
    def __init__(self, api, native, link, state, clock=time.monotonic):
        self.api, self.native, self.link, self.clock = api, native, link, clock
        self.username = portable(link['username'])
        if len(self.username) > 100 or '/' in self.username or self.username in ('users', 'gm', native.administrator):
            raise ValueError('A non-administrator Nucleus user is required')
        identifier(link['device_id'])
        self.state = Path(state)
        self.state.mkdir(mode=0o700, parents=True, exist_ok=True)
        self.observed = {}
        self.last_batch = None
        self.last_heartbeat = 0

    def lockdown(self):
        self.native.mkdir(self.username)
        for name, item in self.native.list(self.username).items():
            if item['directory']:
                identifier(name)
                self.native.acl(self.username + '/' + name, self.username, 0)
        self.native.acl(self.username, self.username, 0)

    def cycle(self):
        account = self.api.bootstrap()
        if str(account['account']['id']) != str(self.link['account_id']) or not account['capabilities']['intake']:
            raise AccessLost()
        if account.get('layout') != {'label': 'OPAL', 'revision': 1, 'materials': '/materials', 'upload': '/upload'}:
            raise ValueError('Unsupported OPAL drive layout')
        inbox = self.api.inbox()
        batch = identifier(inbox['id'])
        if not inbox['writable'] or inbox['path'] != account['layout']['upload']:
            raise AccessLost()
        folder = self.username + '/' + batch
        self.api.heartbeat(self.link['device_id'], 'connecting', self.native.url(folder))
        self.last_heartbeat = self.clock()
        self.native.mkdir(self.username)
        # A new batch is a new native directory. Old bytes can never silently be
        # replayed into a different batch/workspace after submission or restart.
        for name, item in self.native.list(self.username).items():
            if item['directory'] and name != batch:
                identifier(name)
                self.native.acl(self.username + '/' + name, self.username, 0)
        self.native.mkdir(folder)
        self.native.acl(folder, self.username, 3)
        self.native.acl(self.username, self.username, 1)
        snapshot = self.native.list(folder, recursive=True)
        files = {name: item for name, item in snapshot.items() if not item['directory']}
        if len(files) > BATCH_LIMIT:
            raise ValueError('Nucleus inbox exceeds file limit')
        confirmed = {portable(item['path']).casefold(): item for item in inbox['files'] if item['uploaded']}
        for name, item in files.items():
            portable(name)
            if item['bytes'] < 1 or item['bytes'] > FILE_LIMIT:
                raise ValueError('Nucleus file exceeds intake limits')
        # Existing confirmed native versions are verified once and persisted.
        receipt_file = self.state / (batch + '.json')
        receipts = json.loads(receipt_file.read_text()) if receipt_file.exists() else {}
        changes, issues = 0, 0
        for name, item in files.items():
            if self.clock() - self.last_heartbeat >= 30:
                self.api.heartbeat(self.link['device_id'], 'connecting', self.native.url(folder))
                self.last_heartbeat = self.clock()
            key = name.casefold()
            signature = [item['bytes'], item['version'], item['modified']]
            if key in receipts and receipts[key] == signature:
                continue
            observed_key = (batch, name)
            previous = self.observed.get(observed_key)
            self.observed[observed_key] = (signature, self.clock())
            if previous is None or previous[0] != signature or self.clock() - previous[1] < 5:
                continue
            try:
                with tempfile.TemporaryDirectory(dir=self.state) as temporary:
                    local = Path(temporary) / 'payload'
                    self.native.download(folder + '/' + name, local)
                    size, sha = digest(local)
                    # A file can change during transfer; defer until a stable pass.
                    if self.native.stat(folder + '/' + name) != item:
                        continue
                    other = confirmed.get(key)
                    if other and (other['bytes'], other['sha256']) != (size, sha):
                        raise Conflict()
                    self.api.upload(batch, name, local, size, sha)
                    receipts[key] = signature
                    changes += 1
            except Conflict:
                issues += 1
        native_paths = {path.casefold(): value for path, value in snapshot.items()}
        for key, item in confirmed.items():
            name = portable(item['path'])
            if key in native_paths:
                if native_paths[key]['directory']:
                    issues += 1
                continue
            if any(not value['directory'] and key.startswith(path + '/') for path, value in native_paths.items()):
                issues += 1
                continue
            with tempfile.TemporaryDirectory(dir=self.state) as temporary:
                local = Path(temporary) / 'payload'
                self.api.download(batch, item, local)
                # ERROR_IF_EXISTS protects concurrent native uploads. No overwrite.
                self.native.upload(local, folder + '/' + name)
                changes += 1
        temporary = receipt_file.with_suffix('.tmp')
        temporary.write_text(json.dumps(receipts))
        temporary.replace(receipt_file)
        self.observed = {key: value for key, value in self.observed.items() if key[0] == batch and key[1] in files}
        self.last_batch = batch
        self.api.heartbeat(self.link['device_id'], 'error' if issues else 'mounted', self.native.url(folder), 'unknown' if issues else None)
        return {'state': 'conflict' if issues else 'ready', 'transfers': changes, 'conflicts': issues}


def main():
    import omni.client as oc
    config = json.loads(Path(os.environ['OPAL_NUCLEUS_LINKS']).read_text())
    state = Path(os.environ.get('OPAL_NUCLEUS_STATE', '/state'))
    oc.initialize()
    administrator = os.environ['OLSYN_OMNI_USER']
    auth = oc.register_authentication_callback(lambda url: (administrator, os.environ['OLSYN_OMNI_PASS']))
    native = Native(oc, config['host'], administrator)
    bridges = [Bridge(Api(config['origin'], link['token']), native, link, state / link['device_id']) for link in config['links']]
    # Own only /OPAL/upload. Remove stale enrollment grants as well as active
    # grants on startup; parent ACLs cannot override explicit batch ACLs.
    for username, item in native.list('').items():
        if not item['directory'] or username in ('users', 'gm', administrator):
            raise ValueError('Unexpected upload root entry')
        native.acl(username, username, 0)
        for batch, child in native.list(username).items():
            if child['directory']:
                identifier(batch)
                native.acl(username + '/' + batch, username, 0)
    native.parent_acl([])
    import signal
    def stop(signum, frame):
        raise KeyboardInterrupt()
    signal.signal(signal.SIGTERM, stop)
    try:
        while True:
            authorized = []
            results = []
            for bridge in bridges:
                try:
                    result = bridge.cycle()
                    authorized.append(bridge.username)
                except Exception as exc:
                    bridge.lockdown()
                    result = {'state': 'sign_in' if isinstance(exc, AccessLost) else 'error', 'error_type': type(exc).__name__}
                    try:
                        bridge.api.heartbeat(bridge.link['device_id'], 'error', native.url(bridge.username), 'sign_in' if isinstance(exc, AccessLost) else 'unknown')
                    except Exception:
                        pass
                results.append(result)
                print(json.dumps(result), flush=True)
            native.parent_acl(authorized)
            state.mkdir(parents=True, exist_ok=True)
            (state / 'health.json').write_text(json.dumps({'time': time.time(), 'links': results}))
            time.sleep(10)
    finally:
        for bridge in bridges:
            bridge.lockdown()
        native.parent_acl([])
        del auth
        oc.shutdown()


if __name__ == '__main__':
    main()
