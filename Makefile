.PHONY: bootstrap dev up down check hmr-check prism-check prism-e2e prism-s3-test prism-run prism-publish prism-update web-test

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

prism-publish:
	./scripts/prismfs-subtree.sh publish

prism-update:
	./scripts/prismfs-subtree.sh pull

web-test:
	docker compose -f apps/web/compose.yaml --project-directory apps/web exec -T laravel.test bun run build
	docker compose -f apps/web/compose.yaml --project-directory apps/web exec -T laravel.test composer test
