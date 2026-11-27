<?php
declare(strict_types=1);

function wk_json(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function wk_fail(int $status, string $message): never
{
    wk_json(['error' => $message], $status);
}

/**
 * Emit conditional-caching headers; ends the request with a 304 when the
 * browser already holds this exact version.
 */
function wk_cache_headers(string $etag, int $maxAge = 604800): void
{
    $etag = '"' . $etag . '"';
    header('ETag: ' . $etag);
    header('Cache-Control: private, max-age=' . $maxAge);
    $match = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    if ($match !== '' && trim($match) === $etag) {
        http_response_code(304);
        exit;
    }
}

function wk_mime_for(string $name, ?string $bytes = null): string
{
    $map = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif',
        'bmp' => 'image/bmp',
    ];
    $mime = $map[wk_ext($name)] ?? null;
    if ($mime === null && $bytes !== null) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: null;
    }
    return $mime ?? 'application/octet-stream';
}

function wk_send_image(string $name, string $bytes): never
{
    header('Content-Type: ' . wk_mime_for($name, $bytes));
    header('Content-Length: ' . strlen($bytes));
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
    exit;
}

function wk_send_file(string $path): never
{
    header('Content-Type: ' . wk_mime_for($path));
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

/**
 * Guard for state-changing API calls: POST only, plus a custom header that
 * browsers refuse to send cross-origin without a CORS preflight (which we
 * never grant) — enough to stop cross-site request forgery.
 */
function wk_require_post(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        wk_fail(405, 'POST required');
    }
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'wankarr') {
        wk_fail(403, 'Missing request header');
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    return is_array($body) ? $body : [];
}

/** Run an API action, turning exceptions into JSON errors. */
function wk_api(callable $fn): never
{
    try {
        wk_json($fn());
    } catch (InvalidArgumentException $e) {
        wk_fail(422, $e->getMessage());
    } catch (WkHttpError $e) {
        wk_fail(502, 'Metadata provider error: ' . $e->getMessage());
    } catch (Throwable $e) {
        wk_log('api: ' . $e->getMessage());
        wk_fail(500, $e->getMessage());
    }
}
