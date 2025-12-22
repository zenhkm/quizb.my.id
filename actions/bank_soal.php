<?php
// actions/bank_soal.php
// Handler untuk Bank Soal Terintegrasi

if (!is_admin()) {
    echo '<div class="alert alert-danger">Akses admin diperlukan.</div>';
    return;
}

$act = $_POST['act'] ?? ($_GET['act'] ?? '');

// ============================================================
// DOWNLOAD SOAL EXCEL (Format sama dengan template import)
// ============================================================
if ($act === 'download_excel') {
    $title_id = (int)($_GET['title_id'] ?? 0);
    if ($title_id <= 0) {
        die('ID Judul tidak valid');
    }

    // Autoload PhpSpreadsheet
    require_once __DIR__ . '/../vendor/autoload.php';

    if (!class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
        die('PhpSpreadsheet Library Tidak Ditemukan');
    }

    // Ambil info judul
    $title_info = q(
        "SELECT qt.title, st.name AS subtheme, t.name AS theme
         FROM quiz_titles qt
         JOIN subthemes st ON st.id = qt.subtheme_id
         JOIN themes t ON t.id = st.theme_id
         WHERE qt.id = ? AND qt.deleted_at IS NULL",
        [$title_id]
    )->fetch();

    if (!$title_info) {
        die('Data tidak ditemukan');
    }

    // Ambil semua soal
    $questions = q(
        "SELECT id, text, explanation
         FROM questions
         WHERE title_id = ?
         ORDER BY id ASC",
        [$title_id]
    )->fetchAll();

    // Bersihkan output buffer sebelum header download
    if (ob_get_level()) {
        ob_end_clean();
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Header kolom (persis seperti template import)
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
            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            'startColor' => ['rgb' => '0fb26b'],
        ],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
        ],
    ];
    $sheet->getStyle('A1:H1')->applyFromArray($headerStyle);

    // Lebar kolom (persis seperti template import)
    $sheet->getColumnDimension('A')->setWidth(50);
    $sheet->getColumnDimension('B')->setWidth(30);
    $sheet->getColumnDimension('C')->setWidth(30);
    $sheet->getColumnDimension('D')->setWidth(30);
    $sheet->getColumnDimension('E')->setWidth(30);
    $sheet->getColumnDimension('F')->setWidth(30);
    $sheet->getColumnDimension('G')->setWidth(25);
    $sheet->getColumnDimension('H')->setWidth(40);

    // Isi data
    $letters = ['A', 'B', 'C', 'D', 'E'];
    $row = 2;
    foreach (($questions ?: []) as $q) {
        $sheet->setCellValue('A' . $row, (string)($q['text'] ?? ''));

        $choices = q(
            "SELECT text, is_correct
             FROM choices
             WHERE question_id = ?
             ORDER BY id ASC",
            [(int)$q['id']]
        )->fetchAll();

        $correct_letter = '';
        for ($i = 0; $i < 5; $i++) {
            $choice_text = $choices[$i]['text'] ?? '';
            $is_correct = (int)($choices[$i]['is_correct'] ?? 0);
            $sheet->setCellValue(chr(ord('B') + $i) . $row, (string)$choice_text);
            if ($correct_letter === '' && $is_correct === 1) {
                $correct_letter = $letters[$i] ?? '';
            }
        }

        $sheet->setCellValue('G' . $row, $correct_letter);
        $sheet->setCellValue('H' . $row, (string)($q['explanation'] ?? ''));
        $row++;
    }

    // Border: sama style seperti template import, untuk range terpakai
    $lastRow = max(2, $row - 1);
    $borderStyle = [
        'borders' => [
            'allBorders' => [
                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                'color' => ['rgb' => 'CCCCCC'],
            ],
        ],
    ];
    $sheet->getStyle('A1:H' . $lastRow)->applyFromArray($borderStyle);

    // Freeze header row
    $sheet->freezePane('A2');

    // Download file
    $safeTitle = preg_replace('/[^a-zA-Z0-9]/', '_', (string)($title_info['title'] ?? 'Soal'));
    $safeTitle = trim($safeTitle, '_');
    if ($safeTitle === '') {
        $safeTitle = 'Soal';
    }
    $filename = 'Soal_' . $safeTitle . '_' . date('Y-m-d_His') . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Cache-Control: max-age=1');
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
    header('Cache-Control: cache, must-revalidate');
    header('Pragma: public');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ========== TEMA ==========
    if ($act === 'add_theme') {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if ($name !== '') {
            $max = q("SELECT COALESCE(MAX(sort_order), 0) AS m FROM themes")->fetch();
            $next = (int)$max['m'] + 10;
            q("INSERT INTO themes (name, description, sort_order) VALUES (?,?,?)", [$name, $desc, $next]);
            $new_id = (int)pdo()->lastInsertId();
            redirect('?page=bank_soal&theme_id=' . $new_id . '&success=1&msg=' . urlencode('Tema berhasil ditambahkan'));
        }
        redirect('?page=bank_soal');
    }
    
    if ($act === 'edit_theme') {
        $id = (int)($_POST['theme_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($id > 0 && $name !== '') {
            q("UPDATE themes SET name=? WHERE id=?", [$name, $id]);
            redirect('?page=bank_soal&theme_id=' . $id . '&success=1&msg=' . urlencode('Tema berhasil diupdate'));
        }
        redirect('?page=bank_soal');
    }
    
    if ($act === 'delete_theme') {
        $id = (int)($_POST['theme_id'] ?? 0);
        if ($id > 0) {
            $ts = now();
            q("UPDATE themes SET deleted_at=? WHERE id=?", [$ts, $id]);
            q("UPDATE subthemes SET deleted_at=? WHERE theme_id=?", [$ts, $id]);
            q(
                "UPDATE quiz_titles qt JOIN subthemes st ON st.id=qt.subtheme_id SET qt.deleted_at=? WHERE st.theme_id=?",
                [$ts, $id]
            );
            redirect('?page=bank_soal&success=1&msg=' . urlencode('Tema dipindahkan ke Bin'));
        }
        redirect('?page=bank_soal');
    }
    
    // ========== SUBTEMA ==========
    if ($act === 'add_subtheme') {
        $theme_id = (int)($_POST['theme_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($theme_id > 0 && $name !== '') {
            q("INSERT INTO subthemes (theme_id, name) VALUES (?,?)", [$theme_id, $name]);
            $new_id = (int)pdo()->lastInsertId();
            redirect('?page=bank_soal&theme_id=' . $theme_id . '&subtheme_id=' . $new_id . '&success=1&msg=' . urlencode('Subtema berhasil ditambahkan'));
        }
        redirect('?page=bank_soal');
    }
    
    if ($act === 'edit_subtheme') {
        $id = (int)($_POST['subtheme_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($id > 0 && $name !== '') {
            $sub = q("SELECT theme_id FROM subthemes WHERE id=?", [$id])->fetch();
            q("UPDATE subthemes SET name=? WHERE id=?", [$name, $id]);
            redirect('?page=bank_soal&theme_id=' . $sub['theme_id'] . '&subtheme_id=' . $id . '&success=1&msg=' . urlencode('Subtema berhasil diupdate'));
        }
        redirect('?page=bank_soal');
    }
    
    if ($act === 'delete_subtheme') {
        $id = (int)($_POST['subtheme_id'] ?? 0);
        if ($id > 0) {
            $row = q("SELECT theme_id FROM subthemes WHERE id=?", [$id])->fetch();
            $theme_id = $row ? (int)$row['theme_id'] : 0;
            $ts = now();
            q("UPDATE subthemes SET deleted_at=? WHERE id=?", [$ts, $id]);
            q("UPDATE quiz_titles SET deleted_at=? WHERE subtheme_id=?", [$ts, $id]);
            redirect('?page=bank_soal&theme_id=' . $theme_id . '&success=1&msg=' . urlencode('Subtema dipindahkan ke Bin'));
        }
        redirect('?page=bank_soal');
    }
    
    // ========== JUDUL ==========
    if ($act === 'add_title') {
        $subtheme_id = (int)($_POST['subtheme_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        if ($subtheme_id > 0 && $title !== '') {
            q("INSERT INTO quiz_titles (subtheme_id, title) VALUES (?,?)", [$subtheme_id, $title]);
            $new_id = (int)pdo()->lastInsertId();

            // Notifikasi broadcast: kuis baru
            $subtheme_info = q("SELECT name FROM subthemes WHERE id = ?", [$subtheme_id])->fetch();
            $subtheme_name = $subtheme_info['name'] ?? '';
            $notif_message = "Kuis baru di subtema \"" . h($subtheme_name) . "\": \"" . h($title) . "\"";
            $notif_link = "?page=play&title_id=" . $new_id;
            q(
                "INSERT INTO broadcast_notifications (type, message, link, related_id) VALUES ('new_quiz', ?, ?, ?)",
                [$notif_message, $notif_link, $new_id]
            );
            
            // Get theme_id
            $sub = q("SELECT theme_id FROM subthemes WHERE id=?", [$subtheme_id])->fetch();
            
            redirect('?page=bank_soal&theme_id=' . $sub['theme_id'] . '&subtheme_id=' . $subtheme_id . '&title_id=' . $new_id . '&success=1&msg=' . urlencode('Judul berhasil ditambahkan'));
        }
        redirect('?page=bank_soal');
    }
    
    if ($act === 'edit_title') {
        $id = (int)($_POST['title_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $theme_id = (int)($_POST['theme_id'] ?? 0);
        $subtheme_id = (int)($_POST['subtheme_id'] ?? 0);
        
        if ($id > 0 && $title !== '') {
            q("UPDATE quiz_titles SET title=? WHERE id=?", [$title, $id]);
            redirect('?page=bank_soal&theme_id=' . $theme_id . '&subtheme_id=' . $subtheme_id . '&title_id=' . $id . '&success=1&msg=' . urlencode('Judul berhasil diupdate'));
        }
        redirect('?page=bank_soal');
    }
    
    if ($act === 'delete_title') {
        $id = (int)($_POST['title_id'] ?? 0);
        $theme_id = (int)($_POST['theme_id'] ?? 0);
        $subtheme_id = (int)($_POST['subtheme_id'] ?? 0);
        
        if ($id > 0) {
            q("UPDATE quiz_titles SET deleted_at=? WHERE id=?", [now(), $id]);
            redirect('?page=bank_soal&theme_id=' . $theme_id . '&subtheme_id=' . $subtheme_id . '&success=1&msg=' . urlencode('Judul dipindahkan ke Bin'));
        }
        redirect('?page=bank_soal');
    }
    
    // ========== SOAL ==========
    if ($act === 'add_question') {
        $title_id = (int)($_POST['title_id'] ?? 0);
        $text = trim($_POST['text'] ?? '');
        $exp = trim($_POST['explanation'] ?? '');
        $choices = $_POST['choice_text'] ?? [];
        $correct_index = (int)($_POST['correct_index'] ?? 1);
        $return_url = $_POST['return_url'] ?? '?page=bank_soal';
        
        if ($title_id > 0 && $text !== '') {
            q("INSERT INTO questions (title_id, text, explanation, created_at) VALUES (?,?,?,?)", 
              [$title_id, $text, ($exp ?: null), now()]);
            $qid = pdo()->lastInsertId();
            
            $choices = array_values(array_filter(array_map('trim', $choices), fn($x) => $x !== ''));
            $n = count($choices);
            
            if ($n >= 2 && $n <= 5) {
                for ($i = 0; $i < $n; $i++) {
                    $is = (int)(($i + 1) === $correct_index);
                    q("INSERT INTO choices (question_id, text, is_correct) VALUES (?,?,?)", [$qid, $choices[$i], $is]);
                }
            }
            
            redirect($return_url . '&success=1&msg=' . urlencode('Soal berhasil ditambahkan'));
        }
        redirect($return_url);
    }
    
    if ($act === 'update_question') {
        $qid = (int)($_POST['question_id'] ?? 0);
        $text = trim($_POST['text'] ?? '');
        $exp = trim($_POST['explanation'] ?? '');
        $return_url = $_POST['return_url'] ?? '?page=bank_soal';
        
        if ($qid > 0 && $text !== '') {
            // Update question
            q("UPDATE questions SET text=?, explanation=?, updated_at=? WHERE id=?", 
              [$text, ($exp ?: null), now(), $qid]);
            
            // Update choices
            $cidArr = array_map('intval', $_POST['cid'] ?? []);
            $ctextArr = array_map('trim', $_POST['ctext'] ?? []);
            $correct_index = (int)($_POST['correct_index'] ?? 1);
            
            // Sync choices
            $pairs = [];
            for ($i = 0; $i < count($ctextArr); $i++) {
                if ($ctextArr[$i] !== '') {
                    $pairs[] = ['id' => $cidArr[$i] ?? 0, 'text' => $ctextArr[$i]];
                }
            }
            
            $n = count($pairs);
            if ($n >= 2 && $n <= 5) {
                $oldIds = q("SELECT id FROM choices WHERE question_id=? ORDER BY id", [$qid])->fetchAll(PDO::FETCH_COLUMN);
                $newIds = [];
                
                foreach ($pairs as $p) {
                    if ($p['id'] > 0) {
                        q("UPDATE choices SET text=? WHERE id=?", [$p['text'], $p['id']]);
                        $newIds[] = (int)$p['id'];
                    } else {
                        q("INSERT INTO choices (question_id, text, is_correct) VALUES (?,?,0)", [$qid, $p['text']]);
                        $newIds[] = (int)pdo()->lastInsertId();
                    }
                }
                
                // Delete removed choices
                foreach ($oldIds as $oid) {
                    if (!in_array($oid, $newIds, true)) {
                        q("DELETE FROM choices WHERE id=?", [$oid]);
                    }
                }
                
                // Set correct answer
                $correct_choice_id = $newIds[$correct_index - 1];
                q("UPDATE choices SET is_correct = CASE WHEN id=? THEN 1 ELSE 0 END WHERE question_id=?", 
                  [$correct_choice_id, $qid]);
            }
            
            redirect($return_url . '&success=1&msg=' . urlencode('Soal berhasil diupdate'));
        }
        redirect($return_url);
    }
    
    if ($act === 'delete_question') {
        $qid = (int)($_POST['question_id'] ?? 0);
        $return_url = $_POST['return_url'] ?? '?page=bank_soal';
        if ($qid > 0) {
            q("DELETE FROM questions WHERE id=?", [$qid]);
            redirect($return_url . '&success=1&msg=' . urlencode('Soal berhasil dihapus'));
        }
        redirect($return_url);
    }
}

// Tampilkan view
require __DIR__ . '/../views/bank_soal.php';
