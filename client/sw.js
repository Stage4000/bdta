"use strict";

const OFFLINE_MESSAGE = 'Unable to reach the server. Please check your connection and retry.';

// A minimal service worker for the admin backend that purposefully avoids caching.
// All requests are fetched from the network with cache: 'no-store'.

self.addEventListener("install", (event) => {
  event.waitUntil(self.skipWaiting());
});

self.addEventListener("activate", (event) => {
  event.waitUntil(self.clients.claim());
});

self.addEventListener("fetch", (event) => {
  event.respondWith(
    fetch(event.request, { cache: "no-store" }).catch((err) => {
      console.error('Network request failed:', err);
      if (event.request.mode === "navigate") {
        return new Response(OFFLINE_MESSAGE, { status: 503, headers: { 'Content-Type': 'text/plain' } });
      }
      return Response.error();
    })
  );
});
