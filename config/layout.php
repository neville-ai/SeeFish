<?php
declare(strict_types=1);

/**
 * Layout bersama halaman aplikasi SeeFish (setelah login).
 */

function seefish_nav_items(): array
{
    return [
        ['section' => 'Menu Utama'],
        ['key' => 'dashboard', 'href' => 'dashboard', 'icon' => 'fa-chart-line', 'label' => 'Dashboard'],
        ['section' => 'Budidaya'],
        ['key' => 'kolam', 'href' => 'kolam', 'icon' => 'fa-water', 'label' => 'Informasi Kolam'],
        ['key' => 'siklus', 'href' => 'siklus', 'icon' => 'fa-fish-fins', 'label' => 'Siklus Budidaya'],
        ['section' => 'Operasional'],
        ['key' => 'tebar', 'href' => 'tebar', 'icon' => 'fa-seedling', 'label' => 'Tebar Awal'],
        ['key' => 'pakan', 'href' => 'pakan', 'icon' => 'fa-bowl-food', 'label' => 'Jadwal & Pakan'],
        ['key' => 'mortalitas', 'href' => 'mortalitas', 'icon' => 'fa-skull-crossbones', 'label' => 'Catatan Kematian'],
        ['key' => 'panen', 'href' => 'panen', 'icon' => 'fa-fish', 'label' => 'Catatan Panen'],
        ['section' => 'Pelaporan'],
        ['key' => 'estimasi', 'href' => 'estimasi', 'icon' => 'fa-clock-rotate-left', 'label' => 'Estimasi Ikan'],
        ['key' => 'riwayat', 'href' => 'riwayat', 'icon' => 'fa-file-lines', 'label' => 'Riwayat & Koreksi'],
        ['key' => 'log', 'href' => 'log', 'icon' => 'fa-clipboard-list', 'label' => 'Log Aktivitas'],
        ['key' => 'ekspor', 'href' => 'ekspor', 'icon' => 'fa-file-csv', 'label' => 'Ekspor CSV'],
    ];
}

function seefish_security_headers(bool $allowInline = true): void
{
    if (headers_sent()) {
        return;
    }

    $script = $allowInline
        ? "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self';"
        : "default-src 'self'; script-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self';";

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Content-Security-Policy: ' . $script);
}

/**
 * @param array{
 *   nav: string,
 *   title: string,
 *   heading?: string,
 *   subtitle?: string,
 *   breadcrumbs?: list<array{0: string, 1?: string}>,
 *   actions?: string,
 *   query_ok?: bool,
 *   query_error?: string
 * } $page
 */
