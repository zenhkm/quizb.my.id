<?php
include __DIR__.'/backup_diff.php';
// === AWAL ERROR REPORTING ===
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');
set_error_handler(function ($s, $m, $f, $l) {
  if ($s === E_NOTICE || $s === E_USER_NOTICE) {
    return false;
  } // biarkan notice
  throw new ErrorException($m, 0, $s, $f, $l);
});

// Safety net: cegah "headers already sent" karena output nyasar
if (ob_get_level() === 0) {
  ob_start();
}
register_shutdown_function(function () {
  // Flush sisa output dengan rapi di akhir
  if (ob_get_level() > 0) {
    @ob_end_flush();
  }
});

// === AKHIR ERROR REPORTING ===

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/others/cronjob/wa.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// === SESSION: sangat awal, sebelum ada output apa pun ===
if (session_status() === PHP_SESSION_NONE) {

  // 1. Tentukan lokasi folder sesi pribadi kita
  $session_path = __DIR__ . '/sessions';

  // 2. Buat folder jika belum ada (untuk keamanan)
  if (!is_dir($session_path)) {
    mkdir($session_path, 0755, true);
  }

  // 3. Atur PHP untuk menyimpan sesi di folder tersebut
  ini_set('session.save_path', $session_path);

  // 4. Atur durasi cookie menjadi 10 tahun agar "selalu login"
  $sepuluh_tahun = 10 * 365 * 24 * 60 * 60;

  // 5. Jalankan session_start dengan konfigurasi lengkap
  session_start([
    'cookie_lifetime' => $sepuluh_tahun,
    'gc_maxlifetime' => $sepuluh_tahun,
    'name' => 'quizb_sess',
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax'
  ]);
}
// === AKHIR SESSION ===

// === WELCOME PAGE REDIRECT (WAJIB) ===
// Cek jika user login TAPI belum melengkapi profil
// dan mereka TIDAK sedang berada di halaman 'welcome' atau 'logout'.
if (uid() && ($_SESSION['user']['welcome_complete'] ?? 0) == 0) {
  $page = $_GET['page'] ?? 'home';
  $action = $_GET['action'] ?? '';

  // Izinkan akses hanya ke halaman 'welcome' dan aksi 'logout' atau 'save_welcome'
  if ($page !== 'welcome' && $action !== 'logout' && $action !== 'save_welcome') {
    redirect('?page=welcome');
  }
}
// === AKHIR WELCOME PAGE REDIRECT (WAJIB) ===


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
// === AKHIR PDO ===



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


// === Guard: pastikan sesi aktif terikat ke title_id saat ini (tanpa kolom 'status') ===
function ensure_session_bound_to_title(PDO $pdo, $user_id, $title_id) {
    // Jika ada sesi kuis di $_SESSION dan judulnya beda, buang saja sesi lokalnya.
    // Nanti router akan membuat sesi baru untuk title_id yang sekarang.
    if (!empty($_SESSION['quiz']['session_id'])) {
        $currTitle = (int)($_SESSION['quiz']['title_id'] ?? 0);
        if ($currTitle !== (int)$title_id) {
            unset($_SESSION['quiz']);
        }
    }

    // (OPSIONAL) Jika ingin lebih ketat, Anda bisa cek sesi terakhir di DB.
    // Karena tabel quiz_sessions TIDAK punya kolom 'status',
    // kita TIDAK melakukan UPDATE apapun di DB.
    // Cukup pastikan sesi lokal tidak "terbawa" ke judul lain.
    /*
    $st = $pdo->prepare("
        SELECT id, title_id
        FROM quiz_sessions
        WHERE user_id <=> ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $st->execute([$user_id]);
    $last = $st->fetch(PDO::FETCH_ASSOC);
    // Tidak ada tindakan DB yang perlu diambil di sini.
    */
}




// Set header default untuk semua halaman HTML (selain sitemap/robots)
header('Content-Type: text/html; charset=utf-8');
// === Anti-cache global untuk halaman dinamis (HTML) ===
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// =============================
// City helper (nama kota per pemain)
// =============================
function get_city_name()
{
  // 1) Jika user mengirim manual
  if (isset($_POST['city']) || isset($_GET['city'])) {
    $c = trim((string)($_POST['city'] ?? $_GET['city']));
    if ($c !== '') {
      // Simpan cookie bila memungkinkan (tidak wajib untuk logika server-side ini)
      $opts = [
        'expires'  => time() + 60 * 60 * 24 * 180,
        'path'     => '/',
        'samesite' => 'Lax',
        'httponly' => false,
      ];
      if (!headers_sent()) {
        setcookie('player_city', $c, $opts);
      } else {
        $js = json_encode($c, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        // echo "<script>document.cookie='player_city='+encodeURIComponent($js)+'; path=/; max-age='+(60*60*24*180)+'; samesite=Lax';</script>";
      }
      return $c;
    }
  }

  // 2) Pakai cookie jika sudah ada dan valid
  if (!empty($_COOKIE['player_city']) && $_COOKIE['player_city'] !== 'Anonim') {
    return $_COOKIE['player_city'];
  }

  // 3) Fallback server-side: pakai hasil deteksi IP yang SUDAH dihitung di global $city,
  //    atau deteksi langsung sekarang. Ini yang membuat link langsung tetap dapat kota.
  $resolved = null;
  if (isset($GLOBALS['city']) && $GLOBALS['city'] && $GLOBALS['city'] !== 'Anonim') {
    $resolved = $GLOBALS['city'];
  } else {
    $ip = get_client_ip();
    $resolved = get_city_from_ip($ip);
  }

  if (!$resolved || trim($resolved) === '') {
    $resolved = 'Anonim';
  }

  // (Opsional) simpan cookie untuk request berikutnya
  if ($resolved !== 'Anonim' && (!isset($_COOKIE['player_city']) || $_COOKIE['player_city'] !== $resolved)) {
    $opts = [
      'expires'  => time() + 60 * 60 * 24 * 180,
      'path'     => '/',
      'samesite' => 'Lax',
      'httponly' => false,
    ];
    if (!headers_sent()) {
      setcookie('player_city', $resolved, $opts);
    } else {
      $js = json_encode($resolved, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
      //   echo "<script>document.cookie='player_city='+encodeURIComponent($js)+'; path=/; max-age='+(60*60*24*180)+'; samesite=Lax';</script>";
    }
  }

  return $resolved;
}

// === GET CLIENT ===
function get_client_ip()
{
  $keys = [
    'HTTP_CLIENT_IP',
    'HTTP_X_FORWARDED_FOR',
    'REMOTE_ADDR'
  ];
  foreach ($keys as $k) {
    if (!empty($_SERVER[$k])) {
      $ip = $_SERVER[$k];
      if (strpos($ip, ',') !== false) {
        $parts = explode(',', $ip);
        $ip = trim($parts[0]);
      }
      if (filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
      }
    }
  }
  return '0.0.0.0';
}


if (!function_exists('http_get_json')) {
  function http_get_json($url, $timeout = 7, $force_local_cacert = false)
  {
    if (function_exists('curl_init')) {
      $ch = curl_init();
      curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: QuizB/1.0'],
      ]);
      if ($force_local_cacert) {
        $localCacert = __DIR__ . '/cacert.pem';
        if (is_file($localCacert)) {
          curl_setopt($ch, CURLOPT_CAINFO, $localCacert);
        }
      }
      $res  = curl_exec($ch);
      $err  = curl_error($ch);
      $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);
      if ($err || $code < 200 || $code >= 300 || !$res) return null;
      $data = json_decode($res, true);
      return is_array($data) ? $data : null;
    }
    $ctx = stream_context_create([
      'http' => ['timeout' => $timeout, 'ignore_errors' => true],
      'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if (!$raw) return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
  }
}

// === GET CITY FROM IP ===
// === GET CITY FROM IP (DENGAN SESSION CACHE) ===
function get_city_from_ip($ip)
{
  // 1. Cek dari Session Cache dulu
  if (isset($_SESSION['user_city']) && $_SESSION['user_city'] !== 'Anonim') {
    return $_SESSION['user_city'];
  }

  // skip IP dummy
  if (!$ip || $ip === '0.0.0.0') {
    return 'Anonim';
  }

  $city_result = 'Anonim'; // Default

  // 2. Jika cache tidak ada, baru panggil API
  // 2a) ipapi.co
  $j = http_get_json("https://ipapi.co/{$ip}/json/");
  if ($j && !empty($j['city'])) {
    $city_result = $j['city'];
  }
  // 2b) ipwho.is (jika #1 gagal)
  elseif ($j = http_get_json("https://ipwho.is/{$ip}?fields=city,success")) {
    if ($j && (!isset($j['success']) || $j['success'] === true)) {
      if (!empty($j['city'])) $city_result = $j['city'];
    }
  }
  // 2c) ipinfo.io (jika #2 gagal)
  elseif ($j = http_get_json("https://ipinfo.io/{$ip}/json")) {
    if ($j && !empty($j['city'])) {
      $city_result = $j['city'];
    }
  }

  // 3. Simpan hasil (baik 'Anonim' atau nama kota) ke session
  $_SESSION['user_city'] = $city_result;
  return $city_result;
}



// Kode baru dengan logging yang lebih baik:
$ip = get_client_ip();
$city = get_city_from_ip($ip); // Fungsi ini sekarang sudah menggunakan cache

// Ganti error_log Anda agar kita bisa memantau
if (isset($_SESSION['user_city_logged'])) {
    // Jika sudah pernah di-log, jangan log lagi
} else {
    // Log pertama kali untuk sesi ini
    error_log("GEO_LOOKUP: IP=$ip CITY=$city (Session Created)");
    $_SESSION['user_city_logged'] = true;
}

/**
 * ===============================================
 *  QuizB — "Quiz Berkah"
 *  Single-file starter (index.php)
 * ===============================================
 *  Fitur v1 (sesuai brief):
 *  1) Login & Register via Google (GIS button)
 *  2) MySQL (PDO) + installer & seeder (?action=install & ?action=seed)
 *  3) Landing: 5 tema besar, peserta terbaru, top skor, kuis terbaru
 *  4) Alur: Tema ➜ Sub Tema ➜ Judul Soal ➜ Kuis (10 soal acak) 
 *  5) Mode review: salah langsung review / selesai dulu baru review
 *  6) Rekam hasil (nama, asal, nilai) + per-pertanyaan (benar/salah) ➜ rating mudah/sulit
 *  7) Profil user: riwayat kuis + link tantang teman (challenge link)
 *  8) Backend (admin): tambah user, tema, sub tema, judul, soal, overview realtime-ish
 *  9) Adaptive: pemula → soal mudah dulu; mahir → soal sulit dulu
 * 10) Random soal (tiap main diacak) — namun tetap sesuai judul soal
 * 11) Hanya admin yang boleh menambah tema/subtema/soal (super-admin: zenhkm@gmail.com)
 *
 *  Catatan Keamanan Produksi:
 *  - Pastikan set DB_USER/DB_PASS sesuai server Anda.
 *  - Verifikasi Google ID Token di sini menggunakan endpoint resmi Google.
 *  - Tambahkan HTTPS & hardening (CSRF token, rate-limit, Content-Security-Policy, dsb.) untuk produksi.
 */

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



// ===============================================
// BOOTSTRAP
// ===============================================

// =============================
// Guest Identity (untuk tamu)
// =============================
function get_guest_id(): string
{
  if (!isset($_COOKIE['guest_id']) || !preg_match('/^[a-f0-9]{16,64}$/', $_COOKIE['guest_id'])) {
    $gid = bin2hex(random_bytes(8)); // 16 hex chars
    setcookie('guest_id', $gid, [
      'expires'  => time() + 31536000, // 1 tahun
      'path'     => '/',
      'secure'   => !empty($_SERVER['HTTPS']),
      'httponly' => false,
      'samesite' => 'Lax'
    ]);
    $_COOKIE['guest_id'] = $gid;
  }
  return $_COOKIE['guest_id'];
}




function q($sql, $p = [])
{
  $st = pdo()->prepare($sql);
  $st->execute($p);
  return $st;
}
function h($s)
{
  return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}
function now()
{
  return date('Y-m-d H:i:s');
}
function uid()
{
  return $_SESSION['user']['id'] ?? null;
}



// ... (Di area yang sama dengan fungsi utilitas seperti h(), q(), dll.)

// ===============================================
// FUNGSI HELPER PENGIRIMAN EMAIL VIA SMTP
// ===============================================
/**
 * Mengirim email menggunakan konfigurasi SMTP global.
 */
function send_smtp_email(string $to, string $subject, string $body): bool
{
    $mail = new PHPMailer(true); 

    try {
        // Pengaturan Server
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        // Port 587 menggunakan TLS. Ganti ke ENCRYPTION_SMTPS jika port 465.
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; 
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        // Pengirim (Admin QuizB)
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);

        // Penerima
        $mail->addAddress($to);

        // Konten
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body); 

        $mail->send();
        return true;
    } catch (Exception $e) {
        // Logging error agar Admin bisa mendiagnosis masalah SMTP
        error_log("PHPMailer Error to {$to}: " . $e->getMessage());
        return false;
    }
}


// ===============================================
// FUNGSI KHUSUS: KIRIM EMAIL SELAMAT DATANG
// ===============================================
function send_welcome_email(string $toEmail, string $toName) {
    global $CONFIG;
    
    $subject = "🎉 Selamat Datang di QuizB | Asah Wawasanmu!";
    
    // Konten Email (Gunakan HTML untuk tampilan yang lebih baik)
    $body = "
    <html>
    <body style='font-family: sans-serif; line-height: 1.6; color: #333;'>
        <div style='max-width: 600px; margin: 20px auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px;'>
            <h2 style='color: #0d6efd;'>Halo, " . h($toName) . "!</h2>
            
            <p>Kami sangat senang Anda bergabung dengan keluarga QuizB (Quiz Berkah)! Akun Anda (" . h($toEmail) . ") telah berhasil diaktifkan.</p>
            
            <p>QuizB adalah platform kuis adaptif yang dirancang untuk menguji wawasan Anda di berbagai bidang, dari pengetahuan umum hingga materi spesifik.</p>
            
            <h3 style='color: #0d6efd;'>Fitur Utama yang Dapat Anda Eksplorasi:</h3>
            <ul>
                <li><strong>Mode Adaptif:</strong> Sistem kami akan menyesuaikan tingkat kesulitan soal berdasarkan riwayat bermain Anda.</li>
                <li><strong>Tantang Teman:</strong> Buat <a href='" . base_url() . "?page=challenges'>Challenge Link</a> untuk mengadu skor dengan teman Anda.</li>
                <li><strong>Akses Materi:</strong> Jelajahi ribuan soal melalui menu <a href='" . base_url() . "?page=themes'>Pencarian</a>.</li>
                <li><strong>Tugas (Assignments):</strong> Jika Anda seorang Pelajar, periksa menu <a href='" . base_url() . "?page=student_tasks'>Daftar Tugas</a> untuk tugas dari Pengajar Anda.</li>
            </ul>
            
            <div style='text-align: center; margin: 25px 0;'>
                <a href='" . base_url() . "' style='background-color: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; font-weight: bold;'>
                    Mulai Main Kuis Sekarang!
                </a>
            </div>
            
            <p style='font-size: 0.9em; color: #666;'>
                Jika Anda memiliki pertanyaan atau masukan, jangan ragu membalas email ini atau kunjungi halaman <a href='" . base_url() . "?page=feedback'>Kirim Umpan Balik</a>.
            </p>
            <p style='font-size: 0.8em; color: #aaa; text-align: right;'>— Salam dari " . SMTP_FROM_NAME . "</p>
        </div>
    </body>
    </html>";

    // Panggil helper SMTP yang sudah kita buat
    return send_smtp_email($toEmail, $subject, $body);
}

function user_timer_seconds($default = 30)
{
  $u = $_SESSION['user'] ?? null;
  if (!$u) return $default;
  $row = q("SELECT quiz_timer_seconds FROM users WHERE id=?", [$u['id']])->fetch();
  $v = (int)($row['quiz_timer_seconds'] ?? 0);
  return ($v >= 5 && $v <= 300) ? $v : $default;
}


function user_exam_timer_minutes($default = 60)
{
  $u = $_SESSION['user'] ?? null;
  if (!$u) return $default;
  // Hanya admin yang pengaturannya dibaca, selain itu pakai default
  if (($u['role'] ?? '') !== 'admin') return $default;

  $row = q("SELECT exam_timer_minutes FROM users WHERE id=?", [$u['id']])->fetch();
  $v = (int)($row['exam_timer_minutes'] ?? 0);
  return ($v >= 1 && $v <= 300) ? $v : $default;
}



function save_user_settings($timerSeconds = null, $theme = null, $newName = null, $examTimerMinutes = null)
{
  $u = $_SESSION['user'] ?? null;
  if (!$u) return false;

  $fields = [];
  $params = [];

  // Nama tampilan (manual) -> kunci nama agar tak ditimpa login
  if ($newName !== null) {
    $clean = trim($newName);
    if ($clean !== '' && mb_strlen($clean) >= 3 && mb_strlen($clean) <= 190) {
      $fields[] = "name = ?";
      $params[] = $clean;
      $fields[] = "name_locked = 1"; // <- penting
      $_SESSION['user']['name'] = $clean; // sinkron sesi
    }
  }

  // Timer (opsional)
  if ($timerSeconds !== null) {
    $s = max(5, min(300, (int)$timerSeconds));
    $fields[] = "quiz_timer_seconds = ?";
    $params[] = $s;
  }

  // ▼▼▼ BLOK BARU UNTUK TIMER UJIAN (HANYA ADMIN) ▼▼▼
  if ($examTimerMinutes !== null && is_admin()) {
    $m = max(1, min(300, (int)$examTimerMinutes));
    $fields[] = "exam_timer_minutes = ?";
    $params[] = $m;
  }
  // ▲▲▲ AKHIR BLOK BARU ▲▲▲

  // Theme (opsional, kalau dipakai)
  if ($theme !== null && in_array($theme, ['light', 'dark'], true)) {
    $fields[] = "theme = ?";
    $params[] = $theme;
  }

  if (!$fields) return false;
  $fields[] = "updated_at = NOW()";
  $params[] = $u['id'];

  $sql = "UPDATE users SET " . implode(", ", $fields) . " WHERE id = ?";
  return q($sql, $params);
}


function is_admin()
{
  return ($_SESSION['user']['role'] ?? '') === 'admin';
}

function is_pengajar()
{
    return ($_SESSION['user']['role'] ?? '') === 'pengajar';
}


function base_url()
{
  $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  return $proto . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
}
function redirect($u)
{
  header('Location: ' . $u);
  exit;
}


// ===============================================
// CHOICES SHUFFLE (konsisten per sesi & per soal)
// ===============================================
function get_shuffled_choice_ids($question_id, $session_id)
{
  if (!isset($_SESSION['choice_order'])) $_SESSION['choice_order'] = [];
  if (isset($_SESSION['choice_order'][$session_id][$question_id])) {
    return $_SESSION['choice_order'][$session_id][$question_id];
  }
  $ids = q("SELECT id FROM choices WHERE question_id=? ORDER BY id", [$question_id])->fetchAll(PDO::FETCH_COLUMN);
  if (!$ids) {
    $_SESSION['choice_order'][$session_id][$question_id] = [];
    return [];
  }

  usort($ids, function ($a, $b) use ($session_id) {
    $ha = substr(sha1($session_id . '-' . $a), 0, 8);
    $hb = substr(sha1($session_id . '-' . $b), 0, 8);
    return strcmp($ha, $hb);
  });

  $_SESSION['choice_order'][$session_id][$question_id] = $ids;
  return $ids;
}

function get_choices_by_ids_in_order(array $ids)
{
  if (!$ids) return [];
  $in  = implode(',', array_fill(0, count($ids), '?'));
  $st  = q("SELECT id, question_id, text, is_correct FROM choices WHERE id IN ($in)", $ids);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  $map = [];
  foreach ($rows as $r) {
    $map[$r['id']] = $r;
  }
  $out = [];
  foreach ($ids as $id) {
    if (isset($map[$id])) $out[] = $map[$id];
  }
  return $out;
}

function get_shuffled_choices_cached($question_id, $session_id)
{
  $ids = get_shuffled_choice_ids($question_id, $session_id);
  return get_choices_by_ids_in_order($ids);
}


if (isset($_GET['action'])) {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');
}


// ===============================================
// INSTALLER & SEEDER
// ===============================================
if (isset($_GET['action']) && $_GET['action'] === 'install') {
  pdo()->exec(get_schema_sql());
  echo '<pre>Schema installed/verified. Jalankan ?action=seed untuk data contoh. <a href="./">Kembali</a></pre>';
  exit;
}
if (isset($_GET['action']) && $_GET['action'] === 'seed') {
  seed_data();
  echo '<pre>Data contoh ditambahkan. <a href="./">Kembali</a></pre>';
  exit;
}

// ===============================================
// GOOGLE SIGN-IN HANDLER (verifikasi ID Token)
// ===============================================
if (isset($_GET['action']) && $_GET['action'] === 'google_login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $idToken = $_POST['credential'] ?? '';
  $payload = verify_google_id_token($idToken);
  if (!$payload) {
    http_response_code(401);
    echo 'Login gagal: token tidak valid.';
    exit;
  }

  $email = strtolower(trim($payload['email'] ?? ''));
  $name  = $payload['name'] ?? '';
  $sub   = $payload['sub'] ?? '';
  $avatar = $payload['picture'] ?? '';

  // Upsert user
  $u = q("SELECT * FROM users WHERE email=?", [$email])->fetch();
  if (!$u) {
    // INI BLOK UNTUK PENGGUNA BARU
    $role = ($email === $GLOBALS['CONFIG']['SUPER_ADMIN_EMAIL']) ? 'admin' : 'user';

    // Set nama tampilan awal sama dengan nama Google
    $display_name = $name;
    q(
      // Tambahkan `display_name` dan set `welcome_complete` ke 0
      "INSERT INTO users (google_sub,email,name,display_name,avatar,role,welcome_complete,created_at) VALUES (?,?,?,?,?,?,0,?)",
      [$sub, $email, $name, $display_name, $avatar, $role, now()]
    );

    $new_user_id = pdo()->lastInsertId(); // Dapatkan ID user baru

    // ▼▼▼ PANGGIL FUNGSI KIRIM EMAIL DI SINI (BARU) ▼▼▼
    // Memanggil fungsi pengiriman email Selamat Datang
    send_welcome_email($email, $name);
    // ▲▲▲ AKHIR PANGGIL FUNGSI ▼▼▼

    // ▼▼▼ NOTIFIKASI ▼▼▼
    // Buat 1 notifikasi broadcast untuk pendaftaran pengguna baru
    $notif_message = "Pengguna baru telah mendaftar: " . h($name);
    $notif_link = "?page=profile&user_id=" . $new_user_id;

    q(
      "INSERT INTO broadcast_notifications (type, message, link, related_id) VALUES ('new_user_admin', ?, ?, ?)",
      [$notif_message, $notif_link, $new_user_id]
    );

    // ▲▲▲ AKHIR DARI BAGIAN YANG DIPERBAIKI ▲▲▲

    $u = q("SELECT * FROM users WHERE id=?", [$new_user_id])->fetch();
  } else {
    // Hormati nama manual: jika name_locked=1, JANGAN timpa 'name' saat login
    $row = q("SELECT id, name_locked FROM users WHERE id=?", [$u['id']])->fetch();
    if ((int)($row['name_locked'] ?? 0) === 1) {
      q(
        "UPDATE users SET google_sub=?, avatar=?, updated_at=? WHERE id=?",
        [$sub, $avatar, now(), $row['id']]
      );
    } else {
      q(
        "UPDATE users SET google_sub=?, name=?, avatar=?, updated_at=? WHERE id=?",
        [$sub, $name, $avatar, now(), $row['id']]
      );
    }
    $u = q("SELECT * FROM users WHERE id=?", [$row['id']])->fetch();
  }

  $_SESSION['user'] = $u;
  redirect('./');
}

// ===============================================
// AKHIR GOOGLE LOGIN
// ===============================================

// ===============================================
// LOGOUT HANDLER
// ===============================================
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    
  // 1. Hapus semua data sesi
  $_SESSION = [];

  // 2. Hapus cookie sesi dari browser
  if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
      session_name(),
      '',
      time() - 42000,
      $params["path"],
      $params["domain"],
      $params["secure"],
      $params["httponly"]
    );
  }

  // 3. Hancurkan sesi di server
  session_destroy();

  // 4. Arahkan kembali ke halaman utama
  redirect('./');
}
// ▲▲▲ AKHIR DARI BLOK LOGOUT BARU ▲▲▲


// ===============================================
// AKHIR KIRIM PESAN HANDLER (SEKARANG API)
// ===============================================



// ===============================================
// KIRIM PESAN HANDLER (SEKARANG API)
// ===============================================
if (isset($_GET['action']) && $_GET['action'] === 'send_message' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json; charset=UTF-8');
  if (!uid()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Login diperlukan.']);
    exit;
  }

  $receiver_id = (int)($_POST['receiver_id'] ?? 0);
  $message_text = trim($_POST['message_text'] ?? '');

  if ($receiver_id <= 0 || empty($message_text)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Pesan atau penerima tidak valid.']);
    exit;
  }

  // 1. Simpan ke DB
  $created_at = now();
  q(
    "INSERT INTO messages (sender_id, receiver_id, message_text, created_at) VALUES (?, ?, ?, ?)",
    [uid(), $receiver_id, $message_text, $created_at]
  );
  $new_message_id = pdo()->lastInsertId();

  // 2. Buat HTML untuk pesan baru (pesan *saya*)
  $my_avatar = $_SESSION['user']['avatar'] ?? '';
  $message_html = render_message_bubble(
    $new_message_id,
    $message_text,
    $created_at,
    true, // is_my_message
    $my_avatar
  );

  echo json_encode(['ok' => true, 'html' => $message_html]);
  exit;
}

// ===============================================
// ROUTER
// ===============================================
$page = $_GET['page'] ?? 'home';

if ($page === 'play' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  quiz_post();
}
if ($page === 'qmanage' && is_admin() && $_SERVER['REQUEST_METHOD'] === 'POST') {
  qmanage_post();
}
if ($page === 'crud' && is_admin() && $_SERVER['REQUEST_METHOD'] === 'POST') {
  crud_post();
}
if (isset($_GET['action']) && $_GET['action'] === 'tambah_anggota') {
  handle_tambah_anggota();
}
if (isset($_GET['action']) && $_GET['action'] === 'send_broadcast') {
  handle_broadcast_notification();
}
if (isset($_GET['action']) && $_GET['action'] === 'edit_tugas') {
  handle_edit_tugas();
}
if (isset($_GET['action']) && $_GET['action'] === 'delete_tugas') {
  handle_delete_tugas();
}
if (isset($_GET['action']) && $_GET['action'] === 'api_get_classes') {
  api_get_classes_by_institution_name();
}
if (isset($_GET['action']) && $_GET['action'] === 'beri_tugas') {
  handle_beri_tugas();
}
if (isset($_GET['action']) && $_GET['action'] === 'tambah_institusi') {
  handle_tambah_institusi();
}
if (isset($_GET['action']) && $_GET['action'] === 'tambah_kelas') {
  handle_tambah_kelas();
}

if (isset($_GET['action']) && $_GET['action'] === 'handle_feedback' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  handle_feedback();
}

if (isset($_GET['action']) && $_GET['action'] === 'handle_reply' && is_admin() && $_SERVER['REQUEST_METHOD'] === 'POST') {
  handle_admin_reply();
}


if (isset($_GET['action']) && $_GET['action'] === 'save_welcome' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  handle_save_welcome();
}
if ($page === 'kelola_user' && is_admin() && $_SERVER['REQUEST_METHOD'] === 'POST') {
  handle_update_user_role();
}
if (isset($_GET['action']) && $_GET['action'] === 'api_get_page_content') {
  api_get_page_content();
}
if (isset($_GET['action']) && $_GET['action'] === 'kirim_rekap') {
  handle_kirim_rekap();
}
if (isset($_GET['action']) && $_GET['action'] === 'rekap_nilai') {
  handle_rekap_nilai();
}
if (isset($_GET['action']) && $_GET['action'] === 'api_get_quiz') {
  api_get_quiz(); // Panggil fungsi API yang baru kita buat
}
if (isset($_GET['action']) && $_GET['action'] === 'api_submit_answers') {
  api_submit_answers(); // Panggil fungsi API yang baru kita buat
}
if (isset($_GET['action']) && $_GET['action'] === 'create_challenge' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  handle_create_challenge();
}
if (isset($_GET['action']) && $_GET['action'] === 'download_csv') {
    handle_download_csv();
}
if (isset($_GET['action']) && $_GET['action'] === 'api_get_older_messages') {
  api_get_older_messages();
}
if (isset($_GET['action']) && $_GET['action'] === 'api_get_classes_for_student' && $_SERVER['REQUEST_METHOD'] === 'GET') {
  api_get_classes_for_student();
}
if (isset($_GET['action']) && $_GET['action'] === 'save_student_class' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  handle_save_student_class();
}
if ($page === 'teacher_crud' && (is_pengajar() || is_admin())) {
    view_crud_pengajar();
}
if ($page === 'teacher_qmanage' && (is_pengajar() || is_admin())) {
    view_qmanage_pengajar();
}
if (isset($_GET['action']) && $_GET['action'] === 'crud_post_pengajar' && (is_pengajar() || is_admin())) {
    crud_post_pengajar();
}
if (isset($_GET['action']) && $_GET['action'] === 'api_get_master_titles') {
    api_get_master_titles(); // Ini akan kita definisikan.
}
if (isset($_GET['action']) && $_GET['action'] === 'handle_delete_conversation') {
  handle_delete_conversation(); // Fungsi baru untuk hapus seluruh percakapan
}
if (isset($_GET['action']) && $_GET['action'] === 'api_get_conversations') {
  api_get_conversations(); // Fungsi baru untuk API infinite scroll
}
if (isset($_GET['action']) && $_GET['action'] === 'save_subscription' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  handle_save_subscription();
}
if (isset($_GET['action']) && $_GET['action'] === 'delete_message' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  handle_delete_message(); // Fungsi baru untuk hapus pesan
}
if (isset($_GET['action']) && $_GET['action'] === 'api_search_users') {
  api_search_users(); // Fungsi baru untuk API pencarian user
}
if (isset($_GET['action']) && $_GET['action'] === 'send_test_push') {
    handle_send_test_push();
}
if (isset($_GET['action']) && $_GET['action'] === 'edit_kelas' && $_SERVER['REQUEST_METHOD'] === 'POST') handle_edit_kelas();
if (isset($_GET['action']) && $_GET['action'] === 'delete_kelas' && $_SERVER['REQUEST_METHOD'] === 'POST') handle_delete_kelas();
if (isset($_GET['action']) && $_GET['action'] === 'delete_institusi' && $_SERVER['REQUEST_METHOD'] === 'POST') handle_delete_institusi();
// === API BARU UNTUK CARD BROWSER ===
if (isset($_GET['action']) && $_GET['action'] === 'api_get_subthemes') {
  header('Content-Type: application/json; charset=UTF-8');
  $theme_id = (int)($_GET['theme_id'] ?? 0);
  if ($theme_id > 0) {
    // HANYA subtema GLOBAL yang boleh muncul di beranda
    $data = q("
      SELECT id, name
      FROM subthemes
      WHERE theme_id = ?
        AND owner_user_id IS NULL
      ORDER BY name
    ", [$theme_id])->fetchAll();

    echo json_encode($data);
  } else {
    echo json_encode([]);
  }
  exit;
}





if (isset($_GET['action']) && $_GET['action'] === 'api_get_titles') {
  header('Content-Type: application/json; charset=UTF-8');
  $subtheme_id = (int)($_GET['subtheme_id'] ?? 0);
  if ($subtheme_id > 0) {
    $data = q("
      SELECT id, title AS name
      FROM quiz_titles
      WHERE subtheme_id = ? 
        AND owner_user_id IS NULL
      ORDER BY title
    ", [$subtheme_id])->fetchAll();
    echo json_encode($data);
  } else {
    echo json_encode([]);
  }
  exit;
}


// =================================================================
// BLOK BARU: QUIZ STARTER GUARD (Mendukung Tugas & Kuis Biasa)
// =================================================================

// Cek jika ini adalah permintaan untuk memulai TUGAS
if (($page ?? '') === 'play' && isset($_GET['assignment_id']) && !isset($_GET['i'])) {
    $assignment_id = (int)$_GET['assignment_id'];

    // 1. Ambil detail tugas dari database
    $assignment = q("SELECT * FROM assignments WHERE id = ?", [$assignment_id])->fetch();

    // Keamanan: Pastikan tugas ada dan siswa adalah anggota kelas tersebut
    if ($assignment) {
        $is_member = q("SELECT COUNT(*) FROM class_members WHERE id_kelas = ? AND id_pelajar = ?", [$assignment['id_kelas'], uid()])->fetchColumn();

        if ($is_member) {
            // Ambil pengaturan dari tugas
            $title_id = (int)$assignment['id_judul_soal'];
            $mode = $assignment['mode'] === 'bebas' ? 'instant' : $assignment['mode']; // Jika bebas, paksa jadi instant
            $jumlah_soal = $assignment['jumlah_soal'] ?? 10; // Default 10 jika NULL

            // 2. Buat sesi kuis dengan jumlah soal yang ditentukan
            $sid = create_session($title_id, $mode, $jumlah_soal);

            // 3. Simpan SEMUA pengaturan tugas ke dalam session PHP
            $_SESSION['quiz'] = [
                'session_id' => $sid,
                'title_id' => $title_id,
                'mode' => $mode,
                'assignment_id' => $assignment_id, // Simpan ID tugasnya
                'assignment_settings' => [ // Simpan pengaturan spesifiknya
                    'timer_per_soal' => $assignment['timer_per_soal_detik'],
                    'durasi_ujian' => $assignment['durasi_ujian_menit']
                ]
            ];

            // 4. Redirect ke soal pertama, sekarang dengan parameter assignment_id
            redirect("?page=play&assignment_id={$assignment_id}&i=0");
        }
    }
    // Jika tugas tidak valid atau bukan anggota, hentikan.
    echo '<div class="alert alert-danger">Tugas tidak valid atau Anda tidak terdaftar di kelas ini.</div>';
    exit;
}

// Logika untuk memulai KUIS BIASA (tidak berubah)
if (($page ?? '') === 'play' && isset($_GET['mode']) && !isset($_GET['i']) && !isset($_GET['assignment_id'])) {
    $title_id = (int)($_GET['title_id'] ?? 0);
    $mode = $_GET['mode'] ?? 'instant';
    
        // Pastikan session lama ditutup jika beda judul
    $user_id = uid();
    ensure_session_bound_to_title(pdo_instance(), $user_id, $title_id);


    // Panggil create_session tanpa argumen jumlah soal (akan menggunakan default 10)
    $sid = create_session($title_id, $mode);

    // Hapus pengaturan tugas lama jika ada
    unset($_SESSION['quiz']['assignment_settings']);
    unset($_SESSION['quiz']['assignment_id']);

    $_SESSION['quiz'] = ['session_id' => $sid, 'title_id' => $title_id, 'mode' => $mode];
    redirect("?page=play&title_id={$title_id}&mode={$mode}&i=0");
}

// START-PLAY (tanpa mode): JANGAN redirect, biarkan view_play() tampilkan pilihan mode
if (($page ?? '') === 'play'
    && !isset($_GET['i'])
    && !isset($_GET['assignment_id'])
    && !isset($_GET['mode'])
    && isset($_GET['title_id'])) {

    // Hanya clear session lama, JANGAN buat session baru
    // Biarkan view_play() menampilkan pilihan mode
    $title_id = (int)($_GET['title_id'] ?? 0);
    $user_id = uid();
    
    // Putus sesi lama jika beda judul
    ensure_session_bound_to_title(pdo_instance(), $user_id, $title_id);
    
    // JANGAN create_session di sini, JANGAN redirect
    // Biarkan view_play() menangani pilihan mode
}



// Blok untuk "Coba Lagi" (restart=1) tetap diperlukan dan tidak berubah.
// Blok untuk "Coba Lagi" (restart=1)
if (($page ?? '') === 'play' && isset($_GET['restart']) && $_GET['restart'] === '1') {
    
    // Cek apakah ini me-restart TUGAS
    if (isset($_GET['assignment_id'])) {
        $assignment_id = (int)$_GET['assignment_id'];
        $assignment = q("SELECT * FROM assignments WHERE id = ?", [$assignment_id])->fetch();
        
        if ($assignment) {
            $title_id = (int)$assignment['id_judul_soal'];
            $mode = $assignment['mode'] === 'bebas' ? 'instant' : $assignment['mode'];
            $jumlah_soal = $assignment['jumlah_soal'] ?? 10;

            $sid = create_session($title_id, $mode, $jumlah_soal);
            
            // Atur ulang session dengan semua detail tugas
            $_SESSION['quiz'] = [
                'session_id' => $sid,
                'title_id' => $title_id,
                'mode' => $mode,
                'assignment_id' => $assignment_id,
                'assignment_settings' => [
                    'timer_per_soal' => $assignment['timer_per_soal_detik'],
                    'durasi_ujian' => $assignment['durasi_ujian_menit']
                ]
            ];
            redirect("?page=play&assignment_id={$assignment_id}&i=0");
        }
    } else { // Jika ini me-restart KUIS BIASA
        $title_id = (int)($_GET['title_id'] ?? 0);
        $mode = $_GET['mode'] ?? ($_SESSION['quiz']['mode'] ?? 'instant');
        
        $sid = create_session($title_id, $mode); // Jumlah soal default
        $_SESSION['quiz'] = ['session_id' => $sid, 'title_id' => $title_id, 'mode' => $mode];
        redirect("?page=play&title_id={$title_id}&mode={$mode}&i=0");
    }
}


// SAVE SETTINGS (POST) — nama, timer, (dan tema kalau nanti dipakai)
if (isset($_GET['action']) && $_GET['action'] === 'save_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!uid()) {
    http_response_code(403);
    exit('Silakan login.');
  }


  // Nama (opsional)
  $name = isset($_POST['name']) ? trim(strip_tags($_POST['name'])) : null;
  if ($name !== null && $name === '') {
    $name = null;
  } // kosong -> abaikan

  // Timer per soal (opsional)
  $timer = isset($_POST['timer_seconds']) ? (int)$_POST['timer_seconds'] : null;

  // ▼▼▼ TAMBAHKAN INI ▼▼▼
  // Timer Ujian (opsional, hanya admin)
  $examTimer = isset($_POST['exam_timer_minutes']) ? (int)$_POST['exam_timer_minutes'] : null;
  // ▲▲▲ SELESAI ▲▲▲

  // Theme (opsional, untuk nanti)
  $theme = isset($_POST['theme']) ? $_POST['theme'] : null;

  save_user_settings($timer, $theme, $name, $examTimer); // Tambahkan parameter baru
  redirect('?page=setting&ok=1');
}


// ===============================================
// SET THEME (AJAX) — simpan ke DB agar lintas perangkat konsisten
// ===============================================
if (isset($_GET['action']) && $_GET['action'] === 'set_theme' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  if (!uid()) {
    echo json_encode(['ok' => false, 'msg' => 'not_logged_in']);
    exit;
  }
  $theme = $_POST['theme'] ?? '';
  if (!in_array($theme, ['light', 'dark'], true)) {
    echo json_encode(['ok' => false, 'msg' => 'invalid_theme']);
    exit;
  }
  $ok = save_user_settings(null, $theme);
  echo json_encode(['ok' => (bool)$ok]);
  exit;
}

// ===============================================
// UPDATE CITY (AJAX) — update kota untuk sesi yang sedang berjalan
// ===============================================
if (isset($_GET['action']) && $_GET['action'] === 'update_city' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: text/plain; charset=utf-8');
  $sid  = (int)($_POST['sid']  ?? 0);
  $city = trim((string)($_POST['city'] ?? ''));
  if ($sid > 0 && $city !== '') {
    q("UPDATE quiz_sessions SET city=? WHERE id=?", [$city, $sid]);
    echo "ok";
  } else {
    http_response_code(400);
    echo "bad_request";
  }
  exit;
}

// ===============================================
// START CHALLENGE (buat session dari komposisi tantangan)
// ===============================================
if (isset($_GET['action']) && $_GET['action'] === 'start_challenge') {
  $token = $_GET['token'] ?? '';
  if ($token === '') {
    echo '<div class="alert alert-danger m-3">Token tidak valid.</div>';
    exit;
  }

  // Pastikan ada komposisi
  $ch = q("SELECT * FROM challenges WHERE token=?", [$token])->fetch();
  if (!$ch) {
    echo '<div class="alert alert-danger m-3">Tantangan tidak ditemukan.</div>';
    exit;
  }

  $items = q("SELECT question_id, sort_no FROM challenge_items WHERE token=? ORDER BY sort_no", [$token])->fetchAll();
  if (!$items) {
    echo '<div class="alert alert-danger m-3">Tantangan belum memiliki komposisi soal.</div>';
    exit;
  }

  // Buat session baru (mode instant)
  $gid = get_guest_id();
  $city = get_city_name();
  q(
    "INSERT INTO quiz_sessions (user_id,title_id,mode,guest_id,city,created_at) VALUES (?,?,?,?,?,?)",
    [uid(), (int)$ch['title_id'], 'instant', $gid, $city, now()]
  );

  $sid = pdo()->lastInsertId();

  // Isi mapping session-question sesuai urutan tantangan
  $no = 1;
  foreach ($items as $it) {
    q(
      "INSERT INTO quiz_session_questions (session_id,question_id,sort_no) VALUES (?,?,?)",
      [$sid, (int)$it['question_id'], $no++]
    );
  }

  // Set session & redirect langsung ke i=0 (bypass guard auto-start)
  $_SESSION['quiz'] = ['session_id' => $sid, 'title_id' => (int)$ch['title_id'], 'mode' => 'instant'];
  $title_id = (int)$ch['title_id'];
  $_SESSION['current_challenge_token'] = $token;

  redirect("?page=play&title_id={$title_id}&mode=instant&i=0");
}

// ===============================================
// SEO: URL helpers & Breadcrumb JSON-LD
// ===============================================
function current_url()
{
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'quizb.my.id';
  $uri  = strtok($_SERVER['REQUEST_URI'], '#');
  return $scheme . '://' . $host . $uri;
}

function canonical_url()
{
  // buang parameter yang ga perlu (misal session/temp)
  $allowed = ['page', 'theme_id', 'subtheme_id', 'title_id', 'i', 'mode'];
  $query = [];
  foreach ($_GET as $k => $v) {
    if (in_array($k, $allowed, true)) $query[$k] = $v;
  }
  $base = preg_replace('~\?.*$~', '', current_url());
  return $query ? $base . '?' . http_build_query($query) : $base;
}

// >>> GANTI SELURUH FUNGSI LAMA DENGAN KODE BARU INI (Mulai baris ~784) <<<
function breadcrumb_trail()
{
  // 1. Mulai dengan breadcrumb dasar 'Beranda'
  $trail = [
    ['name' => 'Beranda', 'url' => base_url() . '/'] // Pastikan base_url() benar
  ];
  // Ambil nama halaman saat ini dari URL
  $page = $_GET['page'] ?? 'home';

  // 2. Logika untuk Alur Kuis Biasa (Tema > Subtema > Judul > Main)
  //    (Kode ini SAMA seperti di file Anda, tidak perlu diubah)
  if (in_array($page, ['themes', 'subthemes', 'titles', 'play'])) {
    $theme = null; $sub = null; $title = null;
    if (!empty($_GET['theme_id'])) {
        $theme = q("SELECT id,name FROM themes WHERE id=?", [$_GET['theme_id']])->fetch(PDO::FETCH_ASSOC);
        // Tambahkan Nama Tema jika ada
        if ($theme) $trail[] = ['name' => $theme['name'], 'url' => base_url() . '?page=subthemes&theme_id=' . $theme['id']];
    }
    if (!empty($_GET['subtheme_id'])) {
        $sub = q("SELECT id,name,theme_id FROM subthemes WHERE id=?", [$_GET['subtheme_id']])->fetch(PDO::FETCH_ASSOC);
         // Tambahkan Nama Subtema jika ada
        if ($sub) $trail[] = ['name' => $sub['name'], 'url' => base_url() . '?page=titles&subtheme_id=' . $sub['id']];
    }
    if (!empty($_GET['title_id'])) {
        $title = q("SELECT id,title FROM quiz_titles WHERE id=?", [$_GET['title_id']])->fetch(PDO::FETCH_ASSOC);
        // Tambahkan Judul Kuis jika ada
        if ($title) $trail[] = ['name' => $title['title'], 'url' => base_url() . '?page=play&title_id=' . $title['id']];
    }
  }
  // 3. >>> LOGIKA BARU untuk Alur Pengajar <<<
  //    Cek apakah halaman saat ini adalah salah satu halaman pengajar
  elseif (in_array($page, ['kelola_institusi', 'kelola_tugas', 'detail_kelas', 'detail_tugas', 'edit_tugas'])) {
     // a. Tambahkan langkah pertama untuk alur ini: 'Kelola Institusi & Kelas'
     //    Ini adalah link ke halaman pemilihan institusi.
     $trail[] = ['name' => 'Kelola Institusi & Kelas', 'url' => base_url() . '?page=kelola_institusi'];

     // b. Ambil ID dari URL (institusi, kelas, tugas) jika ada
     $inst_id = isset($_GET['inst_id']) ? (int)$_GET['inst_id'] : 0;
     $kelas_id = isset($_GET['id_kelas']) ? (int)$_GET['id_kelas'] : 0;
     $assignment_id = isset($_GET['assignment_id']) ? (int)$_GET['assignment_id'] : 0;

     $inst_name = null; // Variabel untuk menyimpan nama institusi

     // c. Jika ada ID Institusi di URL (artinya kita sudah memilih institusi)...
     if ($inst_id > 0) {
         // Ambil nama institusi dari database
         $inst_name = q("SELECT nama_institusi FROM teacher_institutions WHERE id = ? AND id_pengajar = ?", [$inst_id, uid()])->fetchColumn(); // Tambahkan cek id_pengajar

         // Jika nama institusi ditemukan...
         if ($inst_name) {
              // Tambahkan nama institusi ke breadcrumb.
              // JADIKAN LINK ke halaman 'kelola_tugas' untuk institusi ini.
              // Ini memungkinkan pengguna kembali ke daftar tugas dari halaman detail/edit.
             $trail[] = ['name' => $inst_name, 'url' => base_url() . '?page=kelola_tugas&inst_id=' . $inst_id];
         }
     }

     // d. Logika spesifik berdasarkan halaman saat ini:

     // Jika kita di halaman 'kelola_tugas'...
     if ($page === 'kelola_tugas' && $inst_name) {
         // Tambahkan 'Kelola Tugas' sebagai langkah terakhir (tanpa link, karena kita sudah di sini)
         $trail[] = ['name' => 'Kelola Tugas'];
     }
     // Jika kita di halaman 'detail_kelas'...
     elseif ($page === 'detail_kelas' && $kelas_id > 0) {
        $kelas_info = q("SELECT nama_kelas, id_institusi FROM classes WHERE id = ? AND id_pengajar = ?", [$kelas_id, uid()])->fetch(); // Tambah cek id_pengajar
        if ($kelas_info) {
             // Jika breadcrumb institusi belum ada (misal akses URL langsung), coba tambahkan
            if (!$inst_name && $kelas_info['id_institusi']) {
                 $inst_name_kelas = q("SELECT nama_institusi FROM teacher_institutions WHERE id = ? AND id_pengajar = ?", [$kelas_info['id_institusi'], uid()])->fetchColumn();
                 if ($inst_name_kelas) $trail[] = ['name' => $inst_name_kelas, 'url' => base_url() . '?page=kelola_tugas&inst_id=' . $kelas_info['id_institusi']];
            }
            // Tambahkan nama kelas sebagai langkah terakhir (tanpa link)
            $trail[] = ['name' => 'Detail Kelas: ' . $kelas_info['nama_kelas']];
        }
     }
     // Jika kita di halaman 'detail_tugas' atau 'edit_tugas'...
     elseif (($page === 'detail_tugas' || $page === 'edit_tugas') && $assignment_id > 0) {
         // Ambil info tugas (termasuk ID institusi untuk fallback)
         $tugas_info = q("SELECT a.judul_tugas, c.id_institusi FROM assignments a JOIN classes c ON a.id_kelas = c.id WHERE a.id = ? AND a.id_pengajar = ?", [$assignment_id, uid()])->fetch(); // Tambah cek id_pengajar
         if ($tugas_info) {
              // Jika breadcrumb institusi belum ada, coba tambahkan
             if (!$inst_name && $tugas_info['id_institusi']) {
                  $inst_name_tugas = q("SELECT nama_institusi FROM teacher_institutions WHERE id = ? AND id_pengajar = ?", [$tugas_info['id_institusi'], uid()])->fetchColumn();
                  if ($inst_name_tugas) $trail[] = ['name' => $inst_name_tugas, 'url' => base_url() . '?page=kelola_tugas&inst_id=' . $tugas_info['id_institusi']];
             }
              // Pastikan breadcrumb 'Kelola Tugas' ada SEBELUM nama tugas
             $last_breadcrumb = end($trail); // Lihat breadcrumb terakhir
             // Jika breadcrumb terakhir BUKAN 'Kelola Tugas' DAN kita punya nama institusi
             if ($last_breadcrumb && $last_breadcrumb['name'] !== 'Kelola Tugas' && ($inst_name || isset($inst_name_tugas))) {
                 $current_inst_id = $inst_id ?: $tugas_info['id_institusi']; // Tentukan ID institusi yang benar
                 // Tambahkan link 'Kelola Tugas'
                 $trail[] = ['name' => 'Kelola Tugas', 'url' => base_url() . '?page=kelola_tugas&inst_id=' . $current_inst_id];
             }
             // Tambahkan nama tugas (detail atau edit) sebagai langkah terakhir (tanpa link)
             if ($page === 'detail_tugas') {
                 $trail[] = ['name' => 'Detail Tugas: ' . $tugas_info['judul_tugas']];
             } else { // edit_tugas
                 $trail[] = ['name' => 'Edit Tugas: ' . $tugas_info['judul_tugas']];
             }
         }
     }
  }
   // 4. Logika untuk halaman profil, setting, dll (SAMA seperti sebelumnya)
  elseif ($page === 'profile') {
      $trail[] = ['name' => 'Profil', 'url' => base_url() . '?page=profile'];
  }
  elseif ($page === 'setting') {
      $trail[] = ['name' => 'Pengaturan', 'url' => base_url() . '?page=setting'];
  }
  // ... (tambahkan case lain jika perlu) ...

  // 5. Kembalikan array $trail yang sudah dibangun
  return $trail;
}
// >>> AKHIR DARI KODE BARU <<<

// >>> GANTI SELURUH FUNGSI LAMA DENGAN KODE BARU INI (Mulai baris ~897) <<<
function echo_breadcrumb_jsonld()
{
  $trail = breadcrumb_trail(); // Ambil data breadcrumb
  $items = [];
  $pos = 1;
  foreach ($trail as $t) {
    // *** PERBAIKAN DI SINI ***
    // Hanya tambahkan 'item' (URL) jika memang ada di array $t
    $item_data = [
      "@type" => "ListItem",
      "position" => $pos++,
      "name" => $t['name'] // Nama selalu ada
    ];
    // Cek apakah 'url' ada sebelum menambahkannya
    if (isset($t['url'])) {
        $item_data["item"] = $t['url'];
    }
    // *** AKHIR PERBAIKAN ***
    $items[] = $item_data; // Tambahkan ke array $items
  }
  // Buat struktur JSON-LD
  $json = [
    "@context" => "https://schema.org",
    "@type" => "BreadcrumbList",
    "itemListElement" => $items
  ];
  // Cetak JSON-LD ke dalam tag script
  echo '<script type="application/ld+json">' . json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
}
// >>> AKHIR DARI KODE BARU <<<

// API: daftar subtema by theme (untuk dropdown di modal pindah judul)
if (isset($_GET['action']) && $_GET['action'] === 'api_subthemes') {
  if (!is_admin()) {
    http_response_code(403);
    exit('Forbidden');
  }
  $theme_id = (int)($_GET['theme_id'] ?? 0);
  header('Content-Type: application/json; charset=UTF-8');
  if ($theme_id <= 0) {
    echo '[]';
    exit;
  }
  $rows = q("SELECT id, name FROM subthemes WHERE theme_id=? ORDER BY name", [$theme_id])->fetchAll(PDO::FETCH_ASSOC);
  echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}



// ▼▼▼ AWAL BLOK PERBAIKAN CACHING ▼▼▼

// Tentukan halaman mana yang dinamis dan tidak boleh di-cache
$no_cache_pages = [
    'home', 'play', 'profile', 'admin', 'qmanage', 
    'setting', 'pesan', 'welcome', 'notifikasi',
    'kelola_user', 'kelola_kelas', 'detail_kelas', 'detail_tugas',
    'kelola_tugas' // <--- TAMBAHKAN BARIS INI
];

if (in_array($page, $no_cache_pages) || isset($_GET['action'])) {
    // Header ini memaksa browser untuk selalu meminta versi baru dari server
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    
} else {
    // Halaman lain (seperti about, privacy) boleh di-cache
    header("Cache-Control: private, max-age=3600"); // Cache selama 1 jam
}



// =================================================================
// REDIRECT SISWA JIKA BELUM MEMILIH KELAS
// =================================================================
if (uid() && ($_SESSION['user']['role'] ?? '') === 'pelajar') {
  $page = $_GET['page'] ?? 'home';
  // Cek apakah siswa sudah punya kelas
  $kelas_count = q("SELECT COUNT(*) FROM class_members WHERE id_pelajar = ?", [uid()])->fetchColumn();

  // Jika belum punya kelas, dan TIDAK sedang di halaman kelola kelas, welcome, atau logout,
  // paksa redirect ke halaman kelola kelas.
  if ($kelas_count == 0 && $page !== 'kelola_kelas' && ($_GET['action'] ?? '') !== 'logout' && $page !== 'welcome') {
    redirect('?page=kelola_kelas');
  }
}
// ▲▲▲ AKHIR BLOK BARU ▲▲▲


// ▲▲▲ AKHIR BLOK PERBAIKAN CACHING ▲▲▲
// ===============================================
// VIEW: HEAD
// ===============================================
html_head();

switch ($page) {
  case 'home':
    view_home();
    break;
  case 'themes':
    view_themes();
    break;
  case 'explore':
    view_explore();
    break;
  case 'kelola_user':
    view_kelola_user();
    break;
    case 'kelola_kelas': // Halaman untuk siswa memilih kelas
    if (uid()) view_kelola_kelas_siswa(); // Pastikan hanya user login yang bisa akses
    else redirect('./');
    break;
case 'kelola_institusi': // Nama halaman baru untuk institusi & kelas
       if (is_pengajar() || is_admin()) view_kelola_institusi_kelas();
       else redirect('./');
       break;
  case 'kelola_tugas': // Halaman baru khusus tugas
       // Ambil inst_id dari URL, pastikan integer
       $inst_id_from_url = isset($_GET['inst_id']) ? (int)$_GET['inst_id'] : 0;
       if (is_pengajar() || is_admin()) view_kelola_tugas($inst_id_from_url);
       else redirect('./');
       break;    
    
  case 'detail_kelas':
    view_detail_kelas();
    break;
      
  case 'detail_tugas':
    view_detail_tugas();
    break;
  case 'edit_tugas':
    view_edit_tugas();
    break;
  case 'broadcast':
    view_broadcast();
    break;
  case 'subthemes':
    view_subthemes();
    break;
  case 'titles':
    view_titles();
    break;
  case 'play':
    view_play();
    break;
  case 'profile':
    view_profile();
    break;
  case 'admin':
    view_admin();
    break;
  case 'challenge':
    view_challenge();
    break;
  case 'qmanage':
    view_qmanage();
    break;
  case 'teacher_crud':
    // handled earlier via direct call (see above); avoid falling through to default 404
    break;
  case 'teacher_qmanage':
    // handled earlier via direct call (see above); avoid falling through to default 404
    break;
  case 'setting':
    view_setting();
    break;
  case 'challenges':
    view_challenges_list();
    break;
  case 'crud':
    view_crud();
    break;
  case 'about':
    view_about();
    break;
  case 'privacy':
    view_privacy();
    break;
  case 'feedback': // TAMBAHKAN CASE BARU
    view_feedback();
    break;
  case 'pesan':
    view_pesan();
    break;
  case 'welcome':
    view_welcome();
    break;
  case 'notifikasi':
    view_notifikasi();
    break;
  case 'difficulty':
    if (!guard_admin()) {
      break;
    }
    view_difficulty_titles();
    break;
  case 'difficulty_questions':
    if (!guard_admin()) {
      break;
    }
    $title_id = isset($_GET['title_id']) ? (int)$_GET['title_id'] : 0;
    view_difficulty_questions($title_id);
    break;
 case 'review': // <--- BLOK BARU
    if (!uid()) redirect('./');
    view_review_summary();
    break;

 case 'student_tasks':
    view_student_tasks();
    break;
  case 'monitor_jawaban':
    if (is_pengajar() || is_admin()) view_monitor_jawaban();
    else redirect('./');
    break;
  default:
    echo '<div class="container py-5"><h3>404</h3></div>';
    break;
}

html_foot();

/**
 * API untuk mengambil daftar kelas berdasarkan nama institusi.
 * [VERSI PERBAIKAN] Menggunakan LIKE untuk pencocokan nama yang lebih fleksibel.
 */
function api_get_classes_by_institution_name()
{
  header('Content-Type: application/json; charset=UTF-8');

  if (!uid()) {
    http_response_code(403);
    echo json_encode(['error' => 'Login diperlukan.']);
    exit;
  }

  $nama_institusi = trim($_GET['nama_sekolah'] ?? '');

  if (empty($nama_institusi)) {
    echo json_encode([]);
    exit;
  }

  // ▼▼▼ PERBAIKAN UTAMA ADA DI SINI ▼▼▼
  // Menggunakan "LIKE" agar pencarian tidak terlalu kaku.
  // Contoh: "SMA Negeri 1" akan cocok dengan "SMAN 1".
  $classes = q("
        SELECT c.id, c.nama_kelas 
        FROM classes c
        JOIN teacher_institutions ti ON c.id_institusi = ti.id
        WHERE ti.nama_institusi LIKE ?
        ORDER BY c.nama_kelas ASC
    ", ['%' . $nama_institusi . '%'])->fetchAll(PDO::FETCH_ASSOC);
  // ▲▲▲ AKHIR DARI PERBAIKAN ▲▲▲

  echo json_encode($classes);
  exit;
}


function api_get_master_titles() {
    // Pastikan user adalah pengajar/admin untuk mengakses master titles
    if (!is_pengajar() && !is_admin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    
    header('Content-Type: application/json; charset=UTF-8');
    // Hanya tampilkan Judul yang owner_user_id IS NULL (atau ID Admin) dan is_master = 1
    $titles = q("
        SELECT 
            qt.id, CONCAT(t.name, ' › ', st.name, ' › ', qt.title) AS label
        FROM quiz_titles qt
        JOIN subthemes st ON st.id = qt.subtheme_id
        JOIN themes t ON t.id = st.theme_id
        WHERE qt.is_master = 1 AND (qt.owner_user_id IS NULL OR qt.owner_user_id = 1) /* Asumsi ID Admin Master = 1 */
        ORDER BY t.name, st.name, qt.title
    ")->fetchAll();
    
    echo json_encode($titles);
    exit;
}

// LETAKKAN INI DI DEKAT FUNGSI-FUNGSI LAIN, MISALNYA SEBELUM html_head()

/**
 * API Endpoint untuk mengambil seluruh data kuis sesi dalam format JSON.
 * Ini akan digunakan oleh JavaScript untuk menjalankan kuis tanpa reload.
 */
function api_get_quiz()
{
  header('Content-Type: application/json; charset=UTF-8');

  // Validasi dasar
  if (!isset($_SESSION['quiz']['session_id'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Sesi kuis tidak aktif.']);
    exit;
  }

  $sid = (int)$_SESSION['quiz']['session_id'];

  // Ambil info dasar sesi dan judul
  $session_info = q("
        SELECT s.id as session_id, s.title_id, t.title
        FROM quiz_sessions s
        JOIN quiz_titles t ON s.title_id = t.id
        WHERE s.id = ?
    ", [$sid])->fetch();

  if (!$session_info) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Sesi tidak ditemukan.']);
    exit;
  }

  // Ambil semua pertanyaan untuk sesi ini
  $questions = q("
        SELECT q.id, q.text, q.explanation
        FROM quiz_session_questions m
        JOIN questions q ON q.id = m.question_id
        WHERE m.session_id = ?
        ORDER BY m.sort_no
    ", [$sid])->fetchAll();

  // Ambil semua pilihan jawaban sekaligus untuk efisiensi
  $question_ids = array_column($questions, 'id');
  $choices_flat = [];
  if ($question_ids) {
    $in_clause = implode(',', array_fill(0, count($question_ids), '?'));
    $choices_flat = q("
            SELECT id, question_id, text, is_correct
            FROM choices
            WHERE question_id IN ($in_clause)
        ", $question_ids)->fetchAll();
  }

  // Kelompokkan pilihan jawaban per pertanyaan
  $choices_by_question = [];
  foreach ($choices_flat as $choice) {
    $choices_by_question[$choice['question_id']][] = $choice;
  }

  // Gabungkan pertanyaan dengan pilihan jawabannya
  foreach ($questions as &$q) {
    $q_id = $q['id'];
    $shuffled_choice_ids = get_shuffled_choice_ids($q_id, $sid);
    $choices_in_order = [];
    $available_choices = $choices_by_question[$q_id] ?? [];

    // Buat map untuk lookup cepat
    $choice_map = [];
    foreach ($available_choices as $c) {
      $choice_map[$c['id']] = $c;
    }

    // Susun sesuai urutan yang sudah di-shuffle
    foreach ($shuffled_choice_ids as $cid) {
      if (isset($choice_map[$cid])) {
        // Kirim hanya data yang relevan ke client
        $choices_in_order[] = [
          'id' => (int)$choice_map[$cid]['id'],
          'text' => $choice_map[$cid]['text'],
          'is_correct' => (bool)$choice_map[$cid]['is_correct']
        ];
      }
    }
    $q['choices'] = $choices_in_order;
  }

  // Siapkan data final
  $output = [
    'ok' => true,
    'session' => $session_info,
    'questions' => $questions
  ];

  echo json_encode($output);
  exit; // Penting! Hentikan eksekusi agar tidak ada output HTML lain.
}

/**
 * API Endpoint untuk menerima dan menyimpan semua jawaban dari kuis.
 * [VERSI PERBAIKAN] Menangani kasus waktu habis (choice_id = 0).
 */
function api_submit_answers()
{
  header('Content-Type: application/json; charset=UTF-8');

  // Ambil data JSON dari body request
  $input = json_decode(file_get_contents('php://input'), true);

  $sid = (int)($input['session_id'] ?? 0);
  $answers = $input['answers'] ?? [];

  // Validasi dasar
  if ($sid === 0 || !is_array($answers)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Data tidak valid.']);
    exit;
  }

  // Pastikan sesi ini milik user yang sedang aktif
  $session_owner = q("SELECT user_id FROM quiz_sessions WHERE id = ?", [$sid])->fetchColumn();
  if ($session_owner !== null && $session_owner != uid()) {
    // Jika sesi punya user lain, tolak. (Tamu (null) boleh)
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Sesi tidak sah.']);
    exit;
  }

  // Hapus attempt lama jika ada (untuk mencegah duplikasi jika user refresh halaman)
  q("DELETE FROM attempts WHERE session_id = ?", [$sid]);

  // Simpan setiap jawaban
  foreach ($answers as $ans) {
    $qid = (int)($ans['question_id'] ?? 0);
    $cid = (int)($ans['choice_id'] ?? 0);
    $is_correct = (int)($ans['is_correct'] ?? 0);

    // ▼▼▼ BLOK PERBAIKAN UTAMA ADA DI SINI ▼▼▼
    if ($qid > 0 && $cid === 0) { // Jika waktu habis (choice_id adalah 0)
        // Ambil ID salah satu jawaban yang salah untuk soal ini sebagai fallback
        $fallback_choice_id = q("SELECT id FROM choices WHERE question_id = ? AND is_correct = 0 LIMIT 1", [$qid])->fetchColumn();
        
        // Jika ada jawaban salah, gunakan ID-nya. Jika tidak ada (kasus langka), lewati saja.
        if ($fallback_choice_id) {
            $cid = (int)$fallback_choice_id;
        } else {
            continue; // Lewati iterasi ini untuk menghindari error database
        }
    }
    // ▲▲▲ AKHIR BLOK PERBAIKAN ▲▲▲

    if ($qid > 0) {
      q(
        "INSERT INTO attempts (session_id, question_id, choice_id, is_correct, created_at) VALUES (?, ?, ?, ?, ?)",
        [$sid, $qid, $cid, $is_correct, now()]
      );

      // Update statistik per pertanyaan
      q("UPDATE questions SET attempts = attempts + 1, corrects = corrects + ? WHERE id = ?", [$is_correct, $qid]);
    }
  }

  // Dapatkan info sesi (title_id dan mode) untuk membuat URL summary
  $session_info = q("SELECT title_id, mode FROM quiz_sessions WHERE id = ?", [$sid])->fetch();
  if (!$session_info) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Sesi tidak ditemukan.']);
    exit;
  }

  $title_id = $session_info['title_id'];
  $mode = $session_info['mode'];

  $total_questions = q("SELECT COUNT(*) FROM quiz_session_questions WHERE session_id = ?", [$sid])->fetchColumn();

  // Buat URL yang lengkap dengan menyertakan &mode=...
  $summaryUrl = "?page=play&title_id=" . $title_id . "&mode=" . $mode . "&i=" . $total_questions;

  echo json_encode(['ok' => true, 'summaryUrl' => $summaryUrl]);
  exit;
}


/**
 * API Endpoint untuk mengambil konten halaman yang sudah di-render
 * untuk navigasi SPA/AJAX.
 */
function api_get_page_content()
{
  // PENTING: Kita mengirim HTML, bukan JSON
  header('Content-Type: text/html; charset=UTF-8');

  $page = $_GET['page'] ?? 'home';

  // Kita harus *hanya* me-render konten, bukan <head> atau <footer>.
  // Kita panggil fungsi view original Anda dan menangkap output 'echo' nya.
  ob_start();
  switch ($page) {
    case 'home':
      view_home();
      break;
    case 'themes': // Ini adalah halaman "Pencarian" Anda
      view_themes();
      break;
    case 'explore':
      view_explore();
      break;
    case 'challenges':
      view_challenges_list();
      break;
    case 'pesan':
      view_pesan();
      break;
    case 'teacher_crud':
      // Render teacher CRUD content for SPA/api_get_page_content
      view_crud_pengajar();
      break;
    case 'teacher_qmanage':
      // Render teacher question management content for SPA/api_get_page_content
      view_qmanage_pengajar();
      break;
    /* ▼▼▼ TAMBAHKAN KASUS BARU UNTUK HALAMAN TUGAS INI ▼▼▼ */
    case 'student_tasks':
      view_student_tasks();
      break;
    case 'kelola_tugas':
      // Dapatkan inst_id dari URL untuk memanggil fungsi view_kelola_tugas()
      $inst_id_from_url = isset($_GET['inst_id']) ? (int)$_GET['inst_id'] : 0;
      view_kelola_tugas($inst_id_from_url);
      break;
    /* ▲▲▲ AKHIR KASUS BARU ▲▲▲ */  
    case 'notifikasi':
      view_notifikasi();
      break;
    // ▲▲▲ SELESAI ▲▲▲    

    case 'profile':
      view_profile();
      break;
    case 'about':
      view_about();
      break;
    case 'privacy':
      view_privacy();
      break;
    // Tambahkan case lain jika diperlukan
    default:
      echo '<div class="container py-5"><h3>404: Halaman tidak ditemukan</h3></div>';
  }
  $html = ob_get_clean();

  echo $html; // Kirim hanya konten HTML mentahnya
  exit;
}


// ===============================================
// VIEW HELPERS
// ===============================================
function html_head()
{
  global $CONFIG;
  $u = $_SESSION['user'] ?? null;
  $cid = h($CONFIG['GOOGLE_CLIENT_ID']);


  // ▼▼▼ GANTI SELURUH BLOK INI ▼▼▼
  $unread_messages = 0;
  $unread_notifications = 0;
  if ($u) {
    // Hitung pesan pribadi yang belum dibaca
    $unread_messages = (int)q("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0", [uid()])->fetchColumn();

    // Hitung notifikasi broadcast yang belum dibaca
    $total_broadcast = (int)q("SELECT COUNT(*) FROM broadcast_notifications")->fetchColumn();
    $read_broadcast = (int)q("SELECT COUNT(*) FROM user_notification_reads WHERE user_id = ?", [uid()])->fetchColumn();
    $unread_broadcast = $total_broadcast - $read_broadcast;

    // Hitung notifikasi personal (tugas, dll) yang belum dibaca
    $unread_personal = (int)q("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0", [uid()])->fetchColumn();

    // Jumlahkan keduanya
    $unread_notifications = $unread_broadcast + $unread_personal;
  }
  // ▲▲▲ AKHIR BLOK PENGGANTIAN ▲▲▲



  echo '<!doctype html><html lang="id"><head>';
  echo '<link rel="icon" type="image/x-icon" href="/favicon.png">
  <link rel="shortcut icon" href="/favicon.png">';
  echo '<script>
(function(){
  var t = localStorage.getItem("quizb_theme") || "light";
  document.documentElement.setAttribute("data-bs-theme", t);
})();
</script>';

  echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
  // 1. Link ke file manifest
  echo '<link rel="manifest" href="/manifest.json">';

  // 2. Script untuk mendaftarkan Service Worker
  echo <<<JS
  <script>
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', function() {
        navigator.serviceWorker.register('/sw.js').then(function(registration) {
          console.log('ServiceWorker registration successful with scope: ', registration.scope);
        }, function(err) {
          console.log('ServiceWorker registration failed: ', err);
        });
      });
    }
  </script>
  JS;


  // ▼▼▼ PASTE SELURUH BLOK KODE DI BAWAH INI ▼▼▼

  // --- Logika Cerdas untuk Judul & Deskripsi SEO ---
  $page = $_GET['page'] ?? 'home';

  // Default untuk Homepage dan halaman lain
  $og_title = 'QuizB | Kuis Berkah: Asah Pengetahuan, Tantang Temanmu!';
  $og_description = 'Uji wawasanmu dengan kuis seru di berbagai bidang! Dari pengetahuan umum hingga agama. Buat rekor baru dan tantang temanmu untuk adu skor di QuizB!';
  $og_image = 'https://quizb.my.id/og-image.png'; // PENTING: Buat gambar ini!
  $og_url = canonical_url();

  // Kustomisasi jika sedang di halaman kuis spesifik
  if ($page === 'play' && !empty($_GET['title_id'])) {
    $title_info = q("SELECT title FROM quiz_titles WHERE id=?", [$_GET['title_id']])->fetch();
    if ($title_info) {
      $og_title = 'Mulai Kuis: ' . h($title_info['title']) . ' | QuizB';
      $og_description = 'Seberapa jauh pengetahuanmu tentang ' . h($title_info['title']) . '? Mainkan kuisnya sekarang di QuizB dan raih skor tertinggi!';
    }
  }

  // --- Output Tag Meta ke HTML ---

  // Untuk Google Search & Tab Browser
  echo '<title>' . h($og_title) . '</title>';
  echo '<meta name="description" content="' . h($og_description) . '">';

  // Untuk WhatsApp, Facebook, Telegram (Open Graph)
  echo '<meta property="og:title" content="' . h($og_title) . '" />';
  echo '<meta property="og:description" content="' . h($og_description) . '" />';
  echo '<meta property="og:type" content="website" />';
  echo '<meta property="og:url" content="' . h($og_url) . '" />';
  echo '<meta property="og:image" content="' . h($og_image) . '" />';
  echo '<meta property="og:image:width" content="1200" />';
  echo '<meta property="og:image:height" content="630" />';
  echo '<meta property="og:site_name" content="QuizB" />';

  // Untuk Twitter Card
  echo '<meta name="twitter:card" content="summary_large_image">';
  echo '<meta name="twitter:title" content="' . h($og_title) . '">';
  echo '<meta name="twitter:description" content="' . h($og_description) . '">';
  echo '<meta name="twitter:image" content="' . h($og_image) . '">';

  // ▲▲▲ AKHIR BLOK KODE UNTUK DI-PASTE ▲▲▲


  echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
  // SweetAlert2 CDN
  echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>';

  // ===========================================
  // Inisialisasi Google Sign-In
  // ===========================================
  // GANTI KESELURUHAN BLOK SCRIPT GOOGLE YANG LAMA
  // DENGAN BLOK KODE FINAL YANG SUDAH DIPERBAIKI INI:

  echo '<script src="https://accounts.google.com/gsi/client" async defer></script>';

  echo "<script>
  // ==========================================================
  // LOGIKA BARU & ANDAL UNTUK GOOGLE SIGN-IN
  // ==========================================================

  // --- Step 1: Siapkan penanda status ---
  let isGoogleScriptReady = false;
  let isPageLoaded = false;

  // --- Step 2: Fungsi callback setelah login berhasil (tidak berubah) ---
  function onGoogleSignIn(r) {
    const f = document.createElement('form');f.method = 'POST';f.action = '?action=google_login';
    const i = document.createElement('input');i.type = 'hidden';i.name = 'credential';i.value = r.credential;
    f.appendChild(i);document.body.appendChild(f);f.submit();
  }

  // --- Step 3: Fungsi inti untuk merender semua tombol yang ada di halaman ---
  function renderAllGoogleButtons() {
    if (!window.google || !google.accounts || !google.accounts.id) {
      // Jika library Google belum siap, kita tidak bisa melakukan apa-apa.
      return;
    }
    
    // Cari semua placeholder tombol di halaman
    document.querySelectorAll('.g_id_signin').forEach(div => {
      // Hanya render jika div tersebut masih kosong untuk mencegah tombol duplikat
      if (div.childElementCount === 0) { 
        google.accounts.id.renderButton(div, {
            type: div.dataset.type || 'standard', size: div.dataset.size || 'large',
            theme: div.dataset.theme || 'outline', text: div.dataset.text || 'signin_with',
            shape: div.dataset.shape || 'rectangular', logo_alignment: div.dataset.logoAlignment || 'left'
        });
      }
    });
  }

  // --- Step 4: Pemicu yang akan dipanggil dari dua tempat berbeda ---
  function tryRenderInitialButtons() {
    // Fungsi ini hanya akan berjalan jika KEDUA kondisi terpenuhi:
    // 1. Halaman sudah siap.
    // 2. Skrip Google sudah siap.
    if (isPageLoaded && isGoogleScriptReady) {
      renderAllGoogleButtons();
    }
  }

  // --- Step 5: Fungsi yang dipanggil oleh skrip Google setelah selesai dimuat ---
(function waitForGIS(){
  if (window.google && google.accounts && google.accounts.id) {
    try {
      google.accounts.id.initialize({
        client_id: '" . h($CONFIG['GOOGLE_CLIENT_ID']) . "',
        callback: onGoogleSignIn,
        auto_select: false,
        cancel_on_tap_outside: true
      });
      isGoogleScriptReady = true;
      tryRenderInitialButtons();
    } catch(e) {
      console.error('Init GIS error:', e);
    }
    return;
  }
  // Cek ulang setiap 150ms sampai GIS siap
  setTimeout(waitForGIS, 150);
})();

  // --- Step 6: Listener yang dijalankan setelah SEMUA konten halaman (termasuk gambar) selesai ---
  window.addEventListener('load', () => {
    isPageLoaded = true;      // Tandai halaman sudah siap
    tryRenderInitialButtons();    // Coba render, mungkin skrip Google sudah siap duluan
  });

  // ==========================================================
  // AKHIR DARI BLOK GOOGLE SIGN-IN
  // ==========================================================
</script>";
  // ===========================================
  // Akhir Inisialisasi Google Sign-In
  // ===========================================

  echo '<style>.brand{font-weight:800;letter-spacing:.3px}.avatar{width:32px;height:32px;border-radius:50%;object-fit:cover}.quiz-card{transition:transform .1s}.quiz-card:hover{transform:translateY(-2px)}</style>';
  echo '<style>
    .brand{font-weight:800;letter-spacing:.3px}
    .avatar{width:32px;height:32px;border-radius:50%;object-fit:cover}
    .quiz-card{transition:transform .1s}
    .quiz-card:hover{transform:translateY(-2px)}

    /* ===== Kustomisasi Tombol Pilihan Jawaban Mode Instan ===== */
    .answer-btn {
        padding: 1rem 2rem;
        font-size: 1.1rem;
        font-weight: 500;
        border-width: 3px;
    }

    .d-grid.gap-2 {
        gap: 2rem !important; /* Memberi jarak antar tombol */
    }

</style>';


  echo '<style>
/* ========== THEME TOKENS (header) ========== */



/* ========== HAMBURGER → "X" ANIMATION ========== */
.navbar .navbar-toggler.hamburger{
  padding: 8px 10px;
  border: 1px solid var(--bs-navbar-toggler-border-color, rgba(0,0,0,.1));
  border-radius: .5rem;
  background: transparent;
}

/* Container 3 garis */
.navbar .hamburger-lines{
  position: relative;
  display: inline-block;
  width: 26px;
  height: 18px;
}

/* Garis-garis */
.navbar .hamburger-lines span{
  position: absolute;
  left: 0; right: 0;
  height: 2px;
  background: var(--header-fg) !important; /* ikut tema terang/gelap */
  border-radius: 2px;
  transition: transform 200ms ease, opacity 150ms ease;
}

/* Posisi default */
.navbar .hamburger-lines span:nth-child(1){ top: 0; }
.navbar .hamburger-lines span:nth-child(2){ top: 8px; }
.navbar .hamburger-lines span:nth-child(3){ top: 16px; }

/* Saat menu terbuka (Bootstrap set aria-expanded=true) */
.navbar .navbar-toggler[aria-expanded="true"] .hamburger-lines span:nth-child(1){
  transform: translateY(8px) rotate(45deg);
}
.navbar .navbar-toggler[aria-expanded="true"] .hamburger-lines span:nth-child(2){
  opacity: 0;
}
.navbar .navbar-toggler[aria-expanded="true"] .hamburger-lines span:nth-child(3){
  transform: translateY(-8px) rotate(-45deg);
}

/* Kurangi animasi bila prefer-reduced-motion */
@media (prefers-reduced-motion: reduce){
  .navbar .hamburger-lines span{ transition: none; }
}

:root{
  --header-bg:#ffffff;
  --header-fg:#111111; /* warna teks "quizb" & ikon menu (mode terang) */
}
[data-bs-theme="dark"]{
  --header-bg:#0b0f14; /* latar header saat gelap */
  --header-fg:#f5f7fa; /* warna teks "quizb" & ikon menu saat gelap */
}

/* ========== Bootstrap Navbar: sinkron dengan tema ========== */
.navbar{
  background:var(--header-bg)!important;
  /* atur variabel warna bawaan navbar Bootstrap */
  --bs-navbar-color:var(--header-fg);
  --bs-navbar-hover-color:var(--header-fg);
  --bs-navbar-active-color:var(--header-fg);
  --bs-navbar-brand-color:var(--header-fg);
  /* border toggler */
  --bs-navbar-toggler-border-color:rgba(255,255,255,.25);
}
/* Toggler icon (hamburger) untuk LIGHT */
[data-bs-theme="light"] .navbar{
  --bs-navbar-toggler-border-color:rgba(0,0,0,.1);
  --bs-navbar-toggler-icon-bg:url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 30 30\'%3E%3Cpath stroke=\'rgba(17,17,17,0.85)\' stroke-width=\'2\' stroke-linecap=\'round\' d=\'M4 7h22M4 15h22M4 23h22\'/%3E%3C/svg%3E");
}
/* Toggler icon (hamburger) untuk DARK */
[data-bs-theme="dark"] .navbar{
  --bs-navbar-toggler-icon-bg:url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 30 30\'%3E%3Cpath stroke=\'rgba(245,247,250,0.95)\' stroke-width=\'2\' stroke-linecap=\'round\' d=\'M4 7h22M4 15h22M4 23h22\'/%3E%3C/svg%3E");
}

/* Teks judul/brand: "quizb" */
.navbar .navbar-brand.brand{
  color:var(--header-fg)!important;
  font-weight:800;
  letter-spacing:.3px;
  text-shadow:0 0 1px rgba(0,0,0,.25);
}

/* Pastikan Bootstrap pakai icon yang kita set */
.navbar .navbar-toggler .navbar-toggler-icon{
  background-image:var(--bs-navbar-toggler-icon-bg)!important;
}


<style>
/* Sedikit mencerahkan latar navbar saat dark mode */
[data-bs-theme="dark"] .navbar{
  background:#0e141b !important;
}

</style>';

  // ===================================================================
  // TAMBAHKAN BLOK STYLE BARU INI UNTUK POSISI BADGE
  echo '<style>
    .nav-item-icon {
        position: relative;
    }
    .nav-item-icon .badge {
        position: absolute;
        top: -5px;
        right: -8px;
        font-size: 0.6em;
        padding: 0.25em 0.4em;
    }
  </style>';
  // ===================================================================

  // ===================================================================
  // ▼▼▼ 1. LETAKKAN BLOK CSS INI DI DALAM html_head() ▼▼▼
  // ===================================================================
  echo <<<CSS
<style>
  /* ======== LOADER ANIMASI KUSTOM QUIZB ======== */
  .quizb-loader-container {
      position: fixed;
      inset: 0;
      z-index: 9999;
      background-color: rgba(255, 255, 255, 0.85);
      display: flex;
      align-items: center;
      justify-content: center;
      opacity: 1;
      visibility: visible;
      transition: opacity 0.5s ease, visibility 0.5s ease;
  }
  [data-bs-theme="dark"] .quizb-loader-container {
      background-color: rgba(11, 15, 20, 0.85);
  }
  .quizb-loader {
      width: 80px;
      height: 80px;
  }
  .quizb-loader .q-shape {
      fill: var(--bs-primary);
  }
  .quizb-loader .dot {
      fill: var(--bs-primary);
      animation: pulse 1.4s infinite ease-in-out both;
  }
  .quizb-loader .dot-1 { animation-delay: -0.32s; }
  .quizb-loader .dot-2 { animation-delay: -0.16s; }
  .quizb-loader-container.hidden {
      opacity: 0;
      visibility: hidden;
  }
  @keyframes pulse {
      0%, 80%, 100% { transform: scale(0); } 
      40% { transform: scale(1.0); }
  }
</style>
CSS;
  // ===================================================================
  // ▲▲▲ AKHIR BLOK CSS ▲▲▲
  // ===================================================================

  echo '<link rel="canonical" href="' . htmlspecialchars(canonical_url(), ENT_QUOTES, 'UTF-8') . '" />';


  // ▼▼▼ TAMBAHKAN KODE INI ▼▼▼
  // --- Structured Data untuk Nama Situs ---
  $website_schema = [
    "@context" => "https://schema.org",
    "@type" => "WebSite",
    "name" => "QuizB",
    "url" => base_url() . "/"
  ];
  echo '<script type="application/ld+json">' . json_encode($website_schema, JSON_UNESCAPED_SLASHES) . '</script>';
  // ▲▲▲ AKHIR DARI KODE TAMBAHAN ▲▲▲


  echo_breadcrumb_jsonld();

  echo "
  <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','GTM-WQ8HS267');</script>
<script async src='https://www.googletagmanager.com/gtag/js?id=G-VTN3LNDWCT'></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'G-VTN3LNDWCT');
</script>
  
  ";

  // ===================================================================
  // INI BLOK YANG DIMODIFIKASI - DULU HANYA HEADER & FOOTER
  echo '<style>
    body {
      /* Memberi ruang di bagian atas body agar konten tidak tertutup header */
      padding-top: 75px; 
    }
    
    .navbar {
      /* Membuat header menempel di bagian paling atas viewport */
      position: fixed;
      top: 0;
      right: 0;
      left: 0;
      z-index: 1030; /* Pastikan header di atas elemen lain */
    }

    /* Ini footer teks untuk desktop */
    footer.desktop-footer {
      text-align:center; 
      padding:15px; 
      margin-top:40px; 
      font-size:14px; 
      color:#666; 
      border-top:1px solid #ddd;
    }
  </style>';

  /* ===== CSS BARU UNTUK FOOTER MOBILE ===== */
  echo '<style>
    /* Hanya terapkan gaya ini pada layar kecil (mobile) */
    @media (max-width: 767.98px) {
      body {
        /* Tambahkan padding bawah seukuran tinggi footer agar konten tidak tertutup */
        padding-bottom: 70px; 
      }
      
      .mobile-nav-footer {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        z-index: 1030;
        background: var(--bs-body-bg);
        border-top: 1px solid var(--bs-border-color);
        box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
      }

      .mobile-nav-footer .nav-container {
        display: flex;
        justify-content: space-around;
        align-items: center;
        padding: 4px 0;
      }

      .mobile-nav-footer .nav-link {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        flex-grow: 1;
        padding: 8px 4px;
        text-decoration: none;
        color: var(--bs-body-color);
        font-size: 0.75rem; /* Ukuran teks label */
        transition: color 0.2s;
      }
      
      .mobile-nav-footer .nav-link:hover {
        color: var(--bs-primary);
      }
      
      .mobile-nav-footer .nav-link svg {
        margin-bottom: 4px; /* Jarak antara ikon dan teks */
      }
    }
  </style>';
  // ===================================================================





  // Di dalam fungsi html_head(), tambahkan style ini
  echo '<style>
    /* Styling untuk daftar pencarian di halaman utama */
    .search-item-subtheme {
        padding-left: 1.5rem;
        border-left: 2px solid #e9ecef;
    }
    [data-bs-theme="dark"] .search-item-subtheme {
        border-left-color: #343a40;
    }
    .search-item-title {
        padding-left: 1.5rem;
    }
    
    
    .sidebar-widget .list-group-item {
    margin-bottom: 8px; /* Memberi jarak antar item */
    border-radius: .5rem !important; /* Membuat sudut lebih melengkung */
    border: 1px solid var(--bs-border-color-translucent);
    transition: all 0.2s ease-in-out;
  }
  .sidebar-widget .list-group-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    z-index: 2;
  }
  [data-bs-theme="dark"] .sidebar-widget .list-group-item:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
  }
  .widget-title {
    font-size: 1.1rem;
    font-weight: 600;
    margin-bottom: 1rem;
    color: var(--bs-emphasis-color);
  }
  
  
  /* Terapkan gaya ini HANYA pada layar dengan lebar maksimal 991.98px (di bawah breakpoint large Bootstrap) */
  @media (max-width: 991.98px) {
    .sidebar-separator-mobile {
      margin-top: 2.5rem; /* Beri jarak atas yang cukup */
      padding-top: 2rem; /* Beri ruang di atas konten sidebar */
      border-top: 1px solid var(--bs-border-color); /* Garis pemisah horizontal */
    }
  }
</style>';



  // ===================================================================
  // ▼▼▼ TAMBAHKAN BLOK CSS BARU INI DI SINI ▼▼▼
  // ===================================================================
  echo '<style>
    /* Styling untuk Container Utama Kuis */
    .quiz-container {
      max-width: 800px;
      margin: 2rem auto;
      padding: 1.5rem;
      background-color: var(--bs-body-bg);
      border-radius: 16px;
      /* box-shadow: 0 8px 30px rgba(0,0,0,0.1); */
    }

    /* Kotak untuk Pertanyaan */
    .quiz-question-box {
      padding: 1.5rem;
      margin-bottom: 2rem;
      background-color: var(--bs-tertiary-bg);
      border: 1px solid var(--bs-border-color-translucent);
      border-radius: 12px;
      text-align: center;
    }
    .quiz-question-number {
      font-size: 0.9rem;
      font-weight: 500;
      color: var(--bs-secondary-color);
      margin-bottom: 0.5rem;
    }
    .quiz-question-text {
      font-size: 1.5rem;
      font-weight: 600;
      color: var(--bs-emphasis-color);
      line-height: 1.4;
    }

    /* Grid untuk Pilihan Jawaban (Layout 2 Kolom) */
    .quiz-choices-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr); /* Default 2 kolom */
      gap: 1rem; /* Jarak antar pilihan */
    }

    /* Styling untuk setiap item pilihan jawaban */
    .quiz-choice-item {
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1.25rem 1rem;
      font-size: 1.1rem;
      font-weight: 500;
      text-align: center;
      border: 2px solid var(--bs-border-color);
      border-radius: 12px;
      background-color: var(--bs-body-bg);
      color: var(--bs-body-color);
      cursor: pointer;
      transition: all 0.2s ease-in-out;
      text-decoration: none; /* Menghilangkan garis bawah jika menggunakan <a> */
    }
    .quiz-choice-item:hover {
      transform: translateY(-4px);
      box-shadow: 0 4px 15px rgba(0,0,0,0.08);
      border-color: var(--bs-primary);
      color: var(--bs-primary);
    }
    .quiz-choice-item:focus {
      outline: none;
      box-shadow: 0 0 0 3px var(--bs-primary-border-subtle);
      border-color: var(--bs-primary);
    }
    
    /* Jika hanya ada 3 pilihan, pilihan terakhir akan memanjang */
    .quiz-choice-item:nth-child(odd):last-child {
        grid-column: 1 / -1;
    }

    /* Responsif untuk layar kecil (Mobile) */
    @media (max-width: 576px) {
      .quiz-choices-grid {
        grid-template-columns: 1fr; /* Jadi 1 kolom di layar kecil */
      }
      .quiz-question-text {
        font-size: 1.25rem;
      }
      .quiz-container {
        padding: 1rem;
      }
    }
  </style>';
  // ===================================================================
  // ▲▲▲ AKHIR DARI BLOK CSS BARU ▲▲▲
  // ===================================================================

  // ===================================================================
  // ▼▼▼ TAMBAHKAN BLOK CSS BARU INI DI SINI ▼▼▼
  // ===================================================================
  echo '<style>
    /* Styling untuk logo tengah di header mobile */
    .navbar-mobile-center-logo {
        position: absolute;
        left: 50%;
        top: -10px; /* Mengatur seberapa jauh logo menjorok ke bawah */
        transform: translateX(-50%);
        background-color: var(--bs-body-bg); /* Warna latar mengikuti tema */
        padding: 8px; /* Jarak di sekitar gambar */
        border-radius: 40%; /* Membuatnya bulat */
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        border: 1px solid var(--bs-border-color);
        line-height: 0; /* Menghilangkan spasi ekstra */
        z-index: 1040; /* Memastikan logo di atas elemen lain */
    }

    .navbar-mobile-center-logo img {
        width: 48px;  /* Ukuran gambar logo */
        height: 48px;
    }

    /* Menambah ruang di atas konten pada tampilan mobile agar tidak tertutup logo */
    @media (max-width: 991.98px) {
        body {
            /* padding-top awal adalah 75px, kita tambah sedikit ruang */
            padding-top: 80px;
        }
    }
    



    /* ▼▼▼ TAMBAHKAN INI ▼▼▼ */
    /* Menambah tinggi navbar agar garis bawahnya sejajar logo */
    .navbar {
        min-height: 60px;
    }
    /* ▲▲▲ AKHIR DARI TAMBAHAN ▲▲▲ */
  }
  
  
  
  </style>';
  // ===================================================================
  // ▲▲▲ AKHIR DARI BLOK CSS BARU ▲▲▲




  // ===================================================================
  // TAMBAHKAN BLOK STYLE BARU INI UNTUK POSISI BADGE
  echo '<style>
    .nav-item-icon {
        position: relative;
    }
    .nav-item-icon .badge {
        position: absolute;
        top: -5px;
        right: -8px;
        font-size: 0.6em;
        padding: 0.25em 0.4em;
    }
    
    /* CSS BARU UNTUK BADGE DI NAVIGASI MOBILE */
    .mobile-nav-footer .nav-link .badge {
        position: absolute;
        top: 0;
        right: 15px; /* Sesuaikan posisi horizontal */
    }
  </style>';
  // ===================================================================
  // Script untuk tabel interaktif (TIDAK BERUBAH)
  echo <<<'JS'
<script>
function setupTable(opts){
    var PAGE_SIZE = opts.pageSize || 10;
    var input   = document.getElementById(opts.inputId);
    var table   = document.getElementById(opts.tableId);
    var pager   = document.getElementById(opts.pagerId);
    var badge   = document.getElementById(opts.countBadgeId);
    if(!table) return;
    var tbody   = table.querySelector("tbody");
    var rows    = Array.from(tbody.querySelectorAll("tr"));
    var q       = "";
    var page    = 1;
    var filtered = rows.slice();
    function applyFilter(){
      q = (input && input.value ? input.value.toLowerCase() : "");
      filtered = rows.filter(function(tr){
        var txt = (tr.getAttribute("data-search") || tr.innerText || "").toLowerCase();
        return txt.indexOf(q) !== -1;
      });
      if (badge) badge.textContent = filtered.length;
      page = 1;
      applyPaginate();
    }
    function applyPaginate(){
      var total = filtered.length;
      var pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
      if(page < 1) page = 1;
      if(page > pages) page = pages;
      rows.forEach(function(tr){ tr.style.display = "none"; });
      var start = (page - 1) * PAGE_SIZE;
      var end   = Math.min(start + PAGE_SIZE, total);
      for(var i=start; i<end; i++){
        filtered[i].style.display = "";
      }
      if(pager){
        var pEl = pager.querySelector('[data-role="page"]');
        var psEl = pager.querySelector('[data-role="pages"]');
        if(pEl) pEl.textContent = String(pages === 0 ? 0 : page);
        if(psEl) psEl.textContent = String(pages);
        var btnPrev = pager.querySelector('[data-page="prev"]');
        var btnNext = pager.querySelector('[data-page="next"]');
        if(btnPrev) btnPrev.disabled = (page <= 1 || pages <= 1);
        if(btnNext) btnNext.disabled = (page >= pages || pages <= 1);
      }
    }
    if(input) input.addEventListener("input", applyFilter);
    if(pager){
      pager.addEventListener("click", function(ev){
        var btn = ev.target.closest("[data-page]");
        if(!btn) return;
        var dir = btn.getAttribute("data-page");
        if(dir === "prev") page--;
        if(dir === "next") page++;
        applyPaginate();
      });
    }
    applyFilter();
}




// === Namespace state per judul/subtema (tanpa PHP variable) ===
(function () {
  const url = new URL(location.href);
  const TITLE_ID    = parseInt(url.searchParams.get('title_id')    || '0', 10);
  const SUBTHEME_ID = parseInt(url.searchParams.get('subtheme_id') || '0', 10);
  const PREFIX = `quizb:${TITLE_ID}:${SUBTHEME_ID}:`;

  const NS_KEYS = new Set([
    'current_questions','answers','i','timer_remain','instant_session_id','assignment','picked'
  ]);

  const _getItem = localStorage.getItem.bind(localStorage);
  const _setItem = localStorage.setItem.bind(localStorage);
  const _removeItem = localStorage.removeItem.bind(localStorage);

  localStorage.getItem    = (k) => _getItem(NS_KEYS.has(k) ? (PREFIX + k) : k);
  localStorage.setItem    = (k,v)=> _setItem(NS_KEYS.has(k) ? (PREFIX + k) : k, v);
  localStorage.removeItem = (k) => _removeItem(NS_KEYS.has(k) ? (PREFIX + k) : k);

  ['current_questions','answers','i','timer_remain','assignment','picked','instant_session_id']
    .forEach(k => { try { _removeItem(k); } catch(e){} });
})();


// === Global fetch guard untuk endpoint PHP/action ===
(function () {
  const _fetch = window.fetch.bind(window);
  window.fetch = (input, init = {}) => {
    let urlObj;

    if (typeof input === 'string') {
      urlObj = new URL(input, location.origin);
    } else if (input instanceof Request) {
      urlObj = new URL(input.url);
    } else {
      return _fetch(input, init);
    }

    const isSameOrigin = (urlObj.origin === location.origin);
    const isPhp        = urlObj.pathname.endsWith('.php');
    const isAction     = urlObj.searchParams.has('action');

    if (isSameOrigin && (isPhp || isAction)) {
      // Tambah cache-buster
      urlObj.searchParams.set('_ts', Date.now().toString());

      // Paksa no-store
      init = init || {};
      init.cache = 'no-store';
      init.headers = new Headers(init.headers || {});
      init.headers.set('Cache-Control', 'no-store');

      // Rekonstruksi Request bila input awal berupa Request
      if (input instanceof Request) {
        input = new Request(urlObj.toString(), input);
      } else {
        input = urlObj.toString();
      }
    }

    return _fetch(input, init);
  };
})();
</script>
JS;


  // ===================================================================
  // ▼▼▼ PASTE BLOK HEADER BARU INI ▼▼▼
  // ===================================================================
  // Kembalikan output normal head/body — pastikan tiap view menutup div sendiri
  echo '</head><body>';

  // ===================================================================
  // ▼▼▼ 2. LETAKKAN BLOK HTML INI TEPAT SETELAH echo '</head><body>'; ▼▼▼
  // ===================================================================
  echo <<<HTML
<div class="quizb-loader-container hidden" id="pageLoader">
    <svg class="quizb-loader" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
        <path class="q-shape" d="M50,5C25.2,5,5,25.2,5,50s20.2,45,45,45s45-20.2,45-45S74.8,5,50,5z M50,86.5C29.9,86.5,13.5,70.1,13.5,50 S29.9,13.5,50,13.5S86.5,29.9,86.5,50S70.1,86.5,50,86.5z M68.5,43.8c-1.3-1.3-3.5-1.3-4.8,0L49,58.5l-6.7-6.7 c-1.3-1.3-3.5-1.3-4.8,0s-1.3,3.5,0,4.8l9,9c0.6,0.6,1.5,1,2.4,1s1.8-0.4,2.4-1l17-17C69.8,47.2,69.8,45.1,68.5,43.8z"/>
        <circle class="dot dot-1" cx="35" cy="50" r="5"/>
        <circle class="dot dot-2" cx="50" cy="50" r="5"/>
        <circle class="dot dot-3" cx="65" cy="50" r="5"/>
    </svg>
</div>
HTML;
  // ===================================================================
  // ▲▲▲ AKHIR BLOK HTML ▲▲▲
  // ===================================================================


  echo '<nav class="navbar navbar-expand-lg bg-body-tertiary border-bottom">';
  echo '  <div class="container position-relative">'; // Menambahkan position-relative

  // --- Tampilan Desktop (d-none d-lg-flex akan menyembunyikannya di mobile) ---
  echo '    <a class="navbar-brand brand d-none d-lg-block" href="./">' . h($CONFIG['APP_NAME']) . '</a>';

  // --- Tampilan Mobile (d-lg-none akan menyembunyikannya di desktop) ---
  // Logo di tengah
  echo '    <a href="./" class="navbar-mobile-center-logo d-lg-none">
            <img src="/favicon.png" alt="QuizB Logo">
          </a>';
  // Placeholder agar layout tidak rusak
  echo '    <div class="navbar-brand d-lg-none" aria-hidden="true" style="opacity: 0;"></div>';


  // --- Menu Kanan (HANYA UNTUK DESKTOP) ---
  echo '    <div class="collapse navbar-collapse d-none d-lg-flex" id="navDesktop">'; // Menggunakan d-none d-lg-flex
  echo '      <ul class="navbar-nav me-auto">';


  // ▼▼▼ UBAH BAGIAN INI ▼▼▼

  echo '        <li class="nav-item"><a class="nav-link" href="?page=themes">Pencarian</a></li>'; // Ini halaman baru Anda

  // ▲▲▲ AKHIR PERUBAHAN ▲▲▲


  if (is_admin()) {

    echo '    <li class="nav-item"><a class="nav-link" href="?page=admin">Backend</a></li>';
    echo '    <li class="nav-item"><a class="nav-link" href="?page=kelola_user">Kelola User</a></li>';
    echo '    <li class="nav-item"><a class="nav-link" href="?page=broadcast">Broadcast</a></li>';
    echo '    <li class="nav-item"><a class="nav-link" href="?page=qmanage">Kelola Soal (CRUD)</a></li>';
    echo '    <li class="nav-item"><a class="nav-link" href="?page=monitor_jawaban">📊 Monitor Jawaban</a></li>';

    // ▼▼▼ TAMBAHKAN BLOK BARU INI ▼▼▼
    // Tampilkan menu ini HANYA jika pengguna adalah Pengajar
if (($_SESSION['user']['role'] ?? '') === 'pengajar') {
       // >>> UBAH LINK INI <<<
      echo '    <li class="nav-item"><a class="nav-link" href="?page=kelola_institusi">Kelola Institusi & Kelas</a></li>';
    }
    // ▲▲▲ AKHIR BLOK BARU ▲▲▲

    echo '    <li class="nav-item"><a class="nav-link" href="?page=crud">CRUD Bank Soal</a></li>';
} else if ($u) {
    // Menu yang tampil untuk semua user login (non-admin)
    echo '    <li class="nav-item"><a class="nav-link" href="?page=challenges">Data Challenge</a></li>';

    // Cek peran pengguna untuk menampilkan menu spesifik
    $user_role = $_SESSION['user']['role'] ?? '';

    if ($user_role === 'pengajar') {
      // Tampilkan menu untuk Pengajar
      echo '<li class="nav-item"><a class="nav-link" href="?page=kelola_institusi">Kelola Institusi & Kelas</a></li>';
      echo '<li class="nav-item"><a class="nav-link" href="?page=teacher_crud">Bank Soal Saya</a></li>';
      echo '<li class="nav-item"><a class="nav-link" href="?page=monitor_jawaban">📊 Monitor Jawaban</a></li>';
    } elseif ($user_role === 'pelajar') {
      // ▼▼▼ INI MENU BARU UNTUK SISWA ▼▼▼
      echo '<li class="nav-item"><a class="nav-link" href="?page=student_tasks">Daftar Tugas</a></li>';
    }
  }
  echo '      </ul>';
  
  echo '      <div class="d-flex align-items-center gap-2">';
  if ($u) {
    echo '    <a class="btn btn-sm btn-outline-secondary d-none d-lg-block nav-item-icon" href="?page=pesan" title="Pesan">';
    echo '      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-envelope" viewBox="0 0 16 16"><path d="M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V4Zm2-1a1 1 0 0 0-1 1v.217l7 4.2 7-4.2V4a1 1 0 0 0-1-1H2Zm13 2.383-4.708 2.825L15 11.105V5.383Zm-.034 6.876-5.64-3.471L8 9.583l-1.326-.795-5.64 3.47A1 1 0 0 0 2 13h12a1 1 0 0 0 .966-.741ZM1 11.105l4.708-2.897L1 5.383v5.722Z"/></svg>';
    if ($unread_messages > 0) {
      echo '    <span class="badge bg-danger rounded-pill">' . $unread_messages . '</span>';
    }
    echo '    </a>';


    // ▼▼▼ TAMBAHKAN BLOK INI ▼▼▼
    echo '    <a class="btn btn-sm btn-outline-secondary d-none d-lg-block nav-item-icon" href="?page=notifikasi" title="Notifikasi">';
    echo '      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-bell" viewBox="0 0 16 16"><path d="M8 16a2 2 0 0 0 2-2H6a2 2 0 0 0 2 2zM8 1.918l-.797.161A4.002 4.002 0 0 0 4 6c0 .768-.214 2.622-.53 4.141-.318 1.52-.995 2.825-1.766 3.492C1.06 14.24 1.16 15 2 15h12c.84 0 .94-1.258.234-1.367-.77-.667-1.448-1.972-1.766-3.492C12.214 8.622 12 6.768 12 6a4.002 4.002 0 0 0-3.203-3.92L8 1.917zM14.22 12.23c.22.18.22.522 0 .704-.18.146-.443.146-.624 0l-.093-.08c-.743-.65-1.182-1.653-1.44-2.868-.258-1.216-.487-2.87-.487-3.786 0-2.096-1.555-3.79-3.5-3.79-.986 0-1.891.39-2.56.985l-.049.049c-.669.595-1.096 1.48-1.096 2.457 0 .916-.23 2.57-.488 3.786-.258 1.216-.697 2.218-1.44 2.868l-.094.08c-.18.146-.443.146-.624 0-.22-.182-.22-.524 0-.704.18-.147.444-.147.625 0l.093.08c.844-.725 1.365-1.92 1.64-3.328.278-1.41.523-3.23.523-4.203 0-2.553 1.846-4.618 4.2-4.618 2.354 0 4.2 2.065 4.2 4.618 0 .973.245 2.793.523 4.203.275 1.408.796 2.603 1.64 3.328l.093.08c.18.147.443.147.624 0z"/></svg>';
    if ($unread_notifications > 0) {
      echo '    <span class="badge bg-danger rounded-pill">' . $unread_notifications . '</span>';
    }
    echo '    </a>';
    // ▲▲▲ SELESAI ▲▲▲


  }
  echo '        <button id="themeToggleDesktop" class="btn btn-outline-secondary btn-sm" title="Ganti tema">🌓</button>';
  if ($u) {
  $feedback_link_label = is_admin() ? 'Kelola Umpan Balik' : 'Kirim Umpan Balik'; // BARIS KONDISIONAL
    echo '    <div class="nav-item dropdown">';
    echo '      <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">';
    echo '        <img class="avatar me-2" src="' . h($u['avatar']) . '" alt="Avatar">';
    echo          h($u['name']);
    echo '      </a>';
    echo '      <ul class="dropdown-menu dropdown-menu-end">';
    echo '        <li><a class="dropdown-item" href="?page=profile">Profil Saya</a></li>';
    echo '        <li><a class="dropdown-item" href="?page=setting">Setting</a></li>';
    echo '        <li><hr class="dropdown-divider"></li>';
    echo '        <li><a class="dropdown-item" href="?page=about">Tentang QuizB</a></li>';
    echo '        <li><a class="dropdown-item" href="?page=privacy">Privacy Policy</a></li>';
    echo '        <li><a class="dropdown-item" href="?page=feedback">' . $feedback_link_label . '</a></li>';
    echo '        <li><hr class="dropdown-divider"></li>';
    echo '        <li><a class="dropdown-item text-danger" href="?action=logout">Logout</a></li>';
    echo '      </ul>';
    echo '    </div>';
  } else {
    echo '    <a href="?page=about" class="btn btn-outline-secondary btn-sm" title="Tentang QuizB">Tentang</a>';
    echo '    <a href="?page=privacy" class="btn btn-outline-secondary btn-sm" title="Kebijakan Privasi">Privacy</a>';
    echo '    <a href="?page=feedback" class="btn btn-outline-secondary btn-sm" title="Kirim Umpan Balik">Feedback</a>'; // BIARKAN 'Feedback' karena ini tamu/non-admin
    echo google_btn($cid);
  }
  echo '      </div>'; // Penutup d-flex
  echo '    </div>';   // Penutup collapse
  echo '  </div>';     // Penutup container
  echo '</nav>';
  echo '<div class="container mt-4" id="main-content-container">';

  // ===================================================================
  // ▲▲▲ AKHIR BLOK HEADER BARU ▲▲▲
  // ===================================================================

  // ... di dalam fungsi html_head()

  // ===================================================================
  // ▼▼▼ TAMBAHKAN BLOK CSS BARU INI UNTUK MEMPERBAIKI WARNA SKOR ▼▼▼
  // ===================================================================
  echo '<style>
    .summary-score-box .score-label {
        color: var(--bs-secondary-emphasis-color);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 1px;
        font-size: 0.9rem;
    }
    .summary-score-box .score-details {
        color: var(--bs-secondary-color);
        font-size: 0.95rem;
    }
    /* Sedikit penyesuaian untuk mode gelap agar lebih bagus */
    [data-bs-theme="dark"] .summary-score-box {
        background-color: var(--bs-tertiary-bg) !important;
    }
  </style>';
  // ===================================================================
  // ▲▲▲ AKHIR DARI BLOK CSS BARU ▲▲▲

  // ... (kode lainnya di html_head() tetap sama)
}

//===================
// AKHIR HTML HEAD
//===================

// ===================================================================
// ▼▼▼ GANTI SELURUH FUNGSI html_foot() LAMA DENGAN VERSI BARU INI ▼▼▼
// ===================================================================
function html_foot()
{
global $CONFIG;
  echo '</div><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>';
  echo '<script>
(function(){
  function handleThemeToggle() {
    var cur  = document.documentElement.getAttribute("data-bs-theme") || "light";
    var next = (cur === "dark") ? "light" : "dark";
    document.documentElement.setAttribute("data-bs-theme", next);
    localStorage.setItem("quizb_theme", next);
    try {
      fetch("?action=set_theme", {
        method: "POST",
        headers: {"Content-Type":"application/x-www-form-urlencoded"},
        body: "theme=" + encodeURIComponent(next)
      }).catch(function(){ /* abaikan */ });
    } catch(e) { /* abaikan */ }
  }
  var btnDesktop = document.getElementById("themeToggleDesktop");
  var btnProfile = document.getElementById("themeToggle");
  if (btnDesktop) { btnDesktop.addEventListener("click", handleThemeToggle); }
  if (btnProfile) { btnProfile.addEventListener("click", handleThemeToggle); }
})();
</script>';



  // Script untuk tombol laporan (TIDAK BERUBAH)
  echo <<<JS
<script>
function setupReportButtons() {
    document.querySelectorAll('.kirim-laporan-btn:not([data-initialized])').forEach(btn => {
        btn.dataset.initialized = 'true';
        btn.addEventListener('click', async () => {
            function createCertificateImage(quizTitle, subTheme, userName, userEmail, quizMode) {
                return new Promise(resolve => {
                    const canvas = document.createElement('canvas');
                    const ctx = canvas.getContext('2d');
                    const width = 800, height = 450;
                    canvas.width = width; canvas.height = height;
                    ctx.fillStyle = '#f0f9ff';
                    ctx.fillRect(0, 0, width, height);
                    ctx.strokeStyle = '#0d6efd';
                    ctx.lineWidth = 10;
                    ctx.strokeRect(5, 5, width - 10, height - 10);
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillStyle = '#0d6efd';
                    ctx.font = 'bold 42px Arial';
                    ctx.fillText('Selamat!', width / 2, 80);
                    ctx.font = '32px Arial';
                    ctx.fillText('Anda mendapatkan nilai 100', width / 2, 140);
                    ctx.fillStyle = '#444';
                    ctx.font = '22px Arial';
                    let userDetailsText = userEmail ? `(\${userName} - \${userEmail})` : `(\${userName})`;
                    ctx.fillText(userDetailsText, width / 2, 180);
                    ctx.fillStyle = '#333';
                    ctx.font = '24px Arial';
                    ctx.fillText('Pada soal:', width / 2, 230);
                    ctx.font = 'bold 28px Arial';
                    let titleY = wrapText(ctx, `'` + quizTitle + ` - ` + subTheme + `'`, width / 2, 280, width - 80, 34);
                    if (quizMode) {
                        ctx.font = 'italic 20px Arial';
                        ctx.fillStyle = '#555';
                        const modeText = `(Mode: \${quizMode === 'end' ? 'End Review' : 'Instan Review'})`;
                        ctx.fillText(modeText, width / 2, titleY + 10);
                    }
                    ctx.fillStyle = '#555';
                    ctx.font = 'italic 22px Arial';
                    ctx.fillText('Semoga berkah, tetap semangat belajar!', width / 2, height - 60);
                    canvas.toBlob(blob => resolve(blob), 'image/png');
                });
            }
            function wrapText(context, text, x, y, maxWidth, lineHeight) {
                let words = text.split(' ');
                let line = '';
                let currentY = y;
                for(let n = 0; n < words.length; n++) {
                    let testLine = line + words[n] + ' ';
                    let metrics = context.measureText(testLine);
                    if (metrics.width > maxWidth && n > 0) {
                        context.fillText(line, x, currentY);
                        line = words[n] + ' ';
                        currentY += lineHeight;
                    } else { line = testLine; }
                }
                context.fillText(line, x, currentY);
                return currentY + lineHeight;
            }
            btn.disabled = true;
            btn.textContent = 'Menyiapkan...';
            const userName = btn.dataset.userName, userEmail = btn.dataset.userEmail, quizTitle = btn.dataset.quizTitle, subTheme = btn.dataset.subTheme, quizMode = btn.dataset.quizMode;
            try {
                const imageBlob = await createCertificateImage(quizTitle, subTheme, userName, userEmail, quizMode);
                const imageFile = new File([imageBlob], 'laporan-skor-100.png', { type: 'image/png' });
                if (navigator.canShare && navigator.canShare({ files: [imageFile] })) {
                    await navigator.share({ files: [imageFile], title: 'Laporan Tugas Quiz' });
                } else {
                    alert('Browser Anda tidak mendukung fitur berbagi laporan ini.');
                }
            } catch (error) {
                if (error.name !== 'AbortError') console.error('Gagal membagikan:', error);
            } finally {
                btn.disabled = false; btn.textContent = 'Kirim Laporan';
            }
        });
    });
}
</script>
JS;

  // Script untuk tombol Share (TIDAK BERUBAH)
  echo <<<JS
<script>
// Handler untuk tombol "Tantang Teman" yang membuat challenge
document.getElementById('createChallenge')?.addEventListener('click', async function(e) {
    e.preventDefault();
    const titleId = this.getAttribute('data-title-id');
    const sessionId = this.getAttribute('data-session-id');
    const btn = this;
    
    btn.disabled = true;
    btn.innerHTML = 'Membuat link tantangan...';
    
    try {
        const response = await fetch('?action=create_challenge', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                title_id: titleId,
                session_id: sessionId
            })
        });
        
        const result = await response.json();
        
        if (result.success && result.token) {
            // Setelah challenge dibuat, share link
            const url = location.origin + location.pathname + '?page=challenge&token=' + result.token;
            const title = 'Tantangan Kuis QUIZB';
            const text = 'Coba kalahkan skor saya di kuis ini:';
            
            try {
                if (navigator.share) {
                    await navigator.share({ title, text, url });
                } else {
                    await navigator.clipboard.writeText(url);
                    alert('Link tantangan berhasil dibuat dan disalin!');
                }
            } catch (err) {
                console.error(err);
            }
        } else {
            alert('Gagal membuat link tantangan: ' + (result.error || 'Error tidak diketahui'));
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Terjadi kesalahan saat membuat link tantangan');
    } finally {
        btn.disabled = false;
        btn.innerHTML = 'Tantang Teman';
    }
});

const shareBtn = document.getElementById('shareChallenge');
if (shareBtn) {
    shareBtn.addEventListener('click', async function(e) {
        e.preventDefault();
        const url = this.getAttribute('data-url');
        const title = 'Tantangan Kuis QUIZB';
        const text  = 'Coba kalahkan skor saya di kuis ini:';
        try {
            if (navigator.share) await navigator.share({ title, text, url });
            else { await navigator.clipboard.writeText(url); alert('Link disalin!'); }
        } catch (err) { console.error(err); }
    });
}
</script>
JS;

  // Kode HTML untuk Footer (TIDAK BERUBAH)
  $u = $_SESSION['user'] ?? null;
  $current_page = $_GET['page'] ?? 'home';
  $unread_badge_html = '';
  $unread_notif_badge_html = '';
  if (uid()) {
    $count_result = q("SELECT COUNT(*) as unread_count FROM messages WHERE receiver_id = ? AND is_read = 0", [uid()])->fetch();
    if ($count_result && (int)$count_result['unread_count'] > 0) {
      $unread_badge_html = '<span class="badge bg-danger rounded-pill">' . (int)$count_result['unread_count'] . '</span>';
    }
    $total_notifs = (int)q("SELECT COUNT(*) FROM broadcast_notifications")->fetchColumn();
    $read_notifs = (int)q("SELECT COUNT(*) FROM user_notification_reads WHERE user_id = ?", [uid()])->fetchColumn();
    $unread_count = $total_notifs - $read_notifs;
    if ($unread_count > 0) {
      $unread_notif_badge_html = '<span class="badge bg-danger rounded-pill">' . $unread_count . '</span>';
    }
  }
  echo '<footer class="desktop-footer d-none d-md-block">&copy; ' . date("Y") . ' QuizB — "Quiz Berkah". All rights reserved.</footer>';

  // ===================================================================
  // ▼▼▼ TAMBAHKAN BLOK STYLE BARU INI TEPAT DI SINI ▼▼▼
  // ===================================================================
  echo '
  <style>
    .mobile-nav-footer .nav-link { 
        color: var(--bs-secondary-color); /* Warna ikon tidak aktif */
    }
    .mobile-nav-footer .nav-link.active {
        color: var(--bs-primary); /* Warna ikon aktif */
    }
    
    /* INI ADALAH ATURAN CSS YANG HILANG */
    .mobile-nav-footer .nav-link .avatar-icon {
        width: 28px;           /* Menentukan lebar gambar */
        height: 28px;          /* Menentukan tinggi gambar */
        border-radius: 50%;    /* Membuat gambar menjadi bulat */
        border: 2px solid transparent;
        object-fit: cover;     /* Memastikan gambar terpotong rapi */
        margin-bottom: 2px;
    }
    
    .mobile-nav-footer .nav-link.active .avatar-icon {
        border-color: var(--bs-primary);
    }
    .mobile-nav-footer .nav-link .badge {
        position: absolute;
        top: 0;
        right: 15px;
    }
</style>
  ';
  // ===================================================================
  // ▲▲▲ AKHIR DARI BLOK STYLE BARU ▲▲▲
  // ===================================================================

  echo '<nav class="mobile-nav-footer d-block d-md-none"><div class="nav-container">';
  echo '<a href="./" class="nav-link spa-nav-link' . ($current_page === 'home' ? ' active' : '') . '" data-page="home"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-house-door-fill" viewBox="0 0 16 16"><path d="M6.5 14.5v-3.505c0-.245.25-.495.5-.495h2c.25 0 .5.25.5.5v3.5a.5.5 0 0 0 .5.5h4a.5.5 0 0 0 .5-.5v-7a.5.5 0 0 0-.146-.354L13 5.793V2.5a.5.5 0 0 0-.5-.5h-1a.5.5 0 0 0-.5.5v1.293L8.354 1.146a.5.5 0 0 0-.708 0l-6 6A.5.5 0 0 0 1.5 7.5v7a.5.5 0 0 0 .5.5h4a.5.5 0 0 0 .5-.5z"/></svg><span>Home</span></a>';
  echo '<a href="?page=themes" class="nav-link spa-nav-link' . (in_array($current_page, ['themes', 'subthemes', 'titles']) ? ' active' : '') . '" data-page="themes"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-search" viewBox="0 0 16 16"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z"/></svg><span>Pencarian</span></a>';
  
  // Ambil peran (role) pengguna saat ini (asumsi $role sudah didefinisikan sebelumnya)
$role = $_SESSION['user']['role'] ?? 'umum';

// ▼▼▼ START: MENU TUGAS KONDISIONAL (Menggantikan 'Challenge') ▼▼▼

$target_page = 'challenges'; // Default: Challenges
$target_url = '?page=challenges';
$icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-trophy-fill" viewBox="0 0 16 16"><path d="M2.5.5A.5.5 0 0 1 3 0h10a.5.5 0 0 1 .5.5c0 .538-.012 1.05-.034 1.536a3 3 0 1 1-1.133 5.89c-.79 1.865-1.878 2.777-2.833 3.011v2.173l1.425.356c.194.048.377.135.537.255l.617.463c.12.09.186.215.186.346 0 .134-.066.256-.186.346l-.617.463c-.16.12-.343.207-.537.255L10.5 15.5v.5a.5.5 0 0 1-1 0v-.5a.5.5 0 0 1 1 0v-2.148a.5.5 0 0 1-.306-.474c-.182-.92-.57-1.706-.986-2.287-.333-.465-.68-.87-1.287-1.175-.487-.247-1.146-.546-1.85-.758-.552-.168-.962-.46-1.287-1.175C3.25 7.243 2.86 6.458 2.678 5.538a.5.5 0 0 1-.306.474v2.147a.5.5 0 0 1-1 0v-.5a.5.5 0 0 1 1 0v.5l1.425-.356a.5.5 0 0 1 .537-.255l.617-.463a.5.5 0 0 1 .186-.346 1.03 1.03 0 0 0-.186-.346l-.617-.463a.5.5 0 0 1-.537-.255L3.5 13.5v-2.173c-.955-.234-2.043-1.146-2.833-3.012a3 3 0 1 1-1.132-5.89A33.076 33.076 0 0 1 2.5.5z"/></svg>';
    $link_text = 'Challenge';

    if ($role === 'pengajar') {
        $target_page = 'kelola_tugas';
        $target_url = '?page=kelola_tugas';
        $link_text = 'Kelola Tugas';
        // Ikon: Management/Workspace
        $icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-person-workspace" viewBox="0 0 16 16"><path d="M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1h-1.5zM12 11h2V2h-2v9zM2 11V2h2v9H2z"/><path d="M6 14.5a.5.5 0 0 1 .5-.5h3a.5.5 0 0 1 .5.5v.5H6v-.5zm4 .5h1.5a.5.5 0 0 1 0 1H4.5a.5.5 0 0 1 0-1H6v.5h4v-.5z"/></svg>';
    } elseif ($role === 'pelajar') {
        $target_page = 'student_tasks';
        $target_url = '?page=student_tasks';
        $link_text = 'Daftar Tugas';
        // Ikon: Check Square/Assignment
        $icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-journal-check" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M10.854 6.146a.5.5 0 0 1 0 .708l-3 3a.5.5 0 0 1-.708 0l-1.5-1.5a.5.5 0 1 1 .708-.708L7.5 8.793l2.646-2.647a.5.5 0 0 1 .708 0z"/><path d="M3 0h10a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2v-1h1v1a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1H3a1 1 0 0 0-1 1v1H1V2a2 2 0 0 1 2-2z"/><path d="M1 5v-.5a.5.5 0 0 1 1 0V5h.5a.5.5 0 0 1 0 1h-2a.5.5 0 0 1 0-1H1zm0 3v-.5a.5.5 0 0 1 1 0V8h.5a.5.5 0 0 1 0 1h-2a.5.5 0 0 1 0-1H1zm0 3v-.5a.5.5 0 0 1 1 0v.5h.5a.5.5 0 0 1 0 1h-2a.5.5 0 0 1 0-1H1z"/></svg>';
    }

    // Render link
    echo '<a href="' . $target_url . '" class="nav-link spa-nav-link' . ($current_page === $target_page ? ' active' : '') . '" data-page="' . $target_page . '">';
    echo $icon_svg;
    echo '<span>' . $link_text . '</span></a>';

    // ▲▲▲ END: MENU TUGAS KONDISIONAL ▼▼▲
  
  echo '<a href="?page=pesan" class="nav-link nav-item-icon spa-nav-link' . ($current_page === 'pesan' ? ' active' : '') . '" data-page="pesan"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-envelope-fill" viewBox="0 0 16 16"><path d="M.05 3.555A2 2 0 0 1 2 2h12a2 2 0 0 1 1.95 1.555L8 8.414.05 3.555zM0 4.697v7.104l5.803-3.558L0 4.697zM6.761 8.83l-6.57 4.027A2 2 0 0 0 2 14h12a2 2 0 0 0 1.808-1.144l-6.57-4.027L8 9.586l-1.239-.757zm3.436-.586L16 11.801V4.697l-5.803 3.546z"/></svg>' . $unread_badge_html . '<span>Pesan</span></a>';
  echo '<a href="?page=notifikasi" class="nav-link nav-item-icon spa-nav-link' . ($current_page === 'notifikasi' ? ' active' : '') . '" data-page="notifikasi"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-bell-fill" viewBox="0 0 16 16"><path d="M8 16a2 2 0 0 0 2-2H6a2 2 0 0 0 2 2zm.995-14.901a1 1 0 1 0-1.99 0A5.002 5.002 0 0 0 3 6c0 1.098-.5 6-2 7h14c-1.5-1-2-5.902-2-7 0-2.42-1.72-4.44-4.005-4.901z"/></svg>' . $unread_notif_badge_html . '<span>Notifikasi</span></a>';
  echo '<a href="?page=profile" class="nav-link spa-nav-link' . ($current_page === 'profile' ? ' active' : '') . '" data-page="profile">';
  if ($u && !empty($u['avatar'])) {
    echo '<img src="' . h($u['avatar']) . '" class="avatar-icon" alt="Avatar">';
  } else {
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-person-fill" viewBox="0 0 16 16"><path d="M3 14s-1 0-1-1 1-4 6-4 6 3 6 4-1 1-1 1H3zm5-6a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/></svg>';
  }
  echo '<span>Profil</span></a></div></nav>';

  // ===================================================================
  // ▼▼▼ PERUBAHAN UTAMA: SCRIPT LOADER & ROUTER SPA ▼▼▼
  // ===================================================================
  // PERHATIKAN PENGGUNAAN <<<'JS' (dengan kutip tunggal)
  echo <<<'JS'
  <script>
  // Script ini akan menangani loader untuk Pemuatan Awal dan Navigasi SPA
  document.addEventListener('DOMContentLoaded', function() {
      const pageLoader = document.getElementById('pageLoader');

      // Tampilkan loader saat halaman pertama kali dibuka
      if (pageLoader) {
          pageLoader.classList.remove('hidden');
      }
      
      // Sembunyikan loader setelah SEMUA aset halaman awal selesai dimuat
      window.addEventListener('load', function() {
          if (pageLoader) {
              pageLoader.classList.add('hidden');
          }
      });
      
      if (typeof setupReportButtons === 'function') {
          setupReportButtons();
      }
      
      // --- Logika Router SPA ---
      const mainContent = document.getElementById('main-content-container');
      const mobileNav = document.querySelector('.mobile-nav-footer');

      function executeScripts(container) {
          container.querySelectorAll('script').forEach(oldScript => {
              const newScript = document.createElement('script');
              Array.from(oldScript.attributes).forEach(attr => newScript.setAttribute(attr.name, attr.value));
              if (!oldScript.src) {
                  newScript.textContent = oldScript.textContent;
              }
              oldScript.parentNode.replaceChild(newScript, oldScript);
          });
      }

      // INI ADALAH FUNGSI loadPage YANG SUDAH DIPERBAIKI TOTAL
      async function loadPage(page, pushState = true) {
          if (!mainContent) return;

          // Tampilkan loader kustom kita
          if (pageLoader) {
              pageLoader.classList.remove('hidden');
          }
          
          // Kosongkan konten lama
          mainContent.innerHTML = ''; 

          if (mobileNav) {
              mobileNav.querySelectorAll('.nav-link.spa-nav-link').forEach(link => link.classList.remove('active'));
              const activeLink = mobileNav.querySelector(`.spa-nav-link[data-page="${page}"]`);
              if (activeLink) activeLink.classList.add('active');
          }

          try {
              // Beri jeda agar animasi sempat terlihat
              await new Promise(resolve => setTimeout(resolve, 250));

              const response = await fetch(`?action=api_get_page_content&page=${page}&_=${new Date().getTime()}`);
              if (!response.ok) throw new Error('Gagal memuat halaman.');
              
              const html = await response.text();
              mainContent.innerHTML = html;
                              
              if (typeof renderAllGoogleButtons === 'function') renderAllGoogleButtons();
              if (typeof executeScripts === 'function') executeScripts(mainContent);
              if (typeof setupReportButtons === 'function') setupReportButtons();
              
              if (pushState) {
                  const url = (page === 'home') ? './' : `?page=${page}`;
                  history.pushState({ page: page }, '', url);
              }
              
              const pageTitle = (page.charAt(0).toUpperCase() + page.slice(1)).replace('_', ' ');
              document.title = `${pageTitle} | QuizB`;
              
              window.scrollTo(0, 0);

          } catch (error) {
              mainContent.innerHTML = '<div class="alert alert-danger">Gagal memuat halaman. Silakan coba lagi.</div>';
              console.error('Fetch error:', error);
          } finally {
              // Selalu sembunyikan loader setelah selesai
              if (pageLoader) {
                  pageLoader.classList.add('hidden');
              }
          }
      }

      if (mobileNav) {
          mobileNav.addEventListener('click', function(e) {
              const link = e.target.closest('a.spa-nav-link');
              if (link && link.dataset.page) {
                  e.preventDefault();
                  const page = link.dataset.page;
                  if (!link.classList.contains('active')) {
                      loadPage(page, true);
                  }
              }
          });
      }

      window.addEventListener('popstate', function(e) {
          const fallbackPage = new URLSearchParams(window.location.search).get('page') || 'home';
          loadPage(e.state?.page || fallbackPage, false);
      });

      const initialPage = new URLSearchParams(window.location.search).get('page') || 'home';
      history.replaceState({ page: initialPage }, '', window.location.href);
  });
  </script>
JS;
  // ===================================================================
  // ▲▲▲ AKHIR DARI BLOK SCRIPT PERBAIKAN ▲▲▲
  // ===================================================================


// ▼▼▼ TAMBAHKAN BLOK SCRIPT INI TEPAT SEBELUM </body></html> ▼▼▼
  echo '<script src="/main-push.js"></script>';
  echo "<script>
      // Panggil fungsi inisialisasi dari main-push.js setelah halaman dimuat
      window.addEventListener('load', () => {
          const VAPID_PUBLIC_KEY = '" . ($CONFIG['VAPID_PUBLIC_KEY'] ?? '') . "';
          if (VAPID_PUBLIC_KEY) {
              initializeUI(VAPID_PUBLIC_KEY);
          } else {
              console.error('VAPID Public Key is not set in PHP config.');
          }
      });
  </script>";
  // ▲▲▲ AKHIR BLOK SCRIPT ▲▲▲



  echo '</body></html>';
}
// ===================================================================
// ▲▲▲ AKHIR DARI PENGGANTIAN FUNGSI html_foot() ▲▲▲
// ===================================================================




// ===
// FUNGSI GOOGLE BTN
// ===

function google_btn($clientId)
{
  // Div untuk inisialisasi sudah tidak diperlukan lagi.
  return '<div class="g_id_signin" data-type="standard" data-size="medium" data-theme="outline" data-text="continue_with" data-shape="rectangular" data-logo_alignment="left"></div>';
}

// ===
// AKHIR FUNGSI GOOGLE BTN
// ===



// ===
// AWAL FUNGSI TOP SKOR
// ===
function get_top_scores($limit = 20): array
{
  // [MODIFIKASI] Query ini diubah untuk hanya menghitung perolehan skor 100
  $sql = "
    SELECT
      u.id, -- Dipertahankan untuk membuat link profil
      u.name AS display_name,
      -- MENGGANTI SUM(score) menjadi COUNT untuk menghitung jumlah skor 100
      COUNT(r.id) AS perfect_scores,
      MAX(r.created_at) AS last_play
    FROM results r
    INNER JOIN users u ON r.user_id = u.id
    WHERE r.user_id IS NOT NULL 
      AND u.name != 'Zainul Hakim' 
      AND r.score = 100 -- MENAMBAHKAN KONDISI INI untuk HANYA menghitung skor 100
    GROUP BY u.id, u.name
    ORDER BY perfect_scores DESC, last_play DESC -- Mengurutkan berdasarkan jumlah skor 100
    LIMIT :lim
  ";
  $st = pdo()->prepare($sql);
  $st->bindValue(':lim', (int)$limit, PDO::PARAM_INT);
  $st->execute();
  return $st->fetchAll(PDO::FETCH_ASSOC);
}
// ===
// AKHIR FUNGSI TOP SKOR
// ===


function get_most_played_titles($limit = 5)
{
  // Fungsi ini mengambil judul kuis yang paling sering dimainkan
  // berdasarkan data dari tabel 'results'.
  $sql = "
        SELECT
            qt.id,
            qt.title,
            COUNT(r.id) AS play_count
        FROM results r
        JOIN quiz_titles qt ON qt.id = r.title_id
        GROUP BY qt.id, qt.title
        ORDER BY play_count DESC
        LIMIT :limit
    ";
  $st = pdo()->prepare($sql);
  $st->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
  $st->execute();
  return $st->fetchAll(PDO::FETCH_ASSOC);
}



// ===============================================
// FUNGSI VIEW NOTIFIKASI
// ===============================================

/**
 * Menampilkan halaman notifikasi (gabungan dari broadcast dan personal).
 */
function view_notifikasi()
{
  if (!uid()) {
    echo '<div class="alert alert-warning">Silakan login untuk melihat notifikasi.</div>';
    return;
  }

  $current_user_id = uid();

  // Query untuk mengambil notifikasi gabungan dari dua tabel
  $notifications = q("
        (SELECT 
            'personal' as type, id, message, link, created_at, is_read 
        FROM notifications 
        WHERE user_id = ?)
        UNION ALL
        (SELECT 
            'broadcast' as type, bn.id, bn.message, bn.link, bn.created_at, 
            (unr.read_at IS NOT NULL) as is_read
        FROM broadcast_notifications bn
        LEFT JOIN user_notification_reads unr ON bn.id = unr.notification_id AND unr.user_id = ?)
        ORDER BY created_at DESC
        LIMIT 50
    ", [$current_user_id, $current_user_id])->fetchAll();

  echo '<h3 class="mb-3">Notifikasi</h3>';

  if (!$notifications) {
    echo '<div class="text-center p-5 text-muted"><svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="currentColor" class="bi bi-bell-slash mb-3" viewBox="0 0 16 16"><path d="M5.161 14.016A7.003 7.003 0 0 0 14 7c0-.629-.086-1.233-.24-1.805l1.623 1.623a.5.5 0 0 0 .707-.707l-10-10a.5.5 0 0 0-.707.707l1.623 1.623A6.966 6.966 0 0 0 2 7c0 2.22 1.225 4.138 3.161 5.016zM11.873 2.12a5.002 5.002 0 0 1 2.065 4.077c.02.13.031.263.031.396 0 .025-.001.05-.002.075a.5.5 0 0 1-.498.498a.5.5 0 0 1-.498-.498c.001-.026.002-.05.002-.076 0-.133-.01-.265-.03-.395a4.002 4.002 0 0 0-3.033-3.033A3.998 3.998 0 0 0 8 2.034V1.5a.5.5 0 0 0-1 0v.534a4.002 4.002 0 0 0-2.31.954l1.43 1.43A4.988 4.988 0 0 1 8 3c1.396 0 2.64 1.12 2.64 2.5 0 .78-.344 1.465-.923 2.099l.753.753A3.49 3.49 0 0 0 11.36 5.5c0-1.39-1.12-2.5-2.5-2.5a2.492 2.492 0 0 0-1.072.274l.753.753a1.5 1.5 0 0 1 1.459-1.42zM4.182 5.103A3.53 3.53 0 0 0 3.64 5.5C3.64 6.88 4.76 8 6.14 8c.454 0 .878-.125 1.226-.348l.753.753A3.49 3.49 0 0 1 6.14 9c-1.933 0-3.5-1.567-3.5-3.5 0-.52.115-1.012.32-1.465l.722.722zM1.99 1.99L.293 3.707a.5.5 0 0 0 .707.707l.255-.255A7.027 7.027 0 0 0 1 7c0 2.42 1.22 4.535 3.187 5.623L3 13.5a.5.5 0 0 0 1 0v-.243a4.524 4.524 0 0 0 1.944-.896l.255.255a.5.5 0 0 0 .707-.707L1.99 1.99zM8.5 16a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"/></svg><p>Belum ada notifikasi untuk Anda.</p></div>';
  } else {
    echo '<div class="list-group">';
    $unread_personal_ids = [];
    $unread_broadcast_ids = [];

    foreach ($notifications as $notif) {
      $bg_class = $notif['is_read'] ? '' : 'list-group-item-light';

      // Kumpulkan ID notifikasi yang belum dibaca berdasarkan tipenya
      if (!$notif['is_read']) {
        if ($notif['type'] === 'personal') {
          $unread_personal_ids[] = $notif['id'];
        } else {
          $unread_broadcast_ids[] = $notif['id'];
        }
      }

      // Ikon default untuk tugas
      $icon = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-journal-check text-primary me-3" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M10.854 6.146a.5.5 0 0 1 0 .708l-3 3a.5.5 0 0 1-.708 0l-1.5-1.5a.5.5 0 1 1 .708-.708L7.5 8.793l2.646-2.647a.5.5 0 0 1 .708 0z"/><path d="M3 0h10a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2v-1h1v1a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1H3a1 1 0 0 0-1 1v1H1V2a2 2 0 0 1 2-2z"/><path d="M1 5v-.5a.5.5 0 0 1 1 0V5h.5a.5.5 0 0 1 0 1h-2a.5.5 0 0 1 0-1H1zm0 3v-.5a.5.5 0 0 1 1 0V8h.5a.5.5 0 0 1 0 1h-2a.5.5 0 0 1 0-1H1zm0 3v-.5a.5.5 0 0 1 1 0v.5h.5a.5.5 0 0 1 0 1h-2a.5.5 0 0 1 0-1H1z"/></svg>';

      $time = new DateTime($notif['created_at']);
      $time_ago = time_elapsed_string($time);

      echo '<a href="' . h($notif['link']) . '" class="list-group-item list-group-item-action ' . $bg_class . '">';
      echo '  <div class="d-flex w-100 align-items-center">' . $icon . '<div>';
      echo '      <p class="mb-1">' . $notif['message'] . '</p>';
      echo '      <small class="text-muted">' . $time_ago . '</small>';
      echo '  </div></div></a>';
    }
    echo '</div>';

    // Tandai notifikasi sebagai "sudah dibaca"
    if (!empty($unread_personal_ids)) {
      $in_clause = implode(',', array_fill(0, count($unread_personal_ids), '?'));
      q("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND id IN ($in_clause)", array_merge([$current_user_id], $unread_personal_ids));
    }
    if (!empty($unread_broadcast_ids)) {
      $stmt = pdo()->prepare("INSERT IGNORE INTO user_notification_reads (user_id, notification_id) VALUES (?, ?)");
      foreach ($unread_broadcast_ids as $notif_id) {
        $stmt->execute([$current_user_id, $notif_id]);
      }
    }
  }
}

// Helper function untuk format waktu (letakkan di dekat fungsi lain)
function time_elapsed_string($datetime, $full = false)
{
  $now = new DateTime;
  $ago = $datetime;
  $diff = $now->diff($ago);

  $diff->w = floor($diff->d / 7);
  $diff->d -= $diff->w * 7;

  $string = ['y' => 'tahun', 'm' => 'bulan', 'w' => 'minggu', 'd' => 'hari', 'h' => 'jam', 'i' => 'menit', 's' => 'detik'];
  foreach ($string as $k => &$v) {
    if ($diff->$k) {
      $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? '' : '');
    } else {
      unset($string[$k]);
    }
  }

  if (!$full) $string = array_slice($string, 0, 1);
  return $string ? implode(', ', $string) . ' yang lalu' : 'baru saja';
}
// ===============================================
// AKHIR VIEW NOTIFIKASI
// ===============================================


// ▼▼▼ TAMBAHKAN FUNGSI BARU INI DI SINI ▼▼▼
/**
 * Helper untuk me-render kontrol paginasi Bootstrap
 * @param int $current_page Halaman saat ini
 * @param int $total_pages Total halaman
 * @param string $base_url_params Parameter URL dasar (contoh: "page=kelola_tugas&inst_id=1")
 * @param string $page_param_name Nama parameter untuk halaman (default: "p")
 */
function render_pagination_controls($current_page, $total_pages, $base_url_params, $page_param_name = 'p')
{
    echo '<nav aria-label="Navigasi Halaman" class="mt-4 d-flex justify-content-center">';
    echo '  <ul class="pagination">';
    
    // Tombol "Previous"
    $prev_disabled = ($current_page <= 1) ? ' disabled' : '';
    echo '    <li class="page-item' . $prev_disabled . '">';
    echo '      <a class="page-link" href="?' . $base_url_params . '&' . $page_param_name . '=' . ($current_page - 1) . '" aria-label="Previous">&laquo;</a>';
    echo '    </li>';

    // Logika angka halaman (jendela)
    $window = 2; // Tampilkan 2 angka sebelum dan sesudah halaman saat ini
    for ($i = 1; $i <= $total_pages; $i++) {
        if (
            $i == 1 || // Selalu tampilkan halaman 1
            $i == $total_pages || // Selalu tampilkan halaman terakhir
            ($i >= $current_page - $window && $i <= $current_page + $window) // Tampilkan "jendela"
        ) {
            if ($i == $current_page) {
                echo '    <li class="page-item active" aria-current="page"><span class="page-link">' . $i . '</span></li>';
            } else {
                echo '    <li class="page-item"><a class="page-link" href="?' . $base_url_params . '&' . $page_param_name . '=' . $i . '">' . $i . '</a></li>';
            }
        } elseif (
            ($i == $current_page - $window - 1) ||
            ($i == $current_page + $window + 1)
        ) {
            // Tampilkan '...' sebagai pemisah
            echo '    <li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }
    
    // Tombol "Next"
    $next_disabled = ($current_page >= $total_pages) ? ' disabled' : '';
    echo '    <li class="page-item' . $next_disabled . '">';
    echo '      <a class="page-link" href="?' . $base_url_params . '&' . $page_param_name . '=' . ($current_page + 1) . '" aria-label="Next">&raquo;</a>';
    echo '    </li>';
    
    echo '  </ul>';
    echo '</nav>';
}
// ▲▲▲ AKHIR FUNGSI BARU ▲▲▲


/**
 * Menangani permintaan download data soal dalam format CSV.
 */
function handle_download_csv()
{
    // Keamanan
    if (!is_admin()) {
        http_response_code(403);
        echo "Akses ditolak.";
        exit;
    }

    $title_id = (int)($_GET['title_id'] ?? 0);
    if ($title_id <= 0) {
        http_response_code(400);
        echo "ID Judul tidak valid.";
        exit;
    }

    // Ambil data dari DB
    $questions = q("
        SELECT
            t.name AS theme_name,
            st.name AS subtheme_name,
            qt.title AS title_name,
            q.text AS question_text,
            q.id AS question_id,
            q.explanation
        FROM questions q
        JOIN quiz_titles qt ON q.title_id = qt.id
        JOIN subthemes st ON qt.subtheme_id = st.id
        JOIN themes t ON st.theme_id = t.id
        WHERE q.title_id = ?
        ORDER BY q.id ASC
    ", [$title_id])->fetchAll();

    if (!$questions) {
        echo "Tidak ada data soal.";
        exit;
    }
    
        // Siapkan nama file
    $filename = "soal_" . preg_replace('/[^a-z0-9_]+/i', '', strtolower($questions[0]['subtheme_name'])) . "-" . preg_replace('/[^a-z0-9_]+/i', '', strtolower($questions[0]['title_name'])) .  ".csv";



    // Header download
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="soal_'.$filename.'.csv"');

    // Tambahkan BOM UTF-8 agar Excel bisa baca huruf Arab
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');

    // Judul kolom
    fputcsv($out, ['Tema', 'Subtema', 'Judul', 'Pertanyaan', 'A', 'B', 'C', 'D', 'E', 'Kunci', 'Pembahasan'], ';');

    foreach ($questions as $q) {
        // Ambil pilihan jawaban
        $choices = q("SELECT text, is_correct FROM choices WHERE question_id = ? ORDER BY id ASC", [$q['question_id']])->fetchAll();
        $opsi = ['', '', '', '', '', '', ''];
        foreach ($choices as $i => $c) {
            $opsi[$i] = $c['text'];
            if ($c['is_correct']) {
                $opsi[5] = chr(65 + $i); // huruf A, B, C, dst
            }
        }

        fputcsv($out, [
            $q['theme_name'],
            $q['subtheme_name'],
            $q['title_name'],
            $q['question_text'],
            $opsi[0], $opsi[1], $opsi[2], $opsi[3], $opsi[4],
            $opsi[5],
            $q['explanation']
        ], ';');
    }

    fclose($out);
    exit;
}

/**
 * Menangani permintaan rekap nilai untuk sebuah kelas.
 * Output: CSV yang di-download berisi skor tiap siswa per tugas.
 */
function handle_rekap_nilai()
{
  // Periksa autentikasi dasar
  if (!uid()) {
    http_response_code(403);
    echo "Login diperlukan.";
    exit;
  }

  $id_kelas = (int)($_GET['id_kelas'] ?? 0);
  if ($id_kelas <= 0) {
    http_response_code(400);
    echo "ID kelas tidak valid.";
    exit;
  }

  // Cek kepemilikan kelas: pengajar pemilik atau admin
  if (!is_admin()) {
    $owner = q("SELECT id_pengajar FROM classes WHERE id = ? LIMIT 1", [$id_kelas])->fetch();
    if (!$owner || (int)$owner['id_pengajar'] !== (int)uid()) {
      http_response_code(403);
      echo "Akses ditolak.";
      exit;
    }
  }

  // Ambil daftar tugas untuk kelas
  $assignments = q("SELECT id, judul_tugas FROM assignments WHERE id_kelas = ? ORDER BY created_at", [$id_kelas])->fetchAll(PDO::FETCH_ASSOC);

  // Ambil daftar siswa anggota kelas
  $students = q("SELECT u.id, u.name, u.email FROM users u JOIN class_members cm ON cm.id_pelajar = u.id WHERE cm.id_kelas = ? ORDER BY u.name", [$id_kelas])->fetchAll(PDO::FETCH_ASSOC);

  // Ambil skor tertinggi per assignment per user (jika ada)
  $scores = [];
  if (!empty($assignments)) {
    $assignment_ids = array_column($assignments, 'id');
    $placeholders = implode(',', array_fill(0, count($assignment_ids), '?'));
    $sql = "SELECT asub.assignment_id, asub.user_id, MAX(r.score) AS score
        FROM assignment_submissions asub
        JOIN results r ON asub.result_id = r.id
        WHERE asub.assignment_id IN ($placeholders)
        GROUP BY asub.assignment_id, asub.user_id";
    $st = pdo()->prepare($sql);
    $st->execute($assignment_ids);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
      $scores[$r['user_id']][$r['assignment_id']] = $r['score'];
    }
  }

  // Stream CSV
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="rekap_nilai_kelas_' . $id_kelas . '.csv"');
  $out = fopen('php://output', 'w');
  // Tulis BOM untuk kompatibilitas Excel
  fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

  // Header CSV
  $header = ['No', 'User ID', 'Nama', 'Email'];
  foreach ($assignments as $a) $header[] = $a['judul_tugas'];
  $header[] = 'Rata-rata';
  fputcsv($out, $header);

  $no = 1;
  foreach ($students as $s) {
    $line = [$no, $s['id'], $s['name'], $s['email']];
    $sum = 0; $count = 0;
    foreach ($assignments as $a) {
      $sc = $scores[$s['id']][$a['id']] ?? '';
      if ($sc !== '') { $sum += (float)$sc; $count++; }
      $line[] = $sc;
    }
    $avg = $count ? round($sum / $count, 2) : '';
    $line[] = $avg;
    fputcsv($out, $line);
    $no++;
  }

  fclose($out);
  exit;
}

// ===============================================
// AWAL HANDLER KELOLA USER
// ===============================================






/**
 * Memproses permintaan perubahan role dan user_type dari halaman kelola user.
 */
function handle_update_user_role()
{
  if (!is_admin()) {
    redirect('./');
    return;
  }

  $user_id = (int)($_POST['user_id'] ?? 0);
  if ($user_id <= 0) {
    redirect('?page=kelola_user');
    return;
  }

  $new_role = trim($_POST['role'] ?? '');
  $new_user_type = trim($_POST['user_type'] ?? '');

  $fields = [];
  $params = [];

  // 1. Siapkan update untuk 'role' jika ada
  $allowed_roles = ['admin', 'pengajar', 'pelajar', 'umum'];
  if (!empty($new_role) && in_array($new_role, $allowed_roles, true)) {
    if ($user_id === uid() && $new_role !== 'admin') {
      redirect('?page=kelola_user&err=self_demote');
      return;
    }
    $fields[] = "role = ?";
    $params[] = $new_role;
  }

  // 2. Siapkan update untuk 'user_type' jika ada
  $allowed_user_types = ['Pengajar', 'Pelajar', 'Umum'];
  if (!empty($new_user_type) && in_array($new_user_type, $allowed_user_types, true)) {
    $fields[] = "user_type = ?";
    $params[] = $new_user_type;
  }

  // 3. Jalankan query jika ada field yang akan diubah
  if (!empty($fields)) {
    $fields[] = "updated_at = NOW()";
    $sql = "UPDATE users SET " . implode(", ", $fields) . " WHERE id = ?";
    $params[] = $user_id;
    q($sql, $params);
  }

  redirect('?page=kelola_user&ok=1');
}


// ===============================================
// AWAL HANDLER KELOLA USER
// ===============================================

/**
 * Memproses form edit tugas.
 */
function handle_edit_tugas() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SESSION['user']['role'] ?? '') !== 'pengajar') {
        redirect('./');
    }
    
    $id_pengajar = uid();
    
    // Ambil semua data dari form
    $assignment_id = (int)($_POST['assignment_id'] ?? 0);
    $judul_tugas = trim($_POST['judul_tugas'] ?? '');
    $id_judul_soal = (int)($_POST['id_judul_soal'] ?? 0);
    $mode = trim($_POST['mode'] ?? 'bebas');
    $batas_waktu = !empty($_POST['batas_waktu']) ? date('Y-m-d H:i:s', strtotime($_POST['batas_waktu'])) : null;
    $inst_id = (int)($_POST['inst_id'] ?? 0); // Untuk redirect

    // Validasi
    if (!in_array($mode, ['instant', 'end', 'exam', 'bebas'])) $mode = 'bebas';

    // Keamanan: Verifikasi kepemilikan tugas sebelum update
    $assignment = q("SELECT id FROM assignments WHERE id = ? AND id_pengajar = ?", [$assignment_id, $id_pengajar])->fetch();

    if ($assignment && !empty($judul_tugas) && $id_judul_soal > 0) {
        q("
            UPDATE assignments 
            SET judul_tugas = ?, id_judul_soal = ?, mode = ?, batas_waktu = ?
            WHERE id = ?
        ", [$judul_tugas, $id_judul_soal, $mode, $batas_waktu, $assignment_id]);
    }

    redirect('?page=kelola_tugas&inst_id=' . $inst_id . '&edited=1');
}

/**
 * Memproses permintaan hapus tugas.
 */
function handle_delete_tugas() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SESSION['user']['role'] ?? '') !== 'pengajar') {
        redirect('./');
    }
    
    $id_pengajar = uid();
    $assignment_id = (int)($_POST['assignment_id'] ?? 0);
    $inst_id = (int)($_POST['inst_id'] ?? 0); // Untuk redirect

    // Keamanan: Verifikasi kepemilikan tugas sebelum hapus
    $assignment = q("SELECT id FROM assignments WHERE id = ? AND id_pengajar = ?", [$assignment_id, $id_pengajar])->fetch();

    if ($assignment) {
        // Hapus tugas. Data di `assignment_submissions` akan otomatis terhapus karena ON DELETE CASCADE.
        q("DELETE FROM assignments WHERE id = ?", [$assignment_id]);
    }
    
    redirect('?page=kelola_tugas&inst_id=' . $inst_id . '&deleted=1');
}


function handle_kirim_rekap() {
    // Pastikan ini adalah respons AJAX/API
    header('Content-Type: application/json; charset=UTF-8');

    // Keamanan: Pastikan pengajar & POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_pengajar()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Akses ditolak.']);
        exit;
    }

    $id_pengajar = uid();
    $assignment_id = (int)($_POST['assignment_id'] ?? 0);

    try {
        // 1. Verifikasi kepemilikan dan ambil detail tugas
        $assignment_details = q(
            "SELECT id_kelas, judul_tugas FROM assignments WHERE id = ? AND id_pengajar = ?", 
            [$assignment_id, $id_pengajar]
        )->fetch();

        if (!$assignment_details) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Tugas tidak ditemukan atau akses ditolak.']);
            exit;
        }
        
        $id_kelas = (int)$assignment_details['id_kelas'];
        $judul_tugas = $assignment_details['judul_tugas'];

        // 2. Ambil detail kelas (nama dan link WA)
        $class_details = q("SELECT nama_kelas, wa_link FROM classes WHERE id = ?", [$id_kelas])->fetch();

        if (!$class_details || empty($class_details['wa_link'])) {
            echo json_encode(['ok' => false, 'error' => 'Link WA Grup belum diatur untuk kelas ini.', 'type' => 'warning']);
            exit;
        }

        $nama_kelas = $class_details['nama_kelas'];
        $wa_link_group = $class_details['wa_link'];

        // 3. Ambil daftar siswa yang mendapat nilai 100
        $perfect_scorers = q("
            SELECT u.name
            FROM assignment_submissions asub
            JOIN results r ON asub.result_id = r.id
            JOIN users u ON asub.user_id = u.id
            WHERE asub.assignment_id = ? AND r.score = 100
            ORDER BY asub.submitted_at DESC
        ", [$assignment_id])->fetchAll(PDO::FETCH_COLUMN);

        if (empty($perfect_scorers)) {
            echo json_encode(['ok' => false, 'error' => 'Belum ada siswa yang mendapat nilai 100.', 'type' => 'info']);
            exit;
        }

        // 4. Susun pesan 
        $today_date = date('d F Y');
        $message_header = "🎉 Daftar Mahasiswa yang mendapat nilai 100_.\n\nNama Tugas : $judul_tugas\nHari : $today_date\nKelas : $nama_kelas\n\n";
        
        $student_list_lines = [];
        foreach ($perfect_scorers as $index => $student_name) {
            $student_list_lines[] = ($index + 1) . ". " . $student_name;
        }
        $student_list_string = implode("\n", $student_list_lines);

        $assignment_link = base_url() . '?page=student_tasks';
        $footer_message = "\n\nBagi yang belum, yuk kerjakan tugasnya di sini:\n" . $assignment_link;
        $full_message = $message_header . $student_list_string . $footer_message;
        
        // 5. Kirim pesan
        $wa_success = wa_send($wa_link_group, $full_message);

        if ($wa_success) {
            // 6. Respon Sukses JSON
            echo json_encode(['ok' => true, 'message' => 'Rekap berhasil dikirim ke grup WhatsApp.', 'type' => 'success']);
            exit;
        } else {
             // 7. Respon Gagal Kirim WA (dari fungsi wa_send)
            error_log('Gagal mengirim WA dari wa_send untuk assignment ID: ' . $assignment_id);
            echo json_encode(['ok' => false, 'error' => 'Gagal mengirim pesan ke WhatsApp.', 'type' => 'danger']);
            exit;
        }

    } catch (Throwable $e) {
        // Tangkap kesalahan server umum
        error_log('Error handle_kirim_rekap: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Terjadi kesalahan server saat memproses data.', 'type' => 'danger']);
        exit;
    }
}
// GANTI SELURUH FUNGSI handle_beri_tugas() DENGAN INI

function handle_beri_tugas()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array(($_SESSION['user']['role'] ?? ''), ['pengajar', 'admin'])) {
        redirect('./');
    }

    $id_pengajar = uid();

    // 1. Ambil semua data dari form, termasuk yang baru
    $judul_tugas = trim($_POST['judul_tugas'] ?? '');
    $id_judul_soal = (int)($_POST['id_judul_soal'] ?? 0);
    $mode = trim($_POST['mode'] ?? 'bebas');
    $batas_waktu = !empty($_POST['batas_waktu']) ? date('Y-m-d H:i:s', strtotime($_POST['batas_waktu'])) : null;
    $id_institusi = (int)($_POST['id_institusi'] ?? 0);
    $id_kelas_array = $_POST['id_kelas'] ?? [];
    
    // Ambil nilai kustom, jadikan NULL jika kosong
    $jumlah_soal = !empty($_POST['jumlah_soal']) ? (int)$_POST['jumlah_soal'] : null;
    $timer_per_soal = !empty($_POST['timer_per_soal_detik']) ? (int)$_POST['timer_per_soal_detik'] : null;
    $durasi_ujian = !empty($_POST['durasi_ujian_menit']) ? (int)$_POST['durasi_ujian_menit'] : null;

    // Validasi dasar
    if (empty($judul_tugas) || $id_judul_soal <= 0 || empty($id_kelas_array)) {
        redirect('?page=kelola_tugas&inst_id=' . $id_institusi . '&err=incomplete');
    }
    if (!in_array($mode, ['instant', 'end', 'exam', 'bebas'])) {
        $mode = 'bebas';
    }

    // 2. Siapkan query INSERT dengan kolom-kolom baru
    $assignment_stmt = pdo()->prepare(
        "INSERT INTO assignments 
            (id_kelas, id_pengajar, id_judul_soal, judul_tugas, mode, batas_waktu, jumlah_soal, timer_per_soal_detik, durasi_ujian_menit) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $notification_stmt = pdo()->prepare(
        "INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)"
    );

    // 3. Lakukan looping untuk setiap kelas yang dipilih
    foreach ($id_kelas_array as $id_kelas) {
        $id_kelas = (int)$id_kelas;
        
        $kelas = q("SELECT nama_kelas FROM classes WHERE id = ? AND id_pengajar = ?", [$id_kelas, $id_pengajar])->fetch();
        if (!$kelas) {
            continue; 
        }

        // a. Eksekusi query INSERT dengan parameter baru
        $assignment_stmt->execute([
            $id_kelas, $id_pengajar, $id_judul_soal, $judul_tugas, $mode, $batas_waktu,
            $jumlah_soal, $timer_per_soal, $durasi_ujian
        ]);
        
        // Dapatkan ID tugas yang baru saja dibuat
        $new_assignment_id = pdo()->lastInsertId();

        // b. Buat link notifikasi yang baru: mengarah ke tugas, bukan kuis langsung
        $link = "?page=play&assignment_id=" . $new_assignment_id;

        // c. Ambil semua siswa di kelas ini
        $anggota_ids = q("
    SELECT cm.id_pelajar 
    FROM class_members cm
    JOIN users u ON cm.id_pelajar = u.id   /* <-- PASTIKAN USER MASIH ADA */
    WHERE cm.id_kelas = ?
", [$id_kelas])->fetchAll(PDO::FETCH_COLUMN);

        // d. Kirim notifikasi ke setiap siswa
        if ($anggota_ids) {
            $message = "Tugas baru: \"" . h($judul_tugas) . "\" di kelas " . h($kelas['nama_kelas']) . ". Klik untuk mengerjakan.";
            foreach ($anggota_ids as $id_pelajar) {
                $notification_stmt->execute([(int)$id_pelajar, $message, $link]);
            }
        }
    }

    redirect('?page=kelola_tugas&inst_id=' . $id_institusi . '&ok=1');
}


/**
 * Menampilkan halaman untuk mengedit detail sebuah tugas.
 */
function view_edit_tugas()
{
    // Keamanan: Pastikan hanya pengajar yang bisa mengakses
    if (($_SESSION['user']['role'] ?? '') !== 'pengajar') {
        echo '<div class="alert alert-danger">Hanya pengajar yang dapat mengakses halaman ini.</div>';
        return;
    }

    $id_pengajar = uid();
    $assignment_id = (int)($_GET['assignment_id'] ?? 0);

    // Ambil detail tugas dan verifikasi kepemilikan
    $assignment = q("
        SELECT a.*, c.nama_kelas, c.id_institusi 
        FROM assignments a 
        JOIN classes c ON a.id_kelas = c.id 
        WHERE a.id = ? AND a.id_pengajar = ?
    ", [$assignment_id, $id_pengajar])->fetch();

    if (!$assignment) {
        echo '<div class="alert alert-danger">Tugas tidak ditemukan atau Anda tidak memiliki akses.</div>';
        return;
    }

    // Ambil semua kuis untuk mengisi dropdown
    $all_quizzes = q("SELECT id, title FROM quiz_titles ORDER BY title ASC")->fetchAll();

    // Mulai render form
    echo '<div class="d-flex justify-content-between align-items-center mb-3">';
    echo '  <h3>Edit Tugas</h3>';
    echo '  <a href="?page=kelola_institusi&inst_id=' . $assignment['id_institusi'] . '" class="btn btn-outline-secondary btn-sm">&laquo; Batal</a>';
    echo '</div>';

    echo '<div class="card">';
    echo '  <div class="card-body">';
    echo '      <form action="?action=edit_tugas" method="POST">';
    echo '          <input type="hidden" name="assignment_id" value="' . $assignment_id . '">';
    echo '          <input type="hidden" name="inst_id" value="' . $assignment['id_institusi'] . '">'; // Penting untuk redirect

    // Field Judul Tugas
    echo '          <div class="mb-3"><label class="form-label">Judul Tugas</label><input type="text" name="judul_tugas" class="form-control" value="' . h($assignment['judul_tugas']) . '" required></div>';
    
    // Info Kelas (tidak bisa diubah dari sini)
    echo '          <div class="mb-3"><label class="form-label">Kelas</label><input type="text" class="form-control" value="' . h($assignment['nama_kelas']) . '" disabled readonly></div>';

    // Dropdown Pilih Kuis
    echo '          <div class="mb-3"><label class="form-label">Pilih Kuis</label><select name="id_judul_soal" class="form-select" required>';
    foreach ($all_quizzes as $quiz) {
        $selected = ($quiz['id'] == $assignment['id_judul_soal']) ? ' selected' : '';
        echo '              <option value="' . $quiz['id'] . '"' . $selected . '>' . h($quiz['title']) . '</option>';
    }
    echo '          </select></div>';

    // Dropdown Mode Pengerjaan
    $modes = ['bebas' => 'Bebas', 'instant' => 'Instan Review', 'end' => 'End Review', 'exam' => 'Ujian'];
    echo '          <div class="mb-3"><label class="form-label">Mode Pengerjaan</label><select name="mode" class="form-select" required>';
    foreach ($modes as $key => $label) {
        $selected = ($key == $assignment['mode']) ? ' selected' : '';
        echo '              <option value="' . $key . '"' . $selected . '>' . $label . '</option>';
    }
    echo '          </select></div>';

    // Field Batas Waktu
    $batas_waktu_formatted = $assignment['batas_waktu'] ? date('Y-m-d\TH:i', strtotime($assignment['batas_waktu'])) : '';
    echo '          <div class="mb-3"><label class="form-label">Batas Waktu (Opsional)</label><input type="datetime-local" name="batas_waktu" class="form-control" value="' . $batas_waktu_formatted . '"></div>';
    
    // Tombol Simpan
    echo '          <button type="submit" class="btn btn-primary">Simpan Perubahan</button>';
    echo '      </form>';
    echo '  </div>';
    echo '</div>';
}



// === VIEW KELOLA TUGAS ===

/**
 * Menampilkan halaman detail progres pengerjaan sebuah tugas.
 * Halaman ini menampilkan siapa saja siswa yang sudah dan belum mengerjakan.
 */
function view_detail_tugas()
{
    // Keamanan: Pastikan hanya pengajar yang bisa mengakses
    if (($_SESSION['user']['role'] ?? '') !== 'pengajar') {
        echo '<div class="alert alert-danger">Hanya pengajar yang dapat mengakses halaman ini.</div>';
        return;
    }

    $id_pengajar = uid();
    $assignment_id = (int)($_GET['assignment_id'] ?? 0);

    // 1. Ambil detail tugas dan verifikasi kepemilikan oleh pengajar yang sedang login
    $assignment = q("
        SELECT a.*, qt.title as quiz_title, c.nama_kelas, c.id_institusi
        FROM assignments a
        JOIN quiz_titles qt ON a.id_judul_soal = qt.id
        JOIN classes c ON a.id_kelas = c.id
        WHERE a.id = ? AND a.id_pengajar = ?
    ", [$assignment_id, $id_pengajar])->fetch();

    if (!$assignment) {
        echo '<div class="alert alert-danger">Tugas tidak ditemukan atau Anda tidak memiliki akses.</div>';
        return;
    }

    // 2. Ambil semua siswa yang terdaftar di kelas ini
    $semua_siswa = q("
        SELECT u.id, u.name, u.avatar
        FROM class_members cm
        JOIN users u ON cm.id_pelajar = u.id
        WHERE cm.id_kelas = ?
        ORDER BY u.name ASC
    ", [$assignment['id_kelas']])->fetchAll(PDO::FETCH_ASSOC);

    // 3. Ambil data siswa yang sudah mengerjakan tugas ini beserta nilainya
    $submissions = q("
        SELECT asub.user_id, r.score, asub.submitted_at
        FROM assignment_submissions asub
        JOIN results r ON asub.result_id = r.id
        WHERE asub.assignment_id = ?
    ", [$assignment_id])->fetchAll(PDO::FETCH_ASSOC);

    // 4. Proses data untuk memudahkan pengecekan
    $submission_map = [];
    foreach ($submissions as $sub) {
        $submission_map[$sub['user_id']] = [
            'score' => $sub['score'],
            'submitted_at' => $sub['submitted_at']
        ];
    }

    // 5. Pisahkan siswa menjadi dua kelompok: sudah dan belum mengerjakan
    $sudah_mengerjakan = [];
    $belum_mengerjakan = [];

    foreach ($semua_siswa as $siswa) {
        if (isset($submission_map[$siswa['id']])) {
            // Siswa sudah mengerjakan
            $sudah_mengerjakan[] = [
                'id' => $siswa['id'],
                'name' => $siswa['name'],
                'avatar' => $siswa['avatar'],
                'score' => $submission_map[$siswa['id']]['score'],
                'submitted_at' => $submission_map[$siswa['id']]['submitted_at']
            ];
        } else {
            // Siswa belum mengerjakan
            $belum_mengerjakan[] = $siswa;
        }
    }

    // 6. Tampilkan ke browser
    echo '<div class="d-flex justify-content-between align-items-center mb-3">';
    echo '  <div><h3>Progres Tugas: ' . h($assignment['judul_tugas']) . '</h3><p class="text-muted mb-0">Kelas: ' . h($assignment['nama_kelas']) . '</p></div>';
    echo '  <a href="?page=kelola_tugas&inst_id=' . $assignment['id_institusi'] . '" class="btn btn-outline-secondary btn-sm">&laquo; Kembali ke Kelola Tugas</a>';
    echo '</div>';

    echo '<div class="row g-4">';

    // Kolom Kiri: Sudah Mengerjakan
    echo '  <div class="col-md-6">';
    echo '    <div class="card">';
    echo '      <div class="card-header bg-success text-white">';
    echo '        <h5 class="mb-0">✅ Sudah Mengerjakan (' . count($sudah_mengerjakan) . ')</h5>';
    echo '      </div>';
    if (empty($sudah_mengerjakan)) {
        echo '<div class="card-body text-center text-muted">Belum ada siswa yang mengerjakan.</div>';
    } else {
        echo '      <ul class="list-group list-group-flush">';
        foreach ($sudah_mengerjakan as $siswa) {
            echo '<li class="list-group-item d-flex justify-content-between align-items-center">';
            echo '  <div class="d-flex align-items-center">';
            echo '    <img src="' . h($siswa['avatar']) . '" class="avatar me-3" style="width:40px; height:40px;">';
            echo '    <div>';
            echo '      <div class="fw-bold">' . h($siswa['name']) . '</div>';
            echo '      <small class="text-muted">Mengumpulkan pada: ' . date('d M Y, H:i', strtotime($siswa['submitted_at'])) . '</small>';
            echo '    </div>';
            echo '  </div>';
            echo '  <span class="badge bg-primary rounded-pill fs-6">' . $siswa['score'] . '</span>';
            echo '</li>';
        }
        echo '      </ul>';
    }
    echo '    </div>';
    echo '  </div>';

    // Kolom Kanan: Belum Mengerjakan
    echo '  <div class="col-md-6">';
    echo '    <div class="card">';
    echo '      <div class="card-header bg-warning">';
    echo '        <h5 class="mb-0">⚠️ Belum Mengerjakan (' . count($belum_mengerjakan) . ')</h5>';
    echo '      </div>';
    if (empty($belum_mengerjakan)) {
        echo '<div class="card-body text-center text-muted">Semua siswa telah mengerjakan tugas ini.</div>';
    } else {
        echo '      <ul class="list-group list-group-flush">';
        foreach ($belum_mengerjakan as $siswa) {
            echo '<li class="list-group-item d-flex align-items-center">';
            echo '  <img src="' . h($siswa['avatar']) . '" class="avatar me-3" style="width:40px; height:40px;">';
            echo '  <div class="fw-bold">' . h($siswa['name']) . '</div>';
            echo '</li>';
        }
        echo '      </ul>';
    }
    echo '    </div>';
    echo '  </div>';

    echo '</div>'; // penutup .row
}

// >>> MULAI LETAKKAN KODE BARU DI SINI <<<

/**
 * Menampilkan halaman pemilihan institusi dan pengelolaan kelas.
 */
function view_kelola_institusi_kelas()
{
    // Keamanan: Pastikan hanya pengajar atau admin
    if (!is_pengajar() && !is_admin()) { // Gunakan fungsi is_pengajar() yang baru
        echo '<div class="alert alert-danger">Hanya pengajar atau admin yang dapat mengakses halaman ini.</div>';
        return;
    }


// >>> TAMBAHKAN BLOK NOTIFIKASI INI (MULAI DARI SINI) <<<
    if (isset($_GET['ok'])) {
        $message = '';
        if ($_GET['ok'] === 'kelas_edited') $message = 'Nama kelas berhasil diperbarui.';
        if ($_GET['ok'] === 'kelas_deleted') $message = 'Kelas berhasil dihapus.';
        if ($_GET['ok'] === 'institusi_deleted') $message = 'Institusi berhasil dihapus.';
        // Tambahkan alert-dismissible agar bisa ditutup
        if ($message) echo '<div class="alert alert-success alert-dismissible fade show" role="alert">' . $message . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    }
    if (isset($_GET['err'])) {
         $message = 'Terjadi kesalahan.';
         if ($_GET['err'] === 'edit_invalid') $message = 'Gagal mengedit kelas: Data tidak valid.';
         if ($_GET['err'] === 'delete_invalid') $message = 'Gagal menghapus: Data tidak valid.';
         if ($_GET['err'] === 'kelas_not_found') $message = 'Gagal: Kelas tidak ditemukan atau Anda tidak punya akses.';
         if ($_GET['err'] === 'institusi_not_found') $message = 'Gagal: Institusi tidak ditemukan atau Anda tidak punya akses.';
         // Tambahkan alert-dismissible agar bisa ditutup
         echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">' . $message . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    }



    $id_pengajar = uid();

    echo '<h3>Kelola Institusi & Kelas</h3>';

    // Ambil daftar institusi milik pengajar
    $institutions = q("SELECT * FROM teacher_institutions WHERE id_pengajar = ? ORDER BY nama_institusi ASC", [$id_pengajar])->fetchAll();

    echo '<div class="card mb-4"><div class="card-body">';
    echo '<h5 class="card-title">Pilih Institusi</h5>';

    if ($institutions) {
        echo '<p>Pilih institusi untuk mengelola kelas atau tugas:</p>';
        echo '<div class="list-group">';
        foreach ($institutions as $inst) {
            echo '<div class="list-group-item list-group-item-action d-flex justify-content-between align-items-center flex-wrap">'; // flex-wrap untuk mobile
            echo '  <span class="fw-bold me-3 mb-2 mb-md-0">' . h($inst['nama_institusi']) . '</span>'; // Beri margin dan tebalkan
            echo '  <div class="btn-group btn-group-sm">'; // btn-group-sm
            // Tombol untuk Kelola Kelas di Institusi ini (pakai collapse)
            echo '      <button type="button" class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#kelasCollapse-' . $inst['id'] . '" aria-expanded="false">Kelola Kelas</button>';
            // Tombol untuk Langsung Kelola Tugas (link ke halaman baru)
            echo '      <a href="?page=kelola_tugas&inst_id=' . $inst['id'] . '" class="btn btn-primary">Kelola Tugas</a>';
            // >>> TAMBAHKAN FORM HAPUS INSTITUSI INI <<<
    echo '      <form method="POST" action="?action=delete_institusi" class="d-inline" onsubmit="return confirm(\'Yakin ingin menghapus institusi '.h($inst['nama_institusi']).' beserta SEMUA kelas dan tugas di dalamnya? Tindakan ini tidak bisa dibatalkan!\');">';
    echo '          <input type="hidden" name="id_institusi" value="' . $inst['id'] . '">';
    echo '          <button type="submit" class="btn btn-danger" title="Hapus Institusi"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-trash" viewBox="0 0 16 16"><path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/><path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/></svg></button>';
    echo '      </form>';
    // >>> AKHIR TAMBAHAN FORM <<<
            echo '  </div>';
            echo '</div>'; // penutup list-group-item

            // Collapse content for managing classes
            echo '<div class="collapse mt-2" id="kelasCollapse-' . $inst['id'] . '">';
            echo '  <div class="card card-body shadow-sm">'; // Tambah shadow
            echo '      <h6>Kelas di ' . h($inst['nama_institusi']) . '</h6>';
            // Ambil kelas untuk institusi ini
            $classes = q("SELECT id, nama_kelas FROM classes WHERE id_institusi = ? AND id_pengajar = ? ORDER BY nama_kelas", [$inst['id'], $id_pengajar])->fetchAll();
            if ($classes) {
                echo '<ul class="list-unstyled mb-3">'; // Beri margin bawah
                foreach ($classes as $kelas) {
                     // Link ke detail kelas
                     echo '<li class="mb-1 d-flex justify-content-between align-items-center">'; // Jadikan flex container
            // Link nama kelas ke detail kelas
            echo '  <a href="?page=detail_kelas&id_kelas=' . $kelas['id'] . '" class="text-decoration-none">' . h($kelas['nama_kelas']) . '</a>';
            // Grup tombol Edit & Hapus
            echo '  <div class="btn-group btn-group-sm">';

            // >>> TOMBOL EDIT KELAS (Trigger Modal) <<<
            echo '      <button type="button" class="btn btn-outline-primary edit-kelas-btn" data-bs-toggle="modal" data-bs-target="#editKelasModal" data-kelas-id="' . $kelas['id'] . '" data-kelas-nama="' . h($kelas['nama_kelas']) . '">Edit</button>';

            // >>> FORM HAPUS KELAS <<<
            echo '      <form method="POST" action="?action=delete_kelas" class="d-inline" onsubmit="return confirm(\'Yakin ingin menghapus kelas '.h($kelas['nama_kelas']).' beserta anggota dan tugasnya?\');">';
            echo '          <input type="hidden" name="id_kelas" value="' . $kelas['id'] . '">';
            echo '          <button type="submit" class="btn btn-outline-danger">Hapus</button>';
            echo '      </form>';
            // >>> AKHIR EDIT & HAPUS <<<

            echo '  </div>'; // penutup .btn-group
            echo '</li>'; // penutup li
                     
                }
                 echo '</ul>';
            } else {
                 echo '<p class="text-muted small">Belum ada kelas.</p>';
            }
             // Form Tambah Kelas
            echo '      <form action="?action=tambah_kelas" method="POST" class="mt-2">'; // Kurangi margin atas
            echo '          <input type="hidden" name="id_institusi" value="' . $inst['id'] . '">';
            echo '          <div class="input-group input-group-sm">';
            echo '              <input type="text" name="nama_kelas" class="form-control" placeholder="Nama kelas baru" required>';
            echo '              <button class="btn btn-success" type="submit">Tambah Kelas</button>';
            echo '          </div>';
            echo '      </form>';
            echo '  </div>'; // card-body collapse
            echo '</div>'; // collapse div
        }
        echo '</div>'; // list-group
    } else {
        echo '<div class="alert alert-info">Anda belum terhubung dengan institusi manapun. Silakan tambahkan di bawah.</div>';
    }
    echo '</div></div>'; // card-body & card

    // --- Form Tambah Institusi ---
    // (Kode form tambah institusi dari file Anda sudah benar,
    // pastikan JavaScript-nya juga ada di dekatnya atau di html_foot())
    $all_institutions_master = q("SELECT nama FROM institutions ORDER BY nama ASC")->fetchAll(PDO::FETCH_COLUMN);
    ?>
    <div class="card">
        <div class="card-header"><h5 class="mb-0">Hubungkan Institusi Baru</h5></div>
        <div class="card-body">
             <form action="?action=tambah_institusi" method="POST">
                 <input type="hidden" name="nama_institusi" id="hiddenNamaInstitusi">
                 <div id="institusiDropdownContainer">
                     <label for="namaInstitusiDropdown" class="form-label">Pilih dari daftar institusi yang ada:</label>
                     <select class="form-select" id="namaInstitusiDropdown">
                         <option value="" selected disabled>-- Pilih Institusi --</option>
                         <?php foreach ($all_institutions_master as $inst_name): ?>
                             <option value="<?php echo h($inst_name); ?>"><?php echo h($inst_name); ?></option>
                         <?php endforeach; ?>
                     </select>
                 </div>
                 <div id="institusiManualContainer" class="mt-2" style="display: none;">
                     <label for="namaInstitusiManual" class="form-label">Nama institusi baru:</label>
                     <input type="text" class="form-control" id="namaInstitusiManual" placeholder="Ketik nama sekolah/kampus Anda">
                 </div>
                 <div class="form-check mt-3">
                     <input class="form-check-input" type="checkbox" id="manualInstitutionCheckbox">
                     <label class="form-check-label" for="manualInstitutionCheckbox">
                         Institusi saya tidak ada di daftar.
                     </label>
                 </div>
                 <button class="btn btn-success mt-3" type="submit">Tambahkan Institusi</button>
             </form>
        </div>
    </div>
    <!-- Pastikan script JS untuk form ini ada di bawah atau di html_foot() -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const checkbox = document.getElementById('manualInstitutionCheckbox');
        const dropdownContainer = document.getElementById('institusiDropdownContainer');
        const manualContainer = document.getElementById('institusiManualContainer');
        const dropdown = document.getElementById('namaInstitusiDropdown');
        const manualInput = document.getElementById('namaInstitusiManual');
        const hiddenInput = document.getElementById('hiddenNamaInstitusi');

        // Pastikan semua elemen ditemukan sebelum menambah event listener
        if (checkbox && dropdownContainer && manualContainer && dropdown && manualInput && hiddenInput) {
            function updateHiddenValue() {
                if (hiddenInput) {
                     hiddenInput.value = checkbox.checked && manualInput ? manualInput.value : (dropdown ? dropdown.value : '');
                }
            }

            checkbox.addEventListener('change', function() {
                const isManual = this.checked;
                if (dropdownContainer) dropdownContainer.style.display = isManual ? 'none' : 'block';
                if (manualContainer) manualContainer.style.display = isManual ? 'block' : 'none';
                if (manualInput) manualInput.required = isManual;
                if (dropdown) dropdown.required = !isManual;
                if (isManual && manualInput) {
                    manualInput.value = '';
                    manualInput.focus();
                }
                updateHiddenValue();
            });

            if (dropdown) dropdown.addEventListener('change', updateHiddenValue);
            if (manualInput) manualInput.addEventListener('input', updateHiddenValue);

            // Inisialisasi nilai
            updateHiddenValue();
        } else {
             console.error("Satu atau lebih elemen form tambah institusi tidak ditemukan.");
        }
    });
    </script>
   
    
    <div class="modal fade" id="editKelasModal" tabindex="-1" aria-labelledby="editKelasModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="editKelasModalLabel">Edit Nama Kelas</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="?action=edit_kelas" method="POST">
            <div class="modal-body">
              <!-- Input tersembunyi untuk ID kelas -->
              <input type="hidden" name="id_kelas" id="editKelasId">
              <div class="mb-3">
                <label for="editKelasNama" class="form-label">Nama Kelas Baru</label>
                <!-- Input untuk nama kelas baru, akan diisi oleh JS -->
                <input type="text" class="form-control" id="editKelasNama" name="nama_kelas" required>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
              <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <script>
    // Script ini akan berjalan ketika halaman dimuat
    document.addEventListener('DOMContentLoaded', function () {
        var editKelasModal = document.getElementById('editKelasModal');
        if (editKelasModal) {
            // Dengarkan event 'show.bs.modal' (saat modal akan ditampilkan)
            editKelasModal.addEventListener('show.bs.modal', function (event) {
                // Ambil tombol 'Edit' yang diklik
                var button = event.relatedTarget;
                
                // Ambil data 'data-kelas-id' dan 'data-kelas-nama' dari tombol itu
                var kelasId = button.getAttribute('data-kelas-id');
                var kelasNama = button.getAttribute('data-kelas-nama');

                // Cari elemen di dalam modal
                var modalTitle = editKelasModal.querySelector('.modal-title');
                var inputId = editKelasModal.querySelector('#editKelasId');
                var inputNama = editKelasModal.querySelector('#editKelasNama');

                // Masukkan data dari tombol ke dalam modal
                modalTitle.textContent = 'Edit Nama Kelas: ' + kelasNama;
                inputId.value = kelasId;
                inputNama.value = kelasNama;
            });
        }
    });
    </script>
    <?php
// >>> AKHIR DARI BLOK KODE LANGKAH 4 <<<
}

// >>> MULAI KODE MODIFIKASI view_kelola_tugas <<<

/**
 * Menampilkan halaman kelola tugas untuk institusi yang dipilih.
 * [MODIFIKASI] Menambahkan Progress Bar (progres pengerjaan) pada setiap tugas.
 */
function view_kelola_tugas($inst_id)
{
    // Keamanan: Pastikan hanya pengajar atau admin dan inst_id valid
    if ((!is_pengajar() && !is_admin()) || $inst_id <= 0) {
        redirect('?page=kelola_institusi'); // Redirect jika tidak sah
        return;
    }

    $id_pengajar = uid();

    // Verifikasi kepemilikan institusi
    $institution = q("SELECT * FROM teacher_institutions WHERE id = ? AND id_pengajar = ?", [$inst_id, $id_pengajar])->fetch();
    if (!$institution) {
        echo '<div class="alert alert-danger">Institusi tidak ditemukan atau Anda tidak memiliki akses.</div>';
        echo '<p><a href="?page=kelola_institusi" class="btn btn-secondary btn-sm">&laquo; Kembali</a></p>'; // Tombol kembali
        return;
    }

    // Header Halaman
    echo '<div class="d-flex justify-content-between align-items-center mb-3">';
    echo '  <h3 class="mb-0">Kelola Tugas: ' . h($institution['nama_institusi']) . '</h3>';
    echo '  <a href="?page=kelola_institusi" class="btn btn-outline-secondary btn-sm">&laquo; Kembali ke Pilih Institusi</a>';
    echo '</div>';

    // Tampilkan notifikasi
     if (isset($_GET['ok'])) echo '<div class="alert alert-success alert-dismissible fade show" role="alert">Tugas baru berhasil diberikan.<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
     if (isset($_GET['edited'])) echo '<div class="alert alert-success alert-dismissible fade show" role="alert">Tugas berhasil diperbarui.<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
     if (isset($_GET['deleted'])) echo '<div class="alert alert-success alert-dismissible fade show" role="alert">Tugas berhasil dihapus.<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
     if (isset($_GET['ok_rekap'])) echo '<div class="alert alert-success alert-dismissible fade show" role="alert">Rekap WA berhasil dikirim.<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
     if (isset($_GET['err']) && $_GET['err'] === 'no_wa_link') echo '<div class="alert alert-warning alert-dismissible fade show" role="alert">Gagal mengirim rekap: Link WA Grup untuk kelas ini belum diatur.<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
     if (isset($_GET['err']) && $_GET['err'] === 'no_scorers') echo '<div class="alert alert-info alert-dismissible fade show" role="alert">Tidak ada yang dikirim: Belum ada siswa yang mendapat nilai 100 untuk tugas ini.<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
     if (isset($_GET['err']) && $_GET['err'] === 'auth') echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">Gagal: Anda tidak memiliki akses ke tugas ini.<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
     if (isset($_GET['err']) && $_GET['err'] === 'wa_failed') echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">Gagal mengirim rekap WA. Silakan cek log error server.<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
     if (isset($_GET['err'])) echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">Gagal memberikan tugas. Pastikan semua data terisi dengan benar.<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';

    // --- AWAL LOGIKA PAGINASI ---
    $page_size = 5; // Tentukan jumlah tugas per halaman
    $current_page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
    
    // 1. Hitung total tugas untuk paginasi
    $total_assignments = (int)q("
        SELECT COUNT(a.id)
        FROM assignments a
        JOIN classes c ON a.id_kelas = c.id
        WHERE c.id_institusi = ? AND a.id_pengajar = ?
    ", [$inst_id, $id_pengajar])->fetchColumn();
    
    $total_pages = ceil($total_assignments / $page_size);
    if ($total_pages <= 0) $total_pages = 1; // Pastikan minimal 1 halaman
    if ($current_page > $total_pages) $current_page = $total_pages; // Jangan lewati halaman maks

    $offset = ($current_page - 1) * $page_size;

    // 2. Ambil tugas untuk halaman ini saja (tambahkan LIMIT dan OFFSET)
    $assignments = q("
        SELECT a.id, a.judul_tugas, a.batas_waktu, a.mode, qt.title as quiz_title, c.nama_kelas, c.id as id_kelas
        FROM assignments a
        JOIN classes c ON a.id_kelas = c.id
        JOIN quiz_titles qt ON a.id_judul_soal = qt.id
        WHERE c.id_institusi = ? AND a.id_pengajar = ?
        ORDER BY a.created_at DESC
        LIMIT $page_size OFFSET $offset
    ", [$inst_id, $id_pengajar])->fetchAll();
    // --- AKHIR LOGIKA PAGINASI ---



    echo '<h5>Daftar Tugas</h5>';
    if (!$assignments) {
        // *** PENTING: Pesan jika belum ada tugas ***
        echo '<div class="alert alert-secondary">Belum ada tugas yang diberikan di institusi ini. Silakan buat tugas pertama Anda di bawah ini.</div>';
    } else {
        // Ambil SEMUA total anggota per kelas yang ada di halaman ini
        $kelas_ids = array_column($assignments, 'id_kelas');
        $total_members_map = [];
        if (!empty($kelas_ids)) {
             $in_clause = implode(',', array_fill(0, count($kelas_ids), '?'));
             $member_counts = q("SELECT id_kelas, COUNT(id_pelajar) as count FROM class_members WHERE id_kelas IN ($in_clause) GROUP BY id_kelas", $kelas_ids)->fetchAll();
             foreach($member_counts as $mc) {
                 $total_members_map[$mc['id_kelas']] = (int)$mc['count'];
             }
        }
        
        echo '<div class="list-group mb-4">';
        foreach ($assignments as $tugas) {
            
            // Hitung Progres
            $total_members = $total_members_map[$tugas['id_kelas']] ?? 0;
            $submissions_count = (int)q("SELECT COUNT(DISTINCT user_id) FROM assignment_submissions WHERE assignment_id = ?", [$tugas['id']])->fetchColumn();
            
            $progress_percent = $total_members > 0 ? round(($submissions_count / $total_members) * 100) : 0;
            $progress_percent = min(100, max(0, $progress_percent)); // Clamp 0-100%

            // Tentukan status batas waktu
            $batas_waktu_obj = $tugas['batas_waktu'] ? new DateTime($tugas['batas_waktu']) : null;
            $lewat_batas = $batas_waktu_obj && $batas_waktu_obj < new DateTime();
            $batas_waktu_text = '';
            $warna_batas = '';
            
            if ($batas_waktu_obj) {
                $batas_waktu_text = ' &middot; Batas: ' . $batas_waktu_obj->format('d M Y H:i');
                $warna_batas = $lewat_batas ? 'text-danger fw-bold' : 'text-danger';
            }


            echo '<div class="list-group-item">';
            echo '  <div class="row align-items-center g-2">'; // g-2 for gap
             // Kolom Info Tugas
            echo '    <div class="col-md-7 col-lg-8">'; 
            echo '      <a href="?page=detail_tugas&assignment_id=' . $tugas['id'] . '" class="text-decoration-none text-body">';
            echo '          <strong class="d-block mb-1">' . h($tugas['judul_tugas']) . '</strong>'; 
            echo '          <small class="text-muted d-block d-sm-inline mb-1 mb-sm-0">Kelas: ' . h($tugas['nama_kelas']) . '</small>';
            echo '          <small class="text-muted d-block d-sm-inline mb-1 mb-sm-0"> &middot; Kuis: ' . h(mb_strimwidth($tugas['quiz_title'], 0, 30, "...")) . '</small>'; 
            $mode_map = ['instant' => 'Instan', 'end' => 'Akhir', 'exam' => 'Ujian', 'bebas' => 'Bebas'];
            echo '          <small class="text-muted d-block d-sm-inline mb-1 mb-sm-0"> &middot; Mode: ' . ($mode_map[$tugas['mode']] ?? 'Bebas') . '</small>';
            
            // Tampilkan batas waktu dengan warna
            echo ' <small class="' . $warna_batas . ' d-block d-sm-inline">' . $batas_waktu_text . ($lewat_batas ? ' (Lewat)' : '') . '</small>';
            echo '      </a>';
            // Progress Bar
            echo '      <div class="mt-2">';
            echo '          <div class="progress" style="height: 15px;">';
            // Gunakan warna hijau (bg-success) jika 100%, abu-abu (bg-secondary) jika 0%, biru (bg-primary) untuk lainnya
            $progress_class = ($progress_percent === 100) ? 'bg-success' : (($progress_percent === 0) ? 'bg-secondary' : 'bg-primary');
            echo '              <div class="progress-bar ' . $progress_class . '" role="progressbar" style="width: ' . $progress_percent . '%;" aria-valuenow="' . $progress_percent . '" aria-valuemin="0" aria-valuemax="100">';
            echo '              </div>';
            echo '          </div>';
            echo '          <small class="text-muted">' . $submissions_count . ' dari ' . $total_members . ' siswa (' . $progress_percent . '%) sudah mengerjakan.</small>';
            echo '      </div>';
            echo '    </div>';

             // Kolom Aksi
            echo '    <div class="col-md-5 col-lg-4 text-md-end">'; 
            echo '      <div class="btn-group btn-group-sm">'; 
            // Tombol lihat progres
            echo '        <a href="?page=detail_tugas&assignment_id=' . $tugas['id'] . '" class="btn btn-outline-info">Progres</a>';
            // Tombol Edit
            echo '        <a href="?page=edit_tugas&assignment_id=' . $tugas['id'] . '" class="btn btn-outline-primary">Edit</a>';
            // GANTI FORM LAMA DENGAN TOMBOL INI:
            echo '        <button type="button" class="btn btn-success btn-kirim-rekap" 
                  data-assignment-id="' . $tugas['id'] . '" 
                  data-inst-id="' . $inst_id . '"
                  id="rekap-btn-' . $tugas['id'] . '"
                  title="Kirim Rekap WA">Rekap</button>';
             // Form Hapus
            echo '        <form method="POST" action="?action=delete_tugas" onsubmit="return confirm(\'Anda yakin ingin menghapus tugas ini? Progres siswa yang terkait juga akan hilang.\');" class="d-inline">';
            echo '          <input type="hidden" name="assignment_id" value="' . $tugas['id'] . '">';
            echo '          <input type="hidden" name="inst_id" value="' . $inst_id . '">'; 
            echo '          <button type="submit" class="btn btn-outline-danger">Hapus</button>';
            echo '        </form>';
            echo '      </div>';
            echo '    </div>';

            echo '  </div>'; // .row
            echo '</div>'; // .list-group-item
        }
        echo '</div>'; // .list-group
    }
    
    // ▼▼▼ TAMBAHKAN KONTROL PAGINASI DI SINI ▼▼▼
    if ($total_pages > 1) {
        // --- HTML untuk Paginasi Bergaya Admin View (Side-to-Side) ---
        echo '<div class="d-flex align-items-center justify-content-between mt-2" id="kelola-tugas-pager">';
        // Tombol Previous
        $prev_disabled = ($current_page <= 1) ? ' disabled' : '';
        echo '  <a href="?page=kelola_tugas&inst_id=' . $inst_id . '&p=' . ($current_page - 1) . '" class="btn btn-sm btn-outline-secondary' . $prev_disabled . '" data-page="prev">◀︎</a>';
        
        // Teks Halaman
        echo '  <div class="small text-muted">Halaman <span data-role="page">' . $current_page . '</span>/<span data-role="pages">' . $total_pages . '</span></div>';
        
        // Tombol Next
        $next_disabled = ($current_page >= $total_pages) ? ' disabled' : '';
        echo '  <a href="?page=kelola_tugas&inst_id=' . $inst_id . '&p=' . ($current_page + 1) . '" class="btn btn-sm btn-outline-secondary' . $next_disabled . '" data-page="next">▶︎</a>';
        
        echo '</div>';
    }
    // ▲▲▲ AKHIR KONTROL PAGINASI ▲▲▲

    echo '<hr class="my-4">';

    // --------------------------------------------------------
    // BAGIAN MODIFIKASI: FORM "BERI TUGAS BARU"
    // --------------------------------------------------------
    
    // 1. QUERY BARU: Ambil semua judul soal dengan format Tema › Subtema › Judul
    $all_quizzes_full_label = q("
        SELECT 
            qt.id, 
            CONCAT(t.name, ' › ', st.name, ' › ', qt.title) AS full_title
        FROM quiz_titles qt
        JOIN subthemes st ON st.id = qt.subtheme_id
        JOIN themes t ON t.id = st.theme_id
        ORDER BY t.name, st.name, qt.title ASC
    ")->fetchAll();

    // Ambil kelas HANYA untuk institusi ini
    $all_classes_in_inst = q("SELECT id, nama_kelas FROM classes WHERE id_institusi = ? AND id_pengajar = ? ORDER BY nama_kelas", [$inst_id, $id_pengajar])->fetchAll();

    echo '<div class="card">';
    echo '  <div class="card-header"><h5 class="mb-0">Beri Tugas Baru</h5></div>';
    echo '  <div class="card-body">';
    if (empty($all_classes_in_inst)) {
        echo '<div class="alert alert-warning">Anda belum membuat kelas di institusi ini. Silakan <a href="?page=kelola_institusi">buat kelas</a> terlebih dahulu pada institusi ini.</div>';
    } else {
        echo '<form action="?action=beri_tugas" method="POST" id="formBeriTugas">';
        echo '  <input type="hidden" name="id_institusi" value="' . $inst_id . '">';
        echo '  <div class="mb-3"><label class="form-label">Judul Tugas <span class="text-danger">*</span></label><input type="text" name="judul_tugas" class="form-control" required placeholder="Contoh: Latihan Bab 1"></div>';
        
        // ******* DROPDOWN YANG DIMODIFIKASI *******
        echo '  <div class="mb-3"><label class="form-label">Pilih Kuis <span class="text-danger">*</span></label><select name="id_judul_soal" class="form-select" required><option value="" disabled selected>-- Pilih Kuis (Tema › Subtema › Judul) --</option>';
        
        foreach ($all_quizzes_full_label as $quiz) {
            echo '      <option value="' . $quiz['id'] . '">' . h($quiz['full_title']) . '</option>';
        }
        
        echo '  </select></div>';
        // ******* AKHIR MODIFIKASI DROPDOWN *******

        echo '  <div class="mb-3"><label class="form-label">Mode Pengerjaan</label><select name="mode" id="modePengerjaan" class="form-select" required><option value="bebas" selected>Bebas (Siswa memilih mode sendiri)</option><option value="instant">Instan Review</option><option value="end">End Review</option><option value="exam">Ujian</option></select></div>';

        // ▼▼▼ AWAL BLOK INPUT BARU YANG DINAMIS ▼▼▼
        echo '  <div class="row">';
        echo '      <div class="col-md-6 mb-3">';
        echo '          <label for="jumlah_soal" class="form-label">Jumlah Soal</label>';
        echo '          <input type="number" name="jumlah_soal" id="jumlah_soal" class="form-control" placeholder="Default: 10 soal" min="1">';
        echo '          <small class="text-muted">Kosongkan untuk menggunakan default (10 soal).</small>';
        echo '      </div>';
        echo '      <div class="col-md-6 mb-3" id="timerPerSoalContainer" style="display: none;">';
        echo '          <label for="timer_per_soal_detik" class="form-label">Timer per Soal (detik)</label>';
        echo '          <input type="number" name="timer_per_soal_detik" id="timer_per_soal_detik" class="form-control" placeholder="Default: 30 detik" min="5">';
        echo '          <small class="text-muted">Kosongkan untuk timer default.</small>';
        echo '      </div>';
        echo '      <div class="col-md-6 mb-3" id="durasiUjianContainer" style="display: none;">';
        echo '          <label for="durasi_ujian_menit" class="form-label">Total Durasi Ujian (menit)</label>';
        echo '          <input type="number" name="durasi_ujian_menit" id="durasi_ujian_menit" class="form-control" placeholder="Default: 60 menit" min="1">';
        echo '          <small class="text-muted">Kosongkan untuk durasi default.</small>';
        echo '      </div>';
        echo '  </div>';
        // ▲▲▲ AKHIR BLOK INPUT BARU ▲▲▲

        echo '  <div class="mb-3"><label class="form-label">Batas Waktu (Opsional)</label><input type="datetime-local" name="batas_waktu" class="form-control"></div>';
        echo '  <hr>';
        echo '  <div class="mb-3">';
        echo '      <label class="form-label fw-bold">Berikan Tugas ini ke Kelas (Pilih minimal satu) <span class="text-danger">*</span></label>';
        echo '      <div class="border rounded p-2" style="max-height: 200px; overflow-y: auto;">';
        if (empty($all_classes_in_inst)) {
            echo '<p class="text-muted small m-2">Tidak ada kelas tersedia.</p>';
        } else {
            foreach ($all_classes_in_inst as $kelas) {
                echo '<div class="form-check p-2"><input class="form-check-input" type="checkbox" name="id_kelas[]" value="' . $kelas['id'] . '" id="kelas-' . $kelas['id'] . '"><label class="form-check-label ms-2" for="kelas-' . $kelas['id'] . '">' . h($kelas['nama_kelas']) . '</label></div>';
            }
        }
        echo '      </div>';
        echo '      <div id="kelasCheckboxError" class="text-danger small mt-1" style="display: none;">Pilih setidaknya satu kelas.</div>';
        echo '  </div>';
        echo '  <button type="submit" class="btn btn-primary">Kirim Tugas ke Kelas Terpilih</button>';
        echo '</form>';

        // ▼▼▼ SCRIPT BARU UNTUK MENGATUR TAMPILAN INPUT (Tidak berubah) ▼▼▼
        echo '<script>
            document.addEventListener("DOMContentLoaded", function() {
                const modeSelect = document.getElementById("modePengerjaan");
                const timerPerSoal = document.getElementById("timerPerSoalContainer");
                const durasiUjian = document.getElementById("durasiUjianContainer");

                function toggleTimerInputs() {
                    const selectedMode = modeSelect.value;
                    if (selectedMode === "instant" || selectedMode === "end") {
                        timerPerSoal.style.display = "block";
                        durasiUjian.style.display = "none";
                    } else if (selectedMode === "exam") {
                        timerPerSoal.style.display = "none";
                        durasiUjian.style.display = "block";
                    } else { // mode "bebas" atau lainnya
                        timerPerSoal.style.display = "none";
                        durasiUjian.style.display = "none";
                    }
                }
                
                modeSelect.addEventListener("change", toggleTimerInputs);
                toggleTimerInputs(); // Jalankan saat halaman dimuat
            });
        </script>';

        // Script validasi checkbox (Tidak berubah)
        echo '<script>
            const formBeriTugas = document.querySelector(\'#formBeriTugas\');
            if (formBeriTugas) {
                formBeriTugas.addEventListener(\'submit\', function(event) {
                    const checkboxes = formBeriTugas.querySelectorAll(\'input[name="id_kelas[]"]:checked\');
                    const errorDiv = document.getElementById(\'kelasCheckboxError\');
                    if (checkboxes.length === 0) {
                        event.preventDefault();
                        if (errorDiv) errorDiv.style.display = \'block\';
                    } else {
                        if (errorDiv) errorDiv.style.display = \'none\';
                    }
                });
            }
        </script>';
        
        echo '<script>
            document.addEventListener("DOMContentLoaded", function() {
                // Skrip ini hanya memastikan bahwa tombol Previous/Next benar-benar menunjuk ke URL yang sesuai
                // dan menonaktifkannya jika sudah mencapai batas (meskipun PHP sudah melakukannya).
                const pager = document.getElementById("kelola-tugas-pager");
                if (pager) {
                    pager.querySelectorAll("a[data-page]").forEach(link => {
                        // Jika PHP sudah menambahkan disabled, hapus atribut href agar tidak bisa diklik
                        if (link.classList.contains("disabled")) {
                            link.removeAttribute("href"); 
                        }
                    });
                }
            });
        </script>';
        
        
        
    }
    
    echo '<script>
    document.addEventListener("DOMContentLoaded", function() {
        document.querySelectorAll(".btn-kirim-rekap").forEach(button => {
            button.addEventListener("click", async function() {
                const btn = this;
                const assignmentId = btn.getAttribute("data-assignment-id");
                const instId = btn.getAttribute("data-inst-id");
                
                if (!confirm("Kirim rekap nilai 100 ke grup WA sekarang?")) {
                    return;
                }
                
                // 1. Simpan status tombol lama dan cari kontainer alert (di luar form)
                const originalText = btn.textContent;
                const originalClass = btn.className;
                const alertContainer = document.getElementById("kelola-tugas-pager") 
                                     ? document.getElementById("kelola-tugas-pager").parentNode 
                                     : document.querySelector("h3").parentNode;

                // Hapus alert lama
                document.querySelectorAll(".alert-dismissible").forEach(a => a.remove());
                
                // 2. Ubah tombol menjadi loading
                btn.disabled = true;
                btn.className = "btn btn-warning";
                btn.innerHTML = \'<span class="spinner-border spinner-border-sm me-1"></span> Mengirim\';
                
                // 3. Buat FormData dan kirim AJAX
                const formData = new FormData();
                formData.append("assignment_id", assignmentId);
                formData.append("inst_id", instId);

                let alertHtml = "";
                let finalType = "danger";
                
                try {
                    const response = await fetch("?action=kirim_rekap", {
                        method: "POST",
                        body: formData
                    });
                    
                    const result = await response.json();
                    
                    if (response.ok && result.ok) {
                        finalType = result.type || "success";
                        alertHtml = result.message;
                    } else if (result.error) {
                        // Gunakan tipe yang dikirim dari PHP (info/warning/danger)
                        finalType = result.type || "danger";
                        alertHtml = "Gagal: " + result.error;
                    } else {
                        alertHtml = "Gagal memproses rekap. Respon tidak valid.";
                    }
                } catch (error) {
                    alertHtml = "Error jaringan saat mengirim rekap. Coba lagi.";
                } finally {
                    // 4. Buat dan Tampilkan Alert
                    const newAlert = document.createElement("div");
                    newAlert.className = "alert alert-" + finalType + " alert-dismissible fade show";
                    newAlert.innerHTML = alertHtml + \'<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>\';
                    
                    // Masukkan alert di awal container (di atas h3)
                    document.querySelector("h3").insertAdjacentElement(\'beforebegin\', newAlert);

                    // 5. Kembalikan tombol ke status semula
                    btn.disabled = false;
                    btn.className = originalClass;
                    btn.textContent = originalText;
                }
            });
        });
    });
    </script>';
    
    
    echo '  </div>'; // card-body
    echo '</div>'; // card
}

// >>> AKHIR KODE MODIFIKASI view_kelola_tugas <<<

// ===============================================
// AWAL VIEW KELOLA USER
// ===============================================

/**
 * Menampilkan halaman untuk mengelola semua user (Admin-only) dengan tampilan mobile-friendly.
 * [VERSI FINAL - Perbaikan untuk echo di dalam echo]
 */
function view_kelola_user()
{
  // Keamanan: Guard untuk memastikan hanya admin yang bisa melihat
  if (!is_admin()) {
    echo '<div class="alert alert-danger">Akses ditolak. Hanya admin yang dapat mengakses halaman ini.</div>';
    return;
  }

  // Tampilkan notifikasi jika ada
  if (isset($_GET['ok'])) {
    echo '<div class="alert alert-success">Peran pengguna berhasil diperbarui.</div>';
  }
  if (isset($_GET['err']) && $_GET['err'] === 'self_demote') {
    echo '<div class="alert alert-danger">Anda tidak dapat menurunkan peran Anda sendiri.</div>';
  }

  // Ambil data user dari database
  $users = q("SELECT id, name, email, role, user_type, created_at, updated_at FROM users ORDER BY id DESC")->fetchAll();
  $userCount = count($users);

  // Siapkan array untuk dropdown
  $available_roles = ['admin', 'pengajar', 'pelajar', 'umum'];
  $available_user_types = ['Pengajar', 'Pelajar', 'Umum'];

  // Judul, Badge, dan Filter Input
  echo '<h3>Kelola Pengguna <span class="badge bg-secondary align-middle" id="kelola-user-count">' . $userCount . '</span></h3>';
  echo '<input id="kelola-user-filter" type="text" class="form-control mb-2" placeholder="Cari berdasarkan nama atau email...">';

  if (!$users) {
    echo '<div class="alert alert-info">Belum ada pengguna yang terdaftar.</div>';
    return;
  }

  // ----- AWAL TABEL -----
  echo '<div class="table-responsive">';
  echo '<table class="table table-striped table-hover align-middle" id="kelola-user-table">';
  echo '  <thead>';
  echo '      <tr>';
  echo '          <th class="d-none d-md-table-cell">Nama</th>';
  echo '          <th>Email</th>';
  echo '          <th class="d-none d-md-table-cell" style="width: 350px;">Aksi</th>';
  echo '          <th class="d-none d-md-table-cell">Waktu Dibuat</th>';
  echo '          <th class="d-none d-md-table-cell">Terakhir Update</th>';
  echo '          <th class="d-md-none text-end">Aksi</th>';
  echo '      </tr>';
  echo '  </thead>';
  echo '  <tbody>';

  // Perulangan untuk setiap baris data pengguna
  foreach ($users as $user) {
    $search_text = strtolower($user['name'] . ' ' . $user['email']);

    echo '<tr data-search="' . h($search_text) . '" 
                  data-userid="' . $user['id'] . '" 
                  data-name="' . h($user['name']) . '" 
                  data-email="' . h($user['email']) . '" 
                  data-role="' . h($user['role']) . '" 
                  data-usertype="' . h($user['user_type']) . '" 
                  data-created="' . h($user['created_at']) . '" 
                  data-updated="' . h($user['updated_at'] ?: '-') . '">';

    // Kolom-kolom data untuk desktop
    echo '  <td class="d-none d-md-table-cell">' . h($user['name']) . '</td>';
    echo '  <td>' . h($user['email']) . '</td>';
    echo '  <td class="d-none d-md-table-cell">';
    echo '      <form method="POST" action="?page=kelola_user" class="d-flex align-items-center gap-2">';
    echo '          <input type="hidden" name="user_id" value="' . $user['id'] . '">';
    echo '          <select name="role" class="form-select form-select-sm" title="Ubah Role">';
    foreach ($available_roles as $role) {
      $selected = ($user['role'] === $role) ? 'selected' : '';
      echo '              <option value="' . $role . '" ' . $selected . '>' . ucfirst($role) . '</option>';
    }
    echo '          </select>';
    echo '          <select name="user_type" class="form-select form-select-sm" title="Ubah Tipe User">';
    foreach ($available_user_types as $type) {
      $selected = ($user['user_type'] === $type) ? 'selected' : '';
      echo '              <option value="' . $type . '" ' . $selected . '>' . $type . '</option>';
    }
    echo '          </select>';
    echo '          <button type="submit" class="btn btn-primary btn-sm">Simpan</button>';
    echo '      </form>';
    echo '  </td>';
    echo '  <td class="d-none d-md-table-cell">' . h($user['created_at']) . '</td>';
    echo '  <td class="d-none d-md-table-cell">' . ($user['updated_at'] ? h($user['updated_at']) : '<em>-</em>') . '</td>';

    // Tombol "Kelola" hanya untuk mobile
    echo '  <td class="d-md-none text-end">';
    echo '      <button class="btn btn-primary btn-sm kelola-user-btn">Kelola</button>';
    echo '  </td>';
    echo '</tr>';
  }

  echo '  </tbody>';
  echo '</table>';
  echo '</div>'; // Penutup .table-responsive

  // Paginasi
  echo '<div class="d-flex align-items-center justify-content-between mt-2" id="kelola-user-pager">';
  echo '  <button class="btn btn-sm btn-outline-secondary" data-page="prev">◀︎</button>';
  echo '  <div class="small text-muted">Halaman <span data-role="page">1</span>/<span data-role="pages">1</span></div>';
  echo '  <button class="btn btn-sm btn-outline-secondary" data-page="next">▶︎</button>';
  echo '</div>';

  // ----- HTML MODAL UNTUK TAMPILAN MOBILE -----
  echo '
    <div class="modal fade" id="userManageModal" tabindex="-1" aria-labelledby="userManageModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="userManageModalLabel">Kelola Pengguna</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <dl class="row">
              <dt class="col-sm-4">Nama</dt>
              <dd class="col-sm-8" id="modalUserName"></dd>
              <dt class="col-sm-4">Email</dt>
              <dd class="col-sm-8" id="modalUserEmail"></dd>
              <dt class="col-sm-4">Waktu Dibuat</dt>
              <dd class="col-sm-8" id="modalUserCreated"></dd>
              <dt class="col-sm-4">Update Terakhir</dt>
              <dd class="col-sm-8" id="modalUserUpdated"></dd>
            </dl>
            <hr>
            <form id="modalUserRoleForm" method="POST" action="?page=kelola_user">
                <input type="hidden" name="user_id" id="modalUserId">
                <div class="mb-3">
                    <label for="modalUserRole" class="form-label"><strong>Ubah Peran (Rule)</strong></label>
                    <select name="role" id="modalUserRole" class="form-select">';
  foreach ($available_roles as $role) {
    echo '          <option value="' . $role . '">' . ucfirst($role) . '</option>';
  }
  echo '          </select>
                </div>
                <div class="mb-3">
                    <label for="modalUserType" class="form-label"><strong>Ubah Tipe Pengguna</strong></label>
                    <select name="user_type" id="modalUserType" class="form-select">';
  foreach ($available_user_types as $type) {
    echo '          <option value="' . $type . '">' . $type . '</option>';
  }
  echo '          </select>
                </div>
            </form>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            <button type="submit" form="modalUserRoleForm" class="btn btn-primary">Simpan Perubahan</button>
          </div>
        </div>
      </div>
    </div>';

  // JavaScript untuk mengaktifkan semuanya
  echo "<script>
        document.addEventListener('DOMContentLoaded', function() {
            setupTable({
                inputId: 'kelola-user-filter',
                tableId: 'kelola-user-table',
                pagerId: 'kelola-user-pager',
                countBadgeId: 'kelola-user-count',
                pageSize: 15
            });

            const userModal = new bootstrap.Modal(document.getElementById('userManageModal'));
            const tableBody = document.getElementById('kelola-user-table').querySelector('tbody');

            tableBody.addEventListener('click', function(event) {
                if (event.target.classList.contains('kelola-user-btn')) {
                    const row = event.target.closest('tr');
                    const userData = row.dataset;
                    document.getElementById('modalUserName').textContent = userData.name;
                    document.getElementById('modalUserEmail').textContent = userData.email;
                    document.getElementById('modalUserCreated').textContent = userData.created;
                    document.getElementById('modalUserUpdated').textContent = userData.updated;
                    document.getElementById('modalUserId').value = userData.userid;
                    document.getElementById('modalUserRole').value = userData.role;
                    document.getElementById('modalUserType').value = userData.usertype;
                    userModal.show();
                }
            });
        });
    </script>";
}
// ===============================================
// AKHIR VIEW KELOLA USER
// ===============================================
// // +++ DENGAN FUNGSI BARU INI +++
/**
 * Menampilkan halaman detail kelas untuk mengelola anggota dan tugas.
 */
function view_detail_kelas()
{
  // Keamanan: Pastikan hanya pengajar yang bisa mengakses
  if (($_SESSION['user']['role'] ?? '') !== 'pengajar') {
    echo '<div class="alert alert-danger">Hanya pengajar yang dapat mengakses halaman ini.</div>';
    return;
  }

  $id_pengajar = uid();
  $id_kelas = (int)($_GET['id_kelas'] ?? 0);

  // Dapatkan info kelas & verifikasi kepemilikan
  $kelas = q("
        SELECT c.*, i.nama_institusi 
        FROM classes c
        JOIN teacher_institutions i ON c.id_institusi = i.id
        WHERE c.id = ? AND c.id_pengajar = ?
    ", [$id_kelas, $id_pengajar])->fetch();

  if (!$kelas) {
    echo '<div class="alert alert-danger">Kelas tidak ditemukan atau Anda tidak memiliki akses.</div>';
    return;
  }

  // Tampilkan header halaman
  echo '<div class="d-flex justify-content-between align-items-center mb-3">';
  echo '  <div><h3>' . h($kelas['nama_kelas']) . '</h3><p class="text-muted mb-0">Institusi: ' . h($kelas['nama_institusi']) . '</p></div>';
  echo '  <a href="?page=kelola_institusi" class="btn btn-outline-secondary btn-sm">&laquo; Ganti Institusi</a>';
  echo '</div>';

  // Tampilkan notifikasi sukses jika ada
  if (isset($_GET['ok'])) echo '<div class="alert alert-success">Perubahan berhasil disimpan.</div>';

  // Gunakan Tabs untuk memisahkan Anggota dan Tugas
  echo '<ul class="nav nav-tabs" id="kelasTab" role="tablist">';
  echo '  <li class="nav-item" role="presentation"><button class="nav-link active" id="anggota-tab" data-bs-toggle="tab" data-bs-target="#anggota-tab-pane" type="button" role="tab">Anggota</button></li>';
  echo '  <li class="nav-item" role="presentation"><button class="nav-link" id="tugas-tab" data-bs-toggle="tab" data-bs-target="#tugas-tab-pane" type="button" role="tab">Tugas</button></li>';
  echo '</ul>';

  echo '<div class="tab-content" id="kelasTabContent">';

  // ======================================
  // TAB PENGELOLAAN ANGGOTA
  // ======================================
  echo '  <div class="tab-pane fade show active" id="anggota-tab-pane" role="tabpanel">';

  $anggota_ids = q("SELECT id_pelajar FROM class_members WHERE id_kelas = ?", [$id_kelas])->fetchAll(PDO::FETCH_COLUMN);
  $calon_anggota = q("SELECT id, name, avatar FROM users WHERE nama_sekolah = ? AND user_type = 'Pelajar' AND id != ? ORDER BY name ASC", [$kelas['nama_institusi'], $id_pengajar])->fetchAll();

  echo '      <form action="?action=tambah_anggota" method="POST">';
  echo '          <input type="hidden" name="id_kelas" value="' . $id_kelas . '">';
  echo '          <div class="card border-top-0">';
  echo '              <div class="card-header">Pilih Pelajar untuk Dimasukkan ke Kelas</div>';
  if (empty($calon_anggota)) {
    echo '          <div class="card-body text-center text-muted">Tidak ada pengguna "Pelajar" yang terdaftar di institusi ini.</div>';
  } else {
    echo '          <ul class="list-group list-group-flush">';
    foreach ($calon_anggota as $pelajar) {
      $checked = in_array($pelajar['id'], $anggota_ids) ? 'checked' : '';
      echo '              <li class="list-group-item"><div class="form-check">';
      echo '                  <input class="form-check-input" type="checkbox" name="pelajar_ids[]" value="' . $pelajar['id'] . '" id="pelajar-' . $pelajar['id'] . '" ' . $checked . '>';
      echo '                  <label class="form-check-label d-flex align-items-center" for="pelajar-' . $pelajar['id'] . '"><img src="' . h($pelajar['avatar']) . '" class="avatar me-2" style="width:24px; height:24px;">' . h($pelajar['name']) . '</label>';
      echo '              </div></li>';
    }
    echo '          </ul>';
  }
  echo '              <div class="card-footer text-end"><button type="submit" class="btn btn-primary" ' . (empty($calon_anggota) ? 'disabled' : '') . '>Simpan Anggota Kelas</button></div>';
  echo '          </div>';
  echo '      </form>';
  echo '  </div>';

  // ======================================
  // TAB PENGELOLAAN TUGAS
  // ======================================
  echo '  <div class="tab-pane fade" id="tugas-tab-pane" role="tabpanel">';

  // Ambil daftar tugas yang sudah ada
$assignments = q("
    SELECT a.id, a.judul_tugas, a.batas_waktu, qt.title as judul_kuis
    FROM assignments a
    JOIN quiz_titles qt ON a.id_judul_soal = qt.id
    WHERE a.id_kelas = ? ORDER BY a.created_at DESC
", [$id_kelas])->fetchAll();

if ($assignments) {
  echo '<h5>Daftar Tugas yang Telah Diberikan</h5>';
  // Tombol rekap nilai untuk pengajar pemilik kelas
  echo '<div class="mb-3">';
  echo '  <a href="?action=rekap_nilai&id_kelas=' . $id_kelas . '" class="btn btn-sm btn-success">Rekap Nilai</a>';
  echo '</div>';
    // Gunakan list-group-item-action agar bisa diklik
    echo '<div class="list-group mb-4">';
    foreach ($assignments as $tugas) {
        // Ubah <div> menjadi <a> yang mengarah ke halaman detail tugas
        echo '<a href="?page=detail_tugas&assignment_id=' . $tugas['id'] . '" class="list-group-item list-group-item-action">';
        echo '  <div class="fw-bold">' . h($tugas['judul_tugas']) . '</div>';
        echo '  <small class="text-muted">Kuis: ' . h($tugas['judul_kuis']) . '</small><br>';
        echo '  <small class="text-muted">Batas Waktu: ' . ($tugas['batas_waktu'] ? date('d M Y, H:i', strtotime($tugas['batas_waktu'])) : 'Tidak ada') . '</small>';
        echo '</a>'; // Penutup tag <a>
    }
    echo '</div>';
}

  // Form untuk memberi tugas baru
$all_quizzes = q("SELECT id, title FROM quiz_titles ORDER BY title ASC")->fetchAll();

echo '      <div class="card border-top-0">';
echo '          <div class="card-header">Beri Tugas Baru</div>';
echo '          <div class="card-body">';
echo '              <form action="?action=beri_tugas" method="POST">';
echo '                  <input type="hidden" name="id_kelas" value="' . $id_kelas . '">';

// Field Judul Tugas (tidak berubah)
echo '                  <div class="mb-3"><label for="judul_tugas" class="form-label">Judul Tugas</label><input type="text" name="judul_tugas" class="form-control" placeholder="Contoh: Ulangan Harian 1" required></div>';

// Field Pilih Kuis (tidak berubah)
echo '                  <div class="mb-3"><label for="id_judul_soal" class="form-label">Pilih Kuis</label><select name="id_judul_soal" class="form-select" required><option value="" disabled selected>-- Pilih Judul Soal --</option>';
foreach ($all_quizzes as $quiz) {
    echo '                      <option value="' . $quiz['id'] . '">' . h($quiz['title']) . '</option>';
}
echo '                  </select></div>';

// ▼▼▼ TAMBAHAN BARU: PILIHAN MODE ▼▼▼
echo '                  <div class="mb-3">';
echo '                      <label for="mode" class="form-label">Mode Pengerjaan</label>';
echo '                      <select name="mode" id="mode" class="form-select" required>';
echo '                          <option value="bebas" selected>Bebas (Siswa memilih mode sendiri)</option>';
echo '                          <option value="instant">Instan Review</option>';
echo '                          <option value="end">End Review</option>';
echo '                          <option value="exam">Ujian</option>';
echo '                      </select>';
echo '                  </div>';
// ▲▲▲ AKHIR TAMBAHAN BARU ▲▲▲

// Field Batas Waktu (tidak berubah)
echo '                  <div class="mb-3"><label for="batas_waktu" class="form-label">Batas Waktu (Opsional)</label><input type="datetime-local" name="batas_waktu" class="form-control"></div>';

echo '                  <button type="submit" class="btn btn-primary">Beri Tugas</button>';
echo '              </form>';
echo '          </div>';
echo '      </div>';
// +++ AKHIR DARI KODE BARU +++
  echo '  </div>';

  echo '</div>'; // Penutup tab-content
}





// ===============================================
// LANDING
// ===============================================
// GANTI SELURUH FUNGSI view_home() YANG LAMA DENGAN KODE DI BAWAH INI
function view_home()
{
  // =================================================================
  // BAGIAN 1: PERSIAPAN DATA
  // =================================================================
  // Data untuk Sidebar Kanan (Peserta, Skor, dll.)
  $recent = q("SELECT r.created_at, r.score, r.user_id, COALESCE(u.name, CONCAT('Tamu – ', COALESCE(r.city,'Anonim'))) AS display_name, u.avatar, qt.title AS quiz_title 
                 FROM results r 
                 LEFT JOIN users u ON u.id = r.user_id 
                 LEFT JOIN quiz_titles qt ON qt.id = r.title_id 
                 WHERE (u.id IS NULL OR u.role != 'admin') 
                 ORDER BY r.created_at DESC LIMIT 5")
    ->fetchAll();
  $is_visitor_logged_in = uid();
  $tops = get_top_scores(5);
  
  // Get allowed teacher IDs berdasarkan role
  $allowed_teacher_ids = get_allowed_teacher_ids_for_content();
  
  // Query latest quizzes dengan filter owner_user_id
  if (empty($allowed_teacher_ids)) {
      $latest = q("SELECT qt.id,qt.title,st.name subn FROM quiz_titles qt 
                   JOIN subthemes st ON st.id=qt.subtheme_id 
                   WHERE qt.owner_user_id IS NULL 
                   ORDER BY qt.created_at DESC LIMIT 5")->fetchAll();
  } else {
      $placeholders = implode(',', array_fill(0, count($allowed_teacher_ids), '?'));
      $latest = q("SELECT qt.id,qt.title,st.name subn FROM quiz_titles qt 
                   JOIN subthemes st ON st.id=qt.subtheme_id 
                   WHERE qt.owner_user_id IS NULL OR qt.owner_user_id IN ($placeholders)
                   ORDER BY qt.created_at DESC LIMIT 5",
                  $allowed_teacher_ids)->fetchAll();
  }
  
  $most_played = get_most_played_titles(5);

  // Data untuk Fitur Pencarian dengan filter owner_user_id
  if (empty($allowed_teacher_ids)) {
      $searchable_items_sql = "
            SELECT 
                t.name as theme_name,
                s.id as subtheme_id, s.name as subtheme_name,
                qt.id as title_id, qt.title as title_name
            FROM themes t
            LEFT JOIN subthemes s ON t.id = s.theme_id
            LEFT JOIN quiz_titles qt ON s.id = qt.subtheme_id
            WHERE t.owner_user_id IS NULL
              AND (s.owner_user_id IS NULL OR s.id IS NULL)
              AND (qt.owner_user_id IS NULL OR qt.id IS NULL)
            ORDER BY t.sort_order, t.name, s.name, qt.title
        ";
      $flat_data = q($searchable_items_sql)->fetchAll();
  } else {
      $placeholders = implode(',', array_fill(0, count($allowed_teacher_ids), '?'));
      $searchable_items_sql = "
            SELECT 
                t.name as theme_name,
                s.id as subtheme_id, s.name as subtheme_name,
                qt.id as title_id, qt.title as title_name
            FROM themes t
            LEFT JOIN subthemes s ON t.id = s.theme_id
            LEFT JOIN quiz_titles qt ON s.id = qt.subtheme_id
            WHERE (t.owner_user_id IS NULL OR t.owner_user_id IN ($placeholders))
              AND (s.owner_user_id IS NULL OR s.id IS NULL OR s.owner_user_id IN ($placeholders))
              AND (qt.owner_user_id IS NULL OR qt.id IS NULL OR qt.owner_user_id IN ($placeholders))
            ORDER BY t.sort_order, t.name, s.name, qt.title
        ";
      $flat_data = q($searchable_items_sql, array_merge($allowed_teacher_ids, $allowed_teacher_ids, $allowed_teacher_ids))->fetchAll();
  }
  
  $searchable_list = [];
  foreach ($flat_data as $item) {
    if ($item['subtheme_id']) {
      $searchable_list[] = [
        'type' => 'subtheme',
        'id' => $item['subtheme_id'],
        'name' => $item['subtheme_name'],
        'context' => $item['theme_name'],
        'url' => '?page=titles&subtheme_id=' . $item['subtheme_id'],
        'searchText' => strtolower($item['theme_name'] . ' ' . $item['subtheme_name'])
      ];
    }
    if ($item['title_id']) {
      $searchable_list[] = [
        'type' => 'title',
        'id' => $item['title_id'],
        'name' => $item['title_name'],
        'context' => $item['theme_name'] . ' › ' . $item['subtheme_name'],
        'url' => '?page=play&title_id=' . $item['title_id'],
        'searchText' => strtolower($item['theme_name'] . ' ' . $item['subtheme_name'] . ' ' . $item['title_name'])
      ];
    }
  }
  $searchable_list = array_values(array_unique($searchable_list, SORT_REGULAR));

  // Data Awal untuk Card Browser dengan filter owner_user_id
  $allowed_teacher_ids = get_allowed_teacher_ids_for_content();
  
  if (empty($allowed_teacher_ids)) {
      // Hanya tampilkan tema global
      $themes = q("SELECT id, name FROM themes WHERE owner_user_id IS NULL ORDER BY sort_order, name")->fetchAll();
  } else {
      // Tampilkan tema global + tema dari pengajar yang diizinkan
      $placeholders = implode(',', array_fill(0, count($allowed_teacher_ids), '?'));
      $themes = q(
          "SELECT id, name FROM themes WHERE owner_user_id IS NULL OR owner_user_id IN ($placeholders) ORDER BY sort_order, name",
          $allowed_teacher_ids
      )->fetchAll();
  }

  // Ambil peran user
  $user_role = $_SESSION['user']['role'] ?? 'umum';
  $current_user_id = uid();

  // =================================================================
  // BAGIAN 2: TAMPILAN HTML
  // =================================================================
  echo '<div class="row g-4">';

  // --- KOLOM KIRI (Konten Utama) ---
  echo '<div class="col-lg-8">';

//   echo '<div class="text-center mb-4">
//         <button id="enable-notifications-btn" class="btn btn-warning" style="display:none;">
//             🔔 Aktifkan Notifikasi Kuis Baru
//         </button>
//       </div>';

  // === WIDGET TUGAS (PELAJAR / PENGAJAR) ===
  if ($current_user_id) {

      // --- KONDISI: PENGAJAR ---
      if ($user_role === 'pengajar') {
          // Query 3 tugas terbaru yang dibuat pengajar ini
          $preview_tugas_pengajar = q("
              SELECT
                  a.id AS assignment_id, a.judul_tugas, a.batas_waktu,
                  c.nama_kelas, c.id as id_kelas, c.id_institusi,
                  ti.nama_institusi
              FROM assignments a
              JOIN classes c ON a.id_kelas = c.id
              JOIN teacher_institutions ti ON c.id_institusi = ti.id
              WHERE
                  a.id_pengajar = ?
              ORDER BY a.created_at DESC
              LIMIT 3
          ", [$current_user_id])->fetchAll();
          
          // Cek jumlah institusi yang dikelola pengajar ini
          $institusi_count = q("SELECT COUNT(id) FROM teacher_institutions WHERE id_pengajar = ?", [$current_user_id])->fetchColumn();

          // Ambil SEMUA total anggota per kelas yang relevan
          $kelas_ids_pengajar = array_column($preview_tugas_pengajar, 'id_kelas');
          $total_members_map_pengajar = [];
          if (!empty($kelas_ids_pengajar)) {
             $in_clause = implode(',', array_fill(0, count($kelas_ids_pengajar), '?'));
             $member_counts = q("SELECT id_kelas, COUNT(id_pelajar) as count FROM class_members WHERE id_kelas IN ($in_clause) GROUP BY id_kelas", $kelas_ids_pengajar)->fetchAll();
             foreach($member_counts as $mc) {
                 $total_members_map_pengajar[$mc['id_kelas']] = (int)$mc['count'];
             }
          }


          echo '<h5 class="widget-title">🧑‍🏫 Tugas Terbaru (Pengajar)</h5>';

          if (empty($preview_tugas_pengajar)) {
              echo '<div class="alert alert-secondary small">Anda belum membuat tugas apa pun.</div>';
          } else {
              echo '<div class="list-group sidebar-widget">';
              foreach ($preview_tugas_pengajar as $tugas) {
                  $batas_waktu_obj = $tugas['batas_waktu'] ? new DateTime($tugas['batas_waktu']) : null;
                  $lewat_batas = $batas_waktu_obj && $batas_waktu_obj < new DateTime();
                  
                  // Hitung Progres
                  $total_members = $total_members_map_pengajar[$tugas['id_kelas']] ?? 0;
                  $submissions_count = (int)q("SELECT COUNT(DISTINCT user_id) FROM assignment_submissions WHERE assignment_id = ?", [$tugas['assignment_id']])->fetchColumn();
                  
                  $progress_percent = $total_members > 0 ? round(($submissions_count / $total_members) * 100) : 0;
                  $progress_percent = min(100, max(0, $progress_percent));

                  $status_html = '';
                  if ($lewat_batas) {
                       $status_html = '<span class="badge bg-danger">Terlewat</span>';
                  } elseif ($batas_waktu_obj) {
                      $status_html = '<span class="badge bg-success">Batas: ' . $batas_waktu_obj->format('d M, H:i') . '</span>';
                  }

                  // Progress Bar (untuk pengajar, tampilkan progres kelas)
                  $progress_class = ($progress_percent === 100) ? 'bg-success' : (($progress_percent === 0) ? 'bg-secondary' : 'bg-primary');
                  $progress_bar_html = '
                       <div class="progress mt-1" style="height: 15px;">
                            <div class="progress-bar ' . $progress_class . '" role="progressbar" style="width: ' . $progress_percent . '%; font-size: 0.75rem;" aria-valuenow="' . $progress_percent . '" aria-valuemin="0" aria-valuemax="100">' . $progress_percent . '%</div>
                       </div>
                       <small class="text-muted d-block small mt-1">' . $submissions_count . ' dari ' . $total_members . ' siswa sudah mengerjakan.</small>';


                  echo '<a href="?page=detail_tugas&assignment_id=' . $tugas['assignment_id'] . '" class="list-group-item list-group-item-action p-2">';
                  echo '  <div class="d-flex w-100 justify-content-between align-items-start">';
                  echo '      <div class="me-2">';
                  echo '          <div class="fw-semibold" style="line-height: 1.3;">' . h($tugas['judul_tugas']) . '</div>';
                  echo '          <small class="text-muted d-block">' . h($tugas['nama_institusi']) . ' › ' . h($tugas['nama_kelas']) . '</small>';
                  echo '      </div>';
                  echo '      <div class="text-end flex-shrink-0" style="min-width: 80px;">' . $status_html . '</div>';
                  echo '  </div>';
                  echo $progress_bar_html;
                  echo '</a>';
              }
              echo '</div>'; // penutup list-group
              
              // --- LINK LIHAT SEMUA TUGAS PENGAJAR ---
              echo '<div class="text-end mt-3">';
              // Link "Lihat Semua Tugas" menuju halaman Kelola Institusi
              echo '<a href="?page=kelola_institusi" class="btn btn-sm btn-primary">Lihat Semua Tugas &raquo;</a>';
              echo '</div>';
              
              // --- TAMPILKAN INSTITUSI LAIN (Jika Lebih dari Satu) ---
              if ($institusi_count > 1) {
                  $institutions = q("SELECT id, nama_institusi FROM teacher_institutions WHERE id_pengajar = ?", [$current_user_id])->fetchAll();
                  echo '<div class="mt-4 pt-3 border-top">';
                  echo '<small class="fw-bold d-block mb-1">Kelola di Institusi Lain:</small>';
                  foreach ($institutions as $inst) {
                      // Link ke kelola tugas di institusi tersebut
                      echo '<a href="?page=kelola_tugas&inst_id=' . $inst['id'] . '" class="badge bg-secondary text-decoration-none me-1 mb-1">' . h($inst['nama_institusi']) . '</a>';
                  }
                  echo '</div>';
              }
          }
          echo '<hr class="my-4">';
      }
      
      // --- KONDISI: PELAJAR ---
      elseif ($user_role === 'pelajar') {
          // Query 3 tugas teratas (tanpa paginasi penuh, hanya preview)
          // Menggunakan LEFT JOIN dengan SUBQUERY untuk mendapatkan SKOR TERTINGGI (max_score)
          $preview_tugas = q("
              SELECT
                  a.id AS assignment_id, a.judul_tugas, a.batas_waktu,
                  c.nama_kelas,
                  qt.title AS quiz_title,
                  asub_max.score AS max_score, 
                  asub_max.submitted_at AS last_submitted_at
              FROM assignments a
              JOIN class_members cm ON a.id_kelas = cm.id_kelas
              JOIN classes c ON a.id_kelas = c.id
              JOIN quiz_titles qt ON a.id_judul_soal = qt.id
              LEFT JOIN (
                  SELECT 
                      asub.assignment_id, 
                      asub.user_id, 
                      MAX(r.score) AS score, 
                      MAX(asub.submitted_at) AS submitted_at
                  FROM assignment_submissions asub
                  JOIN results r ON asub.result_id = r.id
                  WHERE asub.user_id = ?
                  GROUP BY asub.assignment_id, asub.user_id 
              ) asub_max ON a.id = asub_max.assignment_id AND asub_max.user_id = cm.id_pelajar
              WHERE
                  cm.id_pelajar = ?
              ORDER BY a.created_at DESC
              LIMIT 3
          ", [$current_user_id, $current_user_id])->fetchAll();

          echo '<h5 class="widget-title">🔔 Tugas Terbaru (Pelajar)</h5>';

          if (empty($preview_tugas)) {
              echo '<div class="alert alert-secondary small">Tidak ada tugas aktif untuk Anda saat ini.</div>';
          } else {
              echo '<div class="list-group sidebar-widget">';
              foreach ($preview_tugas as $tugas) {
                  $sudah_dikerjakan = $tugas['max_score'] !== null;
                  $batas_waktu_obj = $tugas['batas_waktu'] ? new DateTime($tugas['batas_waktu']) : null;
                  $lewat_batas = $batas_waktu_obj && $batas_waktu_obj < new DateTime();

                  $max_score = $sudah_dikerjakan ? (int)$tugas['max_score'] : 0; 
                  
                  $link_tugas = '#';
                  $status_html = '';
                  $is_disabled = true;

                  // Tentukan Status dan Link
                  if ($sudah_dikerjakan) {
                      if ($max_score === 100) {
                          $status_html = '<div class="fs-3">💯</div>';
                      } else {
                          // Sudah dikerjakan, bisa coba lagi (Restart)
                          $link_tugas = '?page=play&assignment_id=' . $tugas['assignment_id'] . '&restart=1';
                          $is_disabled = false;
                          $status_html = '<div class="fw-bold fs-4 text-primary me-1">' . $max_score . '</div>';
                      }
                  } elseif ($lewat_batas) {
                      $status_html = '<span class="badge bg-danger">Terlewat</span>';
                  } else {
                      // Belum dikerjakan
                      $link_tugas = '?page=play&assignment_id=' . $tugas['assignment_id'];
                      $is_disabled = false;
                      $status_html = '<span class="badge bg-warning text-dark fs-6">📝 Kerjakan</span>';
                  }
                  
                  // === START MODIFIKASI: Ganti Progress Bar dengan Bar Nilai ===
                  // Progres bar sekarang akan menampilkan BAR NILAI (SCORE BAR)
                  $score_bar_percent = $max_score; 
                  // Tentukan kelas warna berdasarkan skor
                  $score_bar_class = ($max_score === 100) ? 'bg-success' : (($max_score >= 70) ? 'bg-primary' : (($max_score > 0) ? 'bg-warning text-dark' : 'bg-secondary'));

                  // Jika belum dikerjakan, tampilkan bar nol dengan kelas sekunder
                  if (!$sudah_dikerjakan) {
                     $score_bar_percent = 0;
                     $score_bar_class = 'bg-secondary';
                  }
                  
                  $score_bar_html = '
                       <div class="progress mt-1" style="height: 15px;">
                            <div class="progress-bar ' . $score_bar_class . '" role="progressbar" style="width: ' . $score_bar_percent . '%; font-size: 0.75rem;" aria-valuenow="' . $max_score . '" aria-valuemin="0" aria-valuemax="100">' . $max_score . '%</div>
                       </div>
                       <small class="text-muted d-block small mt-1">Skor Tertinggi: ' . ($sudah_dikerjakan ? $max_score : '—') . '</small>';
                  // === END MODIFIKASI ===


                  echo '<a href="' . $link_tugas . '" class="list-group-item list-group-item-action p-2 ' . ($is_disabled ? 'disabled' : '') . '">';
                  echo '  <div class="d-flex w-100 justify-content-between align-items-start">';
                  echo '      <div class="me-2">';
                  echo '          <div class="fw-semibold" style="line-height: 1.3;">' . h($tugas['judul_tugas']) . '</div>';
                  echo '          <small class="text-muted">' . h($tugas['nama_kelas']) . ' &middot; Kuis: ' . h(mb_strimwidth($tugas['quiz_title'], 0, 25, "...")) . '</small>';
                   if ($batas_waktu_obj && !$sudah_dikerjakan) {
                      $warna_batas = ($lewat_batas) ? 'text-danger fw-bold' : '';
                      echo '      <small class="' . $warna_batas . ' d-block">Batas: ' . $batas_waktu_obj->format('d M, H:i') . '</small>';
                  }
                  echo '      </div>';
                  echo '      <div class="text-end flex-shrink-0" style="min-width: 80px;">' . $status_html . '</div>';
                  echo '  </div>';
                  
                  // Tampilkan Bar Nilai
                  echo $score_bar_html;
                  
                  echo '</a>';
              }
              echo '</div>'; // penutup list-group
              
              // --- LINK LIHAT SEMUA TUGAS PELAJAR ---
              echo '<div class="text-end mt-3">';
              echo '<a href="?page=student_tasks" class="btn btn-sm btn-outline-primary">Lihat Semua Tugas &raquo;</a>';
              echo '</div>';
          }
          echo '<hr class="my-4">';
      }
  }

  // === AKHIR WIDGET TUGAS ===


  // a. Kotak Pencarian (Tetap ada)
  echo '
    <div class="mb-4 position-relative home-search-box-home">
        <input type="text" id="homeSearchInput" class="form-control form-control-lg ps-5" placeholder="Cari subtema atau judul soal...">
        <div class="position-absolute top-50 start-0 translate-middle-y ps-3 text-muted">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-search" viewBox="0 0 16 16"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z"/></svg>
        </div>
    </div>';

  // b. Container untuk Hasil Pencarian (awalnya disembunyikan)
  echo '<div id="searchResultsView" style="display: none;">
            <div id="subthemeResultsContainer" style="display: none;">
                <h5 class="text-muted">Subtema</h5>
                <div id="subthemeResults" class="list-group mb-3"></div>
            </div>
            <hr id="searchDivider" style="display: none;">
            <div id="titleResultsContainer" style="display: none;">
                <h5 class="text-muted">Judul Soal</h5>
                <div id="titleResults" class="list-group"></div>
            </div>
            <div id="searchNoResults" class="alert alert-warning" style="display: none;">
                Tidak ada hasil yang cocok dengan pencarian Anda.
            </div>
          </div>';

  // c. Container untuk Card Browser Dinamis (awalnya terlihat)
  echo '<div id="card-browser-container">';
  echo '  <div id="breadcrumb-nav" class="mb-3"></div>';

  echo '  <div id="card-display" class="row row-cols-2 row-cols-sm-2 row-cols-md-3 g-3"></div>';

  echo '  <div id="loading-indicator" class="text-center p-4" style="display: none;">
            <svg class="quizb-loader" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" style="margin: auto;">
                <path class="q-shape" d="M50,5C25.2,5,5,25.2,5,50s20.2,45,45,45s45-20.2,45-45S74.8,5,50,5z M50,86.5C29.9,86.5,13.5,70.1,13.5,50 S29.9,13.5,50,13.5S86.5,29.9,86.5,50S70.1,86.5,50,86.5z M68.5,43.8c-1.3-1.3-3.5-1.3-4.8,0L49,58.5l-6.7-6.7 c-1.3-1.3-3.5-1.3-4.8,0s-1.3,3.5,0,4.8l9,9c0.6,0.6,1.5,1,2.4,1s1.8-0.4,2.4-1l17-17C69.8,47.2,69.8,45.1,68.5,43.8z"/>
                <circle class="dot dot-1" cx="35" cy="50" r="5"/>
                <circle class="dot dot-2" cx="50" cy="50" r="5"/>
                <circle class="dot dot-3" cx="65" cy="50" r="5"/>
            </svg>
        </div>';
  echo '</div>';

  echo '</div>'; // Penutup .col-lg-8

  // --- KOLOM KANAN (Sidebar) ---
  echo '<div class="col-lg-4 sidebar-separator-mobile">';

  // Widget Peserta Terbaru
  echo '<h5 class="widget-title">🏆 Peserta Terbaru</h5>';
  echo '<div class="list-group sidebar-widget">';
  foreach ($recent as $r) {
    $avatar = !empty($r['avatar']) ? $r['avatar'] : 'https://www.gravatar.com/avatar/?d=mp&s=40';
    $profile_url = $is_visitor_logged_in && !empty($r['user_id']) ? '?page=profile&user_id=' . $r['user_id'] : '#';
    echo '<a href="' . $profile_url . '" class="list-group-item list-group-item-action p-2">';
    echo '  <div class="d-flex align-items-center">';
    echo '    <img src="' . h($avatar) . '" class="rounded-circle me-3" width="40" height="40" alt="Avatar">';
    echo '    <div style="line-height: 1.3;">';
    echo '      <div class="fw-semibold mb-1">' . h($r['display_name']) . '</div>';
    echo '      <div class="small text-muted">' . h($r['quiz_title'] ?? '—') . '</div>';
    echo '    </div></div></a>';
  }
  echo '</div>';

  echo '</div>'; // Penutup .col-lg-4

  echo '</div>'; // Penutup .row

  // =================================================================
  // BAGIAN 3: JAVASCRIPT & CSS
  // =================================================================
  // a. Tanam data JSON untuk JavaScript
  echo '<script id="searchData" type="application/json">' . json_encode($searchable_list) . '</script>';
  echo '<script id="initial-data" type="application/json">' . json_encode($themes) . '</script>';

  // b. CSS untuk Card Browser
  echo '<style>
    .clickable-card { cursor: pointer; transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out; }
    .clickable-card:hover { transform: translateY(-5px); box-shadow: 0 8px 20px rgba(0,0,0,0.1); }
    [data-bs-theme="dark"] .clickable-card:hover { box-shadow: 0 8px 20px rgba(0,0,0,0.3); }

    .clickable-card .card-title {
        white-space: normal;
        word-wrap: break-word;
    }
</style>';

  // CSS BARU UNTUK GRADIEN SUDUT KARTU
  echo '<style>
  :root {
    --card-gradient-color: rgba(13, 110, 253, 0.1);
  }
  [data-bs-theme="dark"] {
    --card-gradient-color: rgba(13, 110, 253, 0.15);
  }

  .clickable-card {
    background-image: radial-gradient(circle 250px at bottom right, var(--card-gradient-color), transparent 70%);
    background-repeat: no-repeat;
  }
</style>';

  // c. JavaScript untuk Pencarian dan Card Browser
  echo <<<JS
    <script>
    setTimeout(function() { 
        
        // --- Variabel untuk semua elemen ---
        const searchInput = document.getElementById('homeSearchInput');
        const searchResultsView = document.getElementById('searchResultsView');
        const cardBrowserContainer = document.getElementById('card-browser-container');
        
        // Elemen Pencarian
        const subthemeResults = document.getElementById('subthemeResults');
        const titleResults = document.getElementById('titleResults');
        const searchDivider = document.getElementById('searchDivider');
        const searchNoResults = document.getElementById('searchNoResults');
        const searchData = JSON.parse(document.getElementById('searchData').textContent);

        // Elemen Card Browser
        const cardDisplay = document.getElementById('card-display');
        const loadingIndicator = document.getElementById('loading-indicator');
        const breadcrumbNav = document.getElementById('breadcrumb-nav');
        const initialDataScript = document.getElementById('initial-data');
        let breadcrumbTrail = [];

        // --- FUNGSI UNTUK CARD BROWSER ---
        function showLoading(show) {
            loadingIndicator.style.display = show ? 'block' : 'none';
            cardDisplay.style.display = show ? 'none' : 'flex';
        }

        function createCard(item, type) {
            const col = document.createElement('div');
            col.className = 'col';
            const card = document.createElement('div');
            card.className = 'card h-100 clickable-card';
            card.dataset.id = item.id;
            card.dataset.name = item.name;
            card.dataset.type = type;
            const cardBody = document.createElement('div');
            cardBody.className = 'card-body text-center d-flex align-items-center justify-content-center';
            cardBody.innerHTML = `<h5 class="card-title mb-0">\${item.name}</h5>`;
            card.appendChild(cardBody);
            col.appendChild(card);
            return col;
        }

        function renderBreadcrumb() {
            if (breadcrumbTrail.length === 0) {
                breadcrumbNav.innerHTML = ''; return;
            }
            let html = '<nav aria-label="breadcrumb"><ol class="breadcrumb">';
            html += '<li class="breadcrumb-item"><a href="#" data-level="-1">Tema</a></li>';
            breadcrumbTrail.forEach((crumb, index) => {
                html += (index < breadcrumbTrail.length - 1)
                    ? `<li class="breadcrumb-item"><a href="#" data-level="\${index}">\${crumb.name}</a></li>`
                    : `<li class="breadcrumb-item active" aria-current="page">\${crumb.name}</li>`;
            });
            html += '</ol></nav>';
            breadcrumbNav.innerHTML = html;
        }

        async function renderCards(items, type) {
            showLoading(true);
            cardDisplay.innerHTML = '';
            await new Promise(resolve => setTimeout(resolve, 200));
            if (items.length === 0) {
                cardDisplay.innerHTML = '<div class="col-12"><div class="alert alert-secondary">Belum ada item di kategori ini.</div></div>';
            } else {
                items.forEach(item => cardDisplay.appendChild(createCard(item, type)));
            }
            showLoading(false);
            renderBreadcrumb();
        }

        async function fetchAndDisplay(level, id, name) {
            let url = '', nextType = '';
            if (level === 'theme') {
                url = `?action=api_get_subthemes&theme_id=\${id}`;
                nextType = 'subtheme';
                breadcrumbTrail = [{ name: name, type: 'theme', id: id }];
            } else if (level === 'subtheme') {
                url = `?action=api_get_titles&subtheme_id=\${id}`;
                nextType = 'title';
                breadcrumbTrail.push({ name: name, type: 'subtheme', id: id });
            }
            try {
                showLoading(true);
                const response = await fetch(url);
                if (!response.ok) throw new Error('Gagal mengambil data.');
                const data = await response.json();
                renderCards(data, nextType);
            } catch (error) {
                cardDisplay.innerHTML = '<div class="col-12"><div class="alert alert-danger">Terjadi kesalahan. Coba lagi.</div></div>';
                showLoading(false);
            }
        }

        // --- FUNGSI UNTUK PENCARIAN ---
        function handleSearch(query) {
            if (query === '') {
                cardBrowserContainer.style.display = 'block';
                searchResultsView.style.display = 'none';
                return;
            }
            cardBrowserContainer.style.display = 'none';
            searchResultsView.style.display = 'block';

            subthemeResults.innerHTML = '';
            titleResults.innerHTML = '';
            
            const subthemeMatches = searchData.filter(item => item.type === 'subtheme' && item.searchText.includes(query));
            const titleMatches = searchData.filter(item => item.type === 'title' && item.searchText.includes(query));

            document.getElementById('subthemeResultsContainer').style.display = subthemeMatches.length > 0 ? 'block' : 'none';
            subthemeMatches.forEach(item => {
                const a = document.createElement('a');
                a.href = item.url; a.className = 'list-group-item list-group-item-action';
                a.innerHTML = `\${item.name} <small class="text-muted d-block">\${item.context}</small>`;
                subthemeResults.appendChild(a);
            });

            document.getElementById('titleResultsContainer').style.display = titleMatches.length > 0 ? 'block' : 'none';
            titleMatches.forEach(item => {
                const a = document.createElement('a');
                a.href = item.url; a.className = 'list-group-item list-group-item-action';
                a.innerHTML = `\${item.name} <small class="text-muted d-block">\${item.context}</small>`;
                titleResults.appendChild(a);
            });
            
            searchDivider.style.display = (subthemeMatches.length > 0 && titleMatches.length > 0) ? 'block' : 'none';
            searchNoResults.style.display = (subthemeMatches.length === 0 && titleMatches.length === 0) ? 'block' : 'none';
        }

        // --- EVENT LISTENERS ---
        searchInput.addEventListener('input', function () { handleSearch(this.value.toLowerCase().trim()); });

        cardDisplay.addEventListener('click', function(e) {
            const card = e.target.closest('.clickable-card');
            if (!card) return;
            if (card.dataset.type === 'title') {
                window.location.href = `?page=play&title_id=\${card.dataset.id}`;
            } else {
                fetchAndDisplay(card.dataset.type, card.dataset.id, card.dataset.name);
            }
        });

        breadcrumbNav.addEventListener('click', function(e) {
            e.preventDefault();
            const link = e.target.closest('a[data-level]');
            if (!link) return;
            const level = parseInt(link.dataset.level, 10);
            if (level === -1) {
                breadcrumbTrail = [];
                const initialData = JSON.parse(initialDataScript.textContent);
                renderCards(initialData, 'theme');
            } else {
                const crumb = breadcrumbTrail[level];
                breadcrumbTrail = breadcrumbTrail.slice(0, level);
                fetchAndDisplay(crumb.type, crumb.id, crumb.name);
            }
        });

        // --- INISIALISASI ---
        if(initialDataScript) {
            const initialData = JSON.parse(initialDataScript.textContent);
            renderCards(initialData, 'theme');
        }
    });
    </script>
    JS;
}


// ===============================================
// HELPER: Dapatkan ID pengajar yang boleh dilihat user sesuai role
// ===============================================
/**
 * Mengembalikan array ID pengajar yang konten (tema/subtema/judul) boleh dilihat oleh user saat ini
 * - Admin: HANYA konten global (owner_user_id IS NULL)
 * - Pengajar: konten global + konten miliknya sendiri
 * - Pelajar: konten global + konten dari pengajar di kelas yang sama
 * - Guest/Umum: HANYA konten global (owner_user_id IS NULL)
 */
function get_allowed_teacher_ids_for_content()
{
    $user_id = uid();
    $role = $_SESSION['user']['role'] ?? 'umum';
    
    // Default: hanya konten global (owner_user_id IS NULL)
    $allowed_ids = [];
    
    if ($role === 'admin') {
        // Admin: hanya lihat konten global
        return []; // empty array = hanya filter owner_user_id IS NULL
    } elseif ($role === 'pengajar' || $role === 'teacher') {
        // Pengajar: konten global + konten miliknya
        $allowed_ids = [$user_id];
    } elseif ($role === 'pelajar' || ($role === 'user' && ($_SESSION['user']['user_type'] ?? '') === 'Pelajar')) {
        // Pelajar: konten global + konten dari pengajar di kelas yang sama
        $pengajar_ids = q(
            "SELECT DISTINCT c.id_pengajar FROM classes c
             JOIN class_members cm ON c.id = cm.id_kelas
             WHERE cm.id_pelajar = ?",
            [$user_id]
        )->fetchAll(PDO::FETCH_COLUMN, 0);
        $allowed_ids = $pengajar_ids ?: [];
    } else {
        // Guest/Umum: hanya lihat konten global
        $allowed_ids = [];
    }
    
    return $allowed_ids; // empty array jika hanya ingin global, array dengan ID jika ada pengajar tertentu
}


// ===============================================
// HALAMAN BARU: JELAJAH TEMA (Tampilan Kartu)
// ===============================================
function view_explore()
{
  // Filter berdasarkan role dan class
  $allowed_teacher_ids = get_allowed_teacher_ids_for_content();
  
  if (empty($allowed_teacher_ids)) {
      // Hanya konten global (owner_user_id IS NULL)
      $themes = q("SELECT * FROM themes WHERE owner_user_id IS NULL ORDER BY sort_order, name")->fetchAll();
  } else {
      // Konten global + milik pengajar yang diizinkan
      $placeholders = implode(',', array_fill(0, count($allowed_teacher_ids), '?'));
      $themes = q(
          "SELECT * FROM themes WHERE owner_user_id IS NULL 
           OR owner_user_id IN ($placeholders) 
           ORDER BY sort_order, name",
          $allowed_teacher_ids
      )->fetchAll();
  }
  
  echo '<h3 class="mb-3">Jelajah Tema Kuis</h3><div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 g-3">';
  foreach ($themes as $t) {
    echo '<div class="col"><a class="text-decoration-none" href="?page=subthemes&theme_id=' . $t['id'] . '"><div class="card h-100 quiz-card"><div class="card-body"><h5>' . h($t['name']) . '</h5><p class="small text-muted">' . h($t['description']) . '</p></div></div></a></div>';
  }
  echo '</div>';
}




// ===============================================
// PICKERS
// ===============================================
function view_themes()
{
  // Get allowed teacher IDs based on role and class
  $allowed_teacher_ids = get_allowed_teacher_ids_for_content();
  
  // Build owner_user_id filter
  $owner_filter = "";
  $owner_params = [];
  if (empty($allowed_teacher_ids)) {
      // Hanya global: owner_user_id IS NULL
      $owner_filter = "AND (t.owner_user_id IS NULL AND (s.owner_user_id IS NULL OR s.id IS NULL) AND (qt.owner_user_id IS NULL OR qt.id IS NULL))";
      $owner_params = [];
  } else {
      // Global + pengajar yang diizinkan
      $placeholders = implode(',', array_fill(0, count($allowed_teacher_ids), '?'));
      $owner_filter = "AND ((t.owner_user_id IS NULL OR t.owner_user_id IN ($placeholders)) 
                         AND (s.owner_user_id IS NULL OR s.id IS NULL OR s.owner_user_id IN ($placeholders)) 
                         AND (qt.owner_user_id IS NULL OR qt.id IS NULL OR qt.owner_user_id IN ($placeholders)))";
      $owner_params = array_merge($allowed_teacher_ids, $allowed_teacher_ids, $allowed_teacher_ids);
  }
  
  // =================================================================
  // BAGIAN 1: LOGIKA PENCARIAN (SAMA SEPERTI DI HALAMAN HOME)
  // =================================================================
  $searchable_items_sql = "
        SELECT 
            t.name as theme_name,
            s.id as subtheme_id, s.name as subtheme_name,
            qt.id as title_id, qt.title as title_name
        FROM themes t
        LEFT JOIN subthemes s ON t.id = s.theme_id
        LEFT JOIN quiz_titles qt ON s.id = qt.subtheme_id
        WHERE 1=1 $owner_filter
        ORDER BY t.sort_order, t.name, s.name, qt.title
    ";
  
  // Fetch dan process hasil
  $flat_data = q($searchable_items_sql, $owner_params)->fetchAll();
  
  $searchable_list = [];
  foreach ($flat_data as $item) {
    if ($item['subtheme_id']) {
      $searchable_list[] = [
        'type' => 'subtheme',
        'id' => $item['subtheme_id'],
        'name' => $item['subtheme_name'],
        'context' => $item['theme_name'],
        'url' => '?page=titles&subtheme_id=' . $item['subtheme_id'],
        'searchText' => strtolower($item['theme_name'] . ' ' . $item['subtheme_name'])
      ];
    }
    if ($item['title_id']) {
      $searchable_list[] = [
        'type' => 'title',
        'id' => $item['title_id'],
        'name' => $item['title_name'],
        'context' => $item['theme_name'] . ' › ' . $item['subtheme_name'],
        'url' => '?page=play&title_id=' . $item['title_id'],
        'searchText' => strtolower($item['theme_name'] . ' ' . $item['subtheme_name'] . ' ' . $item['title_name'])
      ];
    }
  }
  // Hapus duplikat subtema
  $searchable_list = array_values(array_unique($searchable_list, SORT_REGULAR));

  // =================================================================
  // BAGIAN 2: TAMPILAN HTML
  // =================================================================
  // echo '<h3>Pencarian Kuis</h3>';

  // Kotak Pencarian
  echo '
    <div class="mb-4 position-relative">
        <input type="text" id="pageSearchInput" class="form-control form-control-lg ps-5" placeholder="Cari subtema atau judul soal...">
        <div class="position-absolute top-50 start-0 translate-middle-y ps-3 text-muted">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-search" viewBox="0 0 16 16"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z"/></svg>
        </div>
    </div>';

  // Container untuk hasil pencarian (awalnya disembunyikan)
  echo '<div id="pageSearchResultsView" style="display: none;">
            <div id="pageSubthemeResultsContainer" style="display: none;">
                <h5 class="text-muted">Subtema</h5>
                <div id="pageSubthemeResults" class="list-group mb-3"></div>
            </div>
            <hr id="pageSearchDivider" style="display: none;">
            <div id="pageTitleResultsContainer" style="display: none;">
                <h5 class="text-muted">Judul Soal</h5>
                <div id="pageTitleResults" class="list-group"></div>
            </div>
            <div id="pageSearchNoResults" class="alert alert-warning" style="display: none;">
                Tidak ada hasil yang cocok dengan pencarian Anda.
            </div>
          </div>';

  // Container untuk daftar default (tabel semua soal)
  echo '<div id="defaultListView">';

  // Query untuk mengambil semua judul soal diurutkan berdasarkan popularitas
  $all_titles_sql = "
        SELECT
            qt.id,
            qt.title,
            st.name AS subtheme_name,
            t.name AS theme_name,
            COUNT(r.id) AS play_count
        FROM quiz_titles qt
        JOIN subthemes st ON qt.subtheme_id = st.id
        JOIN themes t ON st.theme_id = t.id
        LEFT JOIN results r ON qt.id = r.title_id
        /* KRITIS: Filter Judul, Subtema, dan Tema sesuai owner_user_id */
        WHERE (t.owner_user_id IS NULL OR " . (count($owner_params) > 0 && !empty($allowed_teacher_ids) ? "t.owner_user_id IN (" . implode(',', array_fill(0, count($allowed_teacher_ids), '?')) . ")" : "FALSE") . ")
          AND (st.owner_user_id IS NULL OR " . (count($owner_params) > 0 && !empty($allowed_teacher_ids) ? "st.owner_user_id IN (" . implode(',', array_fill(0, count($allowed_teacher_ids), '?')) . ")" : "FALSE") . ")
          AND (qt.owner_user_id IS NULL OR " . (count($owner_params) > 0 && !empty($allowed_teacher_ids) ? "qt.owner_user_id IN (" . implode(',', array_fill(0, count($allowed_teacher_ids), '?')) . ")" : "FALSE") . ")
        GROUP BY qt.id, qt.title, st.name, t.name
        ORDER BY play_count DESC, t.name ASC, st.name ASC, qt.title ASC
    ";
    $all_titles = q($all_titles_sql, $owner_params)->fetchAll();

  // ... di dalam fungsi view_themes() ...
  if (!$all_titles) {
    echo '<div class="alert alert-info">Belum ada judul soal yang tersedia.</div>';
  } else {
    // echo '<h5 class="mb-3">Semua Judul Soal</h5>';
    // Mengganti struktur tabel dengan list-group
    echo '<div class="list-group">';
    foreach ($all_titles as $title) {
      // Setiap baris sekarang adalah sebuah item link yang interaktif
      echo '<a href="?page=play&title_id=' . $title['id'] . '" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">';

      // Bagian kiri: Judul Soal dan konteksnya (Tema > Subtema)
      echo '  <div>';
      echo '    <div class="fw-semibold">' . h($title['title']) . '</div>';
      echo '    <small class="text-muted">' . h($title['theme_name']) . ' › ' . h($title['subtheme_name']) . '</small>';
      echo '  </div>';

      // Bagian kanan: Badge untuk jumlah dimainkan
      echo '  <span class="badge bg-primary rounded-pill" title="Jumlah dimainkan">' . (int)$title['play_count'] . 'x</span>';

      echo '</a>';
    }
    echo '</div>'; // Penutup list-group
  }
  // ...

  // =================================================================
  // BAGIAN 3: JAVASCRIPT UNTUK FUNGSI PENCARIAN
  // =================================================================
  echo '<script id="searchData" type="application/json">' . json_encode($searchable_list) . '</script>';

  echo <<<JS
    <script>
    setTimeout(function() { // <-- Tambahkan ini
        const searchInput = document.getElementById('pageSearchInput');
        const defaultView = document.getElementById('defaultListView');
        const searchResultsView = document.getElementById('pageSearchResultsView');
        const subthemeResultsContainer = document.getElementById('pageSubthemeResultsContainer');
        const titleResultsContainer = document.getElementById('pageTitleResultsContainer');
        const subthemeResults = document.getElementById('pageSubthemeResults');
        const titleResults = document.getElementById('pageTitleResults');
        const searchDivider = document.getElementById('pageSearchDivider');
        const searchNoResults = document.getElementById('pageSearchNoResults');
        
        const searchData = JSON.parse(document.getElementById('searchData').textContent);

        searchInput.addEventListener('input', function () {
            const query = this.value.toLowerCase().trim();

            if (query === '') {
                defaultView.style.display = 'block';
                searchResultsView.style.display = 'none';
                return;
            }

            defaultView.style.display = 'none';
            searchResultsView.style.display = 'block';

            subthemeResults.innerHTML = '';
            titleResults.innerHTML = '';

            const subthemeMatches = searchData.filter(item => item.type === 'subtheme' && item.searchText.includes(query));
            const titleMatches = searchData.filter(item => item.type === 'title' && item.searchText.includes(query));

            if (subthemeMatches.length > 0) {
                subthemeResultsContainer.style.display = 'block';
                subthemeMatches.forEach(item => {
                    const a = document.createElement('a');
                    a.href = item.url;
                    a.className = 'list-group-item list-group-item-action';
                    a.innerHTML = `\${item.name} <small class="text-muted d-block">\${item.context}</small>`;
                    subthemeResults.appendChild(a);
                });
            } else {
                subthemeResultsContainer.style.display = 'none';
            }

            if (titleMatches.length > 0) {
                titleResultsContainer.style.display = 'block';
                titleMatches.forEach(item => {
                    const a = document.createElement('a');
                    a.href = item.url;
                    a.className = 'list-group-item list-group-item-action';
                    a.innerHTML = `\${item.name} <small class="text-muted d-block">\${item.context}</small>`;
                    titleResults.appendChild(a);
                });
            } else {
                titleResultsContainer.style.display = 'none';
            }
            
            searchDivider.style.display = (subthemeMatches.length > 0 && titleMatches.length > 0) ? 'block' : 'none';
            searchNoResults.style.display = (subthemeMatches.length === 0 && titleMatches.length === 0) ? 'block' : 'none';
        });
    });
    </script>
    JS;
}

// =======================
// AWAL FUNGSI SUBTHEME
// =======================


function view_subthemes()
{
  // 1. Ambil ID tema dari URL
  $theme_id = (int)($_GET['theme_id'] ?? 0);

  // 2. Dapatkan informasi tema untuk judul halaman
  $theme = q("SELECT id, name FROM themes WHERE id = ?", [$theme_id])->fetch();
  if (!$theme) {
    echo '<div class="alert alert-warning">Tema tidak ditemukan.</div>';
    return;
  }

  // 3. Tampilkan judul halaman
  echo '<h3 class="mb-3">Pilih Subtema: ' . h($theme['name']) . '</h3>';

  // 4. Ambil allowed teacher IDs berdasarkan role
  $allowed_teacher_ids = get_allowed_teacher_ids_for_content();
  
  // 5. Query subtema dengan filter owner_user_id
  if (empty($allowed_teacher_ids)) {
      $subthemes = q("SELECT id, name FROM subthemes WHERE theme_id = ? AND owner_user_id IS NULL ORDER BY name", [$theme_id])->fetchAll();
  } else {
      $placeholders = implode(',', array_fill(0, count($allowed_teacher_ids), '?'));
      $subthemes = q(
          "SELECT id, name FROM subthemes WHERE theme_id = ? AND (owner_user_id IS NULL OR owner_user_id IN ($placeholders)) ORDER BY name",
          array_merge([$theme_id], $allowed_teacher_ids)
      )->fetchAll();
  }
  
  // 6. Jika tidak ada subtema, tampilkan pesan
  if (!$subthemes) {
    echo '<div class="alert alert-secondary">Belum ada subtema untuk tema ini. Silakan tambahkan melalui halaman admin.</div>';
    return;
  }

  // 7. Tampilkan daftar subtema sebagai link
  echo '<div class="list-group">';
  foreach ($subthemes as $sub) {
    $url = '?page=titles&subtheme_id=' . $sub['id'];
    echo '<a href="' . $url . '" class="list-group-item list-group-item-action">' . h($sub['name']) . '</a>';
  }
  echo '</div>';
}


// =======================
// AKHIR FUNGSI SUBTHEME
// =======================

function view_titles()
{
  $sub_id = (int)($_GET['subtheme_id'] ?? 0);
  
  // Get subtheme info
  $sub = q("SELECT st.*,t.name tname FROM subthemes st JOIN themes t ON t.id=st.theme_id WHERE st.id=?", [$sub_id])->fetch();
  if (!$sub) {
    echo '<div class="alert alert-warning">Sub tema tidak ditemukan.</div>';
    return;
  }
  echo '<h3 class="mb-3">Judul Soal: ' . h($sub['tname']) . ' › ' . h($sub['name']) . '</h3>';
  
  // Get allowed teacher IDs
  $allowed_teacher_ids = get_allowed_teacher_ids_for_content();
  
  // Query titles dengan filter owner_user_id
  if (empty($allowed_teacher_ids)) {
      $titles = q("SELECT * FROM quiz_titles WHERE subtheme_id=? AND owner_user_id IS NULL ORDER BY title", [$sub_id])->fetchAll();
  } else {
      $placeholders = implode(',', array_fill(0, count($allowed_teacher_ids), '?'));
      $titles = q(
          "SELECT * FROM quiz_titles WHERE subtheme_id=? AND (owner_user_id IS NULL OR owner_user_id IN ($placeholders)) ORDER BY title",
          array_merge([$sub_id], $allowed_teacher_ids)
      )->fetchAll();
  }
  
  echo '<div class="list-group">';
  foreach ($titles as $t) {
    echo '<a class="list-group-item list-group-item-action" href="?page=play&title_id=' . $t['id'] . '">' . h($t['title']) . '</a>';
  }
  echo '</div>';
}

function view_difficulty_titles()
{
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

  echo '<div class="container">';
  echo '<h1 class="h4 mb-2 text-center">Peta Kesulitan — Judul Soal</h1>';
  echo '<div class="text-center mb-3">';
  echo '  <a class="btn btn-sm ' . ($metric === 'count' ? 'btn-primary' : 'btn-outline-primary') . '" href="' . $urlCount . '">Hitung Kesalahan</a> ';
  echo '  <a class="btn btn-sm ' . ($metric === 'ratio' ? 'btn-primary' : 'btn-outline-primary') . '" href="' . $urlRatio . '">Rasio Kesalahan</a>';
  echo '</div>';

  if ($metric === 'ratio') {
    echo '<p class="text-muted small text-center mb-3">Menampilkan judul dengan attempt ≥ <strong>' . $min . '</strong>. Ubah ambang: tambahkan <code>&min=5</code> (misal).</p>';
  } else {
    echo '<p class="text-muted small text-center mb-3">Urut: paling sering salah → paling jarang salah.</p>';
  }

  if (!$rows) {
    echo '<div class="alert alert-secondary">Belum ada data sesuai filter.</div></div>';
    return;
  }

  echo '<div class="list-group">';
  foreach ($rows as $r) {
    $id      = (int)$r['id'];
    $title   = h($r['title']);
    $wrong   = (int)$r['wrong_count'];
    $attempt = (int)$r['total_attempts'];
    $ratio   = (float)$r['wrong_ratio'];
    $pct     = ($attempt > 0) ? round($ratio * 100) : 0;

    // Bangun URL pertanyaan dengan metric yang sama
    $qs = http_build_query([
      'page' => 'difficulty_questions',
      'title_id' => $id,
      'metric' => $metric,
      'min' => $min,
    ]);
    $href = '?' . $qs;

    echo '<a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="' . $href . '">
            <span>' . $title . '</span>
            <span class="text-nowrap">
              <span class="badge text-bg-danger me-1">Salah: ' . $wrong . '</span>
              <span class="badge text-bg-secondary me-1">Attempt: ' . $attempt . '</span>';
    if ($attempt > 0) {
      echo '<span class="badge text-bg-dark">' . $pct . '%</span>';
    } else {
      echo '<span class="badge text-bg-dark">0%</span>';
    }
    echo   '</span>
          </a>';
  }
  echo '</div></div>';
}


function view_difficulty_questions(int $title_id)
{
  $pdo = pdo_instance();

  echo '<div class="container">';

  if ($title_id <= 0) {
    echo '<div class="alert alert-warning">Judul tidak ditemukan.</div></div>';
    return;
  }

  // Info judul
  $st = $pdo->prepare("SELECT id, title FROM quiz_titles WHERE id = ?");
  $st->execute([$title_id]);
  $title = $st->fetch(PDO::FETCH_ASSOC);
  if (!$title) {
    echo '<div class="alert alert-warning">Judul tidak ditemukan.</div></div>';
    return;
  }

  // Ambil parameter UI
  // Ambil parameter UI
  $metric = isset($_GET['metric']) ? strtolower(trim($_GET['metric'])) : 'count'; // 'count' | 'ratio'
  if ($metric !== 'ratio') $metric = 'count';
  $min = isset($_GET['min']) ? max(0, (int)$_GET['min']) : 10;

  // Query dasar per soal
  $sqlBase = "
    SELECT
      q.id,
      q.text AS question_text,
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

  // Siapkan statement sesuai metric
  if ($metric === 'ratio') {
    $sql = $sqlBase . " HAVING total_attempts >= :min ORDER BY wrong_ratio DESC, total_attempts DESC, q.id ASC";
    $stq = $pdo->prepare($sql);
    $stq->bindValue(':tid', $title_id, PDO::PARAM_INT);
    $stq->bindValue(':min', $min, PDO::PARAM_INT);
    $stq->execute();
  } else {
    $sql = $sqlBase . " ORDER BY wrong_count DESC, q.id ASC";
    $stq = $pdo->prepare($sql);
    $stq->bindValue(':tid', $title_id, PDO::PARAM_INT);
    $stq->execute();
  }
  $rows = $stq->fetchAll(PDO::FETCH_ASSOC);

  // AUTO-RELAX: jika metric=ratio dan hasil kosong, turunkan ambang ke 0 otomatis
  $autoRelaxed = false;
  if ($metric === 'ratio' && empty($rows) && $min > 0) {
    $sql = $sqlBase . " HAVING total_attempts >= 0 ORDER BY wrong_ratio DESC, total_attempts DESC, q.id ASC";
    $stq = $pdo->prepare($sql);
    $stq->bindValue(':tid', $title_id, PDO::PARAM_INT);
    $stq->execute();
    $rows = $stq->fetchAll(PDO::FETCH_ASSOC);
    $autoRelaxed = true; // tandai supaya kita beri notifikasi ringan
  }




  // UI toggle metric (pertahankan title_id)
  $base = [
    'page' => 'difficulty_questions',
    'title_id' => $title_id
  ];
  $urlCount = '?' . http_build_query($base + ['metric' => 'count']);
  $urlRatio = '?' . http_build_query($base + ['metric' => 'ratio', 'min' => $min]);

  echo '<div class="mb-2"><a href="?page=difficulty&metric=' . $metric . '&min=' . $min . '" class="small text-decoration-underline">&larr; Kembali ke Peta Kesulitan</a></div>';
  echo '<h2 class="h5 mb-1">Judul: ' . h($title['title']) . '</h2>';
  echo '<div class="mb-3">';
  echo '  <a class="btn btn-sm ' . ($metric === 'count' ? 'btn-primary' : 'btn-outline-primary') . '" href="' . $urlCount . '">Hitung Kesalahan</a> ';
  echo '  <a class="btn btn-sm ' . ($metric === 'ratio' ? 'btn-primary' : 'btn-outline-primary') . '" href="' . $urlRatio . '">Rasio Kesalahan</a>';
  echo '</div>';

  if ($metric === 'ratio') {
    if ($autoRelaxed) {
      echo '<p class="text-muted small mb-3">Tidak ada soal dengan attempt ≥ <strong>' . $min . '</strong>. Filter <em>otomatis</em> dilonggarkan ke <code>min=0</code> agar data tetap tampil.</p>';
    } else {
      echo '<p class="text-muted small mb-3">Menampilkan soal dengan attempt ≥ <strong>' . $min . '</strong>. Ubah ambang di URL: <code>&min=5</code> (misal).</p>';
    }
  } else {
    echo '<p class="text-muted small mb-3">Soal diurutkan berdasarkan jumlah salah (terbanyak di atas).</p>';
  }


  if (!$rows) {
    echo '<div class="alert alert-secondary">Belum ada soal sesuai filter.</div>';
    return;
  }

  echo '<div class="list-group">';
  foreach ($rows as $r) {
    // Ambil ID soal dari data
    $question_id = (int)$r['id'];
    $qtext   = h($r['question_text']);
    $wrong   = (int)$r['wrong_count'];
    $attempt = (int)$r['total_attempts'];
    $ratio   = (float)$r['wrong_ratio'];
    $pct     = ($attempt > 0) ? round($ratio * 100) : 0;

    // Buat URL tujuan untuk halaman edit
    $edit_url = '?page=qmanage&title_id=' . $title_id . '&edit=' . $question_id;

    // Ubah <div> menjadi <a> dengan class "list-group-item-action" agar interaktif
    echo '<a href="' . $edit_url . '" class="list-group-item list-group-item-action">
            <div class="fw-semibold mb-1">' . $qtext . '</div>
            <div class="small">
              <span class="badge text-bg-danger me-1">Salah: ' . $wrong . '</span>
              <span class="badge text-bg-secondary me-1">Attempt: ' . $attempt . '</span>
              <span class="badge text-bg-dark">' . $pct . '%</span>
            </div>
          </a>';
  }
  echo '</div></div>';
}



// ------ ADMIN GUARD (aman walau fungsi/auth kamu beda nama) ------
function is_admin_current(): bool
{
  // 1) Jika kamu sudah punya is_admin(), pakai itu
  if (function_exists('is_admin')) {
    try {
      return (bool) is_admin();
    } catch (Throwable $e) { /* ignore */
    }
  }

  // 2) Cek variabel $me (gaya file kamu)
  if (isset($GLOBALS['me']) && is_array($GLOBALS['me'])) {
    $me = $GLOBALS['me'];
    if (!empty($me['is_admin'])) return true;
    if (!empty($me['role']) && strtolower((string)$me['role']) === 'admin') return true;
  }

  // 3) (Opsional) Kalau hanya punya is_logged_in(), JANGAN otomatis admin.
  // Biarkan false agar benar-benar khusus admin.
  return false;
}

function guard_admin(): bool
{
  if (!is_admin_current()) {
    echo '<div class="container"><div class="alert alert-danger">403 — Khusus admin.</div></div>';
    return false;
  }
  return true;
}



// ===============================================
// QUIZ ENGINE
// ===============================================
function view_play()
{

  // === BLOK BARU: CEK JIKA SUDAH PERNAH 100 PADA ASSIGNMENT ===
  if (isset($_SESSION['quiz']['assignment_id']) && uid()) {
      $assignment_id = (int)$_SESSION['quiz']['assignment_id'];
      $user_id = uid();
      
      // Query untuk mencari apakah sudah ada submission dengan nilai 100
      $has_perfect_score = q("
          SELECT COUNT(asub.id)
          FROM assignment_submissions asub
          JOIN results r ON asub.result_id = r.id
          WHERE asub.assignment_id = ? AND asub.user_id = ? AND r.score = 100
      ", [$assignment_id, $user_id])->fetchColumn();

      if ($has_perfect_score > 0) {
          // Jika sudah ada nilai 100, tampilkan pesan dan blokir akses
          echo '<div class="alert alert-success mt-4">
                  <strong>Selamat!</strong> Anda sudah mendapatkan nilai 100 untuk tugas ini. Kamu Tidak Perlu mengerjakannya lagi.
                </div>';
          
          // Tampilkan tombol untuk kembali ke daftar tugas
          echo '<a href="?page=student_tasks" class="btn btn-primary mt-3">&laquo; Kembali ke Daftar Tugas</a>';
          return; // HENTIKAN EKSEKUSI view_play()
      }
  }
  // === AKHIR BLOK BARU ===
  
  
  // Coba ambil ID dari URL dulu (untuk kuis biasa & pemilihan mode)
  $title_id_from_url = (int)($_GET['title_id'] ?? 0);
  $assignment_id_from_url = (int)($_GET['assignment_id'] ?? 0);

  // Jika sedang dalam sesi kuis (baik biasa maupun tugas), prioritaskan data dari session
  if (isset($_SESSION['quiz']['session_id'])) {
      $title_id = (int)($_SESSION['quiz']['title_id'] ?? 0);
  } else {
      // Jika tidak ada sesi, gunakan dari URL (hanya untuk halaman pemilihan mode)
      $title_id = $title_id_from_url;
  }
  
  // Ambil mode dari session jika ada, jika tidak, ambil dari URL
  $mode = $_SESSION['quiz']['mode'] ?? ($_GET['mode'] ?? null);

  // Get allowed teacher IDs untuk content visibility check
  $allowed_teacher_ids = get_allowed_teacher_ids_for_content();
  
  // Query title dengan filter owner_user_id berdasarkan role
  if (empty($allowed_teacher_ids)) {
      $title = q(
          "SELECT qt.*,st.name subn,t.name themen FROM quiz_titles qt 
           JOIN subthemes st ON st.id=qt.subtheme_id 
           JOIN themes t ON t.id=st.theme_id 
           WHERE qt.id=? AND qt.owner_user_id IS NULL",
          [$title_id]
      )->fetch();
  } else {
      $placeholders = implode(',', array_fill(0, count($allowed_teacher_ids), '?'));
      $title = q(
          "SELECT qt.*,st.name subn,t.name themen FROM quiz_titles qt 
           JOIN subthemes st ON st.id=qt.subtheme_id 
           JOIN themes t ON t.id=st.theme_id 
           WHERE qt.id=? AND (qt.owner_user_id IS NULL OR qt.owner_user_id IN ($placeholders))",
          array_merge([$title_id], $allowed_teacher_ids)
      )->fetch();
  }
  
  if (!$title) {
    echo '<div class="alert alert-warning">Judul kuis tidak ditemukan.</div>';
    return;
  }

  // Cek apakah ini dari assignment atau restart (jangan tampilkan pilihan mode)
  $from_assignment = isset($_GET['assignment_id']) && (int)$_GET['assignment_id'] > 0;
  $is_restart = isset($_GET['restart']) && $_GET['restart'] === '1';
  $should_show_mode_selection = !$from_assignment && !$is_restart && $title_id > 0;

  if (!$mode && $should_show_mode_selection) {
    echo '<div class="card shadow-sm border-0"><div class="card-body p-4 p-md-5">';
    echo '  <h1 class="card-title text-center mb-2 h3">' . h($title['title']) . '</h1>';
    echo '  <p class="card-subtitle text-center text-muted mb-4">' . h($title['themen']) . ' › ' . h($title['subn']) . '</p>';
    echo '  <hr class="my-4">';
    echo '  <p class="text-center fw-bold mb-4">Pilih Mode Permainan</p>';

    // STRUKTUR BARU: Tombol dan keterangan disatukan per kolom
    echo '  <div class="row g-4">'; // g-4 memberi jarak lebih baik

    // --- Kolom 1: Instan Review ---
    echo '    <div class="col-md-4">';
    echo '      <a class="btn btn-primary btn-lg d-block w-100" href="?page=play&title_id=' . $title_id . '&mode=instant">Instan Review</a>';
    echo '      <p class="small text-muted mt-2 mb-0 text-center">Jawab soal, jika salah akan langsung dibahas. Cocok untuk belajar cepat.</p>';
    echo '    </div>';

    // --- Kolom 2: End Review ---
    echo '    <div class="col-md-4">';
    echo '      <a class="btn btn-outline-primary btn-lg d-block w-100" href="?page=play&title_id=' . $title_id . '&mode=end">End Review</a>';
    echo '      <p class="small text-muted mt-2 mb-0 text-center">Selesaikan semua soal terlebih dahulu, baru lihat pembahasan lengkap di akhir.</p>';
    echo '    </div>';

    // --- Kolom 3: Ujian ---
    echo '    <div class="col-md-4">';
    echo '      <a class="btn btn-outline-danger btn-lg d-block w-100" href="?page=play&title_id=' . $title_id . '&mode=exam">Ujian</a>';
    echo '      <p class="small text-muted mt-2 mb-0 text-center">Mode serius dengan timer keseluruhan. Tidak ada review jawaban di akhir.</p>';
    echo '    </div>';

    echo '  </div>';

    echo '</div></div>';
    return;
  }



// Dengan guard yang baru, kita bisa berasumsi sesi sudah siap.
  // Jika sesi tidak ada (misal, karena akses URL manual yang salah), beri peringatan.
  if (!isset($_SESSION['quiz']) || !isset($_SESSION['quiz']['session_id'])) {
      echo '<div class="alert alert-warning">Sesi kuis tidak valid. Silakan mulai dari awal.</div>';
      echo '<a href="?page=play&title_id=' . $title_id . '" class="btn btn-primary">Pilih Mode</a>';
      return;
  }
  
  // Langsung gunakan session_id yang sudah ada dan valid.
  $sid = $_SESSION['quiz']['session_id'];
  
  


  $qs = q("SELECT q.* FROM quiz_session_questions m
         JOIN questions q ON q.id = m.question_id
         WHERE m.session_id = ?
         ORDER BY m.sort_no", [$sid])->fetchAll();
  $i = max(0, (int)($_GET['i'] ?? 0));
  if ($i >= count($qs) && count($qs) > 0) { // Tambahkan pengecekan count($qs) > 0
    return view_summary($sid);
  }

  // ==========================================================
  // ▼▼▼ GANTI SELURUH BLOK INI ▼▼▼
  // ==========================================================
  if ($mode === 'instant' || $mode === 'end' || $mode === 'exam') {
    // Siapkan CSS khusus untuk panel navigasi soal
    echo '<style>
        #exam-nav-panel .nav-link { 
            border: 1px solid var(--bs-border-color);
            margin: 2px;
            border-radius: .25rem;
            width: 40px;
            height: 40px;
            line-height: 40px;
            padding: 0;
            text-align: center;
        }
        #exam-nav-panel .nav-link.answered {
            background-color: var(--bs-primary);
            color: white;
            border-color: var(--bs-primary);
        }
        .quiz-choice-item.selected {
            background-color: var(--bs-primary-bg-subtle);
            border-color: var(--bs-primary);
        }
    </style>';

    // Siapkan "wadah"
    echo '<div id="quiz-app-container">';
    echo '  <div id="loading-indicator" class="text-center p-4" style="display: none;">
            <svg class="quizb-loader" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" style="margin: auto;">
                <path class="q-shape" d="M50,5C25.2,5,5,25.2,5,50s20.2,45,45,45s45-20.2,45-45S74.8,5,50,5z M50,86.5C29.9,86.5,13.5,70.1,13.5,50 S29.9,13.5,50,13.5S86.5,29.9,86.5,50S70.1,86.5,50,86.5z M68.5,43.8c-1.3-1.3-3.5-1.3-4.8,0L49,58.5l-6.7-6.7 c-1.3-1.3-3.5-1.3-4.8,0s-1.3,3.5,0,4.8l9,9c0.6,0.6,1.5,1,2.4,1s1.8-0.4,2.4-1l17-17C69.8,47.2,69.8,45.1,68.5,43.8z"/>
                <circle class="dot dot-1" cx="35" cy="50" r="5"/>
                <circle class="dot dot-2" cx="50" cy="50" r="5"/>
                <circle class="dot dot-3" cx="65" cy="50" r="5"/>
            </svg>
        </div>';
    echo   '</div>';

    // Tanam data dari PHP ke JavaScript
    $session_id_for_js = (int)$_SESSION['quiz']['session_id'];
// ▼▼▼ GANTI BLOK INI ▼▼▼
$assignment_settings = $_SESSION['quiz']['assignment_settings'] ?? null;

// Terapkan timer dari tugas jika ada, jika tidak, gunakan pengaturan personal pengguna
$timerSecs = $assignment_settings['timer_per_soal'] ?? user_timer_seconds();
$examTimerMins = $assignment_settings['durasi_ujian'] ?? user_exam_timer_minutes();
// ▲▲▲ AKHIR PERUBAHAN ▲▲▲
    $mode_for_js = h($mode);

    echo <<<JS
    <script>
        const appContainer = document.getElementById('quiz-app-container');
        
        const quizState = {
            sessionId: {$session_id_for_js},
            mode: '{$mode_for_js}',
            title: '',
            questions: [],
            currentQuestionIndex: 0,
            userAnswers: new Map(), // Gunakan Map untuk memudahkan update jawaban
            timerInterval: null,
            examTimerInterval: null
        };

        async function fetchQuizData() {
            try {
                const response = await fetch(`?action=api_get_quiz`);
                if (!response.ok) throw new Error('Gagal mengambil data kuis.');
                
                const data = await response.json();
                if (!data.ok) throw new Error(data.error || 'Data kuis tidak valid.');

                quizState.title = data.session.title;
                quizState.questions = data.questions;

                if(quizState.mode === 'exam' && quizState.questions.length > 0) {
                    startExamTimer({$examTimerMins});
                }
                renderQuestion(quizState.currentQuestionIndex);

            } catch (error) {
                appContainer.innerHTML = `<div class="alert alert-danger">Error: \${error.message}</div>`;
            }
        }

        function renderQuestion(index) {
            clearInterval(quizState.timerInterval);
            
            if (index >= quizState.questions.length) {
                finishQuiz();
                return;
            }
            quizState.currentQuestionIndex = index;
            const question = quizState.questions[index];
            
            let choicesHTML = '';
            const existingAnswer = quizState.userAnswers.get(question.id);
            question.choices.forEach(choice => {
                const isSelected = existingAnswer && existingAnswer.choice_id === choice.id;
                choicesHTML += `<button type="button" class="quiz-choice-item \${isSelected ? 'selected' : ''}" data-choice-id="\${choice.id}" data-is-correct="\${choice.is_correct}">\${escapeHTML(choice.text)}</button>`;
            });
            
            // Render UI berdasarkan mode
            if(quizState.mode === 'exam') {
                renderExamUI(question, choicesHTML);
            } else {
                renderStandardUI(question, choicesHTML);
            }
            
            // Pasang event listener
            appContainer.querySelectorAll('.quiz-choice-item').forEach(button => {
                button.addEventListener('click', handleAnswerClick);
            });
            
            // Mulai timer per soal jika bukan mode ujian
            if(quizState.mode !== 'exam'){
                startTimer({$timerSecs});
            }
        }

        function renderStandardUI(question, choicesHTML) {
            const totalQuestions = quizState.questions.length;
            const index = quizState.currentQuestionIndex;
            appContainer.innerHTML = `
                <div class="quiz-container">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h4 class="h5 m-0">\${escapeHTML(quizState.title)}</h4>
                        <span class="badge bg-secondary">Soal \${index + 1} dari \${totalQuestions}</span>
                    </div>
                    <div class="progress mb-3" style="height: 5px;"><div class="progress-bar" style="width: \${((index + 1) / totalQuestions) * 100}%;"></div></div>
                    <div class="quiz-question-box"><h2 class="quiz-question-text">\${escapeHTML(question.text)}</h2></div>
                    <div id="timerWrap" class="text-center mb-3"><span class="badge text-bg-secondary fs-6">Sisa waktu: <b id="timerLabel">{$timerSecs}</b> detik</span></div>
                    <div class="quiz-choices-grid">\${choicesHTML}</div>
                </div>
            `;
        }
        
        function renderExamUI(question, choicesHTML) {
            const totalQuestions = quizState.questions.length;
            const index = quizState.currentQuestionIndex;

            let navButtonsHTML = '';
            for(let i=0; i < totalQuestions; i++) {
                const qId = quizState.questions[i].id;
                const isAnswered = quizState.userAnswers.has(qId);
                navButtonsHTML += `<a href="#" class="nav-link \${isAnswered ? 'answered' : ''}" onclick="renderQuestion(\${i}); return false;">\${i + 1}</a>`;
            }

            appContainer.innerHTML = `
                <div class="offcanvas offcanvas-start" tabindex="-1" id="exam-nav-panel">
                  <div class="offcanvas-header">
                    <h5 class="offcanvas-title">Navigasi Soal</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
                  </div>
                  <div class="offcanvas-body">
                    <div class="d-flex flex-wrap">\${navButtonsHTML}</div>
                  </div>
                </div>

                <div class="quiz-container">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h4 class="h5 m-0">\${escapeHTML(quizState.title)}</h4>
                        <span id="exam-timer-display" class="badge text-bg-danger fs-6">Sisa Waktu: --:--</span>
                    </div>
                    <div class="progress mb-3" style="height: 5px;"><div class="progress-bar" id="exam-progress-bar"></div></div>
                    <div class="quiz-question-box"><h2 class="quiz-question-text">\${escapeHTML(question.text)}</h2></div>
                    <div class="quiz-choices-grid">\${choicesHTML}</div>

                    <div class="d-flex justify-content-between mt-4">
                        <button class="btn btn-secondary" onclick="renderQuestion(\${index - 1})" \${index === 0 ? 'disabled' : ''}>&laquo; Kembali</button>
                        <button class="btn btn-info" type="button" data-bs-toggle="offcanvas" data-bs-target="#exam-nav-panel">Daftar Soal</button>
                        \${index === totalQuestions - 1 
                            ? `<button class="btn btn-success" onclick="confirmFinish()">Selesaikan Ujian</button>`
                            : `<button class="btn btn-primary" onclick="renderQuestion(\${index + 1})">Berikutnya &raquo;</button>`
                        }
                    </div>
                </div>
            `;
            updateExamProgress();
        }
        
        function startExamTimer(minutes) {
            let totalSeconds = minutes * 60;
            const timerDisplay = document.getElementById('exam-timer-display');

            quizState.examTimerInterval = setInterval(() => {
                if (totalSeconds <= 0) {
                    clearInterval(quizState.examTimerInterval);
                    alert('Waktu ujian telah habis!');
                    finishQuiz();
                    return;
                }
                totalSeconds--;
                const mins = Math.floor(totalSeconds / 60).toString().padStart(2, '0');
                const secs = (totalSeconds % 60).toString().padStart(2, '0');
                if(document.getElementById('exam-timer-display')) {
                    document.getElementById('exam-timer-display').textContent = `Sisa Waktu: \${mins}:\${secs}`;
                }
            }, 1000);
        }

        function handleAnswerClick(event) {
            clearInterval(quizState.timerInterval);
            const selectedButton = event.currentTarget;
            
            if (quizState.mode === 'exam') {
                handleAnswerClickExamMode(selectedButton);
            } else if (quizState.mode === 'end') {
                appContainer.querySelectorAll('.quiz-choice-item').forEach(btn => btn.disabled = true);
                handleAnswerClickEndMode(selectedButton);
            } else {
                appContainer.querySelectorAll('.quiz-choice-item').forEach(btn => btn.disabled = true);
                handleAnswerClickInstantMode(selectedButton);
            }
        }
        
        function handleAnswerClickExamMode(selectedButton){
            const question = quizState.questions[quizState.currentQuestionIndex];
            
            // Hapus kelas 'selected' dari semua pilihan di soal ini
            appContainer.querySelectorAll('.quiz-choice-item').forEach(btn => btn.classList.remove('selected'));
            // Tambahkan kelas 'selected' ke pilihan yang diklik
            selectedButton.classList.add('selected');

            quizState.userAnswers.set(question.id, {
                question_id: question.id,
                choice_id: parseInt(selectedButton.dataset.choiceId),
                is_correct: selectedButton.dataset.isCorrect === 'true'
            });
            updateExamProgress();
            
            // ▼▼▼ AUTO-ADVANCE KE SOAL BERIKUTNYA (MODE UJIAN) ▼▼▼
            const totalQuestions = quizState.questions.length;
            const nextIndex = quizState.currentQuestionIndex + 1;
            
            // Disable semua pilihan jawaban untuk mencegah klik ganda
            appContainer.querySelectorAll('.quiz-choice-item').forEach(btn => btn.disabled = true);
            
            // Tunggu 300ms, lalu lanjut ke soal berikutnya atau selesaikan ujian
            setTimeout(() => {
                if (nextIndex >= totalQuestions) {
                    // Jika ini soal terakhir, tampilkan konfirmasi sebelum selesai
                    confirmFinish();
                } else {
                    // Lanjut ke soal berikutnya
                    renderQuestion(nextIndex);
                }
            }, 300);
            // ▲▲▲ AKHIR AUTO-ADVANCE ▲▲▲
        }

        function updateExamProgress() {
            const total = quizState.questions.length;
            const answered = quizState.userAnswers.size;
            const percentage = (answered / total) * 100;
            if(document.getElementById('exam-progress-bar')) {
                document.getElementById('exam-progress-bar').style.width = `\${percentage}%`;
            }
            // Update panel navigasi
            const panel = document.getElementById('exam-nav-panel');
            if (panel) {
                for(let i=0; i<total; i++) {
                    const qId = quizState.questions[i].id;
                    const navLink = panel.querySelector(`a[onclick*="renderQuestion(\${i})"]`);
                    if(navLink) {
                        if(quizState.userAnswers.has(qId)) {
                            navLink.classList.add('answered');
                        } else {
                            navLink.classList.remove('answered');
                        }
                    }
                }
            }
        }

        function confirmFinish() {
            const unansweredCount = quizState.questions.length - quizState.userAnswers.size;
            let message = "Anda yakin ingin menyelesaikan ujian?";
            if (unansweredCount > 0) {
                message += `\\nMasih ada \${unansweredCount} soal yang belum terjawab.`;
            }
            if (confirm(message)) {
                finishQuiz();
            }
        }
        
        function startTimer(duration) {
            let timeLeft = duration;
            const timerLabel = document.getElementById('timerLabel');
            quizState.timerInterval = setInterval(() => {
                timeLeft--;
                if(timerLabel) timerLabel.textContent = timeLeft;
                if (timeLeft <= 0) {
                    clearInterval(quizState.timerInterval);
                    handleTimeout();
                }
            }, 1000);
        }

        function handleAnswerClickEndMode(selectedButton) {
            const question = quizState.questions[quizState.currentQuestionIndex];
            quizState.userAnswers.set(question.id, {
                question_id: question.id,
                choice_id: parseInt(selectedButton.dataset.choiceId),
                is_correct: selectedButton.dataset.isCorrect === 'true'
            });
            quizState.currentQuestionIndex++;
            setTimeout(() => renderQuestion(quizState.currentQuestionIndex), 200);
        }

        function handleAnswerClickInstantMode(selectedButton) {
            const question = quizState.questions[quizState.currentQuestionIndex];
            const isCorrect = selectedButton.dataset.isCorrect === 'true';
            const choiceId = parseInt(selectedButton.dataset.choiceId);
            quizState.userAnswers.set(question.id, {
                question_id: question.id,
                choice_id: choiceId,
                is_correct: isCorrect
            });
            if (isCorrect) {
                quizState.currentQuestionIndex++;
                setTimeout(() => renderQuestion(quizState.currentQuestionIndex), 300);
            } else {
                // PERUBAHAN DI SINI: Langsung panggil finishQuiz()
                finishQuiz();
            }
        }
        
        function handleTimeout() {
            if (quizState.mode === 'end' || quizState.mode === 'exam') {
                handleTimeoutEndMode();
            } else {
                handleTimeoutInstantMode();
            }
        }

        function handleTimeoutEndMode() {
            const question = quizState.questions[quizState.currentQuestionIndex];
            quizState.userAnswers.set(question.id, {
                question_id: question.id,
                choice_id: 0,
                is_correct: false 
            });
            quizState.currentQuestionIndex++;
            renderQuestion(quizState.currentQuestionIndex);
        }
        
        function handleTimeoutInstantMode() {
            const question = quizState.questions[quizState.currentQuestionIndex];
            quizState.userAnswers.set(question.id, {
                question_id: question.id,
                choice_id: 0,
                is_correct: false 
            });
            // PERUBAHAN DI SINI: Langsung panggil finishQuiz()
            finishQuiz();
        }
        
        function showReviewAndFinish(result) {
            const question = quizState.questions[quizState.currentQuestionIndex];
            const statusText = result.wasTimeout ? 'Waktu Habis ⏰' : 'Jawaban Salah ❌';
            const statusClass = 'danger';
            let choicesHTML = '';
            question.choices.forEach(choice => {
                let itemClass = 'list-group-item';
                let note = '';
                if (choice.is_correct) {
                    itemClass += ' list-group-item-success';
                    note = ' <small class="text-muted">— Jawaban benar</small>';
                } else if (choice.id === result.selectedChoiceId) {
                    itemClass += ' list-group-item-danger';
                    note = ' <small class="text-muted">— Pilihan Anda</small>';
                }
                choicesHTML += `<li class="\${itemClass}">\${escapeHTML(choice.text)}\${note}</li>`;
            });
            let explanationHTML = '';
            if (question.explanation) {
                explanationHTML = `
                <div class="alert alert-secondary mt-3">
                    <strong>Penjelasan:</strong>
                    <p class="mb-0">\${escapeHTML(question.explanation)}</p>
                </div>`;
            }
            appContainer.innerHTML = `
                <div class="quiz-container">
                    <h4 class="h5">\${escapeHTML(quizState.title)}</h4>
                     <div class="alert alert-\${statusClass}">\${statusText}</div>
                    <div class="card">
                        <div class="card-header"><strong>Soal:</strong> \${escapeHTML(question.text)}</div>
                        <ul class="list-group list-group-flush">\${choicesHTML}</ul>
                    </div>
                    \${explanationHTML}
                    <div class="text-center mt-4"><button id="finishButton" class="btn btn-primary btn-lg">Lihat Ringkasan Akhir</button></div>
                </div>
            `;
            document.getElementById('finishButton').addEventListener('click', finishQuiz);
        }

        async function finishQuiz() {
            clearInterval(quizState.examTimerInterval);
            clearInterval(quizState.timerInterval);
            const loadingMessage = quizState.mode === 'exam' ? 'Ujian Selesai. Menyimpan hasil...' : 'Menyimpan hasil & memuat ringkasan...';
            appContainer.innerHTML = `<div class="text-center p-5"><div class="spinner-border"></div><p class="mt-2">\${loadingMessage}</p></div>`;
            
            try {
                const response = await fetch('?action=api_submit_answers', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        session_id: quizState.sessionId,
                        answers: Array.from(quizState.userAnswers.values())
                    })
                });
                const result = await response.json();
                
                if (result.ok && result.summaryUrl) {
                    window.location.href = result.summaryUrl;
                } else {
                    throw new Error(result.error || 'Gagal menyimpan hasil.');
                }
            } catch (error) {
                appContainer.innerHTML = `<div class="alert alert-danger">Error: \${error.message}</div>`;
            }
        }
        
        function escapeHTML(str) {
            const p = document.createElement('p');
            p.textContent = str;
            return p.innerHTML;
        }

        fetchQuizData();
    </script>
    JS;
  }
  // ==========================================================
  // ▲▲▲ AKHIR DARI BLOK PERUBAHAN ▲▲▲
}
// ================================
// AKHIR VIEW PLAY
// ================================


/**
 * Menampilkan halaman "Notification Broadcaster" untuk Admin.
 */
function view_broadcast()
{
  // Keamanan: Hanya admin yang boleh mengakses halaman ini.
  if (!is_admin()) {
    echo '<div class="alert alert-danger">Akses ditolak. Halaman ini khusus untuk administrator.</div>';
    return;
  }

  // Menampilkan pesan sukses setelah mengirim
  if (isset($_GET['ok'])) {
    $count = (int)($_GET['count'] ?? 0);
    echo '<div class="alert alert-success">Notifikasi berhasil dikirim ke ' . $count . ' pengguna.</div>';
  }

  // Ambil data dinamis untuk dropdown pilihan target
  $roles = ['admin', 'pengajar', 'pelajar', 'umum'];
  $user_types = ['Pengajar', 'Pelajar', 'Umum'];
  $institutions = q("SELECT DISTINCT nama_sekolah FROM users WHERE nama_sekolah IS NOT NULL AND nama_sekolah != '' ORDER BY nama_sekolah ASC")->fetchAll(PDO::FETCH_COLUMN);
  $classes = q("SELECT c.id, CONCAT(ti.nama_institusi, ' - ', c.nama_kelas) AS full_class_name FROM classes c JOIN teacher_institutions ti ON c.id_institusi = ti.id ORDER BY full_class_name ASC")->fetchAll();

  ?>
  <div class="card">
    <div class="card-header">
      <h4 class="mb-0">Kirim Notifikasi Massal</h4>
    </div>
    <div class="card-body">
      <form action="?action=send_broadcast" method="POST">

        <div class="mb-3">
          <label for="message" class="form-label fw-bold">Isi Pesan Notifikasi <span class="text-danger">*</span></label>
          <textarea class="form-control" id="message" name="message" rows="3" required placeholder="Contoh: Maintenance akan dilakukan malam ini pukul 23:00."></textarea>
        </div>

        <div class="mb-3">
          <label for="link" class="form-label fw-bold">Link Tujuan (Opsional)</label>
          <input type="text" class="form-control" id="link" name="link" placeholder="Contoh: ?page=about atau https://link.lain">
        </div>

        <hr class="my-4">

        <h5 class="mb-3">Pilih Target Penerima</h5>

        <div class="mb-3">
          <label for="target_type" class="form-label">Kirim berdasarkan...</label>
          <select class="form-select" id="target_type" name="target_type" required>
            <option value="" selected disabled>-- Pilih Kriteria --</option>
            <option value="role">Role Pengguna</option>
            <option value="user_type">Tipe Pengguna</option>
            <option value="institution">Institusi</option>
            <option value="class">Kelas Spesifik</option>
          </select>
        </div>

        <div id="target_role_div" class="target-group" style="display: none;">
          <label for="target_value_role" class="form-label">Pilih Role</label>
          <select class="form-select" id="target_value_role" name="target_value_role">
            <?php foreach ($roles as $role): ?>
              <option value="<?php echo h($role); ?>"><?php echo ucfirst(h($role)); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div id="target_user_type_div" class="target-group" style="display: none;">
          <label for="target_value_user_type" class="form-label">Pilih Tipe Pengguna</label>
          <select class="form-select" id="target_value_user_type" name="target_value_user_type">
            <?php foreach ($user_types as $type): ?>
              <option value="<?php echo h($type); ?>"><?php echo h($type); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div id="target_institution_div" class="target-group" style="display: none;">
          <label for="target_value_institution" class="form-label">Pilih Institusi</label>
          <select class="form-select" id="target_value_institution" name="target_value_institution">
            <?php foreach ($institutions as $inst): ?>
              <option value="<?php echo h($inst); ?>"><?php echo h($inst); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div id="target_class_div" class="target-group" style="display: none;">
          <label for="target_value_class" class="form-label">Pilih Kelas</label>
          <select class="form-select" id="target_value_class" name="target_value_class">
            <?php foreach ($classes as $class): ?>
              <option value="<?php echo $class['id']; ?>"><?php echo h($class['full_class_name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="mt-4">
          <button type="submit" class="btn btn-primary">Kirim Notifikasi</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const targetTypeSelect = document.getElementById('target_type');
      const targetGroups = document.querySelectorAll('.target-group');
      const targetSelects = document.querySelectorAll('.target-group select');

      targetTypeSelect.addEventListener('change', function() {
        const selectedType = this.value;

        // Sembunyikan semua grup dan nonaktifkan semua select di dalamnya
        targetGroups.forEach(group => group.style.display = 'none');
        targetSelects.forEach(select => select.disabled = true);

        if (selectedType) {
          const targetDiv = document.getElementById('target_' + selectedType + '_div');
          const targetSelect = document.getElementById('target_value_' + selectedType);

          if (targetDiv && targetSelect) {
            targetDiv.style.display = 'block'; // Tampilkan grup yang relevan
            targetSelect.disabled = false; // Aktifkan select di dalamnya
            // Ganti nama agar hanya yang aktif yang dikirim
            targetSelect.name = 'target_value';
          }
        }
      });
    });
  </script>
<?php
}



// ================================
// AWAL FUNGSI WELCOME
// ================================




/**
 * Menampilkan halaman selamat datang untuk pengguna baru.
 * [VERSI SEDERHANA] Opsi input kelas dihapus sepenuhnya.
 */
function view_welcome()
{
  $u = $_SESSION['user'] ?? null;
  if (!$u) {
    redirect('./');
    return;
  }

  $all_institutions = q("SELECT nama, tingkat_pendidikan, npsn_kode_pt FROM institutions ORDER BY nama")->fetchAll();
  $institutions_by_tingkat = [
    'SD/MI' => [],
    'SMP/MTs' => [],
    'SMA/SMK/MA' => [],
    'Perguruan Tinggi' => [],
  ];
  foreach ($all_institutions as $inst) {
    $institutions_by_tingkat[$inst['tingkat_pendidikan']][] = [
      'id' => $inst['npsn_kode_pt'],
      'nama' => $inst['nama']
    ];
  }
?>

  <style>
    .navbar,
    footer,
    .mobile-nav-footer {
      display: none !important;
    }

    body {
      padding-top: 0 !important;
      padding-bottom: 0 !important;
    }
  </style>

  <div class="container d-flex align-items-center justify-content-center min-vh-100 py-4">
    <div class="card shadow-sm" style="max-width: 550px; width: 100%;">
      <div class="card-body p-4 p-md-5">
        <h2 class="card-title text-center mb-2">Selamat Datang di QuizB!</h2>
        <p class="text-center text-muted mb-4">Satu langkah lagi, <?php echo h(explode(' ', $u['name'])[0]); ?>. Mohon lengkapi profil Anda.</p>

        <form action="?action=save_welcome" method="POST" id="welcomeForm">

          <div class="mb-3">
            <label for="displayName" class="form-label fw-bold">Nama Tampilan Anda</label>
            <input type="text" class="form-control" id="displayName" name="display_name" value="<?php echo h($u['name']); ?>" required minlength="3">
          </div>

          <div class="mb-3">
            <label for="userType" class="form-label fw-bold">Saya adalah seorang:</label>
            <select class="form-select" id="userType" name="user_type" required>
              <option value="" selected disabled>-- Pilih Peran --</option>
              <option value="Pengajar">Pengajar</option>
              <option value="Pelajar">Pelajar</option>
              <option value="Umum">Umum</option>
            </select>
          </div>

          <div id="conditionalFields" style="display: none;">
            <hr class="my-3">
            <div class="mb-3">
              <label for="tingkatPendidikan" class="form-label fw-bold">Tingkat Pendidikan</label>
              <select class="form-select" id="tingkatPendidikan" name="tingkat_pendidikan">
                <option value="" selected disabled>-- Pilih Tingkat --</option>
                <option value="SD/MI">SD / MI</option>
                <option value="SMP/MTs">SMP / MTs</option>
                <option value="SMA/SMK/MA">SMA / SMK / MA</option>
                <option value="Perguruan Tinggi">Perguruan Tinggi</option>
              </select>
            </div>

            <div class="mb-3" id="sekolahContainer" style="display: none;">
              <label for="namaSekolahDropdown" class="form-label fw-bold">Nama Sekolah / Kampus</label>
              <select class="form-select" id="namaSekolahDropdown"></select>
              <input type="text" class="form-control mt-2" id="namaSekolahManual" placeholder="Ketik nama sekolah/kampus Anda" style="display:none;">
              <input type="hidden" id="namaSekolahHidden" name="nama_sekolah">
              <input type="hidden" id="sekolahIdHidden" name="sekolah_id">
              <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" id="manualEntryCheckbox">
                <label class="form-check-label" for="manualEntryCheckbox">
                  Sekolah/Kampus saya tidak ada di daftar.
                </label>
              </div>
            </div>

          </div>

          <div class="d-grid mt-4">
            <button type="submit" class="btn btn-primary btn-lg">Simpan & Mulai Belajar</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script id="institutionsData" type="application/json">
    <?php echo json_encode($institutions_by_tingkat); ?>
  </script>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Ambil elemen-elemen DOM
      const userType = document.getElementById('userType');
      const conditional = document.getElementById('conditionalFields');
      const tingkat = document.getElementById('tingkatPendidikan');
      const sekolahBox = document.getElementById('sekolahContainer');

      const sekolahDropdown = document.getElementById('namaSekolahDropdown');
      const manualInput = document.getElementById('namaSekolahManual');
      const manualCheckbox = document.getElementById('manualEntryCheckbox');

      const hiddenNamaSekolah = document.getElementById('namaSekolahHidden');
      const hiddenIdSekolah = document.getElementById('sekolahIdHidden');

      const institutionsData = JSON.parse(document.getElementById('institutionsData').textContent);

      function populateSekolahDropdown(selectedTingkat) {
        sekolahDropdown.innerHTML = '<option value="" selected disabled>-- Pilih Institusi --</option>';
        const data = institutionsData[selectedTingkat] || [];
        data.forEach(inst => {
          const option = new Option(inst.nama, inst.id);
          sekolahDropdown.add(option);
        });
      }

      function handleInstitusiChange() {
        if (manualCheckbox.checked) {
          hiddenNamaSekolah.value = manualInput.value;
          hiddenIdSekolah.value = '';
        } else {
          const selectedOption = sekolahDropdown.options[sekolahDropdown.selectedIndex];
          hiddenNamaSekolah.value = selectedOption ? selectedOption.text : '';
          hiddenIdSekolah.value = sekolahDropdown.value;
        }
      }

      userType.addEventListener('change', function() {
        const isEdu = this.value === 'Pengajar' || this.value === 'Pelajar';
        conditional.style.display = isEdu ? 'block' : 'none';
        tingkat.required = isEdu;
        hiddenNamaSekolah.required = isEdu;
        if (!isEdu) {
          sekolahBox.style.display = 'none';
        }
      });

      tingkat.addEventListener('change', function() {
        const selectedTingkat = this.value;
        const hasValue = selectedTingkat !== '';

        sekolahBox.style.display = hasValue ? 'block' : 'none';

        if (hasValue) {
          populateSekolahDropdown(selectedTingkat);
          manualCheckbox.checked = false;
          sekolahDropdown.style.display = 'block';
          manualInput.style.display = 'none';
          handleInstitusiChange();
        }
      });

      manualCheckbox.addEventListener('change', function() {
        const isManual = this.checked;
        sekolahDropdown.style.display = isManual ? 'none' : 'block';
        manualInput.style.display = isManual ? 'block' : 'none';
        manualInput.required = isManual;
        sekolahDropdown.required = !isManual;
        if (isManual) {
          manualInput.value = '';
          manualInput.focus();
        }
        handleInstitusiChange();
      });

      sekolahDropdown.addEventListener('change', handleInstitusiChange);
      manualInput.addEventListener('input', handleInstitusiChange);
    });
  </script>

<?php
}

// ===================
// SAVE WELCOME
// ===================



function handle_save_welcome()
{
  if (!uid()) redirect('./');

  $uid = uid();

  // Ambil semua data dari form (nama_kelas sudah dihapus)
  $display_name = trim($_POST['display_name'] ?? '');
  $user_type = $_POST['user_type'] ?? null;
  $tingkat = $_POST['tingkat_pendidikan'] ?? null;
  $sekolah_id = trim($_POST['sekolah_id'] ?? '');
  $nama_sekolah = trim($_POST['nama_sekolah'] ?? '');
  // Variabel $nama_kelas sudah tidak ada

  if (mb_strlen($display_name) < 3) {
    $display_name = $_SESSION['user']['name'];
  }

  $new_role = 'umum';
  if ($user_type === 'Pengajar') {
    $new_role = 'pengajar';
  } elseif ($user_type === 'Pelajar') {
    $new_role = 'pelajar';
  }

  // Update data di database (query sudah tidak menyertakan nama_kelas)
  q(
    "UPDATE users SET 
        display_name = ?, user_type = ?, role = ?, tingkat_pendidikan = ?, 
        sekolah_id = ?, nama_sekolah = ?, 
        welcome_complete = 1 
       WHERE id = ?",
    [$display_name, $user_type, $new_role, $tingkat, $sekolah_id, $nama_sekolah, $uid]
  );

  if ($user_type === 'Pengajar' && !empty($nama_sekolah)) {
    $exists = q("SELECT id FROM teacher_institutions WHERE id_pengajar = ? AND nama_institusi = ?", [$uid, $nama_sekolah])->fetch();
    if (!$exists) {
      q("INSERT INTO teacher_institutions (id_pengajar, nama_institusi) VALUES (?, ?)", [$uid, $nama_sekolah]);
    }
  }

  // Perbarui sesi
  $_SESSION['user']['welcome_complete'] = 1;
  $_SESSION['user']['display_name'] = $display_name;
  $_SESSION['user']['name'] = $display_name;
  $_SESSION['user']['user_type'] = $user_type;
  $_SESSION['user']['role'] = $new_role;

  redirect('./');
}



/**
 * Mengirim notifikasi percobaan ke semua pengguna yang terdaftar.
 * HANYA BISA DIAKSES OLEH ADMIN.
 */
function handle_send_test_push() {
    global $CONFIG; // Ambil konfigurasi global

    // Keamanan: Hanya admin yang boleh menjalankan ini
    if (!is_admin()) {
        echo "Akses ditolak. Halaman ini hanya untuk administrator.";
        exit;
    }

    // 1. Inisialisasi library WebPush dengan kunci dari $CONFIG
    $webPush = new \Minishlink\WebPush\WebPush([
        'VAPID' => [
            'subject' => 'https://quizb.my.id',
            'publicKey' => $CONFIG['VAPID_PUBLIC_KEY'],
            'privateKey' => $CONFIG['VAPID_PRIVATE_KEY'],
        ],
    ]);

    // 2. Siapkan payload (data notifikasi)
    $payload = json_encode([
        'title' => 'Percobaan Notifikasi QuizB!',
        'body' => 'Jika Anda menerima ini, artinya web push berhasil. 🎉',
    ]);

    // 3. Ambil semua subscription dari database
    $subscriptions = q("SELECT * FROM push_subscriptions")->fetchAll();
    if (empty($subscriptions)) {
        echo "Tidak ada pengguna yang berlangganan notifikasi.";
        exit;
    }

    // 4. Masukkan semua notifikasi ke dalam antrean
    foreach ($subscriptions as $sub) {
        $subscription_object = \Minishlink\WebPush\Subscription::create([
            'endpoint' => $sub['endpoint'],
            'publicKey' => $sub['p256dh'],
            'authToken' => $sub['auth'],
        ]);
        $webPush->queueNotification($subscription_object, $payload);
    }

    // 5. Kirim semua notifikasi dalam antrean dan tampilkan laporannya
    echo "<h3>Mengirim Notifikasi...</h3><pre>";
    foreach ($webPush->flush() as $report) {
        $endpoint = $report->getRequest()->getUri()->__toString();
        if ($report->isSuccess()) {
            echo "[OK] Notifikasi berhasil dikirim ke: ...".substr($endpoint, -20)."\n";
        } else {
            echo "[GAGAL] Gagal mengirim ke: ...".substr($endpoint, -20)." | Alasan: {$report->getReason()}\n";
            // Jika subscription sudah tidak valid/kedaluwarsa, hapus dari database
            if ($report->isSubscriptionExpired()) {
                q("DELETE FROM push_subscriptions WHERE endpoint = ?", [$endpoint]);
                echo "[INFO] Subscription yang kedaluwarsa telah dihapus dari database.\n";
            }
        }
    }
    echo "</pre><p>Selesai. " . count($subscriptions) . " notifikasi telah diproses.</p>";
    exit;
}

/**
 * Memproses form dari halaman broadcaster dan mengirim notifikasi ke target.
 */
function handle_broadcast_notification()
{
  // Keamanan ganda: pastikan hanya admin
  if (!is_admin()) {
    redirect('./');
    return;
  }

  // Ambil data dari form
  $message = trim($_POST['message'] ?? '');
  $link = trim($_POST['link'] ?? '');
  $target_type = $_POST['target_type'] ?? '';
  $target_value = $_POST['target_value'] ?? '';

  // Validasi dasar
  if (empty($message) || empty($target_type) || empty($target_value)) {
    // Jika ada yang kosong, kembali ke halaman broadcast dengan pesan error
    redirect('?page=broadcast&err=incomplete');
    return;
  }

  $user_ids = [];

  // Tentukan query berdasarkan tipe target
  switch ($target_type) {
    case 'role':
      $user_ids = q("SELECT id FROM users WHERE role = ?", [$target_value])->fetchAll(PDO::FETCH_COLUMN);
      break;

    case 'user_type':
      $user_ids = q("SELECT id FROM users WHERE user_type = ?", [$target_value])->fetchAll(PDO::FETCH_COLUMN);
      break;

    case 'institution':
      // Kita cari user yang nama sekolahnya cocok
      $user_ids = q("SELECT id FROM users WHERE nama_sekolah = ?", [$target_value])->fetchAll(PDO::FETCH_COLUMN);
      break;

    case 'class':
      // Kita cari semua pelajar di dalam ID kelas yang dipilih
      $user_ids = q("SELECT id_pelajar FROM class_members WHERE id_kelas = ?", [$target_value])->fetchAll(PDO::FETCH_COLUMN);
      break;
  }

  // Jika ada user yang ditemukan, kirim notifikasi
  if (!empty($user_ids)) {
    // Siapkan query sekali saja untuk performa yang lebih baik
    $stmt = pdo()->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");

    foreach ($user_ids as $user_id) {
      $stmt->execute([(int)$user_id, $message, $link]);
    }
  }

  // Arahkan kembali ke halaman broadcast dengan pesan sukses
  $count = count($user_ids);
  redirect('?page=broadcast&ok=1&count=' . $count);
}


// ================================
// AWAL QUIZ POST
// ================================
function quiz_post()
{
  $sid = (int)($_POST['session_id'] ?? 0);
  $qid = (int)($_POST['question_id'] ?? 0);
  $cid = isset($_POST['choice_id']) ? (int)$_POST['choice_id'] : 0;
  $idx = (int)($_POST['index'] ?? 0);
  $isTimeout = (int)($_POST['timeout'] ?? 0); // <-- TAMBAHAN

  $exists = q("SELECT id FROM attempts WHERE session_id=? AND question_id=?", [$sid, $qid])->fetch();
  if ($exists) {
    redirect("?page=play&title_id=" . $_SESSION['quiz']['title_id'] . "&mode=" . $_SESSION['quiz']['mode'] . "&i=" . $idx);
  }

  if ($isTimeout) {
    // pilih 1 opsi yang salah agar FK choice_id valid
    $wrong = q("SELECT id FROM choices WHERE question_id=? AND is_correct=0 ORDER BY id LIMIT 1", [$qid])->fetch();
    if ($wrong) {
      $cid = (int)$wrong['id'];
    }
    $is_correct = 0;
  } else {
    $is_correct = (int)q("SELECT is_correct FROM choices WHERE id=?", [$cid])->fetch()['is_correct'];
  }

  q("INSERT INTO attempts (session_id,question_id,choice_id,is_correct,created_at) VALUES (?,?,?,?,?)", [$sid, $qid, $cid, $is_correct, now()]);
  q("UPDATE questions SET attempts=attempts+1, corrects=corrects+? WHERE id=?", [$is_correct, $qid]);

  if ($_SESSION['quiz']['mode'] === 'end') {
    // Mode "selesai dulu" tetap jalan seperti biasa
    $idx++;
    $extra = $isTimeout ? '&timeout=1' : '';
    redirect("?page=play&title_id=" . $_SESSION['quiz']['title_id'] . "&mode=end&i=" . $idx . $extra);
  }
}

// === AMBIL OAL ACAK ===
function create_session($title_id, $mode, $jumlah_soal = 10)
{
  $user_id = uid();
  $gid  = get_guest_id();
  $city = get_city_name();

  // 1) Buat sesi
  q(
    "INSERT INTO quiz_sessions (user_id,title_id,mode,guest_id,city,created_at) VALUES (?,?,?,?,?,?)",
    [$user_id, $title_id, $mode, $gid, $city, now()]
  );
  $sid = pdo()->lastInsertId();

  // 2) Ambil semua soal judul ini + metrik
  $rows = q("
    SELECT q.id, q.text,
           COALESCE(q.attempts,0) AS attempts,
           COALESCE(q.corrects,0) AS corrects
    FROM questions q
    WHERE q.title_id = ?
  ", [$title_id])->fetchAll(PDO::FETCH_ASSOC);

  if (!$rows) return $sid;

  // 3) Hitung skill & target kesulitan
  $cap = max(1, (int)$jumlah_soal); // Gunakan parameter, pastikan minimal 1
  $skill  = user_skill($user_id);          // 0.1–0.95 kira-kira
  $target = ($skill < 0.4) ? 0.15 : (($skill > 0.7) ? 0.85 : 0.50);

  // OPTIONAL: kurangi peluang soal yang baru saja kamu mainkan (3 sesi terakhir)
  $recentIds = q("
    SELECT m.question_id
    FROM quiz_sessions s
    JOIN quiz_session_questions m ON m.session_id = s.id
    WHERE s.user_id <=> ? AND s.title_id = ?
    ORDER BY s.id DESC
    LIMIT 300
  ", [$user_id, $title_id])->fetchAll(PDO::FETCH_COLUMN);
  $recentSet = array_flip($recentIds); // untuk lookup cepat

  // 4) Bangun bobot per soal
  $items = [];
  foreach ($rows as $r) {
    $att = (int)$r['attempts'];
    $cor = (int)$r['corrects'];
    $diff = ($att > 0) ? (1.0 - ($cor / max(1, $att))) : 0.50; // unseen = netral 0.5

    // Jarak ke target
    $dist = abs($diff - $target);

    // Bobot dasar: makin dekat target => makin besar
    // + sedikit smoothing agar tidak tak hingga
    $base = 1.0 / (0.0001 + $dist);

    // Eksplorasi: bonus untuk soal yang belum pernah dicoba (attempts=0)
    $explore = ($att === 0) ? 1.6 : 1.0;

    // Penalti untuk soal yang sangat sering muncul baru-baru ini
    $recent_penalty = isset($recentSet[$r['id']]) ? 0.5 : 1.0;

    $weight = $base * $explore * $recent_penalty;

    $items[] = [
      'id' => (int)$r['id'],
      'weight' => max(0.000001, $weight),
    ];
  }

  // 5) Weighted sampling tanpa penggantian (Efraimidis–Spirakis)
  //    key = U^(1/weight), ambil top-k berdasar key terbesar
  foreach ($items as &$it) {
    $u = mt_rand() / mt_getrandmax();
    if ($u <= 0) $u = 1e-9;
    $it['key'] = pow($u, 1.0 / $it['weight']);
  }
  unset($it);

  usort($items, fn($a, $b) => ($b['key'] <=> $a['key'])); // terbesar dulu
  $pick = array_slice($items, 0, min($cap, count($items)));

  // 6) Acak urutan tampil (bukan komposisi)
  shuffle($pick);

  // 7) Simpan ke mapping sesi
  $no = 1;
  foreach ($pick as $it) {
    q(
      "INSERT INTO quiz_session_questions (session_id,question_id,sort_no) VALUES (?,?,?)",
      [$sid, $it['id'], $no++]
    );
  }

  return $sid;
}



// ===============================================
// AWAL VIEW SUMMARY
// ===============================================

function view_summary($sid)
{
  $session = q("SELECT * FROM quiz_sessions WHERE id=?", [$sid])->fetch();
  // --- AMBIL DETAIL KUIS & NAMA PENGGUNA ---
  $quiz_details = q("
    SELECT qt.title, st.name AS subtheme_name, t.name AS theme_name
    FROM quiz_titles qt
    JOIN subthemes st ON qt.subtheme_id = st.id
    JOIN themes t ON st.theme_id = t.id
    WHERE qt.id = ?
", [$session['title_id']])->fetch();
  $user_name = $_SESSION['user']['name'] ?? 'Peserta';

  // 1. Ambil semua jawaban yang sudah dicatat (untuk menghitung yang benar)
  $att = q("SELECT a.*, q.text qtext,(SELECT text FROM choices WHERE id=a.choice_id) choice_text,(SELECT text FROM choices WHERE question_id=q.id AND is_correct=1 LIMIT 1) correct_text FROM attempts a JOIN questions q ON q.id=a.question_id WHERE a.session_id=? ORDER BY a.id", [$sid])->fetchAll();

  // 2. Hitung jumlah jawaban yang benar dari data di atas
  $correct = count(array_filter($att, fn($a) => (bool)$a['is_correct']));

  // 3. HITUNG TOTAL SOAL DARI SESI KUIS, BUKAN DARI JUMLAH JAWABAN
  $total_questions_in_quiz = (int)q("SELECT COUNT(*) FROM quiz_session_questions WHERE session_id=?", [$sid])->fetchColumn();

  // 4. Hitung skor dengan pembagi yang benar.
  $score = $total_questions_in_quiz > 0 ? round(($correct / $total_questions_in_quiz) * 100) : 0;

  // 5. Bagian selanjutnya tetap sama
  $exist = q("SELECT id FROM results WHERE session_id=?", [$sid])->fetch();

  $city = q("SELECT city FROM quiz_sessions WHERE id=?", [$sid])->fetchColumn();
  if (!$city || trim($city) === '') {
    $city = 'Anonim';
  }

  if (!$exist) q(
    "INSERT INTO results (session_id,user_id,title_id,score,city,created_at)
               VALUES (?,?,?,?,?,?)",
    [$sid, $session['user_id'], $session['title_id'], $score, $city, now()]
  );

  $myRes = q("SELECT id,score,user_id FROM results WHERE session_id=? LIMIT 1", [$sid])->fetch();

// ▼▼▼ AWAL BLOK KODE BARU UNTUK MENCATAT PENGUMPULAN TUGAS ▼▼▼
  // Cek apakah sesi ini dikerjakan oleh siswa yang login
  if ($session['user_id']) {
      $current_user_id = (int)$session['user_id'];
      $current_title_id = (int)$session['title_id'];

      // Cari tugas yang relevan untuk siswa ini dan kuis ini
      // Tugas harus aktif (belum melewati batas waktu jika ada)
      $relevant_assignment = q("
          SELECT a.id, a.id_kelas
          FROM assignments a
          JOIN class_members cm ON a.id_kelas = cm.id_kelas
          WHERE
              cm.id_pelajar = ?
              AND a.id_judul_soal = ?
              AND (a.batas_waktu IS NULL OR a.batas_waktu >= NOW())
          ORDER BY a.created_at DESC
          LIMIT 1
      ", [$current_user_id, $current_title_id])->fetch();

      // Jika ada tugas yang cocok dan hasil kuis sudah tersimpan...
      if ($relevant_assignment && isset($myRes['id'])) {
          $assignment_id = $relevant_assignment['id'];
          $result_id = $myRes['id'];

          // Simpan catatan ke assignment_submissions
          // Gunakan ON DUPLICATE KEY UPDATE untuk mencegah error jika siswa mencoba mengerjakan ulang
          q("
              INSERT INTO assignment_submissions (assignment_id, user_id, result_id, submitted_at)
              VALUES (?, ?, ?, NOW())
              ON DUPLICATE KEY UPDATE result_id = VALUES(result_id), submitted_at = VALUES(submitted_at)
          ", [$assignment_id, $current_user_id, $result_id]);
          

        
      }
  }
  // ▲▲▲ AKHIR DARI BLOK KODE BARU ▲▲▲
  
  // =================================================================
// ▼▼▼ AWAL LOGIKA PENGIRIMAN LAPORAN NILAI 100 KE GRUP WA ▼▼▼
// =================================================================
// KONDISI 1: Cek apakah ini adalah sesi tugas (assignment) dan pengguna yang login.
if (isset($_SESSION['quiz']['assignment_id']) && $session['user_id']) {
    
    // KONDISI 2: Cek apakah skor yang baru saja didapat adalah 100.
    if ($score == 100) {
        try {
            $assignment_id = (int)$_SESSION['quiz']['assignment_id'];

            // LANGKAH 3: Ambil data yang diperlukan dari database.
            // a) Ambil id_kelas dan judul_tugas dari tabel assignments.
            $assignment_details = q("SELECT id_kelas, judul_tugas FROM assignments WHERE id = ?", [$assignment_id])->fetch();

            if ($assignment_details) {
                $id_kelas = (int)$assignment_details['id_kelas'];
                $judul_tugas = $assignment_details['judul_tugas'];

                // f) Ambil nama_kelas dan link_wa dari tabel classes.
                $class_details = q("SELECT nama_kelas, wa_link FROM classes WHERE id = ?", [$id_kelas])->fetch();

                // Lanjutkan hanya jika ada link WA di data kelas.
                if ($class_details && !empty($class_details['wa_link'])) {
                    $nama_kelas = $class_details['nama_kelas'];
                    $wa_link_group = $class_details['wa_link'];

                    // e) Ambil daftar NAMA SEMUA USER yang sudah mendapat nilai 100 untuk tugas ini.
                    $perfect_scorers = q("
                        SELECT u.name
                        FROM assignment_submissions asub
                        JOIN results r ON asub.result_id = r.id
                        JOIN users u ON asub.user_id = u.id
                        WHERE asub.assignment_id = ? AND r.score = 100
                        ORDER BY asub.submitted_at DESC
                    ", [$assignment_id])->fetchAll(PDO::FETCH_COLUMN);

                    // Lanjutkan hanya jika ada minimal satu orang yang mendapat nilai 100.
                    if (!empty($perfect_scorers)) {
                        // Siapkan format pesan WA.
                        $today_date = date('d F Y');
                        $message_header = "🎉 Daftar Mahasiswa yang mendapat nilai 100.\n\nNama Tugas : $judul_tugas\nHari : $today_date\nKelas : $nama_kelas\n\n";
 
                        
                        
                        $student_list_lines = [];
                        foreach ($perfect_scorers as $index => $student_name) {
                            $student_list_lines[] = ($index + 1) . ". " . $student_name;
                        }
                        
                        // Ubah ini menjadi variabel sendiri
                        $student_list_string = implode("\n", $student_list_lines);

                        // ▼▼▼ TAMBAHKAN 3 BARIS INI (SAMA PERSIS) ▼▼▼
                        // 1. Buat link absolut ke tugas menggunakan base_url()
                        // Variabel $assignment_id sudah ada dari baris di atasnya
                        $assignment_link = base_url() . '?page=student_tasks'; // <--- PERUBAHAN UTAMA DI SINI
                        
                        // 2. Buat pesan ajakan (footer)
                        $footer_message = "\n\nBagi yang belum, yuk kerjakan tugasnya di sini:\n" . $assignment_link;
                        
                        // 3. Gabungkan semua bagian pesan
                        $full_message = $message_header . $student_list_string . $footer_message;
                        // ▲▲▲ AKHIR TAMBAHAN ▲▲▲
                        
                        wa_send($wa_link_group, $full_message);
                        
                    }
                }
            }
        } catch (Exception $e) {
            // Catat error jika terjadi masalah saat mengirim WA agar tidak merusak halaman.
            error_log('Gagal mengirim notifikasi WA tugas: ' . $e->getMessage());
        }
    }
}
// =================================================================
// ▲▲▲ AKHIR LOGIKA PENGIRIMAN LAPORAN NILAI 100 KE GRUP WA ▲▲▲
// =================================================================
  
  echo '<style>
        .summary-table tbody tr {
            border-bottom: 1px solid var(--bs-border-color-translucent);
        }
        .summary-table .question-text {
            font-weight: 600;
            color: var(--bs-emphasis-color);
            display: block;
            margin-bottom: 0.5rem;
        }
        .summary-table .answer-label {
            font-size: 0.8rem;
            color: var(--bs-secondary-color);
            display: block;
        }
        .summary-table .user-answer-incorrect {
            color: var(--bs-danger);
            text-decoration: line-through;
        }
        .summary-table .correct-answer {
            color: var(--bs-success);
        }
    </style>';

  $mode_text = '';
  if (isset($session['mode'])) {
    if ($session['mode'] === 'instant') {
      $mode_text = 'Mode Instan Review';
    } elseif ($session['mode'] === 'end') {
      $mode_text = 'Mode End Review';
    } elseif ($session['mode'] === 'exam') {
      $mode_text = 'Mode Ujian';
    }
  }

  echo '<div class="card"><div class="card-body">';
  echo '  <h4 class="card-title">Ringkasan Kuis ' . $mode_text . '</h4>';

  echo '  <p class="card-subtitle mb-2 text-muted">' . h($quiz_details['title']) . '</p>';

  echo '  <div class="text-center bg-light rounded p-3 my-4 summary-score-box">';
  echo '      <div class="score-label">SKOR AKHIR</div>';
  echo '      <div class="display-4 fw-bold text-primary">' . $score . '</div>';
  echo '      <div class="score-details">' . $correct . ' dari ' . $total_questions_in_quiz . ' soal benar</div>';
  echo '  </div>';

  // ▼▼▼ LOGIKA UTAMA: SEMBUNYIKAN REVIEW JIKA MODE UJIAN ▼▼▼
  if ($session['mode'] !== 'exam') {
    echo '<div class="table-responsive">';
    echo '  <table class="table table-borderless align-middle summary-table">';
    echo '      <thead><tr class="small text-muted"><th style="width: 5%;">#</th><th>Pertanyaan & Jawaban</th><th class="text-end">Status</th></tr></thead>';
    echo '      <tbody>';

    $i = 1;
    foreach ($att as $a) {
      $is_correct = (bool)$a['is_correct'];
      $status_badge = $is_correct ? '<span class="badge text-bg-success">Benar</span>' : '<span class="badge text-bg-danger">Salah</span>';

      echo '<tr>';
      echo '  <td class="text-muted">' . $i++ . '.</td>';
      echo '  <td>';
      echo '      <span class="question-text">' . h($a['qtext']) . '</span>';
      if ($is_correct) {
        echo '<div><span class="correct-answer">✅ ' . h($a['choice_text']) . '</span></div>';
      } else {
        echo '<div><span class="user-answer-incorrect">' . h($a['choice_text']) . '</span></div>';
        echo '<div><span class="correct-answer">👍 ' . h($a['correct_text']) . '</span></div>';
      }
      echo '  </td>';
      echo '  <td class="text-end">' . $status_badge . '</td>';
      echo '</tr>';
    }

    echo '      </tbody>';
    echo '  </table>';
    echo '</div>';
  } else {
    echo '<div class="alert alert-info text-center">Review jawaban tidak ditampilkan untuk Mode Ujian.</div>';
  }
  // ▲▲▲ AKHIR DARI LOGIKA KONDISIONAL ▲▲▲

  $inChallenge = !empty($_SESSION['current_challenge_token']);
  $ch = null;
  
  // PERBAIKAN: Jangan auto-create challenge, hanya ambil jika sudah ada
  if (!$inChallenge) {
    $existTok = q("SELECT token FROM challenges WHERE owner_session_id=? OR owner_result_id=? LIMIT 1", [$sid, (int)($myRes['id'] ?? 0)])->fetch();
    if ($existTok) {
      $ch = $existTok['token'];
    }
    // PERBAIKAN: Hapus create_challenge_token() dari sini
    // Challenge hanya akan dibuat saat user klik "Tantang Teman"
  }

  echo '<div class="d-flex flex-wrap gap-2">'; // Tambahkan flex-wrap untuk responsivitas

  if ($score == 100 && isset($_SESSION['user']) && $quiz_details) {
    $js_userName = h($_SESSION['user']['name'] ?? 'Peserta');
    $js_userEmail = h($_SESSION['user']['email'] ?? '');
    $js_quizTitle = h($quiz_details['title']);
    $js_subTheme = h($quiz_details['subtheme_name']);
    $js_quizMode = h($session['mode']);
    echo "<button class='btn btn-success kirim-laporan-btn' data-user-name='{$js_userName}' data-user-email='{$js_userEmail}' data-quiz-title='{$js_quizTitle}' data-sub-theme='{$js_subTheme}' data-quiz-mode='{$js_quizMode}'>Kirim Laporan</button>";
  }

  // Cek apakah ini adalah sesi dari sebuah tugas
$assignment_id = $_SESSION['quiz']['assignment_id'] ?? null;
if ($assignment_id) {
    // Jika ya, link "Coba Lagi" harus menggunakan assignment_id
    echo '<a class="btn btn-primary" href="?page=play&assignment_id=' . $assignment_id . '&restart=1">Coba Lagi</a>';
} else {
    // Jika tidak (kuis biasa), gunakan link lama
    echo '<a class="btn btn-primary" href="?page=play&title_id=' . $session['title_id'] . '&mode=' . $session['mode'] . '&restart=1">Coba Lagi</a>';
}

  if ($quiz_details && $score == 100) {
    $story_quiz_title = $quiz_details['title'] ?? 'Kuis';
    $story_sub_theme = $quiz_details['subtheme_name'] ?? '';
    $mode_selection_url = base_url() . '?page=play&title_id=' . $session['title_id'];
    $storyText = "Alhamdulillah, tuntas! 💯\nSaya baru saja menyelesaikan kuis \"{$story_quiz_title} - {$story_sub_theme}\" di QuizB.\n\nIngin mencoba juga? Klik di sini:\n{$mode_selection_url}\n\n#QuizB #BelajarAsyik";
    $encodedStoryText = urlencode($storyText);
    $wa_link = "https://wa.me/?text=" . $encodedStoryText;
    echo "<a href='{$wa_link}' target='_blank' class='btn btn-success'><svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='currentColor' class='bi bi-whatsapp' viewBox='0 0 16 16'><path d='M13.601 2.326A7.85 7.85 0 0 0 7.994 0C3.627 0 .068 3.558.064 7.926c0 1.399.366 2.76 1.057 3.965L0 16l4.204-1.102a7.9 7.9 0 0 0 3.79.965h.004c4.368 0 7.926-3.558 7.93-7.93A7.89 7.89 0 0 0 13.6 2.326zM7.994 14.521a6.57 6.57 0 0 1-3.356-.92l-.24-.144-2.494.654.666-2.433-.156-.251a6.56 6.56 0 0 1-1.007-3.505c0-3.626 2.957-6.584 6.591-6.584a6.56 6.56 0 0 1 4.66 1.931 6.56 6.56 0 0 1 1.928 4.66c-.004 3.639-2.961 6.592-6.592 6.592m3.615-4.934c-.197-.099-1.17-.578-1.353-.646-.182-.068-.315-.099-.445.099-.133.197-.513.646-.627.775-.114.133-.232.148-.43.05-.197-.1-.836-.308-1.592-.985-.59-.525-.985-1.175-1.103-1.372-.114-.198-.011-.304.088-.403.087-.088.197-.232.296-.346.1-.114.133-.198.198-.33.065-.134.034-.248-.015-.347-.05-.099-.445-1.076-.612-1.47-.16-.389-.323-.335-.445-.34-.114-.007-.247-.007-.38-.007a.73.73 0 0 0-.529.247c-.182.198-.691.677-.691 1.654s.71 1.916.81 2.049c.098.133 1.394 2.132 3.383 2.992.47.205.84.326 1.129.418.475.152.904.129 1.246.08.38-.058 1.171-.48 1.338-.943.164-.464.164-.86.114-.943-.049-.084-.182-.133-.38-.232z'/></svg> Story WA</a>";
  }

  if (!$inChallenge && uid()) {
    // PERBAIKAN: Tampilkan tombol untuk membuat challenge (bukan hanya jika sudah ada)
    echo '<button id="createChallenge" type="button" class="btn btn-primary" data-title-id="' . $session['title_id'] . '" data-session-id="' . $sid . '">Tantang Teman</button>';
  }

  echo '<a class="btn btn-outline-secondary" href="?page=titles&subtheme_id=' . h(q("SELECT subtheme_id FROM quiz_titles WHERE id=?", [$session['title_id']])->fetch()['subtheme_id']) . '">Pilih Judul Lain</a>';
  echo '</div>';

  if (!empty($_SESSION['current_challenge_token'])) {
    $tok = $_SESSION['current_challenge_token'];
    $meta = q("SELECT owner_result_id FROM challenges WHERE token=? LIMIT 1", [$tok])->fetch();
    if ($meta && !empty($meta['owner_result_id'])) {
      $owner = q("SELECT r.id, r.score, u.name FROM results r LEFT JOIN users u ON u.id = r.user_id WHERE r.id=? LIMIT 1", [(int)$meta['owner_result_id']])->fetch();
      $ownerName  = $owner['name'] ?? 'Pemilik Tantangan';
      $ownerScore = (int)($owner['score'] ?? 0);
      $myScore    = (int)($myRes['score'] ?? 0);
      $status = ($myScore > $ownerScore) ? 'Kamu MENANG 🎉' : (($myScore < $ownerScore) ? 'Kamu KALAH 😅' : 'SERI 🤝');
      echo '<hr>';
      echo '<div class="p-3 border rounded-3">';
      echo '<h5 class="mb-2">Perbandingan Skor</h5>';
      echo '<div class="row">';
      echo '  <div class="col-md-6"><div class="border rounded-3 p-2 mb-2"><div class="small text-muted">Pemilik Tantangan</div><div class="fs-5">' . h($ownerName) . '</div><div class="fw-bold">Skor: ' . $ownerScore . '</div></div></div>';
      echo '  <div class="col-md-6"><div class="border rounded-3 p-2 mb-2"><div class="small text-muted">Kamu</div><div class="fs-5">' . h($_SESSION['user']['name'] ?? 'Kamu') . '</div><div class="fw-bold">Skor: ' . $myScore . '</div></div></div>';
      echo '</div>';
      echo '<div class="alert ' . ($myScore > $ownerScore ? 'alert-success' : ($myScore < $ownerScore ? 'alert-warning' : 'alert-info')) . ' mt-2">' . $status . '</div>';
      echo '</div>';
    }

    if (!isset($myRes) || empty($myRes['id'])) {
      $myRes = q("SELECT id, score, user_id FROM results WHERE session_id=? LIMIT 1", [$sid])->fetch();
    }
    if (!empty($myRes['id'])) {
      q(
        "INSERT INTO challenge_runs (token, result_id, user_id, score, created_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE score=VALUES(score), created_at=VALUES(created_at)",
        [$tok, (int)$myRes['id'], ($myRes['user_id'] ?? (uid() ?: null)), (int)$myRes['score'], now()]
      );
    }
    unset($_SESSION['current_challenge_token']);
  }

  echo '</div></div>'; // Penutup card-body dan card

  if (!uid()) {
    global $CONFIG;
    echo '<div class="mt-4 p-4 border rounded bg-light text-center" style="max-width:500px;margin:30px auto;">';
    echo '<div style="font-size:1.1rem;font-weight:600;color:#222;margin-bottom:15px;">';
    echo 'Jangan biarkan hasil belajarmu hilang sia-sia.<br>Login dengan Google untuk menyimpannya dengan aman!';
    echo '</div>';
    echo '<div style="display:flex;justify-content:center;">';
    echo google_btn($CONFIG["GOOGLE_CLIENT_ID"]);
    echo '</div>';
    echo '</div>';
  }
}
// ===============================================
// AKHIR VIEW SUMMARY
// ===============================================

// ===============================================
// CHALLENGE LINK
// ===============================================

// Handler untuk membuat challenge ketika user klik "Tantang Teman"
function handle_create_challenge()
{
    header('Content-Type: application/json; charset=UTF-8');
    
    // Validasi input
    $title_id = (int)($_POST['title_id'] ?? 0);
    $session_id = (int)($_POST['session_id'] ?? 0);
    
    if ($title_id <= 0 || $session_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Data tidak valid']);
        exit;
    }
    
    // Cek apakah session ada dan milik user
    $session = q("SELECT user_id FROM quiz_sessions WHERE id = ?", [$session_id])->fetch();
    if (!$session) {
        echo json_encode(['success' => false, 'error' => 'Sesi tidak ditemukan']);
        exit;
    }
    
    // Cek apakah session milik user yang login
    if ($session['user_id'] != uid()) {
        echo json_encode(['success' => false, 'error' => 'Sesi bukan milik Anda']);
        exit;
    }
    
    // Cek apakah sudah ada challenge untuk session ini
    $existing = q("SELECT token FROM challenges WHERE owner_session_id = ?", [$session_id])->fetch();
    if ($existing) {
        echo json_encode(['success' => true, 'token' => $existing['token']]);
        exit;
    }
    
    // Buat challenge token baru
    try {
        $token = create_challenge_token($title_id, $session_id);
        echo json_encode(['success' => true, 'token' => $token]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Gagal membuat challenge: ' . $e->getMessage()]);
    }
    exit;
}
function create_challenge_token($title_id, $session_id)
{
  // Siapkan tabel komposisi tantangan
  pdo()->exec("CREATE TABLE IF NOT EXISTS challenge_items (
    token VARCHAR(32) NOT NULL,
    question_id INT NOT NULL,
    sort_no INT NOT NULL,
    PRIMARY KEY (token, question_id),
    FOREIGN KEY (token) REFERENCES challenges(token) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // Papan skor per token
  pdo()->exec("CREATE TABLE IF NOT EXISTS challenge_runs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  token VARCHAR(32) NOT NULL,
  result_id INT NOT NULL,
  user_id INT NULL,
  score INT NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uniq_token_result (token, result_id),
  INDEX idx_token_score (token, score),
  FOREIGN KEY (token) REFERENCES challenges(token) ON DELETE CASCADE,
  FOREIGN KEY (result_id) REFERENCES results(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");



  // Tambah kolom metadata (abaikan error jika sudah ada)
try {
    pdo()->exec("ALTER TABLE challenges ADD COLUMN owner_session_id INT NULL");
} catch (\PDOException $e) {
    // Tangkap khusus PDOException 1060 (Duplicate column name)
    if ($e->getCode() !== '42S21') {
        throw $e; // Lempar ulang jika bukan kesalahan "Column already exists"
    }
}
try {
    pdo()->exec("ALTER TABLE challenges ADD COLUMN owner_result_id INT NULL");
} catch (\PDOException $e) {
    // Tangkap khusus PDOException 1060 (Duplicate column name)
    if ($e->getCode() !== '42S21') {
        throw $e; // Lempar ulang jika bukan kesalahan "Column already exists"
    }
}

  // Buat token
  $token = bin2hex(random_bytes(8));
  q(
    "INSERT INTO challenges (token,title_id,user_id,created_at) VALUES (?,?,?,?)",
    [$token, $title_id, uid(), now()]
  );

  // Ambil komposisi soal dari session pemilik
  $rows = q("SELECT question_id, sort_no
             FROM quiz_session_questions
             WHERE session_id=?
             ORDER BY sort_no", [$session_id])->fetchAll();
  foreach ($rows as $r) {
    q(
      "INSERT INTO challenge_items (token, question_id, sort_no) VALUES (?,?,?)",
      [$token, (int)$r['question_id'], (int)$r['sort_no']]
    );
  }

  // Ambil result pemilik tantangan (harusnya sudah dibuat di view_summary)
  // --- OWNER META + PAPAN SKOR ---
  $ownerRes = q("SELECT id, score, user_id FROM results WHERE session_id=? LIMIT 1", [$session_id])->fetch();
  $ownerResId  = (int)($ownerRes['id'] ?? 0);
  $ownerScore  = (int)($ownerRes['score'] ?? 0);
  $ownerUserId = $ownerRes['user_id'] ?? (uid() ?: null);

  // Simpan metadata owner ke tabel challenges
  q(
    "UPDATE challenges SET owner_session_id=?, owner_result_id=? WHERE token=?",
    [$session_id, ($ownerResId ?: null), $token]
  );

  // Masukkan PEMILIK ke papan skor (hindari duplikat dengan UPSERT)
  if ($ownerResId) {
    q(
      "INSERT INTO challenge_runs (token, result_id, user_id, score, created_at)
     VALUES (?,?,?,?,?)
     ON DUPLICATE KEY UPDATE score=VALUES(score), created_at=VALUES(created_at)",
      [$token, $ownerResId, $ownerUserId, $ownerScore, now()]
    );
  }

  return $token;
}


/**
 * Menampilkan ringkasan kuis untuk review oleh admin/pemilik hasil.
 * Mengambil result_id dari URL untuk mendapatkan session_id.
 */
function view_review_summary()
{
    $result_id = (int)($_GET['result_id'] ?? 0);
    
    // 1. Ambil session_id, user_id, dan mode kuis dari results
    $result = q("
        SELECT r.session_id, r.user_id, s.mode 
        FROM results r
        JOIN quiz_sessions s ON r.session_id = s.id
        WHERE r.id = ?
    ", [$result_id])->fetch();

    if (!$result) {
        echo '<div class="alert alert-danger">Ringkasan hasil kuis tidak ditemukan.</div>';
        return;
    }

    $is_owner = ((int)$result['user_id'] === uid());
    $is_admin = is_admin();

    // Guard: Hanya admin atau pemilik hasil yang boleh melihat review
    if (!$is_admin && !$is_owner) {
        echo '<div class="alert alert-danger">Anda tidak memiliki izin untuk melihat hasil ini.</div>';
        return;
    }

    // 2. Set mode ke dalam session PHP sementara agar view_summary dapat menampilkan mode yang benar
    $original_mode = $_SESSION['quiz']['mode'] ?? null;
    $_SESSION['quiz']['mode'] = $result['mode'];
    
    // 3. Panggil fungsi view_summary() yang sudah ada
    echo '<h3>Review Hasil Kuis</h3>';
    
    // Panggil view_summary dengan session_id
    view_summary((int)$result['session_id']);

    // 4. Kembalikan mode semula (membersihkan session)
    if ($original_mode !== null) {
        $_SESSION['quiz']['mode'] = $original_mode;
    } else {
        unset($_SESSION['quiz']['mode']);
    }

}



// === VIEW CHALLENGE ===
function view_challenge()
{
  $token = $_GET['token'] ?? '';
  $row = q("SELECT * FROM challenges WHERE token=?", [$token])->fetch();
  if (!$row) {
    echo '<div class="alert alert-warning">Tantangan tidak ditemukan.</div>';
    return;
  }
  $title = q("SELECT * FROM quiz_titles WHERE id=?", [$row['title_id']])->fetch();
  echo '<div class="card"><div class="card-body">';
  echo '<h4>Tantangan Kuis</h4><p>Judul: <b>' . h($title['title']) . '</b></p>';
  echo '<a class="btn btn-primary" href="?action=start_challenge&token=' . h($row['token']) . '">Terima Tantangan</a>';

  // Tombol bagikan link tantangan (di halaman tantangan)
  echo '<button id="shareChallengeToken" type="button" class="btn btn-outline-secondary ms-2" data-url="' . h(base_url() . '?page=challenge&token=' . $row['token']) . '">Tantang Teman</button>';

  // JS share/copy untuk tombol di atas
  echo <<<JS
<script>
document.getElementById('shareChallengeToken')?.addEventListener('click', async function(e){
  e.preventDefault();
  const url = this.getAttribute('data-url') || window.location.href;
  const title = 'Tantangan Kuis QUIZB';
  const text  = 'Ayo coba kalahkan skor di tantangan ini:';

  try {
    if (navigator.share) {
      await navigator.share({ title, text, url });
    } else {
      await navigator.clipboard.writeText(url);
      alert('Link tantangan disalin ke clipboard:\\n' + url);
    }
  } catch (err) {
    console.error(err);
    prompt('Salin link tantangan secara manual:', url);
  }
});
</script>
JS;

  // === PAPAN SKOR (Top 10) — dengan medali & highlight "Anda" ===
  $leaders = q("SELECT 
                cr.score, 
                cr.created_at, 
                u.name,
                r.city AS city,
                COALESCE(u.id, r.user_id) AS uid
              FROM challenge_runs cr
              LEFT JOIN results r ON r.id = cr.result_id
              LEFT JOIN users  u ON u.id = r.user_id
              WHERE cr.token = ?
              ORDER BY cr.score DESC, cr.created_at ASC
              LIMIT 10", [$row['token']])->fetchAll();


  echo '<hr><h5 class="mb-2">Papan Skor Tantangan</h5>';
  if (!$leaders) {
    echo '<div class="text-muted">Belum ada peserta.</div>';
  } else {
    echo '<div class="table-responsive"><table class="table table-sm align-middle">';
    echo '<thead><tr><th width="72">Peringkat</th><th>Nama</th><th>Skor</th><th>Waktu</th><th width="90">Aksi</th></tr></thead><tbody>';
    $rank = 1;
    $me = uid();
    foreach ($leaders as $L) {
      // Medali untuk 1–3
      $medal = ($rank === 1 ? "🥇" : ($rank === 2 ? "🥈" : ($rank === 3 ? "🥉" : "#" . $rank)));
      // Highlight baris "Anda"
      $isMe = ($me && (int)$L['uid'] === (int)$me);
      $rowClass = $isMe ? ' class="table-success"' : '';
      $city = '';
      if (isset($L['city'])) {
        $city = trim((string)$L['city']);
      }
      if ($city === '') {
        $city = 'Anonim';
      }

      $nm = '';
      if (isset($L['name'])) {
        $nm = trim((string)$L['name']);
      }

      // Jika tidak ada nama user (tamu), tampilkan "Tamu – Kota"
      if ($nm === '') {
        $nm = 'Tamu – ' . $city;
      }

      if ($isMe) {
        $nm .= ' (Anda)';
      }

      echo '<tr' . $rowClass . '>'
        . '<td>' . $medal . '</td>'
        . '<td>' . h($nm) . '</td>'
        . '<td class="fw-bold">' . (int)$L['score'] . '</td>'
        . '<td class="text-muted small">' . h($L['created_at']) . '</td>'
        . '<td><button type="button" class="btn btn-sm btn-outline-secondary copy-link" '
        . 'data-url="' . h(base_url() . '?page=challenge&token=' . $row['token']) . '" '
        . 'title="Salin link tantangan">📋 Salin</button></td>'
        . '</tr>';
      $rank++;
    }
    echo '</tbody></table></div>';
  }
  // === END PAPAN SKOR ===


  echo <<<JS
<script>
(function(){
  function shareOrCopy(url){
    try {
      if (navigator.share) {
        // Share jika tersedia (HP modern)
        navigator.share({ title: 'Tantangan Kuis QUIZB', text: 'Ikuti tantangan ini:', url });
        return;
      }
    } catch(e){ /* lanjut ke copy */ }

    // Fallback: salin ke clipboard (desktop)
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(function(){
        alert('Link tantangan disalin:\\n' + url);
      }, function(){
        prompt('Salin link tantangan:', url);
      });
    } else {
      prompt('Salin link tantangan:', url);
    }
  }

  // Pasang handler ke semua tombol "📋 Salin"
  document.querySelectorAll('.copy-link').forEach(function(btn){
    btn.addEventListener('click', function(e){
      e.preventDefault();
      var url = this.getAttribute('data-url');
      if (url) shareOrCopy(url);
    });
  });
})();
</script>
JS;


  echo '</div></div>';

  // [CTA Login] — versi tampilan lebih jelas dan tombol di tengah
  if (!uid()) {
    global $CONFIG;
    echo '<div class="mt-4 p-4 border rounded bg-light text-center" style="max-width:500px;margin:30px auto;">';
    echo '<div style="font-size:1.1rem;font-weight:600;color:#222;margin-bottom:15px;">';
    echo 'Jangan biarkan hasil belajarmu hilang sia-sia.<br>Login dengan Google untuk menyimpannya dengan aman!';
    echo '</div>';
    echo '<div style="display:flex;justify-content:center;">';
    echo google_btn($CONFIG["GOOGLE_CLIENT_ID"]);
    echo '</div>';
    echo '</div>';
  }
}


// ===============================================
// VIEW: DAFTAR CHALLENGE (login-only)
// ===============================================
function view_challenges_list()
{
  if (!uid()) {
    global $CONFIG;
    echo '<div class="container py-5 text-center" style="max-width: 500px;">';
    echo '<p class="lead mb-4">Anda harus login untuk melihat Data Challenge. Silakan login dengan akun Google Anda.</p>';
    echo google_btn($CONFIG["GOOGLE_CLIENT_ID"]);
    echo '<div class="mt-4"><a href="./" class="btn btn-outline-secondary">Kembali ke Beranda</a></div>';
    echo '</div>';
    return;
  }

  // Query SQL tetap sama, tidak perlu diubah
    $sql = "
    SELECT
      qt.id AS title_id,
      qt.title,
      COUNT(DISTINCT cr.result_id) AS participant_count,
      (SELECT 
         c2.token 
       FROM challenges c2 
       WHERE c2.title_id = qt.id 
       ORDER BY c2.created_at DESC 
       LIMIT 1
      ) AS latest_token
    FROM quiz_titles qt
    JOIN challenges c ON c.title_id = qt.id
    JOIN challenge_runs cr ON cr.token = c.token
    WHERE qt.owner_user_id IS NULL
    GROUP BY qt.id, qt.title
    HAVING participant_count > 0
    ORDER BY participant_count DESC, qt.title ASC LIMIT 10
  ";

  $challenges = q($sql)->fetchAll(PDO::FETCH_ASSOC);

  echo '<div class="container" style="max-width:1000px;margin:20px auto">';
  //   echo '<h2 class="mb-3">Tantangan Paling Populer</h2>';
  //   echo '<p class="text-muted">Berikut adalah daftar kuis yang paling sering dimainkan dalam mode tantangan.</p>';


  if (!$challenges) {
    echo '<div class="alert alert-info mt-3">Belum ada tantangan yang dimainkan. Buat tantangan baru dan ajak temanmu untuk bermain!</div>';
    echo '</div>';
    return;
  }

  // Tampilan BARU menggunakan List Group yang lebih modern
  echo '<div class="list-group mt-3">';

  foreach ($challenges as $ch) {
    $judul = h($ch['title']);
    $peserta = (int)$ch['participant_count'];
    $token = h($ch['latest_token']);
    $url = '?page=challenge&token=' . $token;

    // ▼▼▼ BAGIAN INI YANG DIPERBAIKI ▼▼▼
    echo '
    <div class="list-group-item p-3">
      <div class="row g-2 align-items-center">
        
        <div class="col-md">
          <h5 class="mb-1">' . $judul . '</h5>
          <span class="badge bg-secondary fw-normal">' . $peserta . ' Peserta</span>
        </div>

        <div class="col-md-auto">
          <a href="' . $url . '" class="btn btn-primary w-100">
            Lihat & Tantang
          </a>
        </div>

      </div>
    </div>';
    // ▲▲▲ AKHIR DARI BAGIAN YANG DIPERBAIKI ▲▲▲
  }

  echo '</div>'; // penutup .list-group
  // container already closed earlier in some branches; avoid double-close
  // penutup .container (echo removed to avoid duplicate close)
}


/**
 * Menangani penghapusan percakapan dengan mencatatnya di tabel message_deletion.
 */
function handle_delete_conversation()
{
  if (!uid()) {
    http_response_code(403);
    exit('Login diperlukan.');
  }

  $current_user_id = uid();
  $other_user_id = (int)($_POST['other_user_id'] ?? 0);

  if ($other_user_id <= 0) {
    http_response_code(400);
    exit('ID pengguna tidak valid.');
  }

  // Gunakan INSERT... ON DUPLICATE KEY UPDATE untuk mencatat atau memperbarui waktu hapus.
  q(
    "INSERT INTO message_deletion (user_id, conversation_with_user_id, deleted_at) 
         VALUES (?, ?, NOW()) 
         ON DUPLICATE KEY UPDATE deleted_at = NOW()",
    [$current_user_id, $other_user_id]
  );

  // Redirect kembali ke halaman pesan
  redirect('?page=pesan');
}


// ===============================================
// PROFIL
// ===============================================
function view_profile()
{
  global $CONFIG;
  $u = $_SESSION['user'] ?? null;

  // --- TAMPILAN JIKA PENGGUNA BELUM LOGIN ---
  if (!uid()) {
    echo '<div class="container py-5 text-center" style="max-width: 500px;">';
    echo '  <h4 class="mb-3">Masuk untuk Lanjut</h4>';
    echo '  <p class="lead mb-4">Anda harus login untuk melihat halaman profil, riwayat kuis, dan mengakses pengaturan.</p>';
    echo google_btn($CONFIG["GOOGLE_CLIENT_ID"]);

    // Menu Informasi Umum untuk Pengguna yang belum login
    echo '  <div class="card mt-4 text-start">';
    echo '      <div class="card-header">Pengaturan & Informasi</div>';
    echo '      <div class="list-group list-group-flush">';
    echo '          <div class="list-group-item d-flex justify-content-between align-items-center">';
    echo '              <span>Ganti Mode Gelap/Terang</span>';
    echo '              <button id="themeToggle" class="btn btn-outline-secondary btn-sm" title="Ganti tema">🌓</button>'; // Tombol ganti tema
    echo '          </div>';
    echo '          <a class="list-group-item list-group-item-action" href="?page=about">Tentang QuizB</a>';
    echo '          <a class="list-group-item list-group-item-action" href="?page=privacy">Privacy Policy</a>';
    echo '          <a class="list-group-item list-group-item-action" href="?page=feedback">Feedback</a>';
    
    echo '      </div>';
    echo '  </div>';

    echo '</div>';
    return;
  }

  // --- TAMPILAN JIKA PENGGUNA SUDAH LOGIN ---
  $is_own_profile = !isset($_GET['user_id']) || (int)$_GET['user_id'] === $u['id'];
  $profile_user_id = $is_own_profile ? $u['id'] : (int)$_GET['user_id'];
  $profile_user = q("SELECT id, name, email, avatar FROM users WHERE id = ?", [$profile_user_id])->fetch();

  if (!$profile_user) {
    echo '<div class="alert alert-warning">Profil pengguna tidak ditemukan.</div>';
    return;
  }

  echo '<div class="mb-3 d-block d-md-flex align-items-center justify-content-between">';
  echo '  <div class="text-center text-md-start d-md-flex align-items-center gap-3">';
  echo '    <img style="width: 80px; height: 80px;" class="avatar mx-auto mb-2 mb-md-0" src="' . h($profile_user['avatar']) . '">';
  echo '    <div>';
  echo '      <div class="fw-bold fs-5">' . h($profile_user['name']) . '</div>';
  if ($is_own_profile || is_admin()) {
    echo '<div class="text-muted small">' . h($profile_user['email']) . '</div>';
  }
  echo '    </div>';
  echo '  </div>';

  if (!$is_own_profile) {
    echo '<div class="text-center text-md-end mt-3 mt-md-0"><a href="?page=pesan&with_id=' . $profile_user['id'] . '" class="btn btn-primary btn-sm">Kirim Pesan</a></div>';
  }
  echo '</div>';

  if ($is_own_profile) {
    echo '<div class="accordion mb-4" id="profileMenuAccordion">';
    echo '  <div class="accordion-item">';
    echo '    <h2 class="accordion-header" id="headingSettings">';
    echo '      <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseSettings" aria-expanded="false" aria-controls="collapseSettings">';
    echo '        Pengaturan & Informasi';
    echo '      </button>';
    echo '    </h2>';
    echo '    <div id="collapseSettings" class="accordion-collapse collapse" aria-labelledby="headingSettings" data-bs-parent="#profileMenuAccordion">';
    echo '      <div class="accordion-body p-0">';
    $feedback_link_label = is_admin() ? 'Kelola Umpan Balik' : 'Kirim Umpan Balik';
    echo '        <div class="list-group list-group-flush">';
    echo '          <a href="?page=setting" class="list-group-item list-group-item-action">Ubah Nama & Timer Kuis</a>';
    echo '          <div class="list-group-item d-flex justify-content-between align-items-center">';
    echo '            <span>Ganti Mode Gelap/Terang</span>';
    echo '            <button id="themeToggle" class="btn btn-outline-secondary btn-sm" title="Ganti tema">🌓</button>';
    echo '          </div>';
    echo '          <a href="?page=about" class="list-group-item list-group-item-action">Tentang QuizB</a>';
    echo '          <a href="?page=privacy" class="list-group-item list-group-item-action">Privacy Policy</a>';
    echo '          <a href="?page=feedback" class="list-group-item list-group-item-action">' . $feedback_link_label . '</a>';
    echo '          <a href="?action=logout" class="list-group-item list-group-item-action text-danger">Logout</a>';
    echo '        </div>';
    echo '      </div>';
    echo '    </div>';
    echo '  </div>';
    echo '  <div class="accordion-item">';
    echo '    <h2 class="accordion-header" id="headingNav">';
    echo '      <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseNav" aria-expanded="false" aria-controls="collapseNav">';
    echo '        Menu Navigasi';
    echo '      </button>';
    echo '    </h2>';
    echo '    <div id="collapseNav" class="accordion-collapse collapse" aria-labelledby="headingNav" data-bs-parent="#profileMenuAccordion">';
    echo '      <div class="accordion-body p-0">';
    echo '        <div class="list-group list-group-flush">';
    echo '          <a class="list-group-item list-group-item-action" href="?page=themes">Pencarian</a>';
    echo '          <a class="list-group-item list-group-item-action" href="?page=explore">Jelajah Tema</a>';

    if (is_admin()) {
      echo '    <a class="list-group-item list-group-item-action" href="?page=difficulty">Peta Kesulitan</a>';
      echo '    <a class="list-group-item list-group-item-action" href="?page=challenges">Data Challenge</a>';
      echo '    <a class="list-group-item list-group-item-action" href="?page=admin">Backend</a>';
      echo '    <a class="list-group-item list-group-item-action" href="?page=kelola_user">Kelola User</a>';
      echo '    <a class="list-group-item list-group-item-action" href="?page=qmanage">Kelola Soal (CRUD)</a>';

      echo '    <a class="list-group-item list-group-item-action" href="?page=crud">CRUD Bank Soal</a>';
} else {
      $user_role = $_SESSION['user']['role'] ?? '';
      if ($user_role === 'pengajar') {
        // Link untuk Pengajar (tidak berubah)
        echo '<a class="list-group-item list-group-item-action" href="?page=kelola_institusi">Kelola Institusi & Kelas</a>';
      } elseif ($user_role === 'pelajar') {
        // ▼▼▼ TAUTAN BARU UNTUK SISWA ▼▼▼
        echo '<a class="list-group-item list-group-item-action" href="?page=kelola_kelas">Gabung ke Kelas Saya</a>';
      }
      echo '<a class="list-group-item list-group-item-action" href="?page=challenges">Data Challenge</a>';
    }
    
    echo '        </div>';
    echo '      </div>';
    echo '    </div>';
    echo '  </div>';
    echo '</div>';
  }

  if ($is_own_profile && is_admin()) {
    echo '<div class="card mt-4"><div class="card-body">';
   $recent_admin_view = q("SELECT 
            r.created_at,
            r.score,
            COALESCE(u.name, CONCAT('Tamu – ', COALESCE(r.city,'Anonim'))) AS display_name,
            u.avatar,
            u.id AS user_id,
            qt.title AS quiz_title
          FROM results r
          LEFT JOIN users u ON u.id = r.user_id
          LEFT JOIN quiz_titles qt ON qt.id = r.title_id
          WHERE r.score = 100
          ORDER BY r.created_at DESC
          LIMIT 20000")->fetchAll();
    $participantsCount_admin = (is_array($recent_admin_view) || $recent_admin_view instanceof Countable) ? count($recent_admin_view) : 0;
    echo   '<div class="d-flex align-items-center justify-content-between">';
    echo     '<h5 class="mb-2">Riwayat Peserta Terbaru (Admin View) <span class="badge bg-secondary" id="admin-tbl-participants-count">' . $participantsCount_admin . '</span></h5>';
    echo   '</div>';
    echo   '<input id="admin-filter-participants" type="text" class="form-control form-control-sm mb-2" placeholder="Cari: nama / judul / waktu / skor / kota">';
    if (!$recent_admin_view) {
      echo '<div class="text-muted small">Belum ada peserta.</div>';
    } else {
      echo '<div class="table-responsive">';
      echo   '<table class="table table-sm align-middle" id="admin-tbl-participants">';
      echo     '<thead><tr>';
      echo       '<th style="white-space:nowrap;">Nama / Tamu</th>';
      echo       '<th>Judul</th>';
      echo       '<th style="white-space:nowrap;">Waktu</th>';
      echo       '<th style="text-align:right; white-space:nowrap;">Skor</th>';
      echo     '</tr></thead>';
      echo     '<tbody>';
      foreach ($recent_admin_view as $r) {
        $avatar = (!empty($r['avatar'])) ? $r['avatar'] : 'https://www.gravatar.com/avatar/?d=mp&s=32';
        $display = $r['display_name'];
        $judul   = $r['quiz_title'] ?? '—';
        $waktu   = $r['created_at'];
        $skor    = (string)$r['score'];
        $search  = strtolower($display . ' ' . $judul . ' ' . $waktu . ' ' . $skor);
        echo '<tr data-search="' . h($search) . '">';
        echo   '<td>';
        if (!empty($r['user_id'])) {
          echo '<a href="?page=profile&user_id=' . (int)$r['user_id'] . '" class="text-decoration-none text-body">';
          echo   '<div class="d-flex align-items-center">';
          echo     '<img src="' . h($avatar) . '" class="rounded-circle me-2" width="24" height="24" alt="">';
          echo     '<span>' . h($display) . '</span>';
          echo   '</div>';
          echo '</a>';
        } else {
          echo   '<div class="d-flex align-items-center">';
          echo     '<img src="' . h($avatar) . '" class="rounded-circle me-2" width="24" height="24" alt="">';
          echo     '<span>' . h($display) . '</span>';
          echo   '</div>';
        }
        echo   '</td>';
        echo   '<td>' . h($judul) . '</td>';
        echo   '<td>' . h($waktu) . '</td>';
        echo   '<td style="text-align:right; font-weight:600;">' . h($skor) . '</td>';
        echo '</tr>';
      }
      echo     '</tbody>';
      echo   '</table>';
      echo '</div>';
    }
    echo '<div class="d-flex align-items-center justify-content-between mt-2" id="admin-pager-participants">';
    echo '  <button class="btn btn-sm btn-outline-secondary" data-page="prev">◀︎</button>';
    echo '  <div class="small text-muted">Halaman <span data-role="page">1</span>/<span data-role="pages">1</span></div>';
    echo '  <button class="btn btn-sm btn-outline-secondary" data-page="next">▶︎</button>';
    echo '</div>';
    echo '</div></div>';
    echo "<script>
            setTimeout(function() {
                setupTable({
                    inputId: 'admin-filter-participants',
                    tableId: 'admin-tbl-participants',
                    pagerId: 'admin-pager-participants',
                    countBadgeId: 'admin-tbl-participants-count',
                    pageSize: 10
                });
            }, 0);
        </script>";
  } else {
    // ▼▼▼ PERUBAHAN QUERY SQL DI SINI ▼▼▼
    $rows = q("
            SELECT 
                r.id AS result_id, -- <--- TAMBAHKAN INI
                r.score, 
                r.created_at, 
                qt.id AS title_id, 
                qt.title, 
                st.name AS subtheme_name,
                s.mode -- Tambahkan kolom mode dari tabel sesi
            FROM results r 
            JOIN quiz_titles qt ON qt.id = r.title_id 
            JOIN subthemes st ON st.id = qt.subtheme_id 
            JOIN quiz_sessions s ON s.id = r.session_id -- JOIN tabel sesi
            WHERE r.user_id = ? 
            ORDER BY r.created_at DESC
        ", [$profile_user_id])->fetchAll();
    // ▲▲▲ AKHIR PERUBAHAN QUERY ▲▲▲

    if (!$rows) {
      echo '<div class="alert alert-secondary mt-4">Pengguna ini belum memiliki riwayat kuis.</div>';
    } else {
      echo '<h5 class="mt-4">Riwayat Kuis</h5>';
      echo '<input id="profile-search" type="text" class="form-control form-control-sm mb-2" placeholder="Cari riwayat kuis...">';
      echo '<table class="table table-sm align-middle" id="profile-table"><thead><tr><th>Waktu</th><th>Judul</th>';

      if ($is_own_profile || is_admin()) {
        echo '<th>Skor</th>';
      }
      if ($is_own_profile) {
        echo '<th>Aksi</th>';
      }
      echo '</tr></thead><tbody>';

     foreach ($rows as $r) {
        $review_url = '?page=review&result_id=' . (int)$r['result_id'];
        
        // Tautkan seluruh baris ke halaman review, tetapi hanya jika Admin yang melihat.
        // Jika bukan admin, biarkan barisnya biasa, kecuali untuk kolom Aksi.
        if (is_admin()) {
            echo '<tr onclick="window.location.href=\'' . $review_url . '\'" style="cursor:pointer;">';
        } else {
            echo '<tr>';
        }

        echo '<td>' . h($r['created_at']) . '</td><td>' . h($r['title']) . '</td>';

        if ($is_own_profile || is_admin()) {
          echo '<td>' . (int)$r['score'] . '</td>';
        }

        if ($is_own_profile) {
          echo '<td>';
          
          // Tampilkan tombol Review HANYA jika Admin
          if (is_admin()) {
              echo '<a href="' . $review_url . '" class="btn btn-sm btn-info w-100 mb-1">Review</a>';
          }
          
          if ((int)$r['score'] === 100) {
            // ... (Tombol Laporan dan Story WA)
            $js_userName = h($u['name']);
            $js_userEmail = h($u['email']);
            $js_quizTitle = h($r['title']);
            $js_subTheme = h($r['subtheme_name']);
            $js_quizMode = h($r['mode']); 

            echo '<div class="d-flex flex-column gap-1">';
            echo "<button class='btn btn-sm btn-success kirim-laporan-btn' 
                                data-user-name='{$js_userName}' 
                                data-user-email='{$js_userEmail}' 
                                data-quiz-title='{$js_quizTitle}' 
                                data-sub-theme='{$js_subTheme}'
                                data-quiz-mode='{$js_quizMode}'>Laporan</button>"; 
            
            $story_quiz_title = $r['title'];
            $story_sub_theme = $r['subtheme_name'];
            $mode_selection_url = base_url() . '?page=play&title_id=' . $r['title_id'];
            $storyText = "Alhamdulillah, tuntas! 💯\nSaya baru saja menyelesaikan kuis \"{$story_quiz_title} - {$story_sub_theme}\" di QuizB.\n\nIngin mencoba juga? Klik di sini:\n{$mode_selection_url}\n\n#QuizB #BelajarAsyik";
            $encodedStoryText = urlencode($storyText);
            $wa_link = "https://wa.me/?text=" . $encodedStoryText;
            echo "<a href='{$wa_link}' target='_blank' class='btn btn-sm btn-info'><svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='currentColor' class='bi bi-whatsapp' viewBox='0 0 16 16'><path d='M13.601 2.326A7.85 7.85 0 0 0 7.994 0C3.627 0 .068 3.558.064 7.926c0 1.399.366 2.76 1.057 3.965L0 16l4.204-1.102a7.9 7.9 0 0 0 3.79.965h.004c4.368 0 7.926-3.558 7.93-7.93A7.89 7.89 0 0 0 13.6 2.326zM7.994 14.521a6.57 6.57 0 0 1-3.356-.92l-.24-.144-2.494.654.666-2.433-.156-.251a6.56 6.56 0 0 1-1.007-3.505c0-3.626 2.957-6.584 6.591-6.584a6.56 6.56 0 0 1 4.66 1.931 6.56 6.56 0 0 1 1.928 4.66c-.004 3.639-2.961 6.592-6.592 6.592m3.615-4.934c-.197-.099-1.17-.578-1.353-.646-.182-.068-.315-.099-.445.099-.133.197-.513.646-.627.775-.114.133-.232.148-.43.05-.197-.1-.836-.308-1.592-.985-.59-.525-.985-1.175-1.103-1.372-.114-.198-.011-.304.088-.403.087-.088.197-.232.296-.346.1-.114.133-.198.198-.33.065-.134.034-.248-.015-.347-.05-.099-.445-1.076-.612-1.47-.16-.389-.323-.335-.445-.34-.114-.007-.247-.007-.38-.007a.73.73 0 0 0-.529.247c-.182.198-.691.677-.691 1.654s.71 1.916.81 2.049c.098.133 1.394 2.132 3.383 2.992.47.205.84.326 1.129.418.475.152.904.129 1.246.08.38-.058 1.171-.48 1.338-.943.164-.464.164-.86.114-.943-.049-.084-.182-.133-.38-.232z'/></svg> Story</a>";
            echo '</div>';
          }
          echo '</td>';
        }
        echo '</tr>';
      }

      echo '</tbody></table>';
      echo '<div class="d-flex align-items-center justify-content-between mt-2" id="profile-pager"><button class="btn btn-sm btn-outline-secondary" data-page="prev">◀︎</button><div class="small text-muted">Halaman <span data-role="page">1</span>/<span data-role="pages">1</span></div><button class="btn btn-sm btn-outline-secondary" data-page="next">▶︎</button></div>';
      echo "<script>
                setTimeout(function() {
                    setupTable({ inputId: 'profile-search', tableId: 'profile-table', pagerId: 'profile-pager', pageSize: 10 });
                }, 0);
            </script>";
    }
  }
}


// ===============================================
// AKHIR VIEW PROFILE
// ===============================================

/**
 * Menangani permintaan penghapusan pesan.
 */
function handle_delete_message()
{
  if (!uid()) {
    http_response_code(403);
    exit('Login required.');
  }

  $message_id = (int)($_POST['message_id'] ?? 0);
  $delete_type = $_POST['delete_type'] ?? '';
  $current_user_id = uid();

  $msg = q("SELECT id, sender_id, receiver_id FROM messages WHERE id = ?", [$message_id])->fetch();

  if (!$msg) {
    http_response_code(404);
    exit('Message not found.');
  }

  // Otorisasi: pastikan user adalah pengirim atau penerima
  $is_sender = ($msg['sender_id'] == $current_user_id);
  $is_receiver = ($msg['receiver_id'] == $current_user_id);

  if (!$is_sender && !$is_receiver) {
    http_response_code(403);
    exit('You cannot delete this message.');
  }

  if ($delete_type === 'for_everyone') {
    // Hanya pengirim yang bisa hapus untuk semua
    if (!$is_sender) {
      http_response_code(403);
      exit('Only the sender can delete for everyone.');
    }
    // Hapus permanen
    q("DELETE FROM messages WHERE id = ?", [$message_id]);
  } elseif ($delete_type === 'for_me') {
    if ($is_sender) {
      q("UPDATE messages SET deleted_by_sender = 1 WHERE id = ?", [$message_id]);
    }
    if ($is_receiver) {
      q("UPDATE messages SET deleted_by_receiver = 1 WHERE id = ?", [$message_id]);
    }
  }

  // Redirect kembali ke percakapan
  $other_user_id = $is_sender ? $msg['receiver_id'] : $msg['sender_id'];
  redirect('?page=pesan&with_id=' . $other_user_id);
}

/**
 * API untuk mencari pengguna berdasarkan angka (NIM) di dalam nama.
 */
function api_search_users()
{
  header('Content-Type: application/json; charset=UTF-8');
  if (!uid()) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
  }

  $query = trim($_GET['query'] ?? '');
  // Pastikan query hanya berisi angka
  if (!ctype_digit($query) || $query === '') {
    echo json_encode([]); // Kembalikan array kosong jika bukan angka
    exit;
  }

  // Cari angka di dalam kolom 'name' dan jangan tampilkan diri sendiri
  $users = q(
    "SELECT id, name, avatar FROM users WHERE name LIKE ? AND id != ? LIMIT 10",
    ['%' . $query . '%', uid()]
  )->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode($users);
  exit;
}

// ================
// AWAL VIEW PESAN
// ================

// === RENDER MESSAGE ===
/**
 * Helper untuk mencetak HTML gelembung pesan
 */
function render_message_bubble($msg_id, $msg_text, $msg_time_str, $is_my_message, $avatar_src)
{
  $bubble_class = $is_my_message ? 'bg-primary-subtle text-emphasis' : 'bg-body-secondary';
  $flex_direction = $is_my_message ? 'flex-row-reverse' : 'flex-row';
  $margin_class = $is_my_message ? 'me-2' : 'ms-2';
  $time_formatted = date('H:i', strtotime($msg_time_str));
  $is_sender_attr = $is_my_message ? '1' : '0';

  // Gunakan output buffering untuk menangkap HTML
  ob_start();
?>
  <div class="d-flex align-items-start mb-2 group <?php echo $flex_direction; ?>">
    <img src="<?php echo h($avatar_src); ?>" class="avatar" style="width: 32px; height: 32px; object-fit: cover; margin-top: 5px;">
    <div class="p-2 rounded <?php echo $bubble_class; ?> <?php echo $margin_class; ?>" style="max-width: 70%;">
      <?php echo htmlspecialchars($msg_text); ?>
      <div class="text-muted" style="font-size: 0.75rem; text-align: right;"><?php echo $time_formatted; ?></div>
    </div>
    <div class="dropdown align-self-center <?php echo $is_my_message ? 'me-1' : 'ms-1'; ?>">
      <button class="btn btn-sm btn-light py-0 px-1" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="--bs-btn-padding-y: 0; --bs-btn-padding-x: .25rem; font-size: .75rem; line-height: 1;">⋮</button>
      <ul class="dropdown-menu">
        <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#deleteMessageModal" data-message-id="<?php echo $msg_id; ?>" data-is-sender="<?php echo $is_sender_attr; ?>">Hapus</a></li>
      </ul>
    </div>
  </div>
<?php
  return ob_get_clean();
}






function view_pesan()
{
  // 1. Cek jika pengguna sudah login
  if (!uid()) {
    global $CONFIG;
    echo '<div class="container py-5 text-center" style="max-width: 500px;">';
    echo '<p class="lead mb-4">Anda harus login untuk mengirim dan menerima pesan. Silakan login dengan akun Google Anda.</p>';
    echo google_btn($CONFIG["GOOGLE_CLIENT_ID"]);
    echo '<div class="mt-4"><a href="./" class="btn btn-outline-secondary">Kembali ke Beranda</a></div>';
    echo '</div>';
    return;
  }

  $current_user_id = uid();

  // 2. HTML untuk Modal Konfirmasi Hapus PERCAKAPAN
  echo <<<HTML
    <div class="modal fade" id="deleteConversationModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="deleteModalTitle">Hapus Percakapan</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p>Anda yakin ingin menghapus percakapan dengan <strong id="deleteModalUserName"></strong> dari kotak masuk? <br><small class="text-muted">Tindakan ini tidak akan menghapus pesan di sisi lawan bicara.</small></p>
          </div>
          <div class="modal-footer">
            <form id="deleteConversationForm" method="post" action="?action=handle_delete_conversation">
                <input type="hidden" name="other_user_id" id="userIdToDelete">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-danger">Ya, Hapus</button>
            </form>
          </div>
        </div>
      </div>
    </div>
HTML;

  // 3. HTML untuk Modal Hapus PESAN INDIVIDUAL
  echo <<<HTML
    <div class="modal fade" id="deleteMessageModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Hapus Pesan</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p>Anda yakin ingin menghapus pesan ini?</p>
            <form id="deleteMessageForm" method="post" action="?action=delete_message">
                <input type="hidden" name="message_id" id="messageIdToDelete">
                <input type="hidden" name="delete_type" id="deleteType">
            </form>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
            <button type="button" id="btnDeleteForMe" class="btn btn-warning">Hapus untuk Saya</button>
            <button type="button" id="btnDeleteForEveryone" class="btn btn-danger">Hapus untuk Semua</button>
          </div>
        </div>
      </div>
    </div>
HTML;


  // 4. Logika Tampilan: Percakapan Spesifik atau Kotak Masuk
  if (isset($_GET['with_id'])) {
    // ==========================================================
    // TAMPILAN PERCAKAPAN SPESIFIK (CHAT ROOM) - (Tidak ada perubahan di blok ini)
    // ==========================================================
    $other_user_id = (int)$_GET['with_id'];
    $other_user = q("SELECT id, name, avatar FROM users WHERE id = ?", [$other_user_id])->fetch();

    if (!$other_user) {
      echo '<div class="alert alert-danger">Pengguna tidak ditemukan.</div>';
      return;
    }

    // Tandai pesan sebagai sudah dibaca
    q("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ?", [$other_user_id, $current_user_id]);

    // Header Chat
    echo '<div class="d-flex align-items-center mb-3 sticky-top bg-body py-2 border-bottom">';
    echo '  <a href="?page=pesan" class="btn btn-outline-secondary btn-sm me-3" title="Kembali ke Kotak Masuk">&laquo;</a>';
    echo '  <img src="' . h($other_user['avatar']) . '" class="avatar me-2" style="width: 40px; height: 40px;">';
    echo '  <h5 class="mb-0">' . h($other_user['name']) . '</h5>';
    echo '</div>';

    $messages = q(
      "SELECT * FROM messages
             WHERE
               ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
               AND NOT (sender_id = ? AND deleted_by_sender = 1)
               AND NOT (receiver_id = ? AND deleted_by_receiver = 1)
             ORDER BY created_at ASC",
      [$current_user_id, $other_user_id, $other_user_id, $current_user_id, $current_user_id, $current_user_id]
    )->fetchAll();

    // Container pesan
    echo '<div class="mb-3" style="height: 50vh; overflow-y: auto; padding: 10px;" id="message-container">';

    $my_avatar = $_SESSION['user']['avatar'] ?? '';
    $other_avatar = $other_user['avatar'] ?? '';

    if (!$messages) {
      echo '<p class="text-center text-muted mt-5" id="empty-chat-placeholder">Mulai percakapan pertama Anda!</p>';
    } else {
      foreach ($messages as $msg) {
        $is_my_message = $msg['sender_id'] == $current_user_id;
        $avatar_src = $is_my_message ? $my_avatar : $other_avatar;

        echo render_message_bubble(
          $msg['id'],
          $msg['message_text'],
          $msg['created_at'],
          $is_my_message,
          $avatar_src
        );
      }
    }



    echo '</div>'; // Akhir message-container

    // Form Kirim Pesan
    echo '<form method="post" action="?action=send_message" class="mt-auto" id="chat-form">';
    echo   '<input type="hidden" name="receiver_id" value="' . $other_user_id . '">';
    echo   '<div class="d-flex align-items-end gap-2">';
    echo     '<textarea name="message_text" class="form-control" rows="1" placeholder="Ketik pesan Anda..." required style="resize: none; overflow-y: hidden;"></textarea>';
    echo     '<button type="submit" class="btn btn-primary" style="flex-shrink: 0;" title="Kirim">';
    echo       '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-send-fill" viewBox="0 0 16 16" style="display: block; margin: auto;"><path d="M15.964.686a.5.5 0 0 0-.65-.65L.767 5.855H.766l-.452.18a.5.5 0 0 0-.082.887l.41.26.001.002 4.995 3.178 3.178 4.995.002.002.26.41a.5.5 0 0 0 .886-.083l6-15Zm-1.833 1.89L6.637 10.07l-.215-.338a.5.5 0 0 0-.154-.154l-.338-.215 7.494-7.494 1.178-.471-.47 1.178Z"/></svg>';
    echo     '</button>';
    echo   '</div>';
    echo '</form>';
  } else {
    // ==========================================================
    // TAMPILAN KOTAK MASUK (INBOX) - BLOK INI YANG DIPERBAIKI
    // ==========================================================
    // ▼▼▼ CSS BARU UNTUK TOMBOL PLUS MELAYANG ▼▼▼
    echo '<style>
        .fab-plus {
            position: fixed;
            /* 70px (nav mobile) + 15px (jarak) = 85px */
            bottom: 85px; 
            right: 20px;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background-color: var(--bs-primary);
            color: white;
            border: none;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            font-size: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1035; /* Di atas konten, di bawah modal */
            text-decoration: none;
            transition: background-color 0.2s;
        }
        .fab-plus:hover {
            background-color: #0b5ed7; /* Warna primer yg sedikit lebih gelap (light mode) */
            color: white;
        }
        [data-bs-theme="dark"] .fab-plus:hover {
             background-color: #0a58ca; /* Warna primer yg sedikit lebih gelap (dark mode) */
        }
        
/* Pengaturan posisi desktop: Tetap melayang, tapi sejajar dengan container */
        
        /* md (tablet) - Container 720px */
        @media (min-width: 768px) {
            .fab-plus {
                bottom: 170px; /* Lebih rendah di desktop */
                /* (100vw - 720px) / 2 = margin. lalu + 20px padding dari tepi container */
                right: calc((100vw - 720px) / 2 + 20px);
            }
        }
        
        /* lg (desktop) - Container 960px */
        @media (min-width: 992px) {
            .fab-plus {
                right: calc((100vw - 960px) / 2 + 20px);
            }
        }
        
        /* xl (desktop besar) - Container 1140px */
        @media (min-width: 1200px) {
            .fab-plus {
                right: calc((100vw - 1140px) / 2 + 20px);
            }
        }
        
        /* xxl (desktop sangat besar) - Container 1320px */
        @media (min-width: 1400px) {
            .fab-plus {
                right: calc((100vw - 1320px) / 2 + 20px);
            }
        }
    </style>';
    // ▲▲▲ AKHIR DARI CSS BARU ▲▲▲

    // (Fitur pencarian kontak tidak berubah)
    echo '<div class="collapse mb-3" id="newMessageCollapse">
            <div class="card card-body">
              <h5>Cari Kontak</h5>
              <input type="text" id="userSearchInput" class="form-control" placeholder="Ketik kontak..." inputmode="numeric" pattern="\d*">
              <div id="userSearchResults" class="list-group mt-2"></div>
            </div>
          </div>';

    // ▼▼▼ AWAL BLOK PAGINASI (YANG DIPERBAIKI) ▼▼▼
    $limit = 10;
    $page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
    $offset = ($page - 1) * $limit;

    // --- PERBAIKAN DI SINI ---
    // Subquery sekarang HANYA mencari pesan milik user yang login
    $latest_message_ids_subquery = "
        SELECT MAX(m.id)
        FROM messages m
        WHERE m.sender_id = ? OR m.receiver_id = ?
        GROUP BY IF(m.sender_id = ?, CONCAT(m.receiver_id, '-', m.sender_id), CONCAT(m.sender_id, '-', m.receiver_id))
    ";

    // 1. Dapatkan TOTAL percakapan untuk paginasi
    $total_conversations_sql = "
        SELECT COUNT(m.id)
        FROM messages m
        JOIN users u ON u.id = IF(m.sender_id = ?, m.receiver_id, m.sender_id)
        LEFT JOIN message_deletion md ON md.user_id = ? AND md.conversation_with_user_id = u.id
        WHERE 
            m.id IN ($latest_message_ids_subquery)
            AND (md.deleted_at IS NULL OR m.created_at > md.deleted_at)
    ";

    // --- PERBAIKAN DI SINI ---
    // Sekarang kita kirim 5 parameter: 2 untuk query utama, 3 untuk subquery
    $total_conversations = (int) q(
      $total_conversations_sql,
      [
        $current_user_id, // 1. for join users (IF)
        $current_user_id, // 2. for join message_deletion
        // --- Parameter untuk subquery ---
        $current_user_id, // 3. for subquery (WHERE m.sender_id)
        $current_user_id, // 4. for subquery (OR m.receiver_id)
        $current_user_id  // 5. for subquery (GROUP BY IF)
      ]
    )->fetchColumn();

    $total_pages = ceil($total_conversations / $limit);

    // 2. Dapatkan percakapan untuk HALAMAN SAAT INI
    $conversations_sql = "
        SELECT
            u.id, u.name, u.avatar, m.message_text, m.created_at, m.is_read, m.sender_id as last_sender_id,
            (
                SELECT COUNT(*) FROM messages
                WHERE sender_id = u.id AND receiver_id = ? AND is_read = 0
            ) as unread_count
        FROM messages m
        JOIN users u ON u.id = IF(m.sender_id = ?, m.receiver_id, m.sender_id)
        LEFT JOIN message_deletion md ON md.user_id = ? AND md.conversation_with_user_id = u.id
        WHERE 
            m.id IN ($latest_message_ids_subquery)
            AND (md.deleted_at IS NULL OR m.created_at > md.deleted_at)
        ORDER BY m.created_at DESC
        LIMIT $limit OFFSET $offset
    ";

    // --- PERBAIKAN DI SINI ---
    // Sekarang kita kirim 6 parameter: 3 untuk query utama, 3 untuk subquery
    $conversations = q($conversations_sql, [
      $current_user_id, // 1. for unread_count
      $current_user_id, // 2. for join users (IF)
      $current_user_id, // 3. for join message_deletion
      // --- Parameter untuk subquery ---
      $current_user_id, // 4. for subquery (WHERE m.sender_id)
      $current_user_id, // 5. for subquery (OR m.receiver_id)
      $current_user_id  // 6. for subquery (GROUP BY IF)
    ])->fetchAll();
    // ▲▲▲ AKHIR BLOK PAGINASI (YANG DIPERBAIKI) ▲▲▲


    if (!$conversations && $page === 1) {
      echo '<div class="alert alert-info">Anda belum memiliki percakapan.</div>';
    } else {
      // (Blok HTML untuk menampilkan list percakapan tidak berubah)
      echo '<div class="list-group">';
      foreach ($conversations as $convo) {
        $status_icon = '';
        if ($convo['last_sender_id'] == $current_user_id) {
          $icon = $convo['is_read']
            ? '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-eye-fill text-primary" viewBox="0 0 16 16"><path d="M10.5 8a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0z"/><path d="M0 8s3-5.5 8-5.5S16 8 16 8s-3 5.5-8 5.5S0 8 0 8zm8 3.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z"/></svg>'
            : '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-check" viewBox="0 0 16 16"><path d="M10.97 4.97a.75.75 0 0 1 1.07 1.05l-3.99 4.99a.75.75 0 0 1-1.08.02L4.324 8.384a.75.75 0 1 1 1.06-1.06l2.094 2.093 3.473-4.425a.267.267 0 0 1 .02-.022z"/></svg>';
          $status_icon = '<span class="me-1">' . $icon . '</span>';
        }

        echo '<div class="list-group-item list-group-item-action convo-item" data-user-id="' . $convo['id'] . '" data-user-name="' . h($convo['name']) . '">';
        echo '  <div class="d-flex justify-content-between align-items-center">';
        echo '    <a href="?page=pesan&with_id=' . $convo['id'] . '" class="text-decoration-none text-body d-flex align-items-center flex-grow-1" style="min-width: 0;">';
        echo '      <img src="' . h($convo['avatar']) . '" class="avatar me-3">';
        echo '      <div class="flex-grow-1" style="min-width: 0;">';
        echo '        <div class="d-flex justify-content-between">';
        echo '          <span class="fw-bold text-truncate">' . h($convo['name']) . '</span>';
        echo '          <small class="text-muted text-nowrap ms-2">' . date('H:i', strtotime($convo['created_at'])) . '</small>';
        echo '        </div>';
        echo '        <div class="d-flex align-items-center">';
        echo          $status_icon;
        echo '        <small class="text-muted text-truncate">' . h(mb_strimwidth($convo['message_text'], 0, 40, '...')) . '</small>';
        echo '        </div>';
        echo '      </div>';
        echo '    </a>';
        if ($convo['unread_count'] > 0) {
          echo '<span class="badge bg-primary rounded-pill ms-2">' . $convo['unread_count'] . '</span>';
        }
        echo '    <button class="btn btn-sm btn-outline-danger d-none d-md-inline-block ms-2 delete-btn-desktop" title="Hapus Percakapan">';
        echo '      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-trash" viewBox="0 0 16 16"><path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/><path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/></svg>';
        echo '    </button>';
        echo '  </div>';
        echo '</div>';
      }
      echo '</div>'; // penutup .list-group

      // (Blok HTML untuk navigasi paginasi tidak berubah)
      if ($total_pages > 1) {
        echo '<nav aria-label="Navigasi Halaman" class="mt-4 d-flex justify-content-center">';
        echo '  <ul class="pagination">';
        $prev_disabled = ($page <= 1) ? ' disabled' : '';
        echo '    <li class="page-item' . $prev_disabled . '">';
        echo '      <a class="page-link" href="?page=pesan&p=' . ($page - 1) . '" aria-label="Previous">';
        echo '        <span aria-hidden="true">&laquo;</span>';
        echo '      </a>';
        echo '    </li>';
        $window = 2;
        for ($i = 1; $i <= $total_pages; $i++) {
          if (
            $i == 1 ||
            $i == $total_pages ||
            ($i >= $page - $window && $i <= $page + $window)
          ) {
            if ($i == $page) {
              echo '    <li class="page-item active" aria-current="page"><span class="page-link">' . $i . '</span></li>';
            } else {
              echo '    <li class="page-item"><a class="page-link" href="?page=pesan&p=' . $i . '">' . $i . '</a></li>';
            }
          } elseif (
            ($i == $page - $window - 1) ||
            ($i == $page + $window + 1)
          ) {
            echo '    <li class="page-item disabled"><span class="page-link">...</span></li>';
          }
        }
        $next_disabled = ($page >= $total_pages) ? ' disabled' : '';
        echo '    <li class="page-item' . $next_disabled . '">';
        echo '      <a class="page-link" href="?page=pesan&p=' . ($page + 1) . '" aria-label="Next">';
        echo '        <span aria-hidden="true">&raquo;</span>';
        echo '      </a>';
        echo '    </li>';
        echo '  </ul>';
        echo '</nav>';
      }
    }


    // ▼▼▼ TOMBOL FAB (PLUS) BARU ▼▼▼
    echo '<button class="fab-plus" type="button" data-bs-toggle="collapse" data-bs-target="#newMessageCollapse" title="Pesan Baru">';
    echo '  <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" fill="currentColor" class="bi bi-plus-lg" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M8 2a.5.5 0 0 1 .5.5v5h5a.5.5 0 0 1 0 1h-5v5a.5.5 0 0 1-1 0v-5h-5a.5.5 0 0 1 0-1h5v-5A.5.5 0 0 1 8 2Z"/></svg>';
    echo '</button>';
    // ▲▲▲ AKHIR TOMBOL FAB ▲▲▲
  }

  // 5. JavaScript & CSS (Tidak ada perubahan di blok ini)
  // ... (tepat sebelum '}') penutup function view_pesan()
  echo <<<JS
    <style>
        .group .dropdown { visibility: hidden; opacity: 0; transition: opacity 0.2s; }
        .group:hover .dropdown { visibility: visible; opacity: 1; }
        .convo-item { user-select: none; -webkit-user-select: none; }
    </style>
    <script>
    // Gunakan setTimeout(..., 0) untuk "menunda" eksekusi script 
    // hingga browser selesai me-render HTML yang baru saja dimasukkan oleh SPA router.
    setTimeout(function() {
    
        // --- LOGIKA HAPUS PERCAKAPAN ---
        const deleteModalEl = document.getElementById('deleteConversationModal');
        if (deleteModalEl) {
            const deleteModal = new bootstrap.Modal(deleteModalEl);
            const userIdToDeleteInput = document.getElementById('userIdToDelete');
            const deleteModalUserName = document.getElementById('deleteModalUserName');

            function openDeleteModal(userId, userName) {
                userIdToDeleteInput.value = userId;
                deleteModalUserName.textContent = userName;
                deleteModal.show();
            }
            document.querySelectorAll('.delete-btn-desktop').forEach(button => {
                button.addEventListener('click', function() {
                    const item = this.closest('.convo-item');
                    openDeleteModal(item.dataset.userId, item.dataset.userName);
                });
            });
            document.querySelectorAll('.convo-item').forEach(item => {
                let pressTimer;
                item.addEventListener('contextmenu', function(e) {
                    e.preventDefault();
                });
                item.addEventListener('touchstart', function(e) {
                    pressTimer = window.setTimeout(() => {
                        if (navigator.vibrate) navigator.vibrate(50);
                        openDeleteModal(this.dataset.userId, this.dataset.userName);
                    }, 800); 
                });
                ['touchend', 'touchmove', 'touchcancel'].forEach(evt => {
                    item.addEventListener(evt, () => clearTimeout(pressTimer));
                });
            });
        }

        // --- LOGIKA PENCARIAN KONTAK ---
        const searchInput = document.getElementById('userSearchInput');
        if (searchInput) {
            let searchTimeout;
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                const query = this.value;
                const searchResults = document.getElementById('userSearchResults');

                if (query.length < 2) {
                    searchResults.innerHTML = '';
                    return;
                }
                searchTimeout = setTimeout(() => {
                    fetch('?action=api_search_users&query=' + encodeURIComponent(query))
                        .then(response => response.json())
                        .then(users => {
                            searchResults.innerHTML = '';
                            if (users.length === 0) {
                                searchResults.innerHTML = '<div class="list-group-item">Tidak ada kontak ditemukan.</div>';
                            } else {
                                users.forEach(user => {
                                    const a = document.createElement('a');
                                    a.href = '?page=pesan&with_id=' + user.id;
                                    a.className = 'list-group-item list-group-item-action d-flex align-items-center';
                                    a.innerHTML = `<img src="\${user.avatar}" class="avatar me-3"><div>\${user.name}</div>`;
                                    searchResults.appendChild(a);
                                });
                            }
                        });
                }, 300);
            });
        }
        
        // --- LOGIKA HAPUS PESAN INDIVIDUAL ---
        const deleteMessageModal = document.getElementById('deleteMessageModal');
        if (deleteMessageModal) {
            const deleteForm = document.getElementById('deleteMessageForm');
            const messageIdInput = document.getElementById('messageIdToDelete');
            const deleteTypeInput = document.getElementById('deleteType');
            const btnDeleteForMe = document.getElementById('btnDeleteForMe');
            const btnDeleteForEveryone = document.getElementById('btnDeleteForEveryone');

            deleteMessageModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                messageIdInput.value = button.getAttribute('data-message-id');
                btnDeleteForEveryone.style.display = (button.getAttribute('data-is-sender') === '1') ? 'inline-block' : 'none';
            });
            btnDeleteForMe.addEventListener('click', function() {
                deleteTypeInput.value = 'for_me';
                deleteForm.submit();
            });
            btnDeleteForEveryone.addEventListener('click', function() {
                deleteTypeInput.value = 'for_everyone';
                deleteForm.submit();
            });
        }

        // --- JS CHAT ROOM (DIGABUNG DAN DIPERBAIKI) ---
        const chatForm = document.getElementById('chat-form');
        const chatTextarea = document.querySelector('textarea[name="message_text"]');
        const messageContainer = document.getElementById('message-container');
        
        if (chatForm && chatTextarea && messageContainer) {
            const chatButton = chatForm.querySelector('button[type="submit"]');
            const emptyPlaceholder = document.getElementById('empty-chat-placeholder');

          // 1. Fungsi untuk mengirim (dipanggil oleh submit & Enter)
            const handleFormSubmit = async () => {
                const messageText = chatTextarea.value.trim();
                if (messageText === '') return;

                // ▼▼▼ PERBAIKAN: Ambil data SEBELUM form dinonaktifkan ▼▼▼
                const formData = new FormData(chatForm);
                
                // Baru nonaktifkan form
                chatTextarea.disabled = true;
                chatButton.disabled = true;
                chatButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';

                try {
                    // const formData = new FormData(chatForm); // <-- Baris ini dipindah ke atas
                    const response = await fetch('?action=send_message', {
                        method: 'POST',
                        body: formData
                    });
// ... (dst)
                    const result = await response.json();
                    if (response.ok && result.ok) {
                        if (emptyPlaceholder) emptyPlaceholder.style.display = 'none';
                        messageContainer.insertAdjacentHTML('beforeend', result.html);
                        messageContainer.scrollTop = messageContainer.scrollHeight;
                        chatTextarea.value = '';
                        chatTextarea.style.height = 'auto'; // Reset tinggi
                    } else {
                        alert('Gagal mengirim pesan: ' + (result.error || 'Unknown error'));
                    }
                } catch (error) {
                    alert('Terjadi kesalahan jaringan. Silakan coba lagi.');
                } finally {
                    chatTextarea.disabled = false;
                    chatButton.disabled = false;
                    chatButton.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-send-fill" viewBox="0 0 16 16" style="display: block; margin: auto;"><path d="M15.964.686a.5.5 0 0 0-.65-.65L.767 5.855H.766l-.452.18a.5.5 0 0 0-.082.887l.41.26.001.002 4.995 3.178 3.178 4.995.002.002.26.41a.5.5 0 0 0 .886-.083l6-15Zm-1.833 1.89L6.637 10.07l-.215-.338a.5.5 0 0 0-.154-.154l-.338-.215 7.494-7.494 1.178-.471-.47 1.178Z"/></svg>';
                    chatTextarea.focus();
                }
            };

            // 2. Listener untuk tombol KIRIM
            chatForm.addEventListener('submit', function(e) {
                e.preventDefault(); // Hentikan reload
                handleFormSubmit(); // Panggil fungsi AJAX
            });

            // 3. Listener untuk auto-resize
            chatTextarea.style.height = (chatTextarea.scrollHeight) + 'px';
            chatTextarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });

            // 4. Listener untuk tombol ENTER (DIPERBAIKI)
            chatTextarea.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault(); 
                    // JANGAN panggil this.form.submit()
                    // Panggil fungsi AJAX kita
                    handleFormSubmit();
                }
            });
            
            // 5. Scroll ke bawah saat halaman dimuat
            if (messageContainer) {
                messageContainer.scrollTop = messageContainer.scrollHeight;
            }
        }
        // --- AKHIR BLOK CHAT ROOM ---

    }, 0); // Penutup setTimeout
    </script>
JS;
}
// ================
// AKHIR VIEW PESAN
// ================

// ================
// AWAL VIEW SETTING
// ================

function view_setting()
{
  if (!uid()) {
    echo '<div class="alert alert-info">Silakan login untuk mengatur preferensi.</div>';
    return;
  }

  $u = $_SESSION['user'];
  $curTimer = user_timer_seconds(30);
  $curExamTimer = user_exam_timer_minutes(60);

  echo '<div class="card"><div class="card-body" style="max-width:520px">';
  echo '<h4 class="mb-3">Pengaturan</h4>';
  if (isset($_GET['ok'])) echo '<div class="alert alert-success py-2">Tersimpan.</div>';

  echo '<form method="post" action="?action=save_settings">';
  // === Ubah Nama ===
  echo '<label class="form-label">Nama tampilan</label>';
  echo '<input class="form-control mb-3" name="name" type="text" maxlength="190" value="' . h($u['name']) . '" placeholder="Nama Anda">';

  // === Timer per Soal ===
  echo '<label class="form-label">Timer per soal (detik) <small class="text-muted">(Mode Instan & End Review)</small></label>';
  echo '<input class="form-control mb-3" name="timer_seconds" type="number" min="5" max="300" step="5" value="' . $curTimer . '" required>';

  // ▼▼▼ BLOK BARU UNTUK ADMIN ▼▼▼
  if (is_admin()) {
    echo '<label class="form-label">Durasi Mode Ujian (menit) <small class="text-muted">(Hanya Admin)</small></label>';
    echo '<input class="form-control mb-3" name="exam_timer_minutes" type="number" min="1" max="300" step="1" value="' . $curExamTimer . '" required>';
  }
  // ▲▲▲ AKHIR BLOK BARU ▲▲▲

  echo '<button class="btn btn-primary">Simpan</button>';
  echo '</form>';

  echo '<div class="small text-muted mt-3">Nama dipakai di header/profil. Jika timer tidak diubah, default 30 detik.</div>';

  echo '<form method="post" action="?action=unlock_name" class="mt-2">
        <button class="btn btn-outline-secondary btn-sm">Gunakan nama Google lagi (buka kunci)</button>
      </form>';

  echo '</div></div>';
}

// ====
// ABOUT
// ====
function view_about()
{
    $user_role = $_SESSION['user']['role'] ?? 'tamu';

    // --- Ambil statistik singkat dari DB (aman kalau tabel ada) ---
    $stats = [
        'themes' => 0, 'titles' => 0, 'questions' => 0, 'users' => 0,
        'results_30d' => 0, 'avg_score_30d' => 0.0, 'active_users_30d' => 0, 'top_title' => null,
    ];
    try {
        $stats['themes']    = (int) q("SELECT COUNT(*) c FROM themes")->fetch()['c'];
        $stats['titles']    = (int) q("SELECT COUNT(*) c FROM quiz_titles")->fetch()['c'];
        $stats['questions'] = (int) q("SELECT COUNT(*) c FROM questions")->fetch()['c'];
        $stats['users']     = (int) q("SELECT COUNT(*) c FROM users")->fetch()['c'];

        $r30 = q("
            SELECT COUNT(*) cnt, AVG(score) avg_score, COUNT(DISTINCT user_id) au
            FROM results
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ")->fetch();
        if ($r30) {
            $stats['results_30d']     = (int) ($r30['cnt'] ?? 0);
            $stats['avg_score_30d']   = round((float) ($r30['avg_score'] ?? 0), 1);
            $stats['active_users_30d']= (int) ($r30['au'] ?? 0);
        }

        $top = q("
            SELECT qt.title, COUNT(*) plays
            FROM results r
            JOIN quiz_titles qt ON qt.id = r.title_id
            GROUP BY r.title_id
            ORDER BY plays DESC
            LIMIT 1
        ")->fetch();
        if ($top) $stats['top_title'] = $top['title'];
    } catch (Throwable $e) { /* diamkan: biar 0 jika tabel belum ada */ }

    // --- CTA Dinamis ---
    if ($user_role === 'pengajar') {
        $cta_primary = '<a href="?page=kelola_institusi" class="btn btn-lg btn-primary">Kelola Kelas &amp; Beri Tugas</a>';
    } elseif ($user_role === 'pelajar') {
        $cta_primary = '<a href="?page=kelola_kelas" class="btn btn-lg btn-primary">Gabung Kelas &amp; Kerjakan Tugas</a>';
    } elseif ($user_role === 'admin') {
        $cta_primary = '<a href="?page=admin" class="btn btn-lg btn-primary">Buka Dashboard Admin</a>';
    } else {
        $cta_primary = '<a href="./" class="btn btn-lg btn-primary">Masuk &amp; Main Kuis</a>';
    }

    // --- Format angka ---
    $themes        = number_format($stats['themes']);
    $titles        = number_format($stats['titles']);
    $questions     = number_format($stats['questions']);
    $users         = number_format($stats['users']);
    $results_30d   = number_format($stats['results_30d']);
    $avg_score_30d = number_format($stats['avg_score_30d'], 1);
    $active_30d    = number_format($stats['active_users_30d']);
    $top_title     = htmlspecialchars($stats['top_title'] ?? '—');

    echo <<<HTML
<div class="container py-5" style="max-width:980px">

  <!-- HERO -->
  <div class="text-center mb-4">
    <div class="d-inline-flex align-items-center gap-2">
      <span style="font-size:2rem">💡</span>
      <h1 class="mb-0">Tentang QuizB (Quiz Berkah)</h1>
    </div>
    <p class="lead mt-2">
      Platform kuis interaktif & manajemen tugas yang <strong>ringan, adaptif</strong>, dan siap pakai untuk kelas Indonesia.
    </p>
    <div class="d-flex flex-column flex-md-row justify-content-center gap-3 mt-3">
      {$cta_primary}
      <a href="?page=themes" class="btn btn-lg btn-outline-secondary">Jelajahi Bank Soal</a>
    </div>
  </div>

  <!-- STATS -->
  <div class="row g-3 mb-4 text-center">
    <div class="col-6 col-md-3"><div class="card shadow-sm h-100"><div class="card-body">
      <div class="small text-muted">Tema</div><div class="h4 mb-0">{$themes}</div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card shadow-sm h-100"><div class="card-body">
      <div class="small text-muted">Judul</div><div class="h4 mb-0">{$titles}</div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card shadow-sm h-100"><div class="card-body">
      <div class="small text-muted">Soal</div><div class="h4 mb-0">{$questions}</div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card shadow-sm h-100"><div class="card-body">
      <div class="small text-muted">Pengguna</div><div class="h4 mb-0">{$users}</div>
    </div></div></div>
  </div>

  <div class="row g-3 mb-5 text-center">
    <div class="col-6 col-md-4"><div class="card shadow-sm h-100"><div class="card-body">
      <div class="small text-muted">Hasil 30 hari</div><div class="h4 mb-0">{$results_30d}</div>
    </div></div></div>
    <div class="col-6 col-md-4"><div class="card shadow-sm h-100"><div class="card-body">
      <div class="small text-muted">Rata skor 30 hari</div><div class="h4 mb-0">{$avg_score_30d}</div>
    </div></div></div>
    <div class="col-12 col-md-4"><div class="card shadow-sm h-100"><div class="card-body">
      <div class="small text-muted">Pengguna aktif 30 hari</div><div class="h4 mb-0">{$active_30d}</div>
    </div></div></div>
  </div>

  <!-- UNTUK SIAPA -->
  <h4 class="mb-3">Untuk Siapa?</h4>
  <div class="row g-4 mb-4">
    <div class="col-md-6">
      <div class="card h-100 shadow-sm border-primary">
        <div class="card-body">
          <h5 class="card-title text-primary">Untuk Pelajar</h5>
          <ul class="mb-0">
            <li>✅ <strong>Instan Review</strong> atau <strong>End Review</strong>.</li>
            <li>🏆 <strong>Challenge Link</strong> untuk adu skor per judul.</li>
            <li>⏱️ <strong>Timer personal</strong>, resume, progres tersimpan (Login Google).</li>
            <li>📊 <strong>Riwayat & Ringkasan</strong> mudah ditinjau kapan saja.</li>
          </ul>
        </div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card h-100 shadow-sm border-success">
        <div class="card-body">
          <h5 class="card-title text-success">Untuk Pengajar</h5>
          <ul class="mb-0">
            <li>🧑‍🏫 <strong>Assignments</strong>: deadline, durasi, jumlah soal, mode.</li>
            <li>🏫 <strong>Kelas & Institusi</strong>: tambah siswa, kelola tugas per kelas.</li>
            <li>📢 <strong>Rekap WA Nilai 100</strong> otomatis ke grup.</li>
            <li>📈 <strong>Peta Kesulitan</strong>: Count/Ratio + ambang min-attempts.</li>
          </ul>
        </div>
      </div>
    </div>
  </div>

  <!-- KEUNIKAN -->
  <div class="mb-4">
    <h4 class="mb-2">Keunggulan & Keunikan</h4>
    <ul class="mb-0">
      <li>🧠 <strong>Adaptive Pick 2.0</strong> → distribusi soal adil & variatif.</li>
      <li>🔗 <strong>Challenge Token</strong> untuk kompetisi sehat.</li>
      <li>🗺️ <strong>Peta Kesulitan</strong> bantu kurasi materi.</li>
      <li>📨 <strong>Integrasi WhatsApp</strong> (rekap nilai 100 & pengumuman).</li>
      <li>⚡ <strong>Ringan & Offline-ready</strong> (Service Worker).</li>
      <li>🔐 <strong>Login Google</strong> sederhana & efektif.</li>
    </ul>
  </div>

  <!-- TERBARU -->
  <div class="mb-4">
    <h4 class="mb-2">Apa yang Baru (2025)</h4>
    <ul class="mb-0">
      <li>🎯 Adaptive & skill scaling ditingkatkan.</li>
      <li>🧑‍🏫 Assignments v2: opsi mode & durasi lebih fleksibel.</li>
      <li>📢 Rekap WA nilai 100 per kelas.</li>
      <li>🏅 Leaderboard/Top Titles berbasis window 30 hari.</li>
      <li>🧭 UI Peta Kesulitan dengan toggle Count/Ratio + min-attempts.</li>
      <li>🔑 Integrasi Login Google diperbarui.</li>
    </ul>
  </div>

  <!-- CARA KERJA -->
  <div class="mb-4">
    <h4 class="mb-2">Cara Kerja Singkat</h4>
    <ol class="mb-0">
      <li>Pengajar siapkan judul/soal → buat Assignment → bagikan ke Kelas.</li>
      <li>Pelajar login opsional → pilih mode → kerjakan → hasil tersimpan.</li>
      <li>Hasil → Peta Kesulitan & Leaderboard → Rekap WA → perbaikan materi.</li>
    </ol>
  </div>

  <!-- PRIVASI -->
  <div class="mb-4">
    <h4 class="mb-2">Privasi & Data</h4>
    <p class="mb-0 small text-muted">
      Data minimum untuk pembelajaran & laporan. Login Google opsional.
      Anda dapat meminta unduh/hapus data melalui admin. Notifikasi bersifat opt-in.
    </p>
  </div>

  <!-- TOP TITLE -->
  <div class="mb-4">
    <div class="alert alert-light border d-flex align-items-center" role="alert">
      <span class="me-2">🔥</span>
      <div>
        <div class="fw-bold">Judul Terpopuler</div>
        <div class="small mb-0">{$top_title}</div>
      </div>
    </div>
  </div>

  <!-- CTA BAWAH -->
  <div class="d-flex flex-column flex-md-row gap-3 mb-4">
    {$cta_primary}
    <a href="?page=explore" class="btn btn-lg btn-outline-secondary">Explore Judul Populer</a>
    <a href="?page=difficulty" class="btn btn-lg btn-outline-secondary">Lihat Peta Kesulitan</a>
  </div>

<!-- HUBUNGI KAMI -->
<div class="border rounded p-3 mt-4 bg-body-tertiary text-body">
  <h5 class="mb-2">Hubungi Kami</h5>
  <p class="mb-1">
    📱 WhatsApp: 
    <a href="https://wa.me/6285743399595" target="_blank" rel="noopener" class="link-body-emphasis">
      0857-4339-9595
    </a>
  </p>
  <p class="mb-1">
    📧 Email: 
    <a href="mailto:zenhkm@gmail.com" class="link-body-emphasis">
      zenhkm@gmail.com
    </a>
  </p>
  <p class="mb-0">
    📸 Instagram: 
    <a href="https://instagram.com/zainul.hakim" target="_blank" rel="noopener" class="link-body-emphasis">
      Zainul Hakim
    </a>
  </p>
</div>


</div>
HTML;
}


// ... (di sekitar area function handle_save_welcome)

// ===============================================
// HANDLE FEEDBACK (BARU)
// ===============================================
function handle_feedback()
{
    $sender_id = uid(); // ID pengirim (NULL jika tamu)
    $name = trim($_POST['name'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $reply_email = trim($_POST['reply_email'] ?? ''); // <<< AMBIL EMAIL

    // Validasi
    if (empty($name) || empty($content) || empty($reply_email)) { 
        redirect('?page=feedback&err=1');
    }

    // Insert data ke tabel feedbacks, termasuk email balasan
    q(
        "INSERT INTO feedbacks (sender_id, sender_name, reply_email, subject, content, created_at) VALUES (?, ?, ?, ?, ?, ?)",
        [$sender_id, h($name), h($reply_email), h($subject), h($content), now()] 
    );
    
    redirect('?page=feedback&ok=1');
}


// ... (setelah function handle_feedback())

// ===============================================
// HANDLE BALASAN EMAIL DARI ADMIN
// ===============================================
function handle_admin_reply()
{
    if (!is_admin()) {
        redirect('?page=feedback');
    }

    $id = (int)($_POST['feedback_id'] ?? 0);
    $reply_subject = trim($_POST['reply_subject'] ?? '');
    $reply_body = trim($_POST['reply_body'] ?? '');

    if ($id <= 0 || empty($reply_body) || empty($reply_subject)) {
        redirect('?page=feedback&id=' . $id . '&err=reply_empty');
    }

    $feedback = q("SELECT reply_email, sender_name, subject FROM feedbacks WHERE id = ?", [$id])->fetch();

    if (!$feedback) {
        redirect('?page=feedback&err=not_found');
    }

    $to_email = $feedback['reply_email'];
    $to_name = $feedback['sender_name'];
    $original_subject = $feedback['subject'];

    // Siapkan body email dalam format HTML
    $html_body = "Halo <strong>" . h($to_name) . "</strong>,<br><br>";
    $html_body .= "Berikut adalah balasan dari Admin QuizB terkait masukan Anda:<br><br>";
    $html_body .= "<div style='border: 1px solid #ccc; background-color: #f9f9f9; padding: 15px; margin: 15px 0;'>";
    $html_body .= "<strong>Subjek Asli:</strong> " . h($original_subject) . "<br><hr>";
    $html_body .= nl2br(h($reply_body));
    $html_body .= "</div>";
    $html_body .= "<br>Terima kasih atas partisipasi Anda.<br><br>Salam,<br>" . SMTP_FROM_NAME;
    
    // Panggil fungsi pengiriman email
    $success = send_smtp_email($to_email, $reply_subject, $html_body);

    if ($success) {
        redirect('?page=feedback&id=' . $id . '&ok=reply_sent');
    } else {
        redirect('?page=feedback&id=' . $id . '&err=reply_failed');
    }
}


// ... (Lanjutan dari kode sebelumnya)

// ===============================================
// VIEW: FEEDBACK UTAMA (ROUTER ADMIN/USER)
// ===============================================
function view_feedback()
{
    if (is_admin()) {
        // Halaman Admin Feedback
        view_feedback_admin();
    } else {
        // Halaman User Feedback (Form)
        view_feedback_user_form();
    }
}

// ===============================================
// SUB-VIEW: FEEDBACK USER FORM (Tampilan User)
// ===============================================
function view_feedback_user_form() 
{
    $u = $_SESSION['user'] ?? null;
    $default_name = $u ? h($u['name']) : '';
    $default_email = $u ? h($u['email']) : ''; // Default email dari sesi

    echo '<div class="card shadow-sm" style="max-width: 600px; margin: auto;">';
    echo '  <div class="card-body p-4 p-md-5">';
    echo '    <h3 class="card-title text-center mb-4">Kirim Umpan Balik</h3>';
    
    if (isset($_GET['ok'])) {
        echo '<div class="alert alert-success">Terima kasih atas masukan Anda. Pesan telah dikirim.</div>';
    }
    if (isset($_GET['err'])) {
        echo '<div class="alert alert-danger">Gagal mengirim umpan balik. Pastikan semua kolom wajib diisi.</div>';
    }

    echo '    <form action="?action=handle_feedback" method="POST">';

    echo '      <div class="mb-3">';
    echo '        <label for="feedbackName" class="form-label fw-bold">Nama Anda</label>';
    echo '        <input type="text" class="form-control" id="feedbackName" name="name" value="' . $default_name . '" required placeholder="Nama Anda (Wajib)">';
    echo '      </div>';

    // BLOK EMAIL
    echo '      <div class="mb-3">';
    echo '        <label for="feedbackEmail" class="form-label fw-bold">Email Balasan <span class="text-danger">*</span></label>';
    echo '        <input type="email" class="form-control" id="feedbackEmail" name="reply_email" value="' . $default_email . '" required placeholder="Email Anda (Wajib)">';
    echo '        <small class="text-muted">Untuk membalas masukan Anda jika diperlukan.</small>';
    echo '      </div>';
    // AKHIR BLOK EMAIL

    echo '      <div class="mb-3">';
    echo '        <label for="feedbackSubject" class="form-label fw-bold">Tentang <span class="text-muted">(Opsional)</span></label>';
    echo '        <input type="text" class="form-control" id="feedbackSubject" name="subject" placeholder="Contoh: Bug di halaman A, Saran fitur, dll.">';
    echo '      </div>';

    echo '      <div class="mb-3">';
    echo '        <label for="feedbackContent" class="form-label fw-bold">Isi Umpan Balik <span class="text-danger">*</span></label>';
    echo '        <textarea class="form-control" id="feedbackContent" name="content" rows="5" required placeholder="Tulis masukan, kritik, atau saran Anda di sini."></textarea>';
    echo '      </div>';

    echo '      <div class="d-grid mt-4">';
    echo '        <button type="submit" class="btn btn-primary btn-lg">Kirim Umpan Balik</button>';
    echo '      </div>';
    echo '    </form>';
    echo '  </div>';
    echo '</div>';
}

// ===============================================
// SUB-VIEW: FEEDBACK ADMIN (LIST & DETAIL ROUTER)
// ===============================================
function view_feedback_admin() 
{
    $detail_id = $_GET['id'] ?? null;
    $detail_id = is_numeric($detail_id) ? (int)$detail_id : null;

    if ($detail_id) {
        view_feedback_detail($detail_id);
    } else {
        view_feedback_list();
    }
}

// ===============================================
// SUB-VIEW: FEEDBACK ADMIN DETAIL (Final Version with Reply Form)
// ===============================================
function view_feedback_detail(int $id)
{
    $feedback = q("SELECT * FROM feedbacks WHERE id = ?", [$id])->fetch();

    if (!$feedback) {
        echo '<div class="alert alert-warning">Feedback tidak ditemukan.</div>';
        return;
    }

    // Set status is_read menjadi 1
    if ($feedback['is_read'] == 0) {
        q("UPDATE feedbacks SET is_read = 1 WHERE id = ?", [$id]);
    }

    $sender_info = $feedback['sender_id'] 
        ? ('User ID: ' . $feedback['sender_id']) 
        : 'Tamu';
    
    if ($feedback['sender_id']) {
        $user_row = q("SELECT email FROM users WHERE id=?", [$feedback['sender_id']])->fetch();
        if ($user_row) {
            $sender_info .= ' (' . h($user_row['email']) . ')';
        }
    } else {
        $sender_info .= ' (IP: ' . get_client_ip() . ')';
    }

    echo '<h2>Detail Umpan Balik #' . $feedback['id'] . '</h2>';
    echo '<a href="?page=feedback" class="btn btn-sm btn-outline-secondary mb-3"><i class="fas fa-arrow-left"></i> Kembali ke Daftar</a>';

    // Tampilkan notifikasi balasan
    if (isset($_GET['ok']) && $_GET['ok'] === 'reply_sent') {
        echo '<div class="alert alert-success">Balasan email berhasil dikirim ke ' . h($feedback['reply_email']) . '.</div>';
    }
    if (isset($_GET['err']) && $_GET['err'] === 'reply_failed') {
        echo '<div class="alert alert-danger">Gagal mengirim balasan email. Cek konfigurasi SMTP Anda di kode.</div>';
    }
    if (isset($_GET['err']) && $_GET['err'] === 'reply_empty') {
        echo '<div class="alert alert-warning">Subjek atau isi balasan tidak boleh kosong.</div>';
    }

    // Tampilan Detail Feedback
    echo '<div class="card shadow-sm mb-4">';
    echo '  <div class="card-header bg-light">';
    echo '    <h5 class="mb-0"><strong>Subjek:</strong> ' . (empty($feedback['subject']) ? '— Tanpa Subjek —' : h($feedback['subject'])) . '</h5>';
    echo '  </div>';
    echo '  <ul class="list-group list-group-flush">';
    echo '    <li class="list-group-item"><strong>Dari:</strong> ' . h($feedback['sender_name']) . '</li>';
    echo '    <li class="list-group-item"><strong>Email Balasan:</strong> <a href="mailto:' . h($feedback['reply_email']) . '">' . h($feedback['reply_email']) . '</a></li>'; 
    echo '    <li class="list-group-item"><strong>Info Pengirim:</strong> ' . $sender_info . '</li>';
    echo '    <li class="list-group-item"><strong>Tanggal:</strong> ' . date('d F Y H:i', strtotime($feedback['created_at'])) . '</li>';
    echo '  </ul>';
    echo '  <div class="card-body">';
    echo '    <h6><strong>Isi Umpan Balik:</strong></h6>';
    echo '    <pre class="card-text border p-3 bg-white" style="white-space: pre-wrap; word-wrap: break-word;">' . h($feedback['content']) . '</pre>';
    echo '  </div>';
    echo '</div>'; // Akhir Tampilan Detail Feedback

    // FORMULIR BALASAN EMAIL
    echo '<h3>Balas Umpan Balik</h3>';
    echo '<div class="card shadow-sm">';
    echo '  <div class="card-body">';
    echo '    <form method="POST" action="?action=handle_reply">';
    echo '      <input type="hidden" name="feedback_id" value="' . $feedback['id'] . '">';
    
    echo '      <div class="mb-3">';
    echo '        <label class="form-label">Kepada:</label>';
    echo '        <input type="text" class="form-control" value="' . h($feedback['sender_name']) . ' (' . h($feedback['reply_email']) . ')" disabled>';
    echo '      </div>';

    echo '      <div class="mb-3">';
    echo '        <label for="replySubject" class="form-label">Subjek Balasan:</label>';
    // Isi subjek otomatis
    $default_subject = 'RE: ' . (empty($feedback['subject']) ? 'Balasan dari Admin QuizB' : h($feedback['subject']));
    echo '        <input type="text" class="form-control" id="replySubject" name="reply_subject" value="' . $default_subject . '" required>';
    echo '      </div>';

    echo '      <div class="mb-3">';
    echo '        <label for="replyBody" class="form-label">Isi Balasan (Email):</label>';
    echo '        <textarea class="form-control" id="replyBody" name="reply_body" rows="6" required></textarea>';
    echo '      </div>';

    echo '      <button type="submit" class="btn btn-success"><i class="fas fa-paper-plane"></i> Kirim Balasan ke Email</button>';
    echo '    </form>';
    echo '  </div>';
    echo '</div>';
}

// ===============================================
// SUB-VIEW: FEEDBACK ADMIN LIST
// ===============================================
function view_feedback_list()
{
    $feedbacks = q("SELECT id, sender_name, subject, is_read, created_at, content FROM feedbacks ORDER BY created_at DESC")->fetchAll();
    $unread_count = q("SELECT COUNT(*) FROM feedbacks WHERE is_read = 0")->fetchColumn();

    echo '<h2>Daftar Umpan Balik (' . count($feedbacks) . ' Total)</h2>';
    if ($unread_count > 0) {
        echo '<div class="alert alert-info">Anda memiliki <strong>' . $unread_count . '</strong> umpan balik yang belum dibaca.</div>';
    }

    if (empty($feedbacks)) {
        echo '<div class="alert alert-warning">Belum ada umpan balik yang diterima.</div>';
        return;
    }

    echo '<div class="list-group">';
    foreach ($feedbacks as $f) {
        $badge = $f['is_read'] == 0 
            ? '<span class="badge bg-primary rounded-pill me-2">Baru</span>' 
            : '';
        $subject = empty($f['subject']) ? '— Tanpa Subjek —' : h($f['subject']);
        $snippet = h(substr($f['content'], 0, 100)) . (strlen($f['content']) > 100 ? '...' : '');

        echo '<a href="?page=feedback&id=' . $f['id'] . '" class="list-group-item list-group-item-action ' . ($f['is_read'] == 0 ? 'list-group-item-light fw-bold' : '') . '">';
        echo '  <div class="d-flex w-100 justify-content-between">';
        echo '    <h5 class="mb-1 text-truncate">' . $badge . $subject . '</h5>';
        echo '    <small class="text-muted text-nowrap">' . date('d/m/Y H:i', strtotime($f['created_at'])) . '</small>';
        echo '  </div>';
        echo '  <p class="mb-1 text-truncate" style="max-width: 90%;">Dari: ' . h($f['sender_name']) . ' - <small class="' . ($f['is_read'] == 0 ? '' : 'fw-normal') . '">' . $snippet . '</small></p>';
        echo '</a>';
    }
    echo '</div>';
}


// ===
// PRIVACY POLICY (Kebijakan Privasi)
// ===
function view_privacy()
{
    echo '<div class="container py-5" style="max-width:900px">';
    echo '<h1 class="mb-3">Kebijakan Privasi (Privacy Policy)</h1>';
    echo '<p class="text-muted">Terakhir diperbarui: ' . date('Y-m-d') . '</p>';

    echo '<p>QuizB (Quiz Berkah) menghormati privasi setiap pengguna. Halaman ini menjelaskan jenis data yang kami kumpulkan, cara penggunaannya, serta hak dan pilihan yang Anda miliki.</p>';

    echo '<h5 class="mt-4">1. Data yang Kami Kumpulkan</h5>';
    echo '<ul>';
    echo '<li><strong>Login Google</strong> (opsional): nama, email, foto profil, ID unik akun — untuk autentikasi & sinkronisasi progres.</li>';
    echo '<li><strong>Aktivitas Kuis</strong>: skor, hasil, waktu, mode (instan/end), riwayat tantangan.</li>';
    echo '<li><strong>Kelas & Tugas</strong>: data institusi/kelas/assignment yang terkait akun.</li>';
    echo '<li><strong>Kota/Lokasi umum tamu</strong> (jika diisi): menampilkan asal pada leaderboard tamu.</li>';
    echo '<li><strong>Preferensi</strong>: tema, durasi timer, pengaturan tampilan (LocalStorage).</li>';
    echo '<li><strong>Cookie & LocalStorage</strong>: sesi login, tema, posisi terakhir.</li>';
    echo '<li><strong>Push Subscription</strong> (jika diaktifkan): untuk notifikasi pembaruan/pengumuman.</li>';
    echo '</ul>';

    echo '<h5 class="mt-4">2. Cara Kami Menggunakan Data</h5>';
    echo '<ul>';
    echo '<li>Mengelola sesi, menyimpan progres, menampilkan hasil & analitik (Peta Kesulitan, Leaderboard).</li>';
    echo '<li>Mendukung tugas kelas (Assignments), pemantauan hasil, dan rekap nilai 100 via WhatsApp.</li>';
    echo '<li>Meningkatkan kualitas soal melalui statistik benar/salah (Adaptive Pick).</li>';
    echo '<li>Menjaga keamanan sistem & mencegah penyalahgunaan.</li>';
    echo '<li>Memberi pengalaman yang disesuaikan (tema, durasi, mode, notifikasi).</li>';
    echo '</ul>';

    echo '<h5 class="mt-4">3. Berbagi Data</h5>';
    echo '<p>Kami <strong>tidak menjual</strong> data pribadi. Akses data terbatas untuk admin QuizB demi pemeliharaan sistem, dan dapat diungkap jika diwajibkan oleh hukum.</p>';

    echo '<h5 class="mt-4">4. Penyimpanan & Keamanan</h5>';
    echo '<p>Data disimpan aman di basis data dengan praktik standar (SSL, pembatasan akses, validasi token). Namun, tidak ada sistem yang 100% aman; harap jaga kredensial Anda.</p>';

    echo '<h5 class="mt-4">5. Hak & Pilihan Anda</h5>';
    echo '<ul>';
    echo '<li>Gunakan sebagai <strong>Tamu</strong> tanpa login (dengan batasan progres).</li>';
    echo '<li>Ajukan <strong>penghapusan akun/data</strong> dengan menghubungi admin.</li>';
    echo '<li><strong>Nonaktifkan notifikasi</strong> kapan saja dari pengaturan browser.</li>';
    echo '<li>Atur ulang preferensi (tema, durasi, mode) dari menu pengaturan.</li>';
    echo '</ul>';

    echo '<h5 class="mt-4">6. Penggunaan oleh Pelajar & Anak-Anak</h5>';
    echo '<p>QuizB dapat digunakan pelajar di bawah bimbingan guru/wali. Kami tidak sengaja mengumpulkan data anak < 13 tahun tanpa izin orang tua/lembaga. Laporkan kepada kami untuk penghapusan bila diperlukan.</p>';

    echo '<h5 class="mt-4">7. Perubahan Kebijakan</h5>';
    echo '<p>Kebijakan dapat diperbarui mengikuti pengembangan fitur/aturan privasi. Tanggal pembaruan tercantum di atas.</p>';

    echo '<h5 class="mt-4">8. Kontak</h5>';
    echo '<ul>';
    echo '<li>Email: <a href="mailto:zenhkm@gmail.com">zenhkm@gmail.com</a></li>';
    echo '<li>WhatsApp: <a href="https://wa.me/6285743399595" target="_blank" rel="noopener">0857-4339-9595</a></li>';
    echo '</ul>';

    echo '<div class="mt-4">';
    echo '<a href="./" class="btn btn-primary me-2">Kembali ke Beranda</a>';
    echo '<a href="?page=about" class="btn btn-outline-secondary">Tentang QuizB</a>';
    echo '</div>';

    echo '</div>';
}


// ===============================================
// BACKEND (ADMIN) — Grid kiri–kanan (fix)
// ===============================================
function view_admin()
{
  if (!is_admin()) {
    echo '<div class="alert alert-warning">Akses admin diperlukan.</div>';
    return;
  }

  // Header + tombol CRUD
  echo '<div class="d-flex align-items-center justify-content-between mb-3">'
    . '<h3 class="m-0">Backend Admin</h3>'
    . '</div>';

  // ====== GRID UTAMA ======
  echo '<div class="row g-4">';

  // ====== KARTU OVERVIEW (full width) ======
  echo '<div class="col-12"><div class="card"><div class="card-body">';
  echo '<h5 class="mb-3">Overview</h5>';

  $counts = q("SELECT 
      (SELECT COUNT(*) FROM users) AS users,
      (SELECT COUNT(*) FROM quiz_titles) AS titles,
      (SELECT COUNT(*) FROM questions) AS questions,
      (SELECT COUNT(*) FROM results) AS results,
      (SELECT COUNT(*) FROM attempts) AS attempts
    ")->fetch();

  echo '<div class="row text-center">';
  $labels = array(
    'users'     => 'Users',
    'titles'    => 'Judul',
    'questions' => 'Soal',
    'results'   => 'Hasil',
    'attempts'  => 'Jawaban'
  );
  foreach ($labels as $k => $label) {
    $val = isset($counts[$k]) ? (int)$counts[$k] : 0;
    echo '<div class="col"><div class="p-3 border rounded-3">'
      . '<div class="small text-muted">' . h($label) . '</div>'
      . '<div class="fs-4">' . $val . '</div>'
      . '</div></div>';
  }
  echo '</div>'; // row metrics
  echo '</div></div></div>'; // card overview

  // ====== DUA KOLOM ======

  // ---- KOLOM KIRI: USER TERBARU (TABLE + SEARCH) ----
  echo '<div class="col-12 col-lg-6"><div class="card"><div class="card-body">';

  // Query dulu, baru hitung
  $recentUsers = q("SELECT name, email, created_at 
                  FROM users 
                  ORDER BY created_at DESC 
                  LIMIT 300")->fetchAll();
  $usersCount = (is_array($recentUsers) || $recentUsers instanceof Countable) ? count($recentUsers) : 0;

  echo   '<div class="d-flex align-items-center justify-content-between">';
  echo     '<h5 class="mb-2">User Terbaru <span class="badge bg-secondary" id="tbl-users-count">' . $usersCount . '</span></h5>';
  echo   '</div>';

  echo   '<input id="filter-users" type="text" class="form-control form-control-sm mb-2" placeholder="Cari: nama / email">';

  if (!$recentUsers) {
    echo '<div class="text-muted small">Belum ada user.</div>';
  } else {
    echo '<div class="table-responsive">';
    echo   '<table class="table table-sm align-middle" id="tbl-users">';
    echo     '<thead><tr>';
    echo       '<th style="white-space:nowrap;">Nama</th>';
    echo       '<th>Email</th>';
    echo       '<th style="white-space:nowrap;">Waktu</th>';
    echo     '</tr></thead>';
    echo     '<tbody>';
    foreach ($recentUsers as $u) {
      $search = strtolower(($u['name'] ?? '') . ' ' . ($u['email'] ?? '') . ' ' . ($u['created_at'] ?? ''));
      echo '<tr data-search="' . h($search) . '">';
      echo   '<td>' . h($u['name']) . '</td>';
      echo   '<td>' . h($u['email']) . '</td>';
      echo   '<td>' . h($u['created_at']) . '</td>';
      echo '</tr>';
    }
    echo     '</tbody>';
    echo   '</table>';
    echo '</div>';
  }


  echo '<div class="d-flex align-items-center justify-content-between mt-2" id="pager-users">';
  echo '  <button class="btn btn-sm btn-outline-secondary" data-page="prev">◀︎</button>';
  echo '  <div class="small text-muted">Halaman <span data-role="page">1</span>/<span data-role="pages">1</span></div>';
  echo '  <button class="btn btn-sm btn-outline-secondary" data-page="next">▶︎</button>';
  echo '</div>';

  echo '</div></div></div>'; // end kolom kiri

  // ---- KOLOM KANAN: PESERTA TERBARU (TABLE + SEARCH) ----
  echo '<div class="col-12 col-lg-6"><div class="card"><div class="card-body">';

  // Query dulu, baru hitung
  $recent = q("SELECT 
    r.created_at,
    r.score,
    COALESCE(u.name, CONCAT('Tamu – ', COALESCE(r.city,'Anonim'))) AS display_name,
    u.avatar,
    qt.title AS quiz_title
  FROM results r
  LEFT JOIN users u ON u.id = r.user_id
  LEFT JOIN quiz_titles qt ON qt.id = r.title_id
  ORDER BY r.created_at DESC
  LIMIT 500")->fetchAll();
  $participantsCount = (is_array($recent) || $recent instanceof Countable) ? count($recent) : 0;

  echo   '<div class="d-flex align-items-center justify-content-between">';
  echo     '<h5 class="mb-2">Peserta Terbaru <span class="badge bg-secondary" id="tbl-participants-count">' . $participantsCount . '</span></h5>';
  echo   '</div>';

  echo   '<input id="filter-participants" type="text" class="form-control form-control-sm mb-2" placeholder="Cari: nama / judul / waktu / skor / kota">';

  if (!$recent) {
    echo '<div class="text-muted small">Belum ada peserta.</div>';
  } else {
    echo '<div class="table-responsive">';
    echo   '<table class="table table-sm align-middle" id="tbl-participants">';
    echo     '<thead><tr>';
    echo       '<th style="white-space:nowrap;">Nama / Tamu</th>';
    echo       '<th>Judul</th>';
    echo       '<th style="white-space:nowrap;">Waktu</th>';
    echo       '<th style="text-align:right; white-space:nowrap;">Skor</th>';
    echo     '</tr></thead>';
    echo     '<tbody>';
    foreach ($recent as $r) {
      $avatar = (!empty($r['avatar'])) ? $r['avatar'] : 'https://www.gravatar.com/avatar/?d=mp&s=32';
      $display = $r['display_name'];
      $judul   = $r['quiz_title'] ?? '—';
      $waktu   = $r['created_at'];
      $skor    = (string)$r['score'];
      $search  = strtolower($display . ' ' . $judul . ' ' . $waktu . ' ' . $skor);
      echo '<tr data-search="' . h($search) . '">';
      echo   '<td>';
      echo     '<div class="d-flex align-items-center">';
      echo       '<img src="' . h($avatar) . '" class="rounded-circle me-2" width="24" height="24" alt="">';
      echo       '<span>' . h($display) . '</span>';
      echo     '</div>';
      echo   '</td>';
      echo   '<td>' . h($judul) . '</td>';
      echo   '<td>' . h($waktu) . '</td>';
      echo   '<td style="text-align:right; font-weight:600;">' . h($skor) . '</td>';
      echo '</tr>';
    }
    echo     '</tbody>';
    echo   '</table>';
    echo '</div>';
  }

  echo '<div class="d-flex align-items-center justify-content-between mt-2" id="pager-participants">';
  echo '  <button class="btn btn-sm btn-outline-secondary" data-page="prev">◀︎</button>';
  echo '  <div class="small text-muted">Halaman <span data-role="page">1</span>/<span data-role="pages">1</span></div>';
  echo '  <button class="btn btn-sm btn-outline-secondary" data-page="next">▶︎</button>';
  echo '</div>';

  echo '</div></div></div>'; // end kolom kanan
  echo '</div>'; // END .row g-4

  echo "<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Panggil fungsi global setupTable untuk tabel user
        setupTable({
            inputId: 'filter-users',
            tableId: 'tbl-users',
            pagerId: 'pager-users',
            countBadgeId: 'tbl-users-count'
        });

        // Panggil fungsi global setupTable untuk tabel peserta
        setupTable({
            inputId: 'filter-participants',
            tableId: 'tbl-participants',
            pagerId: 'pager-participants',
            countBadgeId: 'tbl-participants-count'
        });
    });
</script>";
}
// === AKHIR VIEW ADMIN ===

// GANTI SELURUH FUNGSI view_kelola_kelas_siswa() DENGAN INI

/**
 * Menampilkan halaman bagi siswa untuk memilih Institusi dan Kelas mereka.
 * Versi baru: Menggunakan checkbox, menampilkan semua kelas yang sudah diikuti,
 * dan hanya bisa menambah kelas baru.
 */
function view_kelola_kelas_siswa()
{
    if (!in_array(($_SESSION['user']['role'] ?? ''), ['pelajar', 'admin'])) {
        echo '<div class="alert alert-danger">Halaman ini khusus untuk Pelajar.</div>';
        return;
    }

    $user_id = uid();

    // Ambil SEMUA kelas yang sudah diikuti siswa
    $current_classes = q("
        SELECT c.id, c.nama_kelas, ti.nama_institusi
        FROM class_members cm
        JOIN classes c ON cm.id_kelas = c.id
        JOIN teacher_institutions ti ON c.id_institusi = ti.id
        WHERE cm.id_pelajar = ?
        ORDER BY ti.nama_institusi, c.nama_kelas
    ", [$user_id])->fetchAll();

    $current_class_ids = array_column($current_classes, 'id'); // Dapatkan hanya ID-nya untuk JS

    // Ambil institusi terakhir yang dipilih dari profil pengguna
    $last_selected_institution = q("SELECT nama_sekolah FROM users WHERE id = ?", [$user_id])->fetchColumn();

    if (isset($_GET['ok'])) {
        echo '<div class="alert alert-success">Perubahan berhasil disimpan!</div>';
    }

    echo '<div class="card" style="max-width: 600px; margin: auto;">';
    echo '  <div class="card-header"><h4 class="mb-0">Gabung ke Kelas</h4></div>';
    echo '  <div class="card-body">';

    // Tampilkan daftar kelas yang sudah diikuti
    if ($current_classes) {
        echo '<p>Anda saat ini terdaftar di kelas:</p>';
        echo '<ul class="list-group mb-4">';
        foreach ($current_classes as $class) {
            echo '<li class="list-group-item list-group-item-light d-flex align-items-center">';
            echo '  <span class="badge bg-success me-2">✔</span>';
            echo '  <div><strong>' . h($class['nama_kelas']) . '</strong><br><small class="text-muted">' . h($class['nama_institusi']) . '</small></div>';
            echo '</li>';
        }
        echo '</ul><hr>';
        echo '<h5>Gabung ke Kelas Lain</h5>';
    } else {
        echo '<div class="alert alert-warning">Anda belum terdaftar di kelas manapun. Silakan pilih di bawah.</div>';
    }

    $institutions = q("
        SELECT DISTINCT ti.nama_institusi 
        FROM teacher_institutions ti
        JOIN classes c ON c.id_institusi = ti.id
        ORDER BY ti.nama_institusi ASC
    ")->fetchAll(PDO::FETCH_COLUMN);

    echo '      <form action="?action=save_student_class" method="POST">';
    echo '          <div class="mb-3">';
    echo '              <label for="namaSekolah" class="form-label fw-bold">1. Pilih Institusi</label>';
    echo '              <select class="form-select" id="namaSekolah" name="nama_sekolah" required>';
    echo '                  <option value="" disabled ' . (!$last_selected_institution ? 'selected' : '') . '>- Pilih Sekolah/Kampus -</option>';
    foreach ($institutions as $inst) {
        $selected = ($inst === $last_selected_institution) ? 'selected' : '';
        echo '              <option value="' . h($inst) . '" ' . $selected . '>' . h($inst) . '</option>';
    }
    echo '              </select>';
    echo '          </div>';
    echo '          <div class="mb-3">';
    echo '              <label class="form-label fw-bold">2. Centang Kelas untuk Bergabung</label>';
    echo '              <div id="kelasContainer" class="border rounded p-2" style="min-height: 100px; max-height: 250px; overflow-y: auto;">';
    echo '                  <div id="kelasPlaceholder" class="text-muted p-2">- Pilih institusi terlebih dahulu -</div>';
    echo '              </div>';
    echo '          </div>';
    echo '          <button type="submit" class="btn btn-primary">Simpan Pilihan</button>';
    echo '      </form>';
    echo '  </div>';
    echo '</div>'; // <-- Ini adalah penutup card

    // ▼▼▼ BLOK BARU YANG SAYA TAMBAHKAN ▼▼▼
    // Tampilkan link ke Halaman Utama (Home) HANYA jika siswa sudah terdaftar di minimal satu kelas
    if (!empty($current_classes)) {
        echo '<div class="text-center mt-4">'; // Beri jarak atas dan tengahkan
        echo '  <a href="./" class="btn btn-primary btn-lg">'; // Tautan ke home (./)
        echo '    Kerjakan tugas sekarang!';
        echo '  </a>';
        echo '</div>';
    }
    // ▲▲▲ AKHIR BLOK BARU ▲▲▲

    echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            const sekolahSelect = document.getElementById("namaSekolah");
            const kelasContainer = document.getElementById("kelasContainer");
            const kelasPlaceholder = document.getElementById("kelasPlaceholder");
            const currentClassIds = ' . json_encode($current_class_ids) . ';

            async function fetchClasses(namaSekolah) {
                kelasPlaceholder.textContent = "Memuat kelas...";
                
                try {
                    const response = await fetch("?action=api_get_classes_for_student&nama_sekolah=" + encodeURIComponent(namaSekolah));
                    const classes = await response.json();
                    
                    kelasContainer.innerHTML = ""; // Hapus placeholder
                    
                    if (classes.length > 0) {
                        classes.forEach(cls => {
                            const isJoined = currentClassIds.includes(String(cls.id));
                            
                            const div = document.createElement("div");
                            div.className = "form-check";
                            
                            const input = document.createElement("input");
                            input.className = "form-check-input";
                            input.type = "checkbox";
                            input.name = "id_kelas[]";
                            input.value = cls.id;
                            input.id = "kelas-" + cls.id;
                            
                            if (isJoined) {
                                input.checked = true;
                                input.disabled = true;
                            }
                            
                            const label = document.createElement("label");
                            label.className = "form-check-label";
                            label.htmlFor = "kelas-" + cls.id;
                            label.textContent = cls.nama_kelas;
                            
                            if (isJoined) {
                                label.classList.add("text-muted");
                            }

                            div.appendChild(input);
                            div.appendChild(label);
                            kelasContainer.appendChild(div);
                        });
                    } else {
                        kelasPlaceholder.textContent = "- Tidak ada kelas tersedia di institusi ini -";
                        kelasContainer.appendChild(kelasPlaceholder);
                    }
                } catch (error) {
                    kelasPlaceholder.textContent = "- Gagal memuat kelas -";
                    kelasContainer.appendChild(kelasPlaceholder);
                    console.error("Fetch error:", error);
                }
            }

            sekolahSelect.addEventListener("change", function() {
                if (this.value) {
                    fetchClasses(this.value);
                }
            });

            if (sekolahSelect.value) {
                fetchClasses(sekolahSelect.value);
            }
        });
    </script>';
}

/**
 * Halaman Monitor Jawaban - untuk Pengajar/Admin (VERSI 2)
 * Menampilkan status LENGKAP: Siswa yang sudah submit + yang belum submit
 * Kolom: Nama, Sekolah, Kelas, Sub Tema, Judul Soal, Status, Jawaban Benar, Prosentase, Nilai Total
 */
function view_monitor_jawaban()
{
    // 1. Ambil parameter filter dari URL
    $assignment_id = isset($_GET['assignment_id']) ? (int)$_GET['assignment_id'] : 0;
    $title_id = isset($_GET['title_id']) ? (int)$_GET['title_id'] : 0;
    $kelas_id = isset($_GET['kelas_id']) ? (int)$_GET['kelas_id'] : 0;

    // 2. Query LENGKAP: Siswa yang sudah submit + belum submit
    $query = "
        SELECT
            cm.id_pelajar AS user_id,
            u.name AS user_name,
            u.nama_sekolah,
            u.nama_kelas,
            st.name AS subtheme_name,
            qt.title AS quiz_title,
            a.judul_tugas,
            COALESCE(r.score, -1) AS score_percentage,
            COALESCE(COUNT(DISTINCT att.id), 0) AS total_questions,
            COALESCE(SUM(CASE WHEN att.is_correct = 1 THEN 1 ELSE 0 END), 0) AS correct_answers,
            COALESCE(asub.submitted_at, NULL) AS submitted_at,
            CASE 
                WHEN asub.id IS NOT NULL THEN 'Sudah Submit'
                ELSE 'Belum Submit'
            END AS status,
            a.batas_waktu,
            a.mode
        FROM class_members cm
        JOIN users u ON cm.id_pelajar = u.id
        JOIN assignments a ON a.id_kelas = cm.id_kelas
        JOIN quiz_titles qt ON a.id_judul_soal = qt.id
        JOIN subthemes st ON qt.subtheme_id = st.id
        LEFT JOIN assignment_submissions asub ON a.id = asub.assignment_id AND cm.id_pelajar = asub.user_id
        LEFT JOIN results r ON asub.result_id = r.id
        LEFT JOIN attempts att ON r.session_id = att.session_id
        WHERE 1=1
    ";

    $params = [];

    // Filter berdasarkan assignment jika diberikan
    if ($assignment_id > 0) {
        $query .= " AND a.id = ?";
        $params[] = $assignment_id;
    }

    // Filter berdasarkan title jika diberikan
    if ($title_id > 0) {
        $query .= " AND a.id_judul_soal = ?";
        $params[] = $title_id;
    }

    // Filter berdasarkan kelas jika diberikan
    if ($kelas_id > 0) {
        $query .= " AND cm.id_kelas = ?";
        $params[] = $kelas_id;
    }

    $query .= "
        GROUP BY cm.id_pelajar, a.id, u.id, u.name, u.nama_sekolah, u.nama_kelas, st.name, qt.title, a.judul_tugas, r.score, asub.submitted_at, asub.id, a.batas_waktu, a.mode
        ORDER BY a.created_at DESC, status ASC, u.name ASC
    ";

    $jawaban_data = q($query, $params)->fetchAll();

    // 3. Display halaman
    echo '<div class="container py-4">';
    echo '<h3>📊 Monitor Jawaban & Status Pengerjaan</h3>';
    echo '<p class="text-muted">Tabel lengkap status pengerjaan siswa - baik yang sudah submit maupun belum</p>';

    if (empty($jawaban_data)) {
        echo '<div class="alert alert-info">Data tidak ditemukan.</div>';
        echo '</div>';
        return;
    }

    // 4. Statistik ringkas
    $submitted_count = 0;
    $not_submitted_count = 0;
    foreach ($jawaban_data as $row) {
        if ($row['status'] === 'Sudah Submit') $submitted_count++;
        else $not_submitted_count++;
    }

    echo '<div class="row mb-4">';
    echo '<div class="col-md-4">';
    echo '<div class="card border-success">';
    echo '<div class="card-body text-center">';
    echo '<h5 class="card-title">✅ Sudah Submit</h5>';
    echo '<h3 class="text-success">' . $submitted_count . '</h3>';
    echo '</div></div></div>';
    
    echo '<div class="col-md-4">';
    echo '<div class="card border-warning">';
    echo '<div class="card-body text-center">';
    echo '<h5 class="card-title">⏳ Belum Submit</h5>';
    echo '<h3 class="text-warning">' . $not_submitted_count . '</h3>';
    echo '</div></div></div>';

    echo '<div class="col-md-4">';
    echo '<div class="card border-info">';
    echo '<div class="card-body text-center">';
    echo '<h5 class="card-title">📝 Total Siswa</h5>';
    echo '<h3 class="text-info">' . count($jawaban_data) . '</h3>';
    echo '</div></div></div>';
    echo '</div>';

    // 5. Render tabel
    echo '<div class="table-responsive">';
    echo '<table class="table table-striped table-hover">';
    echo '<thead class="table-dark">';
    echo '<tr>';
    echo '<th>Nama</th>';
    echo '<th>Sekolah</th>';
    echo '<th>Kelas</th>';
    echo '<th>Tugas</th>';
    echo '<th>Status</th>';
    echo '<th>Jawaban Benar</th>';
    echo '<th>Prosentase</th>';
    echo '<th>Nilai</th>';
    echo '<th>Waktu Submit</th>';
    echo '<th>Batas Waktu</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    foreach ($jawaban_data as $row) {
        $jawaban_benar = (int)$row['correct_answers'];
        $total_soal = (int)$row['total_questions'];
        $prosentase = $total_soal > 0 ? round(($jawaban_benar / $total_soal) * 100, 2) : 0;
        $nilai_total = $row['score_percentage'] >= 0 ? (int)$row['score_percentage'] : '-';
        $submitted_at = $row['submitted_at'] ? date('d-m-Y H:i', strtotime($row['submitted_at'])) : '-';
        $batas_waktu = $row['batas_waktu'] ? date('d-m-Y H:i', strtotime($row['batas_waktu'])) : 'Tidak ada';

        // Warna badge berdasarkan status
        if ($row['status'] === 'Sudah Submit') {
            $status_badge = '<span class="badge bg-success">✅ Sudah Submit</span>';
            $badge_class = $prosentase >= 75 ? 'bg-success' : ($prosentase >= 50 ? 'bg-warning' : 'bg-danger');
        } else {
            $status_badge = '<span class="badge bg-warning text-dark">⏳ Belum Submit</span>';
            $badge_class = 'bg-secondary';
        }

        // Cek apakah sudah terlewat deadline
        $deadline_status = '';
        if ($row['batas_waktu'] && $row['status'] === 'Belum Submit') {
            $deadline = strtotime($row['batas_waktu']);
            $now = time();
            if ($now > $deadline) {
                $status_badge = '<span class="badge bg-danger">❌ Terlambat</span>';
            }
        }

        echo '<tr>';
        echo '<td><strong>' . h($row['user_name']) . '</strong></td>';
        echo '<td><small>' . h($row['nama_sekolah'] ?? '-') . '</small></td>';
        echo '<td><small>' . h($row['nama_kelas'] ?? '-') . '</small></td>';
        echo '<td><small>' . h($row['judul_tugas']) . '</small></td>';
        echo '<td>' . $status_badge . '</td>';
        
        if ($row['status'] === 'Sudah Submit') {
            echo '<td><span class="badge bg-info">' . $jawaban_benar . ' / ' . $total_soal . '</span></td>';
            echo '<td><span class="badge ' . $badge_class . '">' . $prosentase . '%</span></td>';
            echo '<td><strong>' . $nilai_total . '</strong></td>';
        } else {
            echo '<td colspan="3" class="text-center text-muted">-</td>';
        }
        
        echo '<td><small>' . $submitted_at . '</small></td>';
        echo '<td><small>' . $batas_waktu . '</small></td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
}

/**
 * Menampilkan daftar semua tugas (assignments) dari semua kelas yang diikuti pelajar, dengan paginasi.
 * [PERBAIKAN] Menggunakan nilai tertinggi (MAX(r.score)) untuk status tugas.
 */
function view_student_tasks()
{
    // 1. Cek Login
    if (!uid()) {
        echo "<script>
            Swal.fire({
                title: 'Akses Ditolak',
                text: 'Anda belum login. Silakan login terlebih dahulu.',
                icon: 'warning',
                confirmButtonText: 'Login Sekarang',
                allowOutsideClick: false
            }).then((result) => {
                if (result.isConfirmed) {
                    // Deteksi mobile device sederhana
                    if (/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)) {
                        window.location.href = '?page=profile';
                    } else {
                        window.location.href = './';
                    }
                }
            });
        </script>";
        return;
    }

    // 2. Cek Role Siswa
    $role = $_SESSION['user']['role'] ?? '';
    $user_type = $_SESSION['user']['user_type'] ?? '';
    
    // Siswa bisa berupa role 'pelajar' atau user biasa dengan tipe 'Pelajar'
    if ($role !== 'pelajar' && !($role === 'user' && $user_type === 'Pelajar')) {
         echo "<script>
            Swal.fire({
                title: 'Akses Dibatasi',
                text: 'Halaman ini khusus untuk siswa.',
                icon: 'error',
                confirmButtonText: 'Kembali ke Beranda',
                allowOutsideClick: false
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = './';
                }
            });
        </script>";
        return;
    }
    
    $current_user_id = uid();
    
    // === LOGIKA PAGINASI ===
    $page_size = 5;
    $current_page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
    $offset = ($current_page - 1) * $page_size;

    // 1. Hitung Total Tugas
    $total_tugas_query = q("
        SELECT COUNT(a.id)
        FROM assignments a
        JOIN class_members cm ON a.id_kelas = cm.id_kelas
        WHERE cm.id_pelajar = ?
    ", [$current_user_id])->fetchColumn();
    
    $total_tugas = (int)$total_tugas_query;
    $total_pages = ceil($total_tugas / $page_size);
    if ($total_pages <= 0) $total_pages = 1;
    if ($current_page > $total_pages) $current_page = $total_pages;

    // 2. Query Daftar Tugas dengan LIMIT/OFFSET
    // Menggunakan LEFT JOIN dengan SUBQUERY untuk mendapatkan SKOR TERTINGGI
    $daftar_tugas = q("
        SELECT
            a.id AS assignment_id, a.judul_tugas, a.batas_waktu, a.mode,
            c.nama_kelas,
            qt.title AS quiz_title,
            asub_max.score AS max_score, /* Kolom Skor Tertinggi */
            asub_max.submitted_at AS last_submitted_at /* Kolom Waktu Submit Terakhir (untuk status dikerjakan) */
        FROM assignments a
        JOIN class_members cm ON a.id_kelas = cm.id_kelas
        JOIN classes c ON a.id_kelas = c.id
        JOIN quiz_titles qt ON a.id_judul_soal = qt.id
        LEFT JOIN (
            SELECT 
                asub.assignment_id, 
                asub.user_id, 
                MAX(r.score) AS score, 
                MAX(asub.submitted_at) AS submitted_at /* Ambil waktu submit terakhir untuk penanda */
            FROM assignment_submissions asub
            JOIN results r ON asub.result_id = r.id
            WHERE asub.user_id = ? /* Filter hanya untuk user ini */
            GROUP BY asub.assignment_id, asub.user_id 
        ) asub_max ON a.id = asub_max.assignment_id AND asub_max.user_id = cm.id_pelajar
        WHERE
            cm.id_pelajar = ?
        ORDER BY a.created_at DESC
        LIMIT $page_size OFFSET $offset
    ", [$current_user_id, $current_user_id])->fetchAll();
    
    // === AKHIR LOGIKA PAGINASI ===

    
    echo '<h3>Semua Tugas Saya (' . $total_tugas . ' Tugas)</h3>';
    echo '<p class="text-muted">Daftar semua tugas dari kelas yang Anda ikuti.</p>';

    if (empty($daftar_tugas)) {
        echo '<div class="alert alert-info">Anda belum memiliki tugas yang diberikan.</div>';
    } else {
        echo '<div class="list-group mb-4">';
        foreach ($daftar_tugas as $tugas) {
            
            // Perubahan: Cek apakah tugas sudah dikerjakan berdasarkan max_score
            $sudah_dikerjakan = $tugas['max_score'] !== null;
            $batas_waktu_obj = $tugas['batas_waktu'] ? new DateTime($tugas['batas_waktu']) : null;
            $lewat_batas = $batas_waktu_obj && $batas_waktu_obj < new DateTime();

            $link_tugas = '#';
            $status_html = '';
            $is_disabled = true;

            if ($sudah_dikerjakan) {
                $max_score = (int)$tugas['max_score'];
                if ($max_score === 100) {
                    $status_html = '<div class="fs-3">💯</div>';
                } else {
                    // Jika sudah dikerjakan tapi < 100, bisa coba lagi
                    $link_tugas = '?page=play&assignment_id=' . $tugas['assignment_id'] . '&restart=1';
                    $is_disabled = false;
                    $status_html = '<div class="fw-bold fs-4 text-primary me-1">' . $max_score . '</div>';
                }
            } elseif ($lewat_batas) {
                $status_html = '<span class="badge bg-danger">Terlewat</span>';
            } else {
                // Belum dikerjakan dan belum lewat batas waktu
                $link_tugas = '?page=play&assignment_id=' . $tugas['assignment_id'];
                $is_disabled = false;
                $status_html = '<span class="badge bg-warning text-dark fs-6">📝 Kerjakan</span>';
            }

            echo '<a href="' . $link_tugas . '" class="list-group-item list-group-item-action p-2 ' . ($is_disabled ? 'disabled' : '') . '">';
            echo '  <div class="d-flex w-100 justify-content-between align-items-center">';
            echo '      <div class="me-2">';
            echo '          <div class="fw-semibold" style="line-height: 1.3;">' . h($tugas['judul_tugas']) . '</div>';
            echo '          <small class="text-muted d-block">' . h($tugas['nama_kelas']) . '</small>';
             if ($batas_waktu_obj && !$sudah_dikerjakan) {
                $warna_batas = ($lewat_batas) ? 'text-danger fw-bold' : '';
                echo '      <small class="' . $warna_batas . ' d-block">Batas: ' . $batas_waktu_obj->format('d M, H:i') . '</small>';
            }
            echo '      </div>';
            echo '      <div class="text-end flex-shrink-0" style="min-width: 80px;">' . $status_html . '</div>';
            echo '  </div>';
            echo '</a>';
        }
        echo '</div>';

        // --- TAMPILKAN KONTROL PAGINASI ---
        if ($total_pages > 1) {
            $base_url_params = "page=student_tasks";
            render_pagination_controls($current_page, $total_pages, $base_url_params, 'p');
        }
    }
}


/**
 * API Endpoint (JSON) untuk mengambil daftar kelas berdasarkan nama institusi.
 * Ini khusus untuk halaman pilihan kelas siswa.
 */
function api_get_classes_for_student()
{
    header('Content-Type: application/json; charset=UTF-8');
    if (!uid()) {
        http_response_code(403);
        echo json_encode(['error' => 'Login diperlukan.']);
        exit;
    }

    $nama_institusi = trim($_GET['nama_sekolah'] ?? '');
    if (empty($nama_institusi)) {
        echo json_encode([]);
        exit;
    }

    $classes = q("
        SELECT c.id, c.nama_kelas
        FROM classes c
        JOIN teacher_institutions ti ON c.id_institusi = ti.id
        WHERE ti.nama_institusi = ?
        ORDER BY c.nama_kelas ASC
    ", [$nama_institusi])->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($classes);
    exit;
}

// GANTI SELURUH FUNGSI handle_save_student_class() DENGAN INI

/**
 * Memproses form penyimpanan pilihan kelas siswa.
 * Versi baru: Mendukung multi-kelas dan tidak menghapus pilihan lama.
 */
function handle_save_student_class()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !uid()) {
        redirect('./');
    }

    $user_id = uid();
    // Ambil array kelas yang dipilih dari checkbox
    $id_kelas_array = $_POST['id_kelas'] ?? []; 
    // Ambil nama institusi yang terakhir dipilih untuk disimpan di profil
    $nama_sekolah = trim($_POST['nama_sekolah'] ?? '');

    // 1. Update nama sekolah di profil utama pengguna (opsional, tapi baik untuk data)
    if (!empty($nama_sekolah)) {
        q("UPDATE users SET nama_sekolah = ? WHERE id = ?", [$nama_sekolah, $user_id]);
    }

    // 2. Jika ada kelas yang dipilih, tambahkan siswa ke kelas tersebut
    if (!empty($id_kelas_array)) {
        // Siapkan query sekali untuk efisiensi
        $stmt = pdo()->prepare("INSERT IGNORE INTO class_members (id_kelas, id_pelajar) VALUES (?, ?)");
        
        // Loop melalui setiap ID kelas yang dicentang
        foreach ($id_kelas_array as $id_kelas) {
            $stmt->execute([(int)$id_kelas, $user_id]);
        }
    }

    // ▼▼▼ PERUBAHAN YANG ANDA INGINKAN ADA DI SINI ▼▼▼
    redirect('./'); // Langsung arahkan ke halaman utama
}
/**
 * Menerima dan menyimpan subscription object dari browser pengguna.
 */
function handle_save_subscription() {
    header('Content-Type: application/json');

    // 1. Ambil data JSON dari request body
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    // 2. Validasi data yang masuk
    if (!isset($data['endpoint']) || !isset($data['keys']['p256dh']) || !isset($data['keys']['auth'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid subscription object.']);
        exit;
    }

    // 3. Ekstrak data
    $endpoint = $data['endpoint'];
    $p256dh = $data['keys']['p256dh'];
    $auth = $data['keys']['auth'];
    $user_id = uid(); // uid() akan mengembalikan NULL jika tidak login, ini sudah benar.

    // 4. Simpan ke database menggunakan ON DUPLICATE KEY UPDATE.
    // Ini akan mengupdate jika endpoint sudah ada, atau menambah baru jika belum.
    q(
        "INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth)",
        [$user_id, $endpoint, $p256dh, $auth]
    );

    echo json_encode(['success' => true]);
    exit; // Penting untuk menghentikan eksekusi agar tidak ada output lain
}



// === VIEW CRUD ===
function view_crud()
{
  if (!is_admin()) {
    echo '<div class="alert alert-danger">Akses ditolak.</div>';
    return;
  }

  $sel_theme_id    = isset($_GET['theme_id']) ? (int)$_GET['theme_id'] : 0;
  $sel_subtheme_id = isset($_GET['subtheme_id']) ? (int)$_GET['subtheme_id'] : 0;

  echo '<div class="row g-3">';

  // ======================
  // Kolom 1: TEMA
  // ======================
  echo '<div class="col-md-4"><div class="card"><div class="card-body">';
  echo '<h5 class="mb-3">Tema</h5>';
  // echo "<button class='btn btn-sm' 
  //         onclick='openMoveSubthemeModal({$s["id"]})'>Pindah Subtema</button>";


  // Form Tambah Tema (nama + deskripsi)
  echo '<form method="post" class="mb-3">';
  echo '<input type="hidden" name="act" value="add_theme">';
  echo '<div class="input-group mb-1">';
  echo '<input class="form-control" name="name" placeholder="Nama tema" required>';
  echo '<button class="btn btn-success" type="submit">Tambah Tema</button>';
  echo '</div>';
  echo '<input class="form-control" name="description" placeholder="Deskripsi (opsional)">';
  echo '</form>';

  $themes = q("SELECT id,name,description,sort_order FROM themes ORDER BY sort_order, name")->fetchAll();
  if (!$themes) {
    echo '<div class="alert alert-secondary">Belum ada tema.</div>';
  } else {
    echo '<div class="list-group">';
    foreach ($themes as $t) {
      $active = ($sel_theme_id === (int)$t['id']) ? ' active' : '';
      $url    = '?page=crud&theme_id=' . $t['id'];
      echo '<div class="list-group-item' . $active . ' d-flex justify-content-between align-items-center">';
      // HANYA teks link biasa (tanpa stretched-link)
      echo '<div><a class="text-decoration-none ' . ($active ? 'text-white' : '') . '" href="' . $url . '">' . h($t['name']) . '</a></div>';
      echo '<div class="ms-2 d-flex gap-1">';

      // Rename Tema
      echo '<button type="button" class="btn btn-sm btn-primary btn-rename" 
              data-id="' . $t['id'] . '" 
              data-type="theme" 
              data-name="' . h($t['name']) . '">Rename</button>';

      // Hapus Tema
      echo '<form method="post" class="d-inline" onsubmit="return confirm(\'Hapus TEMA beserta semua isinya? (Subtema, Judul, Soal)\\nTindakan ini tidak bisa dibatalkan.\')">';
      echo '<input type="hidden" name="act" value="delete_theme">';
      echo '<input type="hidden" name="theme_id" value="' . $t['id'] . '">';
      echo '<button type="submit" class="btn btn-sm btn-danger">Hapus</button></form>';
      echo '</div></div>';
    }
    echo '</div>';
  }
  echo '</div></div></div>';

  // ======================
  // Kolom 2: SUBTEMA
  // ======================
  echo '<div class="col-md-4"><div class="card"><div class="card-body">';
  echo '<h5 class="mb-3">Subtema</h5>';

  // Form Tambah Subtema (muncul jika tema dipilih)
  if ($sel_theme_id > 0) {
    echo '<form method="post" class="mb-3">';
    echo '<input type="hidden" name="act" value="add_subtheme">';
    echo '<input type="hidden" name="theme_id" value="' . $sel_theme_id . '">';
    echo '<div class="input-group">';
    echo '<input class="form-control" name="name" placeholder="Nama subtema" required>';
    echo '<button class="btn btn-success" type="submit">Tambah Subtema</button>';
    echo '</div>';
    echo '</form>';
  }


  if ($sel_theme_id <= 0) {
    echo '<div class="alert alert-info">Pilih Tema di kolom kiri untuk melihat Subtema.</div>';
  } else {
    $subs = q("SELECT id,name FROM subthemes WHERE theme_id=? ORDER BY name", [$sel_theme_id])->fetchAll();
    if (!$subs) {
      echo '<div class="alert alert-secondary">Belum ada subtema pada tema ini.</div>';
    } else {
      echo '<div class="list-group">';
      foreach ($subs as $s) {
        $active = ($sel_subtheme_id === (int)$s['id']) ? ' active' : '';
        $url    = '?page=crud&theme_id=' . $sel_theme_id . '&subtheme_id=' . $s['id'];
        echo '<div class="list-group-item' . $active . ' d-flex justify-content-between align-items-center">';
        // HANYA teks link biasa (tanpa stretched-link)
        echo '<div><a class="text-decoration-none ' . ($active ? 'text-white' : '') . '" href="' . $url . '">' . h($s['name']) . '</a></div>';
        echo '<div class="ms-2 d-flex gap-1">';

        // Rename Subtema
        // Tombol Rename Subtema (pakai modal)
        echo '<button type="button" class="btn btn-sm btn-primary btn-rename" 
                data-id="' . $s['id'] . '" 
                data-type="subtheme" 
                data-name="' . h($s['name']) . '">Rename</button>';
        echo '<button type="button" class="btn btn-sm btn-warning btn-move-subtheme" data-id="' . (int)$s['id'] . '">Pindah Subtema</button> ';

        // Hapus Subtema
        echo '<form method="post" class="d-inline" onsubmit="return confirm(\'Hapus SUBTEMA beserta semua judul & soal di dalamnya?\\nTidak bisa dibatalkan.\')">';
        echo '<input type="hidden" name="act" value="delete_subtheme">';
        echo '<input type="hidden" name="subtheme_id" value="' . $s['id'] . '">';
        echo '<button type="submit" class="btn btn-sm btn-danger">Hapus</button></form>';
        echo '</div></div>';
      }
      echo '</div>';
    }
  }
  echo '</div></div></div>';

  // ======================
  // Kolom 3: JUDUL
  // ======================
  echo '<div class="col-md-4"><div class="card"><div class="card-body">';
  echo '<h5 class="mb-3">Judul Soal</h5>';


  // Form Tambah Judul (muncul jika subtema dipilih)
  if ($sel_subtheme_id > 0) {
    echo '<form method="post" class="mb-3">';
    echo '<input type="hidden" name="act" value="add_title">';
    echo '<input type="hidden" name="subtheme_id" value="' . $sel_subtheme_id . '">';
    echo '<div class="input-group">';
    echo '<input class="form-control" name="title" placeholder="Nama judul soal" required>';
    echo '<button class="btn btn-success" type="submit">Tambah Judul</button>';
    echo '</div>';
    echo '</form>';
  }


  if ($sel_subtheme_id <= 0) {
    echo '<div class="alert alert-info">Pilih Subtema di kolom tengah untuk melihat Judul.</div>';
  } else {
    $titles = q("SELECT id,title FROM quiz_titles WHERE subtheme_id=? ORDER BY title", [$sel_subtheme_id])->fetchAll();
    if (!$titles) {
      echo '<div class="alert alert-secondary">Belum ada judul pada subtema ini.</div>';
    } else {
      echo '<div class="list-group">';
      foreach ($titles as $t) {
        echo '<div class="list-group-item d-flex justify-content-between align-items-center">';
        // Klik nama → ke qmanage
        echo '<div><a class="text-decoration-none fw-semibold" href="?page=qmanage&title_id=' . $t['id'] . '">' . h($t['title']) . '</a></div>';
        echo '<div class="ms-2 d-flex gap-1">';

        echo '<button type="button" class="btn btn-sm btn-secondary btn-move-title" data-id="' . (int)$t['id'] . '">Pindah Judul</button> ';


        echo '<div class="ms-2 d-flex gap-1">';
        // echo '<a class="btn btn-sm btn-outline-primary" href="?page=qmanage&title_id=' . $t['id'] . '">Kelola</a>';
        // (opsional: tombol hapus/rename sudah ada di bawah, biarkan apa adanya)
        echo '</div>';


        // Rename Judul
        // Tombol Rename Judul (pakai modal)
        echo '<button type="button" class="btn btn-sm btn-primary btn-rename" 
                data-id="' . $t['id'] . '" 
                data-type="title" 
                data-name="' . h($t['title']) . '">Rename</button>';

        // Hapus Judul
        echo '<form method="post" class="d-inline" onsubmit="return confirm(\'Hapus JUDUL beserta semua soal di dalamnya?\\nTidak bisa dibatalkan.\')">';
        echo '<input type="hidden" name="act" value="delete_title">';
        echo '<input type="hidden" name="title_id" value="' . $t['id'] . '">';
        echo '<button type="submit" class="btn btn-sm btn-danger">Hapus</button></form>';
        echo '</div></div>';
      }
      echo '</div>';
    }
  }
  echo '</div></div></div>';



  echo '</div>'; // .row


  // ====================================================================
  // ▼▼▼ AWAL BLOK MODAL DAN SCRIPT (VERSI PERBAIKAN) ▼▼▼
  // ====================================================================

  // 1. Siapkan data tema dalam format JSON untuk JavaScript
  $themes_js = q("SELECT id, name FROM themes ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
  echo '<script id="__themes_json" type="application/json">'
    . json_encode($themes_js, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    . '</script>';

  // 2. Tampilkan SEMUA HTML MODAL terlebih dahulu
  echo <<<HTML
    <div id="modal-move-title" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:9999;">
      <div style="max-width:520px;margin:60px auto;background:var(--bs-body-bg); color: var(--bs-body-color);padding:16px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.2);">
        <h5 class="mb-3">Pindah Judul Soal</h5>
        <form id="form-move-title" action="?page=crud" method="post">
          <input type="hidden" name="act" value="move_title">
          <input type="hidden" name="title_id" id="move_title_id">
          <div class="mb-2">
            <label class="form-label">Tema Tujuan</label>
            <select name="target_theme_id" id="move_title_theme" class="form-select" required></select>
          </div>
          <div class="mb-2">
            <label class="form-label">Subtema Tujuan</label>
            <select name="target_subtheme_id" id="move_title_subtheme" class="form-select" required></select>
          </div>
          <div class="text-end">
            <button type="button" class="btn btn-light" data-close="modal-move-title">Batal</button>
            <button type="submit" class="btn btn-primary">Simpan</button>
          </div>
        </form>
      </div>
    </div>

    <div id="modal-move-subtheme" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:9999;">
      <div style="max-width:520px;margin:60px auto;background:var(--bs-body-bg); color: var(--bs-body-color);padding:16px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.2);">
        <h5 class="mb-3">Pindah Subtema</h5>
        <form id="form-move-subtheme" action="?page=crud" method="post">
          <input type="hidden" name="act" value="move_subtheme">
          <input type="hidden" name="subtheme_id" id="move_subtheme_id">
          <div class="mb-2">
            <label class="form-label">Tema Tujuan</label>
            <select name="target_theme_id" id="move_subtheme_theme" class="form-select" required></select>
          </div>
          <div class="text-end">
            <button type="button" class="btn btn-light" data-close="modal-move-subtheme">Batal</button>
            <button type="submit" class="btn btn-primary">Simpan</button>
          </div>
        </form>
      </div>
    </div>

    <div class="modal fade" id="renameModal" tabindex="-1" aria-labelledby="renameModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <form method="post" action="?page=crud">
            <div class="modal-header">
              <h5 class="modal-title" id="renameModalLabel">Ubah Nama</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <input type="hidden" name="act" value="rename_item">
              <input type="hidden" name="item_id" id="renameItemId">
              <input type="hidden" name="item_type" id="renameItemType">
              <div class="mb-3">
                <label for="renameItemName" class="form-label">Nama Baru:</label>
                <input type="text" class="form-control" id="renameItemName" name="name" required>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
              <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            </div>
          </form>
        </div>
      </div>
    </div>
HTML;

  // 3. Tampilkan SEMUA JAVASCRIPT setelah HTML modal siap
  echo <<<JS
<script>
document.addEventListener('DOMContentLoaded', function() {
    (function(){
      const qs  = (s)=>document.querySelector(s);

      // --- Logika untuk Modal Pindah ---
      function getThemes(){
        const node = document.getElementById('__themes_json');
        if(!node) return [];
        try { return JSON.parse(node.textContent || '[]'); } catch(e){ return []; }
      }
      function fillThemes(sel){
        if(!sel) return;
        const themes = getThemes();
        sel.innerHTML = '';
        themes.forEach(t=>{
          const o=document.createElement('option');
          o.value=t.id; o.textContent=t.name;
          sel.appendChild(o);
        });
      }
      async function fillSubthemes(themeId, sel, withEmpty=true){
        if(!sel) return;
        sel.innerHTML='';
        if(withEmpty){
          const o0=document.createElement('option');
          o0.value=''; o0.textContent='— Pilih Subtema —';
          sel.appendChild(o0);
        }
        if(!themeId) return;
        const res = await fetch('?action=api_subthemes&theme_id=' + encodeURIComponent(themeId));
        if(!res.ok){ alert('Gagal memuat subtema'); return; }
        const data = await res.json();
        data.forEach(st=>{
          const o=document.createElement('option');
          o.value=st.id; o.textContent=st.name;
          sel.appendChild(o);
        });
      }

      function show(id){ const m=qs(id); if(m) m.style.display='block'; }
      function hide(id){ const m=qs(id); if(m) m.style.display='none'; }

      document.addEventListener('click', function(e){
        const btnTitle = e.target.closest('.btn-move-title');
        if(btnTitle){
          const id = btnTitle.dataset.id;
          const tSel = qs('#move_title_theme');
          const sSel = qs('#move_title_subtheme');
          qs('#move_title_id').value = id;
          fillThemes(tSel);
          if(tSel){ fillSubthemes(tSel.value, sSel, true); }
          show('#modal-move-title');
          return;
        }
        const btnSub = e.target.closest('.btn-move-subtheme');
        if(btnSub){
          const id = btnSub.dataset.id;
          const tSel = qs('#move_subtheme_theme');
          qs('#move_subtheme_id').value = id;
          fillThemes(tSel);
          show('#modal-move-subtheme');
          return;
        }
        const closer = e.target.closest('[data-close]');
        if(closer){
          hide('#' + closer.getAttribute('data-close'));
          return;
        }
      });
      const fTitle = qs('#form-move-title');
      if(fTitle){
        const tSel = qs('#move_title_theme');
        const sSel = qs('#move_title_subtheme');
        tSel && tSel.addEventListener('change', (ev)=> fillSubthemes(ev.target.value, sSel, true));
      }
      
      // --- Logika untuk Modal Rename ---
      const renameModalEl = document.getElementById('renameModal');
      if (renameModalEl) {
          const renameModal = new bootstrap.Modal(renameModalEl);
          const renameItemId = document.getElementById('renameItemId');
          const renameItemType = document.getElementById('renameItemType');
          const renameItemName = document.getElementById('renameItemName');
          const renameModalLabel = document.getElementById('renameModalLabel');

          document.addEventListener('click', function (event) {
              const renameButton = event.target.closest('.btn-rename');
              if (renameButton) {
                  const id = renameButton.dataset.id;
                  const type = renameButton.dataset.type;
                  const currentName = renameButton.dataset.name;
                  
                  renameItemId.value = id;
                  renameItemType.value = type;
                  renameItemName.value = currentName;
                  renameModalLabel.textContent = 'Ubah Nama ' + type.charAt(0).toUpperCase() + type.slice(1);
                  
                  renameModal.show();
              }
          });
      }
    })();
});
</script>
JS;

  // ====================================================================
  // ▲▲▲ AKHIR BLOK MODAL DAN SCRIPT (VERSI PERBAIKAN) ▲▲▲
  // ====================================================================

}




// ===============================================
// AKHIR VIEW CRUD
// ===============================================

function view_crud_pengajar()
{
    if (!is_pengajar() && !is_admin()) {
        echo '<div class="alert alert-danger">Akses ditolak.</div>';
        return;
    }

    // Wrap dalam container untuk margin/padding yang benar
    echo '<div class="container mt-4">';

    $user_id = uid();
    $sel_theme_id    = isset($_GET['theme_id']) ? (int)$_GET['theme_id'] : 0;
    $sel_subtheme_id = isset($_GET['subtheme_id']) ? (int)$_GET['subtheme_id'] : 0;
    
    // Tampilkan notifikasi jika ada
    if (isset($_GET['ok'])) echo '<div class="alert alert-success">Operasi berhasil.</div>';
    if (isset($_GET['err'])) echo '<div class="alert alert-danger">Terjadi kesalahan.</div>';

    echo '<h3>Bank Soal Saya</h3>';
    echo '<p class="text-muted">Kelola Tema, Subtema, dan Judul Kuis pribadi Anda.</p>';

    echo '<div class="row g-3">';

    // ===============================================
    // Kolom 1: TEMA (FILTER: owner_user_id = uid())
    // ===============================================
    echo '<div class="col-md-4"><div class="card"><div class="card-body">';
    echo '<h5 class="mb-3">Tema Pribadi</h5>';
    
    // Form Tambah Tema
    echo '<form method="post" action="?action=crud_post_pengajar" class="mb-3">';
    echo '<input type="hidden" name="act" value="add_theme">';
    echo '<div class="input-group mb-1">';
    echo '  <input class="form-control" name="name" placeholder="Nama tema" required>';
    echo '  <button class="btn btn-success" type="submit">Tambah Tema</button>';
    echo '</div>';
    echo '</form>';
    
    $themes = q("SELECT id,name FROM themes WHERE owner_user_id = ? ORDER BY name", [$user_id])->fetchAll();
    
    if (!$themes) {
        echo '<div class="alert alert-secondary">Belum ada tema pribadi.</div>';
    } else {
        echo '<div class="list-group">';
        foreach ($themes as $t) {
            $active = ($sel_theme_id === (int)$t['id']) ? ' active' : '';
            $url    = '?page=teacher_crud&theme_id=' . $t['id'];
            echo '<div class="list-group-item' . $active . ' d-flex justify-content-between align-items-center">';
            
            echo '<div><a class="text-decoration-none ' . ($active ? 'text-white' : '') . '" href="' . $url . '">' . h($t['name']) . '</a></div>';
            
            echo '<div class="ms-2 d-flex gap-1">';
            echo '<button type="button" class="btn btn-sm btn-primary btn-rename" data-id="' . $t['id'] . '" data-type="theme" data-name="' . h($t['name']) . '">Rename</button>';

            echo '<form method="post" action="?action=crud_post_pengajar" class="d-inline" onsubmit="return confirm(\'Hapus TEMA beserta semua isinya?\\nTindakan ini tidak bisa dibatalkan.\')">';
            echo '<input type="hidden" name="act" value="delete_theme">';
            echo '<input type="hidden" name="theme_id" value="' . $t['id'] . '">';
            echo '<button type="submit" class="btn btn-sm btn-danger">Hapus</button></form>';
            echo '</div></div>';
        }
        echo '</div>';
    }
    
    echo '</div></div></div>'; // END Kolom 1

    // ===============================================
    // Kolom 2: SUBTEMA (FILTER: owner_user_id = uid())
    // ===============================================
    echo '<div class="col-md-4"><div class="card"><div class="card-body">';
    echo '<h5 class="mb-3">Subtema Pribadi</h5>';
    if ($sel_theme_id > 0) {
        // Form Tambah Subtema
        echo '<form method="post" action="?action=crud_post_pengajar" class="mb-3">';
        echo '<input type="hidden" name="act" value="add_subtheme">';
        echo '<input type="hidden" name="theme_id" value="' . $sel_theme_id . '">';
        echo '<div class="input-group">';
        echo '  <input class="form-control" name="name" placeholder="Nama subtema" required>';
        echo '  <button class="btn btn-success" type="submit">Tambah Subtema</button>';
        echo '</div>';
        echo '</form>';

        // Filter Subtema
        $subs = q("SELECT id,name FROM subthemes WHERE theme_id=? AND owner_user_id = ? ORDER BY name", [$sel_theme_id, $user_id])->fetchAll();
        if (!$subs) {
            echo '<div class="alert alert-secondary">Belum ada subtema pada tema ini.</div>';
        } else {
            echo '<div class="list-group">';
            foreach ($subs as $s) {
                $active = ($sel_subtheme_id === (int)$s['id']) ? ' active' : '';
                $url    = '?page=teacher_crud&theme_id=' . $sel_theme_id . '&subtheme_id=' . $s['id'];
                echo '<div class="list-group-item' . $active . ' d-flex justify-content-between align-items-center">';
                
                echo '<div><a class="text-decoration-none ' . ($active ? 'text-white' : '') . '" href="' . $url . '">' . h($s['name']) . '</a></div>';
                
                echo '<div class="ms-2 d-flex gap-1">';
                echo '<button type="button" class="btn btn-sm btn-primary btn-rename" 
                        data-id="' . $s['id'] . '" 
                        data-type="subtheme" 
                        data-name="' . h($s['name']) . '">Rename</button>';
                
                echo '<form method="post" action="?action=crud_post_pengajar" class="d-inline" onsubmit="return confirm(\'Hapus SUBTEMA beserta semua judul & soal di dalamnya?\\nTidak bisa dibatalkan.\')">';
                echo '<input type="hidden" name="act" value="delete_subtheme">';
                echo '<input type="hidden" name="subtheme_id" value="' . $s['id'] . '">';
                echo '<button type="submit" class="btn btn-sm btn-danger">Hapus</button></form>';
                echo '</div></div>';
            }
            echo '</div>';
        }
    } else {
        echo '<div class="alert alert-info">Pilih Tema di kolom kiri.</div>';
    }
    echo '</div></div></div>'; // END Kolom 2

    // ===============================================
    // Kolom 3: JUDUL (FILTER: owner_user_id = uid())
    // ===============================================
    echo '<div class="col-md-4"><div class="card"><div class="card-body">';
    echo '<h5 class="mb-3">Judul Soal Pribadi</h5>';
    if ($sel_subtheme_id > 0) {
        // Form Tambah Judul
        echo '<form method="post" action="?action=crud_post_pengajar" class="mb-3">';
        echo '<input type="hidden" name="act" value="add_title">';
        echo '<input type="hidden" name="subtheme_id" value="' . $sel_subtheme_id . '">';
        echo '<div class="input-group">';
        echo '  <input class="form-control" name="title" placeholder="Nama judul soal" required>';
        echo '  <button class="btn btn-success" type="submit">Tambah Judul</button>';
        echo '</div>';
        echo '</form>';
        
        // Filter Judul
        $titles = q("SELECT id,title FROM quiz_titles WHERE subtheme_id=? AND owner_user_id = ? ORDER BY title", [$sel_subtheme_id, $user_id])->fetchAll();
        
        if (!$titles) {
            echo '<div class="alert alert-secondary">Belum ada judul pada subtema ini.</div>';
        } else {
            echo '<div class="list-group">';
            foreach ($titles as $t) {
                echo '<div class="list-group-item d-flex justify-content-between align-items-center">';
                
                // Link ke Kelola Soal (teacher_qmanage)
                echo '<div><a class="text-decoration-none fw-semibold" href="?page=teacher_qmanage&title_id=' . $t['id'] . '">' . h($t['title']) . '</a></div>';
                
                echo '<div class="ms-2 d-flex gap-1">';

                // Tombol Rename Judul
                echo '<button type="button" class="btn btn-sm btn-primary btn-rename" 
                        data-id="' . $t['id'] . '" 
                        data-type="title" 
                        data-name="' . h($t['title']) . '">Rename</button>';

                // Form Hapus Judul
                echo '<form method="post" action="?action=crud_post_pengajar" class="d-inline" onsubmit="return confirm(\'Hapus JUDUL beserta semua soal di dalamnya?\\nTidak bisa dibatalkan.\')">';
                echo '<input type="hidden" name="act" value="delete_title">';
                echo '<input type="hidden" name="title_id" value="' . $t['id'] . '">';
                echo '<button type="submit" class="btn btn-sm btn-danger">Hapus</button></form>';
                echo '</div></div>';
            }
            echo '</div>'; // Tutup list-group

            // Tombol Import Soal Master (Perbaikan telah dilakukan untuk mencegah error)
            // Menggunakan ID judul pertama ($titles[0]['id']) sebagai TARGET import.
            echo '<hr><button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#importMasterSoalModal" data-target-title-id="' . $titles[0]['id'] . '">Import Soal Master</button>';
            
        } // Tutup else ($titles tidak kosong)
        
    } else {
        echo '<div class="alert alert-info">Pilih Subtema di kolom tengah.</div>';
    }
    echo '</div></div></div>'; // END Kolom 3

    echo '</div>'; // END .row g-3
    echo '</div>'; // END .container
    
    // Catatan: Pastikan Anda menambahkan HTML MODAL Import Soal Master di bagian akhir file Anda, 
    // seperti yang diinstruksikan pada jawaban sebelumnya.
}

/**
 * Memproses form untuk menambah institusi baru milik pengajar.
 */
function handle_tambah_institusi()
{
  if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SESSION['user']['role'] ?? '') !== 'pengajar') {
    redirect('./');
  }

  $nama_institusi = trim($_POST['nama_institusi'] ?? '');
  $id_pengajar = uid();

  if (!empty($nama_institusi)) {
    // Cek agar tidak duplikat
    $exists = q("SELECT id FROM teacher_institutions WHERE id_pengajar = ? AND nama_institusi = ?", [$id_pengajar, $nama_institusi])->fetch();
    if (!$exists) {
      q("INSERT INTO teacher_institutions (id_pengajar, nama_institusi) VALUES (?, ?)", [$id_pengajar, $nama_institusi]);
    }
  }
  redirect('?page=kelola_kelas');
}

/**
 * Memproses form untuk menambah kelas baru di dalam sebuah institusi.
 */
function handle_tambah_kelas()
{
  if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SESSION['user']['role'] ?? '') !== 'pengajar') {
    redirect('./');
  }

  $nama_kelas = trim($_POST['nama_kelas'] ?? '');
  $id_institusi = (int)($_POST['id_institusi'] ?? 0);
  $id_pengajar = uid();

  // Verifikasi kepemilikan institusi sebelum menambah kelas
  $is_owner = q("SELECT id FROM teacher_institutions WHERE id = ? AND id_pengajar = ?", [$id_institusi, $id_pengajar])->fetch();

  if (!empty($nama_kelas) && $is_owner) {
    q(
      "INSERT INTO classes (nama_kelas, id_pengajar, id_institusi) VALUES (?, ?, ?)",
      [$nama_kelas, $id_pengajar, $id_institusi]
    );
  }

  // Kembali ke halaman detail institusi tersebut
  redirect('?page=kelola_institusi');
}


/**
 * Memproses form untuk menambah/memperbarui anggota kelas.
 */
function handle_tambah_anggota()
{
  if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SESSION['user']['role'] ?? '') !== 'pengajar') {
    redirect('./');
  }

  $id_kelas = (int)($_POST['id_kelas'] ?? 0);
  $id_pengajar = uid();

  // Keamanan: Verifikasi lagi kepemilikan kelas
  $is_owner = q("SELECT id FROM classes WHERE id = ? AND id_pengajar = ?", [$id_kelas, $id_pengajar])->fetch();
  if (!$is_owner) {
    redirect('?page=kelola_kelas'); // Arahkan jika bukan pemilik
  }

  // Ambil daftar ID pelajar yang dicentang dari form
  $pelajar_ids = $_POST['pelajar_ids'] ?? [];

  // Gunakan metode "sinkronisasi": hapus semua anggota lama, lalu masukkan yang baru.
  // Ini cara paling sederhana dan aman untuk menangani penambahan & pengurangan anggota.

  // 1. Hapus semua anggota lama dari kelas ini
  q("DELETE FROM class_members WHERE id_kelas = ?", [$id_kelas]);

  // 2. Masukkan semua anggota yang baru dipilih
  if (!empty($pelajar_ids)) {
    $stmt = pdo()->prepare("INSERT INTO class_members (id_kelas, id_pelajar) VALUES (?, ?)");
    foreach ($pelajar_ids as $id_pelajar) {
      $stmt->execute([$id_kelas, (int)$id_pelajar]);
    }
  }

  // Kembali ke halaman detail kelas dengan notifikasi sukses
  redirect('?page=detail_kelas&id_kelas=' . $id_kelas . '&ok=1');
}





// >>> TAMBAHKAN FUNGSI BARU DI SINI (setelah handle_tambah_anggota) <<<

/**
 * Memproses form edit nama kelas.
 */
function handle_edit_kelas()
{
    // Keamanan dasar dan validasi input
    if (!is_pengajar() || $_SERVER['REQUEST_METHOD'] !== 'POST') redirect('./');
    $id_pengajar = uid();
    $id_kelas = isset($_POST['id_kelas']) ? (int)$_POST['id_kelas'] : 0;
    $nama_kelas_baru = isset($_POST['nama_kelas']) ? trim($_POST['nama_kelas']) : '';

    if ($id_kelas <= 0 || empty($nama_kelas_baru)) {
        redirect('?page=kelola_institusi&err=edit_invalid');
    }

    // Verifikasi kepemilikan kelas sebelum update
    $kelas = q("SELECT id, id_institusi FROM classes WHERE id = ? AND id_pengajar = ?", [$id_kelas, $id_pengajar])->fetch();

    if ($kelas) {
        // Lakukan update nama kelas
        q("UPDATE classes SET nama_kelas = ? WHERE id = ?", [$nama_kelas_baru, $id_kelas]);
        // Redirect kembali ke halaman institusi dengan pesan sukses
        redirect('?page=kelola_institusi&ok=kelas_edited');
    } else {
        // Redirect jika bukan pemilik atau kelas tidak ada
        redirect('?page=kelola_institusi&err=kelas_not_found');
    }
}

/**
 * Memproses permintaan hapus kelas.
 */
function handle_delete_kelas()
{
    // Keamanan dasar dan validasi input
    if (!is_pengajar() || $_SERVER['REQUEST_METHOD'] !== 'POST') redirect('./');
    $id_pengajar = uid();
    $id_kelas = isset($_POST['id_kelas']) ? (int)$_POST['id_kelas'] : 0;

    if ($id_kelas <= 0) {
         redirect('?page=kelola_institusi&err=delete_invalid');
    }

    // Verifikasi kepemilikan kelas sebelum hapus
    $kelas = q("SELECT id, id_institusi FROM classes WHERE id = ? AND id_pengajar = ?", [$id_kelas, $id_pengajar])->fetch();

    if ($kelas) {
        // Hapus data terkait terlebih dahulu (anggota dan tugas)
        // (Asumsi tidak ada ON DELETE CASCADE di database)
        q("DELETE FROM class_members WHERE id_kelas = ?", [$id_kelas]);
        q("DELETE FROM assignments WHERE id_kelas = ?", [$id_kelas]); // Ini juga akan menghapus assignment_submissions jika ada cascade

        // Hapus kelasnya
        q("DELETE FROM classes WHERE id = ?", [$id_kelas]);

        // Redirect kembali ke halaman institusi dengan pesan sukses
        redirect('?page=kelola_institusi&ok=kelas_deleted');
    } else {
         // Redirect jika bukan pemilik atau kelas tidak ada
        redirect('?page=kelola_institusi&err=kelas_not_found');
    }
}

/**
 * Memproses permintaan hapus institusi.
 */
function handle_delete_institusi()
{
    // Keamanan dasar dan validasi input
    if (!is_pengajar() || $_SERVER['REQUEST_METHOD'] !== 'POST') redirect('./');
    $id_pengajar = uid();
    $id_institusi = isset($_POST['id_institusi']) ? (int)$_POST['id_institusi'] : 0;

    if ($id_institusi <= 0) {
        redirect('?page=kelola_institusi&err=delete_invalid');
    }

    // Verifikasi kepemilikan institusi sebelum hapus
    $institusi = q("SELECT id FROM teacher_institutions WHERE id = ? AND id_pengajar = ?", [$id_institusi, $id_pengajar])->fetch();

    if ($institusi) {
        // 1. Ambil semua ID kelas dalam institusi ini
        $kelas_ids = q("SELECT id FROM classes WHERE id_institusi = ?", [$id_institusi])->fetchAll(PDO::FETCH_COLUMN);

        if ($kelas_ids) {
            // Buat placeholder (?) sebanyak jumlah kelas
            $placeholders = implode(',', array_fill(0, count($kelas_ids), '?'));

            // 2. Hapus semua anggota dari semua kelas tersebut
            q("DELETE FROM class_members WHERE id_kelas IN ($placeholders)", $kelas_ids);
            // 3. Hapus semua tugas dari semua kelas tersebut
            q("DELETE FROM assignments WHERE id_kelas IN ($placeholders)", $kelas_ids);
            // 4. Hapus semua kelas dalam institusi ini
            q("DELETE FROM classes WHERE id_institusi = ?", [$id_institusi]);
        }

        // 5. Hapus institusi itu sendiri
        q("DELETE FROM teacher_institutions WHERE id = ?", [$id_institusi]);

        // Redirect kembali ke halaman institusi dengan pesan sukses
        redirect('?page=kelola_institusi&ok=institusi_deleted');
    } else {
        // Redirect jika bukan pemilik atau institusi tidak ada
        redirect('?page=kelola_institusi&err=institusi_not_found');
    }
}

// >>> AKHIR FUNGSI BARU <<<

// ===============================================
// AWAL CRUD POST
// ===============================================

function crud_post()
{
  $act = $_POST['act'] ?? $_GET['act'] ?? '';

  if (!is_admin()) {
    http_response_code(403);
    exit('Forbidden');
  }
  $act = $_POST['act'] ?? '';



  if ($act === 'add_theme') {
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    if ($name !== '') {
      $max = q("SELECT COALESCE(MAX(sort_order), 0) AS m FROM themes")->fetch();
      $next = (int)$max['m'] + 10;
      q("INSERT INTO themes (name, description, sort_order) VALUES (?,?,?)", [$name, $desc, $next]);
      $new_id = (int)pdo()->lastInsertId();
      redirect('?page=crud&theme_id=' . $new_id);
    }
    redirect('?page=crud');
  }

  if ($act === 'add_subtheme') {
    $theme_id = (int)($_POST['theme_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if ($theme_id > 0 && $name !== '') {
      q("INSERT INTO subthemes (theme_id, name) VALUES (?,?)", [$theme_id, $name]);
      $new_id = (int)pdo()->lastInsertId();
      redirect('?page=crud&theme_id=' . $theme_id . '&subtheme_id=' . $new_id);
    }
    redirect('?page=crud');
  }

  // ---- TITLE ADD ----
  if ($act === 'add_title') {
    $subtheme_id = (int)($_POST['subtheme_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    if ($subtheme_id > 0 && $title !== '') {
      q("INSERT INTO quiz_titles (subtheme_id, title) VALUES (?,?)", [$subtheme_id, $title]);
      $new_id = (int)pdo()->lastInsertId();

      // ▼▼▼ BAGIAN YANG DIPERBAIKI ▼▼▼
      // Ambil nama subtema untuk notifikasi yang lebih deskriptif
      $subtheme_info = q("SELECT name FROM subthemes WHERE id = ?", [$subtheme_id])->fetch();
      $subtheme_name = $subtheme_info['name'] ?? '';

      // Buat 1 notifikasi broadcast untuk semua pengguna
      $notif_message = "Kuis baru di subtema \"" . h($subtheme_name) . "\": \"" . h($title) . "\"";
      $notif_link = "?page=play&title_id=" . $new_id;

      q(
        "INSERT INTO broadcast_notifications (type, message, link, related_id) VALUES ('new_quiz', ?, ?, ?)",
        [$notif_message, $notif_link, $new_id]
      );

      // ▲▲▲ AKHIR DARI BAGIAN YANG DIPERBAIKI ▲▲▲

      redirect('?page=qmanage&title_id=' . $new_id);
    }
    redirect('?page=crud');
  }

  if ($act === 'delete_theme') {
    $id = (int)($_POST['theme_id'] ?? 0);
    if ($id > 0) {
      q("DELETE FROM themes WHERE id=?", [$id]);
    }
    redirect('?page=crud');
  }





  if ($act === 'delete_subtheme') {
    $id = (int)($_POST['subtheme_id'] ?? 0);
    if ($id > 0) {
      $row = q("SELECT theme_id FROM subthemes WHERE id=?", [$id])->fetch();
      $theme_id = $row ? (int)$row['theme_id'] : 0;
      q("DELETE FROM subthemes WHERE id=?", [$id]);
      redirect('?page=crud&theme_id=' . $theme_id);
    }
    redirect('?page=crud');
  }






  if ($act === 'delete_title') {
    $id = (int)($_POST['title_id'] ?? 0);
    if ($id > 0) {
      $row = q("SELECT subtheme_id FROM quiz_titles WHERE id=?", [$id])->fetch();
      $sid = $row ? (int)$row['subtheme_id'] : 0;
      $theme_id = 0;
      if ($sid) {
        $r2 = q("SELECT theme_id FROM subthemes WHERE id=?", [$sid])->fetch();
        $theme_id = $r2 ? (int)$r2['theme_id'] : 0;
      }
      q("DELETE FROM quiz_titles WHERE id=?", [$id]);
      redirect('?page=crud&theme_id=' . $theme_id . '&subtheme_id=' . $sid);
    }
    redirect('?page=crud');
  }

  if ($act === 'move_subtheme') {
    $subtheme_id = (int)($_POST['subtheme_id'] ?? 0);
    $target_theme_id = (int)($_POST['target_theme_id'] ?? 0);

    if ($subtheme_id > 0 && $target_theme_id > 0) {
      $ok = q("SELECT id FROM subthemes WHERE id=?", [$subtheme_id])->fetch();
      if ($ok) {
        q("UPDATE subthemes SET theme_id=? WHERE id=?", [$target_theme_id, $subtheme_id]);
      }
    }
    redirect('?page=crud&theme_id=' . $target_theme_id);
  }

  if ($act === 'move_title') {
    $title_id = (int)($_POST['title_id'] ?? 0);
    $target_subtheme_id = (int)($_POST['target_subtheme_id'] ?? 0);

    if ($title_id > 0 && $target_subtheme_id > 0) {
      $title_ok = q("SELECT id FROM quiz_titles WHERE id=?", [$title_id])->fetch();
      $subtheme_ok = q("SELECT id FROM subthemes WHERE id=?", [$target_subtheme_id])->fetch();

      if ($title_ok && $subtheme_ok) {
        q("UPDATE quiz_titles SET subtheme_id=? WHERE id=?", [$target_subtheme_id, $title_id]);
      }
    }

    $subtheme_info = q("SELECT theme_id FROM subthemes WHERE id=?", [$target_subtheme_id])->fetch();
    $target_theme_id = $subtheme_info['theme_id'] ?? 0;
    redirect('?page=crud&theme_id=' . $target_theme_id . '&subtheme_id=' . $target_subtheme_id);
  }


  if ($act === 'rename_item') {
    $id   = (int)($_POST['item_id'] ?? 0);
    $type = $_POST['item_type'] ?? '';
    $name = trim($_POST['name'] ?? '');

    if ($id > 0 && $name !== '' && in_array($type, ['theme', 'subtheme', 'title'])) {
      $table = '';
      $id_col = '';

      switch ($type) {
        case 'theme':
          $table = 'themes';
          $id_col = 'id';
          break;
        case 'subtheme':
          $table = 'subthemes';
          $id_col = 'id';
          break;
        case 'title':
          $table = 'quiz_titles';
          $id_col = 'id';
          // Di sini kita ganti 'name' menjadi 'title' sesuai kolom di DB
          q("UPDATE quiz_titles SET title=? WHERE id=?", [$name, $id]);
          // Logika redirect untuk title sedikit berbeda, jadi kita handle khusus
          $row = q("SELECT subtheme_id FROM quiz_titles WHERE id=?", [$id])->fetch();
          $sid = $row ? (int)$row['subtheme_id'] : 0;
          $theme_id = 0;
          if ($sid) {
            $r2 = q("SELECT theme_id FROM subthemes WHERE id=?", [$sid])->fetch();
            $theme_id = $r2 ? (int)$r2['theme_id'] : 0;
          }
          redirect('?page=crud&theme_id=' . $theme_id . '&subtheme_id=' . $sid);
          break; // Penting untuk keluar setelah redirect
      }

      // Jalankan query untuk theme dan subtheme
      if ($table && $id_col) {
        q("UPDATE {$table} SET name=? WHERE {$id_col}=?", [$name, $id]);
      }
    }
    // Redirect umum untuk theme dan subtheme
    redirect('?page=crud&' . $_SERVER['QUERY_STRING']);
  }
  // ▲▲▲ AKHIR BLOK RENAME UNIVERSAL ▲▲▲


}

// ===============================================
// AKHIR CRUD POST
// ===============================================


function crud_post_pengajar()
{
    // Cek otorisasi: hanya pengajar atau admin yang boleh mengakses.
    if (!is_pengajar() && !is_admin()) {
        http_response_code(403);
        exit('Akses ditolak.');
    }
    
    $act = $_POST['act'] ?? '';
    $user_id = uid(); // ID pengajar yang sedang login

    // Fungsi helper untuk redirect ke QManage yang benar
    $redirect_qmanage = function($title_id, $ok = 1) {
        redirect('?page=teacher_qmanage&title_id=' . $title_id . '&ok=' . $ok);
    };
    
    // Fungsi untuk memverifikasi kepemilikan soal
    $get_title_id_and_verify = function($qid) use ($user_id) {
        $q_info = q("SELECT title_id FROM questions WHERE id=? AND owner_user_id=?", [$qid, $user_id])->fetch();
        if (!$q_info) return 0;
        return (int)$q_info['title_id'];
    };


    // ==========================================
    // AKSI CRUD TEMA / SUBTEMA / JUDUL
    // ==========================================

    if ($act === 'add_theme') {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $url_redirect = '?page=teacher_crud';
        if ($name !== '') {
            q("INSERT INTO themes (name, description, owner_user_id) VALUES (?,?,?)", [$name, $desc, $user_id]);
            $new_id = pdo()->lastInsertId();
            $url_redirect = '?page=teacher_crud&theme_id=' . $new_id;
        }
        redirect($url_redirect);
    }

    if ($act === 'delete_theme') {
        $id = (int)($_POST['theme_id'] ?? 0);
        if ($id > 0) {
            q("DELETE FROM themes WHERE id=? AND owner_user_id=?", [$id, $user_id]);
        }
        redirect('?page=teacher_crud&ok=1');
    }

    if ($act === 'add_subtheme') {
        $theme_id = (int)($_POST['theme_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $url_redirect = '?page=teacher_crud';
        
        $is_owner = q("SELECT id FROM themes WHERE id=? AND owner_user_id=?", [$theme_id, $user_id])->fetch();

        if ($is_owner && $name !== '') {
            q("INSERT INTO subthemes (theme_id, name, owner_user_id) VALUES (?,?,?)", [$theme_id, $name, $user_id]);
            $new_id = pdo()->lastInsertId();
            $url_redirect = '?page=teacher_crud&theme_id=' . $theme_id . '&subtheme_id=' . $new_id;
        }
        redirect($url_redirect);
    }
    
    if ($act === 'delete_subtheme') {
        $id = (int)($_POST['subtheme_id'] ?? 0);
        $theme_id = 0;
        if ($id > 0) {
            $row = q("SELECT theme_id FROM subthemes WHERE id=? AND owner_user_id=?", [$id, $user_id])->fetch();
            $theme_id = $row ? (int)$row['theme_id'] : 0;
            q("DELETE FROM subthemes WHERE id=? AND owner_user_id=?", [$id, $user_id]);
        }
        redirect('?page=teacher_crud&theme_id=' . $theme_id . '&ok=1');
    }

    if ($act === 'add_title') {
        $subtheme_id = (int)($_POST['subtheme_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $url_redirect = '?page=teacher_crud';

        $is_owner = q("SELECT id FROM subthemes WHERE id=? AND owner_user_id=?", [$subtheme_id, $user_id])->fetch();
        
        if ($is_owner && $title !== '') {
            q("INSERT INTO quiz_titles (subtheme_id, title, owner_user_id) VALUES (?,?,?)", [$subtheme_id, $title, $user_id]);
            $new_id = pdo()->lastInsertId();
            $url_redirect = '?page=teacher_qmanage&title_id=' . $new_id;
        }
        redirect($url_redirect);
    }

    if ($act === 'delete_title') {
        $id = (int)($_POST['title_id'] ?? 0);
        $subtheme_id = 0;
        if ($id > 0) {
            $row = q("SELECT subtheme_id FROM quiz_titles WHERE id=? AND owner_user_id=?", [$id, $user_id])->fetch();
            $subtheme_id = $row ? (int)$row['subtheme_id'] : 0;
            q("DELETE FROM quiz_titles WHERE id=? AND owner_user_id=?", [$id, $user_id]);
        }
        redirect('?page=teacher_crud&subtheme_id=' . $subtheme_id . '&ok=1');
    }
    
    if ($act === 'rename_item') {
        $id = (int)($_POST['item_id'] ?? 0);
        $type = $_POST['item_type'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $redirect_to = '?page=teacher_crud';
        
        if ($id > 0 && $name !== '') {
            $table = '';
            $col_name = 'name';
            
            switch ($type) {
                case 'theme': $table = 'themes'; $redirect_to = '?page=teacher_crud&theme_id=' . $id; break;
                case 'subtheme': $table = 'subthemes'; $col_name = 'name';
                    $row = q("SELECT theme_id FROM subthemes WHERE id=? AND owner_user_id=?", [$id, $user_id])->fetch();
                    if ($row) $redirect_to = '?page=teacher_crud&theme_id=' . $row['theme_id'] . '&subtheme_id=' . $id; 
                    break;
                case 'title': $table = 'quiz_titles'; $col_name = 'title'; 
                    $row = q("SELECT subtheme_id FROM quiz_titles WHERE id=? AND owner_user_id=?", [$id, $user_id])->fetch();
                    if ($row) {
                        $theme_row = q("SELECT theme_id FROM subthemes WHERE id=?", [$row['subtheme_id']])->fetch();
                        $redirect_to = '?page=teacher_crud&theme_id=' . $theme_row['theme_id'] . '&subtheme_id=' . $row['subtheme_id'];
                    }
                    break;
            }

            if ($table) {
                q("UPDATE {$table} SET {$col_name}=? WHERE id=? AND owner_user_id=?", [$name, $id, $user_id]);
            }
        }
        redirect($redirect_to);
    }
    
    
    // ==========================================
    // AKSI KELOLA SOAL (QManage)
    // ==========================================

    // --- Tambah Soal Baru (dengan kepemilikan) ---
    if ($act === 'add_question_dyn_pengajar') {
        $title_id = (int)$_POST['title_id'];
        $text = trim($_POST['text'] ?? '');
        $exp = trim($_POST['explanation'] ?? '');
        $choices = $_POST['choice_text'] ?? [];
        $correct_index = (int)($_POST['correct_index'] ?? 1);

        $is_owner = q("SELECT id FROM quiz_titles WHERE id = ? AND owner_user_id = ?", [$title_id, $user_id])->fetch();
        if (!$is_owner) redirect('?page=teacher_qmanage');
        
        q("INSERT INTO questions (title_id, owner_user_id, text, explanation, created_at) VALUES (?,?,?,?,?)", [$title_id, $user_id, $text, ($exp ?: null), now()]);
        $qid = pdo()->lastInsertId();

        $choices = array_values(array_filter(array_map('trim', $choices), fn($x) => $x !== ''));
        $n = count($choices);
        if ($n < 2 || $n > 5) die('Jumlah pilihan harus 2–5.');
        
        for ($i = 0; $i < $n; $i++) {
            $is = (int)(($i + 1) === $correct_index);
            q("INSERT INTO choices (question_id,text,is_correct) VALUES (?,?,?)", [$qid, $choices[$i], $is]);
        }
        
        $redirect_qmanage($title_id);
    }

    // --- Update Soal (dengan verifikasi kepemilikan) ---
    if ($act === 'update_question_dyn_pengajar') {
        $qid = (int)($_POST['question_id'] ?? 0);
        $title_id = $get_title_id_and_verify($qid);
        if ($title_id === 0) redirect('?page=teacher_qmanage');

        $text = trim($_POST['text'] ?? '');
        $exp = trim($_POST['explanation'] ?? '');

        // 1. Update Soal
        q("UPDATE questions SET text=?, explanation=?, updated_at=? WHERE id=? AND owner_user_id=?", [$text, ($exp ?: null), now(), $qid, $user_id]);
        
        // 2. Update Choices (Logika sinkronisasi)
        $cidArr = array_map('intval', $_POST['cid'] ?? []);
        $ctextArr = array_map('trim', $_POST['ctext'] ?? []);
        $correct_index = max(1, min(5, (int)($_POST['correct_index'] ?? 1)));
        
        $pairs = []; for ($i = 0; $i < count($ctextArr); $i++) { if ($ctextArr[$i] !== '') $pairs[] = ['id' => $cidArr[$i] ?? 0, 'text' => $ctextArr[$i]]; }
        $n = count($pairs); if ($n < 2 || $n > 5) die('Jumlah pilihan harus 2–5.');
        $oldIds = q("SELECT id FROM choices WHERE question_id=? ORDER BY id", [$qid])->fetchAll(PDO::FETCH_COLUMN);
        $newIds = [];
        foreach ($pairs as $p) {
            if ($p['id'] > 0) { q("UPDATE choices SET text=? WHERE id=?", [$p['text'], $p['id']]); $newIds[] = (int)$p['id']; } 
            else { q("INSERT INTO choices (question_id,text,is_correct) VALUES (?,?,0)", [$qid, $p['text']]); $newIds[] = (int)pdo()->lastInsertId(); }
        }
        foreach ($oldIds as $oid) { if (!in_array($oid, $newIds, true)) q("DELETE FROM choices WHERE id=?", [$oid]); }
        $correct_choice_id = $newIds[$correct_index - 1];
        q("UPDATE choices SET is_correct = CASE WHEN id=? THEN 1 ELSE 0 END WHERE question_id=?", [$correct_choice_id, $qid]);

        $redirect_qmanage($title_id);
    }

    // --- Hapus Soal (dengan verifikasi kepemilikan) ---
    if ($act === 'del_question_pengajar') {
        $qid = (int)$_POST['question_id'];
        $title_id = $get_title_id_and_verify($qid);
        if ($title_id === 0) redirect('?page=teacher_qmanage');
        
        q("DELETE FROM questions WHERE id=? AND owner_user_id=?", [$qid, $user_id]);
        
        $redirect_qmanage($title_id);
    }

    // --- Duplikat Soal (dengan kepemilikan) ---
    if ($act === 'duplicate_question_pengajar') {
        $original_qid = (int)($_POST['question_id'] ?? 0);
        $title_id = $get_title_id_and_verify($original_qid);
        if ($title_id === 0) redirect('?page=teacher_qmanage');
        
        $original_q = q("SELECT * FROM questions WHERE id=?", [$original_qid])->fetch();
        
        $new_text = "[DUPLIKAT] " . $original_q['text'];
        q("INSERT INTO questions (title_id, owner_user_id, text, explanation, attempts, corrects, created_at) VALUES (?, ?, ?, ?, 0, 0, ?)",
          [$title_id, $user_id, $new_text, $original_q['explanation'], now()]
        );
        $new_qid = pdo()->lastInsertId();

        $original_choices = q("SELECT * FROM choices WHERE question_id=?", [$original_qid])->fetchAll();
        if ($original_choices) {
          foreach ($original_choices as $choice) {
            q("INSERT INTO choices (question_id, text, is_correct) VALUES (?, ?, ?)",
              [$new_qid, $choice['text'], $choice['is_correct']]
            );
          }
        }
        
        $redirect_qmanage($title_id);
    }
    
    // --- Import Master Question ---
    if ($act === 'import_master_question') {
        $master_title_id = (int)($_POST['master_title_id'] ?? 0);
        $target_title_id = (int)($_POST['target_title_id'] ?? 0);
        $num_questions = (int)($_POST['num_questions'] ?? 0);
        
        $is_target_owner = q("SELECT id FROM quiz_titles WHERE id = ? AND owner_user_id = ?", [$target_title_id, $user_id])->fetch();
        if (!$is_target_owner) redirect('?page=teacher_crud&err=not_owner');

        $is_master = q("SELECT id FROM quiz_titles WHERE id = ? AND is_master = 1", [$master_title_id])->fetch();
        if (!$is_master) redirect('?page=teacher_crud&err=not_master');

        $limit_sql = $num_questions > 0 ? "ORDER BY RAND() LIMIT $num_questions" : "";
        $master_questions = q("SELECT * FROM questions WHERE title_id = ? {$limit_sql}", [$master_title_id])->fetchAll();

        if ($master_questions) {
            foreach ($master_questions as $q) {
                // A. Duplikasi Pertanyaan (Tambahkan owner_user_id = $user_id)
                q("INSERT INTO questions (title_id, owner_user_id, text, explanation, created_at) VALUES (?, ?, ?, ?, ?)",
                  [$target_title_id, $user_id, $q['text'], $q['explanation'], now()]);
                $new_qid = pdo()->lastInsertId();

                // B. Duplikasi Pilihan Jawaban
                $master_choices = q("SELECT * FROM choices WHERE question_id = ?", [$q['id']])->fetchAll();
                foreach ($master_choices as $c) {
                    q("INSERT INTO choices (question_id, text, is_correct) VALUES (?, ?, ?)",
                      [$new_qid, $c['text'], $c['is_correct']]);
                }
            }
        }
        
        redirect('?page=teacher_qmanage&title_id=' . $target_title_id . '&imported=1');
    }
}


function api_list_subthemes(int $theme_id)
{
  $pdo = pdo_instance();
  $stmt = $pdo->prepare("SELECT id, name FROM subthemes WHERE theme_id = ? ORDER BY name");
  $stmt->execute([$theme_id]);
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}

// ===============================================
// ADAPTIVE / SKILL
// ===============================================
function user_skill($user_id)
{
  if (!$user_id) return 0.3; // belum login → anggap pemula
  $r = q("SELECT AVG(score) avg FROM results WHERE user_id=?", [$user_id])->fetch();
  $avg = (float)($r['avg'] ?? 0);
  return $avg ? max(0.1, min(0.95, $avg / 100)) : 0.4;
}

// ===============================================
// GOOGLE VERIFY (simple via tokeninfo endpoint)
// ===============================================
function verify_google_id_token($idToken)
{
  global $CONFIG;
  if (!$idToken) return null;

  // Pakai endpoint tokeninfo (cukup untuk MVP); produksi idealnya verifikasi JWT RS256 penuh.
  $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);

  // Pakai helper cURL stabil (HTTP/1.1, SSL verify ON). Set param 3 = true jika Anda menaruh cacert.pem lokal.
  $data = http_get_json($url, 7 /*timeout*/, false /*force_local_cacert*/);
  if (!$data) return null;

  // Validasi minimal yang aman
  if (($data['aud'] ?? '') !== ($CONFIG['GOOGLE_CLIENT_ID'] ?? '')) return null;

  // Issuer Google yang valid
  $iss = $data['iss'] ?? '';
  if ($iss !== 'accounts.google.com' && $iss !== 'https://accounts.google.com') return null;

  // Expired check (jaga-jaga jika tokeninfo tetap mengembalikan data)
  if (isset($data['exp']) && (int)$data['exp'] < time()) return null;

  // Email verified harus true (kadang string 'true' atau boolean true)
  $ev = $data['email_verified'] ?? '';
  if (!($ev === true || $ev === 'true' || $ev === 1 || $ev === '1')) return null;

  return $data; // berisi sub, email, name, picture, dsb.
}

// ===============================================
// SCHEMA & SEED
// ===============================================
function get_schema_sql()
{
  return <<<SQL
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  google_sub VARCHAR(64) NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  name VARCHAR(190) NOT NULL,
  avatar VARCHAR(300) NULL,
  role ENUM('user','admin') NOT NULL DEFAULT 'user',
  quiz_timer_seconds INT NOT NULL DEFAULT 30,
  exam_timer_minutes INT NOT NULL DEFAULT 60, -- ▼▼▼ TAMBAHKAN BARIS INI ▼▼▼
  name_locked TINYINT(1) NOT NULL DEFAULT 0,
  theme VARCHAR(20) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS themes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subthemes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  theme_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (theme_id) REFERENCES themes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS quiz_titles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  subtheme_id INT NOT NULL,
  title VARCHAR(200) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (subtheme_id) REFERENCES subthemes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title_id INT NOT NULL,
  text TEXT NOT NULL,
  explanation TEXT NULL,
  attempts INT NOT NULL DEFAULT 0,
  corrects INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  FOREIGN KEY (title_id) REFERENCES quiz_titles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS choices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  question_id INT NOT NULL,
  text VARCHAR(300) NOT NULL,
  is_correct TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS quiz_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  title_id INT NOT NULL,
  mode ENUM('instant','end','exam') NOT NULL, -- ▼▼▼ UBAH BARIS INI ▼▼▼
  guest_id VARCHAR(32) NULL,
  city VARCHAR(100) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (title_id) REFERENCES quiz_titles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS quiz_session_questions (
  session_id INT NOT NULL,
  question_id INT NOT NULL,
  sort_no INT NOT NULL,
  PRIMARY KEY(session_id,question_id),
  FOREIGN KEY (session_id) REFERENCES quiz_sessions(id) ON DELETE CASCADE,
  FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_id INT NOT NULL,
  question_id INT NOT NULL,
  choice_id INT NOT NULL,
  is_correct TINYINT(1) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (session_id) REFERENCES quiz_sessions(id) ON DELETE CASCADE,
  FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
  FOREIGN KEY (choice_id) REFERENCES choices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS results (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_id INT NOT NULL,
  user_id INT NULL,
  title_id INT NOT NULL,
  score INT NOT NULL,
  city VARCHAR(100) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (session_id) REFERENCES quiz_sessions(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (title_id) REFERENCES quiz_titles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS challenges (
  token VARCHAR(32) PRIMARY KEY,
  title_id INT NOT NULL,
  user_id INT NULL,
  owner_session_id INT NULL,
  owner_result_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (title_id) REFERENCES quiz_titles(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



SQL;
}
function seed_data()
{
  // 5 Tema default
  $themes = [
    ['Ilmu Bahasa', 'Bahasa, sastra, dan linguistik'],
    ['Ilmu Eksakta', 'Matematika, Fisika, Kimia, Biologi'],
    ['Ilmu Sosial', 'Sejarah, Geografi, Ekonomi, Sosiologi'],
    ['Ilmu Agama', 'Aqidah, Fiqh, Hadis, Tafsir'],
    ['Ilmu Umum', 'Pengetahuan umum & campuran']
  ];
  foreach ($themes as $t) {
    q("INSERT INTO themes (name,description,sort_order,created_at) VALUES (?,?,(SELECT COALESCE(MAX(sort_order),0)+1 FROM themes),?)", [$t[0], $t[1], now()]);
  }

  // Sub tema contoh per tema (1 sub tema)
  $themeRows = q("SELECT * FROM themes ORDER BY id")->fetchAll();
  $subsIds = [];
  foreach ($themeRows as $T) {
    q("INSERT INTO subthemes (theme_id,name,created_at) VALUES (?,?,?)", [$T['id'], 'Dasar', now()]);
    $subsIds[$T['name']] = pdo()->lastInsertId();
  }

  // Judul contoh per subtema
  $titlesMap = [];
  foreach ($subsIds as $themeName => $subId) {
    $J1 = $themeName . ' — Paket A';
    q("INSERT INTO quiz_titles (subtheme_id,title,created_at) VALUES (?,?,?)", [$subId, $J1, now()]);
    $titlesMap[$J1] = pdo()->lastInsertId();
    $J2 = $themeName . ' — Paket B';
    q("INSERT INTO quiz_titles (subtheme_id,title,created_at) VALUES (?,?,?)", [$subId, $J2, now()]);
    $titlesMap[$J2] = pdo()->lastInsertId();
  }

  // 3 soal per judul (sesuai requirement)
  foreach ($titlesMap as $title => $tid) {
    $qs = [
      ['Pertanyaan 1 untuk ' . $title, ['Pilihan A', 'Pilihan B', 'Pilihan C', 'Pilihan D'], 2, 'Penjelasan singkat soal 1.'],
      ['Pertanyaan 2 untuk ' . $title, ['Opsi 1', 'Opsi 2', 'Opsi 3', 'Opsi 4'], 1, 'Penjelasan singkat soal 2.'],
      ['Pertanyaan 3 untuk ' . $title, ['Iya', 'Tidak', 'Mungkin', 'Tidak tahu'], 3, 'Penjelasan singkat soal 3.'],
    ];
    foreach ($qs as $qrow) {
      q("INSERT INTO questions (title_id,text,explanation,created_at) VALUES (?,?,?,?)", [$tid, $qrow[0], $qrow[3], now()]);
      $qid = pdo()->lastInsertId();
      for ($i = 1; $i <= 4; $i++) {
        q("INSERT INTO choices (question_id,text,is_correct) VALUES (?,?,?)", [$qid, $qrow[1][$i - 1], (int)($i === $qrow[2])]);
      }
    }
  }
}


// >>> GANTI SELURUH FUNGSI view_qmanage() DENGAN KODE YANG SAMA PERSIS DENGAN YANG ADA DI FILE ANDA <<<
function view_qmanage()
{
  if (!is_admin()) {
    echo '<div class="alert alert-warning">Akses admin diperlukan.</div>';
    return;
  }

  $title_id = (int)($_GET['title_id'] ?? 0);

  echo '<h3 class="mb-3">Kelola Soal (CRUD)</h3>';

  // =================================================================
  // BAGIAN 1: INTERFACE PENCARIAN (Pengganti Dropdown)
  // =================================================================

  // Query untuk mengambil semua judul soal untuk data pencarian
  $all_titles_for_search = q("
        SELECT qt.id, CONCAT(t.name, ' › ', st.name, ' › ', qt.title) AS label
        FROM quiz_titles qt
        JOIN subthemes st ON st.id = qt.subtheme_id
        JOIN themes t ON t.id = st.theme_id
        ORDER BY t.name, st.name, qt.title
    ")->fetchAll();

  $searchable_list = [];
  foreach ($all_titles_for_search as $item) {
    $searchable_list[] = [
      'id' => $item['id'],
      'name' => $item['label'],
      // PENTING: URL di sini mengarah ke halaman qmanage, bukan play
      'url' => '?page=qmanage&title_id=' . $item['id'],
      'searchText' => strtolower($item['label'])
    ];
  }

  // Kotak Pencarian
  echo '
    <div class="mb-3 position-relative">
        <input type="text" id="qmanageSearchInput" class="form-control form-control-lg ps-5" placeholder="Cari Tema › Subtema › Judul Soal...">
        <div class="position-absolute top-50 start-0 translate-middle-y ps-3 text-muted">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-search" viewBox="0 0 16 16"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z"/></svg>
        </div>
    </div>';

  // Container untuk hasil pencarian
  echo '<div id="qmanageSearchResults" class="list-group mb-3" style="display: none;"></div>';
  echo '<div id="qmanageSearchNoResults" class="alert alert-warning" style="display: none;">Tidak ada hasil ditemukan.</div>';

  // Tanam data pencarian ke dalam script
  echo '<script id="qmanageSearchData" type="application/json">' . json_encode($searchable_list) . '</script>';

  // JavaScript untuk mengaktifkan pencarian
  echo <<<JS
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('qmanageSearchInput');
        const searchResults = document.getElementById('qmanageSearchResults');
        const noResults = document.getElementById('qmanageSearchNoResults');
        const searchData = JSON.parse(document.getElementById('qmanageSearchData').textContent);

        searchInput.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();

            if (query === '') {
                searchResults.style.display = 'none';
                noResults.style.display = 'none';
                return;
            }

            searchResults.innerHTML = '';
            const matches = searchData.filter(item => item.searchText.includes(query));

            if (matches.length > 0) {
                searchResults.style.display = 'block';
                noResults.style.display = 'none';
                matches.forEach(item => {
                    const a = document.createElement('a');
                    a.href = item.url;
                    a.className = 'list-group-item list-group-item-action';
                    a.textContent = item.name;
                    searchResults.appendChild(a);
                });
            } else {
                searchResults.style.display = 'none';
                noResults.style.display = 'block';
            }
        });
    });
    </script>
    JS;

  // =================================================================
  // BAGIAN 2: TAMPILAN KELOLA SOAL (Hanya muncul jika judul dipilih)
  // =================================================================

  if (!$title_id) {
    echo '<div class="alert alert-info mt-3">Silakan cari dan pilih judul soal di atas untuk mulai mengelola.</div>';
    return; // Hentikan eksekusi jika belum ada judul yang dipilih
  }
  
  // ▼▼▼ AWAL BLOK PERUBAHAN ▼▼▼

  // 1. Ambil informasi lengkap tentang judul, subtema, dan tema
  $title_info = q("
      SELECT 
          qt.title, 
          st.name AS subtheme_name, 
          t.name AS theme_name
      FROM quiz_titles qt
      JOIN subthemes st ON st.id = qt.subtheme_id
      JOIN themes t ON t.id = st.theme_id
      WHERE qt.id = ?
  ", [$title_id])->fetch();

  if (!$title_info) {
      echo '<div class="alert alert-danger">Judul kuis tidak ditemukan. Silakan pilih judul lain.</div>';
      return;
  }

  // 2. Tampilkan header/breadcrumb
  echo '<div class="card bg-body-tertiary mb-4">';
  echo '  <div class="card-body">';
  echo '      <h5 class="card-title mb-0">' . h($title_info['title']) . '</h5>';
  echo '      <nav aria-label="breadcrumb">';
  echo '          <ol class="breadcrumb mb-0">';
  echo '              <li class="breadcrumb-item">' . h($title_info['theme_name']) . '</li>';
  echo '              <li class="breadcrumb-item">' . h($title_info['subtheme_name']) . '</li>';
  echo '          </ol>';
  echo '      </nav>';
  echo '  </div>';
  echo '</div>';
  
  // Bagian Kanan: Tombol Download CSV
  echo '  <div class="flex-shrink-0">';
  echo '      <a href="?action=download_csv&title_id=' . $title_id . '" class="btn btn-success">';
  echo '          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-download me-1" viewBox="0 0 16 16"><path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/><path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/></svg>';
  echo '          Download Seluruh Soal di Judul Ini';
  echo '      </a>';
  echo '  </div>';

//   penutup container (echo removed to avoid duplicate close)
  
  // Baris pemisah tidak lagi diperlukan karena sudah ada card
  // echo '<hr class="my-4">';

  // ▲▲▲ AKHIR BLOK PERUBAHAN ▲▲▲

  // Baris pemisah agar lebih rapi
  echo '<hr class="my-4">';

  // MODE: EDIT?
  $edit_id = (int)($_GET['edit'] ?? 0);

  // Dropdown semua judul (untuk fitur Pindah Soal) - Tidak berubah
  $all_titles = q("
      SELECT qt.id, CONCAT(t.name,' › ',st.name,' › ',qt.title) AS label
      FROM quiz_titles qt
      JOIN subthemes st ON st.id = qt.subtheme_id
      JOIN themes t     ON t.id = st.theme_id
      ORDER BY t.name, st.name, qt.title
    ")->fetchAll();
  echo '<script id="__titles_json" type="application/json">'
    . json_encode($all_titles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    . '</script>';

  // (Sisa dari fungsi ini sama seperti sebelumnya, tidak ada yang perlu diubah)
  // ----- FORM EDIT (jika ada ?edit=ID)
  if ($edit_id) {
    $qrow = q("SELECT * FROM questions WHERE id=? AND title_id=?", [$edit_id, $title_id])->fetch();
    if (!$qrow) {
      echo '<div class="alert alert-warning">Soal tidak ditemukan.</div>';
    } else {
      $choices = q("SELECT * FROM choices WHERE question_id=? ORDER BY id", [$edit_id])->fetchAll();
      // Batasi/bijaki: jika kosong, siapkan 2 baris
      if (count($choices) < 2) {
        for ($i = count($choices); $i < 2; $i++) $choices[] = ['id' => 0, 'text' => '', 'is_correct' => 0];
      }
      echo '<div class="card mb-4"><div class="card-body">';
      echo '<h5 class="mb-3">Edit Soal</h5>';
      echo '<form method="post" id="form-edit-q"><input type="hidden" name="act" value="update_question_dyn"><input type="hidden" name="question_id" value="' . $edit_id . '">';
      echo '<div class="mb-2"><label class="form-label">Teks Pertanyaan</label><textarea class="form-control" name="text" required>' . h($qrow['text']) . '</textarea></div>';

      echo '<div id="edit-choices">';
      $i = 0;
      foreach ($choices as $c) {
        $i++;
        echo '<div class="border rounded-3 p-2 mb-2 choice-row">';
        echo '<div class="d-flex align-items-center gap-2">';
        echo '<input type="hidden" name="cid[]" value="' . (int)$c['id'] . '">';
        echo '<input class="form-check-input mt-0" type="radio" name="correct_index" value="' . $i . '" ' . (!empty($c['is_correct']) ? 'checked' : '') . '>';
        echo '<input class="form-control" name="ctext[]" value="' . h($c['text']) . '" placeholder="Teks pilihan" required>';
        echo '<button type="button" class="btn btn-outline-danger btn-sm remove-choice" title="Hapus"><span>&times;</span></button>';
        echo '</div></div>';
      }
      echo '</div>';

      echo '<div class="d-flex gap-2 mb-2">';
      echo '<button type="button" class="btn btn-sm btn-outline-secondary" id="btn-edit-add-choice">+ Tambah Pilihan (maks 5)</button>';
      echo '<small class="text-muted">Minimal 2 pilihan, maksimal 5.</small>';
      echo '</div>';

      echo '<div class="mb-2"><label class="form-label">Penjelasan (opsional)</label><input class="form-control" name="explanation" value="' . h($qrow['explanation']) . '"></div>';
      echo '<button class="btn btn-primary">Simpan Perubahan</button> <a href="?page=qmanage&title_id=' . $title_id . '" class="btn btn-secondary">Batal</a>';
      echo '</form>';

      echo '<script>
      (function(){
        const box = document.getElementById("edit-choices");
        const addBtn = document.getElementById("btn-edit-add-choice");
        function countRows(){ return box.querySelectorAll(".choice-row").length; }
        function updateRemoveButtons(){
          box.querySelectorAll(".remove-choice").forEach(btn=>{
            btn.onclick = function(){
              if(countRows()<=2){ alert("Minimal harus ada 2 pilihan."); return; }
              this.closest(".choice-row").remove();
              // jika radio benar hilang, set yang pertama
              const radios = box.querySelectorAll(\'input[type=radio][name="correct_index"]\');
              if(![...radios].some(r=>r.checked) && radios[0]) radios[0].checked = true;
            };
          });
        }
        addBtn.onclick = function(){
          if(countRows()>=5){ alert("Maksimal 5 pilihan."); return; }
          const idx = countRows()+1;
          const div = document.createElement("div");
          div.className="border rounded-3 p-2 mb-2 choice-row";
          div.innerHTML = \'<div class="d-flex align-items-center gap-2">\
            <input type="hidden" name="cid[]" value="0">\
            <input class="form-check-input mt-0" type="radio" name="correct_index" value="\'+idx+\'">\
            <input class="form-control" name="ctext[]" placeholder="Teks pilihan" required>\
            <button type="button" class="btn btn-outline-danger btn-sm remove-choice" title="Hapus"><span>&times;</span></button>\
          </div>\';
          box.appendChild(div);
          updateRemoveButtons();
        };
        updateRemoveButtons();
      })();
    </script>';

      echo '</div></div>';
    }
  }

  // ----- FORM TAMBAH (hanya jika TIDAK sedang edit)
  if (!$edit_id) {
    echo '<div class="card mb-4"><div class="card-body">';
    echo '<h5 class="mb-3">Tambah Soal Baru</h5>';
    echo '<form method="post" id="form-add-q"><input type="hidden" name="act" value="add_question_dyn"><input type="hidden" name="title_id" value="' . $title_id . '">';
    echo '<div class="mb-2"><label class="form-label">Teks Pertanyaan</label><textarea class="form-control" name="text" required></textarea></div>';

    echo '<div id="add-choices">';
    for ($i = 0; $i < 4; $i++) {
      echo '<div class="border rounded-3 p-2 mb-2 choice-row">';
      echo '<div class="d-flex align-items-center gap-2">';
      echo '<input class="form-check-input mt-0" type="radio" name="correct_index" value="' . ($i + 1) . '" ' . ($i === 0 ? 'checked' : '') . '>';
      echo '<input class="form-control" name="choice_text[]" placeholder="Teks pilihan" required>';
      echo '<button type="button" class="btn btn-outline-danger btn-sm remove-choice" title="Hapus"><span>&times;</span></button>';
      echo '</div></div>';
    }
    echo '</div>';

    echo '<div class="d-flex gap-2 mb-2">';
    echo '<button type="button" class="btn btn-sm btn-outline-secondary" id="btn-add-choice">+ Tambah Pilihan (maks 5)</button>';
    echo '<small class="text-muted">Centang bulatan di kiri untuk menandai jawaban benar. Minimal 2 pilihan.</small>';
    echo '</div>';

    echo '<div class="mb-2"><label class="form-label">Penjelasan (opsional)</label><input class="form-control" name="explanation" placeholder="Penjelasan (opsional)"></div>';
    echo '<button class="btn btn-success">Tambah Soal</button></form>';

    echo '<script>
    (function(){
      const box = document.getElementById("add-choices");
      const addBtn = document.getElementById("btn-add-choice");
      function countRows(){ return box.querySelectorAll(".choice-row").length; }
      function updateRemoveButtons(){
        box.querySelectorAll(".remove-choice").forEach(btn=>{
          btn.onclick = function(){
            if(countRows()<=2){ alert("Minimal harus ada 2 pilihan."); return; }
            this.closest(".choice-row").remove();
            const radios = box.querySelectorAll(\'input[type=radio][name="correct_index"]\');
            if(![...radios].some(r=>r.checked) && radios[0]) radios[0].checked = true;
          };
        });
      }
      addBtn.onclick = function(){
        if(countRows()>=5){ alert("Maksimal 5 pilihan."); return; }
        const idx = countRows()+1;
        const div = document.createElement("div");
        div.className="border rounded-3 p-2 mb-2 choice-row";
        div.innerHTML = \'<div class="d-flex align-items-center gap-2">\
          <input class="form-check-input mt-0" type="radio" name="correct_index" value="\'+idx+\'">\
          <input class="form-control" name="choice_text[]" placeholder="Teks pilihan" required>\
          <button type="button" class="btn btn-outline-danger btn-sm remove-choice" title="Hapus"><span>&times;</span></button>\
        </div>\';
        box.appendChild(div);
        updateRemoveButtons();
      };
      updateRemoveButtons();
    })();
  </script>';

    echo '</div></div>';
  }

  // ----- LIST SOAL
  $rows = q("SELECT * FROM questions WHERE title_id=? ORDER BY id DESC", [$title_id])->fetchAll();
  echo '<div class="card"><div class="card-body">';
  echo '<h5 class="mb-3">Daftar Soal</h5>';
  if (!$rows) {
    echo '<div class="alert alert-secondary">Belum ada soal.</div>';
  } else {
    echo '<div class="table-responsive"><table class="table table-sm align-middle">';
    echo '<thead><tr><th width="60">ID</th><th>Pertanyaan</th><th width="180">Aksi</th></tr></thead><tbody>';
    foreach ($rows as $r) {
      $short = mb_strimwidth(strip_tags($r['text']), 0, 90, '…', 'UTF-8');
      echo '<tr><td>' . $r['id'] . '</td><td>' . h($short) . '</td><td>';
      echo '<a href="?page=qmanage&title_id=' . $title_id . '&edit=' . $r['id'] . '" class="btn btn-sm btn-primary me-1">Edit</a>';

      echo '<button type="button" class="btn btn-sm btn-outline-secondary move-q" data-id="' . (int)$r['id'] . '">Pindah</button>';



      echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Hapus soal ini?\')">'
        . '<input type="hidden" name="act" value="del_question">'
        . '<input type="hidden" name="question_id" value="' . $r['id'] . '">'
        . '<button class="btn btn-sm btn-danger">Hapus</button></form>';


      // ▼▼▼ TOMBOL DUPLIKAT BARU ▼▼▼
      echo '  <form method="post" onsubmit="return confirm(\'Anda yakin ingin duplikat soal ini?\')">';
      echo '    <input type="hidden" name="act" value="duplicate_question">';
      echo '    <input type="hidden" name="question_id" value="' . $r['id'] . '">';
      echo '    <button type="submit" class="btn btn-sm mt-2 btn-info w-100">Duplikat</button>';
      echo '  </form>';
      // ▲▲▲ AKHIR TOMBOL DUPLIKAT ▲▲▲
      echo '</td></tr>';
    }
    echo '</tbody></table></div>';
  }

  // Modal untuk Pindah Soal (tetap dibutuhkan)
  echo <<<HTML
    <div id="modal-move-q" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:9999;">
      <div style="max-width:520px;margin:60px auto;background:var(--bs-body-bg); color: var(--bs-body-color);padding:16px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.2);">
        <h5 class="mb-3">Pindah Soal</h5>
        <form id="form-move-q" action="?page=qmanage" method="post">
          <input type="hidden" name="act" value="move_question">
          <input type="hidden" name="question_id" id="move_q_id">
          <div class="mb-2">
            <label class="form-label">Pindahkan ke Judul</label>
            <select name="dest_title_id" id="move_q_dest" class="form-select" required></select>
          </div>
          <div class="text-end">
            <button type="button" class="btn btn-light" data-close="modal-move-q">Batal</button>
            <button type="submit" class="btn btn-primary">Simpan</button>
          </div>
        </form>
      </div>
    </div>
    <script>
    (function(){
      const qs  = (s)=>document.querySelector(s);
      function getTitles(){
        const node = document.getElementById('__titles_json');
        if(!node) return [];
        try { return JSON.parse(node.textContent || '[]'); } catch(e){ return []; }
      }
      function fillTitles(sel){
        if(!sel) return;
        const titles = getTitles();
        sel.innerHTML = '';
        titles.forEach(t=>{
          const o = document.createElement('option');
          o.value = t.id; o.textContent = t.label;
          sel.appendChild(o);
        });
      }
      function showMoveQ(id){
        qs('#move_q_id').value = id;
        fillTitles(qs('#move_q_dest'));
        qs('#modal-move-q').style.display = 'block';
      }
      function hideMoveQ(){
        qs('#modal-move-q').style.display = 'none';
      }
      document.addEventListener('click', (ev)=>{
        const btn = ev.target.closest('.move-q');
        if(btn){ showMoveQ(btn.getAttribute('data-id')); return; }
        if(ev.target.id === 'modal-move-q'){ hideMoveQ(); return; }
        const closer = ev.target.closest('[data-close="modal-move-q"]');
        if(closer){ hideMoveQ(); return; }
      });
    })();
    </script>
    HTML;
  echo '</div></div>';
}


function qmanage_post()
{
  if (!is_admin()) return;
  $act = $_POST['act'] ?? '';

  // Tambah soal baru (4 pilihan)
  // Tambah soal baru (dinamis 2–5 pilihan)
  if ($act === 'add_question_dyn') {
    $title_id = (int)$_POST['title_id'];
    $text = trim($_POST['text'] ?? '');
    $exp = trim($_POST['explanation'] ?? '');
    $choices = $_POST['choice_text'] ?? [];
    $correct_index = (int)($_POST['correct_index'] ?? 1);

    // Bersihkan & validasi
    $choices = array_values(array_filter(array_map('trim', $choices), fn($x) => $x !== ''));
    $n = count($choices);
    if ($n < 2 || $n > 5) {
      die('Jumlah pilihan harus 2–5.');
    }
    if ($correct_index < 1 || $correct_index > $n) {
      $correct_index = 1;
    }

    q(
      "INSERT INTO questions (title_id,text,explanation,created_at) VALUES (?,?,?,?)",
      [$title_id, $text, ($exp ?: null), now()]
    );
    $qid = pdo()->lastInsertId();

    for ($i = 0; $i < $n; $i++) {
      $is = (int)(($i + 1) === $correct_index);
      q("INSERT INTO choices (question_id,text,is_correct) VALUES (?,?,?)", [$qid, $choices[$i], $is]);
    }
    redirect('?page=qmanage&title_id=' . $title_id);
  }


  // Simpan perubahan (EDIT)
  // Simpan perubahan (EDIT) — dinamis 2–5 pilihan
  if ($act === 'update_question_dyn') {
    $qid = (int)($_POST['question_id'] ?? 0);
    $text = trim($_POST['text'] ?? '');
    $exp = trim($_POST['explanation'] ?? '');

    q(
      "UPDATE questions SET text=?, explanation=?, updated_at=? WHERE id=?",
      [$text, ($exp ?: null), now(), $qid]
    );

    // Ambil array id & teks (dipasangkan per baris)
    $cidArr = array_map('intval', $_POST['cid'] ?? []);
    $ctextArr = array_map('trim', $_POST['ctext'] ?? []);
    $pairs = [];
    for ($i = 0; $i < count($ctextArr); $i++) {
      if ($ctextArr[$i] !== '') $pairs[] = ['id' => $cidArr[$i] ?? 0, 'text' => $ctextArr[$i]];
    }
    $n = count($pairs);
    if ($n < 2 || $n > 5) {
      die('Jumlah pilihan harus 2–5.');
    }

    // Sinkronisasi choices:
    // 1) ambil id lama
    $old = q("SELECT id FROM choices WHERE question_id=? ORDER BY id", [$qid])->fetchAll();
    $oldIds = array_map(fn($r) => (int)$r['id'], $old);

    // 2) update/insert sesuai urutan baru
    $newIds = [];
    foreach ($pairs as $p) {
      if ($p['id'] > 0) {
        q("UPDATE choices SET text=? WHERE id=?", [$p['text'], $p['id']]);
        $newIds[] = (int)$p['id'];
      } else {
        q("INSERT INTO choices (question_id,text,is_correct) VALUES (?,?,0)", [$qid, $p['text']]);
        $newIds[] = (int)pdo()->lastInsertId();
      }
    }

    // 3) hapus yang tidak terpakai
    foreach ($oldIds as $oid) {
      if (!in_array($oid, $newIds, true)) {
        q("DELETE FROM choices WHERE id=?", [$oid]);
      }
    }

    // Set jawaban benar
    $correct_index = max(1, min($n, (int)($_POST['correct_index'] ?? 1)));
    $correct_choice_id = $newIds[$correct_index - 1];

    q(
      "UPDATE choices SET is_correct = CASE WHEN id=? THEN 1 ELSE 0 END WHERE question_id=?",
      [$correct_choice_id, $qid]
    );

    $title_id = (int)q("SELECT title_id FROM questions WHERE id=?", [$qid])->fetch()['title_id'];
    redirect('?page=qmanage&title_id=' . $title_id);
  }
  // ▼▼▼ TAMBAHKAN BLOK KODE BARU DI SINI ▼▼▼
  if ($act === 'duplicate_question') {
    $original_qid = (int)($_POST['question_id'] ?? 0);
    if ($original_qid <= 0) {
      redirect('?page=qmanage');
    }

    // 1. Ambil data soal asli
    $original_q = q("SELECT * FROM questions WHERE id=?", [$original_qid])->fetch();
    if (!$original_q) {
      // Soal tidak ditemukan, kembali saja
      redirect('?page=qmanage');
    }

    // 2. Buat soal baru (duplikat) dengan prefix [DUPLIKAT]
    $new_text = "[DUPLIKAT] " . $original_q['text'];
    q(
      "INSERT INTO questions (title_id, text, explanation, attempts, corrects, created_at) VALUES (?, ?, ?, 0, 0, ?)",
      [$original_q['title_id'], $new_text, $original_q['explanation'], now()]
    );
    $new_qid = pdo()->lastInsertId();

    // 3. Ambil semua pilihan jawaban dari soal asli
    $original_choices = q("SELECT * FROM choices WHERE question_id=?", [$original_qid])->fetchAll();

    // 4. Buat pilihan jawaban baru untuk soal duplikat
    if ($original_choices) {
      foreach ($original_choices as $choice) {
        q(
          "INSERT INTO choices (question_id, text, is_correct) VALUES (?, ?, ?)",
          [$new_qid, $choice['text'], $choice['is_correct']]
        );
      }
    }

    // 5. Kembali ke halaman kelola soal
    redirect('?page=qmanage&title_id=' . $original_q['title_id']);
  }
  // ▲▲▲ AKHIR BLOK KODE BARU ▲▲▲

  // Hapus soal (otomatis hapus choices via FK)
  if ($act === 'del_question') {
    $qid = (int)$_POST['question_id'];
    $title_id = (int)q("SELECT title_id FROM questions WHERE id=?", [$qid])->fetch()['title_id'];
    q("DELETE FROM questions WHERE id=?", [$qid]);
    redirect('?page=qmanage&title_id=' . $title_id);
  }


  // Pindah soal ke judul lain (lintas subtema)
  if ($act === 'move_question') {
    $qid = (int)($_POST['question_id'] ?? 0);
    $dest_title_id = (int)($_POST['dest_title_id'] ?? 0);

    if ($qid > 0 && $dest_title_id > 0) {
      $exists = q("SELECT id FROM quiz_titles WHERE id=?", [$dest_title_id])->fetch();
      if ($exists) {
        q("UPDATE questions SET title_id=?, updated_at=? WHERE id=?", [$dest_title_id, now(), $qid]);
      }
    }
    redirect('?page=qmanage&title_id=' . $dest_title_id);
  }
}

function view_qmanage_pengajar()
{
    if (!is_pengajar() && !is_admin()) {
        echo '<div class="alert alert-danger">Akses admin/pengajar diperlukan.</div>';
        return;
    }

    $user_id = uid();
    $title_id = (int)($_GET['title_id'] ?? 0);
    $edit_id = (int)($_GET['edit'] ?? 0);
    $post_handler = '?action=crud_post_pengajar'; // Handler POST yang benar

    echo '<h3>Kelola Soal Saya</h3>';

    // Periksa apakah ID Judul telah dipilih
    if (!$title_id) {
        echo '<div class="alert alert-info mt-3">Silakan pilih Judul Soal dari menu <a href="?page=teacher_crud">Bank Soal Saya</a> untuk mulai mengelola.</div>';
        return;
    }

    // --- 1. FILTER KEPEMILIKAN JUDUL ---
    $title_info = q("
      SELECT qt.id, qt.title, qt.subtheme_id, st.name AS subtheme_name, st.theme_id, t.name AS theme_name
      FROM quiz_titles qt
      JOIN subthemes st ON st.id = qt.subtheme_id
      JOIN themes t ON t.id = st.theme_id
      WHERE qt.id = ? AND qt.owner_user_id = ?
    ", [$title_id, $user_id])->fetch();

    if (!$title_info) {
        echo '<div class="alert alert-danger">Judul kuis tidak ditemukan atau Anda tidak memilikinya.</div>';
        return;
    }
    
    // Tampilkan notifikasi
    if (isset($_GET['imported'])) echo '<div class="alert alert-success">Soal Master berhasil diimpor ke Judul ini.</div>';
    if (isset($_GET['ok'])) echo '<div class="alert alert-success">Perubahan berhasil disimpan.</div>';
    
    // --- 2. TAMPILAN HEADER/BREADCRUMB ---
    echo '<div class="card bg-body-tertiary mb-4"><div class="card-body">';
    echo '  <h5 class="card-title mb-0">' . h($title_info['title']) . '</h5>';
    echo '  <nav aria-label="breadcrumb">';
    echo '    <ol class="breadcrumb mb-0">';
    echo '      <li class="breadcrumb-item"><a href="?page=teacher_crud&theme_id=' . $title_info['theme_id'] . '">' . h($title_info['theme_name']) . '</a></li>'; 
    echo '      <li class="breadcrumb-item"><a href="?page=teacher_crud&theme_id=' . $title_info['theme_id'] . '&subtheme_id=' . $title_info['subtheme_id'] . '">' . h($title_info['subtheme_name']) . '</a></li>'; 
    echo '      <li class="breadcrumb-item active" aria-current="page">Kelola Soal</li>';
    echo '    </ol>';
    echo '  </nav>';
    echo '</div></div>';
    
    echo '<hr class="my-4">';

    // --- 3. FORM EDIT (Jika ada ?edit=ID) ---
    if ($edit_id) {
        // Query Edit: Filter dengan owner_user_id
        $qrow = q("SELECT * FROM questions WHERE id=? AND title_id=? AND owner_user_id=?", [$edit_id, $title_id, $user_id])->fetch();
        if (!$qrow) {
            echo '<div class="alert alert-warning">Soal tidak ditemukan atau Anda tidak memilikinya.</div>';
        } else {
            $choices = q("SELECT * FROM choices WHERE question_id=? ORDER BY id", [$edit_id])->fetchAll();
            if (count($choices) < 2) {
                for ($i = count($choices); $i < 2; $i++) $choices[] = ['id' => 0, 'text' => '', 'is_correct' => 0];
            }
            
            echo '<div class="card mb-4"><div class="card-body">';
            echo '<h5 class="mb-3">Edit Soal</h5>';
            // ACTION: update_question_dyn_pengajar
            echo '<form method="post" action="' . $post_handler . '" id="form-edit-q"><input type="hidden" name="act" value="update_question_dyn_pengajar"><input type="hidden" name="question_id" value="' . $edit_id . '">';
            echo '<div class="mb-2"><label class="form-label">Teks Pertanyaan</label><textarea class="form-control" name="text" required>' . h($qrow['text']) . '</textarea></div>';

            echo '<div id="edit-choices">';
            $i = 0;
            foreach ($choices as $c) {
                $i++;
                echo '<div class="border rounded-3 p-2 mb-2 choice-row">';
                echo '<div class="d-flex align-items-center gap-2">';
                echo '<input type="hidden" name="cid[]" value="' . (int)$c['id'] . '">';
                echo '<input class="form-check-input mt-0" type="radio" name="correct_index" value="' . $i . '" ' . (!empty($c['is_correct']) ? 'checked' : '') . '>';
                echo '<input class="form-control" name="ctext[]" value="' . h($c['text']) . '" placeholder="Teks pilihan" required>';
                echo '<button type="button" class="btn btn-outline-danger btn-sm remove-choice" title="Hapus"><span>&times;</span></button>';
                echo '</div></div>';
            }
            echo '</div>';

            echo '<div class="d-flex gap-2 mb-2">';
            echo '<button type="button" class="btn btn-sm btn-outline-secondary" id="btn-edit-add-choice">+ Tambah Pilihan (maks 5)</button>';
            echo '<small class="text-muted">Minimal 2 pilihan, maksimal 5.</small>';
            echo '</div>';

            echo '<div class="mb-2"><label class="form-label">Penjelasan (opsional)</label><input class="form-control" name="explanation" value="' . h($qrow['explanation']) . '"></div>';
            echo '<button class="btn btn-primary">Simpan Perubahan</button> <a href="?page=teacher_qmanage&title_id=' . $title_id . '" class="btn btn-secondary">Batal</a>';
            echo '</form>';
            
            // Script JS untuk form edit (mengatur penambahan/penghapusan pilihan)
            echo '<script>
              (function(){
                const box = document.getElementById("edit-choices");
                const addBtn = document.getElementById("btn-edit-add-choice");
                function countRows(){ return box.querySelectorAll(".choice-row").length; }
                function updateRemoveButtons(){
                  box.querySelectorAll(".remove-choice").forEach(btn=>{
                    btn.onclick = function(){
                      if(countRows()<=2){ alert("Minimal harus ada 2 pilihan."); return; }
                      this.closest(".choice-row").remove();
                      const radios = box.querySelectorAll(\'input[type=radio][name="correct_index"]\');
                      if(![...radios].some(r=>r.checked) && radios[0]) radios[0].checked = true;
                    };
                  });
                }
                addBtn.onclick = function(){
                  if(countRows()>=5){ alert("Maksimal 5 pilihan."); return; }
                  const idx = countRows()+1;
                  const div = document.createElement("div");
                  div.className="border rounded-3 p-2 mb-2 choice-row";
                  div.innerHTML = \'<div class="d-flex align-items-center gap-2">\
                    <input type="hidden" name="cid[]" value="0">\
                    <input class="form-check-input mt-0" type="radio" name="correct_index" value="\'+idx+\'">\
                    <input class="form-control" name="ctext[]" placeholder="Teks pilihan" required>\
                    <button type="button" class="btn btn-outline-danger btn-sm remove-choice" title="Hapus"><span>&times;</span></button>\
                  </div>\';
                  box.appendChild(div);
                  updateRemoveButtons();
                };
                updateRemoveButtons();
              })();
            </script>';

            echo '</div></div>';
        }
    }


    // --- 4. FORM TAMBAH SOAL (Jika TIDAK sedang edit) ---
    if (!$edit_id) {
        echo '<div class="card mb-4"><div class="card-body">';
        echo '<h5 class="mb-3">Tambah Soal Baru</h5>';
        
        // ACTION: add_question_dyn_pengajar
        echo '<form method="post" action="' . $post_handler . '" id="form-add-q"><input type="hidden" name="act" value="add_question_dyn_pengajar"><input type="hidden" name="title_id" value="' . $title_id . '">';
        echo '<div class="mb-2"><label class="form-label">Teks Pertanyaan</label><textarea class="form-control" name="text" required></textarea></div>';

        echo '<div id="add-choices">';
        for ($i = 0; $i < 4; $i++) {
            echo '<div class="border rounded-3 p-2 mb-2 choice-row">';
            echo '<div class="d-flex align-items-center gap-2">';
            echo '<input class="form-check-input mt-0" type="radio" name="correct_index" value="' . ($i + 1) . '" ' . ($i === 0 ? 'checked' : '') . '>';
            echo '<input class="form-control" name="choice_text[]" placeholder="Teks pilihan" required>';
            echo '<button type="button" class="btn btn-outline-danger btn-sm remove-choice" title="Hapus"><span>&times;</span></button>';
            echo '</div></div>';
        }
        echo '</div>';

        echo '<div class="d-flex gap-2 mb-2">';
        echo '<button type="button" class="btn btn-sm btn-outline-secondary" id="btn-add-choice">+ Tambah Pilihan (maks 5)</button>';
        echo '<small class="text-muted">Centang bulatan di kiri untuk menandai jawaban benar. Minimal 2 pilihan.</small>';
        echo '</div>';

        echo '<div class="mb-2"><label class="form-label">Penjelasan (opsional)</label><input class="form-control" name="explanation" placeholder="Penjelasan (opsional)"></div>';
        echo '<button class="btn btn-success">Tambah Soal</button></form>';
        
        // Script JS untuk form tambah
        echo '<script>
        (function(){
          const box = document.getElementById("add-choices");
          const addBtn = document.getElementById("btn-add-choice");
          function countRows(){ return box.querySelectorAll(".choice-row").length; }
          function updateRemoveButtons(){
            box.querySelectorAll(".remove-choice").forEach(btn=>{
              btn.onclick = function(){
                if(countRows()<=2){ alert("Minimal harus ada 2 pilihan."); return; }
                this.closest(".choice-row").remove();
                const radios = box.querySelectorAll(\'input[type=radio][name="correct_index"]\');
                if(![...radios].some(r=>r.checked) && radios[0]) radios[0].checked = true;
              };
            });
          }
          addBtn.onclick = function(){
            if(countRows()>=5){ alert("Maksimal 5 pilihan."); return; }
            const idx = countRows()+1;
            const div = document.createElement("div");
            div.className="border rounded-3 p-2 mb-2 choice-row";
            div.innerHTML = \'<div class="d-flex align-items-center gap-2">\
              <input class="form-check-input mt-0" type="radio" name="correct_index" value="\'+idx+\'">\
              <input class="form-control" name="choice_text[]" placeholder="Teks pilihan" required>\
              <button type="button" class="btn btn-outline-danger btn-sm remove-choice" title="Hapus"><span>&times;</span></button>\
            </div>\';
            box.appendChild(div);
            updateRemoveButtons();
          };
          updateRemoveButtons();
        })();
        </script>';
        
        echo '</div></div>';
    }


    // --- 5. LIST SOAL ---
    // Query list soal: Filter dengan owner_user_id
    $rows = q("SELECT * FROM questions WHERE title_id=? AND owner_user_id = ? ORDER BY id DESC", [$title_id, $user_id])->fetchAll();
    echo '<div class="card"><div class="card-body">';
    echo '<h5 class="mb-3">Daftar Soal (' . count($rows) . ' soal)</h5>';
    if (!$rows) {
        echo '<div class="alert alert-secondary">Belum ada soal.</div>';
    } else {
        echo '<div class="table-responsive"><table class="table table-sm align-middle">';
        echo '<thead><tr><th width="60">ID</th><th>Pertanyaan</th><th width="220">Aksi</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $short = mb_strimwidth(strip_tags($r['text']), 0, 80, '…', 'UTF-8');
            echo '<tr><td>' . $r['id'] . '</td><td>' . h($short) . '</td><td>';
            
            // Tombol Edit
            echo '<a href="?page=teacher_qmanage&title_id=' . $title_id . '&edit=' . $r['id'] . '" class="btn btn-sm btn-primary me-1">Edit</a>';

            // Tombol Hapus (ACTION: del_question_pengajar)
            echo '<form method="post" action="' . $post_handler . '" style="display:inline" onsubmit="return confirm(\'Hapus soal ini?\')">'
                . '<input type="hidden" name="act" value="del_question_pengajar">'
                . '<input type="hidden" name="question_id" value="' . $r['id'] . '">'
                . '<button class="btn btn-sm btn-danger">Hapus</button></form>';

            // Tombol Duplikat (ACTION: duplicate_question_pengajar)
            echo '  <form method="post" action="' . $post_handler . '" style="display:inline" onsubmit="return confirm(\'Anda yakin ingin duplikat soal ini?\')">';
            echo '    <input type="hidden" name="act" value="duplicate_question_pengajar">';
            echo '    <input type="hidden" name="question_id" value="' . $r['id'] . '">'
                . '<button type="submit" class="btn btn-sm btn-info w-100 mt-2">Duplikat</button>';
            echo '  </form>';

            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></div>';
}

// ===============================================
// THE END
// ===============================================