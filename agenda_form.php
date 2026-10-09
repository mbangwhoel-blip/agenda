<?php

declare(strict_types=1);

// === DEBUG HOSTING (sementara) ===
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
set_exception_handler(function (\Throwable $e): void {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "TERJADI ERROR (debug hosting):\n\n";
    echo "Pesan   : " . $e->getMessage() . "\n";
    echo "File    : " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Kode    : " . $e->getCode() . "\n\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
    exit;
});
register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo "\n\nFATAL SHUTDOWN:\n" . $err['message'] . "\n" . $err['file'] . ":" . $err['line'] . "\n";
    }
});
// === END DEBUG ===

require_once __DIR__ . '/app/helpers.php';

/**
 * Konversi input tanggal pelaksanaan dari user ke format DB (Y-m-d).
 * Menerima dd/mm/yyyy (utama), d/m/yyyy, dan fallback yyyy-mm-dd.
 * Return: string Y-m-d, null (kosong), atau false (format tidak valid).
 */
function agendaFormNormalizeDate(string $value): string|false|null
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    // dd/mm/yyyy (format form)
    if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})$#', $value, $m)) {
        $d = (int) $m[1];
        $mo = (int) $m[2];
        $y = (int) $m[3];
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : false;
    }
    // yyyy-mm-dd (fallback, mis. dari parameter URL)
    if (preg_match('#^(\d{4})[/\-.](\d{1,2})[/\-.](\d{1,2})$#', $value, $m)) {
        $y = (int) $m[1];
        $mo = (int) $m[2];
        $d = (int) $m[3];
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : false;
    }
    return false;
}

/**
 * Tampilkan tanggal (Y-m-d dari DB/default) sebagai dd/mm/yyyy di input form.
 * Input mentah dd/mm/yyyy dari POST yang gagal validasi dikembalikan apa adanya
 * supaya user tidak kehilangan ketikannya.
 */
function agendaFormYmdToDmy(string $value): string
{
    $value = trim($value);
    if ($value === '' || $value === '0000-00-00') {
        return '';
    }
    if (preg_match('#^\d{1,2}/\d{1,2}/\d{4}$#', $value)) {
        return $value;
    }
    $ts = strtotime($value);
    return $ts !== false ? date('d/m/Y', $ts) : $value;
}

$pdo = getPdo();
requireLogin();
requireRole(['admin', 'sekretaris', 'pimpinan', 'arsiparis']);
$authUser = currentUser();

if (isPimpinan()) {
    setFlash('error', 'Akses ditolak. Pimpinan tidak diperkenankan menambah atau mengubah agenda.');
    redirect('dashboard.php');
}

