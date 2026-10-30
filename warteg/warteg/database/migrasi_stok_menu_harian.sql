-- Migrasi: stok menu per tanggal. Jalankan SEKALI pada database warteg_admin (phpMyAdmin -> tab SQL).

USE warteg_admin;
ALTER TABLE menu_harian
  ADD COLUMN stok INT UNSIGNED NULL
  COMMENT 'jatah box untuk tanggal ini; NULL = tanpa batas stok'
  AFTER tersedia;
