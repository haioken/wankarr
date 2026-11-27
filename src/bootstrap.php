<?php
declare(strict_types=1);

const WK_ROOT = __DIR__ . '/..';
// Release builds stamp VERSION; anything else reports what's in the checkout.
define('WK_VERSION', trim((string) @file_get_contents(WK_ROOT . '/VERSION')) ?: '0.0.0-dev');
const WK_IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp'];
const WK_ARCHIVE_EXT = ['cbz', 'zip', 'cbr', 'rar', 'cb7', '7z', 'cbt', 'tar'];

require __DIR__ . '/http.php';
require __DIR__ . '/db.php';
require __DIR__ . '/text.php';
require __DIR__ . '/library.php';
require __DIR__ . '/archive.php';
require __DIR__ . '/catalog.php';
require __DIR__ . '/scanner.php';
require __DIR__ . '/metadata.php';
require __DIR__ . '/tasks.php';
require __DIR__ . '/naming.php';
require __DIR__ . '/organize.php';
require __DIR__ . '/import.php';
require __DIR__ . '/mail.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/layout.php';

function wk_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $config = require WK_ROOT . '/config.php';
    // Overrides: config.local.php next to the app, then WANKARR_CONFIG (e.g.
    // /config/config.php in the container) — both optional, partial arrays.
    foreach (array_filter([WK_ROOT . '/config.local.php', getenv('WANKARR_CONFIG') ?: null]) as $file) {
        if (!is_file($file)) {
            continue;
        }
        $local = require $file;
        if (is_array($local)) {
            // Lists (libraries) are replaced wholesale; maps are merged.
            foreach ($local as $k => $v) {
                $config[$k] = (is_array($v) && is_array($config[$k] ?? null) && !array_is_list($v))
                    ? array_replace_recursive($config[$k], $v)
                    : $v;
            }
        }
    }

    $env = getenv('WANKARR_LIBRARIES');
    if ($env) {
        $config['libraries'] = [];
        foreach (array_filter(array_map('trim', explode(';', $env))) as $pair) {
            [$name, $path] = array_pad(explode('=', $pair, 2), 2, null);
            if ($path === null) {
                [$name, $path] = [basename($name), $name];
            }
            $config['libraries'][] = ['name' => $name, 'path' => $path];
        }
    }
    return $config;
}

/** Absolute path inside the data dir, creating parent directories on demand. */
function wk_data_path(string $rel): string
{
    $base = rtrim(wk_config()['data_dir'], '/');
    $path = $base . '/' . ltrim($rel, '/');
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $path;
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function wk_ext(string $path): string
{
    return strtolower(pathinfo($path, PATHINFO_EXTENSION));
}

function wk_is_image_name(string $name): bool
{
    $base = basename($name);
    if ($base === '' || $base[0] === '.' || str_contains($name, '__MACOSX/')) {
        return false;
    }
    return in_array(wk_ext($name), WK_IMAGE_EXT, true);
}

function wk_natsort(array &$names): void
{
    usort($names, 'strnatcasecmp');
}

function wk_int(mixed $v): ?int
{
    $i = filter_var($v, FILTER_VALIDATE_INT);
    return $i === false ? null : $i;
}
