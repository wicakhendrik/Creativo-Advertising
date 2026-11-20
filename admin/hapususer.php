<?php

require '../functions.php';
require __DIR__ . '/auth.php';
// Aksi mengubah data: hanya POST (token CSRF diperiksa otomatis di functions.php).
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    header("Allow: POST");
    exit("Method Not Allowed");
}
$id = $_POST["id"] ?? 0;

if ( hapususer($id) > 0) {
    echo "
        <script>
            alert('user berhasil dihapus!');
            document.location.href = 'datauser.php';
        </script>
    "; 
} else {
    echo "
        <script>
            alert('user gagal dihapus!');
            document.location.href = 'datauser.php';
        </script>
    ";
}
?>