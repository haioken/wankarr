<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

/** Liveness/readiness for container health checks: 200 when the DB answers. */

try {
    wk_val('SELECT 1');
    wk_json(['status' => 'ok', 'version' => WK_VERSION]);
} catch (Throwable $e) {
    wk_json(['status' => 'error', 'version' => WK_VERSION, 'error' => 'database unavailable'], 503);
}
