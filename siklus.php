<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/config/koneksi.php';
require_once __DIR__ . '/config/layout.php';

$username = (string) ($_SESSION['username'] ?? 'Pengguna');
$userId = (int) $_SESSION['user_id'];
$userRole = (string) ($_SESSION['role'] ?? 'Admin Kolam');

// Ambil daftar siklus milik user
$cycles = [];
$availablePonds = [];
$speciesList = [];
$siklusOk = true;

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
    $siklusOk = false;
    error_log('Siklus fetch error: ' . $e->getMessage());
}

$sfActions = '';
seefish_app_start([
    'nav' => 'siklus',
    'title' => 'Siklus Budidaya - SeeFish',
    'heading' => 'Siklus Budidaya',
    'subtitle' => 'Buka dan pantau siklus penebaran benih ikan pada kolam Anda',
    'breadcrumbs' => [['Dashboard', 'dashboard'], ['Siklus Budidaya']],
    'actions' => $sfActions,
    'query_ok' => $siklusOk,
]);
?>
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
            <label for="kolam_id">Pilih Kolam <span class="req">*</span></label>
            <select id="kolam_id" name="kolam_id" class="select-input" required>
              <option value="">-- Pilih Kolam --</option>
              <?php foreach ($availablePonds as $p): ?>
                <option value="<?php echo (int)$p['id']; ?>"><?php echo e($p['nama']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="db-field" style="min-width: 170px;">
            <label for="jenis_ikan_id">Jenis Ikan <span class="req">*</span></label>
            <select id="jenis_ikan_id" name="jenis_ikan_id" class="select-input" required>
              <option value="">-- Pilih Jenis Ikan --</option>
              <?php foreach ($speciesList as $s): ?>
                <option value="<?php echo (int)$s['id']; ?>"><?php echo e($s['nama']); ?> (<?php echo e($s['nama_ilmiah']); ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="db-field field-small">
            <label for="tanggal_mulai">Tanggal Mulai <span class="req">*</span></label>
            <input type="date" id="tanggal_mulai" name="tanggal_mulai" value="<?php echo date('Y-m-d'); ?>" required>
          </div>
          <div class="db-field field-small">
            <label for="jumlah_tebar">Tebar Awal (ekor) <span class="req">*</span></label>
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
        <label for="tanggal_selesai">Tanggal Selesai <span class="req">*</span></label>
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
<?php seefish_app_end(); ?>
