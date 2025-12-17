<?php
// actions/qmanage.php

if (!is_admin()) {
    echo '<div class="alert alert-warning">Akses admin diperlukan.</div>';
    return;
}

$title_id = (int)($_GET['title_id'] ?? 0);

// =================================================================
// BAGIAN 1: DATA PENCARIAN
// =================================================================

// Query untuk mengambil semua judul soal untuk data pencarian
$all_titles_for_search = q("
        SELECT qt.id, CONCAT(t.name, ' › ', st.name, ' › ', qt.title) AS label
        FROM quiz_titles qt
        JOIN subthemes st ON st.id = qt.subtheme_id
        JOIN themes t ON t.id = st.theme_id
        ORDER BY t.name, st.name, qt.title
    ")->fetchAll();

$searchable_list = [];
foreach ($all_titles_for_search as $item) {
    $searchable_list[] = [
        'id' => $item['id'],
        'name' => $item['label'],
        // PENTING: URL di sini mengarah ke halaman qmanage, bukan play
        'url' => '?page=qmanage&title_id=' . $item['id'],
        'searchText' => strtolower($item['label'])
    ];
}

// =================================================================
// BAGIAN 2: DATA KELOLA SOAL (Jika judul dipilih)
// =================================================================

$title_info = null;
$edit_id = 0;
$qrow = null;
$choices = [];
$rows = [];
$all_titles = [];

if ($title_id) {
    // 1. Ambil informasi lengkap tentang judul, subtema, dan tema
    $title_info = q("
      SELECT 
          qt.title, 
          st.name AS subtheme_name, 
          t.name AS theme_name
      FROM quiz_titles qt
      JOIN subthemes st ON st.id = qt.subtheme_id
      JOIN themes t ON t.id = st.theme_id
      WHERE qt.id = ?
  ", [$title_id])->fetch();

    if ($title_info) {
        // MODE: EDIT?
        $edit_id = (int)($_GET['edit'] ?? 0);

        // Dropdown semua judul (untuk fitur Pindah Soal)
        $all_titles = q("
          SELECT qt.id, CONCAT(t.name,' › ',st.name,' › ',qt.title) AS label
          FROM quiz_titles qt
          JOIN subthemes st ON st.id = qt.subtheme_id
          JOIN themes t     ON t.id = st.theme_id
          ORDER BY t.name, st.name, qt.title
        ")->fetchAll();

        // ----- DATA EDIT (jika ada ?edit=ID)
        if ($edit_id) {
            $qrow = q("SELECT * FROM questions WHERE id=? AND title_id=?", [$edit_id, $title_id])->fetch();
            if ($qrow) {
                $choices = q("SELECT * FROM choices WHERE question_id=? ORDER BY id", [$edit_id])->fetchAll();
                // Batasi/bijaki: jika kosong, siapkan 2 baris
                if (count($choices) < 2) {
                    for ($i = count($choices); $i < 2; $i++) $choices[] = ['id' => 0, 'text' => '', 'is_correct' => 0];
                }
            }
        }

        // ----- LIST SOAL
        $rows = q("SELECT * FROM questions WHERE title_id=? ORDER BY id DESC", [$title_id])->fetchAll();
    }
}

require 'views/qmanage.php';
