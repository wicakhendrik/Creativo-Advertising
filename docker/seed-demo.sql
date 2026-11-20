-- ============================================================
-- AKUN DEMO (khusus LOKAL / testing). JANGAN dipakai di production.
--
--   demoadmin  (admin)     password: BFI0SOmqOfuy
--   democust   (customer)  password: BFI0SOmqOfuy
--
-- Kenapa ada file ini: akun contoh di dump (admin, pelanggan1..5) berisi data fiktif
-- dengan password acak, jadi tidak ada akun yang bisa dipakai login.
--
-- Cara pakai (tidak dijalankan otomatis, jalankan SEKALI saja):
--   docker compose exec -T db sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' < docker/seed-demo.sql
-- Hapus lagi:
--   DELETE FROM users WHERE username IN ('demoadmin','democust');
-- ============================================================
INSERT IGNORE INTO users (id_user, username, email, password, jenisKelamin, noTelp, usertype) VALUES
(NULL, 'demoadmin', 'demoadmin@demo.id', '$2y$10$Bh/PQooO5qj7m01jhfY6heLOpE4Floop5OHYExghRAWbhb54jzFg6', 1, '081200000000', 1),
(NULL, 'democust', 'democust@demo.id', '$2y$10$sGGZLISBrpbk1FA5oslzyuC1yxxg47EYfX8GGhsVFqi1H.QFuebFK', 1, '081200000000', 2);
