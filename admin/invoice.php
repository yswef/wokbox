<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/shop.php';
start_session();
if (empty($_SESSION['admin'])) {
    http_response_code(401);
    exit('غير مصرح');
}
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function e($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT * FROM orders WHERE id = ?');
$st->execute([$id]);
$o = $st->fetch();
if (!$o) {
    http_response_code(404);
    exit('الطلب غير موجود');
}
$items = order_items($id);
$cur = setting('currency');
$n = function ($v) {
    return number_format((int)$v);
};
$statusLabels = ['new' => 'جديد', 'preparing' => 'قيد التجهيز', 'ready' => 'جاهز', 'out' => 'خرج للتوصيل', 'delivered' => 'تم التسليم', 'cancelled' => 'ملغي'];
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>فاتورة <?= e($o['code']) ?></title>
<style>
@page{size:80mm auto;margin:3mm}
*{box-sizing:border-box}
body{font-family:Tahoma,Arial,sans-serif;font-size:13px;color:#000;margin:0 auto;width:74mm;padding:6px 0;line-height:1.5}
h1{font-size:17px;text-align:center;margin:0}
.c{text-align:center}.muted{color:#444;font-size:12px}
hr{border:0;border-top:1px dashed #000;margin:8px 0}
table{width:100%;border-collapse:collapse}
td{padding:2px 0;vertical-align:top}
td.q{width:26px;text-align:center}td.p{text-align:left;white-space:nowrap;direction:ltr}
.row{display:flex;justify-content:space-between}
.total{font-size:16px;font-weight:bold}
.note{border:1px solid #000;padding:4px 6px;margin-top:6px}
.noprint{text-align:center;margin:10px 0}
@media print{.noprint{display:none}}
</style>
</head>
<body>
<h1><?= e(setting('store_name')) ?></h1>
<div class="c muted"><?= e(setting('tagline')) ?></div>
<hr>
<div class="row"><b>رقم الطلب</b><span dir="ltr"><?= e($o['code']) ?></span></div>
<div class="row"><span>التاريخ</span><span dir="ltr"><?= e(substr($o['created_at'], 0, 16)) ?></span></div>
<div class="row"><span>الحالة</span><span><?= e($statusLabels[$o['status']] ?? $o['status']) ?></span></div>
<hr>
<div><b>العميل:</b> <?= e($o['customer_name']) ?></div>
<div><b>الهاتف:</b> <span dir="ltr"><?= e($o['phone']) ?></span></div>
<?php if ($o['zone_name'] !== ''): ?><div><b>المنطقة:</b> <?= e($o['zone_name']) ?></div><?php endif; ?>
<div><b>العنوان:</b> <?= nl2br(e($o['address'])) ?></div>
<hr>
<table>
<?php foreach ($items as $it): ?>
<tr><td class="q"><?= (int)$it['qty'] ?>×</td><td><?= e($it['name']) ?></td><td class="p"><?= $n($it['unit_price'] * $it['qty']) ?></td></tr>
<?php endforeach; ?>
</table>
<hr>
<div class="row"><span>المجموع</span><span><?= $n($o['subtotal']) ?> <?= e($cur) ?></span></div>
<?php if ((int)$o['discount'] > 0): ?><div class="row"><span>الخصم<?= $o['coupon_code'] !== '' ? ' (' . e($o['coupon_code']) . ')' : '' ?></span><span>- <?= $n($o['discount']) ?> <?= e($cur) ?></span></div><?php endif; ?>
<?php if ($o['zone_name'] !== ''): ?><div class="row"><span>التوصيل</span><span><?= (int)$o['delivery_fee'] ? $n($o['delivery_fee']) . ' ' . e($cur) : 'مجاني' ?></span></div><?php endif; ?>
<div class="row total"><span>الإجمالي</span><span><?= $n($o['total']) ?> <?= e($cur) ?></span></div>
<?php if (trim($o['notes']) !== ''): ?><div class="note"><b>ملاحظات:</b> <?= nl2br(e($o['notes'])) ?></div><?php endif; ?>
<hr>
<div class="c muted">شكرًا لطلبكم</div>
<div class="noprint"><button onclick="window.print()">طباعة</button></div>
<script>window.addEventListener('load',function(){setTimeout(function(){window.print()},300)});</script>
</body>
</html>
