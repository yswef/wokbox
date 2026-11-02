(function () {
  'use strict';

  var M = window.__BOOT;
  var CART_KEY = 'wb_cart', ORD_KEY = 'wb_orders', PROFILE_KEY = 'wb_profile';
  var STEPS = [['new', 'تم الاستلام'], ['preparing', 'قيد التجهيز'], ['ready', 'جاهز'], ['out', 'خرج للتوصيل'], ['delivered', 'تم التسليم']];

  var $ = function (s, r) { return (r || document).querySelector(s); };
  var esc = function (v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };
  var fmt = function (n) { return Number(n || 0).toLocaleString('en-US'); };
  var cur = function () { return M.settings.currency; };
  var money = function (n) { return fmt(n) + ' ' + cur(); };

  function load(key, fallback) {
    try { var v = JSON.parse(localStorage.getItem(key)); return v == null ? fallback : v; } catch (e) { return fallback; }
  }
  function save(key, val) { try { localStorage.setItem(key, JSON.stringify(val)); } catch (e) { /* storage unavailable */ } }

  var cart = load(CART_KEY, {});
  var state = { cat: 0, q: '', coupon: '', quote: null, quoteTimer: null, trackTimer: null, trackArgs: null };

  function api(action, body, method) {
    method = method || 'POST';
    return fetch('api/public.php?action=' + action, {
      method: method,
      headers: { 'Content-Type': 'application/json' },
      body: method === 'POST' ? JSON.stringify(body || {}) : undefined,
      cache: 'no-store'
    }).then(function (r) {
      return r.json().catch(function () { throw new Error('تعذر الاتصال بالخادم، حاول مرة أخرى.'); });
    }).then(function (j) {
      if (!j.ok) { throw new Error(j.error || 'حدث خطأ'); }
      return j.data;
    });
  }

  function toast(msg) {
    var t = $('#toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toast.t);
    toast.t = setTimeout(function () { t.classList.remove('show'); }, 1900);
  }

  function product(id) {
    for (var i = 0; i < M.products.length; i++) { if (M.products[i].id === id) { return M.products[i]; } }
    return null;
  }

  /* ---------- حالة المتجر ---------- */
  function renderStatus() {
    var open = M.settings.open;
    var b = $('#status-badge');
    b.textContent = open ? 'مفتوح لاستقبال الطلبات' : 'الطلبات متوقفة مؤقتًا';
    b.classList.toggle('closed', !open);
    $('#closed-note').hidden = open;
  }

  /* ---------- الإعلانات والعروض ---------- */
  function renderNotices() {
    var html = '';
    M.ads.forEach(function (a) {
      var prod = a.product ? M.products.filter(function (p) { return p.name === a.product; })[0] : null;
      html += '<div class="ad">' + (a.image ? '<img src="uploads/' + esc(a.image) + '" alt="" loading="lazy">' : '') +
        '<div class="ad-body"><h3>' + esc(a.title) + '</h3>' + (a.description ? '<p>' + esc(a.description) + '</p>' : '') +
        (prod && prod.available && M.settings.open ? '<button class="btn btn-primary" data-ad-add="' + prod.id + '">أضف «' + esc(prod.name) + '» للسلة</button>' : '') +
        '</div></div>';
    });
    if (M.offers.length) {
      html += '<div class="offers">' + M.offers.map(function (o) {
        var v = o.type === 'percent' ? o.value + '%' : fmt(o.value) + ' ' + cur();
        return '<span class="offer-pill">' + esc(o.name) + ' — خصم ' + v + (o.product ? ' على ' + esc(o.product) : ' على كل المنتجات') + '</span>';
      }).join('') + '</div>';
    }
    $('#notices').innerHTML = html;
  }

  /* ---------- المنيو ---------- */
  function renderChips() {
    var all = [{ id: 0, name: 'الكل', emoji: '' }].concat(M.categories);
    $('#chips').innerHTML = all.map(function (c) {
      return '<button type="button" class="chip' + (state.cat === c.id ? ' active' : '') + '" data-cat="' + c.id + '">' +
        (c.emoji ? esc(c.emoji) + ' ' : '') + esc(c.name) + '</button>';
    }).join('');
  }

  function renderProducts() {
    var q = state.q.trim().toLowerCase();
    var list = M.products.filter(function (p) {
      if (state.cat && p.category_id !== state.cat) { return false; }
      return !q || (p.name + ' ' + p.description).toLowerCase().indexOf(q) !== -1;
    });
    if (!list.length) {
      $('#products').innerHTML = '<div class="empty-menu">لا توجد أصناف مطابقة.</div>';
      return;
    }
    var open = M.settings.open;
    $('#products').innerHTML = list.map(function (p) {
      var qty = cart[p.id] || 0;
      var art = p.image ? '<img src="uploads/' + esc(p.image) + '" alt="' + esc(p.name) + '" loading="lazy">' : esc(p.emoji || '🍽️');
      var badge = p.offer ? '<span class="p-badge">عرض</span>' : '';
      var price = '<div class="price">' + (p.price_now < p.price ? '<del>' + fmt(p.price) + '</del>' : '') + fmt(p.price_now) + ' <small>' + esc(cur()) + '</small></div>';
      var action;
      if (!p.available) {
        action = '<button class="btn btn-light add" disabled>غير متوفر</button>';
      } else if (!open) {
        action = '<button class="btn btn-light add" disabled>مغلق</button>';
      } else if (qty > 0) {
        action = '<div class="stepper"><button type="button" data-dec="' + p.id + '" aria-label="تقليل">−</button><b>' + qty + '</b><button type="button" data-inc="' + p.id + '" aria-label="زيادة">+</button></div>';
      } else {
        action = '<button class="btn btn-primary add" data-add="' + p.id + '">+ أضف للسلة</button>';
      }
      return '<article class="product' + (p.available ? '' : ' off') + '"><div class="p-art">' + art + badge + '</div>' +
        '<div class="p-body"><h3>' + esc(p.name) + '</h3><p>' + esc(p.description) + '</p><div class="p-foot">' + price + action + '</div></div></article>';
    }).join('');
  }

  /* ---------- السلة ---------- */
  function cleanCart() {
    var changed = false;
    Object.keys(cart).forEach(function (id) {
      var p = product(parseInt(id, 10));
      if (!p || !p.available || cart[id] < 1) { delete cart[id]; changed = true; }
    });
    if (changed) { save(CART_KEY, cart); }
  }

  function cartEntries() {
    return Object.keys(cart).map(function (id) { return { p: product(parseInt(id, 10)), q: cart[id] }; }).filter(function (e) { return e.p; });
  }

  function localSubtotal() {
    return cartEntries().reduce(function (s, e) { return s + e.p.price_now * e.q; }, 0);
  }

  function cartCount() {
    return cartEntries().reduce(function (s, e) { return s + e.q; }, 0);
  }

  function setQty(id, delta) {
    var p = product(id);
    if (!p || !p.available || !M.settings.open) { return; }
    var n = (cart[id] || 0) + delta;
    if (n > 50) { n = 50; }
    if (n <= 0) { delete cart[id]; } else { cart[id] = n; }
    save(CART_KEY, cart);
    renderAll(true);
  }

  function renderCart() {
    var entries = cartEntries();
    var count = cartCount();
    var sub = localSubtotal();
    $('#cart-count').textContent = count;
    $('#mb-count').textContent = count;
    $('#mb-total').textContent = fmt(sub);
    var tc = $('#tab-count');
    tc.textContent = count;
    tc.hidden = !count;
    var mb = $('#mobile-bar');
    mb.hidden = !count;
    mb.classList.toggle('show', count > 0);

    var box = $('#cart-items');
    if (!entries.length) {
      box.innerHTML = '<div class="cart-empty"><div class="ce-ico">🛒</div><b>سلتك فارغة</b><p>اختر وجبتك من المنيو وأضفها هنا.</p><button type="button" class="btn btn-primary" id="empty-go">تصفّح المنيو</button></div>';
      $('#empty-go').addEventListener('click', function () { closeDrawer(); $('#menu').scrollIntoView({ behavior: 'smooth' }); });
      $('#checkout').hidden = true;
      return;
    }
    box.innerHTML = entries.map(function (e) {
      return '<div class="cart-line"><div class="cart-thumb">' + (e.p.image ? '<img src="uploads/' + esc(e.p.image) + '" alt="">' : esc(e.p.emoji || '🍽️')) + '</div>' +
        '<div class="cart-info"><b>' + esc(e.p.name) + '</b><small>' + money(e.p.price_now) + ' × ' + e.q + '</small></div>' +
        '<div class="stepper"><button type="button" data-dec="' + e.p.id + '" aria-label="تقليل">−</button><b>' + e.q + '</b><button type="button" data-inc="' + e.p.id + '" aria-label="زيادة">+</button></div></div>';
    }).join('');
    $('#checkout').hidden = false;
    renderSummary();
  }

  function renderSummary() {
    var q = state.quote;
    var sub = localSubtotal();
    var rows = '<div class="sum-row"><span>المجموع</span><b>' + money(q ? q.subtotal : sub) + '</b></div>';
    var total = q ? q.total : sub;
    if (q && q.discount > 0) {
      rows += '<div class="sum-row"><span>الخصم' + (q.coupon ? ' (' + esc(q.coupon) + ')' : '') + '</span><b>- ' + money(q.discount) + '</b></div>';
    }
    if (M.zones.length) {
      if (q && q.zone_required) {
        rows += '<div class="sum-row"><span>التوصيل</span><b>اختر المنطقة</b></div>';
      } else if (q) {
        rows += '<div class="sum-row"><span>التوصيل' + (q.zone_name ? ' (' + esc(q.zone_name) + ')' : '') + '</span><b>' + (q.delivery_fee ? money(q.delivery_fee) : 'مجاني') + '</b></div>';
      }
    }
    rows += '<div class="sum-row total"><span>الإجمالي</span><span>' + money(total) + '</span></div>';
    var min = M.settings.min_order;
    if (min > 0 && sub < min) {
      rows += '<div class="alert warn">الحد الأدنى للطلب ' + money(min) + '.</div>';
    }
    if (M.settings.free_delivery_over > 0 && M.zones.length) {
      rows += '<div class="muted">توصيل مجاني للطلبات من ' + money(M.settings.free_delivery_over) + ' فأكثر.</div>';
    }
    $('#summary').innerHTML = rows;
    $('#foot-total').textContent = money(total);
    $('#submit-order').disabled = !M.settings.open || (min > 0 && sub < min);
  }

  function scheduleQuote() {
    clearTimeout(state.quoteTimer);
    if (!cartCount()) { state.quote = null; return; }
    state.quoteTimer = setTimeout(function () {
      var items = cartEntries().map(function (e) { return { id: e.p.id, qty: e.q }; });
      api('quote', { items: items, zone_id: parseInt($('#f-zone').value, 10) || 0, coupon: state.coupon }).then(function (q) {
        state.quote = q;
        var msg = $('#coupon-msg');
        if (state.coupon && q.coupon_error) {
          msg.textContent = q.coupon_error; msg.className = 'bad'; state.coupon = '';
        } else if (state.coupon && q.coupon) {
          msg.textContent = 'تم تطبيق كود الخصم.'; msg.className = 'ok';
        }
        renderSummary();
      }).catch(function () { /* التقدير المحلي يبقى معروضًا */ });
    }, 280);
  }

  function renderZones() {
    var wrap = $('#zone-wrap');
    if (!M.zones.length) { wrap.hidden = true; return; }
    wrap.hidden = false;
    var sel = $('#f-zone');
    var prev = sel.value || (load(PROFILE_KEY, {}).zone || '');
    sel.innerHTML = '<option value="">اختر المنطقة</option>' + M.zones.map(function (z) {
      return '<option value="' + z.id + '">' + esc(z.name) + ' — ' + (z.fee ? fmt(z.fee) + ' ' + esc(cur()) : 'توصيل مجاني') + '</option>';
    }).join('');
    if (prev && M.zones.some(function (z) { return String(z.id) === String(prev); })) { sel.value = prev; }
  }

  function renderAll(refreshQuote) {
    cleanCart();
    renderStatus();
    renderChips();
    renderProducts();
    renderCart();
    if (refreshQuote) { scheduleQuote(); }
  }

  /* ---------- الدرج ---------- */
  function openDrawer() {
    $('#overlay').hidden = false;
    var d = $('#drawer');
    d.classList.add('open');
    d.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    scheduleQuote();
  }
  function closeDrawer() {
    $('#overlay').hidden = true;
    var d = $('#drawer');
    d.classList.remove('open');
    d.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  /* ---------- إرسال الطلب ---------- */
  function submitOrder(ev) {
    ev.preventDefault();
    var err = $('#form-error');
    err.textContent = '';
    var name = $('#f-name').value.trim(), phone = $('#f-phone').value.trim(), address = $('#f-address').value.trim();
    var zone = parseInt($('#f-zone').value, 10) || 0;
    if (name.length < 2) { err.textContent = 'اكتب اسمك.'; return; }
    if (phone.replace(/\D/g, '').length < 8) { err.textContent = 'اكتب رقم هاتف صحيح.'; return; }
    if (M.zones.length && !zone) { err.textContent = 'اختر منطقة التوصيل.'; return; }
    if (address.length < 5) { err.textContent = 'اكتب العنوان بالتفصيل.'; return; }
    var btn = $('#submit-order');
    btn.disabled = true;
    $('span', btn).textContent = 'جارٍ الإرسال...';
    var payload = {
      name: name, phone: phone, address: address, notes: $('#f-notes').value.trim(), zone_id: zone,
      coupon: state.coupon, website: $('#f-website').value,
      items: cartEntries().map(function (e) { return { id: e.p.id, qty: e.q }; })
    };
    api('order', payload).then(function (r) {
      var list = load(ORD_KEY, []);
      list.unshift({ code: r.code, phone: r.phone, at: Date.now() });
      save(ORD_KEY, list.slice(0, 10));
      save(PROFILE_KEY, { name: name, phone: phone, address: address, zone: zone || '' });
      cart = {};
      save(CART_KEY, cart);
      state.coupon = '';
      state.quote = null;
      $('#f-coupon').value = '';
      $('#coupon-msg').textContent = '';
      $('#f-notes').value = '';
      renderAll(false);
      closeDrawer();
      $('#success-code').textContent = r.code;
      $('#success-total').textContent = money(r.total);
      $('#success').hidden = false;
      $('#success').dataset.code = r.code;
      $('#success').dataset.phone = r.phone;
    }).catch(function (e) {
      err.textContent = e.message;
      refreshMenu();
    }).then(function () {
      $('span', btn).textContent = 'تأكيد الطلب';
      renderSummary();
    });
  }

  /* ---------- تتبع الطلب ---------- */
  function renderTrack(o) {
    var box = $('#track-result');
    var html = '<div class="order-card"><h3>الطلب <span dir="ltr">' + esc(o.code) + '</span></h3>';
    if (o.status === 'cancelled') {
      html += '<div class="alert bad">تم إلغاء هذا الطلب.</div>';
    } else {
      var idx = 0;
      STEPS.forEach(function (s, i) { if (s[0] === o.status) { idx = i; } });
      html += '<ol class="steps">' + STEPS.map(function (s, i) {
        return '<li class="' + (i <= idx ? 'done' : '') + (i === idx ? ' now' : '') + '">' + s[1] + '</li>';
      }).join('') + '</ol>';
    }
    if (o.cancel_requested && o.status !== 'cancelled') {
      html += '<div class="alert warn">تم إرسال طلب الإلغاء، بانتظار تأكيد المتجر.</div>';
    }
    html += '<ul class="lines">' + o.items.map(function (i) {
      return '<li><span>' + esc(i.name) + ' × ' + i.qty + '</span><b>' + money(i.unit_price * i.qty) + '</b></li>';
    }).join('') + '</ul>';
    html += '<div class="sum-row"><span>المجموع</span><b>' + money(o.subtotal) + '</b></div>';
    if (o.discount > 0) { html += '<div class="sum-row"><span>الخصم</span><b>- ' + money(o.discount) + '</b></div>'; }
    if (o.zone_name) { html += '<div class="sum-row"><span>التوصيل (' + esc(o.zone_name) + ')</span><b>' + (o.delivery_fee ? money(o.delivery_fee) : 'مجاني') + '</b></div>'; }
    html += '<div class="sum-row total"><span>الإجمالي</span><span>' + money(o.total) + '</span></div>';
    if (o.can_cancel) { html += '<button class="btn btn-light" type="button" id="cancel-order" style="margin-top:12px">طلب إلغاء الطلب</button>'; }
    html += '</div>';
    box.innerHTML = html;
    var c = $('#cancel-order');
    if (c) {
      c.addEventListener('click', function () {
        if (!confirm('هل تريد إرسال طلب إلغاء لهذا الطلب؟')) { return; }
        api('cancel', state.trackArgs).then(function () { toast('تم إرسال طلب الإلغاء'); doTrack(true); }).catch(function (e) { toast(e.message); });
      });
    }
  }

  function doTrack(silent) {
    var args = state.trackArgs;
    if (!args) { return; }
    api('track', args).then(function (o) {
      renderTrack(o);
      clearTimeout(state.trackTimer);
      if (o.status !== 'delivered' && o.status !== 'cancelled') {
        state.trackTimer = setTimeout(function () { doTrack(true); }, 30000);
      }
    }).catch(function (e) {
      clearTimeout(state.trackTimer);
      if (!silent) { $('#track-result').innerHTML = '<div class="alert bad">' + esc(e.message) + '</div>'; }
    });
  }

  function trackWith(code, phone) {
    $('#track-code').value = code;
    $('#track-phone').value = phone;
    state.trackArgs = { code: code, phone: phone };
    doTrack(false);
  }

  /* ---------- تحديث المنيو ---------- */
  function refreshMenu() {
    api('menu', null, 'GET').then(function (m) {
      M = m;
      renderNotices();
      renderZones();
      renderAll(true);
    }).catch(function () { /* تجاهل أخطاء الشبكة المؤقتة */ });
  }

  /* ---------- الأحداث ---------- */
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-add],[data-inc],[data-dec],[data-cat],[data-ad-add]');
    if (!t) { return; }
    if (t.dataset.add) { setQty(parseInt(t.dataset.add, 10), 1); toast('أُضيف إلى السلة'); }
    else if (t.dataset.adAdd) { setQty(parseInt(t.dataset.adAdd, 10), 1); toast('أُضيف إلى السلة'); }
    else if (t.dataset.inc) { setQty(parseInt(t.dataset.inc, 10), 1); }
    else if (t.dataset.dec) { setQty(parseInt(t.dataset.dec, 10), -1); }
    else if (t.dataset.cat !== undefined) { state.cat = parseInt(t.dataset.cat, 10); renderChips(); renderProducts(); }
  });

  $('#open-cart').addEventListener('click', openDrawer);
  $('#hero-cart').addEventListener('click', openDrawer);
  $('#mobile-bar').addEventListener('click', openDrawer);
  $('#tab-cart').addEventListener('click', openDrawer);
  $('#close-cart').addEventListener('click', closeDrawer);
  $('#overlay').addEventListener('click', closeDrawer);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeDrawer(); $('#success').hidden = true; } });
  $('#search').addEventListener('input', function (e) { state.q = e.target.value; renderProducts(); });
  $('#order-form').addEventListener('submit', submitOrder);
  $('#f-zone').addEventListener('change', scheduleQuote);
  $('#apply-coupon').addEventListener('click', function () {
    var code = $('#f-coupon').value.trim().toUpperCase();
    var msg = $('#coupon-msg');
    if (!code) { state.coupon = ''; msg.textContent = ''; scheduleQuote(); return; }
    state.coupon = code;
    msg.textContent = 'جارٍ التحقق...';
    msg.className = 'muted';
    scheduleQuote();
  });
  $('#track-form').addEventListener('submit', function (e) {
    e.preventDefault();
    trackWith($('#track-code').value.trim().toUpperCase(), $('#track-phone').value.trim());
  });
  $('#success-close').addEventListener('click', function () { $('#success').hidden = true; });
  $('#success-track').addEventListener('click', function () {
    var s = $('#success');
    s.hidden = true;
    trackWith(s.dataset.code, s.dataset.phone);
    $('#track').scrollIntoView({ behavior: 'smooth' });
  });
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { refreshMenu(); } });

  /* ظل الهيدر عند التمرير */
  var topbar = $('.topbar');
  var onScroll = function () { topbar.classList.toggle('scrolled', window.scrollY > 6); };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  /* ---------- البدء ---------- */
  var profile = load(PROFILE_KEY, {});
  $('#f-name').value = profile.name || '';
  $('#f-phone').value = profile.phone || '';
  $('#f-address').value = profile.address || '';
  var orders = load(ORD_KEY, []);
  if (orders.length) {
    $('#track-code').value = orders[0].code;
    $('#track-phone').value = orders[0].phone;
  }
  renderNotices();
  renderZones();
  renderAll(true);
  setInterval(refreshMenu, 120000);

  /* إبراز التبويب الحالي في الشريط السفلي */
  if ('IntersectionObserver' in window) {
    var tabs = document.querySelectorAll('.tabbar [data-tab]');
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting) { return; }
        tabs.forEach(function (t) { t.classList.toggle('active', t.dataset.tab === en.target.id); });
      });
    }, { rootMargin: '-35% 0px -55% 0px' });
    ['menu', 'track', 'contact'].forEach(function (id) { var el = document.getElementById(id); if (el) { io.observe(el); } });
  }
})();
