<?php
// Handler POST yang benar (sekarang ditangani oleh actions/teacher_qmanage.php)
$post_handler = '?page=teacher_qmanage'; 
?>

<h3>Kelola Soal Saya</h3>

<?php
// Periksa apakah ID Judul telah dipilih
if (!$title_id) {
    echo '<div class="alert alert-info mt-3">Silakan pilih Judul Soal dari menu <a href="?page=teacher_crud">Bank Soal Saya</a> untuk mulai mengelola.</div>';
    return;
}

if (!$title_info) {
    echo '<div class="alert alert-danger">Judul kuis tidak ditemukan atau Anda tidak memilikinya.</div>';
    return;
}

// Tampilkan notifikasi
if (isset($_GET['imported'])) echo '<div class="alert alert-success">Soal Master berhasil diimpor ke Judul ini.</div>';
if (isset($_GET['ok'])) echo '<div class="alert alert-success">Perubahan berhasil disimpan.</div>';
?>

<!-- --- 2. TAMPILAN HEADER/BREADCRUMB --- -->
<div class="card bg-body-tertiary mb-4">
    <div class="card-body">
        <h5 class="card-title mb-0"><?= h($title_info['title']) ?></h5>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="?page=teacher_crud&theme_id=<?= $title_info['theme_id'] ?>"><?= h($title_info['theme_name']) ?></a></li>
                <li class="breadcrumb-item"><a href="?page=teacher_crud&theme_id=<?= $title_info['theme_id'] ?>&subtheme_id=<?= $title_info['subtheme_id'] ?>"><?= h($title_info['subtheme_name']) ?></a></li>
                <li class="breadcrumb-item active" aria-current="page">Kelola Soal</li>
            </ol>
        </nav>
    </div>
</div>

<hr class="my-4">

<!-- --- 3. FORM EDIT (Jika ada ?edit=ID) --- -->
<?php if ($edit_id): ?>
    <?php if (!$qrow): ?>
        <div class="alert alert-warning">Soal tidak ditemukan atau Anda tidak memilikinya.</div>
    <?php else: ?>
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="mb-3">Edit Soal</h5>
                <!-- ACTION: update_question_dyn_pengajar -->
                <form method="post" action="<?= $post_handler ?>" id="form-edit-q">
                    <input type="hidden" name="act" value="update_question_dyn_pengajar">
                    <input type="hidden" name="question_id" value="<?= $edit_id ?>">
                    <div class="mb-2">
                        <label class="form-label">Teks Pertanyaan</label>
                        <textarea class="form-control" name="text" required><?= h($qrow['text']) ?></textarea>
                    </div>

                    <div id="edit-choices">
                        <?php $i = 0; foreach ($choices as $c): $i++; ?>
                            <div class="border rounded-3 p-2 mb-2 choice-row">
                                <div class="d-flex align-items-center gap-2">
                                    <input type="hidden" name="cid[]" value="<?= (int)$c['id'] ?>">
                                    <input class="form-check-input mt-0" type="radio" name="correct_index" value="<?= $i ?>" <?= (!empty($c['is_correct']) ? 'checked' : '') ?>>
                                    <input class="form-control" name="ctext[]" value="<?= h($c['text']) ?>" placeholder="Teks pilihan" required>
                                    <button type="button" class="btn btn-outline-danger btn-sm remove-choice" title="Hapus"><span>&times;</span></button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="d-flex gap-2 mb-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-edit-add-choice">+ Tambah Pilihan (maks 5)</button>
                        <small class="text-muted">Minimal 2 pilihan, maksimal 5.</small>
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Penjelasan (opsional)</label>
                        <input class="form-control" name="explanation" value="<?= h($qrow['explanation']) ?>">
                    </div>
                    <button class="btn btn-primary">Simpan Perubahan</button> 
                    <a href="?page=teacher_qmanage&title_id=<?= $title_id ?>" class="btn btn-secondary">Batal</a>
                </form>

                <!-- Script JS untuk form edit -->
                <script>
                  (function(){
                    const box = document.getElementById("edit-choices");
                    const addBtn = document.getElementById("btn-edit-add-choice");
                    function countRows(){ return box.querySelectorAll(".choice-row").length; }
                    function updateRemoveButtons(){
                      box.querySelectorAll(".remove-choice").forEach(btn=>{
                        btn.onclick = function(){
                          if(countRows()<=2){ alert("Minimal harus ada 2 pilihan."); return; }
                          this.closest(".choice-row").remove();
                          const radios = box.querySelectorAll('input[type=radio][name="correct_index"]');
                          if(![...radios].some(r=>r.checked) && radios[0]) radios[0].checked = true;
                        };
                      });
                    }
                    addBtn.onclick = function(){
                      if(countRows()>=5){ alert("Maksimal 5 pilihan."); return; }
                      const idx = countRows()+1;
                      const div = document.createElement("div");
                      div.className="border rounded-3 p-2 mb-2 choice-row";
                      div.innerHTML = '<div class="d-flex align-items-center gap-2">\
                        <input type="hidden" name="cid[]" value="0">\
                        <input class="form-check-input mt-0" type="radio" name="correct_index" value="'+idx+'">\
                        <input class="form-control" name="ctext[]" placeholder="Teks pilihan" required>\
                        <button type="button" class="btn btn-outline-danger btn-sm remove-choice" title="Hapus"><span>&times;</span></button>\
                      </div>';
                      box.appendChild(div);
                      updateRemoveButtons();
                    };
                    updateRemoveButtons();
                  })();
                </script>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>


