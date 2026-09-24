"""Publish immutable consumer versions; update the compatibility alias last."""
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

client, directory = sys.argv[1:]
if client not in ('revit', 'omniverse'):
    raise SystemExit('Unknown client')
tag = os.environ['GITHUB_REF_NAME']
match = re.fullmatch(rf'{client}/v(\d+\.\d+\.\d+)', tag)
if not match:
    raise SystemExit('A client/vMAJOR.MINOR.PATCH tag is required')
root = Path(directory)
assets = sorted(str(p) for p in root.iterdir() if p.is_file() and p.suffix in ('.json', '.zip', '.exe'))
version = match[1]
notes = root / 'release-notes.md'
notes.write_text(f'OPAL {client.title()} {version}\n\nBuilt from {os.environ["GITHUB_SHA"]}. Download and setup: https://opal.olsyn.com/connect\n\nThe release includes SHA-256 manifests. Revit 2024–2027 and Omniverse Kit builds are packaged independently.\n', encoding='utf-8')
def gh(*args, **kwargs):
    return subprocess.run(['gh', *args], check=True, **kwargs)
# No clobber: a version is immutable once published.
gh('release', 'create', tag, *assets, '--verify-tag', '--title', f'OPAL {client.title()} {version}', '--notes-file', str(notes), '--latest=false')
alias = f'{client}-latest'
exists = subprocess.run(['gh', 'release', 'view', alias], capture_output=True).returncode == 0
if exists:
    with tempfile.TemporaryDirectory() as tmp:
        gh('release', 'download', alias, '--pattern', 'release-manifest.json', '--dir', tmp)
        previous = json.loads((Path(tmp) / 'release-manifest.json').read_text(encoding='utf-8-sig'))
        if tuple(map(int, previous['version'].split('.'))) > tuple(map(int, version.split('.'))):
            raise SystemExit('Published historical version; latest remains newer.')
    # Upload payloads before manifests, so clients never see an unavailable payload.
    binaries = [p for p in assets if not p.endswith('.json')]
    manifests = [p for p in assets if p.endswith('.json')]
    gh('release', 'upload', alias, *binaries, '--clobber')
    gh('release', 'upload', alias, *manifests, '--clobber')
    gh('release', 'edit', alias, '--title', f'OPAL {client.title()} {version}', '--notes-file', str(notes), '--latest=false')
else:
    gh('release', 'create', alias, *assets, '--target', os.environ['GITHUB_SHA'], '--title', f'OPAL {client.title()} {version}', '--notes-file', str(notes), '--latest=false')
