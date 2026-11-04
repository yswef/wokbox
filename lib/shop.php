<?php
function order_next_statuses($status)
{
    $next = [
        'new' => ['confirmed', 'cancelled'],
        'confirmed' => ['preparing', 'cancelled'],
        'preparing' => ['ready', 'cancelled'],
        'ready' => ['out', 'cancelled'],
        'out' => ['delivered', 'cancelled'],
        'delivered' => [], 'cancelled' => [],
    ];
    return $next[$status] ?? [];
}

function active_offers()
{
    $today = date('Y-m-d');
    $st = db()->prepare(
        'SELECT * FROM offers WHERE active = 1
         AND (starts_on IS NULL OR starts_on <= ?) AND (ends_on IS NULL OR ends_on >= ?)'
    );
    $st->execute([$today, $today]);
    return $st->fetchAll();
}

function effective_price(array $p, array $offers)
{
    $base = (int)$p['price'];
    $best = $base;
    $name = null;
    foreach ($offers as $o) {
        if ($o['product_id'] !== null && (int)$o['product_id'] !== (int)$p['id']) {
            continue;
        }
        $v = (int)$o['value'];
        $np = $o['type'] === 'percent'
            ? (int)round($base * (1 - min(100, $v) / 100))
            : max(0, $base - $v);
        if ($np < $best) {
            $best = $np;
            $name = $o['name'];
        }
    }
    return [$best, $name];
}

function public_settings()
{
    $s = settings_all();
    $keys = ['store_name', 'tagline', 'hours', 'open', 'contact_1', 'contact_2', 'currency',
             'min_order', 'free_delivery_over', 'payment_note', 'logo'];
    $out = [];
    foreach ($keys as $k) {
        $out[$k] = $s[$k];
    }
    $out['open'] = store_is_open($s);
    if ($s['schedule_enabled'] === '1') { $out['hours'] = $s['opens_at'] . ' – ' . $s['closes_at'] . ' (توقيت المتجر)'; }
    $out['min_order'] = (int)$s['min_order'];
    $out['free_delivery_over'] = (int)$s['free_delivery_over'];
    return $out;
}

function menu_payload()
{
    $pdo = db();
    $offers = active_offers();
    $cats = $pdo->query('SELECT id, name, emoji FROM categories ORDER BY sort_order, id')->fetchAll();
    $products = [];
    $names = [];
    foreach ($pdo->query('SELECT * FROM products ORDER BY sort_order, id') as $p) {
        list($now, $offer) = effective_price($p, $offers);
        $names[(int)$p['id']] = $p['name'];
        $products[] = [
            'id'          => (int)$p['id'],
            'category_id' => (int)$p['category_id'],
            'name'        => $p['name'],
            'description' => $p['description'],
            'price'       => (int)$p['price'],
            'price_now'   => $now,
            'offer'       => $offer,
            'emoji'       => $p['emoji'],
            'image'       => $p['image'],
            'available'   => (bool)$p['available'],
        ];
    }
    $pubOffers = [];
    foreach ($offers as $o) {
        $pubOffers[] = [
            'name'    => $o['name'],
            'type'    => $o['type'],
            'value'   => (int)$o['value'],
            'product' => $o['product_id'] === null ? null : ($names[(int)$o['product_id']] ?? null),
        ];
    }
    $ads = [];
    foreach ($pdo->query('SELECT id, title, description, image, product_id FROM ads WHERE active = 1 ORDER BY id DESC') as $a) {
        $a['product'] = $a['product_id'] === null ? null : ($names[(int)$a['product_id']] ?? null);
        unset($a['product_id']);
        $ads[] = $a;
    }
    $zones = $pdo->query('SELECT id, name, fee FROM zones WHERE active = 1 ORDER BY name')->fetchAll();
    foreach ($zones as &$z) {
        $z['id'] = (int)$z['id'];
        $z['fee'] = (int)$z['fee'];
    }
    unset($z);
    return [
        'settings'   => public_settings(),
        'categories' => $cats,
        'products'   => $products,
        'offers'     => $pubOffers,
        'ads'        => $ads,
        'zones'      => $zones,
    ];
}

