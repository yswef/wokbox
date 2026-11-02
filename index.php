<?php
require __DIR__ . '/lib/bootstrap.php';
if (!file_exists(ROOT . '/install.lock')) {
    header('Location: install.php');
    exit;
}
require __DIR__ . '/lib/shop.php';

function e($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

try {
    $boot = menu_payload();
} catch (Throwable $ex) {
    error_log('[wokbox] ' . $ex->getMessage());
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body style="font-family:Tahoma,sans-serif;text-align:center;padding:60px 20px;direction:rtl"><h2>المتجر قيد الصيانة مؤقتًا</h2><p>يرجى المحاولة بعد قليل.</p></body>';
    exit;
}
$S = $boot['settings'];
$ver = function ($f) {
    return @filemtime(__DIR__ . '/assets/' . $f) ?: 1;
};
$wa = function ($n) {
    $n = preg_replace('/\D+/', '', (string)$n);
    return $n;
};
$bootJson = json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
// Set site_url in config.php to the public HTTPS store URL for reliable social previews.
$siteUrl = rtrim((string)cfg('site_url', ''), '/');
if ($siteUrl !== '' && !preg_match('~^https?://[a-z0-9.-]+(?::[0-9]+)?(?:/[^?#]*)?$~i', $siteUrl)) { $siteUrl = ''; }
$logoUrl = $S['logo'] ? 'uploads/' . $S['logo'] : 'assets/logo.png?v=' . $ver('logo.png');
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= e($S['store_name']) ?></title>
<meta name="description" content="<?= e($S['store_name'] . ' — ' . $S['tagline']) ?>">
<?php if ($siteUrl !== ''): ?>
<link rel="canonical" href="<?= e($siteUrl . '/') ?>">
<meta property="og:url" content="<?= e($siteUrl . '/') ?>">
<?php endif; ?>
<meta property="og:site_name" content="<?= e($S['store_name']) ?>">
<meta property="og:type" content="website">
<meta property="og:locale" content="ar_AR">
<meta property="og:title" content="<?= e($S['store_name']) ?>">
<meta property="og:description" content="<?= e($S['tagline'] . ' — ' . $S['hours']) ?>">
<?php if ($siteUrl !== ''): ?>
<meta property="og:image" content="<?= e($siteUrl . '/' . $logoUrl) ?>">
<meta property="og:image:alt" content="<?= e($S['store_name']) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($S['store_name']) ?>">
<meta name="twitter:description" content="<?= e($S['tagline']) ?>">
<meta name="twitter:image" content="<?= e($siteUrl . '/' . $logoUrl) ?>">
<?php endif; ?>
<meta name="theme-color" content="#d65a1c">
<link rel="icon" href="<?= e($logoUrl) ?>">
<link rel="apple-touch-icon" href="<?= e($logoUrl) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/store.css?v=<?= $ver('store.css') ?>">
</head>
<body>
<header class="topbar">
  <div class="wrap nav">
    <a class="brand" href="#top" aria-label="<?= e($S['store_name']) ?>">
      <img class="brand-logo" src="<?= e($logoUrl) ?>" alt="">
      <span class="brand-text"><b><?= e($S['store_name']) ?></b><small><?= e($S['tagline']) ?></small></span>
    </a>
    <nav class="nav-links">
      <a href="#menu">المنيو</a>
      <a href="#track">متابعة الطلب</a>
      <a href="#contact">تواصل معنا</a>
    </nav>
    <button class="cart-btn" id="open-cart" type="button" aria-label="فتح السلة">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/></svg>
      <span>السلة</span><b id="cart-count">0</b>
    </button>
  </div>
</header>

<main id="top">
  <section class="hero">
    <div class="wrap">
      <div class="hero-card">
        <img class="hero-logo" src="<?= e($logoUrl) ?>" alt="<?= e($S['store_name']) ?>" width="180" height="180">
        <div class="hero-text">
          <span class="status-badge" id="status-badge"></span>
          <h1><?= e($S['store_name']) ?></h1>
          <p><?= e($S['tagline']) ?></p>
          <div class="hero-actions">
            <a class="btn btn-light" href="#menu">تصفّح المنيو</a>
            <button class="btn btn-ghost" type="button" id="hero-cart">عرض السلة</button>
          </div>
          <ul class="hero-facts">
            <li><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><?= e($S['hours']) ?></li>
            <?php if ($boot['zones']): ?><li><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="6" cy="17" r="3"/><circle cx="18" cy="17" r="3"/><path d="M6 17h4l3-7h3M13 10l-2-4H8"/></svg>خدمة توصيل</li><?php endif; ?>
            <?php if ($S['payment_note'] !== ''): ?><li><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/></svg><?= e($S['payment_note']) ?></li><?php endif; ?>
          </ul>
        </div>
      </div>
    </div>
  </section>

  <section class="wrap" id="notices"></section>

  <section class="section" id="menu">
    <div class="wrap">
      <div class="section-head">
        <h2>المنيو</h2>
        <input class="search" id="search" type="search" placeholder="ابحث عن وجبة..." aria-label="بحث في المنيو">
      </div>
      <div class="closed-note" id="closed-note" hidden>استقبال الطلبات متوقف مؤقتًا. يمكنك تصفّح المنيو الآن وسنعود قريبًا.</div>
      <div class="chips" id="chips" role="tablist"></div>
      <div class="grid" id="products"></div>
    </div>
  </section>

  <section class="section" id="track">
    <div class="wrap">
      <div class="panel track-panel">
        <h2>متابعة الطلب</h2>
        <p class="muted">أدخل رقم الطلب ورقم الهاتف الذي استخدمته عند الطلب.</p>
        <form id="track-form" class="track-form" autocomplete="off">
          <label>رقم الطلب<input id="track-code" placeholder="WB-XXXXXXXXXXXX" dir="ltr" required></label>
          <label>رقم الهاتف<input id="track-phone" type="tel" inputmode="tel" placeholder="7XXXXXXXX" dir="ltr" required></label>
          <button class="btn btn-primary" type="submit">متابعة</button>
        </form>
        <button class="btn btn-light" type="button" id="track-notify">تفعيل تنبيهات التتبع</button>
        <p class="muted">تعمل التنبيهات أثناء فتح صفحة التتبع والسماح بإشعارات المتصفح.</p>
        <div id="track-result" aria-live="polite"></div>
      </div>
    </div>
  </section>
</main>

<footer class="footer" id="contact">
  <div class="wrap footer-grid">
    <div class="f-brand">
      <img src="<?= e($logoUrl) ?>" alt="" width="72" height="72" loading="lazy">
      <div><h3><?= e($S['store_name']) ?></h3><p><?= e($S['tagline']) ?></p></div>
    </div>
    <div>
      <h4>تواصل معنا</h4>
      <?php foreach (['contact_1', 'contact_2'] as $k): if ($S[$k] !== ''): $n = $wa($S[$k]); ?>
        <div class="contact-row" dir="ltr">
          <span>+<?= e($n) ?></span>
          <a class="mini-btn" href="tel:+<?= e($n) ?>">اتصال</a>
          <a class="mini-btn wa" href="https://wa.me/<?= e($n) ?>" target="_blank" rel="noopener">واتساب</a>
        </div>
      <?php endif; endforeach; ?>
    </div>
    <div>
      <h4>أوقات العمل</h4>
      <p><?= e($S['hours']) ?></p>
      <h4 class="mt">روابط سريعة</h4>
      <p><a href="#menu">المنيو</a> · <a href="#track">متابعة الطلب</a></p>
    </div>
  </div>
  <div class="wrap credit">
    <span dir="ltr">Made with <span class="heart" aria-label="love">&hearts;</span> by
      <a href="https://github.com/yswef" target="_blank" rel="noopener">Yousef Al-Hamzi</a></span>
  </div>
</footer>

<button class="mobile-bar" id="mobile-bar" type="button" hidden>
  <span>عرض السلة (<b id="mb-count">0</b>)</span><span><b id="mb-total">0</b> <?= e($S['currency']) ?></span>
</button>

<nav class="tabbar" aria-label="التنقل السريع">
  <a href="#menu" data-tab="menu"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h10"/></svg><span>المنيو</span></a>
  <a href="#track" data-tab="track"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s7-6.1 7-11a7 7 0 1 0-14 0c0 4.9 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/></svg><span>تتبع الطلب</span></a>
  <button type="button" id="tab-cart"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/></svg><span>السلة</span><i id="tab-count" hidden>0</i></button>
  <a href="#contact" data-tab="contact"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/></svg><span>تواصل</span></a>
</nav>

<div class="overlay" id="overlay" hidden></div>
<aside class="drawer" id="drawer" role="dialog" aria-modal="true" aria-label="سلة الطلب" aria-hidden="true">
  <div class="drawer-head">
    <h3>سلة الطلب</h3>
    <button class="icon-btn" type="button" id="close-cart" aria-label="إغلاق">&times;</button>
  </div>
  <div class="drawer-body">
    <div id="cart-items"></div>
    <form id="order-form" autocomplete="on" novalidate>
      <div id="checkout" hidden>
        <h4>بيانات التوصيل</h4>
        <label class="f">الاسم *<input id="f-name" name="name" autocomplete="name" maxlength="100"></label>
        <label class="f">رقم الهاتف *<input id="f-phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="7XXXXXXXX" dir="ltr" maxlength="20"></label>
        <label class="f" id="zone-wrap" hidden>منطقة التوصيل *<select id="f-zone"></select></label>
        <label class="f">العنوان بالتفصيل *<textarea id="f-address" name="address" maxlength="500" placeholder="المنطقة، الشارع، أقرب معلم..."></textarea></label>
        <label class="f">ملاحظات (اختياري)<textarea id="f-notes" name="notes" maxlength="500" placeholder="مثلًا: بدون صوص، أو اتصل عند الوصول"></textarea></label>
        <div class="f">
          <span class="lbl">كود الخصم</span>
          <div class="coupon-row"><input id="f-coupon" placeholder="اكتب الكود" dir="ltr" maxlength="40"><button class="btn btn-light" type="button" id="apply-coupon">تطبيق</button></div>
          <small id="coupon-msg" class="muted"></small>
        </div>
        <input type="text" name="website" id="f-website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;opacity:0" aria-hidden="true">
        <div class="summary" id="summary"></div>
        <?php if ($S['payment_note'] !== ''): ?><p class="muted pay-note"><?= e($S['payment_note']) ?></p><?php endif; ?>
        <div class="checkout-foot">
          <div class="form-error" id="form-error" role="alert"></div>
          <button class="btn btn-primary wide" type="submit" id="submit-order"><span>تأكيد الطلب</span><b id="foot-total"></b></button>
        </div>
      </div>
    </form>
  </div>
</aside>

<div class="modal" id="success" hidden>
  <div class="modal-box">
    <div class="check">&#10003;</div>
    <h3>تم استلام طلبك</h3>
    <p class="muted">احتفظ برقم الطلب لمتابعة حالته.</p>
    <div class="code" id="success-code" dir="ltr"></div>
    <p>الإجمالي: <b id="success-total"></b></p>
    <div class="modal-actions">
      <button class="btn btn-primary" type="button" id="success-track">متابعة الطلب</button>
      <button class="btn btn-light" type="button" id="success-close">إغلاق</button>
    </div>
  </div>
</div>

<div class="toast" id="toast" role="status"></div>
<noscript><div style="padding:20px;text-align:center">يلزم تفعيل جافاسكربت لاستخدام المتجر.</div></noscript>
<script>window.__BOOT=<?= $bootJson ?>;</script>
<script src="assets/store.js?v=<?= $ver('store.js') ?>"></script>
</body>
</html>
