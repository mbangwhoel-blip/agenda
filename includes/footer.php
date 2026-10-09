    </main>
    <?php
    $footerInstansi = function_exists('getAppSetting')
        ? getAppSetting('instansi_nama', 'BBLKL Salatiga — Kementerian Kesehatan RI')
        : 'BBLKL Salatiga — Kementerian Kesehatan RI';
    ?>
    <footer class="border-t border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 mt-auto shrink-0">
        <div class="mx-auto max-w-7xl px-4 py-4 sm:px-6 lg:px-8">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1.5">
                <div class="text-xs font-semibold text-slate-500 dark:text-slate-400">
                    <span class="font-bold text-teal-700 dark:text-teal-400">Agenda Pimpinan v2</span>
                    <span class="mx-1.5 text-slate-300 dark:text-slate-600">&middot;</span>
                    <span><?= e($footerInstansi); ?></span>
                </div>
                <div class="text-xs text-slate-400 dark:text-slate-500">
                    &copy; <?= date('Y'); ?> Kementerian Kesehatan RI &mdash; v2.0
                </div>
            </div>
        </div>
    </footer>
    <script>
        // Konfirmasi hapus terpadu (SweetAlert2) untuk semua elemen [data-confirm-delete].
        // Atribut opsional: data-confirm-title, data-confirm-text, data-confirm-confirm-text, data-confirm-cancel-text.
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('[data-confirm-delete]').forEach(function (el) {
                el.addEventListener('click', function (event) {
                    event.preventDefault();
                    var title = el.getAttribute('data-confirm-title') || 'Yakin menghapus?';
                    var text = el.getAttribute('data-confirm-text') || 'Data yang sudah dihapus tidak bisa dikembalikan.';
                    var confirmText = el.getAttribute('data-confirm-confirm-text') || 'Ya, hapus';
                    var cancelText = el.getAttribute('data-confirm-cancel-text') || 'Batal';
                    var href = el.getAttribute('href');
                    var form = el.closest('form');

                    if (typeof Swal === 'undefined') {
                        if (confirm(title + '\n' + text)) {
                            if (href) { window.location.href = href; }
                            else if (form) { form.submit(); }
                        }
                        return;
                    }

                    Swal.fire({
                        title: title,
                        text: text,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: confirmText,
                        cancelButtonText: cancelText,
                        confirmButtonColor: '#e11d48',
                        cancelButtonColor: '#64748b',
                        reverseButtons: true,
                        focusCancel: true
                    }).then(function (result) {
                        if (result.isConfirmed) {
                            if (href) { window.location.href = href; }
                            else if (form) { form.submit(); }
                        }
                    });
                });
            });

            // Hapus pola lama: inline onsubmit="return confirm(...)" — biarkanSweetAlert2 ambil alih.
            document.querySelectorAll('form[onsubmit*="confirm"]').forEach(function (form) {
                form.removeAttribute('onsubmit');
                if (!form.hasAttribute('data-confirm-delete')) {
                    form.setAttribute('data-confirm-delete', '');
                }
            });
        });
    </script>
    <?php
    $footerUser = currentUser();
    $footerPage = basename($_SERVER['PHP_SELF'] ?? '');
    $enableBgAiSync = $footerUser && (isAdmin() || isSekretaris()) && $footerPage !== 'agenda.php';
    ?>
    <?php if ($enableBgAiSync): ?>
        <script>
            (function () {
                var hasPending = <?= !empty($_SESSION['ai_pending_letter_ids']) ? 'true' : 'false' ?>;

                // Throttle: tanpa antrian pending, jalankan maksimal 1x per 5 menit
                // per sesi browser agar navigasi antar halaman tidak memicu job berulang.
                if (!hasPending) {
                    try {
                        var lastRun = parseInt(localStorage.getItem('aiBgSyncLastRun') || '0', 10);
                        if (Date.now() - lastRun < 300000) { return; }
                    } catch (e) { /* localStorage diblokir — lanjut tanpa throttle */ }
                }
                try { localStorage.setItem('aiBgSyncLastRun', String(Date.now())); } catch (e) {}

                <?php if (!empty($_SESSION['ai_pending_letter_ids'])): ?>
                    const ToastLoading = Swal.mixin({
                        toast: true,
                        position: 'bottom-end',
                        showConfirmButton: false,
                        customClass: {
                            popup: 'rounded-xl shadow-lg border border-indigo-200 dark:border-indigo-900 bg-indigo-50 dark:bg-slate-800 text-indigo-900 dark:text-indigo-100',
                            title: 'text-sm font-semibold'
                        }
                    });
                    
                    ToastLoading.fire({
                        title: '<div class="flex items-center gap-2"><svg class="animate-spin h-4 w-4 text-indigo-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> AI sedang membaca surat...</div>',
                        timer: 5000,
                        timerProgressBar: true
                    });
                <?php endif; ?>

                fetch('ai_process_background.php', {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                })
                .catch(function () {});
            })();
        </script>
    <?php endif; ?>

    <?php if (isset($flash) && is_array($flash)): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const Toast = Swal.mixin({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 3000,
                    timerProgressBar: true,
                    customClass: {
                        popup: 'rounded-xl shadow-lg border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100',
                        title: 'text-sm font-semibold'
                    },
                    didOpen: (toast) => {
                        toast.onmouseenter = Swal.stopTimer;
                        toast.onmouseleave = Swal.resumeTimer;
                    }
                });
                Toast.fire({
                    icon: '<?= $flash['type'] === 'success' ? 'success' : 'error' ?>',
                    title: '<?= e($flash['message']) ?>'
                });
            });
        </script>
    <?php endif; ?>



    <!-- Back to Top Button -->
    <button id="backToTopBtn" 
            onclick="window.scrollTo({top: 0, behavior: 'smooth'})"
            style="position: fixed; bottom: 5rem; right: 1.5rem; z-index: 50;"
            class="flex items-center justify-center w-11 h-11 rounded-full bg-cyan-600 hover:bg-cyan-500 text-white shadow-lg shadow-cyan-600/30 transition-all duration-300 opacity-0 translate-y-10 pointer-events-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-400"
            aria-label="Kembali ke atas"
            title="Kembali ke atas">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" />
        </svg>
    </button>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const bttBtn = document.getElementById('backToTopBtn');
            if (bttBtn) {
                window.addEventListener('scroll', function() {
                    if (window.scrollY > 300) {
                        bttBtn.classList.remove('opacity-0', 'translate-y-10', 'pointer-events-none');
                        bttBtn.classList.add('opacity-100', 'translate-y-0');
                    } else {
                        bttBtn.classList.add('opacity-0', 'translate-y-10', 'pointer-events-none');
                        bttBtn.classList.remove('opacity-100', 'translate-y-0');
                    }
                }, { passive: true });
            }
        });
    </script>

    </div>
    <script>
        // Register service worker (PWA)
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('/sw.js').catch(function (e) {
                    console.warn('SW registration failed:', e);
                });
            });
        }
    </script>
</body>
</html>
