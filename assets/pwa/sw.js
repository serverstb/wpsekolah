/* Portal Sekolah PWA — Service Worker */
/* global self, caches, fetch, Response, clients */

var CACHE = 'qrg-sekolah-pwa-v1';
var PRECACHE = [
  './',
  './offline/',
  './icons/192.png',
  './icons/512.png',
  './icons/180.png'
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(CACHE).then(function (cache) {
      return cache.addAll(PRECACHE).catch(function () {
        return undefined;
      });
    }).then(function () {
      return self.skipWaiting();
    })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(
        keys.filter(function (k) { return k !== CACHE; }).map(function (k) {
          return caches.delete(k);
        })
      );
    }).then(function () {
      return self.clients.claim();
    })
  );
});

function isNavigation(request) {
  return request.mode === 'navigate' ||
    (request.method === 'GET' && request.headers.get('accept') &&
      request.headers.get('accept').indexOf('text/html') !== -1);
}

self.addEventListener('fetch', function (event) {
  var request = event.request;
  if (request.method !== 'GET') {
    return;
  }

  var url;
  try {
    url = new URL(request.url);
  } catch (e) {
    return;
  }

  // Hanya tangani origin yang sama di bawah /sekolah/
  if (url.origin !== self.location.origin) {
    return;
  }
  if (url.pathname.indexOf('/sekolah') !== 0) {
    return;
  }

  // Jangan cache auth / logout / POST-like query
  if (url.searchParams.has('qrg_logout') || url.searchParams.has('qrg_sekolah_login')) {
    return;
  }

  if (isNavigation(request)) {
    event.respondWith(
      fetch(request)
        .then(function (response) {
          var copy = response.clone();
          if (response.ok) {
            caches.open(CACHE).then(function (cache) {
              cache.put(request, copy);
            });
          }
          return response;
        })
        .catch(function () {
          return caches.match(request).then(function (cached) {
            if (cached) {
              return cached;
            }
            return caches.match('./offline/');
          });
        })
    );
    return;
  }

  // Aset ikon / SW terkait: cache-first
  if (/\/sekolah\/(icons\/|manifest\.webmanifest|sw\.js)/.test(url.pathname)) {
    event.respondWith(
      caches.match(request).then(function (cached) {
        var network = fetch(request).then(function (response) {
          if (response && response.ok) {
            var copy = response.clone();
            caches.open(CACHE).then(function (cache) {
              cache.put(request, copy);
            });
          }
          return response;
        }).catch(function () {
          return cached;
        });
        return cached || network;
      })
    );
  }
});

self.addEventListener('message', function (event) {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});
