<?php

function html_head()
{
  global $CONFIG;
  $u = $_SESSION['user'] ?? null;
  $cid = h($CONFIG['GOOGLE_CLIENT_ID']);


  // ▼▼▼ GANTI SELURUH BLOK INI ▼▼▼
  $unread_messages = 0;
  $unread_notifications = 0;
  if ($u) {
    // Hitung pesan pribadi yang belum dibaca
    $unread_messages = (int)q("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0", [uid()])->fetchColumn();

    // Hitung notifikasi broadcast yang belum dibaca
    $total_broadcast = (int)q("SELECT COUNT(*) FROM broadcast_notifications")->fetchColumn();
    $read_broadcast = (int)q("SELECT COUNT(*) FROM user_notification_reads WHERE user_id = ?", [uid()])->fetchColumn();
    $unread_broadcast = $total_broadcast - $read_broadcast;

    // Hitung notifikasi personal (tugas, dll) yang belum dibaca
    $unread_personal = (int)q("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0", [uid()])->fetchColumn();

    // Jumlahkan keduanya
    $unread_notifications = $unread_broadcast + $unread_personal;
  }
  // ▲▲▲ AKHIR BLOK PENGGANTIAN ▲▲▲



  echo '<!doctype html><html lang="id"><head>';
  echo '<link rel="icon" type="image/x-icon" href="/favicon.png">
  <link rel="shortcut icon" href="/favicon.png">';
  echo '<script>
(function(){
  var t = localStorage.getItem("quizb_theme") || "light";
  document.documentElement.setAttribute("data-bs-theme", t);
})();
</script>';

  echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
  // 1. Link ke file manifest
  echo '<link rel="manifest" href="/manifest.json">';

  // 2. Script untuk mendaftarkan Service Worker
  echo <<<JS
  <script>
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', function() {
        navigator.serviceWorker.register('/sw.js').then(function(registration) {
          console.log('ServiceWorker registration successful with scope: ', registration.scope);
        }, function(err) {
          console.log('ServiceWorker registration failed: ', err);
        });
      });
    }
  </script>
  JS;


  // ▼▼▼ PASTE SELURUH BLOK KODE DI BAWAH INI ▼▼▼

  // --- Logika Cerdas untuk Judul & Deskripsi SEO ---
  $page = $_GET['page'] ?? 'home';

  // Default untuk Homepage dan halaman lain
  $og_title = 'QuizB | Kuis Berkah: Asah Pengetahuan, Tantang Temanmu!';
  $og_description = 'Uji wawasanmu dengan kuis seru di berbagai bidang! Dari pengetahuan umum hingga agama. Buat rekor baru dan tantang temanmu untuk adu skor di QuizB!';
  $og_image = 'https://quizb.my.id/og-image.png'; // PENTING: Buat gambar ini!
  $og_url = canonical_url();

  // Kustomisasi jika sedang di halaman kuis spesifik
  if ($page === 'play' && !empty($_GET['title_id'])) {
    $title_info = q("SELECT title FROM quiz_titles WHERE id=? AND deleted_at IS NULL", [$_GET['title_id']])->fetch();
    if ($title_info) {
      $og_title = 'Mulai Kuis: ' . h($title_info['title']) . ' | QuizB';
      $og_description = 'Seberapa jauh pengetahuanmu tentang ' . h($title_info['title']) . '? Mainkan kuisnya sekarang di QuizB dan raih skor tertinggi!';
    }
  }

  // --- Output Tag Meta ke HTML ---

  // Untuk Google Search & Tab Browser
  echo '<title>' . h($og_title) . '</title>';
  echo '<meta name="description" content="' . h($og_description) . '">';

  // Untuk WhatsApp, Facebook, Telegram (Open Graph)
  echo '<meta property="og:title" content="' . h($og_title) . '" />';
  echo '<meta property="og:description" content="' . h($og_description) . '" />';
  echo '<meta property="og:type" content="website" />';
  echo '<meta property="og:url" content="' . h($og_url) . '" />';
  echo '<meta property="og:image" content="' . h($og_image) . '" />';
  echo '<meta property="og:image:width" content="1200" />';
  echo '<meta property="og:image:height" content="630" />';
  echo '<meta property="og:site_name" content="QuizB" />';

  // Untuk Twitter Card
  echo '<meta name="twitter:card" content="summary_large_image">';
  echo '<meta name="twitter:title" content="' . h($og_title) . '">';
  echo '<meta name="twitter:description" content="' . h($og_description) . '">';
  echo '<meta name="twitter:image" content="' . h($og_image) . '">';

  // ▲▲▲ AKHIR BLOK KODE UNTUK DI-PASTE ▲▲▲


  // Modern typography: Plus Jakarta Sans (with system fallbacks)
  echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
  echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'; 
  echo '<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">';

  // Base typography tokens and hierarchy
  echo '<style>
    :root{ 
      --font-sans: "Plus Jakarta Sans", Inter, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", "Apple Color Emoji", "Segoe UI Emoji";
    }
    body{ 
      font-family: var(--font-sans);
      font-size: 16px; 
      line-height: 1.6; 
      letter-spacing: 0; 
      -webkit-font-smoothing: antialiased; 
      -moz-osx-font-smoothing: grayscale;
    }
    h1,h2,h3,h4,h5,h6,.h1,.h2,.h3,.h4,.h5,.h6{ 
      font-family: var(--font-sans); 
      line-height: 1.25; 
      letter-spacing: -0.01em; 
      font-weight: 700;
    }
    h1,.h1{ font-weight: 800; }
    /* Fluid sizes with xl clamps */
    h1,.h1{ font-size: calc(1.375rem + 1.5vw); }
    h2,.h2{ font-size: calc(1.325rem + 0.9vw); }
    h3,.h3{ font-size: calc(1.3rem + 0.6vw); }
    @media (min-width: 1200px){
      h1,.h1{ font-size: 2.25rem; }
      h2,.h2{ font-size: 1.75rem; }
      h3,.h3{ font-size: 1.5rem; }
    }
    small,.small{ letter-spacing: .005em; }
    .btn{ letter-spacing: .01em; }
  </style>';

  echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';

  // Design tokens & theming (aliases to Bootstrap variables where possible)
  echo '<style>
    :root{
      /* Color aliases */
      --surface-1: var(--bs-body-bg);
      --surface-2: var(--bs-tertiary-bg);
      --border-1: var(--bs-border-color);
      --text-1: var(--bs-body-color);
      --text-2: var(--bs-secondary-color);
      --brand: var(--bs-primary);
      --brand-contrast: #ffffff;

      /* Spacing scale */
      --space-1: .25rem; /* 4px */
      --space-2: .5rem;  /* 8px */
      --space-3: .75rem; /* 12px */
      --space-4: 1rem;   /* 16px */
      --space-5: 1.5rem; /* 24px */
      --space-6: 2rem;   /* 32px */

      /* Radius */
      --radius-xs: .25rem;
      --radius-sm: .375rem;
      --radius-md: .5rem;
      --radius-lg: .75rem;
      --radius-xl: 1rem;

      /* Shadows */
      --shadow-xs: 0 1px 2px rgba(0,0,0,.05);
      --shadow-sm: 0 2px 8px rgba(0,0,0,.08);
      --shadow-md: 0 6px 18px rgba(0,0,0,.12);

      /* Motion */
      --easing-standard: cubic-bezier(.2,.8,.2,1);
      --transition-fast: 120ms var(--easing-standard);
      --transition-base: 180ms var(--easing-standard);

      /* Map to Bootstrap radii */
      --bs-border-radius: var(--radius-md);
      --bs-border-radius-sm: var(--radius-sm);
      --bs-border-radius-lg: var(--radius-lg);
      --bs-border-radius-xl: var(--radius-xl);
    }

    [data-bs-theme="dark"]{
      /* Keep aliases tied to Bootstrap dark variables */
      --surface-1: var(--bs-body-bg);
      --surface-2: var(--bs-tertiary-bg);
      --border-1: var(--bs-border-color);
      --text-1: var(--bs-body-color);
      --text-2: var(--bs-secondary-color);
      --brand-contrast: #ffffff;
    }

    /* Components polish using tokens */
    .card{ border-radius: var(--bs-border-radius-lg); box-shadow: var(--shadow-xs); }
    .card.hover, .card:hover{ box-shadow: var(--shadow-sm); transition: box-shadow var(--transition-base); }
    .btn{ border-radius: var(--bs-border-radius); transition: box-shadow var(--transition-fast), transform var(--transition-fast); }
    .btn:hover{ transform: translateY(-1px); box-shadow: var(--shadow-sm); }
    .btn:active{ transform: translateY(0); box-shadow: var(--shadow-xs); }
    .badge{ border-radius: calc(var(--bs-border-radius) - 2px); }
    .form-control, .dropdown-menu, .offcanvas, .modal-content{ border-radius: var(--bs-border-radius-lg); }
    .progress{ background-color: var(--surface-2); }
    .progress-bar{ transition: width var(--transition-base); }
  </style>';

  // Styling khusus backend: hilangkan garis bawah pada link
  echo '<style>
    .backend a,
    .backend a:visited,
    .backend a:hover,
    .backend a:focus,
    .backend a:active {
      text-decoration: none !important;
    }
    .backend .list-group-item a,
    .backend .list-group-item a:visited,
    .backend .list-group-item a:hover,
    .backend .list-group-item a:focus,
    .backend .list-group-item a:active {
      text-decoration: none !important;
      color: inherit;
    }
  </style>';

  // Color theme: Emerald Modern (clean, fresh)
  echo '<style>
    :root {
      /* Brand */
      --brand: #0fb26b; /* Emerald vivid */
      --accent: #06b6d4; /* Cyan accent */
      --success: var(--brand);
      --warning: #f59e0b;
      --danger:  #ef4444;

      /* Neutrals (light) */
      --neutral-0: #F6FBF8; /* page background */
      --neutral-50: #F0F9F4;
      --neutral-100: #FFFFFF; /* cards / surfaces */
      --neutral-200: #E6F0EA; /* borders */
      --neutral-900: #0f172a; /* text */

      /* Bootstrap mapping (light) */
      --bs-primary: var(--brand);
      --bs-link-color: var(--brand);
      --bs-success: var(--success);
      --bs-warning: var(--warning);
      --bs-danger:  var(--danger);
      --bs-body-bg: var(--neutral-0);
      --bs-tertiary-bg: var(--neutral-100);
      --bs-border-color: var(--neutral-200);
      --bs-body-color: var(--neutral-900);
      --bs-secondary-color: #6b7280;
    }

    [data-bs-theme="dark"] {
      /* Dark mode tweaks */
      --brand: #0fb26b;
      --accent: #06b6d4;
      --success: var(--brand);
      --warning: #f59e0b;
      --danger: #ef4444;

      --bs-body-bg: hsl(220 18% 8%);
      --bs-tertiary-bg: hsl(220 16% 12%);
      --bs-border-color: hsl(220 12% 18%);
      --bs-body-color: hsl(220 20% 92%);
      --bs-secondary-color: hsl(220 12% 70%);
      --bs-primary: var(--brand);
      --bs-link-color: var(--brand);
    }

    /* Button hover subtle effect */
    .btn-primary:hover, .btn-primary:focus { filter: brightness(0.95); }
    a:hover { color: var(--bs-link-color); }
  </style>';
  // SweetAlert2 CDN
  echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>';

  // ===========================================
  // Inisialisasi Google Sign-In
  // ===========================================
  // GANTI KESELURUHAN BLOK SCRIPT GOOGLE YANG LAMA
  // DENGAN BLOK KODE FINAL YANG SUDAH DIPERBAIKI INI:

  echo '<script src="https://accounts.google.com/gsi/client" async defer></script>';

  echo "<script>
  // ==========================================================
  // LOGIKA BARU & ANDAL UNTUK GOOGLE SIGN-IN
  // ==========================================================

  // --- Step 1: Siapkan penanda status ---
  let isGoogleScriptReady = false;
  let isPageLoaded = false;

  // --- Step 2: Fungsi callback setelah login berhasil (tidak berubah) ---
  function onGoogleSignIn(r) {
    const f = document.createElement('form');f.method = 'POST';f.action = '?action=google_login';
    const i = document.createElement('input');i.type = 'hidden';i.name = 'credential';i.value = r.credential;
    f.appendChild(i);document.body.appendChild(f);f.submit();
  }

  // --- Step 3: Fungsi inti untuk merender semua tombol yang ada di halaman ---
  function renderAllGoogleButtons() {
    if (!window.google || !google.accounts || !google.accounts.id) {
      // Jika library Google belum siap, kita tidak bisa melakukan apa-apa.
      return;
    }
    
    // Cari semua placeholder tombol di halaman
    document.querySelectorAll('.g_id_signin').forEach(div => {
      // Hanya render jika div tersebut masih kosong untuk mencegah tombol duplikat
      if (div.childElementCount === 0) { 
        google.accounts.id.renderButton(div, {
            type: div.dataset.type || 'standard', size: div.dataset.size || 'large',
            theme: div.dataset.theme || 'outline', text: div.dataset.text || 'signin_with',
            shape: div.dataset.shape || 'rectangular', logo_alignment: div.dataset.logoAlignment || 'left'
        });
      }
    });
  }

  // --- Step 4: Pemicu yang akan dipanggil dari dua tempat berbeda ---
  function tryRenderInitialButtons() {
    // Fungsi ini hanya akan berjalan jika KEDUA kondisi terpenuhi:
    // 1. Halaman sudah siap.
    // 2. Skrip Google sudah siap.
    if (isPageLoaded && isGoogleScriptReady) {
      renderAllGoogleButtons();
    }
  }

  // --- Step 5: Fungsi yang dipanggil oleh skrip Google setelah selesai dimuat ---
