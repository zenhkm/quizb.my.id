<?php
// views/profile_guest.php
echo '<div class="container py-5 text-center" style="max-width: 500px;">';
echo '  <h4 class="mb-3">Masuk untuk Lanjut</h4>';
echo '  <p class="lead mb-4">Anda harus login untuk melihat halaman profil, riwayat kuis, dan mengakses pengaturan.</p>';
echo google_btn($CONFIG["GOOGLE_CLIENT_ID"]);

// Menu Informasi Umum untuk Pengguna yang belum login
echo '  <div class="card mt-4 text-start">';
echo '      <div class="card-header">Pengaturan & Informasi</div>';
echo '      <div class="list-group list-group-flush">';
echo '          <div class="list-group-item d-flex justify-content-between align-items-center">';
echo '              <span>Ganti Mode Gelap/Terang</span>';
echo '              <button id="themeToggle" class="btn btn-outline-secondary btn-sm" title="Ganti tema">🌓</button>'; // Tombol ganti tema
echo '          </div>';
echo '          <a class="list-group-item list-group-item-action" href="?page=about">Tentang QuizB</a>';
echo '          <a class="list-group-item list-group-item-action" href="?page=privacy">Privacy Policy</a>';
echo '          <a class="list-group-item list-group-item-action" href="?page=feedback">Feedback</a>';

echo '      </div>';
echo '  </div>';

echo '</div>';
