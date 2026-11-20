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

if ( hapusjasa($id) > 0) {
    echo "
        <script>
            alert('data berhasil dihapus!');
            document.location.href = 'daftarharga.php';
        </script>
    "; 
} else {
    echo "
        <script>
            alert('data gagal dihapus!');
            document.location.href = 'daftarharga.php';
        </script>
    ";
}
?>