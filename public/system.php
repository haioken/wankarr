<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
$me = wk_require_admin();

$tasks = wk_tasks_state();
$stats = wk_library_stats();
$libCounts = [];
foreach (wk_all('SELECT library_id, COUNT(DISTINCT series_id) AS series, COUNT(*) AS books FROM books GROUP BY library_id') as $r) {
    $libCounts[(int) $r['library_id']] = $r;
}
$dbVersion = (string) wk_db()->getAttribute(PDO::ATTR_SERVER_VERSION);
$tools = ['bsdtar' => 'CBR / CB7 / CBT', '7z' => 'CB7 / CBR fallback', 'unrar' => 'CBR fallback'];
$log = wk_log_tail(300);

wk_page_start('System', ['system'], 'system');
?>
<div class="page-toolbar">
  <div class="toolbar-section">
    <?= wk_toolbar_button('refresh', 'Refresh', ['href' => 'system.php']) ?>
  </div>
</div>
<div class="content-body system">

  <section id="status" class="card">
    <h2>Status</h2>
    <dl class="info-list">
      <div><dt>Version</dt><dd><?= h(WK_VERSION) ?></dd></div>
      <div><dt>PHP</dt><dd><?= h(PHP_VERSION) ?> (<?= h(PHP_SAPI) ?>)</dd></div>
      <div><dt>Database</dt><dd><?= h(wk_db_driver() === 'mysql' ? (stripos($dbVersion, 'mariadb') !== false ? 'MariaDB' : 'MySQL') : 'SQLite') ?> <?= h($dbVersion) ?> · schema v<?= h(wk_setting('schema_version')) ?></dd></div>
      <div><dt>Data folder</dt><dd><code><?= h(realpath(wk_config()['data_dir']) ?: wk_config()['data_dir']) ?></code></dd></div>
      <div><dt>Metadata</dt><dd>Tenrai (MyAnimeList) · content ratings from MangaDex</dd></div>
      <div><dt>Library</dt><dd><?= number_format($stats['series']) ?> series · <?= number_format($stats['books']) ?> volumes · <?= number_format($stats['pages']) ?> pages · <?= h(wk_bytes($stats['bytes'])) ?></dd></div>
      <div><dt>Matching</dt><dd><?= $stats['matched'] ?> matched · <a href="./?match=unmatched"><?= $stats['unmatched'] ?> need review</a> · <?= $stats['pending'] ?> pending</dd></div>
    </dl>

    <h3>Libraries</h3>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Name</th><th>Path</th><th class="num">Series</th><th class="num">Volumes</th><th>State</th></tr></thead>
        <tbody>
          <?php foreach (wk_libraries() as $lib): $c = $libCounts[(int) $lib['id']] ?? ['series' => 0, 'books' => 0]; ?>
            <tr>
              <td><a href="./?library=<?= (int) $lib['id'] ?>"><?= h($lib['name']) ?></a></td>
              <td><code><?= h($lib['path']) ?></code></td>
              <td class="num"><?= (int) $c['series'] ?></td>
              <td class="num"><?= (int) $c['books'] ?></td>
              <td><?= $lib['available'] ? '<span class="state ok">Available</span>' : '<span class="state bad">Not found</span>' ?></td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>

    <?php if ($orphans = wk_orphan_libraries()): ?>
      <div class="alert">
        <?= wk_icon('warning') ?>
        <div><strong><?= count($orphans) ?> library <?= count($orphans) === 1 ? 'is' : 'are' ?> no longer configured</strong>
          (<?= h(implode(', ', array_column($orphans, 'name'))) ?>). Their series are hidden but kept, in case the path was changed by mistake.
          To delete them for good, run <code>bin/wankarr prune --yes</code>.</div>
      </div>
    <?php endif ?>

    <h3>Archive tools</h3>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Tool</th><th>Used for</th><th>State</th></tr></thead>
        <tbody>
          <tr><td>ext-zip</td><td>CBZ / ZIP</td><td><?= extension_loaded('zip') ? '<span class="state ok">Available</span>' : '<span class="state bad">Missing</span>' ?></td></tr>
          <?php foreach ($tools as $tool => $use): ?>
            <tr><td><?= h($tool) ?></td><td><?= h($use) ?></td><td><?= wk_tool($tool) ? '<span class="state ok">' . h(wk_tool($tool)) . '</span>' : '<span class="state muted">Not installed</span>' ?></td></tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
  </section>

  <section id="tasks" class="card">
    <h2>Scheduled Tasks</h2>
    <div class="table-wrap">
      <table class="table js-tasks">
        <thead><tr><th>Name</th><th>Interval</th><th>Last Execution</th><th>Last Duration</th><th>Next Execution</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($tasks as $t): ?>
            <tr data-task="<?= h($t['name']) ?>">
              <td><?= h($t['label']) ?></td>
              <td><?= h(wk_interval((int) $t['interval_minutes'])) ?></td>
              <td><?= h(wk_ago($t['last_finished'] ? (int) $t['last_finished'] : null)) ?></td>
              <td><?= $t['last_duration'] !== null ? h(number_format((float) $t['last_duration'], 1)) . ' s' : '–' ?></td>
              <td><?= $t['running'] ? 'Running' : ($t['queued'] ? 'Queued' : h(wk_ago((int) $t['next_run']))) ?></td>
              <td class="task-message<?= $t['last_error'] ? ' is-error' : '' ?>"><?= h((string) $t['message']) ?></td>
              <td class="actions"><button type="button" class="icon-button js-run-task<?= ($t['running'] || $t['queued']) ? ' is-spinning' : '' ?>" data-task="<?= h($t['name']) ?>" title="Run now" aria-label="Run <?= h($t['label']) ?> now"><?= wk_icon('refresh') ?></button></td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
    <p class="muted help">Tasks run in a background PHP process. Page views start overdue ones automatically. For dependable scheduling, add a cron job (<code>*/5 * * * * php <?= h(realpath(WK_ROOT . '/bin/wankarr')) ?> tasks</code>) or run <code>bin/wankarr worker</code> as a service.</p>
  </section>

  <section id="logs" class="card">
    <h2>Logs <span class="muted">newest first</span></h2>
    <?php if ($log): ?>
      <pre class="log"><?php foreach ($log as $line): ?><span class="<?= preg_match('/failed:|error|unavailable|no confident/i', $line) ? 'log-warn' : '' ?>"><?= h($line) ?></span>
<?php endforeach ?></pre>
    <?php else: ?>
      <p class="muted">Nothing logged yet.</p>
    <?php endif ?>
  </section>
</div>
<?php wk_page_end(['system']);

function wk_interval(int $minutes): string
{
    return $minutes % 1440 === 0 ? ($minutes / 1440) . ' d' : ($minutes % 60 === 0 ? ($minutes / 60) . ' h' : "$minutes min");
}
