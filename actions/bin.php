<?php
// actions/bin.php

if (!is_admin() && !is_pengajar()) {
    echo '<div class="alert alert-danger">Akses ditolak.</div>';
    return;
}

$user_id = uid();
$act = $_POST['act'] ?? '';

$can_manage_all = is_admin();

$owned_clause = '';
$owned_params = [];
if (!$can_manage_all) {
    $owned_clause = ' AND owner_user_id = ? ';
    $owned_params = [$user_id];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($act === 'restore_theme') {
        $id = (int)($_POST['theme_id'] ?? 0);
        if ($id > 0) {
            q("UPDATE themes SET deleted_at = NULL WHERE id = ?" . ($can_manage_all ? '' : ' AND owner_user_id = ?'), array_merge([$id], $owned_params));
            q("UPDATE subthemes SET deleted_at = NULL WHERE theme_id = ?" . ($can_manage_all ? '' : ' AND owner_user_id = ?'), array_merge([$id], $owned_params));
            q(
                "UPDATE quiz_titles qt JOIN subthemes st ON st.id = qt.subtheme_id SET qt.deleted_at = NULL WHERE st.theme_id = ?" . ($can_manage_all ? '' : ' AND qt.owner_user_id = ? AND st.owner_user_id = ?'),
                $can_manage_all ? [$id] : [$id, $user_id, $user_id]
            );
        }
        redirect('?page=bin&ok=1');
    }

    if ($act === 'restore_subtheme') {
        $id = (int)($_POST['subtheme_id'] ?? 0);
        if ($id > 0) {
            $row = q("SELECT theme_id FROM subthemes WHERE id = ?" . ($can_manage_all ? '' : ' AND owner_user_id = ?'), array_merge([$id], $owned_params))->fetch();
            $theme_id = (int)($row['theme_id'] ?? 0);
            if ($theme_id > 0) {
                q("UPDATE themes SET deleted_at = NULL WHERE id = ?" . ($can_manage_all ? '' : ' AND owner_user_id = ?'), array_merge([$theme_id], $owned_params));
            }
            q("UPDATE subthemes SET deleted_at = NULL WHERE id = ?" . ($can_manage_all ? '' : ' AND owner_user_id = ?'), array_merge([$id], $owned_params));
            q("UPDATE quiz_titles SET deleted_at = NULL WHERE subtheme_id = ?" . ($can_manage_all ? '' : ' AND owner_user_id = ?'), array_merge([$id], $owned_params));
        }
        redirect('?page=bin&ok=1');
    }

    if ($act === 'restore_title') {
        $id = (int)($_POST['title_id'] ?? 0);
        if ($id > 0) {
            $row = q(
                "SELECT qt.subtheme_id, st.theme_id
                 FROM quiz_titles qt
                 JOIN subthemes st ON st.id = qt.subtheme_id
                 WHERE qt.id = ?" . ($can_manage_all ? '' : ' AND qt.owner_user_id = ? AND st.owner_user_id = ?'),
                $can_manage_all ? [$id] : [$id, $user_id, $user_id]
            )->fetch();
            $subtheme_id = (int)($row['subtheme_id'] ?? 0);
            $theme_id = (int)($row['theme_id'] ?? 0);

            if ($theme_id > 0) {
                q("UPDATE themes SET deleted_at = NULL WHERE id = ?" . ($can_manage_all ? '' : ' AND owner_user_id = ?'), array_merge([$theme_id], $owned_params));
            }
            if ($subtheme_id > 0) {
                q("UPDATE subthemes SET deleted_at = NULL WHERE id = ?" . ($can_manage_all ? '' : ' AND owner_user_id = ?'), array_merge([$subtheme_id], $owned_params));
            }
            q("UPDATE quiz_titles SET deleted_at = NULL WHERE id = ?" . ($can_manage_all ? '' : ' AND owner_user_id = ?'), array_merge([$id], $owned_params));
        }
        redirect('?page=bin&ok=1');
    }
}

$themes = q(
    "SELECT id, name, owner_user_id, deleted_at FROM themes WHERE deleted_at IS NOT NULL" . ($can_manage_all ? '' : $owned_clause) . " ORDER BY deleted_at DESC",
    $owned_params
)->fetchAll();

$subthemes = q(
    "SELECT st.id, st.name, st.owner_user_id, st.theme_id, t.name AS theme_name, st.deleted_at
     FROM subthemes st
     JOIN themes t ON t.id = st.theme_id
     WHERE st.deleted_at IS NOT NULL" . ($can_manage_all ? '' : $owned_clause) . "
     ORDER BY st.deleted_at DESC",
    $owned_params
)->fetchAll();

$titles = q(
    "SELECT qt.id, qt.title, qt.owner_user_id, qt.subtheme_id, st.name AS subtheme_name, t.name AS theme_name, qt.deleted_at
     FROM quiz_titles qt
     JOIN subthemes st ON st.id = qt.subtheme_id
     JOIN themes t ON t.id = st.theme_id
     WHERE qt.deleted_at IS NOT NULL" . ($can_manage_all ? '' : $owned_clause) . "
     ORDER BY qt.deleted_at DESC",
    $owned_params
)->fetchAll();

require 'views/bin.php';
