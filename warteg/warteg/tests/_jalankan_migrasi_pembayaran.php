<?php
// Jalankan migrasi pembayaran. Aman: hanya menambah kolom/tabel. Bila sudah pernah dijalankan,
// kolom yang sudah ada akan memicu error 1060 -> ditangani (dilewati).
require __DIR__ . '/../config/koneksi.php';

function jalankan(mysqli $kon, string $sql, string $label): void {
    try {
        $kon->query($sql);
        echo "[OK] $label\n";
    } catch (mysqli_sql_exception $e) {
        $kode = $e->getCode();
        if (in_array((int)$kode, [1060, 1061], true)) { // duplicate column/key
            echo "[LEWATI] $label (sudah ada: {$e->getMessage()})\n";
        } else {
            echo "[GAGAL] $label -> {$e->getMessage()}\n";
        }
    }
}

// 1) Kolom pembayaran pada pesanan (satu per satu agar aman bila sebagian sudah ada).
$kolom = [
    "ADD COLUMN status_bayar ENUM('belum_bayar','menunggu','berhasil','gagal','kedaluwarsa') NOT NULL DEFAULT 'belum_bayar' COMMENT 'status pembayaran QRIS, terpisah dari status proses' AFTER total_harga",
    "ADD COLUMN metode_bayar VARCHAR(20) NOT NULL DEFAULT 'qris' COMMENT 'metode pembayaran' AFTER status_bayar",
    "ADD COLUMN kode_bayar VARCHAR(120) NULL DEFAULT NULL COMMENT 'ID transaksi/order di payment gateway' AFTER metode_bayar",
    "ADD COLUMN qr_payload TEXT NULL DEFAULT NULL COMMENT 'string QRIS dinamis / URL gambar QR (tidak sensitif)' AFTER kode_bayar",
    "ADD COLUMN nominal_bayar BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'nominal yang harus dibayar (snapshot)' AFTER qr_payload",
    "ADD COLUMN dibayar_pada TIMESTAMP NULL DEFAULT NULL COMMENT 'waktu pembayaran terverifikasi' AFTER nominal_bayar",
    "ADD COLUMN bayar_kedaluwarsa_pada TIMESTAMP NULL DEFAULT NULL COMMENT 'batas waktu bayar' AFTER dibayar_pada",
    "ADD COLUMN token_nota CHAR(40) NULL DEFAULT NULL COMMENT 'token acak untuk membuka nota/lacak pesanan tanpa login' AFTER bayar_kedaluwarsa_pada",
    "ADD COLUMN catatan VARCHAR(255) NULL DEFAULT NULL COMMENT 'catatan pesanan dari pelanggan' AFTER token_nota",
];
foreach ($kolom as $k) jalankan($kon, "ALTER TABLE pesanan $k", 'pesanan ' . substr(trim($k), 11, 40));

jalankan($kon, 'UPDATE pesanan SET token_nota = MD5(CONCAT(kode, "-", id, "-", RAND())) WHERE token_nota IS NULL', 'isi token_nota lama');

foreach ([
    'ADD KEY idx_pesanan_status_bayar (status_bayar)',
    'ADD KEY idx_pesanan_token (token_nota)',
    'ADD KEY idx_pesanan_kode_bayar (kode_bayar)',
] as $k) jalankan($kon, "ALTER TABLE pesanan $k", 'index ' . $k);

jalankan($kon, "CREATE TABLE IF NOT EXISTS pembayaran_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pesanan_id INT UNSIGNED NULL DEFAULT NULL,
  kode_pesanan VARCHAR(30) NULL DEFAULT NULL,
  kode_bayar VARCHAR(120) NULL DEFAULT NULL,
  provider VARCHAR(20) NOT NULL DEFAULT '',
  event VARCHAR(60) NOT NULL DEFAULT '',
  status_masuk VARCHAR(30) NOT NULL DEFAULT '',
  status_hasil VARCHAR(30) NOT NULL DEFAULT '',
  nominal BIGINT UNSIGNED NULL DEFAULT NULL,
  fingerprint CHAR(64) NOT NULL,
  ip VARCHAR(45) NULL DEFAULT NULL,
  dibuat_pada TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pembayaran_fingerprint (fingerprint),
  KEY idx_pembayaran_pesanan (pesanan_id),
  KEY idx_pembayaran_kode_bayar (kode_bayar),
  CONSTRAINT fk_pembayaran_pesanan FOREIGN KEY (pesanan_id) REFERENCES pesanan(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB", 'tabel pembayaran_log');

jalankan($kon, "CREATE TABLE IF NOT EXISTS wa_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pesanan_id INT UNSIGNED NULL DEFAULT NULL,
  kode_pesanan VARCHAR(30) NULL DEFAULT NULL,
  tujuan VARCHAR(30) NOT NULL DEFAULT '',
  jenis VARCHAR(30) NOT NULL DEFAULT 'pesanan_baru',
  status ENUM('menunggu','terkirim','gagal') NOT NULL DEFAULT 'menunggu',
  kode_http INT NULL DEFAULT NULL,
  respons VARCHAR(255) NULL DEFAULT NULL,
  percobaan INT UNSIGNED NOT NULL DEFAULT 0,
  dibuat_pada TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  dikirim_pada TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_wa_pesanan (pesanan_id),
  KEY idx_wa_status (status),
  CONSTRAINT fk_wa_pesanan FOREIGN KEY (pesanan_id) REFERENCES pesanan(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB", 'tabel wa_log');

echo "\nKolom pesanan sekarang: ";
$c = $kon->query('SHOW COLUMNS FROM pesanan'); $cols = [];
while ($x = $c->fetch_assoc()) $cols[] = $x['Field'];
echo implode(', ', $cols), "\n";
