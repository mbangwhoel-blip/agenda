<?php

declare(strict_types=1);

/**
 * Internationalization (i18n) framework sederhana.
 *
 * Pakai: t('key') → terjemahkan ke bahasa aktif.
 * Bahasa default: 'id'. Aktifkan 'en' via env APP_LANG=en.
 *
 * Tambahkan terjemahan baru di storage/lang/{lang}.php (format array key → string).
 */

function currentLang(): string
{
    static $lang = null;
    if ($lang === null) {
        $lang = (string) ($_SESSION['lang'] ?? env('APP_LANG', 'id'));
        if (!in_array($lang, ['id', 'en'], true)) $lang = 'id';
    }
    return $lang;
}

function t(string $key, array $params = []): string
{
    static $dict = null;
    if ($dict === null) {
        $lang = currentLang();
        $file = __DIR__ . '/../storage/lang/' . $lang . '.php';
        $dict = is_file($file) ? (require $file) : [];
    }
    $text = $dict[$key] ?? $key;
    // Substitute {placeholder}.
    foreach ($params as $k => $v) {
        $text = str_replace('{' . $k . '}', (string) $v, $text);
    }
    return $text;
}

// Helper untuk switch bahasa.
function setLang(string $lang): void
{
    if (in_array($lang, ['id', 'en'], true)) {
        $_SESSION['lang'] = $lang;
    }
}
