<?php
require_once __DIR__ . '/../config/koneksi.php';

$res = $conn->query("SHOW TABLES");
echo "TABLES IN seefish_db:\n";
while ($r = $res->fetch_row()) {
    echo " - " . $r[0] . "\n";
}

