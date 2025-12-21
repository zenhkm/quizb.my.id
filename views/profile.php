<?php
// views/profile.php

echo '<div class="mb-3 d-block d-md-flex align-items-center justify-content-between">';
echo '  <div class="text-center text-md-start d-md-flex align-items-center gap-3">';
echo '    <img style="width: 80px; height: 80px;" class="avatar mx-auto mb-2 mb-md-0" src="' . h($profile_user['avatar']) . '">';
echo '    <div>';
echo '      <div class="fw-bold fs-5">' . h($profile_user['name']) . '</div>';
if ($is_own_profile || is_admin()) {
  echo '<div class="text-muted small">' . h($profile_user['email']) . '</div>';
}
echo '    </div>';
echo '  </div>';

if (!$is_own_profile) {
  echo '<div class="text-center text-md-end mt-3 mt-md-0"><a href="?page=pesan&with_id=' . $profile_user['id'] . '" class="btn btn-primary btn-sm">Kirim Pesan</a></div>';
}
echo '</div>';

if ($is_own_profile) {
  echo '<div class="accordion mb-4" id="profileMenuAccordion">';
  echo '  <div class="accordion-item">';
  echo '    <h2 class="accordion-header" id="headingSettings">';
  echo '      <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseSettings" aria-expanded="false" aria-controls="collapseSettings">';
  echo '        Pengaturan & Informasi';
  echo '      </button>';
  echo '    </h2>';
  echo '    <div id="collapseSettings" class="accordion-collapse collapse" aria-labelledby="headingSettings" data-bs-parent="#profileMenuAccordion">';
  echo '      <div class="accordion-body p-0">';
  $feedback_link_label = is_admin() ? 'Kelola Umpan Balik' : 'Kirim Umpan Balik';
  echo '        <div class="list-group list-group-flush">';
  echo '          <a href="?page=setting" class="list-group-item list-group-item-action">Ubah Nama & Timer Kuis</a>';
  echo '          <div class="list-group-item d-flex justify-content-between align-items-center">';
  echo '            <span>Ganti Mode Gelap/Terang</span>';
  echo '            <button id="themeToggle" class="btn btn-outline-secondary btn-sm" title="Ganti tema">🌓</button>';
  echo '          </div>';
  echo '          <a href="?page=about" class="list-group-item list-group-item-action">Tentang QuizB</a>';
  echo '          <a href="?page=privacy" class="list-group-item list-group-item-action">Privacy Policy</a>';
  echo '          <a href="?page=feedback" class="list-group-item list-group-item-action">' . $feedback_link_label . '</a>';
  echo '          <a href="?action=logout" class="list-group-item list-group-item-action text-danger">Logout</a>';
  echo '        </div>';
  echo '      </div>';
  echo '    </div>';
  echo '  </div>';
  echo '  <div class="accordion-item">';
  echo '    <h2 class="accordion-header" id="headingNav">';
  echo '      <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseNav" aria-expanded="false" aria-controls="collapseNav">';
  echo '        Menu Navigasi';
  echo '      </button>';
  echo '    </h2>';
  echo '    <div id="collapseNav" class="accordion-collapse collapse" aria-labelledby="headingNav" data-bs-parent="#profileMenuAccordion">';
  echo '      <div class="accordion-body p-0">';
  echo '        <div class="list-group list-group-flush">';
  echo '          <a class="list-group-item list-group-item-action" href="?page=themes">Pencarian</a>';
  echo '          <a class="list-group-item list-group-item-action" href="?page=explore">Jelajah Tema</a>';

  if (is_admin()) {
    echo '    <a class="list-group-item list-group-item-action" href="?page=difficulty">Peta Kesulitan</a>';
    echo '    <a class="list-group-item list-group-item-action" href="?page=challenges">Data Challenge</a>';
    echo '    <a class="list-group-item list-group-item-action" href="?page=admin">Backend</a>';
    echo '    <a class="list-group-item list-group-item-action" href="?page=kelola_user">Kelola User</a>';
    echo '    <a class="list-group-item list-group-item-action" href="?page=qmanage">Kelola Soal (CRUD)</a>';

    echo '    <a class="list-group-item list-group-item-action" href="?page=crud">CRUD Bank Soal</a>';
} else {
    $user_role = $_SESSION['user']['role'] ?? '';
    if ($user_role === 'pengajar') {
      // Link untuk Pengajar (tidak berubah)
      echo '<a class="list-group-item list-group-item-action" href="?page=kelola_institusi">Kelola Institusi & Kelas</a>';
    } elseif ($user_role === 'pelajar') {
      // ▼▼▼ TAUTAN BARU UNTUK SISWA ▼▼▼
      echo '<a class="list-group-item list-group-item-action" href="?page=kelola_kelas">Gabung ke Kelas Saya</a>';
    }
    echo '<a class="list-group-item list-group-item-action" href="?page=challenges">Data Challenge</a>';
  }
  
  echo '        </div>';
  echo '      </div>';
  echo '    </div>';
  echo '  </div>';
  echo '</div>';
}

