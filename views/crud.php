<div class="row g-3">

  <!-- ======================
       Kolom 1: TEMA
       ====================== -->
  <div class="col-md-4">
    <div class="card">
      <div class="card-body">
        <h5 class="mb-3">Tema</h5>

        <!-- Form Tambah Tema -->
        <form method="post" class="mb-3">
          <input type="hidden" name="act" value="add_theme">
          <div class="input-group mb-1">
            <input class="form-control" name="name" placeholder="Nama tema" required>
            <button class="btn btn-success" type="submit">Tambah Tema</button>
          </div>
          <input class="form-control" name="description" placeholder="Deskripsi (opsional)">
        </form>

        <?php if (!$themes): ?>
          <div class="alert alert-secondary">Belum ada tema.</div>
        <?php else: ?>
          <div class="list-group">
            <?php foreach ($themes as $t): ?>
              <?php
              $active = ($sel_theme_id === (int)$t['id']) ? ' active' : '';
              $url    = '?page=crud&theme_id=' . $t['id'];
              ?>
              <div class="list-group-item<?= $active ?> d-flex justify-content-between align-items-center">
                <div>
                  <a class="text-decoration-none <?= ($active ? 'text-white' : '') ?>" href="<?= $url ?>">
                    <?= h($t['name']) ?>
                  </a>
                </div>
                <div class="ms-2 d-flex gap-1">
                  <!-- Rename Tema -->
                  <button type="button" class="btn btn-sm btn-primary btn-rename"
                    data-id="<?= $t['id'] ?>"
                    data-type="theme"
                    data-name="<?= h($t['name']) ?>">Rename</button>

                  <!-- Hapus Tema -->
                  <form method="post" class="d-inline" onsubmit="return confirm('Hapus TEMA beserta semua isinya? (Subtema, Judul, Soal)\nTindakan ini tidak bisa dibatalkan.')">
                    <input type="hidden" name="act" value="delete_theme">
                    <input type="hidden" name="theme_id" value="<?= $t['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ======================
       Kolom 2: SUBTEMA
       ====================== -->
  <div class="col-md-4">
    <div class="card">
      <div class="card-body">
        <h5 class="mb-3">Subtema</h5>

        <?php if ($sel_theme_id > 0): ?>
          <form method="post" class="mb-3">
            <input type="hidden" name="act" value="add_subtheme">
            <input type="hidden" name="theme_id" value="<?= $sel_theme_id ?>">
            <div class="input-group">
              <input class="form-control" name="name" placeholder="Nama subtema" required>
              <button class="btn btn-success" type="submit">Tambah Subtema</button>
            </div>
          </form>
        <?php endif; ?>

        <?php if ($sel_theme_id <= 0): ?>
          <div class="alert alert-info">Pilih Tema di kolom kiri untuk melihat Subtema.</div>
        <?php else: ?>
          <?php if (!$subs): ?>
            <div class="alert alert-secondary">Belum ada subtema pada tema ini.</div>
          <?php else: ?>
            <div class="list-group">
              <?php foreach ($subs as $s): ?>
                <?php
                $active = ($sel_subtheme_id === (int)$s['id']) ? ' active' : '';
                $url    = '?page=crud&theme_id=' . $sel_theme_id . '&subtheme_id=' . $s['id'];
                ?>
                <div class="list-group-item<?= $active ?> d-flex justify-content-between align-items-center">
                  <div>
                    <a class="text-decoration-none <?= ($active ? 'text-white' : '') ?>" href="<?= $url ?>">
                      <?= h($s['name']) ?>
                    </a>
                  </div>
                  <div class="ms-2 d-flex gap-1">
                    <!-- Rename Subtema -->
                    <button type="button" class="btn btn-sm btn-primary btn-rename"
                      data-id="<?= $s['id'] ?>"
                      data-type="subtheme"
                      data-name="<?= h($s['name']) ?>">Rename</button>
                    
                    <!-- Pindah Subtema -->
                    <button type="button" class="btn btn-sm btn-warning btn-move-subtheme" data-id="<?= (int)$s['id'] ?>">Pindah Subtema</button>

                    <!-- Hapus Subtema -->
                    <form method="post" class="d-inline" onsubmit="return confirm('Hapus SUBTEMA beserta semua judul & soal di dalamnya?\nTidak bisa dibatalkan.')">
                      <input type="hidden" name="act" value="delete_subtheme">
                      <input type="hidden" name="subtheme_id" value="<?= $s['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
                    </form>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ======================
       Kolom 3: JUDUL
       ====================== -->
  <div class="col-md-4">
    <div class="card">
      <div class="card-body">
        <h5 class="mb-3">Judul Soal</h5>

        <?php if ($sel_subtheme_id > 0): ?>
          <form method="post" class="mb-3">
            <input type="hidden" name="act" value="add_title">
            <input type="hidden" name="subtheme_id" value="<?= $sel_subtheme_id ?>">
            <div class="input-group">
              <input class="form-control" name="title" placeholder="Nama judul soal" required>
              <button class="btn btn-success" type="submit">Tambah Judul</button>
            </div>
          </form>
        <?php endif; ?>

        <?php if ($sel_subtheme_id <= 0): ?>
          <div class="alert alert-info">Pilih Subtema di kolom tengah untuk melihat Judul.</div>
        <?php else: ?>
          <?php if (!$titles): ?>
            <div class="alert alert-secondary">Belum ada judul pada subtema ini.</div>
          <?php else: ?>
            <div class="list-group">
              <?php foreach ($titles as $t): ?>
                <div class="list-group-item d-flex justify-content-between align-items-center">
                  <div>
                    <a class="text-decoration-none fw-semibold" href="?page=qmanage&title_id=<?= $t['id'] ?>">
                      <?= h($t['title']) ?>
                    </a>
                  </div>
                  <div class="ms-2 d-flex gap-1">
                    <!-- Pindah Judul -->
                    <button type="button" class="btn btn-sm btn-secondary btn-move-title" data-id="<?= (int)$t['id'] ?>">Pindah Judul</button>

                    <!-- Rename Judul -->
                    <button type="button" class="btn btn-sm btn-primary btn-rename"
                      data-id="<?= $t['id'] ?>"
                      data-type="title"
                      data-name="<?= h($t['title']) ?>">Rename</button>

                    <!-- Hapus Judul -->
                    <form method="post" class="d-inline" onsubmit="return confirm('Hapus JUDUL beserta semua soal di dalamnya?\nTidak bisa dibatalkan.')">
                      <input type="hidden" name="act" value="delete_title">
                      <input type="hidden" name="title_id" value="<?= $t['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
                    </form>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div> <!-- .row -->

