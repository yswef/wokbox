<?php
require_once __DIR__ . '/schema.php';

function backup_payload()
{
    ensure_order_requests_table();
    $data = [];
    foreach (backup_tables() as $t) {
        $data[$t] = db()->query("SELECT * FROM `$t`")->fetchAll();
    }
    return ['format' => 'wokbox-backup', 'version' => 1, 'created_at' => date('c'), 'tables' => $data];
}

function build_backup_file($dir)
{
    $payload = backup_payload();
    $referenced = [];
    foreach (['products', 'ads'] as $t) {
        foreach ($payload['tables'][$t] as $row) { if (!empty($row['image'])) { $referenced[] = $row['image']; } }
    }
    foreach ($payload['tables']['settings'] as $row) { if (($row['skey'] ?? '') === 'logo' && !empty($row['svalue'])) { $referenced[] = $row['svalue']; } }
    foreach (array_unique($referenced) as $name) {
        if (!valid_image_name($name) || !is_file(ROOT . '/uploads/' . $name) || filesize(ROOT . '/uploads/' . $name) > 5 * 1024 * 1024) { throw new UserError('تعذر نسخ صورة مرتبطة ببيانات المتجر.'); }
        $bytes = @file_get_contents(ROOT . '/uploads/' . $name);
        if (!backup_image_valid($name, $bytes)) { throw new UserError('إحدى صور المتجر غير صالحة للنسخ.'); }
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) { throw new UserError('تعذر تجهيز بيانات النسخة الاحتياطية.'); }
    if (strlen($json) > 30 * 1024 * 1024 || count(array_unique($referenced)) > 9999) { throw new UserError('بيانات النسخة كبيرة جدًا للاستعادة؛ خذ نسخة من لوحة الاستضافة.'); }
    $total = strlen($json);
    foreach (array_unique($referenced) as $name) { $total += filesize(ROOT . '/uploads/' . $name); }
    if ($total > 150 * 1024 * 1024) { throw new UserError('حجم النسخة المفكوك يتجاوز الحد؛ خذ نسخة من لوحة الاستضافة.'); }
    $base = 'wokbox-backup-' . date('Y-m-d_His') . '-' . bin2hex(random_bytes(4));
    if (class_exists('ZipArchive')) {
        $path = $dir . '/' . $base . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new UserError('تعذر إنشاء ملف النسخة الاحتياطية.');
        }
        $ok = $zip->addFromString('db.json', $json);
        foreach (array_unique($referenced) as $name) {
            $ok = $zip->addFile(ROOT . '/uploads/' . $name, 'uploads/' . $name) && $ok;
        }
        $closed = $zip->close();
        if (!$ok || !$closed || !is_file($path) || filesize($path) === 0 || filesize($path) > 100 * 1024 * 1024) {
            @unlink($path);
            throw new UserError('تعذر حفظ نسخة احتياطية كاملة.');
        }
        return [$path, $base . '.zip', 'application/zip'];
    }
    $path = $dir . '/' . $base . '.json';
    if (file_put_contents($path, $json, LOCK_EX) === false) { throw new UserError('تعذر حفظ النسخة الاحتياطية.'); }
    return [$path, $base . '.json', 'application/json'];
}

function backup_image_valid($name, $bytes)
{
    if (!valid_image_name($name) || !is_string($bytes) || strlen($bytes) > 5 * 1024 * 1024) { return false; }
    $info = @getimagesizefromstring($bytes);
    $types = ['jpg' => IMAGETYPE_JPEG, 'png' => IMAGETYPE_PNG, 'webp' => IMAGETYPE_WEBP, 'gif' => IMAGETYPE_GIF];
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    return $info && isset($types[$ext]) && $info[2] === $types[$ext]
        && $info[0] > 0 && $info[1] > 0 && $info[0] * $info[1] <= 16000000;
}

