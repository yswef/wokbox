<?php
require __DIR__ . '/../lib/bootstrap.php';
if (!file_exists(ROOT . '/install.lock')) {
    header('Location: ../install.php');
    exit;
}
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
try { $share = settings_all(); $siteUrl = public_site_url(); } catch (Throwable $e) { $share = default_settings(); $siteUrl = ''; }
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$ver = function ($f) {
    return @filemtime(__DIR__ . '/../assets/' . $f) ?: 1;
};
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title>لوحة الإدارة — <?= e($share['store_name']) ?></title>
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e($share['store_name']) ?>">
<meta property="og:title" content="لوحة الإدارة — <?= e($share['store_name']) ?>">
<meta property="og:description" content="لوحة إدارة <?= e($share['store_name']) ?>">
<?php if ($siteUrl !== ''): ?>
<meta property="og:url" content="<?= e($siteUrl . '/admin/') ?>">
<meta property="og:image" content="<?= e($siteUrl . '/social-image.php?v=' . substr(hash('sha256', $share['logo']), 0, 12)) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="<?= e($siteUrl . '/social-image.php?v=' . substr(hash('sha256', $share['logo']), 0, 12)) ?>">
<?php endif; ?>
<meta name="theme-color" content="#2b221c">
<link rel="icon" href="../assets/logo-192.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/admin.css?v=<?= $ver('admin.css') ?>">
</head>
<body>
<div id="login" class="login" hidden>
  <form class="login-box" id="login-form" autocomplete="on">
    <h1>لوحة الإدارة</h1>
    <p class="muted">سجّل الدخول لإدارة المتجر</p>
    <label class="f">اسم المستخدم<input id="l-user" name="username" autocomplete="username" dir="ltr" required></label>
    <label class="f">كلمة المرور<input id="l-pass" name="password" type="password" autocomplete="current-password" dir="ltr" required></label>
    <div class="form-error" id="l-error" role="alert"></div>
    <button class="btn btn-primary wide" type="submit">دخول</button>
  </form>
</div>

<div id="app" class="app" hidden>
  <header class="mtop">
    <button type="button" id="menu-btn" aria-label="القائمة"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
    <img class="logo-s" id="mtop-logo" src="../assets/logo-192.png" alt="">
    <b id="mtop-name">لوحة الإدارة</b>
  </header>
  <aside class="side" id="side">
    <div class="side-head"><img class="logo-s" id="side-logo" src="../assets/logo-192.png" alt=""><div><b id="side-name">المتجر</b><br><a class="muted" href="../index.php" target="_blank" rel="noopener">فتح المتجر ↗</a></div></div>
    <nav id="nav"></nav>
    <div class="side-foot">
      <button class="btn btn-light wide" id="enable-notify" type="button">تفعيل التنبيهات</button>
      <button class="btn btn-light wide" id="logout" type="button">تسجيل الخروج</button>
    </div>
  </aside>
  <div class="scrim" id="scrim" hidden></div>
  <main class="content" id="view"></main>
  <nav class="mtabs" id="mtabs" aria-label="التنقل السريع">
    <button type="button" data-view="overview"><svg viewBox="0 0 24 24"><path d="M3 12l9-8 9 8M5 10v10h14V10"/></svg><span>الرئيسية</span></button>
    <button type="button" data-view="orders"><svg viewBox="0 0 24 24"><path d="M6 2h12v20l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6"/></svg><span>الطلبات</span><i id="mtabs-badge" hidden>0</i></button>
    <button type="button" data-view="products"><svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h10"/></svg><span>المنيو</span></button>
    <button type="button" id="mtabs-more"><svg viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg><span>المزيد</span></button>
  </nav>
</div>

<div class="toast" id="toast" role="status"></div>
<script src="../assets/admin.js?v=<?= $ver('admin.js') ?>"></script>
</body>
</html>
