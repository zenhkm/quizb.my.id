-- Update assignment ID 66 dari mode 'exam' menjadi 'instant'
UPDATE assignments 
SET mode = 'instant'
WHERE id = 66 AND judul_tugas LIKE '%UTS GANJIL HADIS TARBAWI 2%';

-- Verifikasi perubahan
SELECT id, judul_tugas, id_judul_soal, id_kelas, mode FROM assignments WHERE id = 66;
