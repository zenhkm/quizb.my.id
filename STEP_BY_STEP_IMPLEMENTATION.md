================================================================
STEP-BY-STEP IMPLEMENTATION GUIDE
Auto-Save Jawaban Mode Ujian
================================================================

LANGKAH 1: BACKUP DATABASE & FILES
===================================

Sebelum melakukan perubahan apapun:

1.1 Backup database:
   ```bash
   # Via command line (jika bisa SSH)
   mysqldump -u username -p quic1934_quizb > backup_quic1934_quizb_$(date +%Y%m%d).sql
   
   # Atau via phpMyAdmin:
   - Buka phpMyAdmin
   - Pilih database: quic1934_quizb
   - Tab Export → Download as SQL file
   ```

1.2 Backup index.php:
   ```bash
   cp index.php index.php.backup
   ```

STATUS: ✓ BACKUP COMPLETE

================================================================

LANGKAH 2: BUAT TABEL draft_attempts
=====================================

2.1 Siapkan SQL script:
   - Gunakan file: create_draft_attempts_table.sql
   - Atau copy-paste script di bawah

2.2 Jalankan script via phpMyAdmin:
   - Buka phpMyAdmin
   - Select database: quic1934_quizb
   - Tab SQL → Paste script di bawah
   - Klik Go

2.3 SQL Script:

