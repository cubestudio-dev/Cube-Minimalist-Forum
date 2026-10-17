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
  /* v1.30.0：HTML 转义（搜索预览等需要把用户内容嵌进 innerHTML 的地方统一走这里） */
  function escHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
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

  /* ---------- v1.16.0：保存后回到原位置 ----------
     后台动作保存后整页回跳（tab/筛选/分页由服务端 session 精确恢复）；
     前端配合：页面带 flash 提示（保存成功 / 操作完成 / 出错回跳）时，恢复离开前的滚动位置。
     v1.28.0：从"仅后台"扩展到全站——前台个人设置等保存回跳同样锚定位置，用户要求
     "每次保存、调整完以后必须在当前页面，位置不能变"。 */
  (function () {
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

  /* ---------- v1.29.0：后台侧边导航高度实时贴合视口 ----------
     修复 v1.28.0 的残留问题：导航 max-height 按"吸附后"的位置（视口顶部 76px）计算，
     但页面还没滚下去、sticky 尚未吸附时，导航底部会伸出视口——内部滚动条明明滑到了
     最底下，最后一组标签却还在屏幕外；若当前标签页内容较短、整页不出滚动条，导航
     永远不会吸附，底部标签就永远看不到。现在按导航当前在视口中的实际位置动态收放
     max-height：未吸附时自动变矮、吸附后自动长高，任何位置"导航滚到底 = 视口里也到底"。
     平铺布局 / 窄屏横排（CSS max-height:none）自动跳过，无 JS 时回退 CSS 固定计算。 */
  (function () {
    var nav = document.querySelector('.admin-nav');
    if (!nav) return;
    function fit() {
      nav.style.maxHeight = ''; /* 先清内联值，让 CSS（布局设置 / 窄屏媒体查询）先说话 */
      if (getComputedStyle(nav).maxHeight === 'none') return;
      var h = Math.round(window.innerHeight - nav.getBoundingClientRect().top - 16);
      if (h < 120) h = 120; /* 视口极矮时保底，导航自身仍可内部滚动 */
      if (h > window.innerHeight - 16) h = window.innerHeight - 16; /* 惯性回弹等瞬态下不超出视口 */
      nav.style.maxHeight = h + 'px';
    }
    var ticking = false;
    function queue() {
      if (ticking) return;
      ticking = true;
      requestAnimationFrame(function () { ticking = false; fit(); });
    }
    window.addEventListener('scroll', queue, { passive: true });
    window.addEventListener('resize', queue);
    window.addEventListener('load', fit); /* 字体 / 图标加载后页头高度可能微调 */
    fit();
  })();

  /* ---------- v1.17.0：注销账号 10 秒冷静期倒计时 ---------- */
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

  /* ---------- v1.17.0：侧栏 Ping（浏览器到服务器的往返延迟） ----------
     向 p=ping 发空请求，用 performance.now() 差值测算 RTT；
     v1.18.0：间隔由后台「基本设置 → Ping 测量间隔」控制（meta[ping-interval]，0=关闭）；
     页面切到后台自动暂停；<100ms 绿色 / <300ms 琥珀 / 其余红色。 */
  (function () {
    var el = $('#pingVal');
    if (!el) return;
    var block = el.closest('.side-ping');
    var meta = $('meta[name="ping-interval"]');
    var iv = meta ? Math.max(0, parseInt(meta.getAttribute('content'), 10) || 0) : 15;
    if (iv <= 0) { if (block) block.hidden = true; return; }
    function measure() {
      if (document.hidden) return;
      var t0 = performance.now();
      fetch(apiUrl('p=ping'), { credentials: 'same-origin', cache: 'no-store' })
        .then(function () {
          var rtt = Math.round(performance.now() - t0);
          el.textContent = String(rtt);
          if (block) {
            block.classList.remove('ping-ok', 'ping-mid', 'ping-bad');
            block.classList.add(rtt < 100 ? 'ping-ok' : (rtt < 300 ? 'ping-mid' : 'ping-bad'));
          }
        })
        .catch(function () { el.textContent = '--'; });
    }
    measure();
    setInterval(measure, iv * 1000);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) measure();
    });
  })();

  /* ---------- v1.17.0：设置表单免刷新保存（form[data-ajax]） ----------
     提交后不再整页跳转：服务端所有动作用 redirect() 返回前会先走 is_ajax()
     分支把 Flash 消息转成 JSON，这里弹出 Toast 即完成，滚动位置原样保留。
     仅用于"保存后页面内容不变"的设置类表单；会改变列表内容的操作（封禁、
     删除、板块管理等）仍走整页回跳以反映最新状态。 */
  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    if (!f || !f.matches || !f.matches('form[data-ajax]')) return;
    ev.preventDefault();
    var btn = f.querySelector('button[type="submit"]');
    if (btn) btn.disabled = true;
    fetch(f.getAttribute('action'), {
      method: 'POST',
      body: new FormData(f),
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch' }
    }).then(function (r) { return r.json(); }).then(function (res) {
      if (btn) btn.disabled = false;
      var ok = !!(res && res.ok);
      toast((res && res.msg) || (ok ? '已保存' : '保存失败'));
      if (ok && f.hasAttribute('data-ajax-live')) applyLive(f); // 基本设置 / 主题：保存后页面即时生效
    }).catch(function () {
      if (btn) btn.disabled = false;
      toast('网络错误，请重试');
    });
  });

  /* v1.17.0：基本设置 / 主题保存后，把可见的配置即时应用到当前页面（免刷新可见） */
  function applyLive(f) {
    var a = f.getAttribute('data-ajax-live');
    if (a === 'brand') {
      var nameI = f.querySelector('[name="site_name"]');
      var descI = f.querySelector('[name="site_desc"]');
      var brand = document.querySelector('.brand b');
      if (brand && nameI && nameI.value.trim() !== '') brand.textContent = nameI.value.trim();
      var desc = document.querySelector('.brand-desc');
      if (desc && descI) desc.textContent = descI.value;
    } else if (a === 'accent') {
      var color = '';
      var checked = f.querySelector('[name="theme_color"]:checked');
      if (checked && /^#[0-9a-fA-F]{3,8}$/.test(checked.value)) color = checked.value;
      /* 取色器仅在“用户真的改了它”（与当前主题色不同）时生效——与服务端 theme_pick_color 同规则，
         否则取色器总是携带旧值，会覆盖色板选择 */
      var custom = f.querySelector('[name="theme_color_custom"]');
      var cur = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim();
      if (custom && /^#[0-9a-fA-F]{3,8}$/.test(custom.value) && custom.value.toLowerCase() !== cur.toLowerCase()) {
        color = custom.value;
      }
      if (/^#[0-9a-fA-F]{3,8}$/.test(color)) {
        document.documentElement.style.setProperty('--accent', color);
      }
    }
  }

  /* ---------- v1.17.0：协议内置模板一键填入（后台 · 协议页） ----------
     v1.24.0：填入时自动把模板中的【站名】【站长邮箱】【更新日期】替换为预填项的值——
     站长无需（也不应）手动替换占位符；预填项为空的保留占位符并提示 */
  $$('[data-doc-tpl]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var key = btn.getAttribute('data-doc-tpl');            // 短键：terms / privacy / disclaimer
      var holder = document.getElementById('doc-tpl-' + key); // 模板占位节点：doc-tpl-terms …
      var ta = document.querySelector('textarea[name="doc_' + key + '"]'); // 对应 textarea：doc_terms …
      if (!holder || !ta) return;
      var tpl = (holder.textContent || '').replace(/^\n+/, '').replace(/\s+$/, '');
      if (!tpl) return;
      var pf = function (id) { var el = document.getElementById(id); return el ? el.value.trim() : ''; };
      var site = pf('doc-pf-site'), mail = pf('doc-pf-mail'), date = pf('doc-pf-date');
      if (tpl.indexOf('【站名】') !== -1 && site === '') { toast('请先在「协议模板预填项」填写站名，再填入模板'); return; }
      if (tpl.indexOf('【站长邮箱】') !== -1 && mail === '') { toast('请先在「协议模板预填项」填写站长邮箱，再填入模板'); return; }
      tpl = tpl.replace(/【站名】/g, site).replace(/【站长邮箱】/g, mail).replace(/【更新日期】/g, date || '');
      if (ta.value.trim() !== '' && !window.confirm('当前已填写内容，填入内置模板将覆盖，确定？')) return;
      ta.value = tpl;
      ta.dispatchEvent(new Event('input', { bubbles: true }));
      toast('内置模板已按预填项自动替换站名 / 邮箱 / 更新日期，可先修改再点「保存协议设置」生效');
    });
  });

  /* ---------- v1.19.0：API 令牌一键复制（设置页，明文仅展示一次） ---------- */
  var copyBtn = document.getElementById('api-token-copy');
  var tokVal = document.getElementById('api-token-val');
  if (copyBtn && tokVal) {
    copyBtn.addEventListener('click', function () {
      var txt = tokVal.textContent || '';
      var done = function () { toast('令牌已复制到剪贴板'); copyBtn.textContent = '已复制 ✓'; setTimeout(function () { copyBtn.textContent = '复制令牌'; }, 2000); };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(txt).then(done, function () { fallbackCopy(txt, done); });
      } else {
        fallbackCopy(txt, done);
      }
    });
  }
  function fallbackCopy(txt, done) {
    var ta = document.createElement('textarea');
    ta.value = txt;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); done(); } catch (e) { toast('复制失败，请手动选择复制'); }
    document.body.removeChild(ta);
  }
  /* v1.20.0：新设备二次验证个人开关——勾选即提交 */
  $$('.js-dev-toggle').forEach(function (cb) {
    cb.addEventListener('change', function () { if (cb.form) cb.form.submit(); });
  });
  /* ---------- v1.30.0：前台搜索预览（输入联想，点击直达帖子；开关在后台「功能」页） ---------- */
  var feSearch = $('.side-search input[name="q"]');
  var fePop = document.getElementById('srsPop');
  if (feSearch && fePop) {
    var feTimer = 0, feItems = [], feActive = -1, feSeq = 0;
    var fePaint = function () {
      $$('.srs', fePop).forEach(function (r, n) { r.classList.toggle('on', n === feActive); });
    };
    var feRender = function (its) {
      feItems = its; feActive = -1;
      if (!its.length) { fePop.hidden = true; fePop.innerHTML = ''; return; }
      fePop.innerHTML = its.map(function (it, n) {
        return '<a class="srs" href="' + escHtml(apiUrl('p=thread&id=' + it.id)) + '" data-n="' + n + '">' +
          '<b>' + escHtml(it.title) + '</b><span>' + escHtml(it.board) + '</span></a>';
      }).join('');
      fePop.hidden = false;
      $$('.srs', fePop).forEach(function (a) {
        a.addEventListener('mousedown', function (e) { // mousedown 先于 blur，避免弹层先收起
          e.preventDefault();
          window.location.href = a.getAttribute('href');
        });
      });
    };
    var feFetch = function (q) {
      var seq = ++feSeq; // 只认最新一次请求，输入快时丢弃过期响应
      fetch(apiUrl('a=search_suggest') + '&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (seq !== feSeq) return;
          feRender(j && j.ok ? (j.items || []) : []);
        })
        .catch(function () { if (seq === feSeq) feRender([]); });
    };
    feSearch.addEventListener('input', function () {
      var q = feSearch.value.trim();
      clearTimeout(feTimer);
      if (q === '') { fePop.hidden = true; fePop.innerHTML = ''; return; }
      feTimer = setTimeout(function () { feFetch(q); }, 220); // 去抖：避免每个按键都打接口
    });
    feSearch.addEventListener('keydown', function (e) {
      var rows = $$('.srs', fePop);
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        if (!rows.length || fePop.hidden) return;
        e.preventDefault();
        feActive = e.key === 'ArrowDown' ? (feActive + 1) % rows.length : (feActive - 1 + rows.length) % rows.length;
        fePaint();
      } else if (e.key === 'Enter') {
        if (feActive >= 0 && rows[feActive] && !fePop.hidden) {
          e.preventDefault();
          window.location.href = rows[feActive].getAttribute('href');
        }
        /* feActive<0 时不拦截：回车走原生表单提交 → 搜索结果页 */
      } else if (e.key === 'Escape') {
        fePop.hidden = true;
      }
    });
    document.addEventListener('click', function (e) {
      if (!feSearch.contains(e.target) && !fePop.contains(e.target)) fePop.hidden = true;
    });
  }

  /* ---------- v1.22.0：后台设置搜索（跨标签页索引 + 即时过滤 + 跳转高亮） ---------- */
  var adminSearch = document.getElementById('adminSearchInput');
  var adminPop = document.getElementById('adminSearchPop');
  if (adminSearch && adminPop) {
    var searchIdx = null;      // 延迟加载：首次聚焦拉取索引
    var searchIdxBusy = false;
    var searchIdxErr = false;  /* v1.30.0：加载失败标记，下次聚焦/输入自动重试 */
    var activeItem = -1;       // 键盘导航

    function popMsg(cls, text) {
      adminPop.hidden = false;
      adminPop.innerHTML = '<div class="' + cls + '">' + escHtml(text) + '</div>';
    }

    function tabTitles() {
      var m = {};
      $$('.admin-tabs .atab').forEach(function (a) {
        var q = (a.getAttribute('href') || '').split('tab=')[1] || '';
        m[q] = a.textContent;
      });
      return m;
    }

    function loadIdx(done) {
      if (searchIdx) { done(searchIdx); return; }
      /* v1.30.0：索引未就绪时弹层给加载中/失败提示，不再静默空白（慢网络下首次聚焦不再“预览显示不出来”） */
      if (searchIdxBusy) { popMsg('asr-empty', '正在加载搜索索引…'); return; }
      searchIdxBusy = true;
      searchIdxErr = false;
      popMsg('asr-empty', '正在加载搜索索引…');
      fetch(apiUrl('a=admin_search'), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          searchIdx = (j && j.ok && j.items) ? j.items : [];
          searchIdxBusy = false;
          done(searchIdx);
        })
        .catch(function () {
          searchIdxBusy = false;
          searchIdxErr = true;
          done(null);
        });
    }

    function highlightCard(cardPrefix, label) {
      var cards = $$('.admin-body .card');
      for (var i = 0; i < cards.length; i++) {
        var h = cards[i].querySelector('.card-title');
        if (h && (h.textContent || '').indexOf(cardPrefix) === 0) {
          cards[i].scrollIntoView({ behavior: 'smooth', block: 'start' });
          cards[i].classList.remove('mf-flash');
          void cards[i].offsetWidth; // 重启动画
          cards[i].classList.add('mf-flash');
          if (label) {
            var ls = cards[i].querySelectorAll('.field-l');
            for (var k = 0; k < ls.length; k++) {
              if ((ls[k].textContent || '').indexOf(label) !== -1) {
                ls[k].classList.remove('mf-flash-l');
                void ls[k].offsetWidth;
                ls[k].classList.add('mf-flash-l');
                break;
              }
            }
          }
          return true;
        }
      }
      return false;
    }

    function gotoItem(it) {
      var hash = '#mfset-' + encodeURIComponent(it[1] + '|' + (it[2] || ''));
      sessionStorage.setItem('mfsetPending', JSON.stringify(it));
      if (window.MF_NAV_START) window.MF_NAV_START(); /* v1.23.0：程序化导航同样给进度条反馈 */
      window.location.href = apiUrl('p=admin&tab=' + it[0]) + hash;
    }

    function renderPop(q) {
      /* v1.30.0：索引未就绪 / 加载失败时给出可读状态，而不是空白或误报“没有匹配” */
      if (!searchIdx) {
        if (q.trim() !== '') {
          popMsg('asr-empty', searchIdxErr ? '搜索索引加载失败，请刷新页面重试' : '正在加载搜索索引…');
        } else {
          adminPop.hidden = true;
        }
        return;
      }
      var tabs = tabTitles();
      var items = searchIdx || [];
      var kw = q.trim().toLowerCase();
      var hits = [];
      if (kw !== '') {
        for (var i = 0; i < items.length && hits.length < 12; i++) {
          var it = items[i];
          var hay = (it[1] + ' ' + it[2] + ' ' + (it[3] || '') + ' ' + (tabs[it[0]] || '')).toLowerCase();
          if (hay.indexOf(kw) !== -1) hits.push(it);
        }
      }
      activeItem = -1;
      if (hits.length === 0) {
        adminPop.hidden = kw === '';
        adminPop.innerHTML = kw === '' ? '' : '<div class="asr-empty">没有匹配的设置项</div>';
        return;
      }
      adminPop.hidden = false;
      adminPop.innerHTML = hits.map(function (it, n) {
        return '<button type="button" class="asr" data-n="' + n + '">' +
          '<b>' + (it[2] || it[1]) + '</b>' +
          '<span>' + it[1] + ' · ' + (tabs[it[0]] || it[0]) + '</span></button>';
      }).join('');
      adminPop._hits = hits;
      $$('.asr', adminPop).forEach(function (btn) {
        btn.addEventListener('mousedown', function (e) { // mousedown 先于 blur，避免弹层先收起
          e.preventDefault();
          gotoItem(adminPop._hits[(parseInt(btn.getAttribute('data-n'), 10) || 0)]);
        });
      });
    }

    adminSearch.addEventListener('focus', function () { loadIdx(function () { renderPop(adminSearch.value); }); });
    adminSearch.addEventListener('input', function () { loadIdx(function () { renderPop(adminSearch.value); }); });
    adminSearch.addEventListener('keydown', function (e) {
      var rows = $$('.asr', adminPop);
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        if (!rows.length) return;
        e.preventDefault();
        activeItem = e.key === 'ArrowDown' ? (activeItem + 1) % rows.length : (activeItem - 1 + rows.length) % rows.length;
        rows.forEach(function (r, n) { r.classList.toggle('on', n === activeItem); });
      } else if (e.key === 'Enter') {
        if (adminPop._hits && adminPop._hits.length) {
          e.preventDefault();
          gotoItem(adminPop._hits[activeItem >= 0 ? activeItem : 0]);
        } else if (!searchIdx && adminSearch.value.trim() !== '') {
          /* v1.30.0：索引还在路上时回车不再无响应——等索引就位后自动跳第一个命中 */
          e.preventDefault();
          loadIdx(function () {
            renderPop(adminSearch.value);
            if (adminPop._hits && adminPop._hits.length) gotoItem(adminPop._hits[0]);
          });
        }
      } else if (e.key === 'Escape') {
        adminPop.hidden = true;
        adminSearch.blur();
      }
    });
    document.addEventListener('click', function (e) {
      if (!adminSearch.contains(e.target) && !adminPop.contains(e.target)) adminPop.hidden = true;
    });

    /* 跨页跳转落地：读取 pending 定位信息，滚动到目标卡片并高亮 */
    var pending = sessionStorage.getItem('mfsetPending');
    if (pending) {
      try {
        var it = JSON.parse(pending);
        sessionStorage.removeItem('mfsetPending');
        setTimeout(function () { highlightCard(it[1], it[2] || ''); }, 150);
      } catch (e) { sessionStorage.removeItem('mfsetPending'); }
    }
  }

  /* ---------- v1.25.0 板块发帖权限面板：类型切换时联动显示白名单输入（v1.27.0 三选） ---------- */
  $$('[data-perm-form]').forEach(function (form) {
    var type = form.querySelector('[data-perm-type]');
    var us = form.querySelector('[data-perm-users]');
    if (!type) return;
    function sync() {
      var v = type.value;
      if (us) us.hidden = v !== 'users';
    }
    type.addEventListener('change', sync);
    sync();
  });

  /* ---------- v1.23.0：行云流水 · 导航编排（高延迟网络动效层） ----------
     职责：让 200ms～7s 延迟下的页面切换依然平滑——
     · 点击瞬间（0ms）：顶部进度条启动 + 旧内容轻淡出（顶栏 / 侧栏不动，永不白屏）
     · 等待期：浏览器持续渲染旧页，进度条先快后慢爬行表达「仍在加载」
     · 预取：hover / 触摸即预热目标页（提前建立连接，高延迟下省 1～2 个往返）
     · 表单提交：按钮转 loading 态并锁住，防高延迟下连点重复提交
     · bfcache 恢复（后退 / 前进瞬时）：自动复位进度条与按钮并刷新实时数据
     支持跨文档 View Transitions 的浏览器（Chrome / Edge 126+）新旧页交叉淡化
     由合成器接管（CSS 端），这里只保留进度条；其余浏览器走轻淡出兼容方案。 */
  var MFNAV = (function () {
    var enabled = window.MF_MOTION !== false
      && !(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches);
    /* 暴露给非 click 导航（后台设置搜索跳转等）的手动入口 */
    if (!enabled) { window.MF_NAV_START = function () {}; return null; }

    var hasVT = false;
    try { hasVT = window.CSS && CSS.supports && CSS.supports('view-transition-name', 'a'); } catch (e) { hasVT = false; }

    var prog = $('.mf-nprog');
    var guardTimer = null;
    var fadeTimer = null;
    var sweeping = false;
    var leaving = false;

    function start() {
      try { sessionStorage.setItem('mfNavGo', '1'); } catch (err) { /* 无痕限制下忽略 */ }
      if (prog) {
        if (fadeTimer) { clearTimeout(fadeTimer); fadeTimer = null; }
        prog.hidden = false;
        prog.classList.remove('done');
        prog.classList.remove('fade');
        if (!prog.classList.contains('run')) {
          void prog.offsetWidth; /* 强制重排以重启动画 */
          prog.classList.add('run');
        }
      }
      if (!hasVT && !leaving) {
        leaving = true;
        document.documentElement.classList.add('mf-leaving');
      }
      /* v1.31.0 安全阀改造：慢加载不再“假装完成”——此前 6s 未见卸载就把进度条收走，
         慢网络下真实加载还在继续，用户看到的是“进度条没了却还在下载”；
         现在进度条爬到高位后原地保持（仍在加载就该看得见在加载），
         仅当 20s 仍无页面卸载（导航被取消 / 下载链接等）才原地淡出收尾；
         真正的完成反馈由新页到达（pageshow → 完成扫条）触发 */
      clearTimeout(guardTimer);
      guardTimer = setTimeout(fade, 20000);
    }

    /* 取消兜底：进度条原地淡出（不伪装 100% 完成，不误导“已加载完”） */
    function fade() {
      guardTimer = null;
      if (prog && !prog.hidden) {
        prog.classList.add('fade');
        fadeTimer = setTimeout(function () {
          fadeTimer = null;
          prog.hidden = true;
          prog.classList.remove('run', 'fade');
        }, 480);
      }
      if (leaving) {
        document.documentElement.classList.remove('mf-leaving');
        leaving = false;
      }
    }

    function done(sweep) {
      clearTimeout(guardTimer);
      guardTimer = null;
      if (fadeTimer) { clearTimeout(fadeTimer); fadeTimer = null; }
      if (prog) {
        if (sweep) {
          /* v1.31.0：到达后快速完成扫条——此前完成反馈实际不可见：新页进度条
             服务端渲染为 hidden，旧页进度条随文档销毁，用户从来看不到“加载完成” */
          sweeping = true;
          prog.hidden = false;
          prog.classList.remove('run');
          prog.classList.add('done');
          setTimeout(function () {
            sweeping = false;
            if (!guardTimer) { prog.hidden = true; prog.classList.remove('done'); }
          }, 380);
        } else if (!sweeping) {
          prog.hidden = true;
          prog.classList.remove('run', 'done');
        }
      }
      if (leaving) {
        document.documentElement.classList.remove('mf-leaving');
        leaving = false;
      }
    }
    window.MF_NAV_START = start;

    /* v1.31.0：到达即完成扫条（app.js 为 defer，执行时 HTML 已解析完，不等慢图片拖住 load）；
       仅当上一页确实发起过导航（mfNavGo 标记）才扫，首开 / 直接输入网址不扫 */
    try {
      if (sessionStorage.getItem('mfNavGo') === '1') {
        sessionStorage.removeItem('mfNavGo');
        done(true);
      }
    } catch (err) { /* 忽略 */ }

    /* 页面到达 / bfcache 恢复：静默复位（扫条进行中不打断）+ bfcache 时刷新实时数据 */
    window.addEventListener('pageshow', function (e) {
      done(false);
      $$('.is-loading').forEach(function (b) { b.classList.remove('is-loading'); });
      $$('form[data-busy]').forEach(function (f) { f.removeAttribute('data-busy'); });
      if (e.persisted && typeof liveTick === 'function') {
        try { liveTick(); } catch (err) { /* 忽略 */ }
      }
    });

    /* ---- 链接预取：同源 GET 导航提前预热（连接复用 + 响应可能入缓存） ---- */
    var prefetched = {};
    var SKIP_RE = /[?&](a=logout|p=(asset|ping|icon|plugin))(\b|&|$)/;
    function prefetch(url) {
      if (!url || prefetched[url] || SKIP_RE.test(url)) return;
      if (url.indexOf(location.origin + '/') !== 0 && url.charAt(0) !== '/' && url.charAt(0) !== 'i') return; // 仅同源
      prefetched[url] = 1;
      try {
        fetch(url, { credentials: 'same-origin', priority: 'low', headers: { 'X-MF-Prefetch': '1' } })
          .catch(function () { /* 预取失败静默，真实导航自行处理 */ });
      } catch (err) { /* 忽略 */ }
    }

    /* 点击拦截（capture 抢先执行，保证 0ms 反馈）：站内链接 → 进度条 + 退场 + 预取 */
    document.addEventListener('click', function (e) {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
      if (!a) return;
      var href = a.getAttribute('href') || '';
      if (href === '' || href.charAt(0) === '#' || /^(javascript|mailto|tel|data):/i.test(href)) return;
      if (a.target && a.target !== '_self') return;
      if (a.hasAttribute('download')) return;
      if (a.origin && a.origin !== location.origin) return;
      prefetch(a.href);
      /* v1.31.0：延一拍再启动反馈——让后续 click 处理器有机会 preventDefault
         （被 JS 接管的链接不会真实导航），避免进度条为“不会发生的导航”空转；
         延迟为一个宏任务（≈0ms），反馈依然瞬时 */
      var evt = e;
      setTimeout(function () { if (!evt.defaultPrevented) start(); }, 0);
    }, true);

    /* 移动端：触摸即预取（比 click 早 200ms+，只预热不启动反馈防误触） */
    document.addEventListener('touchstart', function (e) {
      var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
      if (a) prefetch(a.href);
    }, { passive: true });

    /* 桌面端：悬停 65ms 后预取（防划过链接误预热；高延迟下 hover→click 的间隙正好完成预热） */
    var hovTimer = null, hovLink = null;
    document.addEventListener('pointerover', function (e) {
      var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
      if (!a || a === hovLink) return;
      clearTimeout(hovTimer);
      var href = a.getAttribute('href') || '';
      if (href === '' || href.charAt(0) === '#' || /^(javascript|mailto|tel|data):/i.test(href)) return;
      if (a.target && a.target !== '_self') return;
      if (a.origin && a.origin !== location.origin) return;
      hovLink = a;
      hovTimer = setTimeout(function () { prefetch(a.href); hovLink = null; }, 65);
    }, { passive: true });

    /* 表单提交：真实导航型表单（非 data-ajax）→ 按钮 loading 态 + 防重复 + 反馈
       （危险操作确认取消时 stopPropagation 已拦在上游，此处只在确认通过后执行） */
    document.addEventListener('submit', function (e) {
      var f = e.target;
      if (!f || f.hasAttribute('data-ajax') || f.hasAttribute('data-busy')) return;
      var btns = $$('button[type="submit"], button:not([type])', f).filter(function (b) {
        return !b.classList.contains('like-btn') && !b.hasAttribute('formaction');
      });
      if (btns.length) {
        f.setAttribute('data-busy', '1');
        btns.forEach(function (b) { b.classList.add('is-loading'); });
      }
      start();
    });

    return { start: start, done: done, prefetch: prefetch };
  })();

  /* ---------------- v1.32.0 图片 / 附件上传（发帖 / 回复 / 编辑表单的上传工具条） ---------------- */
  (function () {
    var bars = document.querySelectorAll('.upload-bar');
    if (!bars.length) return;
    function csrfOf(form) {
      var i = form && form.querySelector('input[name=csrf]');
      return i ? i.value : '';
    }
    function insertMd(ta, md) {
      var s = typeof ta.selectionStart === 'number' ? ta.selectionStart : ta.value.length;
      var e2 = typeof ta.selectionEnd === 'number' ? ta.selectionEnd : s;
      var before = ta.value.slice(0, s), after = ta.value.slice(e2);
      var sep = (before === '' || /\n$|\s$/.test(before)) ? '' : '\n';
      ta.value = before + sep + md + '\n' + after;
      var pos = (before + sep + md + '\n').length;
      try { ta.setSelectionRange(pos, pos); } catch (ex) { /* 旧浏览器忽略 */ }
      ta.dispatchEvent(new Event('input', { bubbles: true }));
      ta.focus();
    }
    Array.prototype.forEach.call(bars, function (bar) {
      var btn = bar.querySelector('[data-upload-btn]');
      var input = bar.querySelector('[data-upload-input]');
      if (!btn || !input) return;
      var form = bar.closest('form');
      btn.addEventListener('click', function () { input.click(); });
      input.addEventListener('change', function () {
        var files = Array.prototype.slice.call(input.files || []);
        input.value = '';
        var sel = input.getAttribute('data-upload-target');
        var ta = (form && sel ? form.querySelector(sel) : null) || document.querySelector(sel);
        if (!files.length || !ta) return;
        files.forEach(function (file) {
          var fd = new FormData();
          fd.append('csrf', csrfOf(form));
          fd.append('file', file);
          var xhr = new XMLHttpRequest();
          xhr.open('POST', apiUrl('a=upload'), true);
          xhr.setRequestHeader('X-Requested-With', 'fetch');
          xhr.onload = function () {
            try {
              var j = JSON.parse(xhr.responseText);
              if (j && j.ok && j.md) { insertMd(ta, j.md); }
              else { popMsg('asr-empty', (j && j.msg) || '上传失败'); }
            } catch (e) { popMsg('asr-empty', '上传失败'); }
          };
          xhr.onerror = function () { popMsg('asr-empty', '上传失败（网络错误）'); };
          xhr.send(fd);
        });
      });
    });
  })();

  /* ---------- v1.34.0 扫码登录（登录页页签 + 一次性令牌轮询；二维码为服务端 SVG，前端零依赖渲染） ---------- */
  (function () {
    var tabPass = $('#qrTabPass'), tabQr = $('#qrTabQr');
    if (!tabPass || !tabQr) return;
    var panePass = $('#loginPass'), paneQr = $('#loginQr');
    var box = $('#qrBox'), hint = $('#qrHint'), refreshBtn = $('#qrRefresh');
    var timer = null;

    function setStatus(msg, warn) {
      if (hint) { hint.textContent = msg; hint.className = 'qr-hint' + (warn ? ' qr-warn' : ''); }
    }
    function stop() { if (timer) { clearTimeout(timer); timer = null; } }
    function showRefresh() { stop(); if (refreshBtn) refreshBtn.hidden = false; }

    function poll(t) {
      stop();
      timer = setTimeout(function () {
        fetch(apiUrl('a=qr_check&t=' + encodeURIComponent(t)), {
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'fetch', 'X-CSRF-Token': csrf() }
        }).then(function (r) { return r.json(); }).then(function (j) {
          if (!j || j.ok === false) { setStatus((j && j.msg) || '网络异常，请重试', true); showRefresh(); return; }
          if (j.s === 'ok') {
            setStatus('扫码成功，正在进入论坛…');
            setTimeout(function () { location.href = j.url || apiUrl('p=home'); }, 300);
            return;
          }
          if (j.s === 'expired') { setStatus('二维码已过期，请点击重新生成', true); showRefresh(); return; }
          poll(t);
        }).catch(function () { poll(t); });
      }, 2000);
    }

    function create() {
      stop();
      if (refreshBtn) refreshBtn.hidden = true;
      if (box) box.innerHTML = '<span class="qr-loading">正在生成二维码…</span>';
      setStatus('用已登录本站的手机等设备扫码，确认后本机自动登录');
      postForm(apiUrl('a=qr_create'), {}, function (j) {
        if (!j || !j.ok) { setStatus((j && j.msg) || '二维码生成失败，请重试', true); showRefresh(); return; }
        if (box) box.innerHTML = '<img class="qr-img" alt="登录二维码" width="216" height="216" src="' + apiUrl('p=qrimg&t=' + encodeURIComponent(j.t)) + '">';
        poll(j.t);
      });
    }

    function showQr(on) {
      tabPass.classList.toggle('on', !on);
      tabQr.classList.toggle('on', on);
      tabPass.setAttribute('aria-selected', on ? 'false' : 'true');
      tabQr.setAttribute('aria-selected', on ? 'true' : 'false');
      if (panePass) panePass.hidden = on;
      if (paneQr) paneQr.hidden = !on;
      if (on) { create(); } else { stop(); }
    }

    tabPass.addEventListener('click', function () { showQr(false); });
    tabQr.addEventListener('click', function () { showQr(true); });
    if (refreshBtn) refreshBtn.addEventListener('click', create);
    /* 页签键盘可达：左右方向键切换（原生 Tab / Enter 已可用） */
    [tabPass, tabQr].forEach(function (b) {
      b.addEventListener('keydown', function (ev) {
        if (ev.key !== 'ArrowLeft' && ev.key !== 'ArrowRight') return;
        var next = ev.key === 'ArrowRight' ? tabQr : tabPass;
        next.focus(); next.click();
      });
    });
  })();
  })();
