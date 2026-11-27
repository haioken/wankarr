# Maintaining wankarr: changes, releases and images

Replace `<owner>/<repo>` with the GitHub repository (images use it in lower
case: `ghcr.io/<owner>/<repo>`).

`main` is protected: nothing is pushed to it directly. Every change, a release
included, goes in through a pull request. The commands below are all you need;
none of them rewrite anything that is already on GitHub.

## How it fits together

| You do | GitHub Actions does |
| --- | --- |
| Push a branch and open a pull request | **CI**: lint, unit tests and a test image build. Merging is blocked until it passes |
| Merge a pull request into `main` | **Container image**: builds `:dev` and `:sha-<commit>` |
| `make release V=X.Y.Z`, merge its pull request, `make publish V=X.Y.Z` | **Release**: GitHub Release with notes and tarball. **Container image**: `:X.Y.Z` (plus `:X.Y`, `:X`, `:latest` when it's the newest) |
| Nothing (weekly) | **Container image**: rebuilds the newest release on a fresh base image |
| Merge Dependabot pull requests | Keeps the GitHub Actions and the base image current |

Nothing has to be built or uploaded by hand.

### Words used here

- **Branch**: a named line of work. `main` is the released one; you make
  changes on a short-lived branch of your own and merge it into `main`.
- **Pull request (PR)**: a request on GitHub to merge a branch into `main`. CI
  runs on it, and you merge it with the green **Merge** button.
- **Tag**: a permanent name for one commit, like `v0.4.0`. Pushing a version
  tag is what publishes a release.
- **`origin`**: your GitHub repository, as your local copy calls it.

## First-time setup

Done once, in the repository on GitHub.

1. **Protect `main`**: **Settings → Rules → Rulesets → New branch ruleset**
   (or **Settings → Branches → Add rule**), target `main`:
   - **Require a pull request before merging**. Set the required approvals to
     **0** if you work alone, since GitHub won't let you approve your own PR;
   - **Require status checks to pass**, add **Lint and test**;
   - **Block force pushes**.
2. **Tidy up merged branches**: **Settings → General → Pull Requests**, tick
   **Automatically delete head branches**.
3. **Protect release tags** (recommended): **Settings → Rules → Rulesets → New
   tag ruleset**, target `v*`, and tick **Restrict deletions** and **Block force
   pushes**. Leave **Restrict creations** off, or `make publish` can't push
   tags.
4. **Make the image public** after the first image is built (any merge into
   `main` builds `:dev`): your profile → **Packages** → `<repo>` → **Package
   settings** → **Change visibility** → **Public**. Until then, `docker pull`
   needs `docker login ghcr.io`. The package is linked to the repository
   through the image's `org.opencontainers.image.source` label, and the
   workflows push with the built-in `GITHUB_TOKEN`. No personal token or secret
   is needed.
5. **Actions permissions**: the workflows declare what they need (`contents`,
   `packages`). If **Settings → Actions → General** restricts Actions, allow
   GitHub-authored and verified actions (`actions/*`, `docker/*`,
   `shivammathur/setup-php`).

## Making a change

Every change follows the same loop. `fix/cbr-detection` is an example branch
name; use anything short, such as `feature/…` or `fix/…`.

```sh
# 1. Start from the latest main
git switch main
git pull

# 2. Make a branch for the change
git switch -c fix/cbr-detection

# 3. Edit files, then add a line to CHANGELOG.md under "## [Unreleased]"
#    (in the right section: ### Added, ### Changed, ### Fixed or ### Removed)
make check                      # lint + tests

# 4. Commit and push the branch
git add -A
git commit -m "Fix CBR detection for RAR5 archives"
git push -u origin fix/cbr-detection
```

The push prints a link; open it (or go to the repository, where GitHub offers
**Compare & pull request**) and click **Create pull request**. When **Lint
and test** is green, click **Merge** (squash is fine) and then bring your copy
up to date:

```sh
git switch main
git pull
git branch -D fix/cbr-detection # the local copy of the merged branch
```

More commits on the branch before merging? Commit and `git push` again; the
pull request updates itself.

**Dependabot** pull requests are merged the same way, on GitHub. Afterwards,
`git switch main && git pull`. A base-image bump (`alpine`) can change the
packaged PHP; see [Housekeeping](#housekeeping).

**Editing on github.com**: when you save an edit, GitHub can't commit it to
`main`, so it offers **Create a new branch for this commit and start a pull
request**. Choose that and merge the PR as above.

## Making a release

1. **Choose the version.** Versions follow [Semantic
   Versioning](https://semver.org), `MAJOR.MINOR.PATCH`, and must go up:
   - *Patch* (`0.4.1`): fixes only.
   - *Minor* (`0.5.0`): new features. Database migrations run automatically on
     start, so they don't need anything extra.
   - *Major* (`1.0.0`): anything that needs action from users, such as a
     renamed environment variable, a changed volume layout, or a dropped
     database driver. Spell those steps out in the changelog.
   - *Pre-release* (`1.0.0-rc.1`): see [below](#pre-releases).

   Below 1.0 the rules are looser: a minor bump may contain changes like that,
   as long as the changelog says so.

2. **Check the notes.** Everything under `## [Unreleased]` in `CHANGELOG.md`
   becomes the release notes. Fix them through a normal pull request first if
   needed.

3. **Start the release**:

   ```sh
   git switch main
   make release V=0.5.0
   ```

   It updates `main` from GitHub, runs lint and tests, creates the branch
   `release-v0.5.0` with the new `VERSION` and the changelog section dated
   (`## [0.5.0] - <today>`), pushes it, and prints the pull-request link.
   It stops with an explanation if something isn't right: uncommitted changes,
   local commits that aren't on GitHub, a version that isn't newer, or an
   empty Unreleased section.

4. **Merge the pull request.** Open the printed link, click **Create pull
   request**, wait for **Lint and test** to go green, and merge it.

5. **Publish**:

   ```sh
   make publish V=0.5.0
   ```

   It pulls the merge, shows the notes, and asks for confirmation. Then it tags
   the merged commit `v0.5.0`, pushes the tag, and deletes the release branch.
   Pushing the tag is what publishes the release.

6. **Check it.** The **Actions** tab shows **Release** and **Container image**
   running (a few minutes; the arm64 build is the slow part). Then:

   ```sh
   docker pull ghcr.io/<owner>/<repo>:0.5.0
   docker run --rm -p 8484:8484 ghcr.io/<owner>/<repo>:0.5.0 &
   curl -s localhost:8484/api/health.php     # {"status":"ok","version":"0.5.0"}
   ```

Changed your mind between steps 3 and 5? Close the pull request on GitHub
without merging, delete its branch there, and run `git branch -D
release-v0.5.0`. Nothing was published.

### Pre-releases

`make release V=1.0.0-rc.1`, then `make publish V=1.0.0-rc.1`, work the same
way. The GitHub Release is marked pre-release, and only `:1.0.0-rc.1` is
published. `:latest` and the `:1`/`:1.0` tags don't move. The final release
is then `1.0.0`.

### Patching an older release

Only needed when, say, `0.5.0` is out and `0.4` users need a fix without
upgrading. The older line gets its own long-lived branch, `release/0.4`, which
takes the place of `main` in both steps:

```sh
git fetch --tags
git switch -c release/0.4 v0.4.1          # newest tag in that line
git push -u origin release/0.4            # first time only
git switch -c fix/old-thing
git cherry-pick <fix-commit>              # copy the fix from main, or write it here
# add the fix under "## [Unreleased]" in CHANGELOG.md, commit
git push -u origin fix/old-thing          # open the PR against release/0.4, merge it

git switch release/0.4 && git pull
make release V=0.4.2                      # its pull request targets release/0.4
make publish V=0.4.2                      # after merging it
```

`:0.4.2` and `:0.4` update. `:0` and `:latest` stay on 0.5.0, and the GitHub
Release isn't marked "Latest". Copy the 0.4.2 changelog section to `main`
through a pull request so the history stays complete there.

## Image tags

| Tag | Moves when |
| --- | --- |
| `:latest` | A stable release is published that is the newest of all |
| `:X` / `:X.Y` | A stable release is the newest in that major / minor line |
| `:X.Y.Z` | Never, apart from rebuilds of that same release (weekly or manual) |
| `:X.Y.Z-rc.N` | Never, apart from rebuilds |
| `:dev`, `:sha-…` | On every merge into `main` |
| custom | When you run a manual build with that tag |

Images are built for `linux/amd64` and `linux/arm64`.

## Manual builds

**Actions → Container image → Run workflow**, then fill in:

| Input | Meaning |
| --- | --- |
| `ref` | Branch, tag or commit to build |
| `channel` | `dev` (`:dev`, `:sha-…`), `release` (`ref` must be a `vX.Y.Z` tag; same tag rules as a push), or `custom` |
| `tag` | Image tag for `custom`: letters, digits, `.`, `_`, `-` |

Examples: `ref` = `fix/x`, `channel` = `custom`, `tag` = `try-x` builds a
branch as `:try-x` to test before merging; `ref` = `v0.5.0`, `channel` =
`release` rebuilds a release now. A release rebuild keeps the app version the
same and only refreshes the base image. It's useful after a PHP or Alpine
security fix, if the weekly rebuild is too slow.

With the [GitHub CLI](https://cli.github.com):
`gh workflow run docker.yml -f ref=fix/x -f channel=custom -f tag=try-x`.

## Pulling a bad release

Prefer shipping a fixed patch release quickly. If a release really has to go:

1. Delete the GitHub Release (**Releases** → the release → **Delete**).
2. Delete the tag on GitHub and locally (temporarily allow deletion in the tag
   ruleset if you set one up):

   ```sh
   git push origin --delete v0.5.0
   git tag -d v0.5.0
   ```

3. Delete the package version on GHCR (**Packages** → `<repo>` → **Package
   settings** → **Manage versions**).
4. Point the floating tags back at the previous release: a [manual
   build](#manual-builds) with `ref` = `v0.4.2`, `channel` = `release`. It is
   now the newest tag, so `:latest`, `:0` and `:0.4` follow it.

`VERSION` on `main` still says `0.5.0`, so the fix goes out as `0.5.1`.

## Housekeeping

- **Dependabot** opens weekly PRs for GitHub Actions versions and the
  `alpine` base image. Merge them once CI passes; base-image bumps then ship
  with the next release. A new Alpine release can drop the packaged PHP
  version (`php84` in the Dockerfile). Before merging one, check that its
  package list still has `php84-fpm`, or run `make docker` and try the
  container.
- **The weekly rebuild** re-runs `apk add` against the same Alpine release,
  which picks up its security updates (PHP, nginx, OpenSSL, libarchive).
- **Old `:sha-…` images** pile up on GHCR. Delete them now and then under
  **Manage versions**, or add a cleanup action if it becomes a chore.
- **Check before releasing**: `make check`, and `make docker && make
  docker-run LIBRARY=/path/to/manga` for anything that touches the container.

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| `git push`: *protected branch* / *Changes must be made through a pull request* | You committed on `main`. Move the commit to a branch: `git branch my-change && git reset --hard origin/main`, then `git switch my-change && git push -u origin my-change` and open a PR |
| `release: your local 'main' has commits that aren't on GitHub` | Same cause; the message lists the commits and both ways out |
| `release: you have uncommitted changes` | Commit them on a branch, or `git stash` to set them aside (`git stash pop` brings them back) |
| `release: … the release pull request isn't merged yet` | Merge it on GitHub first, then run `make publish` again |
| `release: there's a local tag vX.Y.Z that isn't on GitHub` | Left over from a failed attempt: `git tag -d vX.Y.Z` |
| `release: the branch release-vX.Y.Z already exists` | An earlier attempt. Unmerged? Merge its PR and `make publish`. Abandoned? Delete it (`git branch -D …` locally, the branch page on GitHub) |
| `git pull`: *Need to specify how to reconcile divergent branches* | Your `main` has commits GitHub doesn't; see the first row |
| Release workflow: *VERSION does not match tag* | The tag was made by hand. Delete it (see [Pulling a bad release](#pulling-a-bad-release)) and use `make release` / `make publish` |
| Image push: `denied: permission_denied` | The package was created outside this repository's workflow. In **Package settings → Manage Actions access**, give this repository the **Write** role |
| `docker pull` → `unauthorized` | The package is still private; see [first-time setup](#first-time-setup) |
| Manual release build: *is not a release tag* | `ref` must be a `vX.Y.Z` tag, or the exact commit one points at |
