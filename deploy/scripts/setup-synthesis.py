#!/usr/bin/env python3
"""Install the OPAL dispatcher boundary in the current Kubernetes context.

Copies the existing broker signing key and registry pull credentials directly
between Kubernetes API requests. No credentials are printed or written to disk.
Run before deploying the app. Generation remains disabled until model setup.
"""
import json
from pathlib import Path
import subprocess


def kubectl(*args, document=None):
    result = subprocess.run(['kubectl', *args], input=json.dumps(document) if document else None, text=True, capture_output=True, check=True)
    return result.stdout


def main():
    root = Path(__file__).resolve().parents[2]
    kubectl('apply', '-f', str(root / 'deploy/k8s/synthesis/namespace.yaml'))
    broker = json.loads(kubectl('-n', 'streaming', 'get', 'secret', 'olsyn-broker-secrets', '-o', 'json'))
    kubectl('apply', '-f', '-', document={'apiVersion': 'v1', 'kind': 'Secret', 'metadata': {'name': 'opal-synthesis-broker', 'namespace': 'opal'}, 'type': 'Opaque', 'data': {'jwt-secret': broker['data']['OLSYN_BROKER_JWT_SECRET']}})
    registry = json.loads(kubectl('-n', 'opal', 'get', 'secret', 'ghcr-secret', '-o', 'json'))
    kubectl('apply', '-f', '-', document={'apiVersion': 'v1', 'kind': 'Secret', 'metadata': {'name': 'ghcr-secret', 'namespace': 'opal-synthesis'}, 'type': registry['type'], 'data': registry['data']})
    deployment = json.loads(kubectl('-n', 'streaming', 'get', 'deployment', 'olsyn-broker', '-o', 'json'))
    container = deployment['spec']['template']['spec']['containers'][0]
    setting = 'OLSYN_BROKER_ALLOWED_CONSUMER_ISSUERS'
    current = next((item for item in container.get('env', []) if item['name'] == setting), None)
    if current and 'value' not in current:
        raise SystemExit('Broker issuer list comes from a secret/config map; update that source to include material-synthesis.')
    issuers = json.loads(current['value']) if current else ['kit-gateway', 'sam-3d', 'render-farm', 'olsyn-website']
    if 'material-synthesis' not in issuers:
        issuers.append('material-synthesis')
        kubectl('-n', 'streaming', 'set', 'env', 'deployment/olsyn-broker', setting + '=' + json.dumps(issuers))
    print('Installed worker namespace, dispatcher permissions, registry access and broker identity. Generation is still gated by OPAL_SYNTHESIS_ENABLED.')


if __name__ == '__main__':
    main()
