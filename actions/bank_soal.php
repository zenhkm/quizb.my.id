<?php
// actions/bank_soal.php
// Handler untuk Bank Soal Terintegrasi

if (!is_admin()) {
    echo '<div class="alert alert-danger">Akses admin diperlukan.</div>';
    return;
}

$act = $_POST['act'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ========== TEMA ==========
    if ($act === 'add_theme') {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if ($name !== '') {
            $max = q("SELECT COALESCE(MAX(sort_order), 0) AS m FROM themes")->fetch();
            $next = (int)$max['m'] + 10;
            q("INSERT INTO themes (name, description, sort_order) VALUES (?,?,?)", [$name, $desc, $next]);
            $new_id = (int)pdo()->lastInsertId();
            redirect('?page=bank_soal&theme_id=' . $new_id . '&success=1&msg=' . urlencode('Tema berhasil ditambahkan'));
        }
        redirect('?page=bank_soal');
    }
    
    if ($act === 'edit_theme') {
        $id = (int)($_POST['theme_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($id > 0 && $name !== '') {
            q("UPDATE themes SET name=? WHERE id=?", [$name, $id]);
            redirect('?page=bank_soal&theme_id=' . $id . '&success=1&msg=' . urlencode('Tema berhasil diupdate'));
        }
        redirect('?page=bank_soal');
    }
    
    if ($act === 'delete_theme') {
        $id = (int)($_POST['theme_id'] ?? 0);
        if ($id > 0) {
            q("DELETE FROM themes WHERE id=?", [$id]);
            redirect('?page=bank_soal&success=1&msg=' . urlencode('Tema berhasil dihapus'));
        }
        redirect('?page=bank_soal');
    }
    
    // ========== SUBTEMA ==========
    if ($act === 'add_subtheme') {
        $theme_id = (int)($_POST['theme_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($theme_id > 0 && $name !== '') {
            q("INSERT INTO subthemes (theme_id, name) VALUES (?,?)", [$theme_id, $name]);
            $new_id = (int)pdo()->lastInsertId();
            redirect('?page=bank_soal&theme_id=' . $theme_id . '&subtheme_id=' . $new_id . '&success=1&msg=' . urlencode('Subtema berhasil ditambahkan'));
        }
        redirect('?page=bank_soal');
    }
    
    if ($act === 'edit_subtheme') {
        $id = (int)($_POST['subtheme_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($id > 0 && $name !== '') {
            $sub = q("SELECT theme_id FROM subthemes WHERE id=?", [$id])->fetch();
            q("UPDATE subthemes SET name=? WHERE id=?", [$name, $id]);
            redirect('?page=bank_soal&theme_id=' . $sub['theme_id'] . '&subtheme_id=' . $id . '&success=1&msg=' . urlencode('Subtema berhasil diupdate'));
        }
        redirect('?page=bank_soal');
    }
    
    if ($act === 'delete_subtheme') {
        $id = (int)($_POST['subtheme_id'] ?? 0);
        if ($id > 0) {
            $row = q("SELECT theme_id FROM subthemes WHERE id=?", [$id])->fetch();
            $theme_id = $row ? (int)$row['theme_id'] : 0;
            q("DELETE FROM subthemes WHERE id=?", [$id]);
            redirect('?page=bank_soal&theme_id=' . $theme_id . '&success=1&msg=' . urlencode('Subtema berhasil dihapus'));
        }
        redirect('?page=bank_soal');
    }
    
    // ========== JUDUL ==========
    if ($act === 'add_title') {
        $subtheme_id = (int)($_POST['subtheme_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        if ($subtheme_id > 0 && $title !== '') {
            q("INSERT INTO quiz_titles (subtheme_id, title) VALUES (?,?)", [$subtheme_id, $title]);
            $new_id = (int)pdo()->lastInsertId();
            
            // Get theme_id
            $sub = q("SELECT theme_id FROM subthemes WHERE id=?", [$subtheme_id])->fetch();
            
            redirect('?page=bank_soal&theme_id=' . $sub['theme_id'] . '&subtheme_id=' . $subtheme_id . '&title_id=' . $new_id . '&success=1&msg=' . urlencode('Judul berhasil ditambahkan'));
        }
        redirect('?page=bank_soal');
    }
    
    // ========== SOAL ==========
    if ($act === 'add_question') {
        $title_id = (int)($_POST['title_id'] ?? 0);
        $text = trim($_POST['text'] ?? '');
        $exp = trim($_POST['explanation'] ?? '');
        $choices = $_POST['choice_text'] ?? [];
        $correct_index = (int)($_POST['correct_index'] ?? 1);
        $return_url = $_POST['return_url'] ?? '?page=bank_soal';
        
        if ($title_id > 0 && $text !== '') {
            q("INSERT INTO questions (title_id, text, explanation, created_at) VALUES (?,?,?,?)", 
              [$title_id, $text, ($exp ?: null), now()]);
            $qid = pdo()->lastInsertId();
            
            $choices = array_values(array_filter(array_map('trim', $choices), fn($x) => $x !== ''));
            $n = count($choices);
            
            if ($n >= 2 && $n <= 5) {
                for ($i = 0; $i < $n; $i++) {
                    $is = (int)(($i + 1) === $correct_index);
                    q("INSERT INTO choices (question_id, text, is_correct) VALUES (?,?,?)", [$qid, $choices[$i], $is]);
                }
            }
            
            redirect($return_url . '&success=1&msg=' . urlencode('Soal berhasil ditambahkan'));
        }
        redirect($return_url);
    }
    
    if ($act === 'delete_question') {
        $qid = (int)($_POST['question_id'] ?? 0);
        $return_url = $_POST['return_url'] ?? '?page=bank_soal';
        if ($qid > 0) {
            q("DELETE FROM questions WHERE id=?", [$qid]);
            redirect($return_url . '&success=1&msg=' . urlencode('Soal berhasil dihapus'));
        }
        redirect($return_url);
    }
}

// Tampilkan view
require __DIR__ . '/../views/bank_soal.php';
