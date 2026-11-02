<?php
// فحص ذاتي للتثبيت. يتطلب تسجيل دخول الإدارة. يكتب التقرير في storage/selftest-report.txt
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/schema.php';
start_session();
if (empty($_SESSION['admin'])) {
    http_response_code(401);
    exit('سجّل الدخول من لوحة الإدارة أولًا ثم افتح هذه الصفحة.');
}
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$lines = [];
$add = function ($ok, $label, $detail = '') use (&$lines) {
    $lines[] = ($ok ? '[ OK ] ' : '[FAIL] ') . $label . ($detail !== '' ? ' — ' . $detail : '');
};

$add(version_compare(PHP_VERSION, '7.4', '>='), 'PHP >= 7.4', PHP_VERSION);
foreach (['pdo_mysql', 'mbstring', 'json', 'gd', 'zip', 'fileinfo'] as $ext) {
    $add(extension_loaded($ext), 'امتداد ' . $ext, extension_loaded($ext) ? '' : ($ext === 'gd' || $ext === 'zip' ? 'اختياري: ميزة محدودة بدونه' : 'مطلوب'));
}
foreach (['uploads', 'storage', 'storage/backups'] as $d) {
    $p = ROOT . '/' . $d;
    $add(is_dir($p) && is_writable($p), 'مجلد قابل للكتابة: ' . $d);
}
try {
    $pdo = db();
    $add(true, 'الاتصال بقاعدة البيانات');
    foreach (backup_tables() as $t) {
        try {
            $c = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            $add(true, 'جدول ' . $t, $c . ' صف');
        } catch (Throwable $e) {
            $add(false, 'جدول ' . $t, $e->getMessage());
        }
    }
    $add(true, 'MySQL version', (string)$pdo->query('SELECT VERSION()')->fetchColumn());
} catch (Throwable $e) {
    $add(false, 'الاتصال بقاعدة البيانات', $e->getMessage());
}
$add(file_exists(ROOT . '/install.lock'), 'ملف install.lock موجود');
$add(!file_exists(ROOT . '/install.php'), 'تم حذف install.php (موصى به)');
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$add($https, 'الاتصال عبر HTTPS', $https ? '' : 'فعّل SSL من لوحة الاستضافة');

$report = date('c') . "\n" . implode("\n", $lines) . "\n";
@file_put_contents(ROOT . '/storage/selftest-report.txt', $report);
echo $report;
