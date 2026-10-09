<?php

declare(strict_types=1);

require_once __DIR__ . '/app/helpers.php';

$pdo = getPdo();
requireLogin();

if (!canApproveDisposisi()) {
    setFlash('error', 'Akses disposisi hanya untuk pimpinan.');
    redirect('dashboard.php');
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    setFlash('error', 'Data surat tidak valid.');
    redirect('disposisi.php');
}

$stmt = $pdo->prepare("SELECT * FROM agendas WHERE id = :id AND entry_type = 'surat' LIMIT 1");
$stmt->execute(['id' => $id]);
$row = $stmt->fetch();
if (!$row) {
    setFlash('error', 'Data surat tidak ditemukan.');
    redirect('disposisi.php');
}

$tab = trim((string) ($_GET['tab'] ?? 'disposisi'));
if (!in_array($tab, ['disposisi', 'koordinasi', 'arahan'], true)) {
    $tab = 'disposisi';
}

$perPage = (int) ($_GET['limit'] ?? 10);
if (!in_array($perPage, [10, 25, 50], true)) {
    $perPage = 10;
}

$searchTo = trim((string) ($_GET['to'] ?? ''));
$searchMsg = trim((string) ($_GET['msg'] ?? ''));
$searchInst = trim((string) ($_GET['inst'] ?? ''));