(function waitForGIS(){
  if (window.google && google.accounts && google.accounts.id) {
    try {
      google.accounts.id.initialize({
        client_id: '" . h($CONFIG['GOOGLE_CLIENT_ID']) . "',
        callback: onGoogleSignIn,
        auto_select: false,
        cancel_on_tap_outside: true
      });
      isGoogleScriptReady = true;
      tryRenderInitialButtons();
    } catch(e) {
      console.error('Init GIS error:', e);
    }
    return;
  }
  // Cek ulang setiap 150ms sampai GIS siap
  setTimeout(waitForGIS, 150);
})();

  // --- Step 6: Listener yang dijalankan setelah SEMUA konten halaman (termasuk gambar) selesai ---
  window.addEventListener('load', () => {
    isPageLoaded = true;      // Tandai halaman sudah siap
    tryRenderInitialButtons();    // Coba render, mungkin skrip Google sudah siap duluan
  });

  // ==========================================================
  // AKHIR DARI BLOK GOOGLE SIGN-IN
  // ==========================================================
</script>";
  // ===========================================
  // Akhir Inisialisasi Google Sign-In
  // ===========================================

  echo '<style>.brand{font-weight:800;letter-spacing:.3px}.avatar{width:32px;height:32px;border-radius:50%;object-fit:cover}.quiz-card{transition:transform .1s}.quiz-card:hover{transform:translateY(-2px)}</style>';
  echo '<style>
    .brand{font-weight:800;letter-spacing:.3px}
    .avatar{width:32px;height:32px;border-radius:50%;object-fit:cover}
    .quiz-card{transition:transform .1s}
    .quiz-card:hover{transform:translateY(-2px)}

    /* ===== Kustomisasi Tombol Pilihan Jawaban Mode Instan ===== */
    .answer-btn {
        padding: 1rem 2rem;
        font-size: 1.1rem;
        font-weight: 500;
        border-width: 3px;
    }

    .d-grid.gap-2 {
        gap: 2rem !important; /* Memberi jarak antar tombol */
    }

</style>';


  echo '<style>
/* ========== THEME TOKENS (header) ========== */



/* ========== HAMBURGER → "X" ANIMATION ========== */
.navbar .navbar-toggler.hamburger{
  padding: 8px 10px;
  border: 1px solid var(--bs-navbar-toggler-border-color, rgba(0,0,0,.1));
  border-radius: .5rem;
  background: transparent;
}

/* Container 3 garis */
.navbar .hamburger-lines{
  position: relative;
  display: inline-block;
  width: 26px;
  height: 18px;
}

/* Garis-garis */
.navbar .hamburger-lines span{
  position: absolute;
  left: 0; right: 0;
  height: 2px;
  background: var(--header-fg) !important; /* ikut tema terang/gelap */
  border-radius: 2px;
  transition: transform 200ms ease, opacity 150ms ease;
}

/* Posisi default */
.navbar .hamburger-lines span:nth-child(1){ top: 0; }
.navbar .hamburger-lines span:nth-child(2){ top: 8px; }
.navbar .hamburger-lines span:nth-child(3){ top: 16px; }

/* Saat menu terbuka (Bootstrap set aria-expanded=true) */
.navbar .navbar-toggler[aria-expanded="true"] .hamburger-lines span:nth-child(1){
  transform: translateY(8px) rotate(45deg);
}
.navbar .navbar-toggler[aria-expanded="true"] .hamburger-lines span:nth-child(2){
  opacity: 0;
}
.navbar .navbar-toggler[aria-expanded="true"] .hamburger-lines span:nth-child(3){
  transform: translateY(-8px) rotate(-45deg);
}

/* Kurangi animasi bila prefer-reduced-motion */
@media (prefers-reduced-motion: reduce){
  .navbar .hamburger-lines span{ transition: none; }
}

:root{
  --header-bg:#ffffff;
  --header-fg:#111111; /* warna teks "quizb" & ikon menu (mode terang) */
}
[data-bs-theme="dark"]{
  --header-bg:#0b0f14; /* latar header saat gelap */
  --header-fg:#f5f7fa; /* warna teks "quizb" & ikon menu saat gelap */
}

/* ========== Bootstrap Navbar: sinkron dengan tema ========== */
.navbar{
  background:var(--header-bg)!important;
  /* atur variabel warna bawaan navbar Bootstrap */
  --bs-navbar-color:var(--header-fg);
  --bs-navbar-hover-color:var(--header-fg);
  --bs-navbar-active-color:var(--header-fg);
  --bs-navbar-brand-color:var(--header-fg);
  /* border toggler */
  --bs-navbar-toggler-border-color:rgba(255,255,255,.25);
}
/* Toggler icon (hamburger) untuk LIGHT */
[data-bs-theme="light"] .navbar{
  --bs-navbar-toggler-border-color:rgba(0,0,0,.1);
  --bs-navbar-toggler-icon-bg:url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 30 30\'%3E%3Cpath stroke=\'rgba(17,17,17,0.85)\' stroke-width=\'2\' stroke-linecap=\'round\' d=\'M4 7h22M4 15h22M4 23h22\'/%3E%3C/svg%3E");
}
/* Toggler icon (hamburger) untuk DARK */
[data-bs-theme="dark"] .navbar{
  --bs-navbar-toggler-icon-bg:url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 30 30\'%3E%3Cpath stroke=\'rgba(245,247,250,0.95)\' stroke-width=\'2\' stroke-linecap=\'round\' d=\'M4 7h22M4 15h22M4 23h22\'/%3E%3C/svg%3E");
}