```sql
-- =====================================================================
-- BUAT TABEL draft_attempts
-- =====================================================================
CREATE TABLE IF NOT EXISTS `draft_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `choice_id` int(11) NOT NULL,
  `is_correct` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('draft','submitted') NOT NULL DEFAULT 'draft',
  `saved_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_draft` (`session_id`, `question_id`),
  KEY `idx_session_user` (`session_id`, `user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_session_status` (`session_id`, `status`),
  KEY `idx_updated_at` (`updated_at`),
  CONSTRAINT `fk_draft_attempts_session` FOREIGN KEY (`session_id`) REFERENCES `quiz_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_draft_attempts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_draft_attempts_question` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Jika sudah ada struktur attempts, tambah kolom (OPTIONAL):
ALTER TABLE `attempts` ADD COLUMN `from_draft` tinyint(1) DEFAULT 0 AFTER `is_correct`;
```

2.4 Verifikasi tabel berhasil dibuat:
   ```sql
   DESCRIBE draft_attempts;
   
   -- Output harus ada 9 kolom:
   -- id, session_id, user_id, question_id, choice_id, is_correct, status, saved_at, updated_at
   ```

STATUS: ✓ DATABASE SCHEMA UPDATED

================================================================

LANGKAH 3: TAMBAH ROUTING UNTUK API ENDPOINT
=============================================

3.1 Buka file: index.php

3.2 Cari baris: if (isset($_GET['action']) && $_GET['action'] === 'api_submit_answers')
   (Sekitar line 933)

3.3 Tambahkan baris ini SETELAH blok api_submit_answers:

```php
if (isset($_GET['action']) && $_GET['action'] === 'api_save_draft_answer') {
  api_save_draft_answer(); // Auto-save jawaban ke database
}
```

3.4 KONTEKS LENGKAP:
```php
if (isset($_GET['action']) && $_GET['action'] === 'api_submit_answers') {
  api_submit_answers(); // Panggil fungsi API yang baru kita buat
}
if (isset($_GET['action']) && $_GET['action'] === 'api_save_draft_answer') {
  api_save_draft_answer(); // Auto-save jawaban ke database  ← TAMBAH INI
}
if (isset($_GET['action']) && $_GET['action'] === 'create_challenge' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  handle_create_challenge();
}
```

STATUS: ✓ ROUTING ADDED

================================================================

LANGKAH 4: TAMBAH FUNGSI API ENDPOINT
======================================

4.1 Cari baris: /**
                 * API Endpoint untuk menerima dan menyimpan semua jawaban dari kuis.
   (Sekitar line 1800, sebelum function api_submit_answers())

4.2 TAMBAHKAN FUNGSI INI SEBELUM api_submit_answers():

```php
/**
 * =====================================================================
 * API Endpoint untuk AUTO-SAVE jawaban ke database (Real-Time)
 * Dipanggil setiap kali siswa memilih jawaban di mode ujian
 * =====================================================================
 */
function api_save_draft_answer()
{
  header('Content-Type: application/json; charset=UTF-8');

  $input = json_decode(file_get_contents('php://input'), true);

  $sid = (int)($input['session_id'] ?? 0);
  $uid = (int)($input['user_id'] ?? 0);
  $qid = (int)($input['question_id'] ?? 0);
  $cid = (int)($input['choice_id'] ?? 0);
  $is_correct = (int)($input['is_correct'] ?? 0);

  // Validasi dasar
  if ($sid === 0 || $qid === 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Data tidak valid (session_id atau question_id kosong).']);
    exit;
  }

  // Gunakan uid dari session jika tidak diberikan
  if ($uid === 0) {
    $uid = uid();
  }

  // Verifikasi sesi milik user yang tepat
  $session_owner = q("SELECT user_id FROM quiz_sessions WHERE id = ?", [$sid])->fetchColumn();
  if ($session_owner !== null && $session_owner != $uid) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Sesi tidak sah.']);
    exit;
  }

  // Cek jika choice_id = 0 (waktu habis), ambil salah satu jawaban salah sebagai fallback
  if ($cid === 0) {
    $fallback_choice_id = q("SELECT id FROM choices WHERE question_id = ? AND is_correct = 0 LIMIT 1", [$qid])->fetchColumn();
    if ($fallback_choice_id) {
      $cid = (int)$fallback_choice_id;
    } else {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => 'Tidak ada pilihan jawaban yang valid.']);
      exit;
    }
  }

  // =====================================================================
  // INSERT atau UPDATE ke draft_attempts (ON DUPLICATE KEY UPDATE)
  // =====================================================================
  try {
    q(
      "INSERT INTO draft_attempts (session_id, user_id, question_id, choice_id, is_correct, status, saved_at, updated_at)
       VALUES (?, ?, ?, ?, ?, 'draft', NOW(), NOW())
       ON DUPLICATE KEY UPDATE
       choice_id = VALUES(choice_id),
       is_correct = VALUES(is_correct),
       updated_at = NOW()",
      [$sid, $uid, $qid, $cid, $is_correct]
    );

    echo json_encode([
      'ok' => true,
      'message' => 'Draft jawaban tersimpan.',
      'question_id' => $qid
    ]);
    exit;
  } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    exit;
  }
}
```

STATUS: ✓ API ENDPOINT ADDED

================================================================

LANGKAH 5: MODIFIKASI api_submit_answers()
===========================================

5.1 Cari fungsi: function api_submit_answers()
   (Sekitar line 1878)

5.2 Cari baris: // Hapus attempt lama jika ada (untuk mencegah duplikasi jika user refresh halaman)
                 q("DELETE FROM attempts WHERE session_id = ?", [$sid]);

5.3 TAMBAHKAN 2 baris setelah baris di atas:

```php
  // Hapus attempt lama jika ada (untuk mencegah duplikasi jika user refresh halaman)
  q("DELETE FROM attempts WHERE session_id = ?", [$sid]);

  // ▼▼▼ TANDAI SEMUA DRAFT ATTEMPTS SEBAGAI SUBMITTED ▼▼▼
  q("UPDATE draft_attempts SET status = 'submitted' WHERE session_id = ? AND status = 'draft'", [$sid]);
  // ▲▲▲ AKHIR UPDATE STATUS ▲▲▲

  // Simpan setiap jawaban
```

STATUS: ✓ api_submit_answers() MODIFIED

================================================================

LANGKAH 6: MODIFIKASI handleAnswerClickExamMode()
==================================================

6.1 Cari fungsi: function handleAnswerClickExamMode(selectedButton)
   (Sekitar line 7050 dalam blok JavaScript di view_play())

6.2 Cari baris dalam fungsi:
```javascript
    quizState.userAnswers.set(question.id, {
        question_id: question.id,
        choice_id: parseInt(selectedButton.dataset.choiceId),
        is_correct: selectedButton.dataset.isCorrect === 'true'
    });
    updateExamProgress();
