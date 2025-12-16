-- =====================================================================
-- SCRIPT SQL: Membuat Tabel draft_attempts untuk Auto-Save Jawaban
-- =====================================================================
-- Tabel ini menyimpan draft jawaban siswa SEBELUM submit
-- Setiap jawaban otomatis disimpan ke database saat siswa memilih
-- =====================================================================

-- =====================================================================
-- 1. BUAT TABEL draft_attempts
-- =====================================================================
CREATE TABLE IF NOT EXISTS `draft_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `choice_id` int(11) NOT NULL,
  `is_correct` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('draft','submitted') NOT NULL DEFAULT 'draft' COMMENT 'draft=belum submit, submitted=sudah submit',
  `saved_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_draft` (`session_id`, `question_id`),
  KEY `idx_session_user` (`session_id`, `user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `fk_draft_attempts_session` FOREIGN KEY (`session_id`) REFERENCES `quiz_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_draft_attempts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_draft_attempts_question` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Menyimpan draft jawaban siswa sebelum submit (auto-saved)';

-- =====================================================================
-- 2. TAMBAH INDEX untuk performa (opsional, tapi recommended)
-- =====================================================================
ALTER TABLE `draft_attempts` ADD INDEX `idx_session_status` (`session_id`, `status`);
ALTER TABLE `draft_attempts` ADD INDEX `idx_updated_at` (`updated_at`);

-- =====================================================================
-- 3. MODIFIKASI TABEL attempts - Tambah kolom status (opsional)
-- =====================================================================
-- Jika ingin tracking apakah attempt dari draft atau langsung submit
ALTER TABLE `attempts` ADD COLUMN `from_draft` tinyint(1) DEFAULT 0 COMMENT 'Apakah attempt ini dari draft (1) atau langsung submit (0)' AFTER `is_correct`;

-- =====================================================================
-- VERIFIKASI
-- =====================================================================
-- Cek tabel sudah dibuat:
-- SELECT * FROM draft_attempts LIMIT 1;
-- SHOW COLUMNS FROM draft_attempts;

-- =====================================================================
-- CLEANUP (Jika perlu reset)
-- =====================================================================
-- DROP TABLE IF EXISTS `draft_attempts`;
