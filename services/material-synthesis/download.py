"""Bounded parallel reads for large private S3 model bundles."""
from concurrent.futures import ThreadPoolExecutor
import hashlib
import os
from pathlib import Path
import time

import requests

PART_BYTES = 64 * 1024 * 1024
MAX_BYTES = 40 * 1024**3


def download(url, destination: Path, cancelled) -> str:
    # A presigned GET cannot be used as HEAD: the HTTP method is signed.
    # Open a streaming GET to discover its size, without buffering the body.
    with requests.get(url, stream=True, timeout=(10, 120)) as response:
        response.raise_for_status()
        size = int(response.headers.get('Content-Length', '0'))
        if not 0 < size <= MAX_BYTES:
            raise ValueError('Invalid model bundle size.')
    with destination.open('w+b') as output:
        output.truncate(size)
        descriptor = output.fileno()

        def part(start):
            end = min(size, start + PART_BYTES) - 1
            for attempt in range(3):
                if cancelled.is_set():
                    raise RuntimeError('Model download cancelled.')
                try:
                    with requests.get(url, headers={'Range': f'bytes={start}-{end}'}, stream=True, timeout=(10, 120)) as response:
                        response.raise_for_status()
                        if response.status_code != 206 or response.headers.get('Content-Range') != f'bytes {start}-{end}/{size}':
                            raise ValueError('Storage did not return the requested model range.')
                        offset = start
                        for chunk in response.iter_content(1024 * 1024):
                            if cancelled.is_set():
                                raise RuntimeError('Model download cancelled.')
                            if offset + len(chunk) > end + 1:
                                raise ValueError('Model range exceeds its declared size.')
                            view = memoryview(chunk)
                            while view:
                                written = os.pwrite(descriptor, view, offset)
                                if written <= 0:
                                    raise OSError('Could not write model bundle.')
                                offset += written
                                view = view[written:]
                        if offset != end + 1:
                            raise ValueError('Incomplete model range.')
                        return
                except requests.RequestException:
                    if attempt == 2:
                        raise
                    time.sleep(2 ** attempt)

        with ThreadPoolExecutor(max_workers=8) as pool:
            # Observe every future; failures must prevent extraction/inference.
            list(pool.map(part, range(0, size, PART_BYTES)))
    with destination.open('rb') as source:
        return hashlib.file_digest(source, 'sha256').hexdigest()
