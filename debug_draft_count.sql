-- Cek apa yang terjadi dengan data draft untuk user 140
-- COUNT DISTINCT question_id vs SUM is_correct

SELECT 
    da.user_id,
    qs.title_id,
    COUNT(DISTINCT da.question_id) as unique_questions,
    COUNT(*) as total_draft_records,
    SUM(da.is_correct) as total_correct_all_records,
    SUM(CASE WHEN da.is_correct = 1 THEN 1 ELSE 0 END) as correct_count_all,
    COUNT(DISTINCT CASE WHEN da.is_correct = 1 THEN da.question_id END) as unique_correct_questions
FROM draft_attempts da
INNER JOIN quiz_sessions qs ON da.session_id = qs.id
WHERE da.user_id = 140 AND da.status = 'draft'
GROUP BY da.user_id, qs.title_id;

-- Lihat per-question untuk user 140
-- Kita ambil draft TERAKHIR (updated_at terbaru) untuk setiap question
SELECT 
    da.question_id,
    MAX(da.updated_at) as latest_update,
    MAX(da.is_correct) as latest_is_correct
FROM draft_attempts da
WHERE da.user_id = 140 AND da.status = 'draft'
GROUP BY da.question_id
LIMIT 10;
