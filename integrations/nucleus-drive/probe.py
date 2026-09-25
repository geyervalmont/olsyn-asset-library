import json
import os
from pathlib import Path
import sys
import time

health = json.loads((Path(os.environ.get('OPAL_NUCLEUS_STATE', '/state')) / 'health.json').read_text())
assert time.time() - health['time'] < (600 if '--live' in sys.argv else 120)
if '--live' not in sys.argv:
    assert all(link['state'] == 'ready' for link in health['links'])
