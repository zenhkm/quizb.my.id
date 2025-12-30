# Export `kategori=bahasa` (tb_soal.sql) → Excel template import

Kamu minta cara yang paling gampang: semua soal yang berhubungan dengan Bahasa dijadikan file Excel sesuai template Import QuizB.

Script ini **tidak butuh import SQL ke database**. Dia langsung membaca file [backup/tb_soal.sql](backup/tb_soal.sql) dan menulis `.xlsx`.

## 1) Generate semua Excel (jawaban selalu A)

Dari root repo:

```bat
php tools\export_tb_soal_sql_to_excels.php --kategori=bahasa --correct=A
```

Hasilnya akan berupa folder baru seperti:

- `exports/tb_soal_excels_YYYYmmdd_HHMMSS/bahasa/*.xlsx`

Setiap file `.xlsx` = 1 Judul Soal (berdasarkan `mapel + jenis`).

## 2) Import ke QuizB lewat UI

Untuk setiap file `.xlsx`:
1. Buka menu Import Soal (`?page=import_questions`)
2. Pilih opsi **Judul Baru**
3. Pilih Tema/Subtema tujuan (kamu bisa buat Tema/Subtema dulu jika belum ada)
4. Upload file `.xlsx`

## Catatan penting
- Kolom yang ada di SQL hanya `a` dan `b`, jadi di Excel:
  - Pilihan C/D/E akan kosong.
- Karena kamu bilang **semua jawaban adalah A**, script mengisi kolom `Jawaban Benar` dengan `A` untuk semua baris.