$returnTo = $_GET['return_to'] ?? ($_POST['return_to'] ?? 'agenda.php');
if (!in_array($returnTo, ['agenda.php', 'agenda_zoom.php', 'agenda_kegiatan.php', 'dashboard.php', 'index.php', 'calendar.php'], true)) {
    $returnTo = 'agenda.php';
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$isEdit = $id > 0;

$requestedEntryType = $_GET['entry_type'] ?? ($_POST['entry_type'] ?? '');
$entryType = in_array($requestedEntryType, ['surat', 'kegiatan'], true) ? (string) $requestedEntryType : 'surat';
$agendaModeParam = in_array($_GET['agenda_mode'] ?? ($_POST['agenda_mode'] ?? ''), ['offline', 'zoom', 'hybrid'], true) ? (string) ($_GET['agenda_mode'] ?? $_POST['agenda_mode']) : 'offline';
$requestedAgendaDate = (isset($_GET['agenda_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['agenda_date'])) ? (string) $_GET['agenda_date'] : date('Y-m-d');

$defaultData = [
    'entry_type' => $entryType,
    'agenda_mode' => $agendaModeParam,
    'direction' => in_array($_GET['direction'] ?? ($_POST['direction'] ?? ''), ['masuk', 'keluar'], true) ? (string) ($_GET['direction'] ?? $_POST['direction']) : 'masuk',
    'title' => '',
    'reference_number' => '',
    'letter_date' => $requestedAgendaDate,
    'recorded_date' => date('Y-m-d'),
    'counterparty' => '',
    // Rentang pelaksanaan surat bersifat opsional. Biarkan tanggal mulai kosong
    // pada form baru supaya tampilannya konsisten dengan tanggal selesai.
    // Tanggal surat sendiri tetap memakai $requestedAgendaDate di letter_date.
    'agenda_date' => $entryType === 'surat' ? '' : $requestedAgendaDate,
    'agenda_date_end' => '',
    'status' => 'draft',
    'priority' => 'normal',
    'agenda_time' => '',
    'reminder_h1_enabled' => 1,
    'reminder_h1_at' => '',
    'reminder_h1_sent_at' => '',
    'invitation_template' => '',
    'location' => '',
    'zoom_link' => '',
    'zoom_meeting_id' => '',
    'zoom_passcode' => '',
    'attachment' => '',
    'description' => '',
    'notes' => '',
];

$formData = $defaultData;

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM agendas WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $existing = $stmt->fetch();

    if (!$existing) {
        setFlash('error', 'Data agenda tidak ditemukan.');
        redirect('agenda.php');
    }
    if (isArsiparis() && ($existing['entry_type'] ?? '') !== 'surat') {
        setFlash('error', 'Arsiparis hanya dapat mengelola dokumen surat.');
        redirect('agenda.php');
    }
    if (!canManageLetterArchive((int) ($existing['user_id'] ?? 0))) {
        setFlash('error', 'Anda tidak punya akses mengubah data ini.');
        redirect('agenda.php');
    }

    $formData = array_merge($defaultData, $existing);
}

$isSuratMode = ($formData['entry_type'] ?? 'surat') === 'surat';
$errors = [];
$oldAttachmentPath = $isEdit ? (string) ($formData['attachment'] ?? '') : '';
$uploadedNewAttachment = false;
$gdriveFallbackNotice = '';
$mergeKegiatanId = 0;   // kegiatan existing dengan nomor undangan sama → di-update, bukan ditolak
$linkSourceSuratId = 0; // id surat dengan nomor sama → dijadikan sumber agenda kegiatan

if (!isset($_SESSION['ai_pending_letter_ids']) || !is_array($_SESSION['ai_pending_letter_ids'])) {
    $_SESSION['ai_pending_letter_ids'] = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token keamanan (CSRF) tidak valid. Silakan muat ulang halaman.';
    } else {
        $entryTypePosted = in_array($_POST['entry_type'] ?? '', ['surat', 'kegiatan'], true) ? $_POST['entry_type'] : ($isSuratMode ? 'surat' : 'kegiatan');
        $formData['entry_type'] = $entryTypePosted;
        $isSuratMode = $entryTypePosted === 'surat';

        $formData['attachment'] = (string) ($formData['attachment'] ?? '');

        if ($isSuratMode) {
            $formData['reference_number'] = trim($_POST['reference_number'] ?? '');
            $formData['direction'] = in_array($_POST['direction'] ?? '', ['masuk', 'keluar'], true) ? $_POST['direction'] : 'masuk';
            // Semua input tanggal surat memakai format tampilan dd/mm/yyyy.
            // Normalisasi kembali ke Y-m-d sebelum disimpan.
            $letterDate = agendaFormNormalizeDate((string) ($_POST['letter_date'] ?? ''));
            $recordedDate = agendaFormNormalizeDate((string) ($_POST['recorded_date'] ?? ''));
            if ($letterDate === false) {
                $errors[] = 'Format "Tanggal Surat" tidak valid — gunakan dd/mm/yyyy, contoh: 25/09/2026.';
                $formData['letter_date'] = trim((string) ($_POST['letter_date'] ?? ''));
            } else {
                $formData['letter_date'] = $letterDate ?? '';
            }
            if ($recordedDate === false) {
                $errors[] = 'Format "Tanggal Terima / Catat" tidak valid — gunakan dd/mm/yyyy, contoh: 25/09/2026.';
                $formData['recorded_date'] = trim((string) ($_POST['recorded_date'] ?? ''));
            } else {
                $formData['recorded_date'] = $recordedDate ?? '';
            }
            $formData['counterparty'] = trim($_POST['counterparty'] ?? '');
            $formData['title'] = trim($_POST['title'] ?? '');
            $formData['notes'] = trim($_POST['notes'] ?? '');
            $formData['agenda_mode'] = 'offline';
            $formData['status'] = 'draft';
            $formData['priority'] = 'normal';
            $formData['invitation_template'] = '';
            $formData['reminder_h1_enabled'] = isset($_POST['reminder_h1_enabled']) ? 1 : 0;
            $formData['reminder_h1_at'] = null;
            $formData['reminder_h1_sent_at'] = null;
            $formData['description'] = $formData['notes'];
            $formData['agenda_time'] = '';
            $formData['location'] = 'Sesuai surat';
            $formData['zoom_link'] = '';
            $formData['zoom_meeting_id'] = '';
            // Tanggal pelaksanaan: input form memakai dd/mm/yyyy — normalisasi
            // ke Y-m-d untuk penyimpanan. false = format salah → error.
            $agdStart = agendaFormNormalizeDate((string) ($_POST['agenda_date'] ?? ''));
            $agdEnd = agendaFormNormalizeDate((string) ($_POST['agenda_date_end'] ?? ''));
            if ($agdStart === false) {
                $errors[] = 'Format "Dari Tanggal (Mulai)" tidak valid — gunakan dd/mm/yyyy, contoh: 25/09/2026.';
                $formData['agenda_date'] = trim((string) ($_POST['agenda_date'] ?? ''));
            } else {
                $formData['agenda_date'] = (string) ($agdStart ?? '');
            }
            if ($agdEnd === false) {
                $errors[] = 'Format "s.d Tanggal (Selesai)" tidak valid — gunakan dd/mm/yyyy, contoh: 30/09/2026.';
                $formData['agenda_date_end'] = trim((string) ($_POST['agenda_date_end'] ?? ''));
            } else {
                $formData['agenda_date_end'] = $agdEnd;
            }

            if (!empty($formData['agenda_date']) && !empty($formData['agenda_date_end'])
                && preg_match('#^\d{4}-\d{2}-\d{2}$#', (string) $formData['agenda_date'])
                && preg_match('#^\d{4}-\d{2}-\d{2}$#', (string) $formData['agenda_date_end'])
                && $formData['agenda_date_end'] < $formData['agenda_date']) {
                $errors[] = 'Tanggal selesai pelaksanaan kegiatan tidak boleh lebih awal dari tanggal mulai.';
            }

            if ($formData['reference_number'] === '') {
                $errors[] = 'Nomor surat wajib diisi.';
            } else {
                $checkSql = "SELECT COUNT(*) FROM agendas WHERE reference_number = :ref AND entry_type = 'surat'";
                $checkParams = [
                    'ref' => $formData['reference_number']
                ];
                if ($isEdit) {
                    $checkSql .= " AND id != :id";
                    $checkParams['id'] = $id;
                }
                $checkStmt = $pdo->prepare($checkSql);
                $checkStmt->execute($checkParams);
                if ($checkStmt->fetchColumn() > 0) {
                    $errors[] = "Surat dengan Nomor Surat \"{$formData['reference_number']}\" sudah pernah diinput sebelumnya. Data ditolak/tidak bisa disimpan.";
                }
            }
            if (!in_array($formData['direction'], ['masuk', 'keluar'], true)) {
                $errors[] = 'Jenis surat tidak valid.';
            }
            if ($formData['letter_date'] === '') {
                $errors[] = 'Tanggal surat wajib diisi.';
            }
            if ($formData['recorded_date'] === '') {
                $errors[] = 'Tanggal terima/pencatatan wajib diisi.';
            }
            if ($formData['counterparty'] === '') {
                $errors[] = 'Pengirim / penerima wajib diisi.';
            }
            if ($formData['title'] === '') {
                $errors[] = 'Perihal wajib diisi.';
            }
        } else {
            // Mode Kegiatan (Input Manual / Edit)
            $formData['title'] = trim($_POST['title'] ?? '');
            // Tanggal pelaksanaan: input form memakai dd/mm/yyyy — normalisasi
            // ke Y-m-d untuk penyimpanan. false = format salah → error.
            $agdStart = agendaFormNormalizeDate((string) ($_POST['agenda_date'] ?? ''));
            $agdEnd = agendaFormNormalizeDate((string) ($_POST['agenda_date_end'] ?? ''));
            if ($agdStart === false) {
                $errors[] = 'Format "Dari Tanggal (Mulai)" tidak valid — gunakan dd/mm/yyyy, contoh: 25/09/2026.';
                $formData['agenda_date'] = trim((string) ($_POST['agenda_date'] ?? ''));
            } else {
                $formData['agenda_date'] = (string) $agdStart;
            }
            if ($agdEnd === false) {
                $errors[] = 'Format "s.d Tanggal (Selesai)" tidak valid — gunakan dd/mm/yyyy, contoh: 30/09/2026.';
                $formData['agenda_date_end'] = trim((string) ($_POST['agenda_date_end'] ?? ''));
            } else {
                $formData['agenda_date_end'] = $agdEnd;
            }
            $formData['agenda_time'] = trim($_POST['agenda_time'] ?? '');
            $formData['agenda_mode'] = in_array($_POST['agenda_mode'] ?? '', ['offline', 'zoom', 'hybrid'], true) ? $_POST['agenda_mode'] : 'offline';
            $formData['location'] = trim($_POST['location'] ?? '');
            $formData['counterparty'] = trim($_POST['counterparty'] ?? '');
            $formData['reference_number'] = trim($_POST['reference_number'] ?? '');
            $formData['direction'] = in_array($_POST['direction'] ?? '', ['masuk', 'keluar'], true) ? $_POST['direction'] : 'masuk';
            $formData['status'] = in_array($_POST['status'] ?? '', ['draft', 'proses', 'selesai'], true) ? $_POST['status'] : 'draft';
            $formData['priority'] = in_array($_POST['priority'] ?? '', ['rendah', 'normal', 'tinggi'], true) ? $_POST['priority'] : 'normal';
            $formData['zoom_link'] = trim($_POST['zoom_link'] ?? '');
            $formData['zoom_meeting_id'] = trim($_POST['zoom_meeting_id'] ?? '');
            $formData['zoom_passcode'] = trim($_POST['zoom_passcode'] ?? '');
            $formData['invitation_template'] = trim($_POST['invitation_template'] ?? '');
            $formData['notes'] = trim($_POST['notes'] ?? '');
            $formData['description'] = trim($_POST['description'] ?? '') !== '' ? trim($_POST['description']) : $formData['notes'];
            $formData['reminder_h1_enabled'] = isset($_POST['reminder_h1_enabled']) ? 1 : 0;
            $formData['letter_date'] = !empty($_POST['letter_date']) ? $_POST['letter_date'] : $formData['agenda_date'];
            $formData['recorded_date'] = !empty($_POST['recorded_date']) ? $_POST['recorded_date'] : date('Y-m-d');

            if ($formData['title'] === '') {
                $errors[] = 'Nama kegiatan / perihal wajib diisi.';
            }
            if ($formData['agenda_date'] === '') {
                $errors[] = 'Tanggal mulai kegiatan wajib diisi.';
            }
            if (!empty($formData['agenda_date_end'])
                && preg_match('#^\d{4}-\d{2}-\d{2}$#', (string) $formData['agenda_date'])
                && preg_match('#^\d{4}-\d{2}-\d{2}$#', (string) $formData['agenda_date_end'])
                && $formData['agenda_date_end'] < $formData['agenda_date']) {
                $errors[] = 'Tanggal selesai kegiatan tidak boleh lebih awal dari tanggal mulai.';
            }
            if ($formData['reference_number'] !== '') {
                // Nomor undangan sudah ada? JANGAN tolak. Cari agenda kegiatan
                // existing dengan nomor yang sama dan update agenda itu, supaya
                // user bisa menambahkan link/tautan Zoom secara manual (mis. AI
                // belum berhasil membaca tautan dari surat).
                $checkSql = "SELECT id FROM agendas WHERE reference_number = :ref AND entry_type = 'kegiatan'";
                $checkParams = ['ref' => $formData['reference_number']];
                if ($isEdit) {
                    $checkSql .= " AND id != :id";
                    $checkParams['id'] = $id;
                }
                $checkSql .= " ORDER BY (ai_generated = 1) DESC, id DESC LIMIT 1";
                $checkStmt = $pdo->prepare($checkSql);
                $checkStmt->execute($checkParams);
                $mergeKegiatanId = (int) ($checkStmt->fetchColumn() ?: 0);

                if ($mergeKegiatanId === 0) {
                    // Tidak ada kegiatan, tapi mungkin ada surat dengan nomor
                    // sama → jadikan surat itu sebagai sumber agenda kegiatan.
                    $srcStmt = $pdo->prepare("SELECT id FROM agendas WHERE reference_number = :ref AND entry_type = 'surat' ORDER BY id DESC LIMIT 1");
                    $srcStmt->execute(['ref' => $formData['reference_number']]);
                    $linkSourceSuratId = (int) ($srcStmt->fetchColumn() ?: 0);
                }
            }
        }

        if (isset($_FILES['attachment']) && (int) $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ((int) $_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Upload file lampiran gagal.';
            } else {
                $ext = strtolower(pathinfo((string) $_FILES['attachment']['name'], PATHINFO_EXTENSION));
                $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
                if (!in_array($ext, $allowed, true)) {
                    $errors[] = 'Format file lampiran harus PDF/Gambar (JPG, JPEG, PNG).';
                }
                if ((int) $_FILES['attachment']['size'] > 10 * 1024 * 1024) {
                    $errors[] = 'Ukuran file lampiran maksimal 10MB.';
                }
                $detectedMime = mime_content_type((string) $_FILES['attachment']['tmp_name']);
                $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png'];
                if ($detectedMime === false || !in_array($detectedMime, $allowedMimes, true)) {
                    $errors[] = 'Tipe file tidak diizinkan (terdeteksi: ' . e($detectedMime ?: 'tidak diketahui') . '). Hanya PDF/JPG/PNG.';
                }
            }
        }

        if (!$errors && isset($_FILES['attachment']) && (int) $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $tmpFile = (string) $_FILES['attachment']['tmp_name'];
            // Simpan lampiran ke server lokal. Mengembalikan path relatif 'uploads/xxx'
            // atau null bila gagal memindahkan file.
            $storeAttachmentLocally = static function () use ($tmpFile, $isSuratMode): ?string {
                $uploadDir = __DIR__ . '/uploads';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $ext = strtolower(pathinfo((string) $_FILES['attachment']['name'], PATHINFO_EXTENSION));
                $prefix = $isSuratMode ? 'surat_' : 'kegiatan_';
                $newName = uniqid($prefix, true) . '.' . $ext;
                $targetPath = $uploadDir . '/' . $newName;
                if (!move_uploaded_file($tmpFile, $targetPath)) {
                    return null;
                }
                if (in_array($ext, ['jpg', 'jpeg', 'png'], true) && function_exists('compressImageUpload')) {
                    compressImageUpload($targetPath, $targetPath, 65);
                }
                return 'uploads/' . $newName;
            };

            if (GoogleDriveStorage::isEnabled()) {
                $mime = mime_content_type($tmpFile) ?: 'application/octet-stream';
                $driveFile = GoogleDriveStorage::upload($tmpFile, (string) $_FILES['attachment']['name'], $mime);
                if ($driveFile === null) {
                    // Jangan blok penyimpanan surat saat Drive bermasalah —
                    // fallback ke penyimpanan lokal uploads/ agar operasional jalan,
                    // beri tahu user lewat flash message.
                    $lastError = GoogleDriveStorage::lastError();
                    error_log('[agenda_form] Upload Google Drive gagal, fallback ke lokal: ' . $lastError);
                    $localPath = $storeAttachmentLocally();
                    if ($localPath === null) {
                        $errors[] = 'Gagal memindahkan file upload.';
                    } else {
                        $formData['attachment'] = $localPath;
                        $uploadedNewAttachment = true;
                        $gdriveFallbackNotice = 'Lampiran disimpan di server lokal karena upload Google Drive gagal: ' . $lastError;
                    }
                } else {
                    $formData['attachment'] = $driveFile['reference'];
                    $uploadedNewAttachment = true;
                }
            } else {
                $localPath = $storeAttachmentLocally();
                if ($localPath === null) {
                    $errors[] = 'Gagal memindahkan file upload.';
                } else {
                    $formData['attachment'] = $localPath;
                    $uploadedNewAttachment = true;
                }
            }
        }
    }

    // Mode merge (nomor undangan sudah ada): pertahankan lampiran agenda lama
    // bila user tidak mengunggah lampiran baru, agar tidak terhapus.
    if ($mergeKegiatanId > 0 && !$uploadedNewAttachment) {
        $existingAttStmt = $pdo->prepare("SELECT attachment FROM agendas WHERE id = :id");
        $existingAttStmt->execute(['id' => $mergeKegiatanId]);
        $existingAtt = (string) ($existingAttStmt->fetchColumn() ?: '');
        if ($existingAtt !== '') {
            $formData['attachment'] = $existingAtt;
        }
    }

    if (!$errors) {
        try {
            if ($isEdit) {
                if ($isSuratMode) {
                    $sql = "UPDATE agendas
                            SET direction = :direction, title = :title, reference_number = :reference_number,
                                letter_date = :letter_date, recorded_date = :recorded_date, agenda_date = :agenda_date, agenda_date_end = :agenda_date_end,
                                agenda_time = :agenda_time, location = :location, zoom_link = :zoom_link,
                                zoom_meeting_id = :zoom_meeting_id, zoom_passcode = :zoom_passcode,
                                counterparty = :counterparty, attachment = :attachment, description = :description, notes = :notes
                            WHERE id = :id";
                    $params = [
                        'direction' => $formData['direction'],
                        'title' => $formData['title'],
                        'reference_number' => $formData['reference_number'],
                        'letter_date' => $formData['letter_date'],
                        'recorded_date' => $formData['recorded_date'],
                        'agenda_date' => $formData['agenda_date'],
                        'agenda_date_end' => $formData['agenda_date_end'],
                        'agenda_time' => $formData['agenda_time'] !== '' ? ($formData['agenda_time'] . ':00') : null,
                        'location' => $formData['location'],
                        'zoom_link' => $formData['zoom_link'],
                        'zoom_meeting_id' => $formData['zoom_meeting_id'],
                        'zoom_passcode' => $formData['zoom_passcode'],
                        'counterparty' => $formData['counterparty'],
                        'attachment' => $formData['attachment'],
                        'description' => $formData['description'],
                        'notes' => $formData['notes'],
                        'id' => $id,
                    ];
                } else {
                    $agendaTimeRaw = trim((string) $formData['agenda_time']);
                    $agendaTimeDb = $agendaTimeRaw !== '' ? (strlen($agendaTimeRaw) === 5 ? ($agendaTimeRaw . ':00') : $agendaTimeRaw) : null;
                    $agendaDateTime = $agendaTimeRaw !== '' ? strtotime((string) $formData['agenda_date'] . ' ' . $agendaTimeRaw) : strtotime((string) $formData['agenda_date'] . ' 08:00:00');
                    $reminderH1At = ((int) $formData['reminder_h1_enabled'] === 1 && $agendaDateTime !== false)
                        ? date('Y-m-d H:i:s', strtotime('-1 day', $agendaDateTime))
                        : null;

                    $sql = "UPDATE agendas
                            SET agenda_mode = :agenda_mode,
                                ai_generated = 0,
                                title = :title,
                                reference_number = :reference_number,
                                counterparty = :counterparty,
                                agenda_date = :agenda_date,
                                agenda_date_end = :agenda_date_end,
                                agenda_time = :agenda_time,
                                status = :status,
                                priority = :priority,
                                location = :location,
                                zoom_link = :zoom_link,
                                zoom_meeting_id = :zoom_meeting_id,
                                zoom_passcode = :zoom_passcode,
                                reminder_h1_enabled = :reminder_h1_enabled,
                                reminder_h1_at = :reminder_h1_at,
                                invitation_template = :invitation_template,
                                attachment = :attachment,
                                description = :description,
                                notes = :notes
                            WHERE id = :id";
                    $params = [
                        'agenda_mode' => $formData['agenda_mode'],
                        'title' => $formData['title'],
                        'reference_number' => $formData['reference_number'] !== '' ? $formData['reference_number'] : null,
                        'counterparty' => $formData['counterparty'] !== '' ? $formData['counterparty'] : null,
                        'agenda_date' => $formData['agenda_date'],
                        'agenda_date_end' => $formData['agenda_date_end'],
                        'agenda_time' => $agendaTimeDb,
                        'status' => $formData['status'],
                        'priority' => $formData['priority'],
                        'location' => $formData['location'],
                        'zoom_link' => $formData['zoom_link'],
                        'zoom_meeting_id' => $formData['zoom_meeting_id'],
                        'zoom_passcode' => $formData['zoom_passcode'],
                        'reminder_h1_enabled' => (int) $formData['reminder_h1_enabled'],
                        'reminder_h1_at' => $reminderH1At,
                        'invitation_template' => $formData['invitation_template'],
                        'attachment' => $formData['attachment'],
                        'description' => $formData['description'],
                        'notes' => $formData['notes'],
                        'id' => $id,
                    ];
                }

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $flashMessage = $isSuratMode ? 'Data surat berhasil diperbarui.' : 'Agenda kegiatan berhasil diperbarui.';

                if ($isSuratMode) {
                    $_SESSION['ai_pending_letter_ids'][] = $id;
                    $_SESSION['ai_pending_letter_ids'] = array_values(array_unique(array_map('intval', $_SESSION['ai_pending_letter_ids'])));
                    $flashMessage .= ' AI akan sinkron otomatis di latar belakang.';

                    if (
                        $uploadedNewAttachment
                        && $oldAttachmentPath !== ''
                        && $oldAttachmentPath !== (string) $formData['attachment']
                    ) {
                        deleteAttachmentIfUnused($pdo, $oldAttachmentPath);
                        $flashMessage .= ' Lampiran lama otomatis dihapus dan lampiran baru tersinkron ke agenda terkait.';
                    }
                }
                setFlash('success', $flashMessage . ($gdriveFallbackNotice !== '' ? ' CATATAN: ' . $gdriveFallbackNotice : ''));
                logActivity('update_agenda', "Memperbarui agenda ID {$id} ({$formData['title']})", 'agenda', $id);
            } else {
                // INSERT BARU
                if ($isSuratMode) {
                    $sql = "INSERT INTO agendas
                            (user_id, entry_type, agenda_mode, direction, title, reference_number, letter_date, recorded_date, counterparty, agenda_date, agenda_date_end, agenda_time, location, zoom_link, zoom_meeting_id, zoom_passcode, status, priority, attachment, description, notes)
                            VALUES
                            (:user_id, 'surat', 'offline', :direction, :title, :reference_number, :letter_date, :recorded_date, :counterparty, :agenda_date, :agenda_date_end, :agenda_time, :location, :zoom_link, :zoom_meeting_id, :zoom_passcode, 'draft', 'normal', :attachment, :description, :notes)";
                    $params = [
                        'user_id' => (int) $authUser['id'],
                        'direction' => $formData['direction'],
                        'title' => $formData['title'],
                        'reference_number' => $formData['reference_number'],
                        'letter_date' => $formData['letter_date'],
                        'recorded_date' => $formData['recorded_date'],
                        'counterparty' => $formData['counterparty'],
                        'agenda_date' => $formData['agenda_date'],
                        'agenda_date_end' => $formData['agenda_date_end'],
                        'agenda_time' => $formData['agenda_time'] !== '' ? ($formData['agenda_time'] . ':00') : null,
                        'location' => $formData['location'],
                        'zoom_link' => $formData['zoom_link'],
                        'zoom_meeting_id' => $formData['zoom_meeting_id'],
                        'zoom_passcode' => $formData['zoom_passcode'],
                        'attachment' => $formData['attachment'],
                        'description' => $formData['description'],
                        'notes' => $formData['notes'],
                    ];
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $letterId = (int) $pdo->lastInsertId();

                    $flashMessage = 'Surat berhasil disimpan.';
                    $_SESSION['ai_pending_letter_ids'][] = $letterId;
                    $_SESSION['ai_pending_letter_ids'] = array_values(array_unique(array_map('intval', $_SESSION['ai_pending_letter_ids'])));
                    $flashMessage .= ' AI akan membaca surat dan mengisi agenda kegiatan/zoom secara otomatis di latar belakang.';
                    setFlash('success', $flashMessage . ($gdriveFallbackNotice !== '' ? ' CATATAN: ' . $gdriveFallbackNotice : ''));
                    logActivity('create_agenda', "Menambahkan agenda surat baru ({$formData['title']})", 'agenda', $letterId);
                    
                    // Beri notifikasi ke pimpinan jika ini surat baru
                    $stmtPim = $pdo->query("SELECT id FROM users WHERE role = 'pimpinan'");
                    $jenisSurat = ucfirst($formData['direction']);
                    while ($p = $stmtPim->fetch()) {
                        createNotification((int)$p['id'], "Surat $jenisSurat Baru", "Terdapat surat $jenisSurat baru: {$formData['title']}", 'disposisi.php');
                    }
                } else {
                    // INSERT / MERGE KEGIATAN MANUAL
                    $agendaTimeRaw = trim((string) $formData['agenda_time']);
                    $agendaTimeDb = $agendaTimeRaw !== '' ? (strlen($agendaTimeRaw) === 5 ? ($agendaTimeRaw . ':00') : $agendaTimeRaw) : null;
                    $agendaDateTime = $agendaTimeRaw !== '' ? strtotime((string) $formData['agenda_date'] . ' ' . $agendaTimeRaw) : strtotime((string) $formData['agenda_date'] . ' 08:00:00');
                    $reminderH1At = ((int) $formData['reminder_h1_enabled'] === 1 && $agendaDateTime !== false)
                        ? date('Y-m-d H:i:s', strtotime('-1 day', $agendaDateTime))
                        : null;

                    $params = [
                        'user_id' => (int) $authUser['id'],
                        'agenda_mode' => $formData['agenda_mode'],
                        'agenda_time' => $agendaTimeDb,
                        'reminder_h1_enabled' => (int) $formData['reminder_h1_enabled'],
                        'reminder_h1_at' => $reminderH1At,
                        'invitation_template' => $formData['invitation_template'],
                        'direction' => $formData['direction'],
                        'title' => $formData['title'],
                        'reference_number' => $formData['reference_number'] !== '' ? $formData['reference_number'] : null,
                        'letter_date' => $formData['letter_date'] !== '' ? $formData['letter_date'] : null,
                        'recorded_date' => $formData['recorded_date'] !== '' ? $formData['recorded_date'] : date('Y-m-d'),
                        'counterparty' => $formData['counterparty'] !== '' ? $formData['counterparty'] : null,
                        'agenda_date' => $formData['agenda_date'],
                        'agenda_date_end' => $formData['agenda_date_end'],
                        'status' => $formData['status'],
                        'priority' => $formData['priority'],
                        'location' => $formData['location'],
                        'zoom_link' => $formData['zoom_link'],
                        'zoom_meeting_id' => $formData['zoom_meeting_id'],
                        'zoom_passcode' => $formData['zoom_passcode'],
                        'attachment' => $formData['attachment'],
                        'description' => $formData['description'],
                        'notes' => $formData['notes'],
                    ];

                    if ($mergeKegiatanId > 0) {
                        // Nomor undangan sudah ada → UPDATE agenda existing dengan
                        // data yang diisi user (link/tautan Zoom, jadwal, dll),
                        // lalu tandai milik user (ai_generated=0) agar tidak
                        // ditimpa/dihapus oleh AI.
                        $params['id'] = $mergeKegiatanId;
                        $sql = "UPDATE agendas SET
                                    user_id = :user_id,
                                    agenda_mode = :agenda_mode,
                                    ai_generated = 0,
                                    agenda_time = :agenda_time,
                                    reminder_h1_enabled = :reminder_h1_enabled,
                                    reminder_h1_at = :reminder_h1_at,
                                    invitation_template = :invitation_template,
                                    direction = :direction,
                                    title = :title,
                                    reference_number = :reference_number,
                                    letter_date = :letter_date,
                                    recorded_date = :recorded_date,
                                    counterparty = :counterparty,
                                    agenda_date = :agenda_date,
                                    agenda_date_end = :agenda_date_end,
                                    status = :status,
                                    priority = :priority,
                                    location = :location,
                                    zoom_link = :zoom_link,
                                    zoom_meeting_id = :zoom_meeting_id,
                                    zoom_passcode = :zoom_passcode,
                                    attachment = :attachment,
                                    description = :description,
                                    notes = :notes
                                WHERE id = :id";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($params);
                        $kegiatanId = $mergeKegiatanId;

                        setFlash('success', 'Link/tautan Zoom berhasil ditambahkan ke agenda yang sudah ada (nomor undangan sama).' . ($gdriveFallbackNotice !== '' ? ' CATATAN: ' . $gdriveFallbackNotice : ''));
                        logActivity('update_agenda', "Menambahkan/memperbarui Zoom pada agenda kegiatan ID {$kegiatanId} ({$formData['title']})", 'agenda', $kegiatanId);
                    } else {
                        $params['source_agenda_id'] = $linkSourceSuratId > 0 ? $linkSourceSuratId : null;
                        $sql = "INSERT INTO agendas
                                (user_id, entry_type, agenda_mode, source_agenda_id, ai_generated, agenda_time, reminder_h1_enabled, reminder_h1_at, reminder_h1_sent_at, invitation_template, direction, title, reference_number, letter_date, recorded_date, counterparty, agenda_date, agenda_date_end, status, priority, location, zoom_link, zoom_meeting_id, zoom_passcode, attachment, description, notes)
                                VALUES
                                (:user_id, 'kegiatan', :agenda_mode, :source_agenda_id, 0, :agenda_time, :reminder_h1_enabled, :reminder_h1_at, NULL, :invitation_template, :direction, :title, :reference_number, :letter_date, :recorded_date, :counterparty, :agenda_date, :agenda_date_end, :status, :priority, :location, :zoom_link, :zoom_meeting_id, :zoom_passcode, :attachment, :description, :notes)";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($params);
                        $kegiatanId = (int) $pdo->lastInsertId();

                        setFlash('success', 'Agenda kegiatan berhasil disimpan.' . ($gdriveFallbackNotice !== '' ? ' CATATAN: ' . $gdriveFallbackNotice : ''));
                        logActivity('create_kegiatan', "Menambahkan agenda kegiatan baru ({$formData['title']})", 'agenda', $kegiatanId);
                    }
                }
            }

            redirect($returnTo);
        } catch (Throwable $exception) {
            if ($uploadedNewAttachment && !empty($formData['attachment'])) {
                safelyDeleteUploadFile((string) $formData['attachment']);
                $formData['attachment'] = $oldAttachmentPath;
            }
            $errors[] = 'Terjadi kesalahan saat menyimpan data. Detail (debug): ' . $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine();
        }
    }
}

$gdriveEnabled = false;
try {
    $gdriveEnabled = GoogleDriveStorage::isEnabled();
} catch (\Throwable $e) {
    $gdriveEnabled = false;
}
$attachmentLabel = '';
if (!empty($formData['attachment'])) {
    try {
        $attachmentLabel = attachmentStorageLabel((string) $formData['attachment']);
    } catch (\Throwable $e) {
        $attachmentLabel = '';
    }
}

if ($isSuratMode) {
    $pageTitle = $isEdit ? 'Edit Surat' : 'Tambah Surat';
    $pageSubtitle = $isEdit ? 'Ubah informasi surat masuk atau surat keluar.' : 'Form pengisian naskah surat (kegiatan otomatis diekstrak oleh AI dari lampiran).';
} else {
    $pageTitle = $isEdit ? 'Edit Agenda Kegiatan' : 'Tambah Agenda Kegiatan';
    $pageSubtitle = $isEdit ? 'Ubah rincian agenda kegiatan dan jadwal pelaksanaan.' : 'Form pengisian agenda kegiatan secara manual (rentang tanggal, waktu, lokasi, dan zoom).';
}

require_once __DIR__ . '/includes/header.php';
?>

<section class="rounded-3xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-5 sm:p-7 shadow-sm"
         x-data="{
             agendaMode: '<?= e((string) ($formData['agenda_mode'] ?? 'offline')); ?>'
         }">
    
    <!-- Breadcrumb -->
    <nav aria-label="Breadcrumb" class="mb-4">
        <ol class="flex flex-wrap items-center gap-1.5 text-xs font-semibold text-slate-500 dark:text-slate-400">
            <li><a href="dashboard.php" class="hover:text-teal-600">Dashboard</a></li>
            <li aria-hidden="true" class="text-slate-300">/</li>
            <?php if ($isSuratMode): ?>
                <li><a href="agenda.php" class="hover:text-teal-600">Agenda Surat</a></li>
            <?php else: ?>
                <li><a href="agenda_kegiatan.php" class="hover:text-teal-600">Agenda Kegiatan</a></li>
            <?php endif; ?>
            <li aria-hidden="true" class="text-slate-300">/</li>
            <li class="text-slate-700 dark:text-slate-200" aria-current="page"><?= e($pageTitle); ?></li>
        </ol>
    </nav>

    <!-- Header Form -->
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 dark:border-slate-700 pb-4">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-black text-slate-900 dark:text-white"><?= e($pageTitle); ?></h1>
                <?php if (!$isSuratMode): ?>
                    <span class="inline-flex items-center rounded-lg bg-teal-100 px-2.5 py-0.5 text-xs font-bold text-teal-800 dark:bg-teal-900/40 dark:text-teal-300">
                        <?= $isEdit ? 'Edit Kegiatan' : 'Manual'; ?>
                    </span>
                <?php endif; ?>
            </div>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400"><?= e($pageSubtitle); ?></p>
        </div>
        <?php if (!$isEdit): ?>
            <div class="flex items-center gap-2">
                <?php if ($isSuratMode): ?>
                    <a href="agenda_form.php?entry_type=kegiatan&return_to=agenda_kegiatan.php" class="rounded-xl border border-teal-200 bg-teal-50 dark:border-teal-800 dark:bg-teal-950/30 px-3.5 py-2 text-xs font-bold text-teal-700 dark:text-teal-300 hover:bg-teal-100 transition">
                        Ganti ke Input Kegiatan
                    </a>
                <?php else: ?>
                    <a href="agenda_form.php?entry_type=surat&return_to=agenda.php" class="rounded-xl border border-indigo-200 bg-indigo-50 dark:border-indigo-800 dark:bg-indigo-950/30 px-3.5 py-2 text-xs font-bold text-indigo-700 dark:text-indigo-300 hover:bg-indigo-100 transition">
                        Ganti ke Input Surat
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($errors): ?>
        <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-950/30 p-4 text-sm text-red-700 dark:text-red-300">
            <p class="font-bold mb-1">Terdapat kesalahan:</p>
            <?php foreach ($errors as $error): ?>
                <p class="mt-0.5">• <?= str_contains($error, '<a ') ? strip_tags($error, '<a>') : e($error); ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="space-y-6" id="agendaForm">
        <?= csrf_field(); ?>
        <input type="hidden" name="return_to" value="<?= e($returnTo); ?>">
        <input type="hidden" name="entry_type" value="<?= e((string) $formData['entry_type']); ?>">

        <?php if ($isSuratMode): ?>
            <!-- ═════════════════════════════════════════════════════════════════ -->
            <!-- ── MODE SURAT ────────────────────────────────────────────────── -->
            <!-- ═════════════════════════════════════════════════════════════════ -->
            <div class="grid gap-6 md:grid-cols-2">
                <!-- Kolom Kiri -->
                <div class="space-y-4">
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-300">
                            Nomor Surat <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="reference_number"
                               value="<?= e((string) $formData['reference_number']); ?>"
                               required
                               placeholder="Contoh: 005/UM/VIII/2026"
                               class="w-full rounded-xl border border-slate-200 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 px-3.5 py-2.5 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:focus:ring-indigo-900">
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-300">
                                Jenis Surat <span class="text-red-500">*</span>
                            </label>
                            <select name="direction"
                                    class="w-full rounded-xl border border-slate-200 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 px-3.5 py-2.5 text-sm focus:border-indigo-500 focus:outline-none">
                                <option value="masuk" <?= $formData['direction'] === 'masuk' ? 'selected' : ''; ?>>Surat Masuk</option>
                                <option value="keluar" <?= $formData['direction'] === 'keluar' ? 'selected' : ''; ?>>Surat Keluar</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-300">
                                Tanggal Surat <span class="text-red-500">*</span>
                            </label>
                            <div class="relative">
                                <input type="text" name="letter_date"
                                       value="<?= e(agendaFormYmdToDmy((string) ($formData['letter_date'] ?? ''))) ?>"
                                       placeholder="dd/mm/yyyy"
                                       required maxlength="10" inputmode="numeric" autocomplete="off" data-date-mask
                                       class="w-full rounded-xl border border-slate-200 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 px-3.5 py-2.5 pr-10 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:focus:ring-indigo-900">
                                <button type="button" data-datepicker-btn title="Pilih tanggal (kalender)" aria-label="Pilih tanggal surat via kalender"
                                        class="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-slate-400 transition-colors hover:text-indigo-600 dark:hover:text-indigo-300"
                                        tabindex="-1">
                                    <svg class="h-5 w-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                        <line x1="16" y1="2" x2="16" y2="6"></line>
                                        <line x1="8" y1="2" x2="8" y2="6"></line>
                                        <line x1="3" y1="10" x2="21" y2="10"></line>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-300">
                            Tanggal Terima / Catat <span class="text-red-500">*</span>
                        </label>
                        <div class="relative">
                            <input type="text" name="recorded_date"
                                   value="<?= e(agendaFormYmdToDmy((string) ($formData['recorded_date'] ?? ''))) ?>"
                                   placeholder="dd/mm/yyyy"
                                   required maxlength="10" inputmode="numeric" autocomplete="off" data-date-mask
                                   class="w-full rounded-xl border border-slate-200 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 px-3.5 py-2.5 pr-10 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:focus:ring-indigo-900">
                            <button type="button" data-datepicker-btn title="Pilih tanggal (kalender)" aria-label="Pilih tanggal terima atau catat via kalender"
                                    class="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-slate-400 transition-colors hover:text-indigo-600 dark:hover:text-indigo-300"
                                    tabindex="-1">
                                <svg class="h-5 w-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                    <line x1="16" y1="2" x2="16" y2="6"></line>
                                    <line x1="8" y1="2" x2="8" y2="6"></line>
                                    <line x1="3" y1="10" x2="21" y2="10"></line>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Rentang Tanggal Pelaksanaan Kegiatan -->
                    <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50/70 dark:bg-slate-800/60 p-3.5 space-y-2.5">
                        <div class="flex items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-indigo-600 dark:text-indigo-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                            </svg>
                            <span class="text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wide">
                                Tanggal Pelaksanaan Kegiatan <span class="text-xs font-normal lowercase text-slate-400 dark:text-slate-500">(opsional)</span>
                            </span>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-slate-600 dark:text-slate-400">
                                    Dari Tanggal (Mulai) <span class="text-[10px] font-normal text-slate-400">dd/mm/yyyy</span>
                                </label>
                                <div class="relative">
                                    <input type="text" name="agenda_date"
                                           value="<?= e(agendaFormYmdToDmy((string) ($formData['agenda_date'] ?? ''))) ?>"
                                           placeholder="dd/mm/yyyy"
                                           maxlength="10" inputmode="numeric" autocomplete="off" data-date-mask
                                           class="w-full rounded-xl border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-slate-200 px-3 py-2 pr-10 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:focus:ring-indigo-900">
                                    <button type="button" data-datepicker-btn title="Pilih tanggal (kalender)" aria-label="Pilih tanggal mulai via kalender"
                                            class="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-slate-400 transition-colors hover:text-indigo-600 dark:hover:text-indigo-300"
                                            tabindex="-1">
                                        <svg class="h-5 w-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                            <line x1="16" y1="2" x2="16" y2="6"></line>
                                            <line x1="8" y1="2" x2="8" y2="6"></line>
                                            <line x1="3" y1="10" x2="21" y2="10"></line>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-slate-600 dark:text-slate-400">
                                    s.d Tanggal (Selesai) <span class="text-[10px] font-normal text-slate-400">dd/mm/yyyy</span>
                                </label>
                                <div class="relative">
                                    <input type="text" name="agenda_date_end"
                                           value="<?= e(agendaFormYmdToDmy((string) ($formData['agenda_date_end'] ?? ''))) ?>"
                                           placeholder="dd/mm/yyyy"
                                           maxlength="10" inputmode="numeric" autocomplete="off" data-date-mask
                                           class="w-full rounded-xl border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-slate-200 px-3 py-2 pr-10 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:focus:ring-indigo-900">
                                    <button type="button" data-datepicker-btn title="Pilih tanggal (kalender)" aria-label="Pilih tanggal selesai via kalender"
                                            class="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-slate-400 transition-colors hover:text-indigo-600 dark:hover:text-indigo-300"
                                            tabindex="-1">
                                        <svg class="h-5 w-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                            <line x1="16" y1="2" x2="16" y2="6"></line>
                                            <line x1="8" y1="2" x2="8" y2="6"></line>
                                            <line x1="3" y1="10" x2="21" y2="10"></line>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <p class="text-[11px] text-slate-400 dark:text-slate-500 italic">Isi jika surat ini berisi undangan/jadwal kegiatan pelaksanaan. Format tanggal: dd/mm/yyyy.</p>
                    </div>
                </div>

                <!-- Kolom Kanan -->
                <div class="space-y-4">
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-300">
                            Perihal <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="title"
                               value="<?= e((string) $formData['title']); ?>"
                               required
                               placeholder="Perihal atau judul surat"
                               class="w-full rounded-xl border border-slate-200 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 px-3.5 py-2.5 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:focus:ring-indigo-900">
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-300">
                            Pengirim / Penerima <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="counterparty"
                               value="<?= e((string) $formData['counterparty']); ?>"
                               required
                               placeholder="Nama instansi atau pengirim surat"
                               class="w-full rounded-xl border border-slate-200 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 px-3.5 py-2.5 text-sm focus:border-indigo-500 focus:outline-none">
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-300">
                            Keterangan / Catatan
                        </label>
                        <textarea name="notes" rows="3"
                                  placeholder="Catatan tambahan naskah surat..."
                                  class="w-full rounded-xl border border-slate-200 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 px-3.5 py-2.5 text-sm focus:border-indigo-500 focus:outline-none"><?= e((string) $formData['notes']); ?></textarea>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-300">
                            File Lampiran Surat <span class="text-xs font-normal text-slate-400">(PDF / JPG / PNG, maks 10MB)</span>
                        </label>
                        <input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png"
                               class="w-full rounded-xl border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-700/50 dark:text-slate-200 px-3.5 py-2 text-sm">
                        <?php if (!empty($formData['attachment'])): ?>
                            <p class="mt-1 text-xs text-indigo-600 dark:text-indigo-400">
                                File saat ini: <a href="file_preview.php?id=<?= (int)$id; ?>&return_to=agenda_form.php" target="_blank" class="underline font-semibold"><?= e($attachmentLabel); ?></a>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="pt-2">
                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-700 dark:text-slate-300 cursor-pointer">
                            <input type="checkbox" name="reminder_h1_enabled" value="1"
                                   <?= (int) $formData['reminder_h1_enabled'] === 1 ? 'checked' : ''; ?>
                                   class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                            Aktifkan pengingat H-1 (untuk kegiatan otomatis yang diekstrak AI dari surat ini)
                        </label>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- ═════════════════════════════════════════════════════════════════ -->
            <!-- ── MODE KEGIATAN (SIMPEL, BERSIH, TERSTRUKTUR RAPI) ─────────── -->
            <!-- ═════════════════════════════════════════════════════════════════ -->
            <div class="grid gap-6 md:grid-cols-2">
                <!-- Kolom Kiri: Detail Jadwal & Lokasi -->
                <div class="space-y-4">
                    <!-- Judul Kegiatan -->
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-300">
                            Nama / Judul Kegiatan <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="title"
                               value="<?= e((string) $formData['title']); ?>"
                               required
                               placeholder="Contoh: Rapat Koordinasi Program BBLKL..."
                               class="w-full rounded-xl border border-slate-200 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 px-3.5 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100 dark:focus:ring-teal-900">
                    </div>

                    <!-- Rentang Tanggal -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-300">
                                Dari Tanggal (Mulai) <span class="text-red-500">*</span> <span class="text-[10px] font-normal text-slate-400">dd/mm/yyyy</span>
                            </label>
                            <div class="relative">
                                <input type="text" name="agenda_date"
                                       value="<?= e($formData['agenda_date'] ? agendaFormYmdToDmy((string) $formData['agenda_date']) : date('d/m/Y')) ?>"
                                       placeholder="dd/mm/yyyy"
                                       required
                                       maxlength="10" inputmode="numeric" autocomplete="off" data-date-mask
                                       class="w-full rounded-xl border border-slate-200 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 px-3.5 py-2.5 pr-10 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                <button type="button" data-datepicker-btn title="Pilih tanggal (kalender)" aria-label="Pilih tanggal mulai via kalender"
                                        class="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-slate-400 transition-colors hover:text-teal-600 dark:hover:text-teal-300"
                                        tabindex="-1">
                                    <svg class="h-5 w-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                        <line x1="16" y1="2" x2="16" y2="6"></line>
                                        <line x1="8" y1="2" x2="8" y2="6"></line>
                                        <line x1="3" y1="10" x2="21" y2="10"></line>
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-300">
                                s.d Tanggal <span class="text-xs font-normal text-slate-400">(Opsional)</span> <span class="text-[10px] font-normal text-slate-400">dd/mm/yyyy</span>
                            </label>
                            <div class="relative">
                                <input type="text" name="agenda_date_end"
                                       value="<?= e(agendaFormYmdToDmy((string) ($formData['agenda_date_end'] ?? ''))) ?>"
                                       placeholder="Kosongkan jika 1 hari"
                                       maxlength="10" inputmode="numeric" autocomplete="off" data-date-mask
                                       class="w-full rounded-xl border border-slate-200 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 px-3.5 py-2.5 pr-10 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                <button type="button" data-datepicker-btn title="Pilih tanggal (kalender)" aria-label="Pilih tanggal selesai via kalender"
                                        class="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-slate-400 transition-colors hover:text-teal-600 dark:hover:text-teal-300"
                                        tabindex="-1">
                                    <svg class="h-5 w-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                        <line x1="16" y1="2" x2="16" y2="6"></line>
                                        <line x1="8" y1="2" x2="8" y2="6"></line>
                                        <line x1="3" y1="10" x2="21" y2="10"></line>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Jam & Lokasi -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-300">
                                Waktu / Jam Mulai
                            </label>
                            <input type="time" name="agenda_time"
                                   value="<?= e(substr((string) $formData['agenda_time'], 0, 5)); ?>"
                                   class="w-full rounded-xl border border-slate-200 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 px-3.5 py-2.5 text-sm focus:border-teal-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-300">
                                Lokasi / Tempat
                            </label>
                            <input type="text" name="location"
                                   value="<?= e((string) $formData['location']); ?>"
                                   placeholder="Contoh: Ruang Rapat Utama / Hotel..."
                                   class="w-full rounded-xl border border-slate-200 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 px-3.5 py-2.5 text-sm focus:border-teal-500 focus:outline-none">
                        </div>
                    </div>

                    <!-- Penyelenggara & No Surat -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-300">
                                Penyelenggara / Mitra
                            </label>
                            <input type="text" name="counterparty"
                                   value="<?= e((string) $formData['counterparty']); ?>"
                                   placeholder="Contoh: Ditjen Kesmas / Biro Umum"
                                   class="w-full rounded-xl border border-slate-200 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 px-3.5 py-2.5 text-sm focus:border-teal-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-300">
                                Nomor Undangan <span class="text-xs font-normal text-slate-400">(Opsional)</span>
                            </label>
                            <input type="text" name="reference_number"
                                   value="<?= e((string) $formData['reference_number']); ?>"
                                   placeholder="Nomor surat jika ada"
                                   class="w-full rounded-xl border border-slate-200 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 px-3.5 py-2.5 text-sm focus:border-teal-500 focus:outline-none">
                        </div>
                    </div>

                    <!-- Catatan -->
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-300">
                            Catatan / Keterangan Kegiatan
                        </label>
                        <textarea name="notes" rows="3"
                                  placeholder="Deskripsi kegiatan, agenda pembahasan, atau arahan khusus..."
                                  class="w-full rounded-xl border border-slate-200 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 px-3.5 py-2.5 text-sm focus:border-teal-500 focus:outline-none"><?= e((string) $formData['notes']); ?></textarea>
                    </div>
                </div>

                <!-- Kolom Kanan: Mode Pelaksanaan, Zoom & Pengaturan -->
                <div class="space-y-4">
                    <!-- Mode Pelaksanaan (Dropdown bersih tanpa squeeze) -->
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-300">
                            Mode Pelaksanaan <span class="text-red-500">*</span>
                        </label>
                        <select name="agenda_mode" x-model="agendaMode"
                                class="w-full rounded-xl border border-slate-200 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 px-3.5 py-2.5 text-sm font-medium focus:border-teal-500 focus:outline-none">
                            <option value="offline">🏢 Tatap Muka (Offline)</option>
                            <option value="zoom">💻 Zoom / Online Meeting</option>
                            <option value="hybrid">🌐 Hybrid (Tatap Muka & Zoom)</option>
                        </select>
                    </div>

                    <!-- Panel Rincian Zoom (Hanya tampil bila mode zoom atau hybrid) -->
                    <div x-show="agendaMode === 'zoom' || agendaMode === 'hybrid'" x-cloak
                         class="space-y-3 rounded-2xl border border-indigo-200 dark:border-indigo-800 bg-indigo-50/50 dark:bg-indigo-950/20 p-4">
                        <div class="flex items-center justify-between">
                            <h4 class="text-xs font-bold uppercase tracking-wider text-indigo-700 dark:text-indigo-300">Detail Meeting Zoom</h4>
                            <span class="text-[11px] text-indigo-500 font-medium">Online Meeting</span>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Link Zoom</label>
                            <input type="url" name="zoom_link"
                                   value="<?= e((string) $formData['zoom_link']); ?>"
                                   placeholder="https://us02web.zoom.us/j/..."
                                   class="w-full rounded-xl border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-slate-200 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none">
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Meeting ID</label>
                                <input type="text" name="zoom_meeting_id"
                                       value="<?= e((string) $formData['zoom_meeting_id']); ?>"
                                       placeholder="812 3456 7890"
                                       class="w-full rounded-xl border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-slate-200 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Passcode</label>
                                <input type="text" name="zoom_passcode"
                                       value="<?= e((string) $formData['zoom_passcode']); ?>"
                                       placeholder="123456"
                                       class="w-full rounded-xl border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-slate-200 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none">
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Template Undangan / Pesan WA</label>
                            <textarea name="invitation_template" rows="2"
                                      placeholder="Teks undangan yang siap dibagikan..."
                                      class="w-full rounded-xl border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-slate-200 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none"><?= e((string) $formData['invitation_template']); ?></textarea>
                        </div>
                    </div>

                    <!-- Status & Prioritas -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-300">Status Pelaksanaan</label>
                            <select name="status"
                                    class="w-full rounded-xl border border-slate-200 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 px-3.5 py-2.5 text-sm focus:border-teal-500 focus:outline-none">
                                <option value="draft" <?= ($formData['status'] ?? 'draft') === 'draft' ? 'selected' : ''; ?>>Draft / Direncanakan</option>
                                <option value="proses" <?= ($formData['status'] ?? '') === 'proses' ? 'selected' : ''; ?>>Berjalan / Proses</option>
                                <option value="selesai" <?= ($formData['status'] ?? '') === 'selesai' ? 'selected' : ''; ?>>Selesai</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-300">Prioritas</label>
                            <select name="priority"
                                    class="w-full rounded-xl border border-slate-200 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 px-3.5 py-2.5 text-sm focus:border-teal-500 focus:outline-none">
                                <option value="normal" <?= ($formData['priority'] ?? 'normal') === 'normal' ? 'selected' : ''; ?>>Normal</option>
                                <option value="tinggi" <?= ($formData['priority'] ?? '') === 'tinggi' ? 'selected' : ''; ?>>Tinggi (Penting)</option>
                                <option value="rendah" <?= ($formData['priority'] ?? '') === 'rendah' ? 'selected' : ''; ?>>Rendah</option>
                            </select>
                        </div>
                    </div>

                    <!-- Upload File Lampiran / Surat Undangan -->
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-300">
                            Upload Surat Undangan / Rundown <span class="text-xs font-normal text-slate-400">(Opsional, PDF/Gambar)</span>
                        </label>
                        <input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png"
                               class="w-full rounded-xl border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-700/50 dark:text-slate-200 px-3.5 py-2 text-sm">
                        <?php if (!empty($formData['attachment'])): ?>
                            <p class="mt-1 text-xs text-teal-600 dark:text-teal-400">
                                Lampiran saat ini: <a href="file_preview.php?id=<?= (int)$id; ?>&return_to=agenda_form.php" target="_blank" class="underline font-semibold"><?= e($attachmentLabel); ?></a>
                            </p>
                        <?php endif; ?>
                    </div>

                    <!-- Pengingat H-1 -->
                    <div class="pt-1">
                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-700 dark:text-slate-300 cursor-pointer">
                            <input type="checkbox" name="reminder_h1_enabled" value="1"
                                   <?= (int) $formData['reminder_h1_enabled'] === 1 ? 'checked' : ''; ?>
                                   class="rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                            Aktifkan pengingat H-1 otomatis untuk kegiatan ini
                        </label>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Tombol Aksi Bawah (Rata Kanan) -->
        <div class="flex flex-wrap items-center justify-end gap-3 pt-6 border-t border-slate-100 dark:border-slate-700">
            <a href="<?= e($returnTo); ?>"
               class="rounded-xl border border-slate-200 dark:border-slate-600 px-5 py-2.5 text-sm font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition">
                Batal
            </a>
            <button type="submit" id="agendaSubmitBtn"
                    class="rounded-xl <?= $isSuratMode ? 'bg-indigo-600 hover:bg-indigo-700' : 'bg-teal-600 hover:bg-teal-700'; ?> px-6 py-2.5 text-sm font-bold text-white shadow-sm transition">
                <span data-btn-label><?= $isEdit ? 'Simpan Perubahan' : ($isSuratMode ? 'Simpan Surat' : 'Simpan Agenda Kegiatan'); ?></span>
                <span data-btn-loading style="display:none;">Menyimpan...</span>
            </button>
        </div>
    </form>

    <script>
    (function () {
        var form = document.getElementById('agendaForm');
        if (!form) return;
        var submitBtn = document.getElementById('agendaSubmitBtn');
        if (submitBtn) {
            var label = submitBtn.querySelector('[data-btn-label]');
            var loading = submitBtn.querySelector('[data-btn-loading]');
            form.addEventListener('submit', function () {
                submitBtn.disabled = true;
                submitBtn.classList.add('opacity-70', 'cursor-not-allowed');
                if (label) label.style.display = 'none';
                if (loading) loading.style.display = '';
            });
        }
    })();

    // Mask tanggal pelaksanaan: auto-format dd/mm/yyyy saat mengetik.
    // "25092026" otomatis menjadi "25/09/2026".
    document.addEventListener('input', function (ev) {
        var el = ev.target;
        if (!el || !el.getAttribute || el.getAttribute('data-date-mask') === null) return;
        var digits = (el.value || '').replace(/\D/g, '').slice(0, 8);
        var out = digits;
        if (digits.length > 4) {
            out = digits.slice(0, 2) + '/' + digits.slice(2, 4) + '/' + digits.slice(4);
        } else if (digits.length > 2) {
            out = digits.slice(0, 2) + '/' + digits.slice(2);
        }
        if (el.value !== out) el.value = out;
        // Sinkron ke input kalender tersembunyi (kalau ada) supaya picker
        // terbuka di bulan yang benar.
        var hidden = el._dpHidden;
        if (hidden && /^\d{2}\/\d{2}\/\d{4}$/.test(out)) {
            hidden.value = out.slice(6, 10) + '-' + out.slice(3, 5) + '-' + out.slice(0, 2);
        }
    });

    // Date picker: tombol kalender membuka picker native (showPicker) via
    // input type="date" tersembunyi; hasil ditulis kembali sebagai dd/mm/yyyy
    // ke input teks yang terlihat. Fallback: browser lama → fokus ke input teks
    // (tetap bisa mengetik manual dengan mask dd/mm/yyyy).
    (function initDatepickers() {
        function dmyToYmd(v) {
            var m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(v || '');
            return m ? (m[3] + '-' + m[2] + '-' + m[1]) : '';
        }
        function ymdToDmy(v) {
            var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v || '');
            return m ? (m[3] + '/' + m[2] + '/' + m[1]) : '';
        }
        var btns = document.querySelectorAll('[data-datepicker-btn]');
        btns.forEach(function (btn) {
            var wrapper = btn.closest('.relative');
            if (!wrapper) return;
            var textInput = wrapper.querySelector('input[data-date-mask]');
            if (!textInput) return;

            var hidden = document.createElement('input');
            hidden.type = 'date';
            hidden.setAttribute('aria-hidden', 'true');
            hidden.tabIndex = -1;
            hidden.style.position = 'absolute';
            hidden.style.width = '1px';
            hidden.style.height = '1px';
            hidden.style.opacity = '0';
            hidden.style.pointerEvents = 'none';
            hidden.value = dmyToYmd(textInput.value) || new Date().toISOString().slice(0, 10);
            wrapper.appendChild(hidden);
            textInput._dpHidden = hidden;

            hidden.addEventListener('change', function () {
                var dmy = ymdToDmy(hidden.value);
                if (dmy) textInput.value = dmy;
            });

            btn.addEventListener('click', function () {
                hidden.value = dmyToYmd(textInput.value) || new Date().toISOString().slice(0, 10);
                if (typeof hidden.showPicker === 'function') {
                    try { hidden.showPicker(); return; } catch (e) { /* lanjut fallback */ }
                }
                textInput.focus();
            });
        });
    })();
    </script>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
