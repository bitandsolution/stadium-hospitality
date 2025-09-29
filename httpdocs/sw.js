/**
 * Service Worker Minimal - Hospitality Manager
 * Cache essenziale per performance PWA
 */
const CACHE_NAME = 'hospitality-v1.0.0';
const STATIC_CACHE = 'hospitality-static-v1.0.0';
const API_CACHE = 'hospitality-api-v1.0.0';

// Files critici da cachare
const STATIC_ASSETS = [
  '/',
  '/manifest.json',
  '/assets/icons/icon-192x192.png',
  '/assets/icons/icon-512x512.png'
];

// Install - Cache static assets
self.addEventListener('install', event => {
  console.log('[SW] Installing...');
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then(cache => cache.addAll(STATIC_ASSETS))
      .then(() => self.skipWaiting())
  );
});

// Activate - Clean old caches
self.addEventListener('activate', event => {
  console.log('[SW] Activating...');
  event.waitUntil(
    caches.keys()
      .then(cacheNames => {
        return Promise.all(
          cacheNames.map(cacheName => {
            if (!cacheName.startsWith('hospitality-')) {
              return caches.delete(cacheName);
            }
          })
        );
      })
      .then(() => self.clients.claim())
  );
});

// Fetch - Network first with cache fallback
self.addEventListener('fetch', event => {
  const { request } = event;
  
  // Skip non-GET requests
  if (request.method !== 'GET') return;
  
  // Handle API requests
  if (request.url.includes('/api/')) {
    event.respondWith(handleAPIRequest(request));
  } else {
    // Handle page/asset requests
    event.respondWith(handleAssetRequest(request));
  }
});

async function handleAPIRequest(request) {
  try {
    // Network first for fresh data
    const response = await fetch(request);
    
    if (response.ok) {
      // Cache successful API responses
      const cache = await caches.open(API_CACHE);
      await cache.put(request, response.clone());
    }
    
    return response;
  } catch (error) {
    // Fallback to cache
    const cachedResponse = await caches.match(request);
    if (cachedResponse) {
      return cachedResponse;
    }
    
    // Return offline response for APIs
    return new Response(JSON.stringify({
      success: false,
      message: 'Offline - Please check your connection',
      error_code: 'OFFLINE',
      timestamp: new Date().toISOString()
    }), {
      status: 503,
      headers: { 'Content-Type': 'application/json' }
    });
  }
}

async function handleAssetRequest(request) {
  try {
    const response = await fetch(request);
    
    if (response.ok) {
      const cache = await caches.open(STATIC_CACHE);
      await cache.put(request, response.clone());
    }
    
    return response;
  } catch (error) {
    // Return cached version
    return caches.match(request) || 
           caches.match('/') || 
           new Response('Offline', { status: 503 });
  }
}