/* Teks judul/brand: "quizb" */
.navbar .navbar-brand.brand{
  color:var(--header-fg)!important;
  font-weight:800;
  letter-spacing:.3px;
  text-shadow:0 0 1px rgba(0,0,0,.25);
}

/* Pastikan Bootstrap pakai icon yang kita set */
.navbar .navbar-toggler .navbar-toggler-icon{
  background-image:var(--bs-navbar-toggler-icon-bg)!important;
}


<style>
/* Sedikit mencerahkan latar navbar saat dark mode */
[data-bs-theme="dark"] .navbar{
  background:#0e141b !important;
}

</style>';

  // ===================================================================
  // TAMBAHKAN BLOK STYLE BARU INI UNTUK POSISI BADGE
  echo '<style>
    .nav-item-icon {
        position: relative;
    }
    .nav-item-icon .badge {
        position: absolute;
        top: -5px;
        right: -8px;
        font-size: 0.6em;
        padding: 0.25em 0.4em;
    }
  </style>';
  // ===================================================================

  // ===================================================================
  // ▼▼▼ 1. LETAKKAN BLOK CSS INI DI DALAM html_head() ▼▼▼
  // ===================================================================
  echo <<<CSS
<style>
  /* ======== LOADER ANIMASI KUSTOM QUIZB ======== */
  .quizb-loader-container {
      position: fixed;
      inset: 0;
      z-index: 9999;
      background-color: rgba(255, 255, 255, 0.85);
      display: flex;
      align-items: center;
      justify-content: center;
      opacity: 1;
      visibility: visible;
      transition: opacity 0.5s ease, visibility 0.5s ease;
  }
  [data-bs-theme="dark"] .quizb-loader-container {
      background-color: rgba(11, 15, 20, 0.85);
  }
  .quizb-loader {
      width: 80px;
      height: 80px;
  }
  .quizb-loader .q-shape {
      fill: var(--bs-primary);
  }
  .quizb-loader .dot {
      fill: var(--bs-primary);
      animation: pulse 1.4s infinite ease-in-out both;
  }
  .quizb-loader .dot-1 { animation-delay: -0.32s; }
  .quizb-loader .dot-2 { animation-delay: -0.16s; }
  .quizb-loader-container.hidden {
      opacity: 0;
      visibility: hidden;
  }
  @keyframes pulse {
      0%, 80%, 100% { transform: scale(0); } 
      40% { transform: scale(1.0); }
  }
</style>
CSS;
  // ===================================================================
  // ▲▲▲ AKHIR BLOK CSS ▲▲▲
  // ===================================================================

  echo '<link rel="canonical" href="' . htmlspecialchars(canonical_url(), ENT_QUOTES, 'UTF-8') . '" />';


  // ▼▼▼ TAMBAHKAN KODE INI ▼▼▼
  // --- Structured Data untuk Nama Situs ---
  $website_schema = [
    "@context" => "https://schema.org",
    "@type" => "WebSite",
    "name" => "QuizB",
    "url" => base_url() . "/"
  ];
  echo '<script type="application/ld+json">' . json_encode($website_schema, JSON_UNESCAPED_SLASHES) . '</script>';
  // ▲▲▲ AKHIR DARI KODE TAMBAHAN ▲▲▲


  echo_breadcrumb_jsonld();

  echo "
  <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','GTM-WQ8HS267');</script>
<script async src='https://www.googletagmanager.com/gtag/js?id=G-VTN3LNDWCT'></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'G-VTN3LNDWCT');
</script>
  
  ";

  // ===================================================================
  // INI BLOK YANG DIMODIFIKASI - DULU HANYA HEADER & FOOTER
  echo '<style>
    body {
      /* Memberi ruang di bagian atas body agar konten tidak tertutup header */
      padding-top: 75px; 
    }
    
    .navbar {
      /* Membuat header menempel di bagian paling atas viewport */
      position: fixed;
      top: 0;
      right: 0;
      left: 0;
      z-index: 1030; /* Pastikan header di atas elemen lain */
    }

    /* Ini footer teks untuk desktop */
    footer.desktop-footer {
      text-align:center; 
      padding:15px; 
      margin-top:40px; 
      font-size:14px; 
      color:#666; 
      border-top:1px solid #ddd;
    }
  </style>';

  // Hide header/footer/menu during active play session (all modes)
  // NOTE: Must be togglable for SPA navigation (mobile footer issue after leaving summary).
  echo '<style>
    body.play-active .navbar,
    body.play-active .mobile-nav-footer,
    body.play-active footer.desktop-footer { display: none !important; }
    body.play-active { padding-top: 0 !important; padding-bottom: 0 !important; }
    body.play-active #main-content-container { margin-top: 0 !important; }
  </style>';

  /* ===== CSS BARU UNTUK FOOTER MOBILE ===== */
  echo '<style>
    /* Hanya terapkan gaya ini pada layar kecil (mobile) */
    @media (max-width: 767.98px) {
      body {
        /* Tambahkan padding bawah seukuran tinggi footer agar konten tidak tertutup */
        padding-bottom: 70px; 
      }
      
      .mobile-nav-footer {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        z-index: 1030;
        background: var(--bs-body-bg);
        border-top: 1px solid var(--bs-border-color);
        box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
      }

      .mobile-nav-footer .nav-container {
        display: flex;
        justify-content: space-around;
        align-items: center;
        padding: 4px 0;
      }

      .mobile-nav-footer .nav-link {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        flex-grow: 1;
        padding: 8px 4px;
        text-decoration: none;
        color: var(--bs-body-color);
        font-size: 0.75rem; /* Ukuran teks label */
        transition: color 0.2s;
      }
      
      .mobile-nav-footer .nav-link:hover {
        color: var(--bs-primary);
      }
      
      .mobile-nav-footer .nav-link svg {
        margin-bottom: 4px; /* Jarak antara ikon dan teks */
      }
    }
  </style>';
  // ===================================================================





  // Di dalam fungsi html_head(), tambahkan style ini
  echo '<style>
    /* Styling untuk daftar pencarian di halaman utama */
    .search-item-subtheme {
        padding-left: 1.5rem;
        border-left: 2px solid #e9ecef;
    }
    [data-bs-theme="dark"] .search-item-subtheme {
        border-left-color: #343a40;
    }
    .search-item-title {
        padding-left: 1.5rem;
    }
    
    
    .sidebar-widget .list-group-item {
    margin-bottom: 8px; /* Memberi jarak antar item */
    border-radius: .5rem !important; /* Membuat sudut lebih melengkung */
    border: 1px solid var(--bs-border-color-translucent);
    transition: all 0.2s ease-in-out;
  }
  .sidebar-widget .list-group-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    z-index: 2;
  }
  [data-bs-theme="dark"] .sidebar-widget .list-group-item:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
  }
  .widget-title {
    font-size: 1.1rem;
    font-weight: 600;
    margin-bottom: 1rem;
    color: var(--bs-emphasis-color);
  }
  
  
  /* Terapkan gaya ini HANYA pada layar dengan lebar maksimal 991.98px (di bawah breakpoint large Bootstrap) */
  @media (max-width: 991.98px) {
    .sidebar-separator-mobile {
      margin-top: 2.5rem; /* Beri jarak atas yang cukup */
      padding-top: 2rem; /* Beri ruang di atas konten sidebar */
      border-top: 1px solid var(--bs-border-color); /* Garis pemisah horizontal */
    }
  }