$errors = [];
$formData = [
    'item_type' => $tab,
    'employee_id' => '',
    'employee_ids' => [],
    'employee_name' => '',
    'message_text' => '',
    'instruction_text' => '',
    'due_at' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Token keamanan tidak valid. Silakan muat ulang halaman.');
        redirect('disposisi_form.php?id=' . $id . '&tab=' . urlencode($tab));
    }

    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'add_item') {
        $formData['item_type'] = trim((string) ($_POST['item_type'] ?? 'disposisi'));
        $formData['employee_id'] = trim((string) ($_POST['employee_id'] ?? ''));
        $formData['employee_ids'] = array_values(array_filter(array_map('intval', (array) ($_POST['employee_ids'] ?? [])), static fn (int $v): bool => $v > 0));
        $formData['employee_name'] = trim((string) ($_POST['employee_name'] ?? ''));
        
        $selectedOpts = (array) ($_POST['message_options'] ?? []);
        $formData['message_text'] = implode(', ', array_filter(array_map('trim', $selectedOpts)));
        
        $formData['instruction_text'] = trim((string) ($_POST['instruction_text'] ?? ''));
        $formData['due_at'] = trim((string) ($_POST['due_at'] ?? ''));

        if (!in_array($formData['item_type'], ['disposisi', 'koordinasi', 'arahan'], true)) {
            $errors[] = 'Jenis tindak lanjut tidak valid.';
        }
        if (count($formData['employee_ids']) === 0 && $formData['employee_name'] === '') {
            $errors[] = 'Pilih minimal satu pegawai tujuan.';
        }
        if ($formData['message_text'] === '') {
            $errors[] = 'Pesan disposisi wajib diisi.';
        }

        $targetEmployees = [];
        if (count($formData['employee_ids']) > 0) {
            $checkEmployees = $pdo->prepare("SELECT id, full_name FROM employees WHERE id = :id LIMIT 1");
            foreach ($formData['employee_ids'] as $employeeIdValue) {
                $checkEmployees->execute(['id' => $employeeIdValue]);
                $employeeRow = $checkEmployees->fetch();
                if ($employeeRow) {
                    $targetEmployees[] = [
                        'id' => (int) $employeeRow['id'],
                        'name' => (string) $employeeRow['full_name'],
                    ];
                }
            }
            if (count($targetEmployees) === 0) {
                $errors[] = 'Pegawai tujuan tidak ditemukan.';
            }
        } elseif ($formData['employee_id'] !== '') {
            $employeeId = (int) $formData['employee_id'];
            $checkEmployee = $pdo->prepare("SELECT id, full_name FROM employees WHERE id = :id LIMIT 1");
            $checkEmployee->execute(['id' => $employeeId]);
            $employeeRow = $checkEmployee->fetch();
            if (!$employeeRow) {
                $errors[] = 'Pegawai tujuan tidak ditemukan.';
            } else {
                $targetEmployees[] = [
                    'id' => (int) $employeeRow['id'],
                    'name' => (string) $employeeRow['full_name'],
                ];
            }
        } else {
            $empNameSearch = strtolower(trim($formData['employee_name']));
            if ($empNameSearch === 'semua pegawai') {
                $allEmployees = $pdo->query("SELECT id, full_name FROM employees WHERE is_active = 1")->fetchAll();
                if ($allEmployees) {
                    foreach ($allEmployees as $empRow) {
                        $targetEmployees[] = [
                            'id' => (int) $empRow['id'],
                            'name' => (string) $empRow['full_name'],
                        ];
                    }
                } else {
                    $errors[] = 'Belum ada data pegawai yang aktif di sistem.';
                }
            } else {
                $employeeByName = $pdo->prepare("SELECT id, full_name FROM employees WHERE full_name LIKE :full_name LIMIT 1");
                $employeeByName->execute(['full_name' => '%' . trim($formData['employee_name']) . '%']);
                $foundByName = $employeeByName->fetch();
                if ($foundByName) {
                    $targetEmployees[] = [
                        'id' => (int) $foundByName['id'],
                        'name' => (string) $foundByName['full_name'],
                    ];
                } else {
                    $errors[] = 'Pegawai tujuan "' . $formData['employee_name'] . '" tidak ditemukan di sistem. Pastikan nama yang diketik sesuai dengan daftar atau ketik "Semua Pegawai".';
                }
            }
        }

        if (!$errors) {
            $dueAt = null;
            if ($formData['due_at'] !== '') {
                $dt = date_create($formData['due_at']);
                $dueAt = $dt ? $dt->format('Y-m-d H:i:s') : null;
            }

            try {
                $insert = $pdo->prepare(
                    "INSERT INTO agenda_disposition_items
                    (agenda_id, item_type, employee_id, employee_name, message_text, instruction_text, attachment_label, disposition_at, due_at, read_status, created_by)
                    VALUES
                    (:agenda_id, :item_type, :employee_id, :employee_name, :message_text, :instruction_text, 'TIDAK ADA', NOW(), :due_at, 'belum', :created_by)"
                );
                foreach ($targetEmployees as $targetEmployee) {
                    $insert->execute([
                        'agenda_id' => $id,
                        'item_type' => $formData['item_type'],
                        'employee_id' => $targetEmployee['id'],
                        'employee_name' => $targetEmployee['name'],
                        'message_text' => $formData['message_text'],
                        'instruction_text' => $formData['instruction_text'],
                        'due_at' => $dueAt,
                        'created_by' => (int) currentUser()['id'],
                    ]);
                    
                    if ($targetEmployee['id'] !== null) {
                        try {
                            sendDisposisiEmail(
                                $pdo,
                                (int) $targetEmployee['id'],
                                $row,
                                $formData['item_type'],
                                $formData['message_text'],
                                $formData['instruction_text'],
                                $dueAt
                            );
                            sendDisposisiWhatsApp(
                                $pdo,
                                (int) $targetEmployee['id'],
                                $row,
                                $formData['item_type'],
                                $formData['message_text'],
                                $formData['instruction_text'],
                                $dueAt
                            );
                        } catch (Throwable $e) {
                            // Abaikan error pengiriman notifikasi agar tidak membuat halaman 500
                            error_log("Gagal kirim notif disposisi: " . $e->getMessage());
                        }
                    }
                }

                $update = $pdo->prepare(
                    "UPDATE agendas
                     SET disposition_status = 'approved',
                         disposition_note = :disposition_note,
                         disposition_by = :disposition_by,
                         disposition_at = NOW()
                     WHERE id = :id"
                );
                $update->execute([
                    'disposition_note' => '[' . strtoupper($formData['item_type']) . '] ' . $formData['message_text'],
                    'disposition_by' => (int) currentUser()['id'],
                    'id' => $id,
                ]);
                $pesanFlash = 'Tugas disposisi berhasil ditambahkan!';
                if ($row['status'] !== 'selesai') {
                    $pesanFlash .= ' JANGAN LUPA klik tombol "Tandai Selesai" jika Anda sudah selesai menambahkan tugas.';
                }
                setFlash('success', $pesanFlash);
                logActivity('add_disposisi', "Menambahkan tindak lanjut untuk agenda ID {$id}", 'agenda', $id);
                foreach ($targetEmployees as $targetEmployee) {
                    if ($targetEmployee['id'] !== null) {
                        createNotification((int) $targetEmployee['id'], 'Disposisi Baru', "Anda mendapat " . strtolower($formData['item_type']) . " baru untuk: {$row['title']}", 'dashboard.php');
                    }
                }
                redirect('disposisi_form.php?id=' . $id . '&tab=' . urlencode($formData['item_type']));
            } catch (PDOException $e) {
                error_log('[disposisi_form.php] DB error: ' . $e->getMessage());
                $errors[] = 'Gagal menyimpan data. Silakan coba lagi atau hubungi administrator.';
            }
        }
    }

    if ($action === 'update_inline_message') {
        $itemId = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;
        $newMessage = trim((string) ($_POST['new_message'] ?? ''));
        
        $predefined = ['Ditindaklanjuti', 'Dikoordinasikan', 'Dihadiri'];
        $parts = array_filter(array_map('trim', explode(',', $newMessage)));
        $isValid = true;
        foreach ($parts as $part) {
            if (!in_array($part, $predefined, true)) {
                $isValid = false;
                break;
            }
        }
        
        if ($itemId > 0 && $isValid) {
            $checkItem = $pdo->prepare("SELECT item_type, employee_id, instruction_text, due_at FROM agenda_disposition_items WHERE id = :id AND agenda_id = :agenda_id LIMIT 1");
            $checkItem->execute(['id' => $itemId, 'agenda_id' => $id]);
            $itemRow = $checkItem->fetch();
            
            if ($itemRow) {
                $dbMsg = $newMessage === '' ? 'Ditindaklanjuti' : $newMessage;
                
                $updateItem = $pdo->prepare("UPDATE agenda_disposition_items SET message_text = :msg WHERE id = :id");
                $updateItem->execute(['msg' => $dbMsg, 'id' => $itemId]);
                
                $updateAgenda = $pdo->prepare(
                    "UPDATE agendas
                     SET disposition_status = 'approved',
                         disposition_note = :disposition_note,
                         disposition_by = :disposition_by,
                         disposition_at = NOW()
                     WHERE id = :id"
                );
                $updateAgenda->execute([
                    'disposition_note' => '[' . strtoupper($itemRow['item_type']) . '] ' . $dbMsg,
                    'disposition_by' => (int) currentUser()['id'],
                    'id' => $id,
                ]);
                
                if (!empty($itemRow['employee_id'])) {
                    sendDisposisiEmail(
                        $pdo,
                        (int) $itemRow['employee_id'],
                        $row,
                        $itemRow['item_type'],
                        $dbMsg,
                        $itemRow['instruction_text'],
                        $itemRow['due_at']
                    );
                    sendDisposisiWhatsApp(
                        $pdo,
                        (int) $itemRow['employee_id'],
                        $row,
                        $itemRow['item_type'],
                        $dbMsg,
                        $itemRow['instruction_text'],
                        $itemRow['due_at']
                    );
                }
                
                setFlash('success', 'Pesan tindak lanjut berhasil diperbarui.');
                logActivity('update_disposisi', "Memperbarui pesan tindak lanjut untuk agenda ID {$id}", 'agenda', $id);
            }
        }
        redirect('disposisi_form.php?id=' . $id . '&tab=' . urlencode($tab));
    }

    if ($action === 'batal_tugas') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        if ($itemId > 0) {
            $delStmt = $pdo->prepare("DELETE FROM agenda_disposition_items WHERE id = :id AND agenda_id = :agenda_id");
            $delStmt->execute(['id' => $itemId, 'agenda_id' => $id]);
            
            $checkRemaining = $pdo->prepare("SELECT COUNT(*) FROM agenda_disposition_items WHERE agenda_id = :agenda_id");
            $checkRemaining->execute(['agenda_id' => $id]);
            if ((int)$checkRemaining->fetchColumn() === 0) {
                $updateAgenda = $pdo->prepare("UPDATE agendas SET disposition_status = 'pending', disposition_note = NULL, disposition_by = NULL, disposition_at = NULL WHERE id = :id");
                $updateAgenda->execute(['id' => $id]);
            }
            
            setFlash('success', 'Tugas disposisi berhasil dibatalkan dan dihapus dari sistem.');
            logActivity('delete_disposisi', "Menghapus item disposisi ID {$itemId} pada agenda ID {$id}", 'agenda', $id);
        }
        redirect('disposisi_form.php?id=' . $id . '&tab=' . urlencode($tab));
    }

    if ($action === 'finalize') {
        $note = trim((string) ($_POST['finalize_note'] ?? ''));
        if ($note === '') {
            $note = 'Selesai. Naskah ditindaklanjuti pimpinan dan tidak dapat dilanjutkan kembali.';
        }
        $upd = $pdo->prepare(
            "UPDATE agendas
             SET disposition_status = 'approved',
                 disposition_note = :disposition_note,
                 disposition_by = :disposition_by,
                 disposition_at = NOW(),
                 status = 'selesai'
             WHERE id = :id"
        );
        $upd->execute([
            'disposition_note' => '[SELESAI] ' . $note,
            'disposition_by' => (int) currentUser()['id'],
            'id' => $id,
        ]);

        setFlash('success', 'Naskah berhasil ditandai selesai.');
        logActivity('finalize_agenda', "Menandai agenda ID {$id} sebagai selesai", 'agenda', $id);
        redirect('disposisi.php');
    }

    if ($action === 'reject') {
        $note = trim((string) ($_POST['reject_note'] ?? ''));
        if ($note === '') {
            $note = 'Ditolak oleh pimpinan.';
        }
        $upd = $pdo->prepare(
            "UPDATE agendas
             SET disposition_status = 'rejected',
                 disposition_note = :disposition_note,
                 disposition_by = :disposition_by,
                 disposition_at = NOW()
             WHERE id = :id"
        );
        $upd->execute([
            'disposition_note' => '[DITOLAK] ' . $note,
            'disposition_by' => (int) currentUser()['id'],
            'id' => $id,
        ]);

        setFlash('success', 'Naskah berhasil ditolak.');
        logActivity('reject_agenda', "Menolak agenda ID {$id}", 'agenda', $id);
        redirect('disposisi.php');
    }
}

