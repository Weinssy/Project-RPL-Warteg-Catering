// =====================================================================
// Uji DOM: memuat customer.js asli ke DOM hasil render halaman pelanggan,
// lalu mensimulasikan klik tombol "Pilih" dan memeriksa perubahan keranjang.
// Menjalankan: node tests/uji_klik_pilih.mjs   (butuh happy-dom terpasang global di $TEMP)
// =====================================================================
import { Window } from 'happy-dom';
import fs from 'fs';

const BASE = 'http://127.0.0.1:8765';
const JS_PATH = process.env.CUSTOMER_JS
    || new URL('../assets/js/customer.js', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1');

let gagal = 0;
function cek(nama, lolos, ket = '') {
    console.log((lolos ? '  [OK]   ' : '  [GAGAL] ') + nama + (ket ? ' :: ' + ket : ''));
    if (!lolos) gagal++;
}

// 1. Ambil HTML halaman asli dari server (supaya kartu = hasil render server).
const html = await (await fetch(BASE + '/index.php')).text();
const scriptJs = fs.readFileSync(JS_PATH, 'utf8');

// 2. Siapkan DOM.
const window = new Window({ url: BASE + '/index.php' });
const { document } = window;
document.write(html);

// Stub fetch (customer.js memakainya untuk AJAX) agar tidak memanggil jaringan di test klik.
window.fetch = async () => ({ json: async () => ({ ok: false, pesan: 'stub' }), ok: true });
window.confirm = () => true;

// 3. Definisikan window.WARTEG seperti yang dilakukan inline script di halaman asli.
//    (Nilai diambil dari meta supaya konsisten.)
const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
window.WARTEG = { base: '', minimal: 10, kapasitas: 120, jamBuka: '09:00', jamTutup: '19:00', csrf, konfigurasiBayar: false };

// 4. Jalankan customer.js asli (IIFE).
window.eval(scriptJs);

// 5. Verifikasi event listener terpasang: klik tombol Pilih pada kartu yang bisa dipilih.
const kartuBisa = [...document.querySelectorAll('.kartu-cust[data-menu-id]')]
    .filter(k => !k.classList.contains('habis') && k.querySelector('.pilih-menu'));

cek('Ada kartu menu yang bisa dipilih', kartuBisa.length > 0, `jumlah=${kartuBisa.length}`);

if (kartuBisa.length > 0) {
    const kartu = kartuBisa[0];
    const id = kartu.getAttribute('data-menu-id');
    const nama = kartu.getAttribute('data-nama');
    const btn = kartu.querySelector('.pilih-menu');

    // Klik pertama -> masuk keranjang
    btn.click();
    const ringkasan = document.querySelector('#ringkasanIsi');
    const totalBox = document.querySelector('#totalBox')?.textContent;
    cek('Klik Pilih: menu masuk keranjang (tidak kosong)', !/Keranjang masih kosong/.test(ringkasan.innerHTML || ''));
    cek('Klik Pilih: ada baris menu di ringkasan', (ringkasan.querySelectorAll('.baris-menu').length) >= 1);
    cek('Klik Pilih: total box = 1 (BUKAN 10)', String(totalBox) === '1', `totalBox=${totalBox}`);
    cek('Klik Pilih: kartu bertanda dipilih', kartu.classList.contains('dipilih'));
    cek('Klik Pilih: teks tombol berubah', /Batalkan|Dipilih/.test(btn.textContent), btn.textContent);

    // Verifikasi localStorage menyimpan keranjang (persist antar langkah).
    const simpan = window.localStorage.getItem('warteg_keranjang');
    cek('Keranjang tersimpan di localStorage', !!simpan && simpan.includes(id));

    // Tambah qty via stepper (data-tambah) hingga >= 10 lalu cek tombol Lanjut aktif.
    for (let i = 0; i < 9; i++) {
        const plus = document.querySelector(`[data-tambah="${id}"]`);
        if (!plus) break;
        plus.click();
    }
    const totalBox2 = document.querySelector('#totalBox')?.textContent;
    const btnLanjut = document.querySelector('#btnLanjut');
    cek('Stepper menambah box menjadi >= 10', parseInt(totalBox2, 10) >= 10, `totalBox=${totalBox2}`);
    cek('Tombol Lanjut aktif saat >= minimum', btnLanjut && btnLanjut.disabled === false);
    cek('Pesan minimum terisi', /\d+\s*box/i.test(document.querySelector('#pesanMin')?.textContent || ''), document.querySelector('#pesanMin')?.textContent);

    // Klik kedua pada tombol kartu -> batalkan pilihan menu ini saja.
    btn.click();
    cek('Klik Pilih lagi: menu dibatalkan (keranjang kosong kembali)', /Keranjang masih kosong/.test(document.querySelector('#ringkasanIsi').innerHTML || ''));
    cek('Klik Pilih lagi: kartu tidak bertanda dipilih', !kartu.classList.contains('dipilih'));
}

// 6. Uji tombol "Kosongkan" setelah pilih.
if (kartuBisa.length > 0) {
    kartuBisa[0].querySelector('.pilih-menu').click();
    const kosongBtn = document.querySelector('#kosongkanKeranjang');
    cek('Tombol Kosongkan tampil saat ada isi', kosongBtn && kosongBtn.hidden === false);
    kosongBtn.click();
    cek('Kosongkan: keranjang kembali kosong', /Keranjang masih kosong/.test(document.querySelector('#ringkasanIsi').innerHTML || ''));
}

console.log('\n' + (gagal === 0 ? 'SEMUA UJI DOM LULUS' : `ADA ${gagal} KEGAGALAN`));
process.exit(gagal === 0 ? 0 : 1);
