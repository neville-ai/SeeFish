# Pengujian SeeFish

Folder ini berisi pengujian native PHP untuk SeeFish. Tidak ada Composer,
PHPUnit, npm, dependensi baru, database, atau layanan eksternal yang dipakai.
Tahap 1 menguji helper/CSRF; Tahap 2 menambahkan pemeriksaan statis dan smoke
test HTTP offline; Tahap 3A menambahkan guard order dan trace include runtime
pada jalur penolakan tiga handler panen; Tahap 5 menambahkan test spesifikasi
offline untuk estimasi jumlah ikan, CSV, pemindaian tulis `panen`, dan urutan
guard dengan role.

## Menjalankan test

Dari root proyek, jika `php` ada di `PATH`:

```sh
php tests/run-unit.php
php tests/run-offline-spec.php
php tests/run-static.php
php tests/run-http.php
```

Pada Windows dengan XAMPP:

```powershell
& 'C:\xampp\php\php.exe' tests\run-unit.php
& 'C:\xampp\php\php.exe' tests\run-offline-spec.php
& 'C:\xampp\php\php.exe' tests\run-static.php
& 'C:\xampp\php\php.exe' tests\run-http.php
```

Urutan verifikasi yang dianjurkan: PHP lint file test, unit test, test
spesifikasi offline, static runner, lalu HTTP smoke test. HTTP smoke test hanya
boleh dijalankan setelah probe isolasi berhasil.

## PHP lint untuk file test

Linux/macOS:

```sh
find tests -type f -name '*.php' -print0 | xargs -0 -n1 php -l
```

Windows PowerShell:

```powershell
Get-ChildItem tests -Recurse -Filter *.php | ForEach-Object {
    C:\xampp\php\php.exe -l $_.FullName
}
```

## Test unit Tahap 1

Cakupan saat ini:

- `valid_text()`
- `valid_username()`
- `valid_password()`
- `valid_date_ymd()`
- `valid_positive_int()`
- `e()`
- `post_raw()`
- `post_trimmed()`
- `post_int()`
- `is_post()`
- `password_min_length()`
- `app_name()`
- `current_ip()`
- `current_user_agent()`
- pembuatan token CSRF (`csrf_generate()`)
- rotasi token CSRF (`csrf_rotate()`)
- output token CSRF (`csrf_input()`)

Runner unit menampilkan status setiap test dan jumlah lulus/gagal. Exit code
`0` berarti semua lulus; `1` berarti ada kegagalan.

## Static tests Tahap 2/3A/5

`tests/run-static.php` adalah runner independen. Runner ini tidak memuat
`tests/bootstrap.php` atau bootstrap aplikasi, tidak memakai session, dan tidak
membaca `.env`. Analisis memakai token PHP (`token_get_all()`), sehingga teks
di komentar dan string tidak otomatis dianggap sebagai pemanggilan fungsi.

Pemeriksaan yang dijalankan:

- **ST-01** — mendeteksi operasi mutasi yang dikenal (`INSERT`, `UPDATE`,
  `DELETE`, `REPLACE`, dan `write_log()`) pada daftar handler, lalu memeriksa
  posisi baris `csrf_verify()` sebelum operasi tersebut.
- **ST-03** — memeriksa urutan include `config/auth.php` sebelum
  `config/koneksi.php` pada `dashboard.php` dan tiga handler panen; untuk
  `logout.php` diperiksa pemeriksaan `$_SESSION['login']` sebelum koneksi.
- **ST-04** — menganalisis pemanggilan SQL (`prepare`, `query`, `mysqli_query`,
  `mysqli_prepare`, `exec`) dan menandai SQL dengan variabel/interpolasi
  dinamis. SQL literal tanpa placeholder diterima sebagai pengecualian eksplisit
  karena tidak memakai input dinamis.
- **ST-05** — mendeteksi fungsi berisiko (`eval`, `extract`, `unserialize`,
  `shell_exec`, `system`, `exec`, `passthru`, `proc_open`, `popen`,
  `pcntl_exec`) dan include/require dengan variabel dinamis.
- **ST-06** — memeriksa urutan `method guard → auth → CSRF → validasi →
  koneksi/logger` pada tiga handler panen, serta memastikan SQL mutasi memakai
  placeholder dan batasan `user_id`.
