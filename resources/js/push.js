async function registerPush() {
    if (!('serviceWorker' in navigator)) {
        throw new Error('Service Worker unsupported');
    }

    if (!('PushManager' in window)) {
        throw new Error('Push unsupported');
    }

    const registration = await navigator.serviceWorker.register('/sw.js');

    const permission = await Notification.requestPermission();

    if (permission !== 'granted') {
        throw new Error('Permission denied');
    }

    // PUBLIC VAPID KEY Z PHP
    const vapidPublicKey = window.VAPID_PUBLIC_KEY;

    const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
    });

    // send subscription to backend
    await fetch('/push/subscribe.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(subscription),
    });

    console.log('Push subscribed', subscription);
}

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);

    const base64 = (base64String + padding)
        .replace(/-/g, '+')
        .replace(/_/g, '/');

    const rawData = atob(base64);

    return Uint8Array.from([...rawData].map(char => char.charCodeAt(0)));
}

registerPush().catch(console.error);