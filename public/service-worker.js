/* ============================================================
   NPO CRM — Service Worker v70
   - Static assets: cache-first
   - HTML: network-first with cache fallback
   - CRM notification/API paths: never cached
   - Web Push: explicit persistent system notifications
   ============================================================ */

const CACHE_VERSION = 'npo-crm-v7-pwa-cache-hardening';
const STATIC_ASSETS = [
  '/',
  '/assets/css/app.css',
  '/assets/js/app.js',
  '/icons/icon.svg',
  '/icons/icon-192.png',
  '/manifest.json',
  '/agent-guide.html',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_VERSION)
      .then((cache) => Promise.all(STATIC_ASSETS.map((url) => cache.add(url).catch(() => null))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys.filter((k) => k !== CACHE_VERSION).map((k) => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  if (request.method !== 'GET') return;
  if (url.origin !== self.location.origin) return;

  if (
    url.pathname.startsWith('/api/') ||
    url.pathname.startsWith('/webhooks/') ||
    url.pathname.startsWith('/notifications/') ||
    url.pathname.startsWith('/modals/') ||
    url.pathname.startsWith('/clear-config') ||
    url.pathname.startsWith('/admin/') ||
    url.pathname.startsWith('/login') ||
    url.pathname.startsWith('/logout')
  ) return;

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((response) => {
          if (response && response.ok && response.status === 200) {
            event.waitUntil(
              caches.open(CACHE_VERSION)
                .then((cache) => cache.put(request, response.clone()))
                .catch(() => {})
            );
          }
          return response;
        })
        .catch(() => caches.match(request).then((cached) => cached || caches.match('/')))
    );
    return;
  }

  if (
    url.pathname.startsWith('/assets/') ||
    url.pathname.startsWith('/icons/') ||
    url.pathname.endsWith('.css') ||
    url.pathname.endsWith('.js') ||
    url.pathname.endsWith('.svg') ||
    url.pathname.endsWith('.png') ||
    url.pathname.endsWith('.woff') ||
    url.pathname.endsWith('.woff2')
  ) {
    event.respondWith(
      caches.match(request).then((cached) => {
        if (cached) return cached;
        return fetch(request).then((response) => {
          const clone = response.clone();
          caches.open(CACHE_VERSION).then((cache) => cache.put(request, clone));
          return response;
        });
      })
    );
  }
});

/* ============================================================
   WEB PUSH — v70
   ============================================================ */
self.addEventListener('push', (event) => {
  // Keep the push event alive for the actual notification promise.
  // Use a deliberately minimal payload first; Android/Chrome can reject a
  // notification when an optional field is unsupported or invalid.
  event.waitUntil((async () => {
    let data = {};
    try {
      if (event.data) {
        const raw = event.data.text();
        if (raw) {
          try { data = JSON.parse(raw); }
          catch (e) { data = { body: raw }; }
        }
      }
    } catch (e) {}

    const title = String(data.title || 'NPO CRM');
    const body = String(data.body || 'You have new work in NPO CRM.');
    const notificationId = data.notification_id || null;
    const url = String(data.url || '/notifications');
    const priority = String(data.priority || 'info');
    const tag = notificationId ? 'npo-crm-' + notificationId : 'npo-crm-' + Date.now();

    try {
      await self.registration.showNotification(title, {
        body: body,
        tag: tag,
        data: { url: url, notification_id: notificationId, priority: priority }
      });
    } catch (e1) {
      // Second attempt: only the title/body. This is intentionally even more
      // conservative so the browser cannot fall back to its generic push tile.
      await self.registration.showNotification('NPO CRM', {
        body: body || 'You have new work in NPO CRM.'
      });
    }
  })());
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  const data = event.notification.data || {};
  let url = data.url || '/notifications';

  if (data.notification_id) {
    const joiner = url.includes('?') ? '&' : '?';
    url += joiner + 'notification_id=' + encodeURIComponent(data.notification_id);
  }

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((wins) => {
      for (const w of wins) {
        try {
          const target = new URL(url, self.location.origin);
          if (new URL(w.url).origin === target.origin) {
            return w.focus().then(() => w.navigate(target.href));
          }
        } catch (e) {}
      }
      return clients.openWindow(url);
    })
  );
});
