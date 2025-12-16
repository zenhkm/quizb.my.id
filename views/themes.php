<?php
// views/themes.php

// echo '<h3>Pencarian Kuis</h3>';

// Kotak Pencarian
echo '
  <div class="mb-4 position-relative">
      <input type="text" id="pageSearchInput" class="form-control form-control-lg ps-5" placeholder="Cari subtema atau judul soal...">
      <div class="position-absolute top-50 start-0 translate-middle-y ps-3 text-muted">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-search" viewBox="0 0 16 16"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z"/></svg>
      </div>
  </div>';

// Container untuk hasil pencarian (awalnya disembunyikan)
echo '<div id="pageSearchResultsView" style="display: none;">
          <div id="pageSubthemeResultsContainer" style="display: none;">
              <h5 class="text-muted">Subtema</h5>
              <div id="pageSubthemeResults" class="list-group mb-3"></div>
          </div>
          <hr id="pageSearchDivider" style="display: none;">
          <div id="pageTitleResultsContainer" style="display: none;">
              <h5 class="text-muted">Judul Soal</h5>
              <div id="pageTitleResults" class="list-group"></div>
          </div>
          <div id="pageSearchNoResults" class="alert alert-warning" style="display: none;">
              Tidak ada hasil yang cocok dengan pencarian Anda.
          </div>
        </div>';

// Container untuk daftar default (tabel semua soal)
echo '<div id="defaultListView">';

if (!$all_titles) {
  echo '<div class="alert alert-info">Belum ada judul soal yang tersedia.</div>';
} else {
  // echo '<h5 class="mb-3">Semua Judul Soal</h5>';
  // Mengganti struktur tabel dengan list-group
  echo '<div class="list-group">';
  foreach ($all_titles as $title) {
    // Setiap baris sekarang adalah sebuah item link yang interaktif
    echo '<a href="?page=play&title_id=' . $title['id'] . '" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">';

    // Bagian kiri: Judul Soal dan konteksnya (Tema > Subtema)
    echo '  <div>';
    echo '    <div class="fw-semibold">' . h($title['title']) . '</div>';
    echo '    <small class="text-muted">' . h($title['theme_name']) . ' › ' . h($title['subtheme_name']) . '</small>';
    echo '  </div>';

    // Bagian kanan: Badge untuk jumlah dimainkan
    echo '  <span class="badge bg-primary rounded-pill" title="Jumlah dimainkan">' . (int)$title['play_count'] . 'x</span>';

    echo '</a>';
  }
  echo '</div>'; // Penutup list-group
}
echo '</div>'; // Penutup defaultListView

// =================================================================
// BAGIAN 3: JAVASCRIPT UNTUK FUNGSI PENCARIAN
// =================================================================
echo '<script id="searchData" type="application/json">' . json_encode($searchable_list) . '</script>';

echo <<<JS
  <script>
  setTimeout(function() { // <-- Tambahkan ini
      const searchInput = document.getElementById('pageSearchInput');
      const defaultView = document.getElementById('defaultListView');
      const searchResultsView = document.getElementById('pageSearchResultsView');
      const subthemeResultsContainer = document.getElementById('pageSubthemeResultsContainer');
      const titleResultsContainer = document.getElementById('pageTitleResultsContainer');
      const subthemeResults = document.getElementById('pageSubthemeResults');
      const titleResults = document.getElementById('pageTitleResults');
      const searchDivider = document.getElementById('pageSearchDivider');
      const searchNoResults = document.getElementById('pageSearchNoResults');
      
      const searchData = JSON.parse(document.getElementById('searchData').textContent);

      if (searchInput) {
          searchInput.addEventListener('input', function () {
              const query = this.value.toLowerCase().trim();

              if (query === '') {
                  defaultView.style.display = 'block';
                  searchResultsView.style.display = 'none';
                  return;
              }

              defaultView.style.display = 'none';
              searchResultsView.style.display = 'block';

              subthemeResults.innerHTML = '';
              titleResults.innerHTML = '';

              const subthemeMatches = searchData.filter(item => item.type === 'subtheme' && item.searchText.includes(query));
              const titleMatches = searchData.filter(item => item.type === 'title' && item.searchText.includes(query));

              if (subthemeMatches.length > 0) {
                  subthemeResultsContainer.style.display = 'block';
                  subthemeMatches.forEach(item => {
                      const a = document.createElement('a');
                      a.href = item.url;
                      a.className = 'list-group-item list-group-item-action';
                      a.innerHTML = `\${item.name} <small class="text-muted d-block">\${item.context}</small>`;
                      subthemeResults.appendChild(a);
                  });
              } else {
                  subthemeResultsContainer.style.display = 'none';
              }

              if (titleMatches.length > 0) {
                  titleResultsContainer.style.display = 'block';
                  titleMatches.forEach(item => {
                      const a = document.createElement('a');
                      a.href = item.url;
                      a.className = 'list-group-item list-group-item-action';
                      a.innerHTML = `\${item.name} <small class="text-muted d-block">\${item.context}</small>`;
                      titleResults.appendChild(a);
                  });
              } else {
                  titleResultsContainer.style.display = 'none';
              }

              if (subthemeMatches.length > 0 && titleMatches.length > 0) {
                  searchDivider.style.display = 'block';
              } else {
                  searchDivider.style.display = 'none';
              }

              if (subthemeMatches.length === 0 && titleMatches.length === 0) {
                  searchNoResults.style.display = 'block';
              } else {
                  searchNoResults.style.display = 'none';
              }
          });
      }
  }, 100);
  </script>
JS;
