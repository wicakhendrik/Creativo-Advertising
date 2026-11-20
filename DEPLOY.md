# DEPLOY.md: Creativo Advertising

Panduan deploy untuk **manusia dan AI agent**. Semua perintah di sini sudah diuji dari nol di Docker (hapus volume, build, import DB, seed, login) pada 2026-11-20.

**Isi**
1. [Ringkasan arsitektur](#1-ringkasan-arsitektur)
2. [Prasyarat](#2-prasyarat)
3. [Deploy lokal](#3-deploy-lokal)
4. [Deploy ke VPS](#4-deploy-ke-vps-ubuntudebian)
5. [HTTPS dengan reverse proxy](#5-https-dengan-reverse-proxy-caddy)
6. [Checklist verifikasi](#6-checklist-verifikasi)
7. [Operasi harian](#7-operasi-harian)
8. [Troubleshooting](#8-troubleshooting)
9. [Catatan keamanan](#9-catatan-keamanan)
10. [Perubahan dari kode asli](#10-perubahan-dari-kode-asli)
11. [Runbook untuk AI agent](#11-runbook-untuk-ai-agent-di-vps)

---

## 1. Ringkasan arsitektur

```
Internet ──► Caddy/Nginx (HTTPS, :443)
                │
                ▼  127.0.0.1:8080
        ┌───────────────┐        ┌───────────────┐
        │  web (Apache  │ ─────► │  db (MySQL 8) │
        │  + PHP 8.2)   │  :3306 │  volume       │
        └───────────────┘        │  db_data      │
          container: creativo-web└───────────────┘
                                   container: creativo-db
```

- Aplikasi: **PHP native** + `mysqli`. Tidak ada composer/npm yang perlu dijalankan (`vendor/` dan `assets/` sudah ikut di repo).
- Database ter-import **otomatis** dari `id9352934_creativo.sql` saat volume `db_data` masih kosong.
- Konfigurasi koneksi DB dibaca dari environment variable (`DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`) yang di-set `docker-compose.yml` dari file `.env`.
- Port MySQL **tidak dibuka ke host**; hanya container `web` yang bisa mengaksesnya.

| File | Fungsi |
|---|---|
| `Dockerfile` | Image `php:8.2-apache` + ekstensi `mysqli`, kode disalin ke `/var/www/html` |
| `docker-compose.yml` | Service `web` + `db`, healthcheck, volume |
| `.env.example` | Template konfigurasi (salin jadi `.env`) |
| `id9352934_creativo.sql` | Skema + data awal (di-import otomatis) |
| `docker/seed-demo.sql` | Akun demo, opsional, **lokal saja** |

## 2. Prasyarat

| Lingkungan | Butuh |
|---|---|
| Lokal (Windows/Mac) | Docker Desktop **sudah running** (kalau tidak: `failed to connect to the docker API`) |
| VPS | Linux (Ubuntu 22.04/24.04 atau Debian 12), Docker Engine + plugin Compose v2 (`docker compose version`), port 80/443 terbuka, domain yang A-record-nya mengarah ke IP VPS (untuk HTTPS) |

## 3. Deploy lokal

```bash
# dari folder project
docker compose up -d --build --wait
```

- `--wait` menunggu sampai kedua container **healthy** (pertama kali ±30-60 detik karena import DB).
- Buka **http://localhost:8080**.
- Tanpa file `.env`, dipakai default dev: user `creativo` / password `creativo_pass` (cukup untuk lokal).

Buat akun untuk login (sekali saja; kredensial ada di header `docker/seed-demo.sql`):

```bash
docker compose exec -T db sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' < docker/seed-demo.sql
```

Atau daftar akun customer lewat http://localhost:8080/daftar.php. Akun contoh bawaan dump (`admin`, `pelanggan1` sampai `pelanggan5`) **tidak bisa dipakai login**: datanya fiktif (dianonimkan) dan password-nya acak.

Berhenti: `docker compose down` (data aman). Reset total: `docker compose down -v` (**menghapus database**).

## 4. Deploy ke VPS (Ubuntu/Debian)

### 4.1 Install Docker (sekali saja)

```bash
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker "$USER"     # logout/login ulang agar berlaku
docker compose version              # harus tercetak versi v2.x
```

### 4.2 Taruh kode di VPS

Pilih salah satu:

```bash
# (a) dari git, kalau project sudah di-push ke repo
git clone <URL_REPO> /opt/creativo && cd /opt/creativo

# (b) dari laptop (PowerShell/Git Bash), rsync/scp folder project
rsync -av --exclude '.env' --exclude 'screenshots' ./ user@IP_VPS:/opt/creativo/
```

### 4.3 Buat `.env` dengan password kuat

```bash
cd /opt/creativo
cp .env.example .env
# isi password acak (jangan pakai nilai contoh)
sed -i "s|^DB_PASS=.*|DB_PASS=$(openssl rand -base64 24 | tr -d '/+=')|"           .env
sed -i "s|^DB_ROOT_PASS=.*|DB_ROOT_PASS=$(openssl rand -base64 24 | tr -d '/+=')|" .env
# web hanya didengarkan di loopback, diakses lewat reverse proxy
sed -i "s|^WEB_BIND=.*|WEB_BIND=127.0.0.1:8080|" .env
chmod 600 .env
cat .env     # cek hasilnya; simpan password di password manager
```

> **Kenapa `127.0.0.1:8080`?** Port yang di-publish Docker **menembus UFW/iptables**. Kalau `WEB_BIND=8080`, aplikasi terbuka ke internet tanpa HTTPS lewat `http://IP:8080`.

### 4.4 Jalankan

```bash
docker compose up -d --build --wait
docker compose ps          # creativo-web & creativo-db harus (healthy)
curl -I http://127.0.0.1:8080/login.php    # HTTP/1.1 200 OK
```

> Import database hanya terjadi **saat volume `db_data` kosong**. Mengubah `DB_PASS` di `.env` setelah first-run **tidak** mengubah password di MySQL. Lihat [troubleshooting](#8-troubleshooting).

### 4.5 Buat akun admin (jangan pakai `seed-demo.sql` di produksi)

1. Daftar lewat `https://DOMAIN/daftar.php` dengan akun yang diinginkan (email maksimal 20 karakter, username maksimal 20).
2. Naikkan ke admin:

```bash
docker compose exec -T db sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e "UPDATE users SET usertype=1 WHERE username='\''NAMA_USER'\''"'
```

3. **Hapus akun contoh bawaan dump** (`admin`, `pelanggan1` sampai `pelanggan5`; data fiktif dengan password acak) dan pesanan contohnya bila tidak diperlukan:

```bash
docker compose exec -T db sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e "SELECT id_user,username,usertype FROM users"'
```

Reset password suatu user (hash dibuat dengan PHP di container `web`):

```bash
HASH=$(docker compose exec -T web php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- 'PasswordBaruAnda')
printf "UPDATE users SET password='%s' WHERE username='%s';\n" "$HASH" "NAMA_USER" \
  | docker compose exec -T db sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'
```

> SQL sengaja dikirim lewat **stdin** (`printf | ...`). Jangan menaruh `$HASH` di dalam `sh -c "..."`: hash bcrypt berisi `$2y$10$...` yang akan di-expand ulang oleh shell dan merusak hash (user jadi tidak bisa login).

## 5. HTTPS dengan reverse proxy (Caddy)

Caddy mengurus sertifikat Let's Encrypt otomatis. Pastikan A-record domain sudah mengarah ke IP VPS.

```bash
sudo apt-get install -y debian-keyring debian-archive-keyring apt-transport-https curl
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list
sudo apt-get update && sudo apt-get install -y caddy
```

`/etc/caddy/Caddyfile`:

```caddyfile
creativo.example.com {
    encode gzip
    reverse_proxy 127.0.0.1:8080
}
```

```bash
sudo systemctl reload caddy
sudo ufw allow 22/tcp && sudo ufw allow 80/tcp && sudo ufw allow 443/tcp && sudo ufw enable
curl -I https://creativo.example.com/login.php
```

**Disarankan sebelum dibuka ke publik** (lihat [bagian 9](#9-catatan-keamanan); CSRF dan batas percobaan login belum ada di kode). Lapisan kedua: kunci `/admin/*` dengan basic auth di Caddy:

```bash
caddy hash-password        # ketik password, salin hash-nya
```

```caddyfile
# Caddy >= 2.8 memakai "basic_auth" (versi lama: "basicauth")
creativo.example.com {
    encode gzip
    @admin path /admin/*
    basic_auth @admin {
        adminproxy  <HASH_DARI_PERINTAH_DI_ATAS>
    }
    reverse_proxy 127.0.0.1:8080
}
```

<details>
<summary>Alternatif: Nginx</summary>

```nginx
server {
    server_name creativo.example.com;
    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
# lalu: sudo certbot --nginx -d creativo.example.com
```
</details>

## 6. Checklist verifikasi

Jalankan semuanya; semua harus sesuai harapan.

```bash
docker compose ps                                         # 2 container, STATUS (healthy)
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8080/            # 200
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8080/login.php   # 200
docker compose exec -T db sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -N -e "SHOW TABLES" | wc -l'   # 8
docker compose exec -T db sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -N -e "SELECT COUNT(*) FROM jasa"'  # 5 (data awal)
docker compose logs --tail=30 web | grep -i -E 'error|fatal' || echo "no errors"
```

File sensitif **tidak boleh** bisa diunduh lewat web (semua harus `404`):

```bash
for u in id9352934_creativo.sql docker/seed-demo.sql .env .env.example docker-compose.yml; do
  echo "$u -> $(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/$u)"
done
```

Dari luar VPS: `curl -I http://IP_VPS:8080` **harus gagal/timeout** (kalau tidak, `WEB_BIND` belum `127.0.0.1:8080`).

Uji guard admin (harus `302` untuk anonim). Id yang tidak ada dipakai agar tidak mengubah data:

```bash
for e in admin/index.php admin/datauser.php "admin/hapususer.php?id=999999" "admin/pesananditerima.php?id=0"; do
  echo "$e -> $(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:8080/$e")"
done
```

Uji CSRF dan header (tanpa login, aman dijalankan):

```bash
# POST tanpa token CSRF harus 403
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8080/login.php -d 'username=x&password=x&login='   # 403
# endpoint destruktif: anonim di-redirect oleh guard (admin yang login mendapat 405 untuk GET)
curl -s -o /dev/null -w '%{http_code}\n' 'http://127.0.0.1:8080/admin/hapususer.php?id=999999'              # 302
# header keamanan
curl -sI http://127.0.0.1:8080/login.php | grep -i -E 'x-content-type|x-frame|referrer|set-cookie|^server'
#   -> nosniff, SAMEORIGIN, strict-origin-when-cross-origin, "HttpOnly; SameSite=Lax", "Server: Apache" (tanpa versi)
```

Uji injection (tanpa login, aman dijalankan): payload di form login harus **ditolak**, bukan masuk. Form login butuh token CSRF, jadi ambil dulu dari halamannya:

```bash
J=$(mktemp)
T=$(curl -s -c $J http://127.0.0.1:8080/login.php | grep -o 'name="csrf_token" value="[^"]*"' | sed 's/.*value="//; s/"$//')
curl -s -b $J -c $J http://127.0.0.1:8080/login.php --data-urlencode "csrf_token=$T" \
  --data-urlencode "username=x' OR '1'='1' -- " --data-urlencode "password=x" --data-urlencode "login=" \
  | grep -c "username / password salah"      # harus 1
rm -f $J
```

Uji fungsional manual: login, buka dashboard, buat pesanan di *Pemesanan*, lalu cek muncul di *Status Pemesanan*. Login sebagai customer lalu buka `/admin/index.php`: harus **403**.

## 7. Operasi harian

```bash
docker compose logs -f web            # log aplikasi/Apache
docker compose logs -f db             # log MySQL
docker compose restart web
docker compose exec db sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'   # shell MySQL
```

**Update kode** (setelah ada perubahan file PHP):

```bash
git pull                              # atau rsync ulang
docker compose up -d --build web      # rebuild hanya web; database tidak tersentuh
```

**Backup database** (jalankan rutin, mis. cron harian):

```bash
mkdir -p backups
docker compose exec -T db sh -c 'mysqldump -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" --no-tablespaces "$MYSQL_DATABASE"' > backups/creativo-$(date +%F).sql
```

**Restore**:

```bash
docker compose exec -T db sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' < backups/creativo-YYYY-MM-DD.sql
```

> Restore ke database yang sudah berisi tabel akan bentrok (`table already exists`). Untuk restore penuh gunakan database kosong (`docker compose down -v`, `up`, lalu restore). Itu **menghapus data saat ini**, jadi backup dulu.

**Reset ke data awal** (menghapus semua data!): `docker compose down -v && docker compose up -d --build --wait`

## 8. Troubleshooting

| Gejala | Penyebab & solusi |
|---|---|
| `failed to connect to the docker API ... dockerDesktopLinuxEngine` | Docker Desktop belum jalan (lokal). Buka aplikasinya, tunggu sampai siap, ulangi. |
| `port is already allocated` / bind error 8080 | Port bentrok. Ubah `WEB_BIND` di `.env` (mis. `8081`), lalu `docker compose up -d`. |
| Halaman: `Koneksi database gagal: ...` | DB belum siap atau kredensial salah. Cek `docker compose ps` dan `docker compose logs db`. Pastikan `.env` tidak berubah setelah first-run. |
| `Access denied for user 'creativo'` setelah ganti `DB_PASS` | Password MySQL ditetapkan **saat volume dibuat**. Ubah lewat SQL: `ALTER USER 'creativo'@'%' IDENTIFIED BY 'baru';` (login sebagai root: `docker compose exec db mysql -uroot -p"$DB_ROOT_PASS"`), atau reset total jika datanya boleh hilang. |
| Tabel kosong / dump tidak ter-import | Init script hanya jalan saat volume kosong. Kalau volume sudah ada tapi kosong/rusak: `docker compose down -v && docker compose up -d --build --wait`. |
| Daftar akun gagal, muncul `Data too long for column 'email'` | Kolom `users.email` hanya `varchar(20)`. Pakai email pendek, atau perlebar: `ALTER TABLE users MODIFY email varchar(100) NOT NULL;` |
| Login selalu "username / password salah" | Password akun bawaan dump tidak diketahui. Pakai `docker/seed-demo.sql` (lokal) atau buat akun baru ([4.5](#45-buat-akun-admin-jangan-pakai-seed-demosql-di-produksi)). |
| Peta kosong abu-abu | Perlu internet ke `tile.openstreetmap.org` dari browser pengunjung. |
| Font/ikon tidak muncul | Beberapa halaman memuat Google Fonts / cdnjs dari internet; cek koneksi browser. |
| `403 - Permintaan tidak valid` saat submit form | Token CSRF tidak cocok: session habis, cookie diblokir, atau form dikirim dari luar aplikasi. Muat ulang halaman lalu coba lagi. Jika terjadi terus, pastikan browser menerima cookie `PHPSESSID` dan reverse proxy meneruskan header `Cookie`. |
| `405 Method Not Allowed` di `/admin/hapus*.php` atau `/admin/pesanan*.php` | Disengaja: aksi itu POST-only. Pakai tombol di halaman admin (tidak bisa lewat URL/curl GET). |
| Query error tidak muncul sebagai exception | Disengaja: `mysqli_report(MYSQLI_REPORT_OFF)` di `functions.php` meniru perilaku PHP 7.x yang dipakai kode ini. |

## 9. Catatan keamanan

Aplikasi ini adalah tugas kuliah 2019. Celah yang bisa dieksploitasi langsung (akses admin tanpa login, SQL injection, eskalasi role, IDOR, manipulasi harga, CSRF, XSS) **sudah ditutup dan diuji**. Yang tersisa lebih ringan (brute force login, CSP, dll.) tapi tetap perlu dipertimbangkan sebelum dibuka ke internet publik.

**Sudah diperbaiki**

1. ✅ **Endpoint admin tanpa autentikasi** (`hapususer.php`, `hapusjasa.php`, `pesananditerima.php`, `pesananselesai.php`): sebelumnya siapa pun bisa memanggilnya lewat `GET ?id=`. Sekarang semua halaman `/admin/*` memakai guard `admin/auth.php`.
2. ✅ **Tidak ada pengecekan role**: sebelumnya akun customer bisa membuka dashboard admin. Sekarang guard mengecek `usertype = 1` ke database di setiap request. Hasil uji:

   | Pengunjung | `/admin/*` |
   |---|---|
   | Belum login | 302 → `login.php` |
   | Login sebagai customer | 403 |
   | Login sebagai admin | 200 |

   `admin/auth.php` yang dibuka langsung lewat URL menghasilkan 404.
3. ✅ **SQL injection.** Semua query yang memuat input (form, `?id=`, session) sekarang memakai *prepared statement* lewat helper `dbq()` / `query()` di `functions.php`. Audit otomatis: **0 query** yang menyisipkan variabel ke string SQL (56 query statis tanpa input, 51 query berparameter). Sebelumnya terbukti bisa: dump hash password semua user lewat `customer/detailstatus.php?id=`, dan **login tanpa user valid** lewat `login.php` (injeksi `UNION` dengan hash buatan sendiri, bahkan sebagai admin).
4. ✅ **Eskalasi role & ubah data user lain.** Form profil (`customer/profile.php`, `admin/profile.php`) dulu mengirim `id` dan `usertype` dari POST sehingga customer bisa menjadi admin atau mengubah user lain. Sekarang `ubahuser()` mode profil selalu memakai user dari **session** dan mengabaikan `id`/`usertype` di form. Hanya `admin/ubahuser.php` (setelah guard admin) yang boleh mengubah user lain dan role.
5. ✅ **IDOR pesanan.** `customer/detailstatus.php?id=` dulu bisa membuka detail pesanan siapa pun; sekarang hanya pesanan milik user yang login.
6. ✅ **Manipulasi harga/pemesan.** Total pesanan dulu dihitung di browser dan dipercaya server (`total=1` lolos, `user=` bisa diganti). Sekarang `pemesanan()` mengambil pemesan dari session, harga dari database, dan menghitung total di server, dalam satu transaksi.
7. ✅ **Session fixation**: `session_regenerate_id(true)` saat login. Username di session diambil dari database, bukan dari input.
8. ✅ Username dipastikan unik saat ubah profil (tabel tidak punya unique key; duplikat merusak login). Password dengan karakter `'` `"` `\` sekarang bisa dipakai (dulu di-escape lalu di-hash sehingga tidak bisa login).

9. ✅ **CSRF.** Setiap form `POST` memuat token acak per-session (`<?= csrf_field() ?>`), dan `functions.php` memverifikasinya **otomatis untuk semua request POST** sebelum halaman memproses data. Token hilang/salah/milik session lain → `403`. Empat aksi destruktif yang dulu berupa link `GET` (`hapusjasa`, `hapususer`, `pesananditerima`, `pesananselesai`) sekarang **POST-only** (`GET` → `405`), tombolnya berupa form kecil dengan token. Lapisan tambahan: cookie session `HttpOnly` + `SameSite=Lax` + `use_strict_mode` (di `Dockerfile`).
10. ✅ **XSS.** Semua data dari database/user yang dicetak ke HTML dibungkus `e()` (`htmlspecialchars` dengan `ENT_QUOTES`, UTF-8): 174 titik output. Data sekarang disimpan **apa adanya** di database dan di-escape saat **ditampilkan** (bukan saat disimpan), jadi tidak ada double-encoding. Sebelumnya, username seperti `<u>x</u>` atau `"><b>` ditampilkan mentah di navbar dan menembus atribut `value="..."`. Header tambahan: `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, dan versi Apache disembunyikan.

Hasil uji (lokal, akun demo): **82 tes lulus, 0 gagal**. Mencakup 5 skenario eksploit (SQL injection, login bypass, eskalasi role, edit user lain, IDOR; semuanya berhasil sebelum perbaikan dan kini **BLOCKED**), tes CSRF (POST tanpa token/token palsu/token session lain, GET ke endpoint destruktif), tes XSS (tag dan breakout atribut ter-escape, tidak double-encode), dan regresi fungsional (daftar, login, pesan, profil, CRUD admin). Semua halaman dicek `200` **dan** render penuh (`</html>`).

> **Aturan untuk kode baru:** setiap `<form method="post">` wajib memuat `<?= csrf_field() ?>`; setiap data yang dicetak wajib `<?= e($x) ?>`; setiap query yang memuat input wajib lewat `dbq()` / `query()` dengan `?`. Lihat contoh di `functions.php`.

**Belum diperbaiki (masih ada di kode)**

11. Tidak ada pembatasan percobaan login (brute force) dan tidak ada kebijakan password.
12. **Logout lewat `GET`** (`logout.php`): situs lain bisa memaksa user logout (gangguan, bukan kebocoran data).
13. Tidak ada **Content-Security-Policy** (halaman memakai banyak skrip inline dari template, jadi CSP ketat akan merusak tampilan) dan flag cookie `Secure` belum di-set karena TLS diterminasi di reverse proxy. Aktifkan **HSTS** di proxy setelah HTTPS stabil.
14. Halaman admin/customer memuat skrip **TrackJS** pihak ketiga dengan token dari template Argon.
15. Token Mapbox publik tahun 2019 sempat tertanam di source (sudah dihapus dari kode, lihat bagian 10). **Sebaiknya di-revoke** di akun Mapbox pemiliknya.
16. Password database lama (`password123`) ada di riwayat kode. Jangan pakai ulang.

**Mitigasi untuk VPS**
- Pertahankan `WEB_BIND=127.0.0.1:8080`; jangan buka port 3306 / 8080 ke publik.
- Ganti semua password di `.env`, hapus/ganti akun bawaan dump, jangan jalankan `seed-demo.sql`.
- Karena batas percobaan login belum ada, **basic auth untuk `/admin/*`** di reverse proxy (contoh di [bagian 5](#5-https-dengan-reverse-proxy-caddy)) masih disarankan sebagai lapisan kedua (sekaligus membatasi brute force ke panel admin). Tidak wajib lagi untuk menutup akses tanpa login, SQL injection, CSRF, atau XSS.
- Jadwalkan backup DB.
- Jika sekadar demo portofolio, pertimbangkan membatasi seluruh situs dengan basic auth atau Cloudflare Access.

**Perbaikan kode berikutnya yang disarankan:** batasi percobaan login (rate limit per IP/username), ubah logout jadi `POST`, tambah `UNIQUE` pada `users.username` dan perlebar kolom `users.email` (`varchar(20)` terlalu sempit), pertimbangkan CSP setelah skrip inline dipindah ke file.

## 10. Perubahan dari kode asli

Hanya perubahan minimum agar bisa jalan di lingkungan modern:

| File | Perubahan | Alasan |
|---|---|---|
| `functions.php` | Kredensial DB dibaca dari env (`DB_HOST/USER/PASS/NAME`); default sekarang netral (`creativo` / `creativo_pass`, sama dengan default `docker-compose.yml`), bukan lagi kredensial hosting lama. Tambah `mysqli_report(MYSQLI_REPORT_OFF)` dan pesan error bila koneksi gagal. | Tidak ada hardcode kredensial; PHP 8.1+ melempar exception untuk error `mysqli`, kode ini dibuat untuk PHP 7.x. |
| `functions.php` (fungsi CRUD) | Semua fungsi (`registrasi`, `tambahjasa`, `hapus*`, `pesanan*`, `ubahuser`, `ubahjasa`, `pemesanan`) ditulis ulang dengan prepared statement; helper baru `dbq()`, `query()` (berparameter), `current_user_id()`. `ubahuser($data, $asAdmin)` punya mode profil vs admin. `pemesanan()` menghitung total di server. | Menutup SQL injection, eskalasi role, dan manipulasi harga (bagian 9). |
| `login.php` | Query berparameter, `session_regenerate_id(true)`, username session dari DB | SQL injection / session fixation |
| `daftar.php` | Pesan error memakai `$db_error` (di-escape); `session_start()` di awal | Mengikuti helper baru; token CSRF disimpan di session |
| `customer/*.php`, `admin/*.php` | Query yang memuat `$session` / `$id` diganti `dbq()` / `query()` berparameter (33 query); `customer/detailstatus.php` hanya menampilkan pesanan milik sendiri; `admin/ubahuser.php` memanggil `ubahuser($_POST, true)` | SQL injection, IDOR |
| `index.php`, `customer/maps.php`, `admin/maps.php` | Tile peta Mapbox v4 → OpenStreetMap | API Mapbox v4 + token 2019 sudah mati (peta kosong). |
| `admin/*.php` (14 file) | Tambah satu baris `require __DIR__ . '/auth.php';` tepat setelah `require '../functions.php';` | Menutup akses tanpa login dan akses customer ke halaman admin (bagian 9). |
| `functions.php` (keamanan) | Helper `e()` (escape output), `csrf_token()` / `csrf_field()` / `csrf_verify()`; verifikasi CSRF otomatis untuk semua POST; tidak lagi `htmlspecialchars` saat **menyimpan** (di-escape saat menampilkan) | Menutup CSRF dan XSS (bagian 9) |
| semua template `customer/*`, `admin/*`, `*.php` publik | 174 output `<?= $x ?>` / `echo $_SESSION[...]` → `<?= e($x) ?>`; token `<?= csrf_field() ?>` di 8 form | XSS, CSRF |
| `admin/hapusjasa.php`, `hapususer.php`, `pesananditerima.php`, `pesananselesai.php`; `admin/daftarharga.php`, `datauser.php`, `pemesanan.php` | Endpoint jadi POST-only (`GET` → 405, `id` dari `$_POST`); link aksi diganti form kecil + token (tampilan tombol sedikit berbeda: kini tombol, di Pemesanan tersusun vertikal) | CSRF (aksi destruktif lewat GET) |
| `customer/index.php` | `require '../functions.php'` dipindah ke atas file | `e()` dipakai di navbar sebelum fungsi ter-load |
| `Dockerfile` | Cookie session `HttpOnly`, `SameSite=Lax`, `use_strict_mode`; header `nosniff`, `X-Frame-Options`, `Referrer-Policy`; `ServerTokens Prod` | Pelapis CSRF/XSS dan kebocoran versi |
| baru: `admin/auth.php` | Guard: wajib login dan `usertype = 1` (dicek ke DB, prepared statement) | Autentikasi/otorisasi admin. |
| baru | `Dockerfile`, `docker-compose.yml`, `.env.example`, `.dockerignore`, `docker/seed-demo.sql`, `README.md`, `DEPLOY.md`, `screenshots/` | Deployment & dokumentasi. |

Tampilan dan alur pengguna **tidak berubah**. Perubahan perilaku yang disengaja:
- Form profil tidak lagi bisa mengubah `usertype` atau user lain (kolom role di form profil kini diabaikan).
- Total pesanan dihitung ulang di server dari harga database; nilai `total`/`harga*`/`user` dari form diabaikan.
- Username harus unik saat ubah profil; ID pesanan acak dicoba ulang bila bentrok.
- Pesanan dengan id jasa yang tidak ada ditolak seluruhnya.
- Semua form `POST` butuh token CSRF: halaman yang dibiarkan terbuka sangat lama (session habis) atau tombol *Back* lalu submit akan menghasilkan `403 Permintaan tidak valid`; muat ulang halaman lalu kirim lagi.
- Aksi hapus/ubah status tidak bisa lagi dipicu lewat URL/link; hanya lewat tombol di halaman admin.
- Data yang dulu disimpan lewat form admin dengan karakter spesial sudah ter-encode di database (mis. `&amp;`) dan akan tampil apa adanya; data bawaan dump tidak terpengaruh.

## 11. Runbook untuk AI agent di VPS

Instruksi deterministik. Kerjakan berurutan dan **berhenti serta lapor** jika ada langkah yang gagal; jangan improvisasi di luar daftar ini.

**Input yang harus tersedia:** akses SSH ke VPS, domain (`DOMAIN`), lokasi kode (repo/rsync), dan konfirmasi pemilik bahwa mitigasi bagian 9 boleh diterapkan.

```
1. PRA-CEK
   - uname -a; docker --version; docker compose version     (Compose harus v2)
   - Jika Docker tidak ada → jalankan 4.1.
   - ss -ltnp | grep -E ':(80|443|8080)\b'                   (catat port yang terpakai; jika 80/443 sudah dipakai
                                                              layanan lain, STOP dan tanyakan pemilik)

2. KODE
   - Letakkan project di /opt/creativo (4.2). Pastikan ada: Dockerfile, docker-compose.yml, id9352934_creativo.sql.

3. KONFIGURASI
   - Buat .env sesuai 4.3 (password acak, WEB_BIND=127.0.0.1:8080, chmod 600).
   - JANGAN mencetak isi .env ke log/percakapan yang tersimpan; sampaikan password ke pemilik lewat jalur aman.

4. JALANKAN
   - cd /opt/creativo && docker compose up -d --build --wait
   - Harapan: kedua container "healthy". Jika tidak: docker compose logs --tail=100 → lihat bagian 8.

5. VERIFIKASI LOKAL
   - Jalankan checklist bagian 6. Semua harus lulus sebelum lanjut.

6. REVERSE PROXY + HTTPS
   - Pasang Caddy (bagian 5), isi Caddyfile dengan DOMAIN dan basic_auth untuk /admin/* sebagai lapisan kedua
     (guard admin/auth.php dan prepared statement sudah ada di kode; basic auth menambah lapisan karena CSRF
     dan batas percobaan login belum ada. Minta hash dari pemilik, atau buat password acak dan serahkan ke pemilik).
   - ufw: izinkan 22, 80, 443 saja. Pastikan SSH (22) diizinkan SEBELUM `ufw enable`.
   - curl -I https://DOMAIN/login.php → 200

7. AKUN
   - Jangan jalankan docker/seed-demo.sql.
   - Minta pemilik mendaftar akun lewat /daftar.php, lalu promosikan ke admin (4.5).

8. BACKUP
   - Pasang cron harian untuk mysqldump (bagian 7) dengan retensi (mis. hapus file > 14 hari).

9. LAPOR
   - Ringkas: URL, status container, hasil checklist, port terbuka, lokasi backup, dan pengingat temuan bagian 9.
```

**Larangan:**
- Jangan menjalankan `docker compose down -v`, `DROP`, atau `DELETE` tanpa persetujuan eksplisit pemilik. Itu menghapus data database.
- Jangan mem-publish port 3306 atau mengubah `WEB_BIND` menjadi `8080`/`0.0.0.0`.
- Jangan commit atau mengirim `.env` ke repo/layanan eksternal.
- Jangan "memperbaiki" kode aplikasi di luar permintaan; usulkan saja (lihat bagian 9).

**Update rutin:** `cd /opt/creativo && git pull && docker compose up -d --build web` lalu ulangi checklist bagian 6.
