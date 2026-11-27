<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
wk_require_admin(true);

/**
 * GET  → naming settings, defaults, tokens and rendered examples
 * POST {action: 'preview'|'save', settings}
 */

wk_api(function () {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $body = wk_require_post();
        $settings = (array) ($body['settings'] ?? []);
        if (($body['action'] ?? '') === 'save') {
            $saved = wk_naming_save($settings);
            return ['settings' => $saved, 'examples' => wk_naming_examples($saved), 'saved' => true];
        }
        $valid = wk_naming_validate($settings);
        return ['settings' => $valid, 'examples' => wk_naming_examples($valid)];
    }
    $s = wk_naming();
    return ['settings' => $s, 'defaults' => WK_NAMING_DEFAULTS, 'examples' => wk_naming_examples($s)];
});
