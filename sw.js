// Service Worker — Agenda Pimpinan BBLKL
// Strategy: cache-first untuk asset statis, network-first untuk halaman PHP.
// PENTING: versi di bawah HARUS sama dengan $assetVer di includes/header.php.

const CACHE_NAME = 'agenda-pimpinan-v2.2.1';
const ASSETS = [
  '/assets/css/kemenkes.css?v=2.2.1',
  '/assets/ui.css?v=2.2.1',
  '/assets/js/alpine.min.js',
  '/assets/js/sweetalert2.all.min.js',
  '/assets/js/chart.umd.min.js',
  '/assets/js/fullcalendar.global.min.js',
  '/assets/logo.png?v=2.2.1',
  '/manifest.json?v=2.2.1',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(ASSETS).catch(() => {}))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  // Halaman PHP → network-first, fallback ke cache offline.
  if (req.mode === 'navigate' || req.url.includes('.php')) {
    event.respondWith(
      fetch(req).then((res) => {
        const copy = res.clone();
        caches.open(CACHE_NAME).then((c) => c.put(req, copy)).catch(() => {});
        return res;
      }).catch(() => caches.match(req).then((r) => r || caches.match('/offline.html')))
    );
    return;
  }

  // Asset statis → cache-first.
  event.respondWith(
    caches.match(req).then((cached) => cached || fetch(req).then((res) => {
      const copy = res.clone();
      caches.open(CACHE_NAME).then((c) => c.put(req, copy)).catch(() => {});
      return res;
    }).catch(() => cached))
  );
});
