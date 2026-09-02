.PHONY: bootstrap dev up down check prism-check prism-s3-test prism-run web-test

bootstrap:
	./scripts/bootstrap.sh

dev:
	gerry dev

up:
	./scripts/up.sh

down:
	./scripts/down.sh

check: prism-check web-test

prism-check:
	cd services/prismfs && cargo fmt --all --check
	cd services/prismfs && cargo clippy --workspace --all-targets --all-features -- -D warnings
	cd services/prismfs && cargo test --workspace --all-features

prism-run:
	cd services/prismfs && cargo run -p prismfs-server -- doctor

prism-s3-test:
	cd services/prismfs && set -a; . ./.env.example; set +a; AWS_ENDPOINT=https://s3.asset-library.test AWS_ALLOW_HTTP=false cargo test -p prismfs-storage --test s3_compat -- --ignored

web-test:
	docker compose -f apps/web/compose.yaml --project-directory apps/web exec -T laravel.test npm run build
	docker compose -f apps/web/compose.yaml --project-directory apps/web exec -T laravel.test composer test
