-- Cek berapa banyak sessions untuk user 140
SELECT 
    qs.id as session_id,
    qs.user_id,
    qs.title_id,
    qs.mode,
    qs.created_at,
    COUNT(DISTINCT da.id) as draft_count,
    COUNT(DISTINCT da.question_id) as unique_questions
FROM quiz_sessions qs
LEFT JOIN draft_attempts da ON qs.id = da.session_id
WHERE qs.user_id = 140 AND qs.title_id = 448
GROUP BY qs.id, qs.user_id, qs.title_id, qs.mode, qs.created_at
ORDER BY qs.created_at DESC;

-- Cek sessions terbaru untuk user 140
SELECT 
    qs.id,
    qs.created_at,
    COUNT(DISTINCT da.question_id) as unique_questions_in_session
FROM quiz_sessions qs
LEFT JOIN draft_attempts da ON qs.id = da.session_id
WHERE qs.user_id = 140 AND qs.title_id = 448
GROUP BY qs.id, qs.created_at
ORDER BY qs.created_at DESC
LIMIT 1;

-- Cek soal di session terbaru saja
SELECT COUNT(DISTINCT qsq.question_id) as total_questions_in_quiz
FROM quiz_session_questions qsq
WHERE qsq.session_id = (
    SELECT MAX(qs.id) FROM quiz_sessions qs 
    WHERE qs.user_id = 140 AND qs.title_id = 448
);
