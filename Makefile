.PHONY: bootstrap dev up down check hmr-check prism-check prism-e2e prism-s3-test prism-run prism-publish prism-update prism-drive-up prism-drive-down prism-drive-check vm-bridge-up vm-bridge-down vm-bridge-ca revit-sync web-test web-seed web-shell

bootstrap:
	./scripts/bootstrap.sh

dev:
	gerry dev

up:
	./scripts/up.sh

down:
	./scripts/down.sh

check: prism-check web-test

hmr-check:
	@./scripts/check-hmr.sh

prism-check:
	cd services/prismfs && cargo fmt --all --check
	cd services/prismfs && cargo clippy --workspace --all-targets --all-features -- -D warnings
	cd services/prismfs && cargo test --workspace --all-features

prism-run:
	$(MAKE) -C services/prismfs doctor

prism-e2e:
	$(MAKE) -C services/prismfs e2e

prism-s3-test:
	cd services/prismfs && set -a; . ./.env.example; set +a; AWS_ENDPOINT=https://s3.asset-library.test AWS_ALLOW_HTTP=false cargo test -p prismfs-storage --test s3_compat -- --ignored

prism-drive-up:
	$(MAKE) -C services/prismfs drive-up

prism-drive-down:
	$(MAKE) -C services/prismfs drive-down

prism-drive-check:
	$(MAKE) -C services/prismfs drive-check

# Expose the dev control plane to the Windows VM on the libvirt bridge (https edge).
vm-bridge-up:
	docker compose -p olsyn-vm-bridge -f infrastructure/local/vm-bridge/compose.yaml up -d

vm-bridge-down:
	docker compose -p olsyn-vm-bridge -f infrastructure/local/vm-bridge/compose.yaml down

# Print the edge's CA root certificate (install it in the VM's Trusted Root store).
vm-bridge-ca:
	docker compose -p olsyn-vm-bridge -f infrastructure/local/vm-bridge/compose.yaml exec -T edge cat /data/caddy/pki/authorities/local/root.crt

prism-publish:
	./scripts/prismfs-subtree.sh publish

prism-update:
	./scripts/prismfs-subtree.sh pull

web-test:
	docker compose -f apps/web/compose.yaml --project-directory apps/web exec -T laravel.test bun run build
	docker compose -f apps/web/compose.yaml --project-directory apps/web exec -T laravel.test composer test

web-seed:
	docker compose -f apps/web/compose.yaml --project-directory apps/web exec -T laravel.test php artisan migrate --force --seed

web-shell:
	docker compose -f apps/web/compose.yaml --project-directory apps/web exec laravel.test bash

# Push the pyRevit extension into the Windows VM over SSH (fallback when the
# virtiofs share is not mounted in the guest). Then Reload in pyRevit.
REVIT_VM ?= harrison@192.168.122.82
revit-sync:
	cd integrations/revit && tar czf - pyrevit/OPAL.extension | ssh $(REVIT_VM) 'powershell -NoProfile -Command "New-Item -ItemType Directory -Force -Path opal | Out-Null; tar -xzf - -C opal; Write-Host synced"'
