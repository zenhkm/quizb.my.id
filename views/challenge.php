<?php
// views/challenge.php
?>
<div class="card">
    <div class="card-body">
        <h4>Tantangan Kuis</h4>
        <p>Judul: <b><?= h($title['title']) ?></b></p>
        <a class="btn btn-primary" href="?action=start_challenge&token=<?= h($row['token']) ?>">Terima Tantangan</a>

        <!-- Tombol bagikan link tantangan (di halaman tantangan) -->
        <button id="shareChallengeToken" type="button" class="btn btn-outline-secondary ms-2" data-url="<?= h(base_url() . '?page=challenge&token=' . $row['token']) ?>">Tantang Teman</button>

        <!-- JS share/copy untuk tombol di atas -->
        <script>
            document.getElementById('shareChallengeToken')?.addEventListener('click', async function(e) {
                e.preventDefault();
                const url = this.getAttribute('data-url') || window.location.href;
                const title = 'Tantangan Kuis QUIZB';
                const text = 'Ayo coba kalahkan skor di tantangan ini:';

                try {
                    if (navigator.share) {
                        await navigator.share({
                            title,
                            text,
                            url
                        });
                    } else {
                        await navigator.clipboard.writeText(url);
                        alert('Link tantangan disalin ke clipboard:\n' + url);
                    }
                } catch (err) {
                    console.error(err);
                    prompt('Salin link tantangan secara manual:', url);
                }
            });
        </script>

        <!-- === PAPAN SKOR (Top 10) — dengan medali & highlight "Anda" === -->
        <hr>
        <h5 class="mb-2">Papan Skor Tantangan</h5>
        <?php if (!$leaders): ?>
            <div class="text-muted">Belum ada peserta.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th width="72">Peringkat</th>
                            <th>Nama</th>
                            <th>Skor</th>
                            <th>Waktu</th>
                            <th width="90">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $rank = 1;
                        $me = uid();
                        foreach ($leaders as $L):
                            // Medali untuk 1–3
                            $medal = ($rank === 1 ? "🥇" : ($rank === 2 ? "🥈" : ($rank === 3 ? "🥉" : "#" . $rank)));
                            // Highlight baris "Anda"
                            $isMe = ($me && (int)$L['uid'] === (int)$me);
                            $rowClass = $isMe ? ' class="table-success"' : '';
                            $city = '';
                            if (isset($L['city'])) {
                                $city = trim((string)$L['city']);
                            }
                            if ($city === '') {
                                $city = 'Anonim';
                            }

                            $nm = '';
                            if (isset($L['name'])) {
                                $nm = trim((string)$L['name']);
                            }

                            // Jika tidak ada nama user (tamu), tampilkan "Tamu – Kota"
                            if ($nm === '') {
                                $nm = 'Tamu – ' . $city;
                            }

                            if ($isMe) {
                                $nm .= ' (Anda)';
                            }
                        ?>
                            <tr<?= $rowClass ?>>
                                <td><?= $medal ?></td>
                                <td><?= h($nm) ?></td>
                                <td class="fw-bold"><?= (int)$L['score'] ?></td>
                                <td class="text-muted small"><?= h($L['created_at']) ?></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-secondary copy-link"
                                        data-url="<?= h(base_url() . '?page=challenge&token=' . $row['token']) ?>"
                                        title="Salin link tantangan">📋 Salin</button>
                                </td>
                            </tr>
                        <?php
                            $rank++;
                        endforeach;
                        ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <!-- === END PAPAN SKOR === -->

        <script>
            (function() {
                function shareOrCopy(url) {
                    try {
                        if (navigator.share) {
                            // Share jika tersedia (HP modern)
                            navigator.share({
                                title: 'Tantangan Kuis QUIZB',
                                text: 'Ikuti tantangan ini:',
                                url
                            });
                            return;
                        }
                    } catch (e) {
                        /* lanjut ke copy */ }

                    // Fallback: salin ke clipboard (desktop)
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(url).then(function() {
                            alert('Link tantangan disalin:\n' + url);
                        }, function() {
                            prompt('Salin link tantangan:', url);
                        });
                    } else {
                        prompt('Salin link tantangan:', url);
                    }
                }

                // Pasang handler ke semua tombol "📋 Salin"
                document.querySelectorAll('.copy-link').forEach(function(btn) {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        var url = this.getAttribute('data-url');
                        if (url) shareOrCopy(url);
                    });
                });
            })();
        </script>

    </div>
</div>

<?php
// [CTA Login] — versi tampilan lebih jelas dan tombol di tengah
if (!uid()) {
    global $CONFIG;
    echo '<div class="mt-4 p-4 border rounded bg-light text-center" style="max-width:500px;margin:30px auto;">';
    echo '<div style="font-size:1.1rem;font-weight:600;color:#222;margin-bottom:15px;">';
    echo 'Jangan biarkan hasil belajarmu hilang sia-sia.<br>Login dengan Google untuk menyimpannya dengan aman!';
    echo '</div>';
    echo '<div style="display:flex;justify-content:center;">';
    echo google_btn($CONFIG["GOOGLE_CLIENT_ID"]);
    echo '</div>';
    echo '</div>';
}
?>
