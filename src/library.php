<?php
declare(strict_types=1);

/**
 * Library roots and filesystem confinement.
 *
 * Libraries are configured in config.php and mirrored into the database so
 * series/books can reference them. Every on-disk path is rebuilt from a
 * library root plus a stored relative path and checked with realpath(), so
 * nothing outside a configured root is ever read.
 */

/** Mirror configured libraries into the DB; returns rows keyed by id. */
function wk_sync_libraries(): array
{
    static $done = null;
    if ($done !== null) {
        return $done;
    }
    $existing = array_column(wk_all('SELECT id, name, path_hash FROM libraries'), null, 'path_hash');
    $keep = [];
    foreach (wk_config()['libraries'] as $lib) {
        $path = rtrim((string) $lib['path'], '/');
        $real = realpath($path) ?: $path;
        $hash = sha1($real);
        $name = (string) ($lib['name'] ?? basename($real));
        $row = $existing[$hash] ?? null;
        if ($row === null) {
            $keep[] = wk_insert('libraries', ['name' => $name, 'path' => $real, 'path_hash' => $hash, 'created_at' => time()]);
            continue;
        }
        if ($row['name'] !== $name) {
            wk_update('libraries', ['name' => $name], ['id' => $row['id']]);
        }
        $keep[] = (int) $row['id'];
    }
    // Libraries no longer in the config are hidden, never deleted here: a
    // typo in a path must not wipe series, merges and matches. Use
    // `bin/wankarr prune` to delete them deliberately.
    $done = [];
    if (!$keep) {
        return $done;
    }
    foreach (wk_all('SELECT * FROM libraries WHERE id IN (' . wk_in($keep) . ') ORDER BY id', $keep) as $row) {
        $row['available'] = is_dir($row['path']);
        $done[(int) $row['id']] = $row;
    }
    return $done;
}

/** Library rows that are no longer configured (hidden, data retained). */
function wk_orphan_libraries(): array
{
    $active = array_keys(wk_sync_libraries());
    return $active
        ? wk_all('SELECT * FROM libraries WHERE id NOT IN (' . wk_in($active) . ')', $active)
        : wk_all('SELECT * FROM libraries');
}

function wk_libraries(): array
{
    return wk_sync_libraries();
}

function wk_library(int $id): ?array
{
    return wk_libraries()[$id] ?? null;
}

/** Resolve a library-relative path, refusing anything that escapes the root. */
function wk_library_path(int $libraryId, string $rel): ?string
{
    $lib = wk_library($libraryId);
    if ($lib === null || str_contains($rel, "\0")) {
        return null;
    }
    $root = realpath($lib['path']);
    if ($root === false) {
        return null;
    }
    $abs = realpath($root . '/' . ltrim($rel, '/'));
    if ($abs === false || ($abs !== $root && !str_starts_with($abs, $root . '/'))) {
        return null;
    }
    return $abs;
}

/** The archive-layer view of a book row: ['abs', 'format', 'kind']. */
function wk_book_node(array $book): ?array
{
    $abs = wk_library_path((int) $book['library_id'], (string) $book['rel_path']);
    if ($abs === null) {
        return null;
    }
    return ['abs' => $abs, 'format' => $book['format'], 'kind' => 'book'];
}

/**
 * A folder with at least this many images of its own is a volume even when it
 * also has subfolders (raw releases put extras like "単ページ" in one). Fewer,
 * and the images are taken to be series art (cover.jpg) next to volume folders.
 */
const WK_DIR_BOOK_MIN_PAGES = 8;

/**
 * Decide what a path is.
 *  - archive file                        => book
 *  - dir with archives                   => folder
 *  - dir with images and no subdirs      => book (loose image folder)
 *  - dir with 8+ images and subdirs      => book (subfolder images are part of it)
 *  - dir with subdirs                    => folder
 * NAS housekeeping folders (@eaDir, __MACOSX, …) don't count.
 */
function wk_classify(string $abs): array
{
    if (is_file($abs)) {
        if (!in_array(wk_ext($abs), WK_ARCHIVE_EXT, true)) {
            return [null, null];
        }
        $format = wk_archive_format($abs);
        return $format ? ['book', $format] : [null, null];
    }
    if (!is_dir($abs)) {
        return [null, null];
    }
    $images = 0;
    $subdirs = false;
    $dh = @opendir($abs);
    if ($dh === false) {
        return [null, null];
    }
    while (($entry = readdir($dh)) !== false) {
        if ($entry[0] === '.' || in_array($entry, WK_SKIP_NAMES, true)) {
            continue;
        }
        if (in_array(wk_ext($entry), WK_ARCHIVE_EXT, true)) {
            closedir($dh);
            return ['folder', null];
        }
        if (is_dir($abs . '/' . $entry)) {
            $subdirs = true;
        } elseif (wk_is_image_name($entry)) {
            $images++;
        }
    }
    closedir($dh);
    if ($subdirs) {
        return $images >= WK_DIR_BOOK_MIN_PAGES ? ['book', 'dir'] : ['folder', null];
    }
    return $images ? ['book', 'dir'] : [null, null];
}