if ($is_own_profile && is_admin()) {
  echo '<div class="card mt-4"><div class="card-body">';
  $participantsCount_admin = (is_array($recent_admin_view) || $recent_admin_view instanceof Countable) ? count($recent_admin_view) : 0;
  echo   '<div class="d-flex align-items-center justify-content-between">';
  echo     '<h5 class="mb-2">Riwayat Peserta Terbaru (Admin View) <span class="badge bg-secondary" id="admin-tbl-participants-count">' . $participantsCount_admin . '</span></h5>';
  echo   '</div>';
  echo   '<input id="admin-filter-participants" type="text" class="form-control form-control-sm mb-2" placeholder="Cari: nama / judul / waktu / skor / kota">';
  if (!$recent_admin_view) {
    echo '<div class="text-muted small">Belum ada peserta.</div>';
  } else {
    echo '<div class="table-responsive">';
    echo   '<table class="table table-sm align-middle" id="admin-tbl-participants">';
    echo     '<thead><tr>';
    echo       '<th style="white-space:nowrap;">Nama / Tamu</th>';
    echo       '<th>Judul</th>';
    echo       '<th style="white-space:nowrap;">Waktu</th>';
    echo       '<th style="text-align:right; white-space:nowrap;">Skor</th>';
    echo     '</tr></thead>';
    echo     '<tbody>';
    foreach ($recent_admin_view as $r) {
      $avatar = (!empty($r['avatar'])) ? $r['avatar'] : 'https://www.gravatar.com/avatar/?d=mp&s=32';
      $display = $r['display_name'];
      $judul   = $r['quiz_title'] ?? '—';
      $waktu   = $r['created_at'];
      $skor    = (string)$r['score'];
      $search  = strtolower($display . ' ' . $judul . ' ' . $waktu . ' ' . $skor);
      echo '<tr data-search="' . h($search) . '">';
      echo   '<td>';
      if (!empty($r['user_id'])) {
        echo '<a href="?page=profile&user_id=' . (int)$r['user_id'] . '" class="text-decoration-none text-body">';
        echo   '<div class="d-flex align-items-center">';
        echo     '<img src="' . h($avatar) . '" class="rounded-circle me-2" width="24" height="24" alt="">';
        echo     '<span>' . h($display) . '</span>';
        echo   '</div>';
        echo '</a>';
      } else {
        echo   '<div class="d-flex align-items-center">';
        echo     '<img src="' . h($avatar) . '" class="rounded-circle me-2" width="24" height="24" alt="">';
        echo     '<span>' . h($display) . '</span>';
        echo   '</div>';
      }
      echo   '</td>';
      echo   '<td>' . h($judul) . '</td>';
      echo   '<td>' . h($waktu) . '</td>';
      echo   '<td style="text-align:right; font-weight:600;">' . h($skor) . '</td>';
      echo '</tr>';
    }
    echo     '</tbody>';
    echo   '</table>';
    echo '</div>';
  }
  echo '<div class="d-flex align-items-center justify-content-between mt-2" id="admin-pager-participants">';
  echo '  <button class="btn btn-sm btn-outline-secondary" data-page="prev">◀︎</button>';
  echo '  <div class="small text-muted">Halaman <span data-role="page">1</span>/<span data-role="pages">1</span></div>';
  echo '  <button class="btn btn-sm btn-outline-secondary" data-page="next">▶︎</button>';
  echo '</div>';
  echo '</div></div>';
  echo "<script>
          setTimeout(function() {
              setupTable({
                  inputId: 'admin-filter-participants',
                  tableId: 'admin-tbl-participants',
                  pagerId: 'admin-pager-participants',
                  countBadgeId: 'admin-tbl-participants-count',
                  pageSize: 10
              });
          }, 0);
      </script>";
} else {
  if (!$rows) {
    echo '<div class="alert alert-secondary mt-4">Pengguna ini belum memiliki riwayat kuis.</div>';
  } else {
    echo '<h5 class="mt-4">Riwayat Kuis</h5>';
    echo '<input id="profile-search" type="text" class="form-control form-control-sm mb-2" placeholder="Cari riwayat kuis...">';
    echo '<table class="table table-sm align-middle" id="profile-table"><thead><tr><th>Waktu</th><th>Judul</th>';

    if ($is_own_profile || is_admin()) {
      echo '<th>Skor</th>';
    }
    if ($is_own_profile) {
      echo '<th>Aksi</th>';
    }
    echo '</tr></thead><tbody>';

     foreach ($rows as $r) {
      $review_url = '?page=review&result_id=' . (int)$r['result_id'];

      // Selain admin: pemilik profil juga boleh klik baris untuk menuju review.
      // Supaya tombol di kolom Aksi tetap berfungsi, klik pada elemen interaktif tidak memicu redirect.
      $row_clickable = ($is_own_profile || is_admin());
      if ($row_clickable) {
        echo '<tr onclick="if(event.target.closest(\'a,button,input,select,textarea,label,form\')) return; window.location.href=\'' . $review_url . '\'" style="cursor:pointer;">';
      } else {
        echo '<tr>';
      }

      echo '<td>' . h($r['created_at']) . '</td><td>' . h($r['title']) . '</td>';

      if ($is_own_profile || is_admin()) {
        echo '<td>' . (int)$r['score'] . '</td>';
      }

      if ($is_own_profile) {
        echo '<td>';
        echo '<a href="' . $review_url . '" class="btn btn-sm btn-info w-100 mb-1">Review</a>';
        
        if ((int)$r['score'] === 100) {
          // ... (Tombol Laporan dan Story WA)
          $js_userName = h($u['name']);
          $js_userEmail = h($u['email']);
          $js_quizTitle = h($r['title']);
          $js_subTheme = h($r['subtheme_name']);
          $js_quizMode = h($r['mode']); 

          echo '<div class="d-flex flex-column gap-1">';
          echo "<button class='btn btn-sm btn-success kirim-laporan-btn' 
                              data-user-name='{$js_userName}' 
                              data-user-email='{$js_userEmail}' 
                              data-quiz-title='{$js_quizTitle}' 
                              data-sub-theme='{$js_subTheme}'
                              data-quiz-mode='{$js_quizMode}'>Laporan</button>"; 
          
          $story_quiz_title = $r['title'];
          $story_sub_theme = $r['subtheme_name'];
          $mode_selection_url = base_url() . '?page=play&title_id=' . $r['title_id'];
          $storyText = "Alhamdulillah, tuntas! 💯\nSaya baru saja menyelesaikan kuis \"{$story_quiz_title} - {$story_sub_theme}\" di QuizB.\n\nIngin mencoba juga? Klik di sini:\n{$mode_selection_url}\n\n#QuizB #BelajarAsyik";
          $encodedStoryText = urlencode($storyText);
          $wa_link = "https://wa.me/?text=" . $encodedStoryText;
          echo "<a href='{$wa_link}' target='_blank' class='btn btn-sm btn-info'><svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='currentColor' class='bi bi-whatsapp' viewBox='0 0 16 16'><path d='M13.601 2.326A7.85 7.85 0 0 0 7.994 0C3.627 0 .068 3.558.064 7.926c0 1.399.366 2.76 1.057 3.965L0 16l4.204-1.102a7.9 7.9 0 0 0 3.79.965h.004c4.368 0 7.926-3.558 7.93-7.93A7.89 7.89 0 0 0 13.6 2.326zM7.994 14.521a6.57 6.57 0 0 1-3.356-.92l-.24-.144-2.494.654.666-2.433-.156-.251a6.56 6.56 0 0 1-1.007-3.505c0-3.626 2.957-6.584 6.591-6.584a6.56 6.56 0 0 1 4.66 1.931 6.56 6.56 0 0 1 1.928 4.66c-.004 3.639-2.961 6.592-6.592 6.592m3.615-4.934c-.197-.099-1.17-.578-1.353-.646-.182-.068-.315-.099-.445.099-.133.197-.513.646-.627.775-.114.133-.232.148-.43.05-.197-.1-.836-.308-1.592-.985-.59-.525-.985-1.175-1.103-1.372-.114-.198-.011-.304.088-.403.087-.088.197-.232.296-.346.1-.114.133-.198.198-.33.065-.134.034-.248-.015-.347-.05-.099-.445-1.076-.612-1.47-.16-.389-.323-.335-.445-.34-.114-.007-.247-.007-.38-.007a.73.73 0 0 0-.529.247c-.182.198-.691.677-.691 1.654s.71 1.916.81 2.049c.098.133 1.394 2.132 3.383 2.992.47.205.84.326 1.129.418.475.152.904.129 1.246.08.38-.058 1.171-.48 1.338-.943.164-.464.164-.86.114-.943-.049-.084-.182-.133-.38-.232z'/></svg> Story</a>";
          echo '</div>';
        }
        echo '</td>';
      }
      echo '</tr>';
    }

    echo '</tbody></table>';
    echo '<div class="d-flex align-items-center justify-content-between mt-2" id="profile-pager"><button class="btn btn-sm btn-outline-secondary" data-page="prev">◀︎</button><div class="small text-muted">Halaman <span data-role="page">1</span>/<span data-role="pages">1</span></div><button class="btn btn-sm btn-outline-secondary" data-page="next">▶︎</button></div>';
    echo "<script>
              setTimeout(function() {
                  setupTable({ inputId: 'profile-search', tableId: 'profile-table', pagerId: 'profile-pager', pageSize: 10 });
              }, 0);
          </script>";
  }
}
