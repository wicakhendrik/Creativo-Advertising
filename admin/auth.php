<?php
// Guard untuk SEMUA halaman /admin/*.
// Di-include SETELAH `require '../functions.php'` (butuh $conn).
//
//  - belum login            -> redirect ke login
//  - login tapi bukan admin -> 403 (usertype di database harus 1)
//
// Role selalu dicek ke database (bukan dari session), supaya perubahan role/penghapusan
// user langsung berlaku.

// Bukan halaman: kalau dibuka langsung lewat URL ($conn belum ada), pura-pura tidak ada.
if (!isset($conn)) {
    http_response_code(404);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION["login"]) || empty($_SESSION["username"])) {
    header("Location: ../login.php");
    exit;
}

$__stmt = mysqli_prepare($conn, "SELECT usertype FROM users WHERE username = ? LIMIT 1");
mysqli_stmt_bind_param($__stmt, "s", $_SESSION["username"]);
mysqli_stmt_execute($__stmt);
$__res = mysqli_stmt_get_result($__stmt);
$__row = $__res ? mysqli_fetch_assoc($__res) : null;
mysqli_stmt_close($__stmt);

if ($__row === null) {
    // user sudah dihapus / session basi
    $_SESSION = [];
    session_destroy();
    header("Location: ../login.php");
    exit;
}

if ((int) $__row["usertype"] !== 1) {
    http_response_code(403);
    echo "<!DOCTYPE html><meta charset='utf-8'><title>403 Forbidden</title>"
       . "<body style='font-family:sans-serif;text-align:center;margin-top:15vh'>"
       . "<h1>403 - Akses ditolak</h1><p>Halaman ini khusus admin.</p>"
       . "<p><a href='../customer/index.php'>Ke dasbor customer</a> &middot; <a href='../logout.php'>Logout</a></p></body>";
    exit;
}

unset($__stmt, $__res, $__row);
