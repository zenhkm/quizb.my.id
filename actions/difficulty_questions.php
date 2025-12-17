<?php
// actions/difficulty_questions.php

$pdo = pdo_instance();

$title_id = isset($_GET['title_id']) ? (int)$_GET['title_id'] : 0;

if ($title_id <= 0) {
    echo '<div class="container"><div class="alert alert-warning">Judul tidak ditemukan.</div></div>';
    return;
}

// Info judul
$st = $pdo->prepare("SELECT id, title FROM quiz_titles WHERE id = ?");
$st->execute([$title_id]);
$title = $st->fetch(PDO::FETCH_ASSOC);
if (!$title) {
    echo '<div class="container"><div class="alert alert-warning">Judul tidak ditemukan.</div></div>';
    return;
}

// Ambil parameter UI
$metric = isset($_GET['metric']) ? strtolower(trim($_GET['metric'])) : 'count'; // 'count' | 'ratio'
if ($metric !== 'ratio') $metric = 'count';
$min = isset($_GET['min']) ? max(0, (int)$_GET['min']) : 10;

// Query dasar per soal
$sqlBase = "
    SELECT
      q.id,
      q.text,
      COALESCE(SUM(CASE WHEN a.is_correct = 0 THEN 1 ELSE 0 END), 0) AS wrong_count,
      COALESCE(COUNT(a.id), 0) AS total_attempts,
      CASE WHEN COALESCE(COUNT(a.id),0) > 0
           THEN (COALESCE(SUM(CASE WHEN a.is_correct = 0 THEN 1 ELSE 0 END),0) / COALESCE(COUNT(a.id),0))
           ELSE 0 END AS wrong_ratio
    FROM questions q
    LEFT JOIN attempts a ON a.question_id = q.id
    WHERE q.title_id = :tid
    GROUP BY q.id, q.text
  ";

if ($metric === 'ratio') {
    $sql = $sqlBase . " HAVING total_attempts >= :min ORDER BY wrong_ratio DESC, total_attempts DESC, q.id ASC";
    $st = $pdo->prepare($sql);
    $st->bindValue(':tid', $title_id, PDO::PARAM_INT);
    $st->bindValue(':min', $min, PDO::PARAM_INT);
    $st->execute();
} else {
    $sql = $sqlBase . " ORDER BY wrong_count DESC, q.id ASC";
    $st = $pdo->prepare($sql);
    $st->bindValue(':tid', $title_id, PDO::PARAM_INT);
    $st->execute();
}
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// UI Toggle metric (tetap di halaman ini, ganti metric)
$qsCount = http_build_query(['page' => 'difficulty_questions', 'title_id' => $title_id, 'metric' => 'count']);
$qsRatio = http_build_query(['page' => 'difficulty_questions', 'title_id' => $title_id, 'metric' => 'ratio', 'min' => $min]);

$urlCount = '?' . $qsCount;
$urlRatio = '?' . $qsRatio;

// Tampilkan view
require 'views/difficulty_questions.php';
