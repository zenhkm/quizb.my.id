<?php
// views/challenges_list.php
?>
<div class="container" style="max-width:1000px;margin:20px auto">
    <?php if (!$challenges): ?>
        <div class="alert alert-info mt-3">Belum ada tantangan yang dimainkan. Buat tantangan baru dan ajak temanmu untuk bermain!</div>
</div>
<?php return; ?>
<?php endif; ?>

<div class="list-group mt-3">
    <?php foreach ($challenges as $ch):
        $judul = h($ch['title']);
        $peserta = (int)$ch['participant_count'];
        $token = h($ch['latest_token']);
        $url = '?page=challenge&token=' . $token;
    ?>
        <div class="list-group-item p-3">
            <div class="row g-2 align-items-center">

                <div class="col-md">
                    <h5 class="mb-1"><?= $judul ?></h5>
                    <span class="badge bg-secondary fw-normal"><?= $peserta ?> Peserta</span>
                </div>

                <div class="col-md-auto">
                    <a href="<?= $url ?>" class="btn btn-primary w-100">
                        Lihat & Tantang
                    </a>
                </div>

            </div>
        </div>
    <?php endforeach; ?>
</div>
</div>
