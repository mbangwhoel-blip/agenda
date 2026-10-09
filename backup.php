<?php

declare(strict_types=1);

require_once __DIR__ . '/app/helpers.php';
// Backup dan restore dapat diakses oleh admin dan sekretaris.
requireRole(['admin', 'sekretaris']);

$pdo = getPdo();

/**
 * Menghapus string literals ('...', "...") dan komentar SQL agar validasi
 * perintah berbahaya tidak memicu false-positive pada teks data pengguna.
 */
function stripSqlStringsAndComments(string $sql): string
{
    $len = strlen($sql);
    $out = '';
    $inString = false;
    $strQ = '';
    $inLineComment = false;
    $inBlockComment = false;

    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($inLineComment) {
            if ($c === "\n") {
                $inLineComment = false;
                $out .= "\n";
            }
            continue;
        }

        if ($inBlockComment) {
            if ($c === '*' && $next === '/') {
                $inBlockComment = false;
                $i++;
            }
            continue;
        }

        if ($inString) {
            if ($c === '\\' && $i + 1 < $len) {
                $i++;
                continue;
            }
            if ($c === $strQ) {
                if ($next === $strQ) {
                    $i++;
                    continue;
                }
                $inString = false;
            }
            continue;
        }

        if ($c === '-' && $next === '-') {
            $inLineComment = true;
            $i++;
            continue;
        }
        if ($c === '#') {
            $inLineComment = true;
            continue;
        }
        if ($c === '/' && $next === '*') {
            // Biarkan MySQL conditional comment /*! ... */ dievaluasi
            if (!($i + 2 < $len && $sql[$i + 2] === '!')) {
                $inBlockComment = true;
                $i++;
                continue;
            }
        }

        if ($c === "'" || $c === '"') {
            $inString = true;
            $strQ = $c;
            continue;
        }

        $out .= $c;
    }

    return $out;
}

/**
 * Memecah konten SQL menjadi query individual dengan menangani kutipan string,
 * komentar awal (leading comments), dan menyaring statement yang diizinkan.
 *
 * @return list<string>
 */
function parseSqlStatements(string $sqlContent): array
{
    $statements = [];
    $buf = '';
    $inString = false;
    $strQ = '';
    $len = strlen($sqlContent);

    for ($i = 0; $i < $len; $i++) {
        $c = $sqlContent[$i];
        if ($inString) {
            $buf .= $c;
            if ($c === '\\' && $i + 1 < $len) {
                $buf .= $sqlContent[++$i];
                continue;
            }
            if ($c === $strQ) {
                if ($i + 1 < $len && $sqlContent[$i + 1] === $strQ) {
                    $buf .= $sqlContent[++$i];
                    continue;
                }
                $inString = false;
            }
            continue;
        }

        if ($c === "'" || $c === '"') {
            $inString = true;
            $strQ = $c;
            $buf .= $c;
            continue;
        }

        if ($c === ';') {
            $stmt = trim($buf);
            $buf = '';
            if ($stmt === '') {
                continue;
            }

            // Bersihkan komentar awal (-- ..., # ..., /* ... */)
            $clean = $stmt;
            while (true) {
                $clean = trim($clean);
                if (str_starts_with($clean, '--')) {
                    $pos = strpos($clean, "\n");
                    $clean = $pos === false ? '' : substr($clean, $pos + 1);
                } elseif (str_starts_with($clean, '#')) {
                    $pos = strpos($clean, "\n");
                    $clean = $pos === false ? '' : substr($clean, $pos + 1);
                } elseif (str_starts_with($clean, '/*')) {
                    if (preg_match('/^\/\*!\d*\s*(.*?)\*\//s', $clean, $m)) {
                        $clean = trim($m[1]) . substr($clean, strlen($m[0]));
                    } else {
                        $pos = strpos($clean, '*/');
                        $clean = $pos === false ? '' : substr($clean, $pos + 2);
                    }
                } else {
                    break;
                }
            }

            if ($clean === '') {
                continue;
            }

            $upper = strtoupper($clean);
            $first = strtok($upper, " \t\n\r(");
            $allowed = ['CREATE', 'INSERT', 'UPDATE', 'DELETE', 'SET', 'LOCK', 'UNLOCK', 'ALTER', 'DROP', 'TRUNCATE'];

            if (!in_array($first, $allowed, true)) {
                // Abaikan statement kontrol transaksi (START TRANSACTION, COMMIT) dan USE
                continue;
            }

            // Tolak keras DROP DATABASE / DROP SCHEMA
            if (preg_match('/^DROP\s+(DATABASE|SCHEMA)\b/i', $clean)) {
                continue;
            }

            $statements[] = $stmt;
        } else {
            $buf .= $c;
        }
    }

    return $statements;
}

