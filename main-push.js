// File: main-push.js

async function subscribeUserToPush(vapidPublicKey) {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        console.warn('Push messaging tidak didukung di browser ini.');
        return;
    }

    try {
        // Kita gunakan .ready agar yakin service worker sudah aktif
        const registration = await navigator.serviceWorker.ready;
        console.log('Service Worker sudah siap.');

        // Cek apakah pengguna sudah berlangganan
        let subscription = await registration.pushManager.getSubscription();
        if (subscription) {
            console.log('Pengguna SUDAH berlangganan notifikasi.');
            return; // Keluar jika sudah, tidak perlu subscribe lagi
        }

        console.log('Pengguna BELUM berlangganan. Memulai proses subscribe...');
        const applicationServerKey = urlBase64ToUint8Array(vapidPublicKey);
        subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: applicationServerKey
        });
        console.log('Pengguna berhasil subscribe.');

        // Kirim data subscription ke server
        await sendSubscriptionToServer(subscription);

        const btn = document.getElementById('enable-notifications-btn');
        if (btn) {
            btn.style.display = 'none';
        }
        alert('Terima kasih! Notifikasi telah diaktifkan.');

    } catch (err) {
        console.error('Gagal subscribe:', err);
        if (Notification.permission === 'denied') {
            alert('Anda telah memblokir notifikasi. Silakan izinkan melalui pengaturan browser jika ingin mengaktifkannya kembali.');
        } else {
            alert('Gagal mengaktifkan notifikasi. Mohon coba lagi.');
        }
    }
}

async function sendSubscriptionToServer(subscription) {
    try {
        const response = await fetch('?action=save_subscription', {
            method: 'POST',
            body: JSON.stringify(subscription),
            headers: { 'Content-Type': 'application/json' }
        });
        if (!response.ok) {
            throw new Error('Gagal mengirim data ke server.');
        }
        console.log('Data subscription berhasil dikirim ke server.');
    } catch (error) {
        console.error('Error saat mengirim subscription:', error);
    }
}

// Fungsi helper untuk konversi VAPID key
function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

// Fungsi untuk inisialisasi tombol
function initializeUI(vapidPublicKey) {
    const notificationButton = document.getElementById('enable-notifications-btn');
    if (!notificationButton) return;

    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        notificationButton.style.display = 'none';
        return;
    }

    // Hanya tampilkan tombol jika izin belum diberikan (status 'default')
    if (Notification.permission === 'default') {
        notificationButton.style.display = 'block';
        notificationButton.addEventListener('click', () => subscribeUserToPush(vapidPublicKey));
    } else {
        notificationButton.style.display = 'none';
    }
}