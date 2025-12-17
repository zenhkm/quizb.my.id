<?php
// views/qmanage.php

echo '<h3 class="mb-3">Kelola Soal (CRUD)</h3>';

// =================================================================
// BAGIAN 1: INTERFACE PENCARIAN
// =================================================================

// Kotak Pencarian
echo '
    <div class="mb-3 position-relative">
        <input type="text" id="qmanageSearchInput" class="form-control form-control-lg ps-5" placeholder="Cari Tema › Subtema › Judul Soal...">
        <div class="position-absolute top-50 start-0 translate-middle-y ps-3 text-muted">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-search" viewBox="0 0 16 16"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z"/></svg>
        </div>
    </div>';

// Container untuk hasil pencarian
echo '<div id="qmanageSearchResults" class="list-group mb-3" style="display: none;"></div>';
echo '<div id="qmanageSearchNoResults" class="alert alert-warning" style="display: none;">Tidak ada hasil ditemukan.</div>';

// Tanam data pencarian ke dalam script
echo '<script id="qmanageSearchData" type="application/json">' . json_encode($searchable_list) . '</script>';

// JavaScript untuk mengaktifkan pencarian
echo <<<JS
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('qmanageSearchInput');
        const searchResults = document.getElementById('qmanageSearchResults');
        const noResults = document.getElementById('qmanageSearchNoResults');
        const searchData = JSON.parse(document.getElementById('qmanageSearchData').textContent);

        searchInput.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();

            if (query === '') {
                searchResults.style.display = 'none';
                noResults.style.display = 'none';
                return;
            }

            searchResults.innerHTML = '';
            const matches = searchData.filter(item => item.searchText.includes(query));

            if (matches.length > 0) {
                searchResults.style.display = 'block';
                noResults.style.display = 'none';
                matches.forEach(item => {
                    const a = document.createElement('a');
                    a.href = item.url;
                    a.className = 'list-group-item list-group-item-action';
                    a.textContent = item.name;
                    searchResults.appendChild(a);
                });
            } else {
                searchResults.style.display = 'none';
                noResults.style.display = 'block';
            }
        });
    });
    </script>
    JS;

// =================================================================
// BAGIAN 2: TAMPILAN KELOLA SOAL
// =================================================================

if (!$title_id) {
    echo '<div class="alert alert-info mt-3">Silakan cari dan pilih judul soal di atas untuk mulai mengelola.</div>';
    return;
}

if (!$title_info) {
    echo '<div class="alert alert-danger">Judul kuis tidak ditemukan. Silakan pilih judul lain.</div>';
    return;
}

// 2. Tampilkan header/breadcrumb
echo '<div class="card bg-body-tertiary mb-4">';
echo '  <div class="card-body">';
echo '      <h5 class="card-title mb-0">' . h($title_info['title']) . '</h5>';
echo '      <nav aria-label="breadcrumb">';
echo '          <ol class="breadcrumb mb-0">';
echo '              <li class="breadcrumb-item">' . h($title_info['theme_name']) . '</li>';
echo '              <li class="breadcrumb-item">' . h($title_info['subtheme_name']) . '</li>';
echo '          </ol>';
echo '      </nav>';
echo '  </div>';
echo '</div>';

// Bagian Kanan: Tombol Download CSV
echo '  <div class="flex-shrink-0">';
echo '      <a href="?action=download_csv&title_id=' . $title_id . '" class="btn btn-success">';
echo '          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-download me-1" viewBox="0 0 16 16"><path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/><path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/></svg>';
echo '          Download Seluruh Soal di Judul Ini';
echo '      </a>';
echo '  </div>';

echo '<hr class="my-4">';

