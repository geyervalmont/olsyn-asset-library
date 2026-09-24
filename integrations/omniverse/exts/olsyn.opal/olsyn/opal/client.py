"""Small HTTPS client; OS proxies and certificate trust apply. No S3 credentials."""
import hashlib
import json
import os
from pathlib import Path
import re
import tempfile
import urllib.error
import urllib.parse
import urllib.request
import uuid

APP_VERSION = '0.2.1'
SERVER = 'https://opal.olsyn.com'

class ApiError(RuntimeError):
    def __init__(self, message, status):
        super().__init__(message)
        self.status = status

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise RuntimeError('OPAL returned a redirect. Reconnect to the correct server.')

class Client:
    def __init__(self, server=SERVER, token=''):
        parsed = urllib.parse.urlsplit(server)
        if parsed.scheme != 'https' or parsed.username or parsed.password or parsed.query or parsed.fragment:
            raise ValueError('OPAL requires an HTTPS server address.')
        self.server = server.rstrip('/')
        self.token = token
        self.opener = urllib.request.build_opener(NoRedirect())

    def url(self, path):
        url = urllib.parse.urljoin(self.server + '/', path)
        if urllib.parse.urlsplit(url)[:2] != urllib.parse.urlsplit(self.server)[:2]:
            raise ValueError('Cross-origin OPAL URL rejected.')
        if not urllib.parse.urlsplit(url).path.startswith('/api/v1/'):
            raise ValueError('Only OPAL API content can be requested.')
        return url

    def request(self, path, body=None, limit=4 * 1024 * 1024, method=None):
        headers = {'Accept': 'application/json', 'User-Agent': 'OPAL-Omniverse/' + APP_VERSION}
        if self.token:
            headers['Authorization'] = 'Bearer ' + self.token
        data = None if body is None else json.dumps(body).encode()
        if data is not None:
            headers['Content-Type'] = 'application/json'
        req = urllib.request.Request(self.url(path), data=data, headers=headers, method=method)
        try:
            with self.opener.open(req, timeout=30) as response:
                raw = response.read(limit + 1)
                if len(raw) > limit:
                    raise ValueError('OPAL response exceeded the size limit.')
                return json.loads(raw) if raw else {}
        except urllib.error.HTTPError as exc:
            try:
                message = json.loads(exc.read(4096)).get('message', str(exc.code))
            except (ValueError, AttributeError):
                message = f'OPAL request failed ({exc.code}).'
            raise ApiError(message, exc.code) from None

    def browse(self, query='', page=1, category=''):
        return self.request('/api/v1/library?' + urllib.parse.urlencode({'q': query, 'page': page, 'category': category, 'per_page': 24}))

    def resolve(self, uuid, version=None):
        params = {'target': 'omniverse'}
        if version is not None:
            params['version'] = int(version)
        return self.request('/api/v1/library/variants/' + urllib.parse.quote(uuid, safe='') + '/resolve?' + urllib.parse.urlencode(params))['data']

    def download(self, path, destination, expected_sha=None, expected_bytes=None, limit=256 * 1024 * 1024):
        destination = Path(destination)
        destination.parent.mkdir(parents=True, exist_ok=True)
        headers = {'Authorization': 'Bearer ' + self.token} if self.token else {}
        req = urllib.request.Request(self.url(path), headers=headers)
        tmp = None
        try:
            with self.opener.open(req, timeout=60) as response, tempfile.NamedTemporaryFile(dir=destination.parent, delete=False) as output:
                tmp = Path(output.name)
                digest = hashlib.sha256()
                size = 0
                while True:
                    block = response.read(65536)
                    if not block:
                        break
                    size += len(block)
                    if size > limit or (expected_bytes is not None and size > expected_bytes):
                        raise ValueError('Texture exceeds its declared size.')
                    output.write(block)
                    digest.update(block)
            if expected_sha is not None and digest.hexdigest() != expected_sha:
                raise ValueError('Texture checksum did not match the published material.')
            if expected_bytes is not None and size != expected_bytes:
                raise ValueError('Texture download was incomplete.')
            os.replace(tmp, destination)
            return str(destination)
        finally:
            if tmp is not None and tmp.exists():
                tmp.unlink()

    def prepare(self, resolved, cache, mount=''):
        result = {}
        for file in resolved['files']:
            if file['role'] not in ('package', 'base_color', 'normal', 'roughness', 'metallic', 'opacity', 'height'):
                continue
            mounted = safe_path(mount, file['path']) if mount else None
            local = safe_path(cache, file['path'])
            if mounted is not None and verified(mounted, file):
                result[file['role']] = str(mounted)
                continue
            if not verified(local, file):
                self.download(file['url'], local, file['sha256'], file['bytes'], 1024 * 1024 * 1024 if file['role'] == 'package' else 256 * 1024 * 1024)
            result[file['role']] = str(local)
        if 'base_color' not in result and 'package' not in result:
            raise ValueError('The published material has no base colour texture.')
        return result

    def prepare_draft(self, draft, cache):
        draft_id = str(uuid.UUID(draft['id']))
        files = []
        for item in draft['maps']:
            if item['role'] not in ('base_color', 'normal', 'roughness', 'metallic', 'opacity', 'height'):
                continue
            if not re.fullmatch(r'[a-f0-9]{64}', item['sha256']) or not re.fullmatch(r'png|jpg|jpeg|exr', item['extension']):
                raise ValueError('Invalid draft texture identity.')
            files.append(dict(item, path=f'drafts/{draft_id}/{item["sha256"]}.{item["extension"]}'))
        return self.prepare({'files': files}, cache)

def safe_path(root, relative):
    parts = relative.lstrip('/').split('/')
    if any(not p or p in ('.', '..') or re.search(r'[\\:\x00-\x1f]', p) for p in parts):
        raise ValueError('Unsafe material path.')
    base = Path(root).resolve()
    candidate = base.joinpath(*parts).resolve()
    if base not in candidate.parents:
        raise ValueError('Material path escaped its root.')
    return candidate

def verified(path, file):
    if not path.is_file() or path.stat().st_size != file['bytes']:
        return False
    with path.open('rb') as stream:
        digest = hashlib.sha256()
        for block in iter(lambda: stream.read(65536), b''):
            digest.update(block)
        return digest.hexdigest() == file['sha256']


def discover_drive(server, account_email, root=None):
    """Read only the same-account mount advertisement; it never carries a token."""
    if not account_email:
        return ''
    root = Path(root) if root is not None else Path(os.environ.get('LOCALAPPDATA', Path.home() / '.cache')) / 'Olsyn' / 'OPAL'
    try:
        info = json.loads((root / 'drive-connection.json').read_text(encoding='utf-8'))
        mount = info['mount_path']
        if (info['server'].rstrip('/').lower() != server.rstrip('/').lower()
                or info['account_email'].lower() != account_email.lower()
                or not re.fullmatch(r'[D-Z]:\\', mount, re.I)):
            return ''
        return mount if Path(mount).is_dir() else ''
    except (OSError, ValueError, KeyError, TypeError):
        return ''
