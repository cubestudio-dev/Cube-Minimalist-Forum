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

  /* ---------- v1.45.0 功能一键自检（后台·监控）：逐项步进实时进度版 ----------
     检查进度 / 修复进度 / 具体清单 / 运行日志全程可视：
     · 清单 11 项常驻，状态六态实时点亮（待检测 / 检测中 / 通过 / 警告 / 失败 / 跳过）
     · 「检查进度」条逐项推进；远程项（真实发信 + AI 真调用）不再黑盒转圈
     · 「停止」可随时中断，「继续检测」从断点续跑（远程项不重复执行）
     · 「一键修复（N）」逐项修复，「修复进度」条同步推进，修完自动复检受影响项
     · 终端式日志区逐行滚动输出，支持自动滚动 / 复制 / 下载 .txt */
  (function () {
    var CAT = window.SC_CATALOG || [];
    var scRun = document.getElementById('scRun');
    var scRows = document.getElementById('scRows');
    if (!CAT.length || !scRun || !scRows) return;
    var scStop = document.getElementById('scStop');
    var scFix = document.getElementById('scFix');
    var scRemote = document.getElementById('scRemote');
    var scProg = document.getElementById('scProg');
    var scProgTx = document.getElementById('scProgTx');
    var scCur = document.getElementById('scCur');
    var scSum = document.getElementById('scSum');
    var scLog = document.getElementById('scLog');
    var scLogN = document.getElementById('scLogN');
    var scAuto = document.getElementById('scAuto');
    var sfProgRow = document.getElementById('sfProgRow');
    var sfProg = document.getElementById('sfProg');
    var sfProgTx = document.getElementById('sfProgTx');
    var logCopy = document.getElementById('scLogCopy');
    var logDl = document.getElementById('scLogDl');

    var KEYS = CAT.map(function (c) { return c.key; });
    var NAME = {};
    var TOTAL = KEYS.length;
    CAT.forEach(function (c) { NAME[c.key] = c.name; });
    var FIX_NAME = {
      repair_dirs: '修复目录权限', rebuild_index: '重建帖子索引', clear_snapshot: '清除静态快照',
      clean_temp: '清理陈旧锁与探针残留', backup_damaged: '备份损坏数据文件'
    };
    var FIX2CHECK = { repair_dirs: 'rw', rebuild_index: 'store', clear_snapshot: 'snapshot', clean_temp: 'temp', backup_damaged: 'store' };
    var IC = {
      pass: ['✔', 'sc-pass'],
      warn: ['▲', 'sc-warn'],
      fail: ['✘', 'sc-fail'],
      skip: ['—', 'sc-skip'],
      run:  ['◐', 'sc-run'],
      pend: ['○', 'sc-pend']
    };
    var results = {};   /* key -> item（服务端返回的原样结果） */
    var logs = [];      /* 日志行（随报告落盘，回看可见） */
    var stopReq = false;
    var running = false;
    var phase = 'fresh'; /* fresh | running | paused | done */

    function nowT() {
      var d = new Date();
      var p = function (n) { return ('0' + n).slice(-2); };
      return p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
    }
    function logLine(txt) {
      logs.push(txt);
      if (logs.length > 400) { logs.splice(0, logs.length - 400); }
      var line = document.createElement('div');
      line.textContent = '[' + nowT() + '] ' + txt;
      scLog.appendChild(line);
      if (scLogN) scLogN.textContent = logs.length + ' 行';
      if (scAuto && scAuto.checked) scLog.scrollTop = scLog.scrollHeight;
    }
    function setIc(key, st) {
      var el = document.getElementById('scIc-' + key);
      var m = IC[st] || IC.pend;
      if (el) { el.textContent = m[0]; el.className = 'sc-ic ' + m[1]; }
    }
    function setDt(key, html) {
      var el = document.getElementById('scDt-' + key);
      if (el) el.innerHTML = html;
    }
    function setCur(txt) { if (scCur) scCur.textContent = txt; }
    function progSet(el, tx, done, total, label) {
      if (tx) tx.textContent = label + ' ' + done + '/' + total;
      if (el) el.style.width = (total ? Math.round(done / total * 100) : 0) + '%';
    }
    function orderedItems() {
      return KEYS.map(function (k) { return results[k]; }).filter(Boolean);
    }
    function fixables() {
      var out = [];
      orderedItems().forEach(function (x) {
        if (x.fixable && x.fixkey && out.indexOf(x.fixkey) < 0) out.push(x.fixkey);
      });
      return out;
    }
    function nextPending() {
      for (var i = 0; i < KEYS.length; i++) { if (!results[KEYS[i]]) return KEYS[i]; }
      return null;
    }
    function doneCount() {
      var n = 0; KEYS.forEach(function (k) { if (results[k]) n++; });
      return n;
    }
    function sumOf(items) {
      var s = { pass: 0, warn: 0, fail: 0, skip: 0 };
      items.forEach(function (x) { s[x.state] = (s[x.state] || 0) + 1; });
      return s;
    }
    function renderSum(s) {
      if (!scSum) return;
      if (!s) { scSum.innerHTML = ''; return; }
      scSum.innerHTML =
        '<span class="sc-ok">通过 ' + (s.pass || 0) + '</span> · ' +
        '<span class="sc-warn-tx">警告 ' + (s.warn || 0) + '</span> · ' +
        '<span class="sc-fail-tx">失败 ' + (s.fail || 0) + '</span> · ' +
        '<span class="sc-skip-tx">跳过 ' + (s.skip || 0) + '</span>';
    }
    function applyItem(key, x, ms, tag) {
      results[key] = x;
      var m = IC[x.state] || IC.warn;
      setIc(key, x.state);
      setDt(key, escHtml(x.detail || '') + (x.fixable ? ' <i style="font-style:normal;color:var(--ok)" class="sc-fixtag">〔可自动修复〕</i>' : '') +
        ' <span class="sc-ms">' + (tag ? tag + ' · ' : '') + (ms || 0) + ' ms</span>');
      var row = document.getElementById('scRow-' + key);
      if (row) row.className = x.state === 'fail' ? 'sc-row-bad' : '';
      logLine(m[0] + (tag ? ' ' + tag + ' ' : ' ') + NAME[key] + '（' + (ms || 0) + ' ms）：' + (x.detail || ''));
    }
    function showFixBtn() {
      var fk = fixables();
      if (scFix) {
        scFix.hidden = fk.length === 0;
        scFix.disabled = false;
        scFix.textContent = fk.length ? '一键修复（' + fk.length + '）' : '一键修复';
      }
    }
    /* 收尾：落盘报告（含日志）→ 汇总点亮 → 决定是否亮出修复按钮 */
    function finishRun(after) {
      var items = orderedItems();
      if (!items.length) { if (after) after(); return; }
      var s = sumOf(items);
      postForm(apiUrl('a=admin_sc_finish'), {
        items: JSON.stringify(items),
        logs: JSON.stringify(logs.slice(-200)),
        remote: scRemote && scRemote.checked ? 1 : 0
      }, function (res) {
        renderSum((res && res.ok && res.sum) ? res.sum : s);
        var tail = '—— 自检完成：通过 ' + (s.pass || 0) + ' / 警告 ' + (s.warn || 0) + ' / 失败 ' + (s.fail || 0) + ' / 跳过 ' + (s.skip || 0) + ' ——';
        logLine(tail);
        toast('自检完成：通过 ' + (s.pass || 0) + ' / 警告 ' + (s.warn || 0) + ' / 失败 ' + (s.fail || 0) + ' / 跳过 ' + (s.skip || 0));
        if (res && !res.ok) logLine('⚠ 报告落盘失败：' + (res.msg || '未知原因') + '（本地结果仍可见）');
        showFixBtn();
        if (after) after();
      });
    }
    function setBtn(mode) {
      if (mode === 'running') {
        scRun.disabled = true;
        scRun.textContent = scRemote && scRemote.checked ? '自检中…（含真实发信与 AI 调用）' : '自检中…';
        if (scStop) { scStop.hidden = false; scStop.disabled = false; }
        return;
      }
      if (scStop) scStop.hidden = true;
      scRun.disabled = false;
      if (mode === 'resume') { scRun.textContent = '继续检测'; phase = 'paused'; }
      else if (mode === 'rerun') { scRun.textContent = '重新自检'; phase = 'done'; }
      else { scRun.textContent = '开始自检'; phase = 'fresh'; }
    }
    function resetRun() {
      results = {};
      logs = [];
      scLog.innerHTML = '';
      if (scLogN) scLogN.textContent = '';
      KEYS.forEach(function (k) {
        setIc(k, 'pend');
        setDt(k, '<span class="muted">待检测</span>');
        var row = document.getElementById('scRow-' + k);
        if (row) row.className = '';
      });
      progSet(scProg, scProgTx, 0, TOTAL, '检查进度');
      if (sfProgRow) sfProgRow.hidden = true;
      progSet(sfProg, sfProgTx, 0, 0, '修复进度');
      renderSum(null);
      if (scFix) scFix.hidden = true;
    }
    function runLoop() {
      if (stopReq) {
        running = false;
        setBtn('resume');
        setCur('已停止：完成 ' + doneCount() + '/' + TOTAL + '——点「继续检测」从断点接着跑（已完成项不重复）');
        logLine('⏸ 已停止（完成 ' + doneCount() + '/' + TOTAL + '）');
        return;
      }
      var key = nextPending();
      if (!key) {
        running = false;
        progSet(scProg, scProgTx, TOTAL, TOTAL, '检查进度');
        setCur('全部 ' + TOTAL + ' 项检测完毕。');
        setBtn('rerun');
        finishRun();
        return;
      }
      var idx = doneCount();
      var c = CAT[KEYS.indexOf(key)];
      progSet(scProg, scProgTx, idx, TOTAL, '检查进度');
      setCur('正在检测（' + (idx + 1) + '/' + TOTAL + '）：' + c.name + (c.remote ? '（远程真实测试，可能需要数秒到数十秒）' : '') + '…');
      setIc(key, 'run');
      logLine('▶ ' + c.name + (c.remote ? '〔远程〕' : '') + '…');
      postForm(apiUrl('a=admin_sc_step'), { key: key, remote: scRemote && scRemote.checked ? 1 : 0 }, function (res) {
        if (!res || !res.ok || !res.item) {
          var why = (res && res.msg) ? res.msg : '网络错误，请重试';
          applyItem(key, { key: key, state: 'fail', detail: '通信失败：' + why }, 0);
        } else {
          applyItem(key, res.item, res.ms || 0);
        }
        progSet(scProg, scProgTx, doneCount(), TOTAL, '检查进度');
        setTimeout(runLoop, 280); /* 轻微步进间隔，让进度可见（远程项本身耗时，不受影响） */
      });
    }
    scRun.addEventListener('click', function () {
      if (running || phase === 'running') return;
      if (phase === 'done' || phase === 'fresh' && nextPending() === null) resetRun();
      running = true;
      stopReq = false;
      setBtn('running');
      logLine('—— 开始自检（共 ' + TOTAL + ' 项，' + (scRemote && scRemote.checked ? '含远程真实测试' : '仅本地项') + '）——');
      runLoop();
    });
    if (scStop) scStop.addEventListener('click', function () {
      stopReq = true;
      scStop.disabled = true;
      setCur('正在停止…（当前项跑完后中断）');
    });
    /* 一键修复：逐项修复 → 修复进度推进 → 修完自动复检受影响项 → 再落盘 */
    if (scFix) scFix.addEventListener('click', function () {
      var fks = fixables();
      if (!fks.length || running) return;
      running = true;
      stopReq = false;
      scFix.disabled = true;
      scRun.disabled = true;
      if (scStop) scStop.hidden = true;
      if (sfProgRow) sfProgRow.hidden = false;
      progSet(sfProg, sfProgTx, 0, fks.length, '修复进度');
      logLine('—— 开始一键修复（' + fks.length + ' 项 · 保守修复：先备份，绝不自动删数据）——');
      var i = 0;
      var step = function () {
        if (i >= fks.length) {
          /* 修复完成：自动复检受影响项（本地模式，不重复远程调用） */
          var cks = [];
          fks.forEach(function (f) { var ck = FIX2CHECK[f]; if (ck && cks.indexOf(ck) < 0) cks.push(ck); });
          setCur('修复完成，自动复检受影响项（' + cks.map(function (k) { return NAME[k]; }).join('、') + '）…');
          logLine('—— 修复完成，自动复检受影响项 ——');
          var j = 0;
          var recheck = function () {
            if (j >= cks.length) {
              running = false;
              scFix.disabled = false;
              scRun.disabled = false;
              setBtn('rerun');
              progSet(sfProg, sfProgTx, fks.length, fks.length, '修复进度');
              finishRun();
              return;
            }
            var ck = cks[j++];
            delete results[ck];
            setIc(ck, 'run');
            setCur('复检（' + j + '/' + cks.length + '）：' + NAME[ck] + '…');
            logLine('▶ 〔复检〕' + NAME[ck] + '…');
            postForm(apiUrl('a=admin_sc_step'), { key: ck, remote: 0 }, function (res) {
              if (res && res.ok && res.item) applyItem(ck, res.item, res.ms || 0, '〔复检〕');
              else logLine('⚠ 〔复检〕' + NAME[ck] + '：通信失败，保持原状态');
              setTimeout(recheck, 280);
            });
          };
          recheck();
          return;
        }
        var fk = fks[i++];
        setCur('正在修复（' + i + '/' + fks.length + '）：' + (FIX_NAME[fk] || fk) + '…');
        logLine('▶ 〔修复〕' + (FIX_NAME[fk] || fk) + '…');
        postForm(apiUrl('a=admin_sf_step'), { key: fk }, function (res) {
          var entries = (res && res.log) || [];
          entries.forEach(function (l) {
            logLine((l.ok ? '✔ 〔修复〕' : '✘ 〔修复〕') + l.name + '：' + l.msg);
          });
          if (!res || !res.ok) logLine('⚠ 〔修复〕' + (FIX_NAME[fk] || fk) + '：执行异常' + ((res && res.msg) ? '——' + res.msg : ''));
          progSet(sfProg, sfProgTx, i, fks.length, '修复进度');
          setTimeout(step, 280);
        });
      };
      step();
    });
    /* 日志复制 / 下载 */
    function logText() { return logs.join('\n'); }
    if (logCopy) logCopy.addEventListener('click', function () {
      var txt = logText();
      if (!txt) { toast('暂无日志——先跑一次自检'); return; }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(txt).then(function () { toast('日志已复制到剪贴板'); }, function () { fallbackCopy(txt, function () { toast('日志已复制到剪贴板'); }); });
      } else {
        fallbackCopy(txt, function () { toast('日志已复制到剪贴板'); });
      }
    });
    if (logDl) logDl.addEventListener('click', function () {
      var txt = logText();
      if (!txt) { toast('暂无日志——先跑一次自检'); return; }
      var d = new Date();
      var p = function (n) { return ('0' + n).slice(-2); };
      var name = 'selfcheck-' + d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate()) + '-' + p(d.getHours()) + p(d.getMinutes()) + p(d.getSeconds()) + '.txt';
      var blob = new Blob([txt], { type: 'text/plain;charset=utf-8' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = name;
      document.body.appendChild(a);
      a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); if (a.parentNode) a.parentNode.removeChild(a); }, 800);
      toast('日志已下载：' + name);
    });
    /* 进页回看：上次自检的运行日志（服务端存档）预填日志区 */
    (function () {
      var last = window.SC_LAST_LOGS || [];
      if (!last.length || !scLog) return;
      var head = document.createElement('div');
      head.className = 'sc-log-head';
      head.textContent = '— — — 上次自检日志（存档，点「开始自检」开始新一轮）— — —';
      scLog.appendChild(head);
      last.forEach(function (l) {
        var line = document.createElement('div');
        line.textContent = String(l);
        scLog.appendChild(line);
      });
      if (scLogN) scLogN.textContent = last.length + ' 行（存档）';
      logs = last.map(String);
      scLog.scrollTop = scLog.scrollHeight;
    })();
  })();

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

  /* ---------------- v1.32.0 图片 / 附件上传（发帖 / 回复 / 编辑表单的上传工具条）
     v1.42.0 重构：粘贴 / 拖拽直传 + 附件预览条（图片本地缩略 / 文件图标 + 名称 + 大小 + 进度）
     · 客户端预检：扩展名（白名单 / 拒绝清单）与大小超限即时报错，不发起无效往返；
     · 逐文件 XHR 进度回显（大文件不再「点了没反应」）；
     · 失败原因精确到响应：413 / 403 / 登录过期 / 500 / 网络中断逐一分辨——
       此前错误提示调用的 popMsg 定义在后台设置搜索块内（块级作用域不可达），
       上传失败必抛 ReferenceError，用户看不到任何提示（静默失败），本版改用全局 toast；
     · 附件预览条计数与后台 upload_per_post 上限联动，超限提前提醒（服务端仍兜底）。 */
  (function () {
    var bars = Array.prototype.slice.call(document.querySelectorAll('[data-upbar]'));
    if (!bars.length) return;
    var IMG_EXT = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
    var DENY = ['php', 'php3', 'php5', 'php7', 'phtml', 'phar', 'cgi', 'pl', 'py', 'sh', 'bash', 'htaccess', 'svg', 'exe', 'bat', 'cmd', 'com', 'js', 'jse', 'vbs', 'html', 'htm', 'ini', 'dll', 'so'];

    function fmtSize(n) {
      if (!n || n < 0) return '0 B';
      if (n < 1024) return n + ' B';
      if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
      return (n / 1048576).toFixed(1) + ' MB';
    }
    function extOf(name) {
      var m = /\.([a-z0-9]{1,8})$/i.exec(String(name || ''));
      return m ? m[1].toLowerCase() : '';
    }
    /* 扩展名 → 徽标（文字 + 色族）；未知类型 FILE 中性灰 */
    function iconOf(ext) {
      if (IMG_EXT.indexOf(ext) >= 0) return ['图片', 'img'];
      if (['zip', 'rar', '7z', 'tar', 'gz'].indexOf(ext) >= 0) return [ext.toUpperCase(), 'zip'];
      if (['txt', 'md', 'log'].indexOf(ext) >= 0) return ['TXT', 'txt'];
      if (ext === 'pdf') return ['PDF', 'pdf'];
      if (['doc', 'docx', 'rtf', 'odt'].indexOf(ext) >= 0) return ['DOC', 'doc'];
      if (['xls', 'xlsx', 'csv', 'ods'].indexOf(ext) >= 0) return ['XLS', 'xls'];
      if (['ppt', 'pptx', 'odp'].indexOf(ext) >= 0) return ['PPT', 'ppt'];
      if (['mp3', 'wav', 'flac', 'm4a', 'ogg'].indexOf(ext) >= 0) return ['音频', 'aud'];
      if (['mp4', 'mov', 'webm', 'mkv', 'avi'].indexOf(ext) >= 0) return ['视频', 'vid'];
      if (['exe', 'msi', 'bat', 'cmd', 'sh', 'appimage', 'dmg'].indexOf(ext) >= 0) return [ext.toUpperCase(), 'exe'];
      return [(ext || 'FILE').toUpperCase().slice(0, 4), 'gen'];
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
    /* 上传失败原因精确诊断：j=服务端 JSON 时用其 msg；否则按 HTTP 状态与响应形态分辨 */
    function diag(xhr, j) {
      if (j) return j.msg || '上传失败';
      var s = xhr.status;
      if (s === 413) return '文件在 PHP 处理前就被服务器拒绝（请求体超限）：多为 nginx client_max_body_size 或 Apache FcgidMaxRequestLen 小于文件大小，请调大服务器上限后重试';
      if (s === 403) return '上传被服务器安全层拦截（403）——连续上传多个文件可能触发限流，请稍等片刻再试';
      if (s === 401) return '登录已过期，请刷新页面重新登录后再上传';
      if (s === 500) return '服务器处理失败（500），请稍后重试；若反复出现请联系站长查看 data/logs/error.log';
      if (s === 0) return '网络错误或连接中断，请重试';
      if (s >= 200 && s < 300) return '服务器返回了无法解析的响应（可能登录已过期），请刷新页面后重试';
      return '上传失败（HTTP ' + s + '）';
    }

    Array.prototype.forEach.call(bars, function (bar) {
      var btn = bar.querySelector('[data-upload-btn]');
      var input = bar.querySelector('[data-upload-input]');
      var sel = bar.getAttribute('data-ta') || (input && input.getAttribute('data-upload-target'));
      var form = bar.closest('form');
      var ta = null;
      try { ta = sel ? ((form && form.querySelector(sel)) || document.querySelector(sel)) : null; } catch (e) { ta = null; }
      if (!btn || !input || !ta) return;
      var imgMax = (parseFloat(bar.getAttribute('data-img-max')) || 5) * 1048576;
      var fileMax = (parseFloat(bar.getAttribute('data-file-max')) || 10) * 1048576;
      var allow = (bar.getAttribute('data-exts') || '').toLowerCase().split(/[\s,，;；]+/).filter(Boolean);
      var cap = parseInt(bar.getAttribute('data-cap'), 10) || 0;
      var okCount = 0;

      /* 附件预览条：动态插在工具条之后（零侵入，老标记兼容） */
      var strip = document.createElement('div');
      strip.className = 'upx';
      strip.hidden = true;
      var capEl = null;
      if (cap > 0) {
        capEl = document.createElement('div');
        capEl.className = 'upx-cap';
        strip.appendChild(capEl);
      }
      var list = document.createElement('div');
      list.className = 'upx-list';
      strip.appendChild(list);
      bar.parentNode.insertBefore(strip, bar.nextSibling);

      function updCap() {
        if (!capEl) return;
        capEl.textContent = '附件 ' + okCount + ' / ' + cap + (okCount >= cap ? '（已达单篇上限，超出的引用将无法保存）' : '');
        capEl.classList.toggle('over', okCount >= cap);
      }
      function dropChip(c, blob) {
        if (blob && window.URL && URL.revokeObjectURL) { try { URL.revokeObjectURL(blob); } catch (e) {} }
        if (c.parentNode) c.parentNode.removeChild(c);
        if (!list.children.length) strip.hidden = true;
      }
      /* 生成一个 chip；返回 { st: 状态文本节点, x: 移除按钮 }（DOM 全部 textContent 赋值，无注入面） */
      function makeChip(file, ico, thumb, st) {
        strip.hidden = false;
        var c = document.createElement('div');
        c.className = 'upx-chip is-wait';
        var head;
        if (thumb) {
          head = document.createElement('img');
          head.className = 'upx-thumb';
          head.src = thumb;
          head.alt = '';
        } else {
          head = document.createElement('span');
          head.className = 'upx-ico upx-ico-' + ico[1];
          head.textContent = ico[0];
        }
        var meta = document.createElement('span');
        meta.className = 'upx-meta';
        var nm = document.createElement('b');
        nm.className = 'upx-name';
        nm.textContent = file.name || '未命名文件';
        nm.title = nm.textContent;
        var sub = document.createElement('i');
        sub.className = 'upx-sub';
        sub.appendChild(document.createTextNode(fmtSize(file.size) + ' · '));
        var stEl = document.createElement('span');
        stEl.className = 'upx-st';
        stEl.textContent = st;
        sub.appendChild(stEl);
        meta.appendChild(nm);
        meta.appendChild(sub);
        var x = document.createElement('button');
        x.type = 'button';
        x.className = 'upx-x';
        x.setAttribute('aria-label', '移除');
        x.textContent = '×';
        c.appendChild(head); c.appendChild(meta); c.appendChild(x);
        list.appendChild(c);
        return { el: c, st: stEl, x: x };
      }
      /* 客户端预检：类型 / 大小即时判断（服务端校验仍是最终防线） */
      function preCheck(file) {
        var ext = extOf(file.name);
        if (!ext) return '无法识别扩展名，请重命名后再上传';
        if (DENY.indexOf(ext) >= 0) return '不支持的文件类型：.' + ext;
        var isImg = IMG_EXT.indexOf(ext) >= 0;
        if (!isImg && allow.indexOf(ext) < 0) return '不支持的附件类型：.' + ext + '（后台「基本设置」可调整白名单）';
        var max = isImg ? imgMax : fileMax;
        if (file.size > max) return '大小 ' + fmtSize(file.size) + ' 超过上限 ' + fmtSize(max);
        return '';
      }
      function upload(file) {
        if (!file) return;
        /* 剪贴板图片可能没有文件名（部分浏览器）——按 MIME 补一个 */
        if (!file.name && /^image\//.test(file.type || '') && window.File) {
          try { file = new File([file], 'image.' + (String(file.type).split('/')[1] || 'png'), { type: file.type }); } catch (e) { /* 老浏览器忽略 */ }
        }
        var ext = extOf(file.name);
        var isImg = IMG_EXT.indexOf(ext) >= 0;
        var blob = null;
        if (isImg && window.URL && URL.createObjectURL) { try { blob = URL.createObjectURL(file); } catch (e) { blob = null; } }
        var chip = makeChip(file, isImg && !blob ? ['图片', 'img'] : iconOf(ext), blob, '等待上传');
        var c = chip.el, stEl = chip.st;
        chip.x.addEventListener('click', function () {
          if (c.classList.contains('is-ok')) { okCount = Math.max(0, okCount - 1); updCap(); }
          dropChip(c, blob);
        });
        var err = preCheck(file);
        if (err) {
          c.classList.remove('is-wait'); c.classList.add('is-err');
          stEl.textContent = err;
          toast('上传失败：' + err);
          return;
        }
        var fd = new FormData();
        var csrfInput = form ? form.querySelector('input[name=csrf]') : null;
        fd.append('csrf', csrfInput ? csrfInput.value : '');
        fd.append('file', file, file.name || 'file');
        var xhr = new XMLHttpRequest();
        xhr.open('POST', apiUrl('a=upload'), true);
        xhr.setRequestHeader('X-Requested-With', 'fetch');
        c.classList.remove('is-wait'); c.classList.add('is-up');
        xhr.upload.onprogress = function (ev) {
          if (ev.lengthComputable) stEl.textContent = '上传中 ' + Math.round(ev.loaded / ev.total * 100) + '%';
        };
        function fail(m) {
          c.classList.remove('is-up'); c.classList.add('is-err');
          stEl.textContent = m;
          toast('上传失败：' + m);
        }
        xhr.onload = function () {
          var j = null;
          try { j = JSON.parse(xhr.responseText); } catch (e) { j = null; }
          if (j && j.ok && j.md) {
            c.classList.remove('is-up'); c.classList.add('is-ok');
            stEl.textContent = '已上传';
            insertMd(ta, j.md);
            okCount++; updCap();
          } else {
            fail(diag(xhr, j));
          }
        };
        xhr.onerror = function () { fail(diag(xhr, null)); };
        xhr.send(fd);
      }
      function handle(files) {
        Array.prototype.slice.call(files || []).forEach(upload);
      }

      btn.addEventListener('click', function () { input.click(); });
      input.addEventListener('change', function () {
        var files = Array.prototype.slice.call(input.files || []);
        input.value = '';
        handle(files);
      });
      /* v1.42.0 粘贴直传：剪贴板里有文件（截图 / 复制的文件）时拦截并上传；纯文本粘贴照常 */
      ta.addEventListener('paste', function (ev) {
        var dt = ev.clipboardData;
        if (!dt || !dt.items) return;
        var files = [];
        for (var i = 0; i < dt.items.length; i++) {
          var it = dt.items[i];
          if (it.kind === 'file') {
            var f = null;
            try { f = it.getAsFile(); } catch (e) { f = null; }
            if (f) files.push(f);
          }
        }
        if (!files.length) return;
        ev.preventDefault();
        handle(files);
      });
      /* v1.42.0 拖拽直传：拖到输入框 / 表单卡片上松手即上传 */
      var zone = form || ta;
      ['dragenter', 'dragover'].forEach(function (t) {
        zone.addEventListener(t, function (ev) {
          if (!ev.dataTransfer || Array.prototype.indexOf.call(ev.dataTransfer.types || [], 'Files') < 0) return;
          ev.preventDefault();
          zone.classList.add('is-drag');
        });
      });
      ['dragleave', 'dragend', 'drop'].forEach(function (t) {
        zone.addEventListener(t, function () { zone.classList.remove('is-drag'); });
      });
      zone.addEventListener('drop', function (ev) {
        var fs = ev.dataTransfer && ev.dataTransfer.files;
        if (!fs || !fs.length) return;
        ev.preventDefault();
        handle(fs);
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

  /* ================= v1.36.0 HTML 静态加速：全站预生成（后台监控页） ================= */
  (function () {
    var sbtn = $('#mf-sgen-btn');
    var slist = $('#mf-sgen-list');
    if (!sbtn || !slist) return;
    var out = $('#mf-sgen-out');
    function label(it) {
      if (it.p === 'thread') return '帖子 #' + it.id;
      if (it.p === 'board') return '版块 #' + it.id;
      return '首页';
    }
    sbtn.addEventListener('click', function () {
      var items;
      try { items = JSON.parse(slist.textContent || '[]'); } catch (e) { items = null; }
      if (!items || !items.length || sbtn.dataset.busy) return;
      sbtn.dataset.busy = '1';
      sbtn.disabled = true;
      var ok = 0, fail = 0, i = 0, total = items.length;
      function next() {
        if (i >= total) {
          sbtn.dataset.busy = '';
          sbtn.disabled = false;
          if (out) {
            out.textContent = '完成：成功 ' + ok + ' 页' + (fail ? '，失败 ' + fail + ' 页（可重试）' : '') + '。1 秒后刷新页面查看快照状态…';
            setTimeout(function () { location.reload(); }, 1200);
          }
          return;
        }
        var it = items[i++];
        if (out) out.textContent = '生成中 ' + i + '/' + total + '：' + label(it) + ' …';
        fetch(apiUrl('__sgen=1&p=' + it.p + (it.id ? '&id=' + it.id : '')), {
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'fetch', 'X-CSRF-Token': csrf() }
        }).then(function (r) { return r.json(); }).then(function (j) {
          if (j && j.ok) ok++; else fail++;
          next();
        }).catch(function () {
          fail++;
          next();
        });
      }
      next();
    });
  })();
  })();
