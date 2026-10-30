<?php
// =====================================================================
// Endpoint AJAX halaman pelanggan (publik, tanpa login).
//   aksi=menu     : ambil daftar menu + info kuota untuk tanggal tertentu.
//   aksi=cek_kuota: ringkasan kuota satu tanggal.
//   aksi=pesan    : buat pesanan (validasi penuh backend) + buat transaksi QRIS.
//
// Keamanan:
//   * CSRF token wajib (mencegah pengiriman lintas situs).
//   * Rate limit sederhana per IP untuk pembuatan pesanan.
//   * Harga, stok, kuota SELALU dihitung ulang di server (buat_pesanan()).
//   * Idempoten: kunci idempoten mencegah pesanan ganda akibat klik berulang.
// =====================================================================
require_once __DIR__ . '/includes/bootstrap.php';

$aksi = (string)($_REQUEST['aksi'] ?? '');

// ---------- Util ----------
function hanya_post(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'pesan' => 'Metode tidak diizinkan.'], 405);
}

// Rate limit sederhana berbasis sesi+IP: maksimal N aksi dalam jendela waktu detik.
function batasi_rate(string $kunci, int $maks, int $jendela): bool {
    mulai_sesi();
    $now = time();
    $key = 'rl_' . $kunci;
    $arr = $_SESSION[$key] ?? ['n' => 0, 't' => $now];
    if ($now - $arr['t'] > $jendela) { $arr = ['n' => 0, 't' => $now]; }
    $arr['n']++;
    $_SESSION[$key] = $arr;
    return $arr['n'] <= $maks;
}

// ---------- aksi=menu (GET) ----------
if ($aksi === 'menu') {
    $tgl = valid_tanggal($_GET['tanggal'] ?? null);
    $min = (new DateTime('today'))->modify('+' . MINIMAL_H_MINUS . ' day')->format('Y-m-d');
    if ($tgl === null) json_out(['ok' => false, 'pesan' => 'Tanggal tidak valid.'], 400);

    $menu = menu_per_tanggal($kon, $tgl);
    $r = ringkasan_tanggal($kon, $tgl);

    // Susun data ringkas untuk JS (harga tetap sumber kebenaran di server saat checkout).
    $out = [];
    foreach ($menu as $m) {
        $tersedia = ((int)$m['tersedia'] === 1) && !empty($m['aktif']);
        $sisa_stok = $m['stok'] === null ? null : max(0, (int)$m['stok'] - (int)$m['jumlah']);
        $out[] = [
            'id' => (int)$m['id'], 'nama' => $m['nama'], 'kategori' => $m['kategori'],
            'harga' => (int)$m['harga'],
            'foto' => !empty($m['foto']) ? url('uploads/menu/' . rawurlencode($m['foto'])) : null,
            'tersedia' => $tersedia,
            'stok' => $m['stok'] === null ? null : (int)$m['stok'],
            'sisa_stok' => $sisa_stok,
            'bisa' => $tersedia && ($sisa_stok === null || $sisa_stok > 0),
        ];
    }
    json_out([
        'ok' => true, 'tanggal' => $tgl, 'tanggal_label' => tanggal_id($tgl),
        'boleh_pesan' => ($tgl >= $min),
        'kuota' => [
            'kapasitas' => KAPASITAS_HARIAN, 'terpakai' => $r['box'], 'sisa' => $r['sisa'],
            'persen' => $r['persen'], 'level' => $r['level'],
        ],
        'menu' => $out,
    ]);
}

// ---------- aksi=cek_kuota (GET) ----------
if ($aksi === 'cek_kuota') {
    $tgl = valid_tanggal($_GET['tanggal'] ?? null);
    if ($tgl === null) json_out(['ok' => false, 'pesan' => 'Tanggal tidak valid.'], 400);
    $r = ringkasan_tanggal($kon, $tgl);
    json_out(['ok' => true, 'kuota' => ['kapasitas' => KAPASITAS_HARIAN, 'terpakai' => $r['box'], 'sisa' => $r['sisa'], 'persen' => $r['persen'], 'level' => $r['level']]]);
}

