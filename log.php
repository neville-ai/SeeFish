<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/config/koneksi.php';
require_once __DIR__ . '/config/layout.php';

$username = (string) ($_SESSION['username'] ?? 'Pengguna');
$userId = (int) $_SESSION['user_id'];
$userRole = (string) ($_SESSION['role'] ?? 'Admin Kolam');

$logs = [];
$logOk = true;

try {
    $stmt = $conn->prepare(
        "SELECT id, action, entity, entity_id, info, ip_address, created_at
         FROM activity_log
         WHERE user_id = ?
         ORDER BY id DESC
         LIMIT 100"
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} catch (Throwable $e) {
    $logOk = false;
    error_log('Log fetch error: ' . $e->getMessage());
}

$sfActions = '';
seefish_app_start([
    'nav' => 'log',
    'title' => 'Log Aktivitas - SeeFish',
    'heading' => 'Log Aktivitas',
    'subtitle' => 'Rekaman jejak audit terhadap aksi penting pada akun Anda.',
    'breadcrumbs' => [['Dashboard', 'dashboard'], ['Log Aktivitas']],
    'actions' => $sfActions,
    'query_ok' => $logOk,
]);
?>
<div class="db-content">

      <div class="db-card">
        <div class="db-card-head">
          <div>
            <h2 class="db-card-title">Audit Trail Sistem</h2>
            <p class="db-card-sub">Seluruh transaksi modifikasi data dicatat bersama alamat IP dan waktu pencatatan.</p>
          </div>
          <span class="badge-status" style="background:#F1F5F9;color:#334155;border:1px solid #CBD5E1;">
            Total: <?php echo count($logs); ?> Aksi
          </span>
        </div>

        <?php if (empty($logs)): ?>
          <div style="padding: 40px 20px; text-align: center; color: var(--text-muted);">
            <i class="fa-solid fa-clipboard-check" style="font-size: 40px; color: var(--border); margin-bottom: 12px; display: block;"></i>
            <p style="font-weight: 500; margin-bottom: 6px;">Belum ada log aktivitas tercatat</p>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="db-table">
              <thead>
                <tr>
                  <th>Waktu</th>
                  <th>Aksi</th>
                  <th>Entitas</th>
                  <th>ID Entitas</th>
                  <th>Detail Informasi</th>
                  <th>IP Address</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($logs as $l): ?>
                  <tr>
                    <td style="font-weight: 500; font-size: 12px; white-space: nowrap;">
                      <?php echo e($l['created_at']); ?>
                    </td>
                    <td>
                      <span class="action-pill">
                        <?php echo e($l['action']); ?>
                      </span>
                    </td>
                    <td style="text-transform: capitalize; font-size: 13px;">
                      <?php echo $l['entity'] !== '' ? e($l['entity']) : '-'; ?>
                    </td>
                    <td style="font-size: 12px; color: var(--text-faint);">
                      <?php echo $l['entity_id'] !== null ? '#' . (int) $l['entity_id'] : '-'; ?>
                    </td>
                    <td style="max-width: 320px; font-size: 12px; color: var(--text-muted);">
                      <?php echo e($l['info']); ?>
                    </td>
                    <td style="font-family: var(--font-mono, monospace); font-size: 11px; color: var(--text-faint);">
                      <?php echo e($l['ip_address'] ?? '-'); ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

    </div>
<?php seefish_app_end(); ?>
