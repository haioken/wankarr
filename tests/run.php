<?php
declare(strict_types=1);

/**
 * Dependency-free unit tests for the pure logic (no database or network):
 * file-name parsing, title matching, naming templates and sanitising.
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

echo $failures ? "$failures of $count checks failed\n" : "All $count checks passed\n";
exit($failures ? 1 : 0);
