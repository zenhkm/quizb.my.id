<?php
// views/subthemes.php
?>
<h3 class="mb-3">Pilih Subtema: <?= h($theme['name']) ?></h3>

<?php if (empty($subthemes)): ?>
    <div class="alert alert-secondary">Belum ada subtema untuk tema ini. Silakan tambahkan melalui halaman admin.</div>
<?php else: ?>
    <div class="list-group">
        <?php foreach ($subthemes as $sub): ?>
            <a href="?page=titles&subtheme_id=<?= $sub['id'] ?>" class="list-group-item list-group-item-action">
                <?= h($sub['name']) ?>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
