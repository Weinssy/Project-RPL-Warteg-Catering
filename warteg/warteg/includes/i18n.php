<?php
// =====================================================================
// Terjemahan antarmuka ringan (Indonesia default, Inggris opsional).
// Cakupan: navigasi sidebar, header/topbar, dan halaman Profil Admin.
// Halaman fitur lain (dashboard, pesanan, menu) tetap berbahasa Indonesia.
//
// Cara pakai:
//   t('nav.dashboard')             -> label sesuai bahasa aktif
//   t('nav.dashboard', 'en')       -> paksa bahasa tertentu
//
// Semua teks HARUS lewat fungsi ini agar pilihan bahasa benar-benar mengubah
// antarmuka yang didukung, bukan hanya label dropdown.
// =====================================================================

function daftar_terjemahan(): array {
    static $kamus = null;
    if ($kamus !== null) return $kamus;
    $kamus = [
        'id' => [
            'nav.dashboard'      => 'Dashboard',
            'nav.rekap'          => 'Rekap Harian',
            'nav.pemesan'        => 'Daftar Pemesan',
            'nav.verifikasi'     => 'Verifikasi Pembayaran',
            'nav.menu'           => 'Menu Harian',
            'nav.tambah'         => 'Tambah Menu',
            'nav.qris'           => 'Pengaturan QRIS',
            'nav.admin'          => 'Manajemen Admin',
            'nav.keluar'         => 'Keluar',
            'nav.buka_menu'      => 'Buka menu',
            'topbar.profil'      => 'Buka profil saya',
            'topbar.foto'        => 'Perbesar foto profil',
            'common.simpan'      => 'Simpan',
            'common.batal'       => 'Batal',
            'common.kembali'     => 'Kembali ke Dashboard',
            'common.tutup'       => 'Tutup',
            'common.tutup_foto'  => 'Tutup pratinjau foto',
            'common.belum_diisi' => 'Belum diisi',
            'common.opsional'    => 'opsional',
            'common.ya'          => 'Ya',
            'common.tidak'       => 'Tidak',
            'profil.judul'       => 'Profil Saya',
            'profil.subjudul'    => 'Kelola informasi pribadi, kontak, kredensial, dan preferensi akun Anda.',
            'profil.akun'        => 'Akun aktif',
            'profil.owner'       => 'Owner',
            'profil.admin'       => 'Admin',
            'profil.pribadi'     => 'Informasi Pribadi',
            'profil.kontak'      => 'Informasi Kontak',
            'profil.kredensial'  => 'Kredensial Akun',
            'profil.preferensi'  => 'Preferensi dan Aktivitas',
            'profil.nama'        => 'Nama lengkap',
            'profil.tgl_lahir'   => 'Tanggal lahir',
            'profil.jk'          => 'Jenis kelamin',
            'profil.jk_l'        => 'Laki-laki',
            'profil.jk_p'        => 'Perempuan',
            'profil.foto'        => 'Foto profil',
            'profil.ganti_foto'  => 'Ganti foto profil',
            'profil.email'       => 'Email',
            'profil.telepon'     => 'Nomor telepon',
            'profil.alamat'      => 'Alamat domisili',
            'profil.username'    => 'Username',
            'profil.role'        => 'Peran sistem (role)',
            'profil.sandi_lama'  => 'Kata sandi lama',
            'profil.sandi_baru'  => 'Kata sandi baru',
            'profil.sandi_konf'  => 'Konfirmasi kata sandi baru',
            'profil.ubah_sandi'  => 'Ubah kata sandi',
            'profil.bahasa'      => 'Bahasa antarmuka',
            'profil.notif'       => 'Preferensi notifikasi',
            'profil.notif_pesan' => 'Notifikasi pesanan baru',
            'profil.notif_stok'  => 'Notifikasi stok menu menipis',
            'profil.aktivitas'   => 'Riwayat aktivitas',
            'profil.aktivitas_kosong' => 'Belum ada aktivitas tercatat untuk akun ini.',
            'profil.waktu'       => 'Waktu',
            'profil.jenis'       => 'Aktivitas',
            'profil.info'        => 'Keterangan',
        ],
        'en' => [
            'nav.dashboard'      => 'Dashboard',
            'nav.rekap'          => 'Daily Recap',
            'nav.pemesan'        => 'Customer List',
            'nav.verifikasi'     => 'Payment Verification',
            'nav.menu'           => 'Daily Menu',
            'nav.tambah'         => 'Add Menu',
            'nav.qris'           => 'QRIS Settings',
            'nav.admin'          => 'Admin Management',
            'nav.keluar'         => 'Sign out',
            'nav.buka_menu'      => 'Open menu',
            'topbar.profil'      => 'Open my profile',
            'topbar.foto'        => 'Enlarge profile photo',
            'common.simpan'      => 'Save',
            'common.batal'       => 'Cancel',
            'common.kembali'     => 'Back to Dashboard',
            'common.tutup'       => 'Close',
            'common.tutup_foto'  => 'Close photo preview',
            'common.belum_diisi' => 'Not set',
            'common.opsional'    => 'optional',
            'common.ya'          => 'Yes',
            'common.tidak'       => 'No',
            'profil.judul'       => 'My Profile',
            'profil.subjudul'    => 'Manage your personal information, contact details, credentials, and preferences.',
            'profil.akun'        => 'Active account',
            'profil.owner'       => 'Owner',
            'profil.admin'       => 'Admin',
            'profil.pribadi'     => 'Personal Information',
            'profil.kontak'      => 'Contact Information',
            'profil.kredensial'  => 'Account Credentials',
            'profil.preferensi'  => 'Preferences and Activity',
            'profil.nama'        => 'Full name',
            'profil.tgl_lahir'   => 'Date of birth',
            'profil.jk'          => 'Gender',
            'profil.jk_l'        => 'Male',
            'profil.jk_p'        => 'Female',
            'profil.foto'        => 'Profile photo',
            'profil.ganti_foto'  => 'Change profile photo',
            'profil.email'       => 'Email',
            'profil.telepon'     => 'Phone number',
            'profil.alamat'      => 'Home address',
            'profil.username'    => 'Username',
            'profil.role'        => 'System role',
            'profil.sandi_lama'  => 'Current password',
            'profil.sandi_baru'  => 'New password',
            'profil.sandi_konf'  => 'Confirm new password',
            'profil.ubah_sandi'  => 'Change password',
            'profil.bahasa'      => 'Interface language',
            'profil.notif'       => 'Notification preferences',
            'profil.notif_pesan' => 'New order notifications',
            'profil.notif_stok'  => 'Low menu stock notifications',
            'profil.aktivitas'   => 'Activity history',
            'profil.aktivitas_kosong' => 'No activity recorded for this account yet.',
            'profil.waktu'       => 'Time',
            'profil.jenis'       => 'Activity',
            'profil.info'        => 'Details',
        ],
    ];
    return $kamus;
}

function t(string $kunci, ?string $bahasa = null): string {
    $bahasa = $bahasa ?? bahasa_aktif();
    $kamus  = daftar_terjemahan();
    return $kamus[$bahasa][$kunci] ?? ($kamus['id'][$kunci] ?? $kunci);
}

// Nama peran yang layak tampil (Owner / Admin) sesuai bahasa aktif.
function label_role(string $role, ?string $bahasa = null): string {
    return t($role === 'owner' ? 'profil.owner' : 'profil.admin', $bahasa);
}
