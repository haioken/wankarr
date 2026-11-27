<?php
declare(strict_types=1);

/**
 * Import: upload volumes, match them to a series (existing library series,
 * or a new one from MyAnimeList via Tenrai, or a plain title), and copy them
 * into the library named by the naming convention.
 *
 * Uploads land in a staging batch under data/import/<batch>. Files arrive
 * in chunks over PUT (php's post_max_size doesn't apply to PUT bodies), so
 * volume size is not limited by PHP's upload settings.
 *
 * Like renaming, the import is two-step: wk_import_plan() renders every
 * destination and validates it; wk_import_commit() recomputes the plan and
 * only proceeds if it still matches the previewed token.
 */

const WK_IMPORT_EXT = ['cbz', 'zip', 'cbr', 'rar', 'cb7', '7z', 'cbt', 'tar'];
const WK_IMPORT_TTL = 172800; // staged batches are cleared after two days

function wk_import_dir(?string $batch = null): string
{
    $base = dirname(wk_data_path('import/.keep'));
    return $batch === null ? $base : "$base/$batch";
}

function wk_import_valid_batch(string $batch): bool
{
    return (bool) preg_match('/^[a-f0-9]{16}$/', $batch) && is_dir(wk_import_dir($batch));
}

function wk_import_create(): string
{
    wk_import_cleanup();
    $batch = bin2hex(random_bytes(8));
    mkdir(wk_import_dir($batch) . '/files', 0775, true);
    wk_import_save($batch, ['created' => time(), 'files' => [], 'analysis' => null]);
    return $batch;
}

function wk_import_load(string $batch): array
{
    if (!wk_import_valid_batch($batch)) {
        throw new InvalidArgumentException('Import batch not found (it may have expired)');
    }
    $data = json_decode((string) @file_get_contents(wk_import_dir($batch) . '/batch.json'), true);
    return is_array($data) ? $data : ['created' => time(), 'files' => [], 'analysis' => null];
}

