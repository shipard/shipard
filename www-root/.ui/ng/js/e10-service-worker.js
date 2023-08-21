self.addEventListener('install', function(e) {
	e.waitUntil(
		caches.open('e10-app-cache').then(function(cache) {
			return cache.addAll([
			]);
		})
	);
});

self.addEventListener('fetch', function(e) {
	e.respondWith(
		caches.match(e.request).then(function(response) {
			return response || fetch(e.request);
		})
	);
});

self.addEventListener('push', function(event) {
	if (event.data) {
		console.log('This push event has data: ', event.data.text());
	} else {
		console.log('This push event has no data.');
	}
});