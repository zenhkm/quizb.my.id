<?php
// views/bank_soal.php
// Bank Soal Terintegrasi - Menggabungkan CRUD dan QManage

if (!is_admin()) {
    echo '<div class="alert alert-danger">Akses admin diperlukan.</div>';
    return;
}

$selected_theme = (int)($_GET['theme_id'] ?? 0);
$selected_subtheme = (int)($_GET['subtheme_id'] ?? 0);
$selected_title = (int)($_GET['title_id'] ?? 0);
$edit_question = (int)($_GET['edit_q'] ?? 0);

// Ambil data untuk panel
$themes = q("SELECT * FROM themes WHERE deleted_at IS NULL ORDER BY sort_order, name")->fetchAll();
$subthemes = [];
$titles = [];
$questions = [];
$question_detail = null;

if ($selected_theme) {
    $subthemes = q("SELECT * FROM subthemes WHERE theme_id = ? AND deleted_at IS NULL ORDER BY name", [$selected_theme])->fetchAll();
}

if ($selected_subtheme) {
    $titles = q("SELECT * FROM quiz_titles WHERE subtheme_id = ? AND deleted_at IS NULL ORDER BY title", [$selected_subtheme])->fetchAll();
}

if ($selected_title) {
    $questions = q("
        SELECT q.*, COUNT(c.id) as choice_count
        FROM questions q
        LEFT JOIN choices c ON c.question_id = q.id
        WHERE q.title_id = ?
        GROUP BY q.id
        ORDER BY q.id
    ", [$selected_title])->fetchAll();
    
    $title_info = q("
        SELECT qt.title, st.name as subtheme, t.name as theme
        FROM quiz_titles qt
        JOIN subthemes st ON st.id = qt.subtheme_id
        JOIN themes t ON t.id = st.theme_id
        WHERE qt.id = ? AND qt.deleted_at IS NULL
    ", [$selected_title])->fetch();
}

if ($edit_question) {
    $question_detail = q("SELECT * FROM questions WHERE id = ?", [$edit_question])->fetch();
    $choices = q("SELECT * FROM choices WHERE question_id = ? ORDER BY id", [$edit_question])->fetchAll();
}

$is_editing_question = (bool)($edit_question && $question_detail);

$success = $_GET['success'] ?? '';
$message = $_GET['msg'] ?? '';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
.bank-soal-container {
    display: grid;
    grid-template-columns: 280px 280px 1fr;
    gap: 20px;
    height: calc(100vh - 180px);
    min-height: 600px;
}

/* Saat edit soal aktif: fokus ke editor, sembunyikan panel Tema/Subtema + daftar Judul */
.bank-soal-container.editing-question {
    grid-template-columns: 1fr;
}
.bank-soal-container.editing-question > .panel:nth-child(1),
.bank-soal-container.editing-question > .panel:nth-child(2) {
    display: none;
}
.bank-soal-container.editing-question .bank-soal-title-picker {
    display: none;
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
            Bank Soal
        </h2>
        <p class="text-muted mb-0">Kelola tema, subtema, judul, dan soal dalam satu halaman</p>
    </div>
    <div class="btn-group">
        <a href="?page=import_questions" class="btn btn-outline-primary">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-upload" viewBox="0 0 16 16">
                <path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/>
                <path d="M7.646 1.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1-.708.708L8.5 2.707V11.5a.5.5 0 0 1-1 0V2.707L5.354 4.854a.5.5 0 1 1-.708-.708l3-3z"/>
            </svg>
            Import Soal
        </a>
    </div>
</div>

<div class="bank-soal-container<?= $is_editing_question ? ' editing-question' : '' ?>">
    <!-- PANEL 1: TEMA -->
    <div class="panel">
        <div class="panel-header">
            <span>📚 Tema</span>
            <button class="btn btn-sm btn-light" onclick="showAddThemeModal()">+</button>
        </div>
        <div class="panel-body">
            <?php if (empty($themes)): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M1 2.5A1.5 1.5 0 0 1 2.5 1h3A1.5 1.5 0 0 1 7 2.5v3A1.5 1.5 0 0 1 5.5 7h-3A1.5 1.5 0 0 1 1 5.5v-3zM2.5 2a.5.5 0 0 0-.5.5v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3a.5.5 0 0 0-.5-.5h-3zm6.5.5A1.5 1.5 0 0 1 10.5 1h3A1.5 1.5 0 0 1 15 2.5v3A1.5 1.5 0 0 1 13.5 7h-3A1.5 1.5 0 0 1 9 5.5v-3zm1.5-.5a.5.5 0 0 0-.5.5v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3a.5.5 0 0 0-.5-.5h-3zM1 10.5A1.5 1.5 0 0 1 2.5 9h3A1.5 1.5 0 0 1 7 10.5v3A1.5 1.5 0 0 1 5.5 15h-3A1.5 1.5 0 0 1 1 13.5v-3zm1.5-.5a.5.5 0 0 0-.5.5v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3a.5.5 0 0 0-.5-.5h-3zm6.5.5A1.5 1.5 0 0 1 10.5 9h3a1.5 1.5 0 0 1 1.5 1.5v3a1.5 1.5 0 0 1-1.5 1.5h-3A1.5 1.5 0 0 1 9 13.5v-3zm1.5-.5a.5.5 0 0 0-.5.5v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3a.5.5 0 0 0-.5-.5h-3z"/>
                </svg>
                <p class="mb-0">Belum ada tema</p>
                <small>Klik + untuk menambah</small>
            </div>
            <?php else: ?>
                <?php foreach ($themes as $theme): ?>
                <div class="panel-item <?= $theme['id'] == $selected_theme ? 'active' : '' ?>" 
                     onclick="window.location='?page=bank_soal&theme_id=<?= $theme['id'] ?>'">
                    <span><?= h($theme['name']) ?></span>
                    <div class="panel-item-actions">
                        <button class="btn btn-icon btn-sm btn-outline-primary" onclick="event.stopPropagation(); editTheme(<?= $theme['id'] ?>, '<?= h($theme['name']) ?>')">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-icon btn-sm btn-outline-danger" onclick="event.stopPropagation(); deleteTheme(<?= $theme['id'] ?>)">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- PANEL 2: SUBTEMA -->
    <div class="panel">
        <div class="panel-header">
            <span>📖 Subtema</span>
            <?php if ($selected_theme): ?>
            <button class="btn btn-sm btn-light" onclick="showAddSubthemeModal(<?= $selected_theme ?>)">+</button>
            <?php endif; ?>
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
                <p class="mb-0">Belum ada subtema</p>
                <small>Klik + untuk menambah</small>
            </div>
            <?php else: ?>
                <?php foreach ($subthemes as $sub): ?>
                <div class="panel-item <?= $sub['id'] == $selected_subtheme ? 'active' : '' ?>" 
                     onclick="window.location='?page=bank_soal&theme_id=<?= $selected_theme ?>&subtheme_id=<?= $sub['id'] ?>'">
                    <span><?= h($sub['name']) ?></span>
                    <div class="panel-item-actions">
                        <button class="btn btn-icon btn-sm btn-outline-primary" onclick="event.stopPropagation(); editSubtheme(<?= $sub['id'] ?>, '<?= h($sub['name']) ?>')">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-icon btn-sm btn-outline-danger" onclick="event.stopPropagation(); deleteSubtheme(<?= $sub['id'] ?>)">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- PANEL 3: JUDUL & SOAL -->
    <div class="panel">
        <div class="panel-header">
            <span>📝 Judul & Soal</span>
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
                <p class="mb-0">Belum ada judul soal</p>
                <small>Klik + Judul untuk menambah</small>
            </div>
            <?php else: ?>
                <!-- List untuk judul dengan tombol edit/delete -->
                <div class="bank-soal-title-picker mb-3">
                    <label class="form-label fw-bold">Daftar Judul Soal:</label>
                    <div class="list-group">
                        <?php foreach ($titles as $idx => $title): ?>
                        <div class="list-group-item <?= $title['id'] == $selected_title ? 'active' : '' ?>" 
                             style="cursor: pointer; display: flex; justify-content: space-between; align-items: center;"
                             onclick="window.location='?page=bank_soal&theme_id=<?= $selected_theme ?>&subtheme_id=<?= $selected_subtheme ?>&title_id=<?= $title['id'] ?>'">
                            <span><?= h($title['title']) ?></span>
                            <div onclick="event.stopPropagation();" style="display: flex; gap: 4px;">
                                <button class="btn btn-sm btn-outline-primary" onclick="editTitle(<?= $title['id'] ?>, '<?= h($title['title']) ?>', <?= $selected_subtheme ?>)" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteTitle(<?= $title['id'] ?>)" title="Hapus">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if ($selected_title): ?>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0"><?= h($title_info['title']) ?></h5>
                    <div class="d-flex gap-2">
                        <a class="btn btn-outline-primary btn-sm" target="_blank" rel="noopener"
                           href="?page=bank_soal&theme_id=<?= $selected_theme ?>&subtheme_id=<?= $selected_subtheme ?>&title_id=<?= $selected_title ?>&act=download_excel">
                            <i class="bi bi-file-earmark-excel"></i>
                            Download Excel
                        </a>
                        <button class="btn btn-primary btn-sm" onclick="showAddQuestionModal()">+ Tambah Soal</button>
                    </div>
                </div>

                <?php if ($edit_question && $question_detail): ?>
                <!-- Form Edit Soal -->
                <div class="card mb-3 border-primary">
                    <div class="card-header bg-primary text-white">
                        <strong>Edit Soal #<?= $edit_question ?></strong>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="?page=bank_soal">
                            <input type="hidden" name="act" value="update_question">
                            <input type="hidden" name="question_id" value="<?= $edit_question ?>">
                            <input type="hidden" name="title_id" value="<?= $selected_title ?>">
                            <input type="hidden" name="return_url" value="?page=bank_soal&theme_id=<?= $selected_theme ?>&subtheme_id=<?= $selected_subtheme ?>&title_id=<?= $selected_title ?>">
                            
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
                                <a href="?page=bank_soal&theme_id=<?= $selected_theme ?>&subtheme_id=<?= $selected_subtheme ?>&title_id=<?= $selected_title ?>" class="btn btn-secondary">Batal</a>
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
                                <a href="?page=bank_soal&theme_id=<?= $selected_theme ?>&subtheme_id=<?= $selected_subtheme ?>&title_id=<?= $selected_title ?>&edit_q=<?= $q['id'] ?>" class="btn btn-outline-primary">
                                    <i class="bi bi-pencil"></i> Edit
                                </a>
                                <button class="btn btn-outline-danger" onclick="deleteQuestion(<?= $q['id'] ?>)">
                                    <i class="bi bi-trash"></i> Hapus
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

<!-- Modal Add/Edit Theme -->
<div class="modal fade" id="themeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="?page=bank_soal">
                <div class="modal-header">
                    <h5 class="modal-title" id="themeModalTitle">Tambah Tema</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="act" id="theme_act" value="add_theme">
                    <input type="hidden" name="theme_id" id="theme_id">
                    <div class="mb-3">
                        <label class="form-label">Nama Tema</label>
                        <input type="text" name="name" id="theme_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Deskripsi</label>
                        <textarea name="description" id="theme_description" class="form-control" rows="3"></textarea>
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

<!-- Modal Add/Edit Subtema -->
<div class="modal fade" id="subthemeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="?page=bank_soal">
                <div class="modal-header">
                    <h5 class="modal-title" id="subthemeModalTitle">Tambah Subtema</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="act" id="subtheme_act" value="add_subtheme">
                    <input type="hidden" name="theme_id" id="subtheme_theme_id">
                    <input type="hidden" name="subtheme_id" id="subtheme_id">
                    <div class="mb-3">
                        <label class="form-label">Nama Subtema</label>
                        <input type="text" name="name" id="subtheme_name" class="form-control" required>
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

<!-- Modal Add/Edit Title -->
<div class="modal fade" id="titleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="?page=bank_soal">
                <div class="modal-header">
                    <h5 class="modal-title" id="titleModalTitle">Tambah Judul Soal</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="act" id="title_act" value="add_title">
                    <input type="hidden" name="subtheme_id" id="title_subtheme_id">
                    <input type="hidden" name="title_id" id="title_id">
                    <input type="hidden" name="theme_id" id="title_theme_id">
                    <div class="mb-3">
                        <label class="form-label">Judul Soal</label>
                        <input type="text" name="title" id="title_name" class="form-control" required>
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

<!-- Modal Add/Edit Question -->
<div class="modal fade" id="questionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="?page=bank_soal">
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

function showAddThemeModal() {
    document.getElementById('theme_act').value = 'add_theme';
    document.getElementById('themeModalTitle').textContent = 'Tambah Tema';
    document.getElementById('theme_name').value = '';
    document.getElementById('theme_description').value = '';
    new bootstrap.Modal(document.getElementById('themeModal')).show();
}

function editTheme(id, name) {
    document.getElementById('theme_act').value = 'edit_theme';
    document.getElementById('theme_id').value = id;
    document.getElementById('theme_name').value = name;
    document.getElementById('themeModalTitle').textContent = 'Edit Tema';
    new bootstrap.Modal(document.getElementById('themeModal')).show();
}

function deleteTheme(id) {
    if (confirm('Yakin hapus tema ini? Semua subtema dan soal akan ikut terhapus!')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '?page=bank_soal';
        form.innerHTML = `
            <input type="hidden" name="act" value="delete_theme">
            <input type="hidden" name="theme_id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function showAddSubthemeModal(themeId) {
    document.getElementById('subtheme_act').value = 'add_subtheme';
    document.getElementById('subtheme_theme_id').value = themeId;
    document.getElementById('subtheme_name').value = '';
    document.getElementById('subthemeModalTitle').textContent = 'Tambah Subtema';
    new bootstrap.Modal(document.getElementById('subthemeModal')).show();
}

function editSubtheme(id, name) {
    document.getElementById('subtheme_act').value = 'edit_subtheme';
    document.getElementById('subtheme_id').value = id;
    document.getElementById('subtheme_name').value = name;
    document.getElementById('subthemeModalTitle').textContent = 'Edit Subtema';
    new bootstrap.Modal(document.getElementById('subthemeModal')).show();
}

function deleteSubtheme(id) {
    if (confirm('Yakin hapus subtema ini? Semua judul dan soal akan ikut terhapus!')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '?page=bank_soal';
        form.innerHTML = `
            <input type="hidden" name="act" value="delete_subtheme">
            <input type="hidden" name="subtheme_id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function showAddTitleModal(subthemeId) {
    document.getElementById('title_act').value = 'add_title';
    document.getElementById('titleModalTitle').textContent = 'Tambah Judul Soal';
    document.getElementById('title_subtheme_id').value = subthemeId;
    document.getElementById('title_name').value = '';
    document.getElementById('title_id').value = '';
    const themeId = new URLSearchParams(window.location.search).get('theme_id');
    document.getElementById('title_theme_id').value = themeId;
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
        form.action = '?page=bank_soal';
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
        form.action = '?page=bank_soal';
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
