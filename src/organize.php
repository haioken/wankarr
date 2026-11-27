<?php
declare(strict_types=1);

/**
 * Renaming ("organise") and deleting series on disk.
 *
 * Both are only ever started by a person from the UI — no task, scan or
 * metadata refresh calls into this file.
 *
 * Renames are two-step: wk_rename_plan() works out every move, checks for
 * collisions and filesystem permissions, and returns a token. The commit
 * recomputes the plan and refuses to run unless the token still matches, so
 * exactly what was previewed is what happens. Each volume is moved as a unit
 * and rolled back if any of its steps fail.
 */

/**
 * Build a rename plan for some series.
 * Returns ['items' => [...], 'extras' => [...], 'series' => [...], 'counts' => [...], 'token' => string].
 */
function wk_rename_plan(array $seriesIds): array
{
    $settings = wk_naming();
    $items = [];
    $extras = [];
    $seriesOut = [];

    foreach (array_unique(array_map('intval', $seriesIds)) as $sid) {
        $series = wk_one(WK_SERIES_SELECT . ' WHERE s.id = ?', [$sid]);
        if ($series === null) {
            continue;
        }
        $lib = wk_library((int) $series['library_id']);
        $root = $lib && $lib['available'] ? realpath($lib['path']) : false;
        $info = ['id' => $sid, 'title' => $series['title'], 'library' => (int) $series['library_id'], 'folder' => null, 'error' => null, 'warning' => null];
        if ($root === false) {
            $info['error'] = 'Library folder is not available';
            $seriesOut[] = $info;
            continue;
        }
        $seriesDir = wk_naming_render($settings['series_folder'], wk_naming_context($series), $settings['colon']);
        $info['folder'] = $seriesDir;
        $others = wk_val(
            'SELECT COUNT(DISTINCT series_id) FROM books WHERE library_id = ? AND series_id <> ? AND (rel_path = ? OR rel_path LIKE ? ' . wk_like_escape() . ')',
            [$series['library_id'], $sid, $seriesDir, addcslashes($seriesDir, '\\%_') . '/%'],
        );
        if ($others) {
            $info['warning'] = "Another series already uses the folder “{$seriesDir}”. Volumes keep their series, but consider renaming one of them.";
        }
        $books = wk_series_books($sid);
        foreach ($books as $book) {
            $items[] = wk_rename_item($series, $book, $seriesDir, $settings, $root);
        }
        foreach (wk_rename_extras($series, $books, $seriesDir, $root, $seriesIds) as $extra) {
            $extras[] = $extra;
        }
        $seriesOut[] = $info;
    }
    $folderUse = array_count_values(array_map(fn($x) => $x['library'] . ':' . mb_strtolower((string) $x['folder']), array_filter($seriesOut, fn($x) => $x['folder'] !== null)));
    foreach ($seriesOut as &$info) {
        if ($info['folder'] !== null && $folderUse[$info['library'] . ':' . mb_strtolower($info['folder'])] > 1) {
            $info['warning'] = "Several selected series would share the folder “{$info['folder']}”.";
        }
    }
    unset($info);

    wk_rename_check_collisions($items, $extras);
    foreach ($items as &$item) {
        if ($item['status'] === 'ok' && $item['changed']) {
            wk_rename_check_fs($item);
        }
    }
    unset($item);
    foreach ($extras as &$extra) {
        if ($extra['status'] === 'ok') {
            wk_rename_check_fs($extra);
        }
    }
    unset($extra);

    $counts = ['rename' => 0, 'unchanged' => 0, 'problems' => 0, 'pages' => 0, 'extras' => 0];
    foreach ($items as $i) {
        if ($i['status'] !== 'ok') {
            $counts['problems']++;
        } elseif ($i['changed']) {
            $counts['rename']++;
            $counts['pages'] += count($i['pages']);
        } else {
            $counts['unchanged']++;
        }
    }
    foreach ($extras as $e) {
        $e['status'] === 'ok' ? $counts['extras']++ : $counts['problems']++;
    }

    $ops = array_map(fn($i) => [$i['book_id'], $i['from'], $i['to'], $i['pages'], $i['status']], $items);
    $ops[] = array_map(fn($e) => [$e['from'], $e['to'], $e['status']], $extras);
    return [
        'series' => $seriesOut,
        'items' => $items,
        'extras' => $extras,
        'counts' => $counts,
        'token' => sha1(json_encode([$ops, $settings])),
    ];
}

