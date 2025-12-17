<?php
// views/difficulty_questions.php
?>
<div class="container">
    <div class="mb-3">
        <a href="?page=difficulty&metric=<?= $metric ?>&min=<?= $min ?>" class="btn btn-outline-secondary btn-sm">← Kembali ke Judul</a>
    </div>

    <h1 class="h4 mb-2 text-center">Detail Kesulitan Soal</h1>
    <p class="text-center text-muted mb-4"><?= h($title['title']) ?></p>

    <div class="text-center mb-3">
        <a class="btn btn-sm <?= ($metric === 'count' ? 'btn-primary' : 'btn-outline-primary') ?>" href="<?= $urlCount ?>">Hitung Kesalahan</a>
        <a class="btn btn-sm <?= ($metric === 'ratio' ? 'btn-primary' : 'btn-outline-primary') ?>" href="<?= $urlRatio ?>">Rasio Kesalahan</a>
    </div>

    <?php if ($metric === 'ratio'): ?>
        <p class="text-muted small text-center mb-3">Menampilkan soal dengan attempt ≥ <strong><?= $min ?></strong>.</p>
    <?php else: ?>
        <p class="text-muted small text-center mb-3">Urut: paling sering salah → paling jarang salah.</p>
    <?php endif; ?>

    <?php if (!$rows): ?>
        <div class="alert alert-secondary">Belum ada data soal yang salah untuk judul ini (atau filter menyembunyikannya).</div>
</div>
<?php return; ?>
<?php endif; ?>

<div class="list-group">
    <?php foreach ($rows as $r):
        $qText   = h($r['text']);
        $wrong   = (int)$r['wrong_count'];
        $attempt = (int)$r['total_attempts'];
        $ratio   = (float)$r['wrong_ratio'];
        $pct     = ($attempt > 0) ? round($ratio * 100) : 0;
    ?>
        <div class="list-group-item">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div class="fw-bold me-2"><?= $qText ?></div>
                <div class="text-nowrap">
                    <span class="badge text-bg-danger">Salah: <?= $wrong ?></span>
                    <span class="badge text-bg-secondary">Attempt: <?= $attempt ?></span>
                    <?php if ($attempt > 0): ?>
                        <span class="badge text-bg-dark"><?= $pct ?>%</span>
                    <?php else: ?>
                        <span class="badge text-bg-dark">0%</span>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Tampilkan opsi jawaban -->
            <?php
            // Ambil opsi jawaban
            $choices = q("SELECT * FROM choices WHERE question_id = ? ORDER BY id ASC", [$r['id']])->fetchAll();
            if ($choices):
            ?>
                <ul class="list-group list-group-flush small mt-2">
                    <?php foreach ($choices as $c):
                        $isCorrect = ($c['is_correct'] == 1);
                        $cls = $isCorrect ? 'list-group-item-success' : '';
                        $mark = $isCorrect ? '✅ ' : '⚪ ';
                    ?>
                        <li class="list-group-item <?= $cls ?> py-1 border-0">
                            <?= $mark . h($c['text']) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
</div>
