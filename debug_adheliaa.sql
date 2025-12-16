-- Cek data untuk siswa Adheliaa Zhraa (user_id 24011010)
-- Cari dulu user_id dari nama tersebut
SELECT id, name FROM users WHERE name LIKE '%Adheliaa%' OR id = 24011010 LIMIT 5;

-- Jika sudah dapat user_id, gunakan untuk cek draft_attempts
-- Ganti USER_ID dengan ID yang sudah didapat
SELECT 
    da.user_id,
    u.name,
    qs.title_id,
    COUNT(DISTINCT da.id) as attempt_count,
    SUM(CASE WHEN da.is_correct = 1 THEN 1 ELSE 0 END) as correct_count,
    qt.title,
    COUNT(DISTINCT qt.id) as question_title_count
FROM draft_attempts da
INNER JOIN quiz_sessions qs ON da.session_id = qs.id
INNER JOIN quiz_titles qt ON qs.title_id = qt.id
LEFT JOIN users u ON da.user_id = u.id
WHERE da.user_id = 24011010
GROUP BY da.user_id, qs.title_id
LIMIT 10;

-- Cek jumlah soal di quiz_titles (id 353)
SELECT COUNT(DISTINCT qsq.question_id) as total_questions
FROM quiz_session_questions qsq
WHERE qsq.session_id IN (
    SELECT DISTINCT qs.id FROM quiz_sessions qs WHERE qs.user_id = 24011010
)
LIMIT 1;

-- Cek draft_attempts raw data tanpa GROUP BY (untuk lihat duplikasi)
SELECT 
    da.id,
    da.user_id,
    da.session_id,
    da.question_id,
    da.is_correct,
    qs.title_id
FROM draft_attempts da
INNER JOIN quiz_sessions qs ON da.session_id = qs.id
WHERE da.user_id = 24011010 AND qs.title_id = 353
ORDER BY da.session_id, da.id
LIMIT 50;
