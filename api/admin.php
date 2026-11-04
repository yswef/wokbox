<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/shop.php';
require __DIR__ . '/../lib/backup.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const ORDER_STATUSES = ['new', 'confirmed', 'preparing', 'ready', 'out', 'delivered', 'cancelled'];

function inp($k, $d = '')
{
    return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $d;
}

function inp_int($k, $d = 0)
{
    return (isset($_POST[$k]) && $_POST[$k] !== '') ? (int)$_POST[$k] : $d;
}

function req_text($k, $max, $label)
{
    $v = clean_text(inp($k), $max);
    if ($v === '') {
        throw new UserError($label . ' مطلوب.');
    }
    return $v;
}

function cap_int($v, $max, $label)
{
    if ($v > $max) {
        throw new UserError($label . ' كبيرة جدًا (الحد الأقصى ' . number_format($max) . ').');
    }
    return $v;
}

function opt_date($k)
{
    $v = inp($k);
    if ($v === '') {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) {
        throw new UserError('تاريخ غير صالح.');
    }
    return $v;
}

function exists_row($table, $id)
{
    $st = db()->prepare("SELECT 1 FROM `$table` WHERE id = ?");
    $st->execute([$id]);
    return (bool)$st->fetchColumn();
}

function has_upload($key)
{
    return !empty($_FILES[$key]['name']) && ($_FILES[$key]['error'] ?? 0) !== UPLOAD_ERR_NO_FILE;
}

function is_admin()
{
    return !empty($_SESSION['admin']);
}

function need_admin()
{
    if (!is_admin()) {
        fail('انتهت الجلسة، سجّل الدخول من جديد.', 401);
    }
    $st = db()->prepare('SELECT 1 FROM admins WHERE id = ?');
    $st->execute([$_SESSION['admin']]);
    if (!$st->fetchColumn()) { $_SESSION = []; session_destroy(); fail('انتهت الجلسة، سجّل الدخول من جديد.', 401); }
    $sent = (string)($_POST['csrf'] ?? '');
    if ($sent === '' || !hash_equals((string)($_SESSION['csrf'] ?? ''), $sent)) {
        fail('رمز الأمان غير صالح، حدّث الصفحة.', 403);
    }
}

function rows_with_ints(array $rows, array $intKeys)
{
    foreach ($rows as &$r) {
        foreach ($intKeys as $k) {
            if (isset($r[$k]) && $r[$k] !== null) {
                $r[$k] = (int)$r[$k];
            }
        }
    }
    return $rows;
}