function restore_backup($tmpPath)
{
    $size = @filesize($tmpPath);
    if ($size === false || $size < 2 || $size > 100 * 1024 * 1024) { throw new UserError('حجم النسخة غير صالح (الحد 100 ميغابايت).'); }
    $handle = fopen($tmpPath, 'rb');
    if (!$handle) { throw new UserError('تعذر قراءة النسخة.'); }
    $signature = fread($handle, 2);
    fclose($handle);
    $files = [];
    $isZip = $signature === 'PK';
    if ($isZip) {
        if (!class_exists('ZipArchive')) { throw new UserError('الخادم لا يدعم ملفات ZIP.'); }
        $zip = new ZipArchive();
        if ($zip->open($tmpPath) !== true) { throw new UserError('ملف ZIP تالف.'); }
        try {
            if ($zip->numFiles > 10000) { throw new UserError('النسخة تحتوي ملفات كثيرة جدًا.'); }
            $total = 0; $json = false; $seen = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!$stat || !isset($stat['name'], $stat['size'])) { throw new UserError('ملف ZIP غير صالح.'); }
                $n = $stat['name']; $length = (int)$stat['size'];
                $total += $length;
                if ($total > 150 * 1024 * 1024 || isset($seen[$n])) { throw new UserError('حجم النسخة المفكوك كبير أو يحتوي ملفات مكررة.'); }
                $seen[$n] = true;
                if ($n === 'db.json') {
                    if ($length > 30 * 1024 * 1024) { throw new UserError('بيانات النسخة كبيرة جدًا.'); }
                    $json = $zip->getFromIndex($i);
                } elseif (preg_match('~^uploads/([a-f0-9]{16}\\.(?:jpg|png|webp|gif))$~', $n, $m)) {
                    if ($length > 5 * 1024 * 1024) { throw new UserError('إحدى صور النسخة كبيرة جدًا.'); }
                    $bytes = $zip->getFromIndex($i);
                    if (!backup_image_valid($m[1], $bytes)) { throw new UserError('إحدى صور النسخة غير صالحة.'); }
                    $files[$m[1]] = $bytes;
                } elseif ($n !== 'uploads/') {
                    throw new UserError('النسخة تحتوي على مسار ملف غير مسموح.');
                }
            }
        } finally { $zip->close(); }
        if ($json === false) { throw new UserError('الملف لا يحتوي على db.json.'); }
    } else {
        if ($size > 30 * 1024 * 1024) { throw new UserError('بيانات النسخة كبيرة جدًا.'); }
        $json = file_get_contents($tmpPath);
    }
    $d = json_decode((string)$json, true);
    if (!is_array($d) || ($d['format'] ?? '') !== 'wokbox-backup' || !isset($d['tables']) || !is_array($d['tables'])) {
        throw new UserError('الملف ليس نسخة احتياطية صالحة من هذا المتجر.');
    }
    // Older backups predate idempotency keys.
    if (!isset($d['tables']['order_requests'])) { $d['tables']['order_requests'] = []; }
    foreach (backup_tables() as $t) {
        if (!isset($d['tables'][$t]) || !is_array($d['tables'][$t])) { throw new UserError('النسخة ناقصة: الجدول ' . $t . ' غير موجود.'); }
        foreach ($d['tables'][$t] as $row) { if (!is_array($row)) { throw new UserError('صف غير صالح في النسخة.'); } }
    }
    // Check that every referenced image is actually available before changing anything.
    $referenced = [];
    foreach (['products', 'ads'] as $t) {
        foreach ($d['tables'][$t] as $row) { if (!empty($row['image'])) { $referenced[] = $row['image']; } }
    }
    foreach ($d['tables']['settings'] as $row) { if (($row['skey'] ?? '') === 'logo' && !empty($row['svalue'])) { $referenced[] = $row['svalue']; } }
    foreach (array_unique($referenced) as $name) {
        if (!valid_image_name($name)) { throw new UserError('اسم صورة غير صالح في النسخة.'); }
        if ($isZip && !isset($files[$name])) { throw new UserError('النسخة لا تحتوي على إحدى الصور المشار إليها.'); }
        if (!$isZip && !is_file(ROOT . '/uploads/' . $name)) { throw new UserError('ملف JSON لا يحتوي الصور المطلوبة؛ استخدم نسخة ZIP.'); }
    }
    $dir = ROOT . '/storage/backups';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) { throw new UserError('تعذر إنشاء مجلد النسخ الاحتياطية.'); }
    build_backup_file($dir);

    // Stage each image under a fresh name. Never overwrite files used by the live database.
    $written = []; $mapping = [];
    $pdo = db();
    try {
        foreach ($files as $name => $bytes) {
            $new = bin2hex(random_bytes(8)) . '.' . pathinfo($name, PATHINFO_EXTENSION);
            $path = ROOT . '/uploads/' . $new;
            if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) { @unlink($path); throw new UserError('تعذر حفظ صور النسخة.'); }
            $written[] = $new; $mapping[$name] = $new;
        }
        $pdo->beginTransaction();
        foreach (backup_tables() as $t) {
            $cols = array_column($pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(), 'Field');
            $pdo->exec("DELETE FROM `$t`");
            foreach ($d['tables'][$t] as $row) {
                $use = array_values(array_intersect(array_keys($row), $cols));
                if (!$use) { throw new UserError('النسخة تحتوي صفًا غير صالح.'); }
                $vals = [];
                foreach ($use as $c) {
                    $v = $row[$c];
                    if (($c === 'image' || ($t === 'settings' && $c === 'svalue' && ($row['skey'] ?? '') === 'logo')) && is_string($v) && isset($mapping[$v])) { $v = $mapping[$v]; }
                    $vals[] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v;
                }
                $sql = "INSERT INTO `$t` (`" . implode('`,`', $use) . '`) VALUES (' . implode(',', array_fill(0, count($use), '?')) . ')';
                $pdo->prepare($sql)->execute($vals);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        foreach ($written as $name) { delete_image($name); }
        throw $e;
    }
    settings_all(true);
}
