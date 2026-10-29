<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/config/koneksi.php';
require_once __DIR__ . '/config/helpers.php';

$username = (string) ($_SESSION['username'] ?? 'Pengguna');
$userId = (int) $_SESSION['user_id'];
$userRole = (string) ($_SESSION['role'] ?? 'Admin Kolam');

$userCycles = [];
$cycleReports = [];
$totalAllTebar = 0;
$totalAllMati = 0;
$totalAllPanenEkor = 0;
$totalAllPanenKg = 0.0;
$totalAllPakanKg = 0.0;
$totalAllSisaIkan = 0;

$filterSiklus = isset($_GET['siklus_id']) && $_GET['siklus_id'] !== '' ? (int) $_GET['siklus_id'] : null;
$dariTanggal = isset($_GET['dari_tanggal']) && is_string($_GET['dari_tanggal']) ? trim($_GET['dari_tanggal']) : '';
$sampaiTanggal = isset($_GET['sampai_tanggal']) && is_string($_GET['sampai_tanggal']) ? trim($_GET['sampai_tanggal']) : '';

try {
    // 1. Ambil daftar siklus milik pengguna untuk opsi dropdown
    $stmt = $conn->prepare(
        "SELECT s.id, s.tanggal_mulai, s.status, k.nama AS nama_kolam, j.nama AS nama_ikan
         FROM siklus_budidaya s
         JOIN kolam k ON s.kolam_id = k.id
         JOIN jenis_ikan j ON s.jenis_ikan_id = j.id
         WHERE k.user_id = ?
         ORDER BY (s.status = 'berjalan') DESC, s.id DESC"
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $userCycles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    // 2. Query data laporan siklus untuk live preview
    $sql = "SELECT s.id, k.nama AS nama_kolam, j.nama AS nama_ikan, s.tanggal_mulai, s.tanggal_selesai,
                   s.status, s.jumlah_tebar, s.target_panen_kg, s.catatan
            FROM siklus_budidaya s
            JOIN kolam k ON s.kolam_id = k.id
            JOIN jenis_ikan j ON s.jenis_ikan_id = j.id
            WHERE k.user_id = ?";
    $params = [$userId];
    $types = 'i';

    if ($filterSiklus !== null) {
        $sql .= " AND s.id = ?";
        $params[] = $filterSiklus;
        $types .= 'i';
    }
    if ($dariTanggal !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dariTanggal)) {
        $sql .= " AND s.tanggal_mulai >= ?";
        $params[] = $dariTanggal;
        $types .= 's';
    }
    if ($sampaiTanggal !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampaiTanggal)) {
        $sql .= " AND s.tanggal_mulai <= ?";
        $params[] = $sampaiTanggal;
        $types .= 's';
    }

    $sql .= " ORDER BY (s.status = 'berjalan') DESC, s.id DESC";

    $stmtReport = $conn->prepare($sql);
    if ($stmtReport !== false) {
        $stmtReport->bind_param($types, ...$params);
        $stmtReport->execute();
        $resReport = $stmtReport->get_result();

        while ($c = $resReport->fetch_assoc()) {
            $cid = (int) $c['id'];
            $stats = get_siklus_stock_stats($conn, $cid);
            $tebar = (int) $c['jumlah_tebar'];
            $mati = (int) ($stats['total_mortalitas'] ?? 0);
            $panenEkor = (int) ($stats['total_panen_ekor'] ?? 0);
            $panenKg = (float) ($stats['total_panen_kg'] ?? 0.0);
            $pakanKg = (float) ($stats['total_pakan_kg'] ?? 0.0);
            $sisa = (int) ($stats['sisa_ikan'] ?? max(0, $tebar - $mati - $panenEkor));
            $fcr = $panenKg > 0 ? round($pakanKg / $panenKg, 2) : '-';

            $totalAllTebar += $tebar;
            $totalAllMati += $mati;
            $totalAllPanenEkor += $panenEkor;
            $totalAllPanenKg += $panenKg;
            $totalAllPakanKg += $pakanKg;
            if ($c['status'] === 'berjalan') {
                $totalAllSisaIkan += $sisa;
            }

            $cycleReports[] = [
                'id' => $cid,
                'nama_kolam' => (string) $c['nama_kolam'],
                'nama_ikan' => (string) $c['nama_ikan'],
                'tanggal_mulai' => (string) $c['tanggal_mulai'],
                'tanggal_selesai' => $c['tanggal_selesai'] ? (string) $c['tanggal_selesai'] : '-',
                'status' => (string) $c['status'],
                'jumlah_tebar' => $tebar,
                'total_mati' => $mati,
                'total_panen_ekor' => $panenEkor,
                'total_panen_kg' => $panenKg,
                'total_pakan_kg' => $pakanKg,
                'sisa_ikan' => $sisa,
                'fcr' => $fcr,
                'catatan' => $c['catatan'] ? (string) $c['catatan'] : '-'
            ];
        }
        $stmtReport->close();
    }
} catch (Throwable $e) {
    error_log('Ekspor cycles fetch error: ' . $e->getMessage());
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
<title>Pelaporan & Ekspor CSV - SeeFish</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="assets/css/vars.css">
<link rel="stylesheet" href="assets/css/dashboard.css">
<link rel="stylesheet" href="assets/css/logo.css">
<style>
.filter-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  padding: 18px 20px;
  margin-bottom: 24px;
}
.filter-form-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)) auto;
  gap: 16px;
  align-items: flex-end;
}
.filter-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.filter-group label {
  font-size: 13px;
  font-weight: 500;
  color: var(--text-muted);
}
.filter-input {
  width: 100%;
  padding: 8px 12px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--surface);
  font-family: inherit;
  font-size: 14px;
  color: var(--text);
  box-sizing: border-box;
}
.filter-input:focus {
  outline: none;
  border-color: var(--primary);
}
.report-summary-bar {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 12px;
  margin-bottom: 20px;
}
.summary-mini-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  padding: 12px 14px;
  border-left: 3px solid var(--primary);
}
.summary-mini-card small {
  display: block;
  font-size: 11px;
  color: var(--text-faint);
  text-transform: uppercase;
  font-weight: 600;
  margin-bottom: 4px;
}
.summary-mini-card strong {
  font-size: 18px;
  color: var(--text);
}
.export-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
  gap: 18px;
  margin-top: 24px;
}
.export-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  padding: 20px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  box-shadow: var(--shadow-sm);
}
.export-card:hover {
  border-color: var(--primary);
}
.export-icon {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  margin-bottom: 14px;
}
.export-title {
  font-size: 16px;
  font-weight: 600;
  margin-bottom: 8px;
  color: var(--text);
}
.export-desc {
  font-size: 13px;
  color: var(--text-muted);
  line-height: 1.5;
  margin-bottom: 18px;
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
    <a href="ekspor" class="db-nav-item active">
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
        <h1 class="db-page-title">Pelaporan & Ekspor CSV</h1>
        <p class="db-page-sub">Laporan analitik per siklus, transparansi estimasi stok, dan ekspor spreadsheet terstandarisasi aman.</p>
      </div>
    </header>

    <div class="db-content">

      <!-- FILTER BOX -->
      <div class="filter-card">
        <form method="GET" action="ekspor" class="filter-form-grid" id="filterForm">
          <div class="filter-group">
            <label for="filterSiklusSelect"><i class="fa-solid fa-fish-fins"></i> Siklus Budidaya:</label>
            <select id="filterSiklusSelect" name="siklus_id" class="filter-input" onchange="updateDownloadLinks()">
              <option value="">-- Semua Siklus Budidaya --</option>
              <?php foreach ($userCycles as $uc): ?>
                <option value="<?php echo (int) $uc['id']; ?>" <?php echo $filterSiklus === (int) $uc['id'] ? 'selected' : ''; ?>>
                  #<?php echo (int) $uc['id']; ?> &mdash; <?php echo e($uc['nama_kolam']); ?> (<?php echo e($uc['nama_ikan']); ?>) - <?php echo ucfirst(e($uc['status'])); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="filter-group">
            <label for="filterDariTgl"><i class="fa-solid fa-calendar"></i> Dari Tanggal:</label>
            <input type="date" id="filterDariTgl" name="dari_tanggal" class="filter-input" value="<?php echo e($dariTanggal); ?>" onchange="updateDownloadLinks()">
          </div>

          <div class="filter-group">
            <label for="filterSampaiTgl"><i class="fa-solid fa-calendar"></i> Sampai Tanggal:</label>
            <input type="date" id="filterSampaiTgl" name="sampai_tanggal" class="filter-input" value="<?php echo e($sampaiTanggal); ?>" onchange="updateDownloadLinks()">
          </div>

          <div style="display:flex;gap:8px;">
            <button type="submit" class="btn btn-primary" style="padding:8px 16px;">
              <i class="fa-solid fa-filter"></i> Terapkan
            </button>
            <?php if ($filterSiklus !== null || $dariTanggal !== '' || $sampaiTanggal !== ''): ?>
              <a href="ekspor" class="btn btn-secondary" style="padding:8px 14px;" title="Reset Filter">
                <i class="fa-solid fa-rotate-left"></i>
              </a>
            <?php endif; ?>
          </div>
        </form>
      </div>

      <!-- RINGKASAN METRIK LAPORAN -->
      <div class="report-summary-bar">
        <div class="summary-mini-card">
          <small>Total Siklus</small>
          <strong><?php echo count($cycleReports); ?></strong>
        </div>
        <div class="summary-mini-card" style="border-left-color:#3B82F6;">
          <small>Total Tebar Awal</small>
          <strong><?php echo number_format($totalAllTebar, 0, ',', '.'); ?> <span style="font-size:12px;font-weight:normal;">ekor</span></strong>
        </div>
        <div class="summary-mini-card" style="border-left-color:#10B981;">
          <small>Sisa Ikan Aktif</small>
          <strong><?php echo number_format($totalAllSisaIkan, 0, ',', '.'); ?> <span style="font-size:12px;font-weight:normal;">ekor</span></strong>
        </div>
        <div class="summary-mini-card" style="border-left-color:#F59E0B;">
          <small>Total Pakan Diberikan</small>
          <strong><?php echo number_format($totalAllPakanKg, 1, ',', '.'); ?> <span style="font-size:12px;font-weight:normal;">kg</span></strong>
        </div>
        <div class="summary-mini-card" style="border-left-color:#EF4444;">
          <small>Total Kematian</small>
          <strong><?php echo number_format($totalAllMati, 0, ',', '.'); ?> <span style="font-size:12px;font-weight:normal;">ekor</span></strong>
        </div>
        <div class="summary-mini-card" style="border-left-color:#059669;">
          <small>Total Panen</small>
          <strong><?php echo number_format($totalAllPanenKg, 1, ',', '.'); ?> <span style="font-size:12px;font-weight:normal;">kg</span></strong>
        </div>
      </div>

      <!-- TABEL LAPORAN RINGKASAN PER SIKLUS -->
      <div class="db-panel" style="margin-bottom:24px;">
        <div class="db-panel-head">
          <span class="db-panel-title"><i class="fa-solid fa-table-list" style="color:var(--primary);margin-right:8px;"></i>Ringkasan Operasional Per Siklus</span>
          <span class="db-badge db-badge-info"><?php echo count($cycleReports); ?> Siklus</span>
        </div>

        <?php if (empty($cycleReports)): ?>
          <div class="db-empty-state">
            <i class="fa-solid fa-clipboard-question"></i>
            <h3>Tidak Ada Data Siklus yang Sesuai</h3>
            <p>Tidak ditemukan data siklus budidaya berdasarkan filter yang Anda pilih. Coba sesuaikan filter atau buka siklus baru.</p>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="db-table">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Kolam & Ikan</th>
                  <th>Periode Siklus</th>
                  <th>Status</th>
                  <th>Tebar Awal</th>
                  <th>Total Pakan</th>
                  <th>Mortalitas</th>
                  <th>Panen Terverifikasi</th>
                  <th>Sisa Ikan Hidup</th>
                  <th>FCR</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($cycleReports as $cr): ?>
                  <tr>
                    <td><strong>#<?php echo (int) $cr['id']; ?></strong></td>
                    <td>
                      <strong><?php echo e($cr['nama_kolam']); ?></strong>
                      <div style="font-size:12px;color:var(--text-muted);"><?php echo e($cr['nama_ikan']); ?></div>
                    </td>
                    <td>
                      <div style="font-size:12px;">Mulai: <strong><?php echo e($cr['tanggal_mulai']); ?></strong></div>
                      <div style="font-size:12px;color:var(--text-faint);">Selesai: <?php echo e($cr['tanggal_selesai']); ?></div>
                    </td>
                    <td>
                      <?php if ($cr['status'] === 'berjalan'): ?>
                        <span class="db-badge db-badge-active"><i class="fa-solid fa-play" style="font-size:10px;"></i> Berjalan</span>
                      <?php elseif ($cr['status'] === 'selesai'): ?>
                        <span class="db-badge db-badge-done"><i class="fa-solid fa-check" style="font-size:10px;"></i> Selesai</span>
                      <?php else: ?>
                        <span class="db-badge db-badge-cancel"><i class="fa-solid fa-xmark" style="font-size:10px;"></i> <?php echo ucfirst(e($cr['status'])); ?></span>
                      <?php endif; ?>
                    </td>
                    <td><strong><?php echo number_format($cr['jumlah_tebar'], 0, ',', '.'); ?></strong> ekor</td>
                    <td><?php echo number_format($cr['total_pakan_kg'], 1, ',', '.'); ?> kg</td>
                    <td style="color:var(--danger);"><?php echo number_format($cr['total_mati'], 0, ',', '.'); ?> ekor</td>
                    <td>
                      <?php if ($cr['total_panen_kg'] > 0 || $cr['total_panen_ekor'] > 0): ?>
                        <strong><?php echo number_format($cr['total_panen_kg'], 1, ',', '.'); ?> kg</strong>
                        <div style="font-size:12px;color:var(--text-muted);">(<?php echo number_format($cr['total_panen_ekor'], 0, ',', '.'); ?> ekor)</div>
                      <?php else: ?>
                        <span style="color:var(--text-faint);">-</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <strong style="color:<?php echo $cr['sisa_ikan'] > 0 ? 'var(--primary)' : 'var(--text-muted)'; ?>;">
                        <?php echo number_format($cr['sisa_ikan'], 0, ',', '.'); ?>
                      </strong> ekor
                    </td>
                    <td><span class="db-badge db-badge-info"><?php echo e((string) $cr['fcr']); ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <!-- KARTU EKSPOR CSV DENGAN PROTEKSI FORMULA INJECTION -->
      <h2 style="font-size:18px;font-weight:600;margin-bottom:12px;color:var(--text);">Unduh Arsip Laporan CSV</h2>
      <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px;">
        File CSV di-encode dengan <strong>UTF-8 BOM</strong> untuk kompatibilitas penuh dengan Microsoft Excel & LibreOffice Calc, serta diproteksi dari <em>CSV formula injection</em>.
      </p>

      <div class="export-grid">

        <!-- KARTU 1: REKAP SIKLUS -->
        <div class="export-card">
          <div>
            <div class="export-icon" style="background:#EFF6FF;color:#2563EB;">
              <i class="fa-solid fa-chart-pie"></i>
            </div>
            <h3 class="export-title">Rekapitulasi Siklus & Estimasi</h3>
            <p class="export-desc">
              Ringkasan komprehensif per siklus: kuota tebar, kematian terverifikasi, panen (ekor & kg), estimasi sisa ikan hidup, total pakan, dan rasio FCR.
            </p>
          </div>
          <a id="btnExportSiklus" href="process/ekspor_csv.php?tipe=siklus" class="btn btn-primary" style="justify-content:center;">
            <i class="fa-solid fa-download"></i> Unduh Rekap Siklus (.csv)
          </a>
        </div>

        <!-- KARTU 2: PAKAN -->
        <div class="export-card">
          <div>
            <div class="export-icon" style="background:#FEF3C7;color:#D97706;">
              <i class="fa-solid fa-bowl-food"></i>
            </div>
            <h3 class="export-title">Catatan Pemberian Pakan</h3>
            <p class="export-desc">
              Riwayat transaksi pemberian pakan: tanggal, jenis/merek pakan, jumlah dalam kilogram, nama kolam/ikan, dan catatan lapangan.
            </p>
          </div>
          <a id="btnExportPakan" href="process/ekspor_csv.php?tipe=pakan" class="btn btn-secondary" style="justify-content:center;">
            <i class="fa-solid fa-download"></i> Unduh Catatan Pakan (.csv)
          </a>
        </div>

        <!-- KARTU 3: MORTALITAS -->
        <div class="export-card">
          <div>
            <div class="export-icon" style="background:#FEE2E2;color:#DC2626;">
              <i class="fa-solid fa-skull-crossbones"></i>
            </div>
            <h3 class="export-title">Catatan Kematian (Mortalitas)</h3>
            <p class="export-desc">
              Daftar kronologis kematian ikan: tanggal kejadian, jumlah ekor, indikasi penyebab kematian, dan status keaktifan catatan audit.
            </p>
          </div>
          <a id="btnExportMortalitas" href="process/ekspor_csv.php?tipe=mortalitas" class="btn btn-secondary" style="justify-content:center;">
            <i class="fa-solid fa-download"></i> Unduh Data Kematian (.csv)
          </a>
        </div>

        <!-- KARTU 4: PANEN -->
        <div class="export-card">
          <div>
            <div class="export-icon" style="background:#DCFCE7;color:#166534;">
              <i class="fa-solid fa-fish"></i>
            </div>
            <h3 class="export-title">Catatan Hasil Panen</h3>
            <p class="export-desc">
              Arsip data hasil panen: tanggal panen, berat total (kg), jumlah ekor, estimasi harga per kg, dan total nilai pendapatan.
            </p>
          </div>
          <a id="btnExportPanen" href="process/ekspor_csv.php?tipe=panen" class="btn btn-secondary" style="justify-content:center;">
            <i class="fa-solid fa-download"></i> Unduh Data Panen (.csv)
          </a>
        </div>

      </div>

    </div>
  </main>
</div>

<script>
function updateDownloadLinks() {
  var sid = document.getElementById('filterSiklusSelect').value;
  var dari = document.getElementById('filterDariTgl').value;
  var sampai = document.getElementById('filterSampaiTgl').value;

  var params = '';
  if (sid) params += '&siklus_id=' + encodeURIComponent(sid);
  if (dari) params += '&dari_tanggal=' + encodeURIComponent(dari);
  if (sampai) params += '&sampai_tanggal=' + encodeURIComponent(sampai);

  document.getElementById('btnExportSiklus').href = 'process/ekspor_csv.php?tipe=siklus' + params;
  document.getElementById('btnExportPakan').href = 'process/ekspor_csv.php?tipe=pakan' + params;
  document.getElementById('btnExportMortalitas').href = 'process/ekspor_csv.php?tipe=mortalitas' + params;
  document.getElementById('btnExportPanen').href = 'process/ekspor_csv.php?tipe=panen' + params;
}
// Jalankan saat pertama kali halaman dimuat untuk menyelaraskan nilai URL filter awal
document.addEventListener('DOMContentLoaded', updateDownloadLinks);
</script>

</body>
</html>
