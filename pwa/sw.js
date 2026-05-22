// Minimal service worker — no caching, no offline support.
// Exists only to make the app installable on Android (PWA criteria
// require a registered service worker with a fetch handler).
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));
self.addEventListener('fetch', () => {});
