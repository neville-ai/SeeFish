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
$harvestLogs = [];
$totalPanenKg = 0.0;
$totalPanenEkor = 0;
$totalEstimasiPendapatan = 0.0;

try {
    // 1. Ambil siklus berjalan milik user serta estimasi sisa ikan saat ini
    $stmt = $conn->prepare(
        "SELECT s.id, k.nama AS nama_kolam, j.nama AS nama_ikan, s.tanggal_mulai, s.jumlah_tebar, s.target_panen_kg
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
        }
    }

    // 2. Ambil seluruh riwayat panen relasional milik user
    $stmt = $conn->prepare(
        "SELECT p.id, p.siklus_id, p.tanggal, p.jumlah_kg, p.jumlah_ekor, p.harga_per_kg, p.catatan, p.status_catatan, p.created_at,
                k.nama AS nama_kolam, j.nama AS nama_ikan, s.status AS status_siklus
         FROM panen p
         JOIN siklus_budidaya s ON p.siklus_id = s.id
         JOIN kolam k ON s.kolam_id = k.id
         JOIN jenis_ikan j ON s.jenis_ikan_id = j.id
         WHERE k.user_id = ?
         ORDER BY p.tanggal DESC, p.id DESC"
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $harvestLogs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    foreach ($harvestLogs as $h) {
        if ($h['status_catatan'] === 'aktif') {
            $kg = (float) $h['jumlah_kg'];
            $totalPanenKg += $kg;
            if ($h['jumlah_ekor'] !== null) {
                $totalPanenEkor += (int) $h['jumlah_ekor'];
            }
            if ($h['harga_per_kg'] !== null) {
                $totalEstimasiPendapatan += $kg * (float) $h['harga_per_kg'];
            }
        }
    }
} catch (Throwable $e) {
    error_log('Panen fetch error: ' . $e->getMessage());
}

$errorParam = $_GET['error'] ?? '';
$successParam = $_GET['success'] ?? '';
$remainingParam = $_GET['remaining'] ?? '';

$errorMessage = match ($errorParam) {
    'invalid' => 'Data panen tidak valid. Pastikan berat panen lebih dari 0 kg dan jumlah ekor (jika diisi) berupa bilangan bulat positif.',
    'forbidden_cycle' => 'Siklus budidaya tidak ditemukan atau bukan milik akun Anda.',
    'cycle_not_running' => 'Pencatatan hasil panen hanya dapat dilakukan pada siklus yang sedang berjalan.',
    'date_before_start' => 'Tanggal panen tidak boleh mendahului tanggal mulai siklus.',
    'exceeds_stock' => 'Jumlah ekor panen melebihi estimasi sisa ikan hidup pada siklus ini'
        . ($remainingParam !== '' ? ' (Sisa saat ini: ' . (int) $remainingParam . ' ekor).' : '.'),
    'csrf' => 'Sesi formulir kadaluarsa atau token keamanan tidak valid. Silakan coba lagi.',
    'db_error' => 'Gagal memproses data panen ke database. Silakan coba beberapa saat lagi.',
    'system_error' => 'Terjadi kesalahan sistem saat menyimpan catatan panen.',
    default => ''
};

