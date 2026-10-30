<?php
// Koneksi database khusus proyek Warteg (BUKAN database lama "arman07").
// Variabel koneksi tetap bernama $kon agar mirip pola proyek lama.
const DB_HOST = 'localhost';
const DB_USER = 'root';
const DB_PASS = '';              // default Laragon: kosong
const DB_NAME = 'warteg_admin';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $kon = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $kon->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    error_log('Koneksi DB gagal: ' . $e->getMessage());
    http_response_code(500);
    exit('Tidak dapat terhubung ke database. Pastikan MySQL di Laragon menyala dan database "warteg_admin" sudah diimpor.');
}
