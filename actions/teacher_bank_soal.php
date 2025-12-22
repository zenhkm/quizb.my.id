<?php
// actions/teacher_bank_soal.php
// Backend untuk Bank Soal Pengajar dengan ownership validation

if (!is_pengajar() && !is_admin()) {
    header("Location: ?page=home");
    exit;
}

$user_id = uid();
$act = $_POST['act'] ?? '';

// =====================================================================
// TEMA & SUBTEMA (MILIK PENGAJAR)
// =====================================================================

if ($act === 'add_theme') {
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    if ($name !== '') {
        $max = q("SELECT COALESCE(MAX(sort_order), 0) AS m FROM themes WHERE owner_user_id = ?", [$user_id])->fetch();
        $next = (int)($max['m'] ?? 0) + 10;
        q(
            "INSERT INTO themes (name, description, sort_order, owner_user_id) VALUES (?,?,?,?)",
            [$name, ($desc !== '' ? $desc : null), $next, $user_id]
        );
        $new_id = (int)pdo()->lastInsertId();
        header("Location: ?page=teacher_bank_soal&theme_id=$new_id&success=1&msg=" . urlencode('Tema berhasil ditambahkan'));
        exit;
    }
    header('Location: ?page=teacher_bank_soal');
    exit;
}

if ($act === 'edit_theme') {
    $id = (int)($_POST['theme_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if ($id > 0 && $name !== '') {
        $is_owner = q("SELECT id FROM themes WHERE id=? AND owner_user_id=?", [$id, $user_id])->fetch();
        if ($is_owner) {
            q("UPDATE themes SET name=? WHERE id=? AND owner_user_id=?", [$name, $id, $user_id]);
            header("Location: ?page=teacher_bank_soal&theme_id=$id&success=1&msg=" . urlencode('Tema berhasil diupdate'));
            exit;
        }
    }
    header('Location: ?page=teacher_bank_soal');
    exit;
}

if ($act === 'delete_theme') {
    $id = (int)($_POST['theme_id'] ?? 0);
    if ($id > 0) {
        $is_owner = q("SELECT id FROM themes WHERE id=? AND owner_user_id=?", [$id, $user_id])->fetch();
        if ($is_owner) {
            // Cascade delete: subthemes -> titles -> questions -> choices
            $sub_ids = q("SELECT id FROM subthemes WHERE theme_id=? AND owner_user_id=?", [$id, $user_id])->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($sub_ids)) {
                $sub_placeholders = implode(',', array_fill(0, count($sub_ids), '?'));
                $title_ids = q(
                    "SELECT id FROM quiz_titles WHERE subtheme_id IN ($sub_placeholders) AND owner_user_id=?",
                    array_merge($sub_ids, [$user_id])
                )->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($title_ids)) {
                    $title_placeholders = implode(',', array_fill(0, count($title_ids), '?'));
                    $q_ids = q(
                        "SELECT id FROM questions WHERE title_id IN ($title_placeholders) AND owner_user_id=?",
                        array_merge($title_ids, [$user_id])
                    )->fetchAll(PDO::FETCH_COLUMN);
                    if (!empty($q_ids)) {
                        $q_placeholders = implode(',', array_fill(0, count($q_ids), '?'));
                        q("DELETE FROM choices WHERE question_id IN ($q_placeholders)", $q_ids);
                        q(
                            "DELETE FROM questions WHERE id IN ($q_placeholders) AND owner_user_id=?",
                            array_merge($q_ids, [$user_id])
                        );
                    }
                    q(
                        "DELETE FROM quiz_titles WHERE id IN ($title_placeholders) AND owner_user_id=?",
                        array_merge($title_ids, [$user_id])
                    );
                }
                q(
                    "DELETE FROM subthemes WHERE id IN ($sub_placeholders) AND owner_user_id=?",
                    array_merge($sub_ids, [$user_id])
                );
            }

            q("DELETE FROM themes WHERE id=? AND owner_user_id=?", [$id, $user_id]);
            header("Location: ?page=teacher_bank_soal&success=1&msg=" . urlencode('Tema berhasil dihapus'));
            exit;
        }
    }
    header('Location: ?page=teacher_bank_soal');
    exit;
}

