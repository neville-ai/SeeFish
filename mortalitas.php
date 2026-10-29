<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/config/koneksi.php';
require_once __DIR__ . '/config/helpers.php';

$username = (string) ($_SESSION['username'] ?? 'Pengguna');
$userId = (int) $_SESSION['user_id'];
$userRole = (string) ($_SESSION['role'] ?? 'Admin Kolam');

$activeCycles = [];
$mortalityLogs = [];
$totalMatiAktif = 0;
$totalTebarAktif = 0;
$totalIkanHidupAktif = 0;

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
<title>Catatan Kematian - SeeFish</title>
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
    <a href="mortalitas" class="db-nav-item active">
      <i class="fa-solid fa-skull-crossbones"></i> Catatan Kematian
    </a>
    <a href="panen" class="db-nav-item">
      <i class="fa-solid fa-fish"></i> Catatan Panen
    </a>

    <div class="db-nav-section">Pelaporan</div>
    <a href="#" class="db-nav-item" aria-disabled="true" title="Tahap C">
      <i class="fa-solid fa-clock-rotate-left"></i> Estimasi Ikan
    </a>
    <a href="#" class="db-nav-item" aria-disabled="true" title="Tahap C">
      <i class="fa-solid fa-file-lines"></i> Riwayat & Koreksi
    </a>
    <a href="#" class="db-nav-item" aria-disabled="true" title="Tahap C">
      <i class="fa-solid fa-clipboard-list"></i> Log Aktivitas
    </a>
    <a href="#" class="db-nav-item" aria-disabled="true" title="Tahap C">
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
        <h1 class="db-page-title">Catatan Kematian (Mortalitas)</h1>
        <p class="db-page-sub">Mencatat kematian ikan secara berkala untuk memantau kelangsungan hidup (survival rate) dan memperbarui estimasi stok.</p>
      </div>
      <div>
        <?php if (!empty($activeCycles)): ?>
          <button type="button" class="btn btn-primary" onclick="openAddMortalityModal()">
            <i class="fa-solid fa-plus"></i> Catat Kematian
          </button>
        <?php else: ?>
          <a href="siklus" class="btn btn-secondary">
            <i class="fa-solid fa-fish-fins"></i> Buka Siklus Dulu
          </a>
        <?php endif; ?>
      </div>
    </header>

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
          <div class="stat-icon" style="background:#FEE2E2;color:#991B1B;">
            <i class="fa-solid fa-skull-crossbones"></i>
          </div>
          <div>
            <div class="stat-val"><?php echo number_format($totalMatiAktif, 0, ',', '.'); ?> <span class="stat-unit">ekor</span></div>
            <div class="stat-lbl">Total Kematian Tercatat</div>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon" style="background:#DCFCE7;color:#166534;">
            <i class="fa-solid fa-fish"></i>
          </div>
          <div>
            <div class="stat-val"><?php echo number_format($totalIkanHidupAktif, 0, ',', '.'); ?> <span class="stat-unit">ekor</span></div>
            <div class="stat-lbl">Estimasi Ikan Hidup (Siklus Aktif)</div>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon" style="background:#EFF6FF;color:#2563EB;">
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
  </main>
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
        <label class="form-label" for="mort_siklus_id">Pilih Siklus Budidaya Aktif *</label>
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
          <label class="form-label" for="mort_tanggal">Tanggal Ditemukan *</label>
          <input type="date" id="mort_tanggal" name="tanggal" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="mort_jumlah_ekor">Jumlah Ikan Mati (Ekor) *</label>
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

</body>
</html>
