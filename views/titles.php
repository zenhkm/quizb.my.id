<?php
// views/titles.php
?>
<h3 class="mb-3">Judul Soal: <?= h($sub['tname']) ?> › <?= h($sub['name']) ?></h3>

<div class="list-group">
    <?php foreach ($titles as $t): ?>
        <div class="list-group-item d-flex justify-content-between align-items-center">
            <a class="text-decoration-none" href="?page=play&title_id=<?= (int)$t['id'] ?>">
                <?= h($t['title']) ?>
            </a>

            <a href="?action=download_questions&title_id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-download me-1" viewBox="0 0 16 16">
                    <path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/>
                    <path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/>
                </svg>
                Download Soal
            </a>
        </div>
    <?php endforeach; ?>
</div>