function orders_with_items(array $orders)
{
    if (!$orders) {
        return [];
    }
    $ids = array_column($orders, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT order_id, name, unit_price, qty FROM order_items WHERE order_id IN ($in) ORDER BY id");
    $st->execute($ids);
    $map = [];
    foreach ($st as $it) {
        $map[(int)$it['order_id']][] = ['name' => $it['name'], 'unit_price' => (int)$it['unit_price'], 'qty' => (int)$it['qty']];
    }
    foreach ($orders as &$o) {
        $o['items'] = $map[(int)$o['id']] ?? [];
        foreach (['id', 'subtotal', 'discount', 'delivery_fee', 'total', 'cancel_requested'] as $k) {
            $o[$k] = (int)$o[$k];
        }
        unset($o['ip']);
    }
    return $orders;
}

start_session();
$action = $_POST['action'] ?? '';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fail('طريقة الطلب غير مسموحة.', 405);
    }
    $pdo = db();

    if ($action === 'login') {
        throttle('login:' . client_ip(), 10, 900);
        $st = $pdo->prepare('SELECT * FROM admins WHERE username = ?');
        $st->execute([inp('username')]);
        $u = $st->fetch();
        if (!$u || !password_verify((string)($_POST['password'] ?? ''), $u['password_hash'])) {
            throw new UserError('اسم المستخدم أو كلمة المرور غير صحيحة.');
        }
        session_regenerate_id(true);
        $_SESSION['admin'] = (int)$u['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        out(['csrf' => $_SESSION['csrf'], 'username' => $u['username']]);
    }
    if ($action === 'me') {
        if (!is_admin()) {
            fail('غير مسجل', 401);
        }
        $st = $pdo->prepare('SELECT username FROM admins WHERE id = ?');
        $st->execute([$_SESSION['admin']]);
        $username = $st->fetchColumn();
        if ($username === false) { $_SESSION = []; session_destroy(); fail('غير مسجل', 401); }
        out(['csrf' => $_SESSION['csrf'], 'username' => $username]);
    }

    need_admin();
    // Any admin change may alter the public menu: drop the cache now and again when the request ends.
    if (!in_array($action, ['logout', 'products_list', 'categories_list', 'offers_list', 'ads_list', 'zones_list', 'coupons_list', 'orders_list', 'order_status', 'order_reject_cancel', 'poll', 'customers_list', 'stats', 'admins_list', 'settings_get', 'backup_download'], true)) {
        menu_cache_clear();
        register_shutdown_function('menu_cache_clear');
    }

    switch ($action) {
        case 'logout':
            $_SESSION = [];
            session_destroy();
            out(true);
            break;

        /* ---------- المنتجات ---------- */
        case 'products_list':
            out([
                'products' => rows_with_ints($pdo->query('SELECT * FROM products ORDER BY category_id, sort_order, id')->fetchAll(),
                    ['id', 'category_id', 'price', 'available', 'sort_order']),
                'categories' => rows_with_ints($pdo->query('SELECT * FROM categories ORDER BY sort_order, id')->fetchAll(), ['id', 'sort_order']),
            ]);
            break;

        case 'product_save':
            $id = inp_int('id');
            $name = req_text('name', 120, 'اسم المنتج');
            $price = inp_int('price', -1);
            if ($price < 0) {
                throw new UserError('أدخل سعرًا صحيحًا.');
            }
            cap_int($price, 10000000, 'القيمة');
            $cat = inp_int('category_id');
            if (!exists_row('categories', $cat)) {
                throw new UserError('اختر قسمًا صالحًا.');
            }
            $desc = clean_text(inp('description'), 255);
            $emoji = clean_text(inp('emoji'), 16);
            $avail = inp_int('available', 1) ? 1 : 0;
            $sort = max(-100000, min(100000, inp_int('sort_order', 0)));
            $old = null;
            $newImage = '';
            if (has_upload('image')) { $newImage = save_image($_FILES['image']); }
            try {
                $pdo->beginTransaction();
                if ($id) {
                    $st = $pdo->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
                    $st->execute([$id]);
                    $old = $st->fetch();
                    if (!$old) { throw new UserError('السجل غير موجود.'); }
                }
                $image = $newImage !== '' ? $newImage : ($old ? $old['image'] : '');
                if ($newImage === '' && $old && inp_int('remove_image')) { $image = ''; }
                if ($old) {
                    $pdo->prepare('UPDATE products SET category_id=?, name=?, description=?, price=?, emoji=?, image=?, available=?, sort_order=? WHERE id=?')
                        ->execute([$cat, $name, $desc, $price, $emoji, $image, $avail, $sort, $id]);
                } else {
                    $pdo->prepare('INSERT INTO products (category_id, name, description, price, emoji, image, available, sort_order, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
                        ->execute([$cat, $name, $desc, $price, $emoji, $image, $avail, $sort, date('Y-m-d H:i:s')]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                if ($newImage !== '') { delete_image($newImage); }
                throw $e;
            }
            if ($old && $old['image'] !== $image) { delete_unused_image($old['image']); }
            out(true);
            break;

        case 'product_toggle':
            $pdo->prepare('UPDATE products SET available = 1 - available WHERE id = ?')->execute([inp_int('id')]);
            out(true);
            break;

        case 'product_delete':
            $id = inp_int('id');
            $st = $pdo->prepare('SELECT image FROM products WHERE id = ?');
            $st->execute([$id]);
            $img = $st->fetchColumn();
            if ($img === false) {
                throw new UserError('المنتج غير موجود.');
            }
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM offers WHERE product_id = ?')->execute([$id]);
                $pdo->prepare('UPDATE ads SET product_id = NULL WHERE product_id = ?')->execute([$id]);
                $pdo->commit();
            } catch (Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $e; }
            delete_unused_image((string)$img);
            out(true);
            break;

        /* ---------- الأقسام ---------- */
        case 'categories_list':
            out(rows_with_ints($pdo->query(
                'SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS products_count
                 FROM categories c ORDER BY c.sort_order, c.id'
            )->fetchAll(), ['id', 'sort_order', 'products_count']));
            break;

        case 'category_save':
            $id = inp_int('id');
            $name = req_text('name', 80, 'اسم القسم');
            $emoji = clean_text(inp('emoji'), 16);
            $sort = max(-100000, min(100000, inp_int('sort_order', 0)));
            if ($id) {
                if (!exists_row('categories', $id)) {
                    throw new UserError('القسم غير موجود.');
                }
                $pdo->prepare('UPDATE categories SET name=?, emoji=?, sort_order=? WHERE id=?')->execute([$name, $emoji, $sort, $id]);
            } else {
                $pdo->prepare('INSERT INTO categories (name, emoji, sort_order) VALUES (?,?,?)')->execute([$name, $emoji, $sort]);
            }
            out(true);
            break;

        case 'category_delete':
            $id = inp_int('id');
            $st = $pdo->prepare('SELECT id FROM categories WHERE id <> ? ORDER BY sort_order, id LIMIT 1');
            $st->execute([$id]);
            $target = $st->fetchColumn();
            if (!$target) {
                throw new UserError('يجب الإبقاء على قسم واحد على الأقل.');
            }
            $pdo->prepare('UPDATE products SET category_id = ? WHERE category_id = ?')->execute([$target, $id]);
            $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
            out(true);
            break;

        /* ---------- العروض ---------- */
        case 'offers_list':
            out(rows_with_ints($pdo->query('SELECT * FROM offers ORDER BY id DESC')->fetchAll(), ['id', 'value', 'product_id', 'active']));
            break;

        case 'offer_save':
            $id = inp_int('id');
            $name = req_text('name', 120, 'اسم العرض');
            $type = inp('type') === 'fixed' ? 'fixed' : 'percent';
            $value = inp_int('value');
            if ($value <= 0 || ($type === 'percent' && $value > 100)) {
                throw new UserError('قيمة الخصم غير صحيحة (النسبة من 1 إلى 100).');
            }
            cap_int($value, 10000000, 'قيمة الخصم');
            $product = inp('product_id') === '' || inp('product_id') === 'all' ? null : inp_int('product_id');
            if ($product !== null && !exists_row('products', $product)) {
                throw new UserError('المنتج المحدد غير موجود.');
            }
            $start = opt_date('starts_on');
            $end = opt_date('ends_on');
            if ($start && $end && $start > $end) {
                throw new UserError('تاريخ النهاية يجب أن يكون بعد البداية.');
            }
            $active = inp_int('active', 1) ? 1 : 0;
            if ($id) {
                $pdo->prepare('UPDATE offers SET name=?, type=?, value=?, product_id=?, starts_on=?, ends_on=?, active=? WHERE id=?')
                    ->execute([$name, $type, $value, $product, $start, $end, $active, $id]);
            } else {
                $pdo->prepare('INSERT INTO offers (name, type, value, product_id, starts_on, ends_on, active) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$name, $type, $value, $product, $start, $end, $active]);
            }
            out(true);
            break;

        case 'offer_toggle':
            $pdo->prepare('UPDATE offers SET active = 1 - active WHERE id = ?')->execute([inp_int('id')]);
            out(true);
            break;

        case 'offer_delete':
            $pdo->prepare('DELETE FROM offers WHERE id = ?')->execute([inp_int('id')]);
            out(true);
            break;

        /* ---------- الإعلانات ---------- */
        case 'ads_list':
            out(rows_with_ints($pdo->query('SELECT * FROM ads ORDER BY id DESC')->fetchAll(), ['id', 'product_id', 'active']));
            break;

        case 'ad_save':
            $id = inp_int('id');
            $title = req_text('title', 160, 'عنوان الإعلان');
            $desc = clean_text(inp('description'), 1000);
            $product = inp('product_id') === '' ? null : inp_int('product_id');
            if ($product !== null && !exists_row('products', $product)) {
                $product = null;
            }
            $active = inp_int('active', 1) ? 1 : 0;
            $old = null;
            $newImage = '';
            if (has_upload('image')) { $newImage = save_image($_FILES['image']); }
            try {
                $pdo->beginTransaction();
                if ($id) {
                    $st = $pdo->prepare('SELECT * FROM ads WHERE id = ? FOR UPDATE');
                    $st->execute([$id]);
                    $old = $st->fetch();
                    if (!$old) { throw new UserError('السجل غير موجود.'); }
                }
                $image = $newImage !== '' ? $newImage : ($old ? $old['image'] : '');
                if ($newImage === '' && $old && inp_int('remove_image')) { $image = ''; }
                if ($old) {
                    $pdo->prepare('UPDATE ads SET title=?, description=?, image=?, product_id=?, active=? WHERE id=?')
                        ->execute([$title, $desc, $image, $product, $active, $id]);
                } else {
                    $pdo->prepare('INSERT INTO ads (title, description, image, product_id, active, created_at) VALUES (?,?,?,?,?,?)')
                        ->execute([$title, $desc, $image, $product, $active, date('Y-m-d H:i:s')]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                if ($newImage !== '') { delete_image($newImage); }
                throw $e;
            }
            if ($old && $old['image'] !== $image) { delete_unused_image($old['image']); }
            out(true);
            break;

        case 'ad_toggle':
            $pdo->prepare('UPDATE ads SET active = 1 - active WHERE id = ?')->execute([inp_int('id')]);
            out(true);
            break;

        case 'ad_delete':
            $st = $pdo->prepare('SELECT image FROM ads WHERE id = ?');
            $st->execute([inp_int('id')]);
            $img = $st->fetchColumn();
            $pdo->prepare('DELETE FROM ads WHERE id = ?')->execute([inp_int('id')]);
            if ($img !== false) { delete_unused_image((string)$img); }
            out(true);
            break;

        /* ---------- مناطق التوصيل ---------- */
        case 'zones_list':
            out(rows_with_ints($pdo->query('SELECT * FROM zones ORDER BY name')->fetchAll(), ['id', 'fee', 'active']));
            break;

        case 'zone_save':
            $id = inp_int('id');
            $name = req_text('name', 100, 'اسم المنطقة');
            $fee = inp_int('fee', 0);
            if ($fee < 0) {
                throw new UserError('رسوم التوصيل غير صحيحة.');
            }
            cap_int($fee, 10000000, 'رسوم التوصيل');
            $active = inp_int('active', 1) ? 1 : 0;
            if ($id) {
                $pdo->prepare('UPDATE zones SET name=?, fee=?, active=? WHERE id=?')->execute([$name, $fee, $active, $id]);
            } else {
                $pdo->prepare('INSERT INTO zones (name, fee, active) VALUES (?,?,?)')->execute([$name, $fee, $active]);
            }
            out(true);
            break;

        case 'zone_toggle':
            $pdo->prepare('UPDATE zones SET active = 1 - active WHERE id = ?')->execute([inp_int('id')]);
            out(true);
            break;

        case 'zone_delete':
            $pdo->prepare('DELETE FROM zones WHERE id = ?')->execute([inp_int('id')]);
            out(true);
            break;

        /* ---------- أكواد الخصم ---------- */
        case 'coupons_list':
            out(rows_with_ints($pdo->query('SELECT * FROM coupons ORDER BY id DESC')->fetchAll(),
                ['id', 'value', 'min_order', 'max_uses', 'used_count', 'active']));
            break;

        case 'coupon_save':
            $id = inp_int('id');
            $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', inp('code')));
            if (strlen($code) < 3 || strlen($code) > 40) {
                throw new UserError('الكود: 3 إلى 40 حرفًا إنجليزيًا أو أرقامًا.');
            }
            $type = inp('type') === 'fixed' ? 'fixed' : 'percent';
            $value = inp_int('value');
            if ($value <= 0 || ($type === 'percent' && $value > 100)) {
                throw new UserError('قيمة الخصم غير صحيحة (النسبة من 1 إلى 100).');
            }
            cap_int($value, 10000000, 'قيمة الخصم');
            $min = cap_int(max(0, inp_int('min_order', 0)), 100000000, 'الحد الأدنى');
            $max = inp('max_uses') === '' ? null : cap_int(max(1, inp_int('max_uses')), 10000000, 'عدد مرات الاستخدام');
            $exp = opt_date('expires_on');
            $active = inp_int('active', 1) ? 1 : 0;
            $dup = $pdo->prepare('SELECT id FROM coupons WHERE code = ? AND id <> ?');
            $dup->execute([$code, $id]);
            if ($dup->fetchColumn()) {
                throw new UserError('هذا الكود مستخدم بالفعل.');
            }
            if ($id) {
                $pdo->prepare('UPDATE coupons SET code=?, type=?, value=?, min_order=?, max_uses=?, expires_on=?, active=? WHERE id=?')
                    ->execute([$code, $type, $value, $min, $max, $exp, $active, $id]);
            } else {
                $pdo->prepare('INSERT INTO coupons (code, type, value, min_order, max_uses, used_count, expires_on, active) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([$code, $type, $value, $min, $max, 0, $exp, $active]);
            }
            out(true);
            break;

        case 'coupon_toggle':
            $pdo->prepare('UPDATE coupons SET active = 1 - active WHERE id = ?')->execute([inp_int('id')]);
            out(true);
            break;

        case 'coupon_delete':
            $pdo->prepare('DELETE FROM coupons WHERE id = ?')->execute([inp_int('id')]);
            out(true);
            break;

        /* ---------- الطلبات ---------- */
        case 'orders_list':
            $where = [];
            $args = [];
            $status = inp('status');
            if ($status !== '' && in_array($status, ORDER_STATUSES, true)) {
                $where[] = 'status = ?';
                $args[] = $status;
            }
            if (inp('cancel_requested') === '1') {
                $where[] = 'cancel_requested = 1';
            }
            $q = clean_text(inp('q'), 60);
            if ($q !== '') {
                $where[] = '(code LIKE ? OR customer_name LIKE ? OR phone LIKE ?)';
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
                array_push($args, $like, $like, $like);
            }
            if (($from = opt_date('from'))) {
                $where[] = 'created_at >= ?';
                $args[] = $from . ' 00:00:00';
            }
            if (($to = opt_date('to'))) {
                $where[] = 'created_at <= ?';
                $args[] = $to . ' 23:59:59';
            }
            $sql = 'SELECT * FROM orders' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT 200';
            $st = $pdo->prepare($sql);
            $st->execute($args);
            $counts = [];
            foreach ($pdo->query('SELECT status, COUNT(*) c FROM orders GROUP BY status') as $r) {
                $counts[$r['status']] = (int)$r['c'];
            }
            $cr = (int)$pdo->query('SELECT COUNT(*) FROM orders WHERE cancel_requested = 1 AND status <> \'cancelled\'')->fetchColumn();
            out(['orders' => orders_with_items($st->fetchAll()), 'counts' => $counts, 'cancel_requests' => $cr]);
            break;

        case 'order_status':
            $id = inp_int('id');
            $status = inp('status');
            if (!in_array($status, ORDER_STATUSES, true)) {
                throw new UserError('حالة غير صالحة.');
            }
            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare('SELECT status, coupon_code, cancel_requested FROM orders WHERE id = ? FOR UPDATE');
                $st->execute([$id]);
                $o = $st->fetch();
                if (!$o) { throw new UserError('الطلب غير موجود.'); }
                $expected = inp('expected_status');
                if ($expected !== '' && $expected !== $o['status']) {
                    throw new UserError('غيّر مدير آخر حالة الطلب. حدّث القائمة أولًا.');
                }
                if ($status !== $o['status'] && !in_array($status, order_next_statuses($o['status']), true)) {
                    throw new UserError('انتقال الحالة غير مسموح. حدّث القائمة واختر المرحلة التالية.');
                }
                if ($status !== $o['status'] && $status !== 'cancelled' && (int)$o['cancel_requested']) {
                    throw new UserError('يوجد طلب إلغاء، اقبله أو ارفضه قبل تغيير الحالة.');
                }
                if ($status !== $o['status']) {
                    $pdo->prepare('UPDATE orders SET status = ?, cancel_requested = 0, updated_at = ? WHERE id = ?')
                        ->execute([$status, date('Y-m-d H:i:s'), $id]);
                    if ($status === 'cancelled' && $o['coupon_code'] !== '') {
                        $pdo->prepare('UPDATE coupons SET used_count = GREATEST(0, used_count - 1) WHERE code = ?')->execute([$o['coupon_code']]);
                    }
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                throw $e;
            }
            out(true);
            break;

        case 'order_reject_cancel':
            $st = $pdo->prepare("UPDATE orders SET cancel_requested = 0, updated_at = ? WHERE id = ? AND status = 'new' AND cancel_requested = 1");
            $st->execute([date('Y-m-d H:i:s'), inp_int('id')]);
            if (!$st->rowCount()) { throw new UserError('تغير الطلب أو لم يعد طلب الإلغاء موجودًا. حدّث القائمة.'); }
            out(true);
            break;

        case 'poll':
            $since = inp_int('since', 0);
            $max = (int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM orders')->fetchColumn();
            $st = $pdo->prepare('SELECT id, code, customer_name, total FROM orders WHERE id > ? ORDER BY id DESC LIMIT 5');
            $st->execute([$since]);
            $latest = $st->fetchAll();
            $cnt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE id > ?');
            $cnt->execute([$since]);
            out([
                'max_id'     => $max,
                'new_count'  => (int)$cnt->fetchColumn(),
                'pending'    => (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'new'")->fetchColumn(),
                'cancel_req' => (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE cancel_requested = 1 AND status <> 'cancelled'")->fetchColumn(),
                'latest'     => $latest,
            ]);
            break;

        /* ---------- العملاء ---------- */
        case 'customers_list':
            $q = clean_text(inp('q'), 60);
            $sort = inp('sort', 'orders');
            $order = ['spend' => 'spent DESC', 'recent' => 'last_order DESC', 'name' => 'name ASC'][$sort] ?? 'orders_count DESC, spent DESC';
            $args = [];
            $having = '';
            if ($q !== '') {
                $having = 'HAVING name LIKE ? OR phone LIKE ?';
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
                $args = [$like, $like];
            }
            $st = $pdo->prepare(
                "SELECT phone,
                        SUBSTRING_INDEX(GROUP_CONCAT(customer_name ORDER BY id DESC SEPARATOR '||'), '||', 1) AS name,
                        COUNT(*) AS orders_count,
                        SUM(CASE WHEN status <> 'cancelled' THEN total ELSE 0 END) AS spent,
                        MAX(created_at) AS last_order
                 FROM orders GROUP BY phone $having ORDER BY $order LIMIT 300"
            );
            $st->execute($args);
            out(rows_with_ints($st->fetchAll(), ['orders_count', 'spent']));
            break;

        /* ---------- الإحصائيات والتقارير ---------- */
        case 'stats':
            $today = date('Y-m-d');
            $month = date('Y-m-01');
            $sum = function ($since) use ($pdo) {
                $st = $pdo->prepare("SELECT COALESCE(SUM(total),0) s, COUNT(*) c FROM orders WHERE status <> 'cancelled' AND created_at >= ?");
                $st->execute([$since . ' 00:00:00']);
                $r = $st->fetch();
                return ['sales' => (int)$r['s'], 'orders' => (int)$r['c']];
            };
            $all = $pdo->query("SELECT COALESCE(SUM(total),0) s, COUNT(*) c FROM orders WHERE status <> 'cancelled'")->fetch();
            $days = $pdo->prepare(
                "SELECT DATE(created_at) d, COALESCE(SUM(total),0) s, COUNT(*) c FROM orders
                 WHERE status <> 'cancelled' AND created_at >= ? GROUP BY DATE(created_at) ORDER BY d"
            );
            $days->execute([date('Y-m-d', strtotime('-13 days')) . ' 00:00:00']);
            $byDay = [];
            foreach ($days as $r) {
                $byDay[$r['d']] = ['sales' => (int)$r['s'], 'orders' => (int)$r['c']];
            }
            $series = [];
            for ($i = 13; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-$i days"));
                $series[] = ['date' => $d] + ($byDay[$d] ?? ['sales' => 0, 'orders' => 0]);
            }
            $top = $pdo->query(
                "SELECT oi.name, SUM(oi.qty) AS qty, SUM(oi.qty * oi.unit_price) AS revenue
                 FROM order_items oi JOIN orders o ON o.id = oi.order_id
                 WHERE o.status <> 'cancelled' GROUP BY oi.name ORDER BY qty DESC LIMIT 10"
            )->fetchAll();
            out([
                'today'      => $sum($today),
                'month'      => $sum($month),
                'all'        => ['sales' => (int)$all['s'], 'orders' => (int)$all['c']],
                'series'     => $series,
                'top'        => rows_with_ints($top, ['qty', 'revenue']),
                'products'   => (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(),
                'available'  => (int)$pdo->query('SELECT COUNT(*) FROM products WHERE available = 1')->fetchColumn(),
                'pending'    => (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'new'")->fetchColumn(),
                'customers'  => (int)$pdo->query('SELECT COUNT(DISTINCT phone) FROM orders')->fetchColumn(),
            ]);
            break;

        /* حسابات المديرين: جميعها تملك صلاحيات كاملة. */
        case 'admins_list':
            out(['admins' => $pdo->query('SELECT id, username, created_at FROM admins ORDER BY id')->fetchAll(), 'current_id' => (int)$_SESSION['admin']]);
            break;

        case 'admin_create':
            $username = inp('username');
            $password = (string)($_POST['password'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $username)) {
                throw new UserError('اسم المستخدم: 3 إلى 30 حرفًا إنجليزيًا أو أرقامًا.');
            }
            if (!valid_admin_password($password)) {
                throw new UserError('كلمة المرور يجب ألا تقل عن 8 أحرف أو أرقام ولا تزيد عن 72 بايت.');
            }
            try {
                $pdo->prepare('INSERT INTO admins (username, password_hash, created_at) VALUES (?,?,?)')
                    ->execute([$username, password_hash($password, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') { throw new UserError('اسم المستخدم مستخدم بالفعل.'); }
                throw $e;
            }
            out(true);
            break;

        case 'admin_delete':
            $id = inp_int('id');
            if ($id === (int)$_SESSION['admin']) { throw new UserError('لا يمكنك حذف حسابك الحالي.'); }
            $password = (string)($_POST['password'] ?? '');
            $pdo->beginTransaction();
            try {
                $admins = $pdo->query('SELECT id, password_hash FROM admins ORDER BY id FOR UPDATE')->fetchAll();
                $hash = null;
                foreach ($admins as $admin) {
                    if ((int)$admin['id'] === (int)$_SESSION['admin']) { $hash = $admin['password_hash']; }
                }
                if (!$hash || !password_verify($password, $hash)) { throw new UserError('كلمة المرور الحالية غير صحيحة.'); }
                if (count($admins) <= 1) { throw new UserError('لا يمكن حذف آخر مدير.'); }
                $st = $pdo->prepare('DELETE FROM admins WHERE id = ?');
                $st->execute([$id]);
                if (!$st->rowCount()) { throw new UserError('حساب المدير غير موجود.'); }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                throw $e;
            }
            out(true);
            break;

        /* ---------- الإعدادات ---------- */
        case 'settings_get':
            $s = settings_all(true);
            unset($s['last_backup']);
            $s['last_backup'] = settings_all()['last_backup'];
            out($s);
            break;

        case 'settings_save':
            $schedule = inp('schedule_enabled', '0') === '1' ? '1' : '0';
            $start = inp('opens_at', '18:00'); $end = inp('closes_at', '23:30');
            if (!valid_time($start) || !valid_time($end) || $start === $end) { throw new UserError('أدخل وقتي فتح وإغلاق صحيحين ومختلفين.'); }
            $url = rtrim(inp('site_url'), '/');
            if ($url !== '') {
                $parts = parse_url($url);
                if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !preg_match('/^[a-z0-9.-]+$/i', $parts['host'])) { throw new UserError('أدخل رابط متجر صحيحًا يبدأ بـ https:// دون معاملات.'); }
            }
            $newLogo = ''; $oldLogo = '';
            try {
                $pdo->beginTransaction();
            set_setting('schedule_enabled', $schedule);
            set_setting('opens_at', $start);
            set_setting('closes_at', $end);
            set_setting('site_url', $url);
            $text = ['store_name' => 100, 'tagline' => 160, 'hours' => 120, 'currency' => 20, 'payment_note' => 160];
            foreach ($text as $k => $max) {
                if (isset($_POST[$k])) {
                    $v = clean_text($_POST[$k], $max);
                    if (in_array($k, ['store_name', 'currency'], true) && $v === '') {
                        throw new UserError('الحقل مطلوب: ' . $k);
                    }
                    set_setting($k, $v);
                }
            }
            foreach (['contact_1', 'contact_2'] as $k) {
                if (isset($_POST[$k])) {
                    set_setting($k, preg_replace('/\D+/', '', (string)$_POST[$k]));
                }
            }
            foreach (['min_order', 'free_delivery_over'] as $k) {
                if (isset($_POST[$k])) {
                    set_setting($k, (string)cap_int(max(0, (int)$_POST[$k]), 100000000, 'القيمة'));
                }
            }
            if (isset($_POST['open'])) {
                set_setting('open', $_POST['open'] === '1' ? '1' : '0');
            }
            if (has_upload('logo')) {
                $newLogo = save_image($_FILES['logo']);
                $oldLogo = setting('logo');
                set_setting('logo', $newLogo);
            } elseif (inp_int('remove_logo')) {
                $oldLogo = setting('logo');
                set_setting('logo', '');
            }
            $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                if ($newLogo !== '') { delete_image($newLogo); }
                throw $e;
            }
            if ($oldLogo !== '') { delete_unused_image($oldLogo); }
            out(true);
            break;

        case 'store_reset':
            if (inp('confirmation') !== 'حذف البيانات') { throw new UserError('اكتب عبارة التأكيد كما تظهر في الصفحة.'); }
            throttle('reset:' . client_ip(), 5, 900);
            $st = $pdo->prepare('SELECT password_hash FROM admins WHERE id = ?');
            $st->execute([$_SESSION['admin']]);
            $hash = $st->fetchColumn();
            if (!$hash || !password_verify((string)($_POST['password'] ?? ''), $hash)) { throw new UserError('كلمة المرور الحالية غير صحيحة.'); }
            if (!class_exists('ZipArchive')) { throw new UserError('يلزم دعم ZIP لحفظ نسخة احتياطية بالصور قبل الحذف.'); }
            $backupDir = ROOT . '/storage/backups';
            if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) { throw new UserError('تعذر إنشاء مجلد النسخ الاحتياطية.'); }
            ensure_order_requests_table();
            $pdo->beginTransaction();
            try {
                // Block new orders and require the owner to close the store first.
                $st = $pdo->query("SELECT svalue FROM settings WHERE skey = 'open' FOR UPDATE");
                if ($st->fetchColumn() !== '0') { throw new UserError('أغلق استقبال الطلبات يدويًا من الإعدادات قبل حذف البيانات.'); }
                list($backupPath, $backupName, $backupMime) = build_backup_file($backupDir);
                if ($backupMime !== 'application/zip' || !is_file($backupPath) || filesize($backupPath) === 0) { throw new UserError('تعذر حفظ نسخة كاملة قبل الحذف.'); }
                $images = array_merge($pdo->query("SELECT image FROM products WHERE image <> ''")->fetchAll(PDO::FETCH_COLUMN), $pdo->query("SELECT image FROM ads WHERE image <> ''")->fetchAll(PDO::FETCH_COLUMN));
                foreach (['order_requests', 'order_items', 'orders', 'offers', 'ads', 'products', 'categories', 'zones', 'coupons'] as $table) { $pdo->exec("DELETE FROM `$table`"); }
                $pdo->commit();
            } catch (Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $e; }
            foreach (array_unique($images) as $image) { delete_unused_image($image); }
            out(true);
            break;

        case 'password_change':
            $st = $pdo->prepare('SELECT * FROM admins WHERE id = ?');
            $st->execute([$_SESSION['admin']]);
            $u = $st->fetch();
            if (!$u || !password_verify((string)($_POST['current'] ?? ''), $u['password_hash'])) {
                throw new UserError('كلمة المرور الحالية غير صحيحة.');
            }
            $new = (string)($_POST['new'] ?? '');
            if (!valid_admin_password($new)) {
                throw new UserError('كلمة المرور الجديدة يجب ألا تقل عن 8 أحرف أو أرقام ولا تزيد عن 72 بايت.');
            }
            $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
            out(true);
            break;

        /* ---------- النسخ الاحتياطي ---------- */
        case 'backup_download':
            $tmp = sys_get_temp_dir();
            list($path, $name, $mime) = build_backup_file($tmp);
            set_setting('last_backup', date('Y-m-d H:i'));
            header('Content-Type: ' . $mime);
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            @unlink($path);
            exit;

        case 'backup_restore':
            if (!has_upload('file') || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                throw new UserError('اختر ملف النسخة الاحتياطية أولًا.');
            }
            restore_backup($_FILES['file']['tmp_name']);
            out(true);
            break;

        default:
            fail('طلب غير معروف.', 404);
    }
} catch (UserError $e) {
    fail($e->getMessage(), 422);
} catch (Throwable $e) {
    error_log('[wokbox admin] ' . $e->getMessage());
    fail('حدث خطأ في الخادم.', 500);
}
