<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$naming = wk_naming();

wk_page_start('Settings', ['system', 'settings'], 'settings');
?>
<div class="page-toolbar">
  <div class="toolbar-section">
    <?= wk_toolbar_button('check', 'Save Changes', ['class' => 'js-save', 'form' => 'naming-form', 'type' => 'submit']) ?>
    <?= wk_toolbar_button('refresh', 'Reset to Defaults', ['class' => 'js-reset']) ?>
  </div>
</div>
<div class="content-body system">
  <section id="naming" class="card">
    <h2>Media Management <span class="muted">· file and folder naming</span></h2>
    <p class="muted help">Used by <strong>Rename</strong> on a series page and by <strong>Auto Rename</strong> in the library. Renaming only ever happens when you press it, after a preview. Scans never rename anything.</p>

    <form id="naming-form" class="form naming-form js-naming" data-defaults="<?= h(json_encode(WK_NAMING_DEFAULTS)) ?>">
      <?php foreach (WK_NAMING_FIELDS as $field => $label): ?>
        <label class="field">
          <span><?= h($label) ?></span>
          <input class="input js-template" name="<?= h($field) ?>" value="<?= h($naming[$field]) ?>" spellcheck="false" autocomplete="off">
        </label>
      <?php endforeach ?>
      <label class="field">
        <span>Colon replacement</span>
        <select name="colon" class="js-template">
          <?php foreach (WK_NAMING_COLON as $key => $label): ?>
            <option value="<?= h($key) ?>"<?= $naming['colon'] === $key ? ' selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach ?>
        </select>
      </label>
      <p class="form-error js-naming-error" hidden></p>
    </form>

    <h3>Examples</h3>
    <dl class="info-list naming-examples js-examples">
      <?php foreach (wk_naming_examples($naming) as $label => $path): ?>
        <div><dt><?= h($label) ?></dt><dd><code><?= h($path) ?></code></dd></div>
      <?php endforeach ?>
    </dl>

    <h3>Tokens <span class="muted">click one to insert it into the field you were editing</span></h3>
    <div class="table-wrap">
      <table class="table tokens">
        <tbody>
          <?php foreach (WK_NAMING_TOKENS as $token => [$desc, $example]): ?>
            <tr>
              <td><button type="button" class="token js-token" data-token="{<?= h($token) ?>}">{<?= h($token) ?>}</button></td>
              <td><?= h($desc) ?></td>
              <td class="muted"><?= $example !== '' ? h($example) : '<em>blank</em>' ?></td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
    <p class="muted help">Characters that aren't allowed in file names on Windows shares are replaced, and leftovers from blank tokens such as <code>()</code> or a dangling <code>-</code> are removed. Folder and file names are a single level; use the series and volume folder fields for structure.</p>
  </section>
</div>
<?php wk_page_end(['settings']);
