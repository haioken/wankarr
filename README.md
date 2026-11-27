# wankarr

*What are ya...*

A self-hosted manga library and reader built to sit alongside an *arr stack.
It scans your folders into series, matches them against MyAnimeList metadata
(via Tenrai), imports and renames volumes Sonarr-style, and reads them with a
two-page, page-curl reader. It's plain PHP (page scripts plus function
includes, no framework or MVC) and vanilla ES-module JavaScript, styled with
Sonarr's light and dark palettes.

> Replace `<owner>/<repo>` below with your GitHub repository, in lower case
> (for example `someone/wankarr`).

## Deploying with Docker

Images are published to the GitHub Container Registry:

| Tag | What it is |
| --- | --- |
| `ghcr.io/<owner>/<repo>:latest` | The newest stable release (never a pre-release or `main`) |
| `ghcr.io/<owner>/<repo>:0.3.0` | Exactly that release |
| `ghcr.io/<owner>/<repo>:0.3`, `:0` | The newest release in that line (gets patch updates) |
| `ghcr.io/<owner>/<repo>:dev` | The current `main` branch: newest, least tested |
| `ghcr.io/<owner>/<repo>:sha-1a2b3c4` | One exact `main` commit |

Images are built for `linux/amd64` and `linux/arm64`.

### Docker Compose (with MariaDB)

[`docker-compose.yml`](docker-compose.yml) runs wankarr with a MariaDB
container:

```yaml
services:
  wankarr:
    image: ghcr.io/<owner>/<repo>:latest
    restart: unless-stopped
    depends_on:
      db:
        condition: service_healthy
    ports:
      - "${WANKARR_PORT:-8484}:${WANKARR_PORT:-8484}"
    environment:
      WANKARR_PORT: ${WANKARR_PORT:-8484}
      PUID: 1000
      PGID: 1000
      TZ: Etc/UTC
      WANKARR_DB_DSN: mysql:host=db;dbname=wankarr;charset=utf8mb4
      WANKARR_DB_USER: wankarr
      WANKARR_DB_PASS: change-me
    volumes:
      - ./config:/config
      - /path/to/manga:/manga

  db:
    image: mariadb:11
    restart: unless-stopped
    environment:
      MARIADB_DATABASE: wankarr
      MARIADB_USER: wankarr
      MARIADB_PASSWORD: change-me
      MARIADB_RANDOM_ROOT_PASSWORD: "1"
    volumes:
      - ./db:/var/lib/mysql
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 10s
      timeout: 5s
      retries: 10
```

```sh
docker compose up -d
# open http://<host>:8484
```

To use another port, set `WANKARR_PORT` in a `.env` file next to
`docker-compose.yml` (for example `WANKARR_PORT=9494`) or in the shell.

To use an existing MariaDB server instead, drop the `db` service and point
`WANKARR_DB_DSN` at it.

### Docker run (SQLite, no database server)

Without `WANKARR_DB_DSN` the container uses SQLite at `/config/wankarr.db`:

```sh
docker run -d --name wankarr \
  -p 8484:8484 \
  -e PUID=1000 -e PGID=1000 -e TZ=Etc/UTC \
  -v /path/to/wankarr/config:/config \
  -v /path/to/manga:/manga \
  --restart unless-stopped \
  ghcr.io/<owner>/<repo>:latest
```

### Container settings

| Setting | Default | Purpose |
| --- | --- | --- |
| `WANKARR_PORT` | `8484` | Port the web UI listens on inside the container |
| Volume `/config` | | Database (SQLite), caches, logs, optional `config.php` |
| Volume `/manga` | | Your library. It needs to be writable for Import, Rename and Delete |
| `PUID` / `PGID` | `1000` | User/group wankarr runs as. Match the owner of your manga files |
| `TZ` | `UTC` | Time zone for logs and schedules |
| `WANKARR_DB_DSN` | `sqlite:/config/wankarr.db` | PDO DSN, e.g. `mysql:host=db;dbname=wankarr;charset=utf8mb4` |
| `WANKARR_DB_USER` / `WANKARR_DB_PASS` | | Database credentials |
| `WANKARR_LIBRARY` | `/manga` | Single library root inside the container |
| `WANKARR_LIBRARIES` | | Several libraries: `Manga=/manga;Comics=/comics` (mount each) |
| `WANKARR_WORKER` | `1` | Built-in task worker for scheduled scans and metadata. `0` disables it |
| `WANKARR_SCAN_INTERVAL` / `WANKARR_METADATA_INTERVAL` | `360` / `720` | Task intervals in minutes |
| `WANKARR_DIRECTION` | `rtl` | Default reading direction (`rtl` or `ltr`) |

