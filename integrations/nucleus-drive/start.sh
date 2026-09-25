#!/bin/sh
set -eu
for kit_path in /work/packman/chk/kit-kernel/*; do break; done
export PYTHONPATH="$kit_path/extscore/omni.client.lib:/adapter"
export LD_LIBRARY_PATH="$kit_path/extscore/omni.client.lib/bin:$kit_path"
exec "$kit_path/python/bin/python3.12" /adapter/bridge.py
