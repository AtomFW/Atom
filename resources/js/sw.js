self.addEventListener('push', event => {
    let data = {};

    try {
        data = event.data.json();
    } catch (e) {
        data = {
            title: 'Notification',
            body: event.data.text(),
        };
    }

    const options = {
        body: data.body,
        icon: data.icon || '/icon.png',
        badge: data.badge || '/badge.png',
        image: data.image || undefined,
        data: data,
    };

    event.waitUntil(
        self.registration.showNotification(
            data.title || 'Notification',
            options
        )
    );
});

self.addEventListener('notificationclick', event => {
    event.notification.close();

    const url = event.notification.data?.url;

    if (url) {
        event.waitUntil(
            clients.openWindow(url)
        );
    }
});


// Installation - this is where we usually cache files
self.addEventListener('install', (event) => {
  console.log('Service Worker: Instaled...');
});

// Activation
self.addEventListener('activate', (event) => {
  console.log('Service Worker: Activated!');
});

// Capturing queries (e.g. to work offline)
self.addEventListener('fetch', (event) => {
  console.log('Request intercepted for:', event.request.url);
});