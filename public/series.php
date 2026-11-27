<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
$me = wk_require_user();

$sid = wk_int($_GET['id'] ?? null) ?? 0;
$series = wk_series_accessible($sid) ? wk_series($sid) : null;
if ($series === null) {
    http_response_code(404);
    wk_page_start('Not found');
    echo '<div class="content-body"><div class="empty-state">' . wk_icon('warning') . '<h2>Series not found</h2><p>It may have been merged or removed.</p><a class="button" href="./">Back to library</a></div></div>';
    wk_page_end();
    exit;
}

$id = (int) $series['id'];
$m = $series['meta'];
$books = wk_series_books($id);
$admin = $me['role'] === 'admin';
$isHidden = wk_is_hidden((int) $me['id'], $id);
$bookProgress = wk_progress_for_books((int) $me['id'], array_column($books, 'id'));
$bookFraction = function (int $bid) use ($bookProgress): ?array {
    $p = $bookProgress[$bid] ?? null;
    if (!$p || !$p['total']) {
        return null;
    }
    $f = min(1, ($p['page'] + 1) / $p['total']);
    return ['fraction' => $f, 'finished' => $f >= 1];
};
$status = $series['match_status'];
$isMatched = in_array($status, ['matched', 'manual'], true) && $m;

$facts = [];
if ($m) {
    $years = $m['year'] ? (string) $m['year'] : '';
    if ($m['end_date']) {
        $end = substr($m['end_date'], 0, 4);
        $years .= $end !== $years ? "–$end" : '';
    } elseif ($m['status'] === 'Publishing' && $years) {
        $years .= '–';
    }
    $facts = array_filter([
        $years,
        $m['status'],
        $m['type'],
        $m['volumes'] ? $m['volumes'] . ' volumes' : null,
        $m['chapters'] ? $m['chapters'] . ' chapters' : null,
    ]);
} elseif ($series['local_year']) {
    $facts[] = $series['local_year'];
}

// Group people by role: "Story & Art", "Story", "Art", ...
$byRole = [];
foreach ($series['people'] as $p) {
    $byRole[$p['role']][] = $p;
}
$altTitles = $m ? array_values(array_filter($m['titles'], fn($t) => $t !== $series['title'])) : [];

wk_page_start($series['title'], ['library', 'series'], 'library');
?>
<div class="series-page" data-series="<?= $id ?>" data-title="<?= h($series['title']) ?>" data-library="<?= (int) $series['library_id'] ?>">

<div class="page-toolbar">
  <div class="toolbar-section">
    <?= wk_toolbar_button('play', 'Read', ['href' => $books ? 'read.php?id=' . (int) $books[0]['id'] : '#', 'class' => 'js-continue']) ?>
    <?= $isHidden
        ? wk_toolbar_button('eye', 'Unhide', ['class' => 'js-hide', 'data-hide' => '0', 'title' => 'Show this series in your library again'])
        : wk_toolbar_button('eye-off', 'Hide', ['class' => 'js-hide', 'data-hide' => '1', 'title' => 'Hide this series from your library (only for you)']) ?>
    <?php if ($admin): ?>
    <?= wk_toolbar_button('refresh', 'Refresh', ['class' => 'js-refresh', 'title' => $isMatched ? 'Re-fetch metadata' : 'Try automatic matching again']) ?>
    <?= wk_toolbar_button('match', 'Match…', ['class' => 'js-match', 'title' => 'Search the metadata provider and pick the right entry']) ?>
    <?= wk_toolbar_button('edit', 'Edit', ['class' => 'js-edit']) ?>
    <?= wk_toolbar_button('merge', 'Merge…', ['class' => 'js-merge', 'title' => 'Merge this series into another one']) ?>
    <?= wk_toolbar_button('rename', 'Rename…', ['class' => 'js-rename', 'title' => 'Preview renaming this series\' folders and files to the naming convention']) ?>
    <?php endif ?>
  </div>
  <div class="toolbar-section toolbar-right">
    <?php if ($m && $admin): ?>
      <?= wk_toolbar_button('unlink', 'Unmatch', ['class' => 'js-unmatch', 'title' => 'Remove metadata and stop automatic matching for this series']) ?>
    <?php endif ?>
  </div>
</div>

