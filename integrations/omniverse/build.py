"""Build a portable Kit extension ZIP, without installing a Kit host in CI."""
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import re
import zipfile

root = Path(__file__).resolve().parent
ref = os.environ.get('GITHUB_REF_NAME', '')
if os.environ.get('GITHUB_REF_TYPE') == 'tag' and not re.fullmatch(r'omniverse/v\d+\.\d+\.\d+', ref):
    raise SystemExit('Use omniverse/vMAJOR.MINOR.PATCH')
version = ref.split('/v')[1] if ref.startswith('omniverse/v') else '0.2.0'
out = root.parent.parent / 'artifacts' / 'omniverse'
out.mkdir(parents=True, exist_ok=True)
package = out / 'OPAL-Omniverse.zip'
with zipfile.ZipFile(package, 'w', zipfile.ZIP_DEFLATED) as archive:
    for source in sorted((root / 'exts').rglob('*')):
        if source.is_file() and '__pycache__' not in source.parts and source.suffix != '.pyc':
            data = source.read_bytes()
            if source.name == 'extension.toml':
                data = re.sub(rb'version = "[^"]+"', f'version = "{version}"'.encode(), data, count=1)
            if source.name == 'client.py':
                data = re.sub(rb"APP_VERSION = '[^']+'", f"APP_VERSION = '{version}'".encode(), data, count=1)
            archive.writestr(source.relative_to(root).as_posix(), data)
    archive.write(root / 'README.md', 'README.md')
raw = package.read_bytes()
manifest = {'client': 'omniverse', 'version': version, 'commit': os.environ.get('GITHUB_SHA', 'local'),
            'published_at': datetime.now(timezone.utc).isoformat(), 'host': 'Omniverse Kit',
            'package': {'name': package.name, 'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()}}
(out / 'release-manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
print(f'Built {package} ({len(raw)} bytes)')
