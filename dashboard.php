<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/config/koneksi.php';
require_once __DIR__ . '/config/helpers.php';

$username = (string) ($_SESSION['username'] ?? 'Pengguna');
$userId = (int) $_SESSION['user_id'];
$userRole = (string) ($_SESSION['role'] ?? 'Admin Kolam');

$totalKolam = 0;
$totalSiklusAktif = 0;
$totalTebarAktif = 0;
$totalEstimasiIkan = 0;
$totalPakanKg = 0.0;
$totalMatiEkor = 0;
$totalPanenKg = 0.0;
$totalPanenEkor = 0;
$recentPonds = [];
$recentActivities = [];

try {
    // 1. Total Kolam
    $stmt = $conn->prepare("SELECT COUNT(*) FROM kolam WHERE user_id = ?");
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->bind_result($totalKolam);
        $stmt->fetch();
        $stmt->close();
    }

    // 2. Siklus Aktif & Estimasi Sisa Ikan Hidup
    $stmt = $conn->prepare(
        "SELECT s.id, s.jumlah_tebar
         FROM siklus_budidaya s
         JOIN kolam k ON s.kolam_id = k.id
         WHERE k.user_id = ? AND s.status = 'berjalan'"
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $runningCycles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $totalSiklusAktif = count($runningCycles);
        foreach ($runningCycles as $rc) {
            $totalTebarAktif += (int) $rc['jumlah_tebar'];
            $stats = get_siklus_stock_stats($conn, (int) $rc['id']);
            $totalEstimasiIkan += $stats ? $stats['sisa_ikan'] : (int) $rc['jumlah_tebar'];
        }
    }

    // 3. Ringkasan Pakan
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(p.jumlah_kg), 0.0) AS total_pakan
         FROM pemberian_pakan p
         JOIN siklus_budidaya s ON p.siklus_id = s.id
         JOIN kolam k ON s.kolam_id = k.id
         WHERE k.user_id = ?"
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $totalPakanKg = (float) ($row['total_pakan'] ?? 0.0);
        $stmt->close();
    }

    // 4. Ringkasan Kematian
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(m.jumlah_ekor), 0) AS total_mati
         FROM mortalitas m
         JOIN siklus_budidaya s ON m.siklus_id = s.id
         JOIN kolam k ON s.kolam_id = k.id
         WHERE k.user_id = ? AND m.status_catatan = 'aktif'"
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $totalMatiEkor = (int) ($row['total_mati'] ?? 0);
        $stmt->close();
    }

    // 5. Ringkasan Panen
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(p.jumlah_kg), 0.0) AS total_panen_kg,
                COALESCE(SUM(p.jumlah_ekor), 0) AS total_panen_ekor
         FROM panen p
         JOIN siklus_budidaya s ON p.siklus_id = s.id
         JOIN kolam k ON s.kolam_id = k.id
         WHERE k.user_id = ? AND p.status_catatan = 'aktif'"
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $totalPanenKg = (float) ($row['total_panen_kg'] ?? 0.0);
        $totalPanenEkor = (int) ($row['total_panen_ekor'] ?? 0);
        $stmt->close();
    }

    // 6. Daftar ringkas kolam milik user (maks 5)
    $stmt = $conn->prepare(
        "SELECT k.id, k.nama, k.lokasi, k.status,
                (SELECT COUNT(*) FROM siklus_budidaya s WHERE s.kolam_id = k.id AND s.status = 'berjalan') AS siklus_aktif
         FROM kolam k
         WHERE k.user_id = ?
         ORDER BY k.id DESC
         LIMIT 5"
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $recentPonds = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    // 7. Aktivitas terbaru pengguna (maks 5)
    $stmt = $conn->prepare(
        "SELECT action, entity, info, created_at
         FROM activity_log
         WHERE user_id = ?
         ORDER BY id DESC
         LIMIT 5"
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $recentActivities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} catch (Throwable $e) {
    error_log('Dashboard data query error: ' . $e->getMessage());
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self';");
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard - SeeFish</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="assets/css/vars.css">
<link rel="stylesheet" href="assets/css/dashboard.css">
<link rel="stylesheet" href="assets/css/logo.css">
<style>
.db-cards-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}
.activity-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.activity-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 14px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  font-size: 13px;
}
.activity-badge {
  font-family: var(--font-mono, monospace);
  font-size: 11px;
  font-weight: 600;
  padding: 3px 8px;
  border-radius: 4px;
  background: #F1F5F9;
  color: #334155;
  border: 1px solid #CBD5E1;
  display: inline-block;
  margin-right: 8px;
}
</style>
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
    <a href="dashboard" class="db-nav-item active">
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
    <a href="log" class="db-nav-item">
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

  <!-- MAIN -->
  <main class="db-main">

    <!-- TOPBAR -->
    <div class="db-topbar">
      <div>
        <h1>Dashboard Budidaya</h1>
        <p><?php echo e(date('l, d F Y')); ?></p>
      </div>
      <div class="topbar-flex">
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

    <!-- NOTIF -->
    <?php if (isset($_GET['success'])): ?>
      <div class="db-notif db-notif-ok"><i class="fa-solid fa-check"></i> Aksi berhasil diproses.</div>
    <?php elseif (isset($_GET['error'])): ?>
      <div class="db-notif db-notif-err"><i class="fa-solid fa-xmark"></i> Terjadi kesalahan. Coba lagi.</div>
    <?php endif; ?>

    <!-- STAT CARDS -->
    <div class="db-cards-grid">
      <div class="db-card">
        <div class="db-card-icon ic-g"><i class="fa-solid fa-water"></i></div>
        <div class="db-card-label">Total Kolam</div>
        <div class="db-card-val"><?php echo $totalKolam; ?></div>
        <div class="db-card-hint"><?php echo $totalKolam > 0 ? 'Kolam terdaftar' : 'Belum ada kolam'; ?></div>
      </div>

      <div class="db-card">
        <div class="db-card-icon ic-y"><i class="fa-solid fa-fish-fins"></i></div>
        <div class="db-card-label">Siklus Berjalan</div>
        <div class="db-card-val"><?php echo $totalSiklusAktif; ?></div>
        <div class="db-card-hint"><?php echo $totalSiklusAktif > 0 ? 'Siklus budidaya aktif' : 'Menunggu siklus aktif'; ?></div>
      </div>

      <div class="db-card">
        <div class="db-card-icon ic-b"><i class="fa-solid fa-clock-rotate-left"></i></div>
        <div class="db-card-label">Estimasi Ikan Hidup</div>
        <div class="db-card-val"><?php echo $totalSiklusAktif > 0 ? number_format($totalEstimasiIkan, 0, ',', '.') : '-'; ?></div>
        <div class="db-card-hint"><?php echo $totalSiklusAktif > 0 ? 'Sisa ikan aktif saat ini' : 'Tidak ada siklus aktif'; ?></div>
      </div>

      <div class="db-card">
        <div class="db-card-icon" style="background:#FEF3C7;color:#D97706;"><i class="fa-solid fa-bowl-food"></i></div>
        <div class="db-card-label">Total Pakan</div>
        <div class="db-card-val"><?php echo number_format($totalPakanKg, 2, ',', '.'); ?> <span style="font-size:12px;font-weight:normal;color:var(--text-muted);">kg</span></div>
        <div class="db-card-hint">Konsumsi pakan tercatat</div>
      </div>

      <div class="db-card">
        <div class="db-card-icon" style="background:#FEE2E2;color:#DC2626;"><i class="fa-solid fa-skull-crossbones"></i></div>
        <div class="db-card-label">Total Kematian</div>
        <div class="db-card-val"><?php echo number_format($totalMatiEkor, 0, ',', '.'); ?> <span style="font-size:12px;font-weight:normal;color:var(--text-muted);">ekor</span></div>
        <div class="db-card-hint">Mortalitas aktif tercatat</div>
      </div>

      <div class="db-card">
        <div class="db-card-icon" style="background:#DCFCE7;color:#166534;"><i class="fa-solid fa-fish"></i></div>
        <div class="db-card-label">Total Panen</div>
        <div class="db-card-val"><?php echo number_format($totalPanenKg, 2, ',', '.'); ?> <span style="font-size:12px;font-weight:normal;color:var(--text-muted);">kg</span></div>
        <div class="db-card-hint"><?php echo $totalPanenEkor > 0 ? number_format($totalPanenEkor, 0, ',', '.') . ' ekor dipanen' : 'Hasil panen tercatat'; ?></div>
      </div>
    </div>

    <!-- INFO KOLAM -->
    <div class="db-panel">
      <div class="db-panel-head">
        <span class="db-panel-title"><i class="fa-solid fa-water icon-chart"></i> Informasi Kolam</span>
        <span class="db-tag"><?php echo $totalKolam; ?> Kolam</span>
      </div>
      <?php if (!empty($recentPonds)): ?>
        <div style="overflow-x: auto; margin-bottom: 14px;">
          <table class="db-table">
            <thead>
              <tr>
                <th>Nama Kolam</th>
                <th>Lokasi</th>
                <th>Status</th>
                <th>Siklus Berjalan</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentPonds as $p): ?>
                <tr>
                  <td><strong><?php echo e($p['nama']); ?></strong></td>
                  <td><?php echo e($p['lokasi'] ?? '-'); ?></td>
                  <td>
                    <span style="display:inline-block; padding:2px 8px; border-radius:50px; font-size:10px; font-weight:500; <?php echo $p['status'] === 'aktif' ? 'background:#DCFCE7; color:#166534; border:1px solid #86EFAC;' : 'background:#F1F5F9; color:#64748B; border:1px solid #CBD5E1;'; ?>">
                      <?php echo ucfirst(e($p['status'])); ?>
                    </span>
                  </td>
                  <td>
                    <?php if ((int)$p['siklus_aktif'] > 0): ?>
                      <span style="color: var(--primary); font-size: 11px; font-weight: 500;"><i class="fa-solid fa-fish-fins"></i> 1 Berjalan</span>
                    <?php else: ?>
                      <span style="color: var(--text-faint); font-size: 11px;">Tidak ada</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div style="display: flex; justify-content: flex-end;">
          <a href="kolam" class="db-btn db-btn-primary" style="display: inline-flex; width: auto; font-size: 12px;">
            <i class="fa-solid fa-water"></i> Kelola Semua Kolam &rarr;
          </a>
        </div>
      <?php else: ?>
        <div class="db-empty-state">
          <i class="fa-solid fa-water"></i>
          <h3>Data kolam belum tersedia</h3>
          <p>Anda belum memiliki kolam budidaya terdaftar. Tambahkan kolam pertama Anda untuk memulai siklus.</p>
          <a href="kolam" class="db-btn db-btn-primary" style="display: inline-flex; width: auto; margin-top: 10px;">
            <i class="fa-solid fa-plus"></i> Tambah Kolam Pertama
          </a>
        </div>
      <?php endif; ?>
    </div>

    <!-- AKTIVITAS TERBARU -->
    <div class="db-panel">
      <div class="db-panel-head">
        <span class="db-panel-title"><i class="fa-solid fa-clock-rotate-left icon-chart"></i> Aktivitas Terbaru</span>
        <a href="log" style="font-size: 12px; color: var(--primary); text-decoration: none;">Lihat Semua &rarr;</a>
      </div>
      <?php if (!empty($recentActivities)): ?>
        <div class="activity-list">
          <?php foreach ($recentActivities as $act): ?>
            <div class="activity-item">
              <div>
                <span class="activity-badge"><?php echo e($act['action']); ?></span>
                <span style="color: var(--text-muted);"><?php echo e($act['info']); ?></span>
              </div>
              <div style="font-size: 11px; color: var(--text-faint); white-space: nowrap; margin-left: 12px;">
                <?php echo e($act['created_at']); ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="db-empty-state">
          <i class="fa-solid fa-clipboard-list"></i>
          <h3>Belum ada riwayat aktivitas</h3>
          <p>Aktivitas pencatatan budidaya ikan akan tercatat otomatis di sini.</p>
        </div>
      <?php endif; ?>
    </div>

    <!-- AKSES CEPAT -->
    <div class="db-panel">
      <div class="db-panel-head">
        <span class="db-panel-title"><i class="fa-solid fa-bolt icon-chart"></i> Akses Cepat Fitur</span>
        <span class="db-tag">Navigasi</span>
      </div>
      <div class="db-quick-links">
        <a href="kolam" class="db-quick-link">
          <i class="fa-solid fa-water"></i>
          <span>Informasi Kolam</span>
          <small>Kelola kolam</small>
        </a>
        <a href="siklus" class="db-quick-link">
          <i class="fa-solid fa-fish-fins"></i>
          <span>Siklus Budidaya</span>
          <small>Buka / tutup siklus</small>
        </a>
        <a href="pakan" class="db-quick-link">
          <i class="fa-solid fa-bowl-food"></i>
          <span>Catatan Pakan</span>
          <small>Pakan harian</small>
        </a>
        <a href="mortalitas" class="db-quick-link">
          <i class="fa-solid fa-skull-crossbones"></i>
          <span>Catatan Kematian</span>
          <small>Kelola mortalitas</small>
        </a>
        <a href="panen" class="db-quick-link">
          <i class="fa-solid fa-fish"></i>
          <span>Catatan Panen</span>
          <small>Hasil panen</small>
        </a>
        <a href="estimasi" class="db-quick-link">
          <i class="fa-solid fa-clock-rotate-left"></i>
          <span>Estimasi Ikan</span>
          <small>Stok & FCR</small>
        </a>
        <a href="riwayat" class="db-quick-link">
          <i class="fa-solid fa-file-lines"></i>
          <span>Riwayat & Koreksi</span>
          <small>Audit trail</small>
        </a>
        <a href="ekspor" class="db-quick-link">
          <i class="fa-solid fa-file-csv"></i>
          <span>Ekspor CSV</span>
          <small>Unduh spreadsheet</small>
        </a>
      </div>
    </div>

  </main>
</div>

<script src="assets/js/dashboard.js"></script>
</body>
</html>
