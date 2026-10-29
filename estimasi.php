<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/config/koneksi.php';
require_once __DIR__ . '/config/helpers.php';

$username = (string) ($_SESSION['username'] ?? 'Pengguna');
$userId = (int) $_SESSION['user_id'];
$userRole = (string) ($_SESSION['role'] ?? 'Admin Kolam');

$cycles = [];
$totalSisaIkanSemua = 0;
$totalMatiSemua = 0;
$totalPanenEkorSemua = 0;
$totalPanenKgSemua = 0.0;
$totalPakanKgSemua = 0.0;

try {
    $stmt = $conn->prepare(
        "SELECT s.id, s.tanggal_mulai, s.tanggal_selesai, s.status, s.jumlah_tebar, s.target_panen_kg,
                k.nama AS nama_kolam, j.nama AS nama_ikan
         FROM siklus_budidaya s
         JOIN kolam k ON s.kolam_id = k.id
         JOIN jenis_ikan j ON s.jenis_ikan_id = j.id
         WHERE k.user_id = ?
         ORDER BY (s.status = 'berjalan') DESC, s.id DESC"
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $rawCycles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($rawCycles as $rc) {
            $cid = (int) $rc['id'];
            $stats = get_siklus_stock_stats($conn, $cid);
            $tebar = (int) $rc['jumlah_tebar'];
            $mati = $stats['total_mortalitas'] ?? 0;
            $panenEkor = $stats['total_panen_ekor'] ?? 0;
            $panenKg = (float) ($stats['total_panen_kg'] ?? 0.0);
            $pakanKg = (float) ($stats['total_pakan_kg'] ?? 0.0);
            $sisa = $stats['sisa_ikan'] ?? max(0, $tebar - $mati - $panenEkor);
            $sr = $tebar > 0 ? round(($sisa / $tebar) * 100, 1) : 0.0;
            $fcr = $panenKg > 0 ? round($pakanKg / $panenKg, 2) : null;

            $rc['stats'] = [
                'mati' => $mati,
                'panen_ekor' => $panenEkor,
                'panen_kg' => $panenKg,
                'pakan_kg' => $pakanKg,
                'sisa' => $sisa,
                'sr' => $sr,
                'fcr' => $fcr,
            ];

            if ($rc['status'] === 'berjalan') {
                $totalSisaIkanSemua += $sisa;
            }
            $totalMatiSemua += $mati;
            $totalPanenEkorSemua += $panenEkor;
            $totalPanenKgSemua += $panenKg;
            $totalPakanKgSemua += $pakanKg;

            $cycles[] = $rc;
        }
    }
} catch (Throwable $e) {
    error_log('Estimasi fetch error: ' . $e->getMessage());
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
<title>Estimasi Ikan - SeeFish</title>
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
    <a href="estimasi" class="db-nav-item active">
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

  <!-- MAIN CONTENT -->
  <main class="db-main">

    <!-- TOPBAR -->
    <header class="db-topbar">
      <div>
        <h1 class="db-page-title">Estimasi Ikan & Efisiensi Budidaya</h1>
        <p class="db-page-sub">Perhitungan stok ikan hidup real-time, survival rate, dan rasio konversi pakan (FCR) per siklus.</p>
      </div>
      <div>
        <a href="ekspor?tipe=siklus" class="btn btn-secondary">
          <i class="fa-solid fa-file-csv"></i> Unduh Rekap CSV
        </a>
      </div>
    </header>

    <div class="db-content">

      <!-- FORMULA INFO -->
      <div class="formula-card">
        <div>
          <strong><i class="fa-solid fa-circle-info" style="color:var(--primary);margin-right:6px;"></i>Formula Estimasi Standar:</strong>
          <span style="font-size:13px;color:var(--text-muted);margin-left:6px;">
            Hanya mortalitas aktif dan panen berdasarkan jumlah ekor yang mengurangi estimasi ikan hidup.
          </span>
        </div>
        <div class="formula-text">
          Estimasi Ikan = Tebar Awal - Mortalitas (Aktif) - Panen Ekor (Aktif)
        </div>
      </div>

      <!-- METRIC CARDS -->
      <div class="db-stats">
        <div class="stat-card">
          <div class="stat-icon" style="background:#DCFCE7;color:#166534;">
            <i class="fa-solid fa-fish"></i>
          </div>
          <div>
            <div class="stat-val"><?php echo number_format($totalSisaIkanSemua, 0, ',', '.'); ?> <span class="stat-unit">ekor</span></div>
            <div class="stat-lbl">Estimasi Ikan Hidup (Siklus Berjalan)</div>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon" style="background:#FEE2E2;color:#991B1B;">
            <i class="fa-solid fa-skull-crossbones"></i>
          </div>
          <div>
            <div class="stat-val"><?php echo number_format($totalMatiSemua, 0, ',', '.'); ?> <span class="stat-unit">ekor</span></div>
            <div class="stat-lbl">Total Mortalitas Tercatat</div>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon" style="background:#EFF6FF;color:#2563EB;">
            <i class="fa-solid fa-scale-balanced"></i>
          </div>
          <div>
            <div class="stat-val"><?php echo number_format($totalPanenKgSemua, 2, ',', '.'); ?> <span class="stat-unit">kg</span></div>
            <div class="stat-lbl">Total Hasil Panen Tercatat</div>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon" style="background:#FEF3C7;color:#D97706;">
            <i class="fa-solid fa-bowl-food"></i>
          </div>
          <div>
            <div class="stat-val"><?php echo number_format($totalPakanKgSemua, 2, ',', '.'); ?> <span class="stat-unit">kg</span></div>
            <div class="stat-lbl">Total Konsumsi Pakan</div>
          </div>
        </div>
      </div>

      <!-- ESTIMASI PER SIKLUS -->
      <div class="db-card">
        <div class="db-card-head">
          <div>
            <h2 class="db-card-title">Rincian Estimasi per Siklus Budidaya</h2>
            <p class="db-card-sub">Laporan lengkap stok, kelangsungan hidup (SR), dan efisiensi pakan (FCR).</p>
          </div>
          <span class="badge-status badge-cycle">
            <?php echo count($cycles); ?> Siklus
          </span>
        </div>

        <?php if (empty($cycles)): ?>
          <div style="padding: 40px 20px; text-align: center; color: var(--text-muted);">
            <i class="fa-solid fa-fish-fins" style="font-size: 40px; color: var(--border); margin-bottom: 12px; display: block;"></i>
            <p style="font-weight: 500; margin-bottom: 6px;">Belum ada siklus budidaya</p>
            <p style="font-size: 13px; color: var(--text-faint); margin-bottom: 16px;">
              Buka siklus budidaya pertama Anda untuk melihat estimasi stok ikan.
            </p>
            <a href="siklus" class="btn btn-primary">Buka Siklus Budidaya</a>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="db-table">
              <thead>
                <tr>
                  <th>Kolam & Siklus</th>
                  <th>Komoditas</th>
                  <th>Status</th>
                  <th>Tebar Awal</th>
                  <th>Mortalitas</th>
                  <th>Panen (Ekor)</th>
                  <th>Estimasi Sisa Hidup</th>
                  <th>Kelangsungan Hidup (SR)</th>
                  <th>Total Pakan</th>
                  <th>Total Panen (kg)</th>
                  <th>FCR</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($cycles as $c): ?>
                  <?php $st = $c['stats']; ?>
                  <tr>
                    <td>
                      <strong><?php echo e($c['nama_kolam']); ?></strong>
                      <div style="font-size: 11px; color: var(--text-faint);">Siklus #<?php echo (int) $c['id']; ?> (Mulai: <?php echo e($c['tanggal_mulai']); ?>)</div>
                    </td>
                    <td>
                      <span class="badge-status badge-cycle"><?php echo e($c['nama_ikan']); ?></span>
                    </td>
                    <td>
                      <span class="badge-status badge-<?php echo e($c['status']); ?>">
                        <?php echo ucfirst(e($c['status'])); ?>
                      </span>
                    </td>
                    <td style="font-weight: 600;">
                      <?php echo number_format((int) $c['jumlah_tebar'], 0, ',', '.'); ?> ekor
                    </td>
                    <td style="color: #DC2626;">
                      <?php echo $st['mati'] > 0 ? '-' . number_format($st['mati'], 0, ',', '.') . ' ekor' : '0 ekor'; ?>
                    </td>
                    <td style="color: #2563EB;">
                      <?php echo $st['panen_ekor'] > 0 ? '-' . number_format($st['panen_ekor'], 0, ',', '.') . ' ekor' : '0 ekor'; ?>
                    </td>
                    <td style="font-weight: 700; color: #166534; font-size: 14px;">
                      <?php echo number_format($st['sisa'], 0, ',', '.'); ?> ekor
                    </td>
                    <td>
                      <strong style="color: <?php echo $st['sr'] >= 80 ? '#166534' : ($st['sr'] >= 60 ? '#D97706' : '#DC2626'); ?>;">
                        <?php echo $st['sr']; ?>%
                      </strong>
                    </td>
                    <td>
                      <?php echo number_format($st['pakan_kg'], 2, ',', '.'); ?> kg
                    </td>
                    <td style="font-weight: 600;">
                      <?php echo number_format($st['panen_kg'], 2, ',', '.'); ?> kg
                    </td>
                    <td>
                      <?php if ($st['fcr'] !== null): ?>
                        <span class="badge-status" style="background:#F1F5F9;color:#334155;border:1px solid #CBD5E1;">
                          <?php echo $st['fcr']; ?>
                        </span>
                      <?php else: ?>
                        <span style="color:var(--text-faint);">-</span>
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

</body>
</html>
