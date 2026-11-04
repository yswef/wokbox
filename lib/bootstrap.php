<?php
define('ROOT', dirname(__DIR__));
$CONFIG = require ROOT . '/config.php';
date_default_timezone_set($CONFIG['timezone'] ?? 'Asia/Aden');
mb_internal_encoding('UTF-8');

class UserError extends Exception {}

function cfg($key, $default = null)
{
    global $CONFIG;
    return isset($CONFIG[$key]) ? $CONFIG[$key] : $default;
}

function db()
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $dsn = 'mysql:host=' . cfg('db_host') . ';dbname=' . cfg('db_name') . ';charset=' . cfg('db_charset', 'utf8mb4');
    $pdo = new PDO($dsn, cfg('db_user'), cfg('db_pass'), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET time_zone = '" . date('P') . "'");
    return $pdo;
}

function start_session()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('wbsid');
    session_start();
}

function out($data = null, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function fail($message, $code = 400)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_body()
{
    static $body = null;
    if ($body === null) {
        $decoded = json_decode((string)file_get_contents('php://input'), true);
        $body = is_array($decoded) ? $decoded : [];
    }
    return $body;
}

function clean_text($value, $max)
{
    $value = (string)$value;
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
    $value = trim($value);
    return mb_substr($value, 0, $max);
}

function normalize_phone($raw)
{
    $raw = strtr((string)$raw, ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']);
    if (preg_match('/[^0-9+()\s-]/u', $raw)) { return ''; }
    $d = preg_replace('/\D+/', '', $raw);
    if (strpos($d, '00') === 0) {
        $d = substr($d, 2);
    }
    if (strpos($d, '967') === 0 && strlen($d) === 12) {
        $d = substr($d, 3);
    }
    if (strpos($d, '0') === 0 && strlen($d) === 10) {
        $d = substr($d, 1);
    }
    return $d;
}

function valid_customer_phone($phone)
{
    return (bool)preg_match('/^7[0-9]{8}$/', $phone) && !preg_match('/^(\d)\1{8}$/', $phone);
}

function valid_admin_password($password)
{
    return mb_strlen((string)$password, 'UTF-8') >= 8 && strlen((string)$password) <= 72;
}

function client_ip()
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function throttle($bucket, $limit, $window)
{
    $pdo = db();
    $now = time();
    // Serialize checks for this bucket so concurrent requests cannot all pass the limit.
    $lock = 'wb:' . substr(hash('sha256', $bucket), 0, 60);
    $st = $pdo->prepare('SELECT GET_LOCK(?, 3)');
    $st->execute([$lock]);
    if ((int)$st->fetchColumn() !== 1) {
        throw new UserError('محاولات كثيرة، انتظر قليلًا ثم حاول مجددًا.');
    }
    try {
        if (random_int(1, 100) === 1) {
            $pdo->prepare('DELETE FROM throttle WHERE created_at < ? LIMIT 500')->execute([$now - 3600]);
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM throttle WHERE bucket = ? AND created_at > ?');
        $st->execute([$bucket, $now - $window]);
        if ((int)$st->fetchColumn() >= $limit) {
            throw new UserError('محاولات كثيرة، انتظر قليلًا ثم حاول مجددًا.');
        }
        $pdo->prepare('INSERT INTO throttle (bucket, created_at) VALUES (?, ?)')->execute([$bucket, $now]);
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
    }
}

function default_settings()
{
    return [
        'store_name'         => 'WOK BOX | ووك بوكس',
        'tagline'            => 'لقمتك... على كيفك',
        'schedule_enabled'   => '0',
        'opens_at'           => '18:00',
        'closes_at'          => '23:30',
        'site_url'           => '',
        'hours'              => 'من 6:00 مساءً حتى 11:30 مساءً',
        'open'               => '1',
        'contact_1'          => '',
        'contact_2'          => '',
        'currency'           => 'ريال',
        'min_order'          => '0',
        'free_delivery_over' => '0',
        'payment_note'       => 'الدفع نقدًا عند الاستلام',
        'logo'               => '',
        'last_backup'        => '',
    ];
}

function settings_all($reset = false)
{
    static $cache = null;
    if ($reset || $cache === null) {
        $cache = default_settings();
        foreach (db()->query('SELECT skey, svalue FROM settings') as $r) {
            $cache[$r['skey']] = $r['svalue'];
        }
    }
    return $cache;
}

function setting($key, $default = '')
{
    $all = settings_all();
    return isset($all[$key]) ? $all[$key] : $default;
}

function set_setting($key, $value)
{
    db()->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)')
        ->execute([$key, (string)$value]);
    settings_all(true);
}

function valid_time($time)
{
    return (bool)preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', (string)$time);
}

function store_is_open(array $settings, $now = null)
{
    if (($settings['open'] ?? '0') !== '1') { return false; }
    if (($settings['schedule_enabled'] ?? '0') !== '1') { return true; }
    $start = $settings['opens_at'] ?? '';
    $end = $settings['closes_at'] ?? '';
    if (!valid_time($start) || !valid_time($end) || $start === $end) { return false; }
    $now = $now === null ? date('H:i') : $now;
    if (!valid_time($now)) { return false; }
    return $start < $end ? ($now >= $start && $now < $end) : ($now >= $start || $now < $end);
}

function public_site_url()
{
    $url = trim((string)setting('site_url', ''));
    if ($url === '') { $url = trim((string)cfg('site_url', '')); }
    if ($url === '') { return ''; }
    $parts = parse_url($url);
    if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])
        || !preg_match('/^[a-z0-9.-]+$/i', $parts['host'])) { return ''; }
    return rtrim($url, '/');
}

