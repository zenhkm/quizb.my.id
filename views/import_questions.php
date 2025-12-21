<?php
// views/import_questions.php
// Halaman Import Soal untuk Admin dan Pengajar

if (!is_pengajar() && !is_admin()) {
    echo '<div class="alert alert-danger">Akses admin/pengajar diperlukan.</div>';
    return;
}

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';
$imported_count = $_GET['count'] ?? 0;

?>

<div class="row justify-content-center">
    <div class="col-lg-10">
        
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1">
                    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" fill="currentColor" class="bi bi-file-earmark-arrow-up me-2" viewBox="0 0 16 16">
                        <path d="M8.5 11.5a.5.5 0 0 1-1 0V7.707L6.354 8.854a.5.5 0 1 1-.708-.708l2-2a.5.5 0 0 1 .708 0l2 2a.5.5 0 0 1-.708.708L8.5 7.707V11.5z"/>
                        <path d="M14 14V4.5L9.5 0H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2zM9.5 3A1.5 1.5 0 0 0 11 4.5h2V14a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1h5.5v2z"/>
                    </svg>
                    Import Soal
                </h2>
                <p class="text-muted mb-0">Impor soal secara massal menggunakan file Excel</p>
            </div>
        </div>

        <!-- Alert Success -->
        <?php if ($success === '1' && $imported_count > 0): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <strong>Berhasil!</strong> <?= (int)$imported_count ?> soal telah berhasil diimpor.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Alert Error -->
        <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong>Error!</strong> <?= h($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="row">
            <!-- Card Download Template -->
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-body">
                        <h5 class="card-title">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-download me-2" viewBox="0 0 16 16">
                                <path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/>
                                <path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/>
                            </svg>
                            Langkah 1: Download Template
                        </h5>
                        <p class="card-text text-muted">Download template Excel untuk memudahkan proses import. Template sudah dilengkapi dengan format yang benar dan contoh data.</p>
                        
                        <div class="d-grid gap-2">
                            <a href="?page=import_questions&action=download_template" class="btn btn-primary">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-file-earmark-excel me-2" viewBox="0 0 16 16">
                                    <path d="M5.884 6.68a.5.5 0 1 0-.768.64L7.349 10l-2.233 2.68a.5.5 0 0 0 .768.64L8 10.781l2.116 2.54a.5.5 0 0 0 .768-.641L8.651 10l2.233-2.68a.5.5 0 0 0-.768-.64L8 9.219l-2.116-2.54z"/>
                                    <path d="M14 14V4.5L9.5 0H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2zM9.5 3A1.5 1.5 0 0 0 11 4.5h2V14a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1h5.5v2z"/>
                                </svg>
                                Download Template Excel
                            </a>
                        </div>

                        <div class="mt-3">
                            <small class="text-muted">
                                <strong>Format Template:</strong><br>
                                • Kolom A: Pertanyaan<br>
                                • Kolom B-F: Pilihan Jawaban (A-E)<br>
                                • Kolom G: Jawaban Benar (A/B/C/D/E)<br>
                                • Kolom H: Penjelasan (opsional)
                            </small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card Upload File -->
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-body">
                        <h5 class="card-title">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-upload me-2" viewBox="0 0 16 16">
                                <path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/>
                                <path d="M7.646 1.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1-.708.708L8.5 2.707V11.5a.5.5 0 0 1-1 0V2.707L5.354 4.854a.5.5 0 1 1-.708-.708l3-3z"/>
                            </svg>
                            Langkah 2: Upload File Excel
                        </h5>
                        <p class="card-text text-muted">Pilih judul soal tujuan, lalu upload file Excel yang sudah diisi.</p>

                        <form action="?page=import_questions" method="POST" enctype="multipart/form-data" id="importForm">
                            <input type="hidden" name="act" value="import_excel">
                            
                            <div class="mb-3">
                                <label class="form-label">Pilih Judul Soal <span class="text-danger">*</span></label>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="title_option" id="existing_title" value="existing" checked onchange="toggleTitleFields()">
                                    <label class="form-check-label" for="existing_title">
                                        Gunakan Judul Yang Sudah Ada
                                    </label>
                                </div>
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="radio" name="title_option" id="new_title" value="new" onchange="toggleTitleFields()">
                                    <label class="form-check-label" for="new_title">
                                        Buat Judul Baru
                                    </label>
                                </div>
                            </div>

                            <!-- Existing Title Selection -->
                            <div id="existing_title_section">
                                <div class="mb-3">
                                    <label for="title_id" class="form-label">Pilih Judul Soal <span class="text-danger">*</span></label>
                                    <select name="title_id" id="title_id" class="form-select">
                                        <option value="">-- Pilih Judul Soal --</option>
                                        <?php
                                        // Tampilkan judul soal berdasarkan role
                                        if (is_admin()) {
                                            // Admin bisa import ke semua judul
                                            $titles = q("
                                                SELECT qt.id, qt.title, st.name AS subtheme, t.name AS theme
                                                FROM quiz_titles qt
                                                JOIN subthemes st ON st.id = qt.subtheme_id
                                                JOIN themes t ON t.id = st.theme_id
                                                ORDER BY t.name, st.name, qt.title
                                            ")->fetchAll();
                                        } else {
                                            // Pengajar hanya bisa import ke judul miliknya
                                            $titles = q("
                                                SELECT qt.id, qt.title, st.name AS subtheme, t.name AS theme
                                                FROM quiz_titles qt
                                                JOIN subthemes st ON st.id = qt.subtheme_id
                                                JOIN themes t ON t.id = st.theme_id
                                                WHERE qt.owner_user_id = ?
                                                ORDER BY t.name, st.name, qt.title
                                            ", [uid()])->fetchAll();
                                        }
                                        
                                        foreach ($titles as $title) {
                                            echo '<option value="' . $title['id'] . '">';
                                            echo h($title['theme'] . ' › ' . $title['subtheme'] . ' › ' . $title['title']);
                                            echo '</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>

                            <!-- New Title Creation -->
                            <div id="new_title_section" style="display: none;">
                                <div class="mb-3">
                                    <label for="theme_id" class="form-label">Tema <span class="text-danger">*</span></label>
                                    <select name="theme_id" id="theme_id" class="form-select" onchange="loadSubthemes()">
                                        <option value="">-- Pilih Tema --</option>
                                        <?php
                                        $themes = q("SELECT id, name FROM themes ORDER BY name")->fetchAll();
                                        foreach ($themes as $theme) {
                                            echo '<option value="' . $theme['id'] . '">' . h($theme['name']) . '</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="subtheme_id" class="form-label">Subtema <span class="text-danger">*</span></label>
                                    <select name="subtheme_id" id="subtheme_id" class="form-select">
                                        <option value="">-- Pilih Tema Dulu --</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="new_title_name" class="form-label">Nama Judul Soal Baru <span class="text-danger">*</span></label>
                                    <input type="text" name="new_title_name" id="new_title_name" class="form-control" placeholder="Contoh: Kuis Matematika Kelas 7">
                                    <small class="form-text text-muted">Nama judul soal yang akan dibuat</small>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="excel_file" class="form-label">File Excel <span class="text-danger">*</span></label>
                                <input type="file" name="excel_file" id="excel_file" class="form-control" accept=".xlsx,.xls" required>
                                <small class="form-text text-muted">Format: .xlsx atau .xls (Maksimal 5MB)</small>
                            </div>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-success" id="btnSubmit">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-cloud-upload me-2" viewBox="0 0 16 16">
                                        <path fill-rule="evenodd" d="M4.406 1.342A5.53 5.53 0 0 1 8 0c2.69 0 4.923 2 5.166 4.579C14.758 4.804 16 6.137 16 7.773 16 9.569 14.502 11 12.687 11H10a.5.5 0 0 1 0-1h2.688C13.979 10 15 8.988 15 7.773c0-1.216-1.02-2.228-2.313-2.228h-.5v-.5C12.188 2.825 10.328 1 8 1a4.53 4.53 0 0 0-2.941 1.1c-.757.652-1.153 1.438-1.153 2.055v.448l-.445.049C2.064 4.805 1 5.952 1 7.318 1 8.785 2.23 10 3.781 10H6a.5.5 0 0 1 0 1H3.781C1.708 11 0 9.366 0 7.318c0-1.763 1.266-3.223 2.942-3.593.143-.863.698-1.723 1.464-2.383z"/>
                                        <path fill-rule="evenodd" d="M7.646 4.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1-.708.708L8.5 5.707V11.5a.5.5 0 0 1-1 0V5.707L5.354 7.854a.5.5 0 1 1-.708-.708l3-3z"/>
                                    </svg>
                                    Upload dan Import
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Panduan Import -->
        <div class="card mt-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-info-circle me-2" viewBox="0 0 16 16">
                        <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
                        <path d="m8.93 6.588-2.29.287-.082.38.45.083c.294.07.352.176.288.469l-.738 3.468c-.194.897.105 1.319.808 1.319.545 0 1.178-.252 1.465-.598l.088-.416c-.2.176-.492.246-.686.246-.275 0-.375-.193-.304-.533L8.93 6.588zM9 4.5a1 1 0 1 1-2 0 1 1 0 0 1 2 0z"/>
                    </svg>
                    Panduan Import Soal
                </h5>
            </div>
            <div class="card-body">
                <h6>Langkah-langkah Import:</h6>
                <ol>
                    <li><strong>Download Template Excel</strong> - Klik tombol download untuk mendapatkan template dengan format yang benar.</li>
                    <li><strong>Isi Data Soal</strong> - Buka file template dengan Microsoft Excel, LibreOffice Calc, atau Google Sheets.</li>
                    <li><strong>Perhatikan Format</strong> - Pastikan setiap soal memiliki minimal 2 pilihan dan maksimal 5 pilihan.</li>
                    <li><strong>Pilih Jawaban Benar</strong> - Di kolom "Jawaban Benar", isi dengan huruf A, B, C, D, atau E sesuai pilihan yang benar.</li>
                    <li><strong>Tambahkan Penjelasan</strong> - Kolom penjelasan bersifat opsional, namun sangat disarankan untuk pembelajaran.</li>
                    <li><strong>Simpan File</strong> - Simpan file Excel Anda (format .xlsx atau .xls).</li>
                    <li><strong>Pilih Judul Soal</strong> - Pilih judul soal yang sudah ada, atau buat judul baru langsung dari form import.</li>
                    <li><strong>Upload File</strong> - Klik tombol "Upload dan Import" untuk memulai proses import.</li>
                </ol>

                <h6 class="mt-3">Tips & Catatan:</h6>
                <ul>
                    <li><strong>Buat Judul Baru:</strong> Anda bisa membuat judul soal baru langsung saat import tanpa perlu ke halaman CRUD terlebih dahulu.</li>
                    <li>Pastikan file Excel tidak rusak dan bisa dibuka dengan baik.</li>
                    <li>Jangan mengubah struktur header (baris pertama) pada template.</li>
                    <li>Baris kedua pada template berisi contoh data yang bisa Anda hapus.</li>
                    <li>Setiap soal harus memiliki minimal 2 pilihan jawaban dan maksimal 5 pilihan.</li>
                    <li>Jika pilihan C, D, atau E tidak digunakan, kosongkan saja kolom tersebut.</li>
                    <li>Jawaban benar harus berupa huruf kapital (A, B, C, D, atau E).</li>
                    <li>Kolom penjelasan boleh dikosongkan jika tidak diperlukan.</li>
                    <li>Maksimal ukuran file adalah 5MB.</li>
                </ul>

                <div class="alert alert-warning mb-0">
                    <strong>Perhatian!</strong> Proses import akan menambahkan soal baru ke dalam database. Pastikan data yang diimport sudah benar sebelum melakukan upload.
                </div>
            </div>
        </div>

    </div>
</div>

<script>
// Toggle between existing and new title
function toggleTitleFields() {
    const isNew = document.getElementById('new_title').checked;
    document.getElementById('existing_title_section').style.display = isNew ? 'none' : 'block';
    document.getElementById('new_title_section').style.display = isNew ? 'block' : 'none';
    
    // Update required attributes
    if (isNew) {
        document.getElementById('title_id').removeAttribute('required');
        document.getElementById('theme_id').setAttribute('required', 'required');
        document.getElementById('subtheme_id').setAttribute('required', 'required');
        document.getElementById('new_title_name').setAttribute('required', 'required');
    } else {
        document.getElementById('title_id').setAttribute('required', 'required');
        document.getElementById('theme_id').removeAttribute('required');
        document.getElementById('subtheme_id').removeAttribute('required');
        document.getElementById('new_title_name').removeAttribute('required');
    }
}

// Load subthemes berdasarkan theme
function loadSubthemes() {
    const themeId = document.getElementById('theme_id').value;
    const subthemeSelect = document.getElementById('subtheme_id');
    
    if (!themeId) {
        subthemeSelect.innerHTML = '<option value="">-- Pilih Tema Dulu --</option>';
        return;
    }
    
    // Fetch subthemes via AJAX
    fetch('?page=import_questions&action=get_subthemes&theme_id=' + themeId)
        .then(response => response.json())
        .then(data => {
            subthemeSelect.innerHTML = '<option value="">-- Pilih Subtema --</option>';
            data.forEach(sub => {
                const option = document.createElement('option');
                option.value = sub.id;
                option.textContent = sub.name;
                subthemeSelect.appendChild(option);
            });
        })
        .catch(error => {
            console.error('Error loading subthemes:', error);
            subthemeSelect.innerHTML = '<option value="">-- Error loading subtemas --</option>';
        });
}

// Loading state saat submit form
document.getElementById('importForm').addEventListener('submit', function(e) {
    const isNew = document.getElementById('new_title').checked;
    
    // Validasi tambahan
    if (isNew) {
        const themeName = document.getElementById('theme_id').selectedOptions[0]?.text;
        const subthemeName = document.getElementById('subtheme_id').selectedOptions[0]?.text;
        const titleName = document.getElementById('new_title_name').value;
        
        if (!titleName || titleName.trim() === '') {
            alert('Nama judul soal tidak boleh kosong!');
            e.preventDefault();
            return false;
        }
    }
    
    const btn = document.getElementById('btnSubmit');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Mengimpor...';
});

// Validasi ukuran file
document.getElementById('excel_file').addEventListener('change', function() {
    const file = this.files[0];
    if (file && file.size > 5 * 1024 * 1024) { // 5MB
        alert('Ukuran file terlalu besar! Maksimal 5MB.');
        this.value = '';
    }
});
</script>
