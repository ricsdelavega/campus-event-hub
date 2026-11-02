<?php






ob_start();

require __DIR__ . '/config/database.php';

$token = $_GET['token'] ?? '';

if (!preg_match('/^[a-f0-9]{32}$/i', $token)) {
    http_response_code(400);
    exit('Invalid token.');
}


$stmt = $pdo->prepare("
    SELECT r.id, r.attended_at, u.full_name, e.title, e.event_date, e.start_time, e.venue
    FROM registrations r
    INNER JOIN users u ON u.id = r.user_id
    INNER JOIN events e ON e.id = r.event_id
    WHERE r.attendance_token = :t AND r.status = 'registered'
    LIMIT 1
");
$stmt->execute(['t' => $token]);
$reg = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reg) {
    http_response_code(404);
    exit('Token not found or registration is no longer confirmed.');
}


$payload = $token;


require_once __DIR__ . '/includes/phpqrcode.php';

ob_start();
QRcode::png($payload, false, QR_ECLEVEL_H, 6, 0); 
$qrPng = ob_get_clean();

if ($qrPng === false || $qrPng === '') {
    http_response_code(500);
    exit('QR generation failed.');
}

$qrImg = @imagecreatefromstring($qrPng);
if (!$qrImg) {
    http_response_code(500);
    exit('QR decode failed.');
}


$W = 900;
$H = 1273; 
$img = imagecreatetruecolor($W, $H);
imageantialias($img, true);


$colDeep    = imagecolorallocate($img, 11, 61, 46);     
$colDeep2   = imagecolorallocate($img, 27, 111, 82);    
$colAccent  = imagecolorallocate($img, 46, 230, 168);   
$colWhite   = imagecolorallocate($img, 255, 255, 255);
$colBody    = imagecolorallocate($img, 248, 251, 249);  
$colInk     = imagecolorallocate($img, 27, 45, 38);     
$colSub     = imagecolorallocate($img, 90, 116, 103);   
$colLine    = imagecolorallocate($img, 223, 238, 229);  
$colChip    = imagecolorallocate($img, 232, 248, 239);  


$headH = 300;
for ($y = 0; $y < $headH; $y++) {
    $t = $y / $headH;
    $r = (int) round(11 + (27 - 11) * $t);
    $g = (int) round(61 + (111 - 61) * $t);
    $b = (int) round(46 + (82 - 46) * $t);
    $line = imagecolorallocate($img, $r, $g, $b);
    imageline($img, 0, $y, $W, $y, $line);
}


imagefilledrectangle($img, 0, $headH, $W, $headH + 8, $colAccent);


imagefilledrectangle($img, 0, $headH + 8, $W, $H, $colBody);



$fontBold   = __DIR__ . '/assets/fonts/arialbd.ttf';
$fontRegular = __DIR__ . '/assets/fonts/arial.ttf';
$hasTtf = is_file($fontBold) && is_file($fontRegular);

if (!$hasTtf) {
    
    $winFonts = 'C:/Windows/Fonts/';
    if (is_file($winFonts . 'arialbd.ttf')) {
        $fontBold = $winFonts . 'arialbd.ttf';
        $fontRegular = $winFonts . 'arial.ttf';
        $hasTtf = true;
    }
}

function ttftext($img, $hasTtf, $font, $size, $x, $y, $text, $color, $align = 'left', $maxW = 0)
{
    if ($hasTtf) {
        $box = imagettfbbox($size, 0, $font, $text);
        $tw = abs($box[4] - $box[0]);
        if ($maxW > 0 && $tw > $maxW) {
            
            while ($tw > $maxW && $size > 11) {
                $size -= 1;
                $box = imagettfbbox($size, 0, $font, $text);
                $tw = abs($box[4] - $box[0]);
            }
        }
        $tx = $x;
        if ($align === 'center') $tx = $x - (int) ($tw / 2);
        elseif ($align === 'right') $tx = $x - $tw;
        imagettftext($img, $size, 0, $tx, $y, $color, $font, $text);
        return $tw;
    }
    
    $gw = imagefontwidth(5) * strlen($text);
    $tx = $x;
    if ($align === 'center') $tx = $x - (int) ($gw / 2);
    elseif ($align === 'right') $tx = $x - $gw;
    imagestring($img, 5, $tx, $y - 14, $text, $color);
    return $gw;
}


ttftext($img, $hasTtf, $fontBold, 30, (int) ($W / 2), 92, 'PATEROS TECHNOLOGICAL COLLEGE', $colWhite, 'center', $W - 80);
ttftext($img, $hasTtf, $fontRegular, 20, (int) ($W / 2), 128, 'Campus Event Hub  ·  Attendance Pass', $colAccent, 'center', $W - 80);


