<?php
/**
 * wankarr configuration.
 *
 * Every value can be overridden with an environment variable so the app
 * drops neatly into a container next to the rest of an *arr stack.
 * Machine-specific secrets belong in config.local.php (git-ignored), which
 * returns a partial array merged over this one.
 */
return [
    // One or more library roots. Each top-level folder inside a root is a
    // series; loose archives at the root are grouped by their parsed name.
    // WANKARR_LIBRARIES="Manga=/data/manga;Comics=/data/comics" overrides this.
    'libraries' => [
        ['name' => 'Manga', 'path' => getenv('WANKARR_LIBRARY') ?: __DIR__ . '/library'],
    ],

    // Any PDO DSN. MariaDB/MySQL is the default; SQLite also works
    // (e.g. "sqlite:/data/wankarr.db") when pdo_sqlite is installed.
    'db' => [
        'dsn' => getenv('WANKARR_DB_DSN') ?: 'mysql:host=localhost;dbname=wankarr;charset=utf8mb4',
        'user' => getenv('WANKARR_DB_USER') ?: 'wankarr',
        'pass' => getenv('WANKARR_DB_PASS') ?: '',
    ],

    // Writable directory for page, cover and extraction caches.
    'data_dir' => getenv('WANKARR_DATA') ?: __DIR__ . '/data',

    // How many CBR/CB7/CBT archives to keep extracted on disk at once.
    'extract_cache_books' => (int) (getenv('WANKARR_EXTRACT_CACHE') ?: 8),

    // Default reading direction for new books: 'rtl' (manga) or 'ltr'.
    'default_direction' => getenv('WANKARR_DIRECTION') ?: 'rtl',

    // Scheduled task intervals, in minutes.
    'tasks' => [
        'scan' => (int) (getenv('WANKARR_SCAN_INTERVAL') ?: 360),
        'metadata' => (int) (getenv('WANKARR_METADATA_INTERVAL') ?: 720),
    ],

    'metadata' => [
        // Display titles from metadata: 'english' (falls back to romaji) or 'romaji'.
        'title_language' => 'english',
        // Match confidence (0–1) needed to accept a search result automatically.
        'auto_match' => 0.86,
        // Re-fetch matched metadata (scores, status) after this many days.
        'refresh_days' => 14,
        // Retry unmatched series after this many days.
        'retry_unmatched_days' => 7,
        // Max series processed per metadata task run.
        'batch' => 150,
    ],

    // CLI PHP used to run background tasks. Detected automatically; set it
    // when running under php-fpm with php in an unusual location.
    'php_binary' => getenv('WANKARR_PHP') ?: null,
];