</style>';



  // ===================================================================
  // ▼▼▼ TAMBAHKAN BLOK CSS BARU INI DI SINI ▼▼▼
  // ===================================================================
  echo '<style>
    /* Styling untuk Container Utama Kuis */
    .quiz-container {
      max-width: 800px;
      margin: 2rem auto;
      padding: 1.5rem;
      background-color: var(--bs-body-bg);
      border-radius: 16px;
      /* box-shadow: 0 8px 30px rgba(0,0,0,0.1); */
    }

    /* Kotak untuk Pertanyaan */
    .quiz-question-box {
      padding: 1.5rem;
      margin-bottom: 2rem;
      background-color: var(--bs-tertiary-bg);
      border: 1px solid var(--bs-border-color-translucent);
      border-radius: 12px;
      text-align: center;
    }
    .quiz-question-number {
      font-size: 0.9rem;
      font-weight: 500;
      color: var(--bs-secondary-color);
      margin-bottom: 0.5rem;
    }
    .quiz-question-text {
      font-size: 1.5rem;
      font-weight: 600;
      color: var(--bs-emphasis-color);
      line-height: 1.4;
    }

    /* Grid untuk Pilihan Jawaban (Layout 2 Kolom) */
    .quiz-choices-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr); /* Default 2 kolom */
      gap: 1rem; /* Jarak antar pilihan */
    }

    /* Styling untuk setiap item pilihan jawaban */
    .quiz-choice-item {
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1.25rem 1rem;
      font-size: 1.1rem;
      font-weight: 500;
      text-align: center;
      border: 2px solid var(--bs-border-color);
      border-radius: 12px;
      background-color: var(--bs-body-bg);
      color: var(--bs-body-color);
      cursor: pointer;
      transition: all 0.2s ease-in-out;
      text-decoration: none; /* Menghilangkan garis bawah jika menggunakan <a> */
    }
    .quiz-choice-item:hover {
      transform: translateY(-4px);
      box-shadow: 0 4px 15px rgba(0,0,0,0.08);
      border-color: var(--bs-primary);
      color: var(--bs-primary);
    }
    .quiz-choice-item:focus {
      outline: none;
      box-shadow: 0 0 0 3px var(--bs-primary-border-subtle);
      border-color: var(--bs-primary);
    }
    
    /* Jika hanya ada 3 pilihan, pilihan terakhir akan memanjang */
    .quiz-choice-item:nth-child(odd):last-child {
        grid-column: 1 / -1;
    }

    /* Responsif untuk layar kecil (Mobile) */
    @media (max-width: 576px) {
      .quiz-choices-grid {
        grid-template-columns: 1fr; /* Jadi 1 kolom di layar kecil */
      }
      .quiz-question-text {
        font-size: 1.25rem;
      }
      .quiz-container {
        padding: 1rem;
      }
    }
  </style>';
  // ===================================================================
  // ▲▲▲ AKHIR DARI BLOK CSS BARU ▲▲▲
  // ===================================================================

  // ===================================================================
  // ▼▼▼ TAMBAHKAN BLOK CSS BARU INI DI SINI ▼▼▼
  // ===================================================================
  echo '<style>
    /* Styling untuk logo tengah di header mobile */
    .navbar-mobile-center-logo {
        position: absolute;
        left: 50%;
        top: -10px; /* Mengatur seberapa jauh logo menjorok ke bawah */
        transform: translateX(-50%);
        background-color: var(--bs-body-bg); /* Warna latar mengikuti tema */
        padding: 8px; /* Jarak di sekitar gambar */
        border-radius: 40%; /* Membuatnya bulat */
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        border: 1px solid var(--bs-border-color);
        line-height: 0; /* Menghilangkan spasi ekstra */
        z-index: 1040; /* Memastikan logo di atas elemen lain */
    }

    .navbar-mobile-center-logo img {
        width: 48px;  /* Ukuran gambar logo */
        height: 48px;
    }

    /* Menambah ruang di atas konten pada tampilan mobile agar tidak tertutup logo */
    @media (max-width: 991.98px) {
        body {
            /* padding-top awal adalah 75px, kita tambah sedikit ruang */
            padding-top: 80px;
        }
    }
    



    /* ▼▼▼ TAMBAHKAN INI ▼▼▼ */
    /* Menambah tinggi navbar agar garis bawahnya sejajar logo */
    .navbar {
        min-height: 60px;
    }
    /* ▲▲▲ AKHIR DARI TAMBAHAN ▲▲▲ */
  }
  
  
  
  </style>';
  // ===================================================================
  // ▲▲▲ AKHIR DARI BLOK CSS BARU ▲▲▲




  // ===================================================================
  // TAMBAHKAN BLOK STYLE BARU INI UNTUK POSISI BADGE
  echo '<style>
    .nav-item-icon {
        position: relative;
    }
    .nav-item-icon .badge {
        position: absolute;
        top: -5px;
        right: -8px;
        font-size: 0.6em;
        padding: 0.25em 0.4em;
    }
    
    /* CSS BARU UNTUK BADGE DI NAVIGASI MOBILE */
    .mobile-nav-footer .nav-link .badge {
        position: absolute;
        top: 0;
        right: 15px; /* Sesuaikan posisi horizontal */
    }
  </style>';
  // ===================================================================
  // Script untuk tabel interaktif (TIDAK BERUBAH)
  echo <<<'JS'
<script>
function setupTable(opts){
    var PAGE_SIZE = opts.pageSize || 10;
    var input   = document.getElementById(opts.inputId);
    var table   = document.getElementById(opts.tableId);
    var pager   = document.getElementById(opts.pagerId);
    var badge   = document.getElementById(opts.countBadgeId);
    if(!table) return;
    var tbody   = table.querySelector("tbody");
    var rows    = Array.from(tbody.querySelectorAll("tr"));
    var q       = "";
    var page    = 1;
    var filtered = rows.slice();
    function applyFilter(){
      q = (input && input.value ? input.value.toLowerCase() : "");
      filtered = rows.filter(function(tr){
        var txt = (tr.getAttribute("data-search") || tr.innerText || "").toLowerCase();
        return txt.indexOf(q) !== -1;
      });
      if (badge) badge.textContent = filtered.length;
      page = 1;
      applyPaginate();
    }
    function applyPaginate(){
      var total = filtered.length;
      var pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
      if(page < 1) page = 1;
      if(page > pages) page = pages;
      rows.forEach(function(tr){ tr.style.display = "none"; });
      var start = (page - 1) * PAGE_SIZE;
      var end   = Math.min(start + PAGE_SIZE, total);
      for(var i=start; i<end; i++){
        filtered[i].style.display = "";
      }
      if(pager){
        var pEl = pager.querySelector('[data-role="page"]');
        var psEl = pager.querySelector('[data-role="pages"]');
        if(pEl) pEl.textContent = String(pages === 0 ? 0 : page);
        if(psEl) psEl.textContent = String(pages);
        var btnPrev = pager.querySelector('[data-page="prev"]');
        var btnNext = pager.querySelector('[data-page="next"]');
        if(btnPrev) btnPrev.disabled = (page <= 1 || pages <= 1);
        if(btnNext) btnNext.disabled = (page >= pages || pages <= 1);
      }
    }
    if(input) input.addEventListener("input", applyFilter);
    if(pager){
      pager.addEventListener("click", function(ev){
        var btn = ev.target.closest("[data-page]");
        if(!btn) return;
        var dir = btn.getAttribute("data-page");
        if(dir === "prev") page--;
        if(dir === "next") page++;
        applyPaginate();
      });
    }
    applyFilter();
}




// === Namespace state per judul/subtema (tanpa PHP variable) ===
(function () {
  const url = new URL(location.href);
  const TITLE_ID    = parseInt(url.searchParams.get('title_id')    || '0', 10);
  const SUBTHEME_ID = parseInt(url.searchParams.get('subtheme_id') || '0', 10);
  const PREFIX = `quizb:${TITLE_ID}:${SUBTHEME_ID}:`;

  const NS_KEYS = new Set([
    'current_questions','answers','i','timer_remain','instant_session_id','assignment','picked'
  ]);

  const _getItem = localStorage.getItem.bind(localStorage);
  const _setItem = localStorage.setItem.bind(localStorage);
  const _removeItem = localStorage.removeItem.bind(localStorage);

  localStorage.getItem    = (k) => _getItem(NS_KEYS.has(k) ? (PREFIX + k) : k);
  localStorage.setItem    = (k,v)=> _setItem(NS_KEYS.has(k) ? (PREFIX + k) : k, v);
  localStorage.removeItem = (k) => _removeItem(NS_KEYS.has(k) ? (PREFIX + k) : k);

  ['current_questions','answers','i','timer_remain','assignment','picked','instant_session_id']
    .forEach(k => { try { _removeItem(k); } catch(e){} });
})();


// === Global fetch guard untuk endpoint PHP/action ===
(function () {
  const _fetch = window.fetch.bind(window);
  window.fetch = (input, init = {}) => {
    let urlObj;

    if (typeof input === 'string') {
      urlObj = new URL(input, location.origin);
    } else if (input instanceof Request) {
      urlObj = new URL(input.url);
    } else {
      return _fetch(input, init);
    }

    const isSameOrigin = (urlObj.origin === location.origin);
    const isPhp        = urlObj.pathname.endsWith('.php');
    const isAction     = urlObj.searchParams.has('action');

    if (isSameOrigin && (isPhp || isAction)) {
      // Tambah cache-buster
      urlObj.searchParams.set('_ts', Date.now().toString());

      // Paksa no-store
      init = init || {};
      init.cache = 'no-store';
      init.headers = new Headers(init.headers || {});
      init.headers.set('Cache-Control', 'no-store');

      // Rekonstruksi Request bila input awal berupa Request
      if (input instanceof Request) {
        input = new Request(urlObj.toString(), input);
      } else {
        input = urlObj.toString();
      }
    }

    return _fetch(input, init);
  };
})();
</script>
JS;


  // ===================================================================
  // ▼▼▼ PASTE BLOK HEADER BARU INI ▼▼▼
  // ===================================================================
  // Kembalikan output normal head/body — pastikan tiap view menutup div sendiri
  // Beri body class "backend" untuk halaman admin/kelola agar styling spesifik bisa diterapkan
  $page = $_GET['page'] ?? 'home';
  $backendPages = ['crud','qmanage','teacher_crud','teacher_qmanage','admin','bin'];
  // Anggap juga halaman profile sebagai backend jika yang melihat adalah admin
  $isBackend = in_array($page, $backendPages, true) || ($page === 'profile' && is_admin());
  $is_play_active = ($page === 'play') && isset($_SESSION['quiz']['session_id']);
  $bodyClasses = [];
  if ($isBackend) $bodyClasses[] = 'backend';
  if ($is_play_active) $bodyClasses[] = 'play-active';
  $bodyAttrClass = $bodyClasses ? (' class="' . implode(' ', $bodyClasses) . '"') : '';
  echo '</head><body' . $bodyAttrClass . ' data-page="' . h($page) . '">';

  // ===================================================================
  // ▼▼▼ 2. LETAKKAN BLOK HTML INI TEPAT SETELAH echo '</head><body>'; ▼▼▼
  // ===================================================================
  echo <<<HTML
