<?php
foreach (['localhost', '127.0.0.1'] as $host) {
    foreach ([['root', ''], ['seefish_app', '']] as $cred) {
        [$u, $p] = $cred;
        try {
            $conn = @mysqli_connect($host, $u, $p, 'seefish_db', 3306);
            if ($conn) {
                echo "SUCCESS: $u@$host (no pass)\n";
            }
        } catch (Throwable $e) {
            echo "FAIL: $u@$host (no pass): " . $e->getMessage() . "\n";
        }
    }
}

