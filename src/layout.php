<?php
declare(strict_types=1);

/** Shared page chrome (head, header, sidebar) and a small inline icon set. */

function wk_icon(string $name, string $class = 'icon'): string
{
    static $paths = [
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'library' => '<path d="M5 4h3v16H5zM10 4h3v16h-3z"/><path d="m15.5 4.8 2.9-.8 3.6 15.2-2.9.8z"/>',
        'folder' => '<path d="M3 6.5A1.5 1.5 0 0 1 4.5 5h4.2l2 2.2h8.8A1.5 1.5 0 0 1 21 8.7v9.8a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 18.5z"/>',
        'book' => '<path d="M12 6.5C10 5 7 4.5 3 5v13c4-.5 7 0 9 1.5 2-1.5 5-2 9-1.5V5c-4-.5-7 0-9 1.5zM12 6.5v13"/>',
        'open' => '<path d="M12 15V4m0 0L8 8m4-4 4 4"/><path d="M4 14v4.5A1.5 1.5 0 0 0 5.5 20h13a1.5 1.5 0 0 0 1.5-1.5V14"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon' => '<path d="M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5z"/>',
        'auto' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 3.5v17a8.5 8.5 0 0 0 0-17z" fill="currentColor"/>',
        'back' => '<path d="M19 12H5m6-6-6 6 6 6"/>',
        'chev-left' => '<path d="m15 5-7 7 7 7"/>',
        'chev-right' => '<path d="m9 5 7 7-7 7"/>',
        'chev-down' => '<path d="m6 9 6 6 6-6"/>',
        'skip-back' => '<path d="M6 5v14M18 5 9 12l9 7z"/>',
        'skip-fwd' => '<path d="M18 5v14M6 5l9 7-9 7z"/>',
        'sliders' => '<path d="M4 7h10M18 7h2M4 17h4M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/>',
        'expand' => '<path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/>',
        'shrink' => '<path d="M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5"/>',
        'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'refresh' => '<path d="M20 11a8 8 0 0 0-14.3-4.9L4 8m0-4v4h4M4 13a8 8 0 0 0 14.3 4.9L20 16m0 4v-4h-4"/>',
        'image' => '<rect x="3.5" y="4.5" width="17" height="15" rx="1.5"/><circle cx="9" cy="10" r="1.6"/><path d="m4 18 5-5 4 4 3-3 4 4"/>',
        'warning' => '<path d="M12 4 2.5 20h19z"/><path d="M12 10v4.5M12 17.2v.3"/>',
        'star' => '<path d="m12 3.5 2.6 5.4 5.9.8-4.3 4.1 1 5.8L12 16.9l-5.2 2.7 1-5.8-4.3-4.1 5.9-.8z" fill="currentColor"/>',
        'link' => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
        'edit' => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>',
        'merge' => '<path d="M6 4v4a6 6 0 0 0 6 6 6 6 0 0 1 6 6M18 4v4a6 6 0 0 1-3 5.2"/><path d="m3 7 3-3 3 3M15 7l3-3 3 3"/>',
        'move' => '<path d="M4 12h12m-4-4 4 4-4 4"/><path d="M20 5v14"/>',
        'match' => '<circle cx="10" cy="10" r="6"/><path d="m20 20-5.6-5.6M7.5 10l1.8 1.8L13 8.2"/>',
        'unlink' => '<path d="M9 15 15 9M10 6l1-1a4 4 0 0 1 5.7 5.7l-1 1M14 18l-1 1a4 4 0 0 1-5.7-5.7l1-1M4 4l16 16"/>',
        'play' => '<path d="M7 4.5v15l12-7.5z" fill="currentColor"/>',
        'system' => '<rect x="3.5" y="4" width="17" height="6" rx="1.2"/><rect x="3.5" y="14" width="17" height="6" rx="1.2"/><path d="M7 7h.01M7 17h.01"/>',
        'sort' => '<path d="M7 4v16m0 0-3-3m3 3 3-3M17 20V4m0 0-3 3m3-3 3 3"/>',
        'filter' => '<path d="M4 5h16l-6 7.5V19l-4 1v-7.5z"/>',
        'check' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'trash' => '<path d="M4 7h16M9 7V4.5h6V7M6.5 7l1 13h9l1-13M10 11v5.5M14 11v5.5"/>',
        'eye' => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off' => '<path d="M4 4l16 16M10.6 6.1A9.8 9.8 0 0 1 12 6c6 0 9.5 6 9.5 6a17 17 0 0 1-3 3.6M6.6 7.6A17 17 0 0 0 2.5 12s3.5 6 9.5 6c1.6 0 3-.4 4.2-1M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
        'select' => '<rect x="4" y="4" width="16" height="16" rx="2.5"/><path d="m8.5 12 2.5 2.5 4.5-5"/>',
        'rename' => '<path d="M4 7h7M4 12h5M4 17h7"/><path d="M14 19h3l4.5-4.5-3-3L14 16z"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
    ];
    $p = $paths[$name] ?? '';
    return '<svg class="' . h($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

/** The mascot: a shifty fellow in a balaclava. */
function wk_logo(string $class = 'logo-mark'): string
{
    return '<svg class="' . h($class) . '" viewBox="0 0 48 48" aria-hidden="true">'
        . '<defs><clipPath id="wk-logo-clip"><circle cx="24" cy="24" r="19.5"/></clipPath></defs>'
        . '<circle cx="24" cy="24" r="22" fill="#fff"/>'
        . '<circle cx="24" cy="24" r="19.5" fill="var(--themeBlue, #35c5f4)"/>'
        . '<g clip-path="url(#wk-logo-clip)">'
        // shoulders and the knitted mask
        . '<path d="M5 48c1.5-7.5 8.5-11 19-11s17.5 3.5 19 11z" fill="#1f2430"/>'
        . '<path d="M24 6.5c-8.6 0-13.5 6.3-13.5 14.5v5.5c0 8.3 5.6 14.5 13.5 14.5s13.5-6.2 13.5-14.5V21c0-8.2-4.9-14.5-13.5-14.5z" fill="#2b3040"/>'
        . '<path d="M17 9.5c-1 3-1.2 6-1 9M24 7.5v8.5M31 9.5c1 3 1.2 6 1 9" stroke="#3b4256" stroke-width="1.2" fill="none" stroke-linecap="round"/>'
        // eye slot, shifty eyes glancing sideways, heavy brows
        . '<rect x="13.5" y="17.2" width="21" height="7.6" rx="3.8" fill="#f2c18f"/>'
        . '<ellipse cx="19.4" cy="21.1" rx="2.9" ry="2.3" fill="#fff"/><ellipse cx="28.6" cy="21.1" rx="2.9" ry="2.3" fill="#fff"/>'
        . '<circle cx="21" cy="21.5" r="1.35" fill="#151515"/><circle cx="30.2" cy="21.5" r="1.35" fill="#151515"/>'
        . '<path d="M15.6 18.6l6.2-1M26.2 17.6l6.2 1.3" stroke="#151515" stroke-width="1.5" stroke-linecap="round"/>'
        // mouth hole with a smirk
        . '<ellipse cx="24" cy="31.2" rx="4.2" ry="3" fill="#f2c18f"/>'
        . '<path d="M21.6 31.4q2.6 1.7 5-.6" stroke="#7a3b2e" stroke-width="1.2" fill="none" stroke-linecap="round"/>'
        . '</g></svg>';
}

function wk_asset(string $path): string
{
    $file = WK_ROOT . '/public/assets/' . $path;
    $v = is_file($file) ? substr(md5((string) filemtime($file)), 0, 8) : WK_VERSION;
    return 'assets/' . $path . '?v=' . $v;
}

function wk_head(string $title, array $css = [], string $bodyClass = ''): void
{
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<title><?= h($title) ?> · wankarr</title>
<script>
  // Resolve the theme before first paint so there is no light/dark flash.
  try { const t = localStorage.getItem('wk:theme'); if (t === 'light' || t === 'dark') document.documentElement.dataset.theme = t; } catch {}
</script>
<link rel="icon" href="<?= h(wk_asset('img/logo.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= h(wk_asset('css/base.css')) ?>">
<?php foreach ($css as $sheet): ?>
<link rel="stylesheet" href="<?= h(wk_asset("css/$sheet.css")) ?>">
<?php endforeach ?>
</head>
<body class="<?= h($bodyClass) ?>">
<?php
}

function wk_theme_button(string $class = 'header-icon'): string
{
    return '<button type="button" class="' . h($class) . ' js-theme" title="Theme: auto" aria-label="Toggle theme">'
        . wk_icon('auto', 'icon theme-auto') . wk_icon('sun', 'icon theme-light') . wk_icon('moon', 'icon theme-dark')
        . '</button>';
}

function wk_header(): void
{
    ?>
<header class="page-header">
  <div class="header-logo">
    <button type="button" class="header-icon sidebar-toggle js-sidebar-toggle" aria-label="Menu"><?= wk_icon('menu') ?></button>
    <a href="./" class="brand"><?= wk_logo() ?><span class="brand-text"><span class="brand-name">wankarr</span><span class="brand-slogan">What are ya...</span></span></a>
  </div>
  <div class="header-actions">
    <?= wk_theme_button() ?>
  </div>
</header>
<?php
}

function wk_sidebar(string $active = 'library'): void
{
    $libs = wk_libraries();
    $items = [
        'library' => ['./', 'library', 'Library', count($libs) > 1
            ? array_map(fn($l) => ["./?library={$l['id']}", $l['name'], "library-{$l['id']}"], array_values($libs))
            : []],
        'review' => ['./?match=unmatched', 'match', 'Needs Review', []],
        'search' => ['search.php', 'search', 'Search', []],
        'import' => ['import.php', 'open', 'Import', []],
        'settings' => ['settings.php', 'settings', 'Settings', [
            ['settings.php#naming', 'Media Management', 'settings-naming'],
        ]],
        'system' => ['system.php', 'system', 'System', [
            ['system.php#status', 'Status', 'system-status'],
            ['system.php#tasks', 'Tasks', 'system-tasks'],
            ['system.php#logs', 'Logs', 'system-logs'],
        ]],
    ];
    ?>
<nav class="sidebar" aria-label="Main">
  <?php foreach ($items as $key => [$href, $icon, $label, $children]): ?>
    <div class="sidebar-section<?= $active === $key || str_starts_with($active, "$key-") ? ' is-open' : '' ?>">
      <a class="sidebar-item<?= $active === $key || str_starts_with($active, "$key-") ? ' is-active' : '' ?>" href="<?= h($href) ?>"><?= wk_icon($icon) ?><span><?= h($label) ?></span></a>
      <?php foreach ($children as [$chref, $clabel, $ckey]): ?>
        <a class="sidebar-child<?= $active === $ckey ? ' is-active' : '' ?>" href="<?= h($chref) ?>"><?= h($clabel) ?></a>
      <?php endforeach ?>
    </div>
  <?php endforeach ?>
  <div class="sidebar-footer">
    <div class="sidebar-activity js-activity" hidden><span class="spinner-sm"></span><span class="js-activity-text"></span></div>
    <div>v<?= h(WK_VERSION) ?></div>
  </div>
</nav>
<div class="sidebar-scrim js-sidebar-toggle"></div>
<?php
}

/** Header + sidebar + opening of the content column. Starts overdue tasks. */
function wk_page_start(string $title, array $css = [], string $active = 'library'): void
{
    wk_tasks_kick();
    wk_head($title, $css);
    wk_header();
    echo '<div class="page-body">';
    wk_sidebar($active);
    echo '<main class="page-content">';
}

function wk_page_end(array $modules = []): void
{
    echo '</main></div>';
    wk_foot($modules);
}

/** Sonarr-style toolbar button (link or button). */
function wk_toolbar_button(string $icon, string $label, array $attrs = []): string
{
    $tag = isset($attrs['href']) ? 'a' : 'button';
    if ($tag === 'button') {
        $attrs['type'] ??= 'button';
    }
    $attrs['class'] = trim('toolbar-button ' . ($attrs['class'] ?? ''));
    $html = '';
    foreach ($attrs as $k => $v) {
        $html .= ' ' . $k . '="' . h((string) $v) . '"';
    }
    return "<$tag$html>" . wk_icon($icon) . '<span>' . h($label) . "</span></$tag>";
}

function wk_foot(array $modules = []): void
{
    ?>
<script type="module" src="<?= h(wk_asset('js/app.js')) ?>"></script>
<?php foreach ($modules as $m): ?>
<script type="module" src="<?= h(wk_asset("js/$m.js")) ?>"></script>
<?php endforeach ?>
</body>
</html>
<?php
}

function wk_rating_badge(?string $rating): string
{
    if (!$rating || !isset(WK_CONTENT_RATINGS[$rating])) {
        return '';
    }
    return '<span class="rating-badge rating-' . h($rating) . '">' . h(WK_CONTENT_RATINGS[$rating]) . '</span>';
}

function wk_ago(?int $ts): string
{
    if (!$ts) {
        return 'Never';
    }
    $d = time() - $ts;
    $future = $d < 0;
    $d = abs($d);
    $s = match (true) {
        $d < 60 => $future ? 'in under a minute' : 'just now',
        $d < 3600 => intdiv($d, 60) . ' min',
        $d < 86400 => intdiv($d, 3600) . ' h',
        default => intdiv($d, 86400) . ' d',
    };
    if ($d < 60) {
        return $s;
    }
    return $future ? "in $s" : "$s ago";
}

function wk_bytes(int $n): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($n >= 1024 && $i < 4) {
        $n /= 1024;
        $i++;
    }
    return round($n, $i ? 1 : 0) . ' ' . $units[$i];
}