// Tangani aksi download backup
if (isset($_GET['action']) && $_GET['action'] === 'download') {
    @set_time_limit(300);
    @ini_set('memory_limit', '256M');

    $tables = [];
    $stmt = $pdo->query('SHOW TABLES');
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = (string) $row[0];
    }

    $sql = "-- Agenda Pimpinan Database Backup\n";
    $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
    $sql .= "SET NAMES utf8mb4;\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n";
    $sql .= "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";

    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
        $row = $stmt->fetch(PDO::FETCH_NUM);
        $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
        $sql .= $row[1] . ";\n\n";

        $dataStmt = $pdo->query("SELECT * FROM `{$table}`");
        $batch = [];
        $keys = null;
        while ($rowItem = $dataStmt->fetch(PDO::FETCH_ASSOC)) {
            if ($keys === null) {
                $keys = array_keys($rowItem);
            }
            $values = array_map(function ($val) use ($pdo) {
                if ($val === null) {
                    return 'NULL';
                }
                return $pdo->quote((string) $val);
            }, array_values($rowItem));
            $batch[] = '(' . implode(', ', $values) . ')';

            if (count($batch) >= 100) {
                $sql .= "INSERT INTO `{$table}` (`" . implode('`, `', $keys) . "`) VALUES\n" . implode(",\n", $batch) . ";\n";
                $batch = [];
            }
        }

        if (!empty($batch) && $keys !== null) {
            $sql .= "INSERT INTO `{$table}` (`" . implode('`, `', $keys) . "`) VALUES\n" . implode(",\n", $batch) . ";\n";
        }
        $sql .= "\n";
    }

    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

    logActivity('backup_db', 'Mengunduh backup database.', 'system', null);
    $user = currentUser();
    $username = $user['username'] ?? 'pengguna';
    auditLogImmutable('db_backup', "Backup database diunduh oleh {$username}.");

    $filename = 'backup_agenda_' . date('Y-m-d_H-i-s') . '.sql';
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    echo $sql;
    exit;
}

// Tangani aksi restore
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['backup_file'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Token keamanan tidak valid. Silakan muat ulang halaman.'];
        redirect('backup.php');
    }

    $file = $_FILES['backup_file'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext === 'sql' || $ext === 'gz') {
            $rawContent = file_get_contents($file['tmp_name']);
            $sqlContent = false;

            if ($rawContent !== false) {
                if ($ext === 'gz') {
                    $decompressed = @gzdecode($rawContent);
                    $sqlContent = $decompressed !== false ? $decompressed : false;
                } else {
                    $sqlContent = $rawContent;
                }
            }

            if ($sqlContent !== false && trim($sqlContent) !== '') {
                // Batasi ukuran SQL setelah dekompresi maks 50MB
                if (strlen($sqlContent) > 50 * 1024 * 1024) {
                    $_SESSION['flash'] = ['type' => 'error', 'message' => 'File backup terlalu besar (maks 50MB).'];
                    redirect('backup.php');
                }

                // Bersihkan teks data dan komentar sebelum validasi regex perintah berbahaya
                $strippedSql = stripSqlStringsAndComments($sqlContent);
                $dangerous = '/\b(LOAD_FILE\s*\(|INTO\s+OUTFILE\b|INTO\s+DUMPFILE\b|GRANT\s+|REVOKE\s+|CREATE\s+USER\b|ALTER\s+USER\b|DROP\s+USER\b|SET\s+PASSWORD\b|SHUTDOWN\b)/i';
                if (preg_match($dangerous, $strippedSql)) {
                    $_SESSION['flash'] = ['type' => 'error', 'message' => 'File backup ditolak: mengandung perintah berbahaya.'];
                    redirect('backup.php');
                }

                try {
                    @set_time_limit(300);
                    @ini_set('memory_limit', '256M');

                    $statements = parseSqlStatements($sqlContent);
                    if (empty($statements)) {
                        throw new RuntimeException('Tidak ditemukan perintah SQL yang valid dalam file backup.');
                    }

                    // Nonaktifkan pemeriksaan foreign key untuk mengizinkan restore tabel dalam urutan apa pun
                    $pdo->exec('SET NAMES utf8mb4');
                    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
                    $pdo->exec('SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO"');

                    $executed = 0;
                    foreach ($statements as $stmt) {
                        $pdo->exec($stmt);
                        $executed++;
                    }

                    // Pastikan transaksi di-commit bila ada transaksi terbuka
                    if ($pdo->inTransaction()) {
                        $pdo->commit();
                    }

                    // Kembalikan pemeriksaan foreign key
                    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

                    // Sinkronisasi skema aplikasi terbaru agar tabel/kolom migrasi selalu lengkap
                    applySchemaUpdates($pdo);

                    auditLogImmutable('db_restore', "Restore DB berhasil. {$executed} statements dieksekusi.");
                    $_SESSION['flash'] = ['type' => 'success', 'message' => "Database berhasil direstore. {$executed} perintah dieksekusi."];
                    logActivity('restore_db', 'Melakukan restore database dari file backup.', 'system', null);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    try {
                        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
                    } catch (Throwable) {
                    }
                    error_log('[backup.php] Restore gagal: ' . $e->getMessage());
                    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Gagal merestore database: ' . $e->getMessage()];
                }
            } else {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Gagal membaca isi file backup atau format file tidak valid.'];
            }
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'File harus berekstensi .sql atau .sql.gz'];
        }
    } else {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Terjadi kesalahan saat mengunggah file.'];
    }
    redirect('backup.php');
}

