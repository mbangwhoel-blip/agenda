<?php

declare(strict_types=1);


require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/ai_agenda.php';

$pdo = getPdo();
requireLogin();
requireRole(['admin', 'sekretaris', 'pimpinan']);
$flash = getFlash();

$q = trim($_GET['q'] ?? '');
$scheduleFilter = trim($_GET['schedule'] ?? '');

if (!in_array($scheduleFilter, ['today', 'upcoming', 'done'], true)) {
    $scheduleFilter = '';
}

$conditions = [
    "a.entry_type = 'kegiatan'",
    "(a.agenda_mode IN ('zoom', 'hybrid') OR (a.zoom_link IS NOT NULL AND a.zoom_link <> '') OR (a.zoom_meeting_id IS NOT NULL AND a.zoom_meeting_id <> ''))",
];
$params = [];

if ($q !== '') {
    $conditions[] = '(a.title LIKE :q1 OR a.counterparty LIKE :q2 OR a.zoom_meeting_id LIKE :q3)';
    $params['q1'] = "%{$q}%";
    $params['q2'] = "%{$q}%";
    $params['q3'] = "%{$q}%";
}
if ($scheduleFilter === 'today') {
    $conditions[] = 'a.agenda_date = CURDATE()';
} elseif ($scheduleFilter === 'upcoming') {
    $conditions[] = 'a.agenda_date > CURDATE()';
} elseif ($scheduleFilter === 'done') {
    $conditions[] = 'a.agenda_date < CURDATE()';
}

$statsStmt = $pdo->query(
    "SELECT
        COUNT(*) AS total_zoom,
        SUM(CASE WHEN agenda_date = CURDATE() OR (agenda_date <= CURDATE() AND COALESCE(agenda_date_end, agenda_date) >= CURDATE()) THEN 1 ELSE 0 END) AS hari_ini,
        SUM(CASE WHEN COALESCE(agenda_date_end, agenda_date) < CURDATE() THEN 1 ELSE 0 END) AS selesai
     FROM agendas
     WHERE entry_type = 'kegiatan'
       AND (agenda_mode IN ('zoom', 'hybrid') OR (zoom_link IS NOT NULL AND zoom_link <> '') OR (zoom_meeting_id IS NOT NULL AND zoom_meeting_id <> ''))"
);
$stats = $statsStmt->fetch() ?: ['total_zoom' => 0, 'hari_ini' => 0, 'selesai' => 0];

$p = max(1, (int) ($_GET['p'] ?? 1));
$limit = 7;
$offset = ($p - 1) * $limit;

$countSql = "SELECT COUNT(*) FROM agendas a WHERE " . implode(' AND ', $conditions);
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalItems = (int) $countStmt->fetchColumn();

$sql = "SELECT a.*, s.attachment AS source_attachment, s.title AS source_title, s.description AS source_description, s.notes AS source_notes, s.counterparty AS source_counterparty, s.reference_number AS source_reference_number, s.agenda_time AS source_agenda_time, u.full_name AS created_by
        FROM agendas a
        LEFT JOIN agendas s ON s.id = a.source_agenda_id
        LEFT JOIN users u ON u.id = a.user_id
        WHERE " . implode(' AND ', $conditions) . "
        ORDER BY CASE WHEN a.agenda_date = CURDATE() THEN 1 WHEN a.agenda_date > CURDATE() THEN 2 ELSE 3 END ASC, ABS(DATEDIFF(a.agenda_date, CURDATE())) ASC, a.id DESC
        LIMIT {$limit} OFFSET {$offset}";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
foreach($rows as &$r) { $r['title'] = str_replace('Tindak Lanjut Surat: ', '', $r['title'] ?? ''); } unset($r);

$zoomReminderStmt = $pdo->query(
    "SELECT COUNT(*) AS total_zoom_reminder
     FROM agendas
     WHERE entry_type = 'kegiatan'
       AND (agenda_mode IN ('zoom', 'hybrid') OR (zoom_link IS NOT NULL AND zoom_link <> '') OR (zoom_meeting_id IS NOT NULL AND zoom_meeting_id <> ''))
       AND reminder_h1_enabled = 1
       AND reminder_h1_sent_at IS NULL
       AND reminder_h1_at IS NOT NULL
       AND status != 'selesai'
       AND (agenda_date > CURDATE() OR (agenda_date = CURDATE() AND COALESCE(agenda_time, '23:59:59') >= CURTIME()))"
);
$zoomReminderCount = (int) ($zoomReminderStmt->fetchColumn() ?: 0);

