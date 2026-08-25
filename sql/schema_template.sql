-- ============================================================
-- TEMPLATE SKEMA DATABASE TENANT SEKOLAH (MULTI-TENANT SAAS)
-- Dijalankan otomatis saat Master Admin menambahkan sekolah baru
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Tabel Master Karyawan / Guru
DROP TABLE IF EXISTS `master_karyawan`;
CREATE TABLE `master_karyawan` (
  `pin` varchar(50) NOT NULL,
  `nama` varchar(100) NOT NULL,
  `departemen` varchar(50) DEFAULT NULL,
  `tipe` enum('karyawan','guru') NOT NULL DEFAULT 'karyawan',
  `foto` varchar(255) DEFAULT NULL,
  `no_hp` varchar(30) DEFAULT NULL,
  `tempat_lahir` varchar(100) DEFAULT NULL,
  `tanggal_lahir` date DEFAULT NULL,
  `alamat` text DEFAULT NULL,
  `latitude_rumah` decimal(10,8) DEFAULT NULL,
  `longitude_rumah` decimal(11,8) DEFAULT NULL,
  `catatan_alamat` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`pin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabel Users (Akun Login Sekolah)
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL COMMENT 'Hashed dengan password_hash()',
  `role` varchar(20) NOT NULL DEFAULT 'admin',
  `pin` varchar(50) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `last_active` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tabel Log Presensi (Mesin & Mobile Selfie Face AI)
DROP TABLE IF EXISTS `log_absen`;
CREATE TABLE `log_absen` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pin` varchar(50) NOT NULL,
  `waktu` datetime NOT NULL,
  `status` varchar(10) DEFAULT NULL,
  `tipe_verifikasi` varchar(10) DEFAULT NULL,
  `foto_selfie` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pin_waktu` (`pin`,`waktu`),
  KEY `idx_waktu` (`waktu`),
  KEY `idx_pin_waktu` (`pin`,`waktu`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Tabel Perizinan & Surat Dokter
DROP TABLE IF EXISTS `perizinan`;
CREATE TABLE `perizinan` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pin` varchar(50) NOT NULL,
  `tanggal` date NOT NULL,
  `tgl_selesai` date DEFAULT NULL,
  `tipe_izin` enum('cuti','izin','sakit') NOT NULL,
  `keterangan` text DEFAULT NULL,
  `surat_dokter` varchar(255) DEFAULT NULL,
  `status_persetujuan` enum('pending','disetujui','ditolak') DEFAULT 'disetujui',
  `approved_by` varchar(100) DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pin_tanggal` (`pin`,`tanggal`),
  CONSTRAINT `fk_perizinan_karyawan` FOREIGN KEY (`pin`) REFERENCES `master_karyawan` (`pin`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Tabel Jadwal Mengajar Guru
DROP TABLE IF EXISTS `jadwal_guru`;
CREATE TABLE `jadwal_guru` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pin` varchar(50) NOT NULL,
  `hari` tinyint(4) NOT NULL COMMENT '1=Senin 2=Selasa 3=Rabu 4=Kamis 5=Jumat 6=Sabtu',
  `keterangan` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pin_hari` (`pin`,`hari`),
  CONSTRAINT `fk_jadwal_karyawan` FOREIGN KEY (`pin`) REFERENCES `master_karyawan` (`pin`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Tabel Hari Libur Nasional & Sekolah
DROP TABLE IF EXISTS `hari_libur`;
CREATE TABLE `hari_libur` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tanggal` date NOT NULL,
  `keterangan` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `tanggal` (`tanggal`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Tabel Pengaturan Sekolah
DROP TABLE IF EXISTS `app_settings`;
CREATE TABLE `app_settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Tabel Hak Akses Role
DROP TABLE IF EXISTS `role_permissions`;
CREATE TABLE `role_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role` varchar(20) NOT NULL,
  `page_key` varchar(50) NOT NULL,
  `is_allowed` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_page` (`role`,`page_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Tabel Notifikasi
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `target_role` varchar(20) DEFAULT 'admin',
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `type` varchar(50) DEFAULT 'perizinan',
  `link` varchar(255) DEFAULT 'kelola_izin.php',
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Tabel Audit Logs Sekolah
DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `role` varchar(20) NOT NULL,
  `action` varchar(100) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SEEDER DATA DEFAULT UNTUK SETIAP TENANT BARU
-- ============================================================

-- Default App Settings
INSERT INTO `app_settings` (`setting_key`, `setting_value`) VALUES
('jam_masuk', '07:00'),
('jam_toleransi', '07:15'),
('jam_pulang', '15:00'),
('school_latitude', '-6.90652863'),
('school_longitude', '107.57195250'),
('gps_radius_meters', '150'),
('allowed_wifi_subnets', '172.16., 192.168., 10., 127.0.0.1, ::1');

-- Default Role Permissions
INSERT INTO `role_permissions` (`role`, `page_key`, `is_allowed`) VALUES
('admin', 'index', 1),
('admin', 'export_bulanan', 1),
('admin', 'riwayat', 1),
('admin', 'kelola_izin', 1),
('admin', 'rnd_analytics', 0),
('admin', 'audit_log', 0),
('admin', 'jadwal_guru', 1),
('admin', 'export_pdf', 1),
('admin', 'export_excel', 1),
('admin', 'notifikasi', 1),
('admin', 'ganti_password', 1),
('admin', 'user_profile', 0),
('admin', 'user_izin', 0),
('admin', 'user_riwayat', 0),
('admin', 'akses_rute_maps', 1),
('rnd', 'index', 1),
('rnd', 'export_bulanan', 1),
('rnd', 'riwayat', 1),
('rnd', 'kelola_izin', 1),
('rnd', 'rnd_analytics', 1),
('rnd', 'audit_log', 1),
('rnd', 'jadwal_guru', 1),
('rnd', 'export_pdf', 1),
('rnd', 'export_excel', 1),
('rnd', 'notifikasi', 1),
('rnd', 'ganti_password', 1),
('rnd', 'user_profile', 0),
('rnd', 'user_izin', 0),
('rnd', 'user_riwayat', 0),
('rnd', 'akses_rute_maps', 1),
('tatausaha', 'index', 1),
('tatausaha', 'export_bulanan', 1),
('tatausaha', 'riwayat', 1),
('tatausaha', 'export_excel', 1),
('tatausaha', 'export_pdf', 1),
('tatausaha', 'kelola_izin', 1),
('tatausaha', 'notifikasi', 1),
('tatausaha', 'ganti_password', 1),
('tatausaha', 'user_profile', 1),
('tatausaha', 'user_izin', 0),
('tatausaha', 'user_riwayat', 0),
('tatausaha', 'akses_rute_maps', 1),
('staff', 'index', 1),
('staff', 'export_bulanan', 1),
('staff', 'riwayat', 1),
('staff', 'export_excel', 1),
('staff', 'export_pdf', 0),
('staff', 'kelola_izin', 1),
('staff', 'notifikasi', 1),
('staff', 'ganti_password', 1),
('staff', 'user_profile', 1),
('staff', 'user_izin', 0),
('staff', 'user_riwayat', 0),
('staff', 'akses_rute_maps', 1),
('user', 'user_profile', 1),
('user', 'user_izin', 1),
('user', 'user_riwayat', 1),
('user', 'notifikasi', 0),
('user', 'ganti_password', 1),
('user', 'akses_rute_maps', 0);

SET FOREIGN_KEY_CHECKS = 1;
