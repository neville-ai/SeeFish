<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/config/koneksi.php';

$username = (string) ($_SESSION['username'] ?? 'Pengguna');
$userId = (int) $_SESSION['user_id'];
$userRole = (string) ($_SESSION['role'] ?? 'Admin Kolam');

$logs = [];

try {
    $stmt = $conn->prepare(
        "SELECT id, action, entity, entity_id, info, ip_address, created_at
         FROM activity_log
         WHERE user_id = ?
         ORDER BY id DESC
         LIMIT 100"
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} catch (Throwable $e) {
    error_log('Log fetch error: ' . $e->getMessage());
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self';");
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Log Aktivitas - SeeFish</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="assets/css/vars.css">
<link rel="stylesheet" href="assets/css/dashboard.css">
<link rel="stylesheet" href="assets/css/logo.css">
</head>
<body>

<div class="db-wrap">

  <!-- SIDEBAR -->
  <aside class="db-sidebar">
    <div class="db-logo">
      <span class="seefish-logo-mark seefish-logo-mark--nav">
        <img src="assets/SeeFish%20Logo.png" alt="SeeFish Logo" class="seefish-logo-img">
      </span>
      <span class="db-logo-name">SeeFish</span>
    </div>

    <div class="db-nav-section">Menu Utama</div>
    <a href="dashboard" class="db-nav-item">
      <i class="fa-solid fa-chart-line"></i> Dashboard
    </a>

    <div class="db-nav-section">Budidaya</div>
    <a href="kolam" class="db-nav-item">
      <i class="fa-solid fa-water"></i> Informasi Kolam
    </a>
    <a href="siklus" class="db-nav-item">
      <i class="fa-solid fa-fish-fins"></i> Siklus Budidaya
    </a>

    <div class="db-nav-section">Operasional</div>
    <a href="tebar" class="db-nav-item">
      <i class="fa-solid fa-seedling"></i> Tebar Awal
    </a>
    <a href="pakan" class="db-nav-item">
      <i class="fa-solid fa-bowl-food"></i> Catatan Pakan
    </a>
    <a href="mortalitas" class="db-nav-item">
      <i class="fa-solid fa-skull-crossbones"></i> Catatan Kematian
    </a>
    <a href="panen" class="db-nav-item">
      <i class="fa-solid fa-fish"></i> Catatan Panen
    </a>

    <div class="db-nav-section">Pelaporan</div>
    <a href="estimasi" class="db-nav-item">
      <i class="fa-solid fa-clock-rotate-left"></i> Estimasi Ikan
    </a>
    <a href="riwayat" class="db-nav-item">
      <i class="fa-solid fa-file-lines"></i> Riwayat & Koreksi
    </a>
    <a href="log" class="db-nav-item active">
      <i class="fa-solid fa-clipboard-list"></i> Log Aktivitas
    </a>
    <a href="ekspor" class="db-nav-item">
      <i class="fa-solid fa-file-csv"></i> Ekspor CSV
    </a>

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

  <!-- MAIN CONTENT -->
  <main class="db-main">

    <!-- TOPBAR -->
    <header class="db-topbar">
      <div>
        <h1 class="db-page-title">Log Aktivitas Pengguna</h1>
        <p class="db-page-sub">Rekaman jejak audit sistem terhadap aksi penting yang dilakukan pada akun Anda.</p>
      </div>
      <div>
        <span class="badge-status badge-cycle">
          <i class="fa-solid fa-clock-rotate-left"></i> 100 Aktivitas Terakhir
        </span>
      </div>
    </header>

    <div class="db-content">

      <div class="db-card">
        <div class="db-card-head">
          <div>
            <h2 class="db-card-title">Audit Trail Sistem</h2>
            <p class="db-card-sub">Seluruh transaksi modifikasi data dicatat bersama alamat IP dan waktu pencatatan.</p>
          </div>
          <span class="badge-status" style="background:#F1F5F9;color:#334155;border:1px solid #CBD5E1;">
            Total: <?php echo count($logs); ?> Aksi
          </span>
        </div>

        <?php if (empty($logs)): ?>
          <div style="padding: 40px 20px; text-align: center; color: var(--text-muted);">
            <i class="fa-solid fa-clipboard-check" style="font-size: 40px; color: var(--border); margin-bottom: 12px; display: block;"></i>
            <p style="font-weight: 500; margin-bottom: 6px;">Belum ada log aktivitas tercatat</p>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="db-table">
              <thead>
                <tr>
                  <th>Waktu</th>
                  <th>Aksi</th>
                  <th>Entitas</th>
                  <th>ID Entitas</th>
                  <th>Detail Informasi</th>
                  <th>IP Address</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($logs as $l): ?>
                  <tr>
                    <td style="font-weight: 500; font-size: 12px; white-space: nowrap;">
                      <?php echo e($l['created_at']); ?>
                    </td>
                    <td>
                      <span class="action-pill">
                        <?php echo e($l['action']); ?>
                      </span>
                    </td>
                    <td style="text-transform: capitalize; font-size: 13px;">
                      <?php echo $l['entity'] !== '' ? e($l['entity']) : '-'; ?>
                    </td>
                    <td style="font-size: 12px; color: var(--text-faint);">
                      <?php echo $l['entity_id'] !== null ? '#' . (int) $l['entity_id'] : '-'; ?>
                    </td>
                    <td style="max-width: 320px; font-size: 12px; color: var(--text-muted);">
                      <?php echo e($l['info']); ?>
                    </td>
                    <td style="font-family: var(--font-mono, monospace); font-size: 11px; color: var(--text-faint);">
                      <?php echo e($l['ip_address'] ?? '-'); ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

    </div>
  </main>
</div>

</body>
</html>
