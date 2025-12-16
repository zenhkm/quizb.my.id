-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Waktu pembuatan: 16 Des 2025 pada 19.24
-- Versi server: 11.4.8-MariaDB-cll-lve
-- Versi PHP: 8.4.15

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `quic1934_quizb`
--

-- --------------------------------------------------------

--
-- Struktur dari tabel `assignments`
--

CREATE TABLE `assignments` (
  `id` int(11) NOT NULL,
  `id_kelas` int(11) NOT NULL,
  `id_pengajar` int(11) NOT NULL,
  `id_judul_soal` int(11) NOT NULL,
  `judul_tugas` varchar(255) NOT NULL,
  `mode` enum('instant','end','exam','bebas') NOT NULL DEFAULT 'bebas',
  `batas_waktu` datetime DEFAULT NULL,
  `jumlah_soal` int(11) DEFAULT NULL,
  `timer_per_soal_detik` int(11) DEFAULT NULL,
  `durasi_ujian_menit` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `assignments`
--

INSERT INTO `assignments` (`id`, `id_kelas`, `id_pengajar`, `id_judul_soal`, `judul_tugas`, `mode`, `batas_waktu`, `jumlah_soal`, `timer_per_soal_detik`, `durasi_ujian_menit`, `created_at`) VALUES
(22, 16, 203, 345, 'Hadis Kasih Sayang dalam Mengajar - Pertemuan ke 5', 'instant', '2025-11-06 04:04:49', 7, 60, NULL, '2025-10-29 10:44:03'),
(25, 16, 203, 344, 'Hadis Kasih Sayang Dalam Mengajar (Faedah) - Pertemuan ke 5', 'instant', '2025-11-06 05:10:00', 7, 60, NULL, '2025-10-31 05:11:01'),
(26, 15, 203, 344, 'Hadis Kasih Sayang Dalam Mengajar (Faedah) - Pertemuan ke 4', 'instant', '2025-11-06 05:10:00', 7, 60, NULL, '2025-10-31 13:10:51'),
(27, 18, 203, 344, 'Hadis Kasih Sayang Dalam Mengajar (Faedah) - Pertemuan ke 4', 'instant', '2025-11-06 05:10:00', 7, 60, NULL, '2025-10-31 13:10:51'),
(28, 17, 203, 344, 'Hadis Kasih Sayang Dalam Mengajar (Faedah) - Pertemuan ke 4', 'instant', '2025-11-06 05:10:00', 7, 60, NULL, '2025-10-31 13:10:51'),
(29, 15, 203, 345, 'Hadis Kasih Sayang Dalam Mengajar - Pertemuan ke 4', 'instant', '2025-11-06 05:10:00', 7, 60, NULL, '2025-10-31 13:11:45'),
(30, 18, 203, 345, 'Hadis Kasih Sayang Dalam Mengajar - Pertemuan ke 4', 'instant', '2025-11-06 05:10:00', 7, 60, NULL, '2025-10-31 13:11:45'),
(31, 17, 203, 345, 'Hadis Kasih Sayang Dalam Mengajar - Pertemuan ke 4', 'instant', '2025-11-06 05:10:00', 7, 60, NULL, '2025-10-31 13:11:45'),
(46, 19, 203, 347, 'Hukum Islam BAB IV', 'instant', '2025-11-06 05:10:00', 7, 60, NULL, '2025-10-31 20:12:50'),
(47, 25, 203, 348, 'Try Out RPL 1 - PAI 1 (Fiqh)', 'instant', '2025-11-06 05:10:00', 2, 5, NULL, '2025-11-02 08:00:40'),
(48, 15, 203, 349, 'Try Out UTS', 'instant', '2025-11-06 05:10:00', 2, 5, NULL, '2025-11-02 08:32:09'),
(49, 18, 203, 349, 'Try Out UTS', 'instant', '2025-11-06 05:10:00', 2, 5, NULL, '2025-11-02 08:32:30'),
(50, 16, 203, 349, 'Try Out UTS', 'instant', '2025-11-06 05:10:00', 2, 5, NULL, '2025-11-02 08:32:52'),
(51, 17, 203, 349, 'Try Out UTS', 'instant', '2025-11-06 05:10:00', 2, 5, NULL, '2025-11-02 08:33:23'),
(52, 19, 203, 351, 'Try Out', 'instant', '2025-11-06 05:10:00', 2, 5, NULL, '2025-11-02 14:05:35'),
(57, 20, 203, 254, 'Pertemuan 1', 'instant', '2025-11-06 05:10:00', 7, 60, NULL, '2025-11-02 16:10:54'),
(58, 20, 203, 255, 'Pertemuan 2', 'instant', '2025-11-06 05:10:00', 7, 60, NULL, '2025-11-02 16:11:14'),
(60, 20, 203, 347, 'Pertemuan 4', 'instant', '2025-11-06 05:10:00', 7, 60, NULL, '2025-11-02 16:12:09'),
(61, 20, 203, 351, 'Try Out UTS', 'instant', '2025-11-06 05:10:00', 2, 5, NULL, '2025-11-02 16:12:29'),
(62, 20, 203, 270, 'Pertemuan 3', 'instant', '2025-11-06 05:10:00', 7, 60, NULL, '2025-11-02 16:24:32'),
(63, 25, 203, 352, 'UTS GANJIL', 'instant', NULL, 15, 20, NULL, '2025-11-03 07:44:58'),
(64, 15, 203, 353, 'UTS GANJIL HADIS TARBAWI 2', 'instant', NULL, 15, 20, NULL, '2025-11-04 11:28:13'),
(65, 18, 203, 353, 'UTS GANJIL HADIS TARBAWI 2', 'instant', NULL, 15, 20, NULL, '2025-11-04 11:29:03'),
(66, 16, 203, 353, 'UTS GANJIL HADIS TARBAWI 2', 'instant', NULL, 15, 20, NULL, '2025-11-04 11:30:01'),
(67, 17, 203, 353, 'UTS GANJIL HADIS TARBAWI 2', 'instant', NULL, 15, 20, NULL, '2025-11-04 11:33:21'),
(68, 19, 203, 354, 'UTS GANJIL Hukum Islam', 'instant', NULL, 15, 15, NULL, '2025-11-04 15:51:58'),
(69, 20, 203, 354, 'UTS GANJIL Hukum Islam', 'instant', NULL, 15, 15, NULL, '2025-11-04 15:52:31'),
(70, 27, 203, 355, 'UTS GANJIL Ulumul Hadis', 'instant', NULL, 15, NULL, NULL, '2025-11-06 21:09:26'),
(71, 25, 203, 109, 'Latihan Bab Muamalah', 'bebas', NULL, NULL, NULL, NULL, '2025-11-10 15:20:19'),
(72, 16, 203, 357, 'Metode Pengulangan dalam Mengajar (Kosakata) - Latihan 3', 'instant', NULL, NULL, NULL, NULL, '2025-11-12 10:06:08'),
(73, 18, 203, 357, 'Metode Pengulangan dalam Mengajar (Kosakata) - Latihan 3', 'instant', NULL, NULL, NULL, NULL, '2025-11-12 20:19:52'),
(74, 15, 203, 357, 'Metode Pengulangan dalam Mengajar (Kosakata) - Latihan 3', 'instant', NULL, NULL, NULL, NULL, '2025-11-12 20:22:14'),
(79, 17, 203, 357, 'Metode Pengulangan dalam Mengajar (Kosakata) - Latihan 3', 'instant', NULL, NULL, NULL, NULL, '2025-11-13 08:02:10'),
(80, 15, 203, 399, 'Metode Pengulangan dalam Mengajar (Melanjutkan) - Latihan 2', 'instant', NULL, NULL, NULL, NULL, '2025-11-13 08:02:38'),
(81, 18, 203, 399, 'Metode Pengulangan dalam Mengajar (Melanjutkan) - Latihan 2', 'instant', NULL, NULL, NULL, NULL, '2025-11-13 08:02:38'),
(82, 16, 203, 399, 'Metode Pengulangan dalam Mengajar (Melanjutkan) - Latihan 2', 'instant', NULL, NULL, NULL, NULL, '2025-11-13 08:02:38'),
(83, 17, 203, 399, 'Metode Pengulangan dalam Mengajar (Melanjutkan) - Latihan 2', 'instant', NULL, NULL, NULL, NULL, '2025-11-13 08:02:38'),
(84, 15, 203, 400, 'Metode Pengulangan dalam Mengajar (Terjemah) - Latihan 1', 'instant', NULL, NULL, NULL, NULL, '2025-11-13 08:03:06'),
(85, 18, 203, 400, 'Metode Pengulangan dalam Mengajar (Terjemah) - Latihan 1', 'instant', NULL, NULL, NULL, NULL, '2025-11-13 08:03:06'),
(86, 16, 203, 400, 'Metode Pengulangan dalam Mengajar (Terjemah) - Latihan 1', 'instant', NULL, NULL, NULL, NULL, '2025-11-13 08:03:06'),
(87, 17, 203, 400, 'Metode Pengulangan dalam Mengajar (Terjemah)  - Latihan 1', 'instant', NULL, NULL, NULL, NULL, '2025-11-13 08:03:06'),
(88, 19, 203, 401, 'Latihan BAB V - QIYAS', 'instant', NULL, NULL, NULL, NULL, '2025-11-14 10:39:03'),
(89, 20, 203, 401, 'Latihan BAB V - QIYAS', 'instant', NULL, NULL, NULL, NULL, '2025-11-14 10:39:03'),
(90, 19, 203, 435, 'Latihan BAB VI - Metode Istinbath', 'instant', NULL, NULL, NULL, NULL, '2025-11-18 18:53:24'),
(91, 16, 203, 436, 'Latihan 1 (Metode Tanya Jawab - Kosakata)', 'instant', NULL, NULL, NULL, NULL, '2025-11-19 08:12:42'),
(92, 15, 203, 436, 'Latihan 1 (Metode Tanya Jawab - Kosakata)', 'instant', NULL, NULL, NULL, NULL, '2025-11-20 09:08:44'),
(93, 18, 203, 436, 'Latihan 1 (Metode Tanya Jawab - Kosakata)', 'instant', NULL, NULL, NULL, NULL, '2025-11-20 09:08:44'),
(94, 17, 203, 436, 'Latihan 1 (Metode Tanya Jawab - Kosakata)', 'instant', NULL, NULL, NULL, NULL, '2025-11-20 09:08:44'),
(95, 15, 203, 438, 'Latihan 2 (Metode Tanya Jawab - Melanjutkan dan Terjemah)', 'instant', NULL, NULL, NULL, NULL, '2025-11-20 09:32:28'),
(96, 18, 203, 438, 'Latihan 2 (Metode Tanya Jawab - Melanjutkan dan Terjemah)', 'instant', NULL, NULL, NULL, NULL, '2025-11-20 09:32:28'),
(97, 16, 203, 438, 'Latihan 2 (Metode Tanya Jawab - Melanjutkan dan Terjemah)', 'instant', NULL, NULL, NULL, NULL, '2025-11-20 09:32:28'),
(98, 17, 203, 438, 'Latihan 2 (Metode Tanya Jawab - Melanjutkan dan Terjemah)', 'instant', NULL, NULL, NULL, NULL, '2025-11-20 09:32:28'),
(99, 19, 203, 440, 'Soal BAB VII', 'instant', NULL, NULL, NULL, NULL, '2025-11-25 10:47:40'),
(100, 20, 203, 440, 'Soal BAB VII', 'instant', NULL, NULL, NULL, NULL, '2025-11-25 10:47:40'),
(101, 16, 203, 442, 'Tugas Pertemuan ke 9', 'instant', NULL, NULL, NULL, NULL, '2025-12-03 16:13:54'),
(102, 16, 203, 443, 'Tugas Pertemuan ke 10', 'instant', NULL, NULL, NULL, NULL, '2025-12-03 20:59:15'),
(103, 19, 203, 444, 'Tugas Pertemuan ke 10', 'instant', NULL, NULL, NULL, NULL, '2025-12-03 22:28:40'),
(104, 15, 203, 442, 'Tugas Pertemuan ke 9', 'instant', NULL, NULL, NULL, NULL, '2025-12-04 08:05:27'),
(105, 18, 203, 442, 'Tugas Pertemuan ke 9', 'instant', NULL, NULL, NULL, NULL, '2025-12-04 08:05:27'),
(106, 17, 203, 442, 'Tugas Pertemuan ke 9', 'instant', NULL, NULL, NULL, NULL, '2025-12-04 08:05:27'),
(107, 15, 203, 443, 'Tugas Pertemuan ke 10', 'instant', NULL, NULL, NULL, NULL, '2025-12-04 08:05:57'),
(108, 18, 203, 443, 'Tugas Pertemuan ke 10', 'instant', NULL, NULL, NULL, NULL, '2025-12-04 08:05:57'),
(109, 17, 203, 443, 'Tugas Pertemuan ke 10', 'instant', NULL, NULL, NULL, NULL, '2025-12-04 08:05:57'),
(110, 20, 203, 435, 'Tugas BAB VI', 'instant', NULL, NULL, NULL, NULL, '2025-12-05 04:50:06'),
(111, 25, 203, 445, 'Latihan Fiqh Munakahat', 'instant', NULL, NULL, NULL, NULL, '2025-12-08 15:37:33'),
(112, 19, 203, 446, 'Soal Pertemuan ke 11', 'instant', NULL, NULL, NULL, NULL, '2025-12-09 07:07:07'),
(113, 16, 203, 447, 'Tugas Pertemuan 11', 'bebas', NULL, NULL, NULL, NULL, '2025-12-10 09:25:28'),
(114, 15, 203, 447, 'Latihan Pertemuan 11', 'instant', NULL, NULL, NULL, NULL, '2025-12-11 05:43:58'),
(115, 18, 203, 447, 'Latihan Pertemuan 11', 'instant', NULL, NULL, NULL, NULL, '2025-12-11 05:43:58'),
(116, 17, 203, 447, 'Latihan Pertemuan 11', 'instant', NULL, NULL, NULL, NULL, '2025-12-11 05:43:58'),
(117, 19, 203, 448, 'Latihan Hukum Islam I', 'exam', NULL, 50, NULL, 30, '2025-12-16 08:35:44');

--
-- Indexes for dumped tables
--

--
-- Indeks untuk tabel `assignments`
--
ALTER TABLE `assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_kelas` (`id_kelas`);

--
-- AUTO_INCREMENT untuk tabel yang dibuang
--

--
-- AUTO_INCREMENT untuk tabel `assignments`
--
ALTER TABLE `assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=118;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
