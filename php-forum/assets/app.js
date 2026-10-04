/* 极简论坛 · 原生 JS（零依赖）
   主题切换 / 移动端侧栏 / 验证码倒计时 / 后台保存与测试 / 危险操作确认 / 字数统计 */
(function () {
  'use strict';

  var $ = function (s, el) { return (el || document).querySelector(s); };
  var $$ = function (s, el) { return Array.prototype.slice.call((el || document).querySelectorAll(s)); };

  /* 与 PHP u() 保持一致的链接构造 */
  function apiUrl(qs) {
    var url = 'index.php' + (qs ? '?' + qs : '');
    if (window.DEMO_PORT) url += (qs ? '&' : '?') + 'XTransformPort=' + window.DEMO_PORT;
    return url;
  }
  function installUrl() {
    var url = 'install.php';
    if (window.DEMO_PORT) url += '?XTransformPort=' + window.DEMO_PORT;
    return url;
  }
  function csrf() {
    var m = $('meta[name="csrf"]');
    return m ? m.getAttribute('content') : '';
  }

  /* ---------- Toast / Flash ---------- */
  var toastTimer = null;
  function toast(msg) {
    var t = $('#toast');
    if (!t) return;
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.classList.remove('show'); }, 3200);
  }
  $$('.flash').forEach(function (f) {
    if (f.classList.contains('flash-warn')) return; // 警告常驻
    setTimeout(function () {
      f.style.transition = 'opacity .5s';
      f.style.opacity = '0';
      setTimeout(function () { if (f.parentNode) f.parentNode.removeChild(f); }, 600);
    }, 4200);
  });

  /* ---------- 深色模式切换 ---------- */
  var themeBtn = $('#themeToggle');
  if (themeBtn) {
    themeBtn.addEventListener('click', function () {
      var cur = document.documentElement.getAttribute('data-theme') || 'light';
      var next = cur === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      try { localStorage.setItem('mf-theme', next); } catch (e) {}
    });
  }

  /* ---------- 移动端侧栏 ---------- */
  var navToggle = $('#navToggle'), sidebar = $('#sidebar'), mask = $('#sideMask');
  function closeSide() {
    if (sidebar) sidebar.classList.remove('open');
    if (mask) mask.hidden = true;
    if (navToggle) navToggle.setAttribute('aria-expanded', 'false');
  }
  if (navToggle && sidebar) {
    navToggle.addEventListener('click', function () {
      var open = sidebar.classList.toggle('open');
      if (mask) mask.hidden = !open;
      navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    if (mask) mask.addEventListener('click', closeSide);
  }

  /* ---------- 危险操作确认 ---------- */
  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    if (f && f.getAttribute && f.getAttribute('data-confirm')) {
      if (!window.confirm(f.getAttribute('data-confirm'))) {
        ev.preventDefault();
        ev.stopPropagation();
      }
    }
  }, true);

  /* ---------- 字数统计 ---------- */
  $$('input[data-counter],textarea[data-counter]').forEach(function (el) {
    var out = $(el.getAttribute('data-counter'));
    if (!out) return;
    var upd = function () { out.textContent = String(el.value.length); };
    el.addEventListener('input', upd);
    upd();
  });

  /* ---------- AJAX 表单编码 POST ---------- */
  function postForm(url, params, cb) {
    var body = new URLSearchParams();
    body.append('csrf', csrf());
    Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
    fetch(url, {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch' }
    }).then(function (r) { return r.json(); }).then(cb).catch(function () {
      cb({ ok: false, msg: '网络错误，请重试' });
    });
  }

  /* ---------- 邮箱验证码按钮（倒计时 60s） ---------- */
  $$('.send-code').forEach(function (btn) {
    var left = 0, timer = null;
    var msgEl = btn.closest('.field') ? btn.closest('.field').querySelector('.code-msg') : null;
    btn.addEventListener('click', function () {
      if (left > 0) return;
      var emailInput = $(btn.getAttribute('data-email') || '');
      var email = emailInput ? emailInput.value.trim() : '';
      if (!email) { if (msgEl) msgEl.textContent = '请先填写邮箱'; return; }
      btn.disabled = true;
      btn.textContent = '发送中…';
      postForm(apiUrl('a=send_code'), { email: email, purpose: btn.getAttribute('data-purpose') || 'reset' }, function (res) {
        if (msgEl) {
          msgEl.textContent = res.msg || '';
          msgEl.style.color = res.ok ? 'var(--ok)' : 'var(--danger)';
        }
        toast(res.msg || (res.ok ? '已发送' : '发送失败'));
        if (res.ok) {
          left = 60;
          timer = setInterval(function () {
            left--;
            btn.textContent = left > 0 ? left + 's 后可重发' : '发送验证码';
            if (left <= 0) { clearInterval(timer); btn.disabled = false; }
          }, 1000);
        } else {
          btn.disabled = false;
          btn.textContent = '发送验证码';
        }
      });
    });
  });

  /* ---------- 安装向导测试按钮 ---------- */
  $$('[data-inst-test]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var kind = btn.getAttribute('data-inst-test');
      var form = btn.closest('form') || document.body;
      var msgEl = btn.closest('.field').querySelector('.test-msg');
      var params = {};
      $$('input[name]', form).forEach(function (i) { params[i.name] = i.value; });
      if (kind === 'mail' && $('#mail-test-to')) params.to = $('#mail-test-to').value;
      btn.disabled = true;
      if (msgEl) msgEl.textContent = '测试中…';
      var url = installUrl() + (installUrl().indexOf('?') >= 0 ? '&' : '?') + 'a=test_' + kind;
      postForm(url, params, function (res) {
        btn.disabled = false;
        if (msgEl) {
          msgEl.textContent = res.msg || '';
          msgEl.style.color = res.ok ? 'var(--ok)' : 'var(--danger)';
        }
      });
    });
  });

  /* ---------- 后台保存 / 测试按钮 ---------- */
  $$('[data-admin-save], [data-admin-test]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var kind = btn.getAttribute('data-admin-save') || btn.getAttribute('data-admin-test');
      var isSave = !!btn.getAttribute('data-admin-save');
      var form = btn.closest('form') || document.body;
      var msgEl = form.querySelector('.test-msg') || (btn.closest('.card') || document).querySelector('.test-msg');
      var params = {};
      $$('input[name],select[name],textarea[name]', form).forEach(function (i) { params[i.name] = i.value; });
      if (kind === 'mail') {
        var toEl = $('#mail-test-to');
        if (!isSave) {
          if (toEl) params.to = toEl.value;
          if (btn.getAttribute('data-to') && $(btn.getAttribute('data-to'))) params.to = $(btn.getAttribute('data-to')).value;
        }
      }
      btn.disabled = true;
      if (msgEl && !isSave) { msgEl.textContent = '测试中…'; msgEl.style.color = ''; }
      var action = isSave ? 'a=admin_save_' + kind : 'a=admin_test_' + kind;
      postForm(apiUrl(action), params, function (res) {
        btn.disabled = false;
        if (isSave) {
          toast(res.msg || (res.ok ? '已保存' : '保存失败'));
          if (res.ok && res.msg) {
            // 保存动作返回 redirect 意味着 json 之外——但此处为 AJAX：直接提示
          }
        } else if (msgEl) {
          msgEl.textContent = res.msg || '';
          msgEl.style.color = res.ok ? 'var(--ok)' : 'var(--danger)';
        }
      });
    });
  });
  /* ---------- 实时刷新引擎 ----------
     前台每 N 秒（meta live-interval，后台可配，0=关闭）拉取一次只读 JSON：
     · 在线人数 / 公告未读红点
     · 列表页：有新帖或新回复 → 顶部悬浮提示条（点击刷新）
     · 帖子页：有新回复 → 提示条
     页面切到后台（document.hidden）自动暂停，回到前台立即刷新，不产生无效流量。 */
  var liveMeta = $('meta[name="live-interval"]');
  var LIVE_INT = liveMeta ? Math.max(0, parseInt(liveMeta.getAttribute('content'), 10) || 0) : 0;
  var liveTimer = null;
  var liveBar = null;

  function ensureLiveBar() {
    if (liveBar) return liveBar;
    liveBar = document.createElement('button');
    liveBar.className = 'live-bar';
    liveBar.type = 'button';
    liveBar.setAttribute('aria-live', 'polite');
    liveBar.addEventListener('click', function () { window.location.reload(); });
    document.body.appendChild(liveBar);
    return liveBar;
  }
  function showLiveBar(text) {
    var b = ensureLiveBar();
    b.textContent = text;
    b.classList.add('show');
  }
  function hideLiveBar() {
    if (liveBar) liveBar.classList.remove('show');
  }

  function liveTick() {
    var qs = 'a=live';
    var m = window.location.search.match(/[?&]id=(\d+)/); // 帖子页附带 tid
    if (m && /p=thread/.test(window.location.search)) qs += '&tid=' + m[1];
    fetch(apiUrl(qs), { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res || !res.ok) return;
        // 在线人数
        var on = $('#onlineNum');
        if (on && typeof res.online === 'number') on.textContent = String(res.online);
        // 公告未读红点
        var dot = $('#notifyDot');
        if (dot) {
          var n = (typeof res.unread === 'number' ? res.unread : 0) > 0;
          if (n) dot.removeAttribute('hidden'); else dot.setAttribute('hidden', '');
          dot.setAttribute('title', (res.unread || 0) + ' 条未读通知');
        }
        // 列表页（首页 / 板块页）：有更新 → 提示条
        var isList = /p=(home|board)/.test(window.location.search) || window.location.search.indexOf('p=') < 0;
        var cards = $$('.thread-list .tcard');
        if (isList && cards.length && typeof res.latest_ts === 'number') {
          var newest = 0;
          cards.forEach(function (c) { newest = Math.max(newest, parseInt(c.getAttribute('data-lr'), 10) || 0); });
          if (res.latest_ts > newest) {
            showLiveBar('有新内容 · 点击刷新查看');
          } else {
            hideLiveBar();
          }
        }
        // 帖子页：有新回复 → 提示条
        var isThread = /p=thread/.test(window.location.search);
        if (isThread && typeof res.rid === 'number') {
          var lastR = $$('.reply-item');
          var myMax = 0;
          lastR.forEach(function (el) {
            var mm = (el.id || '').match(/^r(\d+)$/);
            if (mm) myMax = Math.max(myMax, parseInt(mm[1], 10) || 0);
          });
          if (res.rid > myMax) {
            showLiveBar('有新回复 · 点击刷新查看');
          } else {
            hideLiveBar();
          }
        }
      })
      .catch(function () { /* 静默：网络抖动不影响浏览 */ });
  }

  function startLive() {
    if (LIVE_INT <= 0) return;
    if (liveTimer) clearInterval(liveTimer);
    liveTimer = setInterval(function () {
      if (!document.hidden) liveTick(); // 后台标签页暂停轮询
    }, LIVE_INT * 1000);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) liveTick();
    });
  }
  startLive();

  /* ---------- 后台监控页实时读数（CPU / 内存 / 磁盘，间隔后台可配，meta monitor-interval，0=关闭） ---------- */
  function isMonitorPage() {
    return /p=admin/.test(window.location.search) && /tab=monitor/.test(window.location.search);
  }
  function ringSet(id, pct, na) {
    var card = $('#' + id);
    if (!card) return;
    var fg = card.querySelector('.ring-fg');
    var svg = card.querySelector('.ring');
    if (!fg || !svg) return;
    var r = parseFloat(fg.getAttribute('r') || '44');
    var c = 2 * Math.PI * r;
    var p = Math.max(0, Math.min(100, pct || 0));
    fg.setAttribute('stroke-dashoffset', String(Math.round(c * (1 - (na ? 0 : p) / 100) * 10) / 10));
    svg.classList.toggle('na', !!na);
    svg.classList.toggle('warn', !na && p >= 60 && p < 85);
    svg.classList.toggle('bad', !na && p >= 85);
  }
  function sysmonTick() {
    if (document.hidden) return;
    fetch(apiUrl('a=sysmon_live'), { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res || !res.ok) return;
        var num = function (v) { return (typeof v === 'number' && isFinite(v) && v >= 0) ? v : null; };
        var loadStr = res.load ? res.load.join(' / ') : '';
        // CPU：proc=实际采样 / load=负载估算 / na=不可读
        var cpu = num(res.cpu);
        var cMode = res.cpu_mode || (cpu === null ? 'na' : 'proc');
        var cNa = cMode === 'na' || cpu === null;
        ringSet('resCpu', cpu || 0, cNa);
        var cv = $('#resCpuVal');
        if (cv) cv.innerHTML = cNa ? '—' : cpu + '<small>%</small>';
        var cf = $('#resCpuFoot');
        if (cf) {
          if (cMode === 'load') cf.textContent = '主机限制 /proc · 按 ' + (res.cores || 1) + ' 核负载估算';
          else if (cNa) cf.textContent = '当前环境不可读 · 负载 ' + loadStr;
          else cf.textContent = '实际采样 · 负载 ' + loadStr;
        }
        // 内存：host=整机 / proc=本 PHP 进程 / na=不可读
        var mem = res.mem || null;
        var mMode = res.mem_mode || 'na';
        var mPct = mem ? num(mem.pct) : null;
        var mNa = mMode === 'na' || mPct === null;
        ringSet('resMem', mPct || 0, mNa);
        var mv = $('#resMemVal');
        if (mv) mv.innerHTML = mNa ? '—' : mPct + '<small>%</small>';
        var mf = $('#resMemFoot');
        if (mf) {
          if (mMode === 'proc' && mem) {
            mf.textContent = '本进程 ' + fmtBytes(mem.used) + (mem.total > 0 ? ' / 上限 ' + fmtBytes(mem.total) : '');
          } else if (mNa || !mem) {
            mf.textContent = '当前环境不可读';
          } else {
            mf.textContent = fmtBytes(mem.used) + ' / ' + fmtBytes(mem.total);
          }
        }
        // 磁盘
        var dPct = res.disk ? num(res.disk.pct) : null;
        if (dPct !== null) {
          ringSet('resDisk', dPct, false);
          var dv = $('#resDiskVal');
          if (dv) dv.innerHTML = dPct + '<small>%</small>';
          var df = $('#resDiskFoot');
          if (df) df.textContent = '剩余 ' + fmtBytes(res.disk.free) + ' / 共 ' + fmtBytes(res.disk.total);
        }
      })
      .catch(function () { /* 静默 */ });
  }
  function fmtBytes(n) {
    var u = ['B', 'KB', 'MB', 'GB'], i = 0, v = n;
    while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
    return (i === 0 ? Math.round(v) : Math.round(v * 10) / 10) + ' ' + u[i];
  }
  if (isMonitorPage()) {
    var monMeta = $('meta[name="monitor-interval"]');
    var MON_INT = monMeta ? Math.max(0, parseInt(monMeta.getAttribute('content'), 10) || 0) : 5;
    if (MON_INT === 1) MON_INT = 2;
    if (MON_INT > 0) {
      setInterval(sysmonTick, MON_INT * 1000);
      document.addEventListener('visibilitychange', function () {
        if (!document.hidden) sysmonTick();
      });
    }
  }
})();