- **ST-07** — memindai literal SQL produksi dan mendeteksi operasi tulis
  (`INSERT`, `UPDATE`, `DELETE`, `REPLACE`, `TRUNCATE`, `ALTER`, `DROP`,
  `CREATE`) yang targetnya tabel legacy `panen`. Komentar SQL dan literal
  string dibersihkan lebih dulu. SQL dinamis atau target yang tidak pasti
  dilaporkan `INCONCLUSIVE`, bukan `PASS`.
- **ST-08** — memeriksa urutan `method guard → auth → role → CSRF → validasi →
  koneksi` pada tiga handler panen. Bila pemanggilan role guard tidak ditemukan,
  hasilnya `PENDING`; bila role guard hanya terlihat di file include dan urutan
  internalnya tidak dapat dibuktikan, hasilnya `INCONCLUSIVE`.
- **Handler inventory** hanya informasi untuk review keamanan. Runner memakai
  daftar handler tetap untuk ST-01/ST-03/ST-06, lalu menampilkan file entry point
  baru di luar daftar tersebut untuk review manual. Inventory bukan manifest
  stabil dan bukan gate.

Status dan exit code static runner:

- `PASS`: pemeriksaan berhasil.
- `FAIL`: ditemukan pelanggaran; exit code `1`.
- `PENDING`: fitur/guard yang diperiksa belum terlihat dan tidak boleh dianggap
  lulus; exit code `2` jika tidak ada `FAIL`.
- `INCONCLUSIVE`: analisis tidak dapat menyimpulkan dengan andal; exit code `2`
  jika tidak ada `FAIL`.

Batasan penting:

- ST-01 hanya memeriksa urutan baris, bukan alur kontrol bercabang. Pemanggilan
  dinamis seperti `call_user_func()` tidak dilacak.
- ST-03 hanya memeriksa urutan include/pemeriksaan sesi pada daftar eksplisit;
  ini tidak membuktikan seluruh logika otorisasi benar.
- ST-04 tidak melacak query yang dibangun di variabel dan tidak membuktikan
  semua query aman. Semua query aktual harus tetap ditinjau dan diuji.
- ST-05 tidak mendeteksi nama fungsi yang dibangun secara dinamis.
- ST-06 tetap memeriksa urutan token/baris, bukan alur kontrol bercabang atau
  pemanggilan dinamis. HTTP trace test menjadi bukti runtime untuk jalur yang
  diuji.
- ST-07 hanya dapat membaca literal SQL. Query yang dibangun sepenuhnya secara
  dinamis dilaporkan `INCONCLUSIVE` dan tidak boleh ditafsirkan sebagai aman.
- ST-08 hanya memeriksa tiga handler panen dan nama pemanggilan role guard yang
  dikenal. Pemeriksaan ini tidak membuktikan implementasi otorisasi yang belum
  ada di produksi.
- ST-02, ST-09, dan ST-10 tidak diimplementasikan dan tidak dijadikan quality
  gate Tahap 2/3A/5.

### Temuan yang masih terbuka

Tahap 3A memperbaiki urutan guard tiga handler panen: method non-POST ditolak
405 sebelum include, autentikasi berjalan lebih dulu, CSRF dan validasi berjalan
sebelum `config/koneksi.php`, dan database/logger baru dimuat setelah pemeriksaan
awal lolos.

Temuan yang sengaja belum diselesaikan:

- `process/delete_panen.php` masih melakukan hard delete.
- `process/update_panen.php` masih melakukan update in-place.
- Belum ada status legacy, pembatalan dengan alasan, atau record pengganti.
- `dashboard.php` masih menampilkan edit/hapus untuk semua baris, dan
  `index.php` masih memakai seluruh data `panen`.
- Tahap 5: ST-07 menemukan tiga operasi tulis ke `panen` yang masih ada:
  `process/tambah_panen.php` (INSERT), `process/update_panen.php` (UPDATE), dan
  `process/delete_panen.php` (DELETE). Temuan ini tidak diperbaiki pada tahap
  test.
- Tahap 5: ST-08 `PENDING` karena belum ada pemanggilan role guard pada tiga
  handler panen maupun `config/auth.php`. Otorisasi operator/admin belum dapat
  diverifikasi.

Perubahan semantik update/delete, model legacy, schema, role guard, dan UI
berada di luar Tahap 3A/5 dan membutuhkan keputusan desain data terpisah.

## Test spesifikasi offline Tahap 5

