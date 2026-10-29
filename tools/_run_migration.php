<?php
require_once __DIR__ . '/../config/koneksi.php';

$sql = file_get_contents(__DIR__ . '/../database/migration_jadwal_pakan.sql');
if ($conn->multi_query($sql)) {
    do {
        if ($res = $conn->store_result()) {
            $res->free();
        }
    } while ($conn->more_results() && $conn->next_result());
    echo "Migration jadwal_pakan executed successfully!\n";
} else {
    echo "Migration failed: " . $conn->error . "\n";
}

