import hashlib
from pathlib import Path
import tempfile
import threading
import unittest
from unittest.mock import patch
import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import download


class Response:
    def __init__(self, payload, headers, status=200):
        self.payload, self.headers, self.status_code = payload, headers, status
    def __enter__(self): return self
    def __exit__(self, *args): pass
    def raise_for_status(self): pass
    def iter_content(self, _): yield self.payload


class DownloadTest(unittest.TestCase):
    def test_ranges_reconstruct_exact_bytes_without_forwarding_credentials(self):
        source = bytes(range(256)) * 13
        def get(url, **kwargs):
            self.assertNotIn('Authorization', kwargs.get('headers', {}))
            if 'headers' not in kwargs:
                return Response(b'', {'Content-Length': str(len(source))})
            start, end = map(int, kwargs['headers']['Range'][6:].split('-'))
            return Response(source[start:end+1], {'Content-Range': f'bytes {start}-{end}/{len(source)}'}, 206)
        with tempfile.TemporaryDirectory() as directory, patch.object(download, 'PART_BYTES', 256), patch.object(download.requests, 'get', get):
            path = Path(directory) / 'model.tar'
            digest = download.download('https://storage.test/bundle', path, threading.Event())
            self.assertEqual(path.read_bytes(), source)
            self.assertEqual(digest, hashlib.sha256(source).hexdigest())

    def test_storage_ignoring_range_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory, patch.object(download.requests, 'get', return_value=Response(b'abcd', {'Content-Length': '4'})):
            with self.assertRaises(ValueError):
                download.download('https://storage.test/bundle', Path(directory) / 'bundle', threading.Event())


if __name__ == '__main__': unittest.main()
