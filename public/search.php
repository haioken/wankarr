<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

/**
 * Search. Every term is a pill: free text, genre, author, status, type,
 * content rating or year. Pills combine with AND; several pills of a
 * single-valued kind (status, type, rating, year) mean "any of these".
 * ?partial=1 returns just the results, for live updates.
 */

$param = fn(string $k) => array_values(array_filter(array_map('trim', array_map('strval', (array) ($_GET[$k] ?? []))), 'strlen'));
$texts = $param('q');
$filters = array_filter([
    'genre' => $param('genre'),
    'person' => array_map('intval', $param('person')),
    'status' => $param('status'),
    'type' => $param('type'),
    'rating' => array_values(array_intersect($param('rating'), array_keys(WK_CONTENT_RATINGS))),
    'year' => array_map('intval', $param('year')),
]);
$searching = $texts || $filters;
$result = $searching ? wk_search($texts, $filters) : ['series' => [], 'books' => [], 'fuzzy' => false, 'total' => 0];
$bookIds = wk_series_book_ids(array_map('intval', array_column($result['series'], 'id')));

function wk_search_results(array $result, array $bookIds, bool $searching): void
{
    if (!$searching) {
        echo '<div class="empty-state">' . wk_icon('search') . '<h2>Search your library</h2><p>Type a title in any language, an author, a genre, a status or a year. Pick suggestions to narrow things down; typos are fine.</p></div>';
        return;
    }
    if (!$result['series'] && !$result['books']) {
        echo '<div class="empty-state">' . wk_icon('search') . '<h2>No results</h2><p>Nothing in your library matches all of these.</p></div>';
        return;
    }
    if ($result['series']): ?>
      <h2 class="section-title">Series <span class="muted"><?= (int) $result['total'] ?></span>
        <?php if ($result['fuzzy']): ?><span class="section-note">No exact matches; showing close ones</span><?php endif ?>
      </h2>
      <div class="poster-grid js-grid">
        <?php foreach ($result['series'] as $s): $id = (int) $s['id']; $n = (int) $s['book_count']; ?>
          <a class="poster" href="series.php?id=<?= $id ?>" data-books="<?= h(implode(',', $bookIds[$id] ?? [])) ?>">
            <div class="poster-art">
              <div class="poster-placeholder"><?= wk_icon('book') ?></div>
              <img src="api/cover.php?series=<?= $id ?>" alt="" loading="lazy" decoding="async">
            </div>
            <div class="poster-progress"><span></span></div>
            <div class="poster-title" title="<?= h($s['title']) ?>"><?= h($s['title']) ?></div>
            <div class="poster-sub"><?= h(implode(' · ', array_filter([$s['year'], $s['meta_type'], $n . ($n === 1 ? ' volume' : ' volumes')]))) ?></div>
          </a>
        <?php endforeach ?>
      </div>
    <?php endif;
    if ($result['books']): ?>
      <h2 class="section-title">Volumes <span class="muted"><?= count($result['books']) ?></span></h2>
      <ul class="book-list">
        <?php foreach ($result['books'] as $b): ?>
          <li><a href="read.php?id=<?= (int) $b['id'] ?>"><?= wk_icon('book', 'icon icon-sm') ?><span><?= h($b['series_title']) ?></span> <span class="muted"><?= h($b['title']) ?></span></a></li>
        <?php endforeach ?>
      </ul>
    <?php endif;
}

if (!empty($_GET['partial'])) {
    header('Cache-Control: no-store');
    wk_search_results($result, $bookIds, (bool) $searching);
    exit;
}

// Pills for the current URL, with display labels.
$pills = [];
foreach ($texts as $t) {
    $pills[] = ['kind' => 'q', 'value' => $t, 'group' => 'Text', 'label' => $t];
}
$genreKinds = array_column(wk_all('SELECT name, kind FROM genres'), 'kind', 'name');
foreach ($filters['genre'] ?? [] as $g) {
    $pills[] = ['kind' => 'genre', 'value' => $g, 'group' => ['theme' => 'Theme', 'demographic' => 'Demographic'][$genreKinds[$g] ?? ''] ?? 'Genre', 'label' => $g];
}
foreach ($filters['person'] ?? [] as $pid) {
    $name = wk_val('SELECT name FROM people WHERE id = ?', [$pid]);
    $pills[] = ['kind' => 'person', 'value' => $pid, 'group' => 'Author', 'label' => $name ? wk_person_name($name) : "#$pid"];
}
foreach (['status' => 'Status', 'type' => 'Type', 'year' => 'Year'] as $k => $group) {
    foreach ($filters[$k] ?? [] as $v) {
        $pills[] = ['kind' => $k, 'value' => $v, 'group' => $group, 'label' => (string) $v];
    }
}
foreach ($filters['rating'] ?? [] as $r) {
    $pills[] = ['kind' => 'rating', 'value' => $r, 'group' => 'Rating', 'label' => WK_CONTENT_RATINGS[$r]];
}

wk_page_start('Search', ['library', 'search'], 'search');
?>
<div class="content-body search-page">
  <div class="facet-search js-facet-search" data-pills="<?= h(json_encode($pills, JSON_UNESCAPED_UNICODE)) ?>">
    <div class="facet-box">
      <?= wk_icon('search') ?>
      <div class="facet-pills js-pills"></div>
      <input type="text" class="js-facet-input" placeholder="Title, author, genre, status, year…" autocomplete="off" spellcheck="false" autofocus
             aria-label="Search" role="combobox" aria-expanded="false" aria-controls="facet-suggest" aria-autocomplete="list">
    </div>
    <div class="facet-suggest js-facet-suggest" id="facet-suggest" role="listbox" hidden></div>
  </div>
  <div class="js-results"><?php wk_search_results($result, $bookIds, (bool) $searching) ?></div>
</div>
<?php wk_page_end(['search']);
