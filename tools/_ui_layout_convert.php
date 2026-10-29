<?php
declare(strict_types=1);

$root = dirname(__DIR__);

$pages = [
    ['file' => 'dashboard.php', 'nav' => 'dashboard', 'title' => 'Dashboard - SeeFish', 'heading' => 'Dashboard Budidaya', 'subtitle' => "date('l, d F Y')", 'crumbs' => "[['Dashboard']]", 'query' => '$dashboardOk', 'actions' => null],
    ['file' => 'kolam.php', 'nav' => 'kolam', 'title' => 'Informasi Kolam - SeeFish', 'heading' => 'Informasi Kolam', 'subtitle' => "'Kelola data dan status kolam budidaya Anda'", 'crumbs' => "[['Dashboard', 'dashboard'], ['Informasi Kolam']]", 'query' => '$kolamOk', 'actions' => null],
    ['file' => 'siklus.php', 'nav' => 'siklus', 'title' => 'Siklus Budidaya - SeeFish', 'heading' => 'Siklus Budidaya', 'subtitle' => "'Buka dan pantau siklus penebaran benih ikan pada kolam Anda'", 'crumbs' => "[['Dashboard', 'dashboard'], ['Siklus Budidaya']]", 'query' => '$siklusOk', 'actions' => null],
    ['file' => 'tebar.php', 'nav' => 'tebar', 'title' => 'Tebar Awal - SeeFish', 'heading' => 'Tebar Awal', 'subtitle' => "'Pencatatan penebaran benih ikan awal saat pembukaan siklus budidaya'", 'crumbs' => "[['Dashboard', 'dashboard'], ['Tebar Awal']]", 'query' => '$tebarOk', 'actions' => null],
    ['file' => 'pakan.php', 'nav' => 'pakan', 'title' => 'Jadwal & Catatan Pakan - SeeFish', 'heading' => 'Jadwal & Catatan Pakan', 'subtitle' => "'Kelola perencanaan waktu pakan dan pantau realisasi konsumsi pakan.'", 'crumbs' => "[['Dashboard', 'dashboard'], ['Jadwal & Pakan']]", 'query' => '$pakanOk', 'actions' => 'pakan'],
    ['file' => 'mortalitas.php', 'nav' => 'mortalitas', 'title' => 'Catatan Kematian - SeeFish', 'heading' => 'Catatan Kematian', 'subtitle' => "'Catat kematian ikan untuk memantau kelangsungan hidup dan memperbarui estimasi stok.'", 'crumbs' => "[['Dashboard', 'dashboard'], ['Catatan Kematian']]", 'query' => '$mortalitasOk', 'actions' => 'mortalitas'],
    ['file' => 'panen.php', 'nav' => 'panen', 'title' => 'Catatan Panen - SeeFish', 'heading' => 'Catatan Panen', 'subtitle' => "'Pencatatan hasil panen ikan (parsial maupun total).'", 'crumbs' => "[['Dashboard', 'dashboard'], ['Catatan Panen']]", 'query' => '$panenOk', 'actions' => 'panen'],
    ['file' => 'estimasi.php', 'nav' => 'estimasi', 'title' => 'Estimasi Ikan - SeeFish', 'heading' => 'Estimasi Ikan & Efisiensi', 'subtitle' => "'Stok ikan hidup, survival rate, dan rasio konversi pakan (FCR) per siklus.'", 'crumbs' => "[['Dashboard', 'dashboard'], ['Estimasi Ikan']]", 'query' => '$estimasiOk', 'actions' => 'estimasi'],
    ['file' => 'riwayat.php', 'nav' => 'riwayat', 'title' => 'Riwayat & Koreksi - SeeFish', 'heading' => 'Riwayat & Koreksi', 'subtitle' => "'Audit trail pencatatan budidaya dengan mekanisme koreksi yang transparan.'", 'crumbs' => "[['Dashboard', 'dashboard'], ['Riwayat & Koreksi']]", 'query' => '$riwayatOk', 'actions' => 'riwayat'],
    ['file' => 'log.php', 'nav' => 'log', 'title' => 'Log Aktivitas - SeeFish', 'heading' => 'Log Aktivitas', 'subtitle' => "'Rekaman jejak audit terhadap aksi penting pada akun Anda.'", 'crumbs' => "[['Dashboard', 'dashboard'], ['Log Aktivitas']]", 'query' => '$logOk', 'actions' => null],
    ['file' => 'ekspor.php', 'nav' => 'ekspor', 'title' => 'Ekspor CSV - SeeFish', 'heading' => 'Pelaporan & Ekspor CSV', 'subtitle' => "'Laporan per siklus dan unduhan spreadsheet yang aman.'", 'crumbs' => "[['Dashboard', 'dashboard'], ['Ekspor CSV']]", 'query' => '$eksporOk', 'actions' => null],
];

