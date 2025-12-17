<?php
if (!uid()) die('Akses ditolak');
if (is_admin()) die('Admin tidak perlu download');

$title_id = (int)($_GET['title_id'] ?? 0);
if ($title_id <= 0) die('ID Judul tidak valid');

// Ambil Info Judul
$title_info = q("SELECT qt.title, st.name as subtheme, t.name as theme 
                  FROM quiz_titles qt
                  JOIN subthemes st ON qt.subtheme_id = st.id
                  JOIN themes t ON st.theme_id = t.id
                  WHERE qt.id = ?", [$title_id])->fetch();

if (!$title_info) die('Data tidak ditemukan');

// Ambil Soal
$questions = q("SELECT * FROM questions WHERE title_id = ? ORDER BY id ASC", [$title_id])->fetchAll();

// Nama file untuk PDF
$filename = preg_replace('/[^a-zA-Z0-9]/', '_', $title_info['title']);

// Output HTML biasa (bukan attachment Word)
echo '<!DOCTYPE html>';
echo '<html>';
echo '<head>';
echo '<meta charset="utf-8">';
echo '<title>Download PDF</title>';
// Sertakan html2pdf.js dari CDN
echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>';
echo '<style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        .question { margin-bottom: 20px; page-break-inside: avoid; }
        .choices { margin-left: 20px; list-style-type: none; padding: 0; }
        .choices li { margin-bottom: 5px; }
        .correct { font-weight: bold; color: green; text-decoration: underline; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 10px; }
        #loading { font-size: 1.2em; font-weight: bold; text-align: center; margin-top: 50px; }
      </style>';
echo '</head>';
echo '<body>';

echo '<div id="loading">Sedang memproses PDF... Mohon tunggu.</div>';

// Container konten yang akan di-convert ke PDF
echo '<div id="content-to-pdf" style="display:none;">';

echo '<div class="header">';
echo '<h1>' . h($title_info['title']) . '</h1>';
echo '<p>Tema: ' . h($title_info['theme']) . ' | Subtema: ' . h($title_info['subtheme']) . '</p>';
echo '</div>';

if ($questions) {
    $no = 1;
    foreach ($questions as $q) {
        echo '<div class="question">';
        echo '<p><strong>' . $no++ . '. ' . nl2br(h($q['text'])) . '</strong></p>';
        
        // Ambil Pilihan
        $choices = q("SELECT * FROM choices WHERE question_id = ? ORDER BY id ASC", [$q['id']])->fetchAll();
        
        if ($choices) {
            echo '<ul class="choices">';
            $abc = range('A', 'Z');
            foreach ($choices as $idx => $c) {
                $marker = isset($abc[$idx]) ? $abc[$idx] . '. ' : '- ';
                $class = $c['is_correct'] ? 'class="correct"' : '';
                $text = h($c['text']);
                if ($c['is_correct']) $text .= ' (Kunci)';
                
                echo '<li ' . $class . '>' . $marker . $text . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }
} else {
    echo '<p>Belum ada soal.</p>';
}
echo '</div>'; // End #content-to-pdf

// Script untuk generate PDF otomatis
echo '<script>
    window.onload = function() {
        var element = document.getElementById("content-to-pdf");
        // Tampilkan dulu agar bisa dirender
        element.style.display = "block";
        
        var opt = {
          margin:       [0.5, 0.5, 0.5, 0.5],
          filename:     "' . $filename . '.pdf",
          image:        { type: "jpeg", quality: 0.98 },
          html2canvas:  { scale: 2, useCORS: true },
          jsPDF:        { unit: "in", format: "letter", orientation: "portrait" }
        };

        html2pdf().set(opt).from(element).save().then(function(){
            document.getElementById("loading").innerHTML = "PDF telah didownload. Anda boleh menutup tab ini.";
        }).catch(function(err){
            document.getElementById("loading").innerHTML = "Gagal membuat PDF: " + err;
            element.style.display = "block";
        });
    };
</script>';

echo '</body>';
echo '</html>';
exit;
