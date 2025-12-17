<?php
if (!is_admin()) {
    echo '<div class="alert alert-danger">Akses ditolak.</div>';
    return;
}

$act = $_POST['act'] ?? $_GET['act'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($act === 'add_theme') {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if ($name !== '') {
            $max = q("SELECT COALESCE(MAX(sort_order), 0) AS m FROM themes")->fetch();
            $next = (int)$max['m'] + 10;
            q("INSERT INTO themes (name, description, sort_order) VALUES (?,?,?)", [$name, $desc, $next]);
            $new_id = (int)pdo()->lastInsertId();
            redirect('?page=crud&theme_id=' . $new_id);
        }
        redirect('?page=crud');
    }

    if ($act === 'add_subtheme') {
        $theme_id = (int)($_POST['theme_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($theme_id > 0 && $name !== '') {
            q("INSERT INTO subthemes (theme_id, name) VALUES (?,?)", [$theme_id, $name]);
            $new_id = (int)pdo()->lastInsertId();
            redirect('?page=crud&theme_id=' . $theme_id . '&subtheme_id=' . $new_id);
        }
        redirect('?page=crud');
    }

    // ---- TITLE ADD ----
    if ($act === 'add_title') {
        $subtheme_id = (int)($_POST['subtheme_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        if ($subtheme_id > 0 && $title !== '') {
            q("INSERT INTO quiz_titles (subtheme_id, title) VALUES (?,?)", [$subtheme_id, $title]);
            $new_id = (int)pdo()->lastInsertId();

            // Ambil nama subtema untuk notifikasi yang lebih deskriptif
            $subtheme_info = q("SELECT name FROM subthemes WHERE id = ?", [$subtheme_id])->fetch();
            $subtheme_name = $subtheme_info['name'] ?? '';

            // Buat 1 notifikasi broadcast untuk semua pengguna
            $notif_message = "Kuis baru di subtema \"" . h($subtheme_name) . "\": \"" . h($title) . "\"";
            $notif_link = "?page=play&title_id=" . $new_id;

            q(
                "INSERT INTO broadcast_notifications (type, message, link, related_id) VALUES ('new_quiz', ?, ?, ?)",
                [$notif_message, $notif_link, $new_id]
            );

            redirect('?page=qmanage&title_id=' . $new_id);
        }
        redirect('?page=crud');
    }

    if ($act === 'delete_theme') {
        $id = (int)($_POST['theme_id'] ?? 0);
        if ($id > 0) {
            q("DELETE FROM themes WHERE id=?", [$id]);
        }
        redirect('?page=crud');
    }

    if ($act === 'delete_subtheme') {
        $id = (int)($_POST['subtheme_id'] ?? 0);
        if ($id > 0) {
            $row = q("SELECT theme_id FROM subthemes WHERE id=?", [$id])->fetch();
            $theme_id = $row ? (int)$row['theme_id'] : 0;
            q("DELETE FROM subthemes WHERE id=?", [$id]);
            redirect('?page=crud&theme_id=' . $theme_id);
        }
        redirect('?page=crud');
    }

    if ($act === 'delete_title') {
        $id = (int)($_POST['title_id'] ?? 0);
        if ($id > 0) {
            $row = q("SELECT subtheme_id FROM quiz_titles WHERE id=?", [$id])->fetch();
            $sid = $row ? (int)$row['subtheme_id'] : 0;
            $theme_id = 0;
            if ($sid) {
                $r2 = q("SELECT theme_id FROM subthemes WHERE id=?", [$sid])->fetch();
                $theme_id = $r2 ? (int)$r2['theme_id'] : 0;
            }
            q("DELETE FROM quiz_titles WHERE id=?", [$id]);
            redirect('?page=crud&theme_id=' . $theme_id . '&subtheme_id=' . $sid);
        }
        redirect('?page=crud');
    }

    if ($act === 'move_subtheme') {
        $subtheme_id = (int)($_POST['subtheme_id'] ?? 0);
        $target_theme_id = (int)($_POST['target_theme_id'] ?? 0);

        if ($subtheme_id > 0 && $target_theme_id > 0) {
            $ok = q("SELECT id FROM subthemes WHERE id=?", [$subtheme_id])->fetch();
            if ($ok) {
                q("UPDATE subthemes SET theme_id=? WHERE id=?", [$target_theme_id, $subtheme_id]);
            }
        }
        redirect('?page=crud&theme_id=' . $target_theme_id);
    }

    if ($act === 'move_title') {
        $title_id = (int)($_POST['title_id'] ?? 0);
        $target_subtheme_id = (int)($_POST['target_subtheme_id'] ?? 0);

        if ($title_id > 0 && $target_subtheme_id > 0) {
            $title_ok = q("SELECT id FROM quiz_titles WHERE id=?", [$title_id])->fetch();
            $subtheme_ok = q("SELECT id FROM subthemes WHERE id=?", [$target_subtheme_id])->fetch();

            if ($title_ok && $subtheme_ok) {
                q("UPDATE quiz_titles SET subtheme_id=? WHERE id=?", [$target_subtheme_id, $title_id]);
            }
        }

        $subtheme_info = q("SELECT theme_id FROM subthemes WHERE id=?", [$target_subtheme_id])->fetch();
        $target_theme_id = $subtheme_info['theme_id'] ?? 0;
        redirect('?page=crud&theme_id=' . $target_theme_id . '&subtheme_id=' . $target_subtheme_id);
    }

    if ($act === 'rename_item') {
        $id   = (int)($_POST['item_id'] ?? 0);
        $type = $_POST['item_type'] ?? '';
        $name = trim($_POST['name'] ?? '');

        if ($id > 0 && $name !== '' && in_array($type, ['theme', 'subtheme', 'title'])) {
            $table = '';
            $id_col = '';

            switch ($type) {
                case 'theme':
                    $table = 'themes';
                    $id_col = 'id';
                    break;
                case 'subtheme':
                    $table = 'subthemes';
                    $id_col = 'id';
                    break;
                case 'title':
                    $table = 'quiz_titles';
                    $id_col = 'id';
                    // Di sini kita ganti 'name' menjadi 'title' sesuai kolom di DB
                    q("UPDATE quiz_titles SET title=? WHERE id=?", [$name, $id]);
                    // Logika redirect untuk title sedikit berbeda, jadi kita handle khusus
                    $row = q("SELECT subtheme_id FROM quiz_titles WHERE id=?", [$id])->fetch();
                    $sid = $row ? (int)$row['subtheme_id'] : 0;
                    $theme_id = 0;
                    if ($sid) {
                        $r2 = q("SELECT theme_id FROM subthemes WHERE id=?", [$sid])->fetch();
                        $theme_id = $r2 ? (int)$r2['theme_id'] : 0;
                    }
                    redirect('?page=crud&theme_id=' . $theme_id . '&subtheme_id=' . $sid);
                    break; // Penting untuk keluar setelah redirect
            }

            // Jalankan query untuk theme dan subtheme
            if ($table && $id_col) {
                q("UPDATE {$table} SET name=? WHERE {$id_col}=?", [$name, $id]);
            }
        }
        // Redirect umum untuk theme dan subtheme
        redirect('?page=crud&' . $_SERVER['QUERY_STRING']);
    }
}

// --- Data Fetching for View ---

$sel_theme_id    = isset($_GET['theme_id']) ? (int)$_GET['theme_id'] : 0;
$sel_subtheme_id = isset($_GET['subtheme_id']) ? (int)$_GET['subtheme_id'] : 0;

$themes = q("SELECT id,name,description,sort_order FROM themes ORDER BY sort_order, name")->fetchAll();

$subs = [];
if ($sel_theme_id > 0) {
    $subs = q("SELECT id,name FROM subthemes WHERE theme_id=? ORDER BY name", [$sel_theme_id])->fetchAll();
}

$titles = [];
if ($sel_subtheme_id > 0) {
    $titles = q("SELECT id,title FROM quiz_titles WHERE subtheme_id=? ORDER BY title", [$sel_subtheme_id])->fetchAll();
}

// Siapkan data tema dalam format JSON untuk JavaScript (Modal Move)
$themes_js = q("SELECT id, name FROM themes ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

require 'views/crud.php';
