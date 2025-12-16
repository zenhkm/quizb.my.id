<?php
// actions/play.php

// === BLOK BARU: CEK JIKA SUDAH PERNAH 100 PADA ASSIGNMENT ===
if (isset($_SESSION['quiz']['assignment_id']) && uid()) {
    $assignment_id = (int)$_SESSION['quiz']['assignment_id'];
    $user_id = uid();
    
    // Query untuk mencari apakah sudah ada submission dengan nilai 100
    $has_perfect_score = q("
        SELECT COUNT(asub.id)
        FROM assignment_submissions asub
        JOIN results r ON asub.result_id = r.id
        WHERE asub.assignment_id = ? AND asub.user_id = ? AND r.score = 100
    ", [$assignment_id, $user_id])->fetchColumn();

    if ($has_perfect_score > 0) {
        // Jika sudah ada nilai 100, tampilkan pesan dan blokir akses
        echo '<div class="alert alert-success mt-4">
                <strong>Selamat!</strong> Anda sudah mendapatkan nilai 100 untuk tugas ini. Kamu Tidak Perlu mengerjakannya lagi.
              </div>';
        
        // Tampilkan tombol untuk kembali ke daftar tugas
        echo '<a href="?page=student_tasks" class="btn btn-primary mt-3">&laquo; Kembali ke Daftar Tugas</a>';
        return; // HENTIKAN EKSEKUSI
    }
}
// === AKHIR BLOK BARU ===


// Coba ambil ID dari URL dulu (untuk kuis biasa & pemilihan mode)
$title_id_from_url = (int)($_GET['title_id'] ?? 0);
$assignment_id_from_url = (int)($_GET['assignment_id'] ?? 0);

// Jika sedang dalam sesi kuis (baik biasa maupun tugas), prioritaskan data dari session
if (isset($_SESSION['quiz']['session_id'])) {
    $title_id = (int)($_SESSION['quiz']['title_id'] ?? 0);
} else {
    // Jika tidak ada sesi, gunakan dari URL (hanya untuk halaman pemilihan mode)
    $title_id = $title_id_from_url;
}

// Ambil mode dari session jika ada, jika tidak, ambil dari URL
$mode = $_SESSION['quiz']['mode'] ?? ($_GET['mode'] ?? null);

// Get allowed teacher IDs untuk content visibility check
$allowed_teacher_ids = get_allowed_teacher_ids_for_content();

// Query title dengan filter owner_user_id berdasarkan role
if (empty($allowed_teacher_ids)) {
    $title = q(
        "SELECT qt.*,st.name subn,t.name themen FROM quiz_titles qt 
         JOIN subthemes st ON st.id=qt.subtheme_id 
         JOIN themes t ON t.id=st.theme_id 
         WHERE qt.id=? AND qt.owner_user_id IS NULL",
        [$title_id]
    )->fetch();
} else {
    $placeholders = implode(',', array_fill(0, count($allowed_teacher_ids), '?'));
    $title = q(
        "SELECT qt.*,st.name subn,t.name themen FROM quiz_titles qt 
         JOIN subthemes st ON st.id=qt.subtheme_id 
         JOIN themes t ON t.id=st.theme_id 
         WHERE qt.id=? AND (qt.owner_user_id IS NULL OR qt.owner_user_id IN ($placeholders))",
        array_merge([$title_id], $allowed_teacher_ids)
    )->fetch();
}

if (!$title) {
  echo '<div class="alert alert-warning">Judul kuis tidak ditemukan.</div>';
  return;
}

// Cek apakah ini dari assignment atau restart (jangan tampilkan pilihan mode)
$from_assignment = isset($_GET['assignment_id']) && (int)$_GET['assignment_id'] > 0;
$is_restart = isset($_GET['restart']) && $_GET['restart'] === '1';
$should_show_mode_selection = !$from_assignment && !$is_restart && $title_id > 0;

if (!$mode && $should_show_mode_selection) {
    // Render mode selection view
    require 'views/play_mode_selection.php';
    return;
}

// Dengan guard yang baru, kita bisa berasumsi sesi sudah siap.
// Jika sesi tidak ada (misal, karena akses URL manual yang salah), beri peringatan.
if (!isset($_SESSION['quiz']) || !isset($_SESSION['quiz']['session_id'])) {
    echo '<div class="alert alert-warning">Sesi kuis tidak valid. Silakan mulai dari awal.</div>';
    echo '<a href="?page=play&title_id=' . $title_id . '" class="btn btn-primary">Pilih Mode</a>';
    return;
}

// Langsung gunakan session_id yang sudah ada dan valid.
$sid = $_SESSION['quiz']['session_id'];

$qs = q("SELECT q.* FROM quiz_session_questions m
       JOIN questions q ON q.id = m.question_id
       WHERE m.session_id = ?
       ORDER BY m.sort_no", [$sid])->fetchAll();
$i = max(0, (int)($_GET['i'] ?? 0));
if ($i >= count($qs) && count($qs) > 0) { // Tambahkan pengecekan count($qs) > 0
  require 'actions/summary.php';
  require 'views/summary.php';
  if (isset($_SESSION['current_challenge_token'])) {
      unset($_SESSION['current_challenge_token']);
  }
  return;
}

if ($mode === 'instant' || $mode === 'end' || $mode === 'exam') {
    require 'views/play.php';
}
