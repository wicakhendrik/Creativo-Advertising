<?php
// Konfigurasi database dibaca dari environment variable (lihat .env.example / docker-compose.yml).
// Kalau env tidak di-set, dipakai default lokal yang sama dengan docker-compose.yml.
$dbHost = getenv("DB_HOST") ?: "localhost";
$dbUser = getenv("DB_USER") ?: "creativo";
$dbPass = getenv("DB_PASS") !== false ? getenv("DB_PASS") : "creativo_pass";
$dbName = getenv("DB_NAME") ?: "creativo";

// Kode ini ditulis untuk PHP 7.x: error query tidak melempar exception.
// PHP 8.1+ default-nya melempar exception, jadi dikembalikan ke perilaku lama.
mysqli_report(MYSQLI_REPORT_OFF);

$conn = mysqli_connect($dbHost, $dbUser, $dbPass, $dbName);
if (!$conn) {
    http_response_code(500);
    die("Koneksi database gagal: " . mysqli_connect_error());
}

// Query statis (tanpa input user)
$result = mysqli_query($conn, "SELECT jasa.id_jasa, kj.ket_katJasa, jasa.namaJasa, jasa.keterangan, jasa.harga
                                FROM jasa, kategoriJasa as kj
                                WHERE jasa.kategori_Jasa = kj.id_katJasa");

/**
 * Escape untuk output HTML (anti-XSS). SEMUA data dari database/user yang dicetak ke halaman
 * WAJIB lewat fungsi ini:  <?= e($row["namaJasa"]) ?>
 * Data disimpan APA ADANYA di database (tidak di-encode saat disimpan), di-escape saat ditampilkan.
 */
function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}

/**
 * CSRF. Token acak per-session. Setiap <form method="post"> WAJIB memuat <?= csrf_field() ?>.
 * Verifikasi dilakukan OTOMATIS untuk semua request POST di bagian bawah file ini (sebelum
 * halaman memproses $_POST), jadi handler POST baru otomatis terlindungi.
 */
function csrf_token() {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }
    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }
    return $_SESSION["csrf_token"];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify() {
    $sent = $_POST["csrf_token"] ?? "";
    $valid = !empty($_SESSION["csrf_token"]) && is_string($sent)
             && hash_equals($_SESSION["csrf_token"], $sent);
    if (!$valid) {
        http_response_code(403);
        echo "<!DOCTYPE html><meta charset='utf-8'><title>403 Forbidden</title>"
           . "<body style='font-family:sans-serif;text-align:center;margin-top:15vh'>"
           . "<h1>403 - Permintaan tidak valid</h1>"
           . "<p>Token keamanan (CSRF) tidak cocok atau sudah kedaluwarsa. Kembali, muat ulang halaman, lalu coba lagi.</p></body>";
        exit;
    }
}

/**
 * Jalankan query dengan prepared statement. SEMUA query yang memuat input (GET/POST/session)
 * WAJIB lewat fungsi ini: nilai dikirim terpisah dari SQL sehingga tidak bisa jadi injection.
 *
 * @param string $sql    SQL dengan placeholder ?
 * @param string $types  tipe parameter: i=integer, s=string, d=double (mis. "si")
 * @param array  $params nilai parameter, urut sesuai placeholder
 * @return mysqli_result|int|false  SELECT -> mysqli_result (dipakai seperti hasil mysqli_query);
 *                                  INSERT/UPDATE/DELETE -> jumlah baris terpengaruh (int);
 *                                  gagal -> false (pesan error di global $db_error)
 */
function dbq($sql, $types = "", $params = []) {
    global $conn, $db_error;
    $db_error = null;

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        $db_error = mysqli_error($conn);
        return false;
    }
    if ($types !== "" && !mysqli_stmt_bind_param($stmt, $types, ...$params)) {
        $db_error = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        return false;
    }
    if (!mysqli_stmt_execute($stmt)) {
        $db_error = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        return false;
    }
    $res = mysqli_stmt_get_result($stmt);          // false bila bukan SELECT
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    return $res !== false ? $res : $affected;
}

