<?php
declare(strict_types=1);

/**
 * Library scanner.
 *
 * Grouping rules:
 *  - A book inside a folder belongs to the series named after the top-level
 *    folder under the library root ("Berserk/Vol 01.cbz", "Berserk/v01/…").
 *  - A book at the library root is grouped by the series name parsed from its
 *    file name ("Berserk v42.cbz").
 * Both produce the same normalised key, so a loose "Berserk v42.cbz" joins
 * the "Berserk/" folder series. Merging series adds the merged series' keys
 * as aliases, so manual grouping survives rescans.
 */

const WK_SKIP_NAMES = ['__MACOSX', '@eaDir', '#recycle', '$RECYCLE.BIN', 'lost+found'];

/** Scan every library (or one). $progress receives human-readable status. */
function wk_scan(?int $libraryId = null, ?callable $progress = null): array
{
    $totals = ['added' => 0, 'updated' => 0, 'removed' => 0, 'books' => 0, 'series_added' => 0];
    foreach (wk_sync_libraries() as $lib) {
        if ($libraryId !== null && (int) $lib['id'] !== $libraryId) {
            continue;
        }
        if (!$lib['available']) {
            // Never treat an unmounted share as "everything was deleted".
            wk_log("scan: library {$lib['name']} ({$lib['path']}) is unavailable; skipped");
            $progress && $progress("{$lib['name']} is unavailable, skipped");
            continue;
        }
        foreach (wk_scan_library($lib, $progress) as $k => $v) {
            $totals[$k] += $v;
        }
    }
    if ($totals['series_added'] > 0 || wk_val("SELECT COUNT(*) FROM series WHERE match_status = 'pending'") > 0) {
        wk_task_make_due('metadata');
    }
    return $totals;
}

/**
 * Scan a library, or only the given top-level entries of it ($scope: list of
 * library-relative names such as "Berserk" or "Berserk v42.cbz").
 */