<!-- ====================================================================
     MODAL DAN SCRIPT
     ==================================================================== -->

<script id="__themes_json" type="application/json">
  <?= json_encode($themes_js, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
</script>

<div id="modal-move-title" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:9999;">
  <div style="max-width:520px;margin:60px auto;background:var(--bs-body-bg); color: var(--bs-body-color);padding:16px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.2);">
    <h5 class="mb-3">Pindah Judul Soal</h5>
    <form id="form-move-title" action="?page=crud" method="post">
      <input type="hidden" name="act" value="move_title">
      <input type="hidden" name="title_id" id="move_title_id">
      <div class="mb-2">
        <label class="form-label">Tema Tujuan</label>
        <select name="target_theme_id" id="move_title_theme" class="form-select" required></select>
      </div>
      <div class="mb-2">
        <label class="form-label">Subtema Tujuan</label>
        <select name="target_subtheme_id" id="move_title_subtheme" class="form-select" required></select>
      </div>
      <div class="text-end">
        <button type="button" class="btn btn-light" data-close="modal-move-title">Batal</button>
        <button type="submit" class="btn btn-primary">Simpan</button>
      </div>
    </form>
  </div>
</div>

<div id="modal-move-subtheme" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:9999;">
  <div style="max-width:520px;margin:60px auto;background:var(--bs-body-bg); color: var(--bs-body-color);padding:16px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.2);">
    <h5 class="mb-3">Pindah Subtema</h5>
    <form id="form-move-subtheme" action="?page=crud" method="post">
      <input type="hidden" name="act" value="move_subtheme">
      <input type="hidden" name="subtheme_id" id="move_subtheme_id">
      <div class="mb-2">
        <label class="form-label">Tema Tujuan</label>
        <select name="target_theme_id" id="move_subtheme_theme" class="form-select" required></select>
      </div>
      <div class="text-end">
        <button type="button" class="btn btn-light" data-close="modal-move-subtheme">Batal</button>
        <button type="submit" class="btn btn-primary">Simpan</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="renameModal" tabindex="-1" aria-labelledby="renameModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" action="?page=crud">
        <div class="modal-header">
          <h5 class="modal-title" id="renameModalLabel">Ubah Nama</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="act" value="rename_item">
          <input type="hidden" name="item_id" id="renameItemId">
          <input type="hidden" name="item_type" id="renameItemType">
          <div class="mb-3">
            <label for="renameItemName" class="form-label">Nama Baru:</label>
            <input type="text" class="form-control" id="renameItemName" name="name" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    (function(){
      const qs  = (s)=>document.querySelector(s);

      // --- Logika untuk Modal Pindah ---
      function getThemes(){
        const node = document.getElementById('__themes_json');
        if(!node) return [];
        try { return JSON.parse(node.textContent || '[]'); } catch(e){ return []; }
      }
      function fillThemes(sel){
        if(!sel) return;
        const themes = getThemes();
        sel.innerHTML = '';
        themes.forEach(t=>{
          const o=document.createElement('option');
          o.value=t.id; o.textContent=t.name;
          sel.appendChild(o);
        });
      }
      async function fillSubthemes(themeId, sel, withEmpty=true){
        if(!sel) return;
        sel.innerHTML='';
        if(withEmpty){
          const o0=document.createElement('option');
          o0.value=''; o0.textContent='— Pilih Subtema —';
          sel.appendChild(o0);
        }
        if(!themeId) return;
        const res = await fetch('?action=api_subthemes&theme_id=' + encodeURIComponent(themeId));
        if(!res.ok){ alert('Gagal memuat subtema'); return; }
        const data = await res.json();
        data.forEach(st=>{
          const o=document.createElement('option');
          o.value=st.id; o.textContent=st.name;
          sel.appendChild(o);
        });
      }

      function show(id){ const m=qs(id); if(m) m.style.display='block'; }
      function hide(id){ const m=qs(id); if(m) m.style.display='none'; }

      document.addEventListener('click', function(e){
        const btnTitle = e.target.closest('.btn-move-title');
        if(btnTitle){
          const id = btnTitle.dataset.id;
          const tSel = qs('#move_title_theme');
          const sSel = qs('#move_title_subtheme');
          qs('#move_title_id').value = id;
          fillThemes(tSel);
          if(tSel){ fillSubthemes(tSel.value, sSel, true); }
          show('#modal-move-title');
          return;
        }
        const btnSub = e.target.closest('.btn-move-subtheme');
        if(btnSub){
          const id = btnSub.dataset.id;
          const tSel = qs('#move_subtheme_theme');
          qs('#move_subtheme_id').value = id;
          fillThemes(tSel);
          show('#modal-move-subtheme');
          return;
        }
        const closer = e.target.closest('[data-close]');
        if(closer){
          hide('#' + closer.getAttribute('data-close'));
          return;
        }
      });
      const fTitle = qs('#form-move-title');
      if(fTitle){
        const tSel = qs('#move_title_theme');
        const sSel = qs('#move_title_subtheme');
        tSel && tSel.addEventListener('change', (ev)=> fillSubthemes(ev.target.value, sSel, true));
      }
      
      // --- Logika untuk Modal Rename ---
      const renameModalEl = document.getElementById('renameModal');
      if (renameModalEl) {
          const renameModal = new bootstrap.Modal(renameModalEl);
          const renameItemId = document.getElementById('renameItemId');
          const renameItemType = document.getElementById('renameItemType');
          const renameItemName = document.getElementById('renameItemName');
          const renameModalLabel = document.getElementById('renameModalLabel');

          document.addEventListener('click', function (event) {
              const renameButton = event.target.closest('.btn-rename');
              if (renameButton) {
                  const id = renameButton.dataset.id;
                  const type = renameButton.dataset.type;
                  const currentName = renameButton.dataset.name;
                  
                  renameItemId.value = id;
                  renameItemType.value = type;
                  renameItemName.value = currentName;
                  renameModalLabel.textContent = 'Ubah Nama ' + type.charAt(0).toUpperCase() + type.slice(1);
                  
                  renameModal.show();
              }
          });
      }
    })();
});
</script>
