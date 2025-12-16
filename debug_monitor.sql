-- ================================================================
-- DEBUG QUERIES - Cari tahu kenapa monitor masih kosong
-- ================================================================

-- 1. Cek apakah ada assignment dengan mode='ujian'
SELECT COUNT(*) as total_ujian FROM assignments WHERE mode = 'ujian';

-- 2. Jika ada, lihat detailnya
SELECT id, judul_tugas, id_judul_soal, id_kelas, mode FROM assignments WHERE mode = 'ujian' LIMIT 5;

-- 3. Cek data di quiz_titles (soal yang digunakan assignment)
SELECT qt.id, qt.title FROM quiz_titles qt 
WHERE qt.id IN (SELECT id_judul_soal FROM assignments WHERE mode = 'ujian')
LIMIT 5;

-- 4. Cek class_members (siswa di kelas)
SELECT COUNT(*) as total_members FROM class_members;

-- 5. Lihat class_members yang terkait assignment ujian
SELECT DISTINCT cm.id_pelajar, cm.id_kelas, u.name
FROM class_members cm
LEFT JOIN users u ON cm.id_pelajar = u.id
WHERE cm.id_kelas IN (SELECT DISTINCT id_kelas FROM assignments WHERE mode = 'ujian')
LIMIT 20;

-- 6. Cek attempts yang ada
SELECT COUNT(*) as total_attempts FROM attempts;

-- 7. Lihat sample attempts dengan quiz_sessions
SELECT att.id, att.session_id, qs.user_id, qs.title_id, u.name
FROM attempts att
INNER JOIN quiz_sessions qs ON att.session_id = qs.id
LEFT JOIN users u ON qs.user_id = u.id
WHERE qs.user_id IS NOT NULL
LIMIT 20;

-- 8. Cek apakah ada attempts untuk siswa yang ada di kelas ujian
SELECT DISTINCT qs.user_id, qs.title_id, COUNT(att.id) as attempt_count
FROM attempts att
INNER JOIN quiz_sessions qs ON att.session_id = qs.id
WHERE qs.user_id IS NOT NULL
  AND qs.title_id IN (SELECT id_judul_soal FROM assignments WHERE mode = 'ujian')
  AND qs.user_id IN (SELECT id_pelajar FROM class_members 
                      WHERE id_kelas IN (SELECT id_kelas FROM assignments WHERE mode = 'ujian'))
GROUP BY qs.user_id, qs.title_id
LIMIT 20;

-- 9. SIMPLE TEST - Gabungkan class_members + assignments + attempts
SELECT 
    cm.id_pelajar,
    u.name,
    a.id AS assignment_id,
    a.judul_tugas,
    COUNT(DISTINCT att.id) as attempt_count
FROM class_members cm
INNER JOIN users u ON cm.id_pelajar = u.id
INNER JOIN assignments a ON a.id_kelas = cm.id_kelas
LEFT JOIN attempts att ON (
    SELECT qs.user_id = cm.id_pelajar 
    AND qs.title_id = a.id_judul_soal
    FROM quiz_sessions qs
    WHERE att.session_id = qs.id
)
WHERE a.mode = 'ujian'
GROUP BY cm.id_pelajar, a.id
LIMIT 20;

-- 10. BETTER TEST - Dengan subquery untuk attempts
SELECT 
    cm.id_pelajar,
    u.name,
    a.id AS assignment_id,
    a.judul_tugas,
    COALESCE(ha.attempt_count, 0) as attempt_count,
    CASE
        WHEN asub.id IS NOT NULL THEN 'Sudah Submit'
        WHEN ha.attempt_count > 0 THEN 'Sedang Mengerjakan'
        ELSE 'Belum Submit'
    END as status
FROM class_members cm
INNER JOIN users u ON cm.id_pelajar = u.id
INNER JOIN assignments a ON a.id_kelas = cm.id_kelas
LEFT JOIN assignment_submissions asub ON a.id = asub.assignment_id AND cm.id_pelajar = asub.user_id
LEFT JOIN (
    SELECT 
        qs.user_id,
        qs.title_id,
        COUNT(DISTINCT att.id) as attempt_count
    FROM attempts att
    INNER JOIN quiz_sessions qs ON att.session_id = qs.id
    WHERE qs.user_id IS NOT NULL
    GROUP BY qs.user_id, qs.title_id
) ha ON cm.id_pelajar = ha.user_id AND a.id_judul_soal = ha.title_id
WHERE a.mode = 'ujian'
LIMIT 50;
