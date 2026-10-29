<?php
$passwords = ['', 'root', 'admin', 'password', '123456', 'seefishdevops', 'seefish', 'seefish_app'];
$users = ['root', 'seefish_app'];

foreach ($users as $u) {
    foreach ($passwords as $p) {
        try {
            $c = @mysqli_connect('127.0.0.1', $u, $p, '', 3306);
            if ($c) {
                echo "SUCCESS: $u with pass '$p'\n";
                $res = $c->query("SHOW DATABASES");
                while ($r = $res->fetch_row()) {
                    echo "  DB: " . $r[0] . "\n";
                }
                exit(0);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
}
echo "All common attempts failed.\n";

