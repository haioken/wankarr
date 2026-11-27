<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
$me = wk_require_user();

$book = wk_book(wk_int($_GET['id'] ?? null) ?? 0);
if ($book !== null && !wk_series_accessible((int) $book['series_id'])) {
    $book = null;
}
if ($book === null) {
    http_response_code(404);
    wk_head('Not found');
    echo '<div class="empty-state">' . wk_icon('warning') . '<h2>Volume not found</h2><p>It may have been moved or removed.</p><a class="button" href="./">Back to library</a></div>';
    wk_foot();
    exit;
}

$title = $book['title'];
$backHref = 'series.php?id=' . (int) $book['series_id'];

wk_head("{$book['series_title']} – {$book['title']}", ['reader'], 'reader-page');
?>
<div class="reader ui-visible" id="reader"
     data-id="<?= (int) $book['id'] ?>"
     data-default-direction="<?= h(wk_config()['default_direction']) ?>">

  <header class="reader-bar reader-top">
    <a class="reader-btn js-back" href="<?= h($backHref) ?>" title="Back to series" aria-label="Back to series"><?= wk_icon('back') ?></a>
    <div class="reader-title">
      <div class="reader-series js-series"><?= h($book['series_title']) ?></div>
      <div class="reader-name js-title"><?= h($title) ?></div>
    </div>
    <div class="reader-actions">
      <a class="reader-btn js-prev-book" hidden title="Previous chapter" aria-label="Previous chapter"><?= wk_icon('skip-back') ?></a>
      <a class="reader-btn js-next-book" hidden title="Next chapter" aria-label="Next chapter"><?= wk_icon('skip-fwd') ?></a>
      <button type="button" class="reader-btn js-settings-toggle" title="Reading options" aria-label="Reading options" aria-expanded="false"><?= wk_icon('sliders') ?></button>
      <button type="button" class="reader-btn js-fullscreen" title="Fullscreen (F)" aria-label="Fullscreen"><?= wk_icon('expand', 'icon fs-enter') ?><?= wk_icon('shrink', 'icon fs-exit') ?></button>
      <?= wk_theme_button('reader-btn') ?>
    </div>
  </header>

  <div class="reader-settings js-settings" hidden data-no-flip>
    <div class="setting">
      <div class="setting-label">Reading direction</div>
      <div class="segmented" role="radiogroup" data-pref="direction">
        <button type="button" data-value="rtl">Right to left</button>
        <button type="button" data-value="ltr">Left to right</button>
      </div>
    </div>
    <div class="setting">
      <div class="setting-label">Page layout</div>
      <div class="segmented" role="radiogroup" data-pref="layout">
        <button type="button" data-value="auto">Auto</button>
        <button type="button" data-value="single">Single</button>
        <button type="button" data-value="double">Spread</button>
      </div>
    </div>
    <label class="setting setting-check">
      <input type="checkbox" class="js-cover-alone">
      <span>
        <span class="setting-label">Cover on its own</span>
        <span class="setting-help">Turn off if spreads look split across pages (this volume only)</span>
      </span>
    </label>
    <div class="setting-help keys">
      <kbd>←</kbd><kbd>→</kbd> turn · <kbd>Space</kbd> next · <kbd>Home</kbd>/<kbd>End</kbd> · <kbd>F</kbd> fullscreen · <kbd>M</kbd> menu
    </div>
  </div>

  <main class="reader-stage js-stage" aria-label="Book"></main>

  <button type="button" class="reader-side reader-side-l js-side" data-side="L" aria-label="Turn left page" tabindex="-1"><?= wk_icon('chev-left') ?></button>
  <button type="button" class="reader-side reader-side-r js-side" data-side="R" aria-label="Turn right page" tabindex="-1"><?= wk_icon('chev-right') ?></button>

  <footer class="reader-bar reader-bottom" data-no-flip>
    <span class="reader-count js-count">–</span>
    <input type="range" class="reader-slider js-slider" min="0" max="0" value="0" step="1" aria-label="Page">
    <span class="reader-count js-total">–</span>
  </footer>

  <div class="reader-overlay js-loading">
    <div class="spinner" aria-label="Loading"></div>
  </div>

  <div class="reader-overlay js-error" hidden>
    <div class="empty-state"><?= wk_icon('warning') ?><h2>Unable to open volume</h2><p class="js-error-text"></p><a class="button" href="<?= h($backHref) ?>">Back</a></div>
  </div>

  <div class="reader-overlay js-end" hidden data-no-flip>
    <div class="empty-state end-card">
      <?= wk_icon('book') ?>
      <h2>End of <span class="js-end-title"></span></h2>
      <p class="js-end-next-label muted"></p>
      <div class="end-actions">
        <button type="button" class="button js-end-close">Keep reading</button>
        <a class="button js-end-library" href="<?= h($backHref) ?>">Back to series</a>
        <a class="button primary js-end-next" hidden>Next chapter</a>
      </div>
    </div>
  </div>


  <div class="reader-toast js-toast" role="status" aria-live="polite"></div>
</div>
<?php wk_foot(['reader']);
