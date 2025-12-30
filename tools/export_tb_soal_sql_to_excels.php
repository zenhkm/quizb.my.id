<?php
// CLI utility: convert legacy SQL dump (backup/tb_soal.sql) into QuizB Import Excels.
// It does NOT touch the database.
//
// Default behavior:
//   - Reads backup/tb_soal.sql
//   - Filters kategori=bahasa
//   - Outputs one .xlsx per (mapel, jenis)
//   - Sets correct answer to A for every row
//
// Usage examples:
//   php tools/export_tb_soal_sql_to_excels.php
//   php tools/export_tb_soal_sql_to_excels.php --kategori=bahasa --out=exports/bahasa
//   php tools/export_tb_soal_sql_to_excels.php --all --out=exports/all
//
// Notes:
// - Columns match the current Import Template (A..H):
//   A Pertanyaan, B Pilihan A, C Pilihan B, D Pilihan C, E Pilihan D, F Pilihan E, G Jawaban, H Penjelasan

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "This script must be run from CLI.\n");
  exit(1);
}

$opts = getopt('', [
  'in::',
  'out::',
  'kategori::',
  'mapel::',
  'sub-kategori::',
  'all',
  'correct::',
  'limit::',
]);

$inFile = isset($opts['in']) && $opts['in'] !== '' ? (string)$opts['in'] : __DIR__ . '/../backup/tb_soal.sql';
$outDir = isset($opts['out']) && $opts['out'] !== '' ? (string)$opts['out'] : (__DIR__ . '/../exports/tb_soal_excels_' . date('Ymd_His'));
$importAll = array_key_exists('all', $opts);
$kategoriFilter = $importAll ? null : (isset($opts['kategori']) && $opts['kategori'] !== '' ? strtolower(trim((string)$opts['kategori'])) : 'bahasa');
$mapelFilter = isset($opts['mapel']) && $opts['mapel'] !== '' ? strtolower(trim((string)$opts['mapel'])) : null;

// Specific search terms for problematic categories (Arabic, Korean, Mandarin)
// Will be checked against mapel and jenis if original kategori is 'bahasa'
$subKategoriSearch = [];
if (isset($opts['sub-kategori']) && $opts['sub-kategori'] !== '') {
  $subKategoriSearch = array_map('strtolower', array_map('trim', explode(',', (string)$opts['sub-kategori'])));
}

$correct = isset($opts['correct']) && $opts['correct'] !== '' ? strtoupper(trim((string)$opts['correct'])) : 'A';
$limit = isset($opts['limit']) && $opts['limit'] !== '' ? max(1, (int)$opts['limit']) : null;

if (!in_array($correct, ['A','B','C','D','E'], true)) {
  fwrite(STDERR, "Invalid --correct. Allowed: A/B/C/D/E\n");
  exit(1);
}

if (!is_file($inFile)) {
  fwrite(STDERR, "Input file not found: $inFile\n");
  exit(1);
}

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

function ensure_dir(string $dir): void {
  if (is_dir($dir)) return;
  if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
    throw new RuntimeException("Failed to create directory: $dir");
  }
}

function safe_filename(string $name): string {
  $name = trim($name);
  if ($name === '') return 'untitled';
  $name = preg_replace('/[^a-zA-Z0-9\-_. ]+/', '_', $name);
  $name = preg_replace('/\s+/', ' ', $name);
  $name = trim($name);
  $name = str_replace(' ', '_', $name);
  $name = trim($name, '_');
  return $name === '' ? 'untitled' : $name;
}

function is_valid_utf8(string $s): bool {
  if ($s === '') return true;
  if (function_exists('mb_check_encoding')) {
    return mb_check_encoding($s, 'UTF-8');
  }
  return preg_match('//u', $s) === 1;
}

