<?php
declare(strict_types=1);

/**
 * Dependency-free unit tests for the pure logic (no database or network):
 * file-name parsing, title matching, naming templates and sanitising, plus
 * folder classification against a throwaway temp directory.
 *
 *   php tests/run.php
 */

require __DIR__ . '/../src/bootstrap.php';

$failures = 0;
$count = 0;

function check(string $name, mixed $actual, mixed $expected): void
{
    global $failures, $count;
    $count++;
    if ($actual === $expected) {
        return;
    }
    $failures++;
    fwrite(STDERR, "FAIL $name\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n");
}

function check_true(string $name, bool $cond): void
{
    check($name, $cond, true);
}

// --- parsing release names ----------------------------------------------------------

$cases = [
    // file name                                           series                 volume chapter year  title
    ['Berserk v01 (2003) (Digital) (danke-Empire).cbz', 'Berserk', 1.0, null, 2003, 'Volume 1'],
    ['Berserk 001.cbz', 'Berserk', null, 1.0, null, 'Chapter 1'],
    ['Naruto - 700.cbz', 'Naruto', null, 700.0, null, 'Chapter 700'],
    ['Mob Psycho 100.cbz', 'Mob Psycho 100', null, null, null, 'Mob Psycho 100'],
    ['Kaiju No. 8 v03.cbz', 'Kaiju No. 8', 3.0, null, null, 'Volume 3'],
    ['[Group] Chainsaw Man - Chapter 12.5 [x].cbz', 'Chainsaw Man', null, 12.5, null, 'Chapter 12.5'],
    ['One_Piece_Vol_105.cbr', 'One Piece', 105.0, null, null, 'Volume 105'],
    ['Dr. Stone c232.cbz', 'Dr. Stone', null, 232.0, null, 'Chapter 232'],
    ['Spy x Family v01 Ch. 001.cbz', 'Spy x Family', 1.0, 1.0, null, 'Vol. 1 Ch. 1'],
    ['Witch Hat Atelier #64.cbz', 'Witch Hat Atelier', null, 64.0, null, 'Chapter 64'],
    ['[Oshi no Ko] v01.cb7', '[Oshi no Ko]', 1.0, null, null, 'Volume 1'],
    ['Vol 01.cbz', null, 1.0, null, null, 'Volume 1'],
    // raw releases: download-site prefixes, Japanese markers, alternate copies
    ['DLRAW.NET-Flying Witch v01.cbz', 'Flying Witch', 1.0, null, null, 'Volume 1'],
    ['13DL.APP_Karakai_Takagi-san_v20b.cbz', 'Karakai Takagi-san', 20.0, null, null, 'Volume 20b'],
    ['DLRAW.NET-[石塚千尋] ふらいんぐうぃっち 第01巻 [別スキャン].zip', 'ふらいんぐうぃっち', 1.0, null, null, 'Volume 1'],
    ['からかい上手の高木さん 第07巻 LQ.cbz', 'からかい上手の高木さん', 7.0, null, null, 'Volume 7'],
    ['ふらいんぐうぃっち 第１２巻.cbz', 'ふらいんぐうぃっち', 12.0, null, null, 'Volume 12'],
    ['高木さん 3巻.cbz', '高木さん', 3.0, null, null, 'Volume 3'],
    ['進撃の巨人 第139話.cbz', '進撃の巨人', null, 139.0, null, 'Chapter 139'],
    ['Dr.Stone v01.cbz', 'Dr.Stone', 1.0, null, null, 'Volume 1'],
];
foreach ($cases as [$file, $series, $vol, $ch, $year, $title]) {
    $p = wk_parse_name($file);
    check("parse series: $file", $p['series'], $series);
    check("parse volume: $file", $p['volume'], $vol);
    check("parse chapter: $file", $p['chapter'], $ch);
    check("parse year: $file", $p['year'], $year);
    check("parse title: $file", $p['title'], $title);
}
check('parse folder (not a file)', wk_parse_name('Volume 03', false)['volume'], 3.0);
check('parse folder bare number stays a title', wk_parse_name('13DL.APP_Takagi-san 01', false)['title'], 'Takagi-san 01');
check('site prefix only', wk_clean_title('DLRAW.NET-単ページ'), '単ページ');
check('site prefix needs a known domain', wk_clean_title('Mr.Me v01'), 'Mr.Me v01');
check('volume range', wk_volume_range('13DL.APP_Karakai_Takagi-san vol 01-20'), [1.0, 20.0]);
check('volume range with variant', wk_volume_range('Flying Witch v01-04b'), [1.0, 4.0]);
check('volume range none', wk_volume_range('Flying Witch v04'), null);
$pp = wk_parse_path('Takagi-san vol 01-20/Karakai Takagi-san v01-03/13DL.APP_Takagi-san 02', false);
check('path: bare number inside a volume pack', [$pp['volume'], $pp['title']], [2.0, 'Volume 2']);
$pp = wk_parse_path('Takagi-san vol 01-20/Karakai Takagi-san v01-03/Takagi-san 25', false);
check('path: number outside the pack range', [$pp['volume'], $pp['title']], [null, 'Takagi-san 25']);
$pp = wk_parse_path('Berserk/Berserk 001.cbz');
check('path: no pack keeps the bare chapter', [$pp['volume'], $pp['chapter']], [null, 1.0]);

