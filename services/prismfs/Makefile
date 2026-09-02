SHELL := /usr/bin/env bash
.DEFAULT_GOAL := help

.PHONY: help check doctor samba-config mount dev-up dev-down dev-logs dev-status endpoints e2e s3-test

help:
	@printf '%s\n' \
		'make check         Format, lint, and run every Rust test' \
		'make doctor        Validate the development manifest' \
		'make mount         Mount PrismFS natively with an isolated RustFS backend' \
		'make dev-up        Start isolated RustFS + PrismFS + Samba containers' \
		'make endpoints     Print this checkout’s dynamically assigned endpoints' \
		'make dev-logs      Follow the PrismFS and Samba logs' \
		'make dev-down      Stop the standalone stack and delete fixture data' \
		'make e2e           Prove S3 -> FUSE -> POSIX and SMB, then clean up' \
		'make s3-test       Run the object-store compatibility test against RustFS'

check:
	cargo fmt --all --check
	cargo clippy --workspace --all-targets --all-features -- -D warnings
	cargo test --workspace --all-features

doctor:
	cargo run --locked -p prismfs-server --bin prismfs -- doctor

samba-config:
	cargo run --locked -p prismfs-server --bin prismfs -- samba-config --mountpoint "$$(pwd)/mnt"

mount:
	./dev/mount.sh

dev-up:
	./dev/compose.sh up --build --detach --wait
	@./dev/endpoints.sh

dev-down:
	./dev/compose.sh down --volumes --remove-orphans

dev-logs:
	./dev/compose.sh logs --follow prismfs-smb

dev-status:
	./dev/compose.sh ps

endpoints:
	@./dev/endpoints.sh

e2e:
	./dev/e2e.sh

s3-test:
	./dev/s3-test.sh
