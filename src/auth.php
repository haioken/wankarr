<?php
declare(strict_types=1);

/**
 * Accounts, sessions and per-user state.
 *
 * - Passwords: Argon2id (bcrypt where PHP lacks it), rehashed on login when
 *   the algorithm or its cost changes.
 * - Sessions: a random 256-bit token in an HttpOnly, SameSite=Lax cookie;
 *   the database only stores its SHA-256, so a leaked database can't be
 *   replayed as logins.
 * - Roles: "admin" manages the library, users and settings; "user" reads,
 *   searches and hides series for themselves.
 * - Visibility: per-user hidden series and an optional maximum content
 *   rating, enforced in SQL for listings and per request for pages, covers
 *   and images.
 */

const WK_ROLES = ['admin' => 'Admin', 'user' => 'User'];
const WK_USER_STATUSES = [
    'active' => 'Active',
    'pending' => 'Awaiting approval',
    'unverified' => 'Email not verified',
    'disabled' => 'Disabled',
];
const WK_RATING_ORDER = ['safe', 'suggestive', 'erotica', 'pornographic'];
const WK_SESSION_COOKIE = 'wankarr_session';

// ---------------------------------------------------------------------------
// Passwords and validation

function wk_password_hash(string $password): string
{
    return defined('PASSWORD_ARGON2ID')
        ? password_hash($password, PASSWORD_ARGON2ID)
        : password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

function wk_password_needs_rehash(string $hash): bool
{
    return defined('PASSWORD_ARGON2ID')
        ? password_needs_rehash($hash, PASSWORD_ARGON2ID)
        : password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
}

function wk_validate_password(string $password): void
{
    $len = mb_strlen($password);
    if ($len < 8) {
        throw new InvalidArgumentException('Passwords need at least 8 characters');
    }
    if ($len > 1024) {
        throw new InvalidArgumentException('That password is too long');
    }
}

function wk_validate_username(string $username): string
{
    $username = trim($username);
    if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $username)) {
        throw new InvalidArgumentException('Usernames are 3–32 letters, digits, dots, dashes or underscores');
    }
    return $username;
}

function wk_validate_email(?string $email): ?string
{
    $email = trim((string) $email);
    if ($email === '') {
        return null;
    }
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('That email address doesn\'t look right');
    }
    return $email;
}

function wk_validate_rating(?string $rating): ?string
{
    $rating = $rating === '' ? null : $rating;
    if ($rating !== null && !in_array($rating, WK_RATING_ORDER, true)) {
        throw new InvalidArgumentException('Unknown content rating');
    }
    // "Adult" (the highest rating) as a limit means no limit at all.
    return $rating === 'pornographic' ? null : $rating;
}

// ---------------------------------------------------------------------------
// Users

function wk_users_exist(): bool
{
    static $exists = null;
    return $exists ??= (bool) wk_val('SELECT 1 FROM users LIMIT 1');
}

function wk_user(int $id): ?array
{
    return wk_one('SELECT * FROM users WHERE id = ?', [$id]);
}

function wk_users(): array
{
    return wk_all('SELECT * FROM users ORDER BY role, username_key');
}

function wk_admin_count(?int $excluding = null): int
{
    return (int) wk_val("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active' AND id <> ?", [$excluding ?? 0]);
}

function wk_user_create(string $username, string $password, string $role = 'user', ?string $email = null, string $status = 'active', ?string $maxRating = null, bool $showUnrated = true): int
{
    $username = wk_validate_username($username);
    wk_validate_password($password);
    $email = wk_validate_email($email);
    if (!isset(WK_ROLES[$role]) || !isset(WK_USER_STATUSES[$status])) {
        throw new InvalidArgumentException('Bad role or status');
    }
    if (wk_val('SELECT 1 FROM users WHERE username_key = ?', [mb_strtolower($username)])) {
        throw new InvalidArgumentException('That username is taken');
    }
    if ($email !== null && wk_val('SELECT 1 FROM users WHERE email_key = ?', [mb_strtolower($email)])) {
        throw new InvalidArgumentException('That email address is already in use');
    }
    $first = !wk_users_exist();
    $id = wk_insert('users', [
        'username' => $username,
        'username_key' => mb_strtolower($username),
        'email' => $email,
        'email_key' => $email !== null ? mb_strtolower($email) : null,
        'password_hash' => wk_password_hash($password),
        'role' => $role,
        'status' => $status,
        'max_rating' => wk_validate_rating($maxRating),
        'show_unrated' => $showUnrated ? 1 : 0,
        'created_at' => time(),
    ]);
    if ($first) {
        // Series hidden before accounts existed become the first admin's hides.
        wk_q('INSERT INTO user_hidden (user_id, series_id, created_at) SELECT ?, id, ? FROM series WHERE hidden = 1', [$id, time()]);
        wk_q('UPDATE series SET hidden = 0 WHERE hidden = 1');
    }
    wk_log("auth: created $role account $username");
    return $id;
}

