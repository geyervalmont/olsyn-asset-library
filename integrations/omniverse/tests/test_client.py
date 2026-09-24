import hashlib
import io
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'exts' / 'olsyn.opal'))
from olsyn.opal.client import Client, safe_path, NoRedirect, discover_drive

class ClientTest(unittest.TestCase):
    def test_drive_discovery_requires_matching_account_origin_and_live_mount(self):
        with tempfile.TemporaryDirectory() as root:
            (Path(root) / 'drive-connection.json').write_text(json.dumps({'server': 'https://opal.olsyn.com', 'account_email': 'designer@example.com', 'mount_path': 'O:\\'}))
            with patch.object(Path, 'is_dir', return_value=True):
                self.assertEqual(discover_drive('https://opal.olsyn.com', 'designer@example.com', root), 'O:\\')
                self.assertEqual(discover_drive('https://opal.olsyn.com', 'other@example.com', root), '')
                self.assertEqual(discover_drive('https://other.test', 'designer@example.com', root), '')
            with patch.object(Path, 'is_dir', return_value=False):
                self.assertEqual(discover_drive('https://opal.olsyn.com', 'designer@example.com', root), '')

    def test_paths_and_origins_cannot_escape(self):
        client = Client()
        for value in ['https://evil.test/api/v1/f', '//evil.test/api/v1/f', '/downloads/a']:
            with self.assertRaises(ValueError):
                client.url(value)
        with tempfile.TemporaryDirectory() as root:
            for path in ['/../secret', '/x/../../secret', '/x\\secret', '/c:/secret']:
                with self.assertRaises(ValueError):
                    safe_path(root, path)
        with self.assertRaises(RuntimeError):
            NoRedirect().redirect_request(None, None, 302, '', {}, 'https://evil.test')

    def test_verified_cache_is_atomic_and_corruption_never_replaces_good_bytes(self):
        client = Client(token='secret')
        with tempfile.TemporaryDirectory() as root:
            path = Path(root) / 'map.png'
            with patch.object(client.opener, 'open', return_value=io.BytesIO(b'valid')):
                client.download('/api/v1/map', path, hashlib.sha256(b'valid').hexdigest(), 5)
            with patch.object(client.opener, 'open', return_value=io.BytesIO(b'bad')):
                with self.assertRaises(ValueError):
                    client.download('/api/v1/map', path, hashlib.sha256(b'valid').hexdigest(), 5)
            self.assertEqual(path.read_bytes(), b'valid')
            self.assertEqual(list(Path(root).iterdir()), [path])

if __name__ == '__main__':
    unittest.main()
