<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/shop.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$action = $_GET['action'] ?? '';
$in = json_body();

try {
    if ($action !== 'menu' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        fail('طريقة الطلب غير مسموحة.', 405);
    }
    switch ($action) {
        case 'menu':
            out(menu_payload());
            break;

        case 'quote':
            throttle('quote:' . client_ip(), 120, 300);
            $q = price_cart(
                is_array($in['items'] ?? null) ? $in['items'] : [],
                (int)($in['zone_id'] ?? 0),
                strtoupper(clean_text($in['coupon'] ?? '', 40)),
                false
            );
            unset($q['lines']);
            out($q);
            break;

        case 'order':
            out(create_order($in));
            break;

        case 'track':
            throttle('track:' . client_ip(), 30, 600);
            $o = find_order_for_customer($in['code'] ?? '', $in['phone'] ?? '');
            out([
                'code'             => $o['code'],
                'status'           => $o['status'],
                'cancel_requested' => (bool)$o['cancel_requested'],
                'can_cancel'       => $o['status'] === 'new' && !$o['cancel_requested'],
                'subtotal'         => (int)$o['subtotal'],
                'discount'         => (int)$o['discount'],
                'delivery_fee'     => (int)$o['delivery_fee'],
                'total'            => (int)$o['total'],
                'zone_name'        => $o['zone_name'],
                'created_at'       => $o['created_at'],
                'items'            => order_items((int)$o['id']),
            ]);
            break;

        case 'cancel':
            throttle('cancel:' . client_ip(), 10, 600);
            $o = find_order_for_customer($in['code'] ?? '', $in['phone'] ?? '');
            if ($o['status'] !== 'new') {
                throw new UserError('لا يمكن طلب الإلغاء بعد بدء التجهيز. تواصل مع المتجر مباشرة.');
            }
            db()->prepare('UPDATE orders SET cancel_requested = 1, updated_at = ? WHERE id = ?')
                ->execute([date('Y-m-d H:i:s'), $o['id']]);
            out(['cancel_requested' => true]);
            break;

        default:
            fail('طلب غير معروف.', 404);
    }
} catch (UserError $e) {
    fail($e->getMessage(), 422);
} catch (Throwable $e) {
    error_log('[wokbox] ' . $e->getMessage());
    fail('حدث خطأ في الخادم، حاول لاحقًا.', 500);
}