Anything else from [`config.php`](config.php), such as the match threshold or
title language, can be overridden by a partial array in `/config/config.php`:

```php
<?php
return ['metadata' => ['title_language' => 'romaji', 'auto_match' => 0.9]];
```

The image runs a health check against `/api/health.php`. On start it migrates
the database schema, retrying for up to a minute while a database container
starts. Updating is the usual routine: `docker compose pull && docker compose up -d`.

Behind a reverse proxy, forward to `WANKARR_PORT` (8484). Imports upload in 8 MB chunks,
so the proxy's request size limit (nginx `client_max_body_size`) needs to be
at least 10M.

## Running without Docker

Requirements: PHP 8.2+ with `pdo_mysql` or `pdo_sqlite`, `zip`, `curl` and
`mbstring`; MariaDB/MySQL or SQLite; and for CBR/CB7/CBT, `bsdtar`
(libarchive), with `7z` or `unrar` as fallbacks.

```sh
# 1. Database (MariaDB)
sudo mariadb -e "CREATE DATABASE wankarr CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'wankarr'@'localhost' IDENTIFIED BY 'change-me';
  GRANT ALL ON wankarr.* TO 'wankarr'@'localhost';"

# 2. Local config (git-ignored): password, library path, …
cp config.local.php.example config.local.php

# 3. Run (the schema is created automatically)
make dev LIBRARY=/path/to/manga        # http://localhost:8484 (PORT=… to change)
```

For production, point nginx/Apache + PHP-FPM at `public/` as the document
root. `src/`, `bin/`, config and `data/` live outside the web root on purpose.

Scans and metadata refreshes run in a background CLI process. Page views start
overdue tasks automatically. For dependable scheduling, add a cron job or a
service:

```sh
*/5 * * * * php /path/to/wankarr/bin/wankarr tasks   # cron
php bin/wankarr worker                               # long-running
```

`bin/wankarr` also has `scan`, `run <task>`, `match <series-id>`, `status`,
`migrate` and `prune`. Run it with no arguments for help.

Configuration lives in `config.local.php` (merged over `config.php`) or in the
same environment variables as the container, with `WANKARR_DATA` (default
`./data`) as the writable cache/log directory.

## Using it

### Importing

**Import** uploads volumes from your device: CBZ, CBR, CB7 and CBT archives,
or folders of images (each folder is packed into a CBZ in the browser). Files
are grouped by series name and matched in this order:

1. an existing library series, by name, alternative titles or MyAnimeList id;
2. MyAnimeList (Tenrai) when the match is confident;
3. otherwise you choose. Search MyAnimeList, pick a library series, or import
   under a plain title.

You can fix volume and chapter numbers, untick files, and see each file's
destination before anything is copied. The import checks for collisions,
existing files and write permissions, then copies the files into the library
using the naming convention. Uploads go in 8 MB chunks, so PHP's upload size
limits don't apply.

### Library

- **Volumes:** an archive (`.cbz .zip .cbr .rar .cb7 .7z .cbt .tar`) or a
  folder that contains only images. The format is detected from the file's
  contents.
- **Series:** each top-level folder in a library is a series. Loose volumes at
  the library root are grouped by the series name parsed from the file name,
  so `Berserk v42.cbz` at the root joins the `Berserk/` folder.
- **Volume and chapter numbers** are parsed from names like `v01`, `Vol. 3`,
  `c012`, `Chapter 5`, `#64` and `Title 001`, and set the reading order.
- **Grouping fixes:** **merge** series and **move** or **split** volumes. These
  survive rescans.
- **Bulk actions:** **Select** series (shift-click for ranges) to **Rescan**,
  **Auto Rename** or **Delete** them. With the box unticked, Delete only hides
  series; ticked, it removes their files after checking permissions.
  **Hidden** lists hidden series.
- **Removed libraries** are hidden, not deleted, in case a path was mistyped.
  `bin/wankarr prune --yes` deletes them.

### Renaming

**Rename…** on a series page and **Auto Rename** in the library reorganise
files to the convention in **Settings → Media Management**. Renaming is manual
only. Scans and scheduled tasks never rename anything.

