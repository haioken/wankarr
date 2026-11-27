<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$sort = array_key_exists($_GET['sort'] ?? '', WK_SERIES_SORTS) ? $_GET['sort'] : 'title';
$match = in_array($_GET['match'] ?? '', ['matched', 'unmatched', 'pending', 'ignored'], true) ? $_GET['match'] : null;
$rating = array_key_exists($_GET['rating'] ?? '', WK_CONTENT_RATINGS) ? $_GET['rating'] : null;
$libraryId = wk_int($_GET['library'] ?? null);
$hidden = !empty($_GET['hidden']);
$opts = ['sort' => $sort, 'match' => $match, 'rating' => $rating, 'library' => $libraryId, 'hidden' => $hidden ? 1 : null];

$libs = wk_libraries();
$series = wk_series_list($opts);
$bookIds = wk_series_book_ids(array_map('intval', array_column($series, 'id')));
$stats = wk_library_stats();
$tasks = array_column(wk_tasks_state(), null, 'name');
$scanning = ($tasks['scan']['running'] ?? false) || ($tasks['scan']['queued'] ?? false);

$url = fn(array $change) => './?' . http_build_query(array_filter(array_merge($opts, $change), fn($v) => $v !== null && $v !== '' && $v !== 'title'));
$active = $match === 'unmatched' ? 'review' : ($libraryId ? "library-$libraryId" : 'library');
$title = $hidden ? 'Hidden Series' : ($match === 'unmatched' ? 'Needs Review' : ($libraryId ? ($libs[$libraryId]['name'] ?? 'Library') : 'Library'));
$filterLabels = [
    '' => 'All',
    'matched' => 'Matched',
    'unmatched' => 'Needs review',
    'pending' => 'Not yet searched',
    'ignored' => 'Matching disabled',
];
$plural = fn(int $n, string $word) => $n . ' ' . $word . ($n === 1 ? '' : 's');

wk_page_start($title, ['library'], $active);
?>
<div class="library-page" data-hidden-view="<?= $hidden ? '1' : '0' ?>">
<div class="page-toolbar js-toolbar">
  <div class="toolbar-section toolbar-default">
    <?php if ($hidden): ?>
      <?= wk_toolbar_button('back', 'Library', ['href' => './']) ?>
    <?php else: ?>
      <?= wk_toolbar_button('refresh', 'Update Library', ['class' => 'js-run-task' . ($scanning ? ' is-spinning' : ''), 'data-task' => 'scan', 'title' => 'Scan library folders for new, changed and removed volumes']) ?>
      <?= wk_toolbar_button('match', 'Refresh Metadata', ['class' => 'js-run-task' . (($tasks['metadata']['running'] ?? false) ? ' is-spinning' : ''), 'data-task' => 'metadata', 'title' => 'Match new series and refresh stale metadata']) ?>
      <?= wk_toolbar_button('open', 'Import', ['href' => 'import.php', 'title' => 'Upload volumes into the library']) ?>
      <?= wk_toolbar_button('eye-off', $stats['hidden'] ? "Hidden ({$stats['hidden']})" : 'Hidden', ['href' => './?hidden=1', 'title' => 'Manage hidden series']) ?>
    <?php endif ?>
    <?= wk_toolbar_button('select', 'Select', ['class' => 'js-select-mode', 'title' => 'Select series for bulk actions']) ?>
  </div>
  <div class="toolbar-section toolbar-selection" hidden>
    <span class="selection-count js-selection-count">0 selected</span>
    <?= wk_toolbar_button('select', 'Select All', ['class' => 'js-select-all']) ?>
    <?= wk_toolbar_button('close', 'Clear', ['class' => 'js-select-clear']) ?>
    <?php if ($hidden): ?>
      <?= wk_toolbar_button('eye', 'Unhide', ['class' => 'js-bulk', 'data-action' => 'unhide']) ?>
    <?php else: ?>
      <?= wk_toolbar_button('refresh', 'Rescan', ['class' => 'js-bulk', 'data-action' => 'rescan']) ?>
      <?= wk_toolbar_button('rename', 'Auto Rename', ['class' => 'js-bulk', 'data-action' => 'rename']) ?>
    <?php endif ?>
    <?= wk_toolbar_button('trash', 'Delete', ['class' => 'js-bulk danger', 'data-action' => 'delete']) ?>
  </div>
  <div class="toolbar-section toolbar-right">
    <details class="menu">
      <summary class="toolbar-button"><?= wk_icon('sort') ?><span>Sort</span></summary>
      <div class="menu-items">
        <?php foreach (WK_SERIES_SORTS as $key => [$label]): ?>
          <a class="menu-item<?= $sort === $key ? ' is-selected' : '' ?>" href="<?= h($url(['sort' => $key])) ?>"><?= h($label) ?></a>
        <?php endforeach ?>
      </div>
    </details>
    <details class="menu">
      <summary class="toolbar-button<?= ($match || $rating) ? ' is-active' : '' ?>"><?= wk_icon('filter') ?><span>Filter</span></summary>
      <div class="menu-items">
        <div class="menu-heading">Metadata</div>
        <?php foreach ($filterLabels as $key => $label): ?>
          <a class="menu-item<?= ($match ?? '') === $key ? ' is-selected' : '' ?>" href="<?= h($url(['match' => $key ?: null])) ?>"><?= h($label) ?></a>
        <?php endforeach ?>
        <div class="menu-heading">Content rating</div>
        <a class="menu-item<?= $rating === null ? ' is-selected' : '' ?>" href="<?= h($url(['rating' => null])) ?>">Any</a>
        <?php foreach (WK_CONTENT_RATINGS as $key => $label): ?>
          <a class="menu-item<?= $rating === $key ? ' is-selected' : '' ?>" href="<?= h($url(['rating' => $key])) ?>"><?= h($label) ?></a>
        <?php endforeach ?>
      </div>
    </details>
    <details class="menu">
      <summary class="toolbar-button"><?= wk_icon('image') ?><span>Poster Size</span></summary>
      <div class="menu-items menu-slider">
        <label>
          <span class="menu-heading">Poster size</span>
          <input type="range" class="js-poster-size" min="90" max="320" step="1" aria-label="Poster size">
        </label>
      </div>
    </details>
  </div>
