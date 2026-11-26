# SeeFish - URL Routing & Access Control Map

Tanggal audit: 26 November 2026
Domain: https://ambarella.site
Web server: Apache
Backend: PHP
Arsitektur: Monolithic PHP

## 1. Mekanisme Routing

SeeFish menggunakan routing berbasis file PHP dengan Apache mod_rewrite.

Konfigurasi:
- DirectoryIndex index.php
- Options -Indexes
- URL tanpa ekstensi .php dapat diarahkan ke file PHP terkait.
- ErrorDocument 403 menggunakan 403.php.
- ErrorDocument 404 menggunakan 404.php.

## 2. Halaman Publik

| URL | File | Hak akses |
|---|---|---|
| / | index.php | Publik |
| /login | login.php | Publik |
| /register | register.php | Publik |
| /403.php | 403.php | Halaman error |
| /404.php | 404.php | Halaman error |

## 3. Halaman Terproteksi

| URL | File | Hak akses |
|---|---|---|
| /dashboard | dashboard.php | Login |
| /kolam | kolam.php | Login |
| /siklus | siklus.php | Login |
| /tebar | tebar.php | Login |
| /pakan | pakan.php | Login |
| /panen | panen.php | Login |
| /mortalitas | mortalitas.php | Login |
| /estimasi | estimasi.php | Login |
| /riwayat | riwayat.php | Login |
| /log | log.php | Login |
| /ekspor | ekspor.php | Login |
| /logout | logout.php | Login dan POST |

## 4. Endpoint Pemrosesan

Semua endpoint berikut memerlukan autentikasi, kecuali login dan registrasi.

| Endpoint | Fungsi |
|---|---|
| process/login_process.php | Autentikasi pengguna |
| process/register_process.php | Registrasi pengguna |
| process/tambah_kolam.php | Menambah kolam |
| process/update_kolam.php | Mengubah kolam |
| process/delete_kolam.php | Menghapus kolam |
| process/tambah_siklus.php | Membuat siklus |
| process/tambah_pakan.php | Mencatat pemberian pakan |
| process/tambah_jadwal_pakan.php | Membuat jadwal pakan |
| process/eksekusi_jadwal_pakan.php | Menjalankan jadwal pakan |
| process/batal_jadwal_pakan.php | Membatalkan jadwal |
| process/tambah_mortalitas.php | Mencatat kematian ikan |
| process/tambah_panen.php | Mencatat panen |
| process/catat_panen.php | Mencatat hasil panen |
| process/update_panen.php | Mengubah data panen |
| process/delete_panen.php | Menghapus data panen |
| process/koreksi_catatan.php | Koreksi catatan |
| process/tutup_siklus.php | Menutup siklus |
| process/ekspor_csv.php | Ekspor data CSV |

## 5. Direktori Internal

Direktori berikut tidak boleh diakses langsung melalui HTTP:

- /config/
- /database/
- /logs/
- /ops/
- /tests/
- /tools/

## 6. Pengamanan Routing

- Autentikasi diperiksa melalui config/auth.php.
- Token CSRF digunakan pada operasi perubahan data.
- Validasi kepemilikan data dilakukan di server.
- Query menggunakan prepared statements.
- Akses data pengguna lain ditolak.
- Directory listing dinonaktifkan.
- File konfigurasi dan direktori internal diblokir.

## 7. Bukti Pengujian

| Pengujian | Hasil |
|---|---|
| Akses /config/ | HTTP 403 |
| Akses /config/koneksi.php | HTTP 403 |
| Akses /tools/ | HTTP 403 |
| Akses /tests/ | HTTP 403 |
| Akses /database/ | HTTP 403 |
| Akses /logs/ | HTTP 403 |
| Akses /ops/ | HTTP 403 |
| User 29 mengakses siklus 18 milik User 32 | HTTP 403 |
| User 29 mengekspor siklus 15 miliknya | CSV berhasil diunduh |

Catatan: Pengujian HTTP dan implementasi kode menjadi dasar verifikasi.
