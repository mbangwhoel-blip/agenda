<?php

declare(strict_types=1);

/**
 * Versi skema database. Naikkan nilai ini SETIAP KALI ada perubahan struktur
 * (tambah fungsi ensure* baru / ubah definisi tabel). Migrasi hanya dijalankan
 * ulang ketika versi tersimpan di app_settings lebih rendah dari nilai ini.
 */
const APP_SCHEMA_VERSION = 3;

/**
 * Baca versi skema tersimpan. Null berarti tabel belum ada atau penanda belum
 * pernah ditulis — seluruh migrasi harus dijalankan (bootstrap pertama kali).
 */
function getSchemaVersion(PDO $pdo): ?int
{
    try {
        $stmt = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = 'schema_version' LIMIT 1");
        $row = $stmt->fetch();
        return $row === false ? null : (int) $row['setting_value'];
    } catch (Throwable) {
        return null;
    }
}

function setSchemaVersion(PDO $pdo, int $version): void
{
    $pdo->prepare(
        "INSERT INTO app_settings (setting_key, setting_value)
         VALUES ('schema_version', :v)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    )->execute(['v' => (string) $version]);
}

function hasColumn(PDO $pdo, string $table, string $column): bool
{
    $sql = "SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function hasIndex(PDO $pdo, string $table, string $indexName): bool
{
    $stmt = $pdo->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = :key_name");
    $stmt->execute(['key_name' => $indexName]);
    return (bool) $stmt->fetch();
}

function ensureRolePimpinan(PDO $pdo): void
{
    $stmt = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME = 'users'
                           AND COLUMN_NAME = 'role'
                         LIMIT 1");
    $row = $stmt->fetch();
    $columnType = strtolower((string) ($row['COLUMN_TYPE'] ?? ''));

    if ($columnType === '') {
        return;
    }

    if (!str_contains($columnType, "'pimpinan'")) {
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin', 'sekretaris', 'pimpinan') NOT NULL DEFAULT 'sekretaris'");
    }
}

function ensureDisposisiColumns(PDO $pdo): void
{
    if (!hasColumn($pdo, 'agendas', 'disposition_status')) {
        $pdo->exec("ALTER TABLE agendas ADD COLUMN disposition_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending' AFTER notes");
    }
    if (!hasColumn($pdo, 'agendas', 'disposition_note')) {
        $pdo->exec("ALTER TABLE agendas ADD COLUMN disposition_note TEXT NULL AFTER disposition_status");
    }
    if (!hasColumn($pdo, 'agendas', 'disposition_by')) {
        $pdo->exec("ALTER TABLE agendas ADD COLUMN disposition_by BIGINT UNSIGNED NULL AFTER disposition_note");
    }
    if (!hasColumn($pdo, 'agendas', 'disposition_at')) {
        $pdo->exec("ALTER TABLE agendas ADD COLUMN disposition_at DATETIME NULL AFTER disposition_by");
    }
    if (!hasIndex($pdo, 'agendas', 'idx_disposition_status')) {
        $pdo->exec("ALTER TABLE agendas ADD INDEX idx_disposition_status (disposition_status)");
    }
    
    if (!hasColumn($pdo, 'agendas', 'ai_generated')) {
        $pdo->exec("ALTER TABLE agendas ADD COLUMN ai_generated TINYINT(1) NOT NULL DEFAULT 0 AFTER disposition_at");
    }
    if (!hasColumn($pdo, 'agendas', 'agenda_date_end')) {
        $pdo->exec("ALTER TABLE agendas ADD COLUMN agenda_date_end DATE NULL AFTER agenda_date");
    }
    if (!hasColumn($pdo, 'agendas', 'reminder_h1_enabled')) {
        $pdo->exec("ALTER TABLE agendas ADD COLUMN reminder_h1_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER ai_generated");
    }
    if (!hasColumn($pdo, 'agendas', 'reminder_h1_at')) {
        $pdo->exec("ALTER TABLE agendas ADD COLUMN reminder_h1_at DATETIME NULL AFTER reminder_h1_enabled");
    }
    if (!hasColumn($pdo, 'agendas', 'reminder_h1_sent_at')) {
        $pdo->exec("ALTER TABLE agendas ADD COLUMN reminder_h1_sent_at DATETIME NULL AFTER reminder_h1_at");
    }
}

function ensurePimpinanUser(PDO $pdo): void
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
    $stmt->execute(['username' => 'pimpinan']);
    $exists = (int) $stmt->fetchColumn() > 0;

    if ($exists) {
        return;
    }

    $passwordHash = '$2y$12$TogNFQpj29keVi1TDoc1LukeuTFlOplUnPesVSFyV4uEXz/.tkt/O'; // pimpinan123
    $insert = $pdo->prepare(
        "INSERT INTO users (full_name, username, password_hash, role)
         VALUES (:full_name, :username, :password_hash, 'pimpinan')"
    );
    $insert->execute([
        'full_name' => 'Pimpinan',
        'username' => 'pimpinan',
        'password_hash' => $passwordHash,
    ]);
}

