<?php
// actions/titles.php

$sub_id = (int)($_GET['subtheme_id'] ?? 0);

// Get subtheme info
$sub = q("SELECT st.*,t.name tname FROM subthemes st JOIN themes t ON t.id=st.theme_id WHERE st.id=?", [$sub_id])->fetch();
if (!$sub) {
    echo '<div class="alert alert-warning">Sub tema tidak ditemukan.</div>';
    return;
}

// Get allowed teacher IDs
// Pastikan fungsi get_allowed_teacher_ids_for_content() tersedia atau di-include
$allowed_teacher_ids = get_allowed_teacher_ids_for_content();

// Query titles dengan filter owner_user_id
if (empty($allowed_teacher_ids)) {
    $titles = q("SELECT * FROM quiz_titles WHERE subtheme_id=? AND owner_user_id IS NULL ORDER BY title", [$sub_id])->fetchAll();
} else {
    $placeholders = implode(',', array_fill(0, count($allowed_teacher_ids), '?'));
    $titles = q(
        "SELECT * FROM quiz_titles WHERE subtheme_id=? AND (owner_user_id IS NULL OR owner_user_id IN ($placeholders)) ORDER BY title",
        array_merge([$sub_id], $allowed_teacher_ids)
    )->fetchAll();
}

// Tampilkan view
require 'views/titles.php';
