<?php
// views/bin.php

if (!is_admin() && !is_pengajar()) {
    echo '<div class="alert alert-danger">Akses ditolak.</div>';
    return;
}

$ok = (int)($_GET['ok'] ?? 0);
?>

<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">Bin</h3>
    <a class="btn btn-outline-secondary" href="?page=home">Kembali</a>
  </div>

  <?php if ($ok): ?>
    <div class="alert alert-success">Berhasil dipulihkan.</div>
  <?php endif; ?>

  <div class="card mb-4">
    <div class="card-header fw-semibold">Tema Terhapus</div>
    <div class="card-body">
      <?php if (empty($themes)): ?>
        <div class="text-muted">Tidak ada data.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Nama</th>
                <th>Deleted At</th>
                <th style="width:140px;"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($themes as $t): ?>
                <tr>
                  <td><?= h($t['name'] ?? '') ?></td>
                  <td class="text-muted"><?= h($t['deleted_at'] ?? '') ?></td>
                  <td>
                    <form method="post" class="m-0">
                      <input type="hidden" name="act" value="restore_theme">
                      <input type="hidden" name="theme_id" value="<?= (int)$t['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-primary">Restore</button>
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

  <div class="card mb-4">
    <div class="card-header fw-semibold">Subtema Terhapus</div>
    <div class="card-body">
      <?php if (empty($subthemes)): ?>
        <div class="text-muted">Tidak ada data.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Subtema</th>
                <th>Tema</th>
                <th>Deleted At</th>
                <th style="width:140px;"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($subthemes as $st): ?>
                <tr>
                  <td><?= h($st['name'] ?? '') ?></td>
                  <td class="text-muted"><?= h($st['theme_name'] ?? '') ?></td>
                  <td class="text-muted"><?= h($st['deleted_at'] ?? '') ?></td>
                  <td>
                    <form method="post" class="m-0">
                      <input type="hidden" name="act" value="restore_subtheme">
                      <input type="hidden" name="subtheme_id" value="<?= (int)$st['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-primary">Restore</button>
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

  <div class="card mb-4">
    <div class="card-header fw-semibold">Judul Terhapus</div>
    <div class="card-body">
      <?php if (empty($titles)): ?>
        <div class="text-muted">Tidak ada data.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Judul</th>
                <th>Subtema</th>
                <th>Tema</th>
                <th>Deleted At</th>
                <th style="width:140px;"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($titles as $qt): ?>
                <tr>
                  <td><?= h($qt['title'] ?? '') ?></td>
                  <td class="text-muted"><?= h($qt['subtheme_name'] ?? '') ?></td>
                  <td class="text-muted"><?= h($qt['theme_name'] ?? '') ?></td>
                  <td class="text-muted"><?= h($qt['deleted_at'] ?? '') ?></td>
                  <td>
                    <form method="post" class="m-0">
                      <input type="hidden" name="act" value="restore_title">
                      <input type="hidden" name="title_id" value="<?= (int)$qt['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-primary">Restore</button>
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
</div>
