<?php

declare(strict_types=1);

$currentPage = basename($_SERVER['PHP_SELF']);
$pageTitle = $pageTitle ?? 'Agenda Pimpinan';
$user = currentUser();

// Versi aset stabil (bukan time() yang merusak cache setiap request).
$assetVer = '2.2.1';
$cssV = $assetVer;
$uiV = $assetVer;

$notifications = [];
$unreadNotifs = 0;
$disposisiBadge = 0;
if ($user) {
    try {
        $pdo = getPdo();
        $notifStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 10");
        $notifStmt->execute(['user_id' => $user['id']]);
        $notifications = $notifStmt->fetchAll();
        foreach ($notifications as $n) {
            if ((int)$n['is_read'] === 0) {
                $unreadNotifs++;
            }
        }
        // Badge pending disposisi — kolom disposition_status NOT NULL DEFAULT 'pending',
        // cukup equality sargable (index idx_agendas_entry_disp) tanpa OR IS NULL.
        if (isAdmin() || isSekretaris() || isPimpinan()) {
            $badgeStmt = $pdo->query("SELECT COUNT(*) FROM agendas WHERE entry_type = 'surat' AND disposition_status = 'pending'");
            $disposisiBadge = (int) $badgeStmt->fetchColumn();

            if (isSekretaris() && $disposisiBadge > 0) {
                array_unshift($notifications, [
                    'id' => -1,
                    'is_read' => 0,
                    'title' => 'Disposisi Menunggu Pimpinan',
                    'message' => "Terdapat {$disposisiBadge} surat yang belum didisposisikan oleh pimpinan. Mohon ingatkan pimpinan.",
                    'link' => 'dashboard.php?tab=disposisi',
                    'created_at' => date('Y-m-d H:i:s')
                ]);
                $unreadNotifs++;
            }
        } elseif (isPegawai()) {
            $empId = (int) ($user['employee_id'] ?? 0);
            if ($empId > 0) {
                $badgeStmt = $pdo->prepare("SELECT COUNT(*) FROM agenda_disposition_items WHERE read_status = 'belum' AND employee_id = :emp_id");
                $badgeStmt->execute(['emp_id' => $empId]);
                $disposisiBadge = (int) $badgeStmt->fetchColumn();
            }
        }
    } catch (Throwable $e) {}
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle); ?></title>
    <link rel="icon" href="assets/logo.png?v=<?= $assetVer ?>" type="image/png" sizes="32x32">
    <link rel="manifest" href="manifest.json?v=<?= $assetVer ?>">
    <meta name="theme-color" content="#0f766e">
    <link rel="apple-touch-icon" href="assets/logo.png?v=<?= $assetVer ?>">
    <link rel="stylesheet" href="assets/css/kemenkes.css?v=<?= $cssV ?>">
    <script>
        if (localStorage.getItem('darkMode') === 'true' || (!('darkMode' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        }
    </script>
    <link rel="preload" href="assets/fonts/plus-jakarta-sans-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="assets/fonts/fonts.css?v=<?= $assetVer ?>">
    <link rel="stylesheet" href="assets/ui.css?v=<?= $uiV ?>">
    <link rel="stylesheet" href="assets/css/enterprise-theme.css?v=1.0.0">
    <style>[x-cloak]{display:none !important;}</style>
    <script defer src="assets/js/alpine.min.js"></script>
    <script defer src="assets/js/sweetalert2.all.min.js"></script>

    <?php
    // Analytics (Plausible self-hosted) — aktifkan via env PLAUSIBLE_DOMAIN + PLAUSIBLE_SCRIPT_URL.
    $plausibleDomain = env('PLAUSIBLE_DOMAIN', '');
    $plausibleScript = env('PLAUSIBLE_SCRIPT_URL', 'https://plausible.io/js/script.js');
    if ($plausibleDomain !== ''):
    ?>
    <script defer data-domain="<?= e($plausibleDomain) ?>" src="<?= e($plausibleScript) ?>"></script>
    <?php endif; ?>
</head>
<body class="app-shell bg-slate-50 dark:bg-slate-900 text-slate-700 dark:text-slate-300 min-h-screen flex" x-data="{ mobileMenuOpen: false, topbar: { notif: false, profile: false, search: false } }" @keydown.escape.window="topbar.notif = false; topbar.profile = false; topbar.search = false; mobileMenuOpen = false">

    <!-- Skip link untuk aksesibilitas keyboard/screen reader -->
    <a href="#main-content" class="skip-link">Lewati ke konten utama</a>

    <!-- Sidebar Desktop -->
    <aside class="hidden w-64 flex-col border-r border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 xl:flex shrink-0 h-screen sticky top-0 overflow-y-auto">
        <div class="px-6 pb-4 pt-6 text-center border-b border-slate-100 dark:border-slate-700 flex flex-col items-center">
            <img src="assets/logo.png" alt="Logo E-Office Enterprise" width="48" height="48" class="h-12 w-auto object-contain mb-3">
            <span class="text-xl font-bold tracking-tight text-teal-700 dark:text-teal-400 leading-tight">E-OFFICE ENTERPRISE</span>
        </div>
        
        <nav class="flex-1 px-4 pt-3 pb-6 space-y-1 text-sm" aria-label="Navigasi utama">
            <div class="px-3 mb-2 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">MENU UTAMA</div>
            <a href="dashboard.php" class="flex items-center gap-3 rounded-xl px-3 py-2.5 transition-all group <?= $currentPage === 'dashboard.php' ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400 font-bold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-700/50 dark:hover:text-slate-200 font-medium'; ?>">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 transition-transform group-hover:translate-x-0.5 <?= $currentPage === 'dashboard.php' ? 'fill-current' : ''; ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                Dashboard
            </a>
            
            <?php if (!isPegawai() && !isArsiparis()): ?>
                <div class="px-3 mt-6 mb-2 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">AGENDA & SURAT</div>
                <a href="agenda.php" class="flex items-center gap-3 rounded-xl px-3 py-2.5 transition-all group <?= $currentPage === 'agenda.php' ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400 font-bold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-700/50 dark:hover:text-slate-200 font-medium'; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 transition-transform group-hover:translate-x-0.5 <?= $currentPage === 'agenda.php' ? 'fill-current' : ''; ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                    Agenda Surat
                </a>
                <a href="agenda_kegiatan.php" class="flex items-center gap-3 rounded-xl px-3 py-2.5 transition-all group <?= $currentPage === 'agenda_kegiatan.php' ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400 font-bold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-700/50 dark:hover:text-slate-200 font-medium'; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 transition-transform group-hover:translate-x-0.5 <?= $currentPage === 'agenda_kegiatan.php' ? 'fill-current' : ''; ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                    Agenda Kegiatan
                </a>
                <a href="agenda_zoom.php" class="flex items-center gap-3 rounded-xl px-3 py-2.5 transition-all group <?= $currentPage === 'agenda_zoom.php' ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400 font-bold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-700/50 dark:hover:text-slate-200 font-medium'; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 transition-transform group-hover:translate-x-0.5 <?= $currentPage === 'agenda_zoom.php' ? 'fill-current' : ''; ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polygon points="23 7 16 12 23 17 23 7"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg>
                    Agenda Zoom
                </a>
                <a href="calendar.php" class="flex items-center gap-3 rounded-xl px-3 py-2.5 transition-all group <?= $currentPage === 'calendar.php' ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400 font-bold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-700/50 dark:hover:text-slate-200 font-medium'; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 transition-transform group-hover:translate-x-0.5 <?= $currentPage === 'calendar.php' ? 'fill-current' : ''; ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line><line x1="8" y1="14" x2="8" y2="14"></line><line x1="12" y1="14" x2="12" y2="14"></line><line x1="16" y1="14" x2="16" y2="14"></line></svg>
                    Kalender
                </a>
                <a href="export_laporan.php" class="flex items-center gap-3 rounded-xl px-3 py-2.5 transition-all group <?= $currentPage === 'export_laporan.php' ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400 font-bold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-700/50 dark:hover:text-slate-200 font-medium'; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 transition-transform group-hover:translate-x-0.5 <?= $currentPage === 'export_laporan.php' ? 'fill-current' : ''; ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    Export Laporan
                </a>
            <?php endif; ?>

            <?php if (isArsiparis()): ?>
                <div class="px-3 mt-6 mb-2 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">ARSIP SURAT</div>
                <a href="agenda.php" class="flex items-center gap-3 rounded-xl px-3 py-2.5 transition-all group <?= $currentPage === 'agenda.php' ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400 font-bold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-700/50 dark:hover:text-slate-200 font-medium'; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                    Arsip Surat
                </a>
            <?php endif; ?>
            
            <?php if (isAdmin() || isPimpinan()): ?>
                <div class="px-3 mt-6 mb-2 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">DISPOSISI</div>
                <a href="disposisi.php" class="flex items-center gap-3 rounded-xl px-3 py-2.5 transition-all group <?= in_array($currentPage, ['disposisi.php', 'disposisi_form.php'], true) ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400 font-bold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-700/50 dark:hover:text-slate-200 font-medium'; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 transition-transform group-hover:translate-x-0.5 <?= in_array($currentPage, ['disposisi.php', 'disposisi_form.php'], true) ? 'fill-current' : ''; ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    Disposisi
                    <?php if ($disposisiBadge > 0): ?>
                        <span class="ml-auto flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold text-white" aria-label="<?= $disposisiBadge ?> surat menunggu disposisi"><?= $disposisiBadge > 99 ? '99+' : $disposisiBadge; ?></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
            
            <?php if (isPegawai()): ?>
                <div class="px-3 mt-6 mb-2 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">DISPOSISI</div>
                <a href="disposisi_saya.php" class="flex items-center gap-3 rounded-xl px-3 py-2.5 transition-all group <?= in_array($currentPage, ['disposisi_saya.php'], true) ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400 font-bold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-700/50 dark:hover:text-slate-200 font-medium'; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 transition-transform group-hover:translate-x-0.5 <?= in_array($currentPage, ['disposisi_saya.php'], true) ? 'fill-current' : ''; ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    Disposisi Saya
                    <?php if ($disposisiBadge > 0): ?>
                        <span class="ml-auto flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold text-white" aria-label="<?= $disposisiBadge ?> disposisi belum dibaca"><?= $disposisiBadge > 99 ? '99+' : $disposisiBadge; ?></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
            
            <?php if (isAdmin() || isSekretaris()): ?>
                <div class="px-3 mt-6 mb-2 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">ADMINISTRASI</div>
                <?php if (isAdmin()): ?>
                <a href="master_pegawai.php" class="flex items-center gap-3 rounded-xl px-3 py-2.5 transition-all group <?= in_array($currentPage, ['master_pegawai.php', 'master_pegawai_form.php'], true) ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400 font-bold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-700/50 dark:hover:text-slate-200 font-medium'; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 transition-transform group-hover:translate-x-0.5 <?= in_array($currentPage, ['master_pegawai.php', 'master_pegawai_form.php'], true) ? 'fill-current' : ''; ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                    Master Pegawai
                </a>
                <?php endif; ?>
                
                <a href="backup.php" class="flex items-center gap-3 rounded-xl px-3 py-2.5 transition-all group <?= $currentPage === 'backup.php' ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400 font-bold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-700/50 dark:hover:text-slate-200 font-medium'; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 transition-transform group-hover:translate-x-0.5 <?= $currentPage === 'backup.php' ? 'fill-current' : ''; ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    Backup Database
                </a>
                <a href="onboarding.php" class="flex items-center gap-3 rounded-xl px-3 py-2.5 transition-all group <?= $currentPage === 'onboarding.php' ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400 font-bold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-700/50 dark:hover:text-slate-200 font-medium'; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 transition-transform group-hover:translate-x-0.5 <?= $currentPage === 'onboarding.php' ? 'fill-current' : ''; ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    Panduan
                </a>
            <?php endif; ?>

            <?php if (isAdmin()): ?>
                <div class="px-3 mt-6 mb-2 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">SISTEM</div>
                <a href="activity_logs.php" class="flex items-center gap-3 rounded-xl px-3 py-2.5 transition-all group <?= $currentPage === 'activity_logs.php' ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400 font-bold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-700/50 dark:hover:text-slate-200 font-medium'; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 transition-transform group-hover:translate-x-0.5 <?= $currentPage === 'activity_logs.php' ? 'fill-current' : ''; ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                    Log Aktivitas
                </a>
                <a href="user_manager.php" class="flex items-center gap-3 rounded-xl px-3 py-2.5 transition-all group <?= in_array($currentPage, ['user_manager.php'], true) ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400 font-bold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-700/50 dark:hover:text-slate-200 font-medium'; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 transition-transform group-hover:translate-x-0.5 <?= in_array($currentPage, ['user_manager.php'], true) ? 'fill-current' : ''; ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><line x1="19" y1="8" x2="19" y2="14"></line><line x1="22" y1="11" x2="16" y2="11"></line></svg>
                    Kelola Pengguna
                </a>
                <a href="settings.php" class="flex items-center gap-3 rounded-xl px-3 py-2.5 transition-all group <?= $currentPage === 'settings.php' ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400 font-bold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-700/50 dark:hover:text-slate-200 font-medium'; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 transition-transform group-hover:translate-x-0.5 <?= $currentPage === 'settings.php' ? 'fill-current' : ''; ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                    Pengaturan
                </a>
                <a href="setup_2fa.php" class="flex items-center gap-3 rounded-xl px-3 py-2.5 transition-all group <?= $currentPage === 'setup_2fa.php' ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400 font-bold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-700/50 dark:hover:text-slate-200 font-medium'; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 transition-transform group-hover:translate-x-0.5 <?= $currentPage === 'setup_2fa.php' ? 'fill-current' : ''; ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    Autentikasi 2FA
                </a>
            <?php endif; ?>
        </nav>
    </aside>

    <!-- Main Wrapper -->
    <div class="flex-1 flex flex-col min-w-0 max-w-full">
        <!-- Topbar -->
        <header class="sticky top-0 z-50 border-b border-slate-200/80 dark:border-white/10 bg-white/80 dark:bg-slate-900/80 backdrop-blur-md">
            <div class="flex items-center justify-between px-3 sm:px-4 py-3 gap-2 sm:gap-4 xl:gap-8">
                <a href="index.php" class="flex shrink items-center gap-2 sm:gap-3 xl:hidden min-w-0">
                    <img src="assets/logo.png" alt="Logo E-Office Enterprise" width="40" height="40" class="h-8 sm:h-10 w-auto max-w-[100px] sm:max-w-[150px] object-contain shrink-0">
                    <div class="flex flex-col min-w-0">
                        <span class="text-sm sm:text-xl font-bold tracking-tight text-teal-700 leading-tight truncate">E-OFFICE ENTERPRISE</span>
                    </div>
                </a>

                <div class="hidden xl:flex items-center gap-4 max-w-lg">
                    <div class="flex flex-col truncate">
                        <p class="text-lg font-extrabold text-slate-900 dark:text-slate-100 leading-tight tracking-tight truncate">Balai Besar Laboratorium Kesehatan Lingkungan</p>
                        <p class="text-sm font-bold text-slate-700 dark:text-slate-300 leading-snug mt-0.5 truncate">Direktorat Jenderal Kesehatan Primer dan Komunitas</p>
                        <div class="flex items-center gap-2 mt-0.5 text-xs font-semibold text-slate-500 dark:text-slate-400">
                            <span>Kementerian Kesehatan Republik Indonesia</span>
                            <span aria-hidden="true">&bull;</span>
                            <span><?= date('d') . ' ' . ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'][(int)date('n') - 1] . ' ' . date('Y') ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="flex items-center gap-2 sm:gap-4 shrink min-w-0">
                    <?php if (!in_array($currentPage, ['agenda.php', 'agenda_kegiatan.php', 'agenda_zoom.php'], true)): ?>
                    <!-- Global Search (responsif: muncul di semua ukuran, full-width drawer di mobile) -->
                    <div x-data="{
                        query: '',
                        results: { surat: [], pegawai: [] },
                        show: false,
                        loading: false,
                        timer: null,
                        doSearch() {
                            clearTimeout(this.timer);
                            if (this.query.length < 3) { this.show = false; return; }
                            this.loading = true;
                            this.timer = setTimeout(() => {
                                fetch('search.php?q=' + encodeURIComponent(this.query), {credentials:'same-origin'})
                                    .then(r => r.json())
                                    .then(data => {
                                        this.results = data;
                                        this.show = (data.surat.length > 0 || data.pegawai.length > 0);
                                        this.loading = false;
                                    })
                                    .catch(() => { this.loading = false; });
                            }, 500);
                        },
                        close() { this.show = false; this.query = ''; }
                    }" class="relative" @keydown.escape.window="close()" @click.outside="show = false">
                        <div class="relative">
                            <svg xmlns="http://www.w3.org/2000/svg" class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                            <label for="global-search" class="sr-only">Cari surat, kegiatan, atau pegawai</label>
                            <input id="global-search" type="text"
                                   x-model="query"
                                   @input="doSearch()"
                                   @focus="show = query.length >= 3"
                                   placeholder="Cari surat, kegiatan, pegawai…"
                                   autocomplete="off"
                                   style="padding-left: 2.5rem;"
                                   class="w-44 sm:w-56 xl:w-64 h-11 rounded-full border border-slate-200 bg-slate-50 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 pr-3 text-sm focus:border-teal-500 focus:bg-white dark:focus:bg-slate-600 focus:outline-none focus:ring-2 focus:ring-teal-100">
                            <?php if (false): ?><span class="absolute right-3 top-1/2 -translate-y-1/2" x-show="loading" x-cloak><svg class="animate-spin h-4 w-4 text-teal-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg></span><?php endif; ?>
                        </div>
                        <!-- Dropdown -->
                        <div x-show="show" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                             class="absolute left-0 top-full mt-2 w-80 max-w-[calc(100vw-2rem)] rounded-2xl border border-slate-200 dark:border-white/10 bg-white/95 dark:bg-slate-800/95 backdrop-blur-md shadow-2xl z-50 max-h-96 overflow-y-auto">
                            <template x-if="loading">
                                <div class="px-4 py-6 text-center text-sm text-slate-400 flex items-center justify-center gap-2">
                                    <svg class="animate-spin h-4 w-4 text-teal-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                    Mencari…
                                </div>
                            </template>
                            <!-- Surat results -->
                            <template x-if="!loading && results.surat && results.surat.length > 0">
                                <div>
                                    <div class="px-4 py-2 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 border-b border-slate-100 dark:border-slate-700">Surat / Agenda</div>
                                    <template x-for="item in results.surat" :key="item.id">
                                        <a :href="item.url"
                                           class="flex flex-col px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors border-b border-slate-50 dark:border-slate-700/50 last:border-0">
                                            <span class="text-sm font-semibold text-slate-800 dark:text-slate-200 truncate" x-text="item.title"></span>
                                            <span class="text-xs text-slate-500 dark:text-slate-400" x-text="(item.reference_number || '') + (item.tanggal ? ' · ' + item.tanggal : '')"></span>
                                        </a>
                                    </template>
                                </div>
                            </template>
                            <!-- Pegawai results -->
                            <template x-if="!loading && results.pegawai && results.pegawai.length > 0">
                                <div>
                                    <div class="px-4 py-2 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 border-b border-slate-100 dark:border-slate-700">Pegawai</div>
                                    <template x-for="p in results.pegawai" :key="p.id">
                                        <a :href="p.url"
                                           class="flex flex-col px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors border-b border-slate-50 dark:border-slate-700/50 last:border-0">
                                            <span class="text-sm font-semibold text-slate-800 dark:text-slate-200" x-text="p.full_name"></span>
                                            <span class="text-xs text-slate-500 dark:text-slate-400" x-text="(p.nip || '') + (p.position_title ? ' · ' + p.position_title : '')"></span>
                                        </a>
                                    </template>
                                </div>
                            </template>
                            <!-- No results -->
                            <template x-if="!loading && (!results.surat || results.surat.length === 0) && (!results.pegawai || results.pegawai.length === 0)">
                                <div class="px-4 py-6 text-center text-sm text-slate-400 dark:text-slate-500">
                                    Tidak ada hasil untuk "<span x-text="query"></span>"
                                </div>
                            </template>
                        </div>
                    </div>
                    <?php endif; ?>

                    <button type="button" onclick="document.documentElement.classList.toggle('dark'); localStorage.setItem('darkMode', document.documentElement.classList.contains('dark'));" class="order-1 xl:order-none rounded-full p-2 text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-400" aria-label="Ganti mode terang/gelap">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 dark:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 hidden dark:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
                    </button>
                    
                    <?php if ($user): ?>
                    <div class="flex items-center gap-3">
                        <div class="relative" x-data="{}">
                            <button type="button" aria-label="Notifikasi" :aria-expanded="topbar.notif.toString()" @click="topbar.notif = !topbar.notif; topbar.profile = false" @click.outside="topbar.notif = false" class="relative rounded-full p-2 bg-white border border-slate-200 shadow-sm text-slate-600 hover:bg-slate-50 hover:text-slate-900 dark:bg-slate-800 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-700 dark:hover:text-white transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-400">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                                <?php if ($unreadNotifs > 0): ?>
                                    <span class="absolute top-1 right-1 flex h-4 w-4 items-center justify-center rounded-full bg-rose-500 text-[9px] font-bold text-white" aria-label="<?= $unreadNotifs ?> notifikasi belum dibaca"><?= $unreadNotifs > 99 ? '99+' : $unreadNotifs; ?></span>
                                <?php endif; ?>
                            </button>
                            <div x-show="topbar.notif" x-cloak x-transition.opacity.duration.200ms class="absolute right-0 mt-2 w-80 max-w-[calc(100vw-2rem)] origin-top-right rounded-2xl border border-slate-100 dark:border-slate-700 bg-white dark:bg-slate-800 shadow-xl ring-1 ring-slate-900/5 focus:outline-none max-h-[400px] overflow-y-auto">
                                <div class="px-4 py-3 border-b border-slate-100 dark:border-slate-700 flex justify-between items-center">
                                    <span class="text-sm font-bold text-slate-900 dark:text-slate-100">Notifikasi</span>
                                    <?php if ($unreadNotifs > 0): ?>
                                        <a href="mark_notification_read.php?action=all" class="text-xs text-teal-600 hover:underline">Tandai semua dibaca</a>
                                    <?php endif; ?>
                                </div>
                                <div class="divide-y divide-slate-100 dark:divide-slate-700">
                                    <?php if (!$notifications): ?>
                                        <div class="px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400">Belum ada notifikasi.</div>
                                    <?php else: ?>
                                        <?php foreach ($notifications as $n): 
                                            $isSynthetic = ($n['id'] ?? 0) === -1;
                                            $href = $isSynthetic ? $n['link'] : 'mark_notification_read.php?id=' . $n['id'] . '&link=' . urlencode((string)$n['link']);
                                        ?>
                                            <a href="<?= e($href); ?>" 
                                               class="block px-4 py-3 transition-colors border-l-2 <?= (int)$n['is_read'] === 0 ? 'border-teal-500 bg-teal-50/70 hover:bg-teal-100/80 dark:bg-slate-700/60 dark:hover:bg-slate-700' : 'border-transparent bg-white hover:bg-slate-50 dark:bg-slate-800 dark:hover:bg-slate-700/50'; ?>">
                                                <div class="flex justify-between items-start mb-1">
                                                    <span class="text-xs <?= (int)$n['is_read'] === 0 ? 'font-bold text-slate-900 dark:text-white' : 'font-medium text-slate-400 dark:text-slate-500'; ?>"><?= e($n['title']); ?></span>
                                                    <span class="text-[10px] <?= (int)$n['is_read'] === 0 ? 'text-slate-600 dark:text-slate-300 font-medium' : 'text-slate-400 dark:text-slate-500'; ?>"><?= date('d/m H:i', strtotime($n['created_at'])); ?></span>
                                                </div>
                                                <p class="text-xs line-clamp-2 <?= (int)$n['is_read'] === 0 ? 'text-slate-800 dark:text-slate-200 font-medium' : 'text-slate-400 dark:text-slate-500'; ?>"><?= e($n['message']); ?></p>
                                            </a>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="relative" x-data="{}">
                            <button type="button" aria-label="Menu Profil" :aria-expanded="topbar.profile.toString()" @click="topbar.profile = !topbar.profile; topbar.notif = false" @click.outside="topbar.profile = false" class="flex items-center gap-2 rounded-full border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 py-1.5 pl-1.5 pr-4 shadow-sm transition-all hover:bg-slate-50 dark:hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-400">
                                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-teal-50 dark:bg-teal-900 text-teal-600 dark:text-teal-400">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                                </div>
                                <div class="flex flex-col text-left w-[90px] xl:w-[120px]">
                                    <span class="text-xs sm:text-sm font-bold text-slate-700 dark:text-slate-200 leading-tight whitespace-normal line-clamp-2"><?= e($user['full_name']); ?></span>
                                    <span class="text-[10px] text-slate-500 dark:text-slate-400 capitalize mt-0.5 truncate"><?= e($user['role'] ?? 'Pegawai'); ?></span>
                                </div>
                                <svg xmlns="http://www.w3.org/2000/svg" class="ml-2 h-4 w-4 text-slate-400 transition-transform duration-200" :class="{ 'rotate-180': topbar.profile }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
                            </button>
                            <div x-show="topbar.profile" x-cloak x-transition.opacity.duration.200ms class="absolute right-0 mt-2 w-48 origin-top-right rounded-2xl border border-slate-100 dark:border-slate-700 bg-white dark:bg-slate-800 p-2 shadow-xl ring-1 ring-slate-900/5 focus:outline-none">
                                <a href="logout.php" class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-semibold text-rose-600 dark:text-rose-400 transition-colors hover:bg-rose-50 dark:hover:bg-rose-900/30">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                                    Logout
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <a href="login.php" class="rounded-full bg-teal-600 px-5 py-2 text-sm font-bold text-white transition-all hover:bg-teal-700 hover:shadow-md">Login</a>
                    <?php endif; ?>

                    <button type="button" @click="mobileMenuOpen = !mobileMenuOpen" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white p-2 text-slate-700 hover:bg-slate-50 xl:hidden focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-400" :aria-expanded="mobileMenuOpen.toString()" aria-label="Buka menu">
                        <svg x-show="!mobileMenuOpen" x-cloak xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M3 6h18"></path>
                            <path d="M3 12h18"></path>
                            <path d="M3 18h18"></path>
                        </svg>
                        <svg x-show="mobileMenuOpen" x-cloak xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="m18 6-12 12"></path>
                            <path d="m6 6 12 12"></path>
                        </svg>
                    </button>
                </div>
            </div>
            
            <div x-show="mobileMenuOpen" x-cloak x-transition class="border-t border-slate-100 bg-white px-4 py-4 shadow-lg absolute w-full z-50">
                <nav class="grid gap-1 px-2 text-sm text-slate-600 dark:text-slate-400" aria-label="Navigasi mobile">
                    <div class="px-3 mb-1 mt-2 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">MENU UTAMA</div>
                    <a href="dashboard.php" class="rounded-xl px-3 py-2.5 transition-all <?= $currentPage === 'dashboard.php' ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400 font-bold' : 'hover:bg-slate-100 hover:text-slate-900 dark:hover:bg-slate-700/50 dark:hover:text-slate-200 font-medium'; ?>">Dashboard</a>
                    <a href="calendar.php" class="rounded-xl px-3 py-2.5 transition-all <?= $currentPage === 'calendar.php' ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400 font-bold' : 'hover:bg-slate-100 hover:text-slate-900 dark:hover:bg-slate-700/50 dark:hover:text-slate-200 font-medium'; ?>">Kalender</a>
                    
                    <?php if (!isPegawai() && !isArsiparis()): ?>
                        <div class="px-3 mt-4 mb-1 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">AGENDA & SURAT</div>
                        <a href="agenda.php" class="rounded-xl px-3 py-2.5 transition-all <?= $currentPage === 'agenda.php' ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400 font-bold' : 'hover:bg-slate-100 hover:text-slate-900 dark:hover:bg-slate-700/50 dark:hover:text-slate-200 font-medium'; ?>">Agenda Surat</a>
                        <a href="agenda_kegiatan.php" class="rounded-xl px-3 py-2.5 transition-all <?= $currentPage === 'agenda_kegiatan.php' ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400 font-bold' : 'hover:bg-slate-100 hover:text-slate-900 dark:hover:bg-slate-700/50 dark:hover:text-slate-200 font-medium'; ?>">Agenda Kegiatan</a>
                        <a href="agenda_zoom.php" class="rounded-xl px-3 py-2.5 transition-all <?= $currentPage === 'agenda_zoom.php' ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400 font-bold' : 'hover:bg-slate-100 hover:text-slate-900 dark:hover:bg-slate-700/50 dark:hover:text-slate-200 font-medium'; ?>">Agenda Zoom</a>
                        <a href="export_laporan.php" class="rounded-xl px-3 py-2.5 transition-all <?= $currentPage === 'export_laporan.php' ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400 font-bold' : 'hover:bg-slate-100 hover:text-slate-900 dark:hover:bg-slate-700/50 dark:hover:text-slate-200 font-medium'; ?>">Export Laporan</a>
                    <?php endif; ?>

                    <?php if (isArsiparis()): ?>
                        <div class="px-3 mt-4 mb-1 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">ARSIP SURAT</div>
                        <a href="agenda.php" class="rounded-xl px-3 py-2.5 transition-all <?= $currentPage === 'agenda.php' ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400 font-bold' : 'hover:bg-slate-100 hover:text-slate-900 dark:hover:bg-slate-700/50 dark:hover:text-slate-200 font-medium'; ?>">Arsip Surat</a>
                    <?php endif; ?>
                    
                    <?php if (isAdmin() || isPimpinan()): ?>
                        <div class="px-3 mt-4 mb-1 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">DISPOSISI</div>
                        <a href="disposisi.php" class="rounded-xl px-3 py-2.5 transition-all <?= in_array($currentPage, ['disposisi.php', 'disposisi_form.php'], true) ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400 font-bold' : 'hover:bg-slate-100 hover:text-slate-900 dark:hover:bg-slate-700/50 dark:hover:text-slate-200 font-medium'; ?>">Disposisi</a>
                    <?php endif; ?>
                    
                    <?php if (isPegawai()): ?>
                        <div class="px-3 mt-4 mb-1 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">DISPOSISI</div>
                        <a href="disposisi_saya.php" class="rounded-xl px-3 py-2.5 transition-all <?= in_array($currentPage, ['disposisi_saya.php'], true) ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400 font-bold' : 'hover:bg-slate-100 hover:text-slate-900 dark:hover:bg-slate-700/50 dark:hover:text-slate-200 font-medium'; ?>">Disposisi Saya</a>
                    <?php endif; ?>
                    
                    <?php if (isAdmin() || isSekretaris()): ?>
                        <div class="px-3 mt-4 mb-1 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">ADMINISTRASI</div>
                        <?php if (isAdmin()): ?>
                        <a href="master_pegawai.php" class="rounded-xl px-3 py-2.5 transition-all <?= in_array($currentPage, ['master_pegawai.php', 'master_pegawai_form.php'], true) ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400 font-bold' : 'hover:bg-slate-100 hover:text-slate-900 dark:hover:bg-slate-700/50 dark:hover:text-slate-200 font-medium'; ?>">Master Pegawai</a>
                        <?php endif; ?>
                        <a href="backup.php" class="rounded-xl px-3 py-2.5 transition-all <?= $currentPage === 'backup.php' ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400 font-bold' : 'hover:bg-slate-100 hover:text-slate-900 dark:hover:bg-slate-700/50 dark:hover:text-slate-200 font-medium'; ?>">Backup Database</a>
                    <?php endif; ?>
                    
                    <?php if (isAdmin()): ?>
                        <div class="px-3 mt-4 mb-1 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">SISTEM</div>
                        <a href="activity_logs.php" class="rounded-xl px-3 py-2.5 transition-all <?= $currentPage === 'activity_logs.php' ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400 font-bold' : 'hover:bg-slate-100 hover:text-slate-900 dark:hover:bg-slate-700/50 dark:hover:text-slate-200 font-medium'; ?>">Log Aktivitas</a>
                        <a href="user_manager.php" class="rounded-xl px-3 py-2.5 transition-all <?= in_array($currentPage, ['user_manager.php'], true) ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400 font-bold' : 'hover:bg-slate-100 hover:text-slate-900 dark:hover:bg-slate-700/50 dark:hover:text-slate-200 font-medium'; ?>">Kelola Pengguna</a>
                        <a href="settings.php" class="rounded-xl px-3 py-2.5 transition-all <?= $currentPage === 'settings.php' ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400 font-bold' : 'hover:bg-slate-100 hover:text-slate-900 dark:hover:bg-slate-700/50 dark:hover:text-slate-200 font-medium'; ?>">Pengaturan</a>
                    <?php endif; ?>
                </nav>
                <?php if ($user): ?>
                    <div class="mt-4 flex items-center justify-between border-t border-slate-100 pt-4">
                        <div class="flex items-center gap-2">
                            <div class="flex h-8 w-8 items-center justify-center rounded-full bg-teal-50 dark:bg-teal-900 text-teal-600 dark:text-teal-400">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                            </div>
                            <div class="flex flex-col">
                                <span class="text-sm font-bold text-slate-700 dark:text-slate-200 leading-tight"><?= e($user['full_name']); ?></span>
                                <span class="text-[10px] text-slate-500 dark:text-slate-400 capitalize"><?= e($user['role'] ?? 'Pegawai'); ?></span>
                            </div>
                        </div>
                        <a href="logout.php" class="rounded-xl px-4 py-2 text-sm font-bold text-rose-600 hover:bg-rose-50">Logout</a>
                    </div>
                <?php else: ?>
                    <div class="mt-4 border-t border-slate-100 pt-4">
                        <a href="login.php" class="block rounded-xl bg-teal-600 px-4 py-2 text-center text-sm font-bold text-white hover:bg-teal-700">Login Pengguna</a>
                    </div>
                <?php endif; ?>
            </div>
        </header>

        <main id="main-content" class="flex-1 p-4 sm:p-6 lg:p-8">
