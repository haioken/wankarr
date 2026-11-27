<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

/** GET: task state. POST {name}: run a task now (in the background). */

wk_require_user(true);

wk_api(function () {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        wk_require_admin(true);
        $body = wk_require_post();
        wk_task_queue((string) ($body['name'] ?? ''));
    }
    return array_map(fn($t) => [
        'name' => $t['name'],
        'label' => $t['label'],
        'running' => $t['running'],
        'queued' => $t['queued'],
        'message' => $t['message'],
        'lastFinished' => $t['last_finished'] ? (int) $t['last_finished'] : null,
        'nextRun' => (int) $t['next_run'],
    ], wk_tasks_state());
});
