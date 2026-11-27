<?php
declare(strict_types=1);

/**
 * Database access: a PDO singleton, tiny query helpers and versioned
 * migrations. MariaDB/MySQL and SQLite are both supported; driver
 * differences are confined to wk_db_types() and wk_upsert().
 */

function wk_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $c = wk_config()['db'];
    $dsn = (string) $c['dsn'];
    if (str_starts_with($dsn, 'sqlite:')) {
        $file = substr($dsn, 7);
        if ($file !== ':memory:' && !is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
    }
    $pdo = new PDO($dsn, $c['user'] ?? null, $c['pass'] ?? null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    if (wk_db_driver($pdo) === 'sqlite') {
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
    }
    wk_db_migrate($pdo);
    return $pdo;
}

function wk_db_driver(?PDO $pdo = null): string
{
    return ($pdo ?? wk_db())->getAttribute(PDO::ATTR_DRIVER_NAME);
}

function wk_q(string $sql, array $params = []): PDOStatement
{
    $st = wk_db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function wk_all(string $sql, array $params = []): array
{
    return wk_q($sql, $params)->fetchAll();
}

function wk_one(string $sql, array $params = []): ?array
{
    $row = wk_q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function wk_val(string $sql, array $params = []): mixed
{
    $v = wk_q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function wk_col(string $sql, array $params = []): array
{
    return wk_q($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
}

function wk_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    wk_q(
        "INSERT INTO $table (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')',
        array_values($data),
    );
    return (int) wk_db()->lastInsertId();
}

function wk_update(string $table, array $data, array $where): int
{
    $set = implode(',', array_map(fn($c) => "$c = ?", array_keys($data)));
    $cond = implode(' AND ', array_map(fn($c) => "$c = ?", array_keys($where)));
    return wk_q("UPDATE $table SET $set WHERE $cond", [...array_values($data), ...array_values($where)])->rowCount();
}

function wk_delete(string $table, array $where): int
{
    $cond = implode(' AND ', array_map(fn($c) => "$c = ?", array_keys($where)));
    return wk_q("DELETE FROM $table WHERE $cond", array_values($where))->rowCount();
}

/** Insert or update on a unique key; returns nothing (look the row up after). */
function wk_upsert(string $table, array $data, array $keys): void
{
    $cols = array_keys($data);
    $place = implode(',', array_fill(0, count($cols), '?'));
    $update = array_values(array_diff($cols, $keys));
    if (wk_db_driver() === 'sqlite') {
        $set = $update ? 'DO UPDATE SET ' . implode(',', array_map(fn($c) => "$c = excluded.$c", $update)) : 'DO NOTHING';
        $sql = "INSERT INTO $table (" . implode(',', $cols) . ") VALUES ($place) ON CONFLICT(" . implode(',', $keys) . ") $set";
    } else {
        $set = $update ? implode(',', array_map(fn($c) => "$c = VALUES($c)", $update)) : "{$keys[0]} = {$keys[0]}";
        $sql = "INSERT INTO $table (" . implode(',', $cols) . ") VALUES ($place) ON DUPLICATE KEY UPDATE $set";
    }
    wk_q($sql, array_values($data));
}

function wk_tx(callable $fn): mixed
{
    $db = wk_db();
    if ($db->inTransaction()) {
        return $fn();
    }
    $db->beginTransaction();
    try {
        $result = $fn();
        $db->commit();
        return $result;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/** "?, ?, ?" for an IN () list. */
function wk_in(array $values): string
{
    return implode(',', array_fill(0, max(1, count($values)), '?'));
}

function wk_setting(string $key, ?string $default = null): ?string
{
    return wk_val('SELECT v FROM wk_meta WHERE k = ?', [$key]) ?? $default;
}

function wk_set_setting(string $key, string $value): void
{
    wk_upsert('wk_meta', ['k' => $key, 'v' => $value], ['k']);
}

// ---------------------------------------------------------------------------
// Schema

function wk_db_types(string $driver): array
{
    return $driver === 'sqlite'
        ? ['pk' => 'INTEGER PRIMARY KEY AUTOINCREMENT', 'ref' => 'INTEGER', 'int' => 'INTEGER', 'big' => 'INTEGER',
           'str' => 'TEXT', 'key' => 'TEXT', 'text' => 'TEXT', 'real' => 'REAL', 'suffix' => '']
        : ['pk' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', 'ref' => 'INT UNSIGNED', 'int' => 'INT', 'big' => 'BIGINT',
           'str' => 'VARCHAR(255)', 'key' => 'VARCHAR(191)', 'text' => 'MEDIUMTEXT', 'real' => 'DOUBLE',
           'suffix' => ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'];
}

/** Ordered migrations; each returns a list of SQL statements. Never edit a released one. */
function wk_db_migrations(): array
{
    return [
        1 => function (array $t): array {
            ['pk' => $pk, 'ref' => $ref, 'int' => $int, 'big' => $big, 'str' => $str, 'key' => $key, 'text' => $text, 'real' => $real, 'suffix' => $sfx] = $t;
            return [
                "CREATE TABLE libraries (
                    id $pk, name $str NOT NULL, path $text NOT NULL, path_hash CHAR(40) NOT NULL,
                    created_at $big NOT NULL
                )$sfx",
                'CREATE UNIQUE INDEX ux_libraries_path ON libraries (path_hash)',

                "CREATE TABLE metadata (
                    id $pk, provider VARCHAR(32) NOT NULL, provider_id VARCHAR(64) NOT NULL,
                    title $str NOT NULL, title_english $str NULL, title_japanese $str NULL, titles $text NULL,
                    type VARCHAR(32) NULL, status VARCHAR(32) NULL, synopsis $text NULL, background $text NULL,
                    start_date VARCHAR(10) NULL, end_date VARCHAR(10) NULL, year $int NULL,
                    volumes $int NULL, chapters $int NULL, score $real NULL, scored_by $int NULL,
                    rank_overall $int NULL, popularity $int NULL, members $int NULL,
                    content_rating VARCHAR(16) NULL, content_rating_source VARCHAR(16) NULL,
                    url $str NULL, cover_url $str NULL, links $text NULL, serializations $text NULL,
                    fetched_at $big NOT NULL
                )$sfx",
                'CREATE UNIQUE INDEX ux_metadata_provider ON metadata (provider, provider_id)',

                "CREATE TABLE people (
                    id $pk, provider VARCHAR(32) NOT NULL, provider_id VARCHAR(64) NOT NULL,
                    name $str NOT NULL, url $str NULL
                )$sfx",
                'CREATE UNIQUE INDEX ux_people_provider ON people (provider, provider_id)',

                "CREATE TABLE metadata_people (
                    metadata_id $ref NOT NULL REFERENCES metadata(id) ON DELETE CASCADE,
                    person_id $ref NOT NULL REFERENCES people(id) ON DELETE CASCADE,
                    role VARCHAR(64) NOT NULL, position $int NOT NULL DEFAULT 0,
                    PRIMARY KEY (metadata_id, person_id, role)
                )$sfx",
                'CREATE INDEX ix_metadata_people_person ON metadata_people (person_id)',

                "CREATE TABLE genres (id $pk, kind VARCHAR(16) NOT NULL, name VARCHAR(100) NOT NULL)$sfx",
                'CREATE UNIQUE INDEX ux_genres_name ON genres (kind, name)',

                "CREATE TABLE metadata_genres (
                    metadata_id $ref NOT NULL REFERENCES metadata(id) ON DELETE CASCADE,
                    genre_id $ref NOT NULL REFERENCES genres(id) ON DELETE CASCADE,
                    PRIMARY KEY (metadata_id, genre_id)
                )$sfx",
                'CREATE INDEX ix_metadata_genres_genre ON metadata_genres (genre_id)',

                "CREATE TABLE series (
                    id $pk, library_id $ref NOT NULL REFERENCES libraries(id) ON DELETE CASCADE,
                    title $str NOT NULL, sort_title $str NOT NULL, title_locked $int NOT NULL DEFAULT 0,
                    folder $text NULL, year $int NULL,
                    metadata_id $ref NULL REFERENCES metadata(id) ON DELETE SET NULL,
                    match_status VARCHAR(16) NOT NULL DEFAULT 'pending', match_score $real NULL,
                    match_attempted_at $big NULL, search_text $text NULL,
                    added_at $big NOT NULL, updated_at $big NOT NULL
                )$sfx",
                'CREATE INDEX ix_series_library ON series (library_id)',
                'CREATE INDEX ix_series_sort ON series (sort_title)',
                'CREATE INDEX ix_series_match ON series (match_status)',

                // Every grouping key that resolves to a series (merges add aliases).
                "CREATE TABLE series_keys (
                    key_hash CHAR(40) NOT NULL PRIMARY KEY,
                    series_id $ref NOT NULL REFERENCES series(id) ON DELETE CASCADE,
                    match_key $key NOT NULL
                )$sfx",
                'CREATE INDEX ix_series_keys_series ON series_keys (series_id)',

                "CREATE TABLE books (
                    id $pk, library_id $ref NOT NULL REFERENCES libraries(id) ON DELETE CASCADE,
                    series_id $ref NOT NULL REFERENCES series(id) ON DELETE CASCADE,
                    rel_path $text NOT NULL, path_hash CHAR(40) NOT NULL, format VARCHAR(8) NOT NULL,
                    file_size $big NOT NULL DEFAULT 0, file_mtime $big NOT NULL DEFAULT 0,
                    title $str NOT NULL, volume $real NULL, chapter $real NULL, page_count $int NULL,
                    added_at $big NOT NULL, updated_at $big NOT NULL, seen_at $big NOT NULL
                )$sfx",
                'CREATE UNIQUE INDEX ux_books_path ON books (path_hash)',
                'CREATE INDEX ix_books_series ON books (series_id)',

                "CREATE TABLE tasks (
                    name VARCHAR(32) NOT NULL PRIMARY KEY, interval_minutes $int NOT NULL,
                    status VARCHAR(16) NOT NULL DEFAULT 'idle', message $str NULL,
                    last_started $big NULL, last_finished $big NULL, last_duration $real NULL,
                    last_error $text NULL, next_run $big NOT NULL DEFAULT 0
                )$sfx",
            ];
        },
        2 => fn(array $t): array => [
            // Hidden series stay in the DB (and keep receiving scanned volumes)
            // but are left out of listings and search.
            "ALTER TABLE series ADD COLUMN hidden {$t['int']} NOT NULL DEFAULT 0",
        ],
        3 => function (array $t): array {
            ['pk' => $pk, 'ref' => $ref, 'int' => $int, 'big' => $big, 'str' => $str, 'key' => $key, 'text' => $text, 'suffix' => $sfx] = $t;
            return [
                // Accounts. username_key is the lower-cased username (unique).
                "CREATE TABLE users (
                    id $pk, username $str NOT NULL, username_key $key NOT NULL, email $str NULL, email_key $key NULL,
                    password_hash $str NOT NULL, role VARCHAR(16) NOT NULL DEFAULT 'user',
                    status VARCHAR(16) NOT NULL DEFAULT 'active',
                    max_rating VARCHAR(16) NULL, show_unrated $int NOT NULL DEFAULT 1,
                    prefs $text NULL, created_at $big NOT NULL, last_login_at $big NULL
                )$sfx",
                'CREATE UNIQUE INDEX ux_users_username ON users (username_key)',
                'CREATE UNIQUE INDEX ux_users_email ON users (email_key)',

                // Login sessions; only a SHA-256 of the cookie token is stored.
                "CREATE TABLE sessions (
                    token_hash CHAR(64) NOT NULL PRIMARY KEY,
                    user_id $ref NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    created_at $big NOT NULL, last_seen_at $big NOT NULL, expires_at $big NOT NULL,
                    persistent $int NOT NULL DEFAULT 1, user_agent $str NULL, ip VARCHAR(64) NULL
                )$sfx",
                'CREATE INDEX ix_sessions_user ON sessions (user_id)',

                // One-time tokens for email verification and password reset.
                "CREATE TABLE user_tokens (
                    token_hash CHAR(64) NOT NULL PRIMARY KEY,
                    user_id $ref NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    purpose VARCHAR(16) NOT NULL, created_at $big NOT NULL, expires_at $big NOT NULL
                )$sfx",
                'CREATE INDEX ix_user_tokens_user ON user_tokens (user_id)',

                "CREATE TABLE login_attempts (id $pk, username_key $key NOT NULL, ip VARCHAR(64) NOT NULL, at $big NOT NULL, success $int NOT NULL)$sfx",
                'CREATE INDEX ix_login_attempts_at ON login_attempts (at)',

                // Per-user reading state.
                "CREATE TABLE user_progress (
                    user_id $ref NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    book_id $ref NOT NULL REFERENCES books(id) ON DELETE CASCADE,
                    page $int NOT NULL DEFAULT 0, total $int NOT NULL DEFAULT 0, cover_alone $int NULL,
                    updated_at $big NOT NULL,
                    PRIMARY KEY (user_id, book_id)
                )$sfx",
                'CREATE INDEX ix_user_progress_book ON user_progress (book_id)',
                'CREATE INDEX ix_user_progress_recent ON user_progress (user_id, updated_at)',

                "CREATE TABLE user_hidden (
                    user_id $ref NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    series_id $ref NOT NULL REFERENCES series(id) ON DELETE CASCADE,
                    created_at $big NOT NULL,
                    PRIMARY KEY (user_id, series_id)
                )$sfx",
                'CREATE INDEX ix_user_hidden_series ON user_hidden (series_id)',
            ];
        },
    ];
}

