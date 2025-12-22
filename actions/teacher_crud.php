<?php
if (!is_pengajar() && !is_admin()) {
    echo '<div class="alert alert-danger">Akses ditolak.</div>';
    return;
}

$act = $_POST['act'] ?? $_GET['act'] ?? '';
$user_id = uid();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($act === 'add_theme') {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $url_redirect = '?page=teacher_crud';
        if ($name !== '') {
            q("INSERT INTO themes (name, description, owner_user_id) VALUES (?,?,?)", [$name, $desc, $user_id]);
            $new_id = pdo()->lastInsertId();
            $url_redirect = '?page=teacher_crud&theme_id=' . $new_id;
        }
        redirect($url_redirect);
    }

    if ($act === 'delete_theme') {
        $id = (int)($_POST['theme_id'] ?? 0);
        if ($id > 0) {
            q("DELETE FROM themes WHERE id=? AND owner_user_id=?", [$id, $user_id]);
        }
        redirect('?page=teacher_crud&ok=1');
    }

    if ($act === 'add_subtheme') {
        $theme_id = (int)($_POST['theme_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $url_redirect = '?page=teacher_crud';
        
        $is_owner = q("SELECT id FROM themes WHERE id=? AND owner_user_id=?", [$theme_id, $user_id])->fetch();

        if ($is_owner && $name !== '') {
            q("INSERT INTO subthemes (theme_id, name, owner_user_id) VALUES (?,?,?)", [$theme_id, $name, $user_id]);
            $new_id = pdo()->lastInsertId();
            $url_redirect = '?page=teacher_crud&theme_id=' . $theme_id . '&subtheme_id=' . $new_id;
        }
        redirect($url_redirect);
    }
    
    if ($act === 'delete_subtheme') {
        $id = (int)($_POST['subtheme_id'] ?? 0);
        $theme_id = 0;
        if ($id > 0) {
            $row = q("SELECT theme_id FROM subthemes WHERE id=? AND owner_user_id=?", [$id, $user_id])->fetch();
            $theme_id = $row ? (int)$row['theme_id'] : 0;
            q("DELETE FROM subthemes WHERE id=? AND owner_user_id=?", [$id, $user_id]);
        }
        redirect('?page=teacher_crud&theme_id=' . $theme_id . '&ok=1');
    }

    if ($act === 'add_title') {
        $subtheme_id = (int)($_POST['subtheme_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $url_redirect = '?page=teacher_crud';

        $is_owner = q("SELECT id FROM subthemes WHERE id=? AND owner_user_id=?", [$subtheme_id, $user_id])->fetch();
        
        if ($is_owner && $title !== '') {
            q("INSERT INTO quiz_titles (subtheme_id, title, owner_user_id) VALUES (?,?,?)", [$subtheme_id, $title, $user_id]);
            $new_id = pdo()->lastInsertId();

            // Notifikasi broadcast: kuis baru
            $subtheme_info = q("SELECT name FROM subthemes WHERE id = ? AND owner_user_id = ?", [$subtheme_id, $user_id])->fetch();
            $subtheme_name = $subtheme_info['name'] ?? '';
            $notif_message = "Kuis baru di subtema \"" . h($subtheme_name) . "\": \"" . h($title) . "\"";
            $notif_link = "?page=play&title_id=" . (int)$new_id;
            q(
                "INSERT INTO broadcast_notifications (type, message, link, related_id) VALUES ('new_quiz', ?, ?, ?)",
                [$notif_message, $notif_link, (int)$new_id]
            );

            $url_redirect = '?page=teacher_qmanage&title_id=' . $new_id;
        }
        redirect($url_redirect);
    }

    if ($act === 'delete_title') {
        $id = (int)($_POST['title_id'] ?? 0);
        $subtheme_id = 0;
        if ($id > 0) {
            $row = q("SELECT subtheme_id FROM quiz_titles WHERE id=? AND owner_user_id=?", [$id, $user_id])->fetch();
            $subtheme_id = $row ? (int)$row['subtheme_id'] : 0;
            q("DELETE FROM quiz_titles WHERE id=? AND owner_user_id=?", [$id, $user_id]);
        }
        redirect('?page=teacher_crud&subtheme_id=' . $subtheme_id . '&ok=1');
    }
    
    if ($act === 'rename_item') {
        $id = (int)($_POST['item_id'] ?? 0);
        $type = $_POST['item_type'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $redirect_to = '?page=teacher_crud';
        
        if ($id > 0 && $name !== '') {
            $table = '';
            $col_name = 'name';
            
            switch ($type) {
                case 'theme': $table = 'themes'; $redirect_to = '?page=teacher_crud&theme_id=' . $id; break;
                case 'subtheme': $table = 'subthemes'; $col_name = 'name';
                    $row = q("SELECT theme_id FROM subthemes WHERE id=? AND owner_user_id=?", [$id, $user_id])->fetch();
                    if ($row) $redirect_to = '?page=teacher_crud&theme_id=' . $row['theme_id'] . '&subtheme_id=' . $id; 
                    break;
                case 'title': $table = 'quiz_titles'; $col_name = 'title'; 
                    $row = q("SELECT subtheme_id FROM quiz_titles WHERE id=? AND owner_user_id=?", [$id, $user_id])->fetch();
                    if ($row) {
                        $theme_row = q("SELECT theme_id FROM subthemes WHERE id=?", [$row['subtheme_id']])->fetch();
                        $redirect_to = '?page=teacher_crud&theme_id=' . $theme_row['theme_id'] . '&subtheme_id=' . $row['subtheme_id'];
                    }
                    break;
            }

            if ($table) {
                q("UPDATE {$table} SET {$col_name}=? WHERE id=? AND owner_user_id=?", [$name, $id, $user_id]);
            }
        }
        redirect($redirect_to);
    }
}

// --- Data Fetching for View ---

$sel_theme_id    = isset($_GET['theme_id']) ? (int)$_GET['theme_id'] : 0;
$sel_subtheme_id = isset($_GET['subtheme_id']) ? (int)$_GET['subtheme_id'] : 0;

$themes = q("SELECT id,name FROM themes WHERE owner_user_id = ? ORDER BY name", [$user_id])->fetchAll();

$subs = [];
if ($sel_theme_id > 0) {
    $subs = q("SELECT id,name FROM subthemes WHERE theme_id=? AND owner_user_id = ? ORDER BY name", [$sel_theme_id, $user_id])->fetchAll();
}

$titles = [];
if ($sel_subtheme_id > 0) {
    $titles = q("SELECT id,title FROM quiz_titles WHERE subtheme_id=? AND owner_user_id = ? ORDER BY title", [$sel_subtheme_id, $user_id])->fetchAll();
}

require 'views/teacher_crud.php';
