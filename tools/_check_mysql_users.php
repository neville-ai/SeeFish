<?php
try {
    $conn = mysqli_connect('127.0.0.1', 'root', '', 'seefish_db', 3306);
    echo "Root connection OK!\n";
    $res = $conn->query("SELECT user, host FROM mysql.user");
    while ($r = $res->fetch_assoc()) {
        echo "User: " . $r['user'] . "@" . $r['host'] . "\n";
    }
} catch (Throwable $e) {
    echo "Root error: " . $e->getMessage() . "\n";
}

