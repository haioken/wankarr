<?php
declare(strict_types=1);

/**
 * Book access for every supported container.
 *
 *  dir  – a folder of loose images, read straight off disk
 *  zip  – CBZ/ZIP via ext-zip, random access without extraction
 *  rar / 7z / tar – CBR/CB7/CBT via bsdtar (or 7z / unrar), extracted once
 *         into a small LRU cache under data/extract and then served as files
 *
 * Formats are detected by magic bytes, so a mis-named .cbr that is really a
 * zip (very common) still opens through the fast path.
 */

function wk_archive_format(string $abs): ?string
{
    $fh = @fopen($abs, 'rb');
    if ($fh === false) {
        return null;
    }
    $head = (string) fread($fh, 512);
    fclose($fh);
    return match (true) {
        str_starts_with($head, "PK\x03\x04"), str_starts_with($head, "PK\x05\x06") => 'zip',
        str_starts_with($head, "Rar!\x1A\x07") => 'rar',
        str_starts_with($head, "7z\xBC\xAF\x27\x1C") => '7z',
        strlen($head) >= 262 && substr($head, 257, 5) === 'ustar' => 'tar',
        default => null,
    };
}

function wk_book_signature(array $node): string
{
    clearstatcache(true, $node['abs']);
    return $node['format'] . '|' . filemtime($node['abs']) . '|' . (is_file($node['abs']) ? filesize($node['abs']) : 0);
}

/**
 * Page list with dimensions, cached per book until the file changes.
 * Returns ['pages' => [[name, width, height], ...], 'root' => ?string]
 * where root is the on-disk directory pages are read from (dir/extracted).
 */
function wk_book_meta(array $node): array
{
    if ($node['kind'] !== 'book') {
        throw new RuntimeException('Not a book');
    }
    $key = sha1($node['abs']);
    $sig = wk_book_signature($node);
    $cacheFile = wk_data_path("meta/$key.ser");

    $root = match ($node['format']) {
        'dir' => $node['abs'],
        'zip' => null,
        default => wk_extract_cached($node, $key, $sig),
    };

    if (is_file($cacheFile)) {
        $cached = @unserialize((string) file_get_contents($cacheFile), ['allowed_classes' => false]);
        if (is_array($cached) && ($cached['sig'] ?? null) === $sig) {
            return ['pages' => $cached['pages'], 'root' => $root];
        }
    }

    $pages = $node['format'] === 'zip' ? wk_zip_scan($node['abs']) : wk_dir_scan($root);
    file_put_contents($cacheFile, serialize(['sig' => $sig, 'pages' => $pages]), LOCK_EX);
    return ['pages' => $pages, 'root' => $root];
}

/** Bytes (zip) or path (everything else) for one page. */
function wk_book_page(array $node, int $index): ?array
{
    $meta = wk_book_meta($node);
    $page = $meta['pages'][$index] ?? null;
    if ($page === null) {
        return null;
    }
    [$name] = $page;
    if ($node['format'] === 'zip') {
        $zip = new ZipArchive();
        if ($zip->open($node['abs'], ZipArchive::RDONLY) !== true) {
            return null;
        }
        $bytes = $zip->getFromName($name);
        $zip->close();
        return $bytes === false ? null : ['name' => $name, 'bytes' => $bytes];
    }
    $path = realpath($meta['root'] . '/' . $name);
    if ($path === false || !str_starts_with($path, $meta['root'] . '/') || !is_file($path)) {
        return null;
    }
    return ['name' => $name, 'path' => $path];
}

/** First page only, without extracting a whole CLI archive (used for covers). */
function wk_book_first_page(array $node): ?array
{
    if (in_array($node['format'], ['dir', 'zip'], true)) {
        return wk_book_page($node, 0);
    }
    $names = array_values(array_filter(wk_cli_list($node['abs'], $node['format']), 'wk_is_image_name'));
    wk_natsort($names);
    if (!$names) {
        return null;
    }
    $bytes = wk_cli_read($node['abs'], $node['format'], $names[0]);
    return $bytes === null ? null : ['name' => $names[0], 'bytes' => $bytes];
}

