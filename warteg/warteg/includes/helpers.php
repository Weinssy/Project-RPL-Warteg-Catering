<?php
// ---------- Output & format ----------
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function rupiah($n): string { return 'Rp' . number_format((int)$n, 0, ',', '.'); }

function valid_tanggal($t): ?string {
    if (!is_string($t) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $t)) return null;
    $d = DateTime::createFromFormat('Y-m-d', $t);
    return ($d && $d->format('Y-m-d') === $t) ? $t : null;
}

function tanggal_id(string $t, bool $dgn_hari = true): string {
    $hari  = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
    $bulan = ['', 'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    $d = new DateTime($t);
    $s = $d->format('j') . ' ' . $bulan[(int)$d->format('n')] . ' ' . $d->format('Y');
    return $dgn_hari ? $hari[(int)$d->format('w')] . ', ' . $s : $s;
}

function jam_pendek(string $j): string { return substr($j, 0, 5); }

function badge_status(string $s): string {
    $kelas = [STATUS_MENUNGGU => 'badge-menunggu', STATUS_MASAK => 'badge-masak', STATUS_SIAP => 'badge-siap'][$s] ?? 'badge-menunggu';
    return '<span class="badge ' . $kelas . '">' . e($s) . '</span>';
}

function url(string $path): string { return BASE_URL . '/' . ltrim($path, '/'); }

// ---------- Upload foto menu ----------
// Dipakai bersama oleh tambah menu (admin/index.php) dan edit menu (admin/aksi/edit_menu.php).
// Mengembalikan [nama_file|null, pesan_galat|null].
function simpan_foto_upload(array $f, int $maks_byte): array {
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) return [null, null];
    if ($err !== UPLOAD_ERR_OK) return [null, 'Unggah foto gagal. Coba lagi dengan file lain.'];
    if ($f['size'] > $maks_byte) return [null, 'Ukuran foto maksimal ' . (int)round($maks_byte / 1024 / 1024) . ' MB.'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext || !@getimagesize($f['tmp_name'])) return [null, 'Format foto harus JPG, PNG, atau WebP.'];
    $dir = __DIR__ . '/../uploads/menu';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $nama = bin2hex(random_bytes(10)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $nama)) return [null, 'Foto tidak dapat disimpan di server.'];
    return [$nama, null];
}

// ---------- Foto profil admin ----------
// Foto admin disimpan terpisah dari foto menu (uploads/admin/). Database hanya menyimpan NAMA file.
const FOTO_ADMIN_MAKS = 2 * 1024 * 1024; // 2 MB, sama dengan batas foto menu

function folder_foto_admin(): string { return __DIR__ . '/../uploads/admin'; }

// Nama file foto admin harus aman dan hanya berakhiran ekstensi gambar. Mengembalikan false
// untuk nilai yang tidak memenuhi pola (mencegah path traversal & nama file aneh dari database).
function foto_admin_valid(?string $nama): bool {
    if (!is_string($nama) || $nama === '' || strlen($nama) > 60) return false;
    return (bool)preg_match('/^[a-f0-9]{20}\.(jpg|png|webp)$/', $nama);
}

// URL foto profil untuk atribut src. $versi (dibuat_pada) dipakai sebagai penanda cache agar
// browser memuat foto baru setelah diganti. null = admin belum punya foto / nama tidak valid,
// sehingga tampilan memakai avatar default (huruf pertama nama).
function url_foto_admin(?string $nama, $versi = null): ?string {
    if (!foto_admin_valid($nama)) return null;
    $u = url('uploads/admin/' . $nama);
    if ($versi !== null && $versi !== '') $u .= '?v=' . rawurlencode((string)$versi);
    return $u;
}

// Simpan file foto profil admin hasil unggahan. Validasi berlapis:
// status unggahan, ukuran, MIME asli (finfo), lalu isi gambar (getimagesize).
// Mengembalikan [nama_file|null, pesan_galat|null]. Tidak pernah memakai nama file dari pengguna.
function simpan_foto_admin(array $f): array {
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) return [null, null]; // foto opsional
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return [null, 'Ukuran foto maksimal ' . (int)(FOTO_ADMIN_MAKS / 1024 / 1024) . ' MB.'];
    }
    if ($err !== UPLOAD_ERR_OK) return [null, 'Unggah foto gagal. Coba lagi dengan file lain.'];
    if (empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) return [null, 'File foto tidak valid.'];
    if (($f['size'] ?? 0) <= 0 || $f['size'] > FOTO_ADMIN_MAKS) {
        return [null, 'Ukuran foto maksimal ' . (int)(FOTO_ADMIN_MAKS / 1024 / 1024) . ' MB.'];
    }

    // MIME berdasarkan ISI file, bukan nama/ekstensi yang dikirim browser.
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $peta = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($peta[$mime])) return [null, 'Format foto harus JPG, JPEG, PNG, atau WebP.'];

    // Pemeriksaan isi gambar: harus gambar yang benar-benar bisa dibaca library.
    $info = @getimagesize($f['tmp_name']);
    if (!$info || empty($info[2]) || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        return [null, 'File yang diunggah bukan gambar yang valid.'];
    }

    $dir = folder_foto_admin();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return [null, 'Folder foto profil tidak dapat dibuat.'];

    // Nama file dibuat sistem: 20 hex acak + ekstensi turunan dari MIME asli. Tidak ada input
    // pengguna pada nama file; whitelist ekstensi mencegah file .php tersimpan sebagai gambar.
    $nama = bin2hex(random_bytes(10)) . '.' . $peta[$mime];
    $tujuan = $dir . '/' . $nama;
    if (!move_uploaded_file($f['tmp_name'], $tujuan)) return [null, 'Foto tidak dapat disimpan di server.'];
    @chmod($tujuan, 0644);
    return [$nama, null];
}

