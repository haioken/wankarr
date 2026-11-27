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

/**
 * Top-level domains of download sites that prefix their releases
 * ("DLRAW.NET-Title", "13DL.APP_Title").
 */
/**
 * Bump when wk_parse_name/wk_parse_path change what they return, so the next
 * full scan re-reads the titles of volumes that are already in the library.
 */
const WK_PARSER_VERSION = 2;

const WK_SITE_TLDS = 'net|com|org|app|me|to|io|cc|co|info|xyz|site|club|online|top|vip|moe|ws|la|tv|ru|jp|biz|link|fun|pro|one|live|cx|nu|in|mobi|asia';

/** Full-width ASCII (ＡＢＣ１２３) and the ideographic space → ASCII. */
function wk_halfwidth(string $s): string
{
    $s = str_replace("\u{3000}", ' ', $s);
    return preg_replace_callback('/[\x{FF01}-\x{FF5E}]/u', fn($m) => chr(mb_ord($m[0]) - 0xFEE0), $s) ?? $s;
}

/** Drop a leading download-site tag such as "DLRAW.NET-" or "13DL.APP_". */
function wk_strip_site_prefix(string $s): string
{
    $re = '/^\s*(?:www\.)?[a-z0-9][a-z0-9-]{2,}\.(?:' . WK_SITE_TLDS . ')(?![a-z0-9])[\s_\-.~:]*/i';
    $out = preg_replace($re, '', $s) ?? $s;
    return trim($out) !== '' ? $out : $s;
}

/**
 * A name with release-group tags, bracketed notes and site prefixes removed,
 * and underscores read as spaces ("One_Piece_v01"; grouping keys ignore
 * punctuation anyway).
 */
function wk_name_core(string $name): string
{
    $s = preg_replace('/\[[^\]]*\]|\([^)]*\)|\{[^}]*\}/u', ' ', wk_halfwidth($name)) ?? $name;
    $s = str_replace('_', ' ', wk_strip_site_prefix($s));
    return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
}

/** Remove release-group tags, years, scan notes and site prefixes from a folder/file name. */
function wk_clean_title(string $name): string
{
    $s = trim(wk_name_core($name), " \t-_.,:;~");
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
 *          'year' => ?int, 'title' => string (display title for the book),
 *          'number' => ?float (a trailing bare number when the name has no
 *          volume or chapter marker; see wk_parse_path)].
 *
 * Understands "v01", "Vol. 1", "Volume 1", "tome 1", "c12", "Ch. 12", "#12",
 * the Japanese/Chinese "第01巻", "01巻", "第1話", full-width digits, and a
 * letter after the number for alternate copies ("v07b" → "Volume 7b").
 */
