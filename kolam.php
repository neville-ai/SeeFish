<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/config/koneksi.php';
require_once __DIR__ . '/config/layout.php';

$username = (string) ($_SESSION['username'] ?? 'Pengguna');
$userId = (int) $_SESSION['user_id'];
$userRole = (string) ($_SESSION['role'] ?? 'Admin Kolam');

// Ambil daftar kolam milik user
$ponds = [];
$kolamOk = true;
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
    $kolamOk = false;
    error_log('Kolam fetch error: ' . $e->getMessage());
}

$sfActions = '';
seefish_app_start([
    'nav' => 'kolam',
    'title' => 'Informasi Kolam - SeeFish',
    'heading' => 'Informasi Kolam',
    'subtitle' => 'Kelola data dan status kolam budidaya Anda',
    'breadcrumbs' => [['Dashboard', 'dashboard'], ['Informasi Kolam']],
    'actions' => $sfActions,
    'query_ok' => $kolamOk,
]);
?>
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
          <label for="nama">Nama Kolam <span class="req">*</span></label>
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
          <label for="edit_nama">Nama Kolam <span class="req">*</span></label>
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
<?php seefish_app_end(); ?>