`tests/run-offline-spec.php` adalah runner terpisah yang **tidak** memuat
`tests/bootstrap.php`, `config/bootstrap.php`, session, konfigurasi aplikasi,
atau koneksi database. Runner ini hanya memuat assertion dan helper spesifikasi
murni di folder `tests/`.

Test yang dijalankan:

- `tests/unit/estimation_test.php` — menguji oracle offline
  `fish_estimate_spec()`: stok awal dikurangi kematian aktif dan panen aktif
  berdasarkan ekor; panen berat dan record `cancelled` tidak mengurangi
  estimasi; data kosong, nilai negatif, tipe salah, status/jenis salah, dan
  estimasi di bawah nol ditangani eksplisit.
- `tests/unit/csv_test.php` — menguji helper spesifikasi CSV offline:
  netralisasi formula `=`, `+`, `-`, `@` termasuk setelah whitespace/tab,
  escaping kutip/delimiter/newline, UTF-8 dan karakter Indonesia, dataset kosong,
  serta output header saja.
- `tests/unit/static_sql_test.php` — menguji scanner SQL sintetis untuk
  memastikan tulis ke `panen` terdeteksi, `SELECT` tidak dianggap tulis, dan
  komentar/literal string tidak menimbulkan false positive.

Helper `tests/lib/estimation_spec.php` dan `tests/lib/csv_spec.php` adalah
**spesifikasi test-only**, bukan fungsi produksi. Lulusnya test ini tidak
membuktikan bahwa estimasi, role guard, atau ekspor CSV sudah diimplementasikan
di aplikasi. Tidak ada endpoint atau query database yang dijalankan.

Runner menampilkan `PASS`, `FAIL`, `PENDING`, dan `INCONCLUSIVE`. Exit code `1`
untuk kegagalan; `2` bila ada `PENDING`/`INCONCLUSIVE` tanpa `FAIL`; `0` bila
semua lulus.

## HTTP smoke tests Tahap 2/3A

`tests/run-http.php` menjalankan PHP built-in server dengan `PHP_BINARY`:

- Hanya bind ke `127.0.0.1`, memilih port loopback bebas, dan menunggu maksimal
  5 detik sampai server siap.
- Session, log, dan trace include ditempatkan di direktori temporer sistem di
  luar workspace, lalu dibersihkan di blok `finally`. Pada Windows, penghentian
  proses memakai fallback `taskkill /F /T` bila diperlukan.
- `tests/http/server_prepend.php` menetapkan `APP_ROOT` ke
  `tests/http/no-app-root` yang tidak berisi aplikasi dan tidak memiliki `.env`.
  File ini juga dapat mencatat `get_included_files()` ke trace temporer bila
  runner mengirim header trace.
- Sebelum endpoint aplikasi dijalankan, runner meminta
  `/tests/http/isolation_probe.php` dan memastikan probe melihat
  `APP_ROOT` yang benar serta mekanisme `auto_prepend_file` benar-benar aktif.
  Jika probe gagal, endpoint aplikasi tidak dijalankan.
- HTTP client memakai stream native PHP, hanya mengizinkan `127.0.0.1`, dan
  memakai `follow_location=0` serta `max_redirects=0`. Redirect tidak diikuti;
  status dan `Location` diperiksa eksplisit.
- Preflight statis memeriksa urutan guard sebelum request sensitif dikirim. Jika
  preflight tidak dapat membuktikan `csrf_verify()`/validasi berjalan sebelum
  `koneksi.php`, request ditandai `INCONCLUSIVE` dan tidak dikirim.
- Status 5xx pada endpoint aplikasi menghentikan suite agar tidak melanjutkan
  request yang mungkin mencoba database.
- Trace include runtime dipakai untuk membuktikan `config/koneksi.php` dan
  `config/logger.php` tidak dimuat pada jalur penolakan.

Endpoint yang diuji:

- `GET login.php` — status, form, dan token CSRF.
- `GET register.php` — status, form, field password, kebijakan minimum, dan
  token CSRF.
- `GET logout.php` — status 405, header `Allow`, dan body sesuai implementasi.
- `POST logout.php` tanpa sesi — status 303 dan `Location`.
- `POST process/login_process.php` tanpa token dan dengan token salah — CSRF
  ditolak sebelum database.
- `POST process/register_process.php` tanpa token dan dengan token salah — CSRF
  ditolak sebelum database.
- `POST process/tambah_panen.php`, `update_panen.php`, dan `delete_panen.php`
  tanpa sesi — auth menghentikan request sebelum database.