function zoomScheduleBadge(string $agendaDate, ?string $endDate = null): array
{
    $date = trim($agendaDate);
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $effectiveEnd = !empty($endDate) && $endDate !== '0000-00-00' ? trim((string)$endDate) : $date;
    if ($effectiveEnd < $date) {
        $effectiveEnd = $date;
    }

    if (empty($date) || $date === '0000-00-00') {
        return ['label' => 'Direncanakan', 'class' => 'badge-direncanakan text-white font-bold border-0 shadow-sm'];
    }

    // Selesai jika tanggal akhir sudah lewat sebelum hari ini
    if ($effectiveEnd < $today) {
        return ['label' => 'Selesai', 'class' => 'badge-selesai text-white font-bold border-0 shadow-sm'];
    }

    // Rentang multi-hari yang sedang berjalan (mulai di masa lalu, berakhir setelah hari ini)
    if ($date < $today && $effectiveEnd > $today) {
        return ['label' => 'Berlangsung', 'class' => 'badge-moving badge-moving-berlangsung text-white font-bold border-0 shadow-sm'];
    }

    // Hari ini jika tepat hari ini (single day) atau hari terakhir rentang kegiatan
    if ($date === $today || $effectiveEnd === $today) {
        return ['label' => 'Hari Ini', 'class' => 'badge-moving badge-moving-hari-ini text-white font-bold border-0 shadow-sm'];
    }

    if ($date === $tomorrow) {
        return ['label' => 'Besok', 'class' => 'badge-besok text-white font-bold border-0 shadow-sm'];
    }

    if ($date > $tomorrow) {
        return ['label' => 'Mendatang', 'class' => 'badge-moving badge-moving-mendatang text-white font-bold border-0 shadow-sm'];
    }

    return ['label' => 'Selesai', 'class' => 'badge-selesai text-white font-bold border-0 shadow-sm'];
}

function normalizeMeetingUrl(?string $url): ?string
{
    $value = trim((string) $url);
    if ($value === '') {
        return null;
    }

    $value = rtrim($value, ".,;:!?)]}");
    if (!preg_match('~^https?://~i', $value)) {
        $value = 'https://' . $value;
    }

    if (!filter_var($value, FILTER_VALIDATE_URL)) {
        return null;
    }

    $host = strtolower((string) parse_url($value, PHP_URL_HOST));
    if (preg_match('/^[a-z]\.[a-z]{2,3}$/', $host) === 1) {
        return null;
    }

    return $value;
}

function zoomTimeLabel(?string $time, ?string $sourceTime = null): string
{
    $value = trim((string) $time);
    if ($value === '' || $value === '00:00:00') {
        $value = trim((string) $sourceTime);
    }
    if ($value === '' || $value === '00:00:00') {
        $value = '09:00:00';
    }
    return substr($value, 0, 5);
}

$pageTitle = 'Agenda Zoom';
require_once __DIR__ . '/includes/header.php';
?>
<div x-data="{ showDeleteModal: false, deleteUrl: '', showFilter: false }">

<?php if ($flash): ?>
    <div x-data="{ show: true }" x-show="show" x-cloak x-init="setTimeout(() => show = false, 2600)" x-transition class="mb-4 rounded-xl border px-4 py-3 text-sm <?= $flash['type'] === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-700'; ?>">
        <?= e($flash['message']); ?>
    </div>
<?php endif; ?>

<header class="mb-6">
    <h1 class="text-3xl font-extrabold tracking-tight text-slate-900 dark:text-white mb-1">Agenda Zoom</h1>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-slate-500 dark:text-slate-400">Kelola jadwal meeting Zoom & hybrid</p>
        
        <?php if ($zoomReminderCount > 0): ?>
        <div class="inline-flex items-center gap-2 rounded-xl border border-violet-200 bg-violet-50 px-3 py-1.5 ml-auto dark:border-violet-800 dark:bg-violet-900/20">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 text-violet-600 dark:text-violet-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
            </svg>
            <p class="text-[13px] font-medium text-violet-800 dark:text-violet-200">
                <strong><?= $zoomReminderCount; ?> agenda zoom</strong> besok akan dikirim pengingat otomatis.
            </p>
        </div>
        <?php endif; ?>
    </div>
</header>