// ---------- aksi=pesan (POST) ----------
if ($aksi === 'pesan') {
    hanya_post();
    csrf_cek(true); // JSON 403 bila token salah

    // Rate limit: maksimal 8 upaya pesan per 10 menit per sesi.
    if (!batasi_rate('pesan', 8, 600)) {
        json_out(['ok' => false, 'pesan' => 'Terlalu banyak percobaan. Tunggu beberapa saat lalu coba lagi.'], 429);
    }

    // Kunci idempoten: token dari klien; bila sama dengan yang terakhir sukses, kembalikan hasil lama.
    $idem = trim((string)($_POST['idempotency'] ?? ''));
    if ($idem !== '' && strlen($idem) <= 64) {
        mulai_sesi();
        $kunci = 'idem_' . $idem;
        if (!empty($_SESSION[$kunci])) {
            json_out($_SESSION[$kunci] + ['duplikat' => true]);
        }
    }

    // Susun items [menu_id => jumlah] dari JSON/form.
    $items = [];
    $items_raw = $_POST['items'] ?? null;
    if (is_string($items_raw)) {
        $decoded = json_decode($items_raw, true);
        if (is_array($decoded)) $items = $decoded;
    } elseif (is_array($items_raw)) {
        $items = $items_raw;
    }
    // Normalisasi: hanya id & jumlah positif.
    $item_bersih = [];
    foreach ($items as $mid => $jml) {
        $mid = (int)$mid; $jml = (int)$jml;
        if ($mid > 0 && $jml > 0 && $jml <= KAPASITAS_HARIAN) $item_bersih[$mid] = $jml;
    }

    $in = [
        'nama' => (string)($_POST['nama'] ?? ''),
        'no_wa' => (string)($_POST['no_wa'] ?? ''),
        'tanggal_ambil' => (string)($_POST['tanggal_ambil'] ?? ''),
        'jam_ambil' => (string)($_POST['jam_ambil'] ?? ''),
        'catatan' => (string)($_POST['catatan'] ?? ''),
        'items' => $item_bersih,
    ];

    // Buat pesanan dengan aturan pelanggan (H-1, jam operasional). Kuota/stok/harga divalidasi di sini.
    $hasil = buat_pesanan($kon, $in, true);
    if (!$hasil['ok']) {
        json_out(['ok' => false, 'pesan' => $hasil['pesan'], 'field' => $hasil['field'] ?? null, 'sisa' => $hasil['sisa'] ?? null], 422);
    }

    // Pesanan tersimpan -> buat transaksi QRIS dinamis HANYA bila gateway terkonfigurasi.
    // Bila hanya QRIS STATIS tersedia, jangan panggil gateway; customer diarahkan ke
    // halaman pembayaran untuk scan QRIS statis dan unggah bukti.
    $pesanan = db_row($kon, 'SELECT * FROM pesanan WHERE id = ?', [(int)$hasil['id']]);
    $bayar = pembayaran_terkonfigurasi() ? buat_transaksi_qris($kon, $pesanan) : ['ok' => false, 'terkonfigurasi' => false];
    $qris_statis = false;
    try { $qris_statis = qris_statis_tersedia($kon); } catch (Throwable $e) {}

    // Ambil token_nota untuk tautan nota (bila kolom ada).
    $token = $hasil['token_nota'] ?? '';
    $url_nota = url('nota.php?kode=' . rawurlencode($hasil['kode']) . ($token ? '&t=' . rawurlencode($token) : ''));
    $url_bayar = url('pembayaran.php?kode=' . rawurlencode($hasil['kode']) . ($token ? '&t=' . rawurlencode($token) : ''));

    $respons = [
        'ok' => true,
        'pesan' => 'Pesanan berhasil dibuat.',
        'kode' => $hasil['kode'],
        'token' => $token,
        'url_nota' => $url_nota,
        'url_bayar' => $url_bayar,
        'pembayaran' => [
            'terkonfigurasi' => ($bayar['terkonfigurasi'] ?? false) || $qris_statis,
            'dinamis' => $bayar['terkonfigurasi'] ?? false,
            'qris_statis' => $qris_statis,
            'ok' => $bayar['ok'] ?? false,
            'pesan' => $bayar['pesan'] ?? null,
        ],
    ];

    // Simpan idempoten agar klik ganda mengembalikan hasil yang sama.
    if ($idem !== '' && strlen($idem) <= 64) {
        mulai_sesi();
        $_SESSION['idem_' . $idem] = $respons;
    }
    json_out($respons);
}

json_out(['ok' => false, 'pesan' => 'Aksi tidak dikenal.'], 400);
