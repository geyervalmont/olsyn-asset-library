"""Link one operator-confirmed Nucleus username through OPAL browser sign-in.

Writes a private enrollment file; never prints the bearer token. Copy the file
into the nucleus-drive-links Kubernetes Secret after confirming the Nucleus user.
"""
import argparse
import json
import os
from pathlib import Path
import time
from urllib.parse import quote
import uuid

from bridge import Api, VERSION, portable

parser = argparse.ArgumentParser()
parser.add_argument('--origin', default='https://opal.olsyn.com')
parser.add_argument('--username', required=True)
parser.add_argument('--output', type=Path, required=True)
args = parser.parse_args()
portable(args.username)
if '/' in args.username or args.username in ('users', 'gm', 'omniverse') or args.output.exists():
    raise SystemExit('Use a personal Nucleus username and a new private output file.')
api = Api(args.origin)
link = api.json('POST', '/api/v1/link', {'client': 'nucleus-drive', 'machine': 'Nucleus', 'app_version': VERSION})
print('Approve OPAL sign-in for Nucleus user ' + args.username + ': ' + link['verify_url'], flush=True)
deadline = time.monotonic() + 600
while time.monotonic() < deadline:
    time.sleep(max(2, link['poll_interval']))
    claimed = api.json('GET', '/api/v1/link/' + quote(link['code'], safe='') + '?secret=' + quote(link['secret'], safe=''))
    if claimed['status'] == 'claimed':
        api.token = claimed['token']
        bootstrap = api.bootstrap()
        if not bootstrap['capabilities']['intake']:
            raise SystemExit('This account does not have material contribution permission.')
        enrollment = {'username': args.username, 'account_id': bootstrap['account']['id'],
                      'device_id': str(uuid.uuid4()), 'token': claimed['token']}
        with os.fdopen(os.open(args.output, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600), 'w') as target:
            json.dump(enrollment, target)
        print('Enrollment saved privately. The operator must verify this Nucleus username before installing it.')
        break
    if claimed['status'] != 'pending':
        raise SystemExit('This sign-in has already been delivered. Start a new link.')
else:
    raise SystemExit('Sign-in expired. Start a new link.')
