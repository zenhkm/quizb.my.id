<?php
// views/teacher_bank_soal.php
// Bank Soal Terintegrasi untuk Pengajar

if (!is_pengajar() && !is_admin()) {
    echo '<div class="alert alert-danger">Akses pengajar diperlukan.</div>';
    return;
}

$user_id = uid();
$selected_theme = (int)($_GET['theme_id'] ?? 0);
$selected_subtheme = (int)($_GET['subtheme_id'] ?? 0);
$selected_title = (int)($_GET['title_id'] ?? 0);
$edit_question = (int)($_GET['edit_q'] ?? 0);

// Master list (untuk modal tambah judul). Tidak ditampilkan di sidebar.
$all_themes = q("SELECT id, name FROM themes ORDER BY sort_order, name")->fetchAll();

// Ambil data untuk panel - hanya tema/subtema yang punya judul milik pengajar
// (agar tema/subtema yang hanya berisi konten admin tidak muncul)
$themes = q(
    "
    SELECT DISTINCT t.*
    FROM themes t
    JOIN subthemes st ON st.theme_id = t.id
    JOIN quiz_titles qt ON qt.subtheme_id = st.id
    WHERE qt.owner_user_id = ?
    ORDER BY t.sort_order, t.name
    ",
    [$user_id]
)->fetchAll();
$subthemes = [];
$titles = [];
$questions = [];
$question_detail = null;

if ($selected_theme) {
    $subthemes = q(
        "
        SELECT DISTINCT st.*
        FROM subthemes st
        JOIN quiz_titles qt ON qt.subtheme_id = st.id
        WHERE st.theme_id = ?
          AND qt.owner_user_id = ?
        ORDER BY st.name
        ",
        [$selected_theme, $user_id]
    )->fetchAll();
}

if ($selected_subtheme) {
    // HANYA judul milik pengajar
    $titles = q("SELECT * FROM quiz_titles WHERE subtheme_id = ? AND owner_user_id = ? ORDER BY title", 
                [$selected_subtheme, $user_id])->fetchAll();
}