function wk_scan_library(array $lib, ?callable $progress = null, ?array $scope = null): array
{
    $root = realpath($lib['path']);
    $libId = (int) $lib['id'];
    $now = time();
    $progress && $progress("Scanning {$lib['name']}…");

    $found = [];
    if ($scope === null) {
        wk_scan_walk($root, '', $found, 0);
    } else {
        foreach (array_unique($scope) as $entry) {
            $abs = "$root/$entry";
            if ($entry === '' || str_contains($entry, '/') || is_link($abs) || !file_exists($abs) || wk_is_data_dir($abs)) {
                continue;
            }
            if (is_dir($abs)) {
                [$kind, $format] = wk_classify($abs);
                if ($kind === 'book') {
                    $found[$entry] = [$format, $abs];
                } elseif ($kind === 'folder') {
                    wk_scan_walk($abs, $entry, $found, 1);
                }
            } elseif (in_array(wk_ext($entry), WK_ARCHIVE_EXT, true) && ($format = wk_archive_format($abs))) {
                $found[$entry] = [$format, $abs];
            }
        }
    }

    $existing = [];
    foreach (wk_all('SELECT id, series_id, rel_path, path_hash, format, file_size, file_mtime FROM books WHERE library_id = ?', [$libId]) as $row) {
        if ($scope !== null && !in_array(explode('/', $row['rel_path'], 2)[0], $scope, true)) {
            continue; // outside the rescanned entries: leave alone
        }
        $existing[$row['path_hash']] = $row;
    }

    $stats = ['added' => 0, 'updated' => 0, 'removed' => 0, 'books' => count($found), 'series_added' => 0];
    $touched = [];
    $seen = [];
    $i = 0;
    $total = count($found);

    foreach (array_chunk($found, 50, true) as $chunk) {
        wk_tx(function () use ($chunk, $lib, $libId, $now, $existing, $total, $progress, &$i, &$stats, &$touched, &$seen) {
            foreach ($chunk as $rel => [$format, $abs]) {
                $i++;
                $hash = sha1($libId . ':' . $rel);
                $isFile = is_file($abs);
                $size = $isFile ? (int) filesize($abs) : 0;
                $mtime = (int) filemtime($abs);
                $row = $existing[$hash] ?? null;

                if ($row && (int) $row['file_size'] === $size && (int) $row['file_mtime'] === $mtime && $row['format'] === $format) {
                    $seen[] = (int) $row['id'];
                    continue;
                }
                if ($progress && ($i % 10 === 0 || $i === $total)) {
                    $progress("Scanning {$lib['name']}: $i / $total");
                }

                $parsed = wk_parse_name(basename($rel), $isFile);
                $fields = [
                    'format' => $format,
                    'file_size' => $size,
                    'file_mtime' => $mtime,
                    'title' => mb_substr($parsed['title'], 0, 255),
                    'volume' => $parsed['volume'],
                    'chapter' => $parsed['chapter'],
                    'page_count' => wk_book_count_pages(['abs' => $abs, 'format' => $format]),
                    'updated_at' => $now,
                    'seen_at' => $now,
                ];
                if ($row) {
                    wk_update('books', $fields, ['id' => $row['id']]);
                    $touched[(int) $row['series_id']] = true;
                    $stats['updated']++;
                } else {
                    [$seriesId, $created] = wk_scan_series_for($lib, $rel, $parsed);
                    wk_insert('books', $fields + [
                        'library_id' => $libId,
                        'series_id' => $seriesId,
                        'rel_path' => $rel,
                        'path_hash' => $hash,
                        'added_at' => $now,
                    ]);
                    $touched[$seriesId] = true;
                    $stats['added']++;
                    $stats['series_added'] += $created ? 1 : 0;
                }
            }
        });
    }

    foreach (array_chunk($seen, 500) as $ids) {
        wk_q('UPDATE books SET seen_at = ? WHERE id IN (' . wk_in($ids) . ')', [$now, ...$ids]);
    }

    // Anything not found on disk this pass has been deleted or moved.
    $present = [];
    foreach (array_keys($found) as $rel) {
        $present[sha1($libId . ':' . $rel)] = true;
    }
    $gone = array_filter($existing, fn($row) => !isset($present[$row['path_hash']]));
    foreach (array_chunk(array_values($gone), 500) as $rows) {
        wk_q('DELETE FROM books WHERE id IN (' . wk_in($rows) . ')', array_column($rows, 'id'));
        foreach ($rows as $row) {
            $touched[(int) $row['series_id']] = true;
        }
        $stats['removed'] += count($rows);
    }

    wk_q('DELETE FROM series WHERE library_id = ? AND id NOT IN (SELECT DISTINCT series_id FROM books WHERE library_id = ?)', [$libId, $libId]);

    foreach (array_keys($touched) as $sid) {
        if (wk_val('SELECT 1 FROM series WHERE id = ?', [$sid])) {
            wk_q('UPDATE series SET updated_at = ? WHERE id = ?', [$now, $sid]);
            wk_series_reindex($sid);
        }
    }

    wk_log(sprintf('scan: %s%s — %d volumes, +%d ~%d -%d, %d new series', $lib['name'], $scope !== null ? ' (' . implode(', ', $scope) . ')' : '', $stats['books'], $stats['added'], $stats['updated'], $stats['removed'], $stats['series_added']));
    return $stats;
}

/**
 * True for wankarr's own data directory, or one of its cache folders when a
 * library root and the data directory are the same folder.
 */
function wk_is_data_dir(string $abs): bool
{
    static $data = null;
    $data ??= realpath(wk_config()['data_dir']) ?: '';
    if ($data === '' || !is_dir($abs)) {
        return false;
    }
    $real = realpath($abs);
    return $real === $data
        || (dirname($real) === $data && in_array(basename($real), ['covers', 'extract', 'locks', 'logs', 'meta'], true));
}

