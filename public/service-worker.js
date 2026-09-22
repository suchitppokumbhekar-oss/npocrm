/* ============================================================
   NPO CRM — Service Worker v74
   Reliable Web Push
   ============================================================ */

const CACHE_VERSION = 'npo-crm-v74-push-final';

const STATIC_ASSETS = [
  '/icons/icon.svg',
  '/icons/icon-192.png',
  '/manifest.json',
  '/agent-guide.html',
];

/* ============================================================
   INSTALL
   ============================================================ */

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_VERSION)
      .then((cache) => {
        return Promise.all(
          STATIC_ASSETS.map((url) =>
            cache.add(url).catch(() => null)
          )
        );
      })
      .then(() => self.skipWaiting())
  );
});

/* ============================================================
   ACTIVATE
   ============================================================ */

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => {
        return Promise.all(
          keys
            .filter((key) => key !== CACHE_VERSION)
            .map((key) => caches.delete(key))
        );
      })
      .then(() => self.clients.claim())
  );
});

/* ============================================================
   FETCH
   ============================================================ */

self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);

  if (request.method !== 'GET') return;
  if (url.origin !== self.location.origin) return;

  // Never cache dynamic CRM endpoints.
  if (
    url.pathname.startsWith('/api/') ||
    url.pathname.startsWith('/webhooks/') ||
    url.pathname.startsWith('/notifications/') ||
    url.pathname.startsWith('/modals/') ||
    url.pathname.startsWith('/clear-config') ||
    url.pathname.startsWith('/admin/') ||
    url.pathname.startsWith('/login') ||
    url.pathname.startsWith('/logout')
  ) {
    return;
  }

  // HTML/navigation: network first.
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).catch(() => caches.match('/'))
    );

    return;
  }

  // JS/CSS: network first so CRM updates are not trapped
  // behind an old service-worker cache.
  if (
    url.pathname.endsWith('.js') ||
    url.pathname.endsWith('.css')
  ) {
    event.respondWith(
      fetch(request)
        .then((response) => {
          if (response && response.ok) {
            const clone = response.clone();

            event.waitUntil(
              caches.open(CACHE_VERSION)
                .then((cache) => cache.put(request, clone))
                .catch(() => {})
            );
          }

          return response;
        })
        .catch(() => caches.match(request))
    );

    return;
  }

  // Static assets: cache first.
  if (
    url.pathname.startsWith('/icons/') ||
    url.pathname.endsWith('.svg') ||
    url.pathname.endsWith('.png') ||
    url.pathname.endsWith('.woff') ||
    url.pathname.endsWith('.woff2')
  ) {
    event.respondWith(
      caches.match(request).then((cached) => {
        if (cached) return cached;

        return fetch(request).then((response) => {
          if (response && response.ok) {
            const clone = response.clone();

            event.waitUntil(
              caches.open(CACHE_VERSION)
                .then((cache) => cache.put(request, clone))
                .catch(() => {})
            );
          }

          return response;
        });
      })
    );
  }
});

/* ============================================================
   WEB PUSH
   ============================================================ */

self.addEventListener('push', (event) => {
  let payload = {};

  try {
    if (event.data) {
      payload = event.data.json();
    }
  } catch (error) {
    try {
      payload = {
        body: event.data ? event.data.text() : ''
      };
    } catch (ignored) {
      payload = {};
    }
  }

  const title =
    payload.title ||
    'NPO CRM';

  const body =
    payload.body ||
    'You have a new CRM notification.';

  const actionUrl =
    payload.action_url ||
    payload.url ||
    '/notifications';

  const options = {
    body: body,

    icon:
      payload.icon_url ||
      '/icons/icon-192.png',

    badge:
      payload.badge_url ||
      '/icons/icon-192.png',

    tag:
      payload.tag ||
      (
        payload.notification_id
          ? 'npo-crm-' + payload.notification_id
          : 'npo-crm-notification'
      ),

    renotify: true,

    data: {
      url: actionUrl,
      notification_id:
        payload.notification_id || null
    }
  };

  event.waitUntil(
    self.registration.showNotification(
      title,
      options
    )
  );
});

/* ============================================================
   NOTIFICATION CLICK
   ============================================================ */

self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  const targetUrl =
    (
      event.notification.data &&
      event.notification.data.url
    )
      ? event.notification.data.url
      : '/notifications';

  event.waitUntil(
    clients.matchAll({
      type: 'window',
      includeUncontrolled: true
    }).then((windows) => {

      for (const windowClient of windows) {
        if ('navigate' in windowClient) {
          windowClient.navigate(targetUrl);
        }

        if ('focus' in windowClient) {
          return windowClient.focus();
        }
      }

      if (clients.openWindow) {
        return clients.openWindow(targetUrl);
      }
    })
  );
});