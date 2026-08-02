
ALTER TABLE perizinan ADD COLUMN IF NOT EXISTS status_persetujuan ENUM("pending", "disetujui", "ditolak") DEFAULT "disetujui" AFTER keterangan;