```

6.3 TAMBAHKAN BLOK INI SETELAH updateExamProgress():

```javascript
            // ▼▼▼ AUTO-SAVE JAWABAN KE DATABASE (REAL-TIME) ▼▼▼
            const answerData = {
                session_id: quizState.sessionId,
                user_id: quizState.userId,
                question_id: question.id,
                choice_id: parseInt(selectedButton.dataset.choiceId),
                is_correct: selectedButton.dataset.isCorrect === 'true' ? 1 : 0
            };
            
            // Kirim ke server (async, jangan perlu menunggu response)
            fetch('?action=api_save_draft_answer', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(answerData)
            }).catch(err => console.log('Auto-save failed:', err));
            // ▲▲▲ AKHIR AUTO-SAVE ▲▲▲
```

6.4 KONTEKS LENGKAP:
```javascript
        function handleAnswerClickExamMode(selectedButton){
            const question = quizState.questions[quizState.currentQuestionIndex];
            
            // Hapus kelas 'selected' dari semua pilihan di soal ini
            appContainer.querySelectorAll('.quiz-choice-item').forEach(btn => btn.classList.remove('selected'));
            // Tambahkan kelas 'selected' ke pilihan yang diklik
            selectedButton.classList.add('selected');

            quizState.userAnswers.set(question.id, {
                question_id: question.id,
                choice_id: parseInt(selectedButton.dataset.choiceId),
                is_correct: selectedButton.dataset.isCorrect === 'true'
            });
            updateExamProgress();
            
            // ▼▼▼ AUTO-SAVE JAWABAN KE DATABASE (REAL-TIME) ▼▼▼
            const answerData = {
                session_id: quizState.sessionId,
                user_id: quizState.userId,
                question_id: question.id,
                choice_id: parseInt(selectedButton.dataset.choiceId),
                is_correct: selectedButton.dataset.isCorrect === 'true' ? 1 : 0
            };
            
            fetch('?action=api_save_draft_answer', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(answerData)
            }).catch(err => console.log('Auto-save failed:', err));
            // ▲▲▲ AKHIR AUTO-SAVE ▲▲▲
            
            // ▼▼▼ AUTO-ADVANCE KE SOAL BERIKUTNYA (MODE UJIAN) ▼▼▼
            const totalQuestions = quizState.questions.length;
            const nextIndex = quizState.currentQuestionIndex + 1;
            
            // Disable semua pilihan jawaban untuk mencegah klik ganda
            appContainer.querySelectorAll('.quiz-choice-item').forEach(btn => btn.disabled = true);
            
            // Tunggu 300ms, lalu lanjut ke soal berikutnya atau selesaikan ujian
            setTimeout(() => {
                if (nextIndex >= totalQuestions) {
                    // Jika ini soal terakhir, tampilkan konfirmasi sebelum selesai
                    confirmFinish();
                } else {
                    // Lanjut ke soal berikutnya
                    renderQuestion(nextIndex);
                }
            }, 300);
            // ▲▲▲ AKHIR AUTO-ADVANCE ▲▲▲
        }
