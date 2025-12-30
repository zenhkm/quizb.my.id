<?php
// CLI utility: migrate legacy `tb_soal` rows into QuizB tables.
// Usage examples:
//   php tools/import_tb_soal_to_quizb.php --kategori=bahasa --theme-name="Pengetahuan Bahasa"
//   php tools/import_tb_soal_to_quizb.php --all
// Optional:
//   --owner-user-id=123   (set ownership for created theme/subtheme/title/questions)
//   --correct-default=A|B|none   (default when rule can't be inferred; default: none)
//   --limit=500           (for testing)
//   --dry-run             (no writes)

require_once __DIR__ . '/../includes/functions.php';

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "This script must be run from CLI.\n");
  exit(1);
}

$opts = getopt('', [
  'kategori::',
  'theme-name::',
  'all',
  'owner-user-id::',
  'correct-default::',
  'limit::',
  'dry-run',
]);

$importAll = array_key_exists('all', $opts);
$kategoriFilter = $importAll ? null : (isset($opts['kategori']) ? (string)$opts['kategori'] : 'bahasa');
$themeName = isset($opts['theme-name']) ? (string)$opts['theme-name'] : null;
$ownerUserId = isset($opts['owner-user-id']) && $opts['owner-user-id'] !== '' ? (int)$opts['owner-user-id'] : null;
$correctDefault = isset($opts['correct-default']) ? strtolower(trim((string)$opts['correct-default'])) : 'none';
$limit = isset($opts['limit']) && $opts['limit'] !== '' ? max(1, (int)$opts['limit']) : null;
$dryRun = array_key_exists('dry-run', $opts);

if (!$importAll && ($kategoriFilter === null || $kategoriFilter === '')) {
  fwrite(STDERR, "Missing --kategori (or use --all).\n");
  exit(1);
}

if (!in_array($correctDefault, ['a', 'b', 'none'], true)) {
  fwrite(STDERR, "Invalid --correct-default. Allowed: A|B|none\n");
  exit(1);
}

if (function_exists('ensure_soft_delete_schema')) {
  ensure_soft_delete_schema();
}

$pdo = pdo();

// Ensure legacy table exists.
$hasLegacy = (bool)q("SHOW TABLES LIKE 'tb_soal'")->fetchColumn();
if (!$hasLegacy) {
  fwrite(STDERR, "Table `tb_soal` not found in current DB.\n");
  fwrite(STDERR, "Import backup/tb_soal.sql into your database first (phpMyAdmin or mysql CLI).\n");
  exit(1);
}

function norm_name(string $s): string {
  $s = trim($s);
  $s = preg_replace('/\s+/', ' ', $s);
  return $s;
}

function infer_correct(string $ruleRaw, string $choiceA, string $choiceB): ?string {
  $rule = strtolower(trim($ruleRaw));
  if ($rule === '') return null;

  // Common patterns
  if (in_array($rule, ['a', 'pilihan a', 'opsi a', 'option a', '1'], true)) return 'a';
  if (in_array($rule, ['b', 'pilihan b', 'opsi b', 'option b', '2'], true)) return 'b';

  // Sometimes rule contains the exact correct text
  $a = strtolower(trim($choiceA));
  $b = strtolower(trim($choiceB));
  if ($a !== '' && $rule === $a) return 'a';
  if ($b !== '' && $rule === $b) return 'b';

  return null;
}

function ensure_theme(PDO $pdo, string $name, ?int $ownerUserId): int {
  $name = norm_name($name);
  if ($name === '') throw new RuntimeException('Theme name empty');

  $sql = "SELECT id FROM themes WHERE name = ? AND ((owner_user_id IS NULL AND ? IS NULL) OR owner_user_id = ?)";
  // deleted_at might exist (newer). Filter it if present.
  try {
    $sql .= " AND deleted_at IS NULL";
    $st = $pdo->prepare($sql);
    $st->execute([$name, $ownerUserId, $ownerUserId]);
  } catch (Throwable $e) {
    $st = $pdo->prepare("SELECT id FROM themes WHERE name = ? AND ((owner_user_id IS NULL AND ? IS NULL) OR owner_user_id = ?)");
    $st->execute([$name, $ownerUserId, $ownerUserId]);
  }

  $id = $st->fetchColumn();
  if ($id) return (int)$id;

  $ins = $pdo->prepare('INSERT INTO themes (name, owner_user_id) VALUES (?, ?)');
  $ins->execute([$name, $ownerUserId]);
  return (int)$pdo->lastInsertId();
}

