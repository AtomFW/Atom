<button id="sendDataBtn" class="tsr tsr-button tsr-button-success">Send Web Push!</button>

<script>
    class PushManagerClient {
    constructor(options = {}) {
        this.subscribeUrl = options.subscribeUrl || window.location.href + 'atpi/subscribe';
        this.serviceWorkerPath = options.serviceWorkerPath || window.location.href + 'resources/js/sw.js';
        this.vapidPublicKey = options.vapidPublicKey || '';
    }

    async init() {
        if (!('serviceWorker' in navigator)) {
            throw new Error('ServiceWorker unsupported');
        }

        if (!('PushManager' in window)) {
            throw new Error('Push API unsupported');
        }

        const registration = await navigator.serviceWorker.register(
            this.serviceWorkerPath
        );

        const permission = await Notification.requestPermission();

        if (permission !== 'granted') {
            throw new Error('Notification permission denied');
        }

        let subscription = await registration.pushManager.getSubscription();

        if (!subscription) {
            subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: this.urlBase64ToUint8Array(
                    this.vapidPublicKey
                ),
            });
        }

        // send subscription to serwer
        await this.sendSubscription(subscription);

        return subscription;
    }

    async sendSubscription(subscription) {
        const response = await fetch(this.subscribeUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(subscription),
        });

        if (!response.ok) {
            throw new Error(
                'Failed to save push subscription'
            );
        }

        return await response.json();
    }

    urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat(
            (4 - (base64String.length % 4)) % 4
        );

        const base64 = (base64String + padding)
            .replace(/-/g, '+')
            .replace(/_/g, '/');

        const rawData = atob(base64);

        return Uint8Array.from(
            [...rawData].map(char => char.charCodeAt(0))
        );
    }
}

// INIT

window.PushClient = new PushManagerClient({
    vapidPublicKey: '<?php echo Atom\Atom::$app->config->get("app")['webPush']['publicKey'] ?>',
    subscribeUrl: window.location.href + '../atpi/subscribe',
    serviceWorkerPath: window.location.href + '../resources/js/sw.js',
});

window.PushClient
    .init()
    .then(subscription => {
        console.log('Push subscribed', subscription);
    })
    .catch(console.error);

    // Choice button
    const btn = document.querySelector('#sendDataBtn');

    // Function to send data
    const sendData = async () => {
        const url = window.location.href + '/../atpi/push/'; 
        
        const payload = {
            app: "my_app",
            opt: true
        };

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            });

            if (response.ok) {
                const result = await response.json();
                console.log('Success:', result);
                alert('Date send successfully!');
            } else {
                console.error('Server error:', response.status);
                alert('Something went wrong in serwer side.');
            }
        } catch (error) {
            console.error('Connection error:', error);
            alert('Failed to connect to endpoint.');
        }
    };

    btn.addEventListener('click', sendData);
</script>