/** Count pages cheaply (no image decoding, no extraction) for library scans. */
function wk_book_count_pages(array $node): ?int
{
    switch ($node['format']) {
        case 'zip':
            $zip = new ZipArchive();
            if ($zip->open($node['abs'], ZipArchive::RDONLY) !== true) {
                return null;
            }
            $n = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (!str_ends_with($name, '/') && wk_is_image_name($name)) {
                    $n++;
                }
            }
            $zip->close();
            return $n;
        case 'dir':
            $n = 0;
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($node['abs'], FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && !$f->isLink() && wk_is_image_name($f->getPathname())) {
                    $n++;
                }
            }
            return $n;
        default:
            $names = wk_cli_list($node['abs'], $node['format']);
            return $names ? count(array_filter($names, 'wk_is_image_name')) : null;
    }
}

// ---------------------------------------------------------------------------
// Scanning

function wk_image_size_bytes(string $bytes): array
{
    $info = @getimagesizefromstring($bytes);
    return $info ? [(int) $info[0], (int) $info[1]] : [0, 0];
}

function wk_zip_scan(string $abs): array
{
    $zip = new ZipArchive();
    if ($zip->open($abs, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException('Unable to open archive');
    }
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if (!str_ends_with($name, '/') && wk_is_image_name($name)) {
            $names[] = $name;
        }
    }
    wk_natsort($names);

    $pages = [];
    foreach ($names as $name) {
        // Image headers live at the front of the file; avoid inflating whole
        // pages just to learn their size, falling back only if needed.
        $size = [0, 0];
        $stream = $zip->getStream($name);
        if ($stream) {
            $head = '';
            while (!feof($stream) && strlen($head) < 262144) {
                $chunk = fread($stream, 65536);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $head .= $chunk;
            }
            fclose($stream);
            $size = wk_image_size_bytes($head);
        }
        if ($size[0] === 0) {
            $size = wk_image_size_bytes((string) $zip->getFromName($name));
        }
        $pages[] = [$name, $size[0], $size[1]];
    }
    $zip->close();
    return $pages;
}

/** Image files under a folder, relative paths in reading (natural) order. */
function wk_dir_images(string $root): array
{
    $names = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if ($file->isLink() || !$file->isFile()) {
            continue;
        }
        $rel = substr($file->getPathname(), strlen($root) + 1);
        if (wk_is_image_name($rel)) {
            $names[] = $rel;
        }
    }
    wk_natsort($names);
    return $names;
}

function wk_dir_scan(string $root): array
{
    $pages = [];
    foreach (wk_dir_images($root) as $rel) {
        $info = @getimagesize($root . '/' . $rel);
        $pages[] = [$rel, $info ? (int) $info[0] : 0, $info ? (int) $info[1] : 0];
    }
    return $pages;
}

// ---------------------------------------------------------------------------
// External tools for RAR / 7z / TAR

function wk_tool(string $name): ?string
{
    static $found = [];
    if (array_key_exists($name, $found)) {
        return $found[$name];
    }
    foreach (explode(':', getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin') as $dir) {
        if ($dir !== '' && is_executable("$dir/$name")) {
            return $found[$name] = "$dir/$name";
        }
    }
    return $found[$name] = null;
}

function wk_run(array $cmd, ?int &$exit = null): string
{
    $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($proc)) {
        $exit = -1;
        return '';
    }
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exit = proc_close($proc);
    return $out;
}

/** bsdtar treats member names as glob patterns; escape them for exact matches. */
function wk_glob_escape(string $s): string
{
    return addcslashes($s, '\\*?[]');
}