<!-- --- 4. FORM TAMBAH SOAL (Jika TIDAK sedang edit) --- -->
<?php if (!$edit_id): ?>
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="mb-3">Tambah Soal Baru</h5>
            
            <!-- ACTION: add_question_dyn_pengajar -->
            <form method="post" action="<?= $post_handler ?>" id="form-add-q">
                <input type="hidden" name="act" value="add_question_dyn_pengajar">
                <input type="hidden" name="title_id" value="<?= $title_id ?>">
                <div class="mb-2">
                    <label class="form-label">Teks Pertanyaan</label>
                    <textarea class="form-control" name="text" required></textarea>
                </div>

                <div id="add-choices">
                    <?php for ($i = 0; $i < 4; $i++): ?>
                        <div class="border rounded-3 p-2 mb-2 choice-row">
                            <div class="d-flex align-items-center gap-2">
                                <input class="form-check-input mt-0" type="radio" name="correct_index" value="<?= ($i + 1) ?>" <?= ($i === 0 ? 'checked' : '') ?>>
                                <input class="form-control" name="choice_text[]" placeholder="Teks pilihan" required>
                                <button type="button" class="btn btn-outline-danger btn-sm remove-choice" title="Hapus"><span>&times;</span></button>
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>

                <div class="d-flex gap-2 mb-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-add-choice">+ Tambah Pilihan (maks 5)</button>
                    <small class="text-muted">Centang bulatan di kiri untuk menandai jawaban benar. Minimal 2 pilihan.</small>
                </div>

                <div class="mb-2">
                    <label class="form-label">Penjelasan (opsional)</label>
                    <input class="form-control" name="explanation" placeholder="Penjelasan (opsional)">
                </div>
                <button class="btn btn-success">Tambah Soal</button>
            </form>
            
            <!-- Script JS untuk form tambah -->
            <script>
            (function(){
              const box = document.getElementById("add-choices");
              const addBtn = document.getElementById("btn-add-choice");
              function countRows(){ return box.querySelectorAll(".choice-row").length; }
              function updateRemoveButtons(){
                box.querySelectorAll(".remove-choice").forEach(btn=>{
                  btn.onclick = function(){
                    if(countRows()<=2){ alert("Minimal harus ada 2 pilihan."); return; }
                    this.closest(".choice-row").remove();
                    const radios = box.querySelectorAll('input[type=radio][name="correct_index"]');
                    if(![...radios].some(r=>r.checked) && radios[0]) radios[0].checked = true;
                  };
                });
              }
              addBtn.onclick = function(){
                if(countRows()>=5){ alert("Maksimal 5 pilihan."); return; }
                const idx = countRows()+1;
                const div = document.createElement("div");
                div.className="border rounded-3 p-2 mb-2 choice-row";
                div.innerHTML = '<div class="d-flex align-items-center gap-2">\
                  <input class="form-check-input mt-0" type="radio" name="correct_index" value="'+idx+'">\
                  <input class="form-control" name="choice_text[]" placeholder="Teks pilihan" required>\
                  <button type="button" class="btn btn-outline-danger btn-sm remove-choice" title="Hapus"><span>&times;</span></button>\
                </div>';
                box.appendChild(div);
                updateRemoveButtons();
              };
              updateRemoveButtons();
            })();
            </script>
        </div>
    </div>

    <!-- --- 5. LIST SOAL --- -->
    <?php
    // Query list soal: Filter dengan owner_user_id
    $rows = q("SELECT * FROM questions WHERE title_id=? AND owner_user_id = ? ORDER BY id DESC", [$title_id, $user_id])->fetchAll();
    ?>
    <div class="card">
        <div class="card-body">
            <h5 class="mb-3">Daftar Soal (<?= count($rows) ?> soal)</h5>
            <?php if (!$rows): ?>
                <div class="alert alert-secondary">Belum ada soal.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th width="60">ID</th>
                                <th>Pertanyaan</th>
                                <th width="220">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <?php $short = mb_strimwidth(strip_tags($r['text']), 0, 80, '…', 'UTF-8'); ?>
                                <tr>
                                    <td><?= $r['id'] ?></td>
                                    <td><?= h($short) ?></td>
                                    <td>
                                        <!-- Tombol Edit -->
                                        <a href="?page=teacher_qmanage&title_id=<?= $title_id ?>&edit=<?= $r['id'] ?>" class="btn btn-sm btn-primary me-1">Edit</a>

                                        <!-- Tombol Hapus (ACTION: del_question_pengajar) -->
                                        <form method="post" action="<?= $post_handler ?>" style="display:inline" onsubmit="return confirm('Hapus soal ini?')">
                                            <input type="hidden" name="act" value="del_question_pengajar">
                                            <input type="hidden" name="question_id" value="<?= $r['id'] ?>">
                                            <button class="btn btn-sm btn-danger">Hapus</button>
                                        </form>

                                        <!-- Tombol Duplikat (ACTION: duplicate_question_pengajar) -->
                                        <form method="post" action="<?= $post_handler ?>" style="display:inline" onsubmit="return confirm('Anda yakin ingin duplikat soal ini?')">
                                            <input type="hidden" name="act" value="duplicate_question_pengajar">
                                            <input type="hidden" name="question_id" value="<?= $r['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-info w-100 mt-2">Duplikat</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
