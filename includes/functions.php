<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/db.php'; // Ensure DB is available

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

/**
 * Perkiraan jumlah user/visitor yang "sedang online" berdasarkan file session
 * yang aktif (mtime) dalam N menit terakhir.
 *
 * Catatan: ini menghitung session aktif (bukan hanya user login).
 */
function count_online_sessions(int $minutes = 5): int
{
  $minutes = max(1, $minutes);
  $path = session_save_path();
  if (!$path || !is_dir($path) || !is_readable($path)) {
    return 0;
  }

  $threshold = time() - ($minutes * 60);
  $files = @glob(rtrim($path, '/\\') . DIRECTORY_SEPARATOR . 'sess_*');
  if (!$files) {
    return 0;
  }

  $count = 0;
  foreach ($files as $file) {
    if (!is_file($file)) continue;
    $mtime = @filemtime($file);
    if ($mtime !== false && $mtime >= $threshold) {
      $count++;
    }
  }
  return $count;
}

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
        CURLOPT_CONNECTTIMEOUT => 15, // Increased timeout
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_SSL_VERIFYPEER => false, // Disabled for local development compatibility
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: QuizB/1.0'],
      ]);
      if ($force_local_cacert) {
        $localCacert = __DIR__ . '/../cacert.pem'; // Adjusted path
        if (is_file($localCacert)) {
          curl_setopt($ch, CURLOPT_CAINFO, $localCacert);
        }
      }
      $res  = curl_exec($ch);
      $err  = curl_error($ch);
      $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);
      if ($err || $code < 200 || $code >= 300 || !$res) {
          error_log("http_get_json Error: $err (Code: $code) URL: $url");
          return null;
      }
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

function get_city_from_ip($ip)
{
  if (isset($_SESSION['user_city']) && $_SESSION['user_city'] !== 'Anonim') {
    return $_SESSION['user_city'];
  }
  if (!$ip || $ip === '0.0.0.0') {
    return 'Anonim';
  }
  $city_result = 'Anonim';
  $j = http_get_json("https://ipapi.co/{$ip}/json/");
  if ($j && !empty($j['city'])) {
    $city_result = $j['city'];
  }
  elseif ($j = http_get_json("https://ipwho.is/{$ip}?fields=city,success")) {
    if ($j && (!isset($j['success']) || $j['success'] === true)) {
      if (!empty($j['city'])) $city_result = $j['city'];
    }
  }
  elseif ($j = http_get_json("https://ipinfo.io/{$ip}/json")) {
    if ($j && !empty($j['city'])) {
      $city_result = $j['city'];
    }
  }
  $_SESSION['user_city'] = $city_result;
  return $city_result;
}

function get_city_name()
{
  if (isset($_POST['city']) || isset($_GET['city'])) {
    $c = trim((string)($_POST['city'] ?? $_GET['city']));
    if ($c !== '') {
      $opts = [
        'expires'  => time() + 60 * 60 * 24 * 180,
        'path'     => '/',
        'samesite' => 'Lax',
        'httponly' => false,
      ];
      if (!headers_sent()) {
        setcookie('player_city', $c, $opts);
      }
      return $c;
    }
  }
  if (!empty($_COOKIE['player_city']) && $_COOKIE['player_city'] !== 'Anonim') {
    return $_COOKIE['player_city'];
  }
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
  if ($resolved !== 'Anonim' && (!isset($_COOKIE['player_city']) || $_COOKIE['player_city'] !== $resolved)) {
    $opts = [
      'expires'  => time() + 60 * 60 * 24 * 180,
      'path'     => '/',
      'samesite' => 'Lax',
      'httponly' => false,
    ];
    if (!headers_sent()) {
      setcookie('player_city', $resolved, $opts);
    }
  }
  return $resolved;
}

function send_smtp_email(string $to, string $subject, string $body): bool
{
    $mail = new PHPMailer(true); 
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; 
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body); 
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer Error to {$to}: " . $e->getMessage());
        return false;
    }
}