// --- classifying folders ------------------------------------------------------------------

$tmp = sys_get_temp_dir() . '/wk-test-' . bin2hex(random_bytes(4));
$mk = function (string $dir, int $images, array $subdirs = []) use ($tmp) {
    @mkdir("$tmp/$dir", 0o777, true);
    for ($i = 1; $i <= $images; $i++) {
        touch(sprintf('%s/%s/%03d.jpg', $tmp, $dir, $i));
    }
    foreach ($subdirs as $sub) {
        @mkdir("$tmp/$dir/$sub", 0o777, true);
    }
    return "$tmp/$dir";
};
check('classify: page folder', wk_classify($mk('a', 20)), ['book', 'dir']);
check('classify: pages + extras subfolder', wk_classify($mk('b', 20, ['単ページ'])), ['book', 'dir']);
check('classify: cover art + volume folders', wk_classify($mk('c', 1, ['Volume 1', 'Volume 2'])), ['folder', null]);
check('classify: NAS thumbnails ignored', wk_classify($mk('d', 3, ['@eaDir'])), ['book', 'dir']);
touch("$tmp/b/単ページ/001.jpg");
touch("$tmp/d/@eaDir/thumb.jpg");
check('pages include subfolder extras', count(wk_dir_images("$tmp/b")), 21);
check('pages skip NAS thumbnails', count(wk_dir_images("$tmp/d")), 3);
$rm = function (string $dir) use (&$rm) {
    foreach (array_diff(scandir($dir), ['.', '..']) as $e) {
        is_dir("$dir/$e") ? $rm("$dir/$e") : unlink("$dir/$e");
    }
    rmdir($dir);
};
$rm($tmp);

// --- normalising + similarity ---------------------------------------------------------

check('norm punctuation', wk_norm('Dr. STONE'), 'dr stone');
check('norm accents', wk_norm('Shōnen Jump'), 'shonen jump');
check('norm full-width', wk_norm('ＡＢＣ１２'), 'abc12');
check('sort title drops articles', wk_sort_title('The Promised Neverland'), 'promised neverland');
check('similarity exact', wk_similarity('Berserk', 'BERSERK'), 1.0);
check_true('similarity punctuation', wk_similarity('Dr. Stone', 'Dr.STONE') > 0.95);
check_true('similarity subtitle', wk_similarity('Berserk', 'Berserk: The Prototype') > 0.7);
check_true('similarity unrelated', wk_similarity('One Piece', 'One Punch-Man') < 0.6);
check_true('search typo', wk_search_similarity('fireen', 'frieren beyond journeys end') >= 0.6);
check_true('search no false friend', wk_search_similarity('naruto', 'attack on titan') < 0.6);

// --- naming ----------------------------------------------------------------------------

