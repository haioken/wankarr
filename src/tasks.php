<?php
declare(strict_types=1);

/**
 * Scheduled tasks, Sonarr-style.
 *
 * Tasks run in a separate CLI process (bin/wankarr), never inside a web
 * request. That process is started by cron/systemd/`wankarr worker`, or, as
 * a fallback, spawned in the background by page views when a task is overdue
 * — so a plain web-server install still scans on schedule. Each task holds
 * an flock while running, so duplicate runners are harmless.
 */

function wk_task_defs(): array
{
    $iv = wk_config()['tasks'];
    return [
        'scan' => ['label' => 'Library Scan', 'interval' => (int) $iv['scan'], 'run' => fn($p) => wk_scan(null, $p)],
        'metadata' => ['label' => 'Refresh Metadata', 'interval' => (int) $iv['metadata'], 'run' => fn($p) => wk_metadata_task($p)],
    ];
}

function wk_tasks_sync(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    foreach (wk_task_defs() as $name => $def) {
        $row = wk_one('SELECT interval_minutes FROM tasks WHERE name = ?', [$name]);
        if ($row === null) {
            wk_insert('tasks', ['name' => $name, 'interval_minutes' => $def['interval'], 'status' => 'idle', 'next_run' => 0]);
        } elseif ((int) $row['interval_minutes'] !== $def['interval']) {
            wk_update('tasks', ['interval_minutes' => $def['interval']], ['name' => $name]);
        }
    }
}

/** Non-blocking exclusive lock for a task; null when already held. */
function wk_task_lock(string $name)
{
    $fh = fopen(wk_data_path("locks/task-$name.lock"), 'c');
    if ($fh && flock($fh, LOCK_EX | LOCK_NB)) {
        return $fh;
    }
    if ($fh) {
        fclose($fh);
    }
    return null;
}

