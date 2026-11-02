<?php










const QR_VERSION_INFO_M = [
    1  => [26,  10, [1, 16, 0, 0]],
    2  => [44,  16, [1, 28, 0, 0]],
    3  => [70,  26, [1, 44, 0, 0]],
    4  => [100, 18, [2, 32, 0, 0]],
    5  => [134, 24, [2, 43, 0, 0]],
    6  => [172, 16, [4, 27, 0, 0]],
    7  => [196, 18, [4, 31, 0, 0]],
    8  => [242, 22, [2, 38, 2, 39]],
    9  => [276, 22, [3, 36, 2, 37]],
    10 => [316, 26, [4, 43, 1, 44]],
];


const QR_ALIGNMENT = [
    1 => [],
    2 => [6, 18],
    3 => [6, 22],
    4 => [6, 26],
    5 => [6, 30],
    6 => [6, 34],
    7 => [6, 22, 38],
    8 => [6, 24, 42],
    9 => [6, 26, 46],
    10 => [6, 28, 50],
];

function qrEncodeMatrix(string $data): ?array
{
    $byteCount = strlen($data);
    if ($byteCount === 0) return null;

    
    $capacities = [1 => 14, 2 => 26, 3 => 42, 4 => 62, 5 => 84, 6 => 106, 7 => 122, 8 => 152, 9 => 180, 10 => 213];
    $version = null;
    foreach ($capacities as $ver => $cap) {
        if ($byteCount <= $cap) {
            $version = $ver;
            break;
        }
    }
    if ($version === null) return null;

    [$totalCw, $ecPerBlock, $blocks] = QR_VERSION_INFO_M[$version];

    
    $bits = '';
    $append = function (string $b, int $len) use (&$bits) {
        $bits .= str_pad(decbin($b), $len, '0', STR_PAD_LEFT);
    };
    $append(4, 4);                    
    $append($byteCount, 8);           
    for ($i = 0; $i < $byteCount; $i++) {
        $append(ord($data[$i]), 8);
    }
    
    $dataCapacityBits = array_sum(array_map(fn($b) => $b[1] * 8 + ($b[3] ? $b[1] * 8 : 0), [$blocks]));
    
    $totalDataCw = $blocks[0] * $blocks[1] + $blocks[2] * $blocks[3];
    $dataCapacityBits = $totalDataCw * 8;
    $bits = substr($bits . str_repeat('0', 4), 0, min(strlen($bits) + 4, $dataCapacityBits));
    
    while (strlen($bits) % 8 !== 0) $bits .= '0';
    
    $padBytes = [0xEC, 0x11];
    $padIdx = 0;
    while (strlen($bits) < $dataCapacityBits) {
        $bits .= str_pad(decbin($padBytes[$padIdx % 2]), 8, '0', STR_PAD_LEFT);
        $padIdx++;
    }

    
    $dataCw = [];
    for ($i = 0; $i < strlen($bits); $i += 8) {
        $dataCw[] = bindec(substr($bits, $i, 8));
    }

    
    $dataBlocks = [];
    $offset = 0;
    for ($b = 0; $b < $blocks[0]; $b++) {
        $dataBlocks[] = array_slice($dataCw, $offset, $blocks[1]);
        $offset += $blocks[1];
    }
    for ($b = 0; $b < $blocks[2]; $b++) {
        $dataBlocks[] = array_slice($dataCw, $offset, $blocks[3]);
        $offset += $blocks[3];
    }

    $ecBlocks = array_map(fn($blk) => qrRsEncode($blk, $ecPerBlock), $dataBlocks);

    
    $final = [];
    $maxDataLen = max(array_map('count', $dataBlocks));
    for ($i = 0; $i < $maxDataLen; $i++) {
        foreach ($dataBlocks as $blk) {
            if ($i < count($blk)) $final[] = $blk[$i];
        }
    }
    for ($i = 0; $i < $ecPerBlock; $i++) {
        foreach ($ecBlocks as $blk) {
            if ($i < count($blk)) $final[] = $blk[$i];
        }
    }

    
    $n = 17 + 4 * $version;
    $modules = array_fill(0, $n, array_fill(0, $n, null)); 
    $reserved = array_fill(0, $n, array_fill(0, $n, false)); 
    qrDrawFunctionPatterns($modules, $reserved, $n, $version);

    
    $bitIdx = 0;
    $totalBits = count($final) * 8;
    $upward = true;
    for ($col = $n - 1; $col >= 1; $col -= 2) {
        if ($col === 6) $col = 5; 
        for ($i = 0; $i < $n; $i++) {
            $row = $upward ? $n - 1 - $i : $i;
            for ($c = $col; $c >= $col - 1; $c--) {
                if ($modules[$row][$c] !== null) continue;
                $bit = false;
                if ($bitIdx < $totalBits) {
                    $byte = $final[intdiv($bitIdx, 8)];
                    $bit = (($byte >> (7 - ($bitIdx % 8))) & 1) === 1;
                }
                $modules[$row][$c] = $bit;
                $bitIdx++;
            }
        }
        $upward = !$upward;
    }

    
    $best = null;
    $bestScore = PHP_INT_MAX;
    for ($mask = 0; $mask < 8; $mask++) {
        $m = $modules;
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if (!$reserved[$r][$c] && $m[$r][$c] !== null && qrMaskBit($mask, $r, $c)) {
                    $m[$r][$c] = !$m[$r][$c];
                }
            }
        }
        qrDrawFormatInfo($m, $n, $mask);
        $score = qrPenaltyScore($m, $n);
        if ($score < $bestScore) {
            $bestScore = $score;
            $best = $m;
        }
    }

    
    for ($r = 0; $r < $n; $r++) {
        for ($c = 0; $c < $n; $c++) {
            if ($best[$r][$c] === null) $best[$r][$c] = false;
        }
    }

    return ['modules' => $best, 'size' => $n];
}