function wk_rename_item(array $series, array $book, string $seriesDir, array $settings, string $root): array
{
    $ctx = wk_naming_context($series, $book);
    $volumeDir = wk_naming_render($settings['volume_folder'], $ctx, $settings['colon']);
    $item = [
        'book_id' => (int) $book['id'],
        'series_id' => (int) $series['id'],
        'library' => (int) $series['library_id'],
        'root' => $root,
        'title' => $book['title'],
        'kind' => $book['format'] === 'dir' ? 'dir' : 'file',
        'from' => $book['rel_path'],
        'to' => null,
        'pages' => [],
        'changed' => false,
        'status' => 'ok',
        'message' => null,
    ];

    $src = wk_library_path((int) $series['library_id'], $book['rel_path']);
    if ($src === null || !file_exists($src)) {
        return ['status' => 'error', 'message' => 'File is missing — run a library scan first'] + $item;
    }

    if ($item['kind'] === 'file') {
        $ext = strtolower(pathinfo($book['rel_path'], PATHINFO_EXTENSION));
        $item['to'] = "$seriesDir/$volumeDir/" . wk_naming_render($settings['volume_file'], $ctx, $settings['colon']) . ".$ext";
    } else {
        $item['to'] = "$seriesDir/$volumeDir";
        // Pages are numbered in the same natural order the reader uses.
        foreach (wk_dir_images($src) as $i => $rel) {
            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            $name = wk_naming_render($settings['page_file'], wk_naming_context($series, $book, $i + 1), $settings['colon']) . ".$ext";
            if ($rel !== $name) {
                $item['pages'][] = ['from' => $rel, 'to' => $name];
            }
        }
    }
    $item['changed'] = $item['to'] !== $item['from'] || $item['pages'];
    return $item;
}

/**
 * Loose files (cover.jpg, ComicInfo.xml, …) in a series folder that is being
 * left behind travel with the series — unless another series still uses it.
 */
function wk_rename_extras(array $series, array $books, string $seriesDir, string $root, array $planSeries): array
{
    $bookPaths = array_flip(array_column($books, 'rel_path'));
    $folders = [];
    foreach ($books as $b) {
        $parts = explode('/', $b['rel_path']);
        if (count($parts) > 1 && $parts[0] !== $seriesDir) {
            $folders[$parts[0]] = true;
        }
    }
    $extras = [];
    foreach (array_keys($folders) as $folder) {
        $shared = wk_val(
            'SELECT COUNT(*) FROM books WHERE library_id = ? AND rel_path LIKE ? ' . wk_like_escape() . ' AND series_id NOT IN (' . wk_in($planSeries) . ')',
            [$series['library_id'], addcslashes($folder, '\\%_') . '/%', ...array_map('intval', $planSeries)],
        );
        if ($shared) {
            continue;
        }
        foreach (@scandir("$root/$folder") ?: [] as $name) {
            $rel = "$folder/$name";
            if ($name[0] === '.' || isset($bookPaths[$rel]) || !is_file("$root/$rel") || is_link("$root/$rel")) {
                continue;
            }
            $extras[] = [
                'series_id' => (int) $series['id'], 'library' => (int) $series['library_id'], 'root' => $root,
                'kind' => 'extra', 'from' => $rel, 'to' => "$seriesDir/$name", 'status' => 'ok', 'message' => null,
            ];
        }
    }
    return $extras;
}

/** Two volumes must never end up at the same path, or inside each other. */
function wk_rename_check_collisions(array &$items, array &$extras): void
{
    $key = fn(array $i) => $i['library'] . ':' . mb_strtolower((string) $i['to']); // case-insensitive shares
    $claims = [];
    foreach ($items as $k => $i) {
        if ($i['status'] === 'ok') {
            $claims[$key($i)][] = $k;
        }
    }
    foreach ($claims as $ks) {
        if (count($ks) > 1) {
            foreach ($ks as $k) {
                $others = array_map(fn($o) => $items[$o]['title'], array_diff($ks, [$k]));
                $items[$k]['status'] = 'conflict';
                $items[$k]['message'] = 'Would get the same name as ' . implode(', ', $others) . ' — give them distinct volume numbers or adjust the naming template';
            }
        }
    }
    // A file volume must not land inside an image-folder volume's folder.
    foreach ($items as $k => $dir) {
        if ($dir['kind'] !== 'dir' || $dir['status'] !== 'ok') {
            continue;
        }
        $prefix = mb_strtolower($dir['to']) . '/';
        foreach ($items as $j => $other) {
            if ($j !== $k && $other['library'] === $dir['library'] && $other['to'] && str_starts_with(mb_strtolower($other['to']), $prefix)) {
                foreach ([$k, $j] as $x) {
                    $items[$x]['status'] = 'conflict';
                    $items[$x]['message'] = "“{$items[$k]['title']}” and “{$items[$j]['title']}” would share the folder {$dir['to']}";
                }
            }
        }
    }
    $taken = array_flip(array_map($key, array_filter($items, fn($i) => $i['to'])));
    foreach ($extras as &$e) {
        if (isset($taken[$key($e)])) {
            $e['status'] = 'conflict';
            $e['message'] = 'A volume will use this name';
        }
    }
}

