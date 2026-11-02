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
    $out['open'] = $s['open'] === '1';
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
    foreach ($items as $it) {
        $id = (int)($it['id'] ?? 0);
        $q = (int)($it['qty'] ?? 0);
        if ($id > 0 && $q > 0) {
            $clean[$id] = min(50, ($clean[$id] ?? 0) + $q);
        }
    }
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

function new_order_code()
{
    $pdo = db();
    for ($i = 0; $i < 10; $i++) {
        $code = 'WB-' . strtoupper(bin2hex(random_bytes(6)));
        $st = $pdo->prepare('SELECT 1 FROM orders WHERE code = ?');
        $st->execute([$code]);
        if (!$st->fetchColumn()) {
            return $code;
        }
    }
    throw new RuntimeException('could not allocate order code');
}

function create_order(array $in)
{
    if (!empty($in['website'])) {
        throw new UserError('تعذر إرسال الطلب.');
    }
    if (setting('open') !== '1') {
        throw new UserError('نعتذر، استقبال الطلبات متوقف مؤقتًا.');
    }
    $name = clean_text($in['name'] ?? '', 100);
    $phone = normalize_phone($in['phone'] ?? '');
    $address = clean_text($in['address'] ?? '', 500);
    $notes = clean_text($in['notes'] ?? '', 500);
    if (mb_strlen($name) < 2) {
        throw new UserError('اكتب اسمك.');
    }
    if (!valid_customer_phone($phone)) {
        throw new UserError('أدخل رقم جوال يمني صحيحًا من 9 أرقام يبدأ بـ 7.');
    }
    if (mb_strlen($address) < 5) {
        throw new UserError('اكتب العنوان بالتفصيل.');
    }
    throttle('order:' . client_ip(), 8, 900);

    $coupon = strtoupper(clean_text($in['coupon'] ?? '', 40));
    $zoneId = (int)($in['zone_id'] ?? 0);
    $items = is_array($in['items'] ?? null) ? $in['items'] : [];
    $quote = price_cart($items, $zoneId, $coupon, true);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($quote['coupon'] !== '') {
            $u = $pdo->prepare('UPDATE coupons SET used_count = used_count + 1
                                WHERE code = ? AND (max_uses IS NULL OR used_count < max_uses)');
            $u->execute([$quote['coupon']]);
            if ($u->rowCount() < 1) {
                throw new UserError('تم استهلاك كود الخصم بالكامل.');
            }
        }
        $code = new_order_code();
        $now = date('Y-m-d H:i:s');
        $pdo->prepare(
            'INSERT INTO orders (code, customer_name, phone, address, zone_id, zone_name, delivery_fee, subtotal,
                                 discount, coupon_code, total, notes, status, cancel_requested, ip, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $code, $name, $phone, $address, $zoneId ?: null, $quote['zone_name'], $quote['delivery_fee'],
            $quote['subtotal'], $quote['discount'], $quote['coupon'], $quote['total'], $notes, 'new', 0,
            client_ip(), $now, $now,
        ]);
        $orderId = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO order_items (order_id, product_id, name, unit_price, qty) VALUES (?,?,?,?,?)');
        foreach ($quote['lines'] as $l) {
            $ins->execute([$orderId, $l['product_id'], $l['name'], $l['unit_price'], $l['qty']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return ['code' => $code, 'total' => $quote['total'], 'phone' => $phone];
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
