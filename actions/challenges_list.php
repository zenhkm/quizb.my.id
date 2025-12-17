<?php
// actions/challenges_list.php

if (!uid()) {
    global $CONFIG;
    echo '<div class="container py-5 text-center" style="max-width: 500px;">';
    echo '<p class="lead mb-4">Anda harus login untuk melihat Data Challenge. Silakan login dengan akun Google Anda.</p>';
    echo google_btn($CONFIG["GOOGLE_CLIENT_ID"]);
    echo '<div class="mt-4"><a href="./" class="btn btn-outline-secondary">Kembali ke Beranda</a></div>';
    echo '</div>';
    return;
}

// Query SQL tetap sama, tidak perlu diubah
$sql = "
    SELECT
      qt.id AS title_id,
      qt.title,
      COUNT(DISTINCT cr.result_id) AS participant_count,
      (SELECT 
         c2.token 
       FROM challenges c2 
       WHERE c2.title_id = qt.id 
       ORDER BY c2.created_at DESC 
       LIMIT 1
      ) AS latest_token
    FROM quiz_titles qt
    JOIN challenges c ON c.title_id = qt.id
    JOIN challenge_runs cr ON cr.token = c.token
    WHERE qt.owner_user_id IS NULL
    GROUP BY qt.id, qt.title
    HAVING participant_count > 0
    ORDER BY participant_count DESC, qt.title ASC LIMIT 10
  ";

$challenges = q($sql)->fetchAll(PDO::FETCH_ASSOC);

// Tampilkan view
require 'views/challenges_list.php';
