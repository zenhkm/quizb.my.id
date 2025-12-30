# Import legacy `tb_soal.sql` into QuizB

This backup file (see `backup/tb_soal.sql`) contains a single legacy table `tb_soal`:
- `kategori` = **Tema** (category)
- `mapel` = **Subtema**
- `jenis` = **Judul soal**
- `soal` = **Pertanyaan**
- `a`, `b` = pilihan jawaban
- `rule` is mostly empty in this dump, so correct answers usually cannot be inferred.

## Step 1 — Import `tb_soal.sql` into your database

### Option A: phpMyAdmin (hosting)
1. Open phpMyAdmin
2. Select your QuizB database
3. Tab **Import** → choose file `backup/tb_soal.sql` → **Go**

### Option B: MySQL CLI (Windows)
From repository root:

```bat
cmd /c "mysql -h YOUR_HOST -u YOUR_USER -p YOUR_DB < backup\\tb_soal.sql"
```

If your MySQL rejects big imports, try:

```bat
cmd /c "mysql -h YOUR_HOST -u YOUR_USER -p --max_allowed_packet=512M YOUR_DB < backup\\tb_soal.sql"
```

## Step 2 — Migrate legacy rows into QuizB tables

This step reads `tb_soal` and inserts into:
- `themes`, `subthemes`, `quiz_titles`, `questions`, `choices`

### Import only Bahasa → Theme name `Pengetahuan Bahasa`

```bat
php tools\\import_tb_soal_to_quizb.php --kategori=bahasa --theme-name="Pengetahuan Bahasa"
```

### Import all categories

```bat
php tools\\import_tb_soal_to_quizb.php --all
```

### Optional flags
- `--owner-user-id=123` : set ownership on inserted content (teacher/admin id)
- `--correct-default=A|B|none` : when `rule` can't be inferred (default: `none`)
- `--limit=500` : test run on first N rows
- `--dry-run` : scan only, no writes

## Notes / Caveats
- This dump uses `latin1` for the legacy table; QuizB uses `utf8mb4`. Some older text may look “weird” if it was already double-encoded before export.
- Because `rule` is mostly empty, many imported questions will have no correct answer set (or will be defaulted if you use `--correct-default`). You can fix correct answers later via QManage.
