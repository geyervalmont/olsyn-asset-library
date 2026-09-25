import hashlib
import json
from pathlib import Path
import tempfile
import unittest
import uuid

from bridge import AccessLost, Api, Bridge, Conflict, digest, portable


class FakeApi:
    def __init__(self):
        self.batch = str(uuid.uuid4())
        self.files = {}
        self.uploads = 0
        self.beats = []
        self.allowed = True

    def bootstrap(self):
        return {'account': {'id': '7'}, 'capabilities': {'intake': self.allowed},
                'layout': {'label': 'OPAL', 'revision': 1, 'materials': '/materials', 'upload': '/upload'}}

    def inbox(self):
        return {'id': self.batch, 'writable': True, 'path': '/upload', 'files': [self.item(name, data) for name, data in self.files.items()]}

    def item(self, name, data):
        return {'id': str(uuid.uuid4()), 'path': name, 'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest(), 'uploaded': True}

    def upload(self, batch, name, local, size, sha):
        assert batch == self.batch and digest(local) == (size, sha)
        data = local.read_bytes()
        if name in self.files and self.files[name] != data:
            raise Conflict()
        self.files[name] = data
        self.uploads += 1

    def download(self, batch, item, local):
        assert batch == self.batch
        local.write_bytes(self.files[item['path']])

    def heartbeat(self, *args):
        self.beats.append(args)


class FakeNative:
    administrator = 'admin'

    def __init__(self):
        self.files = {}
        self.folders = set()
        self.acls = {}
        self.versions = {}
        self.after_download = None

    def put(self, path, data):
        self.files[path] = data
        self.versions[path] = self.versions.get(path, 0) + 1
        for parent in Path(path).parents:
            if str(parent) != '.':
                self.folders.add(str(parent))

    def mkdir(self, path):
        self.folders.add(path)

    def acl(self, path, username, access):
        self.acls[path] = access

    def url(self, path):
        return 'omniverse://nucleus.test/OPAL/upload/' + path

    def list(self, path, recursive=False):
        result = {}
        for entry in self.folders | self.files.keys():
            if entry.startswith(path + '/'):
                name = entry[len(path) + 1:]
                if recursive or '/' not in name:
                    result[name] = self.stat(entry)
        return result

    def stat(self, path):
        return {'directory': path in self.folders, 'bytes': len(self.files.get(path, b'')),
                'version': self.versions.get(path, 0), 'modified': 'today'}

    def download(self, path, local):
        local.write_bytes(self.files[path])
        if self.after_download:
            self.after_download(path)

    def upload(self, local, path):
        if path in self.files:
            raise Conflict()
        self.put(path, local.read_bytes())


class BridgeTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.api, self.native = FakeApi(), FakeNative()
        self.now = 0
        self.link = {'username': 'designer', 'account_id': '7', 'device_id': str(uuid.uuid4())}
        self.bridge = Bridge(self.api, self.native, self.link, self.temp.name, lambda: self.now)

    def cycle(self):
        self.now += 10
        return self.bridge.cycle()

    def test_native_upload_retries_and_receipts_survive_restart(self):
        path = 'designer/' + self.api.batch + '/nested/stone.mdl'
        self.native.put(path, b'material')
        self.cycle()
        self.assertEqual(self.api.uploads, 0)
        self.cycle()
        self.assertEqual(self.api.files['nested/stone.mdl'], b'material')
        self.bridge = Bridge(self.api, self.native, self.link, self.temp.name, lambda: self.now)
        self.cycle()
        self.assertEqual(self.api.uploads, 1)

    def test_other_device_upload_mirrors_without_replacing_native_file(self):
        self.api.files['nested/a.png'] = b'server'
        self.cycle()
        path = 'designer/' + self.api.batch + '/nested/a.png'
        self.assertEqual(self.native.files[path], b'server')
        self.native.put(path, b'local replacement')
        self.cycle()
        result = self.cycle()
        self.assertEqual(result['conflicts'], 1)
        self.assertEqual(self.native.files[path], b'local replacement')
        self.assertEqual(self.api.files['nested/a.png'], b'server')
        self.assertEqual(self.api.beats[-1][1], 'error')

    def test_batch_rotation_cannot_replay_old_files(self):
        old = self.api.batch
        self.native.put('designer/' + old + '/pending.png', b'pending')
        self.cycle()
        self.api.batch = str(uuid.uuid4())
        self.cycle()
        self.cycle()
        self.assertEqual(self.api.uploads, 0)
        self.assertEqual(self.native.acls['designer/' + old], 0)
        self.assertIn('designer/' + old + '/pending.png', self.native.files)

    def test_changes_during_copy_are_deferred(self):
        path = 'designer/' + self.api.batch + '/changing.png'
        self.native.put(path, b'first')
        self.cycle()
        self.native.after_download = lambda path: self.native.put(path, b'changed')
        self.cycle()
        self.assertEqual(self.api.uploads, 0)
        self.native.after_download = None
        self.cycle()
        self.cycle()
        self.assertEqual(self.api.files['changing.png'], b'changed')

    def test_account_and_permission_are_checked_before_any_copy(self):
        self.api.allowed = False
        with self.assertRaises(AccessLost):
            self.cycle()
        self.assertFalse(self.native.folders)
        self.api.allowed = True
        self.link['account_id'] = '8'
        with self.assertRaises(AccessLost):
            self.cycle()

    def test_lockdown_also_removes_explicit_batch_grants(self):
        self.cycle()
        self.bridge.lockdown()
        self.assertEqual(self.native.acls['designer'], 0)
        self.assertEqual(self.native.acls['designer/' + self.api.batch], 0)

    def test_pending_server_reservation_is_not_mirrored(self):
        original = self.api.inbox
        self.api.inbox = lambda: dict(original(), files=[dict(self.api.item('pending.png', b'not uploaded'), uploaded=False)])
        self.cycle()
        self.assertFalse(self.native.files)

    def test_unsafe_paths_and_privileged_identities_rejected(self):
        for path in ('../a', '/a', 'a\\b', 'a//b', 'CON.png', 'a.', 'a:', 'a\n'):
            with self.assertRaises(ValueError):
                portable(path)
        for name in ('gm', 'users', 'admin', 'nested/user'):
            with self.assertRaises(ValueError):
                Bridge(self.api, self.native, dict(self.link, username=name), self.temp.name)

    def test_api_origins_cannot_redirect_tokens_to_http_or_userinfo(self):
        for origin in ('http://opal.test', 'https://user:pass@opal.test', 'https://opal.test/path', 'https://opal.test?x=y'):
            with self.assertRaises(ValueError):
                Api(origin, 'secret')


if __name__ == '__main__':
    unittest.main()