<header class="series-header">
  <div class="series-backdrop" style="background-image: url('api/cover.php?series=<?= $id ?>')"></div>
  <div class="series-header-inner">
    <div class="series-poster">
      <div class="poster-placeholder"><?= wk_icon('book') ?></div>
      <img src="api/cover.php?series=<?= $id ?>" alt="">
    </div>
    <div class="series-info">
      <h1 class="series-title"><?= h($series['title']) ?></h1>
      <?php if ($m && $m['title'] !== $series['title']): ?>
        <div class="series-alt"><?= h($m['title']) ?><?= $m['title_japanese'] ? ' · ' . h($m['title_japanese']) : '' ?></div>
      <?php elseif ($m && $m['title_japanese']): ?>
        <div class="series-alt"><?= h($m['title_japanese']) ?></div>
      <?php endif ?>

      <?php if ($facts): ?><div class="series-facts"><?= implode('<span class="dot">·</span>', array_map('h', array_map('strval', $facts))) ?></div><?php endif ?>

      <div class="series-chips">
        <?php if ($m && $m['score']): ?>
          <span class="chip chip-score" title="<?= h(number_format((int) $m['scored_by'])) ?> ratings on MyAnimeList"><?= wk_icon('star', 'icon icon-sm') ?><?= h(number_format((float) $m['score'], 2)) ?></span>
        <?php endif ?>
        <?php if ($m && $m['rank_overall']): ?><span class="chip">Rank #<?= h(number_format((int) $m['rank_overall'])) ?></span><?php endif ?>
        <?php if ($m && $m['popularity']): ?><span class="chip">Popularity #<?= h(number_format((int) $m['popularity'])) ?></span><?php endif ?>
        <?= $m ? wk_rating_badge($m['content_rating']) : '' ?>
        <?php if ($isHidden): ?><span class="chip">Hidden by you</span><?php endif ?>
        <?php if ($admin && $status === 'unmatched'): ?><span class="chip chip-warning">Needs review</span><?php endif ?>
        <?php if ($status === 'ignored'): ?><span class="chip">Matching disabled</span><?php endif ?>
        <?php if ($status === 'pending'): ?><span class="chip">Metadata pending</span><?php endif ?>
      </div>

      <?php if ($byRole): ?>
        <dl class="series-people">
          <?php foreach ($byRole as $role => $people): ?>
            <div><dt><?= h($role) ?></dt><dd><?= implode(', ', array_map(fn($p) => '<a href="search.php?person=' . (int) $p['id'] . '">' . h(wk_person_name($p['name'])) . '</a>', $people)) ?></dd></div>
          <?php endforeach ?>
          <?php if ($m['serializations']): ?><div><dt>Serialized in</dt><dd><?= h(implode(', ', $m['serializations'])) ?></dd></div><?php endif ?>
        </dl>
      <?php endif ?>

      <?php if ($series['genres']): ?>
        <div class="series-tags">
          <?php foreach ($series['genres'] as $g): ?>
            <a class="tag tag-<?= h($g['kind']) ?>" href="search.php?genre=<?= rawurlencode($g['name']) ?>"><?= h($g['name']) ?></a>
          <?php endforeach ?>
        </div>
      <?php endif ?>

      <?php if ($m && $m['synopsis']): ?>
        <div class="series-synopsis js-synopsis"><?= nl2br(h(trim(preg_replace('/\n*\[Written by MAL Rewrite\]\s*/', "\n", $m['synopsis'])))) ?></div>
      <?php endif ?>

      <?php if ($m && $m['links']): ?>
        <div class="series-links">
          <?php $names = array_count_values(array_column($m['links'], 'name'));
          foreach ($m['links'] as $l):
              // Disambiguate repeats ("Wikipedia" ×2) by language subdomain or host.
              $label = $l['name'];
              if ($names[$label] > 1 && ($host = parse_url($l['url'], PHP_URL_HOST))) {
                  $label .= ' (' . (preg_match('/^([a-z]{2})\./', $host, $mm) ? $mm[1] : $host) . ')';
              } ?>
            <a href="<?= h($l['url']) ?>" target="_blank" rel="noopener noreferrer"><?= wk_icon('link', 'icon icon-sm') ?><?= h($label) ?></a>
          <?php endforeach ?>
        </div>
      <?php endif ?>
    </div>
  </div>
