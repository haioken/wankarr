<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

/**
 * PUT  ?batch=&file=&name=&offset=&total=   upload one chunk (raw body)
 * POST {action: create}                      new staging batch
 * POST {action: analyse, batch, library}     group files, find matches
 * POST {action: plan, batch, groups}         destinations + validation
 * POST {action: commit, batch, groups, token} copy into the library
 * POST {action: discard, batch}              drop the staged files
 */

wk_api(function () {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'PUT') {
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'wankarr') {
            wk_fail(403, 'Missing request header');
        }
        @set_time_limit(0);
        $in = fopen('php://input', 'rb');
        try {
            return wk_import_receive(
                (string) ($_GET['batch'] ?? ''),
                (int) ($_GET['file'] ?? -1),
                (string) ($_GET['name'] ?? ''),
                (int) ($_GET['offset'] ?? -1),
                (int) ($_GET['total'] ?? 0),
                $in,
            );
        } finally {
            fclose($in);
        }
    }

    $body = wk_require_post();
    $batch = (string) ($body['batch'] ?? '');
    switch ($body['action'] ?? '') {
        case 'create':
            return ['batch' => wk_import_create()];
        case 'analyse':
            @set_time_limit(300);
            return wk_import_analyse($batch, (int) ($body['library'] ?? 0));
        case 'plan':
            return wk_import_plan($batch, (array) ($body['groups'] ?? []));
        case 'commit':
            @set_time_limit(0);
            return wk_import_commit($batch, (array) ($body['groups'] ?? []), (string) ($body['token'] ?? ''));
        case 'discard':
            wk_import_discard($batch);
            return ['ok' => true];
    }
    throw new InvalidArgumentException('Unknown action');
});
