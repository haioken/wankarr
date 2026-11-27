#!/usr/bin/env bash
# Cut a release: bump VERSION, date the changelog, commit, and create an
# annotated tag vX.Y.Z. Nothing is pushed; the script prints the command.
#
#   scripts/release.sh 0.4.0        (or: make release V=0.4.0)
#   scripts/release.sh 0.4.0-rc.1   pre-releases don't move :latest
set -euo pipefail
cd "$(dirname "$0")/.."

die() { echo "release: $*" >&2; exit 1; }

new="${1:-}"
[[ $new =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]] || die "usage: release.sh X.Y.Z[-prerelease]"
[[ -z $(git status --porcelain) ]] || die "working tree has uncommitted changes"
branch=$(git symbolic-ref --short HEAD)
[[ $branch == main || -n ${ALLOW_BRANCH:-} ]] || die "releases are cut from main (set ALLOW_BRANCH=1 to override)"
if git rev-parse -q --verify "refs/tags/v$new" >/dev/null; then
  die "tag v$new already exists"
fi

current=$(cat VERSION)
if [[ $new != "$current" && $(printf '%s\n%s\n' "$current" "$new" | sort -V | tail -n1) != "$new" ]]; then
  die "v$new is older than the current version $current"
fi

echo "Running checks…"
scripts/lint.sh
php tests/run.php

echo "$new" > VERSION
if grep -q '^## \[Unreleased\]' CHANGELOG.md; then
  sed -i "s/^## \[Unreleased\]/## [Unreleased]\n\n## [$new] - $(date +%F)/" CHANGELOG.md
fi
notes=$(awk -v v="$new" '$0 ~ "^## \\[" v "\\]" {on=1; next} /^## \[/ {if (on) exit} on' CHANGELOG.md)

git add VERSION CHANGELOG.md
git diff --cached --quiet || git commit -q -m "Release v$new"
git tag -a "v$new" -m "wankarr v$new" -m "$notes"

echo "Tagged v$new. Publish it with:"
echo "  git push origin $branch --follow-tags"