function applySchemaUpdates(PDO $pdo): void
{
    // Gate: skip seluruh pemeriksaan DDL jika versi skema sudah terbaru.
    // Tanpa ini, tiap request melakukan ±40 query information_schema + ALTER TABLE
    // yang menyebabkan metadata lock dan request terserialisasi.
    $currentVersion = getSchemaVersion($pdo);
    if ($currentVersion !== null && $currentVersion >= APP_SCHEMA_VERSION) {
        return;
    }

    ensureRolePimpinan($pdo);
    ensureDisposisiColumns($pdo);
    ensureEmployeesTable($pdo);
    ensureEmployeeEmailColumn($pdo);
    ensureAgendaDispositionItemsTable($pdo);
    ensurePimpinanUser($pdo);
    ensureOptimizedIndexes($pdo);
    ensureAppSettingsTable($pdo);
    ensureEmployeeAuthColumns($pdo);
    ensureDispositionCompletedAt($pdo);
    ensureUserEmployeeIdColumn($pdo);
    ensureNotificationsTable($pdo);
    ensureTwoFactorColumns($pdo);
    ensureRateLimitsTable($pdo);
    ensurePasswordResetsTable($pdo);
    ensureLoginAttemptsTable($pdo);
    runOptionalMigrationStep($pdo, static function (PDO $pdo): void {
        ensureIndexAgendasEntryDisposition($pdo);
    }, 'idx_agendas_entry_disp');
    runOptionalMigrationStep($pdo, static function (PDO $pdo): void {
        ensureAgendaEffectiveDateColumn($pdo);
    }, 'effective_date');

    setSchemaVersion($pdo, APP_SCHEMA_VERSION);
}

/**
 * Jalankan langkah migrasi yang sifatnya opsional (optimasi performa saja).
 * Kegagalan (mis. MySQL lama tanpa dukungan generated column, atau user DB
 * tanpa izin ALTER) TIDAK boleh menggagalkan seluruh migrasi — bila tidak,
 * schema_version tidak pernah tertulis dan setiap request mengulang DDL,
 * membuat aplikasi lambat permanen. Halaman pemakai sudah punya fallback.
 */
function runOptionalMigrationStep(PDO $pdo, callable $step, string $name): void
{
    try {
        $step($pdo);
    } catch (Throwable $e) {
        error_log("Migrasi opsional '{$name}' dilewati: " . $e->getMessage());
    }
}

function ensureUserEmployeeIdColumn(PDO $pdo): void
{
    if (!hasColumn($pdo, 'users', 'employee_id')) {
        $pdo->exec(
            "ALTER TABLE users ADD COLUMN employee_id BIGINT UNSIGNED NULL AFTER role"
        );
        $pdo->exec(
            "ALTER TABLE users ADD CONSTRAINT fk_users_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL"
        );
    }

    // Pastikan enum role mendukung pegawai + arsiparis (hanya jika belum sesuai —
    // ALTER TABLE tanpa guard menimbulkan metadata lock di setiap request).
    $roleStmt = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE()
                               AND TABLE_NAME = 'users'
                               AND COLUMN_NAME = 'role'
                             LIMIT 1");
    $roleRow = $roleStmt->fetch();
    $roleType = strtolower((string) ($roleRow['COLUMN_TYPE'] ?? ''));
    if ($roleType !== ''
        && $roleType !== "enum('admin', 'sekretaris', 'pimpinan', 'pegawai', 'arsiparis')"
    ) {
        $pdo->exec(
            "ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'sekretaris', 'pimpinan', 'pegawai', 'arsiparis') NOT NULL DEFAULT 'pegawai'"
        );
    }

    if (!hasColumn($pdo, 'users', 'email')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(180) NULL AFTER username");
    }
    if (!hasColumn($pdo, 'users', 'whatsapp_number')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN whatsapp_number VARCHAR(30) NULL AFTER email");
    }
}

