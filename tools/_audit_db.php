<?php
require_once __DIR__ . '/../config/koneksi.php';

echo "--- ACTIVITY LOG LAST 25 ---\n";
$res = $conn->query("SELECT id, user_id, action, info, entity, entity_id, ip_address, created_at FROM activity_log ORDER BY id DESC LIMIT 25");
while ($r = $res->fetch_assoc()) {
    echo sprintf("[%d] %s | User: %s | Action: %s | Entity: %s (%s) | IP: %s | Info: %s\n",
        $r['id'], $r['created_at'], $r['user_id'] ?? 'null', $r['action'], $r['entity'] ?? '', $r['entity_id'] ?? '', $r['ip_address'], $r['info']
    );
}

echo "\n--- CHECKING TABLES COUNT ---\n";
$tables = ['users', 'jenis_ikan', 'kolam', 'siklus_budidaya', 'pemberian_pakan', 'jadwal_pakan', 'mortalitas', 'panen', 'kualitas_air', 'activity_log'];
foreach ($tables as $t) {
    $c = $conn->query("SELECT COUNT(*) as cnt FROM `$t`")->fetch_assoc()['cnt'];
    echo "$t: $c rows\n";
}