```
{Series Title} ({Series Year})/{Volume Name}/{Series Title} - {Volume Name}.cbz
[Oshi no Ko] (2020)/Volume 1/[Oshi no Ko] - Volume 1.cbz
[Oshi no Ko] (2020)/Volume 2/[Oshi no Ko] - Volume 2 - Page 1.png   (image volumes)
```

A preview shows the current and resulting folder trees and every change. Each
problem is listed with its reason: collisions, existing files, missing
permissions, or a different filesystem. The commit re-checks everything, only
runs if nothing changed since the preview, and rolls a volume back if any of
its steps fail.

### Metadata

New series are searched on [Tenrai](https://tenrai.org/), a free MyAnimeList
API compatible with Jikan v4, and matched by fuzzy title similarity across
English, romaji, Japanese and synonym titles. Confident matches apply
automatically. Others go to **Needs Review** for **Match…**. Stored metadata
covers titles, synopsis, dates, status, type, counts, MAL score/rank/popularity,
authors and artists with roles, serialization, genres, themes, demographic,
links and a cached cover. Content ratings come from MangaDex for the same
work, and are otherwise inferred from the genres.

### Search

One box suggests genres, themes, authors, statuses, types, content ratings,
years and series as you type, fuzzily ("shonen" finds *Shounen*, "fireen"
finds *Frieren*). Each pick becomes a pill. Pills combine with AND, except
that several statuses, types, ratings or years mean "any of these". Results
update as you type.

### Reader

Two-page spreads on wide screens and single pages on narrow ones,
right-to-left by default, with landscape images kept as two-page spreads. Drag
a page to curl it, flick it to turn, tap the edges, or use ←/→, Space,
PageUp/PageDown, Home/End or the mouse wheel. Progress is stored per device.

## Development

```sh
make            # list targets
make dev        # dev server on PORT (default 8484), LIBRARY=/path/to/manga
make check      # lint + unit tests
make docker     # build the image locally as wankarr:local
make docker-run # run it (LIBRARY=/path/to/manga)
make dist       # source tarball in dist/
```

GitHub Actions run:

- [`ci.yml`](.github/workflows/ci.yml): lint and tests on every push and pull
  request, plus a test image build for pull requests.
- [`docker.yml`](.github/workflows/docker.yml) publishes images:
  - pushes to `main` → `:dev` and `:sha-…`;
  - version tags → `:X.Y.Z`, plus `:X.Y`, `:X` and `:latest` when it's the
    newest stable release in that line (pre-releases get only their own tag);
  - **Actions → Container image → Run workflow** rebuilds a branch or commit
    as `dev` or a custom tag, or a release tag under the same rules.
- [`release.yml`](.github/workflows/release.yml) turns a version tag into a
  GitHub Release, with the changelog section as notes and the tarball
  attached.

Branching, releases, hotfixes, manual builds and first-time GHCR setup are
covered in [RELEASING.md](RELEASING.md). The short version:

```sh
# notes under "## [Unreleased]" in CHANGELOG.md, committed on main, then:
make release V=0.4.0
git push origin main --follow-tags
```

## Code map

```
public/index.php        library (series grid)       public/api/book.php        page list + dimensions
public/series.php       series details              public/api/page.php        page image
public/search.php       search                      public/api/cover.php       series/volume covers
public/import.php       import                      public/api/import.php      chunked upload, analyse, plan, commit
public/settings.php     naming conventions          public/api/rename.php      rename preview / commit
public/system.php       status, tasks, logs         public/api/series.php      match/rename/merge, bulk actions
public/read.php         reader                      public/api/books.php       move/split volume
bin/wankarr             CLI / task runner           public/api/tasks.php       task state / run now
src/db.php              PDO, helpers, migrations    src/scanner.php            scan + grouping
src/catalog.php         queries, search, grouping   src/metadata.php           Tenrai/MangaDex, matching
src/text.php            normalising, fuzzy, parser  src/tasks.php              scheduler, background runner
src/naming.php          templates and tokens        src/organize.php           rename plan/commit, delete
src/import.php          staging, matching, copying  src/archive.php            zip/dir/CLI archive access
public/assets/js/flipbook.js  page-curl engine      tests/run.php              unit tests
Dockerfile, docker/     container image             .github/workflows/         CI, images, releases
```

## License

wankarr is free software under the [GNU General Public License v3.0 or
later](LICENSE). The bundled [fflate](https://github.com/101arrowz/fflate)
library is MIT-licensed ([notice](public/assets/vendor/fflate.LICENSE)).