/** Admin edits: role, status, email, content limit. Keeps one active admin. */
function wk_user_update(int $id, array $fields): void
{
    $user = wk_user($id) ?? throw new InvalidArgumentException('User not found');
    $set = [];
    if (array_key_exists('role', $fields)) {
        if (!isset(WK_ROLES[$fields['role']])) {
            throw new InvalidArgumentException('Unknown role');
        }
        $set['role'] = $fields['role'];
    }
    if (array_key_exists('status', $fields)) {
        if (!isset(WK_USER_STATUSES[$fields['status']])) {
            throw new InvalidArgumentException('Unknown status');
        }
        $set['status'] = $fields['status'];
    }
    if (array_key_exists('email', $fields)) {
        $email = wk_validate_email($fields['email']);
        if ($email !== null && wk_val('SELECT 1 FROM users WHERE email_key = ? AND id <> ?', [mb_strtolower($email), $id])) {
            throw new InvalidArgumentException('That email address is already in use');
        }
        $set['email'] = $email;
        $set['email_key'] = $email !== null ? mb_strtolower($email) : null;
    }
    if (array_key_exists('max_rating', $fields)) {
        $set['max_rating'] = wk_validate_rating($fields['max_rating']);
    }
    if (array_key_exists('show_unrated', $fields)) {
        $set['show_unrated'] = $fields['show_unrated'] ? 1 : 0;
    }
    $staysAdmin = ($set['role'] ?? $user['role']) === 'admin' && ($set['status'] ?? $user['status']) === 'active';
    if ($user['role'] === 'admin' && !$staysAdmin && wk_admin_count($id) === 0) {
        throw new InvalidArgumentException('There must always be at least one active admin');
    }
    if ($set) {
        wk_update('users', $set, ['id' => $id]);
    }
    if (($set['status'] ?? 'active') !== 'active') {
        wk_delete('sessions', ['user_id' => $id]);
    }
}

function wk_user_set_password(int $id, string $password, ?string $keepToken = null): void
{
    wk_validate_password($password);
    wk_update('users', ['password_hash' => wk_password_hash($password)], ['id' => $id]);
    // Changing a password signs out every other session.
    wk_q('DELETE FROM sessions WHERE user_id = ? AND token_hash <> ?', [$id, $keepToken ? hash('sha256', $keepToken) : '']);
    wk_q("DELETE FROM user_tokens WHERE user_id = ? AND purpose = 'reset'", [$id]);
}

function wk_user_delete(int $id): void
{
    $user = wk_user($id) ?? throw new InvalidArgumentException('User not found');
    if ($user['role'] === 'admin' && wk_admin_count($id) === 0) {
        throw new InvalidArgumentException('You can\'t delete the last admin');
    }
    wk_delete('users', ['id' => $id]);
    wk_log("auth: deleted account {$user['username']}");
}

// ---------------------------------------------------------------------------
// Sessions

function wk_client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (wk_config()['auth']['trust_proxy'] ?? false) {
        $fwd = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
        if (filter_var($fwd, FILTER_VALIDATE_IP)) {
            $ip = $fwd;
        }
    }
    return substr($ip ?: 'unknown', 0, 64);
}