<section class="mb-6">
    <div class="grid gap-6 sm:grid-cols-3">
        <article class="relative flex flex-col rounded-3xl bg-white dark:bg-slate-800 p-6 shadow-sm border border-slate-200 dark:border-slate-700 transition-all hover:shadow-md hover:-translate-y-1 overflow-hidden group">
            <div class="absolute inset-0 bg-gradient-to-br from-indigo-50 to-transparent dark:from-indigo-500/10 dark:to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
            <div class="relative z-10 flex justify-between items-start">
                <div>
                    <p class="text-[13px] font-bold tracking-wider text-slate-500 dark:text-slate-400 uppercase">Total Zoom</p>
                    <p class="mt-2 text-4xl font-black text-slate-900 dark:text-white"><?= (int) $stats['total_zoom']; ?></p>
                </div>
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-indigo-100 dark:bg-indigo-500/20 text-indigo-600 dark:text-indigo-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                </div>
            </div>
        </article>
        
        <article class="relative flex flex-col rounded-3xl bg-white dark:bg-slate-800 p-6 shadow-sm border border-slate-200 dark:border-slate-700 transition-all hover:shadow-md hover:-translate-y-1 overflow-hidden group">
            <div class="absolute inset-0 bg-gradient-to-br from-sky-50 to-transparent dark:from-sky-500/10 dark:to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
            <div class="relative z-10 flex justify-between items-start">
                <div>
                    <p class="text-[13px] font-bold tracking-wider text-slate-500 dark:text-slate-400 uppercase">Agenda Hari Ini</p>
                    <p class="mt-2 text-4xl font-black text-slate-900 dark:text-white"><?= (int) $stats['hari_ini']; ?></p>
                </div>
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-sky-100 dark:bg-sky-500/20 text-sky-600 dark:text-sky-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
            </div>
        </article>
        
        <article class="relative flex flex-col rounded-3xl bg-white dark:bg-slate-800 p-6 shadow-sm border border-slate-200 dark:border-slate-700 transition-all hover:shadow-md hover:-translate-y-1 overflow-hidden group">
            <div class="absolute inset-0 bg-gradient-to-br from-emerald-50 to-transparent dark:from-emerald-500/10 dark:to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
            <div class="relative z-10 flex justify-between items-start">
                <div>
                    <p class="text-[13px] font-bold tracking-wider text-slate-500 dark:text-slate-400 uppercase">Sudah Selesai</p>
                    <p class="mt-2 text-4xl font-black text-slate-900 dark:text-white"><?= (int) $stats['selesai']; ?></p>
                </div>
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-100 dark:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                </div>
            </div>
        </article>
    </div>
</section>

<section class="mb-5 rounded-2xl border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-800">
    <form method="get" class="flex flex-wrap items-center gap-2">
        <div class="flex-1 min-w-[180px]">
            <input type="text" name="q" value="<?= e($q); ?>" placeholder="Cari judul, penyelenggara, meeting ID…" class="w-full rounded-lg border border-slate-200 bg-slate-50 py-2.5 px-3 text-sm text-slate-700 placeholder:text-slate-400 focus:border-teal-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-teal-100 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200">
        </div>
        <select name="schedule" style="appearance:none;-webkit-appearance:none;-moz-appearance:none;background-image:url(&quot;data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E&quot;);background-repeat:no-repeat;background-position:right 10px center;padding-right:30px;" class="w-[200px] shrink-0 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 focus:border-teal-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-teal-100 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200">
            <option value="" <?= $scheduleFilter === '' ? 'selected' : ''; ?>>Semua Jadwal</option>
            <option value="today" <?= $scheduleFilter === 'today' ? 'selected' : ''; ?>>Hari Ini</option>
            <option value="upcoming" <?= $scheduleFilter === 'upcoming' ? 'selected' : ''; ?>>Akan Datang</option>
            <option value="done" <?= $scheduleFilter === 'done' ? 'selected' : ''; ?>>Selesai</option>
        </select>
        <button type="submit" class="shrink-0 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-indigo-700 transition">Cari</button>
        <?php if ($q || $scheduleFilter): ?>
        <a href="agenda_zoom.php" class="shrink-0 rounded-lg border border-slate-200 px-3 py-2.5 text-sm font-semibold text-slate-500 hover:bg-slate-50 dark:border-slate-600 dark:hover:bg-slate-700">Reset</a>
        <?php endif; ?>
        <?php if (!isPimpinan()): ?>
        <a href="agenda_form.php?entry_type=kegiatan&agenda_mode=zoom&return_to=agenda_zoom.php" class="shrink-0 inline-flex items-center gap-1.5 rounded-lg bg-teal-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-teal-700 transition">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            Tambah Zoom
        </a>
        <?php endif; ?>
    </form>
</section>