// Short-lived file cache so thousands of visitors do not each run the menu queries.
function menu_cache_file()
{
    return ROOT . '/storage/cache/menu.json';
}

function menu_payload_cached($ttl = 10)
{
    $file = menu_cache_file();
    $mtime = @filemtime($file);
    if ($mtime !== false && (time() - $mtime) < $ttl) {
        $raw = @file_get_contents($file);
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($data) && isset($data['settings'], $data['products'])) { return $data; }
    }
    $data = menu_payload();
    $dir = dirname($file);
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json !== false && is_dir($dir) && is_writable($dir)) {
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $json) !== false && !@rename($tmp, $file)) { @unlink($tmp); }
    }
    return $data;
}

function menu_cache_clear()
{
    @unlink(menu_cache_file());
}

function find_coupon($code, $subtotal)
{
    $st = db()->prepare('SELECT * FROM coupons WHERE code = ?');
    $st->execute([strtoupper($code)]);
    $c = $st->fetch();
    if (!$c || !(int)$c['active']) {
        throw new UserError('كود الخصم غير صالح.');
    }
    if ($c['expires_on'] !== null && $c['expires_on'] < date('Y-m-d')) {
        throw new UserError('انتهت صلاحية كود الخصم.');
    }
    if ($c['max_uses'] !== null && (int)$c['used_count'] >= (int)$c['max_uses']) {
        throw new UserError('تم استهلاك كود الخصم بالكامل.');
    }
    if ((int)$c['min_order'] > $subtotal) {
        throw new UserError('هذا الكود يتطلب طلبًا لا يقل عن ' . number_format((int)$c['min_order']) . ' ' . setting('currency') . '.');
    }
    return $c;
}

function price_cart(array $items, $zoneId, $couponCode, $strict)
{
    $clean = [];
    if (count($items) > 40) { throw new UserError('عدد الأصناف في الطلب كبير جدًا.'); }
    foreach ($items as $it) {
        if (!is_array($it)) { throw new UserError('بيانات السلة غير صالحة.'); }
        $id = (int)($it['id'] ?? 0);
        $q = (int)($it['qty'] ?? 0);
        if ($id > 0 && $q > 0) {
            $clean[$id] = min(1000, ($clean[$id] ?? 0) + $q);
            if ($clean[$id] > 50) { throw new UserError('الحد الأقصى 50 قطعة لكل طلب.'); }
        }
    }
    if (array_sum($clean) > 50) { throw new UserError('الحد الأقصى 50 قطعة لكل طلب.'); }
    if (!$clean) {
        throw new UserError('السلة فارغة.');
    }
    if (count($clean) > 40) {
        throw new UserError('عدد الأصناف في الطلب كبير جدًا.');
    }
    $pdo = db();
    $in = implode(',', array_fill(0, count($clean), '?'));
    $st = $pdo->prepare("SELECT * FROM products WHERE id IN ($in)");
    $st->execute(array_keys($clean));
    $prods = [];
    foreach ($st as $p) {
        $prods[(int)$p['id']] = $p;
    }
    $offers = active_offers();
    $lines = [];
    $subtotal = 0;
    foreach ($clean as $id => $q) {
        if (!isset($prods[$id])) {
            throw new UserError('أحد الأصناف لم يعد موجودًا، حدّث الصفحة وحاول مجددًا.');
        }
        $p = $prods[$id];
        if (!(int)$p['available']) {
            throw new UserError('«' . $p['name'] . '» غير متوفر حاليًا.');
        }
        list($price) = effective_price($p, $offers);
        $lines[] = ['product_id' => $id, 'name' => $p['name'], 'unit_price' => $price, 'qty' => $q];
        $subtotal += $price * $q;
    }

    $discount = 0;
    $couponOk = '';
    $couponError = '';
    if ($couponCode !== '') {
        try {
            $c = find_coupon($couponCode, $subtotal);
            $discount = $c['type'] === 'percent'
                ? (int)floor($subtotal * min(100, (int)$c['value']) / 100)
                : (int)$c['value'];
            $discount = min($discount, $subtotal);
            $couponOk = $c['code'];
        } catch (UserError $e) {
            if ($strict) {
                throw $e;
            }
            $couponError = $e->getMessage();
        }
    }

    $settings = settings_all();
    $minOrder = (int)$settings['min_order'];
    if ($strict && $minOrder > 0 && $subtotal < $minOrder) {
        throw new UserError('الحد الأدنى للطلب ' . number_format($minOrder) . ' ' . $settings['currency'] . '.');
    }

    $zoneName = '';
    $fee = 0;
    $zoneRequired = false;
    $zoneCount = (int)$pdo->query('SELECT COUNT(*) FROM zones WHERE active = 1')->fetchColumn();
    if ($zoneCount > 0) {
        $zone = null;
        if ($zoneId) {
            $zs = $pdo->prepare('SELECT * FROM zones WHERE id = ? AND active = 1');
            $zs->execute([(int)$zoneId]);
            $zone = $zs->fetch();
        }
        if ($zone) {
            $zoneName = $zone['name'];
            $fee = (int)$zone['fee'];
            $freeOver = (int)$settings['free_delivery_over'];
            if ($freeOver > 0 && ($subtotal - $discount) >= $freeOver) {
                $fee = 0;
            }
        } else {
            $zoneRequired = true;
            if ($strict) {
                throw new UserError('اختر منطقة التوصيل.');
            }
        }
    }

    return [
        'lines'        => $lines,
        'subtotal'     => $subtotal,
        'discount'     => $discount,
        'coupon'       => $couponOk,
        'coupon_error' => $couponError,
        'delivery_fee' => $fee,
        'zone_name'    => $zoneName,
        'zone_required' => $zoneRequired,
        'total'        => $subtotal - $discount + $fee,
    ];
}

