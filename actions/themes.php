<?php
// actions/themes.php

// Get allowed teacher IDs based on role and class
$allowed_teacher_ids = get_allowed_teacher_ids_for_content();

// Build owner_user_id filter
$owner_filter = "";
$owner_params = [];
if (empty($allowed_teacher_ids)) {
    // Hanya global: owner_user_id IS NULL
    $owner_filter = "AND (t.owner_user_id IS NULL AND (s.owner_user_id IS NULL OR s.id IS NULL) AND (qt.owner_user_id IS NULL OR qt.id IS NULL))";
    $owner_params = [];
} else {
    // Global + pengajar yang diizinkan
    $placeholders = implode(',', array_fill(0, count($allowed_teacher_ids), '?'));
    $owner_filter = "AND ((t.owner_user_id IS NULL OR t.owner_user_id IN ($placeholders)) 
                       AND (s.owner_user_id IS NULL OR s.id IS NULL OR s.owner_user_id IN ($placeholders)) 
                       AND (qt.owner_user_id IS NULL OR qt.id IS NULL OR qt.owner_user_id IN ($placeholders)))";
    // Kita perlu mengulang parameter sebanyak 3 kali karena ada 3 kondisi IN (...)
    $owner_params = array_merge($allowed_teacher_ids, $allowed_teacher_ids, $allowed_teacher_ids);
}

// =================================================================
// BAGIAN 1: LOGIKA PENCARIAN (SAMA SEPERTI DI HALAMAN HOME)
// =================================================================
$searchable_items_sql = "
      SELECT 
          t.name as theme_name,
          s.id as subtheme_id, s.name as subtheme_name,
          qt.id as title_id, qt.title as title_name
      FROM themes t
      LEFT JOIN subthemes s ON t.id = s.theme_id
      LEFT JOIN quiz_titles qt ON s.id = qt.subtheme_id
    WHERE t.deleted_at IS NULL
    AND (s.deleted_at IS NULL OR s.id IS NULL)
    AND (qt.deleted_at IS NULL OR qt.id IS NULL)
    $owner_filter
      ORDER BY t.sort_order, t.name, s.name, qt.title
  ";

// Fetch dan process hasil
$flat_data = q($searchable_items_sql, $owner_params)->fetchAll();

$searchable_list = [];
foreach ($flat_data as $item) {
  if ($item['subtheme_id']) {
    $searchable_list[] = [
      'type' => 'subtheme',
      'id' => $item['subtheme_id'],
      'name' => $item['subtheme_name'],
      'context' => $item['theme_name'],
      'url' => '?page=titles&subtheme_id=' . $item['subtheme_id'],
      'searchText' => strtolower($item['theme_name'] . ' ' . $item['subtheme_name'])
    ];
  }
  if ($item['title_id']) {
    $searchable_list[] = [
      'type' => 'title',
      'id' => $item['title_id'],
      'name' => $item['title_name'],
      'context' => $item['theme_name'] . ' › ' . $item['subtheme_name'],
      'url' => '?page=play&title_id=' . $item['title_id'],
      'searchText' => strtolower($item['theme_name'] . ' ' . $item['subtheme_name'] . ' ' . $item['title_name'])
    ];
  }
}
// Hapus duplikat subtema
$searchable_list = array_values(array_unique($searchable_list, SORT_REGULAR));


// =================================================================
// BAGIAN 2: QUERY DEFAULT LIST (POPULAR TITLES)
// =================================================================
// Query untuk mengambil semua judul soal diurutkan berdasarkan popularitas
// Perlu parameter owner_params lagi, tapi hati-hati dengan urutannya jika query berbeda.
// Di sini querynya mirip strukturnya (t, st, qt), jadi kita bisa pakai logic yang sama.

// Namun, query di view_themes asli menggunakan parameter yang sama ($owner_params) yang dibuat di awal.
// Mari kita cek query asli di view_themes.
/*
$all_titles_sql = "
      SELECT ...
      WHERE (t.owner_user_id IS NULL OR ...)
        AND (st.owner_user_id IS NULL OR ...)
        AND (qt.owner_user_id IS NULL OR ...)
      ...
  ";
*/
// Parameter $owner_params yang dibuat di atas berisi 3 set allowed_ids.
// Query $all_titles_sql juga memiliki 3 klausa IN (...).
// Jadi kita bisa menggunakan $owner_params yang sama.

$all_titles_sql = "
      SELECT
          qt.id,
          qt.title,
          st.name AS subtheme_name,
          t.name AS theme_name,
          COUNT(r.id) AS play_count
      FROM quiz_titles qt
      JOIN subthemes st ON qt.subtheme_id = st.id
      JOIN themes t ON st.theme_id = t.id
      LEFT JOIN results r ON qt.id = r.title_id
      /* KRITIS: Filter Judul, Subtema, dan Tema sesuai owner_user_id */
      WHERE t.deleted_at IS NULL AND st.deleted_at IS NULL AND qt.deleted_at IS NULL
        AND (t.owner_user_id IS NULL OR " . (count($allowed_teacher_ids) > 0 ? "t.owner_user_id IN (" . implode(',', array_fill(0, count($allowed_teacher_ids), '?')) . ")" : "FALSE") . ")
        AND (st.owner_user_id IS NULL OR " . (count($allowed_teacher_ids) > 0 ? "st.owner_user_id IN (" . implode(',', array_fill(0, count($allowed_teacher_ids), '?')) . ")" : "FALSE") . ")
        AND (qt.owner_user_id IS NULL OR " . (count($allowed_teacher_ids) > 0 ? "qt.owner_user_id IN (" . implode(',', array_fill(0, count($allowed_teacher_ids), '?')) . ")" : "FALSE") . ")
      GROUP BY qt.id, qt.title, st.name, t.name
      ORDER BY play_count DESC, t.name ASC, st.name ASC, qt.title ASC
  ";

// Kita perlu membuat ulang params untuk query ini karena logic pembentukan string SQL-nya sedikit berbeda (inline implode vs prepared statement placeholders di awal).
// Tapi tunggu, di kode asli:
// $all_titles = q($all_titles_sql, $owner_params)->fetchAll();
// Jadi $owner_params yang sama digunakan.
// Mari kita pastikan $owner_params cocok dengan jumlah placeholder.
// $owner_params = [id1, id2, ..., id1, id2, ..., id1, id2, ...] (3 kali)
// Query di atas punya 3 klausa IN (...).
// Jadi cocok.

$all_titles = q($all_titles_sql, $owner_params)->fetchAll();

require 'views/themes.php';