function seefish_app_start(array $page): void
{
    seefish_security_headers(true);

    $nav = (string) ($page['nav'] ?? '');
    $title = (string) ($page['title'] ?? 'SeeFish');
    $heading = (string) ($page['heading'] ?? preg_replace('/\s*-\s*SeeFish$/', '', $title));
    $subtitle = (string) ($page['subtitle'] ?? '');
    $breadcrumbs = $page['breadcrumbs'] ?? [];
    $actions = (string) ($page['actions'] ?? '');
    $queryOk = array_key_exists('query_ok', $page) ? (bool) $page['query_ok'] : true;
    $queryError = (string) ($page['query_error'] ?? 'Data tidak dapat dimuat. Periksa koneksi database lalu muat ulang halaman.');
    $username = (string) ($_SESSION['username'] ?? 'Pengguna');
    $userRole = (string) ($_SESSION['role'] ?? 'Admin Kolam');
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo e($title); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="assets/css/vars.css">
<link rel="stylesheet" href="assets/css/dashboard.css">
<link rel="stylesheet" href="assets/css/logo.css">
</head>
<body class="app-body">
<a class="db-skip" href="#konten-utama">Lewati ke konten</a>
<div class="db-backdrop" id="sidebarBackdrop" hidden></div>
<div class="db-wrap">
  <aside class="db-sidebar" id="appSidebar">
    <div class="db-logo">
      <span class="seefish-logo-mark seefish-logo-mark--nav">
        <img src="assets/SeeFish%20Logo.png" alt="SeeFish Logo" class="seefish-logo-img">
      </span>
      <span class="db-logo-name">SeeFish</span>
    </div>
    <?php foreach (seefish_nav_items() as $item): ?>
      <?php if (isset($item['section'])): ?>
        <div class="db-nav-section"><?php echo e($item['section']); ?></div>
      <?php else: ?>
        <a href="<?php echo e($item['href']); ?>" class="db-nav-item<?php echo $item['key'] === $nav ? ' active' : ''; ?>">
          <i class="fa-solid <?php echo e($item['icon']); ?>"></i> <?php echo e($item['label']); ?>
        </a>
      <?php endif; ?>
    <?php endforeach; ?>
    <div class="db-sidebar-spacer"></div>
    <div class="db-sidebar-footer">
      <div class="db-sidebar-uname"><i class="fa-solid fa-user db-user-icon"></i><?php echo e($username); ?></div>
      <div class="db-sidebar-role"><?php echo e($userRole); ?></div>
    </div>
    <form method="POST" action="logout" class="logout-form">
      <?php csrf_input(); ?>
      <button type="submit" class="db-nav-item logout-btn">
        <i class="fa-solid fa-right-from-bracket"></i> Logout
      </button>
    </form>
  </aside>
  <main class="db-main" id="konten-utama">
    <?php if ($breadcrumbs !== []): ?>
      <nav class="db-crumb" aria-label="Breadcrumb">
        <?php foreach ($breadcrumbs as $i => $crumb): ?>
          <?php if ($i > 0): ?><span class="db-crumb-sep">/</span><?php endif; ?>
          <?php if (isset($crumb[1])): ?>
            <a href="<?php echo e($crumb[1]); ?>"><?php echo e($crumb[0]); ?></a>
          <?php else: ?>
            <span><?php echo e($crumb[0]); ?></span>
          <?php endif; ?>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>
    <div class="db-topbar">
      <div class="topbar-flex">
        <button type="button" class="db-menu-btn" id="sidebarToggle" aria-label="Buka menu" aria-controls="appSidebar" aria-expanded="false">
          <i class="fa-solid fa-bars"></i>
        </button>
        <div>
          <h1 class="db-page-title"><?php echo e($heading); ?></h1>
          <?php if ($subtitle !== ''): ?>
            <p class="db-page-sub"><?php echo e($subtitle); ?></p>
          <?php endif; ?>
        </div>
      </div>
      <div class="topbar-flex">
        <?php echo $actions; ?>
        <div class="db-live-badge">
          <span class="db-dot"></span>
          <?php echo e($username); ?>
        </div>
        <form method="POST" action="logout" class="logout-form logout-form-mobile">
          <?php csrf_input(); ?>
          <button type="submit" class="db-btn db-btn-ghost db-logout-mobile" aria-label="Logout">
            <i class="fa-solid fa-right-from-bracket"></i>
          </button>
        </form>
      </div>
    </div>
    <?php if (!$queryOk): ?>
      <div class="db-error-state" role="alert">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <h3>Gagal memuat data</h3>
        <p><?php echo e($queryError); ?></p>
      </div>
    <?php endif; ?>
    <?php
}

function seefish_app_end(): void
{
    ?>
  </main>
</div>
<script src="assets/js/dashboard.js"></script>
</body>
</html>
    <?php
}

function seefish_flash(?string $success, ?string $error): void
{
    if ($success !== null && $success !== '') {
        echo '<div class="db-notif db-notif-ok" role="status"><i class="fa-solid fa-check"></i> ' . e($success) . '</div>';
    }
    if ($error !== null && $error !== '') {
        echo '<div class="db-notif db-notif-err" role="alert"><i class="fa-solid fa-xmark"></i> ' . e($error) . '</div>';
    }
}

/**
 * @param array{icon?: string, title: string, text: string, action_href?: string, action_label?: string, action_onclick?: string} $opts
 */
function seefish_empty(array $opts): void
{
    $icon = $opts['icon'] ?? 'fa-inbox';
    echo '<div class="db-empty-state">';
    echo '<i class="fa-solid ' . e($icon) . '"></i>';
    echo '<h3>' . e($opts['title']) . '</h3>';
    echo '<p>' . e($opts['text']) . '</p>';
    if (!empty($opts['action_onclick'])) {
        echo '<button type="button" class="db-btn db-btn-primary" style="margin-top:12px" onclick="' . e($opts['action_onclick']) . '"><i class="fa-solid fa-plus"></i> ' . e($opts['action_label'] ?? 'Tambah') . '</button>';
    } elseif (!empty($opts['action_href'])) {
        echo '<a href="' . e($opts['action_href']) . '" class="db-btn db-btn-primary" style="margin-top:12px">' . e($opts['action_label'] ?? 'Lanjutkan') . '</a>';
    }
    echo '</div>';
}