function excel_text_penalty(string $s): int {
  // Lower is better. Penalize common mojibake markers and illegal control chars.
  $penalty = 0;

  // Common UTF-8 mojibake sequences when UTF-8 bytes were misread as latin1 then re-encoded.
  $penalty += 3 * substr_count($s, "Ã");
  $penalty += 3 * substr_count($s, "Â");
  $penalty += 3 * substr_count($s, "Ù");
  $penalty += 3 * substr_count($s, "Ø");
  // CJK mojibake often starts with these.
  $penalty += 2 * substr_count($s, "ë");
  $penalty += 2 * substr_count($s, "ê");
  $penalty += 2 * substr_count($s, "æ");
  $penalty += 2 * substr_count($s, "å");
  $penalty += 2 * substr_count($s, "ç");
  $penalty += 2 * substr_count($s, "Ç");

  // cp1252 special punctuation that frequently appears in mojibake (and is rare in normal text here).
  // Includes: ‚ „ … † ‡ ‰ ‹ ‘ ’ “ ” • – — ™ ›
  $penalty += 4 * preg_match_all('/[\x{201A}\x{201E}\x{2026}\x{2020}\x{2021}\x{2030}\x{2039}\x{2018}\x{2019}\x{201C}\x{201D}\x{2022}\x{2013}\x{2014}\x{2122}\x{203A}]/u', $s, $m);

  // Unicode replacement character.
  $penalty += 5 * substr_count($s, "�");

  // XML 1.0 disallowed control chars: 0x00-0x08, 0x0B, 0x0C, 0x0E-0x1F.
  $control = preg_match_all('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $s, $m);
  if (is_int($control) && $control > 0) {
    $penalty += 10 * $control;
  }

  return $penalty;
}

function utf8_ord(string $ch): int {
  if ($ch === '') return -1;
  if (function_exists('mb_ord')) {
    return mb_ord($ch, 'UTF-8');
  }
  $u = mb_convert_encoding($ch, 'UCS-4BE', 'UTF-8');
  if ($u === false || strlen($u) !== 4) return -1;
  $arr = unpack('N', $u);
  return is_array($arr) && isset($arr[1]) ? (int)$arr[1] : -1;
}

function looks_like_mojibake(string $s): bool {
  if ($s === '') return false;
  // Common visible markers.
  if (strpbrk($s, "ÃÂØÙëêæåçÇ�") !== false) return true;
  // Also catch embedded C1 control characters that show up as U+0080..U+009F.
  if (preg_match('/[\x{0080}-\x{009F}]/u', $s) === 1) return true;
  // cp1252 punctuation (seen in mojibake like "ì‚¼ì´Œ" or "é¦™è•‰").
  return preg_match('/[\x{201A}\x{201E}\x{2026}\x{2020}\x{2021}\x{2022}\x{2013}\x{2014}\x{2122}]/u', $s) === 1;
}

function mojibake_to_bytes_cp1252(string $s): string {
  // Convert a UTF-8 string that visually contains mojibake characters back into
  // the original byte stream by mapping Unicode codepoints to single bytes.
  // - For U+0000..U+00FF: byte is the same value
  // - For Windows-1252 special glyphs (U+20AC etc): map to 0x80..0x9F
  static $cp1252 = [
    0x20AC => 0x80,
    0x201A => 0x82,
    0x0192 => 0x83,
    0x201E => 0x84,
    0x2026 => 0x85,
    0x2020 => 0x86,
    0x2021 => 0x87,
    0x02C6 => 0x88,
    0x2030 => 0x89,
    0x0160 => 0x8A,
    0x2039 => 0x8B,
    0x0152 => 0x8C,
    0x017D => 0x8E,
    0x2018 => 0x91,
    0x2019 => 0x92,
    0x201C => 0x93,
    0x201D => 0x94,
    0x2022 => 0x95,
    0x2013 => 0x96,
    0x2014 => 0x97,
    0x02DC => 0x98,
    0x2122 => 0x99,
    0x0161 => 0x9A,
    0x203A => 0x9B,
    0x0153 => 0x9C,
    0x017E => 0x9E,
    0x0178 => 0x9F,
  ];

  $bytes = '';
  $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
  if (!is_array($chars)) return $bytes;

  foreach ($chars as $ch) {
    $cp = utf8_ord($ch);
    if ($cp < 0) continue;
    if ($cp <= 0xFF) {
      $bytes .= chr($cp);
      continue;
    }
    if (isset($cp1252[$cp])) {
      $bytes .= chr($cp1252[$cp]);
      continue;
    }
    // Unknown mapping; replace with '?' to avoid breaking UTF-8 validator.
    $bytes .= '?';
  }

  return $bytes;
}

function clean_for_excel_xml(string $s): string {
  // Remove characters that break XLSX XML parts.
  // Keep tab (0x09), LF (0x0A), CR (0x0D).
  return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '';
}

function mysql_unescape(string $s): string {
  // Undo common MySQL dump escaping in single-quoted strings.
  // Order matters: backslashes first.
  $s = str_replace('\\\\', "\\", $s);
  $s = str_replace("\\'", "'", $s);
  $s = str_replace('\\"', '"', $s);
  $s = str_replace('\\n', "\n", $s);
  $s = str_replace('\\r', "\r", $s);
  $s = str_replace('\\t', "\t", $s);

  // Ensure we hand PhpSpreadsheet valid UTF-8 without XML-illegal control chars.
  // The legacy dump often contains "UTF-8 text that was previously mis-decoded as latin1",
  // producing mojibake like: ØªÙÙ...
  if (is_valid_utf8($s)) {
    // If it smells like mojibake, reverse it by reconstructing the original bytes
    // (cp1252/latin1 byte mapping), then treating those bytes as UTF-8.
    if (looks_like_mojibake($s)) {
      $candidates = [$s];

      // Up to 2 passes helps for double-encoded strings.
      $current = $s;
      for ($pass = 0; $pass < 2; $pass++) {
        if (!looks_like_mojibake($current)) break;

        $bytes = mojibake_to_bytes_cp1252($current);
        if ($bytes !== '' && is_valid_utf8($bytes)) {
          $candidates[] = $bytes;
          $current = $bytes;
          continue;
        }
        break;
      }

      // Pick the best candidate.
      $best = $candidates[0];
      $bestPenalty = excel_text_penalty($best);
      for ($i = 1; $i < count($candidates); $i++) {
        $p = excel_text_penalty($candidates[$i]);
        if ($p < $bestPenalty) {
          $bestPenalty = $p;
          $best = $candidates[$i];
        }
      }
      $s = $best;
    }

    return clean_for_excel_xml($s);
  }

  // If not valid UTF-8, assume latin1 bytes and convert.
  if (function_exists('iconv')) {
    $converted = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $s);
    if ($converted !== false && is_string($converted) && $converted !== '') {
      return clean_for_excel_xml($converted);
    }
  }
  if (function_exists('mb_convert_encoding')) {
    $converted = @mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
    if ($converted !== false && is_string($converted) && $converted !== '') {
      return clean_for_excel_xml($converted);
    }
  }

  return clean_for_excel_xml($s);
}