$employees = $pdo->query("SELECT id, full_name, COALESCE(position_title,'') AS position_title FROM employees WHERE is_active = 1 ORDER BY full_name ASC")->fetchAll();

$countStmt = $pdo->prepare(
    "SELECT
        SUM(CASE WHEN item_type = 'disposisi' THEN 1 ELSE 0 END) AS total_disposisi,
        SUM(CASE WHEN item_type = 'koordinasi' THEN 1 ELSE 0 END) AS total_koordinasi,
        SUM(CASE WHEN item_type = 'arahan' THEN 1 ELSE 0 END) AS total_arahan
     FROM agenda_disposition_items
     WHERE agenda_id = :agenda_id"
);
$countStmt->execute(['agenda_id' => $id]);
$counts = $countStmt->fetch() ?: ['total_disposisi' => 0, 'total_koordinasi' => 0, 'total_arahan' => 0];

$conditions = ["agenda_id = :agenda_id", "item_type = :item_type"];
$params = [
    'agenda_id' => $id,
    'item_type' => $tab,
];
if ($searchTo !== '') {
    $conditions[] = "employee_name LIKE :to";
    $params['to'] = '%' . $searchTo . '%';
}
if ($searchMsg !== '') {
    $conditions[] = "message_text LIKE :msg";
    $params['msg'] = '%' . $searchMsg . '%';
}
if ($searchInst !== '') {
    $conditions[] = "instruction_text LIKE :inst";
    $params['inst'] = '%' . $searchInst . '%';
}
$itemsSql = "SELECT * FROM agenda_disposition_items WHERE " . implode(' AND ', $conditions) . " ORDER BY disposition_at DESC LIMIT {$perPage}";
$itemStmt = $pdo->prepare($itemsSql);
$itemStmt->execute($params);
$items = $itemStmt->fetchAll();