function ensureEmployeeEmailColumn(PDO $pdo): void
{
    if (!hasColumn($pdo, 'employees', 'email')) {
        $pdo->exec("ALTER TABLE employees ADD COLUMN email VARCHAR(180) NULL AFTER unit_name");
    }
}

function ensureEmployeesTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS employees (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nip VARCHAR(30) NULL UNIQUE,
            full_name VARCHAR(140) NOT NULL,
            position_title VARCHAR(140) NULL,
            unit_name VARCHAR(180) NULL,
            email VARCHAR(180) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_employee_name (full_name),
            INDEX idx_employee_active (is_active)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );

    $count = (int) $pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn();
    if ($count === 0) {
        $pdo->exec(
            "INSERT INTO employees (nip, full_name, position_title, unit_name, is_active) VALUES
            ('197901012006041001', 'Fery Jelitawati, SE, M.Kes, M.M', 'Kepala Bagian TU', 'BBLKL Salatiga', 1),
            ('198112122009021003', 'Andri Kurniawan, S.KM', 'Koordinator Program', 'BBLKL Salatiga', 1),
            ('198506152010012004', 'Rina Novitasari, S.Tr.Keb', 'Koordinator Pelayanan', 'BBLKL Salatiga', 1),
            ('199002022014052001', 'Dewi Rahmawati, S.Kom', 'Analis Data', 'BBLKL Salatiga', 1)"
        );
    }
}

function ensureAgendaDispositionItemsTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS agenda_disposition_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            agenda_id BIGINT UNSIGNED NOT NULL,
            item_type ENUM('disposisi', 'koordinasi', 'arahan') NOT NULL DEFAULT 'disposisi',
            employee_id BIGINT UNSIGNED NULL,
            employee_name VARCHAR(180) NOT NULL,
            message_text TEXT NULL,
            instruction_text TEXT NULL,
            attachment_label VARCHAR(80) NOT NULL DEFAULT 'TIDAK ADA',
            disposition_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            due_at DATETIME NULL,
            read_status ENUM('belum', 'sudah') NOT NULL DEFAULT 'sudah',
            created_by BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_disposition_items_agenda FOREIGN KEY (agenda_id) REFERENCES agendas(id) ON DELETE CASCADE,
            CONSTRAINT fk_disposition_items_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL,
            CONSTRAINT fk_disposition_items_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_disposition_items_type (item_type),
            INDEX idx_disposition_items_agenda (agenda_id),
            INDEX idx_disposition_items_due (due_at)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
}

function ensureOptimizedIndexes(PDO $pdo): void
{
    $indexes = [
        'idx_agendas_entry_direction' => ['entry_type', 'direction'],
        'idx_agendas_entry_status' => ['entry_type', 'status'],
        'idx_agendas_entry_ai_date' => ['entry_type', 'ai_generated', 'agenda_date'],
        'idx_agendas_zoom_lookup' => ['entry_type', 'ai_generated', 'agenda_mode', 'agenda_date'],
        'idx_agendas_source_entry_ai' => ['source_agenda_id', 'entry_type', 'ai_generated'],
        'idx_agendas_reminder_lookup' => ['entry_type', 'reminder_h1_enabled', 'reminder_h1_sent_at', 'reminder_h1_at'],
        'idx_agendas_entry_date' => ['entry_type', 'agenda_date'],
    ];

    foreach ($indexes as $name => $cols) {
        if (!hasIndex($pdo, 'agendas', $name)) {
            $colsStr = implode(', ', array_map(fn($c) => "`{$c}`", $cols));
            $pdo->exec("ALTER TABLE `agendas` ADD INDEX `{$name}` ({$colsStr})");
        }
    }
}

function ensureIndexAgendasEntryDisposition(PDO $pdo): void
{
    // Composite index untuk query badge disposisi header:
    // WHERE entry_type = 'surat' AND disposition_status = 'pending'
    if (!hasIndex($pdo, 'agendas', 'idx_agendas_entry_disp')) {
        $pdo->exec("ALTER TABLE `agendas` ADD INDEX `idx_agendas_entry_disp` (`entry_type`, `disposition_status`)");
    }
}

