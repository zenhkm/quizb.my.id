<?php
// views/titles.php
?>
<h3 class="mb-3">Judul Soal: <?= h($sub['tname']) ?> › <?= h($sub['name']) ?></h3>

<div class="list-group">
    <?php foreach ($titles as $t): ?>
        <a class="list-group-item list-group-item-action" href="?page=play&title_id=<?= $t['id'] ?>">
            <?= h($t['title']) ?>
        </a>
    <?php endforeach; ?>
</div>