- `GET`, `PUT`, `DELETE`, dan `HEAD` pada tiga handler panen — 405, header
  `Allow: POST`, tanpa session/auth/CSRF/database.
- `POST` pada tiga handler dengan session sintetis terautentikasi tetapi token
  CSRF salah — redirect CSRF sebelum database.
- `POST` pada tiga handler dengan session sintetis, token CSRF valid, tetapi
  input invalid — validasi menolak sebelum database.

HTTP runner tidak pernah meminta `index.php`, `dashboard.php`, endpoint yang
membutuhkan database, atau redirect tujuan. Tidak ada request ke jaringan
eksternal. Session sintetis hanya dibuat di direktori temporer dan tidak berisi
kredensial pengguna nyata.

Exit code HTTP runner:

- `0`: semua test lulus.
- `1`: ada kegagalan, kegagalan isolasi, atau suite dihentikan.
- `2`: prasyarat (`proc_open`, socket, `allow_url_fopen`, atau `PHP_BINARY`)
  tidak tersedia, atau ada test yang ditandai `INCONCLUSIVE` karena preflight
  tidak dapat membuktikan jalur aman. Runner menulis diagnostik dan tidak
  diam-diam melewati test.

Batasan HTTP smoke test:

- PHP built-in server bukan Apache. Status/ErrorDocument 403/404, perilaku
  `.htaccess`, dan rewrite URL tanpa ekstensi **tidak** diuji.
- URL endpoint memakai path `.php` langsung; route extensionless Apache tidak
  dibuktikan.
- Tidak ada login/registrasi valid, dashboard, CRUD panen, atau operasi
  database yang diuji.
- Test memakai session sintetis untuk membuktikan jalur auth/CSRF/validasi,
  tetapi tidak membuktikan login nyata atau sesi produksi.
- Request dengan payload fully valid tidak dijalankan offline karena akan
  mencapai `config/koneksi.php` dan query database. Jalur tersebut memerlukan
  integration test database terpisah.
- Hard delete dan update in-place belum diuji sebagai perubahan semantik karena
  memang belum diubah pada Tahap 3A/5.
- Test tidak membuktikan perilaku produksi di balik reverse proxy, HTTPS, atau
  konfigurasi Apache.

## Tanpa database dan tanpa `.env`

- `tests/bootstrap.php` mendefinisikan `APP_ROOT` ke path fixture yang tidak
  ada (`tests/fixtures/no-app-root`) sebelum memuat `config/bootstrap.php`.
- `tests/run-offline-spec.php` tidak memuat `tests/bootstrap.php` maupun
  konfigurasi aplikasi; helper estimasi/CSV dan scanner SQL diuji murni dari
  file `tests/`.
- Static runner hanya membaca file `.php` produksi dan tidak memuat bootstrap
  aplikasi atau `.env`.
- HTTP runner memakai `auto_prepend_file` terisolasi dan memverifikasinya dengan
  probe sebelum endpoint aplikasi. Aplikasi hanya memeriksa path `.env` di
  `tests/http/no-app-root` yang tidak ada; file `.env` root proyek tidak dibuka.
- Test tidak memuat `config/koneksi.php`, `config/logger.php`, atau halaman/
  handler yang membutuhkan database pada jalur yang dieksekusi. Trace runtime
  memverifikasi bahwa file database/logger tidak dimuat pada request yang
  ditolak.
- File `.env` tidak dibuat, dibaca, atau diubah oleh test.
- Tidak ada data pengguna nyata, password hash, log aplikasi, atau secret yang
  dibaca atau ditampilkan. Log server test bersifat temporer dan tidak
  ditampilkan pada eksekusi yang berhasil.

## Struktur

```text
tests/
├── bootstrap.php
├── run-unit.php
├── run-offline-spec.php
├── run-static.php
├── run-http.php
├── lib/
│   ├── assertions.php
│   ├── static_analysis.php
│   ├── estimation_spec.php
│   ├── csv_spec.php
│   └── http_client.php
├── http/
│   ├── server_prepend.php
│   └── isolation_probe.php
├── unit/
│   ├── validation_test.php
│   ├── helpers_test.php
│   ├── csrf_test.php
│   ├── estimation_test.php
│   ├── csv_test.php
│   └── static_sql_test.php
└── README.md
```

Assertion unit memakai fungsi native di `tests/lib/assertions.php`, bukan
`assert()` bawaan PHP.
