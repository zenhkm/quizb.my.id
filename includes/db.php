<?php
require_once __DIR__ . '/../vendor/autoload.php';

// ===============================================
// KONFIG
// ===============================================
$CONFIG = [
  'APP_NAME'   => 'QuizB',
  'APP_TAGLINE' => 'QuizB | Quiz Berkah — Uji wawasanmu dengan kuis seru di berbagai bidang! Dari pengetahuan umum hingga agama. Buat rekor baru dan tantang temanmu untuk adu skor di QuizB!',
  'DB_HOST' => 'localhost',
  'DB_NAME' => 'quic1934_quizb',
  'DB_USER' => 'quic1934_zenhkm',          // GANTI sesuai hosting Anda
  'DB_PASS' => '03Maret1990',               // GANTI sesuai hosting Anda
  'DB_CHARSET' => 'utf8mb4',
  'GOOGLE_CLIENT_ID' => '346372041145-v0htu4n3fmetpssmnkhs656ckhfp8ch6.apps.googleusercontent.com',
  'SUPER_ADMIN_EMAIL' => 'zenhkm@gmail.com',
  'SESSION_NAME' => 'quizb_sess',
  
  'VAPID_PUBLIC_KEY' => 'BMkqICzA0e7xrPWB0_6k5q25ngROfgtMKq4b5RAz8Vk0VGCTeGOd6wqq-AyQ8Z48uqkRd1JL1MJEbb5wRW2L8As',
  'VAPID_PRIVATE_KEY' => 'i3-CDhb33SCXfDn0IBBsDO4wkRA11hczdHbfYq5A_d4',
];

// ===============================================
// KONFIGURASI SMTP (WAJIB DIGANTI!)
// ===============================================
define('SMTP_HOST', 'mail.quizb.my.id');        // Host SMTP Anda (sesuai hosting, mungkin memerlukan SSL)
define('SMTP_PORT', 587);                      // Port SMTP Anda (umumnya 587, atau 465 untuk SMTPS)
define('SMTP_USERNAME', 'admin@quizb.my.id');  // Email Admin SMTP Anda
define('SMTP_PASSWORD', 'kubWumBfSSTAi93'); // Password SMTP Anda
define('SMTP_FROM_EMAIL', 'admin@quizb.my.id');   // Email pengirim yang akan terlihat
define('SMTP_FROM_NAME', 'Admin QuizB.my.id');    // Nama pengirim

// === PDO ===
function pdo()
{
  global $CONFIG;
  static $pdo;
  if (!$pdo) {
    $dsn = "mysql:host={$CONFIG['DB_HOST']};dbname={$CONFIG['DB_NAME']};charset={$CONFIG['DB_CHARSET']}";
    $pdo = new PDO($dsn, $CONFIG['DB_USER'], $CONFIG['DB_PASS'], [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
  }
  return $pdo;
}

/**
 * PDO singleton. Selalu kembalikan objek PDO yang sudah ada.
 * Aman walau koneksi dibuat di tempat lain, atau via fungsi pembungkus.
 */
function pdo_instance(): PDO
{
  static $once = null;

  // 1) Kalau sudah pernah dibuat, pakai ulang
  if ($once instanceof PDO) return $once;

  // 2) Kalau $GLOBALS['pdo'] sudah ada dan siap, pakai
  if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
    $once = $GLOBALS['pdo'];
    return $once;
  }

  // 3) Kalau kamu punya fungsi pembungkus koneksi, coba pakai
  foreach (['pdo', 'get_pdo', 'db', 'connect_db'] as $fn) {
    if (function_exists($fn)) {
      $try = $fn();
      if ($try instanceof PDO) {
        $once = $try;
        $GLOBALS['pdo'] = $once; // simpan global biar konsisten
        return $once;
      }
    }
  }
  throw new RuntimeException('PDO belum siap. Pastikan koneksi dibuat sebelum router, atau isi DSN di pdo_instance().');
}

function q($sql, $p = [])
{
  $st = pdo()->prepare($sql);
  $st->execute($p);
  return $st;
}
