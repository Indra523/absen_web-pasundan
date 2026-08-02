-- Add approved_by column to perizinan table
ALTER TABLE perizinan ADD COLUMN approved_by VARCHAR(100) NULL AFTER status_persetujuan;
