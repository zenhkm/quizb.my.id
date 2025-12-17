<?php
if (!is_pengajar() && !is_admin()) {
    echo '<div class="alert alert-danger">Akses admin/pengajar diperlukan.</div>';
    return;
}

$user_id = uid();
$act = $_POST['act'] ?? $_GET['act'] ?? '';

// Fungsi helper untuk redirect ke QManage yang benar
$redirect_qmanage = function($title_id, $ok = 1) {
    redirect('?page=teacher_qmanage&title_id=' . $title_id . '&ok=' . $ok);
};

// Fungsi untuk memverifikasi kepemilikan soal
$get_title_id_and_verify = function($qid) use ($user_id) {
    $q_info = q("SELECT title_id FROM questions WHERE id=? AND owner_user_id=?", [$qid, $user_id])->fetch();
    if (!$q_info) return 0;
    return (int)$q_info['title_id'];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- Tambah Soal Baru (dengan kepemilikan) ---
    if ($act === 'add_question_dyn_pengajar') {
        $title_id = (int)$_POST['title_id'];
        $text = trim($_POST['text'] ?? '');
        $exp = trim($_POST['explanation'] ?? '');
        $choices = $_POST['choice_text'] ?? [];
        $correct_index = (int)($_POST['correct_index'] ?? 1);

        $is_owner = q("SELECT id FROM quiz_titles WHERE id = ? AND owner_user_id = ?", [$title_id, $user_id])->fetch();
        if (!$is_owner) redirect('?page=teacher_qmanage');
        
        q("INSERT INTO questions (title_id, owner_user_id, text, explanation, created_at) VALUES (?,?,?,?,?)", [$title_id, $user_id, $text, ($exp ?: null), now()]);
        $qid = pdo()->lastInsertId();

        $choices = array_values(array_filter(array_map('trim', $choices), fn($x) => $x !== ''));
        $n = count($choices);
        if ($n < 2 || $n > 5) die('Jumlah pilihan harus 2–5.');
        
        for ($i = 0; $i < $n; $i++) {
            $is = (int)(($i + 1) === $correct_index);
            q("INSERT INTO choices (question_id,text,is_correct) VALUES (?,?,?)", [$qid, $choices[$i], $is]);
        }
        
        $redirect_qmanage($title_id);
    }

    // --- Update Soal (dengan verifikasi kepemilikan) ---
    if ($act === 'update_question_dyn_pengajar') {
        $qid = (int)($_POST['question_id'] ?? 0);
        $title_id = $get_title_id_and_verify($qid);
        if ($title_id === 0) redirect('?page=teacher_qmanage');

        $text = trim($_POST['text'] ?? '');
        $exp = trim($_POST['explanation'] ?? '');

        // 1. Update Soal
        q("UPDATE questions SET text=?, explanation=?, updated_at=? WHERE id=? AND owner_user_id=?", [$text, ($exp ?: null), now(), $qid, $user_id]);
        
        // 2. Update Choices (Logika sinkronisasi)
        $cidArr = array_map('intval', $_POST['cid'] ?? []);
        $ctextArr = array_map('trim', $_POST['ctext'] ?? []);
        $correct_index = max(1, min(5, (int)($_POST['correct_index'] ?? 1)));
        
        $pairs = []; for ($i = 0; $i < count($ctextArr); $i++) { if ($ctextArr[$i] !== '') $pairs[] = ['id' => $cidArr[$i] ?? 0, 'text' => $ctextArr[$i]]; }
        $n = count($pairs); if ($n < 2 || $n > 5) die('Jumlah pilihan harus 2–5.');
        $oldIds = q("SELECT id FROM choices WHERE question_id=? ORDER BY id", [$qid])->fetchAll(PDO::FETCH_COLUMN);
        $newIds = [];
        foreach ($pairs as $p) {
            if ($p['id'] > 0) { q("UPDATE choices SET text=? WHERE id=?", [$p['text'], $p['id']]); $newIds[] = (int)$p['id']; } 
            else { q("INSERT INTO choices (question_id,text,is_correct) VALUES (?,?,0)", [$qid, $p['text']]); $newIds[] = (int)pdo()->lastInsertId(); }
        }
        foreach ($oldIds as $oid) { if (!in_array($oid, $newIds, true)) q("DELETE FROM choices WHERE id=?", [$oid]); }
        $correct_choice_id = $newIds[$correct_index - 1];
        q("UPDATE choices SET is_correct = CASE WHEN id=? THEN 1 ELSE 0 END WHERE question_id=?", [$correct_choice_id, $qid]);

        $redirect_qmanage($title_id);
    }

    // --- Hapus Soal (dengan verifikasi kepemilikan) ---
    if ($act === 'del_question_pengajar') {
        $qid = (int)$_POST['question_id'];
        $title_id = $get_title_id_and_verify($qid);
        if ($title_id === 0) redirect('?page=teacher_qmanage');
        
        q("DELETE FROM questions WHERE id=? AND owner_user_id=?", [$qid, $user_id]);
        
        $redirect_qmanage($title_id);
    }

    // --- Duplikat Soal (dengan kepemilikan) ---
    if ($act === 'duplicate_question_pengajar') {
        $original_qid = (int)($_POST['question_id'] ?? 0);
        $title_id = $get_title_id_and_verify($original_qid);
        if ($title_id === 0) redirect('?page=teacher_qmanage');
        
        $original_q = q("SELECT * FROM questions WHERE id=?", [$original_qid])->fetch();
        
        $new_text = "[DUPLIKAT] " . $original_q['text'];
        q("INSERT INTO questions (title_id, owner_user_id, text, explanation, attempts, corrects, created_at) VALUES (?, ?, ?, ?, 0, 0, ?)",
          [$title_id, $user_id, $new_text, $original_q['explanation'], now()]
        );
        $new_qid = pdo()->lastInsertId();

        $original_choices = q("SELECT * FROM choices WHERE question_id=?", [$original_qid])->fetchAll();
        if ($original_choices) {
          foreach ($original_choices as $choice) {
            q("INSERT INTO choices (question_id, text, is_correct) VALUES (?, ?, ?)",
              [$new_qid, $choice['text'], $choice['is_correct']]
            );
          }
        }
        
        $redirect_qmanage($title_id);
    }
    
    // --- Import Master Question ---
    if ($act === 'import_master_question') {
        $master_title_id = (int)($_POST['master_title_id'] ?? 0);
        $target_title_id = (int)($_POST['target_title_id'] ?? 0);
        $num_questions = (int)($_POST['num_questions'] ?? 0);
        
        $is_target_owner = q("SELECT id FROM quiz_titles WHERE id = ? AND owner_user_id = ?", [$target_title_id, $user_id])->fetch();
        if (!$is_target_owner) redirect('?page=teacher_crud&err=not_owner');

        $is_master = q("SELECT id FROM quiz_titles WHERE id = ? AND is_master = 1", [$master_title_id])->fetch();
        if (!$is_master) redirect('?page=teacher_crud&err=not_master');

        $limit_sql = $num_questions > 0 ? "ORDER BY RAND() LIMIT $num_questions" : "";
        $master_questions = q("SELECT * FROM questions WHERE title_id = ? {$limit_sql}", [$master_title_id])->fetchAll();

        if ($master_questions) {
            foreach ($master_questions as $q) {
                // A. Duplikasi Pertanyaan (Tambahkan owner_user_id = $user_id)
                q("INSERT INTO questions (title_id, owner_user_id, text, explanation, created_at) VALUES (?, ?, ?, ?, ?)",
                  [$target_title_id, $user_id, $q['text'], $q['explanation'], now()]);
                $new_qid = pdo()->lastInsertId();

                // B. Duplikasi Pilihan Jawaban
                $master_choices = q("SELECT * FROM choices WHERE question_id = ?", [$q['id']])->fetchAll();
                foreach ($master_choices as $c) {
                    q("INSERT INTO choices (question_id, text, is_correct) VALUES (?, ?, ?)",
                      [$new_qid, $c['text'], $c['is_correct']]);
                }
            }
        }
        
        redirect('?page=teacher_qmanage&title_id=' . $target_title_id . '&imported=1');
    }
}