/**
 * Parse a single tuple line like:
 * (1, 'mapel', 'jenis', 'no', 'soal', 'a', 'b', 'rule', 'kategori'),
 * Returns array with keys.
 */
function parse_tb_soal_tuple(string $line): ?array {
  $line = trim($line);
  if ($line === '' || $line[0] !== '(') return null;

  // Strip trailing comma/semicolon and surrounding parentheses.
  $line = rtrim($line, ",;\r\n");
  if (!str_ends_with($line, ')')) return null;
  $inner = substr($line, 1, -1);

  // Split by commas outside quotes.
  $values = [];
  $buf = '';
  $inQuote = false;
  $escape = false;

  $len = strlen($inner);
  for ($i = 0; $i < $len; $i++) {
    $ch = $inner[$i];

    if ($escape) {
      $buf .= $ch;
      $escape = false;
      continue;
    }

    if ($ch === '\\') {
      // Preserve escapes, decode later.
      $buf .= $ch;
      $escape = true;
      continue;
    }

    if ($ch === "'") {
      $inQuote = !$inQuote;
      $buf .= $ch;
      continue;
    }

    if ($ch === ',' && !$inQuote) {
      $values[] = trim($buf);
      $buf = '';
      continue;
    }

    $buf .= $ch;
  }
  if (trim($buf) !== '') {
    $values[] = trim($buf);
  }

  // Expected 9 columns.
  if (count($values) !== 9) return null;

  $out = [];
  $out['id'] = (int)$values[0];

  $cols = ['mapel','jenis','no_soal','soal','a','b','rule','kategori'];
  for ($i = 0; $i < 8; $i++) {
    $v = $values[$i + 1];
    if (str_starts_with($v, "'") && str_ends_with($v, "'")) {
      $v = substr($v, 1, -1);
      $v = mysql_unescape($v);
    } elseif (strcasecmp($v, 'NULL') === 0) {
      $v = '';
    }
    $out[$cols[$i]] = $v;
  }

  return $out;
}

