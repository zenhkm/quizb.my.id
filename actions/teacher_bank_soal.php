<?php
// actions/teacher_bank_soal.php
// Backend untuk Bank Soal Pengajar dengan ownership validation

if (!is_pengajar() && !is_admin()) {
    header("Location: ?page=home");
    exit;
}

$user_id = uid();
$act = $_POST['act'] ?? '';

// ADD/EDIT TITLE (MILIK PENGAJAR)
if ($act == 'add_title') {
    $subtheme_id = (int)$_POST['subtheme_id'];
    $title = trim($_POST['title']);
    $theme_id = (int)$_POST['theme_id'];
    
    if ($title && $subtheme_id) {
        q("INSERT INTO quiz_titles (subtheme_id, title, owner_user_id) VALUES (?, ?, ?)", 
          [$subtheme_id, $title, $user_id]);
        header("Location: ?page=teacher_bank_soal&theme_id=$theme_id&subtheme_id=$subtheme_id&success=1&msg=Judul berhasil ditambahkan");
    } else {
        header("Location: ?page=teacher_bank_soal&theme_id=$theme_id&subtheme_id=$subtheme_id");
    }
    exit;
}

if ($act == 'edit_title') {
    $title_id = (int)$_POST['title_id'];
    $title = trim($_POST['title']);
    $theme_id = (int)$_POST['theme_id'];
    $subtheme_id = (int)$_POST['subtheme_id'];
    
    // Verify ownership
    $check = q("SELECT id FROM quiz_titles WHERE id = ? AND owner_user_id = ?", [$title_id, $user_id])->fetch();
    if ($check && $title) {
        q("UPDATE quiz_titles SET title = ? WHERE id = ?", [$title, $title_id]);
        header("Location: ?page=teacher_bank_soal&theme_id=$theme_id&subtheme_id=$subtheme_id&title_id=$title_id&success=1&msg=Judul berhasil diupdate");
    } else {
        header("Location: ?page=teacher_bank_soal&theme_id=$theme_id&subtheme_id=$subtheme_id");
    }
    exit;
}

if ($act == 'delete_title') {
    $title_id = (int)$_POST['title_id'];
    $theme_id = (int)$_POST['theme_id'];
    $subtheme_id = (int)$_POST['subtheme_id'];
    
    // Verify ownership
    $check = q("SELECT id FROM quiz_titles WHERE id = ? AND owner_user_id = ?", [$title_id, $user_id])->fetch();
    if ($check) {
        // Delete questions first (cascade)
        $questions = q("SELECT id FROM questions WHERE title_id = ? AND owner_user_id = ?", [$title_id, $user_id])->fetchAll();
        foreach ($questions as $q) {
            q("DELETE FROM choices WHERE question_id = ?", [$q['id']]);
        }
        q("DELETE FROM questions WHERE title_id = ? AND owner_user_id = ?", [$title_id, $user_id]);
        q("DELETE FROM quiz_titles WHERE id = ? AND owner_user_id = ?", [$title_id, $user_id]);
        
        header("Location: ?page=teacher_bank_soal&theme_id=$theme_id&subtheme_id=$subtheme_id&success=1&msg=Judul berhasil dihapus");
    } else {
        header("Location: ?page=teacher_bank_soal&theme_id=$theme_id&subtheme_id=$subtheme_id");
    }
    exit;
}