// Hapus file foto admin dari disk HANYA jika nama valid, ada DI DALAM uploads/admin/,
// dan tidak dipakai akun admin lain. Fungsi ini tidak akan menghapus file di luar folder itu.
function hapus_foto_admin_file(mysqli $kon, ?string $nama): void {
    if (!foto_admin_valid($nama)) return;
    try {
        $dipakai = (int)db_val($kon, 'SELECT COUNT(*) FROM admin WHERE foto = ?', [$nama]);
    } catch (Throwable $e) {
        error_log('cek pemakaian foto admin gagal: ' . $e->getMessage());
        return; // ragu -> jangan hapus
    }
    if ($dipakai > 0) return;
    $nyata = realpath(folder_foto_admin() . '/' . $nama);
    $basis = realpath(folder_foto_admin());
    if ($nyata !== false && $basis !== false && strpos($nyata, $basis . DIRECTORY_SEPARATOR) === 0 && is_file($nyata)) {
        @unlink($nyata);
    }
}

// Penanda cache foto profil: gabungan waktu ubah dan nama file, sehingga browser
// memuat ulang gambar hanya ketika foto benar-benar berubah.
function versi_foto_admin(?string $nama, $diubah = null): string {
    return ($diubah !== null && $diubah !== '' ? (string)$diubah : '') . '|' . (string)$nama;
}

// Inisial untuk avatar default: huruf pertama nama (fallback ke username / "A").
function inisial_dari(?string $nama, ?string $cadangan = null): string {
    $sumber = trim((string)$nama) !== '' ? (string)$nama : (string)$cadangan;
    $sumber = trim($sumber);
    if ($sumber === '') $sumber = 'Admin';
    return mb_strtoupper(mb_substr($sumber, 0, 1, 'UTF-8'), 'UTF-8');
}

// Tampilkan avatar admin (foto atau default) dalam bentuk HTML. $klik=true menambahkan
// atribut data-lightbox agar foto dapat diperbesar (dihidupkan oleh assets/js/admin.js).
// $ukuran menambahkan kelas ukuran (mis. 'avatar-besar').
function avatar_admin_html(?string $nama, ?string $foto, $versi = null, string $kelas = 'avatar', bool $klik = true): string {
    $url = url_foto_admin($foto, $versi);
    if ($url) {
        $atr = $kelas === 'avatar' ? 'avatar' : 'avatar ' . $kelas;
        $lb  = $klik ? ' data-lightbox="' . e($url) . '"' : '';
        return '<img src="' . e($url) . '" alt="Foto profil ' . e($nama) . '" class="' . e($atr) . '"'
            . ($klik ? ' tabindex="0" role="button"' : '') . $lb . '>';
    }
    return '<span class="avatar avatar-inisial ' . e($kelas) . '" aria-hidden="true">' . e(inisial_dari($nama)) . '</span>';
}

// Username: 3-50 karakter, huruf/angka/titik/garis bawah/garis hubung.
function username_valid(string $u): bool {
    return (bool)preg_match('/^[A-Za-z0-9._-]{3,50}$/', $u);
}

// Nomor telepon Indonesia yang masuk akal: 8-15 digit setelah pembersihan
// (spasi, tanda hubung, kurung, dan awalan + diizinkan). Disimpan dalam bentuk digit saja.
function telepon_valid(string $t): bool {
    $bersih = preg_replace('/[^\d]/', '', $t);
    return strlen((string)$bersih) >= 8 && strlen((string)$bersih) <= 15;
}

// ---------- WhatsApp ----------
// Normalisasi ke format internasional Indonesia: 08xx -> 628xx, +62 / 62 dipertahankan.
function normalisasi_wa(string $no): ?string {
    $no = preg_replace('/[^\d+]/', '', trim($no));
    $no = ltrim($no, '+');
    if ($no === '' || !ctype_digit($no)) return null;
    if (strpos($no, '0') === 0)        $no = '62' . substr($no, 1);
    elseif (strpos($no, '62') !== 0)   $no = (strpos($no, '8') === 0) ? '62' . $no : $no;
    if (strpos($no, '62') !== 0 || strlen($no) < 10 || strlen($no) > 15) return null;
    return $no;
}

function link_wa(string $no, string $nama, int $box, string $kode): ?string {
    $n = normalisasi_wa($no);
    if ($n === null) return null;
    $pesan = "Halo Kak {$nama}, pesanan catering {$box} box dengan kode pesanan {$kode} sudah selesai dan siap diambil di warteg. Terima kasih.";
    return 'https://wa.me/' . $n . '?text=' . rawurlencode($pesan);
}

// ---------- Database (semua lewat prepared statement) ----------
function db_stmt(mysqli $kon, string $sql, array $p = []): mysqli_stmt {
    $st = $kon->prepare($sql);
    if ($p) {
        $tipe = '';
        foreach ($p as $v) $tipe .= is_int($v) ? 'i' : 's';
        $st->bind_param($tipe, ...$p);
    }
    $st->execute();
    return $st;
}
function db_all(mysqli $kon, string $sql, array $p = []): array {
    $st = db_stmt($kon, $sql, $p);
    $r = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $r;
}
function db_row(mysqli $kon, string $sql, array $p = []): ?array { return db_all($kon, $sql, $p)[0] ?? null; }
function db_val(mysqli $kon, string $sql, array $p = []) {
    $r = db_row($kon, $sql, $p);
    return $r ? array_values($r)[0] : null;
}
function db_exec(mysqli $kon, string $sql, array $p = []): int {
    $st = db_stmt($kon, $sql, $p);
    $n = $st->affected_rows;
    $st->close();
    return $n;
}

function json_out(array $data, int $kode = 200): void {
    http_response_code($kode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