<div class="quizb-loader-container hidden" id="pageLoader">
    <svg class="quizb-loader" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
        <path class="q-shape" d="M50,5C25.2,5,5,25.2,5,50s20.2,45,45,45s45-20.2,45-45S74.8,5,50,5z M50,86.5C29.9,86.5,13.5,70.1,13.5,50 S29.9,13.5,50,13.5S86.5,29.9,86.5,50S70.1,86.5,50,86.5z M68.5,43.8c-1.3-1.3-3.5-1.3-4.8,0L49,58.5l-6.7-6.7 c-1.3-1.3-3.5-1.3-4.8,0s-1.3,3.5,0,4.8l9,9c0.6,0.6,1.5,1,2.4,1s1.8-0.4,2.4-1l17-17C69.8,47.2,69.8,45.1,68.5,43.8z"/>
        <circle class="dot dot-1" cx="35" cy="50" r="5"/>
        <circle class="dot dot-2" cx="50" cy="50" r="5"/>
        <circle class="dot dot-3" cx="65" cy="50" r="5"/>
    </svg>
</div>
HTML;
  // ===================================================================
  // ▲▲▲ AKHIR BLOK HTML ▲▲▲
  // ===================================================================


  echo '<nav class="navbar navbar-expand-lg bg-body-tertiary border-bottom">';
  echo '  <div class="container position-relative">'; // Menambahkan position-relative

  // --- Tampilan Desktop (d-none d-lg-flex akan menyembunyikannya di mobile) ---
  echo '    <a class="navbar-brand brand d-none d-lg-block" href="./">' . h($CONFIG['APP_NAME']) . '</a>';

  // --- Tampilan Mobile (d-lg-none akan menyembunyikannya di desktop) ---
  // Logo di tengah
  echo '    <a href="./" class="navbar-mobile-center-logo d-lg-none">
            <img src="/favicon.png" alt="QuizB Logo">
          </a>';
  // Placeholder agar layout tidak rusak
  echo '    <div class="navbar-brand d-lg-none" aria-hidden="true" style="opacity: 0;"></div>';


  // --- Menu Kanan (HANYA UNTUK DESKTOP) ---
  echo '    <div class="collapse navbar-collapse d-none d-lg-flex" id="navDesktop">'; // Menggunakan d-none d-lg-flex
  echo '      <ul class="navbar-nav me-auto">';


  // ▼▼▼ UBAH BAGIAN INI ▼▼▼

  echo '        <li class="nav-item"><a class="nav-link" href="?page=themes">Pencarian</a></li>'; // Ini halaman baru Anda

  // ▲▲▲ AKHIR PERUBAHAN ▲▲▲


  if (is_admin()) {

    echo '    <li class="nav-item"><a class="nav-link" href="?page=admin">Backend</a></li>';
    echo '    <li class="nav-item"><a class="nav-link" href="?page=kelola_user">Kelola User</a></li>';
    echo '    <li class="nav-item"><a class="nav-link" href="?page=broadcast">Broadcast</a></li>';
    echo '    <li class="nav-item"><a class="nav-link" href="?page=bank_soal">Bank Soal</a></li>';
    echo '    <li class="nav-item"><a class="nav-link" href="?page=import_questions">Import Soal</a></li>';
    echo '    <li class="nav-item"><a class="nav-link" href="?page=bin">Bin</a></li>';

    // ▼▼▼ TAMBAHKAN BLOK BARU INI ▼▼▼
    // Tampilkan menu ini HANYA jika pengguna adalah Pengajar
if (($_SESSION['user']['role'] ?? '') === 'pengajar') {
       // >>> UBAH LINK INI <<<
      echo '    <li class="nav-item"><a class="nav-link" href="?page=kelola_institusi">Kelola Institusi & Kelas</a></li>';
    }
    // ▲▲▲ AKHIR BLOK BARU ▲▲▲

} else if ($u) {
    // Menu yang tampil untuk semua user login (non-admin)
    echo '    <li class="nav-item"><a class="nav-link" href="?page=challenges">Data Challenge</a></li>';

    // Cek peran pengguna untuk menampilkan menu spesifik
    $user_role = $_SESSION['user']['role'] ?? '';

    if ($user_role === 'pengajar') {
      // Tampilkan menu untuk Pengajar
      echo '<li class="nav-item"><a class="nav-link" href="?page=kelola_institusi">Kelola Institusi & Kelas</a></li>';
      echo '<li class="nav-item"><a class="nav-link" href="?page=teacher_bank_soal">Bank Soal Saya</a></li>';
      echo '<li class="nav-item"><a class="nav-link" href="?page=import_questions">Import Soal</a></li>';
      echo '<li class="nav-item"><a class="nav-link" href="?page=bin">Bin</a></li>';
    } elseif ($user_role === 'pelajar') {
      // ▼▼▼ INI MENU BARU UNTUK SISWA ▼▼▼
      echo '<li class="nav-item"><a class="nav-link" href="?page=student_tasks">Daftar Tugas</a></li>';
    }
  }
  echo '      </ul>';
  
  echo '      <div class="d-flex align-items-center gap-2">';
  if ($u) {
    echo '    <a class="btn btn-sm btn-outline-secondary d-none d-lg-block nav-item-icon" href="?page=pesan" title="Pesan">';
    echo '      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-envelope" viewBox="0 0 16 16"><path d="M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V4Zm2-1a1 1 0 0 0-1 1v.217l7 4.2 7-4.2V4a1 1 0 0 0-1-1H2Zm13 2.383-4.708 2.825L15 11.105V5.383Zm-.034 6.876-5.64-3.471L8 9.583l-1.326-.795-5.64 3.47A1 1 0 0 0 2 13h12a1 1 0 0 0 .966-.741ZM1 11.105l4.708-2.897L1 5.383v5.722Z"/></svg>';
    if ($unread_messages > 0) {
      echo '    <span class="badge bg-danger rounded-pill">' . $unread_messages . '</span>';
    }
    echo '    </a>';


    // ▼▼▼ TAMBAHKAN BLOK INI ▼▼▼
    echo '    <a class="btn btn-sm btn-outline-secondary d-none d-lg-block nav-item-icon" href="?page=notifikasi" title="Notifikasi">';
    echo '      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-bell" viewBox="0 0 16 16"><path d="M8 16a2 2 0 0 0 2-2H6a2 2 0 0 0 2 2zM8 1.918l-.797.161A4.002 4.002 0 0 0 4 6c0 .768-.214 2.622-.53 4.141-.318 1.52-.995 2.825-1.766 3.492C1.06 14.24 1.16 15 2 15h12c.84 0 .94-1.258.234-1.367-.77-.667-1.448-1.972-1.766-3.492C12.214 8.622 12 6.768 12 6a4.002 4.002 0 0 0-3.203-3.92L8 1.917zM14.22 12.23c.22.18.22.522 0 .704-.18.146-.443.146-.624 0l-.093-.08c-.743-.65-1.182-1.653-1.44-2.868-.258-1.216-.487-2.87-.487-3.786 0-2.096-1.555-3.79-3.5-3.79-.986 0-1.891.39-2.56.985l-.049.049c-.669.595-1.096 1.48-1.096 2.457 0 .916-.23 2.57-.488 3.786-.258 1.216-.697 2.218-1.44 2.868l-.094.08c-.18.146-.443.146-.624 0-.22-.182-.22-.524 0-.704.18-.147.444-.147.625 0l.093.08c.844-.725 1.365-1.92 1.64-3.328.278-1.41.523-3.23.523-4.203 0-2.553 1.846-4.618 4.2-4.618 2.354 0 4.2 2.065 4.2 4.618 0 .973.245 2.793.523 4.203.275 1.408.796 2.603 1.64 3.328l.093.08c.18.147.443.147.624 0z"/></svg>';
    if ($unread_notifications > 0) {
      echo '    <span class="badge bg-danger rounded-pill">' . $unread_notifications . '</span>';
    }
    echo '    </a>';
    // ▲▲▲ SELESAI ▲▲▲


  }
  echo '        <button id="themeToggleDesktop" class="btn btn-outline-secondary btn-sm" title="Ganti tema">🌓</button>';
  if ($u) {
  $feedback_link_label = is_admin() ? 'Kelola Umpan Balik' : 'Kirim Umpan Balik'; // BARIS KONDISIONAL
    echo '    <div class="nav-item dropdown">';
    echo '      <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">';
    echo '        <img class="avatar me-2" src="' . h($u['avatar']) . '" alt="Avatar">';
    echo          h($u['name']);
    echo '      </a>';
    echo '      <ul class="dropdown-menu dropdown-menu-end">';
    echo '        <li><a class="dropdown-item" href="?page=profile">Profil Saya</a></li>';
    echo '        <li><a class="dropdown-item" href="?page=setting">Setting</a></li>';
    echo '        <li><hr class="dropdown-divider"></li>';
    echo '        <li><a class="dropdown-item" href="?page=about">Tentang QuizB</a></li>';
    echo '        <li><a class="dropdown-item" href="?page=privacy">Privacy Policy</a></li>';
    echo '        <li><a class="dropdown-item" href="?page=feedback">' . $feedback_link_label . '</a></li>';
    echo '        <li><hr class="dropdown-divider"></li>';
    echo '        <li><a class="dropdown-item text-danger" href="?action=logout">Logout</a></li>';
    echo '      </ul>';
    echo '    </div>';
  } else {
    echo '    <a href="?page=about" class="btn btn-outline-secondary btn-sm" title="Tentang QuizB">Tentang</a>';
    echo '    <a href="?page=privacy" class="btn btn-outline-secondary btn-sm" title="Kebijakan Privasi">Privacy</a>';
    echo '    <a href="?page=feedback" class="btn btn-outline-secondary btn-sm" title="Kirim Umpan Balik">Feedback</a>'; // BIARKAN 'Feedback' karena ini tamu/non-admin
    echo google_btn($cid);
  }
  echo '      </div>'; // Penutup d-flex
  echo '    </div>';   // Penutup collapse
  echo '  </div>';     // Penutup container
  echo '</nav>';
  echo '<div class="container mt-4" id="main-content-container">';

  // Navbar polish: active/hover states and scroll shadow
  echo '<style>
    .navbar{ box-shadow: var(--shadow-xs); transition: box-shadow var(--transition-base), background-color var(--transition-base); }
    .navbar.is-scrolled{ box-shadow: var(--shadow-sm); }
    .navbar .nav-link{ color: var(--text-2); padding: .5rem .75rem; border-radius: var(--radius-sm); transition: background-color var(--transition-fast), color var(--transition-fast); }
    .navbar .nav-link:hover, .navbar .nav-link:focus{ color: var(--text-1); background: var(--surface-2); }
    .navbar .nav-link.active, .navbar .nav-link[aria-current="page"]{ color: var(--brand-contrast); background: var(--brand); }
    .navbar .navbar-brand.brand{ letter-spacing: .2px; }
    /* Pastikan dropdown akun selalu muncul di atas elemen sticky pada halaman tertentu (mis. Pesan) */
    .navbar .dropdown-menu{ z-index: 1050; }
  </style>';

  // ===================================================================
  // ▲▲▲ AKHIR BLOK HEADER BARU ▲▲▲
  // ===================================================================

  // ... di dalam fungsi html_head()

  // ===================================================================
  // ▼▼▼ TAMBAHKAN BLOK CSS BARU INI UNTUK MEMPERBAIKI WARNA SKOR ▼▼▼
  // ===================================================================
  echo '<style>
    .summary-score-box .score-label {
        color: var(--bs-secondary-emphasis-color);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 1px;
        font-size: 0.9rem;
    }
    .summary-score-box .score-details {
        color: var(--bs-secondary-color);
        font-size: 0.95rem;
    }
    /* Sedikit penyesuaian untuk mode gelap agar lebih bagus */
    [data-bs-theme="dark"] .summary-score-box {
        background-color: var(--bs-tertiary-bg) !important;
    }
  </style>';
  // ===================================================================
  // ▲▲▲ AKHIR DARI BLOK CSS BARU ▲▲▲

  // ... (kode lainnya di html_head() tetap sama)
}

