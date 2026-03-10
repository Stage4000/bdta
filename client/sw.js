"use strict";

// A minimal service worker for the admin backend that purposefully avoids caching.
// All requests are fetched from the network with cache: 'no-store'.

self.addEventListener("install", (event) => {
  // Ensure any existing caches are cleared and activate immediately.
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.map((key) => caches.delete(key))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.map((key) => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener("fetch", (event) => {
  const OFFLINE_MESSAGE = 'Unable to reach the server. Please check your connection and retry.';
  event.respondWith(
    fetch(event.request, { cache: "no-store" }).catch((err) => {
      console.error('Network request failed:', err);
      return new Response(OFFLINE_MESSAGE, { status: 503, headers: { 'Content-Type': 'text/plain' } });
    })
  );
});
