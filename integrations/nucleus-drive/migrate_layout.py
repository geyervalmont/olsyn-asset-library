"""Idempotently provision layout 2 and move the native upload directory intact.

Run inside the adapter SDK container before rolling out bridge 0.2.0. Credentials
come from its existing environment. No files are overwritten or re-uploaded.
"""
import json
import os
from pathlib import Path

from bridge import Native


def migrate(oc, host, administrator):
    native = Native(oc, host, administrator)
    base = 'omniverse://' + host + '/OPAL'

    def exists(url):
        result, entry = oc.stat(url)
        if result == oc.Result.ERROR_NOT_FOUND:
            return False
        native.checked(result)
        if not entry.flags & oc.ItemFlags.CAN_HAVE_CHILDREN or entry.flags & (oc.ItemFlags.IS_MOUNT | oc.ItemFlags.IS_INSIDE_MOUNT):
            raise ValueError('Expected a native folder: ' + url)
        return True

    def folder(url):
        if exists(url):
            return
        native.checked(oc.create_folder(url))
        native.checked(oc.set_acls(url, [oc.AclEntry('users', 0), oc.AclEntry('gm', 7), oc.AclEntry(administrator, 7)]))

    source, destination = base + '/upload', base + '/ingestion/upload'
    old, new = exists(source), exists(destination)
    if old and new:
        raise ValueError('Both upload roots exist; refusing to merge or replace content')
    folder(base + '/ingestion')
    if old:
        result, copied = oc.move(source, destination, oc.CopyBehavior.ERROR_IF_EXISTS)
        native.checked(result)
        if not exists(destination) or exists(source):
            raise RuntimeError('Upload move requires operator inspection')
        print(json.dumps({'source': source, 'destination': destination, 'state': 'moved', 'copied': copied}), flush=True)
    else:
        folder(destination)
    folder(base + '/ingestion/workspace')
    print(json.dumps({'layout': 2, 'upload': destination, 'workspace': base + '/ingestion/workspace'}), flush=True)


if __name__ == '__main__':
    import omni.client as oc
    config = json.loads(Path(os.environ['OPAL_NUCLEUS_LINKS']).read_text())
    administrator = os.environ['OLSYN_OMNI_USER']
    oc.initialize()
    auth = oc.register_authentication_callback(lambda url: (administrator, os.environ['OLSYN_OMNI_PASS']))
    try:
        migrate(oc, config['host'], administrator)
    finally:
        del auth
        oc.shutdown()