function html_foot()
{
global $CONFIG;
  echo '</div><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>';
  echo '<script>
(function(){
  function handleThemeToggle() {
    var cur  = document.documentElement.getAttribute("data-bs-theme") || "light";
    var next = (cur === "dark") ? "light" : "dark";
    document.documentElement.setAttribute("data-bs-theme", next);
    localStorage.setItem("quizb_theme", next);
    try {
      fetch("?action=set_theme", {
        method: "POST",
        headers: {"Content-Type":"application/x-www-form-urlencoded"},
        body: "theme=" + encodeURIComponent(next)
      }).catch(function(){ /* abaikan */ });
    } catch(e) { /* abaikan */ }
  }
  var btnDesktop = document.getElementById("themeToggleDesktop");
  var btnProfile = document.getElementById("themeToggle");
  if (btnDesktop) { btnDesktop.addEventListener("click", handleThemeToggle); }
  if (btnProfile) { btnProfile.addEventListener("click", handleThemeToggle); }
})();
</script>';



  // Script untuk tombol laporan (TIDAK BERUBAH)
  echo <<<JS
<script>
function setupReportButtons() {
    document.querySelectorAll('.kirim-laporan-btn:not([data-initialized])').forEach(btn => {
        btn.dataset.initialized = 'true';
        btn.addEventListener('click', async () => {
            function createCertificateImage(quizTitle, subTheme, userName, userEmail, quizMode) {
                return new Promise(resolve => {
                    const canvas = document.createElement('canvas');
                    const ctx = canvas.getContext('2d');
                    const width = 800, height = 450;
                    canvas.width = width; canvas.height = height;
                    ctx.fillStyle = '#f0f9ff';
                    ctx.fillRect(0, 0, width, height);
                    ctx.strokeStyle = '#0d6efd';
                    ctx.lineWidth = 10;
                    ctx.strokeRect(5, 5, width - 10, height - 10);
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillStyle = '#0d6efd';
                    ctx.font = 'bold 42px Arial';
                    ctx.fillText('Selamat!', width / 2, 80);
                    ctx.font = '32px Arial';
                    ctx.fillText('Anda mendapatkan nilai 100', width / 2, 140);
                    ctx.fillStyle = '#444';
                    ctx.font = '22px Arial';
                    let userDetailsText = userEmail ? `(\${userName} - \${userEmail})` : `(\${userName})`;
                    ctx.fillText(userDetailsText, width / 2, 180);
                    ctx.fillStyle = '#333';
                    ctx.font = '24px Arial';
                    ctx.fillText('Pada soal:', width / 2, 230);
                    ctx.font = 'bold 28px Arial';
                    let titleY = wrapText(ctx, `'` + quizTitle + ` - ` + subTheme + `'`, width / 2, 280, width - 80, 34);
                    if (quizMode) {
                        ctx.font = 'italic 20px Arial';
                        ctx.fillStyle = '#555';
                        const modeText = `(Mode: \${quizMode === 'end' ? 'End Review' : 'Instan Review'})`;
                        ctx.fillText(modeText, width / 2, titleY + 10);
                    }
                    ctx.fillStyle = '#555';
                    ctx.font = 'italic 22px Arial';
                    ctx.fillText('Semoga berkah, tetap semangat belajar!', width / 2, height - 60);
                    canvas.toBlob(blob => resolve(blob), 'image/png');
                });
            }
            function wrapText(context, text, x, y, maxWidth, lineHeight) {
                let words = text.split(' ');
                let line = '';
                let currentY = y;
                for(let n = 0; n < words.length; n++) {
                    let testLine = line + words[n] + ' ';
                    let metrics = context.measureText(testLine);
                    if (metrics.width > maxWidth && n > 0) {
                        context.fillText(line, x, currentY);
                        line = words[n] + ' ';
                        currentY += lineHeight;
                    } else { line = testLine; }
                }
                context.fillText(line, x, currentY);
                return currentY + lineHeight;
            }
            btn.disabled = true;
            btn.textContent = 'Menyiapkan...';
            const userName = btn.dataset.userName, userEmail = btn.dataset.userEmail, quizTitle = btn.dataset.quizTitle, subTheme = btn.dataset.subTheme, quizMode = btn.dataset.quizMode;
            try {
                const imageBlob = await createCertificateImage(quizTitle, subTheme, userName, userEmail, quizMode);
                const imageFile = new File([imageBlob], 'laporan-skor-100.png', { type: 'image/png' });
                if (navigator.canShare && navigator.canShare({ files: [imageFile] })) {
                    await navigator.share({ files: [imageFile], title: 'Laporan Tugas Quiz' });
                } else {
                    alert('Browser Anda tidak mendukung fitur berbagi laporan ini.');
                }
            } catch (error) {
                if (error.name !== 'AbortError') console.error('Gagal membagikan:', error);
            } finally {
                btn.disabled = false; btn.textContent = 'Kirim Laporan';
            }
        });
    });
}
</script>
JS;

  // Script untuk tombol Share (TIDAK BERUBAH)
  echo <<<JS
<script>
// Handler untuk tombol "Tantang Teman" yang membuat challenge
document.getElementById('createChallenge')?.addEventListener('click', async function(e) {
    e.preventDefault();
    const titleId = this.getAttribute('data-title-id');
    const sessionId = this.getAttribute('data-session-id');
    const btn = this;
    
    btn.disabled = true;
    btn.innerHTML = 'Membuat link tantangan...';
    
    try {
        const response = await fetch('?action=create_challenge', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                title_id: titleId,
                session_id: sessionId
            })
        });
        
        const result = await response.json();
        
        if (result.success && result.token) {
            // Setelah challenge dibuat, share link
            const url = location.origin + location.pathname + '?page=challenge&token=' + result.token;
            const title = 'Tantangan Kuis QUIZB';
            const text = 'Coba kalahkan skor saya di kuis ini:';
            
            try {
                if (navigator.share) {
                    await navigator.share({ title, text, url });
                } else {
                    await navigator.clipboard.writeText(url);
                    alert('Link tantangan berhasil dibuat dan disalin!');
                }
            } catch (err) {
                console.error(err);
            }
        } else {
            alert('Gagal membuat link tantangan: ' + (result.error || 'Error tidak diketahui'));
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Terjadi kesalahan saat membuat link tantangan');
    } finally {
        btn.disabled = false;
        btn.innerHTML = 'Tantang Teman';
    }
});