function wk_task_is_locked(string $name): bool
{
    $fh = wk_task_lock($name);
    if ($fh === null) {
        return true;
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return false;
}

/** Run one task now in this process. Returns false if it was already running. */
function wk_task_run(string $name): bool
{
    wk_tasks_sync();
    $def = wk_task_defs()[$name] ?? throw new InvalidArgumentException("Unknown task $name");
    $lock = wk_task_lock($name);
    if ($lock === null) {
        return false;
    }
    $started = microtime(true);
    wk_update('tasks', ['status' => 'running', 'message' => 'Starting…', 'last_started' => time(), 'last_error' => null], ['name' => $name]);
    wk_log("task: {$def['label']} started");
    $lastWrite = 0.0;
    $progress = function (string $msg) use ($name, &$lastWrite) {
        if (microtime(true) - $lastWrite >= 0.75) {
            $lastWrite = microtime(true);
            wk_update('tasks', ['message' => mb_substr($msg, 0, 255)], ['name' => $name]);
        }
    };
    $error = null;
    $summary = '';
    try {
        $result = ($def['run'])($progress);
        $summary = wk_task_summary($name, (array) $result);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        wk_log("task: {$def['label']} failed: $error");
    } finally {
        $now = time();
        $due = (int) wk_val('SELECT next_run FROM tasks WHERE name = ?', [$name]);
        wk_update('tasks', [
            'status' => 'idle',
            'message' => mb_substr($error ? "Failed: $error" : $summary, 0, 255),
            'last_error' => $error,
            'last_finished' => $now,
            'last_duration' => round(microtime(true) - $started, 2),
            // Keep an earlier "run again soon" request made while we ran.
            'next_run' => ($due !== 0 && $due <= $now && $due > (int) $started) ? $due : $now + $def['interval'] * 60,
        ], ['name' => $name]);
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    wk_log("task: {$def['label']} finished — " . ($error ?? $summary));
    return true;
}

function wk_task_summary(string $name, array $r): string
{
    return match ($name) {
        'scan' => sprintf('%d volumes · %d added, %d updated, %d removed', $r['books'] ?? 0, $r['added'] ?? 0, $r['updated'] ?? 0, $r['removed'] ?? 0),
        'metadata' => ($r['matched'] ?? 0) + ($r['unmatched'] ?? 0) + ($r['refreshed'] ?? 0) + ($r['failed'] ?? 0) === 0
            ? 'Nothing to do'
            : sprintf('%d matched, %d need review, %d refreshed, %d failed', $r['matched'] ?? 0, $r['unmatched'] ?? 0, $r['refreshed'] ?? 0, $r['failed'] ?? 0),
        default => 'Done',
    };
}

/** Mark a task to run at the next opportunity. */
function wk_task_make_due(string $name): void
{
    wk_tasks_sync();
    wk_update('tasks', ['next_run' => time()], ['name' => $name]);
}

/** Run everything that's due, repeating while tasks queue follow-up work. */
function wk_tasks_run_due(): int
{
    wk_tasks_sync();
    $ran = 0;
    for ($round = 0; $round < 20; $round++) {
        $due = wk_col('SELECT name FROM tasks WHERE next_run <= ? ORDER BY CASE name WHEN \'scan\' THEN 0 ELSE 1 END', [time()]);
        $progressed = false;
        foreach ($due as $name) {
            if (isset(wk_task_defs()[$name]) && wk_task_run($name)) {
                $ran++;
                $progressed = true;
            }
        }
        if (!$progressed) {
            break;
        }
    }
    return $ran;
}

/** Task rows for display, with crashed runs repaired. */
function wk_tasks_state(): array
{
    wk_tasks_sync();
    $defs = wk_task_defs();
    $rows = [];
    foreach (wk_all('SELECT * FROM tasks') as $row) {
        if (!isset($defs[$row['name']])) {
            continue;
        }
        $running = wk_task_is_locked($row['name']);
        if ($row['status'] === 'running' && !$running) {
            // The runner died mid-task (killed, crashed, server restart).
            wk_update('tasks', ['status' => 'idle', 'message' => 'Interrupted'], ['name' => $row['name']]);
            $row['status'] = 'idle';
            $row['message'] = 'Interrupted';
        }
        $row['label'] = $defs[$row['name']]['label'];
        $row['running'] = $running;
        $row['queued'] = !$running && (int) $row['next_run'] <= time();
        $rows[$row['name']] = $row;
    }
    return array_values(array_replace(array_intersect_key(array_flip(array_keys($defs)), $rows), $rows));
}

/** Queue a task and start a background runner for it. */
function wk_task_queue(string $name): void
{
    if (!isset(wk_task_defs()[$name])) {
        throw new InvalidArgumentException("Unknown task $name");
    }
    wk_task_make_due($name);
    wk_spawn_runner();
}

/**
 * Called on page views: start a background runner when something is overdue.
 * Rate-limited so busy pages don't spawn a process per request.
 */
function wk_tasks_kick(): void
{
    try {
        wk_tasks_sync();
        $now = time();
        if (!wk_val('SELECT 1 FROM tasks WHERE next_run <= ?', [$now])) {
            return;
        }
        if ((int) wk_setting('last_kick', '0') > $now - 30) {
            return;
        }
        wk_set_setting('last_kick', (string) $now);
        wk_spawn_runner();
    } catch (Throwable $e) {
        wk_log('kick: ' . $e->getMessage());
    }
}

function wk_php_binary(): string
{
    if ($bin = wk_config()['php_binary'] ?? null) {
        return $bin;
    }
    if (in_array(PHP_SAPI, ['cli', 'cli-server'], true)) {
        return PHP_BINARY;
    }
    foreach ([PHP_BINDIR . '/php', '/usr/local/bin/php', '/usr/bin/php'] as $candidate) {
        if (is_executable($candidate)) {
            return $candidate;
        }
    }
    return 'php';
}

/** Start `bin/wankarr tasks` detached from this request. */
function wk_spawn_runner(): void
{
    $cmd = escapeshellarg(wk_php_binary()) . ' ' . escapeshellarg(realpath(WK_ROOT . '/bin/wankarr')) . ' tasks';
    $setsid = wk_tool('setsid');
    exec(($setsid ? escapeshellarg($setsid) . ' ' : '') . $cmd . ' > /dev/null 2>&1 < /dev/null &');
}

function wk_log(string $message): void
{
    $file = wk_data_path('logs/wankarr.log');
    if (is_file($file) && filesize($file) > 5_000_000) {
        @rename($file, $file . '.1');
    }
    @file_put_contents($file, date('Y-m-d H:i:s') . ' ' . $message . "\n", FILE_APPEND | LOCK_EX);
    if (PHP_SAPI === 'cli' && getenv('WANKARR_VERBOSE')) {
        fwrite(STDERR, $message . "\n");
    }
}

/** Last lines of the log, newest first. */
function wk_log_tail(int $lines = 200): array
{
    $file = wk_data_path('logs/wankarr.log');
    if (!is_file($file)) {
        return [];
    }
    $fh = fopen($file, 'rb');
    $size = filesize($file);
    $chunk = min($size, 256 * 1024);
    fseek($fh, $size - $chunk);
    $data = (string) fread($fh, $chunk);
    fclose($fh);
    $all = array_filter(explode("\n", $data), 'strlen');
    return array_reverse(array_slice($all, -$lines));
}