$successMessage = match ($successParam) {
    'added' => 'Catatan hasil panen berhasil disimpan dan estimasi stok ikan diperbarui!',
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
<title>Catatan Panen - SeeFish</title>
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
    <a href="panen" class="db-nav-item active">
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
        <h1 class="db-page-title">Catatan Panen</h1>
        <p class="db-page-sub">Pencatatan hasil panen ikan (parsial maupun total) untuk menghitung produksi dan keuntungan budidaya.</p>
      </div>
      <div>
        <?php if (!empty($activeCycles)): ?>
          <button type="button" class="btn btn-primary" onclick="openAddHarvestModal()">
            <i class="fa-solid fa-plus"></i> Catat Hasil Panen
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
          <div class="stat-icon" style="background:#DCFCE7;color:#166534;">
            <i class="fa-solid fa-scale-balanced"></i>
          </div>
          <div>
            <div class="stat-val"><?php echo number_format($totalPanenKg, 2, ',', '.'); ?> <span class="stat-unit">kg</span></div>
            <div class="stat-lbl">Total Berat Panen</div>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon" style="background:#EFF6FF;color:#2563EB;">
            <i class="fa-solid fa-fish"></i>
          </div>
          <div>
            <div class="stat-val"><?php echo number_format($totalPanenEkor, 0, ',', '.'); ?> <span class="stat-unit">ekor</span></div>
            <div class="stat-lbl">Total Ikan Terpanen (Ekor)</div>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon" style="background:#FEF3C7;color:#D97706;">
            <i class="fa-solid fa-coins"></i>
          </div>
          <div>
            <div class="stat-val">Rp <?php echo number_format($totalEstimasiPendapatan, 0, ',', '.'); ?></div>
            <div class="stat-lbl">Estimasi Nilai Panen</div>
          </div>
        </div>
      </div>

      <!-- HARVEST TABLE -->
      <div class="db-card">
        <div class="db-card-head">
          <div>
            <h2 class="db-card-title">Riwayat Hasil Panen</h2>
            <p class="db-card-sub">Daftar panen yang telah dicatat dari siklus budidaya Anda.</p>
          </div>
          <span class="badge-status badge-cycle">
            <i class="fa-solid fa-list-check"></i> <?php echo count($harvestLogs); ?> Catatan Panen
          </span>
        </div>

        <?php if (empty($harvestLogs)): ?>
          <div style="padding: 40px 20px; text-align: center; color: var(--text-muted);">
            <i class="fa-solid fa-fish" style="font-size: 40px; color: var(--border); margin-bottom: 12px; display: block;"></i>
            <p style="font-weight: 500; margin-bottom: 6px;">Belum ada hasil panen yang dicatat</p>
            <p style="font-size: 13px; color: var(--text-faint); margin-bottom: 16px;">
              Catat hasil panen pertama Anda ketika memanen ikan dari siklus budidaya yang sedang berjalan.
            </p>
            <?php if (!empty($activeCycles)): ?>
              <button type="button" class="btn btn-primary" onclick="openAddHarvestModal()">
                <i class="fa-solid fa-plus"></i> Catat Panen Sekarang
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
                  <th>Berat (kg)</th>
                  <th>Jumlah (Ekor)</th>
                  <th>Harga/kg</th>
                  <th>Total Nilai</th>
                  <th>Catatan</th>
                  <th>Status</th>
                  <th>Waktu Catat</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($harvestLogs as $h): ?>
                  <?php
                    $kg = (float) $h['jumlah_kg'];
                    $harga = $h['harga_per_kg'] !== null ? (float) $h['harga_per_kg'] : null;
                    $totalNilai = $harga !== null ? $kg * $harga : null;
                  ?>
                  <tr>
                    <td style="font-weight: 500; white-space: nowrap;">
                      <i class="fa-regular fa-calendar" style="color: var(--text-faint); margin-right: 5px;"></i>
                      <?php echo e($h['tanggal']); ?>
                    </td>
                    <td>
                      <strong><?php echo e($h['nama_kolam']); ?></strong>
                      <div style="font-size: 11px; color: var(--text-faint);">Siklus #<?php echo (int) $h['siklus_id']; ?> (<?php echo e($h['status_siklus']); ?>)</div>
                    </td>
                    <td>
                      <span class="badge-status badge-cycle"><?php echo e($h['nama_ikan']); ?></span>
                    </td>
                    <td style="font-weight: 600; color: #166534;">
                      <?php echo number_format($kg, 2, ',', '.'); ?> kg
                    </td>
                    <td>
                      <?php echo $h['jumlah_ekor'] !== null ? number_format((int) $h['jumlah_ekor'], 0, ',', '.') . ' ekor' : '<span style="color:var(--text-faint);">-</span>'; ?>
                    </td>
                    <td style="font-size: 12px;">
                      <?php echo $harga !== null ? 'Rp ' . number_format($harga, 0, ',', '.') : '<span style="color:var(--text-faint);">-</span>'; ?>
                    </td>
                    <td style="font-weight: 600; color: var(--text);">
                      <?php echo $totalNilai !== null ? 'Rp ' . number_format($totalNilai, 0, ',', '.') : '<span style="color:var(--text-faint);">-</span>'; ?>
                    </td>
                    <td style="max-width: 180px; font-size: 12px; color: var(--text-muted);">
                      <?php echo $h['catatan'] !== null && $h['catatan'] !== '' ? e($h['catatan']) : '<span style="color:var(--text-faint);">-</span>'; ?>
                    </td>
                    <td>
                      <?php if ($h['status_catatan'] === 'aktif'): ?>
                        <span class="badge-status badge-aktif">Aktif</span>
                      <?php else: ?>
                        <span class="badge-status badge-dikoreksi">Dikoreksi</span>
                      <?php endif; ?>
                    </td>
                    <td style="font-size: 11px; color: var(--text-faint); white-space: nowrap;">
                      <?php echo e($h['created_at']); ?>
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

<!-- MODAL CATAT PANEN -->
<div id="addHarvestModal" class="modal-overlay">
  <div class="modal-box">
    <div class="modal-head">
      <h3 class="modal-title"><i class="fa-solid fa-fish" style="color:#166534;margin-right:8px;"></i>Catat Hasil Panen</h3>
      <button type="button" class="modal-close" onclick="closeAddHarvestModal()">&times;</button>
    </div>
    <form method="POST" action="process/catat_panen.php">
      <?php csrf_input(); ?>

      <div class="form-group">
        <label class="form-label" for="panen_siklus_id">Pilih Siklus Budidaya Aktif *</label>
        <select id="panen_siklus_id" name="siklus_id" class="form-select" required>
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
          <label class="form-label" for="panen_tanggal">Tanggal Panen *</label>
          <input type="date" id="panen_tanggal" name="tanggal" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="panen_jumlah_kg">Berat Panen (kg) *</label>
          <input type="number" id="panen_jumlah_kg" name="jumlah_kg" class="form-input" step="0.01" min="0.01" max="999999999.99" placeholder="Contoh: 120.50" required>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label" for="panen_jumlah_ekor">Jumlah Ekor (Opsional)</label>
          <input type="number" id="panen_jumlah_ekor" name="jumlah_ekor" class="form-input" min="1" placeholder="Contoh: 500">
          <span style="font-size:11px;color:var(--text-faint);">Jika diisi, akan mengurangi estimasi sisa ikan.</span>
        </div>
        <div class="form-group">
          <label class="form-label" for="panen_harga_per_kg">Harga Jual per kg (Rp, Opsional)</label>
          <input type="number" id="panen_harga_per_kg" name="harga_per_kg" class="form-input" step="100" min="0" placeholder="Contoh: 28000">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label" for="panen_catatan">Catatan Panen (Opsional)</label>
        <textarea id="panen_catatan" name="catatan" class="form-textarea" maxlength="1000" placeholder="Contoh: Panen parsial sortir ukuran 6-8 ekor/kg dijual ke pengepul"></textarea>
      </div>

      <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
        <button type="button" class="btn btn-secondary" onclick="closeAddHarvestModal()">Batal</button>
        <button type="submit" class="btn btn-primary" style="background:#166534;border-color:#166534;">
          <i class="fa-solid fa-check"></i> Simpan Catatan Panen
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function openAddHarvestModal() {
  document.getElementById('addHarvestModal').classList.add('active');
}
function closeAddHarvestModal() {
  document.getElementById('addHarvestModal').classList.remove('active');
}
window.addEventListener('click', function(e) {
  var modal = document.getElementById('addHarvestModal');
  if (e.target === modal) {
    closeAddHarvestModal();
  }
});
</script>

</body>
</html>