function idDateLabel(?string $value): string
{
    if (!$value) {
        return '-';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return '-';
    }
    return date('l, d M Y \p\u\k\u\l H.i', $ts) . ' WIB';
}

function readStatusBadge(string $status): string
{
    return $status === 'sudah'
        ? '<span class="ui-badge bg-lime-100 text-lime-700">SUDAH</span>'
        : '<span class="ui-badge bg-amber-100 text-amber-700">BELUM</span>';
}

function tabQueryUrl(int $id, string $tab, int $limit): string
{
    return 'disposisi_form.php?id=' . $id . '&tab=' . urlencode($tab) . '&limit=' . $limit;
}

$pageTitle = 'Detail Naskah Masuk';
require_once __DIR__ . '/includes/header.php';
?>

<div x-data="{ openCard: true, activeTab: '<?= e($tab); ?>', showFinishModal: false, showRejectModal: false, isSubmitting: false }">
    <?php $flash = getFlash(); ?>
    <?php if ($flash): ?>
        <div x-data="{ show: true }" x-show="show" x-cloak x-init="setTimeout(() => show = false, 2800)" x-transition class="mb-6 rounded-2xl border px-4 py-3 <?= $flash['type'] === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-700'; ?>">
            <?= e($flash['message']); ?>
        </div>
        <?php if (str_contains($flash['message'], 'JANGAN LUPA')): ?>
            <script>
                // Tampilkan popup peringatan yang tidak bisa dilewatkan
                setTimeout(function() {
                    alert("Tugas disposisi berhasil ditambahkan!\n\nPENTING: Jangan lupa klik tombol 'TANDAI SELESAI' di bagian bawah halaman jika Anda sudah selesai menambahkan semua tugas untuk surat ini.");
                }, 100);
            </script>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Breadcrumb -->
    <nav class="flex text-sm font-medium text-slate-500 dark:text-slate-400 mb-2" aria-label="Breadcrumb">
        <a href="disposisi.php" class="hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">Daftar Disposisi</a>
        <span class="mx-2 text-slate-400">/</span>
        <span class="text-slate-700 dark:text-slate-300" aria-current="page">Detail Naskah Masuk</span>
    </nav>
    
    <?php 
        $totalTugas = (int)($counts['total_disposisi'] ?? 0) + (int)($counts['total_koordinasi'] ?? 0) + (int)($counts['total_arahan'] ?? 0);
        if (($row['status'] ?? '') !== 'selesai' && $totalTugas > 0): 
    ?>
        <div class="mb-5 flex items-start gap-3 rounded-2xl border border-amber-300 bg-amber-50 p-4 shadow-sm ring-1 ring-amber-400/30">
            <div class="rounded-full bg-amber-200/50 p-2 text-amber-600">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/>
                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
            </div>
            <div>
                <h4 class="text-sm font-bold text-amber-900">Jangan Lupa Tandai Selesai!</h4>
                <p class="mt-0.5 text-sm font-medium leading-relaxed text-amber-800">
                    Anda sudah menambahkan <strong><?= $totalTugas; ?> tugas tindak lanjut</strong>. Jika sudah tidak ada tugas tambahan, 
                    silakan klik tombol <span class="rounded bg-amber-200 px-1.5 py-0.5 font-bold text-amber-900">Tandai Selesai</span> di bagian bawah agar proses disposisi surat ini selesai.
                </p>
            </div>
        </div>
    <?php endif; ?>

    <nav aria-label="Breadcrumb" class="mb-3">
        <ol class="flex flex-wrap items-center gap-1.5 text-xs font-semibold text-slate-500 dark:text-slate-400">
            <li><a href="dashboard.php" class="hover:text-teal-600">Dashboard</a></li>
            <li aria-hidden="true" class="text-slate-300">/</li>
            <li><a href="disposisi.php" class="hover:text-teal-600">Disposisi</a></li>
            <li aria-hidden="true" class="text-slate-300">/</li>
            <li class="text-slate-700 dark:text-slate-200" aria-current="page">Detail Naskah Masuk</li>
        </ol>
    </nav>
    <section class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-extrabold text-slate-900 dark:text-slate-100">Detail Naskah Masuk</h1>
        <div class="flex flex-wrap items-center gap-3">

            <?php if (isAdmin() || isSekretaris() || isPimpinan()): ?>
                <a href="agenda_form.php?id=<?= (int) $id; ?>&return_to=disposisi.php" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-teal-500 to-teal-600 px-4 py-2.5 text-xs font-bold uppercase tracking-wider text-white shadow-md shadow-teal-200 hover:from-teal-600 hover:to-teal-700">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                        <rect x="3" y="4" width="18" height="18" rx="2"></rect>
                        <line x1="12" y1="8" x2="12" y2="16"></line>
                        <line x1="8" y1="12" x2="16" y2="12"></line>
                    </svg>
                    Agendakan
                </a>
            <?php endif; ?>
            <a href="disposisi.php" class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-slate-500 to-slate-600 pl-3 pr-5 py-2.5 text-xs font-bold uppercase tracking-wider text-white shadow-md shadow-slate-200 hover:from-slate-600 hover:to-slate-700">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                    <path d="m15 18-6-6 6-6"></path>
                </svg>
                Kembali
            </a>
        </div>
    </section>

    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
        <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400">Naskah Masuk dari:</h3>
        <div class="mt-4 flex items-center gap-4">
            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-emerald-400 text-lg font-extrabold text-white"><?= e(strtoupper(substr((string) ($row['counterparty'] ?: 'N'), 0, 1))); ?></span>
            <div>
                <p class="text-lg font-bold text-slate-800 sm:text-xl"><?= e((string) ($row['counterparty'] ?: '-')); ?></p>
                <p class="text-xs font-medium text-slate-400"><?= e(($row['direction'] ?? '') === 'masuk' ? 'Surat Masuk' : 'Surat Keluar'); ?></p>
            </div>
        </div>

        <div class="mt-6 grid gap-6 rounded-2xl border border-slate-200 bg-slate-50 p-5 sm:grid-cols-2">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Nomor Referensi</p>
                <p class="mt-2 inline-flex rounded-full bg-indigo-50 border border-indigo-200 px-3 py-1.5 text-xs font-bold uppercase text-indigo-700"><?= e((string) (($row['reference_number'] ?: 'TIDAK ADA'))); ?></p>
            </div>
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Tanggal Naskah</p>
                <p class="mt-2 text-sm font-semibold text-slate-850 sm:text-base"><?= e(date('d F Y', strtotime((string) (($row['letter_date'] ?: $row['agenda_date']) ?: date('Y-m-d'))))); ?></p>
            </div>
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Hal</p>
                <p class="mt-2 text-sm font-semibold leading-relaxed text-slate-850 sm:text-base"><?= e((string) ($row['title'] ?? '-')); ?></p>
            </div>
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Isi Ringkas</p>
                <p class="mt-2 text-sm font-medium leading-relaxed text-slate-600 sm:text-base"><?= e((string) (($row['description'] ?: $row['notes']) ?: $row['title'])); ?></p>
            </div>
        </div>

        <?php if ($errors): ?>
            <div class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                <?php foreach ($errors as $error): ?>
                    <p>- <?= e($error); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if (!empty($row['attachment'])): ?>
    <section class="mt-6 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-xl font-extrabold text-slate-900">Preview Surat</h3>
            <a href="file_preview.php?id=<?= (int) $row['id']; ?>&return_to=disposisi_form.php&embed=0" target="_blank" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Buka di Tab Baru</a>
        </div>
        <div class="overflow-hidden rounded-xl border border-slate-200">
            <iframe src="file_preview.php?id=<?= (int) $row['id']; ?>&return_to=disposisi_form.php&embed=1" title="Preview Surat" class="h-[75vh] w-full"></iframe>
        </div>
    </section>
    <?php endif; ?>

    <section x-show="openCard" x-cloak x-transition class="mt-6 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <h3 class="text-xl font-extrabold text-slate-900">Tindak Lanjut</h3>
            <div class="flex items-center gap-2">
                <?php if (isAdmin()): ?>
                    <a href="master_pegawai.php" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Kelola Pegawai</a>
                <?php endif; ?>

            </div>
        </div>

        <div class="grid grid-cols-1 gap-3 border-b border-slate-200 pb-2 text-sm font-semibold text-slate-500 sm:grid-cols-3">
            <a href="<?= e(tabQueryUrl($id, 'disposisi', $perPage)); ?>" class="rounded-t-xl px-4 py-3 text-center <?= $tab === 'disposisi' ? 'border-b-2 border-indigo-500 text-indigo-600 font-bold' : 'text-slate-500 hover:text-slate-800'; ?>">Disposisi (<?= (int) ($counts['total_disposisi'] ?? 0); ?>)</a>
            <a href="<?= e(tabQueryUrl($id, 'koordinasi', $perPage)); ?>" class="rounded-t-xl px-4 py-3 text-center <?= $tab === 'koordinasi' ? 'border-b-2 border-indigo-500 text-indigo-600 font-bold' : 'text-slate-500 hover:text-slate-800'; ?>">Koordinasi (<?= (int) ($counts['total_koordinasi'] ?? 0); ?>)</a>
            <a href="<?= e(tabQueryUrl($id, 'arahan', $perPage)); ?>" class="rounded-t-xl px-4 py-3 text-center <?= $tab === 'arahan' ? 'border-b-2 border-indigo-500 text-indigo-600 font-bold' : 'text-slate-500 hover:text-slate-800'; ?>">Arahan (<?= (int) ($counts['total_arahan'] ?? 0); ?>)</a>
        </div>

        <!-- Elegant Premium Search & Filter Panel -->
        <div class="mt-5 rounded-2xl border border-slate-200/60 bg-slate-50/50 p-4">
            <form method="get" class="grid gap-4 sm:grid-cols-12 items-end">
                <input type="hidden" name="id" value="<?= (int) $id; ?>">
                <input type="hidden" name="tab" value="<?= e($tab); ?>">
                
                <div class="sm:col-span-3">
                    <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-450">Kepada</label>
                    <input type="text" name="to" value="<?= e($searchTo); ?>" placeholder="Nama pegawai..." class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                </div>
                
                <div class="sm:col-span-3">
                    <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-450">Pesan <?= ucfirst($tab); ?></label>
                    <input type="text" name="msg" value="<?= e($searchMsg); ?>" placeholder="Isi pesan..." class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                </div>
                
                <div class="sm:col-span-3">
                    <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-450">Instruksi</label>
                    <input type="text" name="inst" value="<?= e($searchInst); ?>" placeholder="Instruksi tambahan..." class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                </div>
                
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-450">Limit</label>
                    <select name="limit" onchange="this.form.submit()" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700">
                        <option value="10" <?= $perPage === 10 ? 'selected' : ''; ?>>10</option>
                        <option value="25" <?= $perPage === 25 ? 'selected' : ''; ?>>25</option>
                        <option value="50" <?= $perPage === 50 ? 'selected' : ''; ?>>50</option>
                    </select>
                </div>
                
                <div class="sm:col-span-1 flex gap-1">
                    <button type="submit" class="w-full rounded-xl bg-indigo-600 px-3 py-2.5 text-xs font-extrabold uppercase tracking-wide text-white hover:bg-indigo-700 transition">Cari</button>
                    <?php if ($searchTo !== '' || $searchMsg !== '' || $searchInst !== ''): ?>
                        <a href="disposisi_form.php?id=<?= $id; ?>&tab=<?= e($tab); ?>&limit=<?= (int)$perPage; ?>" class="flex items-center justify-center rounded-xl border border-slate-250 bg-white px-3 py-2.5 text-xs font-bold text-slate-500 hover:bg-slate-100" title="Reset">✕</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="mt-6 overflow-x-auto">
            <table class="min-w-full table-fixed text-sm leading-6">
                <thead class="bg-slate-100 text-slate-600">
                    <tr>
                        <th class="w-40 px-4 py-4 text-left font-bold">DILAKUKAN OLEH</th>
                        <th class="w-48 px-4 py-4 text-left font-bold">KEPADA</th>
                        <th class="w-64 px-4 py-4 text-left font-bold">PESAN <?= strtoupper(e($tab)); ?></th>
                        <th class="w-52 px-4 py-4 text-left font-bold">INSTRUKSI / PESAN TAMBAHAN</th>
                        <th class="w-40 px-4 py-4 text-left font-bold">TANGGAL <?= strtoupper(e($tab)); ?></th>
                        <th class="w-40 px-4 py-4 text-left font-bold">BATAS WAKTU</th>
                        <th class="w-36 px-4 py-4 text-left font-bold">STATUS BACA</th>
                        <th class="w-16 px-4 py-4 text-center font-bold">#</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">

                    <?php if (!$items): ?>
                        <tr>
                            <td colspan="8" class="px-6 py-10 text-center text-2xl text-slate-400">Tidak ada data yang tersedia pada tabel ini</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($items as $item): ?>
                            <tr class="align-top">
                                <td class="px-4 py-5 text-base text-slate-700"><?= e((string) (currentUser()['full_name'] ?? 'Pimpinan')); ?></td>
                                <td class="px-4 py-5 text-base text-slate-700"><?= e((string) $item['employee_name']); ?></td>
                                <td class="px-4 py-5">
                                    <?php 
                                    $msgParts = array_filter(array_map('trim', explode(',', (string)($item['message_text'] ?? ''))));
                                    if ($msgParts): ?>
                                        <div class="flex flex-wrap gap-1.5">
                                            <?php foreach ($msgParts as $msgPart): ?>
                                                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 border border-emerald-200 px-3 py-1 text-xs font-semibold text-emerald-800">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg>
                                                    <?= e($msgPart); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-xs text-slate-400">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-5">
                                    <?php if (!empty($item['instruction_text'])): ?>
                                        <p class="text-base text-slate-700"><?= nl2br(e((string) $item['instruction_text'])); ?></p>
                                    <?php else: ?>
                                        <span class="ui-badge bg-slate-400 text-white">TIDAK ADA</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-5 text-base text-slate-700"><?= e(idDateLabel($item['disposition_at'] ?? null)); ?></td>
                                <td class="px-4 py-5 text-base text-slate-700"><?= e(idDateLabel($item['due_at'] ?? null)); ?></td>
                                <td class="px-4 py-5"><?= readStatusBadge((string) ($item['read_status'] ?? 'belum')); ?></td>
                                <td class="px-4 py-5 text-center">
                                    <form action="disposisi_form.php?id=<?= (int) $id; ?>&tab=<?= urlencode($tab); ?>" method="post" class="inline-block" onsubmit="return confirm('Yakin ingin membatalkan dan menghapus tugas disposisi ini ke pegawai bersangkutan? (Catatan: email yang sudah terkirim tidak dapat ditarik kembali)');">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="batal_tugas">
                                        <input type="hidden" name="item_id" value="<?= (int) $item['id']; ?>">
                                        <button type="submit" class="text-rose-500 hover:text-rose-700 transition-colors" title="Hapus Tugas">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <form method="post" class="mt-8 rounded-2xl border-2 border-indigo-200 bg-indigo-50/40 p-5" id="disposition-form" @submit="isSubmitting = true">
            <?= csrf_field(); ?>
            <input type="hidden" name="id" value="<?= (int) $id; ?>">
            <input type="hidden" name="action" value="add_item">
            <input type="hidden" name="item_type" value="<?= e($tab); ?>">

            <div class="mb-4 flex items-center gap-2 border-b border-indigo-200 pb-4">
                <div class="flex h-8 w-8 items-center justify-center rounded-xl bg-indigo-600 text-white">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </div>
                <h4 class="text-sm font-bold text-indigo-800 uppercase tracking-wide">Tambah <?= ucfirst($tab); ?> Baru</h4>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-slate-700">Kepada (Pegawai) <span class="text-red-500">*</span></label>
                    <input list="employees-list" name="employee_name" value="<?= e($formData['employee_name']); ?>" placeholder="Cari pegawai untuk disposisi" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100">
                    <datalist id="employees-list">
                        <option value="Semua Pegawai">Kirim ke Seluruh Pegawai Aktif</option>
                        <?php foreach ($employees as $employee): ?>
                            <option value="<?= e((string) $employee['full_name']); ?>"><?= e((string) $employee['position_title']); ?></option>
                        <?php endforeach; ?>
                    </datalist>
                    <input type="hidden" name="employee_id" value="<?= e($formData['employee_id']); ?>">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-slate-700">Batas Waktu</label>
                    <input type="datetime-local" name="due_at" value="<?= e($formData['due_at']); ?>" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100">
                </div>
                <div>
                    <label class="mb-2 block text-sm font-semibold text-slate-700">
                        Pesan <?= ucfirst($tab); ?> <span class="text-red-500">*</span>
                        <span class="ml-1 font-normal text-slate-400">(pilih satu atau lebih)</span>
                    </label>
                    <div class="space-y-2">
                        <?php 
                        $predefinedMessages = ['Ditindaklanjuti', 'Dikoordinasikan', 'Dihadiri'];
                        $currentMessage = $formData['message_text'] ?: '';
                        $selectedOptions = array_filter(array_map('trim', explode(',', $currentMessage)));
                        foreach ($predefinedMessages as $option): 
                            $isChecked = in_array($option, $selectedOptions, true);
                        ?>
                            <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 transition hover:border-indigo-300 hover:bg-indigo-50/50 has-[:checked]:border-indigo-400 has-[:checked]:bg-indigo-50">
                                <input type="checkbox" name="message_options[]" value="<?= e($option); ?>"
                                       <?= $isChecked ? 'checked' : ''; ?>
                                       class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                <span class="text-sm font-semibold text-slate-700"><?= e($option); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-slate-700">Instruksi / Pesan Tambahan</label>
                    <textarea name="instruction_text" rows="4" placeholder="Tulis instruksi atau catatan tambahan untuk pegawai..." class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100"><?= e($formData['instruction_text']); ?></textarea>
                </div>
            </div>
            <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-indigo-200 pt-4">
                <button type="submit" :disabled="isSubmitting" :class="isSubmitting ? 'opacity-70 cursor-not-allowed' : ''" class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-indigo-700 transition">
                    <svg x-show="!isSubmitting" xmlns="http://www.w3.org/2000/svg" class="mr-1.5 inline h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg>
                    <span x-show="!isSubmitting">Simpan <?= ucfirst($tab); ?></span>
                    <span x-show="isSubmitting" x-cloak>Menyimpan...</span>
                </button>
                <button type="button" @click="showFinishModal = true" class="rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-emerald-700 transition">
                    <svg xmlns="http://www.w3.org/2000/svg" class="mr-1.5 inline h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m22 2-7 20-4-9-9-4 20-7z"/></svg>
                    Tandai Selesai
                </button>
                <button type="button" @click="showRejectModal = true" class="rounded-xl bg-rose-500 px-5 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-rose-600 transition">
                    <svg xmlns="http://www.w3.org/2000/svg" class="mr-1.5 inline h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    Tolak Naskah
                </button>
            </div>
        </form>
    </section>

    <div x-show="showFinishModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
        <div @click.outside="showFinishModal = false" class="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl">
            <div class="mb-4 flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-amber-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="m22 2-7 20-4-9-9-4 20-7z"></path>
                </svg>
                <h3 class="text-lg font-bold text-slate-800">Selesaikan Naskah</h3>
            </div>
            <p class="text-sm font-medium text-slate-600 leading-relaxed">Anda akan menyelesaikan naskah dengan nomor:<br><span class="font-bold text-slate-800"><?= e((string) ($row['reference_number'] ?: '-')); ?></span></p>
            <div class="mt-4 rounded-2xl border border-amber-250 bg-amber-50 p-4 text-center">
                <p class="text-xs font-bold uppercase tracking-wider text-amber-800">Perhatian</p>
                <p class="mt-1 text-xs text-amber-700 font-medium leading-relaxed">Apabila naskah ini ditindak selesai, maka naskah ini tidak dapat dilanjutkan.</p>
            </div>
            <form method="post" class="mt-5 flex justify-end gap-2" @submit="isSubmitting = true">
                <?= csrf_field(); ?>
                <input type="hidden" name="id" value="<?= (int) $id; ?>">
                <input type="hidden" name="action" value="finalize">
                <input type="hidden" name="finalize_note" value="">
                <button type="submit" :disabled="isSubmitting" :class="isSubmitting ? 'opacity-70 cursor-not-allowed' : ''" class="rounded-xl bg-lime-500 px-4 py-2.5 text-xs font-extrabold uppercase tracking-wide text-white hover:bg-lime-650 transition">
                    <span x-show="!isSubmitting">Ya, Selesai</span>
                    <span x-show="isSubmitting" x-cloak>Menyimpan...</span>
                </button>
                <button type="button" @click="showFinishModal = false" class="rounded-xl bg-slate-500 px-4 py-2.5 text-xs font-extrabold uppercase tracking-wide text-white hover:bg-slate-600">Batal</button>
            </form>
        </div>
    </div>

    <div x-show="showRejectModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
        <div @click.outside="showRejectModal = false" class="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl">
            <div class="mb-4 flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-rose-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
                </svg>
                <h3 class="text-lg font-bold text-slate-800">Tolak Naskah</h3>
            </div>
            <p class="text-sm font-medium text-slate-600 leading-relaxed">Anda akan menolak naskah dengan nomor:<br><span class="font-bold text-slate-800"><?= e((string) ($row['reference_number'] ?: '-')); ?></span></p>
            <form method="post" class="mt-4" @submit="isSubmitting = true">
                <?= csrf_field(); ?>
                <input type="hidden" name="id" value="<?= (int) $id; ?>">
                <input type="hidden" name="action" value="reject">
                <div class="mb-4 mt-2">
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Alasan Penolakan / Masukan <span class="text-rose-500">*</span></label>
                    <textarea name="reject_note" rows="3" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" placeholder="Masukkan alasan atau masukan kenapa surat ini ditolak..." required></textarea>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="submit" :disabled="isSubmitting" :class="isSubmitting ? 'opacity-70 cursor-not-allowed' : ''" class="rounded-xl bg-rose-500 px-4 py-2.5 text-xs font-extrabold uppercase tracking-wide text-white hover:bg-rose-650 transition">
                        <span x-show="!isSubmitting">Ya, Tolak</span>
                        <span x-show="isSubmitting" x-cloak>Mengirim...</span>
                    </button>
                    <button type="button" @click="showRejectModal = false" class="rounded-xl bg-slate-500 px-4 py-2.5 text-xs font-extrabold uppercase tracking-wide text-white hover:bg-slate-600">Batal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