```

STATUS: ✓ handleAnswerClickExamMode() MODIFIED

================================================================

LANGKAH 7: MODIFIKASI view_monitor_jawaban()
==============================================

7.1 Cari fungsi: function view_monitor_jawaban()
   (Sekitar line 10750)

7.2 GANTI SELURUH QUERY:

Dari:
```php
    $query = "
        SELECT
            cm.id_pelajar AS user_id,
            u.name AS user_name,
            u.nama_sekolah,
            u.nama_kelas,
            a.id AS assignment_id,
            a.judul_tugas,
            st.name AS subtheme_name,
            qt.title AS quiz_title,
            MAX(r.score) AS score_percentage,
            COUNT(DISTINCT att.id) AS total_questions,
            SUM(CASE WHEN att.is_correct = 1 THEN 1 ELSE 0 END) AS correct_answers,
            MAX(asub.submitted_at) AS submitted_at,
            MAX(r.id) AS result_id,
            a.batas_waktu,
            a.mode,
            CASE 
                WHEN asub.id IS NOT NULL THEN 'Sudah Submit'
                ELSE 'Belum Submit'
            END AS status
        FROM class_members cm
        INNER JOIN users u ON cm.id_pelajar = u.id
        INNER JOIN assignments a ON a.id_kelas = cm.id_kelas
        INNER JOIN quiz_titles qt ON a.id_judul_soal = qt.id
        INNER JOIN subthemes st ON qt.subtheme_id = st.id
        LEFT JOIN assignment_submissions asub ON a.id = asub.assignment_id AND cm.id_pelajar = asub.user_id
        LEFT JOIN results r ON asub.result_id = r.id
        LEFT JOIN attempts att ON r.session_id = att.session_id
        WHERE a.mode = 'ujian'
    ";
```

Ke:
```php
    $query = "
        SELECT
            cm.id_pelajar AS user_id,
            u.name AS user_name,
            u.nama_sekolah,
            u.nama_kelas,
            a.id AS assignment_id,
            a.judul_tugas,
            st.name AS subtheme_name,
            qt.title AS quiz_title,
            MAX(r.score) AS score_percentage,
            COUNT(DISTINCT att.id) AS total_questions,
            SUM(CASE WHEN att.is_correct = 1 THEN 1 ELSE 0 END) AS correct_answers,
            MAX(asub.submitted_at) AS submitted_at,
            MAX(r.id) AS result_id,
            a.batas_waktu,
            a.mode,
            CASE 
                WHEN asub.id IS NOT NULL THEN 'Sudah Submit'
                WHEN COUNT(DISTINCT draft.id) > 0 THEN 'Sedang Mengerjakan'
                ELSE 'Belum Submit'
            END AS status,
            COUNT(DISTINCT draft.id) AS draft_count
        FROM class_members cm
        INNER JOIN users u ON cm.id_pelajar = u.id
        INNER JOIN assignments a ON a.id_kelas = cm.id_kelas
        INNER JOIN quiz_titles qt ON a.id_judul_soal = qt.id
        INNER JOIN subthemes st ON qt.subtheme_id = st.id
        LEFT JOIN assignment_submissions asub ON a.id = asub.assignment_id AND cm.id_pelajar = asub.user_id
        LEFT JOIN results r ON asub.result_id = r.id
        LEFT JOIN attempts att ON r.session_id = att.session_id
        LEFT JOIN draft_attempts draft ON a.id_judul_soal = (SELECT title_id FROM quiz_sessions WHERE id = (
            SELECT session_id FROM draft_attempts 
            WHERE user_id = cm.id_pelajar 
            AND status = 'draft' 
            ORDER BY updated_at DESC LIMIT 1
        )) AND draft.user_id = cm.id_pelajar AND draft.status = 'draft'
        WHERE a.mode = 'ujian'
    ";