function qrGfTables(): array
{
    static $tables = null;
    if ($tables !== null) return $tables;
    $exp = array_fill(0, 512, 0);
    $log = array_fill(0, 256, 0);
    $x = 1;
    for ($i = 0; $i < 255; $i++) {
        $exp[$i] = $x;
        $log[$x] = $i;
        $x <<= 1;
        if ($x & 0x100) $x ^= 0x11D;
    }
    for ($i = 255; $i < 512; $i++) $exp[$i] = $exp[$i - 255];
    return $tables = [$exp, $log];
}

function qrGfMul(int $a, int $b): int
{
    [$exp, $log] = qrGfTables();
    if ($a === 0 || $b === 0) return 0;
    return $exp[$log[$a] + $log[$b]];
}

function qrRsEncode(array $data, int $ecLen): array
{
    [$exp, $log] = qrGfTables();
    
    $gen = [1];
    for ($i = 0; $i < $ecLen; $i++) {
        $newGen = array_fill(0, count($gen) + 1, 0);
        for ($j = 0; $j < count($gen); $j++) {
            $newGen[$j]     ^= $gen[$j];              
            $newGen[$j + 1] ^= qrGfMul($gen[$j], $exp[$i]);
        }
        $gen = $newGen;
    }
    
    $res = array_merge($data, array_fill(0, $ecLen, 0));
    for ($i = 0; $i < count($data); $i++) {
        $factor = $res[$i];
        if ($factor === 0) continue;
        for ($j = 1; $j < count($gen); $j++) {
            $res[$i + $j] ^= qrGfMul($gen[$j], $factor);
        }
    }
    return array_slice($res, count($data));
}




function qrDrawFunctionPatterns(array &$m, array &$reserved, int $n, int $version): void
{
    $mark = function (int $r, int $c, bool $val) use (&$m, &$reserved, $n) {
        if ($r < 0 || $r >= $n || $c < 0 || $c >= $n) return;
        $m[$r][$c] = $val;
        $reserved[$r][$c] = true;
    };

    
    $drawFinder = function (int $r0, int $c0) use ($mark) {
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                $mark($r0 + $r, $c0 + $c,
                    ($r >= 0 && $r <= 6 && ($c === 0 || $c === 6)) ||
                    ($c >= 0 && $c <= 6 && ($r === 0 || $r === 6)) ||
                    ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4)
                );
            }
        }
    };
    $drawFinder(0, 0);
    $drawFinder(0, $n - 7);
    $drawFinder($n - 7, 0);

    
    for ($i = 8; $i < $n - 8; $i++) {
        if ($m[6][$i] === null) $mark(6, $i, ($i % 2 === 0));
        if ($m[$i][6] === null) $mark($i, 6, ($i % 2 === 0));
    }

    
    $centers = QR_ALIGNMENT[$version];
    $count = count($centers);
    for ($i = 0; $i < $count; $i++) {
        for ($j = 0; $j < $count; $j++) {
            $r = $centers[$i];
            $c = $centers[$j];
            
            if (($r <= 8 && $c <= 8) || ($r <= 8 && $c >= $n - 9) || ($r >= $n - 9 && $c <= 8)) continue;
            for ($dr = -2; $dr <= 2; $dr++) {
                for ($dc = -2; $dc <= 2; $dc++) {
                    $mark($r + $dr, $c + $dc, max(abs($dr), abs($dc)) !== 1);
                }
            }
        }
    }

    
    $mark($n - 8, 8, true);
    for ($i = 0; $i < 9; $i++) {
        if ($m[8][$i] === null) $mark(8, $i, false);
        if ($m[$i][8] === null) $mark($i, 8, false);
    }
    for ($i = 0; $i < 8; $i++) {
        if ($m[8][$n - 1 - $i] === null) $mark(8, $n - 1 - $i, false);
        if ($m[$n - 1 - $i][8] === null) $mark($n - 1 - $i, 8, false);
    }

    
    if ($version >= 7) {
        $bits = qrVersionBits($version);
        for ($i = 0; $i < 18; $i++) {
            $bit = (($bits >> $i) & 1) === 1;
            $a = intdiv($i, 3);
            $b = $i % 3;
            $m[$a][$n - 11 + $b] = $bit;
            $m[$n - 11 + $b][$a] = $bit;
            $reserved[$a][$n - 11 + $b] = true;
            $reserved[$n - 11 + $b][$a] = true;
        }
    }
}