// Hasil SELECT -> array of rows
function query($query, $types = "", $params = []) {
    $res = dbq($query, $types, $params);
    $rows = [];
    if ($res instanceof mysqli_result) {
        while( $row = mysqli_fetch_assoc($res) ) {
            $rows[] = $row;
        }
    }
    return $rows;
}

// id_user dari user yang sedang login (berdasarkan session), atau null
function current_user_id() {
    if (empty($_SESSION["username"])) {
        return null;
    }
    $rows = query("SELECT id_user FROM users WHERE username = ? LIMIT 1", "s", [$_SESSION["username"]]);
    return $rows ? (int) $rows[0]["id_user"] : null;
}

function registrasi($data) {
    $username = strtolower(stripslashes($data["username"]));
    $email = stripslashes($data["email"]);
    $jk = (int) $data["jeniskelamin"];
    $noTelp = $data["noTelp"];
    $password = $data["password"];
    $password_repeat = $data["password-repeat"];

    //cek username tersedia
    if ( query("SELECT username FROM users WHERE username = ?", "s", [$username]) ) {
        echo "<script>
                alert('username sudah terdaftar!')
                </script>";
        return false;
    }

    //cek konfirmasi password
    if ( $password !== $password_repeat) {
        echo "<script>
                alert('konfirmasi password tidak sesuai!');
            </script>";
        return false;
    }

    //enkripsi password
    $password = password_hash($password, PASSWORD_DEFAULT);

    //menambahkan user ke database (usertype 2 = customer; role tidak boleh datang dari form)
    $affected = dbq("INSERT INTO users VALUES(NULL, ?, ?, ?, ?, ?, 2)", "sssis",
                    [$username, $email, $password, $jk, $noTelp]);

    return is_int($affected) ? $affected : 0;
}

function tambahjasa($data) {
    $namajasa = $data["namajasa"];
    $kategorijasa = $data["kategorijasa"];
    $keterangan = $data["keterangan"];
    $harga = $data["harga"];

    $affected = dbq("INSERT INTO jasa VALUES(NULL, ?, ?, ?, ?)", "ssss",
                    [$kategorijasa, $namajasa, $keterangan, $harga]);

    return is_int($affected) ? $affected : 0;
}

function hapususer($id) {
    $affected = dbq("DELETE FROM users WHERE id_user = ?", "i", [(int) $id]);
    return is_int($affected) ? $affected : 0;
}

function hapusjasa($id) {
    $affected = dbq("DELETE FROM jasa WHERE id_jasa = ?", "i", [(int) $id]);
    return is_int($affected) ? $affected : 0;
}

function pesananditerima($id) {
    $affected = dbq("UPDATE pemesanan SET id_status = 2 WHERE id_pemesanan = ?", "i", [(int) $id]);
    return is_int($affected) ? $affected : 0;
}

function pesananselesai($id) {
    $affected = dbq("UPDATE pemesanan SET id_status = 3 WHERE id_pemesanan = ?", "i", [(int) $id]);
    return is_int($affected) ? $affected : 0;
}

/**
 * Ubah data user.
 *
 * $asAdmin = true  : dipakai HANYA oleh admin/ubahuser.php (sudah lewat guard admin). Boleh mengubah
 *                    user mana pun (id dari form) dan mengubah role (usertype).
 * $asAdmin = false : dipakai form "profil" (admin & customer). Yang diubah SELALU user yang sedang
 *                    login (id dari session, id/usertype di form diabaikan), sehingga customer tidak
 *                    bisa mengubah user lain atau menaikkan role dirinya sendiri.
 */
