<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

/**
 * Manual rename only — nothing in the scheduler calls this.
 * GET  ?series=1,2,3          preview: planned moves, problems, token
 * POST {series: [..], token}  apply exactly the previewed plan
 */

wk_api(function () {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $body = wk_require_post();
        $ids = array_map('intval', (array) ($body['series'] ?? []));
        if (!$ids) {
            throw new InvalidArgumentException('No series selected');
        }
        return wk_rename_commit($ids, (string) ($body['token'] ?? ''));
    }
    $ids = array_filter(array_map('intval', explode(',', (string) ($_GET['series'] ?? ''))));
    if (!$ids) {
        throw new InvalidArgumentException('No series selected');
    }
    $plan = wk_rename_plan($ids);
    $strip = fn(array $i) => array_diff_key($i, ['root' => 1]);
    $plan['items'] = array_map(function ($i) use ($strip) {
        $i = $strip($i);
        $i['page_count'] = count($i['pages']);
        $i['pages'] = array_slice($i['pages'], 0, 6); // a sample is enough to preview
        return $i;
    }, $plan['items']);
    $plan['extras'] = array_map($strip, $plan['extras']);
    $plan['naming'] = wk_naming();
    return $plan;
});