/** Rescan only the folders/files that hold these series' volumes. */
function wk_rescan_series(array $seriesIds): array
{
    $totals = ['added' => 0, 'updated' => 0, 'removed' => 0, 'books' => 0, 'series_added' => 0];
    $ids = array_values(array_unique(array_map('intval', $seriesIds)));
    if (!$ids) {
        return $totals;
    }
    $scopes = [];
    foreach (wk_all('SELECT library_id, rel_path FROM books WHERE series_id IN (' . wk_in($ids) . ')', $ids) as $r) {
        $scopes[(int) $r['library_id']][] = explode('/', $r['rel_path'], 2)[0];
    }
    foreach (wk_all('SELECT library_id, folder FROM series WHERE id IN (' . wk_in($ids) . ') AND folder IS NOT NULL', $ids) as $r) {
        $scopes[(int) $r['library_id']][] = $r['folder'];
    }
    foreach ($scopes as $libId => $scope) {
        $lib = wk_library($libId);
        if (!$lib || !$lib['available']) {
            continue;
        }
        foreach (wk_scan_library($lib, null, array_values(array_unique($scope))) as $k => $v) {
            $totals[$k] += $v;
        }
    }
    if ($totals['series_added'] > 0) {
        wk_task_make_due('metadata');
    }
    return $totals;
}

/** Collect books below $abs as [rel => [format, abs]]. */
function wk_scan_walk(string $abs, string $rel, array &$out, int $depth): void
{
    if ($depth > 12) {
        return;
    }
    $names = @scandir($abs) ?: [];
    usort($names, 'strnatcasecmp');
    foreach ($names as $name) {
        if ($name === '' || $name[0] === '.' || in_array($name, WK_SKIP_NAMES, true)) {
            continue;
        }
        $childAbs = "$abs/$name";
        $childRel = $rel === '' ? $name : "$rel/$name";
        if (is_link($childAbs) || wk_is_data_dir($childAbs)) {
            continue; // symlinks could escape the root; our own caches aren't manga
        }
        if (is_dir($childAbs)) {
            [$kind, $format] = wk_classify($childAbs);
            if ($kind === 'book') {
                $out[$childRel] = [$format, $childAbs];
            } elseif ($kind === 'folder') {
                wk_scan_walk($childAbs, $childRel, $out, $depth + 1);
            }
        } elseif (in_array(wk_ext($name), WK_ARCHIVE_EXT, true) && ($format = wk_archive_format($childAbs))) {
            $out[$childRel] = [$format, $childAbs];
        }
    }
}

/** Find or create the series a newly discovered book belongs to. */
function wk_scan_series_for(array $lib, string $rel, array $parsed): array
{
    $parts = explode('/', $rel);
    if (count($parts) > 1) {
        $folder = $parts[0];
        [$title, $year] = wk_scan_folder_title($folder);
    } else {
        $folder = null;
        $title = $parsed['series'] ?? wk_clean_title(pathinfo($rel, PATHINFO_FILENAME));
        $year = $parsed['year'];
    }
    $title = mb_substr(trim($title) !== '' ? $title : $rel, 0, 255);
    $key = wk_series_key((int) $lib['id'], $title);

    $id = wk_val('SELECT series_id FROM series_keys WHERE key_hash = ?', [sha1($key)]);
    if ($id) {
        return [(int) $id, false];
    }
    $now = time();
    $id = wk_insert('series', [
        'library_id' => $lib['id'],
        'title' => $title,
        'sort_title' => mb_substr(wk_sort_title($title), 0, 255),
        'folder' => $folder,
        'year' => $year,
        'match_status' => 'pending',
        'added_at' => $now,
        'updated_at' => $now,
    ]);
    wk_insert('series_keys', ['key_hash' => sha1($key), 'series_id' => $id, 'match_key' => mb_substr($key, 0, 191)]);
    return [$id, true];
}

/** Series title (and year) the scanner derives from a series folder name. */
function wk_scan_folder_title(string $folder): array
{
    $parsed = wk_parse_name($folder, false);
    return [$parsed['series'] ?? wk_clean_title($folder), $parsed['year']];
}

function wk_series_key(int $libraryId, string $title): string
{
    $norm = wk_norm($title);
    return $libraryId . ':' . ($norm !== '' ? $norm : $title);
}
