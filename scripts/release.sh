#!/usr/bin/env bash
# Releases happen in two steps, because main only changes through pull requests:
#
#   make release V=0.4.0   1. From an up-to-date main: run the checks, create the
#                             branch release-v0.4.0 with VERSION and a dated
#                             changelog, push it, and print the pull-request link.
#                          2. Merge that pull request on GitHub once CI passes.
#   make publish V=0.4.0   3. Back on main: pull the merge, tag it v0.4.0 and push
#                             the tag. GitHub builds the release and the images.
#
# Pre-releases: V=1.0.0-rc.1 (doesn't move :latest).
# Fixes for an older line: run both steps from a release/X.Y branch instead of main.
set -euo pipefail
cd "$(dirname "$0")/.."

remote=${REMOTE:-origin}

die() { printf '\nrelease: %s\n' "$*" >&2; exit 1; }
say() { printf '\033[1m%s\033[0m\n' "$*"; }

usage() {
  cat >&2 <<'EOF'
usage: scripts/release.sh prepare X.Y.Z   (make release V=X.Y.Z)
       scripts/release.sh publish X.Y.Z   (make publish V=X.Y.Z)
EOF
  exit 1
}

check_version() {
  [[ ${1:-} =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]] || die "'${1:-}' isn't a version. Use X.Y.Z, e.g. 0.4.0 (or 1.0.0-rc.1 for a pre-release)."
}

