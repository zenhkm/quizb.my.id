<?php
// actions/import_questions.php
// Handler untuk Import Soal dari Excel

if (!is_pengajar() && !is_admin()) {
    echo '<div class="alert alert-danger">Akses admin/pengajar diperlukan.</div>';
    return;
}

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$act = $_GET['action'] ?? $_POST['act'] ?? '';

// ============================================================
// DOWNLOAD TEMPLATE EXCEL
// ============================================================
if ($act === 'download_template') {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Set judul kolom
    $sheet->setCellValue('A1', 'Pertanyaan');
    $sheet->setCellValue('B1', 'Pilihan A');
    $sheet->setCellValue('C1', 'Pilihan B');
    $sheet->setCellValue('D1', 'Pilihan C');
    $sheet->setCellValue('E1', 'Pilihan D');
    $sheet->setCellValue('F1', 'Pilihan E');
    $sheet->setCellValue('G1', 'Jawaban Benar (A/B/C/D/E)');
    $sheet->setCellValue('H1', 'Penjelasan (Opsional)');
    
    // Styling header
    $headerStyle = [
        'font' => [
            'bold' => true,
            'color' => ['rgb' => 'FFFFFF'],
            'size' => 12,
        ],
        'fill' => [
            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            'startColor' => ['rgb' => '0fb26b'],
        ],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
        ],
    ];
    $sheet->getStyle('A1:H1')->applyFromArray($headerStyle);
    
    // Set lebar kolom
    $sheet->getColumnDimension('A')->setWidth(50);
    $sheet->getColumnDimension('B')->setWidth(30);
    $sheet->getColumnDimension('C')->setWidth(30);
    $sheet->getColumnDimension('D')->setWidth(30);
    $sheet->getColumnDimension('E')->setWidth(30);
    $sheet->getColumnDimension('F')->setWidth(30);
    $sheet->getColumnDimension('G')->setWidth(25);
    $sheet->getColumnDimension('H')->setWidth(40);
    
    // Contoh data (baris ke-2)
    $sheet->setCellValue('A2', 'Siapakah presiden pertama Indonesia?');
    $sheet->setCellValue('B2', 'Ir. Soekarno');
    $sheet->setCellValue('C2', 'Mohammad Hatta');
    $sheet->setCellValue('D2', 'Soeharto');
    $sheet->setCellValue('E2', 'B.J. Habibie');
    $sheet->setCellValue('F2', ''); // Pilihan E kosong
    $sheet->setCellValue('G2', 'A');
    $sheet->setCellValue('H2', 'Ir. Soekarno adalah presiden pertama Republik Indonesia yang menjabat dari tahun 1945 hingga 1967.');
    
    // Styling contoh data
    $exampleStyle = [
        'fill' => [
            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'E8F5E9'],
        ],
    ];
    $sheet->getStyle('A2:H2')->applyFromArray($exampleStyle);
    
    // Set border untuk semua sel yang digunakan
    $borderStyle = [
        'borders' => [
            'allBorders' => [
                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                'color' => ['rgb' => 'CCCCCC'],
            ],
        ],
    ];
    $sheet->getStyle('A1:H2')->applyFromArray($borderStyle);
    
    // Freeze header row
    $sheet->freezePane('A2');
    
    // Download file
    $filename = 'Template_Import_Soal_' . date('Y-m-d_His') . '.xlsx';
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// ============================================================
// IMPORT EXCEL
// ============================================================
if ($act === 'import_excel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $title_id = (int)($_POST['title_id'] ?? 0);
    $user_id = uid();
    
    // Validasi title_id
    if ($title_id <= 0) {
        redirect('?page=import_questions&error=' . urlencode('Pilih judul soal terlebih dahulu.'));
    }
    
    // Cek kepemilikan title (untuk pengajar)
    if (!is_admin()) {
        $title_check = q("SELECT id FROM quiz_titles WHERE id = ? AND owner_user_id = ?", [$title_id, $user_id])->fetch();
        if (!$title_check) {
            redirect('?page=import_questions&error=' . urlencode('Anda tidak memiliki akses ke judul soal ini.'));
        }
    } else {
        // Cek apakah title_id valid untuk admin
        $title_check = q("SELECT id FROM quiz_titles WHERE id = ?", [$title_id])->fetch();
        if (!$title_check) {
            redirect('?page=import_questions&error=' . urlencode('Judul soal tidak ditemukan.'));
        }
    }
    
    // Validasi file upload
    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        redirect('?page=import_questions&error=' . urlencode('Gagal mengupload file. Silakan coba lagi.'));
    }
    
    $file = $_FILES['excel_file'];
    $file_size = $file['size'];
    $file_tmp = $file['tmp_name'];
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    // Validasi ekstensi file
    if (!in_array($file_ext, ['xlsx', 'xls'])) {
        redirect('?page=import_questions&error=' . urlencode('Format file tidak valid. Gunakan .xlsx atau .xls'));
    }
    
    // Validasi ukuran file (max 5MB)
    if ($file_size > 5 * 1024 * 1024) {
        redirect('?page=import_questions&error=' . urlencode('Ukuran file terlalu besar. Maksimal 5MB.'));
    }
    
    try {
        // Load Excel file
        $spreadsheet = IOFactory::load($file_tmp);
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestRow();
        
        if ($highestRow < 2) {
            redirect('?page=import_questions&error=' . urlencode('File Excel kosong atau tidak memiliki data.'));
        }
        
        $imported_count = 0;
        $errors = [];
        
        // Start dari baris ke-2 (skip header)
        for ($row = 2; $row <= $highestRow; $row++) {
            // Baca data dari Excel
            $question_text = trim($sheet->getCell('A' . $row)->getValue() ?? '');
            $choice_a = trim($sheet->getCell('B' . $row)->getValue() ?? '');
            $choice_b = trim($sheet->getCell('C' . $row)->getValue() ?? '');
            $choice_c = trim($sheet->getCell('D' . $row)->getValue() ?? '');
            $choice_d = trim($sheet->getCell('E' . $row)->getValue() ?? '');
            $choice_e = trim($sheet->getCell('F' . $row)->getValue() ?? '');
            $correct_answer = strtoupper(trim($sheet->getCell('G' . $row)->getValue() ?? ''));
            $explanation = trim($sheet->getCell('H' . $row)->getValue() ?? '');
            
            // Skip baris kosong
            if (empty($question_text)) {
                continue;
            }
            
            // Validasi pertanyaan
            if (empty($question_text)) {
                $errors[] = "Baris $row: Pertanyaan tidak boleh kosong.";
                continue;
            }
            
            // Kumpulkan pilihan yang tidak kosong
            $choices = [];
            if (!empty($choice_a)) $choices['A'] = $choice_a;
            if (!empty($choice_b)) $choices['B'] = $choice_b;
            if (!empty($choice_c)) $choices['C'] = $choice_c;
            if (!empty($choice_d)) $choices['D'] = $choice_d;
            if (!empty($choice_e)) $choices['E'] = $choice_e;
            
            // Validasi jumlah pilihan
            if (count($choices) < 2) {
                $errors[] = "Baris $row: Minimal harus ada 2 pilihan jawaban.";
                continue;
            }
            
            if (count($choices) > 5) {
                $errors[] = "Baris $row: Maksimal hanya 5 pilihan jawaban.";
                continue;
            }
            
            // Validasi jawaban benar
            if (!array_key_exists($correct_answer, $choices)) {
                $errors[] = "Baris $row: Jawaban benar '$correct_answer' tidak ditemukan dalam pilihan.";
                continue;
            }
            
            // Insert question
            try {
                // Set owner_user_id untuk pengajar, null untuk admin
                $owner_id = is_admin() ? null : $user_id;
                
                q("INSERT INTO questions (title_id, owner_user_id, text, explanation, created_at) VALUES (?,?,?,?,?)", 
                  [$title_id, $owner_id, $question_text, ($explanation ?: null), now()]);
                $question_id = pdo()->lastInsertId();
                
                // Insert choices
                foreach ($choices as $letter => $choice_text) {
                    $is_correct = ($letter === $correct_answer) ? 1 : 0;
                    q("INSERT INTO choices (question_id, text, is_correct) VALUES (?,?,?)", 
                      [$question_id, $choice_text, $is_correct]);
                }
                
                $imported_count++;
                
            } catch (Exception $e) {
                $errors[] = "Baris $row: Gagal menyimpan soal. " . $e->getMessage();
            }
        }
        
        // Redirect dengan hasil
        if ($imported_count > 0) {
            $msg = "success=1&count=$imported_count";
            if (!empty($errors)) {
                $msg .= '&error=' . urlencode('Beberapa baris gagal diimport: ' . implode(' | ', $errors));
            }
            redirect('?page=import_questions&' . $msg);
        } else {
            redirect('?page=import_questions&error=' . urlencode('Tidak ada soal yang berhasil diimport. ' . implode(' | ', $errors)));
        }
        
    } catch (Exception $e) {
        redirect('?page=import_questions&error=' . urlencode('Gagal membaca file Excel: ' . $e->getMessage()));
    }
}

// Jika tidak ada action, tampilkan view
require __DIR__ . '/../views/import_questions.php';
