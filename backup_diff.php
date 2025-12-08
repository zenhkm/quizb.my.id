<?php
/**
 * Backup Diferensial (Diff Backup)
 * --------------------------------
 * Hanya melakukan backup jika isi file sumber berubah (berdasarkan checksum SHA256).
 * * Cara Pakai: 
 * 1. Letakkan file ini (backup_diff.php) di folder yang sama dengan file target (misal index.php).
 * 2. Tambahkan baris: include __DIR__ . '/backup_diff.php'; di baris paling atas file target.
 */

(function () {
    // -------------------------
    // --- KONFIGURASI ---
    // -------------------------
    $backupDir  = __DIR__ . '/backup';               // Folder backup (akan dibuat: /public_html/backup)
    $manifest   = $backupDir . '/manifest.json';     // File pencatatan hash terakhir
    $algo       = 'sha256';                          // Algoritma checksum

    // -------------------------
    // --- TENTUKAN FILE SUMBER (index.php) ---
    // -------------------------
    // Mengambil path file utama yang diakses server (index.php)
    $sourceFile = $_SERVER['SCRIPT_FILENAME'] ?? null; 

    // Logika Fallback: Jika SCRIPT_FILENAME tidak tersedia (misal dari CLI) atau mengarah ke skrip ini sendiri.
    if (!$sourceFile || basename($sourceFile) === 'backup_diff.php') {
         // Coba ambil file pertama yang di-include, yang merupakan file utama (index.php).
         $includedFiles = get_included_files();
         $sourceFile = realpath($includedFiles[0] ?? ''); 
    }

    if (!$sourceFile || !is_readable($sourceFile)) {
        // Gagal mengidentifikasi atau membaca file sumber. Hentikan.
        return;
    }

    // -------------------------
    // --- SIAPKAN FOLDER & CHECKSUM ---
    // -------------------------
    if (!is_dir($backupDir)) {
        if (!mkdir($backupDir, 0777, true) && !is_dir($backupDir)) {
            return; // Gagal membuat folder
        }
    }

    // Hitung checksum saat ini
    $currentHash = hash_file($algo, $sourceFile);
    if ($currentHash === false) return; // Gagal hitung hash

    $sourceKey   = realpath($sourceFile) ?: $sourceFile;

    // -------------------------
    // --- BACA MANIFEST (CATATAN HASH LAMA) ---
    // -------------------------
    $data = [];
    if (is_file($manifest) && is_readable($manifest)) {
        $json = file_get_contents($manifest);
        if ($json !== false) {
            $tmp = json_decode($json, true);
            if (is_array($tmp)) $data = $tmp;
        }
    }

    // -------------------------
    // --- CEK PERUBAHAN & HENTIKAN JIKA TIDAK BERUBAH ---
    // -------------------------
    if (isset($data[$sourceKey]['hash']) && hash_equals($data[$sourceKey]['hash'], $currentHash)) {
        return; // Hash sama dengan yang terakhir, TIDAK PERLU BACKUP
    }

    // -------------------------
    // --- PROSES BACKUP ---
    // -------------------------

    // Tentukan nama backup berikutnya: "index.php", "index (1).php", dst.
    $fileName  = basename($sourceFile);
    $nameNoExt = pathinfo($fileName, PATHINFO_FILENAME);
    $ext       = pathinfo($fileName, PATHINFO_EXTENSION);
    $extDot    = $ext !== '' ? '.' . $ext : '';

    $candidate = $backupDir . DIRECTORY_SEPARATOR . $fileName; // Coba nama asli
    $n = 0;
    while (file_exists($candidate)) {
        $n++;
        $candidate = $backupDir . DIRECTORY_SEPARATOR . $nameNoExt . " ($n)" . $extDot;
    }

    // Salin file
    if (!copy($sourceFile, $candidate)) {
        return; // Gagal copy
    }

    // -------------------------
    // --- UPDATE MANIFEST ---
    // -------------------------
    $data[$sourceKey] = [
        'hash'      => $currentHash,
        'algo'      => $algo,
        'updated_at'=> date('c'),
        'backup'    => basename($candidate)
    ];

    // Tulis manifest secara aman (atomic)
    $tmpPath = $manifest . '.tmp';
    $jsonOut = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($jsonOut !== false) {
        // Gunakan lock untuk mencegah race condition (best effort)
        $fp = @fopen($tmpPath, 'c');
        if ($fp) {
            if (@flock($fp, LOCK_EX)) {
                ftruncate($fp, 0);
                fwrite($fp, $jsonOut);
                fflush($fp);
                flock($fp, LOCK_UN);
                fclose($fp);
                @rename($tmpPath, $manifest); // Ganti file lama dengan yang baru
            } else {
                fclose($fp);
            }
        }
    }
})();