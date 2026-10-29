<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/config/koneksi.php';

$username = (string) ($_SESSION['username'] ?? 'Pengguna');
$userId = (int) $_SESSION['user_id'];
$userRole = (string) ($_SESSION['role'] ?? 'Admin Kolam');

// Ambil daftar siklus milik user
$cycles = [];
$availablePonds = [];
$speciesList = [];

try {
    // 1. Daftar seluruh siklus user
    $stmt = $conn->prepare(
        "SELECT s.id, s.kolam_id, k.nama AS nama_kolam, s.jenis_ikan_id, j.nama AS nama_ikan,
                s.tanggal_mulai, s.tanggal_selesai, s.status, s.jumlah_tebar, s.target_panen_kg, s.catatan
         FROM siklus_budidaya s
         JOIN kolam k ON s.kolam_id = k.id
         JOIN jenis_ikan j ON s.jenis_ikan_id = j.id
         WHERE k.user_id = ?
         ORDER BY (s.status = 'berjalan') DESC, s.id DESC"
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $cycles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    // 2. Kolam aktif milik user yang belum memiliki siklus berjalan
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

    // 3. Master jenis ikan aktif
    $res = $conn->query("SELECT id, nama, nama_ilmiah FROM jenis_ikan WHERE aktif = 1 ORDER BY nama ASC");
    if ($res) {
        $speciesList = $res->fetch_all(MYSQLI_ASSOC);
        $res->free();
    }
} catch (Throwable $e) {
    error_log('Siklus fetch error: ' . $e->getMessage());
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
<title>Siklus Budidaya - SeeFish</title>
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
  border-radius: 50px;
  font-size: 11px;
  font-weight: 500;
}
.badge-berjalan { background: #DBEAFE; color: #1E40AF; border: 1px solid #93C5FD; }
.badge-selesai { background: #DCFCE7; color: #166534; border: 1px solid #86EFAC; }
.badge-dibatalkan { background: #FEE2E2; color: #991B1B; border: 1px solid #FCA5A5; }
.modal-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.5);
  backdrop-filter: blur(4px);
  z-index: 1000;
  align-items: center;
  justify-content: center;
}
.modal-overlay.active { display: flex; }
.modal-box {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  width: 90%;
  max-width: 480px;
  padding: 24px;
  box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1);
}
.modal-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
}
.modal-title {
  font-family: var(--font-display);
  font-size: 17px;
  font-weight: 700;
  color: var(--text);
}
.modal-close {
  background: transparent;
  border: none;
  font-size: 18px;
  color: var(--text-faint);
  cursor: pointer;
}
.select-input {
  padding: 10px 13px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  color: var(--text);
  font-family: var(--font-body);
  font-size: 13px;
  width: 100%;
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
    <a href="siklus" class="db-nav-item active">
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
        <h1>Siklus Budidaya</h1>
        <p>Buka dan pantau siklus penebaran benih ikan pada kolam Anda</p>
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
    <?php if (isset($_GET['success'])): ?>
      <?php if ($_GET['success'] === 'added'): ?>
        <div class="db-notif db-notif-ok"><i class="fa-solid fa-check"></i> Siklus budidaya berhasil dibuka dengan tebar awal.</div>
      <?php elseif ($_GET['success'] === 'closed'): ?>
        <div class="db-notif db-notif-ok"><i class="fa-solid fa-check"></i> Siklus budidaya berhasil ditutup.</div>
      <?php endif; ?>
    <?php elseif (isset($_GET['error'])): ?>
      <?php if ($_GET['error'] === 'cycle_running'): ?>
        <div class="db-notif db-notif-err"><i class="fa-solid fa-xmark"></i> Kolam tersebut masih memiliki siklus yang sedang berjalan. Tutup siklus lama terlebih dahulu.</div>
      <?php elseif ($_GET['error'] === 'forbidden_pond'): ?>
        <div class="db-notif db-notif-err"><i class="fa-solid fa-xmark"></i> Kolam tidak ditemukan atau Anda tidak memiliki akses.</div>
      <?php elseif ($_GET['error'] === 'pond_inactive'): ?>
        <div class="db-notif db-notif-err"><i class="fa-solid fa-xmark"></i> Kolam berstatus nonaktif. Aktifkan kolam di menu Informasi Kolam.</div>
      <?php elseif ($_GET['error'] === 'invalid_species'): ?>
        <div class="db-notif db-notif-err"><i class="fa-solid fa-xmark"></i> Jenis ikan yang dipilih tidak valid.</div>
      <?php elseif ($_GET['error'] === 'invalid_dates'): ?>
        <div class="db-notif db-notif-err"><i class="fa-solid fa-xmark"></i> Tanggal selesai tidak boleh lebih awal dari tanggal mulai.</div>
      <?php elseif ($_GET['error'] === 'already_closed'): ?>
        <div class="db-notif db-notif-err"><i class="fa-solid fa-xmark"></i> Siklus ini sudah tidak dalam status berjalan.</div>
      <?php else: ?>
        <div class="db-notif db-notif-err"><i class="fa-solid fa-xmark"></i> Terjadi kesalahan validasi atau database. Silakan coba lagi.</div>
      <?php endif; ?>
    <?php endif; ?>

    <!-- FORM BUKA SIKLUS BARU -->
    <div class="db-panel">
      <div class="db-panel-head">
        <span class="db-panel-title"><i class="fa-solid fa-seedling icon-chart"></i> Buka Siklus Budidaya Baru</span>
        <span class="db-tag">Penebaran Awal</span>
      </div>

      <?php if (empty($availablePonds)): ?>
        <div class="db-empty-state" style="padding: 20px; text-align: left;">
          <p style="margin-bottom: 12px; color: var(--text-dim);">
            <i class="fa-solid fa-circle-info" style="color: var(--primary);"></i>
            Tidak ada kolam aktif yang tersedia untuk membuka siklus baru. Semua kolam aktif Anda sedang memiliki siklus berjalan atau Anda belum memiliki kolam aktif.
          </p>
          <a href="kolam" class="db-btn db-btn-primary" style="display: inline-flex; width: auto;">
            <i class="fa-solid fa-plus"></i> Kelola Informasi Kolam
          </a>
        </div>
      <?php else: ?>
        <form method="POST" action="process/tambah_siklus" class="db-form">
          <?php csrf_input(); ?>
          <div class="db-field" style="min-width: 170px;">
            <label for="kolam_id">Pilih Kolam *</label>
            <select id="kolam_id" name="kolam_id" class="select-input" required>
              <option value="">-- Pilih Kolam --</option>
              <?php foreach ($availablePonds as $p): ?>
                <option value="<?php echo (int)$p['id']; ?>"><?php echo e($p['nama']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="db-field" style="min-width: 170px;">
            <label for="jenis_ikan_id">Jenis Ikan *</label>
            <select id="jenis_ikan_id" name="jenis_ikan_id" class="select-input" required>
              <option value="">-- Pilih Jenis Ikan --</option>
              <?php foreach ($speciesList as $s): ?>
                <option value="<?php echo (int)$s['id']; ?>"><?php echo e($s['nama']); ?> (<?php echo e($s['nama_ilmiah']); ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="db-field field-small">
            <label for="tanggal_mulai">Tgl Mulai *</label>
            <input type="date" id="tanggal_mulai" name="tanggal_mulai" value="<?php echo date('Y-m-d'); ?>" required>
          </div>
          <div class="db-field field-small">
            <label for="jumlah_tebar">Tebar Awal (ekor) *</label>
            <input type="number" min="1" max="10000000" id="jumlah_tebar" name="jumlah_tebar" placeholder="Contoh: 1000" required>
          </div>
          <div class="db-field field-small">
            <label for="target_panen_kg">Target (kg)</label>
            <input type="number" step="0.01" min="0.01" max="999999999" id="target_panen_kg" name="target_panen_kg" placeholder="0.00">
          </div>
          <div class="db-field" style="min-width: 170px;">
            <label for="catatan">Catatan</label>
            <input type="text" id="catatan" name="catatan" placeholder="Batch benih / ukuran" maxlength="1000">
          </div>
          <div class="form-submit">
            <button type="submit" class="db-btn db-btn-primary">
              <i class="fa-solid fa-play"></i> Buka Siklus
            </button>
          </div>
        </form>
      <?php endif; ?>
    </div>

    <!-- TABEL SIKLUS -->
    <div class="db-table-box">
      <div class="db-panel-head" style="padding: 18px 20px 0 20px; margin-bottom: 8px;">
        <span class="db-panel-title"><i class="fa-solid fa-clock-rotate-left icon-chart"></i> Daftar Siklus Budidaya</span>
        <span class="db-tag"><?php echo count($cycles); ?> Siklus</span>
      </div>

      <?php if (empty($cycles)): ?>
        <div class="db-empty-state" style="padding: 40px 20px;">
          <i class="fa-solid fa-fish-fins"></i>
          <h3>Belum ada siklus budidaya</h3>
          <p>Buka siklus budidaya pertama Anda pada kolam yang tersedia untuk mulai mencatat penebaran, pakan, dan mortalitas.</p>
        </div>
      <?php else: ?>
        <div style="overflow-x: auto;">
          <table class="db-table">
            <thead>
              <tr>
                <th>No</th>
                <th>Kolam</th>
                <th>Komoditas</th>
                <th>Tgl Mulai</th>
                <th>Tebar Awal</th>
                <th>Target (kg)</th>
                <th>Status</th>
                <th>Tgl Selesai</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($cycles as $i => $c): ?>
                <tr>
                  <td><?php echo $i + 1; ?></td>
                  <td><strong><?php echo e($c['nama_kolam']); ?></strong></td>
                  <td><span class="kom-pill"><?php echo e($c['nama_ikan']); ?></span></td>
                  <td><?php echo e($c['tanggal_mulai']); ?></td>
                  <td><span class="jml-val"><?php echo number_format((int)$c['jumlah_tebar']); ?></span> ekor</td>
                  <td><?php echo $c['target_panen_kg'] !== null ? e(number_format((float)$c['target_panen_kg'], 2)) . ' kg' : '-'; ?></td>
                  <td>
                    <?php if ($c['status'] === 'berjalan'): ?>
                      <span class="badge-status badge-berjalan"><i class="fa-solid fa-spinner fa-spin" style="font-size:10px;"></i> Berjalan</span>
                    <?php elseif ($c['status'] === 'selesai'): ?>
                      <span class="badge-status badge-selesai"><i class="fa-solid fa-check" style="font-size:10px;"></i> Selesai</span>
                    <?php else: ?>
                      <span class="badge-status badge-dibatalkan">Dibatalkan</span>
                    <?php endif; ?>
                  </td>
                  <td><?php echo e($c['tanggal_selesai'] ?? '-'); ?></td>
                  <td>
                    <?php if ($c['status'] === 'berjalan'): ?>
                      <button type="button" class="act-btn act-delete" onclick="openCloseModal(<?php echo (int)$c['id']; ?>, '<?php echo e(addslashes($c['nama_kolam'])); ?>', '<?php echo e($c['tanggal_mulai']); ?>')">
                        <i class="fa-solid fa-flag-checkered"></i> Tutup
                      </button>
                    <?php else: ?>
                      <span style="color: var(--text-faint); font-size: 11px;">Arsip</span>
                    <?php endif; ?>
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

<!-- MODAL TUTUP SIKLUS -->
<div id="closeModal" class="modal-overlay">
  <div class="modal-box">
    <div class="modal-head">
      <h3 class="modal-title"><i class="fa-solid fa-flag-checkered" style="color: var(--primary);"></i> Tutup Siklus Budidaya</h3>
      <button type="button" class="modal-close" onclick="closeCloseModal()">&times;</button>
    </div>
    <form method="POST" action="process/tutup_siklus">
      <?php csrf_input(); ?>
      <input type="hidden" id="close_id" name="id">
      <p id="close_info" style="font-size: 13px; color: var(--text-dim); margin-bottom: 14px;"></p>
      <div class="db-field" style="margin-bottom: 12px;">
        <label for="tanggal_selesai">Tanggal Selesai *</label>
        <input type="date" id="tanggal_selesai" name="tanggal_selesai" value="<?php echo date('Y-m-d'); ?>" required>
      </div>
      <div class="db-field" style="margin-bottom: 18px;">
        <label for="close_status">Status Akhir</label>
        <select id="close_status" name="status" class="select-input">
          <option value="selesai">Selesai (Panen / Siklus Tuntas)</option>
          <option value="dibatalkan">Dibatalkan (Gagal / Darurat)</option>
        </select>
      </div>
      <div style="display: flex; justify-content: flex-end; gap: 8px;">
        <button type="button" class="db-btn db-btn-ghost" onclick="closeCloseModal()">Batal</button>
        <button type="submit" class="db-btn db-btn-primary"><i class="fa-solid fa-check"></i> Konfirmasi Tutup Siklus</button>
      </div>
    </form>
  </div>
</div>

<script src="assets/js/dashboard.js"></script>
<script>
function openCloseModal(id, kolamNama, tanggalMulai) {
  document.getElementById('close_id').value = id;
  document.getElementById('close_info').innerText = 'Menutup siklus pada kolam: ' + kolamNama + ' (Dimulai: ' + tanggalMulai + ')';
  document.getElementById('tanggal_selesai').min = tanggalMulai;
  document.getElementById('closeModal').classList.add('active');
}
function closeCloseModal() {
  document.getElementById('closeModal').classList.remove('active');
}
document.getElementById('closeModal').addEventListener('click', function(e) {
  if (e.target === this) closeCloseModal();
});
</script>
</body>
</html>
