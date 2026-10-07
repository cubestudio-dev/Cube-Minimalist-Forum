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
      $$('input[name],select[name],textarea[name]', form).forEach(function (i) {
        if (i.type === 'checkbox' && !i.checked) return;
        params[i.name] = i.value;
      });
      var bid = btn.getAttribute('data-id');
      if (bid) params.id = bid;
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

  /* ---------- 安全防护：批量查询 IP 归属地 ---------- */
  var geoBtn = document.querySelector('[data-fw-geo]');
  if (geoBtn) {
    geoBtn.addEventListener('click', function () {
      var cells = [].slice.call(document.querySelectorAll('[data-fw-geo-for]'));
      var ips = [];
      cells.forEach(function (c) {
        var ip = c.getAttribute('data-fw-geo-for');
        if (ip && ips.indexOf(ip) === -1) ips.push(ip);
      });
      ips = ips.slice(0, 60);
      if (!ips.length) return;
      geoBtn.disabled = true;
      var old = geoBtn.textContent;
      geoBtn.textContent = '查询中…';
      var fd = new FormData();
      fd.append('csrf', csrf());
      fd.append('ips', JSON.stringify(ips));
      fetch(apiUrl('a=admin_fw_geo_batch'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'fetch' },
        body: fd
      }).then(function (r) { return r.json(); }).then(function (res) {
        geoBtn.disabled = false;
        geoBtn.textContent = old;
        var msgEl = document.getElementById('fw-geo-msg');
        if (msgEl) msgEl.textContent = (res && res.msg) ? res.msg : '';
        if (!res || !res.ok) return;
        cells.forEach(function (c) {
          var ip = c.getAttribute('data-fw-geo-for');
          if (res.geo && res.geo[ip]) c.textContent = res.geo[ip];
        });
      }).catch(function () {
        geoBtn.disabled = false;
        geoBtn.textContent = old;
        var msgEl = document.getElementById('fw-geo-msg');
        if (msgEl) msgEl.textContent = '请求失败，请刷新页面后重试';
      });
    });
  }

  /* ---------- 主题页：点色板时同步取色器显示，避免旧值误导 ---------- */
  var palette = document.querySelector('.palette');
  var colorInput = palette ? document.querySelector('[name="theme_color_custom"]') : null;
  if (palette && colorInput) {
    palette.addEventListener('change', function (ev) {
      var t = ev.target;
      if (t && t.name === 'theme_color' && /^#[0-9a-fA-F]{6}$/.test(t.value)) {
        colorInput.value = t.value;
      }
    });
  }

  /* ---------- 表情选择器（v1.10.0）----------
     自动注入到所有 Markdown 输入框（发帖 / 回复 / 后台公告，即 textarea[name=content]）；
     点击按钮弹出表情面板，点选后以 :name: 短代码插入光标处，服务端 Markdown 渲染为表情。
     v1.14.0：后台「功能 → 表情选择器」关闭时（window.MF_EMOJI===false）不再注入。 */
  if (window.MF_EMOJI !== false) {
  var EMOJIS = [
    ['smile', '😄'], ['laughing', '😆'], ['joy', '😂'], ['rofl', '🤣'], ['smiley', '😃'], ['grin', '😁'],
    ['wink', '😉'], ['blush', '😊'], ['innocent', '😇'], ['upside_down', '🙃'], ['relieved', '😌'],
    ['heart_eyes', '😍'], ['kissing_heart', '😘'], ['thinking', '🤔'], ['neutral', '😐'], ['expressionless', '😑'],
    ['smirk', '😏'], ['unamused', '😒'], ['roll_eyes', '🙄'], ['pensive', '😔'], ['cry', '😢'], ['sob', '😭'],
    ['angry', '😠'], ['rage', '😡'], ['scream', '😱'], ['cold_sweat', '😰'], ['sleepy', '😪'], ['mask', '😷'],
    ['sunglasses', '😎'], ['nerd', '🤓'], ['clown', '🤪'], ['star_struck', '🤩'], ['party', '🥳'], ['pleading', '🥺'],
    ['shushing', '🤫'], ['ghost', '👻'], ['alien', '👽'], ['robot', '🤖'], ['skull', '💀'], ['poop', '💩'],
    ['clown_face', '🤡'], ['eyes', '👀'], ['brain', '🧠'], ['hug', '🤗'], ['thumbsup', '👍'], ['thumbsdown', '👎'],
    ['ok_hand', '👌'], ['v', '✌️'], ['wave', '👋'], ['clap', '👏'], ['pray', '🙏'], ['muscle', '💪'],
    ['point_right', '👉'], ['point_left', '👈'], ['point_up', '☝️'], ['point_down', '👇'], ['raised_hands', '🙌'],
    ['handshake', '🤝'], ['fist', '✊'], ['bow', '🙇'], ['running', '🏃'], ['dancer', '💃'], ['couple', '👫'],
    ['family', '👪'], ['heart', '❤️'], ['orange_heart', '🧡'], ['yellow_heart', '💛'], ['green_heart', '💚'],
    ['blue_heart', '💙'], ['purple_heart', '💜'], ['black_heart', '🖤'], ['broken_heart', '💔'], ['heartpulse', '💗'],
    ['sparkling_heart', '💖'], ['two_hearts', '💕'], ['revolving_hearts', '💞'], ['star', '⭐'], ['sparkles', '✨'],
    ['fire', '🔥'], ['boom', '💥'], ['zap', '⚡'], ['rainbow', '🌈'], ['sunny', '☀️'], ['moon', '🌙'],
    ['cloud', '☁️'], ['snowflake', '❄️'], ['umbrella', '☔'], ['gift', '🎁'], ['bell', '🔔'], ['mega', '📢'],
    ['lock', '🔒'], ['key', '🔑'], ['bulb', '💡'], ['books', '📚'], ['book', '📖'], ['memo', '📝'],
    ['pencil', '✏️'], ['calendar', '📅'], ['alarm_clock', '⏰'], ['white_check_mark', '✅'], ['x', '❌'],
    ['question', '❓'], ['exclamation', '❗'], ['warning', '⚠️'], ['no_entry', '⛔'], ['recycle', '♻️'],
    ['100', '💯'], ['hot', '🥵'], ['cold_face', '🥶'], ['coffee', '☕'], ['tea', '🍵'], ['beer', '🍺'],
    ['cake', '🍰'], ['apple', '🍎'], ['watermelon', '🍉'], ['pizza', '🍕'], ['ice_cream', '🍦'],
    ['moon_cake', '🥮'], ['fish', '🐟'], ['rice', '🍚'], ['noodles', '🍜'], ['bread', '🍞'], ['egg', '🥚'],
    ['popcorn', '🍿'], ['cat', '🐱'], ['dog', '🐶'], ['mouse', '🐭'], ['rabbit', '🐰'], ['fox', '🦊'],
    ['bear', '🐻'], ['panda', '🐼'], ['tiger', '🐯'], ['lion', '🦁'], ['cow', '🐮'], ['pig', '🐷'],
    ['frog', '🐸'], ['chicken', '🐤'], ['penguin', '🐧'], ['owl', '🦉'], ['bee', '🐝'], ['butterfly', '🦋'],
    ['snail', '🐌'], ['turtle', '🐢'], ['octopus', '🐙'], ['whale', '🐳'], ['dolphin', '🐬'],
    ['blossom', '🌸'], ['rose', '🌹'], ['sunflower', '🌻'], ['four_leaf_clover', '🍀'], ['seedling', '🌱'],
    ['cactus', '🌵'], ['palm_tree', '🌴'], ['computer', '💻'], ['iphone', '📱'], ['camera', '📷'],
    ['headphones', '🎧'], ['music', '🎵'], ['guitar', '🎸'], ['video_game', '🎮'], ['car', '🚗'],
    ['airplane', '✈️'], ['train', '🚄'], ['ship', '🚢'], ['house', '🏠'], ['moneybag', '💰'], ['gem', '💎'],
    ['trophy', '🏆'], ['medal', '🏅'], ['soccer', '⚽'], ['basketball', '🏀'], ['ping_pong', '🏓'],
    ['art', '🎨'], ['ticket', '🎫'], ['balloon', '🎈'], ['tada', '🎉'], ['confetti_ball', '🎊'],
    ['crown', '👑'], ['eyeglasses', '👓']
  ];

  function emojiInsertAt(ta, text) {
    var s = typeof ta.selectionStart === 'number' ? ta.selectionStart : ta.value.length;
    var e = typeof ta.selectionEnd === 'number' ? ta.selectionEnd : s;
    ta.value = ta.value.slice(0, s) + text + ta.value.slice(e);
    ta.selectionStart = ta.selectionEnd = s + text.length;
    try { ta.dispatchEvent(new Event('input', { bubbles: true })); } catch (err) { /* 字数统计兼容 */ }
  }

  $$('textarea[name="content"]').forEach(function (ta) {
    if (ta.getAttribute('data-emoji-ready') === '1') return;
    ta.setAttribute('data-emoji-ready', '1');

    var bar = document.createElement('div');
    bar.className = 'emoji-bar';

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'emoji-btn';
    btn.setAttribute('aria-label', '插入表情');
    btn.setAttribute('aria-expanded', 'false');
    btn.textContent = '😀 表情';

    var pop = document.createElement('div');
    pop.className = 'emoji-pop';
    pop.hidden = true;
    pop.setAttribute('role', 'menu');
    pop.setAttribute('aria-label', '表情列表（点选插入 :name: 短代码）');

    EMOJIS.forEach(function (it) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'emoji-item';
      b.title = ':' + it[0] + ':';
      b.setAttribute('aria-label', ':' + it[0] + ':');
      b.textContent = it[1];
      b.addEventListener('click', function () {
        /* v1.16.0：直接插入 emoji 字符本身（此前插入 :name: 短代码，
           标题 / 通知 / 日志等不经过 Markdown 渲染的位置会显示冒号原文） */
        emojiInsertAt(ta, it[1]);
        pop.hidden = true;
        btn.setAttribute('aria-expanded', 'false');
        ta.focus();
      });
      pop.appendChild(b);
    });

    btn.addEventListener('click', function (ev) {
      ev.preventDefault();
      pop.hidden = !pop.hidden;
      btn.setAttribute('aria-expanded', pop.hidden ? 'false' : 'true');
    });
    document.addEventListener('click', function (ev) {
      if (!pop.hidden && ev.target !== btn && !pop.contains(ev.target)) {
        pop.hidden = true;
        btn.setAttribute('aria-expanded', 'false');
      }
    });

    bar.appendChild(btn);
    bar.appendChild(pop);
    ta.parentNode.insertBefore(bar, ta);
  });
  } /* end MF_EMOJI */

  /* ---------- 管理员 · AI 自主管理（严全面）：立即巡逻一次 ----------
     AJAX 触发 ai_patrol_go（即使无风险事件也强制巡），完成后刷新页面展示最新巡逻报告。 */
  $$('[data-admin-patrol]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (btn.disabled) return;
      btn.disabled = true;
      var old = btn.textContent;
      btn.textContent = '巡逻中…';
      postForm(apiUrl('a=admin_patrol_now'), {}, function (res) {
        btn.disabled = false;
        btn.textContent = old;
        toast(res.msg || (res.ok ? '巡逻完成' : '巡逻失败'));
        setTimeout(function () { window.location.reload(); }, 900);
      });
    });
  });
})();

  /* ---------- v1.16.0：@ 提及自动补全 ----------
     在 textarea 输入 @ 后继续键入，弹出用户名下拉；↑↓ 选择、Enter/点击 插入、Esc 关闭。 */
  (function () {
    var ta = $('textarea[name="content"]');
    if (!ta || window.MF_MENTION === false) return;
    var pop = document.createElement('div');
    pop.className = 'mention-pop';
    pop.hidden = true;
    pop.setAttribute('role', 'listbox');
    ta.parentNode.appendChild(pop);
    var items = [];
    var active = -1;
    var ctx = null; /* {start, q} */

    function close() {
      pop.hidden = true;
      pop.innerHTML = '';
      items = [];
      active = -1;
      ctx = null;
    }

    function pick(idx) {
      if (!items[idx] || !ctx) return;
      var name = items[idx];
      var v = ta.value;
      var pos = ta.selectionStart;
      /* 替换 @q（含 @ 符号）为 @名字 + 空格 */
      var atPos = v.lastIndexOf('@', pos - 1);
      if (atPos < 0) { close(); return; }
      v = v.slice(0, atPos) + '@' + name + ' ' + v.slice(pos);
      ta.value = v;
      var np = atPos + name.length + 2;
      ta.selectionStart = ta.selectionEnd = np;
      close();
      ta.focus();
      ta.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function render() {
      pop.innerHTML = '';
      items.forEach(function (n, i) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'mention-item' + (i === active ? ' on' : '');
        b.textContent = '@' + n;
        b.addEventListener('mousedown', function (ev) { ev.preventDefault(); pick(i); });
        pop.appendChild(b);
      });
      pop.hidden = items.length === 0;
    }

    ta.addEventListener('input', function () {
      var pos = ta.selectionStart;
      var before = ta.value.slice(0, pos);
      var m = before.match(/@([^@\s]{0,20})$/);
      if (!m) { close(); return; }
      ctx = { q: m[1] };
      fetch(apiUrl('p=mention_api&q=' + encodeURIComponent(m[1])), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!ctx || !j || !j.ok || !j.users || !j.users.length) { close(); return; }
          /* 用户输入可能已经变化，丢弃过期结果 */
          var pos2 = ta.selectionStart;
          var before2 = ta.value.slice(0, pos2);
          var m2 = before2.match(/@([^@\s]{0,20})$/);
          if (!m2 || m2[1] !== ctx.q) return;
          items = j.users;
          active = 0;
          render();
        })
        .catch(function () { close(); });
    });

    ta.addEventListener('keydown', function (ev) {
      if (pop.hidden) return;
      if (ev.key === 'ArrowDown') { ev.preventDefault(); active = (active + 1) % items.length; render(); }
      else if (ev.key === 'ArrowUp') { ev.preventDefault(); active = (active - 1 + items.length) % items.length; render(); }
      else if (ev.key === 'Enter') { ev.preventDefault(); pick(active); }
      else if (ev.key === 'Escape') { close(); }
    });
    ta.addEventListener('blur', function () { setTimeout(close, 120); });
  })();

  /* ---------- v1.16.0：后台保存后回到原位置 ----------
     后台动作保存后整页回跳（tab/筛选/分页由服务端 session 精确恢复）；
     前端配合：页面带 flash 提示（保存成功 / 操作完成的回跳）时，恢复离开前的滚动位置。 */
  (function () {
    if (location.search.indexOf('p=admin') === -1) return;
    var KEY = 'mf_admin_scroll';
    window.addEventListener('beforeunload', function () {
      try { sessionStorage.setItem(KEY, String(window.scrollY || 0)); } catch (e) {}
    });
    var hasFlash = $('.flash');
    if (hasFlash) {
      var y = parseInt(sessionStorage.getItem(KEY) || '0', 10);
      if (y > 0) {
        window.scrollTo(0, y);
      }
    }
    try { sessionStorage.removeItem(KEY); } catch (e) {}
  })();

  /* ---------- v1.16.0：注销账号 10 秒冷静期倒计时 ---------- */
  (function () {
    var btn = $('#del-go');
    if (!btn) return;
    var wait = parseInt(btn.getAttribute('data-wait') || '0', 10);
    if (wait <= 0) return;
    var cnt = $('#del-count');
    btn.disabled = true;
    var left = wait;
    var label = btn.textContent;
    var timer = setInterval(function () {
      left--;
      if (cnt) cnt.textContent = String(left);
      if (left <= 0) {
        clearInterval(timer);
        btn.disabled = false;
        btn.textContent = '确认注销（立即生效，不可恢复）';
      }
    }, 1000);
  })();