const shareBtn = document.getElementById('shareChallenge');
if (shareBtn) {
    shareBtn.addEventListener('click', async function(e) {
        e.preventDefault();
        const url = this.getAttribute('data-url');
        const title = 'Tantangan Kuis QUIZB';
        const text  = 'Coba kalahkan skor saya di kuis ini:';
        try {
            if (navigator.share) await navigator.share({ title, text, url });
            else { await navigator.clipboard.writeText(url); alert('Link disalin!'); }
        } catch (err) { console.error(err); }
    });
}
</script>
JS;

  // Kode HTML untuk Footer (TIDAK BERUBAH)
  $u = $_SESSION['user'] ?? null;
  $current_page = $_GET['page'] ?? 'home';
  $unread_badge_html = '';
  $unread_notif_badge_html = '';
  if (uid()) {
    $count_result = q("SELECT COUNT(*) as unread_count FROM messages WHERE receiver_id = ? AND is_read = 0", [uid()])->fetch();
    if ($count_result && (int)$count_result['unread_count'] > 0) {
      $unread_badge_html = '<span class="badge bg-danger rounded-pill">' . (int)$count_result['unread_count'] . '</span>';
    }
    $total_notifs = (int)q("SELECT COUNT(*) FROM broadcast_notifications")->fetchColumn();
    $read_notifs = (int)q("SELECT COUNT(*) FROM user_notification_reads WHERE user_id = ?", [uid()])->fetchColumn();
    $unread_count = $total_notifs - $read_notifs;
    if ($unread_count > 0) {
      $unread_notif_badge_html = '<span class="badge bg-danger rounded-pill">' . $unread_count . '</span>';
    }
  }
  echo '<footer class="desktop-footer d-none d-md-block">&copy; ' . date("Y") . ' QuizB — "Quiz Berkah". All rights reserved.</footer>';

  // ===================================================================
  // ▼▼▼ TAMBAHKAN BLOK STYLE BARU INI TEPAT DI SINI ▼▼▼
  // ===================================================================
  echo '
  <style>
    /* Mobile bottom nav polish (scoped to small screens) */
    @media (max-width: 767.98px){
      .mobile-nav-footer{ box-shadow: var(--shadow-sm); backdrop-filter: saturate(180%) blur(8px); }
      .mobile-nav-footer .nav-container{ padding: 6px 6px; gap: 2px; }
      .mobile-nav-footer .nav-link{ 
        color: var(--bs-secondary-color);
        border-radius: var(--radius-md);
        padding: 10px 6px;
        transition: background-color var(--transition-fast), color var(--transition-fast), transform var(--transition-fast);
      }
      .mobile-nav-footer .nav-link:hover{ color: var(--text-1); background: var(--surface-2); }
      .mobile-nav-footer .nav-link:active{ transform: translateY(0.5px); }
      .mobile-nav-footer .nav-link.active{ background: var(--brand); color: var(--brand-contrast); }

      /* Avatar icon inside active state keeps a thin brand border */
      .mobile-nav-footer .nav-link .avatar-icon {
          width: 28px; height: 28px; border-radius: 50%; border: 2px solid transparent; object-fit: cover; margin-bottom: 2px;
      }
      .mobile-nav-footer .nav-link.active .avatar-icon { border-color: var(--brand-contrast); }

      .mobile-nav-footer .nav-link .badge { position: absolute; top: 2px; right: 12px; }
    }
</style>
  ';
  // ===================================================================
  // ▲▲▲ AKHIR DARI BLOK STYLE BARU ▲▲▲
  // ===================================================================

  echo '<nav class="mobile-nav-footer d-block d-md-none"><div class="nav-container">';
  echo '<a href="./" class="nav-link spa-nav-link' . ($current_page === 'home' ? ' active' : '') . '" data-page="home"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-house-door-fill" viewBox="0 0 16 16"><path d="M6.5 14.5v-3.505c0-.245.25-.495.5-.495h2c.25 0 .5.25.5.5v3.5a.5.5 0 0 0 .5.5h4a.5.5 0 0 0 .5-.5v-7a.5.5 0 0 0-.146-.354L13 5.793V2.5a.5.5 0 0 0-.5-.5h-1a.5.5 0 0 0-.5.5v1.293L8.354 1.146a.5.5 0 0 0-.708 0l-6 6A.5.5 0 0 0 1.5 7.5v7a.5.5 0 0 0 .5.5h4a.5.5 0 0 0 .5-.5z"/></svg><span>Home</span></a>';
  echo '<a href="?page=themes" class="nav-link spa-nav-link' . (in_array($current_page, ['themes', 'subthemes', 'titles']) ? ' active' : '') . '" data-page="themes"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-search" viewBox="0 0 16 16"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z"/></svg><span>Pencarian</span></a>';
  
  // Ambil peran (role) pengguna saat ini (asumsi $role sudah didefinisikan sebelumnya)
$role = $_SESSION['user']['role'] ?? 'umum';

// ▼▼▼ START: MENU TUGAS KONDISIONAL (Menggantikan 'Challenge') ▼▼▼

