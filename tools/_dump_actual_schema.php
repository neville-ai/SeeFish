<?php
require_once __DIR__ . '/../config/koneksi.php';

$res = $conn->query("SHOW TABLES");
while ($r = $res->fetch_row()) {
    $table = $r[0];
    echo "=== TABLE: $table ===\n";
    $cres = $conn->query("SHOW CREATE TABLE `$table`");
    $crow = $cres->fetch_row();
    echo $crow[1] . "\n\n";
}

