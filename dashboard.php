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

$totalKolam = 0;
$totalSiklusAktif = 0;
$totalTebarAktif = 0;
$totalEstimasiIkan = 0;
$totalPakanKg = 0.0;
$totalMatiEkor = 0;
$totalPanenKg = 0.0;
$totalPanenEkor = 0;
$recentPonds = [];
$recentActivities = [];
$dashboardOk = true;

try {
    // 1. Total Kolam
    $stmt = $conn->prepare("SELECT COUNT(*) FROM kolam WHERE user_id = ?");
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->bind_result($totalKolam);
        $stmt->fetch();
        $stmt->close();
    }

    // 2. Siklus Aktif & Estimasi Sisa Ikan Hidup
    $stmt = $conn->prepare(
        "SELECT s.id, s.jumlah_tebar
         FROM siklus_budidaya s
         JOIN kolam k ON s.kolam_id = k.id
         WHERE k.user_id = ? AND s.status = 'berjalan'"
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $runningCycles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $totalSiklusAktif = count($runningCycles);
        foreach ($runningCycles as $rc) {
            $totalTebarAktif += (int) $rc['jumlah_tebar'];
            $stats = get_siklus_stock_stats($conn, (int) $rc['id']);
            $totalEstimasiIkan += $stats ? $stats['sisa_ikan'] : (int) $rc['jumlah_tebar'];
        }
    }

    // 3. Ringkasan Pakan
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(p.jumlah_kg), 0.0) AS total_pakan
         FROM pemberian_pakan p
         JOIN siklus_budidaya s ON p.siklus_id = s.id
         JOIN kolam k ON s.kolam_id = k.id
         WHERE k.user_id = ?"
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $totalPakanKg = (float) ($row['total_pakan'] ?? 0.0);
        $stmt->close();
    }

    // 4. Ringkasan Kematian
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(m.jumlah_ekor), 0) AS total_mati
         FROM mortalitas m
         JOIN siklus_budidaya s ON m.siklus_id = s.id
         JOIN kolam k ON s.kolam_id = k.id
         WHERE k.user_id = ? AND m.status_catatan = 'aktif'"
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $totalMatiEkor = (int) ($row['total_mati'] ?? 0);
        $stmt->close();
    }

    // 5. Ringkasan Panen
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(p.jumlah_kg), 0.0) AS total_panen_kg,
                COALESCE(SUM(p.jumlah_ekor), 0) AS total_panen_ekor
         FROM panen p
         JOIN siklus_budidaya s ON p.siklus_id = s.id
         JOIN kolam k ON s.kolam_id = k.id
         WHERE k.user_id = ? AND p.status_catatan = 'aktif'"
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $totalPanenKg = (float) ($row['total_panen_kg'] ?? 0.0);
        $totalPanenEkor = (int) ($row['total_panen_ekor'] ?? 0);
        $stmt->close();
    }

    // 6. Daftar ringkas kolam milik user (maks 5)
    $stmt = $conn->prepare(
        "SELECT k.id, k.nama, k.lokasi, k.status,
                (SELECT COUNT(*) FROM siklus_budidaya s WHERE s.kolam_id = k.id AND s.status = 'berjalan') AS siklus_aktif
         FROM kolam k
         WHERE k.user_id = ?
         ORDER BY k.id DESC
         LIMIT 5"
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $recentPonds = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    // 7. Aktivitas terbaru pengguna (maks 5)
    $stmt = $conn->prepare(
        "SELECT action, entity, info, created_at
         FROM activity_log
         WHERE user_id = ?
         ORDER BY id DESC
         LIMIT 5"
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $recentActivities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} catch (Throwable $e) {
    $dashboardOk = false;
    error_log('Dashboard data query error: ' . $e->getMessage());
}

$sfActions = '';
seefish_app_start([
    'nav' => 'dashboard',
    'title' => 'Dashboard - SeeFish',
    'heading' => 'Dashboard Kharis Neville Awuy',
    'subtitle' => date('l, d F Y'),
    'breadcrumbs' => [['Dashboard']],
    'actions' => $sfActions,
    'query_ok' => $dashboardOk,
]);
?>
<?php if ($dashboardOk): ?>
    <?php if (isset($_GET['success'])): ?>
      <div class="db-notif db-notif-ok"><i class="fa-solid fa-check"></i> Aksi berhasil diproses.</div>
    <?php elseif (isset($_GET['error'])): ?>
      <div class="db-notif db-notif-err"><i class="fa-solid fa-xmark"></i> Terjadi kesalahan. Coba lagi.</div>
    <?php endif; ?>

    <!-- STAT CARDS -->
    <div class="db-cards-grid">
      <div class="db-card">
        <div class="db-card-icon ic-g"><i class="fa-solid fa-water"></i></div>
        <div class="db-card-label">Total Kolam</div>
        <div class="db-card-val"><?php echo $totalKolam; ?></div>
        <div class="db-card-hint"><?php echo $totalKolam > 0 ? 'Kolam terdaftar' : 'Belum ada kolam'; ?></div>
      </div>

      <div class="db-card">
        <div class="db-card-icon ic-y"><i class="fa-solid fa-fish-fins"></i></div>
        <div class="db-card-label">Siklus Berjalan</div>
        <div class="db-card-val"><?php echo $totalSiklusAktif; ?></div>
        <div class="db-card-hint"><?php echo $totalSiklusAktif > 0 ? 'Siklus budidaya aktif' : 'Menunggu siklus aktif'; ?></div>
      </div>

      <div class="db-card">
        <div class="db-card-icon ic-b"><i class="fa-solid fa-clock-rotate-left"></i></div>
        <div class="db-card-label">Estimasi Ikan Hidup</div>
        <div class="db-card-val"><?php echo $totalSiklusAktif > 0 ? number_format($totalEstimasiIkan, 0, ',', '.') : '-'; ?></div>
        <div class="db-card-hint"><?php echo $totalSiklusAktif > 0 ? 'Sisa ikan aktif saat ini' : 'Tidak ada siklus aktif'; ?></div>
      </div>

      <div class="db-card">
        <div class="db-card-icon ic-warning"><i class="fa-solid fa-bowl-food"></i></div>
        <div class="db-card-label">Total Pakan</div>
        <div class="db-card-val"><?php echo number_format($totalPakanKg, 2, ',', '.'); ?> <span class="db-card-unit">kg</span></div>
        <div class="db-card-hint">Konsumsi pakan tercatat</div>
      </div>

      <div class="db-card">
        <div class="db-card-icon ic-danger"><i class="fa-solid fa-skull-crossbones"></i></div>
        <div class="db-card-label">Total Kematian</div>
        <div class="db-card-val"><?php echo number_format($totalMatiEkor, 0, ',', '.'); ?> <span class="db-card-unit">ekor</span></div>
        <div class="db-card-hint">Mortalitas aktif tercatat</div>
      </div>

      <div class="db-card">
        <div class="db-card-icon ic-success"><i class="fa-solid fa-fish"></i></div>
        <div class="db-card-label">Total Panen</div>
        <div class="db-card-val"><?php echo number_format($totalPanenKg, 2, ',', '.'); ?> <span class="db-card-unit">kg</span></div>
        <div class="db-card-hint"><?php echo $totalPanenEkor > 0 ? number_format($totalPanenEkor, 0, ',', '.') . ' ekor dipanen' : 'Hasil panen tercatat'; ?></div>
      </div>
    </div>

    <!-- INFO KOLAM -->
    <div class="db-panel">
      <div class="db-panel-head">
        <span class="db-panel-title"><i class="fa-solid fa-water icon-chart"></i> Informasi Kolam</span>
        <span class="db-tag"><?php echo $totalKolam; ?> Kolam</span>
      </div>
      <?php if (!empty($recentPonds)): ?>
        <div class="table-wrap" style="margin-bottom: 14px;">
          <table class="db-table">
            <thead>
              <tr>
                <th>Nama Kolam</th>
                <th>Lokasi</th>
                <th>Status</th>
                <th>Siklus Berjalan</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentPonds as $p): ?>
                <tr>
                  <td><strong><?php echo e($p['nama']); ?></strong></td>
                  <td><?php echo e($p['lokasi'] ?? '-'); ?></td>
                  <td>
                    <span class="db-badge <?php echo $p['status'] === 'aktif' ? 'badge-aktif' : 'badge-nonaktif'; ?>">
                      <?php echo ucfirst(e($p['status'])); ?>
                    </span>
                  </td>
                  <td>
                    <?php if ((int)$p['siklus_aktif'] > 0): ?>
                      <span class="db-badge badge-berjalan"><i class="fa-solid fa-fish-fins"></i> 1 Berjalan</span>
                    <?php else: ?>
                      <span style="color: var(--text-faint); font-size: 12px;">Tidak ada</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div style="display: flex; justify-content: flex-end;">
          <a href="kolam" class="db-btn db-btn-primary" style="display: inline-flex; width: auto; font-size: 12px;">
            <i class="fa-solid fa-water"></i> Kelola Semua Kolam &rarr;
          </a>
        </div>
      <?php else: ?>
        <div class="db-empty-state">
          <i class="fa-solid fa-water"></i>
          <p>Anda belum memiliki kolam budidaya terdaftar. Tambahkan kolam pertama Anda untuk memulai siklus.</p>
          <a href="kolam" class="db-btn db-btn-primary" style="display: inline-flex; width: auto; margin-top: 10px;">
            <i class="fa-solid fa-plus"></i> Tambah Kolam Pertama
          </a>
        </div>
      <?php endif; ?>
    </div>

    <!-- AKTIVITAS TERBARU -->
    <div class="db-panel">
      <div class="db-panel-head">
        <span class="db-panel-title"><i class="fa-solid fa-clock-rotate-left icon-chart"></i> Aktivitas Terbaru</span>
        <a href="log" style="font-size: 12px; color: var(--primary); text-decoration: none;">Lihat Semua &rarr;</a>
      </div>
      <?php if (!empty($recentActivities)): ?>
        <div class="activity-list">
          <?php foreach ($recentActivities as $act): ?>
            <div class="activity-item">
              <div>
                <span class="activity-badge"><?php echo e($act['action']); ?></span>
                <span style="color: var(--text-muted);"><?php echo e($act['info']); ?></span>
              </div>
              <div style="font-size: 11px; color: var(--text-faint); white-space: nowrap; margin-left: 12px;">
                <?php echo e($act['created_at']); ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="db-empty-state">
          <i class="fa-solid fa-clipboard-list"></i>
          <h3>Belum ada riwayat aktivitas</h3>
          <p>Aktivitas pencatatan budidaya ikan akan tercatat otomatis di sini.</p>
        </div>
      <?php endif; ?>
    </div>

    <!-- AKSES CEPAT -->
    <div class="db-panel">
      <div class="db-panel-head">
        <span class="db-panel-title"><i class="fa-solid fa-bolt icon-chart"></i> Akses Cepat Fitur</span>
        <span class="db-tag">Navigasi</span>
      </div>
      <div class="db-quick-links">
        <a href="kolam" class="db-quick-link">
          <i class="fa-solid fa-water"></i>
          <span>Informasi Kolam</span>
          <small>Kelola kolam</small>
        </a>
        <a href="siklus" class="db-quick-link">
          <i class="fa-solid fa-fish-fins"></i>
          <span>Siklus Budidaya</span>
          <small>Buka / tutup siklus</small>
        </a>
        <a href="pakan" class="db-quick-link">
          <i class="fa-solid fa-bowl-food"></i>
          <span>Catatan Pakan</span>
          <small>Pakan harian</small>
        </a>
        <a href="mortalitas" class="db-quick-link">
          <i class="fa-solid fa-skull-crossbones"></i>
          <span>Catatan Kematian</span>
          <small>Kelola mortalitas</small>
        </a>
        <a href="panen" class="db-quick-link">
          <i class="fa-solid fa-fish"></i>
          <span>Catatan Panen</span>
          <small>Hasil panen</small>
        </a>
        <a href="estimasi" class="db-quick-link">
          <i class="fa-solid fa-clock-rotate-left"></i>
          <span>Estimasi Ikan</span>
          <small>Stok & FCR</small>
        </a>
        <a href="riwayat" class="db-quick-link">
          <i class="fa-solid fa-file-lines"></i>
          <span>Riwayat & Koreksi</span>
          <small>Audit trail</small>
        </a>
        <a href="ekspor" class="db-quick-link">
          <i class="fa-solid fa-file-csv"></i>
          <span>Ekspor CSV</span>
          <small>Unduh spreadsheet</small>
        </a>
      </div>
    </div>
<?php endif; ?>
<?php seefish_app_end(); ?>