function delete_unused_image($name)
{
    if (!valid_image_name($name)) { return; }
    try {
        $st = db()->prepare("SELECT (SELECT COUNT(*) FROM products WHERE image = ?) + (SELECT COUNT(*) FROM ads WHERE image = ?) + (SELECT COUNT(*) FROM settings WHERE skey = 'logo' AND svalue = ?)");
        $st->execute([$name, $name, $name]);
        if ((int)$st->fetchColumn() === 0) { delete_image($name); }
    } catch (Throwable $e) { error_log('[wokbox] image cleanup deferred: ' . $e->getMessage()); }
}

function valid_image_name($name)
{
    return (bool)preg_match('/^[a-f0-9]{16}\.(jpg|png|webp|gif)$/', (string)$name);
}

function delete_image($name)
{
    if (valid_image_name($name)) {
        $path = ROOT . '/uploads/' . $name;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function resize_image($src, $dest, $type, $w, $h, $webp = false)
{
    switch ($type) {
        case IMAGETYPE_JPEG:
            $im = @imagecreatefromjpeg($src);
            break;
        case IMAGETYPE_PNG:
            $im = @imagecreatefrompng($src);
            break;
        case IMAGETYPE_WEBP:
            $im = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false;
            break;
        default:
            return false;
    }
    if (!$im) {
        return false;
    }
    $scale = min(1, 1600 / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $canvas = imagecreatetruecolor($nw, $nh);
    if ($type !== IMAGETYPE_JPEG) {
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
    }
    imagecopyresampled($canvas, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    if ($webp) {
        $ok = imagewebp($canvas, $dest, 88);
    } elseif ($type === IMAGETYPE_JPEG) {
        $ok = imagejpeg($canvas, $dest, 84);
    } elseif ($type === IMAGETYPE_PNG) {
        $ok = imagepng($canvas, $dest, 6);
    } else {
        $ok = function_exists('imagewebp') ? imagewebp($canvas, $dest, 82) : false;
    }
    imagedestroy($im);
    imagedestroy($canvas);
    return (bool)$ok;
}

function save_image($file)
{
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new UserError('فشل رفع الصورة، حاول مرة أخرى.');
    }
    if ($file['size'] > 3 * 1024 * 1024) {
        throw new UserError('حجم الصورة يجب ألا يتجاوز 3 ميغابايت.');
    }
    $info = @getimagesize($file['tmp_name']);
    $map = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($map[$info[2]])) {
        throw new UserError('الملف ليس صورة صالحة (المسموح: JPG, PNG, WEBP, GIF).');
    }
    if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 16000000) {
        throw new UserError('أبعاد الصورة كبيرة جدًا (الحد 16 ميغابكسل).');
    }
    $dir = ROOT . '/uploads';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $ext = $map[$info[2]];
    $webp = $ext !== 'gif' && function_exists('imagecreatetruecolor') && function_exists('imagewebp');
    $name = bin2hex(random_bytes(8)) . '.' . ($webp ? 'webp' : $ext);
    $dest = $dir . '/' . $name;
    $done = false;
    if (function_exists('imagecreatetruecolor') && $ext !== 'gif') {
        $done = resize_image($file['tmp_name'], $dest, $info[2], $info[0], $info[1], $webp);
        if ($done && filesize($dest) >= $file['size'] && max($info[0], $info[1]) <= 1600) {
            @unlink($dest);
            $done = false;
        }
    }
    if (!$done && $webp && $ext !== 'webp') {
        @unlink($dest);
        $name = bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = $dir . '/' . $name;
    }
    if (!$done && !move_uploaded_file($file['tmp_name'], $dest)) {
        throw new UserError('تعذر حفظ الصورة على الخادم.');
    }
    return $name;
}
