<?php
// Endpoint waktu server (milidetik) untuk pengukuran offset di browser.
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
echo json_encode([
    'server_ms'  => round(microtime(true) * 1000, 3),
    'server_iso' => gmdate('c'),
]);
