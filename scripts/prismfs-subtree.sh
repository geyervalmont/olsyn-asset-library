#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
remote="${PRISMFS_GIT_REMOTE:-prismfs}"
prefix="services/prismfs"
action="${1:-}"

cd "$repo_root"
git diff --quiet && git diff --cached --quiet || {
    printf 'prismfs-subtree: commit or stash monorepo changes first\n' >&2
    exit 1
}
git remote get-url "$remote" >/dev/null 2>&1 || {
    printf 'prismfs-subtree: git remote %s is not configured\n' "$remote" >&2
    printf 'add it with: git remote add %s https://github.com/geyervalmont/prismfs.git\n' "$remote" >&2
    exit 1
}

case "$action" in
    publish)
        split_commit="$(git subtree split --prefix "$prefix" main)"
        git push "$remote" "$split_commit:refs/heads/main"
        printf 'prismfs-subtree: published %s to %s/main\n' "$split_commit" "$remote"
        ;;
    pull)
        git subtree pull --prefix "$prefix" "$remote" main --squash
        ;;
    *)
        printf 'usage: %s publish|pull\n' "$0" >&2
        exit 2
        ;;
esac