function wk_import_save(string $batch, array $data): void
{
    file_put_contents(wk_import_dir($batch) . '/batch.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/** Run $fn with the batch state locked; $fn receives and returns the state. */
function wk_import_update(string $batch, callable $fn): array
{
    if (!wk_import_valid_batch($batch)) {
        throw new InvalidArgumentException('Import batch not found (it may have expired)');
    }
    $lock = fopen(wk_import_dir($batch) . '/batch.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        $data = $fn(wk_import_load($batch));
        wk_import_save($batch, $data);
        return $data;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function wk_import_discard(string $batch): void
{
    if (wk_import_valid_batch($batch)) {
        wk_rmtree(wk_import_dir($batch));
    }
}

function wk_import_cleanup(): void
{
    foreach (glob(wk_import_dir() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (preg_match('/^[a-f0-9]{16}$/', basename($dir)) && filemtime($dir) < time() - WK_IMPORT_TTL) {
            wk_rmtree($dir);
        }
    }
}

/**
 * Append one chunk (read from $input) to an uploading file.
 * Chunks must arrive in order; re-sending a chunk at the current size resumes.
 */
function wk_import_receive(string $batch, int $fileId, string $name, int $offset, int $total, $input): array
{
    $name = basename(str_replace('\\', '/', $name));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($name === '' || $name[0] === '.' || !in_array($ext, WK_IMPORT_EXT, true)) {
        throw new InvalidArgumentException("$name isn't a supported archive (" . implode(', ', WK_IMPORT_EXT) . ')');
    }
    if ($fileId < 0 || $fileId > 100000 || $total <= 0) {
        throw new InvalidArgumentException('Bad upload request');
    }
    $path = wk_import_dir($batch) . "/files/$fileId.$ext";

    $state = wk_import_update($batch, function (array $d) use ($fileId, $name, $ext, $total) {
        $d['files'][$fileId] ??= ['id' => $fileId, 'name' => $name, 'ext' => $ext, 'size' => $total, 'complete' => false, 'error' => null];
        $d['analysis'] = null; // new files invalidate any analysis
        return $d;
    });
    $file = $state['files'][$fileId];
    if ($file['name'] !== $name || $file['size'] !== $total) {
        throw new InvalidArgumentException('Upload id reused for a different file');
    }

    clearstatcache(true, $path);
    $have = is_file($path) ? filesize($path) : 0;
    if ($offset !== $have) {
        // Tell the client where to resume from.
        return ['received' => $have, 'complete' => $have >= $total, 'resume' => true];
    }
    $fh = fopen($path, 'c');
    fseek($fh, $offset);
    $written = stream_copy_to_stream($input, $fh, $total - $offset);
    fclose($fh);
    $received = $offset + (int) $written;

    $complete = $received >= $total;
    $error = null;
    if ($complete) {
        $format = wk_archive_format($path);
        $error = $format ? null : 'Not a readable ZIP, RAR, 7z or TAR archive';
        wk_import_update($batch, function (array $d) use ($fileId, $format) {
            $d['files'][$fileId]['complete'] = true;
            $d['files'][$fileId]['format'] = $format;
            $d['files'][$fileId]['error'] = $format ? null : 'Not a readable ZIP, RAR, 7z or TAR archive';
            return $d;
        });
    }
    return ['received' => $received, 'complete' => $complete, 'error' => $error];
}

function wk_import_file_path(string $batch, array $file): string
{
    return wk_import_dir($batch) . "/files/{$file['id']}.{$file['ext']}";
}

// ---------------------------------------------------------------------------
// Analysis: group files into series and find a match for each group

function wk_import_analyse(string $batch, int $libraryId): array
{
    $lib = wk_library($libraryId);
    if (!$lib) {
        throw new InvalidArgumentException('Library not found');
    }
    $data = wk_import_load($batch);
    $groups = [];
    foreach ($data['files'] as $f) {
        if (!$f['complete'] || $f['error']) {
            continue;
        }
        $parsed = wk_parse_name($f['name'], true);
        $title = $parsed['series'] ?? wk_clean_title(pathinfo($f['name'], PATHINFO_FILENAME));
        $key = wk_norm($title) ?: $f['name'];
        $groups[$key] ??= ['key' => $key, 'title' => $title, 'year' => null, 'files' => []];
        $groups[$key]['year'] ??= $parsed['year'];
        $groups[$key]['files'][] = [
            'id' => $f['id'],
            'name' => $f['name'],
            'size' => $f['size'],
            'format' => $f['format'],
            'pages' => wk_book_count_pages(['abs' => wk_import_file_path($batch, $f), 'format' => $f['format']]),
            'volume' => $parsed['volume'],
            'chapter' => $parsed['chapter'],
            'parsed_title' => $parsed['title'],
            'include' => true,
        ];
    }
    foreach ($groups as &$g) {
        usort($g['files'], fn($a, $b) => [$a['volume'] ?? INF, $a['chapter'] ?? INF, $a['name']] <=> [$b['volume'] ?? INF, $b['chapter'] ?? INF, $b['name']]);
        $g['target'] = wk_import_resolve($libraryId, $g['title'], $g['year'], count($g['files']));
    }
    unset($g);
    $groups = array_values($groups);
    wk_import_update($batch, function (array $d) use ($groups, $libraryId) {
        $d['analysis'] = ['library' => $libraryId, 'groups' => $groups];
        return $d;
    });
    return ['library' => $libraryId, 'groups' => $groups];
}

/**
 * Best target for a parsed series title:
 *   existing library series (by grouping key, then fuzzy title) →
 *   MyAnimeList match (reusing a library series that already has it) →
 *   a new series with just the title, flagged for review.
 */
function wk_import_resolve(int $libraryId, string $title, ?int $year, int $count): array
{
    $threshold = (float) wk_config()['metadata']['auto_match'];

    $sid = wk_val('SELECT k.series_id FROM series_keys k JOIN series s ON s.id = k.series_id WHERE k.key_hash = ? AND s.library_id = ?', [sha1(wk_series_key($libraryId, $title)), $libraryId]);
    if ($sid) {
        return wk_import_existing_target((int) $sid, 1.0, 'library');
    }

    $best = null;
    $candidates = wk_all('SELECT s.id, s.title, s.metadata_id FROM series s WHERE s.library_id = ?', [$libraryId]);
    $alts = wk_series_alt_titles(array_column($candidates, 'metadata_id'));
    foreach ($candidates as $c) {
        foreach ([$c['title'], ...($alts[(int) $c['metadata_id']] ?? [])] as $t) {
            $score = wk_similarity($title, (string) $t);
            if (!$best || $score > $best[1]) {
                $best = [(int) $c['id'], $score];
            }
        }
    }
    if ($best && $best[1] >= $threshold) {
        return wk_import_existing_target($best[0], $best[1], 'library');
    }

    try {
        $cands = wk_match_candidates(['title' => $title, 'local_year' => $year, 'book_count' => $count]);
    } catch (Throwable $e) {
        wk_log('import: metadata search failed: ' . $e->getMessage());
        $cands = [];
    }
    $top = $cands[0] ?? null;
    if ($top && $top['match'] >= $threshold) {
        $existing = wk_val("SELECT s.id FROM series s JOIN metadata m ON m.id = s.metadata_id WHERE s.library_id = ? AND m.provider = 'tenrai' AND m.provider_id = ?", [$libraryId, $top['id']]);
        if ($existing) {
            return wk_import_existing_target((int) $existing, $top['match'], 'myanimelist');
        }
        return wk_import_new_target($top, $top['match']);
    }
    return [
        'type' => 'new', 'title' => $title, 'year' => $year, 'provider_id' => null, 'cover' => null,
        'score' => $top['match'] ?? null, 'via' => 'title', 'review' => true,
        'suggestion' => $top ? ['id' => $top['id'], 'title' => $top['title'], 'year' => $top['year']] : null,
    ];
}

function wk_import_existing_target(int $seriesId, float $score, string $via): array
{
    $s = wk_one('SELECT s.id, s.title, s.folder, COALESCE(m.year, s.year) AS year, s.hidden FROM series s LEFT JOIN metadata m ON m.id = s.metadata_id WHERE s.id = ?', [$seriesId]);
    return [
        'type' => 'existing', 'series_id' => $seriesId, 'title' => $s['title'], 'year' => $s['year'],
        'cover' => "api/cover.php?series=$seriesId", 'score' => round($score, 4), 'via' => $via, 'review' => false,
        'hidden' => (bool) $s['hidden'],
    ];
}

/** A new series from a metadata candidate (as returned by wk_match_candidates). */
function wk_import_new_target(array $cand, ?float $score): array
{
    return [
        'type' => 'new', 'provider_id' => $cand['id'],
        'title' => wk_preferred_title(['title' => $cand['title'], 'title_english' => $cand['title_english'] ?? null]),
        'year' => $cand['year'], 'cover' => $cand['cover'], 'score' => $score, 'via' => 'myanimelist', 'review' => false,
        'url' => $cand['url'] ?? null,
    ];
}

/** Volume display name for edited volume/chapter numbers. */
function wk_import_volume_name(?float $v, ?float $c, string $fallback): string
{
    return match (true) {
        $v !== null && $c !== null => 'Vol. ' . wk_num($v) . ' Ch. ' . wk_num($c),
        $v !== null => 'Volume ' . wk_num($v),
        $c !== null => 'Chapter ' . wk_num($c),
        default => $fallback,
    };
}

// ---------------------------------------------------------------------------
// Plan + commit

/**
 * Render destinations for the client's decisions and validate them.
 * $groups: [{target: {...}, files: [{id, volume, chapter, include}]}]
 */
function wk_import_plan(string $batch, array $groups): array
{
    $data = wk_import_load($batch);
    $libraryId = (int) ($data['analysis']['library'] ?? 0);
    $lib = wk_library($libraryId);
    if (!$lib || !$lib['available']) {
        throw new InvalidArgumentException('The library folder is not available');
    }
    $root = realpath($lib['path']);
    $naming = wk_naming();
    $num = fn($v) => ($v === null || $v === '' || !is_numeric($v)) ? null : (float) $v;
    $claimed = [];
    $out = [];

    foreach ($groups as $gi => $g) {
        $t = (array) ($g['target'] ?? []);
        $group = ['index' => $gi, 'target' => null, 'folder' => null, 'files' => [], 'error' => null];
        // A MyAnimeList pick the library already has goes into that series.
        if (($t['type'] ?? '') === 'new' && !empty($t['provider_id'])) {
            $have = wk_val("SELECT s.id FROM series s JOIN metadata m ON m.id = s.metadata_id WHERE s.library_id = ? AND m.provider = 'tenrai' AND m.provider_id = ?", [$libraryId, (string) $t['provider_id']]);
            if ($have) {
                $t = ['type' => 'existing', 'series_id' => (int) $have];
            }
        }
        if (($t['type'] ?? '') === 'existing') {
            $s = wk_one('SELECT s.id, s.title, s.folder, s.library_id, COALESCE(m.year, s.year) AS year FROM series s LEFT JOIN metadata m ON m.id = s.metadata_id WHERE s.id = ?', [(int) ($t['series_id'] ?? 0)]);
            if (!$s || (int) $s['library_id'] !== $libraryId) {
                $group['error'] = 'Choose a series from this library';
            } else {
                // Keep a series together: use its current folder if it has one.
                $group['folder'] = $s['folder'] && is_dir("$root/{$s['folder']}")
                    ? $s['folder']
                    : wk_naming_render($naming['series_folder'], wk_naming_context($s), $naming['colon']);
                $group['target'] = ['type' => 'existing', 'series_id' => (int) $s['id'], 'title' => $s['title'], 'year' => $s['year']];
                $series = $s;
            }
        } else {
            $title = trim((string) ($t['title'] ?? ''));
            if ($title === '') {
                $group['error'] = 'A series title is required';
            } else {
                $series = ['title' => $title, 'year' => isset($t['year']) && $t['year'] !== '' ? (int) $t['year'] : null];
                $group['folder'] = wk_naming_render($naming['series_folder'], wk_naming_context($series), $naming['colon']);
                $group['target'] = ['type' => 'new', 'title' => $title, 'year' => $series['year'], 'provider_id' => ($t['provider_id'] ?? null) ? (string) $t['provider_id'] : null, 'score' => isset($t['score']) ? (float) $t['score'] : null];
            }
        }

        foreach ((array) ($g['files'] ?? []) as $fd) {
            $file = $data['files'][(int) ($fd['id'] ?? -1)] ?? null;
            if (!$file || !$file['complete'] || $file['error']) {
                continue;
            }
            $v = $num($fd['volume'] ?? null);
            $c = $num($fd['chapter'] ?? null);
            $parsed = wk_parse_name($file['name'], true);
            $book = [
                'title' => wk_import_volume_name($v, $c, $parsed['title']),
                'volume' => $v, 'chapter' => $c, 'rel_path' => $file['name'], 'format' => $file['format'],
            ];
            $item = ['id' => $file['id'], 'name' => $file['name'], 'include' => !empty($fd['include']), 'to' => null, 'status' => 'ok', 'message' => null, 'warning' => null];
            if ($group['error']) {
                $item['status'] = 'error';
                $item['message'] = $group['error'];
            } else {
                $ctx = wk_naming_context($series, $book);
                $item['to'] = $group['folder'] . '/' . wk_naming_render($naming['volume_folder'], $ctx, $naming['colon'])
                    . '/' . wk_naming_render($naming['volume_file'], $ctx, $naming['colon']) . '.' . $file['ext'];
                $item['volume_name'] = $book['title'];
                if ($item['include']) {
                    wk_import_check($item, $root, $claimed, $group['target'], $libraryId, $v, $c);
                }
            }
            $group['files'][] = $item;
        }
        $out[] = $group;
    }

    $counts = ['import' => 0, 'skipped' => 0, 'problems' => 0];
    foreach ($out as $g) {
        foreach ($g['files'] as $f) {
            $counts[!$f['include'] ? 'skipped' : ($f['status'] === 'ok' ? 'import' : 'problems')]++;
        }
    }
    $token = sha1(json_encode([$out, $naming, $libraryId]));
    return ['library' => $libraryId, 'groups' => $out, 'counts' => $counts, 'token' => $token];
}

function wk_import_check(array &$item, string $root, array &$claimed, array $target, int $libraryId, ?float $v, ?float $c): void
{
    $key = mb_strtolower($item['to']);
    if (isset($claimed[$key])) {
        $item['status'] = 'conflict';
        $item['message'] = "Same destination as {$claimed[$key]} — give them different volume numbers";
        return;
    }
    $claimed[$key] = $item['name'];
    $dst = "$root/{$item['to']}";
    if (file_exists($dst)) {
        $item['status'] = 'conflict';
        $item['message'] = 'Already exists in the library';
        return;
    }
    $anchor = dirname($dst);
    while (!file_exists($anchor) && $anchor !== $root && $anchor !== dirname($anchor)) {
        $anchor = dirname($anchor);
    }
    if (!is_dir($anchor) || !is_writable($anchor)) {
        $item['status'] = 'error';
        $item['message'] = 'No write permission on ' . wk_rename_rel($root, $anchor);
        return;
    }
    if (wk_val('SELECT 1 FROM books WHERE path_hash = ?', [sha1($libraryId . ':' . $item['to'])])) {
        $item['status'] = 'conflict';
        $item['message'] = 'The library already lists a volume at this path — run a library scan';
        return;
    }
    if ($target['type'] === 'existing' && ($v !== null || $c !== null)) {
        $dup = wk_val(
            'SELECT title FROM books WHERE series_id = ? AND ((volume IS NULL AND ? IS NULL) OR volume = ?) AND ((chapter IS NULL AND ? IS NULL) OR chapter = ?)',
            [$target['series_id'], $v, $v, $c, $c],
        );
        if ($dup) {
            $item['warning'] = "The series already has $dup";
        }
    }
}

/** Copy the previewed files into the library and register them. */
function wk_import_commit(string $batch, array $groups, string $token): array
{
    $lock = wk_task_lock('scan');
    if ($lock === null) {
        throw new RuntimeException('A library scan is running. Try again once it finishes.');
    }
    try {
        $plan = wk_import_plan($batch, $groups);
        if (!hash_equals($plan['token'], $token)) {
            throw new InvalidArgumentException('Something changed since the preview (files, library or naming). Review the new preview before importing.');
        }
        $data = wk_import_load($batch);
        $libraryId = $plan['library'];
        $lib = wk_library($libraryId);
        $root = realpath($lib['path']);
        $results = [];

        foreach ($plan['groups'] as $g) {
            $files = array_filter($g['files'], fn($f) => $f['include'] && $f['status'] === 'ok');
            if (!$files || $g['error']) {
                continue;
            }
            $result = ['series_id' => null, 'title' => $g['target']['title'], 'imported' => 0, 'errors' => []];
            try {
                $seriesId = wk_import_series($libraryId, $g['target'], $g['folder']);
                $result['series_id'] = $seriesId;
            } catch (Throwable $e) {
                $result['errors'][] = 'Could not create the series: ' . $e->getMessage();
                $results[] = $result;
                continue;
            }
            foreach ($files as $f) {
                $src = wk_import_file_path($batch, $data['files'][$f['id']]);
                $dst = "$root/{$f['to']}";
                try {
                    wk_import_copy($src, $dst);
                    @unlink($src);
                    $result['imported']++;
                    wk_log("import: {$f['name']} → {$f['to']}");
                } catch (Throwable $e) {
                    $result['errors'][] = "{$f['name']}: " . $e->getMessage();
                    wk_log("import: {$f['name']} failed: " . $e->getMessage());
                }
            }
            // Register the copied files through the normal scanner path.
            wk_scan_library($lib, null, [$g['folder']]);
            wk_q('UPDATE series SET updated_at = ? WHERE id = ?', [time(), $seriesId]);
            $results[] = $result;
        }

        $left = array_filter(glob(wk_import_dir($batch) . '/files/*') ?: [], 'is_file');
        if (!$left) {
            wk_import_discard($batch);
        }
        return ['series' => $results, 'batch_cleared' => !$left];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Resolve or create the destination series; make the folder map to it. */
function wk_import_series(int $libraryId, array $target, string $folder): int
{
    [$folderTitle] = wk_scan_folder_title($folder);
    $keys = array_unique([wk_series_key($libraryId, $folderTitle), wk_series_key($libraryId, $target['title'])]);

    if ($target['type'] === 'existing') {
        $sid = (int) $target['series_id'];
    } else {
        $sid = null;
        if ($target['provider_id']) {
            $sid = wk_val("SELECT s.id FROM series s JOIN metadata m ON m.id = s.metadata_id WHERE s.library_id = ? AND m.provider = 'tenrai' AND m.provider_id = ?", [$libraryId, $target['provider_id']]);
        }
        $sid ??= wk_val('SELECT k.series_id FROM series_keys k WHERE k.key_hash = ?', [sha1($keys[0])]);
        if (!$sid) {
            $now = time();
            $sid = wk_insert('series', [
                'library_id' => $libraryId,
                'title' => mb_substr($target['title'], 0, 255),
                'sort_title' => mb_substr(wk_sort_title($target['title']), 0, 255),
                'folder' => $folder,
                'year' => $target['year'],
                'match_status' => $target['provider_id'] ? 'pending' : 'unmatched',
                'match_attempted_at' => $target['provider_id'] ? null : $now,
                'added_at' => $now,
                'updated_at' => $now,
            ]);
            if ($target['provider_id']) {
                try {
                    wk_apply_match((int) $sid, 'tenrai', $target['provider_id'], $target['score'], $target['score'] !== null && $target['score'] >= (float) wk_config()['metadata']['auto_match'] ? 'matched' : 'manual');
                } catch (Throwable $e) {
                    // Metadata can be fetched later; the import itself goes ahead.
                    wk_log('import: metadata fetch failed, will retry: ' . $e->getMessage());
                }
            }
        }
    }
    foreach ($keys as $key) {
        wk_upsert('series_keys', ['key_hash' => sha1($key), 'series_id' => (int) $sid, 'match_key' => mb_substr($key, 0, 191)], ['key_hash']);
    }
    $s = wk_one('SELECT folder FROM series WHERE id = ?', [$sid]);
    if ($s && $s['folder'] === null) {
        wk_update('series', ['folder' => $folder], ['id' => $sid]);
    }
    return (int) $sid;
}

/** Copy (or move, when on the same filesystem) via a temporary name. */
function wk_import_copy(string $src, string $dst): void
{
    if (file_exists($dst)) {
        throw new RuntimeException('Destination already exists');
    }
    if (!is_dir(dirname($dst)) && !@mkdir(dirname($dst), 0775, true)) {
        throw new RuntimeException('Could not create ' . basename(dirname($dst)));
    }
    $tmp = dirname($dst) . '/.wk-import-' . bin2hex(random_bytes(4)) . '.part';
    $sameFs = (@stat($src)['dev'] ?? -1) === (@stat(dirname($dst))['dev'] ?? -2);
    $ok = $sameFs ? @rename($src, $tmp) : @copy($src, $tmp);
    if (!$ok || !is_file($tmp)) {
        @unlink($tmp);
        throw new RuntimeException('Copy failed: ' . preg_replace('/^\w+\([^)]*\):\s*/', '', error_get_last()['message'] ?? 'unknown error'));
    }
    if (!$sameFs && filesize($tmp) !== filesize($src)) {
        @unlink($tmp);
        throw new RuntimeException('Copy was incomplete');
    }
    if (!@rename($tmp, $dst)) {
        $sameFs ? @rename($tmp, $src) : @unlink($tmp);
        throw new RuntimeException('Could not move the file into place');
    }
}
