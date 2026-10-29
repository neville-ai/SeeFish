<?php
$c = mysqli_connect('127.0.0.1', 'root', 'seefishdevops', 'mysql', 3306);

$c->query("CREATE USER IF NOT EXISTS 'seefish_app'@'localhost' IDENTIFIED BY 'seefishdevops'");
$c->query("ALTER USER 'seefish_app'@'localhost' IDENTIFIED BY 'seefishdevops'");
$c->query("GRANT ALL PRIVILEGES ON seefish_db.* TO 'seefish_app'@'localhost'");

$c->query("CREATE USER IF NOT EXISTS 'seefish_app'@'127.0.0.1' IDENTIFIED BY 'seefishdevops'");
$c->query("ALTER USER 'seefish_app'@'127.0.0.1' IDENTIFIED BY 'seefishdevops'");
$c->query("GRANT ALL PRIVILEGES ON seefish_db.* TO 'seefish_app'@'127.0.0.1'");

$c->query("FLUSH PRIVILEGES");
echo "Users updated successfully!\n";
