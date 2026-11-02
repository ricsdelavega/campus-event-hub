<?php




ob_start();

require __DIR__ . '/config/database.php';

$token = $_GET['token'] ?? '';
$size  = filter_input(INPUT_GET, 'size', FILTER_VALIDATE_INT, ['options' => ['default' => 220, 'min_range' => 80, 'max_range' => 600]]);

if (!preg_match('/^[a-f0-9]{32}$/i', $token)) {
    http_response_code(400);
    exit('Invalid token.');
}


$check = $pdo->prepare("
    SELECT COUNT(*) FROM registrations WHERE attendance_token = :t AND status = 'registered'
");
$check->execute(['t' => $token]);
if ((int) $check->fetchColumn() === 0) {
    http_response_code(404);
    exit('Token not found or registration is no longer confirmed.');
}




$payload = $token;

$cacheDir = __DIR__ . '/qr_cache';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0777, true);
}
$cacheFile = $cacheDir . '/v2_' . md5($payload) . '.png';

if (!is_file($cacheFile)) {
    require_once __DIR__ . '/includes/phpqrcode.php';
    
    ob_start();
    
    
    QRcode::png($payload, false, QR_ECLEVEL_H, 8, 2);
    $png = ob_get_clean();
    if ($png === false || $png === '') {
        http_response_code(500);
        exit('QR generation failed.');
    }
    file_put_contents($cacheFile, $png);
}

header('Content-Type: image/png');
header('Content-Length: ' . filesize($cacheFile));
header('Cache-Control: public, max-age=3600');
ob_end_clean();
readfile($cacheFile);
exit;