function php_quote(string $value): string
{
    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
}

function actions_php(?string $kind): string
{
    return match ($kind) {
        'pakan' => <<<'PHP'
ob_start();
if ($siklusAktifCount > 0) {
    echo '<button type="button" class="db-btn db-btn-primary" onclick="openAddScheduleModal()"><i class="fa-solid fa-calendar-plus"></i> Buat Jadwal Pakan</button> ';
    echo '<button type="button" class="db-btn db-btn-ghost" onclick="openAddFeedModal()"><i class="fa-solid fa-plus"></i> Catat Pakan Langsung</button>';
} else {
    echo '<a href="siklus" class="db-btn db-btn-ghost"><i class="fa-solid fa-fish-fins"></i> Buka Siklus Dulu</a>';
}
$sfActions = ob_get_clean();

PHP,
        'mortalitas' => <<<'PHP'
ob_start();
if (!empty($activeCycles)) {
    echo '<button type="button" class="db-btn db-btn-primary" onclick="openAddMortalityModal()"><i class="fa-solid fa-plus"></i> Catat Kematian</button>';
} else {
    echo '<a href="siklus" class="db-btn db-btn-ghost"><i class="fa-solid fa-fish-fins"></i> Buka Siklus Dulu</a>';
}
$sfActions = ob_get_clean();

PHP,
        'panen' => <<<'PHP'
ob_start();
if (!empty($activeCycles)) {
    echo '<button type="button" class="db-btn db-btn-primary" onclick="openAddHarvestModal()"><i class="fa-solid fa-plus"></i> Catat Hasil Panen</button>';
} else {
    echo '<a href="siklus" class="db-btn db-btn-ghost"><i class="fa-solid fa-fish-fins"></i> Buka Siklus Dulu</a>';
}
$sfActions = ob_get_clean();

PHP,
        'estimasi' => "\$sfActions = '<a href=\"ekspor\" class=\"db-btn db-btn-ghost\"><i class=\"fa-solid fa-file-csv\"></i> Unduh Rekap CSV</a>';\n",
        'riwayat' => "\$sfActions = '<a href=\"log\" class=\"db-btn db-btn-ghost\"><i class=\"fa-solid fa-clipboard-list\"></i> Log Aktivitas</a>';\n",
        default => "\$sfActions = '';\n",
    };
}

function start_block(array $page): string
{
    $queryLine = $page['query'] ? "    'query_ok' => {$page['query']},\n" : '';
    return actions_php($page['actions'])
        . "seefish_app_start([\n"
        . "    'nav' => " . php_quote($page['nav']) . ",\n"
        . "    'title' => " . php_quote($page['title']) . ",\n"
        . "    'heading' => " . php_quote($page['heading']) . ",\n"
        . "    'subtitle' => {$page['subtitle']},\n"
        . "    'breadcrumbs' => {$page['crumbs']},\n"
        . "    'actions' => \$sfActions,\n"
        . $queryLine
        . "]);\n";
}