if ($selected_title) {
    // Verifikasi ownership
    $title_check = q("SELECT id FROM quiz_titles WHERE id = ? AND owner_user_id = ?", [$selected_title, $user_id])->fetch();
    if (!$title_check) {
        echo '<div class="alert alert-danger">Anda tidak memiliki akses ke judul ini.</div>';
        return;
    }
    
    $questions = q("
        SELECT q.*, COUNT(c.id) as choice_count
        FROM questions q
        LEFT JOIN choices c ON c.question_id = q.id
        WHERE q.title_id = ? AND q.owner_user_id = ?
        GROUP BY q.id
        ORDER BY q.id
    ", [$selected_title, $user_id])->fetchAll();
    
    $title_info = q("
        SELECT qt.title, st.name as subtheme, t.name as theme
        FROM quiz_titles qt
        JOIN subthemes st ON st.id = qt.subtheme_id
        JOIN themes t ON t.id = st.theme_id
        WHERE qt.id = ?
          AND qt.owner_user_id = ?
    ", [$selected_title, $user_id])->fetch();
}

if ($edit_question) {
    $question_detail = q("SELECT * FROM questions WHERE id = ? AND owner_user_id = ?", [$edit_question, $user_id])->fetch();
    if ($question_detail) {
        $choices = q("SELECT * FROM choices WHERE question_id = ? ORDER BY id", [$edit_question])->fetchAll();
    }
}

$success = $_GET['success'] ?? '';
$message = $_GET['msg'] ?? '';
?>



<style>
.bank-soal-container {
    display: grid;
    grid-template-columns: 280px 280px 1fr;
    gap: 20px;
    height: calc(100vh - 180px);
    min-height: 600px;
}

.panel {
    background: var(--bs-body-bg);
    border: 1px solid var(--bs-border-color);
    border-radius: 12px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.panel-header {
    padding: 16px 20px;
    background: linear-gradient(135deg, var(--brand) 0%, #0a9b5e 100%);
    color: white;
    font-weight: 600;
    font-size: 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 2px solid rgba(255,255,255,0.2);
}

.panel-body {
    flex: 1;
    overflow-y: auto;
    padding: 0;
}

.panel-item {
    padding: 12px 20px;
    border-bottom: 1px solid var(--bs-border-color);
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.panel-item:hover {
    background: var(--bs-tertiary-bg);
    padding-left: 24px;
}

.panel-item.active {
    background: linear-gradient(90deg, rgba(15, 178, 107, 0.1) 0%, transparent 100%);
    border-left: 3px solid var(--brand);
    font-weight: 600;
    color: var(--brand);
}

.panel-item-actions {
    display: none;
    gap: 4px;
}

.panel-item:hover .panel-item-actions {
    display: flex;
}

.btn-icon {
    width: 28px;
    height: 28px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    font-size: 14px;
}

.empty-state {
    padding: 60px 20px;
    text-align: center;
    color: var(--bs-secondary-color);
}

.empty-state svg {
    opacity: 0.3;
    margin-bottom: 16px;
}

.question-card {
    background: var(--bs-body-bg);
    border: 1px solid var(--bs-border-color);
    border-radius: 10px;
    padding: 16px;
    margin-bottom: 12px;
    transition: all 0.2s;
}

.question-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    border-color: var(--brand);
}

.question-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    background: var(--brand);
    color: white;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    margin-right: 12px;
}

.choice-badge {
    display: inline-block;
    padding: 4px 10px;
    background: var(--bs-tertiary-bg);
    border-radius: 6px;
    font-size: 13px;
    margin-right: 6px;
    margin-bottom: 6px;
}

.choice-badge.correct {
    background: #dcfce7;
    color: #166534;
    font-weight: 600;
}

@media (max-width: 1200px) {
    .bank-soal-container {
        grid-template-columns: 1fr;
        height: auto;
    }
    .panel {
        height: 400px;
    }
}
</style>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?= h($message ?: 'Operasi berhasil!') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1">
            <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="currentColor" class="bi bi-bank me-2" viewBox="0 0 16 16">
                <path d="m8 0 6.61 3h.89a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-.5.5H15v7a.5.5 0 0 1 .485.38l.5 2a.498.498 0 0 1-.485.62H.5a.498.498 0 0 1-.485-.62l.5-2A.501.501 0 0 1 1 13V6H.5a.5.5 0 0 1-.5-.5v-2A.5.5 0 0 1 .5 3h.89L8 0ZM3.777 3h8.447L8 1 3.777 3ZM2 6v7h1V6H2Zm2 0v7h2.5V6H4Zm3.5 0v7h1V6h-1Zm2 0v7H12V6H9.5ZM13 6v7h1V6h-1Zm2-1V4H1v1h14Zm-.39 9H1.39l-.25 1h13.72l-.25-1Z"/>
            </svg>
            Bank Soal Saya
        </h2>
        <p class="text-muted mb-0">Kelola judul dan soal milik Anda</p>
    </div>
    <div class="btn-group">
        <button type="button" class="btn btn-primary" onclick="showAddTitleModal()">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" class="me-1" aria-hidden="true">
                <path d="M8 1a.5.5 0 0 1 .5.5V7.5H14.5a.5.5 0 0 1 0 1H8.5V14.5a.5.5 0 0 1-1 0V8.5H1.5a.5.5 0 0 1 0-1H7.5V1.5A.5.5 0 0 1 8 1"/>
            </svg>
            Tambah Judul
        </button>
        <a href="?page=import_questions" class="btn btn-outline-primary">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-upload" viewBox="0 0 16 16">
                <path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/>
                <path d="M7.646 1.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1-.708.708L8.5 2.707V11.5a.5.5 0 0 1-1 0V2.707L5.354 4.854a.5.5 0 1 1-.708-.708l3-3z"/>
            </svg>
            Import Soal
        </a>
    </div>
</div>

<div class="bank-soal-container">
    <!-- PANEL 1: TEMA -->
    <div class="panel">
        <div class="panel-header">
            <span>📚 Tema</span>
        </div>
        <div class="panel-body">
            <?php if (empty($themes)): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M1 2.5A1.5 1.5 0 0 1 2.5 1h3A1.5 1.5 0 0 1 7 2.5v3A1.5 1.5 0 0 1 5.5 7h-3A1.5 1.5 0 0 1 1 5.5v-3zM2.5 2a.5.5 0 0 0-.5.5v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3a.5.5 0 0 0-.5-.5h-3zm6.5.5A1.5 1.5 0 0 1 10.5 1h3A1.5 1.5 0 0 1 15 2.5v3A1.5 1.5 0 0 1 13.5 7h-3A1.5 1.5 0 0 1 9 5.5v-3zm1.5-.5a.5.5 0 0 0-.5.5v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3a.5.5 0 0 0-.5-.5h-3zM1 10.5A1.5 1.5 0 0 1 2.5 9h3A1.5 1.5 0 0 1 7 10.5v3A1.5 1.5 0 0 1 5.5 15h-3A1.5 1.5 0 0 1 1 13.5v-3zm1.5-.5a.5.5 0 0 0-.5.5v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3a.5.5 0 0 0-.5-.5h-3zm6.5.5A1.5 1.5 0 0 1 10.5 9h3a1.5 1.5 0 0 1 1.5 1.5v3a1.5 1.5 0 0 1-1.5 1.5h-3A1.5 1.5 0 0 1 9 13.5v-3zm1.5-.5a.5.5 0 0 0-.5.5v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3a.5.5 0 0 0-.5-.5h-3z"/>
                </svg>
                <p class="mb-0">Belum ada tema untuk konten Anda</p>
                <small>Tambahkan judul/soal terlebih dahulu</small>
            </div>
            <?php else: ?>
                <?php foreach ($themes as $theme): ?>
                <div class="panel-item <?= $theme['id'] == $selected_theme ? 'active' : '' ?>" 
                     onclick="window.location='?page=teacher_bank_soal&theme_id=<?= $theme['id'] ?>'">
                    <span><?= h($theme['name']) ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- PANEL 2: SUBTEMA -->
    <div class="panel">
        <div class="panel-header">
            <span>📖 Subtema</span>
        </div>
        <div class="panel-body">
            <?php if (!$selected_theme): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M8 4.754a3.246 3.246 0 1 0 0 6.492 3.246 3.246 0 0 0 0-6.492zM5.754 8a2.246 2.246 0 1 1 4.492 0 2.246 2.246 0 0 1-4.492 0z"/>
                    <path d="M9.796 1.343c-.527-1.79-3.065-1.79-3.592 0l-.094.319a.873.873 0 0 1-1.255.52l-.292-.16c-1.64-.892-3.433.902-2.54 2.541l.159.292a.873.873 0 0 1-.52 1.255l-.319.094c-1.79.527-1.79 3.065 0 3.592l.319.094a.873.873 0 0 1 .52 1.255l-.16.292c-.892 1.64.901 3.434 2.541 2.54l.292-.159a.873.873 0 0 1 1.255.52l.094.319c.527 1.79 3.065 1.79 3.592 0l.094-.319a.873.873 0 0 1 1.255-.52l.292.16c1.64.893 3.434-.902 2.54-2.541l-.159-.292a.873.873 0 0 1 .52-1.255l.319-.094c1.79-.527 1.79-3.065 0-3.592l-.319-.094a.873.873 0 0 1-.52-1.255l.16-.292c.893-1.64-.902-3.433-2.541-2.54l-.292.159a.873.873 0 0 1-1.255-.52l-.094-.319z"/>
                </svg>
                <p class="mb-0">Pilih tema terlebih dahulu</p>
            </div>
            <?php elseif (empty($subthemes)): ?>
            <div class="empty-state">
                <p class="mb-0">Belum ada subtema untuk konten Anda</p>
                <small>Pilih tema lain atau tambahkan judul pada subtema</small>
            </div>
            <?php else: ?>
                <?php foreach ($subthemes as $sub): ?>
                <div class="panel-item <?= $sub['id'] == $selected_subtheme ? 'active' : '' ?>" 
                     onclick="window.location='?page=teacher_bank_soal&theme_id=<?= $selected_theme ?>&subtheme_id=<?= $sub['id'] ?>'">
                    <span><?= h($sub['name']) ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- PANEL 3: JUDUL & SOAL -->
    <div class="panel">
        <div class="panel-header">
            <span>📝 Judul & Soal Saya</span>
            <?php if ($selected_subtheme): ?>
            <button class="btn btn-sm btn-light" onclick="showAddTitleModal(<?= $selected_subtheme ?>)">+ Judul</button>
            <?php endif; ?>
        </div>
        <div class="panel-body" style="padding: 16px;">
            <?php if (!$selected_subtheme): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M5 4a.5.5 0 0 0 0 1h6a.5.5 0 0 0 0-1H5zm-.5 2.5A.5.5 0 0 1 5 6h6a.5.5 0 0 1 0 1H5a.5.5 0 0 1-.5-.5zM5 8a.5.5 0 0 0 0 1h6a.5.5 0 0 0 0-1H5zm0 2a.5.5 0 0 0 0 1h3a.5.5 0 0 0 0-1H5z"/>
                    <path d="M2 2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V2zm10-1H4a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1z"/>
                </svg>
                <p class="mb-0">Pilih subtema terlebih dahulu</p>
            </div>
            <?php elseif (empty($titles)): ?>
            <div class="empty-state">
                <p class="mb-0">Belum ada judul soal milik Anda</p>
                <small>Klik + Judul untuk menambah</small>
            </div>
            <?php else: ?>
                <!-- List untuk judul dengan tombol edit/delete -->
                <div class="mb-3">
                    <label class="form-label fw-bold">Daftar Judul Soal Saya:</label>
                    <div class="list-group">
                        <?php foreach ($titles as $idx => $title): ?>
                        <div class="list-group-item <?= $title['id'] == $selected_title ? 'active' : '' ?>" 
                             style="cursor: pointer; display: flex; justify-content: space-between; align-items: center;"
                             onclick="window.location='?page=teacher_bank_soal&theme_id=<?= $selected_theme ?>&subtheme_id=<?= $selected_subtheme ?>&title_id=<?= $title['id'] ?>'">
                            <span><?= h($title['title']) ?></span>
                            <div onclick="event.stopPropagation();" style="display: flex; gap: 4px;">
                                <button class="btn btn-sm btn-outline-primary" onclick="editTitle(<?= $title['id'] ?>, '<?= h($title['title']) ?>', <?= $selected_subtheme ?>)" title="Edit">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                                        <path d="M12.146.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1 0 .708l-10 10a.5.5 0 0 1-.168.11l-5 2a.5.5 0 0 1-.65-.65l2-5a.5.5 0 0 1 .11-.168zM11.207 2L2 11.207V13h1.793L13 3.793z"/>
                                    </svg>
                                </button>
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteTitle(<?= $title['id'] ?>)" title="Hapus">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                                        <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m2.5 0A.5.5 0 0 1 8.5 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0z"/>
                                        <path d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4zM2.5 3h11V2h-11z"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if ($selected_title): ?>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0"><?= h($title_info['title']) ?></h5>
                    <button class="btn btn-primary btn-sm" onclick="showAddQuestionModal()">+ Tambah Soal</button>
                </div>

                <?php if ($edit_question && $question_detail): ?>
                <!-- Form Edit Soal -->
                <div class="card mb-3 border-primary">
                    <div class="card-header bg-primary text-white">
                        <strong>Edit Soal #<?= $edit_question ?></strong>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="?page=teacher_bank_soal">
                            <input type="hidden" name="act" value="update_question">
                            <input type="hidden" name="question_id" value="<?= $edit_question ?>">
                            <input type="hidden" name="title_id" value="<?= $selected_title ?>">
                            <input type="hidden" name="return_url" value="?page=teacher_bank_soal&theme_id=<?= $selected_theme ?>&subtheme_id=<?= $selected_subtheme ?>&title_id=<?= $selected_title ?>">
                            
                            <div class="mb-3">
                                <label class="form-label">Pertanyaan</label>
                                <textarea name="text" class="form-control" rows="3" required><?= h($question_detail['text']) ?></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Pilihan Jawaban</label>
                                <?php 
                                $letters = ['A', 'B', 'C', 'D', 'E'];
                                foreach ($choices as $idx => $choice): 
                                ?>
                                <div class="input-group mb-2">
                                    <span class="input-group-text"><?= $letters[$idx] ?></span>
                                    <input type="hidden" name="cid[]" value="<?= $choice['id'] ?>">
                                    <input type="text" name="ctext[]" class="form-control" value="<?= h($choice['text']) ?>" required>
                                    <div class="input-group-text">
                                        <input type="radio" name="correct_index" value="<?= $idx + 1 ?>" <?= $choice['is_correct'] ? 'checked' : '' ?>> Benar
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Penjelasan (Opsional)</label>
                                <textarea name="explanation" class="form-control" rows="2"><?= h($question_detail['explanation'] ?? '') ?></textarea>
                            </div>
                            
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">Update Soal</button>
                                <a href="?page=teacher_bank_soal&theme_id=<?= $selected_theme ?>&subtheme_id=<?= $selected_subtheme ?>&title_id=<?= $selected_title ?>" class="btn btn-secondary">Batal</a>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (empty($questions)): ?>
                <div class="alert alert-info">Belum ada soal. Klik "Tambah Soal" untuk mulai menambahkan.</div>
                <?php else: ?>
                    <?php foreach ($questions as $idx => $q): ?>
                    <div class="question-card">
                        <div class="d-flex align-items-start">
                            <span class="question-number"><?= $idx + 1 ?></span>
                            <div class="flex-grow-1">
                                <p class="mb-2"><strong><?= h($q['text']) ?></strong></p>
                                <?php 
                                // Load choices untuk setiap soal
                                $q_choices = q("SELECT * FROM choices WHERE question_id = ? ORDER BY id", [$q['id']])->fetchAll();
                                if (!empty($q_choices)):
                                ?>
                                <div class="mb-2">
                                    <?php 
                                    $letters = ['A', 'B', 'C', 'D', 'E'];
                                    foreach ($q_choices as $cidx => $ch): 
                                    ?>
                                    <span class="choice-badge <?= $ch['is_correct'] ? 'correct' : '' ?>">
                                        <?= $letters[$cidx] ?>. <?= h($ch['text']) ?>
                                    </span>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                                <?php if ($q['explanation']): ?>
                                <details class="mt-2">
                                    <summary class="text-muted" style="cursor: pointer; font-size: 13px;">Lihat Penjelasan</summary>
                                    <p class="mt-2 mb-0" style="font-size: 13px;"><?= h($q['explanation']) ?></p>
                                </details>
                                <?php endif; ?>
                            </div>
                            <div class="btn-group btn-group-sm">
                                <a href="?page=teacher_bank_soal&theme_id=<?= $selected_theme ?>&subtheme_id=<?= $selected_subtheme ?>&title_id=<?= $selected_title ?>&edit_q=<?= $q['id'] ?>" class="btn btn-outline-primary">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true" class="me-1">
                                        <path d="M12.146.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1 0 .708l-10 10a.5.5 0 0 1-.168.11l-5 2a.5.5 0 0 1-.65-.65l2-5a.5.5 0 0 1 .11-.168zM11.207 2L2 11.207V13h1.793L13 3.793z"/>
                                    </svg>
                                    Edit
                                </a>
                                <button class="btn btn-outline-danger" onclick="deleteQuestion(<?= $q['id'] ?>)">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true" class="me-1">
                                        <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m2.5 0A.5.5 0 0 1 8.5 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0z"/>
                                        <path d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4zM2.5 3h11V2h-11z"/>
                                    </svg>
                                    Hapus
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Add/Edit Title -->
<div class="modal fade" id="titleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="?page=teacher_bank_soal">
                <div class="modal-header">
                    <h5 class="modal-title" id="titleModalTitle">Tambah Judul Soal</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="act" id="title_act" value="add_title">
                    <input type="hidden" name="subtheme_id" id="title_subtheme_id">
                    <input type="hidden" name="title_id" id="title_id">
                    <input type="hidden" name="theme_id" id="title_theme_id">

                    <div id="title-picker" class="mb-3" style="display:none;">
                        <label class="form-label">Tema</label>
                        <select id="title_theme_select" class="form-select"></select>
                        <div class="mt-3">
                            <label class="form-label">Subtema</label>
                            <select id="title_subtheme_select" class="form-select"></select>
                            <div id="title_subtheme_help" class="form-text"></div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Judul Soal</label>
                        <input type="text" name="title" id="title_name" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary" id="btnSaveTitle">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Add/Edit Question -->
<div class="modal fade" id="questionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="?page=teacher_bank_soal">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Soal</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="act" value="add_question">
                    <input type="hidden" name="title_id" value="<?= $selected_title ?>">
                    <input type="hidden" name="return_url" value="<?= h($_SERVER['REQUEST_URI']) ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Pertanyaan</label>
                        <textarea name="text" class="form-control" rows="3" required></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Pilihan Jawaban</label>
                        <div id="choices-container">
                            <div class="input-group mb-2">
                                <span class="input-group-text">A</span>
                                <input type="text" name="choice_text[]" class="form-control" required>
                                <div class="input-group-text">
                                    <input type="radio" name="correct_index" value="1" checked> Benar
                                </div>
                            </div>
                            <div class="input-group mb-2">
                                <span class="input-group-text">B</span>
                                <input type="text" name="choice_text[]" class="form-control" required>
                                <div class="input-group-text">
                                    <input type="radio" name="correct_index" value="2"> Benar
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addChoice()">+ Tambah Pilihan</button>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Penjelasan (Opsional)</label>
                        <textarea name="explanation" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let choiceCount = 2;

const ALL_THEMES = <?= json_encode($all_themes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function setTitleSaveEnabled(isEnabled, helpText = '') {
    const btn = document.getElementById('btnSaveTitle');
    const help = document.getElementById('title_subtheme_help');
    if (btn) btn.disabled = !isEnabled;
    if (help) help.textContent = helpText || '';
}

async function loadSubthemesForTheme(themeId, preferSubthemeId = 0) {
    const subSelect = document.getElementById('title_subtheme_select');
    const hiddenSub = document.getElementById('title_subtheme_id');
    if (!subSelect || !hiddenSub) return;

    subSelect.innerHTML = '';
    hiddenSub.value = '';
    setTitleSaveEnabled(false, 'Memuat subtema...');

    try {
        const res = await fetch(`?action=api_get_subthemes&theme_id=${encodeURIComponent(themeId)}`);
        if (!res.ok) throw new Error('Gagal memuat subtema');
        const data = await res.json();

        if (!Array.isArray(data) || data.length === 0) {
            setTitleSaveEnabled(false, 'Tidak ada subtema pada tema ini.');
            return;
        }

        for (const st of data) {
            const opt = document.createElement('option');
            opt.value = String(st.id);
            opt.textContent = st.name;
            subSelect.appendChild(opt);
        }

        const targetId = preferSubthemeId && data.some(x => Number(x.id) === Number(preferSubthemeId))
            ? String(preferSubthemeId)
            : String(data[0].id);

        subSelect.value = targetId;
        hiddenSub.value = targetId;
        setTitleSaveEnabled(true, '');
    } catch (e) {
        console.error(e);
        setTitleSaveEnabled(false, 'Gagal memuat subtema.');
    }
}

function initTitlePicker() {
    const picker = document.getElementById('title-picker');
    const themeSelect = document.getElementById('title_theme_select');
    const subSelect = document.getElementById('title_subtheme_select');
    const hiddenTheme = document.getElementById('title_theme_id');
    const hiddenSub = document.getElementById('title_subtheme_id');

    if (!picker || !themeSelect || !subSelect || !hiddenTheme || !hiddenSub) return;

    picker.style.display = 'block';
    themeSelect.innerHTML = '';

    if (!Array.isArray(ALL_THEMES) || ALL_THEMES.length === 0) {
        setTitleSaveEnabled(false, 'Tema belum tersedia.');
        return;
    }

    for (const t of ALL_THEMES) {
        const opt = document.createElement('option');
        opt.value = String(t.id);
        opt.textContent = t.name;
        themeSelect.appendChild(opt);
    }

    const qs = new URLSearchParams(window.location.search);
    const preferThemeId = Number(qs.get('theme_id') || 0);
    const preferSubthemeId = Number(qs.get('subtheme_id') || 0);
    const initialThemeId = preferThemeId && ALL_THEMES.some(x => Number(x.id) === preferThemeId)
        ? String(preferThemeId)
        : String(ALL_THEMES[0].id);

    themeSelect.value = initialThemeId;
    hiddenTheme.value = initialThemeId;

    themeSelect.onchange = async () => {
        hiddenTheme.value = themeSelect.value;
        await loadSubthemesForTheme(themeSelect.value, 0);
    };
    subSelect.onchange = () => {
        hiddenSub.value = subSelect.value;
    };

    loadSubthemesForTheme(initialThemeId, preferSubthemeId);
}

function showAddTitleModal(subthemeId) {
    document.getElementById('title_act').value = 'add_title';
    document.getElementById('titleModalTitle').textContent = 'Tambah Judul Soal';
    const picker = document.getElementById('title-picker');
    const hiddenSub = document.getElementById('title_subtheme_id');

    if (subthemeId) {
        if (picker) picker.style.display = 'none';
        hiddenSub.value = subthemeId;
        const themeId = new URLSearchParams(window.location.search).get('theme_id');
        document.getElementById('title_theme_id').value = themeId || '';
        setTitleSaveEnabled(true, '');
    } else {
        hiddenSub.value = '';
        document.getElementById('title_theme_id').value = '';
        initTitlePicker();
    }

    document.getElementById('title_name').value = '';
    document.getElementById('title_id').value = '';
    new bootstrap.Modal(document.getElementById('titleModal')).show();
}

function editTitle(id, name, subthemeId) {
    document.getElementById('title_act').value = 'edit_title';
    document.getElementById('titleModalTitle').textContent = 'Edit Judul Soal';
    document.getElementById('title_id').value = id;
    document.getElementById('title_name').value = name;
    document.getElementById('title_subtheme_id').value = subthemeId;
    const themeId = new URLSearchParams(window.location.search).get('theme_id');
    document.getElementById('title_theme_id').value = themeId;
    new bootstrap.Modal(document.getElementById('titleModal')).show();
}

function deleteTitle(id) {
    if (confirm('Yakin hapus judul ini? Semua soal akan ikut terhapus!')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '?page=teacher_bank_soal';
        const themeId = new URLSearchParams(window.location.search).get('theme_id');
        const subthemeId = new URLSearchParams(window.location.search).get('subtheme_id');
        form.innerHTML = `
            <input type="hidden" name="act" value="delete_title">
            <input type="hidden" name="title_id" value="${id}">
            <input type="hidden" name="theme_id" value="${themeId}">
            <input type="hidden" name="subtheme_id" value="${subthemeId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function showAddQuestionModal() {
    new bootstrap.Modal(document.getElementById('questionModal')).show();
}

function addChoice() {
    if (choiceCount >= 5) {
        alert('Maksimal 5 pilihan');
        return;
    }
    choiceCount++;
    const letters = ['C', 'D', 'E'];
    const letter = letters[choiceCount - 3];
    const container = document.getElementById('choices-container');
    const div = document.createElement('div');
    div.className = 'input-group mb-2';
    div.innerHTML = `
        <span class="input-group-text">${letter}</span>
        <input type="text" name="choice_text[]" class="form-control">
        <div class="input-group-text">
            <input type="radio" name="correct_index" value="${choiceCount}"> Benar
        </div>
        <button type="button" class="btn btn-outline-danger" onclick="this.parentElement.remove(); choiceCount--;">×</button>
    `;
    container.appendChild(div);
}

function deleteQuestion(id) {
    if (confirm('Yakin hapus soal ini?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '?page=teacher_bank_soal';
        form.innerHTML = `
            <input type="hidden" name="act" value="delete_question">
            <input type="hidden" name="question_id" value="${id}">
            <input type="hidden" name="return_url" value="${location.href}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>
