<?php
require_once __DIR__ . '/schema.php';

function backup_payload()
{
    $data = [];
    foreach (backup_tables() as $t) {
        $data[$t] = db()->query("SELECT * FROM `$t`")->fetchAll();
    }
    return [
        'format'     => 'wokbox-backup',
        'version'    => 1,
        'created_at' => date('c'),
        'tables'     => $data,
    ];
}

function build_backup_file($dir)
{
    $json = json_encode(
        backup_payload(),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR
    );
    $base = 'wokbox-backup-' . date('Y-m-d_His');
    if (class_exists('ZipArchive')) {
        $path = $dir . '/' . $base . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $zip->addFromString('db.json', $json);
            foreach (glob(ROOT . '/uploads/*') ?: [] as $f) {
                if (is_file($f) && valid_image_name(basename($f))) {
                    $zip->addFile($f, 'uploads/' . basename($f));
                }
            }
            $zip->close();
            return [$path, $base . '.zip', 'application/zip'];
        }
    }
    $path = $dir . '/' . $base . '.json';
    file_put_contents($path, $json);
    return [$path, $base . '.json', 'application/json'];
}

function restore_backup($tmpPath)
{
    $raw = file_get_contents($tmpPath);
    $files = [];
    if (substr($raw, 0, 2) === 'PK') {
        if (!class_exists('ZipArchive')) {
            throw new UserError('الخادم لا يدعم ملفات ZIP. استخدم ملف النسخة بصيغة JSON.');
        }
        $zip = new ZipArchive();
        if ($zip->open($tmpPath) !== true) {
            throw new UserError('ملف ZIP تالف.');
        }
        $json = $zip->getFromName('db.json');
        if ($json === false) {
            $zip->close();
            throw new UserError('الملف لا يحتوي على db.json.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (strpos($n, 'uploads/') === 0 && valid_image_name(basename($n))) {
                $files[basename($n)] = $zip->getFromIndex($i);
            }
        }
        $zip->close();
    } else {
        $json = $raw;
    }

    $d = json_decode($json, true);
    if (!is_array($d) || ($d['format'] ?? '') !== 'wokbox-backup' || !isset($d['tables']) || !is_array($d['tables'])) {
        throw new UserError('الملف ليس نسخة احتياطية صالحة من هذا المتجر.');
    }
    foreach (backup_tables() as $t) {
        if (!isset($d['tables'][$t]) || !is_array($d['tables'][$t])) {
            throw new UserError('النسخة ناقصة: الجدول ' . $t . ' غير موجود.');
        }
    }

    $dir = ROOT . '/storage/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    build_backup_file($dir);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (backup_tables() as $t) {
            $cols = array_column($pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(), 'Field');
            $pdo->exec("DELETE FROM `$t`");
            foreach ($d['tables'][$t] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $use = array_values(array_intersect(array_keys($row), $cols));
                if (!$use) {
                    continue;
                }
                $vals = [];
                foreach ($use as $c) {
                    $v = $row[$c];
                    if (($c === 'image' || ($t === 'settings' && $c === 'svalue' && ($row['skey'] ?? '') === 'logo'))
                        && $v !== '' && $v !== null && !valid_image_name((string)$v)) {
                        $v = '';
                    }
                    $vals[] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v;
                }
                $sql = "INSERT INTO `$t` (`" . implode('`,`', $use) . '`) VALUES (' . implode(',', array_fill(0, count($use), '?')) . ')';
                $pdo->prepare($sql)->execute($vals);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    $up = ROOT . '/uploads';
    if (!is_dir($up)) {
        @mkdir($up, 0755, true);
    }
    foreach ($files as $name => $bytes) {
        file_put_contents($up . '/' . $name, $bytes);
    }
    settings_all(true);
}
