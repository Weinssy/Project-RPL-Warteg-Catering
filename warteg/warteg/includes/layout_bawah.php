</div>
<?php // Lightbox foto profil: dipakai bersama oleh header & halaman profil. ?>
<div class="lightbox" id="lightboxFoto" hidden role="dialog" aria-modal="true" aria-label="<?= e(t('common.tutup_foto')) ?>">
    <button type="button" class="lightbox-tutup" id="lightboxTutup" aria-label="<?= e(t('common.tutup_foto')) ?>" title="<?= e(t('common.tutup')) ?>">&times;</button>
    <img src="" alt="" class="lightbox-img" id="lightboxGambar">
</div>
<div class="toast-area" id="toastArea" aria-live="polite"></div>
<script src="<?= e(url('assets/js/admin.js')) ?>"></script>
<?php if (!empty($skrip_halaman)): ?><script><?= $skrip_halaman ?></script><?php endif; ?>
</body>
</html>
