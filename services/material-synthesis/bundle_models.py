"""Import pinned model artifacts once; GPU jobs subsequently run offline.

Use an authenticated Hugging Face cache or HF_TOKEN. This script never writes
credentials into the bundle. Accept CHORD's access terms in your account first.
"""
import argparse
import hashlib
import json
from pathlib import Path
import shutil
import tarfile
import tempfile

from huggingface_hub import hf_hub_download, snapshot_download

CHORD = ('Ubisoft/ubisoft-laforge-chord', '2b61fc12f316b4692c2a79651e03340427450735')
# The original Stability SD2.1 repository and CHORD's default mirror are no
# longer publicly accessible. Only architecture/tokenizer configs are used;
# ALL learned CHORD parameters come from Ubisoft's checkpoint.
BASE = ('Manojb/stable-diffusion-2-1-base', '0094d483a120f3f33dafbd187ea4aa60d10de75c')
CLEANUP = ('Stable-X/yoso-delight-v0-4-base', 'a06a968fbc59830ad36f2a7256d191bd0c20d3ce')
RGBX = ('zheng95z/rgb-to-x', 'b38b3fd73a14ea62f3953fc54bc4ac67b067bae0')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('output', type=Path)
    parser.add_argument('--cleanup', action='store_true')
    parser.add_argument('--backend', choices=['chord', 'rgbx'], default='chord')
    args = parser.parse_args()
    if args.output.exists():
        parser.error('Output already exists; choose a new versioned bundle path.')
    args.output.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory() as temp:
        root = Path(temp)
        if args.backend == 'chord':
            checkpoint = hf_hub_download(CHORD[0], 'chord_v1.safetensors', revision=CHORD[1])
            shutil.copyfile(checkpoint, root / 'chord_v1.safetensors')
            shutil.copyfile(hf_hub_download(CHORD[0], 'LICENSE', revision=CHORD[1]), root / 'CHORD-LICENSE')
            snapshot_download(BASE[0], revision=BASE[1], local_dir=root / 'base', allow_patterns=['*/config.json', 'scheduler/*.json', 'tokenizer/*.json', 'tokenizer/*.txt', 'README.md', 'LICENSE*'])
        else:
            snapshot_download(RGBX[0], revision=RGBX[1], local_dir=root / 'rgbx', allow_patterns=['*.json', '**/*.json', '**/*.txt', '**/*.safetensors', 'README.md', 'LICENSE*'])
        if args.cleanup:
            snapshot_download(CLEANUP[0], revision=CLEANUP[1], local_dir=root / 'cleanup', allow_patterns=['*.json', '**/*.json', '**/*.txt', '**/*.fp16.safetensors', 'README.md', 'LICENSE*'])
        # Hub cache metadata is unnecessary and isn't part of model provenance.
        for folder in root.glob('*/.cache'):
            shutil.rmtree(folder)
        manifest = {'schema': 2, 'backend': args.backend, 'model': CHORD if args.backend == 'chord' else RGBX, 'base_configuration': BASE if args.backend == 'chord' else None, 'cleanup': CLEANUP if args.cleanup else None, 'files': {}}
        for path in sorted(root.rglob('*')):
            if path.is_file():
                with path.open('rb') as source:
                    manifest['files'][str(path.relative_to(root))] = hashlib.file_digest(source, 'sha256').hexdigest()
        (root / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
        with tarfile.open(args.output, 'w') as archive:
            for path in sorted(root.rglob('*')):
                if path.is_file():
                    archive.add(path, arcname=str(path.relative_to(root)), recursive=False)
    with args.output.open('rb') as source:
        digest = hashlib.file_digest(source, 'sha256').hexdigest()
    args.output.with_suffix(args.output.suffix + '.sha256').write_text(digest + '\n')
    print(json.dumps({'file': str(args.output), 'sha256': digest, 'cleanup': args.cleanup}))


if __name__ == '__main__':
    main()
