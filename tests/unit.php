<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/shop.php';

function check_test($condition, $label)
{
    if (!$condition) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "OK: $label\n";
}

foreach (['771234567', '0771234567', '+967 771 234 567', '٠٧٧١٢٣٤٥٦٧', '۰۰۹۶۷۷۷۱۲۳۴۵۶۷'] as $raw) {
    check_test(normalize_phone($raw) === '771234567', 'phone normalization ' . $raw);
}
check_test(normalize_phone('77abc1234567') === '', 'reject phone containing letters');
check_test(valid_customer_phone('771234567'), 'valid Yemeni mobile format');
foreach (['777777777', '123456789', '77123456', '7712345678', ''] as $phone) {
    check_test(!valid_customer_phone($phone), 'invalid phone ' . $phone);
}
check_test(order_next_statuses('new') === ['confirmed', 'cancelled'], 'confirmation required');
check_test(order_next_statuses('confirmed') === ['preparing', 'cancelled'], 'preparation after confirmation');
check_test(order_next_statuses('out') === ['delivered', 'cancelled'], 'delivery transition');
check_test(order_next_statuses('delivered') === [] && order_next_statuses('cancelled') === [], 'terminal states');
check_test(order_next_statuses('unknown') === [], 'unknown state');
list($price, $name) = effective_price(['id' => 1, 'price' => 1000], [
    ['product_id' => null, 'type' => 'percent', 'value' => 20, 'name' => 'offer'],
    ['product_id' => 2, 'type' => 'fixed', 'value' => 900, 'name' => 'other'],
]);
check_test($price === 800 && $name === 'offer', 'best applicable offer');
if (function_exists('imagewebp') && function_exists('imagecreatefrompng')) {
    $source = tempnam(sys_get_temp_dir(), 'wb-src-');
    $target = tempnam(sys_get_temp_dir(), 'wb-dst-');
    $image = imagecreatetruecolor(40, 20);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagepng($image, $source);
    imagedestroy($image);
    check_test(resize_image($source, $target, IMAGETYPE_PNG, 40, 20, true), 'convert PNG to WebP');
    $info = getimagesize($target);
    check_test($info && $info[2] === IMAGETYPE_WEBP && $info[0] === 40 && $info[1] === 20, 'converted image type and dimensions');
    $image = imagecreatefromwebp($target);
    check_test((imagecolorat($image, 0, 0) >> 24 & 0x7f) > 100, 'converted image transparency');
    imagedestroy($image);
    unlink($source);
    unlink($target);
}

$base = default_settings();
$base['schedule_enabled'] = '1'; $base['opens_at'] = '18:00'; $base['closes_at'] = '23:30';
check_test(!store_is_open($base, '17:59'), 'closed before opening');
check_test(store_is_open($base, '18:00'), 'opens at opening time');
check_test(store_is_open($base, '23:29') && !store_is_open($base, '23:30'), 'closes at closing time');
$base['opens_at'] = '20:00'; $base['closes_at'] = '02:00';
check_test(store_is_open($base, '23:59') && store_is_open($base, '01:59') && !store_is_open($base, '02:00'), 'overnight hours');
$base['open'] = '0';
check_test(!store_is_open($base, '22:00'), 'manual closing overrides schedule');
$base['open'] = '1'; $base['schedule_enabled'] = '0';
check_test(store_is_open($base, '12:00'), 'schedule disabled');
$token = str_repeat('a', 32);
$code = new_order_code();
check_test((bool)preg_match('/^WB-[0-9A-F]{8}$/', $code), 'new order code has eight characters');
check_test(request_token_hash($token) === request_token_hash($token) && request_token_hash($token) !== request_token_hash(str_repeat('b', 32)), 'stable full-length per-submission hash');
try { request_token_hash('bad'); check_test(false, 'reject invalid request key'); } catch (UserError $e) { check_test(true, 'reject invalid request key'); }
check_test(!valid_admin_password('12345678') && valid_admin_password('123456789012'), 'consistent minimum admin password length');
require __DIR__ . '/../lib/backup.php';
check_test(!backup_image_valid('0000000000000000.png', 'not an image'), 'reject malformed backup image');
check_test(!backup_image_valid('../0000000000000000.png', ''), 'reject invalid image path');
if (class_exists('ZipArchive')) {
    $zipPath = tempnam(sys_get_temp_dir(), 'wb-zip-');
    $zip = new ZipArchive(); $zip->open($zipPath, ZipArchive::OVERWRITE);
    $zip->addFromString('../outside.php', 'bad'); $zip->addFromString('db.json', '{}'); $zip->close();
    try { restore_backup($zipPath); check_test(false, 'reject backup path traversal'); }
    catch (UserError $e) { check_test(true, 'reject backup path traversal'); }
    unlink($zipPath);
}
