================================================================
IMPLEMENTASI AUTO-SAVE JAWABAN MODE UJIAN
Dokumentasi Lengkap Perubahan
================================================================

RINGKASAN PERUBAHAN:
====================
Sistem telah dimodifikasi untuk auto-save jawaban siswa ke database 
setiap kali siswa memilih jawaban di mode ujian. Hal ini memungkinkan 
monitor untuk menampilkan progress real-time siswa, bukan hanya final 
submission.

================================================================
1. DATABASE CHANGES (SQL SCHEMA)
================================================================

FILE: create_draft_attempts_table.sql

TABEL BARU: draft_attempts
- Menyimpan draft jawaban siswa SEBELUM submit final
- Struktur:
  * id (INT, PRIMARY KEY)
  * session_id (INT, FK to quiz_sessions)
  * user_id (INT, FK to users)
  * question_id (INT, FK to questions)
  * choice_id (INT, FK to choices)
  * is_correct (TINYINT) - 1 jika benar, 0 jika salah
  * status (ENUM) - 'draft' atau 'submitted'
  * saved_at (DATETIME) - waktu dibuat
  * updated_at (DATETIME) - waktu terakhir diupdate

UNIQUE KEY:
- UNIQUE KEY `unique_draft` (session_id, question_id)
  → Memastikan hanya 1 jawaban per question per session
  → Jika siswa mengubah jawaban, otomatis update

INDEXES:
- idx_session_user - untuk query draft answers per user/session
- idx_status - untuk filter by status (draft/submitted)
- idx_session_status - kombinasi untuk performa query

JALANKAN SCRIPT INI DI DATABASE:
```sql
-- Buat tabel draft_attempts
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
```

================================================================
2. API ENDPOINT BARU
================================================================

LOKASI FILE: index.php (line ~1801)

FUNGSI: api_save_draft_answer()
Endpoint: ?action=api_save_draft_answer

TUJUAN: Auto-save jawaban ke tabel draft_attempts

REQUEST:
- Method: POST
- Content-Type: application/json
- Body:
  {
    "session_id": 123,
    "user_id": 456,
    "question_id": 789,
    "choice_id": 101,
    "is_correct": 1
  }

RESPONSE:
- Jika berhasil: {"ok": true, "message": "Draft jawaban tersimpan.", "question_id": 789}
- Jika error: {"ok": false, "error": "Deskripsi error"}

FLOW:
1. Validasi input (session_id, question_id harus ada)
2. Verifikasi session milik user yang tepat
3. Handle edge case: jika choice_id = 0 (waktu habis)
4. INSERT atau UPDATE ke draft_attempts
   - Jika sudah ada (unique key): UPDATE choice_id, is_correct
   - Jika baru: INSERT baru
5. Kembalikan JSON response

KEAMANAN:
- Memverifikasi bahwa session milik user yang login
- Jika user tidak sesuai, return 403 Forbidden

================================================================
3. MODIFIKASI JAVASCRIPT
================================================================

LOKASI: index.php dalam view_play() function (line ~7050)

MODIFIKASI: handleAnswerClickExamMode(selectedButton)

TAMBAHAN AUTO-SAVE:
Setiap kali siswa klik jawaban, sekarang dipanggil:

```javascript
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
```

KARAKTERISTIK:
- Async call (tidak menunggu response)
- Dijalankan SEBELUM auto-advance ke soal berikutnya
- Jika gagal, tidak menghentikan flow (error di-log saja)
- Memberikan pengalaman smooth tanpa lag

FLOW LENGKAP SETIAP JAWAB:
1. Siswa klik pilihan jawaban
2. handleAnswerClickExamMode() dipanggil
3. Jawaban disimpan di memory (quizState.userAnswers)
4. AUTO-SAVE dipanggil ke api_save_draft_answer
5. Progress bar diupdate
6. Tunggu 300ms untuk feedback visual
7. Auto-advance ke soal berikutnya

================================================================
4. MODIFIKASI api_submit_answers()
================================================================

LOKASI: index.php (line ~1880)

TAMBAHAN:
```php
// ▼▼▼ TANDAI SEMUA DRAFT ATTEMPTS SEBAGAI SUBMITTED ▼▼▼
q("UPDATE draft_attempts SET status = 'submitted' WHERE session_id = ? AND status = 'draft'", [$sid]);
// ▲▲▲ AKHIR UPDATE STATUS ▲▲▲
```