function wk_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    return (wk_config()['auth']['trust_proxy'] ?? false) && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function wk_set_session_cookie(string $token, int $expires): void
{
    setcookie(WK_SESSION_COOKIE, $token, [
        'expires' => $expires,
        'path' => '/',
        'secure' => wk_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Check credentials and start a session. Returns the user, or throws with a
 * message safe to show (it never reveals whether a username exists).
 */
function wk_login(string $login, string $password, bool $remember): array
{
    $key = mb_strtolower(trim($login));
    $ip = wk_client_ip();
    $since = time() - 900;
    $userFails = (int) wk_val('SELECT COUNT(*) FROM login_attempts WHERE username_key = ? AND success = 0 AND at > ?', [$key, $since]);
    $ipFails = (int) wk_val('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND at > ?', [$ip, $since]);
    if ($userFails >= 8 || $ipFails >= 30) {
        wk_log("auth: login throttled for '$key' from $ip");
        throw new RuntimeException('Too many failed attempts. Try again in 15 minutes.');
    }

    $user = wk_one('SELECT * FROM users WHERE username_key = ? OR email_key = ?', [$key, $key]);
    // Verify against a dummy hash for unknown users so timing doesn't reveal them.
    static $dummy = null;
    $dummy ??= wk_password_hash(bin2hex(random_bytes(16)));
    $ok = password_verify($password, $user['password_hash'] ?? $dummy) && $user !== null;

    wk_insert('login_attempts', ['username_key' => mb_substr($key, 0, 191), 'ip' => $ip, 'at' => time(), 'success' => $ok ? 1 : 0]);
    if (random_int(1, 50) === 1) {
        wk_q('DELETE FROM login_attempts WHERE at < ?', [time() - 86400]);
        wk_q('DELETE FROM sessions WHERE expires_at < ?', [time()]);
        wk_q('DELETE FROM user_tokens WHERE expires_at < ?', [time()]);
    }
    if (!$ok) {
        wk_log("auth: failed login for '$key' from $ip");
        throw new RuntimeException('Wrong username or password');
    }
    match ($user['status']) {
        'active' => null,
        'unverified' => throw new RuntimeException('Confirm your email address first: check your inbox for the link.'),
        'pending' => throw new RuntimeException('Your account is waiting for an admin to approve it.'),
        default => throw new RuntimeException('This account is disabled.'),
    };
    if (wk_password_needs_rehash($user['password_hash'])) {
        wk_update('users', ['password_hash' => wk_password_hash($password)], ['id' => $user['id']]);
    }
    wk_update('users', ['last_login_at' => time()], ['id' => $user['id']]);
    wk_session_start((int) $user['id'], $remember);
    wk_log("auth: {$user['username']} signed in from $ip");
    return $user;
}

function wk_session_start(int $userId, bool $remember): string
{
    $token = bin2hex(random_bytes(32));
    $now = time();
    $ttl = $remember ? 86400 * max(1, (int) wk_config()['auth']['session_days']) : 43200;
    wk_insert('sessions', [
        'token_hash' => hash('sha256', $token),
        'user_id' => $userId,
        'created_at' => $now,
        'last_seen_at' => $now,
        'expires_at' => $now + $ttl,
        'persistent' => $remember ? 1 : 0,
        'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        'ip' => wk_client_ip(),
    ]);
    wk_set_session_cookie($token, $remember ? $now + $ttl : 0);
    $_COOKIE[WK_SESSION_COOKIE] = $token;
    wk_auth_user(true);
    return $token;
}

/** The signed-in user for this request, or null. */
function wk_auth_user(bool $reload = false): ?array
{
    static $user = false;
    if ($user !== false && !$reload) {
        return $user;
    }
    $user = null;
    $token = (string) ($_COOKIE[WK_SESSION_COOKIE] ?? '');
    if (PHP_SAPI === 'cli' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $row = wk_one(
        "SELECT u.*, s.token_hash, s.last_seen_at, s.expires_at, s.persistent
         FROM sessions s JOIN users u ON u.id = s.user_id
         WHERE s.token_hash = ? AND s.expires_at > ? AND u.status = 'active'",
        [hash('sha256', $token), time()],
    );
    if ($row === null) {
        return null;
    }
    // Sliding expiry, written at most every 10 minutes.
    $now = time();
    if ($now - (int) $row['last_seen_at'] > 600) {
        $ttl = $row['persistent'] ? 86400 * max(1, (int) wk_config()['auth']['session_days']) : 43200;
        wk_update('sessions', ['last_seen_at' => $now, 'expires_at' => $now + $ttl], ['token_hash' => $row['token_hash']]);
        if ($row['persistent'] && !headers_sent()) {
            wk_set_session_cookie($token, $now + $ttl);
        }
    }
    unset($row['password_hash']);
    $row['prefs'] = json_decode((string) $row['prefs'], true) ?: [];
    return $user = $row;
}

function wk_logout(): void
{
    $token = (string) ($_COOKIE[WK_SESSION_COOKIE] ?? '');
    if ($token !== '') {
        wk_delete('sessions', ['token_hash' => hash('sha256', $token)]);
    }
    wk_set_session_cookie('', time() - 3600);
}

function wk_is_admin(): bool
{
    return (wk_auth_user()['role'] ?? '') === 'admin';
}

/** Page guard: first-run setup, then login, then the account. */
function wk_require_user(bool $api = false): array
{
    if (!wk_users_exist()) {
        $api ? wk_fail(401, 'Finish setting up wankarr first') : wk_redirect('setup.php');
    }
    $user = wk_auth_user();
    if ($user === null) {
        if ($api) {
            wk_json(['error' => 'Please sign in', 'login' => true], 401);
        }
        wk_redirect('login.php?next=' . rawurlencode((string) ($_SERVER['REQUEST_URI'] ?? '')));
    }
    return $user;
}

function wk_require_admin(bool $api = false): array
{
    $user = wk_require_user($api);
    if ($user['role'] !== 'admin') {
        if ($api) {
            wk_fail(403, 'Only admins can do that');
        }
        http_response_code(403);
        wk_page_start('Not allowed');
        echo '<div class="content-body"><div class="empty-state">' . wk_icon('warning') . '<h2>Admins only</h2><p>Ask an admin if you need this.</p><a class="button" href="./">Back to the library</a></div></div>';
        wk_page_end();
        exit;
    }
    return $user;
}

function wk_redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

/** A same-site path to return to after login (never another host). */
function wk_safe_next(?string $next): string
{
    $next = (string) $next;
    if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//') || str_contains($next, '\\') || preg_match('/[\x00-\x1F]/', $next)) {
        return './';
    }
    return $next;
}

// ---------------------------------------------------------------------------
// One-time tokens (email verification, password reset)

function wk_token_create(int $userId, string $purpose, int $ttl): string
{
    $token = bin2hex(random_bytes(32));
    wk_q('DELETE FROM user_tokens WHERE user_id = ? AND purpose = ?', [$userId, $purpose]);
    wk_insert('user_tokens', [
        'token_hash' => hash('sha256', $token), 'user_id' => $userId, 'purpose' => $purpose,
        'created_at' => time(), 'expires_at' => time() + $ttl,
    ]);
    return $token;
}

/** Returns the user id for a valid token (and burns it), or null. */
function wk_token_consume(string $token, string $purpose, bool $burn = true): ?int
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $hash = hash('sha256', $token);
    $uid = wk_val('SELECT user_id FROM user_tokens WHERE token_hash = ? AND purpose = ? AND expires_at > ?', [$hash, $purpose, time()]);
    if ($uid && $burn) {
        wk_delete('user_tokens', ['token_hash' => $hash]);
    }
    return $uid ? (int) $uid : null;
}

// ---------------------------------------------------------------------------
// Signup and password reset

function wk_signup_enabled(): bool
{
    return (bool) (wk_config()['auth']['signup'] ?? false);
}

/** Email links need SMTP and a configured public address. */
function wk_email_ready(): bool
{
    return wk_mail_configured() && !empty(wk_config()['auth']['base_url']);
}

function wk_public_url(string $path): string
{
    return rtrim((string) wk_config()['auth']['base_url'], '/') . '/' . ltrim($path, '/');
}

/** Returns a message for the person signing up. */
function wk_signup(string $username, string $email, string $password): string
{
    if (!wk_signup_enabled()) {
        throw new InvalidArgumentException('Sign-ups are turned off');
    }
    $email = wk_validate_email($email) ?? throw new InvalidArgumentException('An email address is required');
    $verify = wk_email_ready();
    // A limited account hides unrated series too, until an admin says otherwise.
    $limit = wk_validate_rating(wk_config()['auth']['signup_max_rating'] ?? null);
    $id = wk_user_create($username, $password, 'user', $email, $verify ? 'unverified' : 'pending', $limit, $limit === null);
    if (!$verify) {
        return 'Account created. An admin needs to approve it before you can sign in.';
    }
    $token = wk_token_create($id, 'verify', 86400);
    try {
        wk_mail_send($email, 'Confirm your wankarr account', implode("\n", [
            "Hi $username,",
            '',
            'Confirm your email address to finish creating your wankarr account:',
            wk_public_url('verify.php?token=' . $token),
            '',
            'The link works for 24 hours. If you didn\'t sign up, ignore this email.',
        ]));
    } catch (Throwable $e) {
        wk_delete('users', ['id' => $id]);
        wk_log('mail: verification email failed: ' . $e->getMessage());
        throw new RuntimeException('Couldn\'t send the confirmation email. Please try again later.');
    }
    return 'Almost done: we sent a confirmation link to ' . $email . '.';
}

/** Always "succeeds" so the response never reveals which emails exist. */
function wk_password_reset_request(string $email): void
{
    if (!wk_email_ready()) {
        throw new RuntimeException('Password reset by email isn\'t set up on this server. Ask an admin.');
    }
    $user = wk_one("SELECT * FROM users WHERE email_key = ? AND status IN ('active', 'unverified')", [mb_strtolower(trim($email))]);
    if (!$user) {
        return;
    }
    // At most one reset email per account every five minutes.
    if (wk_val("SELECT 1 FROM user_tokens WHERE user_id = ? AND purpose = 'reset' AND created_at > ?", [$user['id'], time() - 300])) {
        return;
    }
    $token = wk_token_create((int) $user['id'], 'reset', 3600);
    try {
        wk_mail_send((string) $user['email'], 'Reset your wankarr password', implode("\n", [
            "Hi {$user['username']},",
            '',
            'Use this link to choose a new password:',
            wk_public_url('reset.php?token=' . $token),
            '',
            'The link works for one hour. If you didn\'t ask for this, ignore this email.',
        ]));
    } catch (Throwable $e) {
        wk_log('mail: reset email failed: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Per-user visibility

/** Ratings the user may see, or null for no limit. */
function wk_allowed_ratings(?array $user): ?array
{
    $max = $user['max_rating'] ?? null;
    if ($max === null || !in_array($max, WK_RATING_ORDER, true)) {
        return null;
    }
    return array_slice(WK_RATING_ORDER, 0, array_search($max, WK_RATING_ORDER, true) + 1);
}

/**
 * SQL fragment limiting a series alias to what the current user may see.
 * $hidden: false = hide their hidden series, true = only those, null = ignore.
 */
function wk_visibility_sql(string $a = 's', ?bool $hidden = false): array
{
    $user = wk_auth_user();
    if ($user === null) {
        return ['1 = 1', []]; // CLI / background tasks
    }
    $where = [];
    $params = [];
    if ($hidden !== null) {
        $where[] = ($hidden ? '' : 'NOT ') . "EXISTS (SELECT 1 FROM user_hidden uh WHERE uh.user_id = ? AND uh.series_id = $a.id)";
        $params[] = (int) $user['id'];
    }
    if ($allowed = wk_allowed_ratings($user)) {
        $rated = "EXISTS (SELECT 1 FROM metadata mr WHERE mr.id = $a.metadata_id AND mr.content_rating IN (" . wk_in($allowed) . '))';
        $unrated = "NOT EXISTS (SELECT 1 FROM metadata mu WHERE mu.id = $a.metadata_id AND mu.content_rating IS NOT NULL)";
        $where[] = $user['show_unrated'] ? "($rated OR $unrated)" : $rated;
        array_push($params, ...$allowed);
    }
    return [$where ? implode(' AND ', $where) : '1 = 1', $params];
}

/** May the current user open this series at all (hidden ones included)? */
function wk_series_accessible(int $seriesId): bool
{
    [$sql, $params] = wk_visibility_sql('s', null);
    return (bool) wk_val("SELECT 1 FROM series s WHERE s.id = ? AND $sql", [$seriesId, ...$params]);
}

function wk_hide_series(int $userId, array $seriesIds, bool $hide): int
{
    $n = 0;
    foreach (array_unique(array_map('intval', $seriesIds)) as $sid) {
        if ($hide) {
            wk_upsert('user_hidden', ['user_id' => $userId, 'series_id' => $sid, 'created_at' => time()], ['user_id', 'series_id']);
        } else {
            wk_delete('user_hidden', ['user_id' => $userId, 'series_id' => $sid]);
        }
        $n++;
    }
    return $n;
}

function wk_is_hidden(int $userId, int $seriesId): bool
{
    return (bool) wk_val('SELECT 1 FROM user_hidden WHERE user_id = ? AND series_id = ?', [$userId, $seriesId]);
}

// ---------------------------------------------------------------------------
// Reading progress and reader preferences

function wk_progress_for_books(int $userId, array $bookIds): array
{
    $out = [];
    foreach (array_chunk(array_values(array_unique(array_map('intval', $bookIds))), 500) as $ids) {
        foreach (wk_all('SELECT book_id, page, total, cover_alone, updated_at FROM user_progress WHERE user_id = ? AND book_id IN (' . wk_in($ids) . ')', [$userId, ...$ids]) as $r) {
            $out[(int) $r['book_id']] = $r;
        }
    }
    return $out;
}

function wk_progress_save(int $userId, int $bookId, int $page, int $total, ?bool $coverAlone = null, ?int $at = null): void
{
    $row = ['user_id' => $userId, 'book_id' => $bookId, 'page' => max(0, $page), 'total' => max(0, $total), 'updated_at' => $at ?? time()];
    if ($coverAlone !== null) {
        $row['cover_alone'] = $coverAlone ? 1 : 0;
    }
    wk_upsert('user_progress', $row, ['user_id', 'book_id']);
}

/** Reading progress per series: [series_id => ['fraction' => 0–1, 'finished' => bool]]. */
function wk_series_progress(int $userId, array $bookIdsBySeries): array
{
    $progress = wk_progress_for_books($userId, array_merge([], ...array_values($bookIdsBySeries)));
    $out = [];
    foreach ($bookIdsBySeries as $sid => $ids) {
        $sum = 0.0;
        $done = 0;
        $any = false;
        foreach ($ids as $id) {
            $p = $progress[$id] ?? null;
            if (!$p || !$p['total']) {
                continue;
            }
            $any = true;
            $f = min(1, ($p['page'] + 1) / $p['total']);
            $sum += $f;
            $done += $f >= 1 ? 1 : 0;
        }
        if ($any) {
            $out[$sid] = ['fraction' => $sum / count($ids), 'finished' => $done === count($ids)];
        }
    }
    return $out;
}

function wk_user_save_prefs(int $userId, array $prefs): array
{
    $user = wk_user($userId);
    $current = json_decode((string) ($user['prefs'] ?? ''), true) ?: [];
    $clean = array_filter([
        'direction' => in_array($prefs['direction'] ?? null, ['rtl', 'ltr'], true) ? $prefs['direction'] : null,
        'layout' => in_array($prefs['layout'] ?? null, ['auto', 'single', 'double'], true) ? $prefs['layout'] : null,
    ]);
    $merged = array_merge($current, $clean);
    wk_update('users', ['prefs' => json_encode($merged)], ['id' => $userId]);
    return $merged;
}

/** "Firefox on Linux" from a User-Agent string, for the sessions list. */
function wk_user_agent_label(string $ua): string
{
    $browser = match (true) {
        str_contains($ua, 'Firefox/') => 'Firefox',
        str_contains($ua, 'Edg/') => 'Edge',
        str_contains($ua, 'OPR/') => 'Opera',
        str_contains($ua, 'Chrome/') || str_contains($ua, 'Chromium/') => 'Chrome',
        str_contains($ua, 'Safari/') => 'Safari',
        $ua === '' => 'Unknown',
        default => 'Browser',
    };
    $os = match (true) {
        str_contains($ua, 'Android') => 'Android',
        str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
        str_contains($ua, 'Windows') => 'Windows',
        str_contains($ua, 'Mac OS') => 'macOS',
        str_contains($ua, 'Linux') => 'Linux',
        default => null,
    };
    return $os ? "$browser on $os" : $browser;
}