</header>

<div class="content-body">
  <?php if ($admin && $status === 'unmatched'): ?>
    <div class="alert alert-warning">
      <?= wk_icon('warning') ?>
      <div>
        <strong>No confident metadata match.</strong>
        <?= $series['match_score'] !== null ? 'The closest result scored ' . h((string) round((float) $series['match_score'] * 100)) . '%.' : 'The search returned nothing.' ?>
        Pick the right entry manually, or rename the series and retry.
      </div>
      <button type="button" class="button primary js-match">Match…</button>
    </div>
  <?php endif ?>

  <h2 class="section-title">Volumes <span class="muted"><?= count($books) ?></span></h2>
  <div class="poster-grid book-grid js-grid">
    <?php foreach ($books as $b): $bid = (int) $b['id']; ?>
      <?php $bp = $bookProgress[$bid] ?? null; ?>
      <div class="poster book" data-book="<?= $bid ?>" data-page="<?= $bp ? (int) $bp['page'] : '' ?>" data-total="<?= $bp ? (int) $bp['total'] : '' ?>">
        <a class="poster-link" href="read.php?id=<?= $bid ?>" title="<?= h($b['rel_path']) ?>">
          <div class="poster-art">
            <div class="poster-placeholder"><?= wk_icon('book') ?></div>
            <img src="api/cover.php?book=<?= $bid ?>" alt="" loading="lazy" decoding="async">
            <span class="poster-badge"><?= h(['dir' => 'IMG', 'zip' => 'CBZ', 'rar' => 'CBR', '7z' => 'CB7', 'tar' => 'CBT'][$b['format']] ?? '') ?></span>
          </div>
          <?= wk_progress_bar($bookFraction($bid)) ?>
          <div class="poster-title"><?= h($b['title']) ?></div>
          <div class="poster-sub"><?= $b['page_count'] !== null ? (int) $b['page_count'] . ' pages · ' : '' ?><?= h(wk_bytes((int) $b['file_size'])) ?></div>
        </a>
        <?php if ($admin): ?><button type="button" class="poster-menu js-move" data-book="<?= $bid ?>" data-title="<?= h($b['title']) ?>" title="Move this volume to another series" aria-label="Move <?= h($b['title']) ?> to another series"><?= wk_icon('move', 'icon icon-sm') ?></button><?php endif ?>
      </div>
    <?php endforeach ?>
  </div>

  <details class="series-details">
    <summary>Details</summary>
    <dl>
      <?php if ($altTitles): ?><div><dt>Also known as</dt><dd><?= h(implode(' · ', $altTitles)) ?></dd></div><?php endif ?>
      <div><dt>Library</dt><dd><?= h($series['library']['name'] ?? '') ?></dd></div>
      <?php if ($admin && $series['folder']): ?><div><dt>Folder</dt><dd><code><?= h($series['folder']) ?></code></dd></div><?php endif ?>
      <div><dt>Added</dt><dd><?= h(date('Y-m-d', (int) $series['added_at'])) ?></dd></div>
      <div><dt>Metadata</dt><dd><?= h(match ($status) {
          'matched' => 'Matched automatically' . ($series['match_score'] !== null ? ' (' . round((float) $series['match_score'] * 100) . '% confidence)' : ''),
          'manual' => 'Matched manually',
          'unmatched' => 'No confident match',
          'ignored' => 'Matching disabled',
          default => 'Not searched yet',
      }) ?><?= $m ? ' · MyAnimeList #' . h($m['provider_id']) . ' · updated ' . h(wk_ago((int) $m['fetched_at'])) : '' ?></dd></div>
      <?php if ($m && $m['content_rating']): ?><div><dt>Content rating</dt><dd><?= h(WK_CONTENT_RATINGS[$m['content_rating']] ?? $m['content_rating']) ?> <span class="muted">(<?= $m['content_rating_source'] === 'mangadex' ? 'from MangaDex' : 'inferred from genres' ?>)</span></dd></div><?php endif ?>
      <?php if ($m && $m['background']): ?><div><dt>Background</dt><dd><?= nl2br(h($m['background'])) ?></dd></div><?php endif ?>
    </dl>
  </details>
</div>
</div>
<?php wk_page_end(['series']);
