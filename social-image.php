<?php
require __DIR__ . '/lib/bootstrap.php';
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=3600');
$logo = '';
try { $logo = setting('logo'); } catch (Throwable $e) { /* use bundled logo */ }
$path = valid_image_name($logo) && is_file(ROOT . '/uploads/' . $logo) ? ROOT . '/uploads/' . $logo : ROOT . '/assets/logo.png';
if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
    $bytes = @file_get_contents($path);
    $source = $bytes !== false ? @imagecreatefromstring($bytes) : false;
    if (!$source && $path !== ROOT . '/assets/logo.png') {
        $source = @imagecreatefromstring((string)@file_get_contents(ROOT . '/assets/logo.png'));
    }
    if ($source) {
        $canvas = imagecreatetruecolor(1200, 630);
        $bg = imagecolorallocate($canvas, 255, 247, 240);
        imagefill($canvas, 0, 0, $bg);
        $w = imagesx($source); $h = imagesy($source);
        $scale = min(520 / $w, 520 / $h);
        $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
        imagecopyresampled($canvas, $source, (int)((1200 - $nw) / 2), (int)((630 - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
        header('Content-Type: image/jpeg');
        imagejpeg($canvas, null, 88);
        imagedestroy($source); imagedestroy($canvas);
        exit;
    }
}
header('Content-Type: image/png');
readfile(ROOT . '/assets/logo.png');