/**
 * Kolom generated effective_date = COALESCE(letter_date, recorded_date, agenda_date).
 * Membuat filter/ORDER BY tanggal di agenda.php sargable (index-friendly)
 * dibanding ekspresi COALESCE per-baris. Bila hosting tidak mendukung
 * generated column, halaman otomatis fallback ke ekspresi COALESCE lama.
 */
function ensureAgendaEffectiveDateColumn(PDO $pdo): void
{
    if (!hasColumn($pdo, 'agendas', 'effective_date')) {
        $pdo->exec(
            "ALTER TABLE agendas ADD COLUMN effective_date DATE
             GENERATED ALWAYS AS (COALESCE(letter_date, recorded_date, agenda_date)) STORED
             AFTER agenda_date_end"
        );
    }
    if (!hasIndex($pdo, 'agendas', 'idx_agendas_effective_date')) {
        $pdo->exec("ALTER TABLE agendas ADD INDEX idx_agendas_effective_date (effective_date)");
    }
}

function ensureAppSettingsTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS app_settings (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            setting_key   VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT NULL,
            updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_settings_key (setting_key)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );

    // Seed default rows — INSERT IGNORE preserves any value the admin has already set.
    $defaults = [
        'instansi_nama'    => 'BBLKL Salatiga — Kementerian Kesehatan RI',
        'instansi_alamat'  => '',
        'instansi_telp'    => '',
        'smtp_host'        => 'smtp.gmail.com',
        'smtp_port'        => '587',
        'smtp_secure'      => 'tls',
        'smtp_user'        => '',
        'smtp_password'    => '',
        'smtp_from_name'   => 'Agenda Pimpinan BBLKL',
        'fonnte_token'     => '',
    ];

    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES (:k, :v)"
    );
    foreach ($defaults as $key => $value) {
        $stmt->execute(['k' => $key, 'v' => $value]);
    }

    // reminder_secret_key: only generate once on first boot; INSERT IGNORE ensures it's never overwritten.
    $pdo->prepare(
        "INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('reminder_secret_key', :v)"
    )->execute(['v' => bin2hex(random_bytes(16))]);
}

function ensureEmployeeAuthColumns(PDO $pdo): void
{
    if (!hasColumn($pdo, 'employees', 'username')) {
        $pdo->exec(
            "ALTER TABLE employees ADD COLUMN username VARCHAR(50) UNIQUE NULL AFTER full_name"
        );
    }

    if (!hasColumn($pdo, 'employees', 'password_hash')) {
        $pdo->exec(
            "ALTER TABLE employees ADD COLUMN password_hash VARCHAR(255) NULL AFTER username"
        );
    }
}

function ensureNotificationsTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS notifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            link VARCHAR(255) NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notif_user (user_id),
            INDEX idx_notif_read (is_read)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );

    // Composite index untuk dropdown notifikasi header:
    // WHERE user_id = ? ORDER BY created_at DESC LIMIT 10 (menghindari filesort).
    if (!hasIndex($pdo, 'notifications', 'idx_notif_user_created')) {
        $pdo->exec("ALTER TABLE notifications ADD INDEX idx_notif_user_created (user_id, created_at)");
    }
}

function ensureDispositionCompletedAt(PDO $pdo): void
{
    if (!hasColumn($pdo, 'agenda_disposition_items', 'completed_at')) {
        $pdo->exec(
            "ALTER TABLE agenda_disposition_items ADD COLUMN completed_at DATETIME NULL AFTER read_status"
        );
    }
}

/**
 * Tambah kolom 2FA TOTP di tabel users (untuk admin/sekretaris/pimpinan).
 */
function ensureTwoFactorColumns(PDO $pdo): void
{
    if (!hasColumn($pdo, 'users', 'totp_secret')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN totp_secret VARCHAR(64) NULL AFTER password_hash");
    }
    if (!hasColumn($pdo, 'users', 'totp_enabled')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_secret");
    }
    if (!hasColumn($pdo, 'users', 'totp_backup_codes')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN totp_backup_codes TEXT NULL AFTER totp_enabled");
    }
}

function ensureRateLimitsTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        bucket VARCHAR(64) NOT NULL,
        identity VARCHAR(128) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_bucket_id_time (bucket, identity, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensurePasswordResetsTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_table VARCHAR(20) NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        username VARCHAR(100) NOT NULL,
        email VARCHAR(255) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_token (token_hash),
        INDEX idx_user (user_table, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensureLoginAttemptsTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        success TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_username_time (username, created_at),
        INDEX idx_ip_time (ip_address, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
