<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/config/koneksi.php';
require_once __DIR__ . '/config/layout.php';

$username = (string) ($_SESSION['username'] ?? 'Pengguna');
$userId = (int) $_SESSION['user_id'];
$userRole = (string) ($_SESSION['role'] ?? 'Admin Kolam');

$activeCycles = [];
$schedules = [];
$feedLogs = [];
$totalPakanKg = 0.0;
$totalPemberian = 0;
$siklusAktifCount = 0;
$totalJadwalMenunggu = 0;
$totalJadwalTerlaksana = 0;
$pakanOk = true;

$filterStatus = (string) ($_GET['filter'] ?? 'semua');
$allowedFilters = ['semua', 'hari_ini', 'mendatang', 'terlambat', 'terlaksana', 'dibatalkan'];
if (!in_array($filterStatus, $allowedFilters, true)) {
    $filterStatus = 'semua';
}

$nowYmd = date('Y-m-d');
$nowHis = date('H:i:s');
$nowFull = date('Y-m-d H:i:s');

try {
    // 1. Ambil siklus berjalan milik user untuk dropdown input
    $stmt = $conn->prepare(
        "SELECT s.id, k.nama AS nama_kolam, j.nama AS nama_ikan, s.tanggal_mulai
         FROM siklus_budidaya s
         JOIN kolam k ON s.kolam_id = k.id
         JOIN jenis_ikan j ON s.jenis_ikan_id = j.id
         WHERE k.user_id = ? AND s.status = 'berjalan'
         ORDER BY s.id DESC"
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $activeCycles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    $siklusAktifCount = count($activeCycles);

    // 2. Ambil jadwal pemberian pakan milik user jika tabel ada
    $checkTbl = $conn->query("SHOW TABLES LIKE 'jadwal_pakan'");
    if ($checkTbl && $checkTbl->num_rows > 0) {
        $stmtSch = $conn->prepare(
            "SELECT j.id, j.siklus_id, j.tanggal_rencana, j.waktu_rencana, j.nama_pakan, j.jumlah_kg_rencana,
                    j.status, j.pemberian_pakan_id, j.catatan, j.created_at,
                    k.nama AS nama_kolam, i.nama AS nama_ikan, s.status AS status_siklus
             FROM jadwal_pakan j
             JOIN siklus_budidaya s ON j.siklus_id = s.id
             JOIN kolam k ON s.kolam_id = k.id
             JOIN jenis_ikan i ON s.jenis_ikan_id = i.id
             WHERE k.user_id = ?
             ORDER BY j.tanggal_rencana DESC, j.waktu_rencana DESC, j.id DESC"
        );
        if ($stmtSch !== false) {
            $stmtSch->bind_param('i', $userId);
            $stmtSch->execute();
            $rawSchedules = $stmtSch->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmtSch->close();

            foreach ($rawSchedules as $sch) {
                $schedDateTime = $sch['tanggal_rencana'] . ' ' . $sch['waktu_rencana'];
                $isLate = ($sch['status'] === 'direncanakan' && $schedDateTime < $nowFull);

                if ($sch['status'] === 'direncanakan') {
                    $totalJadwalMenunggu++;
                } elseif ($sch['status'] === 'terlaksana') {
                    $totalJadwalTerlaksana++;
                }

                $sch['is_terlambat'] = $isLate;

                // Terapkan filter tampilan
                $include = match ($filterStatus) {
                    'hari_ini' => ($sch['tanggal_rencana'] === $nowYmd),
                    'mendatang' => ($sch['status'] === 'direncanakan' && $schedDateTime >= $nowFull),
                    'terlambat' => $isLate,
                    'terlaksana' => ($sch['status'] === 'terlaksana'),
                    'dibatalkan' => ($sch['status'] === 'dibatalkan'),
                    default => true
                };

                if ($include) {
                    $schedules[] = $sch;
                }
            }
        }
    }

    // 3. Ambil seluruh riwayat pemberian pakan aktual milik user
    $stmt = $conn->prepare(
        "SELECT p.id, p.siklus_id, p.tanggal, p.nama_pakan, p.jumlah_kg, p.catatan, p.created_at,
                k.nama AS nama_kolam, j.nama AS nama_ikan, s.status AS status_siklus
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
        $feedLogs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    $totalPemberian = count($feedLogs);
    foreach ($feedLogs as $log) {
        $totalPakanKg += (float) $log['jumlah_kg'];
    }
} catch (Throwable $e) {
    $pakanOk = false;
    error_log('Pakan & jadwal fetch error: ' . $e->getMessage());
}

$errorParam = $_GET['error'] ?? '';
$successParam = $_GET['success'] ?? '';

$errorMessage = match ($errorParam) {
    'invalid' => 'Data input tidak valid. Periksa kembali tanggal, jam, nama pakan, dan jumlah pakan.',
    'forbidden_cycle' => 'Siklus budidaya tidak ditemukan atau bukan milik akun Anda.',
    'forbidden_schedule' => 'Jadwal pakan tidak ditemukan atau bukan milik akun Anda.',
    'already_executed' => 'Jadwal ini sudah pernah dilaksanakan atau tidak lagi berstatus direncanakan.',
    'cannot_cancel' => 'Jadwal tidak dapat dibatalkan karena sudah terlaksana atau sudah dibatalkan.',
    'cycle_not_running' => 'Aksi ini hanya dapat dilakukan pada siklus yang berstatus berjalan.',
    'date_before_start' => 'Tanggal tidak boleh mendahului tanggal mulai siklus budidaya.',
    'csrf' => 'Sesi formulir kadaluarsa atau token keamanan tidak valid. Silakan coba lagi.',
    'db_error' => 'Gagal memproses data ke database. Silakan coba beberapa saat lagi.',
    'system_error' => 'Terjadi kesalahan sistem saat memproses jadwal / catatan pakan.',
    default => ''
};

$successMessage = match ($successParam) {
    'added' => 'Catatan pakan aktual berhasil disimpan!',
    'schedule_added' => 'Jadwal pemberian pakan baru berhasil dibuat!',
    'schedule_executed' => 'Jadwal pakan berhasil dilaksanakan dan tercatat ke riwayat aktual!',
    'schedule_cancelled' => 'Jadwal pemberian pakan berhasil dibatalkan.',
    default => ''
};

ob_start();
if ($siklusAktifCount > 0) {
    echo '<button type="button" class="db-btn db-btn-primary" onclick="openAddScheduleModal()"><i class="fa-solid fa-calendar-plus"></i> Buat Jadwal Pakan</button> ';
    echo '<button type="button" class="db-btn db-btn-ghost" onclick="openAddFeedModal()"><i class="fa-solid fa-plus"></i> Catat Pakan Langsung</button>';
} else {
    echo '<a href="siklus" class="db-btn db-btn-ghost"><i class="fa-solid fa-fish-fins"></i> Buka Siklus Dulu</a>';
}
$sfActions = ob_get_clean();
seefish_app_start([
    'nav' => 'pakan',
    'title' => 'Jadwal & Catatan Pakan - SeeFish',
    'heading' => 'Jadwal & Catatan Pakan',
    'subtitle' => 'Kelola perencanaan waktu pakan dan pantau realisasi konsumsi pakan.',
    'breadcrumbs' => [['Dashboard', 'dashboard'], ['Jadwal & Pakan']],
    'actions' => $sfActions,
    'query_ok' => $pakanOk,
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
          <div class="stat-icon ic-warning">
            <i class="fa-solid fa-bowl-food"></i>
          </div>
          <div>
            <div class="stat-val"><?php echo number_format($totalPakanKg, 2, ',', '.'); ?> <span class="stat-unit">kg</span></div>
            <div class="stat-lbl">Total Realisasi Pakan Diberikan</div>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon ic-primary">
            <i class="fa-solid fa-calendar-day"></i>
          </div>
          <div>
            <div class="stat-val"><?php echo $totalJadwalMenunggu; ?></div>
            <div class="stat-lbl">Jadwal Menunggu Pelaksanaan</div>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon ic-success">
            <i class="fa-solid fa-circle-check"></i>
          </div>
          <div>
            <div class="stat-val"><?php echo $totalJadwalTerlaksana; ?></div>
            <div class="stat-lbl">Jadwal Sukses Terlaksana</div>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon ic-g">
            <i class="fa-solid fa-water"></i>
          </div>
          <div>
            <div class="stat-val"><?php echo $siklusAktifCount; ?></div>
            <div class="stat-lbl">Siklus Aktif Berjalan</div>
          </div>
        </div>
      </div>

      <!-- BAGIAN 1: MODUL JADWAL PEMBERIAN PAKAN -->
      <div class="db-card" style="margin-bottom: 24px;">
        <div class="db-card-head">
          <div>
            <h2 class="db-card-title"><i class="fa-solid fa-calendar-days" style="color:var(--primary);margin-right:6px;"></i> Jadwal Pemberian Pakan</h2>
            <p class="db-card-sub">Rencana waktu dan kuota pakan ikan yang telah dijadwalkan.</p>
          </div>
          <span class="badge-status badge-cycle">
            <i class="fa-solid fa-clock"></i> <?php echo count($schedules); ?> Jadwal Ditampilkan
          </span>
        </div>

        <!-- Filter Tabs Jadwal -->
        <div class="filter-tabs">
          <a href="pakan?filter=semua" class="filter-tab <?php echo $filterStatus === 'semua' ? 'active' : ''; ?>">
            <i class="fa-solid fa-list"></i> Semua
          </a>
          <a href="pakan?filter=hari_ini" class="filter-tab <?php echo $filterStatus === 'hari_ini' ? 'active' : ''; ?>">
            <i class="fa-solid fa-calendar-day"></i> Hari Ini
          </a>
          <a href="pakan?filter=mendatang" class="filter-tab <?php echo $filterStatus === 'mendatang' ? 'active' : ''; ?>">
            <i class="fa-solid fa-forward"></i> Mendatang
          </a>
          <a href="pakan?filter=terlambat" class="filter-tab <?php echo $filterStatus === 'terlambat' ? 'active' : ''; ?>">
            <i class="fa-solid fa-triangle-exclamation"></i> Terlambat
          </a>
          <a href="pakan?filter=terlaksana" class="filter-tab <?php echo $filterStatus === 'terlaksana' ? 'active' : ''; ?>">
            <i class="fa-solid fa-check-double"></i> Terlaksana
          </a>
          <a href="pakan?filter=dibatalkan" class="filter-tab <?php echo $filterStatus === 'dibatalkan' ? 'active' : ''; ?>">
            <i class="fa-solid fa-ban"></i> Dibatalkan
          </a>
        </div>

        <?php if (empty($schedules)): ?>
          <div style="padding: 36px 20px; text-align: center; color: var(--text-muted);">
            <i class="fa-solid fa-calendar-xmark" style="font-size: 38px; color: var(--border); margin-bottom: 12px; display: block;"></i>
            <p style="font-weight: 500; margin-bottom: 6px;">Tidak ada jadwal pemberian pakan pada filter ini</p>
            <p style="font-size: 13px; color: var(--text-faint); margin-bottom: 16px;">
              Buat jadwal pakan harian agar rutinitas budidaya ikan dapat terpantau secara konsisten.
            </p>
            <?php if ($siklusAktifCount > 0): ?>
              <button type="button" class="btn btn-primary" onclick="openAddScheduleModal()">
                <i class="fa-solid fa-calendar-plus"></i> Buat Jadwal Sekarang
              </button>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="db-table">
              <thead>
                <tr>
                  <th>Waktu Rencana</th>
                  <th>Kolam & Siklus</th>
                  <th>Komoditas</th>
                  <th>Nama Pakan</th>
                  <th>Target (kg)</th>
                  <th>Status Jadwal</th>
                  <th>Catatan / Realisasi</th>
                  <th style="text-align: right;">Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($schedules as $s): ?>
                  <tr>
                    <td style="font-weight: 500; white-space: nowrap;">
                      <div><i class="fa-regular fa-calendar" style="color:var(--text-faint);margin-right:5px;"></i><?php echo e($s['tanggal_rencana']); ?></div>
                      <div style="font-size: 12px; color: var(--text-dim); margin-top: 2px;">
                        <i class="fa-regular fa-clock" style="color:var(--text-faint);margin-right:5px;"></i><?php echo substr((string)$s['waktu_rencana'], 0, 5); ?> WIB
                      </div>
                    </td>
                    <td>
                      <strong><?php echo e($s['nama_kolam']); ?></strong>
                      <div style="font-size: 11px; color: var(--text-faint);">Siklus #<?php echo (int) $s['siklus_id']; ?></div>
                    </td>
                    <td>
                      <span class="badge-status badge-cycle"><?php echo e($s['nama_ikan']); ?></span>
                    </td>
                    <td><strong><?php echo e($s['nama_pakan']); ?></strong></td>
                    <td style="font-weight: 600; color: var(--primary);">
                      <?php echo number_format((float) $s['jumlah_kg_rencana'], 2, ',', '.'); ?> kg
                    </td>
                    <td>
                      <?php if ($s['status'] === 'terlaksana'): ?>
                        <span class="badge-status badge-aktif"><i class="fa-solid fa-check"></i> Terlaksana</span>
                      <?php elseif ($s['status'] === 'dibatalkan'): ?>
                        <span class="badge-status badge-nonaktif"><i class="fa-solid fa-ban"></i> Dibatalkan</span>
                      <?php elseif ($s['is_terlambat']): ?>
                        <span class="badge-status badge-dikoreksi" title="Waktu rencana telah lewat namun belum dikonfirmasi"><i class="fa-solid fa-triangle-exclamation"></i> Terlambat</span>
                      <?php else: ?>
                        <span class="badge-status badge-berjalan"><i class="fa-regular fa-clock"></i> Direncanakan</span>
                      <?php endif; ?>
                    </td>
                    <td style="font-size: 12px; color: var(--text-dim); max-width: 220px;">
                      <?php if ($s['status'] === 'terlaksana' && $s['pemberian_pakan_id'] !== null): ?>
                        <span style="color: var(--success); font-weight: 500;"><i class="fa-solid fa-link"></i> Realisasi #<?php echo (int) $s['pemberian_pakan_id']; ?></span><br>
                      <?php endif; ?>
                      <?php echo $s['catatan'] !== null && $s['catatan'] !== '' ? e($s['catatan']) : '-'; ?>
                    </td>
                    <td style="text-align: right; white-space: nowrap;">
                      <?php if ($s['status'] === 'direncanakan'): ?>
                        <button type="button" class="btn btn-primary" style="padding: 4px 10px; font-size: 12px;" onclick="openExecuteScheduleModal(<?php echo htmlspecialchars(json_encode($s), ENT_QUOTES, 'UTF-8'); ?>)">
                          <i class="fa-solid fa-check"></i> Konfirmasi Beri Pakan
                        </button>
                        <button type="button" class="btn btn-secondary" style="padding: 4px 8px; font-size: 12px;" onclick="openCancelScheduleModal(<?php echo (int)$s['id']; ?>, '<?php echo e(addslashes($s['nama_kolam'])); ?>', '<?php echo e($s['tanggal_rencana']); ?>')">
                          <i class="fa-solid fa-xmark"></i> Batal
                        </button>
                      <?php elseif ($s['status'] === 'terlaksana'): ?>
                        <span style="font-size: 11px; color: var(--success); font-weight: 500;"><i class="fa-solid fa-circle-check"></i> Tuntas</span>
                      <?php else: ?>
                        <span style="font-size: 11px; color: var(--text-faint);">Arsip</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <!-- BAGIAN 2: MODUL RIWAYAT PEMBERIAN PAKAN AKTUAL -->
      <div class="db-card">
        <div class="db-card-head">
          <div>
            <h2 class="db-card-title"><i class="fa-solid fa-receipt" style="color:var(--primary);margin-right:6px;"></i> Riwayat Pemberian Pakan Aktual</h2>
            <p class="db-card-sub">Catatan konsumsi pakan nyata yang telah diberikan ke kolam (digunakan untuk kalkulasi FCR).</p>
          </div>
          <span class="badge-status badge-cycle">
            <i class="fa-solid fa-list-check"></i> <?php echo count($feedLogs); ?> Catatan Aktual
          </span>
        </div>

        <?php if (empty($feedLogs)): ?>
          <div style="padding: 36px 20px; text-align: center; color: var(--text-muted);">
            <i class="fa-solid fa-bowl-food" style="font-size: 38px; color: var(--border); margin-bottom: 12px; display: block;"></i>
            <p style="font-weight: 500; margin-bottom: 6px;">Belum ada catatan realisasi pakan aktual</p>
            <p style="font-size: 13px; color: var(--text-faint); margin-bottom: 16px;">
              Konfirmasi pelaksanaan jadwal di atas atau catat pemberian pakan langsung.
            </p>
            <?php if ($siklusAktifCount > 0): ?>
              <button type="button" class="btn btn-primary" onclick="openAddFeedModal()">
                <i class="fa-solid fa-plus"></i> Catat Pakan Langsung
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
                  <th>Nama Pakan</th>
                  <th>Jumlah (kg)</th>
                  <th>Catatan Realisasi</th>
                  <th>Waktu Pencatatan</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($feedLogs as $log): ?>
                  <tr>
                    <td style="font-weight: 500; white-space: nowrap;">
                      <i class="fa-regular fa-calendar" style="color: var(--text-faint); margin-right: 5px;"></i>
                      <?php echo e($log['tanggal']); ?>
                    </td>
                    <td>
                      <strong><?php echo e($log['nama_kolam']); ?></strong>
                      <div style="font-size: 11px; color: var(--text-faint);">Siklus #<?php echo (int) $log['siklus_id']; ?></div>
                    </td>
                    <td>
                      <span class="badge-status badge-cycle">
                        <?php echo e($log['nama_ikan']); ?>
                      </span>
                    </td>
                    <td>
                      <strong><?php echo e($log['nama_pakan']); ?></strong>
                    </td>
                    <td style="font-weight: 600; color: var(--primary);">
                      <?php echo number_format((float) $log['jumlah_kg'], 2, ',', '.'); ?> kg
                    </td>
                    <td style="max-width: 240px; font-size: 12px; color: var(--text-muted);">
                      <?php if (str_starts_with((string)$log['catatan'], '[Realisasi Jadwal')): ?>
                        <span class="badge-status badge-aktif" style="font-size:10px;padding:2px 6px;margin-bottom:2px;"><i class="fa-solid fa-calendar-check"></i> Dari Jadwal</span><br>
                      <?php endif; ?>
                      <?php echo $log['catatan'] !== null && $log['catatan'] !== '' ? e($log['catatan']) : '<span style="color:var(--text-faint);">-</span>'; ?>
                    </td>
                    <td style="font-size: 11px; color: var(--text-faint); white-space: nowrap;">
                      <?php echo e($log['created_at']); ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

    </div>
<!-- MODAL 1: BUAT JADWAL PAKAN -->
<div id="addScheduleModal" class="modal-overlay">
  <div class="modal-box">
    <div class="modal-head">
      <h3 class="modal-title"><i class="fa-solid fa-calendar-plus" style="color:var(--primary);margin-right:8px;"></i>Buat Jadwal Pemberian Pakan</h3>
      <button type="button" class="modal-close" onclick="closeAddScheduleModal()">&times;</button>
    </div>
    <form method="POST" action="process/tambah_jadwal_pakan.php">
      <?php csrf_input(); ?>

      <div class="form-group">
        <label class="form-label" for="sch_siklus_id">Siklus Budidaya Aktif <span class="req">*</span></label>
        <select id="sch_siklus_id" name="siklus_id" class="form-select" required>
          <option value="">-- Pilih Kolam & Siklus --</option>
          <?php foreach ($activeCycles as $ac): ?>
            <option value="<?php echo (int) $ac['id']; ?>">
              <?php echo e($ac['nama_kolam']); ?> &mdash; <?php echo e($ac['nama_ikan']); ?> (Mulai: <?php echo e($ac['tanggal_mulai']); ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label" for="sch_tanggal_rencana">Tanggal Rencana <span class="req">*</span></label>
          <input type="date" id="sch_tanggal_rencana" name="tanggal_rencana" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="sch_waktu_rencana">Jam Pemberian (WIB) <span class="req">*</span></label>
          <input type="time" id="sch_waktu_rencana" name="waktu_rencana" class="form-input" value="08:00" required>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label" for="sch_nama_pakan">Merek / Jenis Pakan <span class="req">*</span></label>
          <input type="text" id="sch_nama_pakan" name="nama_pakan" class="form-input" maxlength="100" placeholder="Contoh: Pellet PF-1000" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="sch_jumlah_kg_rencana">Target Pakan (kg) <span class="req">*</span></label>
          <input type="number" id="sch_jumlah_kg_rencana" name="jumlah_kg_rencana" class="form-input" step="0.01" min="0.01" max="999999.99" placeholder="Contoh: 5.00" required>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label" for="sch_catatan">Catatan Jadwal (Opsional)</label>
        <textarea id="sch_catatan" name="catatan" class="form-textarea" maxlength="1000" placeholder="Contoh: Sesi pakan pagi hari"></textarea>
      </div>

      <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
        <button type="button" class="btn btn-secondary" onclick="closeAddScheduleModal()">Batal</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-calendar-check"></i> Simpan Jadwal</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL 2: KONFIRMASI PELAKSANAAN JADWAL -->
<div id="executeScheduleModal" class="modal-overlay">
  <div class="modal-box">
    <div class="modal-head">
      <h3 class="modal-title"><i class="fa-solid fa-clipboard-check" style="color:var(--primary);margin-right:8px;"></i>Konfirmasi Pelaksanaan Pakan</h3>
      <button type="button" class="modal-close" onclick="closeExecuteScheduleModal()">&times;</button>
    </div>
    <form method="POST" action="process/eksekusi_jadwal_pakan.php">
      <?php csrf_input(); ?>
      <input type="hidden" id="exec_jadwal_id" name="jadwal_id">

      <p id="exec_info" style="font-size: 13px; color: var(--text-dim); margin-bottom: 14px; background: var(--surface-alt); padding: 10px 12px; border-radius: var(--radius-sm); border: 1px solid var(--border);"></p>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label" for="exec_tanggal_aktual">Tanggal Aktual Pemberian <span class="req">*</span></label>
          <input type="date" id="exec_tanggal_aktual" name="tanggal_aktual" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="exec_jumlah_kg_aktual">Jumlah Pakan Nyata (kg) <span class="req">*</span></label>
          <input type="number" id="exec_jumlah_kg_aktual" name="jumlah_kg_aktual" class="form-input" step="0.01" min="0.01" max="999999.99" required>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label" for="exec_catatan_aktual">Catatan Realisasi (Opsional)</label>
        <textarea id="exec_catatan_aktual" name="catatan_aktual" class="form-textarea" maxlength="1000" placeholder="Kondisi respon makan ikan saat pakan ditebar..."></textarea>
      </div>

      <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
        <button type="button" class="btn btn-secondary" onclick="closeExecuteScheduleModal()">Batal</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Konfirmasi & Catat Realisasi</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL 3: BATALKAN JADWAL -->
<div id="cancelScheduleModal" class="modal-overlay">
  <div class="modal-box">
    <div class="modal-head">
      <h3 class="modal-title"><i class="fa-solid fa-ban" style="color:var(--danger);margin-right:8px;"></i>Batalkan Jadwal Pakan</h3>
      <button type="button" class="modal-close" onclick="closeCancelScheduleModal()">&times;</button>
    </div>
    <form method="POST" action="process/batal_jadwal_pakan.php">
      <?php csrf_input(); ?>
      <input type="hidden" id="cancel_jadwal_id" name="jadwal_id">

      <p id="cancel_info" style="font-size: 13px; color: var(--text-dim); margin-bottom: 14px;"></p>

      <div class="form-group">
        <label class="form-label" for="cancel_alasan">Alasan Pembatalan <span class="req">*</span></label>
        <textarea id="cancel_alasan" name="alasan_batal" class="form-textarea" maxlength="500" placeholder="Contoh: Hujan lebat disertai petir / ikan sedang dipuasakan..." required></textarea>
      </div>

      <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
        <button type="button" class="btn btn-secondary" onclick="closeCancelScheduleModal()">Tutup</button>
        <button type="submit" class="btn btn-primary" style="background:var(--danger);border-color:var(--danger);"><i class="fa-solid fa-ban"></i> Batalkan Jadwal</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL 4: CATAT PAKAN LANGSUNG (AD-HOC) -->
<div id="addFeedModal" class="modal-overlay">
  <div class="modal-box">
    <div class="modal-head">
      <h3 class="modal-title"><i class="fa-solid fa-bowl-food" style="color:var(--primary);margin-right:8px;"></i>Catat Pemberian Pakan Langsung</h3>
      <button type="button" class="modal-close" onclick="closeAddFeedModal()">&times;</button>
    </div>
    <form method="POST" action="process/tambah_pakan.php">
      <?php csrf_input(); ?>

      <div class="form-group">
        <label class="form-label" for="feed_siklus_id">Pilih Siklus Budidaya Aktif <span class="req">*</span></label>
        <select id="feed_siklus_id" name="siklus_id" class="form-select" required>
          <option value="">-- Pilih Kolam & Siklus Berjalan --</option>
          <?php foreach ($activeCycles as $ac): ?>
            <option value="<?php echo (int) $ac['id']; ?>">
              <?php echo e($ac['nama_kolam']); ?> &mdash; <?php echo e($ac['nama_ikan']); ?> (Mulai: <?php echo e($ac['tanggal_mulai']); ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label" for="feed_tanggal">Tanggal Pemberian <span class="req">*</span></label>
          <input type="date" id="feed_tanggal" name="tanggal" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="feed_jumlah_kg">Jumlah Pakan (kg) <span class="req">*</span></label>
          <input type="number" id="feed_jumlah_kg" name="jumlah_kg" class="form-input" step="0.01" min="0.01" max="999999.99" placeholder="Contoh: 5.50" required>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label" for="feed_nama_pakan">Nama / Merek Pakan *</label>
        <input type="text" id="feed_nama_pakan" name="nama_pakan" class="form-input" maxlength="100" placeholder="Contoh: Pellet PF-1000 / Hi-Pro-Vite 781" required>
      </div>

      <div class="form-group">
        <label class="form-label" for="feed_catatan">Catatan Tambahan (Opsional)</label>
        <textarea id="feed_catatan" name="catatan" class="form-textarea" maxlength="1000" placeholder="Contoh: Pemberian pakan sore hari (respon ikan sangat aktif)"></textarea>
      </div>

      <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
        <button type="button" class="btn btn-secondary" onclick="closeAddFeedModal()">Batal</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Simpan Catatan</button>
      </div>
    </form>
  </div>
</div>

<script>
function openAddScheduleModal() {
  document.getElementById('addScheduleModal').classList.add('active');
}
function closeAddScheduleModal() {
  document.getElementById('addScheduleModal').classList.remove('active');
}

function openExecuteScheduleModal(sch) {
  document.getElementById('exec_jadwal_id').value = sch.id;
  document.getElementById('exec_jumlah_kg_aktual').value = sch.jumlah_kg_rencana;
  document.getElementById('exec_info').innerHTML = '<strong>' + sch.nama_kolam + '</strong> &mdash; ' + sch.nama_ikan + '<br>Target: <strong>' + sch.jumlah_kg_rencana + ' kg</strong> (' + sch.nama_pakan + ')';
  document.getElementById('executeScheduleModal').classList.add('active');
}
function closeExecuteScheduleModal() {
  document.getElementById('executeScheduleModal').classList.remove('active');
}

function openCancelScheduleModal(id, kolamNama, tanggal) {
  document.getElementById('cancel_jadwal_id').value = id;
  document.getElementById('cancel_info').innerText = 'Membatalkan jadwal pada kolam ' + kolamNama + ' untuk tanggal ' + tanggal;
  document.getElementById('cancelScheduleModal').classList.add('active');
}
function closeCancelScheduleModal() {
  document.getElementById('cancelScheduleModal').classList.remove('active');
}

function openAddFeedModal() {
  document.getElementById('addFeedModal').classList.add('active');
}
function closeAddFeedModal() {
  document.getElementById('addFeedModal').classList.remove('active');
}

window.addEventListener('click', function(e) {
  ['addScheduleModal', 'executeScheduleModal', 'cancelScheduleModal', 'addFeedModal'].forEach(function(modalId) {
    var modal = document.getElementById(modalId);
    if (e.target === modal) {
      modal.classList.remove('active');
    }
  });
});
</script>
<?php seefish_app_end(); ?>
