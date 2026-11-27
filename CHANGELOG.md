# Changelog

All notable changes are listed here. Versions follow [Semantic Versioning](https://semver.org);
`make release` turns the Unreleased section into the next version's notes.

## [Unreleased]

## [0.4.0] - 2026-11-27

### Added
- Accounts: first start asks for an admin; database-backed users with Argon2id passwords, hashed session tokens, sign-in throttling, account page (email, password, sessions) and `bin/wankarr user …` commands.
- Two roles: admins manage library, metadata, files, settings and users; users read, search and hide series.
- Per-user reading progress, reader preferences and hidden series (progress from older browser storage is imported on first sign-in).
- Per-user content-rating limit (and whether unrated series show), enforced in listings, search, pages, covers and images.
- Optional self sign-up (`WANKARR_SIGNUP`, off by default) with email confirmation over SMTP, or admin approval when email isn't configured; password reset by email.

### Changed
- Hiding a series is now per user; Delete always removes files and is admin-only.
- Releases go through a pull request (`make release`, then `make publish` to tag it), so `main` can stay protected.

## [0.3.0] - 2026-11-27

### Added
- Reader: two-page spreads with a page-curl turn, right-to-left by default, wide-page spreads, touch/mouse/keyboard controls.
- Library: CBZ/ZIP, CBR, CB7, CBT and image-folder volumes, grouped into series; MariaDB or SQLite storage.
- Scheduled and on-demand library scans and metadata refreshes (background task runner, `bin/wankarr`).
- Metadata from MyAnimeList via Tenrai with fuzzy auto-matching, manual matching, and MangaDex content ratings.
- Series pages, merge/move/split grouping, hidden series, multi-select bulk actions.
- Search with fuzzy facet suggestions (genres, authors, status, type, rating, year) as pills.
- Sonarr-style naming conventions with a previewed, validated manual rename.
- Import: upload volumes, auto-match (manual fallback) and copy them into the library with proper names.
- Small Alpine-based container image (about 60 MB: nginx, PHP-FPM 8.4, bsdtar) on GHCR for `linux/amd64` and `linux/arm64`: `:latest` (newest stable release), `:X.Y.Z`/`:X.Y`/`:X`, `:dev` from `main`, weekly security rebuilds of the newest release.
- Web UI port configurable with `WANKARR_PORT` (default 8484) in the container and Compose file.
- Release tooling (`make release`), GitHub Releases with notes and tarball, Dependabot for Actions and the base image.
- Licensed under GPL-3.0-or-later.