// The request key is independent of the shorter, customer-facing order code.
function request_token_hash($token)
{
    if (!preg_match('/^[a-f0-9]{32}$/', (string)$token)) { throw new UserError('تعذر تأمين الطلب، حدّث الصفحة وحاول مجددًا.'); }
    return hash('sha256', $token);
}

function new_order_code()
{
    return 'WB-' . strtoupper(bin2hex(random_bytes(4)));
}

function existing_order_for_request($token, $phone)
{
    $hash = request_token_hash($token);
    $sql = 'SELECT o.code, o.phone, o.total FROM order_requests r JOIN orders o ON o.id = r.order_id WHERE r.token_hash = ?';
    try {
        $st = db()->prepare($sql);
        $st->execute([$hash]);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) !== 1146) { throw $e; }
        require_once __DIR__ . '/schema.php';
        ensure_order_requests_table();
        $st = db()->prepare($sql);
        $st->execute([$hash]);
    }
    $existing = $st->fetch();
    if (!$existing) {
        // Compatibility with a previously issued 12-character code derived from this key.
        $oldCode = 'WB-' . strtoupper(substr($hash, 0, 12));
        $st = db()->prepare('SELECT code, phone, total FROM orders WHERE code = ?');
        $st->execute([$oldCode]);
        $existing = $st->fetch();
    }
    if (!$existing || !hash_equals($existing['phone'], $phone)) { return null; }
    return ['code' => $existing['code'], 'total' => (int)$existing['total'], 'phone' => $existing['phone']];
}

function recover_order_request($token, $phone)
{
    request_token_hash($token);
    $pdo = db();
    $lock = 'wbo:' . $token;
    $st = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $st->execute([$lock]);
    if ((int)$st->fetchColumn() !== 1) { throw new UserError('الطلب ما زال قيد المعالجة، حاول مجددًا بعد قليل.'); }
    try { return existing_order_for_request($token, $phone); }
    finally { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]); }
}

