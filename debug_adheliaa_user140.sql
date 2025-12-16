-- Ah! User ID sebenarnya adalah 140, bukan 24011010!
-- Mari cek draft_attempts untuk user_id=140

SELECT 
    da.user_id,
    u.name,
    qs.title_id,
    qt.title,
    COUNT(DISTINCT da.id) as attempt_count,
    SUM(CASE WHEN da.is_correct = 1 THEN 1 ELSE 0 END) as correct_count
FROM draft_attempts da
INNER JOIN quiz_sessions qs ON da.session_id = qs.id
INNER JOIN quiz_titles qt ON qs.title_id = qt.id
LEFT JOIN users u ON da.user_id = u.id
WHERE da.user_id = 140
GROUP BY da.user_id, qs.title_id
LIMIT 20;

-- Lihat raw draft_attempts untuk user 140 (tanpa GROUP BY)
SELECT 
    da.id,
    da.user_id,
    da.session_id,
    da.question_id,
    da.is_correct,
    qs.title_id,
    COUNT(*) OVER (PARTITION BY da.question_id) as duplikat_count
FROM draft_attempts da
INNER JOIN quiz_sessions qs ON da.session_id = qs.id
WHERE da.user_id = 140
ORDER BY qs.title_id, da.session_id, da.id
LIMIT 100;

-- Cek quiz_titles 353 - berapa soal?
SELECT id, title, subtheme_id FROM quiz_titles WHERE id = 353 LIMIT 1;

-- Cek soal di quiz_session_questions untuk session user 140
SELECT DISTINCT qsq.question_id
FROM quiz_session_questions qsq
WHERE qsq.session_id IN (
    SELECT DISTINCT qs.id FROM quiz_sessions qs WHERE qs.user_id = 140
)
LIMIT 60;
