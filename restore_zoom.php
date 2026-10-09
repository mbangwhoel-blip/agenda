<?php
$file = 'index.php';
$content = file_get_contents($file);

// We will locate the block between <!-- Pagination Kegiatan --> and aria-label="Cari agenda Zoom mendatang"
$startMarker = "<!-- Pagination Kegiatan -->";
$endMarker = "                               aria-label=\"Cari agenda Zoom mendatang\"";

$posStart = strpos($content, $startMarker);
$posEnd = strpos($content, $endMarker);

if ($posStart !== false && $posEnd !== false) {
    $replacement = <<<EOD
<!-- Pagination Kegiatan -->
            <?php
            \$kTotalPages = (int)ceil(\$kegiatanTotal / \$publicLimit);
            if (\$kTotalPages > 1):
                \$kPrevPage = \$kegiatanPage > 1 ? \$kegiatanPage - 1 : null;
                \$kNextPage = \$kegiatanPage < \$kTotalPages ? \$kegiatanPage + 1 : null;
                \$kBase = array_filter(['kq' => \$kegiatanQ ?: null, 'zq' => \$zoomQ ?: null, 'zp' => \$zoomPage > 1 ? \$zoomPage : null]);
            ?>
            <div class="mt-4 flex items-center justify-between text-sm">
                <p class="text-slate-500 text-xs">Halaman <?= \$kegiatanPage ?> dari <?= \$kTotalPages ?> &middot; <span class="font-medium"><?= \$kegiatanTotal ?> kegiatan</span></p>
                <div class="flex flex-wrap gap-1.5">
                    <?php if (\$kPrevPage): ?>
                    <a href="?<?= http_build_query(array_merge(\$kBase, ['kp' => \$kPrevPage])) ?>#agenda-kegiatan"
                       aria-label="Halaman sebelumnya"
                       class="flex items-center justify-center w-8 h-8 rounded-lg border border-slate-300 dark:border-white/20 bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-white/10 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m15 18-6-6 6-6"/></svg>
                    </a>
                    <?php endif; ?>
                    
                    <?php for (\$i = max(1, \$kegiatanPage - 2); \$i <= min(\$kTotalPages, \$kegiatanPage + 2); \$i++): ?>
                    <a href="?<?= http_build_query(array_merge(\$kBase, ['kp' => \$i])) ?>#agenda-kegiatan"
                       class="flex items-center justify-center w-8 h-8 rounded-lg border <?= \$i === \$kegiatanPage ? 'border-teal-500 bg-teal-50 text-teal-700 font-bold dark:bg-teal-500/20 dark:text-teal-300' : 'border-slate-300 bg-white dark:bg-slate-800 dark:border-white/20 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-white/10' ?> transition-colors text-sm">
                        <?= \$i ?>
                    </a>
                    <?php endfor; ?>

                    <?php if (\$kNextPage): ?>
                    <a href="?<?= http_build_query(array_merge(\$kBase, ['kp' => \$kNextPage])) ?>#agenda-kegiatan"
                       aria-label="Halaman selanjutnya"
                       class="flex items-center justify-center w-8 h-8 rounded-lg border border-slate-300 dark:border-white/20 bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-white/10 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            </div>
        </section>

        <!-- Bagian Kiri (Lanjutan): Agenda Zoom -->
        <section id="agenda-zoom" aria-labelledby="heading-zoom" class="pt-8 border-t border-slate-200 dark:border-slate-800">
            <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-rose-100 dark:bg-rose-900/30 text-rose-600 dark:text-rose-400">
                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z"/>
                        </svg>
                    </div>
                    <h2 id="heading-zoom" class="text-xl font-extrabold text-slate-900 dark:text-white tracking-tight">Agenda Zoom</h2>
                </div>
                <form action="index.php" method="GET" class="flex items-center gap-2 w-full sm:w-auto">
                    <input type="hidden" name="kq" value="<?= e(\$kegiatanQ) ?>">
                    <?php if (\$kegiatanPage > 1): ?>
                    <input type="hidden" name="kp" value="<?= \$kegiatanPage ?>">
                    <?php endif; ?>
                    <div class="relative flex-1 sm:w-64">
                        <input type="text" name="zq" value="<?= e(\$zoomQ) ?>" placeholder="Cari..."
EOD;
    $newContent = substr($content, 0, $posStart) . $replacement . "\n" . substr($content, $posEnd);
    file_put_contents($file, $newContent);
    echo "Restored Agenda Zoom layout successfully!\n";
} else {
    echo "Could not find markers!\n";
}
