-- Cek apa saja mode assignment yang ada
SELECT DISTINCT mode, COUNT(*) as total FROM assignments GROUP BY mode;

-- Lihat beberapa assignment yang ada
SELECT id, judul_tugas, id_judul_soal, id_kelas, mode, created_at FROM assignments LIMIT 20;

-- Cek quiz_titles yang digunakan di attempts
SELECT DISTINCT qs.title_id, qt.title, COUNT(DISTINCT att.session_id) as session_count
FROM attempts att
INNER JOIN quiz_sessions qs ON att.session_id = qs.id
INNER JOIN quiz_titles qt ON qs.title_id = qt.id
GROUP BY qs.title_id, qt.title
LIMIT 20;
