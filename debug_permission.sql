-- Cek user dengan email hakimberkahselalu@gmail.com
SELECT id, email, name FROM users WHERE email = 'hakimberkahselalu@gmail.com' LIMIT 1;

-- Cek assignment mode='exam' yang dibuat user ini (ganti ID sesuai hasil query di atas)
SELECT id, judul_tugas, id_pengajar, mode FROM assignments 
WHERE id_pengajar = (SELECT id FROM users WHERE email = 'hakimberkahselalu@gmail.com' LIMIT 1)
AND mode = 'exam'
LIMIT 10;

-- Cek semua assignment mode='exam' (siapa pembuat mereka?)
SELECT a.id, a.judul_tugas, a.id_pengajar, u.email, a.mode 
FROM assignments a
LEFT JOIN users u ON a.id_pengajar = u.id
WHERE a.mode = 'exam'
LIMIT 10;
