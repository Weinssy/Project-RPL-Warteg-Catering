-- =====================================================================
-- DATA DEMO - hanya untuk mencoba dashboard. JANGAN dipakai sebagai data produksi.
-- Jalankan SETELAH schema.sql, pada database warteg_admin, dan cukup sekali.
-- Tanggal dibuat relatif terhadap hari ini (CURDATE()).
-- Harga menu hanyalah contoh dan bisa diubah langsung di tabel `menu`.
-- =====================================================================
USE warteg_admin;

INSERT IGNORE INTO menu (nama, kategori, harga, foto, aktif) VALUES
  ('Nasi Ayam',  'nasi', 15000, NULL, 1),
  ('Nasi Ikan',  'nasi', 17000, NULL, 1),
  ('Nasi Udang', 'nasi', 20000, NULL, 1);

SET @ayam  = (SELECT id FROM menu WHERE nama='Nasi Ayam');
SET @ikan  = (SELECT id FROM menu WHERE nama='Nasi Ikan');
SET @udang = (SELECT id FROM menu WHERE nama='Nasi Udang');

-- Menu harian: hari ini Udang sengaja DINONAKTIFKAN (tapi sudah ada yang memesan -> tetap tercatat).
INSERT IGNORE INTO menu_harian (menu_id, tanggal, tersedia) VALUES
  (@ayam,  CURDATE(), 1), (@ikan,  CURDATE(), 1), (@udang, CURDATE(), 0),
  (@ayam,  CURDATE() + INTERVAL 1 DAY, 1),
  (@ikan,  CURDATE() + INTERVAL 1 DAY, 1),
  (@udang, CURDATE() + INTERVAL 1 DAY, 1);

INSERT IGNORE INTO kuota_harian (tanggal) VALUES (CURDATE()), (CURDATE() + INTERVAL 1 DAY);

-- ---- Hari ini: total 70 box ----
INSERT INTO pesanan (kode, nama_pelanggan, no_wa, tanggal_ambil, jam_ambil, total_box, total_harga, status) VALUES
  (CONCAT('WRT-', DATE_FORMAT(CURDATE(),'%Y%m%d'), '-901'), '[DEMO] Budi Santoso', '6281234567801', CURDATE(), '10:00:00', 30, 470000, 'Siap Diambil');
SET @p = LAST_INSERT_ID();
INSERT INTO detail_pesanan (pesanan_id, menu_id, jumlah, harga_satuan, subtotal) VALUES
  (@p, @ayam, 20, 15000, 300000), (@p, @ikan, 10, 17000, 170000);

INSERT INTO pesanan (kode, nama_pelanggan, no_wa, tanggal_ambil, jam_ambil, total_box, total_harga, status) VALUES
  (CONCAT('WRT-', DATE_FORMAT(CURDATE(),'%Y%m%d'), '-902'), '[DEMO] Siti Rahma', '6281234567802', CURDATE(), '11:30:00', 25, 425000, 'Sedang Dimasak');
SET @p = LAST_INSERT_ID();
INSERT INTO detail_pesanan (pesanan_id, menu_id, jumlah, harga_satuan, subtotal) VALUES
  (@p, @ayam, 15, 15000, 225000), (@p, @udang, 10, 20000, 200000);

INSERT INTO pesanan (kode, nama_pelanggan, no_wa, tanggal_ambil, jam_ambil, total_box, total_harga, status) VALUES
  (CONCAT('WRT-', DATE_FORMAT(CURDATE(),'%Y%m%d'), '-903'), '[DEMO] Andi Wijaya', '6281234567803', CURDATE(), '12:00:00', 15, 255000, 'Menunggu Konfirmasi');
SET @p = LAST_INSERT_ID();
INSERT INTO detail_pesanan (pesanan_id, menu_id, jumlah, harga_satuan, subtotal) VALUES
  (@p, @ikan, 15, 17000, 255000);

-- ---- Besok: tepat 120 box (kuota penuh): Ayam 50, Ikan 50, Udang 20 ----
INSERT INTO pesanan (kode, nama_pelanggan, no_wa, tanggal_ambil, jam_ambil, total_box, total_harga, status) VALUES
  (CONCAT('WRT-', DATE_FORMAT(CURDATE() + INTERVAL 1 DAY,'%Y%m%d'), '-901'), '[DEMO] Dewi Lestari', '6281234567804', CURDATE() + INTERVAL 1 DAY, '09:00:00', 60, 960000, 'Menunggu Konfirmasi');
SET @p = LAST_INSERT_ID();
INSERT INTO detail_pesanan (pesanan_id, menu_id, jumlah, harga_satuan, subtotal) VALUES
  (@p, @ayam, 30, 15000, 450000), (@p, @ikan, 30, 17000, 510000);

INSERT INTO pesanan (kode, nama_pelanggan, no_wa, tanggal_ambil, jam_ambil, total_box, total_harga, status) VALUES
  (CONCAT('WRT-', DATE_FORMAT(CURDATE() + INTERVAL 1 DAY,'%Y%m%d'), '-902'), '[DEMO] Rudi Hartono', '6281234567805', CURDATE() + INTERVAL 1 DAY, '13:00:00', 60, 1040000, 'Menunggu Konfirmasi');
SET @p = LAST_INSERT_ID();
INSERT INTO detail_pesanan (pesanan_id, menu_id, jumlah, harga_satuan, subtotal) VALUES
  (@p, @ayam, 20, 15000, 300000), (@p, @ikan, 20, 17000, 340000), (@p, @udang, 20, 20000, 400000);