function create_order(array $in)
{
    if (!empty($in['website'])) { throw new UserError('تعذر إرسال الطلب.'); }
    $token = strtolower((string)($in['request_token'] ?? ''));
    $tokenHash = request_token_hash($token);
    $phone = normalize_phone($in['phone'] ?? '');
    if (!valid_customer_phone($phone)) { throw new UserError('أدخل رقم جوال يمني صحيحًا من 9 أرقام يبدأ بـ 7.'); }
    $pdo = db();
    $existing = existing_order_for_request($token, $phone);
    if ($existing) { return $existing; }
    throttle('order:' . client_ip(), 30, 900);
    throttle('phone:' . hash('sha256', $phone), 6, 900);
    $lock = 'wbo:' . $token;
    $st = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $st->execute([$lock]);
    if ((int)$st->fetchColumn() !== 1) { throw new UserError('الطلب قيد المعالجة، حاول مجددًا بعد قليل.'); }
    try {
        // Re-check after acquiring the lock in case a concurrent retry just finished.
        $existing = existing_order_for_request($token, $phone);
        if ($existing) { return $existing; }
        $name = clean_text($in['name'] ?? '', 100);
        $address = clean_text($in['address'] ?? '', 500);
        $notes = clean_text($in['notes'] ?? '', 500);
        if (mb_strlen($name) < 2) { throw new UserError('اكتب اسمك.'); }
        if (mb_strlen($address) < 5) { throw new UserError('اكتب العنوان بالتفصيل.'); }
        $coupon = strtoupper(clean_text($in['coupon'] ?? '', 40));
        $zoneId = (int)($in['zone_id'] ?? 0);
        $items = is_array($in['items'] ?? null) ? $in['items'] : [];
        $pdo->beginTransaction();
        try {
            // A shared lock allows concurrent orders while a reset or settings update waits.
            $st = $pdo->query("SELECT svalue FROM settings WHERE skey = 'open' LOCK IN SHARE MODE");
            if ($st->fetchColumn() === false) { throw new UserError('إعداد حالة المتجر غير موجود.'); }
            if (!store_is_open(settings_all(true))) { throw new UserError('نعتذر، استقبال الطلبات متوقف حاليًا أو خارج ساعات العمل.'); }
            $quote = price_cart($items, $zoneId, $coupon, true);
            if ($quote['coupon'] !== '') {
                $u = $pdo->prepare('UPDATE coupons SET used_count = used_count + 1 WHERE code = ? AND active = 1 AND (expires_on IS NULL OR expires_on >= ?) AND (max_uses IS NULL OR used_count < max_uses)');
                $u->execute([$quote['coupon'], date('Y-m-d')]);
                if ($u->rowCount() < 1) { throw new UserError('كود الخصم لم يعد متاحًا.'); }
            }
            $now = date('Y-m-d H:i:s');
            $insertOrder = $pdo->prepare('INSERT INTO orders (code, customer_name, phone, address, zone_id, zone_name, delivery_fee, subtotal, discount, coupon_code, total, notes, status, cancel_requested, ip, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $inserted = false;
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $code = new_order_code();
                try {
                    $insertOrder->execute([$code, $name, $phone, $address, $zoneId ?: null, $quote['zone_name'], $quote['delivery_fee'], $quote['subtotal'], $quote['discount'], $quote['coupon'], $quote['total'], $notes, 'new', 0, client_ip(), $now, $now]);
                    $inserted = true;
                    break;
                } catch (PDOException $e) {
                    if ((int)($e->errorInfo[1] ?? 0) !== 1062) { throw $e; }
                }
            }
            if (!$inserted) { throw new RuntimeException('could not allocate order code'); }
            $orderId = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO order_requests (token_hash, order_id) VALUES (?, ?)')->execute([$tokenHash, $orderId]);
            $ins = $pdo->prepare('INSERT INTO order_items (order_id, product_id, name, unit_price, qty) VALUES (?,?,?,?,?)');
            foreach ($quote['lines'] as $line) { $ins->execute([$orderId, $line['product_id'], $line['name'], $line['unit_price'], $line['qty']]); }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return ['code' => $code, 'total' => $quote['total'], 'phone' => $phone];
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
    }
}

function find_order_for_customer($code, $phone)
{
    $code = strtoupper(clean_text($code, 20));
    $phone = normalize_phone($phone);
    $st = db()->prepare('SELECT * FROM orders WHERE code = ? AND phone = ?');
    $st->execute([$code, $phone]);
    $o = $st->fetch();
    if (!$o) {
        throw new UserError('لم نجد طلبًا بهذه البيانات. تأكد من رقم الطلب ورقم الهاتف.');
    }
    return $o;
}

function order_items($orderId)
{
    $st = db()->prepare('SELECT name, unit_price, qty FROM order_items WHERE order_id = ? ORDER BY id');
    $st->execute([$orderId]);
    return $st->fetchAll();
}
