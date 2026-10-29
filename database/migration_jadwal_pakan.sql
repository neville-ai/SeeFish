-- SeeFish Migration: Tabel Jadwal Pemberian Pakan
-- Database: seefish_db (MySQL 8.4)
-- Tujuan: Mengelola rencana waktu dan kuota pakan, terpisah dari catatan aktual pemberian_pakan.

CREATE TABLE IF NOT EXISTS `jadwal_pakan` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `siklus_id` BIGINT UNSIGNED NOT NULL,
  `tanggal_rencana` DATE NOT NULL,
  `waktu_rencana` TIME NOT NULL,
  `nama_pakan` VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `jumlah_kg_rencana` DECIMAL(10,2) NOT NULL,
  `status` ENUM('direncanakan','terlaksana','dibatalkan') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'direncanakan',
  `pemberian_pakan_id` BIGINT UNSIGNED DEFAULT NULL,
  `catatan` VARCHAR(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_jadwal_pemberian_pakan_id` (`pemberian_pakan_id`),
  KEY `idx_schedule_cycle_date` (`siklus_id`, `tanggal_rencana`),
  KEY `idx_schedule_status` (`status`),
  CONSTRAINT `fk_schedule_cycle` FOREIGN KEY (`siklus_id`) REFERENCES `siklus_budidaya` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_schedule_feed_log` FOREIGN KEY (`pemberian_pakan_id`) REFERENCES `pemberian_pakan` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_jadwal_kg_rencana` CHECK (`jumlah_kg_rencana` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
