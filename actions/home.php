<?php
// Data untuk Sidebar Kanan (Peserta, Skor, dll.)
$recent = q("SELECT r.created_at, r.score, r.user_id, COALESCE(u.name, CONCAT('Tamu – ', COALESCE(r.city,'Anonim'))) AS display_name, u.avatar, qt.title AS quiz_title 
               FROM results r 
               LEFT JOIN users u ON u.id = r.user_id 
               LEFT JOIN quiz_titles qt ON qt.id = r.title_id 
               WHERE (u.id IS NULL OR u.role != 'admin') 
               ORDER BY r.created_at DESC LIMIT 5")
  ->fetchAll();
$online_minutes = 5;
$online_count = count_online_sessions($online_minutes);
$is_visitor_logged_in = uid();
$tops = get_top_scores(5);

// Get allowed teacher IDs berdasarkan role
$allowed_teacher_ids = get_allowed_teacher_ids_for_content();

// Query latest quizzes dengan filter owner_user_id
if (empty($allowed_teacher_ids)) {
    $latest = q("SELECT qt.id,qt.title,st.name subn FROM quiz_titles qt 
                 JOIN subthemes st ON st.id=qt.subtheme_id 
                 WHERE qt.owner_user_id IS NULL 
                 ORDER BY qt.created_at DESC LIMIT 5")->fetchAll();
} else {
    $placeholders = implode(',', array_fill(0, count($allowed_teacher_ids), '?'));
    $latest = q("SELECT qt.id,qt.title,st.name subn FROM quiz_titles qt 
                 JOIN subthemes st ON st.id=qt.subtheme_id 
                 WHERE qt.owner_user_id IS NULL OR qt.owner_user_id IN ($placeholders)
                 ORDER BY qt.created_at DESC LIMIT 5",
                $allowed_teacher_ids)->fetchAll();
}

$most_played = get_most_played_titles(5);

// Data untuk Fitur Pencarian dengan filter owner_user_id
if (empty($allowed_teacher_ids)) {
    $searchable_items_sql = "
          SELECT 
              t.name as theme_name,
              s.id as subtheme_id, s.name as subtheme_name,
              qt.id as title_id, qt.title as title_name
          FROM themes t
          LEFT JOIN subthemes s ON t.id = s.theme_id
          LEFT JOIN quiz_titles qt ON s.id = qt.subtheme_id
          WHERE t.owner_user_id IS NULL
            AND (s.owner_user_id IS NULL OR s.id IS NULL)
            AND (qt.owner_user_id IS NULL OR qt.id IS NULL)
          ORDER BY t.sort_order, t.name, s.name, qt.title
      ";
    $flat_data = q($searchable_items_sql)->fetchAll();
} else {
    $placeholders = implode(',', array_fill(0, count($allowed_teacher_ids), '?'));
    $searchable_items_sql = "
          SELECT 
              t.name as theme_name,
              s.id as subtheme_id, s.name as subtheme_name,
              qt.id as title_id, qt.title as title_name
          FROM themes t
          LEFT JOIN subthemes s ON t.id = s.theme_id
          LEFT JOIN quiz_titles qt ON s.id = qt.subtheme_id
          WHERE (t.owner_user_id IS NULL OR t.owner_user_id IN ($placeholders))
            AND (s.owner_user_id IS NULL OR s.id IS NULL OR s.owner_user_id IN ($placeholders))
            AND (qt.owner_user_id IS NULL OR qt.id IS NULL OR qt.owner_user_id IN ($placeholders))
          ORDER BY t.sort_order, t.name, s.name, qt.title
      ";
    $flat_data = q($searchable_items_sql, array_merge($allowed_teacher_ids, $allowed_teacher_ids, $allowed_teacher_ids))->fetchAll();
}

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
$searchable_list = array_values(array_unique($searchable_list, SORT_REGULAR));

// Data Awal untuk Card Browser dengan filter owner_user_id
$allowed_teacher_ids = get_allowed_teacher_ids_for_content();

if (empty($allowed_teacher_ids)) {
    // Hanya tampilkan tema global
    $themes = q("SELECT id, name FROM themes WHERE owner_user_id IS NULL ORDER BY sort_order, name")->fetchAll();
} else {
    // Tampilkan tema global + tema dari pengajar yang diizinkan
    $placeholders = implode(',', array_fill(0, count($allowed_teacher_ids), '?'));
    $themes = q(
        "SELECT id, name FROM themes WHERE owner_user_id IS NULL OR owner_user_id IN ($placeholders) ORDER BY sort_order, name",
        $allowed_teacher_ids
    )->fetchAll();
}

// Ambil peran user
$user_role = $_SESSION['user']['role'] ?? 'umum';
$current_user_id = uid();

require 'views/home.php';
