<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
$me = wk_require_admin();

$libs = array_values(array_filter(wk_libraries(), fn($l) => $l['available']));

wk_page_start('Import', ['library', 'import'], 'import');
?>
<div class="import-page js-import" data-libraries="<?= h(json_encode(array_map(fn($l) => ['id' => (int) $l['id'], 'name' => $l['name']], $libs))) ?>">
<div class="page-toolbar">
  <div class="toolbar-section">
    <label class="toolbar-button"><?= wk_icon('open') ?><span>Choose Files</span><input type="file" class="js-pick-files" multiple hidden accept=".cbz,.zip,.cbr,.rar,.cb7,.7z,.cbt,.tar"></label>
    <label class="toolbar-button"><?= wk_icon('folder') ?><span>Choose Folder</span><input type="file" class="js-pick-folder" webkitdirectory hidden></label>
    <?= wk_toolbar_button('trash', 'Discard', ['class' => 'js-discard', 'title' => 'Throw away the uploaded files and start over']) ?>
  </div>
  <?php if (count($libs) > 1): ?>
    <div class="toolbar-section toolbar-right">
      <label class="toolbar-select">Into
        <select class="js-library">
          <?php foreach ($libs as $l): ?><option value="<?= (int) $l['id'] ?>"><?= h($l['name']) ?></option><?php endforeach ?>
        </select>
      </label>
    </div>
  <?php endif ?>
</div>

<div class="content-body">
  <?php if (!$libs): ?>
    <div class="empty-state"><?= wk_icon('warning') ?><h2>No library available</h2><p>Configure a library folder before importing.</p></div>
  <?php else: ?>
    <div class="import-drop js-drop">
      <?= wk_icon('open') ?>
      <h2>Drop volumes here</h2>
      <p>CBZ, CBR, CB7, CBT archives, or folders of images (each folder becomes one volume).
        Files are matched to a series, renamed with your <a href="settings.php#naming">naming convention</a> and copied into
        <strong class="js-library-name"><?= h($libs[0]['name']) ?></strong>.</p>
      <div class="end-actions">
        <button type="button" class="button primary js-pick-files-btn">Choose files</button>
        <button type="button" class="button js-pick-folder-btn">Choose a folder</button>
      </div>
    </div>

    <ul class="import-uploads js-uploads" hidden></ul>
    <div class="import-status js-status" hidden></div>
    <div class="import-groups js-groups"></div>
    <div class="import-results js-results" hidden></div>
  <?php endif ?>
</div>

<div class="import-footer js-footer" hidden>
  <span class="js-summary"></span>
  <button type="button" class="button primary js-commit" disabled>Import</button>
</div>
</div>
<?php wk_page_end(['import']);