# The branch a release is cut from: main, or release/X.Y for an older line.
base_branch() {
  local b
  b=$(git symbolic-ref --short -q HEAD) || die "you're not on a branch. Run: git switch main"
  [[ $b == main || $b == release/* ]] || die "you're on the branch '$b'. Releases are made from main. Run: git switch main"
  echo "$b"
}

require_clean() {
  [[ -z $(git status --porcelain) ]] || die "you have uncommitted changes (see: git status). Commit them on a branch, or set them aside with: git stash"
}

# Bring the local branch up to date with GitHub. Refuses if it has commits
# GitHub doesn't, since those could never be merged into a protected branch.
sync_branch() {
  local b=$1 extra
  git fetch -q --prune --tags "$remote" || die "couldn't reach '$remote' (check your network or SSH key)"
  git rev-parse -q --verify "refs/remotes/$remote/$b" >/dev/null || die "'$b' doesn't exist on GitHub yet"
  extra=$(git log --oneline "$remote/$b..$b")
  if [[ -n $extra ]]; then
    die "your local '$b' has commits that aren't on GitHub:
$extra

Changes reach '$b' through pull requests, so these can't be pushed directly.
- To keep them, move them to a branch and open a pull request:
    git branch my-changes && git reset --hard $remote/$b
    git push -u $remote my-changes
- To throw them away (e.g. a release attempt GitHub rejected):
    git reset --hard $remote/$b"
  fi
  git merge -q --ff-only "$remote/$b"
}

# https://github.com/owner/repo from the remote's URL (SSH or HTTPS).
repo_url() {
  local u
  u=$(git remote get-url "$remote")
  u=${u%.git}
  u=${u/#git@github.com:/https://github.com/}
  u=${u/#ssh:\/\/git@github.com\//https://github.com/}
  echo "$u"
}

# Changelog section for a version, without its heading.
notes_for() {
  awk -v v="$1" 'index($0, "## [" v "]") == 1 {on=1; next} /^## \[/ {if (on) exit} on' CHANGELOG.md
}

newest_tag() {
  git tag -l 'v[0-9]*.[0-9]*.[0-9]*' | sed 's/^v//' | sort -V | tail -n1
}

prepare() {
  local new=$1 base branch current newest unreleased url
  check_version "$new"
  base=$(base_branch)
  require_clean
  say "Updating $base from GitHub…"
  sync_branch "$base"

  current=$(cat VERSION)
  [[ $new != "$current" ]] || die "VERSION on $base is already $new. If its pull request is merged, run: make publish V=$new"
  [[ $(printf '%s\n%s\n' "$current" "$new" | sort -V | tail -n1) == "$new" ]] || die "$new is older than the current version $current"
  git rev-parse -q --verify "refs/tags/v$new" >/dev/null && die "the tag v$new already exists"
  newest=$(newest_tag)
  if [[ -n $newest && $(printf '%s\n%s\n' "$newest" "$new" | sort -V | tail -n1) != "$new" ]]; then
    [[ $base == release/* ]] || die "$new is older than the newest release v$newest"
  fi

  grep -q '^## \[Unreleased\]' CHANGELOG.md || die "CHANGELOG.md has no '## [Unreleased]' heading"
  unreleased=$(notes_for Unreleased)
  grep -q '^- ' <<<"$unreleased" || die "there's nothing under '## [Unreleased]' in CHANGELOG.md. Describe the changes there first (through a pull request)."

  branch="release-v$new"
  git rev-parse -q --verify "refs/heads/$branch" >/dev/null && die "the branch $branch already exists locally. Delete it with: git branch -D $branch"
  git ls-remote -q --exit-code --heads "$remote" "$branch" >/dev/null && die "the branch $branch already exists on GitHub. Merge or close its pull request, delete the branch there, and try again."

  say "Running checks…"
  scripts/lint.sh
  php tests/run.php

  say "Creating $branch…"
  git switch -q -c "$branch"
  echo "$new" > VERSION
  sed -i "s/^## \[Unreleased\]/## [Unreleased]\n\n## [$new] - $(date +%F)/" CHANGELOG.md
  git add VERSION CHANGELOG.md
  git commit -q -m "Release v$new"

  say "Pushing $branch to GitHub…"
  if ! git push -q -u "$remote" "$branch"; then
    git switch -q "$base"
    git branch -q -D "$branch"
    die "the push failed, so nothing changed. Fix the problem above and run this again."
  fi
  git switch -q "$base"

  url=$(repo_url)
  cat <<EOF

Done. Next:
  1. Open the pull request (title "Release v$new"):
       $url/compare/$base...$branch?expand=1
  2. Wait for the "Lint and test" check to go green, then merge it.
  3. Run: make publish V=$new
EOF
}

publish() {
  local new=$1 base tag notes newest url answer head
  check_version "$new"
  base=$(base_branch)
  require_clean
  say "Updating $base from GitHub…"
  sync_branch "$base"

  tag="v$new"
  [[ $(cat VERSION) == "$new" ]] || die "VERSION on $base is $(cat VERSION), not $new: the release pull request isn't merged yet. Merge it on GitHub, then run this again."
  if git ls-remote -q --exit-code --tags "$remote" "refs/tags/$tag" >/dev/null; then
    die "$tag is already published. The next release starts with: make release V=<next version>"
  fi
  if git rev-parse -q --verify "refs/tags/$tag" >/dev/null; then
    die "there's a local tag $tag that isn't on GitHub (from an earlier attempt). Remove it with: git tag -d $tag"
  fi
  newest=$(newest_tag)
  if [[ -n $newest && $base == main && $(printf '%s\n%s\n' "$newest" "$new" | sort -V | tail -n1) != "$new" ]]; then
    die "$new is older than the newest release v$newest"
  fi
  notes=$(notes_for "$new")
  [[ -n ${notes//[[:space:]]/} ]] || die "CHANGELOG.md has no notes for $new"

  head=$(git log -1 --format='%h %s')
  say "About to publish $tag from $base at $head with these notes:"
  printf '%s\n\n' "$notes"
  if [[ ${YES:-} != 1 ]]; then
    read -r -p "Publish $tag now? [y/N] " answer
    [[ $answer == [yY]* ]] || die "nothing published"
  fi

  git tag -a "$tag" --cleanup=verbatim -m "wankarr $tag" -m "$notes"
  say "Pushing $tag to GitHub…"
  if ! git push -q "$remote" "refs/tags/$tag"; then
    git tag -d "$tag" >/dev/null
    die "GitHub refused the tag, so nothing was published. If you set up a tag ruleset, it must allow creating v* tags."
  fi

  # The release branch has done its job.
  git branch -q -D "release-$tag" 2>/dev/null || true
  if git ls-remote -q --exit-code --heads "$remote" "release-$tag" >/dev/null; then
    git push -q "$remote" --delete "release-$tag" || true
  fi

  url=$(repo_url)
  cat <<EOF

Published $tag. GitHub is now building it (a few minutes):
  Progress: $url/actions
  Release:  $url/releases/tag/$tag
  Image:    ghcr.io/$(basename "$(dirname "$url")" | tr '[:upper:]' '[:lower:]')/$(basename "$url" | tr '[:upper:]' '[:lower:]'):$new
EOF
}

case ${1:-} in
  prepare) prepare "${2:-}" ;;
  publish) publish "${2:-}" ;;
  *) usage ;;
esac
