// Nama cache unik. Ubah nomor versi jika Anda memperbarui aset statis.
const CACHE_NAME = 'quizb-cache-v3';

// Daftar aset statis yang aman untuk di-cache.
// PENTING: Jangan masukkan '/' atau 'index.php' ke dalam daftar ini.
const STATIC_ASSETS = [
  '/favicon.png',
  '/og-image.png',
  '/manifest.json',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
  'https://accounts.google.com/gsi/client'
];

// Event 'install': Cache aset statis saat service worker diinstal.
self.addEventListener('install', (event) => {
  console.log('SW: Menginstal...');
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      console.log('SW: Menyimpan aset statis ke cache...');
      return cache.addAll(STATIC_ASSETS);
    }).catch(err => {
      console.error("Gagal menyimpan cache aset statis:", err);
    })
  );
  self.skipWaiting();
});

// Event 'activate': Hapus cache lama yang sudah tidak terpakai.
self.addEventListener('activate', (event) => {
  console.log('SW: Mengaktifkan...');
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cacheName) => {
          if (cacheName !== CACHE_NAME) {
            console.log('SW: Menghapus cache lama:', cacheName);
            return caches.delete(cacheName);
          }
        })
      );
    })
  );
  return self.clients.claim();
});
// HAPUS event listener 'fetch' LAMA ANDA DAN GANTI DENGAN INI:
self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // 1) HTML navigasi → network-first (ambil baru; fallback ke cache saat offline)
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).catch(() => caches.match(request))
    );
    return;
  }

  // 2) API / respon dinamis (punya query ?action=... atau Accept JSON) → network-only
  const isApi = url.searchParams.has('action') || (request.headers.get('accept') || '').includes('application/json');
  if (isApi) {
    event.respondWith(fetch(request));
    return;
  }

// 2.1) Semua file .php → network-only (hindari tertangkap cache)
if (url.pathname.endsWith('.php')) {
  event.respondWith(fetch(request));
  return;
}


  // 3) Aset statis → cache-first, tapi hormati header no-store/no-cache
  event.respondWith(
    caches.match(request).then((cached) => {
      if (cached) return cached;

      return fetch(request).then((netRes) => {
        const cc = (netRes.headers.get('Cache-Control') || '').toLowerCase();
        const isNoStore = cc.includes('no-store') || cc.includes('no-cache');
        if (!isNoStore && request.method === 'GET' && netRes.ok) {
          const copy = netRes.clone();
          caches.open(CACHE_NAME).then((c) => c.put(request, copy));
        }
        return netRes;
      });
    })
  );
});


// ▼▼▼ TAMBAHKAN BLOK BARU INI DI AKHIR FILE ▼▼▼

// Event 'push': Menerima notifikasi dari server dan menampilkannya.
self.addEventListener('push', e => {
    const data = e.data.json();
    console.log('SW: Push Notification diterima!', data);

    const title = data.title || 'QuizB';
    const options = {
        body: data.body || 'Ada notifikasi baru untukmu!',
        icon: '/favicon.png', // Ikon yang muncul di notifikasi
        badge: '/favicon.png' // Ikon kecil di status bar (Android)
    };

    // Menampilkan notifikasi ke pengguna
    e.waitUntil(self.registration.showNotification(title, options));
});