function ensure_subtheme(PDO $pdo, int $themeId, string $name, ?int $ownerUserId): int {
  $name = norm_name($name);
  if ($name === '') $name = '(Tanpa Subtema)';

  $sql = "SELECT id FROM subthemes WHERE theme_id = ? AND name = ? AND ((owner_user_id IS NULL AND ? IS NULL) OR owner_user_id = ?)";
  try {
    $sql .= " AND deleted_at IS NULL";
    $st = $pdo->prepare($sql);
    $st->execute([$themeId, $name, $ownerUserId, $ownerUserId]);
  } catch (Throwable $e) {
    $st = $pdo->prepare("SELECT id FROM subthemes WHERE theme_id = ? AND name = ? AND ((owner_user_id IS NULL AND ? IS NULL) OR owner_user_id = ?)");
    $st->execute([$themeId, $name, $ownerUserId, $ownerUserId]);
  }

  $id = $st->fetchColumn();
  if ($id) return (int)$id;

  $ins = $pdo->prepare('INSERT INTO subthemes (theme_id, owner_user_id, name) VALUES (?, ?, ?)');
  $ins->execute([$themeId, $ownerUserId, $name]);
  return (int)$pdo->lastInsertId();
}

function ensure_title(PDO $pdo, int $subthemeId, string $title, ?int $ownerUserId): int {
  $title = norm_name($title);
  if ($title === '') $title = '(Tanpa Judul)';

  $sql = "SELECT id FROM quiz_titles WHERE subtheme_id = ? AND title = ? AND ((owner_user_id IS NULL AND ? IS NULL) OR owner_user_id = ?)";
  try {
    $sql .= " AND deleted_at IS NULL";
    $st = $pdo->prepare($sql);
    $st->execute([$subthemeId, $title, $ownerUserId, $ownerUserId]);
  } catch (Throwable $e) {
    $st = $pdo->prepare("SELECT id FROM quiz_titles WHERE subtheme_id = ? AND title = ? AND ((owner_user_id IS NULL AND ? IS NULL) OR owner_user_id = ?)");
    $st->execute([$subthemeId, $title, $ownerUserId, $ownerUserId]);
  }

  $id = $st->fetchColumn();
  if ($id) return (int)$id;

  $ins = $pdo->prepare('INSERT INTO quiz_titles (subtheme_id, owner_user_id, title) VALUES (?, ?, ?)');
  $ins->execute([$subthemeId, $ownerUserId, $title]);
  return (int)$pdo->lastInsertId();
}

function ensure_question(PDO $pdo, int $titleId, string $text, ?string $explanation, ?int $ownerUserId): int {
  $text = trim($text);
  if ($text === '') throw new RuntimeException('Question text empty');

  $ins = $pdo->prepare('INSERT INTO questions (title_id, owner_user_id, text, explanation) VALUES (?, ?, ?, ?)');
  $ins->execute([$titleId, $ownerUserId, $text, $explanation]);
  return (int)$pdo->lastInsertId();
}

function insert_choice(PDO $pdo, int $questionId, string $text, bool $isCorrect): void {
  $text = trim($text);
  if ($text === '') return;

  $ins = $pdo->prepare('INSERT INTO choices (question_id, text, is_correct) VALUES (?, ?, ?)');
  $ins->execute([$questionId, $text, $isCorrect ? 1 : 0]);
}

// Default theme name if not given.
if ($themeName === null) {
  if ($importAll) {
    $themeName = null; // per kategori
  } else {
    $themeName = ($kategoriFilter === 'bahasa') ? 'Pengetahuan Bahasa' : (ucwords(str_replace('_', ' ', $kategoriFilter)) ?: $kategoriFilter);
  }
}

