<?php
// views/difficulty_titles.php
?>
<div class="container">
    <h1 class="h4 mb-2 text-center">Peta Kesulitan — Judul Soal</h1>
    <div class="text-center mb-3">
        <a class="btn btn-sm <?= ($metric === 'count' ? 'btn-primary' : 'btn-outline-primary') ?>" href="<?= $urlCount ?>">Hitung Kesalahan</a>
        <a class="btn btn-sm <?= ($metric === 'ratio' ? 'btn-primary' : 'btn-outline-primary') ?>" href="<?= $urlRatio ?>">Rasio Kesalahan</a>
    </div>

    <?php if ($metric === 'ratio'): ?>
        <p class="text-muted small text-center mb-3">Menampilkan judul dengan attempt ≥ <strong><?= $min ?></strong>. Ubah ambang: tambahkan <code>&min=5</code> (misal).</p>
    <?php else: ?>
        <p class="text-muted small text-center mb-3">Urut: paling sering salah → paling jarang salah.</p>
    <?php endif; ?>

    <?php if (!$rows): ?>
        <div class="alert alert-secondary">Belum ada data sesuai filter.</div>
</div>
<?php return; ?>
<?php endif; ?>

<div class="list-group">
    <?php foreach ($rows as $r):
        $id      = (int)$r['id'];
        $title   = h($r['title']);
        $wrong   = (int)$r['wrong_count'];
        $attempt = (int)$r['total_attempts'];
        $ratio   = (float)$r['wrong_ratio'];
        $pct     = ($attempt > 0) ? round($ratio * 100) : 0;

        // Bangun URL pertanyaan dengan metric yang sama
        $qs = http_build_query([
            'page' => 'difficulty_questions',
            'title_id' => $id,
            'metric' => $metric,
            'min' => $min,
        ]);
        $href = '?' . $qs;
    ?>
        <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="<?= $href ?>">
            <span><?= $title ?></span>
            <span class="text-nowrap">
                <span class="badge text-bg-danger me-1">Salah: <?= $wrong ?></span>
                <span class="badge text-bg-secondary me-1">Attempt: <?= $attempt ?></span>
                <?php if ($attempt > 0): ?>
                    <span class="badge text-bg-dark"><?= $pct ?>%</span>
                <?php else: ?>
                    <span class="badge text-bg-dark">0%</span>
                <?php endif; ?>
            </span>
        </a>
    <?php endforeach; ?>
</div>
</div>
