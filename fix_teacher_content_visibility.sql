-- ============================================================================
-- FIX: Atur owner_user_id untuk tema/subtema/judul yang dibuat pengajar
-- ============================================================================
-- Catatan: Script ini mengasumsikan pengajar yang membuat konten tersimpan 
-- di field owner_user_id. Jika belum ada, pastikan app sudah set field ini 
-- saat pengajar membuat tema/subtema/judul baru.

-- Untuk testing: Pastikan ada tema/subtema/judul dengan owner_user_id yang bukan NULL

-- Lihat data saat ini:
SELECT 'THEMES' AS table_name, id, name, owner_user_id FROM themes UNION ALL
SELECT 'SUBTHEMES', id, name, owner_user_id FROM subthemes UNION ALL
SELECT 'QUIZ_TITLES', id, title, owner_user_id FROM quiz_titles
ORDER BY table_name, id;

-- Jika perlu, contoh: Set owner_user_id untuk tema tertentu
-- UPDATE themes SET owner_user_id = 2 WHERE id = 5; -- Tema dengan ID 5 milik user ID 2
-- UPDATE subthemes SET owner_user_id = 2 WHERE id = 3; -- Subtema dengan ID 3 milik user ID 2
-- UPDATE quiz_titles SET owner_user_id = 2 WHERE id = 1; -- Judul dengan ID 1 milik user ID 2
