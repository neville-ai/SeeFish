<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/config/koneksi.php';

$username = (string) ($_SESSION['username'] ?? 'Pengguna');
$userId = (int) $_SESSION['user_id'];
$userRole = (string) ($_SESSION['role'] ?? 'Admin Kolam');

// Ambil daftar kolam milik user
$ponds = [];
try {
    $stmt = $conn->prepare(
        "SELECT k.id, k.nama, k.lokasi, k.luas_m2, k.kedalaman_m, k.status, k.created_at,
                (SELECT COUNT(*) FROM siklus_budidaya s WHERE s.kolam_id = k.id AND s.status = 'berjalan') AS siklus_aktif
         FROM kolam k
         WHERE k.user_id = ?
         ORDER BY k.id DESC"
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $ponds = $res->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} catch (Throwable $e) {
    error_log('Kolam fetch error: ' . $e->getMessage());
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
<title>Informasi Kolam - SeeFish</title>
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
    <a href="kolam" class="db-nav-item active">
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
        <h1>Informasi Kolam</h1>
        <p>Kelola data dan status kolam budidaya Anda</p>
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
        <div class="db-notif db-notif-ok"><i class="fa-solid fa-check"></i> Kolam berhasil ditambahkan.</div>
      <?php elseif ($_GET['success'] === 'updated'): ?>
        <div class="db-notif db-notif-ok"><i class="fa-solid fa-check"></i> Data kolam berhasil diperbarui.</div>
      <?php endif; ?>
    <?php elseif (isset($_GET['error'])): ?>
      <?php if ($_GET['error'] === 'duplicate_name'): ?>
        <div class="db-notif db-notif-err"><i class="fa-solid fa-xmark"></i> Nama kolam sudah Anda gunakan. Pilih nama lain.</div>
      <?php elseif ($_GET['error'] === 'not_found'): ?>
        <div class="db-notif db-notif-err"><i class="fa-solid fa-xmark"></i> Kolam tidak ditemukan atau Anda tidak memiliki akses.</div>
      <?php elseif ($_GET['error'] === 'invalid'): ?>
        <div class="db-notif db-notif-err"><i class="fa-solid fa-xmark"></i> Isian form tidak valid. Silakan periksa kembali.</div>
      <?php else: ?>
        <div class="db-notif db-notif-err"><i class="fa-solid fa-xmark"></i> Terjadi kesalahan database. Silakan coba lagi.</div>
      <?php endif; ?>
    <?php endif; ?>

    <!-- FORM TAMBAH KOLAM -->
    <div class="db-panel">
      <div class="db-panel-head">
        <span class="db-panel-title"><i class="fa-solid fa-plus icon-add"></i> Tambah Kolam Baru</span>
        <span class="db-tag">Form Input</span>
      </div>
      <form method="POST" action="process/tambah_kolam" class="db-form">
        <?php csrf_input(); ?>
        <div class="db-field" style="min-width: 180px;">
          <label for="nama">Nama Kolam *</label>
          <input type="text" id="nama" name="nama" placeholder="Contoh: Kolam Terpal A1" required maxlength="100">
        </div>
        <div class="db-field" style="min-width: 180px;">
          <label for="lokasi">Lokasi</label>
          <input type="text" id="lokasi" name="lokasi" placeholder="Contoh: Area Timur Blok B" maxlength="150">
        </div>
        <div class="db-field field-small">
          <label for="luas_m2">Luas (m²)</label>
          <input type="number" step="0.01" min="0.01" max="99999999" id="luas_m2" name="luas_m2" placeholder="0.00">
        </div>
        <div class="db-field field-small">
          <label for="kedalaman_m">Kedalaman (m)</label>
          <input type="number" step="0.01" min="0.01" max="9999" id="kedalaman_m" name="kedalaman_m" placeholder="0.00">
        </div>
        <div class="db-field field-small">
          <label for="status">Status</label>
          <select id="status" name="status" class="select-input">
            <option value="aktif">Aktif</option>
            <option value="nonaktif">Nonaktif</option>
          </select>
        </div>
        <div class="form-submit">
          <button type="submit" class="db-btn db-btn-primary">
            <i class="fa-solid fa-plus"></i> Simpan Kolam
          </button>
        </div>
      </form>
    </div>

    <!-- TABEL DAFTAR KOLAM -->
    <div class="db-table-box">
      <div class="db-panel-head" style="padding: 18px 20px 0 20px; margin-bottom: 8px;">
        <span class="db-panel-title"><i class="fa-solid fa-water icon-chart"></i> Daftar Kolam Anda</span>
        <span class="db-tag"><?php echo count($ponds); ?> Kolam</span>
      </div>

      <?php if (empty($ponds)): ?>
        <div class="db-empty-state" style="padding: 40px 20px;">
          <i class="fa-solid fa-water"></i>
          <h3>Belum ada kolam terdaftar</h3>
          <p>Tambahkan kolam budidaya pertama Anda menggunakan formulir di atas untuk memulai pencatatan siklus.</p>
        </div>
      <?php else: ?>
        <div style="overflow-x: auto;">
          <table class="db-table">
            <thead>
              <tr>
                <th>No</th>
                <th>Nama Kolam</th>
                <th>Lokasi</th>
                <th>Luas (m²)</th>
                <th>Kedalaman (m)</th>
                <th>Status</th>
                <th>Siklus Aktif</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($ponds as $i => $p): ?>
                <tr>
                  <td><?php echo $i + 1; ?></td>
                  <td><strong><?php echo e($p['nama']); ?></strong></td>
                  <td><?php echo e($p['lokasi'] ?? '-'); ?></td>
                  <td><?php echo $p['luas_m2'] !== null ? e(number_format((float)$p['luas_m2'], 2)) : '-'; ?></td>
                  <td><?php echo $p['kedalaman_m'] !== null ? e(number_format((float)$p['kedalaman_m'], 2)) : '-'; ?></td>
                  <td>
                    <span class="badge-status <?php echo $p['status'] === 'aktif' ? 'badge-aktif' : 'badge-nonaktif'; ?>">
                      <?php echo ucfirst(e($p['status'])); ?>
                    </span>
                  </td>
                  <td>
                    <?php if ((int)$p['siklus_aktif'] > 0): ?>
                      <span class="badge-status badge-cycle"><i class="fa-solid fa-fish-fins"></i> 1 Berjalan</span>
                    <?php else: ?>
                      <span style="color: var(--text-faint); font-size: 11px;">Tidak ada</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <button type="button" class="act-btn act-edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8'); ?>)">
                      <i class="fa-solid fa-pen-to-square"></i> Edit
                    </button>
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

<!-- MODAL EDIT KOLAM -->
<div id="editModal" class="modal-overlay">
  <div class="modal-box">
    <div class="modal-head">
      <h3 class="modal-title"><i class="fa-solid fa-pen-to-square icon-edit"></i> Edit Kolam</h3>
      <button type="button" class="modal-close" onclick="closeEditModal()">&times;</button>
    </div>
    <form method="POST" action="process/update_kolam">
      <?php csrf_input(); ?>
      <input type="hidden" id="edit_id" name="id">
      <div class="form-grid">
        <div class="db-field form-grid-full">
          <label for="edit_nama">Nama Kolam *</label>
          <input type="text" id="edit_nama" name="nama" required maxlength="100">
        </div>
        <div class="db-field form-grid-full">
          <label for="edit_lokasi">Lokasi</label>
          <input type="text" id="edit_lokasi" name="lokasi" maxlength="150">
        </div>
        <div class="db-field">
          <label for="edit_luas">Luas (m²)</label>
          <input type="number" step="0.01" min="0.01" max="99999999" id="edit_luas" name="luas_m2">
        </div>
        <div class="db-field">
          <label for="edit_kedalaman">Kedalaman (m)</label>
          <input type="number" step="0.01" min="0.01" max="9999" id="edit_kedalaman" name="kedalaman_m">
        </div>
        <div class="db-field form-grid-full">
          <label for="edit_status">Status Kolam</label>
          <select id="edit_status" name="status" class="select-input">
            <option value="aktif">Aktif</option>
            <option value="nonaktif">Nonaktif</option>
          </select>
        </div>
      </div>
      <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px;">
        <button type="button" class="db-btn db-btn-ghost" onclick="closeEditModal()">Batal</button>
        <button type="submit" class="db-btn db-btn-primary"><i class="fa-solid fa-save"></i> Simpan Perubahan</button>
      </div>
    </form>
  </div>
</div>

<script src="assets/js/dashboard.js"></script>
<script>
function openEditModal(pond) {
  document.getElementById('edit_id').value = pond.id;
  document.getElementById('edit_nama').value = pond.nama;
  document.getElementById('edit_lokasi').value = pond.lokasi || '';
  document.getElementById('edit_luas').value = pond.luas_m2 || '';
  document.getElementById('edit_kedalaman').value = pond.kedalaman_m || '';
  document.getElementById('edit_status').value = pond.status;
  document.getElementById('editModal').classList.add('active');
}
function closeEditModal() {
  document.getElementById('editModal').classList.remove('active');
}
document.getElementById('editModal').addEventListener('click', function(e) {
  if (e.target === this) closeEditModal();
});
</script>
</body>
</html>
