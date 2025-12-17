<?php
// actions/difficulty_titles.php

$pdo = pdo_instance();

// Ambil parameter UI
$metric = isset($_GET['metric']) ? strtolower(trim($_GET['metric'])) : 'count'; // 'count' | 'ratio'
if ($metric !== 'ratio') $metric = 'count';
$min = isset($_GET['min']) ? max(0, (int)$_GET['min']) : 10; // default min attempt utk fairness

// Query dasar: hitung salah & attempt per judul
// Kita hitung juga ratio = wrong/attempt (0 jika attempt=0)
$sqlBase = "
    SELECT
      qt.id,
      qt.title,
      COALESCE(SUM(CASE WHEN a.is_correct = 0 THEN 1 ELSE 0 END), 0) AS wrong_count,
      COALESCE(COUNT(a.id), 0) AS total_attempts,
      CASE WHEN COALESCE(COUNT(a.id),0) > 0
           THEN (COALESCE(SUM(CASE WHEN a.is_correct = 0 THEN 1 ELSE 0 END),0) / COALESCE(COUNT(a.id),0))
           ELSE 0 END AS wrong_ratio
    FROM quiz_titles qt
    LEFT JOIN questions q ON q.title_id = qt.id
    LEFT JOIN attempts  a ON a.question_id = q.id
    GROUP BY qt.id, qt.title
  ";

// Order & filter berdasarkan metric
if ($metric === 'ratio') {
    // Fairness: abaikan judul dengan attempt < $min
    $sql = $sqlBase . " HAVING total_attempts >= :min ORDER BY wrong_ratio DESC, total_attempts DESC, qt.title ASC";
    $st = $pdo->prepare($sql);
    $st->bindValue(':min', $min, PDO::PARAM_INT);
    $st->execute();
} else {
    $sql = $sqlBase . " ORDER BY wrong_count DESC, qt.title ASC";
    $st = $pdo->query($sql);
}
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// UI Toggle metric
$urlCount = '?page=difficulty&metric=count';
$urlRatio = '?page=difficulty&metric=ratio&min=' . $min;

// Tampilkan view
require 'views/difficulty_titles.php';
