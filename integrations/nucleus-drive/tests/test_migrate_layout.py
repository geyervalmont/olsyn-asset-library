from types import SimpleNamespace
import unittest

from migrate_layout import migrate


class NativeSDK:
    Result = SimpleNamespace(OK=0, ERROR_NOT_FOUND=1)
    ItemFlags = SimpleNamespace(CAN_HAVE_CHILDREN=1, IS_MOUNT=2, IS_INSIDE_MOUNT=4)
    CopyBehavior = SimpleNamespace(ERROR_IF_EXISTS=0)

    def __init__(self, old=True, new=False):
        self.base = 'omniverse://nucleus.test/OPAL'
        self.paths = {self.base: 1}
        self.acls, self.moves = {}, []
        if old:
            self.paths.update({self.base + '/upload': 1, self.base + '/upload/original.png': 0,
                               self.base + '/upload/.thumbs': 1, self.base + '/upload/.thumbs/preview.png': 0})
            self.acls[self.base + '/upload'] = 'original private ACL'
        if new:
            self.paths[self.base + '/ingestion/upload'] = 1

    def stat(self, url):
        return (0, SimpleNamespace(flags=self.paths[url])) if url in self.paths else (1, None)

    def create_folder(self, url):
        self.paths[url] = 1
        return 0

    @staticmethod
    def AclEntry(name, access):
        return name, access

    def set_acls(self, url, acl):
        self.acls[url] = acl
        return 0

    def move(self, source, destination, behavior):
        assert behavior == 0 and destination not in self.paths
        for path in list(self.paths):
            if path == source or path.startswith(source + '/'):
                self.paths[destination + path[len(source):]] = self.paths.pop(path)
                if path in self.acls:
                    self.acls[destination + path[len(source):]] = self.acls.pop(path)
        self.moves.append((source, destination))
        return 0, False


class MigrationTest(unittest.TestCase):
    def test_move_preserves_content_acls_and_is_idempotent(self):
        sdk = NativeSDK()
        migrate(sdk, 'nucleus.test', 'admin')
        after = dict(sdk.paths)
        migrate(sdk, 'nucleus.test', 'admin')
        self.assertEqual(sdk.paths, after)
        self.assertEqual(len(sdk.moves), 1)
        self.assertIn(sdk.base + '/ingestion/upload/original.png', sdk.paths)
        self.assertIn(sdk.base + '/ingestion/upload/.thumbs/preview.png', sdk.paths)
        self.assertEqual(sdk.acls[sdk.base + '/ingestion/upload'], 'original private ACL')
        self.assertIn(('users', 0), sdk.acls[sdk.base + '/ingestion/workspace'])

    def test_conflicting_roots_are_not_merged_or_modified(self):
        sdk = NativeSDK(new=True)
        before = dict(sdk.paths)
        with self.assertRaises(ValueError):
            migrate(sdk, 'nucleus.test', 'admin')
        self.assertEqual(sdk.paths, before)
        self.assertEqual(sdk.moves, [])

    def test_fresh_install_creates_private_folders(self):
        sdk = NativeSDK(old=False)
        migrate(sdk, 'nucleus.test', 'admin')
        self.assertIn(('users', 0), sdk.acls[sdk.base + '/ingestion/upload'])
        self.assertIn(sdk.base + '/ingestion/workspace', sdk.paths)

    def test_mount_is_never_moved(self):
        sdk = NativeSDK()
        sdk.paths[sdk.base + '/upload'] = 3
        with self.assertRaises(ValueError):
            migrate(sdk, 'nucleus.test', 'admin')
        self.assertEqual(sdk.moves, [])
