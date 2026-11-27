<?php
declare(strict_types=1);

/**
 * Naming conventions, Sonarr-style: user templates made of {Tokens}, rendered
 * into filesystem-safe path components.
 *
 *   {Series Title} ({Series Year}) / {Volume Name} / {Series Title} - {Volume Name}.cbz
 *
 * Numeric tokens accept zero padding: {Volume:00}, {Page:000}.
 */

const WK_NAMING_DEFAULTS = [
    'series_folder' => '{Series Title} ({Series Year})',
    'volume_folder' => '{Volume Name}',
    'volume_file' => '{Series Title} - {Volume Name}',
    'page_file' => '{Series Title} - {Volume Name} - Page {Page}',
    'colon' => 'smart',
];

const WK_NAMING_FIELDS = [
    'series_folder' => 'Series folder',
    'volume_folder' => 'Volume folder',
    'volume_file' => 'Volume file (archives)',
    'page_file' => 'Page file (image volumes)',
];

const WK_NAMING_COLON = [
    'smart' => 'Smart replace (": " → " - ", ":" → "-")',
    'delete' => 'Delete',
    'dash' => 'Replace with dash',
    'spacedash' => 'Replace with space dash',
    'spacedashspace' => 'Replace with space dash space',
];

/** Token => [description, example]. Keys are matched case-insensitively. */
const WK_NAMING_TOKENS = [
    'Series Title' => ['Full series title, punctuation included', '[Oshi no Ko]'],
    'Series CleanTitle' => ['Series title without punctuation', 'Oshi no Ko'],
    'Series Year' => ['First publication year (blank if unknown)', '2020'],
    'Series TitleYear' => ['Title and year', '[Oshi no Ko] (2020)'],
    'Volume Name' => ['"Volume 1", "Chapter 12", or the volume\'s own name', 'Volume 1'],
    'Volume' => ['Volume number; {Volume:00} pads it', '1'],
    'Chapter' => ['Chapter number; {Chapter:000} pads it', ''],
    'Page' => ['Page number (page files only); {Page:000} pads it', '1'],
    'Original Name' => ['Original file or folder name, without extension', 'Oshi no Ko v01 (Digital)'],
];

function wk_naming(): array
{
    $saved = json_decode((string) wk_setting('naming', '{}'), true);
    return array_merge(WK_NAMING_DEFAULTS, is_array($saved) ? array_intersect_key($saved, WK_NAMING_DEFAULTS) : []);
}

/** Validate and store naming settings; returns the saved settings. */
function wk_naming_save(array $input): array
{
    $settings = wk_naming_validate($input);
    wk_set_setting('naming', json_encode($settings, JSON_UNESCAPED_UNICODE));
    return $settings;
}

function wk_naming_validate(array $input): array
{
    $s = array_merge(WK_NAMING_DEFAULTS, array_intersect_key($input, WK_NAMING_DEFAULTS));
    $known = array_map('strtolower', array_keys(WK_NAMING_TOKENS));
    foreach (WK_NAMING_FIELDS as $field => $label) {
        $tpl = trim((string) $s[$field]);
        if ($tpl === '') {
            throw new InvalidArgumentException("$label can't be empty");
        }
        if (preg_match('#[/\\\\]#', $tpl)) {
            throw new InvalidArgumentException("$label is a single name; it can't contain / or \\");
        }
        foreach (wk_naming_tokens_in($tpl) as $token) {
            if (!in_array(strtolower($token), $known, true)) {
                throw new InvalidArgumentException("$label uses an unknown token {{$token}}");
            }
        }
        $s[$field] = $tpl;
    }
    $has = fn(string $tpl, array $tokens) => (bool) array_intersect(array_map('strtolower', wk_naming_tokens_in($tpl)), $tokens);
    $volumeTokens = ['volume name', 'volume', 'chapter', 'original name'];
    if (!$has($s['volume_folder'], $volumeTokens)) {
        throw new InvalidArgumentException('Volume folder must include {Volume Name}, {Volume}, {Chapter} or {Original Name}, or every volume would share one folder');
    }
    if (!$has($s['page_file'], ['page'])) {
        throw new InvalidArgumentException('Page file must include {Page}, or every page would get the same name');
    }
    if (!isset(WK_NAMING_COLON[$s['colon']])) {
        $s['colon'] = 'smart';
    }
    return $s;
}

function wk_naming_tokens_in(string $tpl): array
{
    preg_match_all('/\{([^}:]+)(?::0+)?\}/', $tpl, $m);
    return array_map('trim', $m[1]);
}

