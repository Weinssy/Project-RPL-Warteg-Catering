-- Migrasi: foto menu per tanggal. Jalankan SEKALI pada database warteg_admin (phpMyAdmin -> tab SQL).
USE warteg_admin;
ALTER TABLE menu_harian
  ADD COLUMN foto VARCHAR(255) NULL COMMENT 'foto menu khusus tanggal ini (folder uploads/menu/)' AFTER tersedia;
