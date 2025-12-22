<?php
  echo '<div class="row g-4">';

  // --- KOLOM KIRI (Konten Utama) ---
  echo '<div class="col-lg-8">';

//   echo '<div class="text-center mb-4">
//         <button id="enable-notifications-btn" class="btn btn-warning" style="display:none;">
//             🔔 Aktifkan Notifikasi Kuis Baru
//         </button>
//       </div>';

  // === WIDGET TUGAS (PELAJAR / PENGAJAR) ===
  if ($current_user_id) {

      // --- KONDISI: PENGAJAR ---
      if ($user_role === 'pengajar') {
          // Query 3 tugas terbaru yang dibuat pengajar ini
          $preview_tugas_pengajar = q("
              SELECT
                  a.id AS assignment_id, a.judul_tugas, a.batas_waktu,
                  c.nama_kelas, c.id as id_kelas, c.id_institusi,
                  ti.nama_institusi
              FROM assignments a
              JOIN classes c ON a.id_kelas = c.id
              JOIN teacher_institutions ti ON c.id_institusi = ti.id
              WHERE
                  a.id_pengajar = ?
              ORDER BY a.created_at DESC
              LIMIT 3
          ", [$current_user_id])->fetchAll();
          
          // Cek jumlah institusi yang dikelola pengajar ini
          $institusi_count = q("SELECT COUNT(id) FROM teacher_institutions WHERE id_pengajar = ?", [$current_user_id])->fetchColumn();

          // Ambil SEMUA total anggota per kelas yang relevan
          $kelas_ids_pengajar = array_column($preview_tugas_pengajar, 'id_kelas');
          $total_members_map_pengajar = [];
          if (!empty($kelas_ids_pengajar)) {
             $in_clause = implode(',', array_fill(0, count($kelas_ids_pengajar), '?'));
             $member_counts = q("SELECT id_kelas, COUNT(id_pelajar) as count FROM class_members WHERE id_kelas IN ($in_clause) GROUP BY id_kelas", $kelas_ids_pengajar)->fetchAll();
             foreach($member_counts as $mc) {
                 $total_members_map_pengajar[$mc['id_kelas']] = (int)$mc['count'];
             }
          }


          echo '<h5 class="widget-title">🧑‍🏫 Tugas Terbaru (Pengajar)</h5>';

          if (empty($preview_tugas_pengajar)) {
              echo '<div class="alert alert-secondary small">Anda belum membuat tugas apa pun.</div>';
          } else {
              echo '<div class="list-group sidebar-widget">';
              foreach ($preview_tugas_pengajar as $tugas) {
                  $batas_waktu_obj = $tugas['batas_waktu'] ? new DateTime($tugas['batas_waktu']) : null;
                  $lewat_batas = $batas_waktu_obj && $batas_waktu_obj < new DateTime();
                  
                  // Hitung Progres
                  $total_members = $total_members_map_pengajar[$tugas['id_kelas']] ?? 0;
                  $submissions_count = (int)q("SELECT COUNT(DISTINCT user_id) FROM assignment_submissions WHERE assignment_id = ?", [$tugas['assignment_id']])->fetchColumn();
                  
                  $progress_percent = $total_members > 0 ? round(($submissions_count / $total_members) * 100) : 0;
                  $progress_percent = min(100, max(0, $progress_percent));

                  $status_html = '';
                  if ($lewat_batas) {
                       $status_html = '<span class="badge bg-danger">Terlewat</span>';
                  } elseif ($batas_waktu_obj) {
                      $status_html = '<span class="badge bg-success">Batas: ' . $batas_waktu_obj->format('d M, H:i') . '</span>';
                  }

                  // Progress Bar (untuk pengajar, tampilkan progres kelas)
                  $progress_class = ($progress_percent === 100) ? 'bg-success' : (($progress_percent === 0) ? 'bg-secondary' : 'bg-primary');
                  $progress_bar_html = '
                       <div class="progress mt-1" style="height: 15px;">
                            <div class="progress-bar ' . $progress_class . '" role="progressbar" style="width: ' . $progress_percent . '%; font-size: 0.75rem;" aria-valuenow="' . $progress_percent . '" aria-valuemin="0" aria-valuemax="100">' . $progress_percent . '%</div>
                       </div>
                       <small class="text-muted d-block small mt-1">' . $submissions_count . ' dari ' . $total_members . ' siswa sudah mengerjakan.</small>';


                  echo '<a href="?page=detail_tugas&assignment_id=' . $tugas['assignment_id'] . '" class="list-group-item list-group-item-action p-2">';
                  echo '  <div class="d-flex w-100 justify-content-between align-items-start">';
                  echo '      <div class="me-2">';
                  echo '          <div class="fw-semibold" style="line-height: 1.3;">' . h($tugas['judul_tugas']) . '</div>';
                  echo '          <small class="text-muted d-block">' . h($tugas['nama_institusi']) . ' › ' . h($tugas['nama_kelas']) . '</small>';
                  echo '      </div>';
                  echo '      <div class="text-end flex-shrink-0" style="min-width: 80px;">' . $status_html . '</div>';
                  echo '  </div>';
                  echo $progress_bar_html;
                  echo '</a>';
              }
              echo '</div>'; // penutup list-group
              
              // --- LINK LIHAT SEMUA TUGAS PENGAJAR ---
              echo '<div class="text-end mt-3">';
              // Link "Lihat Semua Tugas" menuju halaman Kelola Institusi
              echo '<a href="?page=kelola_institusi" class="btn btn-sm btn-primary">Lihat Semua Tugas &raquo;</a>';
              echo '</div>';
              
              // --- TAMPILKAN INSTITUSI LAIN (Jika Lebih dari Satu) ---
              if ($institusi_count > 1) {
                  $institutions = q("SELECT id, nama_institusi FROM teacher_institutions WHERE id_pengajar = ?", [$current_user_id])->fetchAll();
                  echo '<div class="mt-4 pt-3 border-top">';
                  echo '<small class="fw-bold d-block mb-1">Kelola di Institusi Lain:</small>';
                  foreach ($institutions as $inst) {
                      // Link ke kelola tugas di institusi tersebut
                      echo '<a href="?page=kelola_tugas&inst_id=' . $inst['id'] . '" class="badge bg-secondary text-decoration-none me-1 mb-1">' . h($inst['nama_institusi']) . '</a>';
                  }
                  echo '</div>';
              }
          }
          echo '<hr class="my-4">';
      }
      
      // --- KONDISI: PELAJAR ---
      elseif ($user_role === 'pelajar') {
          // Query 3 tugas teratas (tanpa paginasi penuh, hanya preview)
          // Menggunakan LEFT JOIN dengan SUBQUERY untuk mendapatkan SKOR TERTINGGI (max_score)
          $preview_tugas = q("
              SELECT
                  a.id AS assignment_id, a.judul_tugas, a.batas_waktu,
                  c.nama_kelas,
                  qt.title AS quiz_title,
                  asub_max.score AS max_score, 
                  asub_max.submitted_at AS last_submitted_at
              FROM assignments a
              JOIN class_members cm ON a.id_kelas = cm.id_kelas
              JOIN classes c ON a.id_kelas = c.id
              JOIN quiz_titles qt ON a.id_judul_soal = qt.id
              LEFT JOIN (
                  SELECT 
                      asub.assignment_id, 
                      asub.user_id, 
                      MAX(r.score) AS score, 
                      MAX(asub.submitted_at) AS submitted_at
                  FROM assignment_submissions asub
                  JOIN results r ON asub.result_id = r.id
                  WHERE asub.user_id = ?
                  GROUP BY asub.assignment_id, asub.user_id 
              ) asub_max ON a.id = asub_max.assignment_id AND asub_max.user_id = cm.id_pelajar
              WHERE
                  cm.id_pelajar = ?
              ORDER BY a.created_at DESC
              LIMIT 3
          ", [$current_user_id, $current_user_id])->fetchAll();

          echo '<h5 class="widget-title">🔔 Tugas Terbaru (Pelajar)</h5>';

          if (empty($preview_tugas)) {
              echo '<div class="alert alert-secondary small">Tidak ada tugas aktif untuk Anda saat ini.</div>';
          } else {
              echo '<div class="list-group sidebar-widget">';
              foreach ($preview_tugas as $tugas) {
                  $sudah_dikerjakan = $tugas['max_score'] !== null;
                  $batas_waktu_obj = $tugas['batas_waktu'] ? new DateTime($tugas['batas_waktu']) : null;
                  $lewat_batas = $batas_waktu_obj && $batas_waktu_obj < new DateTime();

                  $max_score = $sudah_dikerjakan ? (int)$tugas['max_score'] : 0; 
                  
                  $link_tugas = '#';
                  $status_html = '';
                  $is_disabled = true;

                  // Tentukan Status dan Link
                  if ($sudah_dikerjakan) {
                      if ($max_score === 100) {
                          $status_html = '<div class="fs-3">💯</div>';
                      } else {
                          // Sudah dikerjakan, bisa coba lagi (Restart)
                          $link_tugas = '?page=play&assignment_id=' . $tugas['assignment_id'] . '&restart=1';
                          $is_disabled = false;
                          $status_html = '<div class="fw-bold fs-4 text-primary me-1">' . $max_score . '</div>';
                      }
                  } elseif ($lewat_batas) {
                      $status_html = '<span class="badge bg-danger">Terlewat</span>';
                  } else {
                      // Belum dikerjakan
                      $link_tugas = '?page=play&assignment_id=' . $tugas['assignment_id'];
                      $is_disabled = false;
                      $status_html = '<span class="badge bg-warning text-dark fs-6">📝 Kerjakan</span>';
                  }
                  
                  // === START MODIFIKASI: Ganti Progress Bar dengan Bar Nilai ===
                  // Progres bar sekarang akan menampilkan BAR NILAI (SCORE BAR)
                  $score_bar_percent = $max_score; 
                  // Tentukan kelas warna berdasarkan skor
                  $score_bar_class = ($max_score === 100) ? 'bg-success' : (($max_score >= 70) ? 'bg-primary' : (($max_score > 0) ? 'bg-warning text-dark' : 'bg-secondary'));

                  // Jika belum dikerjakan, tampilkan bar nol dengan kelas sekunder
                  if (!$sudah_dikerjakan) {
                     $score_bar_percent = 0;
                     $score_bar_class = 'bg-secondary';
                  }
                  
                  $score_bar_html = '
                       <div class="progress mt-1" style="height: 15px;">
                            <div class="progress-bar ' . $score_bar_class . '" role="progressbar" style="width: ' . $score_bar_percent . '%; font-size: 0.75rem;" aria-valuenow="' . $max_score . '" aria-valuemin="0" aria-valuemax="100">' . $max_score . '%</div>
                       </div>
                       <small class="text-muted d-block small mt-1">Skor Tertinggi: ' . ($sudah_dikerjakan ? $max_score : '—') . '</small>';
                  // === END MODIFIKASI ===


                  echo '<a href="' . $link_tugas . '" class="list-group-item list-group-item-action p-2 ' . ($is_disabled ? 'disabled' : '') . '">';
                  echo '  <div class="d-flex w-100 justify-content-between align-items-start">';
                  echo '      <div class="me-2">';
                  echo '          <div class="fw-semibold" style="line-height: 1.3;">' . h($tugas['judul_tugas']) . '</div>';
                  echo '          <small class="text-muted">' . h($tugas['nama_kelas']) . ' &middot; Kuis: ' . h(mb_strimwidth($tugas['quiz_title'], 0, 25, "...")) . '</small>';
                   if ($batas_waktu_obj && !$sudah_dikerjakan) {
                      $warna_batas = ($lewat_batas) ? 'text-danger fw-bold' : '';
                      echo '      <small class="' . $warna_batas . ' d-block">Batas: ' . $batas_waktu_obj->format('d M, H:i') . '</small>';
                  }
                  echo '      </div>';
                  echo '      <div class="text-end flex-shrink-0" style="min-width: 80px;">' . $status_html . '</div>';
                  echo '  </div>';
                  
                  // Tampilkan Bar Nilai
                  echo $score_bar_html;
                  
                  echo '</a>';
              }
              echo '</div>'; // penutup list-group
              
              // --- LINK LIHAT SEMUA TUGAS PELAJAR ---
              echo '<div class="text-end mt-3">';
              echo '<a href="?page=student_tasks" class="btn btn-sm btn-outline-primary">Lihat Semua Tugas &raquo;</a>';
              echo '</div>';
          }
          echo '<hr class="my-4">';
      }
  }

  // === AKHIR WIDGET TUGAS ===


  // a. Kotak Pencarian (Tetap ada)
  echo '
    <div class="mb-4 position-relative home-search-box-home">
        <input type="text" id="homeSearchInput" class="form-control form-control-lg ps-5" placeholder="Cari subtema atau judul soal...">
        <div class="position-absolute top-50 start-0 translate-middle-y ps-3 text-muted">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-search" viewBox="0 0 16 16"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z"/></svg>
        </div>
    </div>';

  // b. Container untuk Hasil Pencarian (awalnya disembunyikan)
  echo '<div id="searchResultsView" style="display: none;">
            <div id="subthemeResultsContainer" style="display: none;">
                <h5 class="text-muted">Subtema</h5>
                <div id="subthemeResults" class="list-group mb-3"></div>
            </div>
            <hr id="searchDivider" style="display: none;">
            <div id="titleResultsContainer" style="display: none;">
                <h5 class="text-muted">Judul Soal</h5>
                <div id="titleResults" class="list-group"></div>
            </div>
            <div id="searchNoResults" class="alert alert-warning" style="display: none;">
                Tidak ada hasil yang cocok dengan pencarian Anda.
            </div>
          </div>';

  // c. Container untuk Card Browser Dinamis (awalnya terlihat)
  echo '<div id="card-browser-container">';
  echo '  <div id="breadcrumb-nav" class="mb-3"></div>';

  echo '  <div id="card-display" class="row row-cols-2 row-cols-sm-2 row-cols-md-3 g-3"></div>';

  echo '  <div id="loading-indicator" class="text-center p-4" style="display: none;">
            <svg class="quizb-loader" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" style="margin: auto;">
                <path class="q-shape" d="M50,5C25.2,5,5,25.2,5,50s20.2,45,45,45s45-20.2,45-45S74.8,5,50,5z M50,86.5C29.9,86.5,13.5,70.1,13.5,50 S29.9,13.5,50,13.5S86.5,29.9,86.5,50S70.1,86.5,50,86.5z M68.5,43.8c-1.3-1.3-3.5-1.3-4.8,0L49,58.5l-6.7-6.7 c-1.3-1.3-3.5-1.3-4.8,0s-1.3,3.5,0,4.8l9,9c0.6,0.6,1.5,1,2.4,1s1.8-0.4,2.4-1l17-17C69.8,47.2,69.8,45.1,68.5,43.8z"/>
                <circle class="dot dot-1" cx="35" cy="50" r="5"/>
                <circle class="dot dot-2" cx="50" cy="50" r="5"/>
                <circle class="dot dot-3" cx="65" cy="50" r="5"/>
            </svg>
        </div>';
  echo '</div>';

  echo '</div>'; // Penutup .col-lg-8

  // --- KOLOM KANAN (Sidebar) ---
  echo '<div class="col-lg-4 sidebar-separator-mobile">';

  // Widget Menu Pengguna (Download Soal) - Hanya untuk user login non-admin
  if (uid() && !is_admin()) {
      echo '<h5 class="widget-title">📂 Menu Pengguna</h5>';
      echo '<div class="list-group sidebar-widget mb-4">';
      echo '<a href="?page=download_soal" class="list-group-item list-group-item-action p-2">';
      echo '  <div class="d-flex align-items-center">';
      echo '    <div class="me-3 fs-4">📥</div>';
      echo '    <div style="line-height: 1.3;">';
      echo '      <div class="fw-semibold mb-1">Download Soal</div>';
      echo '      <div class="small text-muted">Unduh soal untuk belajar offline</div>';
      echo '    </div></div></a>';
      echo '</div>';
  }

  // Widget Peserta Terbaru
    echo '<div class="card mb-2">';
    echo '  <div class="card-body py-2 px-3 d-flex align-items-center justify-content-between">';
    echo '    <div class="small text-muted">Sedang online</div>';
    echo '    <span id="onlineCountBadge" class="badge bg-success">' . (int)($online_count ?? 0) . '</span>';
    echo '  </div>';
    echo '</div>';

echo <<<'HTML'
<script>
(function(){
  const badge = document.getElementById('onlineCountBadge');
  if (!badge) return;
  async function refreshOnline(){
    try {
      const res = await fetch('?action=api_get_online_count&minutes=1', { cache: 'no-store' });
      const j = await res.json();
      if (j && j.ok && typeof j.online_count !== 'undefined') {
        badge.textContent = j.online_count;
      }
    } catch (e) {}
  }
  refreshOnline();
  setInterval(refreshOnline, 10000);
})();
</script>
HTML;
  echo '<h5 class="widget-title">🏆 Peserta Terbaru</h5>';
  echo '<div class="list-group sidebar-widget">';
    foreach ($recent as $r) {
        $avatar = !empty($r['avatar']) ? $r['avatar'] : 'https://www.gravatar.com/avatar/?d=mp&s=40';
        $profile_url = !empty($r['user_id']) ? '?page=profile&user_id=' . $r['user_id'] : '#';
        echo '<a href="' . $profile_url . '" class="list-group-item list-group-item-action p-2">';
    echo '  <div class="d-flex align-items-center">';
    echo '    <img src="' . h($avatar) . '" class="rounded-circle me-3" width="40" height="40" alt="Avatar">';
    echo '    <div style="line-height: 1.3;">';
    echo '      <div class="fw-semibold mb-1">' . h($r['display_name']) . '</div>';
    echo '      <div class="small text-muted">' . h($r['quiz_title'] ?? '—') . '</div>';
    echo '    </div></div></a>';
  }
  echo '</div>';

  echo '</div>'; // Penutup .col-lg-4

  echo '</div>'; // Penutup .row

  // =================================================================
  // BAGIAN 3: JAVASCRIPT & CSS
  // =================================================================
  // a. Tanam data JSON untuk JavaScript
  echo '<script id="searchData" type="application/json">' . json_encode($searchable_list) . '</script>';
  echo '<script id="initial-data" type="application/json">' . json_encode($themes) . '</script>';

  // b. CSS untuk Card Browser
  echo '<style>
    .clickable-card { cursor: pointer; transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out; }
    .clickable-card:hover { transform: translateY(-5px); box-shadow: 0 8px 20px rgba(0,0,0,0.1); }
    [data-bs-theme="dark"] .clickable-card:hover { box-shadow: 0 8px 20px rgba(0,0,0,0.3); }

    .clickable-card .card-title {
        white-space: normal;
        word-wrap: break-word;
    }
</style>';

  // CSS BARU UNTUK GRADIEN SUDUT KARTU
  echo '<style>
  :root {
    --card-gradient-color: rgba(13, 110, 253, 0.1);
  }
  [data-bs-theme="dark"] {
    --card-gradient-color: rgba(13, 110, 253, 0.15);
  }

  .clickable-card {
    background-image: radial-gradient(circle 250px at bottom right, var(--card-gradient-color), transparent 70%);
    background-repeat: no-repeat;
  }
</style>';

  // c. JavaScript untuk Pencarian dan Card Browser
  echo <<<JS
    <script>
    setTimeout(function() { 
        
        // --- Variabel untuk semua elemen ---
        const searchInput = document.getElementById('homeSearchInput');
        const searchResultsView = document.getElementById('searchResultsView');
        const cardBrowserContainer = document.getElementById('card-browser-container');
        
        // Elemen Pencarian
        const subthemeResults = document.getElementById('subthemeResults');
        const titleResults = document.getElementById('titleResults');
        const searchDivider = document.getElementById('searchDivider');
        const searchNoResults = document.getElementById('searchNoResults');
        const searchData = JSON.parse(document.getElementById('searchData').textContent);

        // Elemen Card Browser
        const cardDisplay = document.getElementById('card-display');
        const loadingIndicator = document.getElementById('loading-indicator');
        const breadcrumbNav = document.getElementById('breadcrumb-nav');
        const initialDataScript = document.getElementById('initial-data');
        let breadcrumbTrail = [];

        // --- FUNGSI UNTUK CARD BROWSER ---
        function showLoading(show) {
            loadingIndicator.style.display = show ? 'block' : 'none';
            cardDisplay.style.display = show ? 'none' : 'flex';
        }

        function createCard(item, type) {
            const col = document.createElement('div');
            col.className = 'col';
            const card = document.createElement('div');
            card.className = 'card h-100 clickable-card';
            card.dataset.id = item.id;
            card.dataset.name = item.name;
            card.dataset.type = type;
            const cardBody = document.createElement('div');
            cardBody.className = 'card-body text-center d-flex align-items-center justify-content-center';
            cardBody.innerHTML = `<h5 class="card-title mb-0">\${item.name}</h5>`;
            card.appendChild(cardBody);
            col.appendChild(card);
            return col;
        }

        function renderBreadcrumb() {
            if (breadcrumbTrail.length === 0) {
                breadcrumbNav.innerHTML = ''; return;
            }
            let html = '<nav aria-label="breadcrumb"><ol class="breadcrumb">';
            html += '<li class="breadcrumb-item"><a href="#" data-level="-1">Tema</a></li>';
            breadcrumbTrail.forEach((crumb, index) => {
                html += (index < breadcrumbTrail.length - 1)
                    ? `<li class="breadcrumb-item"><a href="#" data-level="\${index}">\${crumb.name}</a></li>`
                    : `<li class="breadcrumb-item active" aria-current="page">\${crumb.name}</li>`;
            });
            html += '</ol></nav>';
            breadcrumbNav.innerHTML = html;
        }

        async function renderCards(items, type) {
            showLoading(true);
            cardDisplay.innerHTML = '';
            await new Promise(resolve => setTimeout(resolve, 200));
            if (items.length === 0) {
                cardDisplay.innerHTML = '<div class="col-12"><div class="alert alert-secondary">Belum ada item di kategori ini.</div></div>';
            } else {
                items.forEach(item => cardDisplay.appendChild(createCard(item, type)));
            }
            showLoading(false);
            renderBreadcrumb();
        }

        async function fetchAndDisplay(level, id, name) {
            let url = '', nextType = '';
            if (level === 'theme') {
                url = `?action=api_get_subthemes&theme_id=\${id}`;
                nextType = 'subtheme';
                breadcrumbTrail = [{ name: name, type: 'theme', id: id }];
            } else if (level === 'subtheme') {
                url = `?action=api_get_titles&subtheme_id=\${id}`;
                nextType = 'title';
                breadcrumbTrail.push({ name: name, type: 'subtheme', id: id });
            }
            try {
                showLoading(true);
                const response = await fetch(url);
                if (!response.ok) throw new Error('Gagal mengambil data.');
                const data = await response.json();
                renderCards(data, nextType);
            } catch (error) {
                cardDisplay.innerHTML = '<div class="col-12"><div class="alert alert-danger">Terjadi kesalahan. Coba lagi.</div></div>';
                showLoading(false);
            }
        }

        // --- FUNGSI UNTUK PENCARIAN ---
        function handleSearch(query) {
            if (query === '') {
                cardBrowserContainer.style.display = 'block';
                searchResultsView.style.display = 'none';
                return;
            }
            cardBrowserContainer.style.display = 'none';
            searchResultsView.style.display = 'block';

            subthemeResults.innerHTML = '';
            titleResults.innerHTML = '';
            
            const subthemeMatches = searchData.filter(item => item.type === 'subtheme' && item.searchText.includes(query));
            const titleMatches = searchData.filter(item => item.type === 'title' && item.searchText.includes(query));

            document.getElementById('subthemeResultsContainer').style.display = subthemeMatches.length > 0 ? 'block' : 'none';
            subthemeMatches.forEach(item => {
                const a = document.createElement('a');
                a.href = item.url; a.className = 'list-group-item list-group-item-action';
                a.innerHTML = `\${item.name} <small class="text-muted d-block">\${item.context}</small>`;
                subthemeResults.appendChild(a);
            });

            document.getElementById('titleResultsContainer').style.display = titleMatches.length > 0 ? 'block' : 'none';
            titleMatches.forEach(item => {
                const a = document.createElement('a');
                a.href = item.url; a.className = 'list-group-item list-group-item-action';
                a.innerHTML = `\${item.name} <small class="text-muted d-block">\${item.context}</small>`;
                titleResults.appendChild(a);
            });
            
            searchDivider.style.display = (subthemeMatches.length > 0 && titleMatches.length > 0) ? 'block' : 'none';
            searchNoResults.style.display = (subthemeMatches.length === 0 && titleMatches.length === 0) ? 'block' : 'none';
        }

        // --- EVENT LISTENERS ---
        searchInput.addEventListener('input', function () { handleSearch(this.value.toLowerCase().trim()); });

        cardDisplay.addEventListener('click', function(e) {
            const card = e.target.closest('.clickable-card');
            if (!card) return;
            if (card.dataset.type === 'title') {
                window.location.href = `?page=play&title_id=\${card.dataset.id}`;
            } else {
                fetchAndDisplay(card.dataset.type, card.dataset.id, card.dataset.name);
            }
        });

        breadcrumbNav.addEventListener('click', function(e) {
            e.preventDefault();
            const link = e.target.closest('a[data-level]');
            if (!link) return;
            const level = parseInt(link.dataset.level, 10);
            if (level === -1) {
                breadcrumbTrail = [];
                const initialData = JSON.parse(initialDataScript.textContent);
                renderCards(initialData, 'theme');
            } else {
                const crumb = breadcrumbTrail[level];
                breadcrumbTrail = breadcrumbTrail.slice(0, level);
                fetchAndDisplay(crumb.type, crumb.id, crumb.name);
            }
        });

        // --- INISIALISASI ---
        if(initialDataScript) {
            const initialData = JSON.parse(initialDataScript.textContent);
            renderCards(initialData, 'theme');
        }
    });
    </script>
    JS;
