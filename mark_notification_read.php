<?php
declare(strict_types=1);

require_once __DIR__ . '/app/helpers.php';
requireLogin();

$user = currentUser();
if (!$user) {
    redirect('login.php');
}

$pdo = getPdo();
$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

/**
 * Pastikan link redirect hanya ke path internal relatif (no scheme/host).
 * Mencegah open redirect ke domain attacker.
 */
$safeRedirect = function (string $link): string {
    $decoded = rawurldecode($link);
    // Tolak URL absolut (scheme://), protocol-relative (//), atau path ke root yang mencurigakan
    if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $decoded) || str_starts_with($decoded, '//')) {
        return 'dashboard.php';
    }
    // Hanya izinkan path relatif yang diakhiri .php atau tanpa scheme
    if (!preg_match('#^[A-Za-z0-9_\-./]+\.(php|html?)$#i', $decoded) && $decoded !== 'dashboard.php') {
        // Izinkan path dengan query string
        if (!preg_match('#^[A-Za-z0-9_\-./]+\.php(\?[A-Za-z0-9_\-./=&%]*)?$#i', $decoded)) {
            return 'dashboard.php';
        }
    }
    return $decoded;
};

$link = $safeRedirect($_GET['link'] ?? 'dashboard.php');

if ($action === 'all') {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = :user_id AND is_read = 0");
    $stmt->execute(['user_id' => $user['id']]);
    redirect('dashboard.php');
} elseif ($id > 0) {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id");
    $stmt->execute(['id' => $id, 'user_id' => $user['id']]);
    redirect($link);
} else {
    redirect('dashboard.php');
}
