<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/config/koneksi.php';

$username = (string) ($_SESSION['username'] ?? 'Pengguna');
$userId = (int) $_SESSION['user_id'];
$userRole = (string) ($_SESSION['role'] ?? 'Admin Kolam');

$filterTipe = (string) ($_GET['tipe'] ?? 'semua');
$items = [];

try {
    // 1. Ambil pakan
    if ($filterTipe === 'semua' || $filterTipe === 'pakan') {
        $stmt = $conn->prepare(
            "SELECT p.id, p.siklus_id, p.tanggal, p.nama_pakan, p.jumlah_kg, p.catatan, p.created_at,
                    k.nama AS nama_kolam, j.nama AS nama_ikan, 'pakan' AS jenis_transaksi,
                    'aktif' AS status_catatan
             FROM pemberian_pakan p
             JOIN siklus_budidaya s ON p.siklus_id = s.id
             JOIN kolam k ON s.kolam_id = k.id
             JOIN jenis_ikan j ON s.jenis_ikan_id = j.id
             WHERE k.user_id = ?
             ORDER BY p.tanggal DESC, p.id DESC"
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $row['detail'] = 'Pakan: ' . $row['nama_pakan'] . ' (' . number_format((float) $row['jumlah_kg'], 2, ',', '.') . ' kg)';
                $row['dapat_dikoreksi'] = false;
                $items[] = $row;
            }
            $stmt->close();
        }
    }

    // 2. Ambil mortalitas
    if ($filterTipe === 'semua' || $filterTipe === 'mortalitas') {
        $stmt = $conn->prepare(
            "SELECT m.id, m.siklus_id, m.tanggal, m.jumlah_ekor, m.penyebab, m.catatan, m.status_catatan, m.created_at,
                    k.nama AS nama_kolam, j.nama AS nama_ikan, 'mortalitas' AS jenis_transaksi
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
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $penyebabStr = $row['penyebab'] ? ' (' . $row['penyebab'] . ')' : '';
                $row['detail'] = 'Kematian: ' . number_format((int) $row['jumlah_ekor'], 0, ',', '.') . ' ekor' . $penyebabStr;
                $row['dapat_dikoreksi'] = ($row['status_catatan'] === 'aktif');
                $items[] = $row;
            }
            $stmt->close();
        }
    }

    // 3. Ambil panen
    if ($filterTipe === 'semua' || $filterTipe === 'panen') {
        $stmt = $conn->prepare(
            "SELECT p.id, p.siklus_id, p.tanggal, p.jumlah_kg, p.jumlah_ekor, p.harga_per_kg, p.catatan, p.status_catatan, p.created_at,
                    k.nama AS nama_kolam, j.nama AS nama_ikan, 'panen' AS jenis_transaksi
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
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $ekorStr = $row['jumlah_ekor'] !== null ? ', ' . number_format((int) $row['jumlah_ekor'], 0, ',', '.') . ' ekor' : '';
                $row['detail'] = 'Panen: ' . number_format((float) $row['jumlah_kg'], 2, ',', '.') . ' kg' . $ekorStr;
                $row['dapat_dikoreksi'] = ($row['status_catatan'] === 'aktif');
                $items[] = $row;
            }
            $stmt->close();
        }
    }

    // Urutkan seluruh items berdasarkan tanggal dan created_at menurun
    usort($items, function($a, $b) {
        $cmp = strcmp((string)$b['tanggal'], (string)$a['tanggal']);
        if ($cmp !== 0) return $cmp;
        return strcmp((string)$b['created_at'], (string)$a['created_at']);
    });
} catch (Throwable $e) {
    error_log('Riwayat fetch error: ' . $e->getMessage());
}

$errorParam = $_GET['error'] ?? '';
$successParam = $_GET['success'] ?? '';

$errorMessage = match ($errorParam) {
    'invalid' => 'Input koreksi tidak valid. Pastikan Anda mengisi alasan koreksi secara jelas.',
    'forbidden' => 'Catatan tidak ditemukan atau bukan milik akun Anda.',
    'already_corrected' => 'Catatan ini sudah pernah dikoreksi sebelumnya.',
    'csrf' => 'Sesi formulir kadaluarsa atau token tidak valid. Silakan coba lagi.',
    'db_error' => 'Gagal memproses data ke database. Silakan coba beberapa saat lagi.',
    'system_error' => 'Terjadi kesalahan sistem saat melakukan koreksi catatan.',
    default => ''
};

