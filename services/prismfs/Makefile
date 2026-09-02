.PHONY: check doctor s3-test

check:
	cargo fmt --all --check
	cargo clippy --workspace --all-targets --all-features -- -D warnings
	cargo test --workspace --all-features

doctor:
	cargo run -p prismfs-server -- doctor

s3-test:
	set -a; . ./.env; set +a; cargo test -p prismfs-storage --test s3_compat -- --ignored
