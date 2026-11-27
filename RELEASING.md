# Maintaining wankarr: branches, releases and images

Replace `<owner>/<repo>` with the GitHub repository (images use it in lower
case: `ghcr.io/<owner>/<repo>`).

## How it fits together

| You do | GitHub Actions does |
| --- | --- |
| Push or open a PR | **CI**: lint and unit tests. PRs also get a test image build |
| Merge to `main` | **Container image**: builds `:dev` and `:sha-<commit>` |
| `make release V=X.Y.Z` and push the tag | **Release**: GitHub Release with notes and tarball. **Container image**: `:X.Y.Z` (plus `:X.Y`, `:X`, `:latest` when it's the newest) |
| Nothing (weekly) | **Container image**: rebuilds the newest release on a fresh base image |
| Merge Dependabot PRs | Keeps the GitHub Actions and the base image current |

Nothing has to be built or uploaded by hand. Images appear on GHCR on their
own; see [first-time setup](#first-time-setup) for the one manual step
(visibility).

- **Versions** follow [Semantic Versioning](https://semver.org). `VERSION`
  holds the current release; dev images report `<version>-dev.<commit>`.
- **Notes** live in `CHANGELOG.md`. Every change adds a line under
  `## [Unreleased]`, and releasing turns that section into the release notes.
- **Tags** look like `vX.Y.Z`, or `vX.Y.Z-rc.N` for a pre-release. Only
  `scripts/release.sh` (`make release`) creates them.

## First-time setup

1. **Push the repository**

   ```sh
   git remote add origin git@github.com:<owner>/<repo>.git
   git push -u origin main --follow-tags
   ```

   The push runs CI and builds `:dev`. Pushing the `v0.3.0` tag also creates
   the first GitHub Release and the `:0.3.0`, `:0.3`, `:0` and `:latest`
   images. Watch the **Actions** tab.

2. **Make the image public.** GHCR creates the package as *private* on its
   first push. Open your profile (or organisation) → **Packages** → `<repo>` →
   **Package settings** → **Change visibility** → **Public**. While it's
   private, `docker pull` needs `docker login ghcr.io`.
   The package is linked to the repository through the image's
   `org.opencontainers.image.source` label. It shows up in the repository
   sidebar, and the workflow's built-in `GITHUB_TOKEN` can push to it. No
   personal token or secret is needed.

3. **Protect `main`** (recommended): **Settings → Branches → Add rule** (or
   **Rules → Rulesets**) for `main`:
   - Require status checks: **Lint and test**;
   - Require a pull request before merging (optional when working alone);
   - Block force pushes.

4. **Protect release tags**: **Settings → Rules → Rulesets → New tag
   ruleset**, target `v*`, and block deletion and force-pushes, so a
   published release can't be rewritten by accident.

5. **Actions permissions**: the workflows declare what they need
   (`contents`, `packages`). If **Settings → Actions → General** restricts
   Actions, allow GitHub-authored and verified actions (`actions/*`, `docker/*`,
   `shivammathur/setup-php`).

## Branching

- **`main` must always be releasable.** Every merge builds `:dev`, so `main`
  is what early adopters run.
- **Feature and fix branches**: branch from `main` (`feature/import-queue`,
  `fix/cbr-detection`), open a pull request, and let CI pass. Add a
  `CHANGELOG.md` line under `## [Unreleased]` (sections `### Added`,
  `### Changed`, `### Fixed`, `### Removed`), then merge (squash is fine).
- **Release branches only when patching an older line.** Everything is
  released from `main` until you need to fix `0.3` after `0.4` is out. Then you
  create `release/0.3` from the last `v0.3.x` tag (see [Patching an older
  release](#patching-an-older-release)).

To try a branch as a container before merging, use a
[manual build](#manual-builds) with a custom tag.

## Making a release

1. **Choose the version.**
   - *Patch* (`0.3.1`): fixes only.
   - *Minor* (`0.4.0`): new features. Database migrations run automatically on
     start, so they don't need anything extra.
   - *Major* (`1.0.0`): anything that needs action from users, such as a
     renamed environment variable, a changed volume layout, or a dropped
     database driver. Spell those steps out in the changelog.
2. **Release from an up-to-date `main`**:

   ```sh
   git switch main && git pull
   make release V=0.4.0
   ```

   The script refuses if the tree is dirty, you're not on `main`, the tag
   exists, or the version goes backwards. It runs lint and tests, writes
   `VERSION`, dates the changelog section, commits `Release v0.4.0`, and
   creates an annotated tag `v0.4.0` whose message holds the notes.
3. **Check it** with `git show v0.4.0`. To change something before pushing,
   undo with `git tag -d v0.4.0 && git reset --hard HEAD~1`.
4. **Publish**:

   ```sh
   git push origin main --follow-tags
   ```

5. **Verify** in the **Actions** tab that **Release** and **Container image**
   passed, then:

   ```sh
   docker pull ghcr.io/<owner>/<repo>:0.4.0
   docker run --rm -p 8484:8484 ghcr.io/<owner>/<repo>:0.4.0 &
   curl -s localhost:8484/api/health.php     # {"status":"ok","version":"0.4.0"}
   ```

### Pre-releases

`make release V=1.0.0-rc.1` works the same way. The GitHub Release is marked
pre-release, and only `:1.0.0-rc.1` is published. `:latest` and the
`:1`/`:1.0` tags don't move.

### Patching an older release

When `0.4.0` is out but `0.3` users need a fix:

```sh
git fetch --tags
git switch -c release/0.3 v0.3.1        # newest tag in that line
git cherry-pick <fix-commit>            # or fix it here and port to main
# add the fix under "## [Unreleased]" in CHANGELOG.md, commit
ALLOW_BRANCH=1 make release V=0.3.2
git push origin release/0.3 --follow-tags
```

`:0.3.2` and `:0.3` update. `:0` and `:latest` stay on 0.4.0, and the GitHub
Release isn't marked "Latest". Copy the 0.3.2 changelog section to `main` so
the history stays complete there.

## Image tags

| Tag | Moves when |
| --- | --- |
| `:latest` | A stable release is published that is the newest of all |
| `:X` / `:X.Y` | A stable release is the newest in that major / minor line |
| `:X.Y.Z` | Never, apart from rebuilds of that same release (weekly or manual) |
| `:X.Y.Z-rc.N` | Never, apart from rebuilds |
| `:dev`, `:sha-…` | On every push to `main` |
| custom | When you run a manual build with that tag |

Images are built for `linux/amd64` and `linux/arm64`.

## Manual builds

**Actions → Container image → Run workflow**, or with the GitHub CLI:

```sh
gh workflow run docker.yml -f ref=feature/x -f channel=custom -f tag=try-x   # test a branch → :try-x
gh workflow run docker.yml -f ref=main -f channel=dev                        # rebuild :dev
gh workflow run docker.yml -f ref=v0.4.0 -f channel=release                  # rebuild a release now
```

| Input | Meaning |
| --- | --- |
| `ref` | Branch, tag or commit to build |
| `channel` | `dev` (`:dev`, `:sha-…`), `release` (`ref` must be a `vX.Y.Z` tag; same tag rules as a push), or `custom` |
| `tag` | Image tag for `custom`: letters, digits, `.`, `_`, `-` |

A release rebuild keeps the app version the same and only refreshes the base
image. It's useful after a PHP or Debian security fix, if the weekly rebuild
is too slow.

## Pulling a bad release

Prefer shipping a fixed patch release quickly. If a release really has to go:

1. Delete the GitHub Release (**Releases** → the release → **Delete**).
2. Delete the tag on GitHub and locally (temporarily allow it in the tag
   ruleset if you set one up):

   ```sh
   git push origin --delete v0.4.0 && git tag -d v0.4.0
   ```

3. Delete the package version on GHCR (**Packages** → `<repo>` → **Package
   settings** → **Manage versions**).
4. Point the floating tags back at the previous release:
   `gh workflow run docker.yml -f ref=v0.3.2 -f channel=release`. It is now
   the newest tag, so `:latest`, `:0` and `:0.3` follow it.

## Housekeeping

- **Dependabot** opens weekly PRs for GitHub Actions versions and the
  `alpine` base image. Merge them once CI passes, and include base-image bumps
  in the next release. Moving to a new Alpine release can change the packaged
  PHP version (`php84` in the Dockerfile), so check `make docker` and the
  container before merging.
- **The weekly rebuild** re-runs `apk add` against the same Alpine release,
  which picks up its security updates (PHP, nginx, OpenSSL, libarchive).
- **Old `:sha-…` images** pile up on GHCR. Delete them now and then under
  **Manage versions**, or add a cleanup action if it becomes a chore.
- **Check before releasing**: `make check`, and `make docker && make
  docker-run LIBRARY=/path/to/manga` for anything that touches the container.

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| Release workflow: *VERSION does not match tag* | The tag was made by hand. Delete it and use `make release`, which keeps them in sync |
| Image push: `denied: permission_denied` | The package was created outside this repository's workflow. In **Package settings → Manage Actions access**, give this repository the **Write** role |
| `docker pull` → `unauthorized` | The package is still private; see [first-time setup](#first-time-setup) |
| `release: working tree has uncommitted changes` | Commit or stash first |
| `release: releases are cut from main` | Switch to `main`, or use `ALLOW_BRANCH=1` on a `release/X.Y` branch |
| Manual release build: *is not a release tag* | `ref` must be a `vX.Y.Z` tag, or the exact commit one points at |