function send_welcome_email(string $toEmail, string $toName) {
    global $CONFIG;
    $subject = "🎉 Selamat Datang di QuizB | Asah Wawasanmu!";
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
  if ($newName !== null) {
    $clean = trim($newName);
    if ($clean !== '' && mb_strlen($clean) >= 3 && mb_strlen($clean) <= 190) {
      $fields[] = "name = ?";
      $params[] = $clean;
      $fields[] = "name_locked = 1"; 
      $_SESSION['user']['name'] = $clean; 
    }
  }
  if ($timerSeconds !== null) {
    $s = max(5, min(300, (int)$timerSeconds));
    $fields[] = "quiz_timer_seconds = ?";
    $params[] = $s;
  }
  if ($examTimerMinutes !== null && is_admin()) {
    $m = max(1, min(300, (int)$examTimerMinutes));
    $fields[] = "exam_timer_minutes = ?";
    $params[] = $m;
  }
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

function ensure_session_bound_to_title(PDO $pdo, $user_id, $title_id) {
    if (!empty($_SESSION['quiz']['session_id'])) {
        $currTitle = (int)($_SESSION['quiz']['title_id'] ?? 0);
        if ($currTitle !== (int)$title_id) {
            unset($_SESSION['quiz']);
        }
    }
}

function current_url()
{
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'quizb.my.id';
  $uri  = strtok($_SERVER['REQUEST_URI'], '#');
  return $scheme . '://' . $host . $uri;
}

function canonical_url()
{
  $allowed = ['page', 'theme_id', 'subtheme_id', 'title_id', 'i', 'mode'];
  $query = [];
  foreach ($_GET as $k => $v) {
    if (in_array($k, $allowed, true)) $query[$k] = $v;
  }
  $base = preg_replace('~\?.*$~', '', current_url());
  return $query ? $base . '?' . http_build_query($query) : $base;
}

function breadcrumb_trail()
{
  $trail = [
    ['name' => 'Beranda', 'url' => base_url() . '/'] 
  ];
  $page = $_GET['page'] ?? 'home';

  if (in_array($page, ['themes', 'subthemes', 'titles', 'play'])) {
    $theme = null; $sub = null; $title = null;
    if (!empty($_GET['theme_id'])) {
      $theme = q("SELECT id,name FROM themes WHERE id=? AND deleted_at IS NULL", [$_GET['theme_id']])->fetch(PDO::FETCH_ASSOC);
        if ($theme) $trail[] = ['name' => $theme['name'], 'url' => base_url() . '?page=subthemes&theme_id=' . $theme['id']];
    }
    if (!empty($_GET['subtheme_id'])) {
      $sub = q("SELECT id,name,theme_id FROM subthemes WHERE id=? AND deleted_at IS NULL", [$_GET['subtheme_id']])->fetch(PDO::FETCH_ASSOC);
        if ($sub) $trail[] = ['name' => $sub['name'], 'url' => base_url() . '?page=titles&subtheme_id=' . $sub['id']];
    }
    if (!empty($_GET['title_id'])) {
      $title = q("SELECT id,title FROM quiz_titles WHERE id=? AND deleted_at IS NULL", [$_GET['title_id']])->fetch(PDO::FETCH_ASSOC);
        if ($title) $trail[] = ['name' => $title['title'], 'url' => base_url() . '?page=play&title_id=' . $title['id']];
    }
  }
  elseif (in_array($page, ['kelola_institusi', 'kelola_tugas', 'detail_kelas', 'detail_tugas', 'edit_tugas'])) {
     $trail[] = ['name' => 'Kelola Institusi & Kelas', 'url' => base_url() . '?page=kelola_institusi'];
     $inst_id = isset($_GET['inst_id']) ? (int)$_GET['inst_id'] : 0;
     $kelas_id = isset($_GET['id_kelas']) ? (int)$_GET['id_kelas'] : 0;
     $assignment_id = isset($_GET['assignment_id']) ? (int)$_GET['assignment_id'] : 0;
     $inst_name = null; 
     if ($inst_id > 0) {
         $inst_name = q("SELECT nama_institusi FROM teacher_institutions WHERE id = ? AND id_pengajar = ?", [$inst_id, uid()])->fetchColumn(); 
         if ($inst_name) {
             $trail[] = ['name' => $inst_name, 'url' => base_url() . '?page=kelola_tugas&inst_id=' . $inst_id];
         }
     }
     if ($page === 'kelola_tugas' && $inst_name) {
         $trail[] = ['name' => 'Kelola Tugas'];
     }
     elseif ($page === 'detail_kelas' && $kelas_id > 0) {
        $kelas_info = q("SELECT nama_kelas, id_institusi FROM classes WHERE id = ? AND id_pengajar = ?", [$kelas_id, uid()])->fetch(); 
        if ($kelas_info) {
            if (!$inst_name && $kelas_info['id_institusi']) {
                 $inst_name_kelas = q("SELECT nama_institusi FROM teacher_institutions WHERE id = ? AND id_pengajar = ?", [$kelas_info['id_institusi'], uid()])->fetchColumn();
                 if ($inst_name_kelas) $trail[] = ['name' => $inst_name_kelas, 'url' => base_url() . '?page=kelola_tugas&inst_id=' . $kelas_info['id_institusi']];
            }
            $trail[] = ['name' => 'Detail Kelas: ' . $kelas_info['nama_kelas']];
        }
     }
     elseif (($page === 'detail_tugas' || $page === 'edit_tugas') && $assignment_id > 0) {
         $tugas_info = q("SELECT a.judul_tugas, c.id_institusi FROM assignments a JOIN classes c ON a.id_kelas = c.id WHERE a.id = ? AND a.id_pengajar = ?", [$assignment_id, uid()])->fetch(); 
         if ($tugas_info) {
             if (!$inst_name && $tugas_info['id_institusi']) {
                  $inst_name_tugas = q("SELECT nama_institusi FROM teacher_institutions WHERE id = ? AND id_pengajar = ?", [$tugas_info['id_institusi'], uid()])->fetchColumn();
                  if ($inst_name_tugas) $trail[] = ['name' => $inst_name_tugas, 'url' => base_url() . '?page=kelola_tugas&inst_id=' . $tugas_info['id_institusi']];
             }
             $last_breadcrumb = end($trail); 
             if ($last_breadcrumb && $last_breadcrumb['name'] !== 'Kelola Tugas' && ($inst_name || isset($inst_name_tugas))) {
                 $current_inst_id = $inst_id ?: $tugas_info['id_institusi']; 
                 $trail[] = ['name' => 'Kelola Tugas', 'url' => base_url() . '?page=kelola_tugas&inst_id=' . $current_inst_id];
             }
             if ($page === 'detail_tugas') {
                 $trail[] = ['name' => 'Detail Tugas: ' . $tugas_info['judul_tugas']];
             } else { 
                 $trail[] = ['name' => 'Edit Tugas: ' . $tugas_info['judul_tugas']];
             }
         }
     }
  }
  elseif ($page === 'profile') {
      $trail[] = ['name' => 'Profil', 'url' => base_url() . '?page=profile'];
  }
  elseif ($page === 'setting') {
      $trail[] = ['name' => 'Pengaturan', 'url' => base_url() . '?page=setting'];
  }
  return $trail;
}

// ============================================================
// Soft-delete / Bin (Recycle)
// ============================================================
function ensure_soft_delete_schema()
{
  static $done = false;
  if ($done) return;
  $done = true;

  $tables = ['themes', 'subthemes', 'quiz_titles'];
  foreach ($tables as $table) {
    try {
      pdo()->exec("ALTER TABLE {$table} ADD COLUMN deleted_at DATETIME NULL");
    } catch (\PDOException $e) {
      // 42S21 = Duplicate column name
      if ($e->getCode() !== '42S21') {
        throw $e;
      }
    }
  }
}

function echo_breadcrumb_jsonld()
{
  $trail = breadcrumb_trail(); 
  $items = [];
  $pos = 1;
  foreach ($trail as $t) {
    $item_data = [
      "@type" => "ListItem",
      "position" => $pos++,
      "name" => $t['name'] 
    ];
    if (isset($t['url'])) {
        $item_data["item"] = $t['url'];
    }
    $items[] = $item_data; 
  }
  $json = [
    "@context" => "https://schema.org",
    "@type" => "BreadcrumbList",
    "itemListElement" => $items
  ];
  echo '<script type="application/ld+json">' . json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
}

function user_skill($user_id)
{
  if (!$user_id) return 0.3; // belum login → anggap pemula
  $r = q("SELECT AVG(score) avg FROM results WHERE user_id=?", [$user_id])->fetch();
  $avg = (float)($r['avg'] ?? 0);
  return $avg ? max(0.1, min(0.95, $avg / 100)) : 0.4;
}

function verify_google_id_token($idToken)
{
  global $CONFIG;
  if (!$idToken) {
      error_log("Google Login Error: No ID Token provided.");
      return null;
  }

  // Pakai endpoint tokeninfo (cukup untuk MVP); produksi idealnya verifikasi JWT RS256 penuh.
  $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);

  // Pakai helper cURL stabil (HTTP/1.1, SSL verify ON). Set param 3 = true jika Anda menaruh cacert.pem lokal.
  $data = http_get_json($url, 20 /*timeout*/, false /*force_local_cacert*/);
  if (!$data) {
      error_log("Google Login Error: Failed to fetch token info from Google. URL: $url");
      return null;
  }

  // Validasi minimal yang aman
  $aud = $data['aud'] ?? '';
  $configClientId = $CONFIG['GOOGLE_CLIENT_ID'] ?? '';
  
  if ($aud !== $configClientId) {
      error_log("Google Login Error: Audience mismatch. Received: '$aud', Expected: '$configClientId'");
      return null;
  }

  // Email verified harus true (kadang string 'true' atau boolean true)
  $ev = $data['email_verified'] ?? '';
  if (!($ev === true || $ev === 'true' || $ev === 1 || $ev === '1')) {
      error_log("Google Login Error: Email not verified. Value: " . var_export($ev, true));
      return null;
  }

  return $data; // berisi sub, email, name, picture, dsb.
}