function qrDrawFormatInfo(array &$m, int $n, int $mask): void
{
    
    $data = (1 << 3) | $mask; 
    $rem = $data << 10;
    $g = 0x537;
    for ($i = 4; $i >= 0; $i--) {
        if (($rem >> (10 + $i)) & 1) $rem ^= $g << $i;
    }
    $fmt = (($data << 10) | $rem) ^ 0x5412;

    for ($i = 0; $i <= 5; $i++)  $m[8][$i] = (($fmt >> $i) & 1) === 1;
    $m[8][7] = (($fmt >> 6) & 1) === 1;
    $m[8][8] = (($fmt >> 7) & 1) === 1;
    $m[7][8] = (($fmt >> 8) & 1) === 1;
    for ($i = 9; $i < 15; $i++) $m[14 - $i][8] = (($fmt >> $i) & 1) === 1;

    for ($i = 0; $i < 8; $i++)  $m[$n - 1 - $i][8] = (($fmt >> $i) & 1) === 1;
    for ($i = 8; $i < 15; $i++) $m[8][$n - 15 + $i] = (($fmt >> $i) & 1) === 1;
    $m[$n - 8][8] = true; 
}

function qrVersionBits(int $version): int
{
    $rem = $version << 12;
    $g = 0x1F25;
    for ($i = 5; $i >= 0; $i--) {
        if (($rem >> (12 + $i)) & 1) $rem ^= $g << $i;
    }
    return ($version << 12) | $rem;
}

function qrMaskBit(int $mask, int $r, int $c): bool
{
    switch ($mask) {
        case 0:
            return ($r + $c) % 2 === 0;
        case 1:
            return $r % 2 === 0;
        case 2:
            return $c % 3 === 0;
        case 3:
            return ($r + $c) % 3 === 0;
        case 4:
            return (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0;
        case 5:
            return (($r * $c) % 2 + ($r * $c) % 3) === 0;
        case 6:
            return ((($r * $c) % 2 + ($r * $c) % 3) % 2) === 0;
        case 7:
            return ((($r + $c) % 2 + ($r * $c) % 3) % 2) === 0;
    }
    return false;
}

function qrPenaltyScore(array $m, int $n): int
{
    $score = 0;
    
    for ($r = 0; $r < $n; $r++) {
        $run = 1;
        for ($c = 1; $c < $n; $c++) {
            if ($m[$r][$c] === $m[$r][$c - 1]) {
                $run++;
                if ($run === 5) $score += 3;
                elseif ($run > 5) $score += 1;
            } else $run = 1;
        }
    }
    for ($c = 0; $c < $n; $c++) {
        $run = 1;
        for ($r = 1; $r < $n; $r++) {
            if ($m[$r][$c] === $m[$r - 1][$c]) {
                $run++;
                if ($run === 5) $score += 3;
                elseif ($run > 5) $score += 1;
            } else $run = 1;
        }
    }
    
    $pat1 = [true, false, true, true, true, false, false, false, false];
    $pat2 = array_reverse($pat1);
    for ($r = 0; $r < $n; $r++) {
        for ($c = 0; $c <= $n - 11; $c++) {
            $seg = array_slice($m[$r], $c, 11);
            if ($seg === $pat1 || $seg === $pat2) $score += 40;
        }
    }
    for ($c = 0; $c < $n; $c++) {
        for ($r = 0; $r <= $n - 11; $r++) {
            $seg = [];
            for ($i = 0; $i < 11; $i++) $seg[] = $m[$r + $i][$c];
            if ($seg === $pat1 || $seg === $pat2) $score += 40;
        }
    }
    
    $dark = 0;
    for ($r = 0; $r < $n; $r++) for ($c = 0; $c < $n; $c++) if ($m[$r][$c]) $dark++;
    $percent = intdiv($dark * 100, $n * $n);
    $score += intdiv(abs($percent - 50), 5) * 10;
    return $score;
}

function qrRenderMatrixPng(array $modules, int $n, int $targetSize): ?string
{
    if (!function_exists('imagecreatetruecolor')) return null;
    $quiet = 4;
    $total = $n + $quiet * 2;
    $scale = max(1, (int) floor($targetSize / $total));
    $px = $total * $scale;

    $img = imagecreatetruecolor($px, $px);
    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 0, 0, 0);
    imagefill($img, 0, 0, $white);

    for ($r = 0; $r < $n; $r++) {
        for ($c = 0; $c < $n; $c++) {
            if ($modules[$r][$c]) {
                imagefilledrectangle(
                    $img,
                    ($c + $quiet) * $scale,
                    ($r + $quiet) * $scale,
                    ($c + $quiet + 1) * $scale - 1,
                    ($r + $quiet + 1) * $scale - 1,
                    $black
                );
            }
        }
    }

    ob_start();
    imagepng($img);
    $png = ob_get_clean();
    imagedestroy($img);
    return $png !== false ? $png : null;
}
