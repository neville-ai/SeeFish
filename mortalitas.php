<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/config/koneksi.php';
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/layout.php';

$username = (string) ($_SESSION['username'] ?? 'Pengguna');
$userId = (int) $_SESSION['user_id'];
$userRole = (string) ($_SESSION['role'] ?? 'Admin Kolam');

$activeCycles = [];
$mortalityLogs = [];
$totalMatiAktif = 0;
$totalTebarAktif = 0;
$totalIkanHidupAktif = 0;
$mortalitasOk = true;

try {
    // 1. Ambil siklus berjalan milik user serta hitung sisa ikan hidupnya
    $stmt = $conn->prepare(
        "SELECT s.id, k.nama AS nama_kolam, j.nama AS nama_ikan, s.tanggal_mulai, s.jumlah_tebar
         FROM siklus_budidaya s
         JOIN kolam k ON s.kolam_id = k.id
         JOIN jenis_ikan j ON s.jenis_ikan_id = j.id
         WHERE k.user_id = ? AND s.status = 'berjalan'
         ORDER BY s.id DESC"
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $rawCycles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($rawCycles as $rc) {
            $stats = get_siklus_stock_stats($conn, (int) $rc['id']);
            $sisa = $stats['sisa_ikan'] ?? (int) $rc['jumlah_tebar'];
            $rc['sisa_ikan'] = $sisa;
            $activeCycles[] = $rc;

            $totalTebarAktif += (int) $rc['jumlah_tebar'];
            $totalIkanHidupAktif += $sisa;
        }
    }

    // 2. Ambil seluruh riwayat mortalitas milik user
    $stmt = $conn->prepare(
        "SELECT m.id, m.siklus_id, m.tanggal, m.jumlah_ekor, m.penyebab, m.catatan, m.status_catatan, m.created_at,
                k.nama AS nama_kolam, j.nama AS nama_ikan, s.status AS status_siklus
         FROM mortalitas m
         JOIN siklus_budidaya s ON m.siklus_id = s.id
         JOIN kolam k ON s.kolam_id = k.id
         JOIN jenis_ikan j ON s.jenis_ikan_id = j.id
         WHERE k.user_id = ?
         ORDER BY m.tanggal DESC, m.id DESC"
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $mortalityLogs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    foreach ($mortalityLogs as $mLog) {
        if ($mLog['status_catatan'] === 'aktif') {
            $totalMatiAktif += (int) $mLog['jumlah_ekor'];
        }
    }
} catch (Throwable $e) {
    $mortalitasOk = false;
    error_log('Mortalitas fetch error: ' . $e->getMessage());
}

$errorParam = $_GET['error'] ?? '';
$successParam = $_GET['success'] ?? '';
$remainingParam = $_GET['remaining'] ?? '';

$errorMessage = match ($errorParam) {
    'invalid' => 'Data input tidak valid. Pastikan jumlah ikan mati berupa angka positif (ekor).',
    'forbidden_cycle' => 'Siklus budidaya tidak ditemukan atau bukan milik akun Anda.',
    'cycle_not_running' => 'Pencatatan kematian hanya dapat dilakukan pada siklus yang sedang berjalan.',
    'date_before_start' => 'Tanggal kematian tidak boleh mendahului tanggal mulai siklus budidaya.',
    'exceeds_stock' => 'Jumlah kematian yang diinput melebihi estimasi sisa ikan hidup pada siklus ini'
        . ($remainingParam !== '' ? ' (Sisa saat ini: ' . (int) $remainingParam . ' ekor).' : '.'),
    'csrf' => 'Sesi formulir kadaluarsa atau token keamanan tidak valid. Silakan coba lagi.',
    'db_error' => 'Gagal memproses data ke database. Silakan coba beberapa saat lagi.',
    'system_error' => 'Terjadi kesalahan sistem saat menyimpan catatan kematian.',
    default => ''
};

$successMessage = match ($successParam) {
    'added' => 'Catatan kematian ikan berhasil disimpan dan estimasi ikan diperbarui!',
    default => ''
};

ob_start();
if (!empty($activeCycles)) {
    echo '<button type="button" class="db-btn db-btn-primary" onclick="openAddMortalityModal()"><i class="fa-solid fa-plus"></i> Catat Kematian</button>';
} else {
    echo '<a href="siklus" class="db-btn db-btn-ghost"><i class="fa-solid fa-fish-fins"></i> Buka Siklus Dulu</a>';
}
$sfActions = ob_get_clean();
seefish_app_start([
    'nav' => 'mortalitas',
    'title' => 'Catatan Kematian - SeeFish',
    'heading' => 'Catatan Kematian',
    'subtitle' => 'Catat kematian ikan untuk memantau kelangsungan hidup dan memperbarui estimasi stok.',
    'breadcrumbs' => [['Dashboard', 'dashboard'], ['Catatan Kematian']],
    'actions' => $sfActions,
    'query_ok' => $mortalitasOk,
]);
?>
<div class="db-content">

      <?php if ($successMessage !== ''): ?>
        <div class="alert-box alert-success">
          <i class="fa-solid fa-circle-check"></i>
          <span><?php echo e($successMessage); ?></span>
        </div>
      <?php endif; ?>

      <?php if ($errorMessage !== ''): ?>
        <div class="alert-box alert-error">
          <i class="fa-solid fa-triangle-exclamation"></i>
          <span><?php echo e($errorMessage); ?></span>
        </div>
      <?php endif; ?>

      <!-- METRIC CARDS -->
      <div class="db-stats">
        <div class="stat-card">
          <div class="stat-icon ic-danger">
            <i class="fa-solid fa-skull-crossbones"></i>
          </div>
          <div>
            <div class="stat-val"><?php echo number_format($totalMatiAktif, 0, ',', '.'); ?> <span class="stat-unit">ekor</span></div>
            <div class="stat-lbl">Total Kematian Tercatat</div>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon ic-success">
            <i class="fa-solid fa-fish"></i>
          </div>
          <div>
            <div class="stat-val"><?php echo number_format($totalIkanHidupAktif, 0, ',', '.'); ?> <span class="stat-unit">ekor</span></div>
            <div class="stat-lbl">Estimasi Ikan Hidup (Siklus Aktif)</div>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon ic-primary">
            <i class="fa-solid fa-heart-pulse"></i>
          </div>
          <div>
            <?php
              $survivalRate = $totalTebarAktif > 0 ? max(0, round(($totalIkanHidupAktif / $totalTebarAktif) * 100, 1)) : 100;
            ?>
            <div class="stat-val"><?php echo $survivalRate; ?>%</div>
            <div class="stat-lbl">Tingkat Kelangsungan Hidup (SR)</div>
          </div>
        </div>
      </div>

      <!-- MORTALITY TABLE -->
      <div class="db-card">
        <div class="db-card-head">
          <div>
            <h2 class="db-card-title">Riwayat Mortalitas</h2>
            <p class="db-card-sub">Daftar pencatatan ikan mati pada seluruh siklus budidaya.</p>
          </div>
          <span class="badge-status badge-cycle">
            <i class="fa-solid fa-list-check"></i> <?php echo count($mortalityLogs); ?> Catatan
          </span>
        </div>

        <?php if (empty($mortalityLogs)): ?>
          <div style="padding: 40px 20px; text-align: center; color: var(--text-muted);">
            <i class="fa-solid fa-shield-heart" style="font-size: 40px; color: var(--border); margin-bottom: 12px; display: block;"></i>
            <p style="font-weight: 500; margin-bottom: 6px;">Tidak ada catatan kematian ikan</p>
            <p style="font-size: 13px; color: var(--text-faint); margin-bottom: 16px;">
              Kondisi budidaya optimal! Catat kematian di sini jika ditemukan ikan yang mati untuk menjaga akurasi estimasi stok.
            </p>
            <?php if (!empty($activeCycles)): ?>
              <button type="button" class="btn btn-secondary" onclick="openAddMortalityModal()">
                <i class="fa-solid fa-plus"></i> Tambah Catatan Kematian
              </button>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="db-table">
              <thead>
                <tr>
                  <th>Tanggal</th>
                  <th>Kolam & Siklus</th>
                  <th>Jenis Ikan</th>
                  <th>Jumlah Mati (Ekor)</th>
                  <th>Penyebab / Indikasi</th>
                  <th>Catatan</th>
                  <th>Status Catatan</th>
                  <th>Waktu Catat</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($mortalityLogs as $m): ?>
                  <tr>
                    <td style="font-weight: 500; white-space: nowrap;">
                      <i class="fa-regular fa-calendar" style="color: var(--text-faint); margin-right: 5px;"></i>
                      <?php echo e($m['tanggal']); ?>
                    </td>
                    <td>
                      <strong><?php echo e($m['nama_kolam']); ?></strong>
                      <div style="font-size: 11px; color: var(--text-faint);">Siklus #<?php echo (int) $m['siklus_id']; ?> (<?php echo e($m['status_siklus']); ?>)</div>
                    </td>
                    <td>
                      <span class="badge-status badge-cycle"><?php echo e($m['nama_ikan']); ?></span>
                    </td>
                    <td style="font-weight: 600; color: #DC2626;">
                      -<?php echo number_format((int) $m['jumlah_ekor'], 0, ',', '.'); ?> ekor
                    </td>
                    <td style="font-size: 13px;">
                      <?php echo $m['penyebab'] !== null && $m['penyebab'] !== '' ? e($m['penyebab']) : '<span style="color:var(--text-faint);">-</span>'; ?>
                    </td>
                    <td style="max-width: 220px; font-size: 12px; color: var(--text-muted);">
                      <?php echo $m['catatan'] !== null && $m['catatan'] !== '' ? e($m['catatan']) : '<span style="color:var(--text-faint);">-</span>'; ?>
                    </td>
                    <td>
                      <?php if ($m['status_catatan'] === 'aktif'): ?>
                        <span class="badge-status badge-aktif">Aktif</span>
                      <?php else: ?>
                        <span class="badge-status badge-dikoreksi">Dikoreksi</span>
                      <?php endif; ?>
                    </td>
                    <td style="font-size: 11px; color: var(--text-faint); white-space: nowrap;">
                      <?php echo e($m['created_at']); ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

    </div>
<!-- MODAL CATAT KEMATIAN -->
<div id="addMortalityModal" class="modal-overlay">
  <div class="modal-box">
    <div class="modal-head">
      <h3 class="modal-title"><i class="fa-solid fa-skull-crossbones" style="color:#DC2626;margin-right:8px;"></i>Catat Kematian Ikan</h3>
      <button type="button" class="modal-close" onclick="closeAddMortalityModal()">&times;</button>
    </div>
    <form method="POST" action="process/tambah_mortalitas.php">
      <?php csrf_input(); ?>

      <div class="form-group">
        <label class="form-label" for="mort_siklus_id">Pilih Siklus Budidaya Aktif <span class="req">*</span></label>
        <select id="mort_siklus_id" name="siklus_id" class="form-select" required>
          <option value="">-- Pilih Kolam & Siklus --</option>
          <?php foreach ($activeCycles as $ac): ?>
            <option value="<?php echo (int) $ac['id']; ?>">
              <?php echo e($ac['nama_kolam']); ?> &mdash; <?php echo e($ac['nama_ikan']); ?> (Sisa hidup: <?php echo number_format($ac['sisa_ikan'], 0, ',', '.'); ?> ekor)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label" for="mort_tanggal">Tanggal Ditemukan <span class="req">*</span></label>
          <input type="date" id="mort_tanggal" name="tanggal" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="mort_jumlah_ekor">Jumlah Ikan Mati (Ekor) <span class="req">*</span></label>
          <input type="number" id="mort_jumlah_ekor" name="jumlah_ekor" class="form-input" min="1" placeholder="Contoh: 15" required>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label" for="mort_penyebab">Penyebab / Indikasi Kematian (Opsional)</label>
        <input type="text" id="mort_penyebab" name="penyebab" class="form-input" maxlength="150" placeholder="Contoh: Perubahan cuaca ekstrem / Penyakit bintik putih / Kanibalisme">
      </div>

      <div class="form-group">
        <label class="form-label" for="mort_catatan">Catatan Tambahan (Opsional)</label>
        <textarea id="mort_catatan" name="catatan" class="form-textarea" maxlength="1000" placeholder="Contoh: Ditemukan mengapung pagi hari, air kolam agak keruh"></textarea>
      </div>

      <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
        <button type="button" class="btn btn-secondary" onclick="closeAddMortalityModal()">Batal</button>
        <button type="submit" class="btn btn-primary" style="background:#DC2626;border-color:#DC2626;">
          <i class="fa-solid fa-check"></i> Simpan Catatan Kematian
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function openAddMortalityModal() {
  document.getElementById('addMortalityModal').classList.add('active');
}
function closeAddMortalityModal() {
  document.getElementById('addMortalityModal').classList.remove('active');
}
window.addEventListener('click', function(e) {
  var modal = document.getElementById('addMortalityModal');
  if (e.target === modal) {
    closeAddMortalityModal();
  }
});
</script>
<?php seefish_app_end(); ?>