function make_template_sheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void {
  $sheet->setCellValue('A1', 'Pertanyaan');
  $sheet->setCellValue('B1', 'Pilihan A');
  $sheet->setCellValue('C1', 'Pilihan B');
  $sheet->setCellValue('D1', 'Pilihan C');
  $sheet->setCellValue('E1', 'Pilihan D');
  $sheet->setCellValue('F1', 'Pilihan E');
  $sheet->setCellValue('G1', 'Jawaban Benar (A/B/C/D/E)');
  $sheet->setCellValue('H1', 'Penjelasan (Opsional)');

  $headerStyle = [
    'font' => [
      'bold' => true,
      'color' => ['rgb' => 'FFFFFF'],
      'size' => 12,
    ],
    'fill' => [
      'fillType' => Fill::FILL_SOLID,
      'startColor' => ['rgb' => '0fb26b'],
    ],
    'alignment' => [
      'horizontal' => Alignment::HORIZONTAL_CENTER,
      'vertical' => Alignment::VERTICAL_CENTER,
    ],
  ];
  $sheet->getStyle('A1:H1')->applyFromArray($headerStyle);

  $sheet->getColumnDimension('A')->setWidth(50);
  $sheet->getColumnDimension('B')->setWidth(30);
  $sheet->getColumnDimension('C')->setWidth(30);
  $sheet->getColumnDimension('D')->setWidth(30);
  $sheet->getColumnDimension('E')->setWidth(30);
  $sheet->getColumnDimension('F')->setWidth(30);
  $sheet->getColumnDimension('G')->setWidth(25);
  $sheet->getColumnDimension('H')->setWidth(40);

  $sheet->freezePane('A2');
}

function apply_border(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $lastRow): void {
  $lastRow = max(2, $lastRow);
  $borderStyle = [
    'borders' => [
      'allBorders' => [
        'borderStyle' => Border::BORDER_THIN,
        'color' => ['rgb' => 'CCCCCC'],
      ],
    ],
  ];
  $sheet->getStyle('A1:H' . $lastRow)->applyFromArray($borderStyle);
}

ensure_dir($outDir);

// Group rows by (kategori, mapel, jenis).
$groups = []; // key => ['kategori'=>..,'mapel'=>..,'jenis'=>..,'rows'=>[]]
$total = 0;
$matched = 0;

$fh = fopen($inFile, 'rb');
if (!$fh) {
  fwrite(STDERR, "Failed to open input file.\n");
  exit(1);
}

