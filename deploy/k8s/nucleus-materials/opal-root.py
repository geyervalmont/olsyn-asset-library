"""Add the OPAL root without moving the legacy material mount.

Run with desktop/nucleus_drive available on PYTHONPATH. Credentials arrive only
on stdin. Existing paths are inspected, never overwritten or reconfigured.
"""
import json
import sys

from nucleus_drive.backend import RemoteError
from nucleus_drive.native import CAPABILITIES, NativeSession

CAPABILITIES.update(mount=0)
c = json.load(sys.stdin)
s = NativeSession('nucleus.olsyn.com')
s.login(c['username'], c['password'])


def exists(path):
    try:
        s.stat(path)
        return True
    except RemoteError as error:
        if error.status != 404:
            raise
        return False


def folder(path, acl):
    if exists(path):
        print(json.dumps({'path': path, 'state': 'already exists; unchanged'}))
        return
    s.mkdir(path)
    s.call('set_acl_v2', path_and_acls=[{'path_at_version': {'path': path + '/'}, 'acl': acl}])
    print(json.dumps({'path': path, 'state': 'created'}))


folder('/OPAL', {'gm': ['read', 'write', 'admin'], 'users': ['read']})
# This is a native, private directory. Never put intake files in the shared S3 mount.
folder('/OPAL/upload', {'gm': ['read', 'write', 'admin'], 'users': []})
if not exists('/OPAL/materials'):
    options = {'service': 's3', 'host': 'nucleus-materials.opal.svc.cluster.local:8080',
               'bucket': 'materials', 'region': 'ap-southeast-2', 'secure': False,
               'access_key_id': c['access'], 'secret_access_key': c['secret'], 'redirection': ''}
    s.call('mount', uri='/OPAL/materials', resolver='omniverse_resolver_s3', options=json.dumps(options))
    print(json.dumps({'path': '/OPAL/materials', 'state': 'mounted shared library'}))
else:
    print(json.dumps({'path': '/OPAL/materials', 'state': 'already exists; unchanged'}))
print(json.dumps({'path': '/OPAL', 'entries': [entry['name'] for entry in s.list('/OPAL')]}))
