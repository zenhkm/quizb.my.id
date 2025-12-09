-- Script untuk menghapus semua data challenge
-- Gunakan di phpMyAdmin atau MySQL client

-- Hapus semua data dari tabel challenges
-- Karena ada ON DELETE CASCADE, tabel challenge_items dan challenge_runs
-- akan otomatis terhapus juga
TRUNCATE TABLE challenges;

-- Jika TRUNCATE tidak bekerja, gunakan DELETE:
-- DELETE FROM challenges;
-- DELETE FROM challenge_items;
-- DELETE FROM challenge_runs;

-- Verifikasi bahwa semua data telah dihapus
SELECT 'Challenges deleted' as status;
SELECT COUNT(*) as challenges_count FROM challenges;
SELECT COUNT(*) as challenge_items_count FROM challenge_items;
SELECT COUNT(*) as challenge_runs_count FROM challenge_runs;
