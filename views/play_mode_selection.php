<?php
// views/play_mode_selection.php
echo '<div class="card shadow-sm border-0"><div class="card-body p-4 p-md-5">';
echo '  <h1 class="card-title text-center mb-2 h3">' . h($title['title']) . '</h1>';
echo '  <p class="card-subtitle text-center text-muted mb-4">' . h($title['themen']) . ' › ' . h($title['subn']) . '</p>';
echo '  <hr class="my-4">';
echo '  <p class="text-center fw-bold mb-4">Pilih Mode Permainan</p>';

// STRUKTUR BARU: Tombol dan keterangan disatukan per kolom
echo '  <div class="row g-4">'; // g-4 memberi jarak lebih baik

// --- Kolom 1: Instan Review ---
echo '    <div class="col-md-4">';
echo '      <a class="btn btn-primary btn-lg d-block w-100" href="?page=play&title_id=' . $title_id . '&mode=instant">Instan Review</a>';
echo '      <p class="small text-muted mt-2 mb-0 text-center">Jawab soal, jika salah akan langsung dibahas. Cocok untuk belajar cepat.</p>';
echo '    </div>';

// --- Kolom 2: End Review ---
echo '    <div class="col-md-4">';
echo '      <a class="btn btn-outline-primary btn-lg d-block w-100" href="?page=play&title_id=' . $title_id . '&mode=end">End Review</a>';
echo '      <p class="small text-muted mt-2 mb-0 text-center">Selesaikan semua soal terlebih dahulu, baru lihat pembahasan lengkap di akhir.</p>';
echo '    </div>';

// --- Kolom 3: Ujian ---
echo '    <div class="col-md-4">';
echo '      <a class="btn btn-outline-danger btn-lg d-block w-100" href="?page=play&title_id=' . $title_id . '&mode=exam">Ujian</a>';
echo '      <p class="small text-muted mt-2 mb-0 text-center">Mode serius dengan timer keseluruhan. Tidak ada review jawaban di akhir.</p>';
echo '    </div>';

echo '  </div>';

echo '</div></div>';
