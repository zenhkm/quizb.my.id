-- Cek UTS Ganjil Tarbawi
SELECT id, judul_tugas, id_judul_soal, id_kelas, mode FROM assignments 
WHERE judul_tugas LIKE '%UTS%' OR judul_tugas LIKE '%Tarbawi%'
LIMIT 20;

-- Cek assignment mode exam vs bukan
SELECT a.id, a.judul_tugas, a.mode, COUNT(DISTINCT cm.id_pelajar) as siswa_terdaftar
FROM assignments a
LEFT JOIN class_members cm ON a.id_kelas = cm.id_kelas
GROUP BY a.id, a.judul_tugas, a.mode
ORDER BY a.mode, a.id;

-- Cek data di draft_attempts untuk UTS Ganjil Tarbawi
SELECT da.id, da.user_id, da.question_id, qs.title_id
FROM draft_attempts da
INNER JOIN quiz_sessions qs ON da.session_id = qs.id
WHERE qs.title_id IN (
    SELECT id_judul_soal FROM assignments 
    WHERE judul_tugas LIKE '%UTS%' OR judul_tugas LIKE '%Tarbawi%'
)
LIMIT 10;