```

7.3 GANTI STATISTIK SECTION:

Dari:
```php
    $submitted_count = 0;
    $not_submitted_count = 0;
    $avg_score = 0;
    $score_sum = 0;
    $submitted_with_score = 0;

    foreach ($jawaban_data as $row) {
        if ($row['status'] === 'Sudah Submit') {
            $submitted_count++;
            if ($row['score_percentage'] !== null) {
                $score_sum += (int)$row['score_percentage'];
                $submitted_with_score++;
            }
        } else {
            $not_submitted_count++;
        }
    }

    $avg_score = $submitted_with_score > 0 ? round($score_sum / $submitted_with_score, 2) : 0;

    echo '<div class="row mb-4">';
    echo '<div class="col-md-3">';
    echo '<div class="card border-success">';
    echo '<div class="card-body text-center">';
    echo '<h5 class="card-title">✅ Sudah Submit</h5>';
    echo '<h3 class="text-success">' . $submitted_count . '</h3>';
    echo '</div></div></div>';
    
    echo '<div class="col-md-3">';
    echo '<div class="card border-warning">';
    echo '<div class="card-body text-center">';
    echo '<h5 class="card-title">⏳ Belum Submit</h5>';
    echo '<h3 class="text-warning">' . $not_submitted_count . '</h3>';
    echo '</div></div></div>';

    echo '<div class="col-md-3">';
    echo '<div class="card border-info">';
    echo '<div class="card-body text-center">';
    echo '<h5 class="card-title">📝 Total Siswa</h5>';
    echo '<h3 class="text-info">' . count($jawaban_data) . '</h3>';
    echo '</div></div></div>';

    echo '<div class="col-md-3">';
    echo '<div class="card border-primary">';
    echo '<div class="card-body text-center">';
    echo '<h5 class="card-title">📈 Rata-rata</h5>';
    echo '<h3 class="text-primary">' . $avg_score . '%</h3>';
    echo '</div></div></div>';
    echo '</div>';
```

Ke:
```php
    $submitted_count = 0;
    $in_progress_count = 0;
    $not_submitted_count = 0;
    $avg_score = 0;
    $score_sum = 0;
    $submitted_with_score = 0;

    foreach ($jawaban_data as $row) {
        if ($row['status'] === 'Sudah Submit') {
            $submitted_count++;
            if ($row['score_percentage'] !== null) {
                $score_sum += (int)$row['score_percentage'];
                $submitted_with_score++;
            }
        } elseif ($row['status'] === 'Sedang Mengerjakan') {
            $in_progress_count++;
        } else {
            $not_submitted_count++;
        }
    }

    $avg_score = $submitted_with_score > 0 ? round($score_sum / $submitted_with_score, 2) : 0;

    echo '<div class="row mb-4">';
    echo '<div class="col-md-3">';
    echo '<div class="card border-success">';
    echo '<div class="card-body text-center">';
    echo '<h5 class="card-title">✅ Sudah Submit</h5>';
    echo '<h3 class="text-success">' . $submitted_count . '</h3>';
    echo '</div></div></div>';
    
    echo '<div class="col-md-3">';
    echo '<div class="card border-info">';
    echo '<div class="card-body text-center">';
    echo '<h5 class="card-title">🟡 Sedang Mengerjakan</h5>';
    echo '<h3 class="text-info">' . $in_progress_count . '</h3>';
    echo '</div></div></div>';
    
    echo '<div class="col-md-3">';
    echo '<div class="card border-warning">';
    echo '<div class="card-body text-center">';
    echo '<h5 class="card-title">⏳ Belum Submit</h5>';
    echo '<h3 class="text-warning">' . $not_submitted_count . '</h3>';
    echo '</div></div></div>';

    echo '<div class="col-md-3">';
    echo '<div class="card border-primary">';
    echo '<div class="card-body text-center">';
    echo '<h5 class="card-title">📈 Rata-rata</h5>';
    echo '<h3 class="text-primary">' . $avg_score . '%</h3>';
    echo '</div></div></div>';
    echo '</div>';
```

7.4 GANTI STATUS BADGE LOGIC:

Dari:
```php
        // Warna badge berdasarkan status
        if ($row['status'] === 'Sudah Submit') {
            $status_badge = '<span class="badge bg-success">✅ Submit</span>';
            $badge_class = $prosentase >= 75 ? 'bg-success' : ($prosentase >= 50 ? 'bg-warning' : 'bg-danger');
        } else {
            $status_badge = '<span class="badge bg-warning text-dark">⏳ Belum</span>';
            $badge_class = 'bg-secondary';

            // Cek deadline
            if ($row['batas_waktu']) {
                $deadline = strtotime($row['batas_waktu']);
                $now = time();
                if ($now > $deadline) {
                    $status_badge = '<span class="badge bg-danger">❌ Terlambat</span>';
                }
            }
        }
