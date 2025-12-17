<div class="container mt-4">
    <?php
    // Tampilkan notifikasi jika ada
    if (isset($_GET['ok'])) echo '<div class="alert alert-success">Operasi berhasil.</div>';
    if (isset($_GET['err'])) echo '<div class="alert alert-danger">Terjadi kesalahan.</div>';
    ?>

    <h3>Bank Soal Saya</h3>
    <p class="text-muted">Kelola Tema, Subtema, dan Judul Kuis pribadi Anda.</p>

    <div class="row g-3">

        <!-- ===============================================
             Kolom 1: TEMA (FILTER: owner_user_id = uid())
             =============================================== -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="mb-3">Tema Pribadi</h5>

                    <!-- Form Tambah Tema -->
                    <form method="post" action="?page=teacher_crud" class="mb-3">
                        <input type="hidden" name="act" value="add_theme">
                        <div class="input-group mb-1">
                            <input class="form-control" name="name" placeholder="Nama tema" required>
                            <button class="btn btn-success" type="submit">Tambah Tema</button>
                        </div>
                    </form>

                    <?php if (!$themes): ?>
                        <div class="alert alert-secondary">Belum ada tema pribadi.</div>
                    <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($themes as $t): ?>
                                <?php
                                $active = ($sel_theme_id === (int)$t['id']) ? ' active' : '';
                                $url    = '?page=teacher_crud&theme_id=' . $t['id'];
                                ?>
                                <div class="list-group-item<?= $active ?> d-flex justify-content-between align-items-center">
                                    <div>
                                        <a class="text-decoration-none <?= ($active ? 'text-white' : '') ?>" href="<?= $url ?>">
                                            <?= h($t['name']) ?>
                                        </a>
                                    </div>
                                    <div class="ms-2 d-flex gap-1">
                                        <button type="button" class="btn btn-sm btn-primary btn-rename" data-id="<?= $t['id'] ?>" data-type="theme" data-name="<?= h($t['name']) ?>">Rename</button>

                                        <form method="post" action="?page=teacher_crud" class="d-inline" onsubmit="return confirm('Hapus TEMA beserta semua isinya?\nTindakan ini tidak bisa dibatalkan.')">
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
        </div> <!-- END Kolom 1 -->

        <!-- ===============================================
             Kolom 2: SUBTEMA (FILTER: owner_user_id = uid())
             =============================================== -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="mb-3">Subtema Pribadi</h5>
                    <?php if ($sel_theme_id > 0): ?>
                        <!-- Form Tambah Subtema -->
                        <form method="post" action="?page=teacher_crud" class="mb-3">
                            <input type="hidden" name="act" value="add_subtheme">
                            <input type="hidden" name="theme_id" value="<?= $sel_theme_id ?>">
                            <div class="input-group">
                                <input class="form-control" name="name" placeholder="Nama subtema" required>
                                <button class="btn btn-success" type="submit">Tambah Subtema</button>
                            </div>
                        </form>

                        <!-- Filter Subtema -->
                        <?php if (!$subs): ?>
                            <div class="alert alert-secondary">Belum ada subtema pada tema ini.</div>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($subs as $s): ?>
                                    <?php
                                    $active = ($sel_subtheme_id === (int)$s['id']) ? ' active' : '';
                                    $url    = '?page=teacher_crud&theme_id=' . $sel_theme_id . '&subtheme_id=' . $s['id'];
                                    ?>
                                    <div class="list-group-item<?= $active ?> d-flex justify-content-between align-items-center">
                                        <div>
                                            <a class="text-decoration-none <?= ($active ? 'text-white' : '') ?>" href="<?= $url ?>">
                                                <?= h($s['name']) ?>
                                            </a>
                                        </div>
                                        <div class="ms-2 d-flex gap-1">
                                            <button type="button" class="btn btn-sm btn-primary btn-rename"
                                                data-id="<?= $s['id'] ?>"
                                                data-type="subtheme"
                                                data-name="<?= h($s['name']) ?>">Rename</button>

                                            <form method="post" action="?page=teacher_crud" class="d-inline" onsubmit="return confirm('Hapus SUBTEMA beserta semua judul & soal di dalamnya?\nTidak bisa dibatalkan.')">
                                                <input type="hidden" name="act" value="delete_subtheme">
                                                <input type="hidden" name="subtheme_id" value="<?= $s['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-info">Pilih Tema di kolom kiri.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div> <!-- END Kolom 2 -->

        <!-- ===============================================
             Kolom 3: JUDUL (FILTER: owner_user_id = uid())
             =============================================== -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="mb-3">Judul Soal Pribadi</h5>
                    <?php if ($sel_subtheme_id > 0): ?>
                        <!-- Form Tambah Judul -->
                        <form method="post" action="?page=teacher_crud" class="mb-3">
                            <input type="hidden" name="act" value="add_title">
                            <input type="hidden" name="subtheme_id" value="<?= $sel_subtheme_id ?>">
                            <div class="input-group">
                                <input class="form-control" name="title" placeholder="Nama judul soal" required>
                                <button class="btn btn-success" type="submit">Tambah Judul</button>
                            </div>
                        </form>

                        <!-- Filter Judul -->
                        <?php if (!$titles): ?>
                            <div class="alert alert-secondary">Belum ada judul pada subtema ini.</div>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($titles as $t): ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <!-- Link ke Kelola Soal (teacher_qmanage) -->
                                        <div>
                                            <a class="text-decoration-none fw-semibold" href="?page=teacher_qmanage&title_id=<?= $t['id'] ?>">
                                                <?= h($t['title']) ?>
                                            </a>
                                        </div>
                                        <div class="ms-2 d-flex gap-1">
                                            <!-- Tombol Rename Judul -->
                                            <button type="button" class="btn btn-sm btn-primary btn-rename"
                                                data-id="<?= $t['id'] ?>"
                                                data-type="title"
                                                data-name="<?= h($t['title']) ?>">Rename</button>

                                            <!-- Form Hapus Judul -->
                                            <form method="post" action="?page=teacher_crud" class="d-inline" onsubmit="return confirm('Hapus JUDUL beserta semua soal di dalamnya?\nTidak bisa dibatalkan.')">
                                                <input type="hidden" name="act" value="delete_title">
                                                <input type="hidden" name="title_id" value="<?= $t['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div> <!-- Tutup list-group -->

                            <!-- Tombol Import Soal Master -->
                            <hr>
                            <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#importMasterSoalModal" data-target-title-id="<?= $titles[0]['id'] ?>">Import Soal Master</button>

                        <?php endif; ?>

                    <?php else: ?>
                        <div class="alert alert-info">Pilih Subtema di kolom tengah.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div> <!-- END Kolom 3 -->

    </div> <!-- END .row g-3 -->
</div> <!-- END .container -->

<!-- Modal Rename (Reused from Admin CRUD if available, or defined here) -->
<!-- Assuming renameModal is global or we need to add it here if it's not present in footer -->
<!-- Adding it here just in case, similar to view_crud -->
<div class="modal fade" id="renameModal" tabindex="-1" aria-labelledby="renameModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" action="?page=teacher_crud">
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
});
</script>
