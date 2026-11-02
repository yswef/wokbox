(function () {
  'use strict';

  const API = '../api/admin.php';
  const ST = { new: 'جديد', confirmed: 'مؤكد', preparing: 'قيد التجهيز', ready: 'جاهز', out: 'خرج للتوصيل', delivered: 'تم التسليم', cancelled: 'ملغي' };
  const NAV = [
    ['overview', 'نظرة عامة'], ['orders', 'الطلبات'], ['products', 'المنيو والأسعار'], ['categories', 'الأقسام'],
    ['offers', 'العروض والخصومات'], ['coupons', 'أكواد الخصم'], ['zones', 'مناطق التوصيل'], ['ads', 'الإعلانات'],
    ['customers', 'العملاء'], ['reports', 'التقارير'], ['admins', 'المديرون'], ['settings', 'الإعدادات'], ['backup', 'النسخ الاحتياطي']
  ];

  let CSRF = '';
  let CUR = 'ريال';
  let STORE = '';
  let current = 'overview';
  let lastId = null;
  let pollTimer = null;
  let ordersFilter = { status: '', q: '', from: '', to: '', cancel_requested: '' };

  const $ = (s, r) => (r || document).querySelector(s);
  const esc = v => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmt = n => Number(n || 0).toLocaleString('en-US');
  const money = n => fmt(n) + ' ' + CUR;

  function toast(msg, bad) {
    const t = $('#toast');
    t.textContent = msg;
    t.classList.toggle('bad', !!bad);
    t.classList.add('show');
    clearTimeout(toast.t);
    toast.t = setTimeout(() => t.classList.remove('show'), 2400);
  }

  async function api(action, data) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('csrf', CSRF);
    Object.keys(data || {}).forEach(k => {
      const v = data[k];
      if (v !== undefined && v !== null) { fd.append(k, v); }
    });
    const r = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' });
    let j;
    try { j = await r.json(); } catch (e) { throw new Error('استجابة غير صالحة من الخادم'); }
    if (!j.ok) {
      if (r.status === 401) { showLogin(); }
      throw new Error(j.error || 'حدث خطأ');
    }
    return j.data;
  }

  function guard(fn) {
    return async (...a) => {
      try { return await fn(...a); } catch (e) { toast(e.message, true); }
    };
  }

  /* ---------- نماذج الإدخال ---------- */
  function fieldHtml(f, v) {
    const val = v == null ? '' : v;
    const cls = 'f' + (f.full ? ' full' : '');
    if (f.type === 'select') {
      return `<label class="${cls}">${esc(f.label)}<select name="${f.name}">${f.options.map(o =>
        `<option value="${esc(o[0])}"${String(o[0]) === String(val) ? ' selected' : ''}>${esc(o[1])}</option>`).join('')}</select></label>`;
    }
    if (f.type === 'textarea') {
      return `<label class="${cls}">${esc(f.label)}<textarea name="${f.name}">${esc(val)}</textarea></label>`;
    }
    if (f.type === 'file') {
      return `<div class="${cls}">${esc(f.label)}<input type="file" name="${f.name}" accept="image/*">${f.current ?
        `<div class="cur-img"><img src="../uploads/${esc(f.current)}" alt=""><label class="chk"><input type="checkbox" name="${f.remove}" value="1"> حذف الصورة الحالية</label></div>` : ''}</div>`;
    }
    return `<label class="${cls}">${esc(f.label)}<input type="${f.type || 'text'}" name="${f.name}" value="${esc(val)}"${f.min !== undefined ? ' min="' + f.min + '"' : ''}${f.dir ? ' dir="' + f.dir + '"' : ''}${f.placeholder ? ' placeholder="' + esc(f.placeholder) + '"' : ''}></label>`;
  }

  function collect(form, fields) {
    const out = {};
    fields.forEach(f => {
      if (f.type === 'file') {
        const el = form.elements[f.name];
        if (el && el.files[0]) { out[f.name] = el.files[0]; }
        if (f.remove) {
          const rm = form.elements[f.remove];
          if (rm && rm.checked) { out[f.remove] = '1'; }
        }
      } else {
        out[f.name] = form.elements[f.name].value;
      }
    });
    return out;
  }

  function openForm(opt) {
    const wrap = document.createElement('div');
    wrap.className = 'modal';
    const vals = opt.values || {};
    wrap.innerHTML = `<form class="form-box" novalidate><h3>${esc(opt.title)}</h3><div class="fgrid">${opt.fields.map(f => fieldHtml(f, vals[f.name])).join('')}</div>
      <div class="form-error" role="alert"></div><div class="modal-actions"><button class="btn btn-primary" type="submit">${esc(opt.submit || 'حفظ')}</button><button class="btn btn-light" type="button" data-close>إلغاء</button></div></form>`;
    document.body.appendChild(wrap);
    const form = $('form', wrap);
    const close = () => wrap.remove();
    wrap.addEventListener('mousedown', e => { if (e.target === wrap) { close(); } });
    $('[data-close]', wrap).addEventListener('click', close);
    form.addEventListener('submit', async e => {
      e.preventDefault();
      const btn = $('button[type=submit]', form);
      btn.disabled = true;
      $('.form-error', form).textContent = '';
      try {
        await opt.onSubmit(collect(form, opt.fields));
        close();
      } catch (err) {
        $('.form-error', form).textContent = err.message;
        btn.disabled = false;
      }
    });
    const first = form.querySelector('input,select,textarea');
    if (first) { first.focus(); }
  }

  /* ---------- قوالب جدول CRUD عامة ---------- */
  async function crud(root, c) {
    const { rows, ctx } = await c.load();
    const head = c.cols.map(x => `<th>${x[0]}</th>`).join('') + '<th>إجراءات</th>';
    const body = rows.length ? rows.map(r => `<tr>${c.cols.map(x => `<td data-label="${esc(x[0])}">${x[1](r, ctx)}</td>`).join('')}<td class="act-cell"><div class="acts">
      <button class="btn btn-light btn-sm" data-act="edit" data-id="${r.id}">تعديل</button>
      ${c.toggle ? `<button class="btn btn-light btn-sm" data-act="toggle" data-id="${r.id}">${(c.isOn ? c.isOn(r) : r.active) ? 'إيقاف' : 'تفعيل'}</button>` : ''}
      <button class="btn btn-danger btn-sm" data-act="del" data-id="${r.id}">حذف</button></div></td></tr>`).join('')
      : `<tr><td colspan="${c.cols.length + 1}" class="empty">${c.empty || 'لا توجد بيانات.'}</td></tr>`;
    root.innerHTML = `<div class="bar"><h2>${c.title}</h2><button class="btn btn-primary" data-act="add">+ ${c.add}</button></div>
      ${c.note ? `<div class="info">${c.note}</div>` : ''}<div class="tablewrap cardm"><table><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table></div>`;
    const reload = () => render(current);
    const form = row => openForm({
      title: row ? c.editTitle : c.add,
      fields: c.fields(ctx, row),
      values: row || c.defaults || {},
      onSubmit: async d => {
        await api(c.save, Object.assign({ id: row ? row.id : '' }, d));
        toast('تم الحفظ');
        reload();
      }
    });
    root.onclick = guard(async e => {
      const b = e.target.closest('[data-act]');
      if (!b) { return; }
      const act = b.dataset.act;
      const row = rows.find(r => String(r.id) === b.dataset.id);
      if (act === 'add') { form(null); }
      else if (act === 'edit') { form(row); }
      else if (act === 'toggle') { await api(c.toggle, { id: row.id }); reload(); }
      else if (act === 'del') {
        if (!confirm(c.confirmDelete || 'هل تريد الحذف نهائيًا؟')) { return; }
        await api(c.del, { id: row.id });
        toast('تم الحذف');
        reload();
      }
    });
  }

  const onOff = on => `<span class="pill ${on ? 'on' : 'off'}">${on ? 'مفعّل' : 'متوقف'}</span>`;
  const yesNo = [['1', 'نعم'], ['0', 'لا']];
  const onOffOpts = [['1', 'مفعّل'], ['0', 'متوقف']];
  const kindOpts = [['percent', 'نسبة مئوية %'], ['fixed', 'مبلغ ثابت']];
  const kindText = r => r.type === 'percent' ? r.value + '%' : money(r.value);

  /* ---------- العروض الأولية للواجهات ---------- */
  const views = {};

  views.overview = async root => {
    const s = await api('stats');
    const max = Math.max(1, ...s.series.map(d => d.sales));
    root.innerHTML = `<div class="bar"><h2>نظرة عامة</h2></div>
      ${s.pending ? `<div class="warn">يوجد ${s.pending} طلب جديد بانتظار المعالجة. <button class="btn btn-primary btn-sm" data-go="orders">عرض الطلبات</button></div>` : ''}
      <div class="cards">
        <div class="stat"><span>مبيعات اليوم</span><b>${money(s.today.sales)}</b></div>
        <div class="stat"><span>طلبات اليوم</span><b>${fmt(s.today.orders)}</b></div>
        <div class="stat"><span>مبيعات الشهر</span><b>${money(s.month.sales)}</b></div>
        <div class="stat"><span>إجمالي المبيعات</span><b>${money(s.all.sales)}</b></div>
        <div class="stat"><span>إجمالي الطلبات</span><b>${fmt(s.all.orders)}</b></div>
        <div class="stat"><span>العملاء</span><b>${fmt(s.customers)}</b></div>
        <div class="stat"><span>المنتجات المتاحة</span><b>${s.available} / ${s.products}</b></div>
      </div>
      <div class="panel"><h3>المبيعات آخر 14 يومًا</h3><div class="bars">${s.series.map(d =>
        `<div title="${esc(d.date)}: ${money(d.sales)} (${d.orders} طلب)"><i style="height:${Math.round(d.sales / max * 100)}%"></i><span>${esc(d.date.slice(8))}</span></div>`).join('')}</div></div>`;
    root.onclick = e => { const g = e.target.closest('[data-go]'); if (g) { go(g.dataset.go); } };
  };

  views.orders = async root => {
    const f = ordersFilter;
    const data = await api('orders_list', f);
    const total = Object.values(data.counts).reduce((a, b) => a + b, 0);
    const chips = [['', 'الكل', total]].concat(Object.keys(ST).map(k => [k, ST[k], data.counts[k] || 0]));
    root.innerHTML = `<div class="bar"><h2>الطلبات</h2><button class="btn btn-light" id="o-refresh">تحديث</button></div>
      ${data.cancel_requests ? `<div class="warn">${data.cancel_requests} طلب إلغاء من العملاء بانتظار قرارك. <button class="btn btn-light btn-sm" data-cr="1">عرضها</button></div>` : ''}
      <div class="filters chips-row">${chips.map(c => `<button class="chip${f.status === c[0] && !f.cancel_requested ? ' active' : ''}" data-st="${c[0]}">${c[1]} (${c[2]})</button>`).join('')}</div>
      <div class="filters">
        <input id="o-q" placeholder="بحث: رقم الطلب / الاسم / الهاتف" value="${esc(f.q)}" style="min-width:230px">
        <input id="o-from" type="date" value="${esc(f.from)}" aria-label="من تاريخ"><input id="o-to" type="date" value="${esc(f.to)}" aria-label="إلى تاريخ">
        <button class="btn btn-primary btn-sm" id="o-apply">تطبيق</button><button class="btn btn-light btn-sm" id="o-clear">مسح الفلاتر</button>
      </div>
      <div class="orders">${data.orders.length ? data.orders.map(orderCard).join('') : '<div class="panel empty">لا توجد طلبات مطابقة.</div>'}</div>`;

    const apply = () => {
      ordersFilter = Object.assign({}, ordersFilter, { q: $('#o-q').value, from: $('#o-from').value, to: $('#o-to').value });
      render('orders');
    };
    $('#o-apply').onclick = apply;
    $('#o-q').onkeydown = e => { if (e.key === 'Enter') { apply(); } };
    $('#o-refresh').onclick = () => render('orders');
    $('#o-clear').onclick = () => { ordersFilter = { status: '', q: '', from: '', to: '', cancel_requested: '' }; render('orders'); };
    root.onclick = guard(async e => {
      const chip = e.target.closest('[data-st]');
      if (chip) { ordersFilter = Object.assign({}, ordersFilter, { status: chip.dataset.st, cancel_requested: '' }); return render('orders'); }
      if (e.target.closest('[data-cr]')) { ordersFilter = Object.assign({}, ordersFilter, { status: '', cancel_requested: '1' }); return render('orders'); }
      const p = e.target.closest('[data-print]');
      if (p) { window.open('invoice.php?id=' + p.dataset.print, '_blank', 'width=420,height=720'); return; }
      const rj = e.target.closest('[data-reject]');
      if (rj) { await api('order_reject_cancel', { id: rj.dataset.reject }); toast('تم رفض طلب الإلغاء'); return render('orders'); }
      const ok = e.target.closest('[data-cancel]');
      if (ok) {
        if (!confirm('تأكيد إلغاء هذا الطلب؟')) { return; }
        await api('order_status', { id: ok.dataset.cancel, status: 'cancelled', expected_status: ok.dataset.expected });
        toast('تم إلغاء الطلب');
        return render('orders');
      }
    });
    root.onchange = guard(async e => {
      const sel = e.target.closest('[data-status]');
      if (!sel) { return; }
      try {
        await api('order_status', { id: sel.dataset.status, status: sel.value, expected_status: sel.dataset.expected });
        toast('تم تحديث الحالة');
      } finally { render('orders'); }
    });
  };

  function waLink(o, text) {
    let p = String(o.phone || '').replace(/\D/g, '');
    if (p.length === 9) { p = '967' + p; }
    return 'https://wa.me/' + p + '?text=' + encodeURIComponent(text);
  }

  function orderCard(o) {
    const next = { new: ['confirmed', 'cancelled'], confirmed: ['preparing', 'cancelled'], preparing: ['ready', 'cancelled'], ready: ['out', 'cancelled'], out: ['delivered', 'cancelled'], delivered: [], cancelled: [] };
    const msg = `${STORE}\nتحديث طلبك ${o.code}\nالحالة: ${ST[o.status] || o.status}\nالإجمالي: ${fmt(o.total)} ${CUR}\nشكرًا لطلبك!`;
    return `<div class="order ${o.status === 'new' ? 'new' : ''}">
      <div class="o-head"><b dir="ltr">${esc(o.code)}</b><span class="s s-${esc(o.status)}">${esc(ST[o.status] || o.status)}</span><span class="muted" dir="ltr">${esc(o.created_at.slice(0, 16))}</span></div>
      <div class="o-grid"><div><b>العميل:</b> ${esc(o.customer_name)}</div><div><b>الهاتف:</b> <span dir="ltr">${esc(o.phone)}</span></div>
        <div><b>العنوان:</b> ${esc(o.address)}${o.zone_name ? ' — ' + esc(o.zone_name) : ''}</div>${o.notes ? `<div><b>ملاحظات:</b> ${esc(o.notes)}</div>` : ''}</div>
      <ul class="o-items">${o.items.map(i => `<li><span>${esc(i.name)} × ${i.qty}</span><span>${money(i.unit_price * i.qty)}</span></li>`).join('')}</ul>
      <div class="muted">المجموع ${money(o.subtotal)}${o.discount ? ' — خصم ' + money(o.discount) + (o.coupon_code ? ' (' + esc(o.coupon_code) + ')' : '') : ''}${o.zone_name ? ' — توصيل ' + (o.delivery_fee ? money(o.delivery_fee) : 'مجاني') : ''}</div>
      ${o.cancel_requested && o.status !== 'cancelled' ? `<div class="warn">العميل طلب إلغاء هذا الطلب. <button class="btn btn-danger btn-sm" data-cancel="${o.id}" data-expected="${esc(o.status)}">تأكيد الإلغاء</button><button class="btn btn-light btn-sm" data-reject="${o.id}">رفض الطلب</button></div>` : ''}
      <div class="o-foot"><span class="o-total">${money(o.total)}</span><div class="acts">
        <select data-status="${o.id}" data-expected="${esc(o.status)}" aria-label="حالة الطلب">${[o.status].concat(next[o.status] || []).map(k => `<option value="${k}"${k === o.status ? ' selected' : ''}>${ST[k]}</option>`).join('')}</select>
        <button class="btn btn-light btn-sm" data-print="${o.id}">طباعة فاتورة</button>
        <a class="btn btn-light btn-sm" href="${esc(waLink(o, msg))}" target="_blank" rel="noopener">رسالة واتساب</a></div></div></div>`;
  }

  views.products = root => crud(root, {
    title: 'المنيو والأسعار', add: 'إضافة منتج', editTitle: 'تعديل المنتج',
    load: async () => {
      const d = await api('products_list');
      const cats = {};
      d.categories.forEach(c => { cats[c.id] = c; });
      return { rows: d.products, ctx: { cats, list: d.categories } };
    },
    cols: [
      ['المنتج', (r) => (r.image ? `<img class="thumb" src="../uploads/${esc(r.image)}" alt="">` : `<span class="thumb">${esc(r.emoji || '🍽️')}</span>`) + esc(r.name)],
      ['القسم', (r, x) => esc((x.cats[r.category_id] || {}).name || '—')],
      ['السعر', r => money(r.price)],
      ['الحالة', r => `<span class="pill ${r.available ? 'on' : 'off'}">${r.available ? 'متوفر' : 'غير متوفر'}</span>`]
    ],
    toggle: 'product_toggle', isOn: r => r.available, save: 'product_save', del: 'product_delete',
    confirmDelete: 'حذف المنتج نهائيًا؟ ستُحذف العروض المرتبطة به أيضًا.',
    defaults: { available: 1, sort_order: 0, emoji: '🍽️' },
    fields: (x, r) => [
      { name: 'name', label: 'اسم المنتج' },
      { name: 'price', label: 'السعر (' + CUR + ')', type: 'number', min: 0 },
      { name: 'category_id', label: 'القسم', type: 'select', options: x.list.map(c => [c.id, c.name]) },
      { name: 'available', label: 'التوفر', type: 'select', options: [['1', 'متوفر'], ['0', 'غير متوفر مؤقتًا']] },
      { name: 'emoji', label: 'رمز بديل (إيموجي)' },
      { name: 'sort_order', label: 'الترتيب', type: 'number' },
      { name: 'description', label: 'وصف مختصر', full: true },
      { name: 'image', label: 'صورة المنتج (اختياري)', type: 'file', full: true, current: r && r.image, remove: 'remove_image' }
    ]
  });

  views.categories = root => crud(root, {
    title: 'الأقسام', add: 'إضافة قسم', editTitle: 'تعديل القسم',
    load: async () => ({ rows: await api('categories_list'), ctx: {} }),
    cols: [['القسم', r => esc((r.emoji ? r.emoji + ' ' : '') + r.name)], ['الترتيب', r => r.sort_order], ['عدد المنتجات', r => r.products_count]],
    save: 'category_save', del: 'category_delete',
    confirmDelete: 'حذف القسم؟ ستُنقل منتجاته تلقائيًا إلى قسم آخر.',
    defaults: { sort_order: 0 },
    fields: () => [
      { name: 'name', label: 'اسم القسم' }, { name: 'emoji', label: 'رمز (إيموجي)' },
      { name: 'sort_order', label: 'الترتيب', type: 'number' }
    ]
  });

  views.offers = root => crud(root, {
    title: 'العروض والخصومات', add: 'إضافة عرض', editTitle: 'تعديل العرض',
    note: 'العرض يخفض سعر المنتج تلقائيًا في المتجر. إذا انطبق أكثر من عرض على منتج يُطبَّق الأقل سعرًا.',
    load: async () => {
      const [rows, p] = await Promise.all([api('offers_list'), api('products_list')]);
      const names = {};
      p.products.forEach(x => { names[x.id] = x.name; });
      return { rows, ctx: { names, products: p.products } };
    },
    cols: [
      ['العرض', r => esc(r.name)], ['الخصم', r => kindText(r)],
      ['المنتج', (r, x) => r.product_id === null ? 'كل المنتجات' : esc(x.names[r.product_id] || '—')],
      ['الفترة', r => esc((r.starts_on || 'الآن') + ' ← ' + (r.ends_on || 'بدون نهاية'))],
      ['الحالة', r => onOff(r.active)]
    ],
    toggle: 'offer_toggle', save: 'offer_save', del: 'offer_delete',
    defaults: { type: 'percent', active: 1, product_id: 'all' },
    fields: x => [
      { name: 'name', label: 'اسم العرض', full: true },
      { name: 'type', label: 'نوع الخصم', type: 'select', options: kindOpts },
      { name: 'value', label: 'قيمة الخصم', type: 'number', min: 1 },
      { name: 'product_id', label: 'المنتج المشمول', type: 'select', options: [['all', 'كل المنتجات']].concat(x.products.map(p => [p.id, p.name])) },
      { name: 'active', label: 'الحالة', type: 'select', options: onOffOpts },
      { name: 'starts_on', label: 'تاريخ البداية (اختياري)', type: 'date' },
      { name: 'ends_on', label: 'تاريخ النهاية (اختياري)', type: 'date' }
    ]
  });

  views.coupons = root => crud(root, {
    title: 'أكواد الخصم', add: 'إضافة كود', editTitle: 'تعديل الكود',
    note: 'يُدخل العميل الكود عند إتمام الطلب. يُحتسب الخصم على مجموع الطلب بعد العروض.',
    load: async () => ({ rows: await api('coupons_list'), ctx: {} }),
    cols: [
      ['الكود', r => `<b dir="ltr">${esc(r.code)}</b>`], ['الخصم', r => kindText(r)],
      ['الحد الأدنى', r => r.min_order ? money(r.min_order) : '—'],
      ['الاستخدام', r => r.used_count + (r.max_uses ? ' / ' + r.max_uses : ' / ∞')],
      ['ينتهي', r => esc(r.expires_on || 'بدون نهاية')], ['الحالة', r => onOff(r.active)]
    ],
    toggle: 'coupon_toggle', save: 'coupon_save', del: 'coupon_delete',
    defaults: { type: 'percent', active: 1, min_order: 0 },
    fields: () => [
      { name: 'code', label: 'الكود (إنجليزي/أرقام)', dir: 'ltr' },
      { name: 'type', label: 'نوع الخصم', type: 'select', options: kindOpts },
      { name: 'value', label: 'قيمة الخصم', type: 'number', min: 1 },
      { name: 'min_order', label: 'الحد الأدنى للطلب', type: 'number', min: 0 },
      { name: 'max_uses', label: 'أقصى عدد استخدامات (اتركه فارغًا لغير محدود)', type: 'number', min: 1 },
      { name: 'expires_on', label: 'تاريخ الانتهاء (اختياري)', type: 'date' },
      { name: 'active', label: 'الحالة', type: 'select', options: onOffOpts }
    ]
  });

  views.zones = root => crud(root, {
    title: 'مناطق التوصيل', add: 'إضافة منطقة', editTitle: 'تعديل المنطقة',
    note: 'إذا أضفت منطقة واحدة على الأقل يصبح اختيار المنطقة إلزاميًا عند الطلب وتُضاف رسومها للإجمالي. بدون مناطق لا تُضاف رسوم توصيل.',
    load: async () => ({ rows: await api('zones_list'), ctx: {} }),
    cols: [['المنطقة', r => esc(r.name)], ['رسوم التوصيل', r => r.fee ? money(r.fee) : 'مجاني'], ['الحالة', r => onOff(r.active)]],
    toggle: 'zone_toggle', save: 'zone_save', del: 'zone_delete', defaults: { active: 1, fee: 0 },
    fields: () => [
      { name: 'name', label: 'اسم المنطقة', full: true },
      { name: 'fee', label: 'رسوم التوصيل', type: 'number', min: 0 },
      { name: 'active', label: 'الحالة', type: 'select', options: onOffOpts }
    ]
  });

  views.ads = root => crud(root, {
    title: 'الإعلانات', add: 'إضافة إعلان', editTitle: 'تعديل الإعلان',
    note: 'الإعلانات المفعّلة تظهر أعلى المنيو في المتجر.',
    load: async () => {
      const [rows, p] = await Promise.all([api('ads_list'), api('products_list')]);
      const names = {};
      p.products.forEach(x => { names[x.id] = x.name; });
      return { rows, ctx: { names, products: p.products } };
    },
    cols: [
      ['الإعلان', r => (r.image ? `<img class="thumb" src="../uploads/${esc(r.image)}" alt="">` : '') + esc(r.title)],
      ['المنتج المرتبط', (r, x) => r.product_id === null ? '—' : esc(x.names[r.product_id] || '—')], ['الحالة', r => onOff(r.active)]
    ],
    toggle: 'ad_toggle', save: 'ad_save', del: 'ad_delete', defaults: { active: 1 },
    fields: (x, r) => [
      { name: 'title', label: 'عنوان الإعلان', full: true },
      { name: 'description', label: 'التفاصيل', type: 'textarea', full: true },
      { name: 'product_id', label: 'المنتج المرتبط', type: 'select', options: [['', 'بدون']].concat(x.products.map(p => [p.id, p.name])) },
      { name: 'active', label: 'الحالة', type: 'select', options: [['1', 'ظاهر'], ['0', 'مخفي']] },
      { name: 'image', label: 'صورة الإعلان (اختياري)', type: 'file', full: true, current: r && r.image, remove: 'remove_image' }
    ]
  });

  views.customers = async root => {
    root.innerHTML = `<div class="bar"><h2>ترتيب العملاء</h2></div>
      <div class="filters"><input id="c-q" placeholder="بحث بالاسم أو الهاتف" style="min-width:230px"><select id="c-sort"><option value="orders">الأكثر طلبًا</option><option value="spend">الأعلى إنفاقًا</option><option value="recent">الأحدث</option><option value="name">الاسم</option></select></div>
      <div id="c-body"></div>`;
    const load = guard(async () => {
      const rows = await api('customers_list', { q: $('#c-q').value, sort: $('#c-sort').value });
      $('#c-body').innerHTML = rows.length ? `<div class="tablewrap cardm"><table><thead><tr><th>#</th><th>العميل</th><th>الهاتف</th><th>الطلبات</th><th>المشتريات</th><th>آخر طلب</th><th></th></tr></thead><tbody>${rows.map((r, i) =>
        `<tr><td data-label="#">${i + 1}</td><td data-label="العميل"><b>${esc(r.name)}</b></td><td data-label="الهاتف" dir="ltr" style="text-align:right">${esc(r.phone)}</td><td data-label="الطلبات">${r.orders_count}</td><td data-label="المشتريات"><b>${money(r.spent)}</b></td><td data-label="آخر طلب" dir="ltr">${esc((r.last_order || '').slice(0, 16))}</td>
        <td class="act-cell"><button class="btn btn-light btn-sm" data-phone="${esc(r.phone)}">سجل الطلبات</button></td></tr>`).join('')}</tbody></table></div>` : '<div class="panel empty">لا توجد بيانات عملاء بعد.</div>';
    });
    $('#c-q').oninput = (() => { let t; return () => { clearTimeout(t); t = setTimeout(load, 300); }; })();
    $('#c-sort').onchange = load;
    root.onclick = e => {
      const b = e.target.closest('[data-phone]');
      if (b) { ordersFilter = { status: '', q: b.dataset.phone, from: '', to: '', cancel_requested: '' }; go('orders'); }
    };
    load();
  };

  views.reports = async root => {
    const s = await api('stats');
    const max = Math.max(1, ...s.top.map(t => t.qty));
    const dayMax = Math.max(1, ...s.series.map(d => d.sales));
    root.innerHTML = `<div class="bar"><h2>تقارير المبيعات</h2></div>
      <div class="info">تُحتسب المبيعات من الطلبات غير الملغاة المسجلة في النظام.</div>
      <div class="cards">
        <div class="stat"><span>مبيعات اليوم</span><b>${money(s.today.sales)}</b></div>
        <div class="stat"><span>مبيعات هذا الشهر</span><b>${money(s.month.sales)}</b></div>
        <div class="stat"><span>إجمالي المبيعات</span><b>${money(s.all.sales)}</b></div>
        <div class="stat"><span>إجمالي الطلبات</span><b>${fmt(s.all.orders)}</b></div></div>
      <div class="panel"><h3>آخر 14 يومًا</h3><div class="tablewrap"><table><thead><tr><th>التاريخ</th><th>الطلبات</th><th>المبيعات</th><th></th></tr></thead><tbody>${s.series.slice().reverse().map(d =>
        `<tr><td dir="ltr" style="text-align:right">${esc(d.date)}</td><td>${d.orders}</td><td>${money(d.sales)}</td><td><div class="hbar"><i style="width:${Math.round(d.sales / dayMax * 100)}%"></i></div></td></tr>`).join('')}</tbody></table></div></div>
      <div class="panel"><h3>المنتجات الأكثر طلبًا</h3>${s.top.length ? `<div class="tablewrap"><table><thead><tr><th>المنتج</th><th>الكمية</th><th>الإيراد</th><th></th></tr></thead><tbody>${s.top.map(t =>
        `<tr><td>${esc(t.name)}</td><td>${t.qty}</td><td>${money(t.revenue)}</td><td><div class="hbar"><i style="width:${Math.round(t.qty / max * 100)}%"></i></div></td></tr>`).join('')}</tbody></table></div>` : '<div class="empty">لا توجد بيانات كافية بعد.</div>'}</div>`;
  };

  views.settings = async root => {
    const s = await api('settings_get');
    root.innerHTML = `<div class="bar"><h2>إعدادات المتجر</h2></div>
      <form class="panel" id="set-form"><div class="settings-grid">
        <label class="f">اسم المتجر<input name="store_name" value="${esc(s.store_name)}"></label>
        <label class="f">الشعار الفرعي<input name="tagline" value="${esc(s.tagline)}"></label>
        <label class="f">ساعات العمل<input name="hours" value="${esc(s.hours)}"></label>
        <label class="f">حالة المتجر<select name="open"><option value="1"${s.open === '1' ? ' selected' : ''}>استقبال الطلبات مفتوح</option><option value="0"${s.open !== '1' ? ' selected' : ''}>الطلبات متوقفة مؤقتًا</option></select></label>
        <label class="f">رقم التواصل 1 (مع مفتاح الدولة)<input name="contact_1" value="${esc(s.contact_1)}" dir="ltr" inputmode="numeric" placeholder="967XXXXXXXXX"></label>
        <label class="f">رقم التواصل 2<input name="contact_2" value="${esc(s.contact_2)}" dir="ltr" inputmode="numeric"></label>
        <label class="f">اسم العملة<input name="currency" value="${esc(s.currency)}"></label>
        <label class="f">الحد الأدنى للطلب (0 = بدون)<input name="min_order" type="number" min="0" value="${esc(s.min_order)}"></label>
        <label class="f">توصيل مجاني للطلبات من (0 = غير مفعّل)<input name="free_delivery_over" type="number" min="0" value="${esc(s.free_delivery_over)}"></label>
        <label class="f">ملاحظة الدفع (تظهر عند الطلب)<input name="payment_note" value="${esc(s.payment_note)}"></label>
        <div class="f">شعار المتجر<input type="file" name="logo" accept="image/*">${s.logo ? `<div class="cur-img"><img src="../uploads/${esc(s.logo)}" alt=""><label class="chk"><input type="checkbox" name="remove_logo" value="1"> حذف الشعار</label></div>` : ''}</div>
      </div><div class="form-error" id="set-err"></div><button class="btn btn-primary" type="submit">حفظ الإعدادات</button></form>
      <form class="panel" id="pw-form"><h3>تغيير كلمة المرور</h3><div class="settings-grid">
        <label class="f">كلمة المرور الحالية<input name="current" type="password" autocomplete="current-password" dir="ltr"></label>
        <label class="f">كلمة المرور الجديدة (8 أحرف فأكثر)<input name="new" type="password" autocomplete="new-password" dir="ltr"></label></div>
        <div class="form-error" id="pw-err"></div><button class="btn btn-light" type="submit">تغيير كلمة المرور</button></form>`;
    $('#set-form').onsubmit = async e => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const data = {};
      fd.forEach((v, k) => { if (!(v instanceof File) || v.name) { data[k] = v; } });
      try {
        await api('settings_save', data);
        toast('تم حفظ الإعدادات');
        await loadMeta();
        render('settings');
      } catch (err) { $('#set-err').textContent = err.message; }
    };
    $('#pw-form').onsubmit = async e => {
      e.preventDefault();
      const fd = new FormData(e.target);
      try {
        await api('password_change', { current: fd.get('current'), new: fd.get('new') });
        toast('تم تغيير كلمة المرور');
        e.target.reset();
        $('#pw-err').textContent = '';
      } catch (err) { $('#pw-err').textContent = err.message; }
    };
  };

  views.admins = async root => {
    const data = await api('admins_list');
    root.innerHTML = `<div class="bar"><h2>حسابات المديرين</h2></div>
      <div class="panel"><p class="muted">كل مدير لديه صلاحية كاملة لإدارة المتجر والنسخ الاحتياطية وحسابات المديرين. أضف الأشخاص الموثوقين فقط.</p>
      ${data.admins.map(a => `<p><b dir="ltr">${esc(a.username)}</b> ${Number(a.id) === data.current_id ? '(حسابك)' : `<button class="btn btn-danger btn-sm" data-delete-admin="${Number(a.id)}">حذف</button>`}</p>`).join('')}</div>
      <form class="panel" id="admin-add"><h3>إضافة مدير</h3><div class="settings-grid">
      <label class="f">اسم المستخدم<input name="username" dir="ltr" required minlength="3" maxlength="30" autocomplete="off"></label>
      <label class="f">كلمة المرور (12 حرفًا على الأقل)<input name="password" type="password" dir="ltr" required minlength="12" maxlength="72" autocomplete="new-password"></label></div>
      <div class="form-error" id="admin-error"></div><button class="btn btn-primary" type="submit">إضافة المدير</button></form>`;
    $('#admin-add').onsubmit = async e => {
      e.preventDefault();
      const fd = new FormData(e.target);
      try { await api('admin_create', { username: fd.get('username'), password: fd.get('password') }); toast('تمت إضافة المدير'); render('admins'); }
      catch (err) { $('#admin-error').textContent = err.message; }
    };
    root.onclick = guard(async e => {
      const button = e.target.closest('[data-delete-admin]');
      if (!button || !confirm('حذف هذا المدير وإلغاء وصوله؟')) return;
      const password = prompt('أدخل كلمة مرور حسابك للتأكيد:');
      if (password === null) return;
      await api('admin_delete', { id: button.dataset.deleteAdmin, password });
      toast('تم حذف المدير'); render('admins');
    });
  };

  views.backup = async root => {
    const s = await api('settings_get');
    root.innerHTML = `<div class="bar"><h2>النسخ الاحتياطي</h2></div>
      <div class="panel"><h3>تنزيل نسخة كاملة</h3>
        <p class="muted">تشمل المنتجات والأقسام والعروض والإعلانات والمناطق والأكواد والطلبات والعملاء والإعدادات وصور المنتجات، في ملف ZIP واحد. لا تشمل كلمة مرور الإدارة.</p>
        <p>آخر نسخة تم تنزيلها: <b>${esc(s.last_backup || 'لم تُنزَّل نسخة بعد')}</b></p>
        <button class="btn btn-primary" id="bk-down">تنزيل النسخة الاحتياطية</button></div>
      <div class="panel"><h3>استعادة نسخة</h3>
        <p class="muted">سيتم استبدال كل بيانات المتجر الحالية ببيانات الملف. يحفظ النظام تلقائيًا نسخة من الوضع الحالي على الخادم قبل الاستعادة. استخدم هذا الخيار أيضًا عند الانتقال إلى استضافة جديدة (ثبّت المتجر هناك أولًا ثم استعد النسخة).</p>
        <input type="file" id="bk-file" accept=".zip,.json,application/zip,application/json"><br><br>
        <button class="btn btn-danger" id="bk-restore">استعادة من الملف</button></div>`;
    $('#bk-down').onclick = () => {
      const f = document.createElement('form');
      f.method = 'POST'; f.action = API; f.style.display = 'none';
      [['action', 'backup_download'], ['csrf', CSRF]].forEach(p => {
        const i = document.createElement('input'); i.type = 'hidden'; i.name = p[0]; i.value = p[1]; f.appendChild(i);
      });
      document.body.appendChild(f);
      f.submit();
      f.remove();
      setTimeout(() => render('backup'), 2500);
    };
    $('#bk-restore').onclick = guard(async () => {
      const file = $('#bk-file').files[0];
      if (!file) { toast('اختر ملف النسخة أولًا', true); return; }
      if (!confirm('سيتم استبدال كل البيانات الحالية. هل أنت متأكد؟')) { return; }
      await api('backup_restore', { file });
      toast('تمت الاستعادة بنجاح');
      await loadMeta();
      lastId = null;
      render('backup');
    });
  };

  /* ---------- التنقل والتشغيل ---------- */
  function renderNav(badges) {
    $('#nav').innerHTML = NAV.map(n => `<button type="button" data-view="${n[0]}" class="${n[0] === current ? 'active' : ''}">${n[1]}${n[0] === 'orders' && badges && badges > 0 ? `<span class="badge">${badges}</span>` : ''}</button>`).join('');
    document.querySelectorAll('#mtabs [data-view]').forEach(b => b.classList.toggle('active', b.dataset.view === current));
    const mb = $('#mtabs-badge');
    mb.textContent = badges || 0;
    mb.hidden = !(badges > 0);
  }

  async function render(name) {
    current = name;
    renderNav(renderNav.pending);
    const root = $('#view');
    root.onclick = null;
    root.onchange = null;
    root.innerHTML = '<div class="empty">جارٍ التحميل...</div>';
    try { await views[name](root); } catch (e) { root.innerHTML = `<div class="panel empty">${esc(e.message)}</div>`; }
  }
  const go = name => render(name);

  async function loadMeta() {
    const s = await api('settings_get');
    CUR = s.currency || 'ريال';
    STORE = s.store_name || '';
    $('#side-name').textContent = STORE || 'المتجر';
    $('#mtop-name').textContent = STORE || 'لوحة الإدارة';
    ['#side-logo', '#mtop-logo'].forEach(id => { $(id).src = s.logo ? '../uploads/' + s.logo : '../assets/logo-192.png'; });
    document.title = 'لوحة الإدارة — ' + STORE;
  }

  function beep() {
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      [880, 1175].forEach((f, i) => {
        const o = ctx.createOscillator(), g = ctx.createGain();
        o.frequency.value = f; o.connect(g); g.connect(ctx.destination);
        g.gain.setValueAtTime(0.15, ctx.currentTime + i * 0.18);
        g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + i * 0.18 + 0.16);
        o.start(ctx.currentTime + i * 0.18); o.stop(ctx.currentTime + i * 0.18 + 0.17);
      });
    } catch (e) { /* الصوت غير مدعوم */ }
  }

  async function poll() {
    try {
      const p = await api('poll', { since: lastId === null ? 0 : lastId });
      renderNav.pending = p.pending;
      if (lastId === null) {
        lastId = p.max_id;
      } else if (p.new_count > 0) {
        lastId = p.max_id;
        beep();
        const o = p.latest[0];
        toast(`طلب جديد ${o.code} — ${o.customer_name} — ${fmt(o.total)} ${CUR}`);
        if ('Notification' in window && Notification.permission === 'granted') {
          try { new Notification('طلب جديد ' + o.code, { body: o.customer_name + ' — ' + fmt(o.total) + ' ' + CUR }); } catch (e) { /* ignore */ }
        }
        if (current === 'orders' || current === 'overview') { render(current); return; }
      }
      renderNav(p.pending);
    } catch (e) { /* يتجاهل أخطاء الشبكة المؤقتة */ }
  }

  function startApp() {
    $('#login').hidden = true;
    $('#app').hidden = false;
    loadMeta().then(() => {
      render(current);
      clearInterval(pollTimer);
      lastId = null;
      poll();
      pollTimer = setInterval(poll, 15000);
    }).catch(e => toast(e.message, true));
  }

  function showLogin() {
    clearInterval(pollTimer);
    $('#app').hidden = true;
    $('#login').hidden = false;
  }

  const setMenu = open => {
    document.body.classList.toggle('menu-open', open);
    $('#scrim').hidden = !open;
  };
  $('#nav').addEventListener('click', e => {
    const b = e.target.closest('[data-view]');
    if (b) { setMenu(false); go(b.dataset.view); window.scrollTo(0, 0); }
  });
  $('#menu-btn').addEventListener('click', () => setMenu(!document.body.classList.contains('menu-open')));
  $('#mtabs-more').addEventListener('click', () => setMenu(true));
  $('#scrim').addEventListener('click', () => setMenu(false));
  $('#mtabs').addEventListener('click', e => {
    const b = e.target.closest('[data-view]');
    if (b) { go(b.dataset.view); window.scrollTo(0, 0); }
  });
  $('#logout').addEventListener('click', async () => {
    try { await api('logout'); } catch (e) { /* ignore */ }
    CSRF = '';
    showLogin();
  });
  $('#enable-notify').addEventListener('click', async () => {
    if (!('Notification' in window)) { toast('المتصفح لا يدعم الإشعارات', true); return; }
    const p = await Notification.requestPermission();
    toast(p === 'granted' ? 'تم تفعيل التنبيهات على هذا الجهاز' : 'لم يتم السماح بالإشعارات من المتصفح', p !== 'granted');
    beep();
  });
  $('#login-form').addEventListener('submit', async e => {
    e.preventDefault();
    $('#l-error').textContent = '';
    const fd = new FormData();
    fd.append('action', 'login');
    fd.append('username', $('#l-user').value.trim());
    fd.append('password', $('#l-pass').value);
    try {
      const r = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' });
      const j = await r.json();
      if (!j.ok) { throw new Error(j.error || 'تعذر تسجيل الدخول'); }
      CSRF = j.data.csrf;
      $('#l-pass').value = '';
      startApp();
    } catch (err) { $('#l-error').textContent = err.message; }
  });

  (async function init() {
    try {
      const fd = new FormData();
      fd.append('action', 'me');
      const r = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' });
      const j = await r.json();
      if (j.ok) { CSRF = j.data.csrf; startApp(); } else { showLogin(); }
    } catch (e) { showLogin(); }
  })();
})();