/** Token values for one series / volume / page. */
function wk_naming_context(array $series, ?array $book = null, ?int $page = null): array
{
    $title = trim((string) $series['title']);
    $year = $series['year'] ?? null;
    $clean = trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}\s]+/u', '', $title) ?? $title) ?? $title);
    $original = $book ? pathinfo((string) $book['rel_path'], is_dir_like($book) ? PATHINFO_BASENAME : PATHINFO_FILENAME) : '';
    return [
        'series title' => $title,
        'series cleantitle' => $clean,
        'series year' => $year,
        'series titleyear' => $year ? "$title ($year)" : $title,
        'volume name' => $book['title'] ?? '',
        'volume' => $book['volume'] ?? null,
        'chapter' => $book['chapter'] ?? null,
        'page' => $page,
        'original name' => $original,
    ];
}

/** Image-folder volumes keep their whole folder name as the "original name". */
function is_dir_like(array $book): bool
{
    return ($book['format'] ?? '') === 'dir';
}

/** Render a template into one filesystem-safe path component. */
function wk_naming_render(string $tpl, array $ctx, string $colon = 'smart'): string
{
    $out = preg_replace_callback('/\{([^}:]+)(?::(0+))?\}/', function ($m) use ($ctx) {
        $key = strtolower(trim($m[1]));
        if (!array_key_exists($key, $ctx)) {
            return '';
        }
        $v = $ctx[$key];
        if ($v === null || $v === '') {
            return '';
        }
        if (isset($m[2]) && is_numeric($v)) {
            $parts = explode('.', wk_num((float) $v), 2);
            return str_pad($parts[0], strlen($m[2]), '0', STR_PAD_LEFT) . (isset($parts[1]) ? '.' . $parts[1] : '');
        }
        return is_float($v) ? wk_num($v) : (string) $v;
    }, $tpl) ?? $tpl;
    return wk_fs_component($out, $colon);
}

/**
 * Make a string safe as a single file/folder name on Linux, macOS and
 * Windows/SMB shares, and tidy what blank tokens leave behind.
 */
function wk_fs_component(string $s, string $colon = 'smart'): string
{
    $s = match ($colon) {
        'delete' => str_replace(':', '', $s),
        'dash' => str_replace(':', '-', $s),
        'spacedash' => str_replace(':', ' -', $s),
        'spacedashspace' => str_replace(':', ' - ', $s),
        default => preg_replace(['/\s*:\s+/u', '/:/u'], [' - ', '-'], $s) ?? $s,
    };
    $s = strtr($s, ['/' => '+', '\\' => '+', '*' => '-', '"' => "'", '?' => '', '<' => '', '>' => '', '|' => '']);
    $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s) ?? $s;
    // Blank tokens leave "()" or "Title -" behind.
    $s = preg_replace('/\(\s*\)|\[\s*\]|\{\s*\}/u', '', $s) ?? $s;
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    $s = preg_replace('/(?:\s+-\s*){2,}|(?:\s*-\s+){2,}/u', ' - ', $s) ?? $s;
    // Windows rejects trailing dots and spaces; leading dots hide files.
    $s = trim($s, " -_\t");
    $s = rtrim($s, '. ');
    $s = ltrim($s, '. ');
    if (strlen($s) > 200) {
        $s = rtrim(mb_strcut($s, 0, 200, 'UTF-8'), '. ');
    }
    return $s !== '' ? $s : 'Unknown';
}

/** Rendered examples for the settings page. */
function wk_naming_examples(array $settings): array
{
    $series = ['title' => '[Oshi no Ko]', 'year' => 2020];
    $vol = ['title' => 'Volume 1', 'volume' => 1.0, 'chapter' => null, 'rel_path' => 'Oshi no Ko v01 (Digital).cbz', 'format' => 'zip'];
    $ch = ['title' => 'Chapter 12', 'volume' => null, 'chapter' => 12.0, 'rel_path' => 'Oshi no Ko - c012.cbz', 'format' => 'zip'];
    $imgs = ['title' => 'Volume 2', 'volume' => 2.0, 'chapter' => null, 'rel_path' => 'Oshi no Ko v02', 'format' => 'dir'];
    $noYear = ['title' => 'Frieren: Beyond Journey\'s End', 'year' => null];
    $r = fn(string $field, array $s, ?array $b = null, ?int $p = null) => wk_naming_render($settings[$field], wk_naming_context($s, $b, $p), $settings['colon']);
    $path = fn(array $s, array $b, string $leaf) => $r('series_folder', $s) . '/' . $r('volume_folder', $s, $b) . '/' . $leaf;
    return [
        'Archive volume' => $path($series, $vol, $r('volume_file', $series, $vol) . '.cbz'),
        'Image volume' => $path($series, $imgs, $r('page_file', $series, $imgs, 1) . '.png'),
        'Chapter' => $path($series, $ch, $r('volume_file', $series, $ch) . '.cbz'),
        'Title with colon, no year' => $path($noYear, $vol, $r('volume_file', $noYear, $vol) . '.cbz'),
    ];
}
