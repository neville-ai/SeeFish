# One-off converter: wrap SeeFish app pages with seefish_app_start/end.
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(r"c:\xampp\htdocs\SeeFish")

PAGES = [
    {
        "file": "dashboard.php",
        "nav": "dashboard",
        "title": "Dashboard - SeeFish",
        "heading": "Dashboard Budidaya",
        "subtitle": "php:date('l, d F Y')",
        "crumbs": "[['Dashboard']]",
        "query": "$dashboardOk",
        "actions": None,
    },
    {
        "file": "kolam.php",
        "nav": "kolam",
        "title": "Informasi Kolam - SeeFish",
        "heading": "Informasi Kolam",
        "subtitle": "Kelola data dan status kolam budidaya Anda",
        "crumbs": "[['Dashboard', 'dashboard'], ['Informasi Kolam']]",
        "query": "$kolamOk",
        "actions": None,
    },
    {
        "file": "siklus.php",
        "nav": "siklus",
        "title": "Siklus Budidaya - SeeFish",
        "heading": "Siklus Budidaya",
        "subtitle": "Buka dan pantau siklus penebaran benih ikan pada kolam Anda",
        "crumbs": "[['Dashboard', 'dashboard'], ['Siklus Budidaya']]",
        "query": "$siklusOk",
        "actions": None,
    },
    {
        "file": "tebar.php",
        "nav": "tebar",
        "title": "Tebar Awal - SeeFish",
        "heading": "Tebar Awal",
        "subtitle": "Pencatatan penebaran benih ikan awal saat pembukaan siklus budidaya",
        "crumbs": "[['Dashboard', 'dashboard'], ['Tebar Awal']]",
        "query": "$tebarOk",
        "actions": None,
    },
    {
        "file": "pakan.php",
        "nav": "pakan",
        "title": "Jadwal & Catatan Pakan - SeeFish",
        "heading": "Jadwal & Catatan Pakan",
        "subtitle": "Kelola perencanaan waktu pakan dan pantau realisasi konsumsi pakan.",
        "crumbs": "[['Dashboard', 'dashboard'], ['Jadwal & Pakan']]",
        "query": "$pakanOk",
        "actions": "pakan",
    },
    {
        "file": "mortalitas.php",
        "nav": "mortalitas",
        "title": "Catatan Kematian - SeeFish",
        "heading": "Catatan Kematian",
        "subtitle": "Catat kematian ikan untuk memantau kelangsungan hidup dan memperbarui estimasi stok.",
        "crumbs": "[['Dashboard', 'dashboard'], ['Catatan Kematian']]",
        "query": "$mortalitasOk",
        "actions": "mortalitas",
    },
    {
        "file": "panen.php",
        "nav": "panen",
        "title": "Catatan Panen - SeeFish",
        "heading": "Catatan Panen",
        "subtitle": "Pencatatan hasil panen ikan (parsial maupun total).",
        "crumbs": "[['Dashboard', 'dashboard'], ['Catatan Panen']]",
        "query": "$panenOk",
        "actions": "panen",
    },
    {
        "file": "estimasi.php",
        "nav": "estimasi",
        "title": "Estimasi Ikan - SeeFish",
        "heading": "Estimasi Ikan & Efisiensi",
        "subtitle": "Stok ikan hidup, survival rate, dan rasio konversi pakan (FCR) per siklus.",
        "crumbs": "[['Dashboard', 'dashboard'], ['Estimasi Ikan']]",
        "query": "$estimasiOk",
        "actions": "estimasi",
    },
    {
        "file": "riwayat.php",
        "nav": "riwayat",
        "title": "Riwayat & Koreksi - SeeFish",
        "heading": "Riwayat & Koreksi",
        "subtitle": "Audit trail pencatatan budidaya dengan mekanisme koreksi yang transparan.",
        "crumbs": "[['Dashboard', 'dashboard'], ['Riwayat & Koreksi']]",
        "query": "$riwayatOk",
        "actions": "riwayat",
    },
    {
        "file": "log.php",
        "nav": "log",
        "title": "Log Aktivitas - SeeFish",
        "heading": "Log Aktivitas",
        "subtitle": "Rekaman jejak audit terhadap aksi penting pada akun Anda.",
        "crumbs": "[['Dashboard', 'dashboard'], ['Log Aktivitas']]",
        "query": "$logOk",
        "actions": None,
    },
    {
        "file": "ekspor.php",
        "nav": "ekspor",
        "title": "Ekspor CSV - SeeFish",
        "heading": "Pelaporan & Ekspor CSV",
        "subtitle": "Laporan per siklus dan unduhan spreadsheet yang aman.",
        "crumbs": "[['Dashboard', 'dashboard'], ['Ekspor CSV']]",
        "query": "$eksporOk",
        "actions": None,
    },
]


