<div class="container py-4">
    <h3>Download Soal</h3>
    <p class="text-muted">Unduh soal-soal untuk belajar offline.</p>

    <?php
    // Ambil semua tema, subtema, dan judul yang tersedia (Global + Milik Pengajar jika relevan)
    // Untuk simplifikasi, kita ambil yang global dulu atau yang bisa diakses user.
    // Kita gunakan logika yang mirip dengan view_themes/view_titles tapi digabung.
    
    // Ambil Tema
    $themes = q("SELECT * FROM themes WHERE owner_user_id IS NULL ORDER BY sort_order, name")->fetchAll();

    if (!$themes) {
        echo '<div class="alert alert-info">Belum ada soal yang tersedia untuk diunduh.</div>';
    } else {
        echo '<div class="accordion" id="accordionDownload">';
        
        foreach ($themes as $index => $theme) {
            $collapseId = "collapseTheme" . $theme['id'];
            $headingId = "headingTheme" . $theme['id'];
            
            echo '<div class="accordion-item">';
            echo '<h2 class="accordion-header" id="' . $headingId . '">';
            echo '<button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#' . $collapseId . '" aria-expanded="false" aria-controls="' . $collapseId . '">';
            echo h($theme['name']);
            echo '</button>';
            echo '</h2>';
            echo '<div id="' . $collapseId . '" class="accordion-collapse collapse" aria-labelledby="' . $headingId . '" data-bs-parent="#accordionDownload">';
            echo '<div class="accordion-body">';
            
            // Ambil Subtema
            $subthemes = q("SELECT * FROM subthemes WHERE theme_id = ? ORDER BY name", [$theme['id']])->fetchAll();
            
            if ($subthemes) {
                foreach ($subthemes as $sub) {
                    echo '<div class="mb-3">';
                    echo '<h6 class="fw-bold">' . h($sub['name']) . '</h6>';
                    
                    // Ambil Judul
                    $titles = q("SELECT * FROM quiz_titles WHERE subtheme_id = ? ORDER BY title", [$sub['id']])->fetchAll();
                    
                    if ($titles) {
                        echo '<div class="list-group">';
                        foreach ($titles as $title) {
                            echo '<div class="list-group-item d-flex justify-content-between align-items-center">';
                            echo '<span>' . h($title['title']) . '</span>';
                            echo '<a href="?action=download_questions&title_id=' . $title['id'] . '" class="btn btn-sm btn-outline-primary" target="_blank">';
                            echo '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-download me-1" viewBox="0 0 16 16"><path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/><path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/></svg>';
                            echo 'Download PDF';
                            echo '</a>';
                            echo '</div>';
                        }
                        echo '</div>';
                    } else {
                        echo '<small class="text-muted">Tidak ada judul soal.</small>';
                    }
                    echo '</div>';
                }
            } else {
                echo '<p class="text-muted">Tidak ada subtema.</p>';
            }
            
            echo '</div>'; // accordion-body
            echo '</div>'; // accordion-collapse
            echo '</div>'; // accordion-item
        }
        
        echo '</div>'; // accordion
    }
    ?>
</div>
