<?php
// actions/subthemes.php

// 1. Ambil ID tema dari URL
$theme_id = (int)($_GET['theme_id'] ?? 0);

// 2. Dapatkan informasi tema untuk judul halaman
$theme = q("SELECT id, name FROM themes WHERE id = ?", [$theme_id])->fetch();
if (!$theme) {
    echo '<div class="alert alert-warning">Tema tidak ditemukan.</div>';
    return;
}

// 3. Ambil allowed teacher IDs berdasarkan role
// Pastikan fungsi get_allowed_teacher_ids_for_content() tersedia atau di-include
// Fungsi ini ada di index.php, jadi aman jika index.php yang me-require file ini.
$allowed_teacher_ids = get_allowed_teacher_ids_for_content();

// 4. Query subtema dengan filter owner_user_id
if (empty($allowed_teacher_ids)) {
    $subthemes = q("SELECT id, name FROM subthemes WHERE theme_id = ? AND owner_user_id IS NULL ORDER BY name", [$theme_id])->fetchAll();
} else {
    $placeholders = implode(',', array_fill(0, count($allowed_teacher_ids), '?'));
    $subthemes = q(
        "SELECT id, name FROM subthemes WHERE theme_id = ? AND (owner_user_id IS NULL OR owner_user_id IN ($placeholders)) ORDER BY name",
        array_merge([$theme_id], $allowed_teacher_ids)
    )->fetchAll();
}

// 5. Tampilkan view
require 'views/subthemes.php';