TUJUAN:
- Saat siswa klik "Selesaikan Ujian", semua draft attempts 
  diubah status menjadi 'submitted'
- Ini menandai waktu formal submission

TIMING:
- Dijalankan SEBELUM data disimpan ke tabel attempts final
- Jadi flow: draft → submitted → final attempts

================================================================
5. UPDATE MONITOR PAGE
================================================================

LOKASI: view_monitor_jawaban() function (line ~10750)

PERUBAHAN UTAMA:

A. QUERY DITINGKATKAN
   - Query sekarang melakukan LEFT JOIN ke draft_attempts
   - Mendeteksi siswa yang sedang mengerjakan (draft ada, belum submit)
   - Status sekarang ada 3 kategori:
     * 'Sudah Submit' - jawaban final di tabel attempts
     * 'Sedang Mengerjakan' - jawaban draft ada, belum final submit
     * 'Belum Submit' - belum ada aksi sama sekali

B. STATISTIK DASHBOARD
   Sekarang menampilkan 4 kartu:
   - ✅ Sudah Submit (count)
   - 🟡 Sedang Mengerjakan (count) ← BARU
   - ⏳ Belum Submit (count)
   - 📈 Rata-rata Score (hanya dari yang submit)

C. STATUS BADGE DI TABEL
   - ✅ Submit (hijau) - untuk sudah submit
   - 🟡 Mengerjakan (biru) - untuk sedang mengerjakan ← BARU
   - ⏳ Belum (kuning) - untuk belum submit
   - ❌ Terlambat (merah) - jika melewati deadline

D. DATA COLUMNS
   Tetap sama, tapi sekarang bisa berasal dari:
   - attempts/results (untuk Sudah Submit)
   - draft_attempts (untuk Sedang Mengerjakan)

E. INFORMASI TOOLTIP
   Ditambahkan penjelasan tentang draft_attempts dan real-time tracking

================================================================
6. WORKFLOW LENGKAP END-TO-END
================================================================

SKENARIO 1: SISWA MENGERJAKAN SOAL
────────────────────────────────────
1. Siswa masuk mode ujian → buat quiz_session
2. Soal 1: Siswa klik pilihan A
   → handleAnswerClickExamMode() called
   → Disimpan di memory (quizState.userAnswers)
   → api_save_draft_answer dipanggil
   → INSERT ke draft_attempts (status='draft')
   → Auto-advance ke soal 2
3. Soal 2: Siswa klik pilihan C
   → Sama, INSERT ke draft_attempts
4. Soal 3: Siswa ubah jawaban dari B ke D
   → UNIQUE KEY triggers UPDATE di draft_attempts
5. Monitor page sekarang:
   → Deteksi siswa punya draft_attempts
   → Status: "🟡 Sedang Mengerjakan"
   → Tabel menampilkan siswa ini dengan status tersebut

SKENARIO 2: SISWA SUBMIT FINAL
──────────────────────────────
1. Siswa klik "Selesaikan Ujian" → finishQuiz()
2. fetch ke api_submit_answers dengan semua jawaban
3. Di backend:
   → UPDATE draft_attempts SET status='submitted' (mark yang final)
   → DELETE old attempts jika ada
   → INSERT ke attempts (final record)
   → INSERT/UPDATE results (hitung score)
   → INSERT assignment_submissions
4. Monitor page sekarang:
   → Deteksi sudah ada di assignment_submissions
   → Status: "✅ Sudah Submit"
   → Tampilkan score, prosentase, nilai

SKENARIO 3: SISWA BARU BELUM JAWAB
──────────────────────────────────
1. Siswa tidak membuka ujian atau refresh page
2. Tidak ada record di draft_attempts
3. Monitor page:
   → Status: "⏳ Belum Submit"
   → Baris tetap visible (INNER JOIN dengan class_members)

================================================================
7. TABEL DATA FLOW
================================================================

SEBELUM PERUBAHAN:
  quiz_session → memory (JS) → attempts → results → assignment_submissions
  (saat submit saja)

SETELAH PERUBAHAN:
  quiz_session → 
    ├─ memory (JS)
    ├─ draft_attempts (status='draft') ← NEW, setiap jawab
    │  └─ dapat dimonitor real-time
    └─ saat submit:
       ├─ UPDATE draft_attempts (status='submitted')
       ├─ INSERT attempts
       ├─ INSERT results
       └─ INSERT assignment_submissions