// Cache to reduce DB queries.
$themeCache = [];
$subthemeCache = []; // [themeId|subName] => id
$titleCache = [];    // [subthemeId|title] => id

$where = '';
$params = [];
if (!$importAll) {
  $where = 'WHERE kategori = ?';
  $params[] = $kategoriFilter;
}

$sql = "SELECT id, mapel, jenis, no_soal, soal, a, b, rule, kategori FROM tb_soal $where ORDER BY kategori, mapel, jenis, CAST(no_soal AS UNSIGNED), id";
if ($limit !== null) {
  $sql .= ' LIMIT ' . (int)$limit;
}

$st = $pdo->prepare($sql);
$st->execute($params);

$totalRows = 0;
$insertedQuestions = 0;
$insertedChoices = 0;
$unknownCorrect = 0;

if (!$dryRun) {
  $pdo->beginTransaction();
}

try {
  while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $totalRows++;

    $kategori = strtolower(trim((string)$row['kategori']));
    $mapel = (string)$row['mapel'];
    $jenis = (string)$row['jenis'];
    $soal = (string)$row['soal'];
    $optA = (string)$row['a'];
    $optB = (string)$row['b'];
    $rule = (string)$row['rule'];

    if ($importAll) {
      $tName = ($kategori === 'bahasa') ? 'Pengetahuan Bahasa' : (ucwords(str_replace('_', ' ', $kategori)) ?: '(Tanpa Kategori)');
    } else {
      $tName = $themeName;
    }

    if (!isset($themeCache[$tName])) {
      $themeCache[$tName] = $dryRun ? 0 : ensure_theme($pdo, $tName, $ownerUserId);
    }
    $themeId = $themeCache[$tName];

    $subKey = $themeId . '|' . norm_name($mapel);
    if (!isset($subthemeCache[$subKey])) {
      $subthemeCache[$subKey] = $dryRun ? 0 : ensure_subtheme($pdo, $themeId, $mapel, $ownerUserId);
    }
    $subthemeId = $subthemeCache[$subKey];

    $titleKey = $subthemeId . '|' . norm_name($jenis);
    if (!isset($titleCache[$titleKey])) {
      $titleCache[$titleKey] = $dryRun ? 0 : ensure_title($pdo, $subthemeId, $jenis, $ownerUserId);
    }
    $titleId = $titleCache[$titleKey];

    // Explanation: keep rule if it looks meaningful.
    $explanation = null;
    $ruleTrim = trim($rule);
    if ($ruleTrim !== '' && strtolower($ruleTrim) !== 'admin') {
      $explanation = $ruleTrim;
    }

    if ($dryRun) {
      continue;
    }

    $questionId = ensure_question($pdo, $titleId, $soal, $explanation, $ownerUserId);
    $insertedQuestions++;

    $correct = infer_correct($rule, $optA, $optB);
    if ($correct === null) {
      $unknownCorrect++;
      if ($correctDefault === 'a') $correct = 'a';
      if ($correctDefault === 'b') $correct = 'b';
    }

    $isA = ($correct === 'a');
    $isB = ($correct === 'b');

    if (trim($optA) !== '') {
      insert_choice($pdo, $questionId, $optA, $isA);
      $insertedChoices++;
    }
    if (trim($optB) !== '') {
      insert_choice($pdo, $questionId, $optB, $isB);
      $insertedChoices++;
    }
  }

  if (!$dryRun) {
    $pdo->commit();
  }
} catch (Throwable $e) {
  if (!$dryRun && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  fwrite(STDERR, "Import failed: {$e->getMessage()}\n");
  exit(1);
}

echo "Done. rows_scanned=$totalRows" . ($dryRun ? " (dry-run)" : "") . "\n";
if (!$dryRun) {
  echo "inserted_questions=$insertedQuestions inserted_choices=$insertedChoices unknown_correct=$unknownCorrect\n";
  if ($unknownCorrect > 0) {
    echo "NOTE: Many rows have empty/unknown `rule` so correct answers may be unset (or defaulted).\n";
    echo "      You can edit correct answers later in QManage.\n";
  }
}