$evTitle = $reg['title'];
ttftext($img, $hasTtf, $fontBold, 40, (int) ($W / 2), 200, $evTitle, $colWhite, 'center', $W - 120);


$dateTxt = date('M j, Y — g:i A', strtotime($reg['event_date'] . ' ' . $reg['start_time']));
$chipW = 460;
$chipH = 52;
$chipX = (int) (($W - $chipW) / 2);
$chipY = 226;
imagefilledrectangle($img, $chipX, $chipY, $chipX + $chipW, $chipY + $chipH, imagecolorallocate($img, 255, 255, 255));

imagearc($img, $chipX + 1, $chipY + 1, 12, 12, 180, 270, $colWhite);
imagearc($img, $chipX + $chipW - 1, $chipY + 1, 12, 12, 270, 360, $colWhite);
imagearc($img, $chipX + 1, $chipY + $chipH - 1, 12, 12, 90, 180, $colWhite);
imagearc($img, $chipX + $chipW - 1, $chipY + $chipH - 1, 12, 12, 0, 90, $colWhite);
ttftext($img, $hasTtf, $fontBold, 22, (int) ($W / 2), $chipY + 35, $dateTxt, $colDeep, 'center', $chipW - 40);


$qrSide = 460;
$qrX = (int) (($W - $qrSide) / 2);
$qrY = $headH + 70;


imagefilledrectangle($img, $qrX - 16, $qrY - 16, $qrX + $qrSide + 16, $qrY + $qrSide + 16, $colWhite);
imagerectangle($img, $qrX - 16, $qrY - 16, $qrX + $qrSide + 16, $qrY + $qrSide + 16, $colLine);


imagecopyresized($img, $qrImg, $qrX, $qrY, 0, 0, $qrSide, $qrSide, imagesx($qrImg), imagesy($qrImg));
imagedestroy($qrImg);


ttftext($img, $hasTtf, $fontRegular, 19, (int) ($W / 2), $qrY + $qrSide + 44, 'Present this QR to the event admin for check-in', $colSub, 'center', $W - 120);


$dy = $qrY + $qrSide + 92;


ttftext($img, $hasTtf, $fontBold, 17, 90, $dy, 'STUDENT', $colSub, 'left');
ttftext($img, $hasTtf, $fontBold, 27, 90, $dy + 36, $reg['full_name'], $colInk, 'left', 480);


ttftext($img, $hasTtf, $fontBold, 17, 520, $dy, 'VENUE', $colSub, 'left');
ttftext($img, $hasTtf, $fontRegular, 24, 520, $dy + 36, $reg['venue'], $colInk, 'left', 300);


$dvY = $dy + 66;
imageline($img, 90, $dvY, $W - 90, $dvY, $colLine);


ttftext($img, $hasTtf, $fontBold, 17, 90, $dvY + 30, 'REFERENCE CODE', $colSub, 'left');
ttftext($img, $hasTtf, $fontRegular, 22, 90, $dvY + 62, strtoupper($token), $colSub, 'left', $W - 200);


$footY = $H - 96;
imagefilledrectangle($img, 0, $footY, $W, $H, $colDeep);
imagefilledrectangle($img, 0, $footY, $W, $footY + 5, $colAccent);
ttftext($img, $hasTtf, $fontBold, 19, (int) ($W / 2), $footY + 44, 'Do not share this pass — one scan per student.', $colWhite, 'center', $W - 100);
ttftext($img, $hasTtf, $fontRegular, 15, (int) ($W / 2), $footY + 72, 'Generated ' . date('M j, Y g:i A') . '  ·  Campus Event Hub', imagecolorallocate($img, 167, 243, 208), 'center', $W - 100);


imagefilledellipse($img, 0, ($headH + 8 + $footY) / 2, 44, 44, $colWhite);
imagefilledellipse($img, $W, ($headH + 8 + $footY) / 2, 44, 44, $colWhite);


$safeName = preg_replace('/[^a-z0-9]+/i', '-', $reg['title']);
$safeName = trim(strtolower($safeName), '-');
$fileBase = 'qr-' . ($safeName !== '' ? $safeName : 'pass') . '-' . substr($token, 0, 8);

header('Content-Type: image/jpeg');
header('Content-Disposition: attachment; filename="' . $fileBase . '.jpg"');
header('Cache-Control: public, max-age=600');


ob_end_clean();
imagejpeg($img, null, 92);
imagedestroy($img);
exit;
