<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/schema.php';

header('Content-Type: text/html; charset=utf-8');

$lock = ROOT . '/install.lock';
$error = '';
$done = false;

function page($title, $body)
{
    echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . htmlspecialchars($title) . '</title><style>'
        . 'body{margin:0;background:#fff7f0;color:#332720;font-family:Tahoma,Arial,sans-serif;line-height:1.7;display:grid;place-items:center;min-height:100vh;padding:20px}'
        . '.box{background:#fff;border:1px solid #f0dfd0;border-radius:18px;padding:26px;width:min(460px,100%)}'
        . 'h1{margin:0 0 6px;font-size:22px}p{color:#7a6a5d;margin:0 0 16px;font-size:14px}label{display:block;font-weight:700;font-size:13px;margin:12px 0 5px}'
        . 'input{width:100%;padding:11px;border:1px solid #e6d3c1;border-radius:10px;font:inherit;box-sizing:border-box}'
        . 'button{margin-top:18px;width:100%;padding:12px;border:0;border-radius:12px;background:#f47732;color:#fff;font:inherit;font-weight:800;cursor:pointer}'
        . '.err{background:#fde8e6;border:1px solid #f3b8b2;color:#8a2a20;padding:10px 12px;border-radius:10px;font-size:13px;margin-bottom:10px}'
        . '.ok{background:#e6f5ec;border:1px solid #b5dfc4;color:#1c5a37;padding:12px;border-radius:10px;font-size:14px}'
        . 'a{color:#d9652c;font-weight:700}</style></head><body><div class="box">' . $body . '</div></body></html>';
    exit;
}

if (file_exists($lock)) {
    page('التثبيت مكتمل', '<h1>التثبيت مكتمل</h1><p>تم تثبيت المتجر مسبقًا. لأسباب أمنية احذف الملف <b>install.php</b> من الخادم.</p><a href="index.php">فتح المتجر</a> · <a href="admin/">لوحة الإدارة</a>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim((string)($_POST['username'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    $store = clean_text($_POST['store_name'] ?? '', 100);
    try {
        if (!preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $user)) {
            throw new UserError('اسم المستخدم: 3 إلى 30 حرفًا إنجليزيًا أو أرقامًا.');
        }
        if (!valid_admin_password($pass)) {
            throw new UserError('كلمة المرور يجب ألا تقل عن 8 أحرف أو أرقام ولا تزيد عن 72 بايت.');
        }
        if ($pass !== (string)($_POST['password2'] ?? '')) {
            throw new UserError('كلمتا المرور غير متطابقتين.');
        }
        try {
            $pdo = db();
        } catch (Throwable $e) {
            throw new UserError('تعذر الاتصال بقاعدة البيانات. راجع القيم في ملف config.php.');
        }
        foreach (schema_statements() as $sql) {
            $pdo->exec($sql);
        }
        $count = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
        if ($count === 0) {
            $pdo->prepare('INSERT INTO admins (username, password_hash, created_at) VALUES (?,?,?)')
                ->execute([$user, password_hash($pass, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
        }
        foreach (default_settings() as $k => $v) {
            $pdo->prepare('INSERT IGNORE INTO settings (skey, svalue) VALUES (?, ?)')->execute([$k, $v]);
        }
        if ($store !== '') {
            set_setting('store_name', $store);
        }
        if ((int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn() === 0) {
            $cat = $pdo->prepare('INSERT INTO categories (name, emoji, sort_order) VALUES (?,?,?)');
            $ids = [];
            foreach ([['الإندومي', '🍜', 1], ['الإضافات', '🧀', 2], ['المشروبات', '🥤', 3]] as $c) {
                $cat->execute($c);
                $ids[$c[0]] = (int)$pdo->lastInsertId();
            }
            $menu = [
                ['إندومي سادة سفري', 'وجبة بسيطة ولذيذة', 500, 'الإندومي', '🍜'],
                ['إندومي سادة مغلف', 'جاهز للاستمتاع', 600, 'الإندومي', '🥡'],
                ['إندومي بالجبنة المثلثات', 'لمسة جبنة كريمية', 600, 'الإندومي', '🧀'],
                ['إندومي شيدر', 'نكهة شيدر غنية', 700, 'الإندومي', '🧀'],
                ['مكشن', 'إضافة لذيذة لوجبتك', 700, 'الإضافات', '🍳'],
                ['صافي', 'إضافة حسب اختيارك', 400, 'الإضافات', '🍽️'],
                ['جبن أبو مثلث', 'إضافة جبنة', 600, 'الإضافات', '🧀'],
                ['جبن سائل', 'جبنة بطعم غني', 700, 'الإضافات', '🫕'],
                ['صوصات', 'أضف نكهتك المفضلة', 600, 'الإضافات', '🥫'],
                ['زبادي', 'إضافة جانبية', 600, 'الإضافات', '🥣'],
                ['نسكافيه', 'مشروب ساخن', 500, 'المشروبات', '☕'],
            ];
            $ins = $pdo->prepare('INSERT INTO products (category_id, name, description, price, emoji, image, available, sort_order, created_at) VALUES (?,?,?,?,?,?,?,?,?)');
            foreach ($menu as $i => $m) {
                $ins->execute([$ids[$m[3]], $m[0], $m[1], $m[2], $m[4], '', 1, $i + 1, date('Y-m-d H:i:s')]);
            }
            set_setting('contact_1', '967780790786');
            set_setting('contact_2', '967782141051');
        }
        if (@file_put_contents($lock, date('c')) === false) {
            throw new UserError('تم إنشاء الجداول لكن تعذر إنشاء ملف install.lock. أنشئه يدويًا في المجلد الرئيسي.');
        }
        $done = true;
    } catch (UserError $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('[wokbox install] ' . $e->getMessage());
        $error = 'حدث خطأ أثناء التثبيت. تحقق من إعدادات قاعدة البيانات وصلاحياتها.';
    }
}

if ($done) {
    page('تم التثبيت', '<h1>تم التثبيت بنجاح</h1><div class="ok">أُنشئت الجداول وحساب الإدارة وبيانات المنيو الأولية.<br><b>احذف الملف install.php الآن</b> من الخادم.</div><p style="margin-top:14px"><a href="admin/">الدخول إلى لوحة الإدارة</a> · <a href="index.php">فتح المتجر</a></p>');
}

$err = $error ? '<div class="err">' . htmlspecialchars($error) . '</div>' : '';
page('تثبيت المتجر', '<h1>تثبيت المتجر</h1><p>أدخل بيانات حساب الإدارة. تأكد أولًا من تعبئة بيانات قاعدة البيانات في config.php.</p>' . $err
    . '<form method="post" autocomplete="off">'
    . '<label>اسم المتجر</label><input name="store_name" value="' . htmlspecialchars($_POST['store_name'] ?? 'WOK BOX | ووك بوكس') . '">'
    . '<label>اسم مستخدم الإدارة (إنجليزي)</label><input name="username" value="' . htmlspecialchars($_POST['username'] ?? 'admin') . '" dir="ltr">'
    . '<label>كلمة المرور (8 خانات فأكثر)</label><input type="password" name="password" dir="ltr">'
    . '<label>تأكيد كلمة المرور</label><input type="password" name="password2" dir="ltr">'
    . '<button>تثبيت</button></form>');
