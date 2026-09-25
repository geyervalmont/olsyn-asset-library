#!/usr/bin/env bash
set -euo pipefail
kubectl -n olsyn-nucleus-control get secret opal-nucleus-drive-links >/dev/null
kubectl -n olsyn-nucleus-control create configmap opal-nucleus-drive-adapter \
  --from-file=integrations/nucleus-drive/bridge.py --from-file=integrations/nucleus-drive/start.sh \
  --from-file=integrations/nucleus-drive/probe.py --dry-run=client -o yaml | kubectl apply -f -
kubectl apply -f deploy/k8s/nucleus-drive/resources.json
kubectl -n olsyn-nucleus-control rollout restart deployment/opal-nucleus-drive
kubectl -n olsyn-nucleus-control rollout status deployment/opal-nucleus-drive --timeout=300s
