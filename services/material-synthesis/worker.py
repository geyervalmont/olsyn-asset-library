"""One immutable OPAL generation attempt. No public inference/model-hub calls."""
import hashlib
import io
import json
import os
from pathlib import Path
import tarfile
import tempfile
import threading
import time
import traceback
from urllib.parse import urlparse

import numpy as np
from PIL import Image
import requests
from image_ops import prepare, normalize_normals, height_from_normals, encode_png


class Cancelled(Exception):
    pass


class Worker:
    def __init__(self):
        self.url = os.environ['OPAL_URL'].rstrip('/') + '/api/synthesis-runs/' + os.environ['OPAL_RUN']
        self.session = requests.Session()
        self.session.headers['Authorization'] = 'Bearer ' + os.environ['OPAL_TOKEN']
        self.stage = 'loading_models'
        self.stop = threading.Event()
        self.cancelled = threading.Event()

    def request(self, method, suffix='', **kwargs):
        response = self.session.request(method, self.url + suffix, timeout=(10, 120), **kwargs)
        if response.status_code in (401, 403, 410):
            self.cancelled.set()
            raise Cancelled('The generation attempt was cancelled or expired.')
        response.raise_for_status()
        return response

    def progress(self, stage):
        if self.cancelled.is_set():
            raise Cancelled()
        self.stage = stage
        self.request('POST', '/progress', json={'stage': stage})

    def heartbeat(self):
        # Separate HTTP session; requests.Session isn't shared across threads.
        while not self.stop.wait(30):
            try:
                response = requests.post(self.url + '/progress', headers={'Authorization': self.session.headers['Authorization']}, json={'stage': self.stage}, timeout=15)
                if response.status_code in (401, 403, 410):
                    self.cancelled.set()
                    # This is a dedicated job process. Stop CUDA work immediately.
                    os._exit(2)
            except requests.RequestException:
                pass  # Kubernetes deadline + broker reconciliation are the fallback.

    def upload(self, role, path):
        data = path.read_bytes()
        for attempt in range(3):
            try:
                self.request('POST', '/artifacts/' + role, files={'image': (role + '.png', data, 'image/png')}, data={'sha256': hashlib.sha256(data).hexdigest()})
                return
            except requests.RequestException:
                if attempt == 2:
                    raise
                time.sleep(2 ** attempt)

    def bundle(self, spec, directory):
        parsed = urlparse(spec['model_url'])
        if parsed.scheme != 'https':
            raise ValueError('Model bundles must use HTTPS.')
        archive = directory / 'models.tar'
        from download import download
        # Storage requests deliberately carry no OPAL bearer token.
        digest = download(spec['model_url'], archive, self.cancelled)
        if digest != spec['model_sha256']:
            raise ValueError('Model bundle checksum mismatch.')
        models = directory / 'models'
        models.mkdir()
        with tarfile.open(archive) as tar:
            # No symlinks, devices or paths outside the destination.
            for member in tar.getmembers():
                if not (member.isfile() or member.isdir()) or Path(member.name).is_absolute() or '..' in Path(member.name).parts:
                    raise ValueError('Unsafe model bundle entry.')
            tar.extractall(models, filter='data')
        archive.unlink()
        return models

    def run(self):
        started = time.monotonic()
        spec = self.request('GET').json()
        thread = threading.Thread(target=self.heartbeat, daemon=True)
        thread.start()
        try:
            with tempfile.TemporaryDirectory(dir='/work') as tmp:
                root = Path(tmp)
                self.progress('loading_models')
                models = self.bundle(spec, root)
                import torch
                if not torch.cuda.is_available():
                    raise RuntimeError('A CUDA GPU is required; CPU fallback is disabled.')
                seed = int(hashlib.sha256(spec['run'].encode()).hexdigest()[:8], 16)
                torch.manual_seed(seed)
                np.random.seed(seed)
                self.progress('preparing_photo')
                source = self.request('GET', '/source').content
                if hashlib.sha256(source).hexdigest() != spec['source_sha256']:
                    raise ValueError('Source checksum mismatch.')
                image = prepare(Image.open(io.BytesIO(source)), spec['crop'], spec['resolution'])
                prepared = root / 'prepared.png'
                image.save(prepared)
                self.upload('prepared', prepared)
                if spec['cleanup'] > 0:
                    self.progress('cleaning_photo')
                    from stabledelight.system.pipeline_yoso_delight import YosoDelightPipeline
                    pipeline = YosoDelightPipeline.from_pretrained(str(models / 'cleanup'), local_files_only=True, safety_checker=None, variant='fp16', torch_dtype=torch.float16, t_start=0).to('cuda')
                    with torch.inference_mode():
                        predicted = pipeline(image, processing_resolution=spec['resolution']).prediction
                    cleaned = Image.fromarray(np.round((np.clip(predicted[0], -1, 1) + 1) * 127.5).astype(np.uint8)).resize(image.size)
                    image = Image.blend(image, cleaned, spec['cleanup'] / 100)
                    path = root / 'cleaned.png'
                    image.save(path)
                    self.upload('cleaned', path)
                    del pipeline
                    torch.cuda.empty_cache()
                self.progress('estimating_material')
                from backends import estimate
                backend = spec.get('backend', 'chord')
                bundle_manifest = json.loads((models / 'manifest.json').read_text())
                if bundle_manifest.get('backend', 'chord') != backend:
                    raise ValueError('Model bundle does not match the requested backend.')
                inference_started = time.monotonic()
                maps, model_manifest = estimate(image, models, backend, seed)
                torch.cuda.synchronize()
                inference_seconds = time.monotonic() - inference_started
                maps['height'] = height_from_normals(maps['normal'])
                self.progress('uploading_maps')
                for role, value in maps.items():
                    path = root / (role + '.png')
                    encode_png(value, path, height=role == 'height')
                    self.upload(role, path)
                manifest = {'normal_convention': 'opengl', 'model_sha256': spec['model_sha256'], **model_manifest, 'inference_seconds': inference_seconds, 'cleanup_revision': os.environ['CLEANUP_REVISION'] if spec['cleanup'] else None, 'seed': seed, 'height_method': 'periodic-normal-integration-relative', 'seconds': time.monotonic() - started, 'peak_vram_bytes': torch.cuda.max_memory_allocated()}
                for attempt in range(3):
                    try:
                        self.request('POST', '/complete', json=manifest)
                        break
                    except requests.RequestException:
                        if attempt == 2:
                            raise
                        time.sleep(2 ** attempt)
                print(json.dumps({'status': 'succeeded', **manifest}), flush=True)
        except Cancelled:
            print('Generation cancelled.', flush=True)
            raise
        except Exception as exc:
            # Exception text may contain signed URLs. Only emit its class.
            print(json.dumps({'status': 'failed', 'error_type': type(exc).__name__, 'stage': self.stage, 'frames': [{'file': frame.filename, 'line': frame.lineno, 'function': frame.name} for frame in traceback.extract_tb(exc.__traceback__)]}), flush=True)
            try:
                self.request('POST', '/progress', json={'stage': self.stage, 'error': type(exc).__name__})
            except Exception:
                pass
            raise SystemExit(1) from None
        finally:
            self.stop.set()


if __name__ == '__main__':
    Worker().run()
