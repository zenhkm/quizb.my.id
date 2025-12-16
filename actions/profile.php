<?php
// actions/profile.php

global $CONFIG;
$u = $_SESSION['user'] ?? null;

// --- TAMPILAN JIKA PENGGUNA BELUM LOGIN ---
if (!uid()) {
    require 'views/profile_guest.php';
    return;
}

// --- TAMPILAN JIKA PENGGUNA SUDAH LOGIN ---
$is_own_profile = !isset($_GET['user_id']) || (int)$_GET['user_id'] === $u['id'];
$profile_user_id = $is_own_profile ? $u['id'] : (int)$_GET['user_id'];
$profile_user = q("SELECT id, name, email, avatar FROM users WHERE id = ?", [$profile_user_id])->fetch();

if (!$profile_user) {
    echo '<div class="alert alert-warning">Profil pengguna tidak ditemukan.</div>';
    return;
}

// Variabel untuk view
$recent_admin_view = null;
$rows = null;

if ($is_own_profile && is_admin()) {
    $recent_admin_view = q("SELECT 
            r.created_at,
            r.score,
            COALESCE(u.name, CONCAT('Tamu – ', COALESCE(r.city,'Anonim'))) AS display_name,
            u.avatar,
            u.id AS user_id,
            qt.title AS quiz_title
          FROM results r
          LEFT JOIN users u ON u.id = r.user_id
          LEFT JOIN quiz_titles qt ON qt.id = r.title_id
          WHERE r.score = 100
          ORDER BY r.created_at DESC
          LIMIT 20000")->fetchAll();
} else {
    $rows = q("
            SELECT 
                r.id AS result_id, 
                r.score, 
                r.created_at, 
                qt.id AS title_id, 
                qt.title, 
                st.name AS subtheme_name,
                s.mode 
            FROM results r 
            JOIN quiz_titles qt ON qt.id = r.title_id 
            JOIN subthemes st ON st.id = qt.subtheme_id 
            JOIN quiz_sessions s ON s.id = r.session_id 
            WHERE r.user_id = ? 
            ORDER BY r.created_at DESC
        ", [$profile_user_id])->fetchAll();
}

require 'views/profile.php';