</div>

<div class="content-body">
  <?php if ($hidden): ?>
    <p class="muted page-intro">Hidden series stay in the database and keep picking up new volumes when the library is scanned, but they don't appear in the library or in search. Select some to unhide or delete them.</p>
  <?php endif ?>

  <?php if (!array_filter($libs, fn($l) => $l['available'])): ?>
    <div class="empty-state">
      <?= wk_icon('library') ?>
      <h2>No library available</h2>
      <p>Point <code>config.php</code>, <code>config.local.php</code> or the <code>WANKARR_LIBRARY</code> environment variable at a folder of manga.</p>
      <?php foreach ($libs as $l): ?><p class="muted"><code><?= h($l['path']) ?></code> was not found.</p><?php endforeach ?>

    </div>
  <?php elseif (!$series && !$hidden && $stats['series'] === 0): ?>
    <div class="empty-state">
      <?php if ($scanning): ?>
        <div class="spinner"></div>
        <h2>Scanning your library…</h2>
        <p class="js-scan-message"><?= h($tasks['scan']['message'] ?? '') ?></p>
      <?php else: ?>
        <?= wk_icon('folder') ?>
        <h2>Nothing here yet</h2>
        <p>Import volumes from this device, or add folders of CBZ, CBR, CB7 archives or images to your library and update it.</p>
        <div class="end-actions"><a class="button primary" href="import.php">Import</a> <button type="button" class="button js-run-task" data-task="scan">Update Library</button></div>
      <?php endif ?>
    </div>
  <?php elseif (!$series): ?>
    <div class="empty-state"><?= wk_icon($hidden ? 'eye' : 'filter') ?><h2><?= $hidden ? 'No hidden series' : 'No series match these filters' ?></h2><a class="button" href="./">Back to the library</a></div>
  <?php else: ?>
    <div class="poster-grid js-grid js-selectable">
      <?php foreach ($series as $s):
          $id = (int) $s['id'];
          $books = (int) $s['book_count'];
          $sub = array_filter([$s['year'], $plural($books, 'volume')]);
      ?>
        <div class="poster-cell" data-series="<?= $id ?>" data-title="<?= h($s['title']) ?>">
          <a class="poster" href="series.php?id=<?= $id ?>" data-books="<?= h(implode(',', $bookIds[$id] ?? [])) ?>">
            <div class="poster-art">
              <div class="poster-placeholder"><?= wk_icon('book') ?></div>
              <img src="api/cover.php?series=<?= $id ?>" alt="" loading="lazy" decoding="async">
              <?php if ($s['match_status'] === 'unmatched'): ?>
                <span class="poster-badge badge-warning" title="No confident metadata match — review it"><?= wk_icon('warning', 'icon icon-sm') ?></span>
              <?php elseif (in_array($s['content_rating'], ['erotica', 'pornographic'], true)): ?>
                <span class="poster-badge badge-danger"><?= h(WK_CONTENT_RATINGS[$s['content_rating']]) ?></span>
              <?php endif ?>
            </div>
            <div class="poster-progress"><span></span></div>
            <div class="poster-title" title="<?= h($s['title']) ?>"><?= h($s['title']) ?></div>
            <div class="poster-sub"><?= h(implode(' · ', $sub)) ?><?php if ($s['score']): ?> · <span class="poster-score"><?= wk_icon('star', 'icon icon-xs') ?><?= h(number_format((float) $s['score'], 1)) ?></span><?php endif ?></div>
          </a>
          <label class="poster-check" title="Select">
            <input type="checkbox" class="js-select" value="<?= $id ?>" aria-label="Select <?= h($s['title']) ?>">
            <span><?= wk_icon('check', 'icon icon-sm') ?></span>
          </label>
        </div>
      <?php endforeach ?>
    </div>
  <?php endif ?>
</div>

<footer class="page-footer">
  <span><?= count($series) ?> series shown</span>
  <span><?= number_format($stats['series']) ?> series · <?= number_format($stats['books']) ?> volumes · <?= number_format($stats['pages']) ?> pages · <?= h(wk_bytes($stats['bytes'])) ?></span>
  <?php if ($stats['unmatched']): ?><a href="./?match=unmatched"><?= $stats['unmatched'] ?> need review</a><?php endif ?>
  <?php if ($stats['hidden'] && !$hidden): ?><a href="./?hidden=1"><?= $stats['hidden'] ?> hidden</a><?php endif ?>
</footer>
</div>
<?php wk_page_end(['library']);