$ex = wk_naming_examples(WK_NAMING_DEFAULTS);
check('naming archive', $ex['Archive volume'], '[Oshi no Ko] (2020)/Volume 1/[Oshi no Ko] - Volume 1.cbz');
check('naming image page', $ex['Image volume'], '[Oshi no Ko] (2020)/Volume 2/[Oshi no Ko] - Volume 2 - Page 1.png');
check('naming chapter', $ex['Chapter'], '[Oshi no Ko] (2020)/Chapter 12/[Oshi no Ko] - Chapter 12.cbz');
check('naming colon + no year', $ex['Title with colon, no year'], "Frieren - Beyond Journey's End/Volume 1/Frieren - Beyond Journey's End - Volume 1.cbz");
$ctx = wk_naming_context(['title' => 'X', 'year' => null], ['title' => 'Volume 3', 'volume' => 3.0, 'chapter' => null, 'rel_path' => 'x.cbz', 'format' => 'zip'], 7);
check('naming padding', wk_naming_render('v{Volume:00} p{Page:000}', $ctx), 'v03 p007');
check('naming case-insensitive tokens', wk_naming_render('{series title}', $ctx), 'X');

check('fs illegal chars', wk_fs_component('a/b\\c*d?"e<f>g|'), "a+b+c-d'efg");
check('fs hidden + trailing dots', wk_fs_component('  ..hidden. '), 'hidden');
check('fs empty brackets', wk_fs_component('Title ()'), 'Title');
check('fs dangling separators', wk_fs_component('Title - - Name'), 'Title - Name');
check('fs colon smart', wk_fs_component('Re:Zero: Starting Life'), 'Re-Zero - Starting Life');
check('fs colon delete', wk_fs_component('Re:Zero', 'delete'), 'ReZero');
check('fs never empty', wk_fs_component('???'), 'Unknown');

$threw = fn(callable $fn) => (function () use ($fn) {
    try {
        $fn();
        return false;
    } catch (InvalidArgumentException) {
        return true;
    }
})();
check_true('naming rejects unknown token', $threw(fn() => wk_naming_validate(['volume_file' => '{Nope}'])));
check_true('naming rejects slash', $threw(fn() => wk_naming_validate(['series_folder' => 'a/b'])));
check_true('naming requires page token', $threw(fn() => wk_naming_validate(['page_file' => '{Series Title}'])));
check_true('naming requires volume token', $threw(fn() => wk_naming_validate(['volume_folder' => 'Stuff'])));

// --- import volume names ---------------------------------------------------------------

check('import name volume', wk_import_volume_name(2.0, null, 'x'), 'Volume 2');
check('import name chapter', wk_import_volume_name(null, 12.5, 'x'), 'Chapter 12.5');
check('import name both', wk_import_volume_name(1.0, 3.0, 'x'), 'Vol. 1 Ch. 3');
check('import name fallback', wk_import_volume_name(null, null, 'Extras'), 'Extras');

// --- auth helpers ------------------------------------------------------------------------

$hash = wk_password_hash('correct horse battery');
check_true('password hash verifies', password_verify('correct horse battery', $hash));
check_true('password hash rejects wrong', !password_verify('Correct horse battery', $hash));
check_true('password hash is argon2id or bcrypt', str_starts_with($hash, '$argon2id$') || str_starts_with($hash, '$2y$'));
check_true('password hash current', !wk_password_needs_rehash($hash));
check_true('short password rejected', $threw(fn() => wk_validate_password('short')));
check_true('bad username rejected', $threw(fn() => wk_validate_username('a b')));
check('username trimmed', wk_validate_username('  Alice_1 '), 'Alice_1');
check_true('bad email rejected', $threw(fn() => wk_validate_email('nope@')));
check('empty email is null', wk_validate_email('  '), null);
check('rating "adult" means no limit', wk_validate_rating('pornographic'), null);
check_true('unknown rating rejected', $threw(fn() => wk_validate_rating('x')));
check('allowed ratings: suggestive', wk_allowed_ratings(['max_rating' => 'suggestive']), ['safe', 'suggestive']);
check('allowed ratings: none', wk_allowed_ratings(['max_rating' => null]), null);
check('safe next: relative path', wk_safe_next('/series.php?id=3'), '/series.php?id=3');
check('safe next: other host', wk_safe_next('//evil.example/x'), './');
check('safe next: absolute URL', wk_safe_next('https://evil.example/'), './');
check('safe next: backslash trick', wk_safe_next('/\\evil.example'), './');
check('user agent label', wk_user_agent_label('Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0'), 'Firefox on Linux');

echo $failures ? "$failures of $count checks failed\n" : "All $count checks passed\n";
exit($failures ? 1 : 0);