$flash = getFlash();
$pageTitle = 'Backup & Restore Database';
require_once __DIR__ . '/includes/header.php';
?>
<div x-data="{ showRestoreModal: false }">

<?php if ($flash): ?>
    <div x-data="{ show: true }" x-show="show" x-cloak x-init="setTimeout(() => show = false, 4000)" x-transition class="mb-6 rounded-2xl border px-4 py-3 <?= $flash['type'] === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-700'; ?>">
        <?= e($flash['message']); ?>
    </div>
<?php endif; ?>

<section class="mb-6">
    <h2 class="text-3xl font-extrabold text-slate-900">Backup & Restore Database</h2>
    <p class="mt-2 text-sm text-slate-600">Amankan data aplikasi Anda dengan mengunduh salinan database atau memulihkannya dari file backup.</p>
</section>

<div class="grid gap-6 lg:grid-cols-2">
    <!-- Panel Backup -->
    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm flex flex-col">
        <div class="mb-6 flex-1">
            <h3 class="text-xl font-bold text-slate-900 mb-4 flex items-center gap-2">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-100 text-indigo-600">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                </div>
                Backup Data
            </h3>
            <div class="rounded-2xl bg-indigo-50 border border-indigo-100 p-5 mb-4">
                <p class="text-sm text-indigo-900 leading-relaxed">Fitur ini akan mengekspor seluruh data yang ada saat ini (surat, kegiatan, disposisi, pengguna) ke dalam satu file <code>.sql</code>. Sangat disarankan untuk membackup data secara rutin guna menghindari kehilangan data.</p>
            </div>
        </div>
        <a href="backup.php?action=download" class="inline-flex w-full items-center justify-center gap-3 rounded-2xl bg-indigo-600 px-6 py-4 text-sm font-bold text-white shadow-lg shadow-indigo-200 hover:bg-indigo-700 transition">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
            Download Backup (.sql)
        </a>
    </section>

    <!-- Panel Restore -->
    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm flex flex-col">
        <div class="mb-6 flex-1">
            <h3 class="text-xl font-bold text-slate-900 mb-4 flex items-center gap-2">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-rose-100 text-rose-600">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                </div>
                Restore Data
            </h3>
            <div class="rounded-2xl bg-rose-50 border border-rose-100 p-5 mb-4">
                <p class="text-sm text-rose-900 leading-relaxed font-semibold">Peringatan Keras!</p>
                <p class="text-sm text-rose-800 leading-relaxed mt-1">Melakukan restore akan <strong>MENGHAPUS SEMUA DATA SAAT INI</strong> dan menggantinya dengan data dari file backup. Pastikan file backup yang Anda unggah sudah benar.</p>
            </div>
            
            <form id="restoreForm" method="post" enctype="multipart/form-data" class="flex flex-col gap-3">
                <?= csrf_field(); ?>
                <label class="block text-sm font-semibold text-slate-700">Pilih File Backup (.sql atau .sql.gz)</label>
                <input id="backupFileInput" type="file" name="backup_file" accept=".sql,.gz" required class="block w-full text-sm text-slate-500 file:mr-4 file:py-3 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-rose-50 file:text-rose-700 hover:file:bg-rose-100 cursor-pointer border border-slate-200 rounded-xl focus:outline-none">
            </form>
        </div>
        <button type="button" @click="if (!document.getElementById('backupFileInput').files || document.getElementById('backupFileInput').files.length === 0) { alert('Silakan pilih file backup (.sql atau .sql.gz) terlebih dahulu.'); return; } showRestoreModal = true" class="inline-flex w-full items-center justify-center gap-3 rounded-2xl bg-rose-600 px-6 py-4 text-sm font-bold text-white shadow-lg shadow-rose-200 hover:bg-rose-700 transition">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
            Upload & Restore Data
        </button>
    </section>
</div>

<!-- Modal Konfirmasi -->
<div x-cloak x-show="showRestoreModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-sm">
    <div @click.outside="showRestoreModal = false" class="w-full max-w-md rounded-3xl border border-slate-200 bg-white p-6 shadow-2xl">
        <div class="flex items-center gap-4 mb-4">
            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-rose-100 text-rose-600 shrink-0">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
            </div>
            <h3 class="text-xl font-bold text-slate-900">Konfirmasi Restore</h3>
        </div>
        <p class="text-slate-600 text-sm leading-relaxed mb-6">Apakah Anda sangat yakin ingin merestore database? Tindakan ini akan <strong>menghapus seluruh data yang ada saat ini secara permanen</strong> dan menimpanya dengan file backup yang diunggah.</p>
        <div class="flex items-center justify-end gap-3">
            <button type="button" @click="showRestoreModal = false" class="rounded-xl border border-slate-300 px-5 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50 transition">Batal</button>
            <button type="button" onclick="document.getElementById('restoreForm').submit()" class="rounded-xl bg-rose-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-rose-700 shadow-md shadow-rose-200 transition">Ya, Saya Yakin</button>
        </div>
    </div>
</div>

</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
