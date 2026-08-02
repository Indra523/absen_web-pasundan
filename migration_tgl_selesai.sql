-- MIGRATION TAMBAH TGL_SELESAI PADA TABEL PERIZINAN
ALTER TABLE perizinan ADD COLUMN IF NOT EXISTS tgl_selesai DATE NULL AFTER tanggal;
UPDATE perizinan SET tgl_selesai = tanggal WHERE tgl_selesai IS NULL;

-- Dapatkan info indeks jika ada
ALTER TABLE perizinan DROP INDEX IF EXISTS uq_pin_tanggal;
