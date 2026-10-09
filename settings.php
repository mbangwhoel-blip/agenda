<?php
declare(strict_types=1);
// Jangan aktifkan display_errors di produksi; error harus hanya ke log server.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/app/helpers.php';
$pdo = getPdo();
requireRole(['admin']);
$flash = getFlash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Token keamanan tidak valid.');
        redirect('settings.php');
    }

    $action = trim($_POST['action'] ?? 'save');

    if ($action === 'test_email') {
        $testEmailTo = trim($_POST['test_email_to'] ?? '');
        if (!filter_var($testEmailTo, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', 'Format email tujuan tidak valid.');
        } else {
            $result = smtpSendMail($testEmailTo, 'Test Email - Agenda Pimpinan', '<p>Email test berhasil dikirim dari aplikasi Agenda Pimpinan.</p>');
            $errorMessage = 'Gagal mengirim email test. Periksa konfigurasi SMTP.';
            if (isset($_SESSION['smtp_error'])) {
                $errorMessage .= ' Error detail: ' . $_SESSION['smtp_error'];
                unset($_SESSION['smtp_error']);
            }
            setFlash(
                $result ? 'success' : 'error',
                $result
                    ? 'Email test berhasil dikirim ke ' . $testEmailTo
                    : $errorMessage
            );
        }
    } else {
        // Save all settings
        $fields = ['instansi_nama', 'instansi_alamat', 'instansi_telp', 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'smtp_from_name', 'simpeg_api_url', 'simpeg_api_key'];
        foreach ($fields as $field) {
            setAppSetting($field, trim($_POST[$field] ?? ''));
        }
        // Only update password if non-empty
        $smtpPass = trim($_POST['smtp_password'] ?? '');
        if ($smtpPass !== '') {
            setAppSetting('smtp_password', $smtpPass);
        }
        $fonnte = trim($_POST['fonnte_token'] ?? '');
        if ($fonnte !== '') {
            setAppSetting('fonnte_token', $fonnte);
        }
        setFlash('success', 'Pengaturan berhasil disimpan.');
    }
    redirect('settings.php');
}

// Load current settings
$cfg = [
    'instansi_nama'   => getAppSetting('instansi_nama',   'BBLKL Salatiga — Kementerian Kesehatan RI'),
    'instansi_alamat' => getAppSetting('instansi_alamat'),
    'instansi_telp'   => getAppSetting('instansi_telp'),
    'smtp_host'       => getAppSetting('smtp_host',       'smtp.gmail.com'),
    'smtp_port'       => getAppSetting('smtp_port',       '587'),
    'smtp_secure'     => getAppSetting('smtp_secure',     'tls'),
    'smtp_user'       => getAppSetting('smtp_user'),
    'smtp_from_name'  => getAppSetting('smtp_from_name',  'Agenda Pimpinan BBLKL'),
    'fonnte_token'    => getAppSetting('fonnte_token'),
    'secret_key'      => getAppSetting('reminder_secret_key'),
    'simpeg_api_url'  => getAppSetting('simpeg_api_url'),
    'simpeg_api_key'  => getAppSetting('simpeg_api_key'),
];

$pageTitle = 'Pengaturan';
require_once __DIR__ . '/includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-extrabold text-slate-900 dark:text-slate-100">Pengaturan</h1>
    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Konfigurasi sistem, email, dan WhatsApp</p>
</div>

<?php if ($flash): ?>
    <div class="mb-5 rounded-2xl px-5 py-4 text-sm font-semibold
        <?= $flash['type'] === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-rose-50 text-rose-700 border border-rose-200' ?>">
        <?= e($flash['message']) ?>
    </div>
<?php endif; ?>