<section class="mt-6 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
    <div class="overflow-x-auto">
        <table class="w-full table-fixed text-sm leading-6">
            <thead class="bg-slate-50 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-700 text-xs uppercase tracking-wider text-slate-500 font-semibold">
                <tr>
                    <th class="w-10 px-2 py-4 text-center">No</th>
                    <th class="w-[34%] px-3 py-4 text-center">Judul Meeting</th>
                    <th class="w-[14%] px-3 py-4 text-center">Tanggal</th>
                    <th class="w-[9%] px-3 py-4 text-center">Waktu</th>
                    <th class="w-[16%] px-3 py-4 text-center">Link Zoom</th>
                    <th class="w-[23%] px-3 py-4 text-center whitespace-nowrap">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700 text-slate-700 dark:text-slate-300">
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="6" class="py-16 text-center">
                            <div class="flex flex-col items-center justify-center opacity-80">
                                <svg xmlns="http://www.w3.org/2000/svg" class="mb-4 h-16 w-16 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                </svg>
                                <h5 class="text-xl font-bold text-slate-500">Belum Ada Agenda Zoom</h5>
                                <p class="mt-2 text-slate-400">Data jadwal meeting Zoom akan ditampilkan di area ini.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $i => $row): ?>
                        <?php $badge = zoomScheduleBadge((string) ($row['agenda_date'] ?? ''), !empty($row['agenda_date_end']) ? (string)$row['agenda_date_end'] : null); ?>
                        <?php $joinUrl = normalizeMeetingUrl((string) ($row['zoom_link'] ?? '')); ?>
                        <?php
                        if ($joinUrl === null) {
                            $sourceContext = implode(' ', [
                                (string) ($row['source_title'] ?? ''),
                                (string) ($row['source_description'] ?? ''),
                                (string) ($row['source_notes'] ?? ''),
                                (string) ($row['source_counterparty'] ?? ''),
                            ]);
                            $fallbackFromSource = extractFirstUrl($sourceContext);
                            $fallbackJoinUrl = normalizeMeetingUrl($fallbackFromSource);
                            if (
                                $fallbackJoinUrl !== null
                                && (
                                    str_contains(strtolower($fallbackJoinUrl), 'zoom')
                                    || isLikelyMeetingShortLink($fallbackJoinUrl)
                                )
                            ) {
                                $joinUrl = $fallbackJoinUrl;
                                if (canManageAgenda((int) ($row['user_id'] ?? 0))) {
                                    $fixStmt = $pdo->prepare("UPDATE agendas SET zoom_link = :zoom_link WHERE id = :id");
                                    $fixStmt->execute([
                                        'zoom_link' => $fallbackJoinUrl,
                                        'id' => (int) $row['id'],
                                    ]);
                                }
                            }
                        }
                        if ($joinUrl === null && !empty($row['zoom_meeting_id'])) {
                            $cleanMeetingDigits = preg_replace('/\D/', '', (string) $row['zoom_meeting_id']);
                            if (strlen($cleanMeetingDigits) >= 9 && strlen($cleanMeetingDigits) <= 12) {
                                $joinUrl = 'https://zoom.us/j/' . $cleanMeetingDigits;
                            }
                        }
                        ?>
                        <tr class="align-middle hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                            <td class="px-2 py-4 font-bold text-indigo-600"><?= $offset + $i + 1; ?></td>
                            <td class="break-words px-3 py-4">
                                <p class="font-semibold text-slate-800"><?= e((string) $row['title']); ?></p>
                                <div class="mt-2">
                                    <span class="ui-badge <?= $badge['class'] ?>">
                                        <?= e((string) $badge['label']); ?>
                                    </span>
                                </div>
                            </td>
                            <td class="px-3 py-4 font-semibold text-slate-800">
                                <?php
                                $d1z = date('d-m-Y', strtotime((string) $row['agenda_date']));
                                $d2z = !empty($row['agenda_date_end']) ? date('d-m-Y', strtotime((string) $row['agenda_date_end'])) : $d1z;
                                echo e($d1z === $d2z ? $d1z : "$d1z s/d $d2z");
                                ?>
                            </td>
                            <td class="px-3 py-4 font-semibold text-slate-800"><?= e(zoomTimeLabel($row['agenda_time'] ?? null, $row['source_agenda_time'] ?? null)); ?></td>
                            <td class="px-3 py-4">
                                <?php if ($joinUrl !== null): ?>
                                    <a href="<?= e($joinUrl); ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 font-semibold text-indigo-600 hover:text-indigo-700 whitespace-nowrap">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M14 3h7v7"></path>
                                            <path d="M10 14 21 3"></path>
                                            <path d="M21 14v7h-7"></path>
                                            <path d="M3 10V3h7"></path>
                                            <path d="m3 3 7 7"></path>
                                        </svg>
                                        Gabung
                                    </a>
                                <?php else: ?>
                                    <span class="text-slate-400">-</span>
                                <?php endif; ?>
                                <?php if (!empty($row['zoom_meeting_id']) || !empty($row['zoom_passcode'])): ?>
                                    <div class="mt-2 text-[11px] text-slate-600 dark:text-slate-400 break-all">
                                        <?php if (!empty($row['zoom_meeting_id'])): ?><div class="mb-0.5"><strong class="text-slate-700 dark:text-slate-300">ID:</strong> <?= e((string)$row['zoom_meeting_id']) ?></div><?php endif; ?>
                                        <?php if (!empty($row['zoom_passcode'])): ?><div><strong class="text-slate-700 dark:text-slate-300">Passcode:</strong> <?= e((string)$row['zoom_passcode']) ?></div><?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-3 py-4">
                                <div class="flex items-center gap-2">
                                    <?php $hasAttachment = !empty($row['attachment']) || !empty($row['source_attachment']); ?>
                                    <?php if ($hasAttachment): ?>
                                        <a href="file_preview.php?id=<?= (int) $row['id']; ?><?= !empty($row['source_attachment']) ? '&source=1' : ''; ?>&return_to=agenda_zoom.php" target="_blank" class="ui-icon-btn bg-indigo-50 text-indigo-600 transition hover:bg-indigo-100" title="Lihat lampiran">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12Z"></path>
                                                <circle cx="12" cy="12" r="3"></circle>
                                            </svg>
                                        </a>
                                    <?php endif; ?>
                                    <?php if (!isPimpinan() && canManageAgenda((int) ($row['user_id'] ?? 0))): ?>
                                        <a href="agenda_duplicate.php?id=<?= (int) $row['id']; ?>&count=52&return_to=agenda_zoom.php&token=<?= e(csrf_token()); ?>" class="ui-icon-btn bg-violet-100 text-violet-700 transition hover:bg-violet-200" title="Duplikat 1 tahun (52 minggu)" onclick="return confirm('Buat 52 duplikat mingguan untuk 1 tahun ke depan? Status draft, reminder H-1 aktif tiap minggu.');">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"></path>
                                                <circle cx="12" cy="12" r="3"></circle>
                                            </svg>
                                        </a>
                                        <a href="agenda_duplicate.php?id=<?= (int) $row['id']; ?>&return_to=agenda_zoom.php&token=<?= e(csrf_token()); ?>" class="ui-icon-btn bg-emerald-100 text-emerald-700 transition hover:bg-emerald-200" title="Duplikat ke minggu depan" onclick="return confirm('Duplikat agenda ini ke minggu depan (tanggal +7 hari)? Reminder H-1 akan direset dan jalan otomatis.');">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                            </svg>
                                        </a>
                                        <a href="agenda_form.php?id=<?= (int) $row['id']; ?>&return_to=agenda_zoom.php" class="ui-icon-btn bg-amber-100 text-amber-700 transition hover:bg-amber-200" title="Edit agenda zoom">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M12 20h9"></path>
                                                <path d="m16.5 3.5 4 4L7 21H3v-4L16.5 3.5Z"></path>
                                            </svg>
                                        </a>
                                        <a href="agenda_delete.php?id=<?= (int) $row['id']; ?>&return_to=agenda_zoom.php&token=<?= e(csrf_token()); ?>" @click.prevent="deleteUrl = $el.getAttribute('href'); showDeleteModal = true" class="ui-icon-btn bg-red-100 text-red-700 transition hover:bg-red-200" title="Hapus agenda zoom">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M3 6h18"></path>
                                                <path d="M8 6V4h8v2"></path>
                                                <path d="M19 6l-1 14H6L5 6"></path>
                                                <path d="M10 11v6M14 11v6"></path>
                                            </svg>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php renderPagination('p', $p, $totalItems, $limit); ?>
</section>

<div x-cloak x-show="showDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
    <div @click.outside="showDeleteModal = false" class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-5 shadow-xl">
        <h3 class="text-lg font-bold text-slate-900">Konfirmasi Hapus</h3>
        <p class="mt-2 text-sm text-slate-600">Yakin ingin menghapus agenda Zoom ini? Tindakan ini tidak bisa dibatalkan.</p>
        <div class="mt-5 flex items-center justify-end gap-2">
            <button type="button" @click="showDeleteModal = false" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Batal</button>
            <a :href="deleteUrl" class="rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Ya, Hapus</a>
        </div>
    </div>
</div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