function wk_parse_name(string $name, bool $isFile = true): array
{
    $base = wk_halfwidth($isFile ? pathinfo($name, PATHINFO_FILENAME) : $name);
    $year = null;
    if (preg_match('/[(\[]((?:19|20)\d{2})[)\]]/', $base, $m)) {
        $year = (int) $m[1];
    }
    $s = wk_name_core($base);

    $edge = '(?<![\p{L}\p{N}])';
    $num = '(\d+(?:\.\d+)?)(?:([a-z])(?![\p{L}\d]))?(?:\s?-\s?\d+(?:\.\d+)?[a-z]?)?(?![\p{L}\d])';
    $patterns = [
        ['volume', "/{$edge}(?:vol(?:ume)?\.?\s*|v(?=\d)|tome\s*)$num/iu", true],
        ['volume', '/(?:第\s*)?(\d+(?:\.\d+)?)\s*[巻卷冊]|第\s*(\d+(?:\.\d+)?)\s*集/u', false],
        ['chapter', "/{$edge}(?:ch(?:apter|ap)?\.?\s*|c(?=\d)|#\s*)$num/iu", true],
        ['chapter', '/(?:第\s*)?(\d+(?:\.\d+)?)\s*[話话]|第\s*(\d+(?:\.\d+)?)\s*回/u', false],
    ];
    $found = ['volume' => null, 'chapter' => null];
    $variant = ['volume' => '', 'chapter' => ''];
    $cut = null;
    foreach ($patterns as [$kind, $re, $latin]) {
        if ($found[$kind] !== null || !preg_match($re, $s, $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL)) {
            continue;
        }
        if ($latin) {
            $found[$kind] = (float) $m[1][0];
            $variant[$kind] = strtolower((string) ($m[2][0] ?? ''));
        } else {
            $found[$kind] = (float) ($m[1][0] ?? $m[2][0]);
        }
        $cut = $cut === null ? $m[0][1] : min($cut, $m[0][1]);
    }

    $number = null;
    if ($cut !== null) {
        $series = trim(substr($s, 0, $cut), " \t-_.,:;~");
    } elseif (preg_match('/^(.*?)(?:\s+-\s+|[\s_]+)(0\d{1,3}(?:\.\d+)?|\d{1,4}(?:\.\d+)?)$/u', $s, $m)
        && !preg_match('/\b(no|part|season|vol)\.?$/i', $m[1])) {
        $number = (float) $m[2];
        if ($isFile && ($m[2][0] === '0' || preg_match('/\s-\s/', $s))) {
            // "Berserk 001" or "Naruto - 700": a bare chapter number. Requiring a
            // leading zero or a dash keeps titles like "Mob Psycho 100" intact.
            $series = trim($m[1], " \t-_.,:;~");
            $found['chapter'] = $number;
        } else {
            $series = $s;
        }
    } else {
        $series = $s;
    }

    // "[Oshi no Ko] v01": when stripping tags leaves no name, the leading
    // bracket group is the title itself, not a release group.
    if ($series === '' && preg_match('/^\s*(\[[^\]]+\])/u', $base, $m)) {
        $series = $m[1];
    }

    $v = $found['volume'] !== null ? wk_num($found['volume']) . $variant['volume'] : null;
    $c = $found['chapter'] !== null ? wk_num($found['chapter']) . $variant['chapter'] : null;
    $title = match (true) {
        $v !== null && $c !== null => "Vol. $v Ch. $c",
        $v !== null => "Volume $v",
        $c !== null => "Chapter $c",
        default => wk_clean_title($base),
    };

    return [
        'series' => $series !== '' ? $series : null,
        'volume' => $found['volume'],
        'chapter' => $found['chapter'],
        'year' => $year,
        'title' => $title,
        'number' => $number,
    ];
}

/**
 * The volume range a pack folder declares: "Title vol 01-20", "v01-04b",
 * "第01-03巻". Returns [from, to] or null.
 */
function wk_volume_range(string $name): ?array
{
    $s = wk_name_core($name);
    if (preg_match('/(?<![\p{L}\p{N}])(?:vol(?:ume)?s?\.?\s*|v)(\d+)\s?[-~〜]\s?(\d+)[a-z]?(?![\p{L}\d])/iu', $s, $m)
        || preg_match('/(?:第\s*)?(\d+)\s*[-~〜]\s*(\d+)\s*[巻卷]/u', $s, $m)) {
        [$from, $to] = [(float) $m[1], (float) $m[2]];
        return $from <= $to ? [$from, $to] : null;
    }
    return null;
}

/**
 * Parse a book by its path within the library: its own name, plus hints from
 * the folders above it. A bare number ("Takagi-san 01") inside a folder that
 * declares a volume range ("… v01-03") is that volume.
 */
function wk_parse_path(string $rel, bool $isFile = true): array
{
    $dirs = explode('/', $rel);
    $p = wk_parse_name(array_pop($dirs), $isFile);
    $n = $p['number'];
    if ($n === null || $p['volume'] !== null) {
        return $p;
    }
    foreach (array_reverse($dirs) as $dir) {
        $range = wk_volume_range($dir);
        if ($range !== null && $n >= $range[0] && $n <= $range[1]) {
            $p['volume'] = $n;
            $p['chapter'] = null;
            $p['title'] = 'Volume ' . wk_num($n);
            break;
        }
    }
    return $p;
}
