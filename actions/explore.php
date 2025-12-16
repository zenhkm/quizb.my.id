<?php
// actions/explore.php

// Filter berdasarkan role dan class
$allowed_teacher_ids = get_allowed_teacher_ids_for_content();

if (empty($allowed_teacher_ids)) {
    // Hanya konten global (owner_user_id IS NULL)
    $themes = q("SELECT * FROM themes WHERE owner_user_id IS NULL ORDER BY sort_order, name")->fetchAll();
} else {
    // Konten global + milik pengajar yang diizinkan
    $placeholders = implode(',', array_fill(0, count($allowed_teacher_ids), '?'));
    $themes = q(
        "SELECT * FROM themes WHERE owner_user_id IS NULL 
         OR owner_user_id IN ($placeholders) 
         ORDER BY sort_order, name",
        $allowed_teacher_ids
    )->fetchAll();
}

require 'views/explore.php';
