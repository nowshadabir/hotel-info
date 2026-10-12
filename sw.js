const CACHE_NAME = 'staybook-shell-v9';
const SHELL_FILES = [
  './offline.html',
  './style.css',
  './app.js',
  './pwa.js',
  './photo-preview.js',
  './manifest.webmanifest',
  './icons/icon.svg',
  './icons/icon-192.png',
  './icons/icon-512.png'
];



self.addEventListener('install', event => {
  event.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(SHELL_FILES)));
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(caches.keys().then(keys => Promise.all(
    keys.filter(key => key.startsWith('staybook-shell-') && key !== CACHE_NAME).map(key => caches.delete(key))
  )));
  self.clients.claim();
});

self.addEventListener('fetch', event => {
  const request = event.request;
  if (request.method !== 'GET' || new URL(request.url).origin !== self.location.origin) return;

  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(async () => (await caches.match('./offline.html')) || Response.error()));
    return;
  }

  const url = new URL(request.url);
  if (/\.(css|js|webmanifest|svg|png)$/.test(url.pathname)) {
    event.respondWith(fetch(request).then(response => {
      if (response.ok) {
        return caches.open(CACHE_NAME).then(cache => cache.put(request, response.clone())).then(() => response);
      }
      return response;
    }).catch(async () => (await caches.match(request)) || Response.error()));
  }
});
