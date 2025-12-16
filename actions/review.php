<?php
// actions/review.php

$result_id = (int)($_GET['result_id'] ?? 0);

// 1. Ambil session_id, user_id, dan mode kuis dari results
$result = q("
    SELECT r.session_id, r.user_id, s.mode 
    FROM results r
    JOIN quiz_sessions s ON r.session_id = s.id
    WHERE r.id = ?
", [$result_id])->fetch();

if (!$result) {
    echo '<div class="alert alert-danger">Ringkasan hasil kuis tidak ditemukan.</div>';
    return;
}

$is_owner = ((int)$result['user_id'] === uid());
$is_admin = is_admin();

// Guard: Hanya admin atau pemilik hasil yang boleh melihat review
if (!$is_admin && !$is_owner) {
    echo '<div class="alert alert-danger">Anda tidak memiliki izin untuk melihat hasil ini.</div>';
    return;
}

// 2. Set mode ke dalam session PHP sementara agar view_summary dapat menampilkan mode yang benar
$original_mode = $_SESSION['quiz']['mode'] ?? null;
$_SESSION['quiz']['mode'] = $result['mode'];

// 3. Panggil logic dan view summary
echo '<h3>Review Hasil Kuis</h3>';

$sid = (int)$result['session_id'];
require 'actions/summary.php';
require 'views/summary.php';

// 4. Kembalikan mode semula (membersihkan session)
if ($original_mode !== null) {
    $_SESSION['quiz']['mode'] = $original_mode;
} else {
    unset($_SESSION['quiz']['mode']);
}