// Dropdown titles json for move question
echo '<script id="__titles_json" type="application/json">'
    . json_encode($all_titles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    . '</script>';

// ----- FORM EDIT (jika ada ?edit=ID)
if ($edit_id) {
    if (!$qrow) {
        echo '<div class="alert alert-warning">Soal tidak ditemukan.</div>';
    } else {
        echo '<div class="card mb-4"><div class="card-body">';
        echo '<h5 class="mb-3">Edit Soal</h5>';
        echo '<form method="post" id="form-edit-q"><input type="hidden" name="act" value="update_question_dyn"><input type="hidden" name="question_id" value="' . $edit_id . '">';
        echo '<div class="mb-2"><label class="form-label">Teks Pertanyaan</label><textarea class="form-control" name="text" required>' . h($qrow['text']) . '</textarea></div>';

        echo '<div id="edit-choices">';
        $i = 0;
        foreach ($choices as $c) {
            $i++;
            echo '<div class="border rounded-3 p-2 mb-2 choice-row">';
            echo '<div class="d-flex align-items-center gap-2">';
            echo '<input type="hidden" name="cid[]" value="' . (int)$c['id'] . '">';
            echo '<input class="form-check-input mt-0" type="radio" name="correct_index" value="' . $i . '" ' . (!empty($c['is_correct']) ? 'checked' : '') . '>';
            echo '<input class="form-control" name="ctext[]" value="' . h($c['text']) . '" placeholder="Teks pilihan" required>';
            echo '<button type="button" class="btn btn-outline-danger btn-sm remove-choice" title="Hapus"><span>&times;</span></button>';
            echo '</div></div>';
        }
        echo '</div>';

        echo '<div class="d-flex gap-2 mb-2">';
        echo '<button type="button" class="btn btn-sm btn-outline-secondary" id="btn-edit-add-choice">+ Tambah Pilihan (maks 5)</button>';
        echo '<small class="text-muted">Minimal 2 pilihan, maksimal 5.</small>';
        echo '</div>';

        echo '<div class="mb-2"><label class="form-label">Penjelasan (opsional)</label><input class="form-control" name="explanation" value="' . h($qrow['explanation']) . '"></div>';
        echo '<button class="btn btn-primary">Simpan Perubahan</button> <a href="?page=qmanage&title_id=' . $title_id . '" class="btn btn-secondary">Batal</a>';
        echo '</form>';

        echo '<script>
      (function(){
        const box = document.getElementById("edit-choices");
        const addBtn = document.getElementById("btn-edit-add-choice");
        function countRows(){ return box.querySelectorAll(".choice-row").length; }
        function updateRemoveButtons(){
          box.querySelectorAll(".remove-choice").forEach(btn=>{
            btn.onclick = function(){
              if(countRows()<=2){ alert("Minimal harus ada 2 pilihan."); return; }
              this.closest(".choice-row").remove();
              // jika radio benar hilang, set yang pertama
              const radios = box.querySelectorAll(\'input[type=radio][name="correct_index"]\');
              if(![...radios].some(r=>r.checked) && radios[0]) radios[0].checked = true;
            };
          });
        }
        addBtn.onclick = function(){
          if(countRows()>=5){ alert("Maksimal 5 pilihan."); return; }
          const idx = countRows()+1;
          const div = document.createElement("div");
          div.className="border rounded-3 p-2 mb-2 choice-row";
          div.innerHTML = \'<div class="d-flex align-items-center gap-2">\
            <input type="hidden" name="cid[]" value="0">\
            <input class="form-check-input mt-0" type="radio" name="correct_index" value="\'+idx+\'">\
            <input class="form-control" name="ctext[]" placeholder="Teks pilihan" required>\
            <button type="button" class="btn btn-outline-danger btn-sm remove-choice" title="Hapus"><span>&times;</span></button>\
          </div>\';
          box.appendChild(div);
          updateRemoveButtons();
        };
        updateRemoveButtons();
      })();
    </script>';

        echo '</div></div>';
    }
}

// ----- FORM TAMBAH (hanya jika TIDAK sedang edit)
if (!$edit_id) {
    echo '<div class="card mb-4"><div class="card-body">';
    echo '<h5 class="mb-3">Tambah Soal Baru</h5>';
    echo '<form method="post" id="form-add-q"><input type="hidden" name="act" value="add_question_dyn"><input type="hidden" name="title_id" value="' . $title_id . '">';
    echo '<div class="mb-2"><label class="form-label">Teks Pertanyaan</label><textarea class="form-control" name="text" required></textarea></div>';

    echo '<div id="add-choices">';
    for ($i = 0; $i < 4; $i++) {
        echo '<div class="border rounded-3 p-2 mb-2 choice-row">';
        echo '<div class="d-flex align-items-center gap-2">';
        echo '<input class="form-check-input mt-0" type="radio" name="correct_index" value="' . ($i + 1) . '" ' . ($i === 0 ? 'checked' : '') . '>';
        echo '<input class="form-control" name="choice_text[]" placeholder="Teks pilihan" required>';
        echo '<button type="button" class="btn btn-outline-danger btn-sm remove-choice" title="Hapus"><span>&times;</span></button>';
        echo '</div></div>';
    }
    echo '</div>';

    echo '<div class="d-flex gap-2 mb-2">';
    echo '<button type="button" class="btn btn-sm btn-outline-secondary" id="btn-add-choice">+ Tambah Pilihan (maks 5)</button>';
    echo '<small class="text-muted">Centang bulatan di kiri untuk menandai jawaban benar. Minimal 2 pilihan.</small>';
    echo '</div>';

    echo '<div class="mb-2"><label class="form-label">Penjelasan (opsional)</label><input class="form-control" name="explanation" placeholder="Penjelasan (opsional)"></div>';
    echo '<button class="btn btn-success">Tambah Soal</button></form>';

    echo '<script>
    (function(){
      const box = document.getElementById("add-choices");
      const addBtn = document.getElementById("btn-add-choice");
      function countRows(){ return box.querySelectorAll(".choice-row").length; }
      function updateRemoveButtons(){
        box.querySelectorAll(".remove-choice").forEach(btn=>{
          btn.onclick = function(){
            if(countRows()<=2){ alert("Minimal harus ada 2 pilihan."); return; }
            this.closest(".choice-row").remove();
            const radios = box.querySelectorAll(\'input[type=radio][name="correct_index"]\');
            if(![...radios].some(r=>r.checked) && radios[0]) radios[0].checked = true;
          };
        });
      }
      addBtn.onclick = function(){
        if(countRows()>=5){ alert("Maksimal 5 pilihan."); return; }
        const idx = countRows()+1;
        const div = document.createElement("div");
        div.className="border rounded-3 p-2 mb-2 choice-row";
        div.innerHTML = \'<div class="d-flex align-items-center gap-2">\
          <input class="form-check-input mt-0" type="radio" name="correct_index" value="\'+idx+\'">\
          <input class="form-control" name="choice_text[]" placeholder="Teks pilihan" required>\
          <button type="button" class="btn btn-outline-danger btn-sm remove-choice" title="Hapus"><span>&times;</span></button>\
        </div>\';
        box.appendChild(div);
        updateRemoveButtons();
      };
      updateRemoveButtons();
    })();
  </script>';

    echo '</div></div>';
}

// ----- LIST SOAL
echo '<div class="card"><div class="card-body">';
echo '<h5 class="mb-3">Daftar Soal</h5>';
if (!$rows) {
    echo '<div class="alert alert-secondary">Belum ada soal.</div>';
} else {
    echo '<div class="table-responsive"><table class="table table-sm align-middle">';
    echo '<thead><tr><th width="60">ID</th><th>Pertanyaan</th><th width="180">Aksi</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $short = mb_strimwidth(strip_tags($r['text']), 0, 90, '…', 'UTF-8');
        echo '<tr><td>' . $r['id'] . '</td><td>' . h($short) . '</td><td>';
        echo '<a href="?page=qmanage&title_id=' . $title_id . '&edit=' . $r['id'] . '" class="btn btn-sm btn-primary me-1">Edit</a>';

        echo '<button type="button" class="btn btn-sm btn-outline-secondary move-q" data-id="' . (int)$r['id'] . '">Pindah</button>';

        echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Hapus soal ini?\')">'
            . '<input type="hidden" name="act" value="del_question">'
            . '<input type="hidden" name="question_id" value="' . $r['id'] . '">'
            . '<button class="btn btn-sm btn-danger">Hapus</button></form>';

        // ▼▼▼ TOMBOL DUPLIKAT BARU ▼▼▼
        echo '  <form method="post" onsubmit="return confirm(\'Anda yakin ingin duplikat soal ini?\')">';
        echo '    <input type="hidden" name="act" value="duplicate_question">';
        echo '    <input type="hidden" name="question_id" value="' . $r['id'] . '">';
        echo '    <button type="submit" class="btn btn-sm mt-2 btn-info w-100">Duplikat</button>';
        echo '  </form>';
        // ▲▲▲ AKHIR TOMBOL DUPLIKAT ▲▲▲
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
}

// Modal untuk Pindah Soal
echo <<<HTML
    <div id="modal-move-q" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:9999;">
      <div style="max-width:520px;margin:60px auto;background:var(--bs-body-bg); color: var(--bs-body-color);padding:16px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.2);">
        <h5 class="mb-3">Pindah Soal</h5>
        <form id="form-move-q" action="?page=qmanage" method="post">
          <input type="hidden" name="act" value="move_question">
          <input type="hidden" name="question_id" id="move_q_id">
          <div class="mb-2">
            <label class="form-label">Pindahkan ke Judul</label>
            <select name="dest_title_id" id="move_q_dest" class="form-select" required></select>
          </div>
          <div class="text-end">
            <button type="button" class="btn btn-light" data-close="modal-move-q">Batal</button>
            <button type="submit" class="btn btn-primary">Simpan</button>
          </div>
        </form>
      </div>
    </div>
    <script>
    (function(){
      const qs  = (s)=>document.querySelector(s);
      function getTitles(){
        const node = document.getElementById('__titles_json');
        if(!node) return [];
        try { return JSON.parse(node.textContent || '[]'); } catch(e){ return []; }
      }
      function fillTitles(sel){
        if(!sel) return;
        const titles = getTitles();
        sel.innerHTML = '';
        titles.forEach(t=>{
          const o = document.createElement('option');
          o.value = t.id; o.textContent = t.label;
          sel.appendChild(o);
        });
      }
      function showMoveQ(id){
        qs('#move_q_id').value = id;
        fillTitles(qs('#move_q_dest'));
        qs('#modal-move-q').style.display = 'block';
      }
      function hideMoveQ(){
        qs('#modal-move-q').style.display = 'none';
      }
      document.addEventListener('click', (ev)=>{
        const btn = ev.target.closest('.move-q');
        if(btn){ showMoveQ(btn.getAttribute('data-id')); return; }
        if(ev.target.id === 'modal-move-q'){ hideMoveQ(); return; }
        const closer = ev.target.closest('[data-close="modal-move-q"]');
        if(closer){ hideMoveQ(); return; }
      });
    })();
    </script>
    HTML;
echo '</div></div>';
