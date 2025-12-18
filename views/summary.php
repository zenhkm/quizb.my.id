<?php
// views/summary.php

echo '<style>
        .summary-table tbody tr {
            border-bottom: 1px solid var(--bs-border-color-translucent);
        }
        .summary-table .question-text {
            font-weight: 600;
            color: var(--bs-emphasis-color);
            display: block;
            margin-bottom: 0.5rem;
        }
        .summary-table .answer-label {
            font-size: 0.8rem;
            color: var(--bs-secondary-color);
            display: block;
        }
        .summary-table .user-answer-incorrect {
            color: var(--bs-danger);
            text-decoration: line-through;
        }
        .summary-table .correct-answer {
            color: var(--bs-success);
        }
    </style>';

$mode_text = '';
if (isset($session['mode'])) {
    if ($session['mode'] === 'instant') {
        $mode_text = 'Mode Instan Review';
    } elseif ($session['mode'] === 'end') {
        $mode_text = 'Mode End Review';
    } elseif ($session['mode'] === 'exam') {
        $mode_text = 'Mode Ujian';
    }
}

echo '<div class="card"><div class="card-body">';
echo '  <h4 class="card-title">Ringkasan Kuis ' . $mode_text . '</h4>';

echo '  <p class="card-subtitle mb-2 text-muted">' . h($quiz_details['title']) . '</p>';

echo '  <div class="text-center bg-light rounded p-3 my-4 summary-score-box">';
echo '      <div class="score-label">SKOR AKHIR</div>';
echo '      <div class="display-4 fw-bold text-primary">' . $score . '</div>';
echo '      <div class="score-details">' . $correct . ' dari ' . $total_questions_in_quiz . ' soal benar</div>';
echo '  </div>';

// ▼▼▼ LOGIKA UTAMA: SEMBUNYIKAN REVIEW JIKA MODE UJIAN (kecuali admin) ▼▼▼
if ($session['mode'] !== 'exam' || (function_exists('is_admin') && is_admin())) {
    echo '<div class="table-responsive">';
    echo '  <table class="table table-borderless align-middle summary-table">';
    echo '      <thead><tr class="small text-muted"><th style="width: 5%;">#</th><th>Pertanyaan & Jawaban</th><th class="text-end">Status</th></tr></thead>';
    echo '      <tbody>';

    $i = 1;
    foreach ($att as $a) {
        $is_correct = (bool)$a['is_correct'];
        $status_badge = $is_correct ? '<span class="badge text-bg-success">Benar</span>' : '<span class="badge text-bg-danger">Salah</span>';

        echo '<tr>';
        echo '  <td class="text-muted">' . $i++ . '.</td>';
        echo '  <td>';
        echo '      <span class="question-text">' . h($a['qtext']) . '</span>';
        if ($is_correct) {
            echo '<div><span class="correct-answer">✅ ' . h($a['choice_text']) . '</span></div>';
        } else {
            echo '<div><span class="user-answer-incorrect">' . h($a['choice_text']) . '</span></div>';
            echo '<div><span class="correct-answer">👍 ' . h($a['correct_text']) . '</span></div>';
        }
        echo '  </td>';
        echo '  <td class="text-end">' . $status_badge . '</td>';
        echo '</tr>';
    }

    echo '      </tbody>';
    echo '  </table>';
    echo '</div>';
} else {
    echo '<div class="alert alert-info text-center">Review jawaban tidak ditampilkan untuk Mode Ujian.</div>';
}
// ▲▲▲ AKHIR DARI LOGIKA KONDISIONAL ▲▲▲

echo '<div class="d-flex flex-wrap gap-2">'; // Tambahkan flex-wrap untuk responsivitas

if ($score == 100 && isset($_SESSION['user']) && $quiz_details) {
    $js_userName = h($_SESSION['user']['name'] ?? 'Peserta');
    $js_userEmail = h($_SESSION['user']['email'] ?? '');
    $js_quizTitle = h($quiz_details['title']);
    $js_subTheme = h($quiz_details['subtheme_name']);
    $js_quizMode = h($session['mode']);
    echo "<button class='btn btn-success kirim-laporan-btn' data-user-name='{$js_userName}' data-user-email='{$js_userEmail}' data-quiz-title='{$js_quizTitle}' data-sub-theme='{$js_subTheme}' data-quiz-mode='{$js_quizMode}'>Kirim Laporan</button>";
}

// Cek apakah ini adalah sesi dari sebuah tugas
$assignment_id = $_SESSION['quiz']['assignment_id'] ?? null;
if ($assignment_id) {
    // Jika ya, link "Coba Lagi" harus menggunakan assignment_id
    echo '<a class="btn btn-primary" href="?page=play&assignment_id=' . $assignment_id . '&restart=1">Coba Lagi</a>';
} else {
    // Jika tidak (kuis biasa), gunakan link lama
    echo '<a class="btn btn-primary" href="?page=play&title_id=' . $session['title_id'] . '&mode=' . $session['mode'] . '&restart=1">Coba Lagi</a>';
}