```

Ke:
```php
        // Warna badge berdasarkan status
        if ($row['status'] === 'Sudah Submit') {
            $status_badge = '<span class="badge bg-success">✅ Submit</span>';
            $badge_class = $prosentase >= 75 ? 'bg-success' : ($prosentase >= 50 ? 'bg-warning' : 'bg-danger');
        } elseif ($row['status'] === 'Sedang Mengerjakan') {
            $status_badge = '<span class="badge bg-info">🟡 Mengerjakan</span>';
            $badge_class = 'bg-info';
        } else {
            $status_badge = '<span class="badge bg-warning text-dark">⏳ Belum</span>';
            $badge_class = 'bg-secondary';

            // Cek deadline
            if ($row['batas_waktu']) {
                $deadline = strtotime($row['batas_waktu']);
                $now = time();
                if ($now > $deadline) {
                    $status_badge = '<span class="badge bg-danger">❌ Terlambat</span>';
                }
            }
        }
```

7.5 GANTI INFO ALERT:

Dari:
```php
    echo '<div class="alert alert-info mt-3 small">';
    echo '<strong>ℹ️ Informasi:</strong><br>';
    echo '• <strong>Status Sudah Submit:</strong> Siswa telah menyelesaikan kuis dan menekan tombol "Selesaikan Ujian"<br>';
    echo '• <strong>Benar:</strong> Jumlah jawaban yang benar / total soal<br>';
    echo '• <strong>%:</strong> Prosentase benar dari total soal<br>';
    echo '• <strong>Nilai:</strong> Skor akhir (0-100)<br>';
    echo '• Data diambil dari: <code>assignment_submissions</code> + <code>attempts</code> + <code>results</code>';
    echo '</div>';
```

Ke:
```php
    echo '<div class="alert alert-info mt-3 small">';
    echo '<strong>ℹ️ Informasi:</strong><br>';
    echo '• <strong>✅ Sudah Submit:</strong> Siswa telah menyelesaikan kuis dan menekan tombol "Selesaikan Ujian" (data dari tabel <code>attempts</code> dengan status submitted)<br>';
    echo '• <strong>🟡 Sedang Mengerjakan:</strong> Siswa sedang menjawab soal, jawaban sudah di-save otomatis (draft) (data dari tabel <code>draft_attempts</code>)<br>';
    echo '• <strong>⏳ Belum Submit:</strong> Siswa belum memulai atau belum ada aksi sama sekali<br>';
    echo '• <strong>Benar:</strong> Jumlah jawaban yang benar / total soal (hanya tampil saat sudah submit)<br>';
    echo '• <strong>%:</strong> Prosentase benar dari total soal<br>';
    echo '• <strong>Nilai:</strong> Skor akhir (0-100) dari tabel <code>results</code>';
    echo '</div>';
