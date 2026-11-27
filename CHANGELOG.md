# Changelog

All notable changes are listed here. Versions follow [Semantic Versioning](https://semver.org);
`scripts/release.sh` dates the Unreleased section when a release is tagged.

## [Unreleased]

### Added
- Reader: two-page spreads with a page-curl turn, right-to-left by default, wide-page spreads, touch/mouse/keyboard controls.
- Library: CBZ/ZIP, CBR, CB7, CBT and image-folder volumes, grouped into series; MariaDB or SQLite storage.
- Scheduled and on-demand library scans and metadata refreshes (background task runner, `bin/wankarr`).
- Metadata from MyAnimeList via Tenrai with fuzzy auto-matching, manual matching, and MangaDex content ratings.
- Series pages, merge/move/split grouping, hidden series, multi-select bulk actions.
- Search with fuzzy facet suggestions (genres, authors, status, type, rating, year) as pills.
- Sonarr-style naming conventions with a previewed, validated manual rename.
- Import: upload volumes, auto-match (manual fallback) and copy them into the library with proper names.
- Container image published to GHCR (`latest`, `dev`, and per-release tags).