$target_page = 'challenges'; // Default: Challenges
$target_url = '?page=challenges';
$icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-trophy-fill" viewBox="0 0 16 16"><path d="M2.5.5A.5.5 0 0 1 3 0h10a.5.5 0 0 1 .5.5c0 .538-.012 1.05-.034 1.536a3 3 0 1 1-1.133 5.89c-.79 1.865-1.878 2.777-2.833 3.011v2.173l1.425.356c.194.048.377.135.537.255l.617.463c.12.09.186.215.186.346 0 .134-.066.256-.186.346l-.617.463c-.16.12-.343.207-.537.255L10.5 15.5v.5a.5.5 0 0 1-1 0v-.5a.5.5 0 0 1 1 0v-2.148a.5.5 0 0 1-.306-.474c-.182-.92-.57-1.706-.986-2.287-.333-.465-.68-.87-1.287-1.175-.487-.247-1.146-.546-1.85-.758-.552-.168-.962-.46-1.287-1.175C3.25 7.243 2.86 6.458 2.678 5.538a.5.5 0 0 1-.306.474v2.147a.5.5 0 0 1-1 0v-.5a.5.5 0 0 1 1 0v.5l1.425-.356a.5.5 0 0 1 .537-.255l.617-.463a.5.5 0 0 1 .186-.346 1.03 1.03 0 0 0-.186-.346l-.617-.463a.5.5 0 0 1-.537-.255L3.5 13.5v-2.173c-.955-.234-2.043-1.146-2.833-3.012a3 3 0 1 1-1.132-5.89A33.076 33.076 0 0 1 2.5.5z"/></svg>';
    $link_text = 'Challenge';

    if ($role === 'pengajar') {
        $target_page = 'kelola_tugas';
        $target_url = '?page=kelola_tugas';
        $link_text = 'Kelola Tugas';
        // Ikon: Management/Workspace
        $icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-person-workspace" viewBox="0 0 16 16"><path d="M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1h-1.5zM12 11h2V2h-2v9zM2 11V2h2v9H2z"/><path d="M6 14.5a.5.5 0 0 1 .5-.5h3a.5.5 0 0 1 .5.5v.5H6v-.5zm4 .5h1.5a.5.5 0 0 1 0 1H4.5a.5.5 0 0 1 0-1H6v.5h4v-.5z"/></svg>';
    } elseif ($role === 'pelajar') {
        $target_page = 'student_tasks';
        $target_url = '?page=student_tasks';
        $link_text = 'Daftar Tugas';
        // Ikon: Check Square/Assignment
        $icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-journal-check" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M10.854 6.146a.5.5 0 0 1 0 .708l-3 3a.5.5 0 0 1-.708 0l-1.5-1.5a.5.5 0 1 1 .708-.708L7.5 8.793l2.646-2.647a.5.5 0 0 1 .708 0z"/><path d="M3 0h10a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2v-1h1v1a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1H3a1 1 0 0 0-1 1v1H1V2a2 2 0 0 1 2-2z"/><path d="M1 5v-.5a.5.5 0 0 1 1 0V5h.5a.5.5 0 0 1 0 1h-2a.5.5 0 0 1 0-1H1zm0 3v-.5a.5.5 0 0 1 1 0V8h.5a.5.5 0 0 1 0 1h-2a.5.5 0 0 1 0-1H1zm0 3v-.5a.5.5 0 0 1 1 0v.5h.5a.5.5 0 0 1 0 1h-2a.5.5 0 0 1 0-1H1z"/></svg>';
    }

    // Render link
    echo '<a href="' . $target_url . '" class="nav-link spa-nav-link' . ($current_page === $target_page ? ' active' : '') . '" data-page="' . $target_page . '">';
    echo $icon_svg;
    echo '<span>' . $link_text . '</span></a>';

    // ▲▲▲ END: MENU TUGAS KONDISIONAL ▼▼▲
  
  echo '<a href="?page=pesan" class="nav-link nav-item-icon spa-nav-link' . ($current_page === 'pesan' ? ' active' : '') . '" data-page="pesan"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-envelope-fill" viewBox="0 0 16 16"><path d="M.05 3.555A2 2 0 0 1 2 2h12a2 2 0 0 1 1.95 1.555L8 8.414.05 3.555zM0 4.697v7.104l5.803-3.558L0 4.697zM6.761 8.83l-6.57 4.027A2 2 0 0 0 2 14h12a2 2 0 0 0 1.808-1.144l-6.57-4.027L8 9.586l-1.239-.757zm3.436-.586L16 11.801V4.697l-5.803 3.546z"/></svg>' . $unread_badge_html . '<span>Pesan</span></a>';
  echo '<a href="?page=notifikasi" class="nav-link nav-item-icon spa-nav-link' . ($current_page === 'notifikasi' ? ' active' : '') . '" data-page="notifikasi"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-bell-fill" viewBox="0 0 16 16"><path d="M8 16a2 2 0 0 0 2-2H6a2 2 0 0 0 2 2zm.995-14.901a1 1 0 1 0-1.99 0A5.002 5.002 0 0 0 3 6c0 1.098-.5 6-2 7h14c-1.5-1-2-5.902-2-7 0-2.42-1.72-4.44-4.005-4.901z"/></svg>' . $unread_notif_badge_html . '<span>Notifikasi</span></a>';
  echo '<a href="?page=profile" class="nav-link spa-nav-link' . ($current_page === 'profile' ? ' active' : '') . '" data-page="profile">';
  if ($u && !empty($u['avatar'])) {
    echo '<img src="' . h($u['avatar']) . '" class="avatar-icon" alt="Avatar">';
  } else {
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-person-fill" viewBox="0 0 16 16"><path d="M3 14s-1 0-1-1 1-4 6-4 6 3 6 4-1 1-1 1H3zm5-6a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/></svg>';
  }
  echo '<span>Profil</span></a></div></nav>';

  // ===================================================================
  // ▼▼▼ PERUBAHAN UTAMA: SCRIPT LOADER & ROUTER SPA ▼▼▼
  // ===================================================================
  // PERHATIKAN PENGGUNAAN <<<'JS' (dengan kutip tunggal)
  echo <<<'JS'
  <script>
  // Script ini akan menangani loader untuk Pemuatan Awal dan Navigasi SPA
  document.addEventListener('DOMContentLoaded', function() {
      const pageLoader = document.getElementById('pageLoader');
      const headerNav = document.querySelector('.navbar');

      // Tampilkan loader saat halaman pertama kali dibuka
      if (pageLoader) {
          pageLoader.classList.remove('hidden');
      }
      
      // Sembunyikan loader setelah SEMUA aset halaman awal selesai dimuat
      window.addEventListener('load', function() {
          if (pageLoader) {
              pageLoader.classList.add('hidden');
          }
          if (headerNav) {
            if (window.scrollY > 2) headerNav.classList.add('is-scrolled');
          }
      });
        window.addEventListener('scroll', function(){
          if (!headerNav) return;
          if (window.scrollY > 2) headerNav.classList.add('is-scrolled');
          else headerNav.classList.remove('is-scrolled');
        }, { passive: true });
      
      if (typeof setupReportButtons === 'function') {
          setupReportButtons();
      }
      
      // --- Logika Router SPA ---
      const mainContent = document.getElementById('main-content-container');
      const mobileNav = document.querySelector('.mobile-nav-footer');

      function executeScripts(container) {
          container.querySelectorAll('script').forEach(oldScript => {
              const newScript = document.createElement('script');
              Array.from(oldScript.attributes).forEach(attr => newScript.setAttribute(attr.name, attr.value));
              if (!oldScript.src) {
                  newScript.textContent = oldScript.textContent;
              }
              oldScript.parentNode.replaceChild(newScript, oldScript);
          });
      }

      // Helper: update badge counts (desktop + mobile) via lightweight API
      // Internal utility to set/remove a badge on an anchor element
      function setBadge(anchor, count){
        if(!anchor) return;
        let badge = anchor.querySelector('.badge');
        if(count>0){
          if(!badge){
            badge = document.createElement('span');
            badge.className = 'badge bg-danger rounded-pill';
            anchor.appendChild(badge);
          }
          badge.textContent = String(count);
        } else if(badge){
          badge.remove();
        }
      }

      // Expose a quick UI-only clear for immediate feedback on tap
      function forceClearBadge(page){
        if(page === 'notifikasi'){
          setBadge(document.querySelector('a.nav-item-icon[href="?page=notifikasi"]'), 0);
          setBadge(document.querySelector('.mobile-nav-footer a.spa-nav-link[data-page="notifikasi"]'), 0);
        }
        if(page === 'pesan'){
          setBadge(document.querySelector('a.nav-item-icon[href="?page=pesan"]'), 0);
          setBadge(document.querySelector('.mobile-nav-footer a.spa-nav-link[data-page="pesan"]'), 0);
        }
      }

      async function updateBadgesViaAPI(){
        try{
          const res = await fetch('?action=get_unread_counts');
          if(!res.ok) return;
          const data = await res.json();
          const msgCount = Number(data.messages||0);
          const notifCount = Number(data.notifications||0);

          // Desktop header buttons
          setBadge(document.querySelector('a.nav-item-icon[href="?page=pesan"]'), msgCount);
          setBadge(document.querySelector('a.nav-item-icon[href="?page=notifikasi"]'), notifCount);

          // Mobile footer nav links
          setBadge(document.querySelector('.mobile-nav-footer a.spa-nav-link[data-page="pesan"]'), msgCount);
          setBadge(document.querySelector('.mobile-nav-footer a.spa-nav-link[data-page="notifikasi"]'), notifCount);
        }catch(e){/* ignore */}
      }

      // INI ADALAH FUNGSI loadPage YANG SUDAH DIPERBAIKI TOTAL
        function syncChromeVisibility(page){
          // If the loaded content contains the quiz app container, treat as an active play UI.
          const isPlayActive = !!(mainContent && mainContent.querySelector('#quiz-app-container'));
          document.body.classList.toggle('play-active', isPlayActive);
          document.body.dataset.page = page;
        }

        async function loadPage(page, pushState = true) {
          if (!mainContent) return;

          // Tampilkan loader kustom kita
          if (pageLoader) {
              pageLoader.classList.remove('hidden');
          }
          
          // Kosongkan konten lama
          mainContent.innerHTML = ''; 

          if (mobileNav) {
              mobileNav.querySelectorAll('.nav-link.spa-nav-link').forEach(link => link.classList.remove('active'));
              const activeLink = mobileNav.querySelector(`.spa-nav-link[data-page="${page}"]`);
              if (activeLink) activeLink.classList.add('active');
          }

          try {
              // Beri jeda agar animasi sempat terlihat
              await new Promise(resolve => setTimeout(resolve, 250));

              const response = await fetch(`?action=api_get_page_content&page=${page}&_=${new Date().getTime()}`);
              if (!response.ok) throw new Error('Gagal memuat halaman.');
              
              const html = await response.text();
              mainContent.innerHTML = html;

              // Keep header/footer state consistent across SPA transitions.
              syncChromeVisibility(page);
                              
              if (typeof renderAllGoogleButtons === 'function') renderAllGoogleButtons();
              if (typeof executeScripts === 'function') executeScripts(mainContent);
              if (typeof setupReportButtons === 'function') setupReportButtons();
              
              if (pushState) {
                  const url = (page === 'home') ? './' : `?page=${page}`;
                  history.pushState({ page: page }, '', url);
              }
              
              const pageTitle = (page.charAt(0).toUpperCase() + page.slice(1)).replace('_', ' ');
              document.title = `${pageTitle} | QuizB`;
              
              window.scrollTo(0, 0);
              // Refresh badges after content loads (e.g., opening Notifikasi marks as read)
              updateBadgesViaAPI();

          } catch (error) {
              mainContent.innerHTML = '<div class="alert alert-danger">Gagal memuat halaman. Silakan coba lagi.</div>';
              console.error('Fetch error:', error);
          } finally {
              // Selalu sembunyikan loader setelah selesai
              if (pageLoader) {
                  pageLoader.classList.add('hidden');
              }
          }
      }

          // Helper to tell server we've read notifications (SPA)
          async function markNotifReadServer(){
          try{ await fetch('?action=mark_notifications_read',{cache:'no-store'}); }catch(e){}
          }

          if (mobileNav) {
            mobileNav.addEventListener('click', async function(e) {
              const link = e.target.closest('a.spa-nav-link');
              if (link && link.dataset.page) {
                e.preventDefault();
                const page = link.dataset.page;
                // Immediate UI feedback
                if(page === 'notifikasi' || page === 'pesan'){
                  forceClearBadge(page);
                }
                // For notifikasi, also notify server then refresh counts
                if(page === 'notifikasi'){
                  markNotifReadServer().then(updateBadgesViaAPI);
                }
                if (!link.classList.contains('active')) {
                  await loadPage(page, true);
                }
              }
            });
          }

      window.addEventListener('popstate', function(e) {
          const fallbackPage = new URLSearchParams(window.location.search).get('page') || 'home';
          loadPage(e.state?.page || fallbackPage, false);
      });

      const initialPage = new URLSearchParams(window.location.search).get('page') || 'home';
      history.replaceState({ page: initialPage }, '', window.location.href);
      // Ensure initial chrome state matches initial content.
      syncChromeVisibility(initialPage);
        // Initial badges update on first load (SPA)
        updateBadgesViaAPI();
  });
  </script>
JS;
  // ===================================================================
  // ▲▲▲ AKHIR DARI BLOK SCRIPT PERBAIKAN ▲▲▲
  // ===================================================================


  echo '</body></html>';
}
