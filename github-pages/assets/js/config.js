// =====================================================================
// KONFIGURASI SUPABASE & APLIKASI WARTEG (GITHUB PAGES READY)
// =====================================================================
// Anda dapat mengisi SUPABASE_URL dan SUPABASE_ANON_KEY kapan saja
// dari Dashboard Supabase -> Project Settings -> API.
// 
// JIKA DIKOSONGKAN:
// Sistem otomatis beralih ke MODE FALLBACK (localStorage) dengan data demo bawaan,
// sehingga halaman di GitHub Pages tetap langsung bisa dicoba dan berfungsi 100%!
// =====================================================================

window.WARTEG_CONFIG = {
    // Kredensial Supabase Anda (Aman dipasang di frontend karena menggunakan Anon Key)
    SUPABASE_URL: "https://cqkzitjxqylznxdykmqt.supabase.co",      // Contoh: "https://your-project.supabase.co"
    SUPABASE_ANON_KEY: "sb_publishable_YJ4vgdO8paMOMmxDgnuQWA_rQ6uVi51", // Contoh: "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."

    // Pengaturan Operasional Warteg
    NAMA_WARTEG: "Warteg Pak Sanam",
    KAPASITAS_HARIAN: 120,      // Maksimum total box per tanggal pengambilan
    MINIMAL_BOX: 10,           // Batas minimum box katering per pemesanan
    STOK_MAKS: 120,            // Batas maksimal jatah stok per menu per hari

    // Aturan Promo & Diskon Box
    DISKON_PER_BOX: 50,        // Setiap kelipatan 50 box
    NOMINAL_DISKON: 50000,     // Mendapat voucher potongan Rp 50.000

    // Waktu & Ketentuan Pengambilan (Self-Pickup)
    JAM_BUKA: "09:00",
    JAM_TUTUP: "19:00",
    MINIMAL_H_MINUS: 1,        // Pemesanan paling cepat untuk H+1
    MAKS_HARI_KE: 30,          // Maksimal pemesanan hingga 30 hari ke depan
    LOKASI_AMBIL: "Warteg Pak Sanam (Self-Pickup)",
    ALAMAT_WARTEG: "Jalan BDN 1 Cilandak Barat Jakarta Selatan",
    TELEPON_WARTEG: "0857-8342-3626"
};

