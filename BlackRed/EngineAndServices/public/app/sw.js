/**
 * BlackRed Service Worker
 *
 * Minimum-viable SW: just enough to qualify the site as a PWA so the install
 * prompt appears on Android. We do NOT cache API responses, HTML, or anything
 * that could go stale — for a money app, freshness > offline support. If the
 * network is down, the user sees a network error, not a stale balance.
 *
 * What this worker does:
 *   - Registers itself and claims open clients on install
 *   - Responds to ALL fetch events (required for Chrome installability check)
 *   - For navigation requests: network-first with offline fallback
 *   - For everything else: passes through to network unchanged
 *
 * What this worker does NOT do (intentionally):
 *   - Cache /api/* responses (would expose stale balances)
 *   - Cache HTML pages (would show stale UI after deploys)
 *   - Cache JS bundles (we have none — JS is inlined)
 *   - Background sync, push notifications (post-launch features)
 */

const CACHE_VERSION = 'br-v2';
const OFFLINE_URL = '../errors/offline.html';

// Pre-cache the offline fallback during install
self.addEventListener('install', (event) => {
  event.waitUntil(
    (async () => {
      const cache = await caches.open(CACHE_VERSION);
      try {
        await cache.add(new Request(OFFLINE_URL, { cache: 'reload' }));
      } catch (e) {
        console.warn('[SW] Could not pre-cache offline page:', e);
      }
    })()
  );
  self.skipWaiting();
});

// Clean up old caches and take control of clients
self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      const keys = await caches.keys();
      await Promise.all(
        keys.filter(k => k !== CACHE_VERSION).map(k => caches.delete(k))
      );
      await self.clients.claim();
    })()
  );
});

// FETCH HANDLER
//
// Chrome's installability check requires the SW to *handle* fetch events —
// every fetch event should call event.respondWith() with a Response.
// Previously we only called respondWith() for navigation requests and let
// other requests fall through. Chrome can interpret fall-through as
// "no fetch handler" and refuse to count the SW as installable.
//
// The fix: always call event.respondWith() with a real fetch promise.
self.addEventListener('fetch', (event) => {
  const req = event.request;

  // Skip non-GETs (POSTs to /api/* etc. must always be live and untouched)
  if (req.method !== 'GET') return;

  // For navigation (HTML page loads): network-first, offline fallback on error
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(async () => {
        const cache = await caches.open(CACHE_VERSION);
        const cached = await cache.match(OFFLINE_URL);
        return cached || new Response('Offline', {
          status: 503,
          headers: { 'Content-Type': 'text/plain' }
        });
      })
    );
    return;
  }

  // For everything else (images, manifest, css): just hit the network.
  // We MUST respondWith to satisfy Chrome's installability requirement,
  // but we don't add any caching layer because for a money app, freshness
  // matters more than offline support.
  event.respondWith(fetch(req));
});

// Allow the page to tell a waiting SW to activate immediately
self.addEventListener('message', (event) => {
  if (event.data === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});