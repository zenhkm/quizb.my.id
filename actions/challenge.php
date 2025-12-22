<?php
// actions/challenge.php

$token = $_GET['token'] ?? '';
$row = q("SELECT * FROM challenges WHERE token=?", [$token])->fetch();
if (!$row) {
    echo '<div class="alert alert-warning">Tantangan tidak ditemukan.</div>';
    return;
}
$title = q("SELECT * FROM quiz_titles WHERE id=? AND deleted_at IS NULL", [$row['title_id']])->fetch();
if (!$title) {
    echo '<div class="alert alert-warning">Kuis untuk tantangan ini tidak tersedia.</div>';
    return;
}

// === PAPAN SKOR (Top 10) — dengan medali & highlight "Anda" ===
$leaders = q("SELECT 
            cr.score, 
            cr.created_at, 
            u.name,
            r.city AS city,
            COALESCE(u.id, r.user_id) AS uid
            FROM challenge_runs cr
            LEFT JOIN results r ON r.id = cr.result_id
            LEFT JOIN users  u ON u.id = r.user_id
            WHERE cr.token = ?
            ORDER BY cr.score DESC, cr.created_at ASC
            LIMIT 10", [$row['token']])->fetchAll();

// Tampilkan view
require 'views/challenge.php';