if ($quiz_details && $score == 100) {
    $story_quiz_title = $quiz_details['title'] ?? 'Kuis';
    $story_sub_theme = $quiz_details['subtheme_name'] ?? '';
    $mode_selection_url = base_url() . '?page=play&title_id=' . $session['title_id'];
    $storyText = "Alhamdulillah, tuntas! 💯\nSaya baru saja menyelesaikan kuis \"{$story_quiz_title} - {$story_sub_theme}\" di QuizB.\n\nIngin mencoba juga? Klik di sini:\n{$mode_selection_url}\n\n#QuizB #BelajarAsyik";
    $encodedStoryText = urlencode($storyText);
    $wa_link = "https://wa.me/?text=" . $encodedStoryText;
    echo "<a href='{$wa_link}' target='_blank' class='btn btn-success'><svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='currentColor' class='bi bi-whatsapp' viewBox='0 0 16 16'><path d='M13.601 2.326A7.85 7.85 0 0 0 7.994 0C3.627 0 .068 3.558.064 7.926c0 1.399.366 2.76 1.057 3.965L0 16l4.204-1.102a7.9 7.9 0 0 0 3.79.965h.004c4.368 0 7.926-3.558 7.93-7.93A7.89 7.89 0 0 0 13.6 2.326zM7.994 14.521a6.57 6.57 0 0 1-3.356-.92l-.24-.144-2.494.654.666-2.433-.156-.251a6.56 6.56 0 0 1-1.007-3.505c0-3.626 2.957-6.584 6.591-6.584a6.56 6.56 0 0 1 4.66 1.931 6.56 6.56 0 0 1 1.928 4.66c-.004 3.639-2.961 6.592-6.592 6.592m3.615-4.934c-.197-.099-1.17-.578-1.353-.646-.182-.068-.315-.099-.445.099-.133.197-.513.646-.627.775-.114.133-.232.148-.43.05-.197-.1-.836-.308-1.592-.985-.59-.525-.985-1.175-1.103-1.372-.114-.198-.011-.304.088-.403.087-.088.197-.232.296-.346.1-.114.133-.198.198-.33.065-.134.034-.248-.015-.347-.05-.099-.445-1.076-.612-1.47-.16-.389-.323-.335-.445-.34-.114-.007-.247-.007-.38-.007a.73.73 0 0 0-.529.247c-.182.198-.691.677-.691 1.654s.71 1.916.81 2.049c.098.133 1.394 2.132 3.383 2.992.47.205.84.326 1.129.418.475.152.904.129 1.246.08.38-.058 1.171-.48 1.338-.943.164-.464.164-.86.114-.943-.049-.084-.182-.133-.38-.232z'/></svg> Story WA</a>";
}

if (!$inChallenge && uid()) {
    // PERBAIKAN: Tampilkan tombol untuk membuat challenge (bukan hanya jika sudah ada)
    echo '<button id="createChallenge" type="button" class="btn btn-primary" data-title-id="' . $session['title_id'] . '" data-session-id="' . $sid . '">Tantang Teman</button>';
}

echo '<a class="btn btn-outline-secondary" href="?page=titles&subtheme_id=' . h(q("SELECT subtheme_id FROM quiz_titles WHERE id=?", [$session['title_id']])->fetch()['subtheme_id']) . '">Pilih Judul Lain</a>';
echo '</div>';

if (!empty($_SESSION['current_challenge_token'])) {
    $tok = $_SESSION['current_challenge_token'];
    $meta = q("SELECT owner_result_id FROM challenges WHERE token=? LIMIT 1", [$tok])->fetch();
    if ($meta && !empty($meta['owner_result_id'])) {
        $owner = q("SELECT r.id, r.score, u.name FROM results r LEFT JOIN users u ON u.id = r.user_id WHERE r.id=? LIMIT 1", [(int)$meta['owner_result_id']])->fetch();
        $ownerName  = $owner['name'] ?? 'Pemilik Tantangan';
        $ownerScore = (int)($owner['score'] ?? 0);
        $myScore    = (int)($myRes['score'] ?? 0);
        $status = ($myScore > $ownerScore) ? 'Kamu MENANG 🎉' : (($myScore < $ownerScore) ? 'Kamu KALAH 😅' : 'SERI 🤝');
        echo '<hr>';
        echo '<div class="p-3 border rounded-3">';
        echo '<h5 class="mb-2">Perbandingan Skor</h5>';
        echo '<div class="row">';
        echo '  <div class="col-md-6"><div class="border rounded-3 p-2 mb-2"><div class="small text-muted">Pemilik Tantangan</div><div class="fs-5">' . h($ownerName) . '</div><div class="fw-bold">Skor: ' . $ownerScore . '</div></div></div>';
        echo '  <div class="col-md-6"><div class="border rounded-3 p-2 mb-2"><div class="small text-muted">Kamu</div><div class="fs-5">' . h($_SESSION['user']['name'] ?? 'Kamu') . '</div><div class="fw-bold">Skor: ' . $myScore . '</div></div></div>';
        echo '</div>';
        echo '<div class="alert ' . ($myScore > $ownerScore ? 'alert-success' : ($myScore < $ownerScore ? 'alert-warning' : 'alert-info')) . ' mt-2">' . $status . '</div>';
        echo '</div>';
    }
}

echo '</div></div>'; // Penutup card-body dan card

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
