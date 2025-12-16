-- ================================================================
-- TEST QUERY UNTUK DEBUG MONITOR JAWABAN
-- ================================================================
-- Jalankan query ini di database Anda untuk troubleshoot

-- 1. Cek apakah ada assignment dengan mode='ujian'
SELECT COUNT(*) as total_ujian_assignment FROM assignments WHERE mode = 'ujian';

-- 2. Lihat detail assignment mode ujian
SELECT 
    a.id,
    a.judul_tugas,
    a.mode,
    a.id_kelas,
    COUNT(cm.id_pelajar) as total_siswa
FROM assignments a
LEFT JOIN class_members cm ON a.id_kelas = cm.id_kelas
WHERE a.mode = 'ujian'
GROUP BY a.id, a.judul_tugas, a.mode, a.id_kelas
LIMIT 20;

-- 3. Cek apakah ada data di draft_attempts
SELECT COUNT(*) as total_draft FROM draft_attempts WHERE status = 'draft';

-- 4. Lihat contoh data draft_attempts
SELECT 
    da.id,
    da.user_id,
    u.name as user_name,
    da.question_id,
    da.status,
    da.updated_at
FROM draft_attempts da
LEFT JOIN users u ON da.user_id = u.id
WHERE da.status = 'draft'
LIMIT 10;

-- 5. Cek data di assignment_submissions (sudah submit)
SELECT COUNT(*) as total_submitted FROM assignment_submissions;

-- 6. Lihat contoh assignment_submissions
SELECT 
    asub.id,
    asub.user_id,
    u.name,
    asub.assignment_id,
    asub.submitted_at,
    r.score
FROM assignment_submissions asub
LEFT JOIN users u ON asub.user_id = u.id
LEFT JOIN results r ON asub.result_id = r.id
LIMIT 10;

-- 7. Cek class_members (student-class relationship)
SELECT COUNT(*) as total_members FROM class_members;

-- 8. Lihat class_members yang terkait assignment ujian
SELECT DISTINCT
    cm.id_pelajar,
    u.name,
    cm.id_kelas,
    COUNT(*) as count_records
FROM class_members cm
LEFT JOIN users u ON cm.id_pelajar = u.id
WHERE cm.id_kelas IN (SELECT DISTINCT id_kelas FROM assignments WHERE mode = 'ujian')
GROUP BY cm.id_pelajar, u.name, cm.id_kelas
LIMIT 20;

-- 9. SIMPLIFIED MONITOR QUERY - Test dengan data yang ada
-- (Versi SIMPLIFIED tanpa draft untuk cek apakah basic query bekerja)
SELECT
    cm.id_pelajar,
    u.name AS user_name,
    a.id AS assignment_id,
    a.judul_tugas,
    COUNT(DISTINCT asub.id) as submitted_count,
    COUNT(DISTINCT da.id) as draft_count
FROM class_members cm
INNER JOIN users u ON cm.id_pelajar = u.id
INNER JOIN assignments a ON a.id_kelas = cm.id_kelas
LEFT JOIN assignment_submissions asub ON a.id = asub.assignment_id AND cm.id_pelajar = asub.user_id
LEFT JOIN draft_attempts da ON da.user_id = cm.id_pelajar AND da.status = 'draft'
WHERE a.mode = 'ujian'
GROUP BY cm.id_pelajar, a.id
LIMIT 20;

-- 10. Full simplified monitor query (untuk immediate testing)
SELECT
    cm.id_pelajar AS user_id,
    u.name AS user_name,
    u.nama_sekolah,
    u.nama_kelas,
    a.id AS assignment_id,
    a.judul_tugas,
    CASE 
        WHEN asub.id IS NOT NULL THEN 'Sudah Submit'
        WHEN da.user_id IS NOT NULL THEN 'Sedang Mengerjakan'
        ELSE 'Belum Submit'
    END AS status,
    asub.submitted_at,
    r.score
FROM class_members cm
INNER JOIN users u ON cm.id_pelajar = u.id
INNER JOIN assignments a ON a.id_kelas = cm.id_kelas
LEFT JOIN assignment_submissions asub ON a.id = asub.assignment_id AND cm.id_pelajar = asub.user_id
LEFT JOIN results r ON asub.result_id = r.id
LEFT JOIN (
    SELECT DISTINCT user_id
    FROM draft_attempts 
    WHERE status = 'draft'
) da ON da.user_id = cm.id_pelajar
WHERE a.mode = 'ujian'
ORDER BY a.id, cm.id_pelajar
LIMIT 50;
