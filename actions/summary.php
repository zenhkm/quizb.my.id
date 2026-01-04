<?php
// actions/summary.php

// Pastikan $sid tersedia (dari actions/play.php atau view_review_summary)
if (!isset($sid)) {
    echo '<div class="alert alert-danger">Session ID missing.</div>';
    return;
}

$session = q("SELECT * FROM quiz_sessions WHERE id=?", [$sid])->fetch();
if (!$session) {
    echo '<div class="alert alert-danger">Sesi tidak ditemukan.</div>';
    return;
}

// --- AMBIL DETAIL KUIS & NAMA PENGGUNA ---
$quiz_details = q("
    SELECT qt.title, st.name AS subtheme_name, t.name AS theme_name
    FROM quiz_titles qt
    JOIN subthemes st ON qt.subtheme_id = st.id
    JOIN themes t ON st.theme_id = t.id
    WHERE qt.id = ?
", [$session['title_id']])->fetch();
$user_name = $_SESSION['user']['name'] ?? 'Peserta';

// Ambil daftar judul terkait (subtheme yang sama ATAU theme yang sama)
// Respect visibility / owner rules sama seperti query title di play.php
$related_titles = [];
$sub_id = (int)$session['title_id'] ? (int)q("SELECT subtheme_id FROM quiz_titles WHERE id=?", [$session['title_id']])->fetchColumn() : 0;
$allowed_teacher_ids = get_allowed_teacher_ids_for_content();
if (empty($allowed_teacher_ids)) {
    $related_titles = q(
        "SELECT qt.id, qt.title, st.name subn FROM quiz_titles qt
         JOIN subthemes st ON st.id=qt.subtheme_id
         WHERE (qt.subtheme_id = ? OR st.theme_id = (SELECT theme_id FROM subthemes WHERE id = ?))
           AND qt.id <> ? AND qt.deleted_at IS NULL AND st.deleted_at IS NULL
         ORDER BY qt.title LIMIT 8",
        [$sub_id, $sub_id, $session['title_id']]
    )->fetchAll();
} else {
    $placeholders = implode(',', array_fill(0, count($allowed_teacher_ids), '?'));
    $sql = "SELECT qt.id, qt.title, st.name subn FROM quiz_titles qt
            JOIN subthemes st ON st.id=qt.subtheme_id
            WHERE (qt.subtheme_id = ? OR st.theme_id = (SELECT theme_id FROM subthemes WHERE id = ?))
              AND qt.id <> ? AND (qt.owner_user_id IS NULL OR qt.owner_user_id IN ($placeholders))
              AND qt.deleted_at IS NULL AND st.deleted_at IS NULL
            ORDER BY qt.title LIMIT 8";
    $related_titles = q($sql, array_merge([$sub_id, $sub_id, $session['title_id']], $allowed_teacher_ids))->fetchAll();
}

// 1. Ambil semua jawaban yang sudah dicatat (untuk menghitung yang benar)
$att = q("SELECT a.*, q.text qtext,(SELECT text FROM choices WHERE id=a.choice_id) choice_text,(SELECT text FROM choices WHERE question_id=q.id AND is_correct=1 LIMIT 1) correct_text FROM attempts a JOIN questions q ON q.id=a.question_id WHERE a.session_id=? ORDER BY a.id", [$sid])->fetchAll();

// 2. Hitung jumlah jawaban yang benar dari data di atas
$correct = count(array_filter($att, fn($a) => (bool)$a['is_correct']));

// 3. HITUNG TOTAL SOAL DARI SESI KUIS, BUKAN DARI JUMLAH JAWABAN
$total_questions_in_quiz = (int)q("SELECT COUNT(*) FROM quiz_session_questions WHERE session_id=?", [$sid])->fetchColumn();

// 4. Hitung skor dengan pembagi yang benar.
$score = $total_questions_in_quiz > 0 ? round(($correct / $total_questions_in_quiz) * 100) : 0;

// 5. Bagian selanjutnya tetap sama
$exist = q("SELECT id FROM results WHERE session_id=?", [$sid])->fetch();

$city = q("SELECT city FROM quiz_sessions WHERE id=?", [$sid])->fetchColumn();
if (!$city || trim($city) === '') {
    $city = 'Anonim';
}

if (!$exist) q(
    "INSERT INTO results (session_id,user_id,title_id,score,city,created_at)
               VALUES (?,?,?,?,?,?)",
    [$sid, $session['user_id'], $session['title_id'], $score, $city, now()]
);

$myRes = q("SELECT id,score,user_id FROM results WHERE session_id=? LIMIT 1", [$sid])->fetch();

// ▼▼▼ AWAL BLOK KODE BARU UNTUK MENCATAT PENGUMPULAN TUGAS ▼▼▼
// Cek apakah sesi ini dikerjakan oleh siswa yang login
if ($session['user_id']) {
    $current_user_id = (int)$session['user_id'];
    $current_title_id = (int)$session['title_id'];

    // Cari tugas yang relevan untuk siswa ini dan kuis ini
    // Tugas harus aktif (belum melewati batas waktu jika ada)
    $relevant_assignment = q("
          SELECT a.id, a.id_kelas
          FROM assignments a
          JOIN class_members cm ON a.id_kelas = cm.id_kelas
          WHERE
              cm.id_pelajar = ?
              AND a.id_judul_soal = ?
              AND (a.batas_waktu IS NULL OR a.batas_waktu >= NOW())
          ORDER BY a.created_at DESC
          LIMIT 1
      ", [$current_user_id, $current_title_id])->fetch();

    // Jika ada tugas yang cocok dan hasil kuis sudah tersimpan...
    if ($relevant_assignment && isset($myRes['id'])) {
        $assignment_id = $relevant_assignment['id'];
        $result_id = $myRes['id'];

        // Simpan catatan ke assignment_submissions
        // Gunakan ON DUPLICATE KEY UPDATE untuk mencegah error jika siswa mencoba mengerjakan ulang
        q("
              INSERT INTO assignment_submissions (assignment_id, user_id, result_id, submitted_at)
              VALUES (?, ?, ?, NOW())
              ON DUPLICATE KEY UPDATE result_id = VALUES(result_id), submitted_at = VALUES(submitted_at)
          ", [$assignment_id, $current_user_id, $result_id]);
    }
}
// ▲▲▲ AKHIR DARI BLOK KODE BARU ▲▲▲

// =================================================================
// ▼▼▼ AWAL LOGIKA PENGIRIMAN LAPORAN NILAI 100 KE GRUP WA ▼▼▼
// =================================================================
// KONDISI 1: Cek apakah ini adalah sesi tugas (assignment) dan pengguna yang login.
if (isset($_SESSION['quiz']['assignment_id']) && $session['user_id']) {

    // KONDISI 2: Cek apakah skor yang baru saja didapat adalah 100.
    if ($score == 100) {
        try {
            $assignment_id = (int)$_SESSION['quiz']['assignment_id'];

            // LANGKAH 3: Ambil data yang diperlukan dari database.
            // a) Ambil id_kelas dan judul_tugas dari tabel assignments.
            $assignment_details = q("SELECT id_kelas, judul_tugas FROM assignments WHERE id = ?", [$assignment_id])->fetch();

            if ($assignment_details) {
                $id_kelas = (int)$assignment_details['id_kelas'];
                $judul_tugas = $assignment_details['judul_tugas'];

                // f) Ambil nama_kelas dan link_wa dari tabel classes.
                $class_details = q("SELECT nama_kelas, wa_link FROM classes WHERE id = ?", [$id_kelas])->fetch();

                // Lanjutkan hanya jika ada link WA di data kelas.
                if ($class_details && !empty($class_details['wa_link'])) {
                    $nama_kelas = $class_details['nama_kelas'];
                    $wa_link_group = $class_details['wa_link'];

                    // e) Ambil daftar NAMA SEMUA USER yang sudah mendapat nilai 100 untuk tugas ini.
                    $perfect_scorers = q("
                        SELECT u.name
                        FROM assignment_submissions asub
                        JOIN results r ON asub.result_id = r.id
                        JOIN users u ON asub.user_id = u.id
                        WHERE asub.assignment_id = ? AND r.score = 100
                        ORDER BY asub.submitted_at DESC
                    ", [$assignment_id])->fetchAll(PDO::FETCH_COLUMN);

                    // Lanjutkan hanya jika ada minimal satu orang yang mendapat nilai 100.
                    if (!empty($perfect_scorers)) {
                        // Siapkan format pesan WA.
                        $today_date = date('d F Y');
                        $message_header = "🎉 Daftar Mahasiswa yang mendapat nilai 100.\n\nNama Tugas : $judul_tugas\nHari : $today_date\nKelas : $nama_kelas\n\n";

                        $student_list_lines = [];
                        foreach ($perfect_scorers as $index => $student_name) {
                            $student_list_lines[] = ($index + 1) . ". " . $student_name;
                        }

                        // Ubah ini menjadi variabel sendiri
                        $student_list_string = implode("\n", $student_list_lines);

                        // ▼▼▼ TAMBAHKAN 3 BARIS INI (SAMA PERSIS) ▼▼▼
                        // 1. Buat link absolut ke tugas menggunakan base_url()
                        // Variabel $assignment_id sudah ada dari baris di atasnya
                        $assignment_link = base_url() . '?page=student_tasks'; // <--- PERUBAHAN UTAMA DI SINI

                        // 2. Buat pesan ajakan (footer)
                        $footer_message = "\n\nBagi yang belum, yuk kerjakan tugasnya di sini:\n" . $assignment_link;

                        // 3. Gabungkan semua bagian pesan
                        $full_message = $message_header . $student_list_string . $footer_message;
                        // ▲▲▲ AKHIR TAMBAHAN ▲▲▲

                        wa_send($wa_link_group, $full_message);
                    }
                }
            }
        } catch (Exception $e) {
            // Catat error jika terjadi masalah saat mengirim WA agar tidak merusak halaman.
            error_log('Gagal mengirim notifikasi WA tugas: ' . $e->getMessage());
        }
    }
}
// =================================================================
// ▲▲▲ AKHIR LOGIKA PENGIRIMAN LAPORAN NILAI 100 KE GRUP WA ▲▲▲
// =================================================================

$inChallenge = !empty($_SESSION['current_challenge_token']);
$ch = null;

// PERBAIKAN: Jangan auto-create challenge, hanya ambil jika sudah ada
if (!$inChallenge) {
    $existTok = q("SELECT token FROM challenges WHERE owner_session_id=? OR owner_result_id=? LIMIT 1", [$sid, (int)($myRes['id'] ?? 0)])->fetch();
    if ($existTok) {
        $ch = $existTok['token'];
    }
}

// Handle Challenge Logic (Update Challenge Runs)
if (!empty($_SESSION['current_challenge_token'])) {
    $tok = $_SESSION['current_challenge_token'];
    
    if (!isset($myRes) || empty($myRes['id'])) {
        $myRes = q("SELECT id, score, user_id FROM results WHERE session_id=? LIMIT 1", [$sid])->fetch();
    }
    if (!empty($myRes['id'])) {
        q(
            "INSERT INTO challenge_runs (token, result_id, user_id, score, created_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE score=VALUES(score), created_at=VALUES(created_at)",
            [$tok, (int)$myRes['id'], ($myRes['user_id'] ?? (uid() ?: null)), (int)$myRes['score'], now()]
        );
    }
    // Note: We do NOT unset $_SESSION['current_challenge_token'] here because the view needs it to display the comparison.
    // We will unset it AFTER the view is rendered, or let it persist until next game?
    // The original code unset it at the very end of view_summary.
    // Since we are splitting, we should probably unset it in the view or after the view is included.
    // But views should be passive.
    // Let's keep it in session for the view, and maybe the view should unset it? No, views shouldn't modify session.
    // We can unset it in actions/play.php AFTER requiring the view?
    // Or just leave it? If we leave it, refreshing the page might show the comparison again. That's probably fine.
    // But wait, if they go to another page and come back?
    // The original code unset it.
    // Let's unset it in the view file at the end, or in the controller after view.
}