$successMessage = match ($successParam) {
    'corrected' => 'Catatan berhasil dikoreksi dan otomatis dikecualikan dari perhitungan stok aktif!',
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
<title>Riwayat & Koreksi - SeeFish</title>
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
    <a href="riwayat" class="db-nav-item active">
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

  <!-- MAIN CONTENT -->
  <main class="db-main">

    <!-- TOPBAR -->
    <header class="db-topbar">
      <div>
        <h1 class="db-page-title">Riwayat & Koreksi Operasional</h1>
        <p class="db-page-sub">Audit trail lengkap pencatatan budidaya dengan mekanisme koreksi data yang transparan.</p>
      </div>
      <div>
        <a href="log" class="btn btn-secondary">
          <i class="fa-solid fa-clipboard-list"></i> Lihat Log Aktivitas
        </a>
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

      <!-- FILTER TABS -->
      <div class="filter-tabs">
        <a href="riwayat?tipe=semua" class="filter-tab <?php echo $filterTipe === 'semua' ? 'active' : ''; ?>">
          <i class="fa-solid fa-layer-group"></i> Semua Riwayat
        </a>
        <a href="riwayat?tipe=pakan" class="filter-tab <?php echo $filterTipe === 'pakan' ? 'active' : ''; ?>">
          <i class="fa-solid fa-bowl-food"></i> Pakan
        </a>
        <a href="riwayat?tipe=mortalitas" class="filter-tab <?php echo $filterTipe === 'mortalitas' ? 'active' : ''; ?>">
          <i class="fa-solid fa-skull-crossbones"></i> Kematian
        </a>
        <a href="riwayat?tipe=panen" class="filter-tab <?php echo $filterTipe === 'panen' ? 'active' : ''; ?>">
          <i class="fa-solid fa-fish"></i> Panen
        </a>
      </div>

      <!-- AUDIT TABLE -->
      <div class="db-card">
        <div class="db-card-head">
          <div>
            <h2 class="db-card-title">Daftar Transaksi Operasional</h2>
            <p class="db-card-sub">Data historis tidak pernah dihapus untuk menjamin integritas audit budidaya.</p>
          </div>
          <span class="badge-status badge-cycle">
            <?php echo count($items); ?> Catatan
          </span>
        </div>

        <?php if (empty($items)): ?>
          <div style="padding: 40px 20px; text-align: center; color: var(--text-muted);">
            <i class="fa-solid fa-file-circle-check" style="font-size: 40px; color: var(--border); margin-bottom: 12px; display: block;"></i>
            <p style="font-weight: 500; margin-bottom: 6px;">Tidak ada riwayat catatan ditemukan</p>
            <p style="font-size: 13px; color: var(--text-faint);">
              Catatan pakan, kematian, atau panen akan otomatis muncul di sini setelah Anda menginput data operasional.
            </p>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="db-table">
              <thead>
                <tr>
                  <th>Tanggal</th>
                  <th>Jenis</th>
                  <th>Kolam & Siklus</th>
                  <th>Komoditas</th>
                  <th>Rincian Catatan</th>
                  <th>Keterangan / Alasan</th>
                  <th>Status</th>
                  <th>Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($items as $it): ?>
                  <tr>
                    <td style="font-weight: 500; white-space: nowrap;">
                      <i class="fa-regular fa-calendar" style="color: var(--text-faint); margin-right: 5px;"></i>
                      <?php echo e($it['tanggal']); ?>
                    </td>
                    <td>
                      <span class="badge-tag tag-<?php echo e($it['jenis_transaksi']); ?>">
                        <?php echo e($it['jenis_transaksi']); ?>
                      </span>
                    </td>
                    <td>
                      <strong><?php echo e($it['nama_kolam']); ?></strong>
                      <div style="font-size: 11px; color: var(--text-faint);">Siklus #<?php echo (int) $it['siklus_id']; ?></div>
                    </td>
                    <td>
                      <span class="badge-status" style="background:#F1F5F9;color:#475569;border:1px solid #CBD5E1;">
                        <?php echo e($it['nama_ikan']); ?>
                      </span>
                    </td>
                    <td style="font-weight: 600;">
                      <?php echo e($it['detail']); ?>
                    </td>
                    <td style="max-width: 240px; font-size: 12px; color: var(--text-muted);">
                      <?php echo $it['catatan'] !== null && $it['catatan'] !== '' ? e($it['catatan']) : '<span style="color:var(--text-faint);">-</span>'; ?>
                    </td>
                    <td>
                      <?php if ($it['status_catatan'] === 'aktif'): ?>
                        <span class="badge-status badge-aktif">Aktif</span>
                      <?php else: ?>
                        <span class="badge-status badge-dikoreksi">Dikoreksi</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($it['dapat_dikoreksi']): ?>
                        <button type="button" class="btn-koreksi" onclick="openKoreksiModal('<?php echo e($it['jenis_transaksi']); ?>', <?php echo (int)$it['id']; ?>, '<?php echo e($it['detail']); ?>')">
                          <i class="fa-solid fa-pen-to-square"></i> Koreksi
                        </button>
                      <?php else: ?>
                        <span style="font-size: 11px; color: var(--text-faint);">-</span>
                      <?php endif; ?>
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

<!-- MODAL KOREKSI CATATAN -->
<div id="koreksiModal" class="modal-overlay">
  <div class="modal-box">
    <div class="modal-head">
      <h3 class="modal-title"><i class="fa-solid fa-pen-to-square" style="color:#D97706;margin-right:8px;"></i>Koreksi Catatan Operasional</h3>
      <button type="button" class="modal-close" onclick="closeKoreksiModal()">&times;</button>
    </div>
    <form method="POST" action="process/koreksi_catatan.php">
      <?php csrf_input(); ?>
      <input type="hidden" id="koreksi_jenis" name="jenis" value="">
      <input type="hidden" id="koreksi_record_id" name="record_id" value="">

      <div style="background:#FFFBEB;border:1px solid #FCD34D;padding:12px;border-radius:var(--radius-sm);margin-bottom:14px;font-size:12px;color:#92400E;">
        <i class="fa-solid fa-shield" style="margin-right:5px;"></i>
        <strong>Prinsip Audit SeeFish:</strong> Data historis tidak akan dihapus. Status catatan akan diubah menjadi <strong>Dikoreksi</strong> dan otomatis dikecualikan dari perhitungan sisa ikan aktif.
      </div>

      <div class="form-group">
        <label class="form-label">Catatan yang Dikoreksi:</label>
        <div id="koreksi_info" style="font-weight:600;font-size:13px;padding:8px 12px;background:var(--background);border:1px solid var(--border);border-radius:var(--radius-sm);"></div>
      </div>

      <div class="form-group">
        <label class="form-label" for="koreksi_alasan">Alasan Koreksi *</label>
        <textarea id="koreksi_alasan" name="alasan" class="form-textarea" placeholder="Contoh: Salah input jumlah ekor, yang benar adalah 25 ekor bukan 250 ekor" required minlength="3" maxlength="500"></textarea>
      </div>

      <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
        <button type="button" class="btn btn-secondary" onclick="closeKoreksiModal()">Batal</button>
        <button type="submit" class="btn btn-primary" style="background:#D97706;border-color:#D97706;">
          <i class="fa-solid fa-check"></i> Simpan Koreksi
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function openKoreksiModal(jenis, id, detail) {
  document.getElementById('koreksi_jenis').value = jenis;
  document.getElementById('koreksi_record_id').value = id;
  document.getElementById('koreksi_info').textContent = detail;
  document.getElementById('koreksi_alasan').value = '';
  document.getElementById('koreksiModal').classList.add('active');
}
function closeKoreksiModal() {
  document.getElementById('koreksiModal').classList.remove('active');
}
window.addEventListener('click', function(e) {
  var modal = document.getElementById('koreksiModal');
  if (e.target === modal) {
    closeKoreksiModal();
  }
});
</script>

</body>
</html>
