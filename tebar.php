<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/config/koneksi.php';
require_once __DIR__ . '/config/helpers.php';

$username = (string) ($_SESSION['username'] ?? 'Pengguna');
$userId = (int) $_SESSION['user_id'];
$userRole = (string) ($_SESSION['role'] ?? 'Admin Kolam');

$stockingList = [];
$availablePonds = [];
$speciesList = [];
$totalTebarAll = 0;
$totalTebarActive = 0;
$totalActiveCycles = 0;

try {
    // 1. Ambil seluruh riwayat tebar awal (siklus budidaya) milik user
    $stmt = $conn->prepare(
        "SELECT s.id, s.kolam_id, k.nama AS nama_kolam, s.jenis_ikan_id, j.nama AS nama_ikan,
                s.tanggal_mulai, s.status, s.jumlah_tebar, s.target_panen_kg, s.catatan
         FROM siklus_budidaya s
         JOIN kolam k ON s.kolam_id = k.id
         JOIN jenis_ikan j ON s.jenis_ikan_id = j.id
         WHERE k.user_id = ?
         ORDER BY (s.status = 'berjalan') DESC, s.id DESC"
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stockingList = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    foreach ($stockingList as $item) {
        $qty = (int) $item['jumlah_tebar'];
        $totalTebarAll += $qty;
        if ($item['status'] === 'berjalan') {
            $totalTebarActive += $qty;
            $totalActiveCycles++;
        }
    }

    // 2. Kolam aktif yang belum memiliki siklus berjalan
    $stmt = $conn->prepare(
        "SELECT k.id, k.nama
         FROM kolam k
         WHERE k.user_id = ? AND k.status = 'aktif'
           AND k.id NOT IN (SELECT kolam_id FROM siklus_budidaya WHERE status = 'berjalan')
         ORDER BY k.nama ASC"
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $availablePonds = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    // 3. Master jenis ikan
    $res = $conn->query("SELECT id, nama, nama_ilmiah FROM jenis_ikan WHERE aktif = 1 ORDER BY nama ASC");
    if ($res) {
        $speciesList = $res->fetch_all(MYSQLI_ASSOC);
        $res->free();
    }
} catch (Throwable $e) {
    error_log('Tebar fetch error: ' . $e->getMessage());
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
<title>Tebar Awal - SeeFish</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="assets/css/vars.css">
<link rel="stylesheet" href="assets/css/dashboard.css">
<link rel="stylesheet" href="assets/css/logo.css">
<style>
.badge-status {
  display: inline-block;
  padding: 3px 10px;
  border-radius: var(--radius-pill);
  font-size: 11px;
  font-weight: 600;
}
.badge-berjalan { background: var(--primary-light); color: var(--primary-dark); border: 1px solid var(--border); }
.badge-selesai { background: var(--success-soft); color: var(--success); border: 1px solid var(--success-border); }
.form-grid-tebar {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 12px;
  align-items: flex-end;
}
.select-input {
  width: 100%;
  padding: 9px 12px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: #FFFFFF;
  color: var(--text);
  font-family: var(--font-body);
  font-size: 13px;
}
.select-input:focus {
  border-color: var(--primary);
  outline: none;
  box-shadow: var(--focus-ring);
}
.alert-empty-pond {
  background: #FFFFFF;
  border: 1px solid var(--border-subtle);
  border-left: 4px solid var(--primary);
  border-radius: var(--radius-sm);
  padding: 14px 18px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
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
    <a href="tebar" class="db-nav-item active">
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
        <h1>Tebar Awal</h1>
        <p>Pencatatan penebaran benih ikan awal saat pembukaan siklus budidaya</p>
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

    <!-- NOTIFIKASI -->
    <?php if (isset($_GET['success']) && $_GET['success'] === 'added'): ?>
      <div class="db-notif db-notif-ok"><i class="fa-solid fa-check"></i> Tebar awal dan pembukaan siklus berhasil disimpan.</div>
    <?php elseif (isset($_GET['error'])): ?>
      <div class="db-notif db-notif-err"><i class="fa-solid fa-xmark"></i> Terjadi kesalahan dalam pencatatan tebar awal. Pastikan data valid.</div>
    <?php endif; ?>

    <!-- STAT CARDS TEBAR -->
    <div class="db-cards-grid">
      <div class="db-card">
        <div class="db-card-header">
          <span class="db-card-label">Tebar Aktif Saat Ini</span>
          <div class="db-card-icon ic-g"><i class="fa-solid fa-seedling"></i></div>
        </div>
        <div class="db-card-val"><?php echo number_format($totalTebarActive, 0, ',', '.'); ?> <span class="db-card-unit">ekor</span></div>
        <div class="db-card-hint"><?php echo $totalActiveCycles; ?> siklus berjalan aktif</div>
      </div>

      <div class="db-card">
        <div class="db-card-header">
          <span class="db-card-label">Total Ikan Ditebar</span>
          <div class="db-card-icon ic-b"><i class="fa-solid fa-fish"></i></div>
        </div>
        <div class="db-card-val"><?php echo number_format($totalTebarAll, 0, ',', '.'); ?> <span class="db-card-unit">ekor</span></div>
        <div class="db-card-hint">Akumulasi seluruh batch tebar</div>
      </div>

      <div class="db-card">
        <div class="db-card-header">
          <span class="db-card-label">Kolam Siap Tebar</span>
          <div class="db-card-icon ic-y"><i class="fa-solid fa-water"></i></div>
        </div>
        <div class="db-card-val"><?php echo count($availablePonds); ?></div>
        <div class="db-card-hint">Kolam aktif tanpa siklus berjalan</div>
      </div>
    </div>

    <!-- FORM INPUT TEBAR AWAL (SIKLUS BARU) -->
    <div class="db-panel">
      <div class="db-panel-head">
        <span class="db-panel-title"><i class="fa-solid fa-plus-circle icon-chart"></i> Catat Penebaran Benih Baru</span>
        <span class="db-tag">Buka Siklus & Tebar</span>
      </div>

      <?php if (empty($availablePonds)): ?>
        <div class="alert-empty-pond">
          <div>
            <strong style="color: var(--heading); display: block; margin-bottom: 3px;">
              <i class="fa-solid fa-circle-info" style="color: var(--primary); margin-right: 6px;"></i>
              Semua kolam aktif Anda sedang memiliki siklus budidaya yang berjalan.
            </strong>
            <span style="font-size: 12.5px; color: var(--text-dim);">
              Untuk melakukan penebaran baru, tambahkan kolam baru atau tutup siklus yang telah selesai dipanen.
            </span>
          </div>
          <div style="display: flex; gap: 8px;">
            <a href="kolam" class="db-btn db-btn-primary" style="font-size: 12.5px;">
              <i class="fa-solid fa-plus"></i> Tambah Kolam
            </a>
            <a href="siklus" class="db-btn db-btn-ghost" style="font-size: 12.5px;">
              <i class="fa-solid fa-list-check"></i> Kelola Siklus
            </a>
          </div>
        </div>
      <?php else: ?>
        <form method="POST" action="process/tambah_siklus" class="form-grid-tebar">
          <?php csrf_input(); ?>
          <div class="db-field">
            <label for="kolam_id">Kolam Tujuan *</label>
            <select id="kolam_id" name="kolam_id" class="select-input" required>
              <option value="">-- Pilih Kolam --</option>
              <?php foreach ($availablePonds as $p): ?>
                <option value="<?php echo (int)$p['id']; ?>"><?php echo e($p['nama']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="db-field">
            <label for="jenis_ikan_id">Komoditas / Ikan *</label>
            <select id="jenis_ikan_id" name="jenis_ikan_id" class="select-input" required>
              <option value="">-- Pilih Jenis Ikan --</option>
              <?php foreach ($speciesList as $s): ?>
                <option value="<?php echo (int)$s['id']; ?>"><?php echo e($s['nama']); ?> (<?php echo e($s['nama_ilmiah']); ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="db-field" style="max-width: 140px;">
            <label for="tanggal_mulai">Tgl Tebar *</label>
            <input type="date" id="tanggal_mulai" name="tanggal_mulai" value="<?php echo date('Y-m-d'); ?>" required>
          </div>

          <div class="db-field" style="max-width: 150px;">
            <label for="jumlah_tebar">Jumlah Tebar (ekor) *</label>
            <input type="number" min="1" max="10000000" id="jumlah_tebar" name="jumlah_tebar" placeholder="Contoh: 1000" required>
          </div>

          <div class="db-field" style="max-width: 130px;">
            <label for="target_panen_kg">Target (kg)</label>
            <input type="number" step="0.01" min="0.01" max="999999999" id="target_panen_kg" name="target_panen_kg" placeholder="0.00">
          </div>

          <div class="db-field">
            <label for="catatan">Catatan / Batch Benih</label>
            <input type="text" id="catatan" name="catatan" placeholder="Supplier benih, ukuran cm, dsb" maxlength="1000">
          </div>

          <div style="margin-bottom: 1px;">
            <button type="submit" class="db-btn db-btn-primary" style="padding: 10px 18px;">
              <i class="fa-solid fa-seedling"></i> Catat Tebar
            </button>
          </div>
        </form>
      <?php endif; ?>
    </div>

    <!-- RIWAYAT PENEBARAN AWAL -->
    <div class="db-table-box">
      <div class="db-panel-head" style="padding: 16px 20px 0 20px; margin-bottom: 8px;">
        <span class="db-panel-title"><i class="fa-solid fa-list-check icon-chart"></i> Riwayat Penebaran Benih (Tebar Awal)</span>
        <span class="db-tag"><?php echo count($stockingList); ?> Penebaran</span>
      </div>

      <?php if (empty($stockingList)): ?>
        <div class="db-empty-state" style="padding: 32px 20px;">
          <i class="fa-solid fa-seedling"></i>
          <h3>Belum ada riwayat tebar awal</h3>
          <p>Lakukan penebaran benih pertama Anda pada kolam yang tersedia untuk memulai siklus budidaya.</p>
        </div>
      <?php else: ?>
        <div style="overflow-x: auto;">
          <table class="db-table">
            <thead>
              <tr>
                <th>No</th>
                <th>Tanggal Tebar</th>
                <th>Kolam</th>
                <th>Komoditas</th>
                <th>Jumlah Tebar</th>
                <th>Target Panen</th>
                <th>Status Siklus</th>
                <th>Catatan Batch</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($stockingList as $i => $item): ?>
                <tr>
                  <td><?php echo $i + 1; ?></td>
                  <td><strong><?php echo e($item['tanggal_mulai']); ?></strong></td>
                  <td><?php echo e($item['nama_kolam']); ?></td>
                  <td><span class="kom-pill"><?php echo e($item['nama_ikan']); ?></span></td>
                  <td><span class="jml-val"><?php echo number_format((int)$item['jumlah_tebar'], 0, ',', '.'); ?></span> ekor</td>
                  <td><?php echo $item['target_panen_kg'] !== null ? e(number_format((float)$item['target_panen_kg'], 2, ',', '.')) . ' kg' : '-'; ?></td>
                  <td>
                    <?php if ($item['status'] === 'berjalan'): ?>
                      <span class="badge-status badge-berjalan"><i class="fa-solid fa-spinner fa-spin" style="font-size:10px;"></i> Berjalan</span>
                    <?php else: ?>
                      <span class="badge-status badge-selesai"><i class="fa-solid fa-check" style="font-size:10px;"></i> Selesai</span>
                    <?php endif; ?>
                  </td>
                  <td><?php echo e($item['catatan'] ?: '-'); ?></td>
                  <td>
                    <a href="siklus" class="act-btn" style="background: var(--surface-alt); border: 1px solid var(--border); color: var(--primary);" title="Lihat Siklus">
                      <i class="fa-solid fa-eye"></i> Detail
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

  </main>
</div>

<script src="assets/js/dashboard.js"></script>
</body>
</html>