try {
  while (!feof($fh)) {
    $line = fgets($fh);
    if ($line === false) break;

    $tuple = parse_tb_soal_tuple($line);
    if (!$tuple) continue;

    $total++;
    $kategori = strtolower(trim((string)$tuple['kategori']));
    $mapel = trim((string)$tuple['mapel']); // Define $mapel early

    if (!$importAll) {
      if ($kategori !== $kategoriFilter) continue;
    }

    // Optional filter for mapel (substring match, case-insensitive).
    if ($mapelFilter !== null) {
      if (strpos(strtolower($mapel), $mapelFilter) === false) continue;
    }

    // Additional filtering for sub-kategori (e.g., Arabic, Korean, Mandarin)
    if ($kategori === 'bahasa' && !empty($subKategoriSearch)) {
      $mapelLower = strtolower($mapel);
      $jenisLower = strtolower(trim((string)$tuple['jenis']));

      // Check if any of the sub-kategori terms match
      $found = false;
      foreach ($subKategoriSearch as $term) {
        if (strpos($mapelLower, $term) !== false || strpos($jenisLower, $term) !== false) {
          $found = true;
          break;
        }
      }
      if (!$found) continue;
    }

    $matched++;
    $jenis = trim((string)$tuple['jenis']);

    $key = $kategori . '||' . $mapel . '||' . $jenis;
    if (!isset($groups[$key])) {
      $groups[$key] = [
        'kategori' => $kategori,
        'mapel' => $mapel,
        'jenis' => $jenis,
        'rows' => [],
      ];
    }
    $groups[$key]['rows'][] = $tuple;

    if ($limit !== null && $matched >= $limit) break;
  }
} finally {
  fclose($fh);
}

// Sort groups for stable output.
ksort($groups);

$written = 0;
foreach ($groups as $g) {
  $kategori = $g['kategori'];
  $mapel = $g['mapel'];
  $jenis = $g['jenis'];
  $rows = $g['rows'];

  // Sort rows by numeric no_soal if possible.
  usort($rows, function($a, $b) {
    $na = is_numeric($a['no_soal']) ? (int)$a['no_soal'] : PHP_INT_MAX;
    $nb = is_numeric($b['no_soal']) ? (int)$b['no_soal'] : PHP_INT_MAX;
    if ($na === $nb) return $a['id'] <=> $b['id'];
    return $na <=> $nb;
  });

  $subDir = $outDir . '/' . safe_filename($kategori);
  ensure_dir($subDir);

  $fileBase = safe_filename($mapel) . '__' . safe_filename($jenis);
  $filePath = $subDir . '/' . ($fileBase === '__' ? 'untitled' : $fileBase) . '.xlsx';

  $spreadsheet = new Spreadsheet();
  $sheet = $spreadsheet->getActiveSheet();
  make_template_sheet($sheet);

  $r = 2;
  foreach ($rows as $row) {
    $sheet->setCellValue('A' . $r, (string)$row['soal']);
    $sheet->setCellValue('B' . $r, (string)$row['a']);
    $sheet->setCellValue('C' . $r, (string)$row['b']);
    $sheet->setCellValue('D' . $r, '');
    $sheet->setCellValue('E' . $r, '');
    $sheet->setCellValue('F' . $r, '');
    $sheet->setCellValue('G' . $r, $correct);

    // Optional explanation: keep original no_soal for traceability.
    $explain = '';
    $no = trim((string)$row['no_soal']);
    if ($no !== '') $explain = 'No: ' . $no;
    $sheet->setCellValue('H' . $r, $explain);

    $r++;
  }

  apply_border($sheet, $r - 1);

  $writer = new Xlsx($spreadsheet);
  $writer->save($filePath);
  $written++;
}

echo "Done. tuples_scanned=$total matched=$matched groups=" . count($groups) . " files_written=$written\n";
if (!$importAll) {
  echo "Filter kategori=$kategoriFilter, correct=$correct\n";
}

echo "Output folder: $outDir\n";
