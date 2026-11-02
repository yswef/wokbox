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