<form method="POST" action="settings.php" x-data="{ open: 1 }">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">

    <!-- ===================== SECTION 1: Identitas Instansi ===================== -->
    <div class="mb-4 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-700 dark:bg-slate-800">
        <button type="button"
            @click="open = (open === 1 ? 0 : 1)"
            class="flex w-full items-center justify-between text-left">
            <div>
                <h2 class="text-lg font-bold text-slate-900 dark:text-slate-100">Identitas Instansi</h2>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Nama, alamat, dan nomor telepon instansi</p>
            </div>
            <svg xmlns="http://www.w3.org/2000/svg"
                class="h-5 w-5 text-slate-400 transition-transform duration-200"
                :class="{ 'rotate-90': open === 1 }"
                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="9 18 15 12 9 6"></polyline>
            </svg>
        </button>

        <div x-show="open === 1" x-cloak x-transition class="mt-5 space-y-4">
            <div>
                <label class="mb-1 block text-sm font-semibold text-slate-700 dark:text-slate-300" for="instansi_nama">
                    Nama Instansi <span class="text-rose-500">*</span>
                </label>
                <input type="text" id="instansi_nama" name="instansi_nama"
                    required
                    value="<?= e($cfg['instansi_nama']) ?>"
                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-800 focus:border-indigo-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-100">
            </div>
            <div>
                <label class="mb-1 block text-sm font-semibold text-slate-700 dark:text-slate-300" for="instansi_alamat">
                    Alamat
                </label>
                <textarea id="instansi_alamat" name="instansi_alamat" rows="3"
                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-800 focus:border-indigo-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-100"><?= e($cfg['instansi_alamat']) ?></textarea>
            </div>
            <div>
                <label class="mb-1 block text-sm font-semibold text-slate-700 dark:text-slate-300" for="instansi_telp">
                    Telepon
                </label>
                <input type="text" id="instansi_telp" name="instansi_telp"
                    value="<?= e($cfg['instansi_telp']) ?>"
                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-800 focus:border-indigo-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-100">
            </div>
            <div class="flex justify-end pt-2">
                <button type="submit" name="action" value="save"
                    class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-300 transition-colors">
                    Simpan Identitas
                </button>
            </div>
        </div>
    </div>

    <!-- ===================== SECTION 2: Konfigurasi Email SMTP ===================== -->
    <div class="mb-4 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-700 dark:bg-slate-800">
        <button type="button"
            @click="open = (open === 2 ? 0 : 2)"
            class="flex w-full items-center justify-between text-left">
            <div>
                <h2 class="text-lg font-bold text-slate-900 dark:text-slate-100">Konfigurasi Email SMTP</h2>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Pengaturan server email untuk pengiriman notifikasi</p>
            </div>
            <svg xmlns="http://www.w3.org/2000/svg"
                class="h-5 w-5 text-slate-400 transition-transform duration-200"
                :class="{ 'rotate-90': open === 2 }"
                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="9 18 15 12 9 6"></polyline>
            </svg>
        </button>

        <div x-show="open === 2" x-cloak x-transition class="mt-5 space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-slate-700 dark:text-slate-300" for="smtp_host">
                        SMTP Host
                    </label>
                    <input type="text" id="smtp_host" name="smtp_host"
                        value="<?= e($cfg['smtp_host']) ?>"
                        placeholder="smtp.gmail.com"
                        class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-800 focus:border-indigo-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-100">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-slate-700 dark:text-slate-300" for="smtp_port">
                        SMTP Port
                    </label>
                    <input type="text" id="smtp_port" name="smtp_port"
                        value="<?= e($cfg['smtp_port']) ?>"
                        placeholder="587"
                        class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-800 focus:border-indigo-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-100">
                </div>
            </div>
            <div>
                <label class="mb-1 block text-sm font-semibold text-slate-700 dark:text-slate-300" for="smtp_secure">
                    Enkripsi
                </label>
                <select id="smtp_secure" name="smtp_secure"
                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-800 focus:border-indigo-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-100">
                    <option value="tls" <?= $cfg['smtp_secure'] === 'tls' ? 'selected' : '' ?>>TLS (Port 587 — direkomendasikan)</option>
                    <option value="ssl" <?= $cfg['smtp_secure'] === 'ssl' ? 'selected' : '' ?>>SSL (Port 465)</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-semibold text-slate-700 dark:text-slate-300" for="smtp_user">
                    Email Pengirim
                </label>
                <input type="text" id="smtp_user" name="smtp_user"
                    value="<?= e($cfg['smtp_user']) ?>"
                    placeholder="nama@gmail.com"
                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-800 focus:border-indigo-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-100">
            </div>
            <div>
                <label class="mb-1 block text-sm font-semibold text-slate-700 dark:text-slate-300" for="smtp_password">
                    Password SMTP
                </label>
                <input type="password" id="smtp_password" name="smtp_password"
                    autocomplete="new-password"
                    placeholder="••••••••"
                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-800 focus:border-indigo-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-100">
                <p class="mt-1 text-xs text-slate-400">Kosongkan jika tidak ingin mengubah password.</p>
            </div>
            <div>
                <label class="mb-1 block text-sm font-semibold text-slate-700 dark:text-slate-300" for="smtp_from_name">
                    Nama Pengirim
                </label>
                <input type="text" id="smtp_from_name" name="smtp_from_name"
                    value="<?= e($cfg['smtp_from_name']) ?>"
                    placeholder="Agenda Pimpinan BBLKL"
                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-800 focus:border-indigo-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-100">
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4 dark:border-slate-700">
                <div class="flex justify-end">
                    <button type="submit" name="action" value="save"
                        class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-300 transition-colors">
                        Simpan Konfigurasi Email
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ===================== SECTION 3: WhatsApp & Reminder ===================== -->
    <div class="mb-4 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-700 dark:bg-slate-800">
        <button type="button"
            @click="open = (open === 3 ? 0 : 3)"
            class="flex w-full items-center justify-between text-left">
            <div>
                <h2 class="text-lg font-bold text-slate-900 dark:text-slate-100">Konfigurasi WhatsApp &amp; Reminder</h2>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Token Fonnte API dan jadwal pengiriman pengingat otomatis</p>
            </div>
            <svg xmlns="http://www.w3.org/2000/svg"
                class="h-5 w-5 text-slate-400 transition-transform duration-200"
                :class="{ 'rotate-90': open === 3 }"
                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="9 18 15 12 9 6"></polyline>
            </svg>
        </button>

        <div x-show="open === 3" x-cloak x-transition class="mt-5 space-y-4">
            <div>
                <label class="mb-1 block text-sm font-semibold text-slate-700 dark:text-slate-300" for="fonnte_token">
                    Fonnte API Token
                </label>
                <input type="password" id="fonnte_token" name="fonnte_token"
                    autocomplete="new-password"
                    placeholder="••••••••"
                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-800 focus:border-indigo-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-100 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-100">
                <p class="mt-1 text-xs text-slate-400">Kosongkan jika tidak ingin mengubah token.</p>
            </div>

            <!-- Reminder Cron Info Card -->
            <div class="rounded-2xl border border-indigo-100 bg-indigo-50 p-4 dark:border-indigo-900 dark:bg-indigo-950/40">
                <h3 class="mb-2 text-sm font-bold text-indigo-800 dark:text-indigo-300">
                    URL Reminder Cron
                </h3>
                <p class="mb-3 text-xs text-indigo-700 dark:text-indigo-400">
                    Jalankan URL berikut secara terjadwal (misal setiap jam via Task Scheduler atau cron job):
                </p>
                <code class="block break-all rounded-xl border border-indigo-200 bg-white px-4 py-2.5 text-xs font-mono text-indigo-900 dark:border-indigo-800 dark:bg-indigo-950 dark:text-indigo-200">
                    http://<?= e($_SERVER['HTTP_HOST'] ?? 'localhost') ?>/agenda-php/reminder_cron.php?secret_key=<?= e(!empty($cfg['secret_key']) ? substr($cfg['secret_key'], 0, 8) . '...' : '(belum digenerate)') ?>
                </code>

                <div class="mt-4">
                    <h4 class="mb-1.5 text-xs font-bold text-indigo-700 dark:text-indigo-400">Windows Task Scheduler (CMD)</h4>
                    <code class="block break-all rounded-xl border border-indigo-200 bg-white px-4 py-2.5 text-xs font-mono text-slate-700 dark:border-indigo-800 dark:bg-indigo-950 dark:text-slate-300">
                        schtasks /create /tn "AgendaReminder" /tr "curl -s \"http://<?= e($_SERVER['HTTP_HOST'] ?? 'localhost') ?>/agenda-php/reminder_cron.php?secret_key=<?= e($cfg['secret_key'] ?? '') ?>\"" /sc hourly /mo 1 /f
                    </code>
                </div>
            </div>

            <div class="flex justify-end pt-2">
                <button type="submit" name="action" value="save"
                    class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-300 transition-colors">
                    Simpan Konfigurasi WhatsApp
                </button>
            </div>
        </div>
    </div>

    <!-- Save All -->
    <div class="flex flex-wrap items-center justify-between gap-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-800">
        <p class="text-sm text-slate-500 dark:text-slate-400">Simpan semua perubahan pengaturan sekaligus.</p>
        <button type="submit" name="action" value="save"
            class="rounded-xl bg-indigo-600 px-6 py-3 text-sm font-bold text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-300 transition-colors">
            Simpan Semua Pengaturan
        </button>
    </div>
</form>

<!-- Test Email (separate form, outside main form) -->
<div class="mt-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-800">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h3 class="text-sm font-bold text-slate-800 dark:text-slate-200">Uji Pengiriman Email</h3>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                Masukkan alamat email tujuan untuk memverifikasi konfigurasi SMTP.
            </p>
        </div>
        <form method="POST" action="settings.php" class="flex flex-wrap items-center gap-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="test_email">
            <input type="email" name="test_email_to" placeholder="Kirim ke email..." required class="rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-700 dark:text-white">
            <button type="submit"
                class="rounded-xl border border-indigo-200 bg-indigo-50 px-5 py-2 text-sm font-bold text-indigo-700 hover:bg-indigo-100 focus:outline-none focus:ring-2 focus:ring-indigo-300 transition-colors dark:border-indigo-800 dark:bg-indigo-950/40 dark:text-indigo-300 dark:hover:bg-indigo-900/40">
                Test Kirim Email
            </button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