/** Permission, existence and same-filesystem checks for one move. */
function wk_rename_check_fs(array &$item)
{
    $root = $item['root'];
    $src = "$root/{$item['from']}";
    $dst = "$root/{$item['to']}";
    $fail = function (string $msg, string $status = 'error') use (&$item) {
        $item['status'] = $status;
        $item['message'] = $msg;
    };

    if ($item['to'] !== $item['from']) {
        if (!is_writable(dirname($src))) {
            return $fail('No write permission on ' . wk_rename_rel($root, dirname($src)) . ' (needed to move it out)');
        }
        if (file_exists($dst) || is_link($dst)) {
            $same = realpath($dst) === realpath($src); // case-only rename on a case-insensitive share
            $emptyDir = $item['kind'] === 'dir' && is_dir($dst) && !is_link($dst) && count(scandir($dst)) === 2;
            if (!$same && !$emptyDir) {
                return $fail("{$item['to']} already exists", 'conflict');
            }
        }
        $anchor = dirname($dst);
        while (!file_exists($anchor) && $anchor !== $root && $anchor !== dirname($anchor)) {
            $anchor = dirname($anchor);
        }
        if (!is_dir($anchor)) {
            return $fail(wk_rename_rel($root, $anchor) . ' is a file, not a folder', 'conflict');
        }
        if (!is_writable($anchor)) {
            return $fail('No write permission on ' . wk_rename_rel($root, $anchor));
        }
        if ((@stat($src)['dev'] ?? null) !== (@stat($anchor)['dev'] ?? null)) {
            return $fail('Source and destination are on different filesystems');
        }
        if ($item['kind'] !== 'extra') {
            $hash = sha1($item['library'] . ':' . $item['to']);
            $owner = wk_val('SELECT id FROM books WHERE path_hash = ? AND id <> ?', [$hash, $item['book_id']]);
            if ($owner) {
                return $fail('The library still lists another volume at this path — run a library scan first', 'conflict');
            }
        }
    }
    if (!empty($item['pages'])) {
        if (!is_writable($src)) {
            return $fail('No write permission inside ' . $item['from'] . ' (needed to rename pages)');
        }
        $names = array_count_values(array_map('mb_strtolower', array_column($item['pages'], 'to')));
        if (max($names) > 1) {
            return $fail('Page names would collide — include {Page} in the page template', 'conflict');
        }
    }
}

function wk_rename_rel(string $root, string $abs): string
{
    return $abs === $root ? '(library root)' : substr($abs, strlen($root) + 1);
}