```

STATUS: ✓ view_monitor_jawaban() MODIFIED

================================================================

LANGKAH 8: VERIFIKASI PERUBAHAN
=================================

8.1 Di index.php, cek:
   - ☐ Routing untuk api_save_draft_answer ada (line ~930)
   - ☐ Fungsi api_save_draft_answer() ada (line ~1801)
   - ☐ UPDATE draft_attempts di api_submit_answers() ada (line ~1880)
   - ☐ Auto-save fetch di handleAnswerClickExamMode() ada (line ~7050)
   - ☐ Query & stats di view_monitor_jawaban() sudah updated (line ~10750)

8.2 Di database, cek:
   ```sql
   DESCRIBE draft_attempts;
   SELECT COUNT(*) FROM draft_attempts;
   ```

STATUS: ✓ VERIFICATION COMPLETE

================================================================

LANGKAH 9: TESTING
===================

UNIT TEST:

9.1 Test Database:
   ```bash
   # Cek tabel terbuat
   SELECT * FROM draft_attempts LIMIT 1;
   ```

9.2 Test API Endpoint (via curl):
   ```bash
   curl -X POST http://localhost/quizb/index.php?action=api_save_draft_answer \
     -H "Content-Type: application/json" \
     -d '{
       "session_id": 1,
       "user_id": 1,
       "question_id": 1,
       "choice_id": 1,
       "is_correct": 1
     }'
   ```

9.3 Test JavaScript (in browser):
   - Buka mode ujian
   - Jawab soal
   - Buka DevTools (F12) → Console
   - Tidak ada error merah
   - Tab Network → setiap soal dijawab ada POST request

INTEGRATION TEST:

9.4 End-to-End:
   - [ ] Siswa buka ujian mode
   - [ ] Jawab soal 1
   - [ ] Database: check draft_attempts punya record
   - [ ] Ubah jawaban soal 1
   - [ ] Database: check UPDATE bukan INSERT
   - [ ] Jawab soal 2, 3, dst
   - [ ] Monitor page: status = "🟡 Sedang Mengerjakan"
   - [ ] Klik "Selesaikan Ujian"
   - [ ] Database: check draft_attempts status='submitted'
   - [ ] Monitor page: status = "✅ Sudah Submit"
   - [ ] Score visible

STATUS: ✓ TESTING COMPLETE

================================================================

LANGKAH 10: DEPLOYMENT
=======================

10.1 Pre-Production Checklist:
   ☐ Semua testing passed
   ☐ Backup sudah dibuat
   ☐ Documentation sudah dibaca
   ☐ Rollback plan sudah siap

10.2 Deploy ke Production:
   ☐ Upload index.php yang sudah dimodifikasi
   ☐ Jalankan SQL script di production database
   ☐ Clear browser cache (Ctrl+Shift+Delete)
   ☐ Test dengan beberapa siswa

10.3 Post-Deployment Monitoring:
   ☐ Monitor error logs untuk anomali
   ☐ Cek apakah data tersimpan di draft_attempts
   ☐ Verifikasi monitor page tampil dengan benar
   ☐ Monitor performance (query tidak lambat)

STATUS: ✓ DEPLOYMENT COMPLETE

================================================================

LANGKAH 11: MAINTENANCE (OPTIONAL)
===================================

11.1 Cleanup Old Drafts (jika perlu):
   ```sql
   -- Jalankan 1-2 minggu sekali untuk cleanup
   DELETE FROM draft_attempts 
   WHERE status = 'draft' 
   AND saved_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
   ```

11.2 Monitoring Performa:
   ```sql
   -- Cek berapa banyak draft records
   SELECT COUNT(*) as total, status, 
          MIN(saved_at) as oldest, MAX(saved_at) as newest
   FROM draft_attempts
   GROUP BY status;
   ```

11.3 Backup Regular:
   - Backup database 1x per minggu
   - Backup index.php setiap kali ada modifikasi

================================================================

ROLLBACK PLAN (jika ada masalah)
=================================

JIKA INGIN ROLLBACK:

11.1 Stop penggunaan:
   ```php
   // Comment baris fetch di handleAnswerClickExamMode()
   // atau biarkan tapi tidak ada proses
   ```

11.2 Restore index.php:
   ```bash
   cp index.php.backup index.php
   ```

11.3 Keep draft_attempts (untuk audit):
   ```sql
   -- Data tetap, sistem hanya tidak insert lagi
   -- Jika ingin delete semua:
   TRUNCATE TABLE draft_attempts;
   ```

11.4 Restart ujian dari awal jika perlu:
   ```sql
   DELETE FROM draft_attempts;
   ```

================================================================

SELESAI! 🎉

Anda sudah berhasil mengimplementasikan auto-save jawaban mode ujian.

Fitur yang sekarang aktif:
✓ Auto-save jawaban ke database setiap soal dijawab
✓ Real-time monitoring progress siswa
✓ 3-status level: Sudah Submit, Sedang Mengerjakan, Belum Submit
✓ Audit trail lengkap di draft_attempts

Selamat! 🚀