// ADD QUESTION (MILIK PENGAJAR)
if ($act == 'add_question') {
    $title_id = (int)$_POST['title_id'];
    $text = trim($_POST['text']);
    $explanation = trim($_POST['explanation'] ?? '');
    $choice_texts = $_POST['choice_text'] ?? [];
    $correct_index = (int)($_POST['correct_index'] ?? 1);
    $return_url = $_POST['return_url'] ?? "?page=teacher_bank_soal";
    
    // Verify title ownership
    $check = q("SELECT id FROM quiz_titles WHERE id = ? AND owner_user_id = ?", [$title_id, $user_id])->fetch();
    if (!$check) {
        header("Location: $return_url");
        exit;
    }
    
    if ($text && $title_id && count($choice_texts) >= 2) {
        // Insert question
        q("INSERT INTO questions (title_id, text, explanation, owner_user_id) VALUES (?, ?, ?, ?)", 
          [$title_id, $text, $explanation, $user_id]);
        $question_id = lastInsertId();
        
        // Insert choices
        foreach ($choice_texts as $idx => $choice_text) {
            $choice_text = trim($choice_text);
            if ($choice_text) {
                $is_correct = (($idx + 1) == $correct_index) ? 1 : 0;
                q("INSERT INTO choices (question_id, text, is_correct) VALUES (?, ?, ?)", 
                  [$question_id, $choice_text, $is_correct]);
            }
        }
        
        header("Location: $return_url&success=1&msg=Soal berhasil ditambahkan");
    } else {
        header("Location: $return_url");
    }
    exit;
}

// UPDATE QUESTION (MILIK PENGAJAR)
if ($act == 'update_question') {
    $question_id = (int)$_POST['question_id'];
    $title_id = (int)$_POST['title_id'];
    $text = trim($_POST['text']);
    $explanation = trim($_POST['explanation'] ?? '');
    $choice_ids = $_POST['cid'] ?? [];
    $choice_texts = $_POST['ctext'] ?? [];
    $correct_index = (int)($_POST['correct_index'] ?? 1);
    $return_url = $_POST['return_url'] ?? "?page=teacher_bank_soal";
    
    // Verify ownership
    $check = q("SELECT id FROM questions WHERE id = ? AND owner_user_id = ?", [$question_id, $user_id])->fetch();
    if (!$check) {
        header("Location: $return_url");
        exit;
    }
    
    if ($text && $question_id) {
        // Update question
        q("UPDATE questions SET text = ?, explanation = ? WHERE id = ?", 
          [$text, $explanation, $question_id]);
        
        // Sync choices
        $existing_ids = [];
        foreach ($choice_ids as $idx => $cid) {
            $cid = (int)$cid;
            $ctext = trim($choice_texts[$idx] ?? '');
            if ($ctext) {
                $is_correct = (($idx + 1) == $correct_index) ? 1 : 0;
                if ($cid > 0) {
                    // Update existing choice
                    q("UPDATE choices SET text = ?, is_correct = ? WHERE id = ? AND question_id = ?", 
                      [$ctext, $is_correct, $cid, $question_id]);
                    $existing_ids[] = $cid;
                } else {
                    // Insert new choice
                    q("INSERT INTO choices (question_id, text, is_correct) VALUES (?, ?, ?)", 
                      [$question_id, $ctext, $is_correct]);
                    $existing_ids[] = lastInsertId();
                }
            }
        }
        
        // Delete removed choices
        if (!empty($existing_ids)) {
            $placeholders = implode(',', array_fill(0, count($existing_ids), '?'));
            q("DELETE FROM choices WHERE question_id = ? AND id NOT IN ($placeholders)", 
              array_merge([$question_id], $existing_ids));
        } else {
            q("DELETE FROM choices WHERE question_id = ?", [$question_id]);
        }
        
        header("Location: $return_url&success=1&msg=Soal berhasil diupdate");
    } else {
        header("Location: $return_url");
    }
    exit;
}

// DELETE QUESTION (MILIK PENGAJAR)
if ($act == 'delete_question') {
    $question_id = (int)$_POST['question_id'];
    $return_url = $_POST['return_url'] ?? "?page=teacher_bank_soal";
    
    // Verify ownership
    $check = q("SELECT id FROM questions WHERE id = ? AND owner_user_id = ?", [$question_id, $user_id])->fetch();
    if ($check) {
        q("DELETE FROM choices WHERE question_id = ?", [$question_id]);
        q("DELETE FROM questions WHERE id = ?", [$question_id]);
        header("Location: $return_url&success=1&msg=Soal berhasil dihapus");
    } else {
        header("Location: $return_url");
    }
    exit;
}

// Jika tidak ada action yang cocok
header("Location: ?page=teacher_bank_soal");
exit;