/** Apply a previewed plan. Returns per-item results. */
function wk_rename_commit(array $seriesIds, string $token): array
{
    // Hold the scan lock so a scan can't run against half-moved folders.
    $lock = wk_task_lock('scan');
    if ($lock === null) {
        throw new RuntimeException('A library scan is running. Try again once it finishes.');
    }
    try {
        $plan = wk_rename_plan($seriesIds);
        if (!hash_equals($plan['token'], $token)) {
            throw new InvalidArgumentException('Files or naming settings changed since the preview was made. Review the new preview before renaming.');
        }
        $results = ['renamed' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []];
        $leftDirs = [];
        foreach ($plan['items'] as $item) {
            if (!$item['changed']) {
                continue;
            }
            if ($item['status'] !== 'ok') {
                $results['skipped']++;
                continue;
            }
            try {
                wk_rename_apply($item);
                $results['renamed']++;
                $leftDirs[] = dirname("{$item['root']}/{$item['from']}");
                if ($item['kind'] === 'dir' && $item['to'] !== $item['from']) {
                    $leftDirs[] = "{$item['root']}/{$item['from']}";
                }
            } catch (Throwable $e) {
                $results['failed']++;
                $results['errors'][] = "{$item['title']}: " . $e->getMessage();
                wk_log("rename: {$item['from']} failed: " . $e->getMessage());
            }
        }
        foreach ($plan['extras'] as $extra) {
            if ($extra['status'] !== 'ok') {
                continue;
            }
            $dst = "{$extra['root']}/{$extra['to']}";
            if (is_dir(dirname($dst)) && !file_exists($dst) && @rename("{$extra['root']}/{$extra['from']}", $dst)) {
                $leftDirs[] = dirname("{$extra['root']}/{$extra['from']}");
                wk_log("rename: {$extra['from']} → {$extra['to']}");
            }
        }
        foreach ($plan['series'] as $s) {
            foreach (array_unique($leftDirs) as $dir) {
                wk_rmdir_empty_up($dir, (string) (wk_library($s['library'])['path'] ?? ''));
            }
            if ($s['folder'] !== null) {
                wk_rename_update_series($s);
            }
        }
        return $results;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Move one volume (and rename its pages), rolling back on any failure. */
function wk_rename_apply(array $item): void
{
    $root = $item['root'];
    $src = "$root/{$item['from']}";
    $dst = "$root/{$item['to']}";
    $done = [];
    $move = function (string $a, string $b) use (&$done) {
        if (!@rename($a, $b)) {
            $err = error_get_last()['message'] ?? 'rename failed';
            throw new RuntimeException(preg_replace('/^rename\([^)]*\):\s*/', '', $err));
        }
        $done[] = [$a, $b];
    };
    try {
        if ($item['to'] !== $item['from']) {
            if (!is_dir(dirname($dst)) && !@mkdir(dirname($dst), 0775, true)) {
                throw new RuntimeException('Could not create ' . dirname($item['to']));
            }
            if ($item['kind'] === 'dir' && is_dir($dst) && realpath($dst) !== realpath($src)) {
                @rmdir($dst); // empty placeholder folder (checked during planning)
            }
            $move($src, $dst);
        }
        if ($item['pages']) {
            // Two passes through temporary names so renames can't clobber
            // each other (e.g. "2.png" → "Page 1.png" while "1.png" exists).
            $temps = [];
            foreach ($item['pages'] as $i => $p) {
                $tmp = "$dst/.wk-tmp-$i-" . bin2hex(random_bytes(3));
                $move("$dst/{$p['from']}", $tmp);
                $temps[] = [$tmp, "$dst/{$p['to']}"];
            }
            foreach ($temps as [$tmp, $final]) {
                if (file_exists($final)) {
                    throw new RuntimeException(basename($final) . ' already exists');
                }
                $move($tmp, $final);
            }
            wk_rmdir_empty_inside($dst);
        }
    } catch (Throwable $e) {
        foreach (array_reverse($done) as [$a, $b]) {
            @rename($b, $a);
        }
        wk_rmdir_empty_up(dirname($dst), $root); // folders we created for it
        throw $e;
    }

    clearstatcache();
    wk_update('books', [
        'rel_path' => $item['to'],
        'path_hash' => sha1($item['library'] . ':' . $item['to']),
        'file_mtime' => (int) filemtime($dst),
        'updated_at' => time(),
    ], ['id' => $item['book_id']]);
    wk_log("rename: {$item['from']} → {$item['to']}" . ($item['pages'] ? ' (' . count($item['pages']) . ' pages renamed)' : ''));
}

/** Point the series at its new folder and teach future scans the new name. */
function wk_rename_update_series(array $s): void
{
    $sid = $s['id'];
    $paths = wk_col('SELECT rel_path FROM books WHERE series_id = ?', [$sid]);
    if (!$paths || array_filter($paths, fn($p) => !str_starts_with($p, $s['folder'] . '/'))) {
        return; // some volumes didn't move; leave the series as it was
    }
    [$title] = wk_scan_folder_title($s['folder']);
    $key = wk_series_key($s['library'], $title);
    wk_upsert('series_keys', ['key_hash' => sha1($key), 'series_id' => $sid, 'match_key' => mb_substr($key, 0, 191)], ['key_hash']);
    wk_update('series', ['folder' => $s['folder'], 'updated_at' => time()], ['id' => $sid]);
    wk_series_reindex($sid);
}

/** Remove empty folders inside $dir (not $dir itself). */
function wk_rmdir_empty_inside(string $dir): void
{
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        if ($f->isDir() && !$f->isLink()) {
            @rmdir($f->getPathname()); // fails harmlessly when not empty
        }
    }
}

/** Remove $dir and its now-empty parents, stopping at the library root. */
function wk_rmdir_empty_up(string $dir, string $root): void
{
    $root = realpath($root) ?: $root;
    if (is_dir($dir) && !is_link($dir)) {
        wk_rmdir_empty_inside($dir);
    }
    while ($dir !== '' && $dir !== $root && str_starts_with($dir, $root . '/') && is_dir($dir) && @rmdir($dir)) {
        $dir = dirname($dir);
    }
}

// ---------------------------------------------------------------------------
// Delete / hide

/** What deleting these series from disk would remove (for the confirmation). */
function wk_delete_plan(array $seriesIds): array
{
    $out = [];
    foreach (array_unique(array_map('intval', $seriesIds)) as $sid) {
        $series = wk_one('SELECT id, title, library_id FROM series WHERE id = ?', [$sid]);
        if (!$series) {
            continue;
        }
        $lib = wk_library((int) $series['library_id']);
        $root = $lib && $lib['available'] ? realpath($lib['path']) : false;
        $entry = ['id' => $sid, 'title' => $series['title'], 'paths' => [], 'folders' => [], 'errors' => []];
        if ($root === false) {
            $entry['errors'][] = 'Library folder is not available';
            $out[] = $entry;
            continue;
        }
        foreach (wk_col('SELECT rel_path FROM books WHERE series_id = ?', [$sid]) as $rel) {
            $abs = wk_library_path((int) $series['library_id'], $rel);
            if ($abs === null) {
                continue; // already gone
            }
            $entry['paths'][] = $rel;
            if (!is_writable(dirname($abs))) {
                $entry['errors'][] = 'No write permission on ' . wk_rename_rel($root, dirname($abs));
            }
            if (is_dir($abs) && !is_writable($abs)) {
                $entry['errors'][] = "No write permission inside $rel";
            }
            $parts = explode('/', $rel);
            if (count($parts) > 1) {
                $entry['folders'][$parts[0]] = true;
            }
        }
        // A series folder goes completely only if no other series uses it.
        foreach (array_keys($entry['folders']) as $folder) {
            $shared = wk_val(
                'SELECT COUNT(*) FROM books WHERE library_id = ? AND series_id <> ? AND rel_path LIKE ? ' . wk_like_escape(),
                [$series['library_id'], $sid, addcslashes($folder, '\\%_') . '/%'],
            );
            if ($shared) {
                unset($entry['folders'][$folder]);
            } elseif (!is_writable($root)) {
                $entry['errors'][] = 'No write permission on the library root';
            }
        }
        $entry['folders'] = array_keys($entry['folders']);
        $entry['errors'] = array_values(array_unique($entry['errors']));
        $entry['root'] = $root;
        $entry['library'] = (int) $series['library_id'];
        $out[] = $entry;
    }
    return $out;
}

/**
 * Hide series (default) or delete them from the library and the disk.
 * Series whose files can't all be removed are left untouched.
 */
function wk_series_delete(array $seriesIds, bool $deleteFiles): array
{
    $ids = array_values(array_unique(array_map('intval', $seriesIds)));
    if (!$ids) {
        return ['hidden' => 0, 'deleted' => 0, 'errors' => []];
    }
    if (!$deleteFiles) {
        wk_q('UPDATE series SET hidden = 1 WHERE id IN (' . wk_in($ids) . ')', $ids);
        return ['hidden' => count($ids), 'deleted' => 0, 'errors' => []];
    }

    $lock = wk_task_lock('scan');
    if ($lock === null) {
        throw new RuntimeException('A library scan is running. Try again once it finishes.');
    }
    $result = ['hidden' => 0, 'deleted' => 0, 'errors' => []];
    try {
        foreach (wk_delete_plan($ids) as $entry) {
            if ($entry['errors']) {
                $result['errors'][] = "{$entry['title']}: " . implode('; ', $entry['errors']);
                continue;
            }
            $root = $entry['root'];
            $failed = false;
            foreach ($entry['paths'] as $rel) {
                $abs = "$root/$rel";
                is_dir($abs) && !is_link($abs) ? wk_rmtree($abs) : @unlink($abs);
                if (file_exists($abs)) {
                    $failed = true;
                    $result['errors'][] = "{$entry['title']}: could not delete $rel";
                }
                wk_rmdir_empty_up(dirname($abs), $root);
            }
            foreach ($entry['folders'] as $folder) {
                wk_rmtree("$root/$folder"); // leftovers such as cover.jpg
            }
            if (!$failed) {
                wk_delete('series', ['id' => $entry['id']]);
                $result['deleted']++;
                wk_log("delete: removed series {$entry['title']} and its files (" . implode(', ', $entry['folders'] ?: $entry['paths']) . ')');
            }
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $result;
}