function wk_cli_list(string $abs, string $format): array
{
    if ($bsdtar = wk_tool('bsdtar')) {
        $out = wk_run([$bsdtar, '-tf', $abs], $exit);
        if ($exit === 0) {
            return array_values(array_filter(explode("\n", $out), fn($l) => $l !== '' && !str_ends_with($l, '/')));
        }
    }
    if ($format === 'rar' && ($unrar = wk_tool('unrar'))) {
        $out = wk_run([$unrar, 'lb', '-inul', $abs], $exit);
        if ($exit === 0) {
            return array_values(array_filter(explode("\n", $out)));
        }
    }
    if ($sz = wk_tool('7z') ?? wk_tool('7za') ?? wk_tool('7zz')) {
        $out = wk_run([$sz, 'l', '-slt', '-ba', $abs], $exit);
        if ($exit === 0 && preg_match_all('/^Path = (.+)$/m', $out, $m)) {
            return $m[1];
        }
    }
    return [];
}

function wk_cli_read(string $abs, string $format, string $entry): ?string
{
    $attempts = [];
    if ($t = wk_tool('bsdtar')) {
        $attempts[] = [$t, '-xOf', $abs, wk_glob_escape($entry)];
    }
    if ($format === 'rar' && ($t = wk_tool('unrar'))) {
        $attempts[] = [$t, 'p', '-inul', $abs, $entry];
    }
    if ($t = wk_tool('7z') ?? wk_tool('7za') ?? wk_tool('7zz')) {
        $attempts[] = [$t, 'e', '-so', '-spd', $abs, $entry];
    }
    foreach ($attempts as $cmd) {
        $out = wk_run($cmd, $exit);
        if ($exit === 0 && $out !== '') {
            return $out;
        }
    }
    return null;
}

/** Extract a CLI-format archive once, returning the directory holding it. */
function wk_extract_cached(array $node, string $key, string $sig): string
{
    $base = dirname(wk_data_path("extract/$key.done"));
    $dir = "$base/$key";
    $marker = "$base/$key.done";

    if (is_file($marker) && file_get_contents($marker) === $sig && is_dir($dir)) {
        @touch($marker);
        return $dir;
    }

    $lock = fopen("$base/$key.lock", 'c');
    flock($lock, LOCK_EX);
    try {
        // Another request may have finished the job while we waited.
        if (is_file($marker) && file_get_contents($marker) === $sig && is_dir($dir)) {
            return $dir;
        }
        wk_rmtree($dir);
        mkdir($dir, 0775, true);
        $abs = $node['abs'];
        $attempts = [];
        if ($t = wk_tool('bsdtar')) {
            $attempts[] = [$t, '-x', '--no-same-owner', '-f', $abs, '-C', $dir];
        }
        if ($node['format'] === 'rar' && ($t = wk_tool('unrar'))) {
            $attempts[] = [$t, 'x', '-o+', '-inul', $abs, $dir . '/'];
        }
        if ($t = wk_tool('7z') ?? wk_tool('7za') ?? wk_tool('7zz')) {
            $attempts[] = [$t, 'x', '-y', '-o' . $dir, $abs];
        }
        if (!$attempts) {
            throw new RuntimeException('Opening ' . strtoupper($node['format']) . ' archives needs bsdtar, 7z or unrar installed');
        }
        $ok = false;
        foreach ($attempts as $cmd) {
            wk_run($cmd, $exit);
            if ($exit === 0) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            throw new RuntimeException('Unable to extract archive');
        }
        file_put_contents($marker, $sig);
        wk_extract_evict($base, $key);
        return $dir;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function wk_extract_evict(string $base, string $keep): void
{
    $limit = max(1, (int) wk_config()['extract_cache_books']);
    $markers = glob("$base/*.done") ?: [];
    usort($markers, fn($a, $b) => filemtime($b) <=> filemtime($a));
    foreach (array_slice($markers, $limit) as $marker) {
        $key = basename($marker, '.done');
        if ($key === $keep || !preg_match('/^[0-9a-f]{40}$/', $key)) {
            continue;
        }
        wk_rmtree("$base/$key");
        @unlink($marker);
        @unlink("$base/$key.lock");
    }
}

function wk_rmtree(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $f) {
        ($f->isDir() && !$f->isLink()) ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}
