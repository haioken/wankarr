<?php
declare(strict_types=1);

/**
 * Title handling: normalisation for grouping/search keys, fuzzy similarity
 * for metadata matching, and the release-name parser that pulls series,
 * volume and chapter out of names like "Berserk v01 (2003) (Digital).cbz".
 */

const WK_FOLD = [
    'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'æ' => 'ae',
    'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e',
    'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'ñ' => 'n',
    'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o', 'œ' => 'oe',
    'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss',
    '’' => "'", '‘' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-', '×' => 'x', '☆' => ' ', '★' => ' ',
];

/** Lower-case and strip accents (and full-width forms) so "Shōnen" == "shonen". */
function wk_fold(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    // Full-width ASCII (ＡＢＣ１２３) → ASCII.
    $s = preg_replace_callback('/[\x{FF01}-\x{FF5E}]/u', fn($m) => chr(mb_ord($m[0]) - 0xFEE0), $s) ?? $s;
    return strtr($s, WK_FOLD);
}

/** Canonical comparison form: folded, punctuation removed, single-spaced. */
function wk_norm(string $s): string
{
    $s = wk_fold($s);
    $s = str_replace(['&', '+'], [' and ', ' and '], $s);
    $s = preg_replace("/['\"`.]/u", '', $s) ?? $s;          // "Dr. Stone" → "dr stone", "JoJo's" → "jojos"
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? $s;
    return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
}

function wk_sort_title(string $title): string
{
    $t = wk_norm($title);
    return preg_replace('/^(the|a|an) /', '', $t) ?? $t;
}

/**
 * 0–1 similarity between two titles, tolerant of word order, punctuation,
 * subtitles and small typos.
 */
function wk_similarity(string $a, string $b): float
{
    $na = wk_norm($a);
    $nb = wk_norm($b);
    if ($na === '' || $nb === '') {
        return 0.0;
    }
    if ($na === $nb) {
        return 1.0;
    }
    $ca = str_replace(' ', '', $na);
    $cb = str_replace(' ', '', $nb);
    if ($ca === $cb) {
        return 0.98;
    }

    $ta = array_values(array_unique(explode(' ', $na)));
    $tb = array_values(array_unique(explode(' ', $nb)));
    $common = count(array_intersect($ta, $tb));
    $tokens = 2 * $common / (count($ta) + count($tb));

    sort($ta);
    sort($tb);
    $sa = implode(' ', $ta);
    $sb = implode(' ', $tb);
    $edit = 1 - levenshtein($sa, $sb) / max(strlen($sa), strlen($sb));
    $compact = 1 - levenshtein($ca, $cb) / max(strlen($ca), strlen($cb));

    $score = max(0.45 * $tokens + 0.55 * $edit, $compact * 0.97);

    // One title wholly contains the other ("Berserk" vs "Berserk: Deluxe
    // Edition"): good, but never as good as an exact match.
    if (str_contains(" $nb ", " $na ") || str_contains(" $na ", " $nb ")) {
        $ratio = min(strlen($na), strlen($nb)) / max(strlen($na), strlen($nb));
        $score = max($score, 0.72 + 0.2 * $ratio);
    }
    return round(min($score, 0.97), 4);
}

/** Remove release-group tags, years and scan notes from a folder/file name. */
function wk_clean_title(string $name): string
{
    $s = preg_replace('/\[[^\]]*\]|\([^)]*\)|\{[^}]*\}/u', ' ', $name) ?? $name;
    if (substr_count($s, '_') > substr_count($s, ' ')) {
        $s = str_replace('_', ' ', $s);
    }
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    $s = trim($s, " \t-_.,:;~");
    return $s !== '' ? $s : trim($name);
}

function wk_num(?float $n): string
{
    if ($n === null) {
        return '';
    }
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
}

/**
 * Parse a book file/folder name.
 * Returns ['series' => ?string, 'volume' => ?float, 'chapter' => ?float,
 *          'year' => ?int, 'title' => string (display title for the book)].
 */
function wk_parse_name(string $name, bool $isFile = true): array
{
    $base = $isFile ? pathinfo($name, PATHINFO_FILENAME) : $name;
    $year = null;
    if (preg_match('/[(\[]((?:19|20)\d{2})[)\]]/', $base, $m)) {
        $year = (int) $m[1];
    }
    $s = preg_replace('/\[[^\]]*\]|\([^)]*\)|\{[^}]*\}/u', ' ', $base) ?? $base;
    if (substr_count($s, '_') > substr_count($s, ' ')) {
        $s = str_replace('_', ' ', $s);
    }
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);

    $edge = '(?<![\p{L}\p{N}])';
    $num = '(\d+(?:\.\d+)?)(?:\s?-\s?\d+(?:\.\d+)?)?(?![\p{L}\d])';
    $patterns = [
        'volume' => "/{$edge}(?:vol(?:ume)?\.?\s*|v(?=\d)|tome\s*)$num/iu",
        'chapter' => "/{$edge}(?:ch(?:apter|ap)?\.?\s*|c(?=\d)|#\s*)$num/iu",
    ];
    $found = ['volume' => null, 'chapter' => null];
    $cut = null;
    foreach ($patterns as $kind => $re) {
        if (preg_match($re, $s, $m, PREG_OFFSET_CAPTURE)) {
            $found[$kind] = (float) $m[1][0];
            $cut = $cut === null ? $m[0][1] : min($cut, $m[0][1]);
        }
    }

    if ($cut !== null) {
        $series = trim(substr($s, 0, $cut), " \t-_.,:;~");
    } elseif ($isFile && preg_match('/^(.*?)(?:\s+-\s+|[\s_]+)(0\d{1,3}(?:\.\d+)?|\d{1,4}(?:\.\d+)?)$/u', $s, $m)
        && ($m[2][0] === '0' || preg_match('/\s-\s/', $s))
        && !preg_match('/\b(no|part|season|vol)\.?$/i', $m[1])) {
        // "Berserk 001" or "Naruto - 700": a bare chapter number. Requiring a
        // leading zero or a dash keeps titles like "Mob Psycho 100" intact.
        $series = trim($m[1], " \t-_.,:;~");
        $found['chapter'] = (float) $m[2];
    } else {
        $series = $s;
    }

    // "[Oshi no Ko] v01": when stripping tags leaves no name, the leading
    // bracket group is the title itself, not a release group.
    if ($series === '' && preg_match('/^\s*(\[[^\]]+\])/u', $base, $m)) {
        $series = $m[1];
    }

    $v = $found['volume'];
    $c = $found['chapter'];
    $title = match (true) {
        $v !== null && $c !== null => 'Vol. ' . wk_num($v) . ' Ch. ' . wk_num($c),
        $v !== null => 'Volume ' . wk_num($v),
        $c !== null => 'Chapter ' . wk_num($c),
        default => wk_clean_title($base),
    };

    return [
        'series' => $series !== '' ? $series : null,
        'volume' => $v,
        'chapter' => $c,
        'year' => $year,
        'title' => $title,
    ];
}