function ubahuser($data, $asAdmin = false) {
    global $db_error;

    $username = $data["username"];
    $email = $data["email"];
    $jeniskelamin = (int) $data["jeniskelamin"];
    $notelp = $data["notelp"];

    if ($asAdmin) {
        $id = (int) $data["id"];
    } else {
        $id = current_user_id();
        if ($id === null) {
            return 0;
        }
    }

    // username harus unik (tabel tidak punya unique key; duplikat merusak login)
    if ( query("SELECT id_user FROM users WHERE username = ? AND id_user <> ?", "si", [$username, $id]) ) {
        $db_error = "username sudah dipakai";
        return 0;
    }

    if ($asAdmin) {
        $affected = dbq("UPDATE users SET username = ?, email = ?, jenisKelamin = ?, noTelp = ?, usertype = ?
                         WHERE id_user = ?", "ssisii",
                        [$username, $email, $jeniskelamin, $notelp, (int) $data["usertype"], $id]);
    } else {
        $affected = dbq("UPDATE users SET username = ?, email = ?, jenisKelamin = ?, noTelp = ?
                         WHERE id_user = ?", "ssisi",
                        [$username, $email, $jeniskelamin, $notelp, $id]);
        // supaya session tetap valid kalau username berubah
        if (is_int($affected) && $affected > 0) {
            $_SESSION["username"] = $username;
        }
    }

    return is_int($affected) ? $affected : 0;
}

function ubahjasa($data) {
    $id = (int) $data["id"];
    $namajasa = $data["namajasa"];
    $kategorijasa = $data["kategorijasa"];
    $keterangan = $data["keterangan"];
    $harga = $data["harga"];

    $affected = dbq("UPDATE jasa SET namaJasa = ?, kategori_Jasa = ?, keterangan = ?, harga = ?
                     WHERE id_jasa = ?", "ssssi",
                    [$namajasa, $kategorijasa, $keterangan, $harga, $id]);

    return is_int($affected) ? $affected : 0;
}

/**
 * Buat pesanan untuk user yang sedang login.
 * Yang dipercaya dari form HANYA id jasa dan jumlah. Pemesan diambil dari session, harga satuan dari
 * database, dan total dihitung di server (nilai "user", "harga*" dan "total" di form diabaikan).
 */
function pemesanan($data) {
    global $conn;

    $customer = current_user_id();
    if ($customer === null) {
        return 0;
    }

    // kumpulkan item: jasa{i} (id jasa) + jumlah{i}
    $items = [];
    $totalharga = 0;
    for ($i = 1; isset($data["jasa$i"]); $i++) {
        $jasa = (int) $data["jasa$i"];
        $jumlah = isset($data["jumlah$i"]) ? max(0, (int) $data["jumlah$i"]) : 0;

        $row = query("SELECT harga FROM jasa WHERE id_jasa = ?", "i", [$jasa]);
        if (!$row) {
            return 0;               // id jasa tidak ada -> tolak seluruh pesanan
        }
        $subtotal = $jumlah * (int) $row[0]["harga"];
        $items[] = [$jasa, $jumlah, $subtotal];
        $totalharga += $subtotal;
    }
    if (!$items) {
        return 0;
    }

    mysqli_begin_transaction($conn);

    // id pesanan acak 8 digit; ulangi bila kebetulan bentrok
    $ok = false;
    for ($try = 0; $try < 5 && !$ok; $try++) {
        $id = random_int(11111111, 99999999);
        $ok = is_int(dbq("INSERT INTO pemesanan VALUES(?, ?, NOW(), ?, 1)", "iii", [$id, $customer, $totalharga]));
    }
    if (!$ok) {
        mysqli_rollback($conn);
        return 0;
    }

    foreach ($items as [$jasa, $jumlah, $subtotal]) {
        if (!is_int(dbq("INSERT INTO detil_pemesanan VALUES(?, ?, ?, ?)", "iiii", [$id, $jasa, $jumlah, $subtotal]))) {
            mysqli_rollback($conn);
            return 0;
        }
    }

    mysqli_commit($conn);
    return 1;
}

// Tolak setiap request POST tanpa token CSRF yang valid (dijalankan saat file ini di-include,
// yaitu sebelum halaman memproses $_POST).
if (($_SERVER["REQUEST_METHOD"] ?? "") === "POST") {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    csrf_verify();
}
?>
