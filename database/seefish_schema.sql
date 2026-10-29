-- SeeFish Database Schema (MySQL 8.4, InnoDB, utf8mb4_unicode_ci)
-- Dibuat untuk review. TIDAK DIEKSEKUSI OTOMATIS.
-- Database lama dihapus; tabel 'panen' legacy diperlakukan read-only di aplikasi (tidak dipindahkan).

SET NAMES utf8mb4;
SET time_zone = '+08:00';

CREATE DATABASE IF NOT EXISTS `seefish`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `seefish`;

-- 1) USERS - hanya Admin Kolam
CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  KEY `idx_users_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) PONDS - informasi kolam
CREATE TABLE `ponds` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `location` VARCHAR(150) NULL,
  `capacity` INT UNSIGNED NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `notes` VARCHAR(500) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ponds_name` (`name`),
  KEY `idx_ponds_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) SPECIES - jenis ikan
CREATE TABLE `species` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_species_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) CULTURE_CYCLES - siklus budidaya per kolam
CREATE TABLE `culture_cycles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pond_id` INT UNSIGNED NOT NULL,
  `species_id` INT UNSIGNED NOT NULL,
  `cycle_code` VARCHAR(50) NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NULL,
  `status` ENUM('active','closed') NOT NULL DEFAULT 'active',
  `notes` VARCHAR(500) NULL,
  `active_flag` TINYINT(1) GENERATED ALWAYS AS (CASE WHEN `status` = 'active' THEN 1 ELSE NULL END) STORED,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cycle_code` (`cycle_code`),
  UNIQUE KEY `uq_one_active_cycle_per_pond` (`pond_id`, `active_flag`),
  KEY `idx_cycles_pond_status` (`pond_id`, `status`),
  KEY `idx_cycles_species` (`species_id`),
  CONSTRAINT `fk_cycles_pond` FOREIGN KEY (`pond_id`) REFERENCES `ponds` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_cycles_species` FOREIGN KEY (`species_id`) REFERENCES `species` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_cycles_dates` CHECK (`end_date` IS NULL OR `end_date` >= `start_date`),
  CONSTRAINT `chk_cycles_closed_has_end` CHECK ((`status` = 'closed' AND `end_date` IS NOT NULL) OR (`status` = 'active' AND `end_date` IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) INITIAL_STOCKING - tebar awal per siklus (max 1 aktif per siklus)
CREATE TABLE `initial_stocking` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cycle_id` INT UNSIGNED NOT NULL,
  `quantity` INT UNSIGNED NOT NULL,
  `event_at` DATETIME NOT NULL,
  `recorded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `recorded_by` INT UNSIGNED NULL,
  `notes` VARCHAR(500) NULL,
  `status` ENUM('active','cancelled') NOT NULL DEFAULT 'active',
  `cancel_reason` VARCHAR(255) NULL,
  `cancelled_at` DATETIME NULL,
  `cancelled_by` INT UNSIGNED NULL,
  `replaces_record_id` INT UNSIGNED NULL,
  `active_flag` TINYINT(1) GENERATED ALWAYS AS (CASE WHEN `status` = 'active' THEN 1 ELSE NULL END) STORED,
  `replaces_active_flag` TINYINT(1) GENERATED ALWAYS AS (CASE WHEN `status` = 'active' AND `replaces_record_id` IS NOT NULL THEN 1 ELSE NULL END) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_one_active_stocking_per_cycle` (`cycle_id`, `active_flag`),
  UNIQUE KEY `uq_one_active_replacement_per_stocking` (`replaces_record_id`, `replaces_active_flag`),
  KEY `idx_stocking_cycle_status` (`cycle_id`, `status`),
  KEY `idx_stocking_event_at` (`event_at`),
  KEY `idx_stocking_cycle_event_at` (`cycle_id`, `event_at`),
  KEY `idx_stocking_recorded_by` (`recorded_by`),
  KEY `idx_stocking_cancelled_by` (`cancelled_by`),
  CONSTRAINT `fk_stocking_cycle` FOREIGN KEY (`cycle_id`) REFERENCES `culture_cycles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_stocking_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_stocking_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_stocking_replaces` FOREIGN KEY (`replaces_record_id`) REFERENCES `initial_stocking` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_stocking_quantity_positive` CHECK (`quantity` > 0),
  CONSTRAINT `chk_stocking_cancel_state` CHECK (
    (`status` = 'active' AND `cancel_reason` IS NULL AND `cancelled_at` IS NULL)
    OR
    (`status` = 'cancelled' AND `cancel_reason` IS NOT NULL AND TRIM(`cancel_reason`) <> '' AND `cancelled_at` IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6) FEEDING_SCHEDULES
CREATE TABLE `feeding_schedules` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cycle_id` INT UNSIGNED NOT NULL,
  `day_of_culture` INT UNSIGNED NOT NULL,
  `feed_time` TIME NULL,
  `feed_type` VARCHAR(100) NULL,
  `amount_kg` DECIMAL(10,3) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `notes` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_feed_sched_cycle_active` (`cycle_id`, `is_active`),
  KEY `idx_feed_sched_cycle_day` (`cycle_id`, `day_of_culture`),
  CONSTRAINT `fk_feed_sched_cycle` FOREIGN KEY (`cycle_id`) REFERENCES `culture_cycles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_feed_sched_day_positive` CHECK (`day_of_culture` > 0),
  CONSTRAINT `chk_feed_sched_amount_positive` CHECK (`amount_kg` IS NULL OR `amount_kg` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7) FEEDING_LOGS - immutable di MVP
CREATE TABLE `feeding_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cycle_id` INT UNSIGNED NOT NULL,
  `schedule_id` INT UNSIGNED NULL,
  `fed_at` DATETIME NOT NULL,
  `recorded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `recorded_by` INT UNSIGNED NULL,
  `feed_type` VARCHAR(100) NULL,
  `amount_kg` DECIMAL(10,3) NOT NULL,
  `notes` VARCHAR(500) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_feed_log_cycle_fedat` (`cycle_id`, `fed_at`),
  KEY `idx_feed_log_schedule` (`schedule_id`),
  KEY `idx_feed_log_recorded_by` (`recorded_by`),
  CONSTRAINT `fk_feed_log_cycle` FOREIGN KEY (`cycle_id`) REFERENCES `culture_cycles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_feed_log_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `feeding_schedules` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_feed_log_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_feed_log_amount_positive` CHECK (`amount_kg` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8) MORTALITY_RECORDS
CREATE TABLE `mortality_records` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cycle_id` INT UNSIGNED NOT NULL,
  `quantity` INT UNSIGNED NOT NULL,
  `event_at` DATETIME NOT NULL,
  `recorded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `recorded_by` INT UNSIGNED NULL,
  `cause` VARCHAR(150) NULL,
  `notes` VARCHAR(500) NULL,
  `status` ENUM('active','cancelled') NOT NULL DEFAULT 'active',
  `cancel_reason` VARCHAR(255) NULL,
  `cancelled_at` DATETIME NULL,
  `cancelled_by` INT UNSIGNED NULL,
  `replaces_record_id` INT UNSIGNED NULL,
  `active_flag` TINYINT(1) GENERATED ALWAYS AS (CASE WHEN `status` = 'active' THEN 1 ELSE NULL END) STORED,
  `replaces_active_flag` TINYINT(1) GENERATED ALWAYS AS (CASE WHEN `status` = 'active' AND `replaces_record_id` IS NOT NULL THEN 1 ELSE NULL END) STORED,
  PRIMARY KEY (`id`),
  KEY `idx_mort_cycle_status` (`cycle_id`, `status`),
  KEY `idx_mort_event_at` (`event_at`),
  KEY `idx_mort_cycle_event_at` (`cycle_id`, `event_at`),
  KEY `idx_mort_replaces` (`replaces_record_id`),
  UNIQUE KEY `uq_one_active_replacement_per_mort` (`replaces_record_id`, `replaces_active_flag`),
  KEY `idx_mort_recorded_by` (`recorded_by`),
  KEY `idx_mort_cancelled_by` (`cancelled_by`),
  CONSTRAINT `fk_mort_cycle` FOREIGN KEY (`cycle_id`) REFERENCES `culture_cycles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_mort_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_mort_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_mort_replaces` FOREIGN KEY (`replaces_record_id`) REFERENCES `mortality_records` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_mort_quantity_positive` CHECK (`quantity` > 0),
  CONSTRAINT `chk_mort_cancel_state` CHECK (
    (`status` = 'active' AND `cancel_reason` IS NULL AND `cancelled_at` IS NULL)
    OR
    (`status` = 'cancelled' AND `cancel_reason` IS NOT NULL AND TRIM(`cancel_reason`) <> '' AND `cancelled_at` IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9) HARVEST_RECORDS - basis jumlah ATAU berat (XOR)
CREATE TABLE `harvest_records` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cycle_id` INT UNSIGNED NOT NULL,
  `basis` ENUM('count','weight') NOT NULL,
  `quantity_count` INT UNSIGNED NULL,
  `quantity_kg` DECIMAL(12,3) NULL,
  `avg_weight_g` DECIMAL(10,3) NULL,
  `event_at` DATETIME NOT NULL,
  `recorded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `recorded_by` INT UNSIGNED NULL,
  `notes` VARCHAR(500) NULL,
  `status` ENUM('active','cancelled') NOT NULL DEFAULT 'active',
  `cancel_reason` VARCHAR(255) NULL,
  `cancelled_at` DATETIME NULL,
  `cancelled_by` INT UNSIGNED NULL,
  `replaces_record_id` INT UNSIGNED NULL,
  `active_flag` TINYINT(1) GENERATED ALWAYS AS (CASE WHEN `status` = 'active' THEN 1 ELSE NULL END) STORED,
  `replaces_active_flag` TINYINT(1) GENERATED ALWAYS AS (CASE WHEN `status` = 'active' AND `replaces_record_id` IS NOT NULL THEN 1 ELSE NULL END) STORED,
  PRIMARY KEY (`id`),
  KEY `idx_harvest_cycle_status` (`cycle_id`, `status`, `basis`),
  KEY `idx_harvest_event_at` (`event_at`),
  KEY `idx_harvest_cycle_event_at` (`cycle_id`, `event_at`),
  KEY `idx_harvest_replaces` (`replaces_record_id`),
  UNIQUE KEY `uq_one_active_replacement_per_harvest` (`replaces_record_id`, `replaces_active_flag`),
  KEY `idx_harvest_recorded_by` (`recorded_by`),
  KEY `idx_harvest_cancelled_by` (`cancelled_by`),
  CONSTRAINT `fk_harvest_cycle` FOREIGN KEY (`cycle_id`) REFERENCES `culture_cycles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_harvest_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_harvest_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_harvest_replaces` FOREIGN KEY (`replaces_record_id`) REFERENCES `harvest_records` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_harvest_basis_xor` CHECK (
    (`basis` = 'count' AND `quantity_count` IS NOT NULL AND `quantity_count` > 0 AND `quantity_kg` IS NULL)
    OR
    (`basis` = 'weight' AND `quantity_kg` IS NOT NULL AND `quantity_kg` > 0 AND `quantity_count` IS NULL)
  ),
  CONSTRAINT `chk_harvest_cancel_state` CHECK (
    (`status` = 'active' AND `cancel_reason` IS NULL AND `cancelled_at` IS NULL)
    OR
    (`status` = 'cancelled' AND `cancel_reason` IS NOT NULL AND TRIM(`cancel_reason`) <> '' AND `cancelled_at` IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10) ACTIVITY_LOG - permanen, tidak dihapus otomatis
CREATE TABLE `activity_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL,
  `action` VARCHAR(50) NOT NULL,
  `entity_type` VARCHAR(50) NULL,
  `entity_id` BIGINT UNSIGNED NULL,
  `info` VARCHAR(1000) NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity_user_created` (`user_id`, `created_at`),
  KEY `idx_activity_action_created` (`action`, `created_at`),
  KEY `idx_activity_entity` (`entity_type`, `entity_id`),
  KEY `idx_activity_created` (`created_at`),
  CONSTRAINT `fk_activity_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CATATAN APLIKASI (tidak di-enforce seluruhnya oleh CHECK):
-- - Satu siklus aktif per kolam: dijamin uq_one_active_cycle_per_pond; race condition dicegah via transaksi+locking.
-- - Satu tebar awal aktif per siklus: dijamin uq_one_active_stocking_per_cycle.
-- - Satu pengganti aktif langsung per catatan: dijamin oleh uq_one_active_replacement_per_* + replaces_active_flag.
-- - PENGGANTIAN/PEMBATALAN: replaces_record_id TIDAK BOLEH menunjuk ID catatan itu sendiri (self-replace); tidak dieksekusi oleh CHECK (MySQL 8.4). 
-- - Target penggantian harus status 'cancelled' + berasal dari siklus/jenis catatan yg sama -> divalidasi aplikasi dalam transaksi (SELECT ... FOR UPDATE).
-- - Pembatalan+penggantian harus atomik (BEGIN/COMMIT/ROLLBACK). Jangan mengubah replaces_record_id setelah INSERT.
-- - Hanya satu pengganti aktif langsung per target. Catatan dibatalkan tetap sebagai riwayat.
-- - Rantai A -> B -> C diizinkan; larang pembatalan/penggantian membentuk siklus atau merujuk catatan dari siklus/jenis berbeda.
-- - activity_log wajib ditulis dalam transaksi yang sama untuk operasi yg diaudit; jika gagal -> rollback.
-- - cancelled_by wajib diisi saat status 'cancelled' (divalidasi aplikasi; tidak masuk CHECK karena kolon adalah FK dengan aksi RESTRICT).
-- - Siklus closed: tidak terima catatan operasional baru; koreksi catatan lama tetap boleh (dengan alasan+audit), tidak dibuka kembali. Jadwal pakan siklus closed tidak boleh dibuat/diaktifkan/diubah jadi baru.
-- - Estimasi = tebar awal aktif - kematian aktif - panen aktif berbasis count; panen berat tdk mengurangi; jika tdk ada tebar awal aktif -> "Belum tersedia"; tolak jika estimasi < 0.
-- - event_at terpisah dari recorded_at; tolak event_at di masa depan; izinkan pencatatan terlambat.
-- - feeding_logs immutable di MVP.
-- - Data panen legacy (tabel panen lama) tetap read-only, tidak digunakan untuk estimasi.
-- - CSV_EXPORT_MAX_ROWS default 10000, perlindungan CSV injection, audit ekspor tanpa isi laporan.
-- - Akun nonaktif via is_active=0 (no hard delete), FK ON DELETE RESTRICT.