function wk_db_migrate(PDO $pdo): void
{
    $driver = wk_db_driver($pdo);
    $t = wk_db_types($driver);
    $pdo->exec("CREATE TABLE IF NOT EXISTS wk_meta (k VARCHAR(64) NOT NULL PRIMARY KEY, v {$t['text']} NULL){$t['suffix']}");
    $current = (int) ($pdo->query("SELECT v FROM wk_meta WHERE k = 'schema_version'")->fetchColumn() ?: 0);
    $migrations = wk_db_migrations();
    if ($current >= max(array_keys($migrations))) {
        return;
    }

    // Serialise concurrent first requests so only one runs the migrations.
    $lock = fopen(wk_data_path('locks/migrate.lock'), 'c');
    flock($lock, LOCK_EX);
    try {
        $current = (int) ($pdo->query("SELECT v FROM wk_meta WHERE k = 'schema_version'")->fetchColumn() ?: 0);
        foreach ($migrations as $version => $migration) {
            if ($version <= $current) {
                continue;
            }
            // MySQL DDL auto-commits, so each statement stands alone.
            foreach ($migration($t) as $sql) {
                $pdo->exec($sql);
            }
            $st = $pdo->prepare($driver === 'sqlite'
                ? "INSERT INTO wk_meta (k, v) VALUES ('schema_version', ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v"
                : "INSERT INTO wk_meta (k, v) VALUES ('schema_version', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)");
            $st->execute([(string) $version]);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
