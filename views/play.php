<?php
// views/play.php

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
$assignment_settings = $_SESSION['quiz']['assignment_settings'] ?? null;

// Terapkan timer dari tugas jika ada, jika tidak, gunakan pengaturan personal pengguna
$timerSecs = $assignment_settings['timer_per_soal'] ?? user_timer_seconds();
$examTimerMins = $assignment_settings['durasi_ujian'] ?? user_exam_timer_minutes();
$mode_for_js = h($mode);
$user_id_js = uid();

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
        examTimerInterval: null,
        userId: {$user_id_js}
    };

    // Best-effort fullscreen + lock controls for exam mode
    const examLock = {
        initialized: false,
        violations: 0,
        maxViolations: 3,
        async enterFullscreen() {
            try {
                if (document.fullscreenElement) return;
                const elem = document.documentElement;
                if (elem.requestFullscreen) {
                    await elem.requestFullscreen({ navigationUI: 'hide' });
                }
            } catch (e) {
                console.warn('Fullscreen request failed:', e);
            }
        },
        async lockOrientation() {
            try {
                if (screen.orientation && screen.orientation.lock) {
                    await screen.orientation.lock('landscape');
                }
            } catch (e) {
                // Orientation lock can fail on unsupported devices/browsers
            }
        },
        blockShortcuts(e) {
            const key = e.key.toLowerCase();
            const ctrl = e.ctrlKey || e.metaKey; // meta for mac keyboards
            const alt = e.altKey;

            // Prevent common navigation/escape shortcuts (best-effort)
            const blockedCombos = (
                (key === 'f11') ||
                (key === 'escape') ||
                (ctrl && ['w','p','s','l','r','o','a','c','x','v','tab'].includes(key)) ||
                (alt && ['tab','f4'].includes(key))
            );
            if (blockedCombos) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
        },
        preventContextMenu(e) {
            e.preventDefault();
        },
        handleVisibility() {
            if (document.hidden) {
                examLock.violations++;
                alert('Jangan keluar dari tampilan ujian. Pelanggaran: ' + examLock.violations);
                if (examLock.violations >= examLock.maxViolations) {
                    alert('Terlalu banyak pelanggaran. Ujian akan diakhiri.');
                    finishQuiz();
                }
            }
        },
        async handleFullscreenChange() {
            if (!document.fullscreenElement) {
                examLock.violations++;
                alert('Mode layar penuh keluar. Pelanggaran: ' + examLock.violations);
                // Coba masuk kembali ke fullscreen
                await examLock.enterFullscreen();
                if (examLock.violations >= examLock.maxViolations) {
                    alert('Terlalu banyak pelanggaran. Ujian akan diakhiri.');
                    finishQuiz();
                }
            }
        },
        init() {
            if (examLock.initialized) return;
            examLock.initialized = true;
            // Enter fullscreen and attempt orientation lock
            examLock.enterFullscreen();
            examLock.lockOrientation();
            // Event listeners
            window.addEventListener('keydown', examLock.blockShortcuts, true);
            window.addEventListener('contextmenu', examLock.preventContextMenu, true);
            document.addEventListener('visibilitychange', examLock.handleVisibility, true);
            document.addEventListener('fullscreenchange', examLock.handleFullscreenChange, true);
            // Fallback: try fullscreen on first user click (required by some browsers)
            const clickOnce = () => {
                examLock.enterFullscreen();
                document.removeEventListener('click', clickOnce, true);
            };
            document.addEventListener('click', clickOnce, true);
        },
        teardown() {
            window.removeEventListener('keydown', examLock.blockShortcuts, true);
            window.removeEventListener('contextmenu', examLock.preventContextMenu, true);
            document.removeEventListener('visibilitychange', examLock.handleVisibility, true);
            document.removeEventListener('fullscreenchange', examLock.handleFullscreenChange, true);
            if (document.exitFullscreen) {
                document.exitFullscreen().catch(() => {});
            }
        }
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
                // Initialize exam lock safeguards on first load
                examLock.init();
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
        
        // ▼▼▼ AUTO-SAVE JAWABAN KE DATABASE (REAL-TIME) ▼▼▼
        const answerData = {
            session_id: quizState.sessionId,
            user_id: quizState.userId,
            question_id: question.id,
            choice_id: parseInt(selectedButton.dataset.choiceId),
            is_correct: selectedButton.dataset.isCorrect === 'true' ? 1 : 0
        };
        
        // Kirim ke server (async, jangan perlu menunggu response)
        fetch('?action=api_save_draft_answer', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(answerData)
        }).catch(err => console.log('Auto-save failed:', err));
        // ▲▲▲ AKHIR AUTO-SAVE ▲▲▲
        
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
    


    async function finishQuiz() {
        clearInterval(quizState.examTimerInterval);
        clearInterval(quizState.timerInterval);
        // Remove lock listeners and exit fullscreen when finishing
        try { examLock.teardown(); } catch (_) {}
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
