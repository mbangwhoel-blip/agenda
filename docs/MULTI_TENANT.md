# Multi-Tenant Schema Proposal

> Catatan: Implementasi multi-tenant adalah refactor besar yang mengubah skema database & isolasi data. Dokumen ini adalah proposal desain, BUKAN implementasi langsung (akan merusak data existing).

## Strategi: Tenant per-row dengan `tenant_id`

Setiap tabel bisnis mendapat kolom `tenant_id` yang merefer ke tabel `tenants`. Semua query di-namespace per tenant aktif (dari session user).

## Skema

```sql
CREATE TABLE tenants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    plan ENUM('free','pro','enterprise') NOT NULL DEFAULT 'free',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE users ADD COLUMN tenant_id BIGINT UNSIGNED NULL AFTER id;
ALTER TABLE users ADD CONSTRAINT fk_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE users ADD INDEX idx_users_tenant (tenant_id);

ALTER TABLE agendas ADD COLUMN tenant_id BIGINT UNSIGNED NULL AFTER id;
ALTER TABLE agendas ADD CONSTRAINT fk_agendas_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE agendas ADD INDEX idx_agendas_tenant (tenant_id);

ALTER TABLE employees ADD COLUMN tenant_id BIGINT UNSIGNED NULL AFTER id;
ALTER TABLE employees ADD CONSTRAINT fk_employees_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE employees ADD INDEX idx_employees_tenant (tenant_id);

-- Tabel lain: notifications, activity_logs, agenda_disposition_items, dll.
```

## Helper di PHP

```php
function currentTenantId(): ?int {
    $user = currentUser();
    return $user ? (int)($user['tenant_id'] ?? null) : null;
}

// Wrapper PDO yang auto-inject WHERE tenant_id = ?
class TenantPDO {
    private PDO $pdo;
    private int $tenantId;

    public function prepare(string $sql): PDOStatement {
        // Inject tenant_id filter (sederhana: replace "FROM agendas" → "FROM agendas WHERE tenant_id = ?")
        // Untuk production: pakai query builder (Doctrine) yang lebih aman.
        ...
    }
}
```

## Roadmap Implementasi

1. Tambah kolom `tenant_id` di semua tabel (nullable dulu).
2. Backfill: set semua row existing ke `tenant_id = 1` (default tenant BBLKL).
3. Tambah helper `currentTenantId()` + ganti semua query.
4. Buat halaman admin super untuk kelola tenant.
5. Tambah plan limits (free: 100 surat/bulan, pro: unlimited, dst).
6. Custom domain per tenant (opsional).

## Catatan

- Multi-tenant berdampak pada semua query di `app/helpers.php`, `dashboard.php`, `agenda*.php`, `disposisi*.php`, `user_manager.php`, dll.
- Backup & restore harus di-scope per tenant.
- Billing & subscription perlu integrasi payment gateway (Midtrans/Xendit).
- Estimasi effort: 2-4 minggu development + testing.

Alternatif lebih cepat: deploy instance terpisah per organisasi (single-tenant). Cocok untuk ≤10 organisasi.