function strip_balanced_block(string $html, string $startPat): array
{
    if (!preg_match($startPat, $html, $m, PREG_OFFSET_CAPTURE)) {
        return [$html, ''];
    }
    $start = $m[0][1];
    $tag = str_starts_with(strtolower($m[0][0]), '<header') ? 'header' : 'div';
    $i = $start + strlen($m[0][0]);
    $depth = 1;
    $len = strlen($html);
    while ($i < $len && $depth > 0) {
        $open = stripos($html, '<' . $tag, $i);
        $close = stripos($html, '</' . $tag . '>', $i);
        if ($close === false) {
            break;
        }
        if ($open !== false && $open < $close) {
            $gt = strpos($html, '>', $open);
            $i = $gt === false ? $open + 1 : $gt + 1;
            $depth++;
        } else {
            $i = $close + strlen('</' . $tag . '>');
            $depth--;
        }
    }
    return [substr($html, 0, $start) . substr($html, $i), substr($html, $start, $i - $start)];
}

foreach ($pages as $page) {
    $path = $root . DIRECTORY_SEPARATOR . $page['file'];
    $text = file_get_contents($path);
    if ($text === false) {
        fwrite(STDERR, "missing {$page['file']}\n");
        exit(1);
    }
    if (str_contains($text, 'seefish_app_start')) {
        echo "skip {$page['file']}\n";
        continue;
    }

    if (!str_contains($text, "config/layout.php")) {
        $replaced = preg_replace(
            "/require_once __DIR__ \\. '\\/config\\/helpers\\.php';\\n/",
            "require_once __DIR__ . '/config/helpers.php';\nrequire_once __DIR__ . '/config/layout.php';\n",
            $text,
            1,
            $count
        );
        if ($count === 0) {
            $text = preg_replace(
                "/require_once __DIR__ \\. '\\/config\\/koneksi\\.php';\\n/",
                "require_once __DIR__ . '/config/koneksi.php';\nrequire_once __DIR__ . '/config/layout.php';\n",
                $text,
                1
            );
        } else {
            $text = $replaced;
        }
    }

    $text = preg_replace(
        "/\nheader\\('X-Content-Type-Options: nosniff'\\);\nheader\\('X-Frame-Options: DENY'\\);\nheader\\('Referrer-Policy: strict-origin-when-cross-origin'\\);\nheader\\(\"Content-Security-Policy:.*?\\n/",
        "\n",
        $text,
        1
    );

    $pos = stripos($text, '<!DOCTYPE html>');
    if ($pos === false) {
        fwrite(STDERR, "no doctype in {$page['file']}\n");
        exit(1);
    }
    $phpPrefix = rtrim(substr($text, 0, $pos));
    if (str_ends_with($phpPrefix, '?>')) {
        $phpPrefix = rtrim(substr($phpPrefix, 0, -2));
    }

    $html = substr($text, $pos);
    $asidePos = stripos($html, '</aside>');
    if ($asidePos === false) {
        fwrite(STDERR, "no aside in {$page['file']}\n");
        exit(1);
    }
    $rest = substr($html, $asidePos + strlen('</aside>'));
    $rest = preg_replace('/^\s*<!-- MAIN[^>]*-->\s*/', '', $rest, 1);
    $rest = preg_replace('/^\s*<main class="db-main">\s*/', '', $rest, 1);
    $rest = preg_replace('/^\s*<!-- TOPBAR[^>]*-->\s*/', '', $rest, 1);
    [$rest] = strip_balanced_block($rest, '/<(?:div|header) class="db-topbar">/');
    $rest = preg_replace('/^\s*<\/header>\s*/', '', $rest, 1);
    $rest = preg_replace('/\s*<\/main>\s*<\/div>\s*/', "\n", $rest, 1);
    $rest = preg_replace('/\s*<script src="assets\/js\/dashboard\.js"><\/script>\s*/', "\n", $rest);
    $rest = preg_replace('/\s*<\/body>\s*<\/html>\s*$/', "\n", $rest);

    $out = $phpPrefix . "\n\n" . start_block($page) . "?>\n" . ltrim($rest);
    if (!str_ends_with(rtrim($out), 'seefish_app_end();')) {
        $out = rtrim($out) . "\n<?php seefish_app_end(); ?>\n";
    }
    file_put_contents($path, $out);
    echo "converted {$page['file']}\n";
}