def php_str(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def actions_php(kind: str | None) -> str:
    if kind == "pakan":
        return r"""ob_start();
if ($siklusAktifCount > 0) {
    echo '<button type="button" class="db-btn db-btn-primary" onclick="openAddScheduleModal()"><i class="fa-solid fa-calendar-plus"></i> Buat Jadwal Pakan</button> ';
    echo '<button type="button" class="db-btn db-btn-ghost" onclick="openAddFeedModal()"><i class="fa-solid fa-plus"></i> Catat Pakan Langsung</button>';
} else {
    echo '<a href="siklus" class="db-btn db-btn-ghost"><i class="fa-solid fa-fish-fins"></i> Buka Siklus Dulu</a>';
}
$sfActions = ob_get_clean();
"""
    if kind == "mortalitas":
        return r"""ob_start();
if (!empty($activeCycles)) {
    echo '<button type="button" class="db-btn db-btn-primary" onclick="openAddMortalityModal()"><i class="fa-solid fa-plus"></i> Catat Kematian</button>';
} else {
    echo '<a href="siklus" class="db-btn db-btn-ghost"><i class="fa-solid fa-fish-fins"></i> Buka Siklus Dulu</a>';
}
$sfActions = ob_get_clean();
"""
    if kind == "panen":
        return r"""ob_start();
if (!empty($activeCycles)) {
    echo '<button type="button" class="db-btn db-btn-primary" onclick="openAddHarvestModal()"><i class="fa-solid fa-plus"></i> Catat Hasil Panen</button>';
} else {
    echo '<a href="siklus" class="db-btn db-btn-ghost"><i class="fa-solid fa-fish-fins"></i> Buka Siklus Dulu</a>';
}
$sfActions = ob_get_clean();
"""
    if kind == "estimasi":
        return "$sfActions = '<a href=\"ekspor\" class=\"db-btn db-btn-ghost\"><i class=\"fa-solid fa-file-csv\"></i> Unduh Rekap CSV</a>';\n"
    if kind == "riwayat":
        return "$sfActions = '<a href=\"log\" class=\"db-btn db-btn-ghost\"><i class=\"fa-solid fa-clipboard-list\"></i> Log Aktivitas</a>';\n"
    return "$sfActions = '';\n"


def start_block(page: dict) -> str:
    subtitle = page["subtitle"]
    if subtitle.startswith("php:"):
        sub_php = subtitle[4:]
    else:
        sub_php = php_str(subtitle)
    query = page["query"]
    query_line = f"    'query_ok' => {query},\n" if query else ""
    return (
        actions_php(page["actions"])
        + "require_once __DIR__ . '/config/layout.php';\n"
        + "seefish_app_start([\n"
        + f"    'nav' => {php_str(page['nav'])},\n"
        + f"    'title' => {php_str(page['title'])},\n"
        + f"    'heading' => {php_str(page['heading'])},\n"
        + f"    'subtitle' => {sub_php},\n"
        + f"    'breadcrumbs' => {page['crumbs']},\n"
        + "    'actions' => $sfActions,\n"
        + query_line
        + "]);\n"
    )


def strip_balanced_block(html: str, start_pat: str) -> tuple[str, str]:
    m = re.search(start_pat, html, re.I)
    if not m:
        return html, ""
    start = m.start()
    tag = "header" if m.group(0).lower().startswith("<header") else "div"
    i = m.end()
    depth = 1
    while i < len(html) and depth:
        open_m = re.match(rf"<{tag}\b", html[i:], re.I)
        close_m = re.match(rf"</{tag}>", html[i:], re.I)
        if open_m:
            depth += 1
            i += open_m.end()
        elif close_m:
            depth -= 1
            i += close_m.end()
        else:
            i += 1
    return html[:start] + html[i:], html[start:i]


def convert(page: dict) -> None:
    path = ROOT / page["file"]
    text = path.read_text(encoding="utf-8")
    if "seefish_app_start" in text:
        print("skip", page["file"])
        return

    if "config/layout.php" not in text:
        text = text.replace(
            "require_once __DIR__ . '/config/helpers.php';\n",
            "require_once __DIR__ . '/config/helpers.php';\nrequire_once __DIR__ . '/config/layout.php';\n",
            1,
        )
        if "config/layout.php" not in text:
            text = text.replace(
                "require_once __DIR__ . '/config/koneksi.php';\n",
                "require_once __DIR__ . '/config/koneksi.php';\nrequire_once __DIR__ . '/config/layout.php';\n",
                1,
            )

    text = re.sub(
        r"\nheader\('X-Content-Type-Options: nosniff'\);\nheader\('X-Frame-Options: DENY'\);\nheader\('Referrer-Policy: strict-origin-when-cross-origin'\);\nheader\(\"Content-Security-Policy:.*?\n",
        "\n",
        text,
        count=1,
        flags=re.S,
    )

    m = re.search(r"<!DOCTYPE html>", text, re.I)
    if not m:
        raise SystemExit(f"no doctype in {page['file']}")
    php_prefix = text[: m.start()].rstrip()
    if php_prefix.endswith("?>"):
        php_prefix = php_prefix[:-2].rstrip()

    html = text[m.start() :]

    aside = re.search(r"</aside>", html, re.I)
    if not aside:
        raise SystemExit(f"no aside in {page['file']}")
    rest = html[aside.end() :]

    rest = re.sub(r"^\s*<!-- MAIN[^>]*-->\s*", "", rest, count=1)
    rest = re.sub(r"^\s*<main class=\"db-main\">\s*", "", rest, count=1)
    rest = re.sub(r"^\s*<!-- TOPBAR[^>]*-->\s*", "", rest, count=1)
    rest, _ = strip_balanced_block(rest, r'<(?:div|header) class="db-topbar">')
    rest = re.sub(r"^\s*</header>\s*", "", rest, count=1)

    # Close of wrap/main before modals or scripts
    rest = re.sub(r"\s*</main>\s*</div>\s*", "\n", rest, count=1)
    rest = re.sub(r'\s*<script src="assets/js/dashboard\.js"></script>\s*', "\n", rest)
    rest = re.sub(r"\s*</body>\s*</html>\s*$", "\n", rest)

    # Drop unused per-page CSS that is now in dashboard.css
    # (already removed with head)

    insert = start_block(page)
    # layout already required above; avoid double require in start_block
    insert = insert.replace("require_once __DIR__ . '/config/layout.php';\n", "")

    out = php_prefix + "\n\n" + insert + "?>\n" + rest.lstrip()
    if not out.rstrip().endswith("seefish_app_end();"):
        out = out.rstrip() + "\n<?php seefish_app_end(); ?>\n"
    path.write_text(out, encoding="utf-8", newline="\n")
    print("converted", page["file"])


if __name__ == "__main__":
    for page in PAGES:
        convert(page)
