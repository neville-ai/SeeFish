<?php
$c = mysqli_connect('127.0.0.1', 'root', 'seefishdevops', 'mysql', 3306);
$res = $c->query("SELECT user, host, plugin FROM mysql.user");
while ($r = $res->fetch_assoc()) {
    echo "User: {$r['user']}@{$r['host']} (plugin: {$r['plugin']})\n";
}