BENEFIT:
- 📊 Real-time monitoring
- 🔍 Audit trail lengkap
- 📈 Bisa track progress siswa
- 🛡️ Recovery jika crash/refresh

================================================================
8. TESTING CHECKLIST
================================================================

DATABASE:
- [ ] Jalankan SQL script untuk buat draft_attempts
- [ ] Verifikasi struktur tabel: 
      DESCRIBE draft_attempts;
- [ ] Verifikasi foreign keys:
      SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
      WHERE TABLE_NAME='draft_attempts';

API ENDPOINT:
- [ ] Test api_save_draft_answer dengan valid data
      - Cek INSERT ke draft_attempts
      - Cek UNIQUE KEY behavior (update jika ada)
- [ ] Test dengan invalid session_id (harus 400)
- [ ] Test dengan session_id punya user lain (harus 403)

JAVASCRIPT:
- [ ] Buka mode ujian
- [ ] Jawab soal, lihat console (tidak boleh error)
- [ ] Cek di database: draft_attempts harus terisi

MONITOR PAGE:
- [ ] Buka halaman monitor
- [ ] Statistik card baru: "Sedang Mengerjakan" visible
- [ ] Cek tabel: siswa yang sedang mengerjakan muncul dengan badge 🟡
- [ ] Cek tabel: siswa yang sudah submit muncul dengan badge ✅

END-TO-END:
- [ ] Siswa jawab soal → cek draft_attempts terisi
- [ ] Siswa ubah jawaban → cek draft_attempts ter-update
- [ ] Siswa submit → cek status='submitted'
- [ ] Monitor refresh → status berubah menjadi ✅ Submit

================================================================
9. PERFORMANCE NOTES
================================================================

INDEX STRATEGY:
- draft_attempts sudah punya indexes untuk query performance
- UNIQUE KEY (session_id, question_id) membantu:
  * Mencegah duplikasi
  * Mempercepat UPDATE (ON DUPLICATE KEY)
  * Mempercepat query filtering

QUERY OPTIMIZATION:
- Monitor query menggunakan INNER JOIN untuk main data
- LEFT JOIN untuk optional data (draft_attempts, results)
- Harus test dengan data besar (ribuan siswa)

DATABASE SIZE:
- Draft records: ~1 record per question per attempt
- Cleanup strategy: Bisa archive old draft_attempts setelah X hari

================================================================
10. MAINTENANCE & CLEANUP (OPSIONAL)
================================================================

JIKA PERLU CLEANUP OLD DRAFTS:
```sql
-- Hapus draft yang lebih dari 30 hari lalu dan belum submit
DELETE FROM draft_attempts 
WHERE status = 'draft' 
AND saved_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

JIKA PERLU RESET:
```sql
-- Truncate semua draft_attempts (hati-hati!)
TRUNCATE TABLE draft_attempts;
```

JIKA PERLU DROP TABEL (jika ingin rollback):
```sql
DROP TABLE draft_attempts;
```

================================================================
DEPLOYMENT STEPS
================================================================

1. Backup database terlebih dahulu
2. Jalankan SQL script (buat tabel draft_attempts)
3. Deploy kode index.php dengan:
   - api_save_draft_answer() function
   - Modifikasi handleAnswerClickExamMode()
   - Modifikasi api_submit_answers()
   - Update view_monitor_jawaban()
4. Test di development dulu
5. Deploy ke production
6. Monitor logs untuk error

================================================================
FILE YANG DISEDIAKAN
================================================================

1. create_draft_attempts_table.sql
   - Script untuk membuat tabel draft_attempts
   - Jalankan di database

2. index.php (modified)
   - 4 area perubahan sudah dilakukan
   - Cek line numbers di atas untuk referensi

3. DOKUMENTASI INI
   - Penjelasan lengkap semua perubahan

================================================================

Catatan Akhir:
- Auto-save bersifat async (fire & forget)
- Jika server error, siswa tetap bisa continue (jawaban di memory)
- Final submission tetap yang penting untuk menghitung score
- Monitor sekarang real-time menampilkan progress siswa

Semoga implementasi ini membantu! 🎉