// --- Data Fetching for View ---

$title_id = (int)($_GET['title_id'] ?? 0);
$edit_id = (int)($_GET['edit'] ?? 0);

// Periksa apakah ID Judul telah dipilih
if (!$title_id) {
    // View will handle this check
} else {
    // --- 1. FILTER KEPEMILIKAN JUDUL ---
    $title_info = q("
      SELECT qt.id, qt.title, qt.subtheme_id, st.name AS subtheme_name, st.theme_id, t.name AS theme_name
      FROM quiz_titles qt
      JOIN subthemes st ON st.id = qt.subtheme_id
      JOIN themes t ON t.id = st.theme_id
      WHERE qt.id = ? AND qt.owner_user_id = ?
    ", [$title_id, $user_id])->fetch();
}

// --- 3. FORM EDIT (Jika ada ?edit=ID) ---
$qrow = null;
$choices = [];
if ($edit_id && $title_id) {
    // Query Edit: Filter dengan owner_user_id
    $qrow = q("SELECT * FROM questions WHERE id=? AND title_id=? AND owner_user_id=?", [$edit_id, $title_id, $user_id])->fetch();
    if ($qrow) {
        $choices = q("SELECT * FROM choices WHERE question_id=? ORDER BY id", [$edit_id])->fetchAll();
        if (count($choices) < 2) {
            for ($i = count($choices); $i < 2; $i++) $choices[] = ['id' => 0, 'text' => '', 'is_correct' => 0];
        }
    }
}

// --- List Questions for Display (Not in original view_qmanage_pengajar?) ---
// Wait, view_qmanage_pengajar DOES NOT list questions?
// Let's check view_qmanage_pengajar again.
// It seems it only shows Edit Form OR Add Form.
// It does NOT show the list of existing questions!
// That's strange. Maybe it relies on another component or I missed it.
// Ah, I see `view_qmanage` (admin) has a list.
// `view_qmanage_pengajar` seems to be missing the list of questions.
// Let me check the file content again.

require 'views/teacher_qmanage.php';