if ($act === 'add_subtheme') {
    $theme_id = (int)($_POST['theme_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if ($theme_id > 0 && $name !== '') {
        $is_owner = q("SELECT id FROM themes WHERE id=? AND owner_user_id=?", [$theme_id, $user_id])->fetch();
        if ($is_owner) {
            q("INSERT INTO subthemes (theme_id, name, owner_user_id) VALUES (?,?,?)", [$theme_id, $name, $user_id]);
            $new_id = (int)pdo()->lastInsertId();
            header("Location: ?page=teacher_bank_soal&theme_id=$theme_id&subtheme_id=$new_id&success=1&msg=" . urlencode('Subtema berhasil ditambahkan'));
            exit;
        }
    }
    header('Location: ?page=teacher_bank_soal');
    exit;
}

if ($act === 'edit_subtheme') {
    $id = (int)($_POST['subtheme_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if ($id > 0 && $name !== '') {
        $row = q("SELECT theme_id FROM subthemes WHERE id=? AND owner_user_id=?", [$id, $user_id])->fetch();
        if ($row) {
            q("UPDATE subthemes SET name=? WHERE id=? AND owner_user_id=?", [$name, $id, $user_id]);
            $theme_id = (int)$row['theme_id'];
            header("Location: ?page=teacher_bank_soal&theme_id=$theme_id&subtheme_id=$id&success=1&msg=" . urlencode('Subtema berhasil diupdate'));
            exit;
        }
    }
    header('Location: ?page=teacher_bank_soal');
    exit;
}

if ($act === 'delete_subtheme') {
    $id = (int)($_POST['subtheme_id'] ?? 0);
    if ($id > 0) {
        $row = q("SELECT theme_id FROM subthemes WHERE id=? AND owner_user_id=?", [$id, $user_id])->fetch();
        if ($row) {
            $theme_id = (int)$row['theme_id'];

            // Cascade delete: titles -> questions -> choices
            $title_ids = q("SELECT id FROM quiz_titles WHERE subtheme_id=? AND owner_user_id=?", [$id, $user_id])->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($title_ids)) {
                $title_placeholders = implode(',', array_fill(0, count($title_ids), '?'));
                $q_ids = q(
                    "SELECT id FROM questions WHERE title_id IN ($title_placeholders) AND owner_user_id=?",
                    array_merge($title_ids, [$user_id])
                )->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($q_ids)) {
                    $q_placeholders = implode(',', array_fill(0, count($q_ids), '?'));
                    q("DELETE FROM choices WHERE question_id IN ($q_placeholders)", $q_ids);
                    q(
                        "DELETE FROM questions WHERE id IN ($q_placeholders) AND owner_user_id=?",
                        array_merge($q_ids, [$user_id])
                    );
                }
                q(
                    "DELETE FROM quiz_titles WHERE id IN ($title_placeholders) AND owner_user_id=?",
                    array_merge($title_ids, [$user_id])
                );
            }

            q("DELETE FROM subthemes WHERE id=? AND owner_user_id=?", [$id, $user_id]);
            header("Location: ?page=teacher_bank_soal&theme_id=$theme_id&success=1&msg=" . urlencode('Subtema berhasil dihapus'));
            exit;
        }
    }
    header('Location: ?page=teacher_bank_soal');
    exit;
}

// ADD/EDIT TITLE (MILIK PENGAJAR)
if ($act == 'add_title') {
    $subtheme_id = (int)$_POST['subtheme_id'];
    $title = trim($_POST['title']);
    $theme_id = (int)$_POST['theme_id'];
    
    if ($title && $subtheme_id) {
        q("INSERT INTO quiz_titles (subtheme_id, title, owner_user_id) VALUES (?, ?, ?)", 
          [$subtheme_id, $title, $user_id]);

                // Notifikasi broadcast: kuis baru
                $new_id = (int)pdo()->lastInsertId();
                $subtheme_info = q("SELECT name FROM subthemes WHERE id = ? AND owner_user_id = ?", [$subtheme_id, $user_id])->fetch();
                $subtheme_name = $subtheme_info['name'] ?? '';
                $notif_message = "Kuis baru di subtema \"" . h($subtheme_name) . "\": \"" . h($title) . "\"";
                $notif_link = "?page=play&title_id=" . $new_id;
                q(
                    "INSERT INTO broadcast_notifications (type, message, link, related_id) VALUES ('new_quiz', ?, ?, ?)",
                    [$notif_message, $notif_link, $new_id]
                );

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
                $question_id = (int)pdo()->lastInsertId();
        
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
                                        $existing_ids[] = (int)pdo()->lastInsertId();
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
