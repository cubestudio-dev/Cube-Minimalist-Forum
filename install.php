<?php
/**
 * 极简论坛 · 安装向导（6 步：环境检测 → 站点信息 → 邮件 → AI → 管理员 → 完成）
 * 已安装时访问本文件将被拒绝；安装完成后建议删除本文件
 */
define('APP', 1);
require __DIR__ . '/src/bootstrap.php';

/* ---------- 安装锁检查 ---------- */
if (Store::exists('lock/install.lock')) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>已安装</title>' .
        '<link rel="stylesheet" href="' . e(ua('assets/style.css?v=' . MF_VERSION)) . '"></head>' .
        '<body><div class="auth-wrap"><div class="card auth-card"><h1 class="page-title">论坛已安装</h1>' .
        '<p class="muted">检测到安装锁（data/lock/install.lock）。<br>如需重新安装，请先在服务器上删除该锁文件，<br>并确认这是您真正想做的事情（会覆盖全部数据）。</p>' .
        '<a class="btn btn-primary" href="' . e(u('p=home')) . '">进入论坛</a></div></div></body></html>';
    exit;
}

Store::ensureDir('lock');
Store::ensureDir('locks');

/* ---------- AJAX 测试（邮件 / AI） ---------- */
if (isset($_GET['a']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string)$_GET['a'];
    if (!csrf_ok()) {
        json_response(['ok' => false, 'msg' => '页面已过期，请刷新重试']);
    }
    if ($a === 'test_mail') {
        $ov = [
            'host' => post_str('smtp_host', 100),
            'port' => max(1, min(65535, (int)($_POST['smtp_port'] ?? 465))),
            'from' => post_str('smtp_from', 60),
            'pass' => post_str('smtp_pass', 100),
        ];
        $to = post_str('to', 60);
        if (!valid_email($to)) {
            json_response(['ok' => false, 'msg' => '请填写有效的收件邮箱']);
        }
        if ($ov['host'] === '' || $ov['from'] === '') {
            json_response(['ok' => false, 'msg' => '请先填写 SMTP 主机与发信邮箱']);
        }
        [$ok, $err] = mail_send($to, 'Cube Minimalist Forum · SMTP 测试邮件', "这是一封安装向导发出的测试邮件。\r\n收到它说明邮件配置正确。\r\n\r\n—— Cube Minimalist Forum", $ov);
        json_response($ok ? ['ok' => true, 'msg' => '测试邮件发送成功，请查收'] : ['ok' => false, 'msg' => $err]);
    }
    if ($a === 'test_ai') {
        $m = [
            'name' => '待保存模型',
            'url' => post_str('ai_url', 200),
            'key' => post_str('ai_key', 200),
            'model' => post_str('ai_model', 100),
        ];
        $msg = '';
        $ok = ai_test_model($m, $msg, ['retries' => 1]);
        json_response(['ok' => $ok, 'msg' => $msg]);
    }
    json_response(['ok' => false, 'msg' => '未知操作']);
}

/* ---------- 会话中的安装进度 ---------- */
if (!isset($_SESSION['inst']) || !is_array($_SESSION['inst'])) {
    $_SESSION['inst'] = [];
}
$inst = &$_SESSION['inst'];

$step = max(1, min(6, (int)($_GET['step'] ?? 1)));
$errors = [];

/* ---------- 各步提交处理 ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step'])) {
    if (!csrf_ok()) {
        $errors[] = '页面已过期，请刷新重试';
    } else {
        $ps = (int)$_POST['step'];
        if ($ps === 2) {
            $name = post_str('site_name', 30);
            $desc = post_str('site_desc', 100);
            $color = post_str('theme_color_custom', 9);
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                $color = post_str('theme_color', 9);
            }
            if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) {
                $color = '#0f766e';
            }
            if ($name === '') {
                $errors[] = '论坛名称不能为空';
            }
            if ($desc === '') {
                $errors[] = '论坛简介不能为空';
            }
            if (!$errors) {
                $inst['site'] = [
                    'site_name' => $name,
                    'site_desc' => $desc,
                    'theme_color' => $color,
                    'site_url' => cut_str(post_str('site_url', 200), 200),
                    'per_page' => max(5, min(100, (int)($_POST['per_page'] ?? 20))),
                    'post_interval' => max(0, min(3600, (int)($_POST['post_interval'] ?? 30))),
                    'online_window' => max(60, min(86400, (int)($_POST['online_window'] ?? 300))),
                    'live_interval' => max(0, min(300, (int)($_POST['live_interval'] ?? 20))),
                ];
                redirect(ua('install.php?step=3'));
            }
        } elseif ($ps === 3) {
            $inst['smtp'] = [
                'smtp_host' => cut_str(post_str('smtp_host', 100), 100) ?: 'smtp.qq.com',
                'smtp_port' => max(1, min(65535, (int)($_POST['smtp_port'] ?? 465))),
                'smtp_from' => cut_str(post_str('smtp_from', 60), 60),
                'smtp_pass' => cut_str(post_str('smtp_pass', 100), 100),
            ];
            if (isset($_POST['skip'])) {
                $inst['smtp_skip'] = 1;
            }
            redirect(ua('install.php?step=4'));
        } elseif ($ps === 4) {
            $inst['ai'] = [
                'ai_url' => cut_str(post_str('ai_url', 200), 200),
                'ai_key' => cut_str(post_str('ai_key', 200), 200),
                'ai_model' => cut_str(post_str('ai_model', 100), 100),
                'ai_retries' => max(1, min(10, (int)($_POST['ai_retries'] ?? 3))),
            ];
            if (isset($_POST['skip'])) {
                $inst['ai_skip'] = 1;
            }
            redirect(ua('install.php?step=5'));
        } elseif ($ps === 5) {
            $name = post_str('name', 20);
            $email = strtolower(post_str('email', 60));
            $pass = (string)($_POST['pass'] ?? '');
            $pass2 = (string)($_POST['pass2'] ?? '');
            if (!valid_name($name)) {
                $errors[] = '管理员用户名需 2-20 位（中文、字母、数字、下划线）';
            }
            if (!valid_email($email)) {
                $errors[] = '管理员邮箱格式不正确';
            }
            if (u_strlen($pass) < 6 || u_strlen($pass) > 60) {
                $errors[] = '密码长度需 6-60 位';
            }
            if ($pass !== $pass2) {
                $errors[] = '两次输入的密码不一致';
            }
            if (!$errors) {
                $inst['admin'] = ['name' => $name, 'email' => $email, 'pass' => password_hash($pass, PASSWORD_DEFAULT)];
                redirect(ua('install.php?step=6'));
            }
        }
    }
}

/* ---------- 完成：写入全部数据 ---------- */
$warnings = [];
if ($step === 6 && empty($inst['done'])) {
    $site = $inst['site'] ?? null;
    $admin = $inst['admin'] ?? null;
    if (!$site || !$admin) {
        redirect(ua('install.php?step=1'));
    }
    $smtp = $inst['smtp'] ?? [];
    $ai = $inst['ai'] ?? [];
    cfg_update(array_merge([
        'version' => MF_VERSION,
        'log_days' => 90,
        'log_views' => 0,
        'dark_default' => 'system',
        'custom_css' => '',
        'footer_text' => '',
        'footer_note' => '',
        'monitor_on' => 1,
        'monitor_mb' => 95,
        'monitor_interval' => 5,
        'quota_mb' => 100,
    ], $site, $smtp, $ai));

    user_create((string)$admin['name'], (string)$admin['email'], (string)$admin['pass'], true);
    $aid = 1;
    foreach (user_all() as $x) {
        $aid = max($aid, (int)($x['id'] ?? 0));
    }
    Store::write('boards.php', [
        ['id' => 1, 'name' => '综合讨论', 'desc' => '站务公告与日常交流', 'sort' => 0],
        ['id' => 2, 'name' => '技术分享', 'desc' => '开发、工具与效率', 'sort' => 1],
        ['id' => 3, 'name' => '灌水区', 'desc' => '轻松一下', 'sort' => 2],
    ]);
    Store::write('threads/index.php', []);
    Store::write('reports.php', []);
    Store::write('queue.php', []);
    Store::write('announcements.php', []);
    Store::write('codes.php', []);
    Store::write('online.php', []);
    Store::ensureDir('notify');
    Store::ensureDir('threads');
    Store::ensureDir('replies');
    Store::ensureDir('logs');
    Store::ensureDir('sessions');
    Store::ensureDir('backup');
    /* 关键目录逐个真实写探针：不可写时给安装者明确警告（发帖等功能将不可用） */
    foreach (['.' => 'data/', 'threads' => 'data/threads/', 'replies' => 'data/replies/', 'logs' => 'data/logs/', 'locks' => 'data/locks/', 'sessions' => 'data/sessions/'] as $d => $lbl) {
        if (!Store::dirWritable($d)) {
            $warnings[] = $lbl . ' 目录不可写：发帖等功能将无法使用。可安装后在后台「监控 → 环境自检」一键修复，或通过 FTP 将权限设为 755 / 775。';
        }
    }
    /* 数据目录 Web 访问保护（Apache）：交付包未含 data/ 时在安装时自动补齐 */
    if (!is_file(DATA_DIR . '/.htaccess')) {
        @file_put_contents(DATA_DIR . '/.htaccess', "# 数据目录禁止一切 Web 访问（Apache）\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
    @file_put_contents(Store::path('lock/install.lock'), 'installed at ' . date('c'));
    log_action('install', 'Cube Minimalist Forum v' . MF_VERSION . ' 安装完成，管理员：' . (string)$admin['name'] . '（#' . $aid . '）', (int)$aid, (string)$admin['name']);
    unset($inst['site'], $inst['smtp'], $inst['ai'], $inst['admin'], $inst['smtp_skip'], $inst['ai_skip']);
    $inst['done'] = 1;

    if (!empty($smtp['smtp_from']) === false) {
        $warnings[] = '邮件配置不完整：注册验证码等功能在补全 SMTP 前不可用，可稍后在「后台 → 邮件」完成。';
    }
    if (($ai['ai_url'] ?? '') === '') {
        $warnings[] = 'AI 配置已跳过：举报内容的自动审核在配置前不会执行，可稍后在「后台 → AI」完成。';
    }
    $inst['warnings'] = $warnings;
} elseif ($step === 6) {
    $warnings = $inst['warnings'] ?? [];
}

/* ---------- 第 1 步：环境检测 ---------- */
function inst_check(string $label, bool $ok, string $note = ''): string
{
    return '<div class="check-row"><span class="check-ico">' . ($ok ? '✔' : '✘') . '</span><div><b>' . e($label) . '</b>' .
        ($note !== '' ? '<p class="muted">' . e($note) . '</p>' : '') . '</div><span class="badge ' . ($ok ? 'badge-ok' : 'badge-danger') . '">' . ($ok ? '通过' : '不通过') . '</span></div>';
}

$checks = [];
$phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
$checks[] = inst_check('PHP 版本 ≥ 7.4', $phpOk, '当前版本 ' . PHP_VERSION . '，推荐 8.0+');

$wOk = false;
try {
    $wOk = is_dir(DATA_DIR) && is_writable(DATA_DIR) && @file_put_contents(Store::path('.wtest'), '1') !== false;
    if ($wOk) {
        @unlink(Store::path('.wtest'));
    }
} catch (Throwable $t) {
    $wOk = false;
}
$checks[] = inst_check('数据目录可写', $wOk, DATA_DIR . ' —— 请通过 FTP 将该目录权限设为可写（如 755）');

$sOk = false;
try {
    $_SESSION['inst_test'] = '1';
    $sOk = ($_SESSION['inst_test'] ?? '') === '1';
    unset($_SESSION['inst_test']);
} catch (Throwable $t) {
    $sOk = false;
}
$checks[] = inst_check('会话功能可用', $sOk, '用于登录态与安装进度');

$lOk = false;
try {
    $fp = @fopen(Store::path('locks/.itest.lock'), 'c');
    if ($fp) {
        $lOk = @flock($fp, LOCK_EX | LOCK_NB);
        @flock($fp, LOCK_UN);
        fclose($fp);
        @unlink(Store::path('locks/.itest.lock'));
    }
} catch (Throwable $t) {
    $lOk = false;
}
$checks[] = inst_check('文件锁可用', $lOk, '保证并发写入安全（flock）');

$mOk = function_exists('fsockopen');
$checks[] = inst_check('邮件发送能力', $mOk, $mOk ? '检测到 socket 能力，可在下一步实测发信' : '缺少 fsockopen，无法使用 SMTP 发信');

$jOk = true;
try {
    $jOk = json_decode(json_encode(['t' => '中', 'n' => 1], JSON_UNESCAPED_UNICODE), true) === ['t' => '中', 'n' => 1];
} catch (Throwable $t) {
    $jOk = false;
}
$checks[] = inst_check('JSON 读写正常', $jOk, '全部数据以 JSON 文件存储');

$allPass = $phpOk && $wOk && $sOk && $lOk && $jOk; // 邮件能力允许缺失（后续可跳过）
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<title>安装向导 · Cube Minimalist Forum</title>
<link rel="stylesheet" href="<?= e(ua('assets/style.css?v=' . MF_VERSION)) ?>">
<script>window.THEME_DEFAULT="system";window.DEMO_PORT=<?= json_encode(demo_port()) ?>;</script>
<script>(function(){try{var d=localStorage.getItem('mf-theme')||'system';if(d==='system'){d=window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}document.documentElement.setAttribute('data-theme',d);}catch(e){}})();</script>
</head>
<body>
<div class="shell">
  <header class="topbar"><div class="topbar-in">
    <a class="brand" href="<?= e(ua('install.php')) ?>"><span class="logo"></span><b>Cube Minimalist Forum · 安装向导</b></a>
    <div class="flex1"></div><button class="icon-btn" id="themeToggle" type="button" aria-label="切换深色模式">◐</button>
  </div></header>
  <main class="main main-narrow">
    <div class="stepper">
      <?php foreach (['环境检测', '站点信息', '邮件配置', 'AI 配置', '创建管理员', '完成'] as $i => $sname): ?>
        <span class="step<?= $step === $i + 1 ? ' on' : ($step > $i + 1 ? ' done' : '') ?>"><i><?= $step > $i + 1 ? '✔' : $i + 1 ?></i><?= e($sname) ?></span>
      <?php endforeach; ?>
    </div>
<?php foreach ($errors as $er): ?>
    <div class="flash flash-err"><?= e($er) ?></div>
<?php endforeach; ?>

<?php if ($step === 1): ?>
    <div class="card form-card">
      <h1 class="card-title">环境检测</h1>
      <?php foreach ($checks as $c) {
          echo $c;
      } ?>
      <div class="form-foot">
        <span class="muted"><?= $allPass ? '环境全部就绪（邮件能力可在下一步实测）' : '存在不通过项，请处理后刷新本页' ?></span>
        <?php if ($allPass): ?>
          <a class="btn btn-primary" href="<?= e(ua('install.php?step=2')) ?>">下一步：站点信息</a>
        <?php else: ?>
          <a class="btn btn-ghost" href="<?= e(ua('install.php?step=1')) ?>">重新检测</a>
        <?php endif; ?>
      </div>
    </div>

<?php elseif ($step === 2): ?>
    <div class="card form-card">
      <h1 class="card-title">站点信息</h1>
      <form method="post" action="<?= e(ua('install.php?step=2')) ?>">
        <?= csrf_field() ?><input type="hidden" name="step" value="2">
        <label class="field"><span class="field-l">论坛名称 *</span><input class="input" name="site_name" required maxlength="30" value="<?= e((string)($inst['site']['site_name'] ?? '')) ?>" placeholder="如：周末茶话会"></label>
        <label class="field"><span class="field-l">论坛简介 *</span><input class="input" name="site_desc" required maxlength="100" value="<?= e((string)($inst['site']['site_desc'] ?? '')) ?>" placeholder="一句话介绍这个论坛"></label>
        <div class="field"><span class="field-l">主题色</span>
          <div class="palette">
            <?php foreach (['#0f766e', '#047857', '#b45309', '#be123c', '#57534e', '#1c1917'] as $pc): ?>
              <label class="swatch" style="background:<?= e($pc) ?>"><input type="radio" name="theme_color" value="<?= e($pc) ?>" <?= (($inst['site']['theme_color'] ?? '') === $pc) ? 'checked' : '' ?> aria-label="<?= e($pc) ?>"></label>
            <?php endforeach; ?>
          </div>
          <input class="input" type="color" name="theme_color_custom" value="#0f766e" style="max-width:120px">
          <span class="hint">选择色板或自定义，安装后可在后台随时修改</span>
        </div>
        <label class="field"><span class="field-l">站点地址（可选，留空自动识别）</span><input class="input" name="site_url" type="url" value="<?= e((string)($inst['site']['site_url'] ?? '')) ?>" placeholder="https://<?= e($_SERVER['HTTP_HOST'] ?? '') ?>/"></label>
        <div class="grid3">
          <label class="field"><span class="field-l">每页帖子数</span><input class="input" name="per_page" type="number" min="5" max="100" value="<?= (int)($inst['site']['per_page'] ?? 20) ?>"></label>
          <label class="field"><span class="field-l">发帖间隔（秒）</span><input class="input" name="post_interval" type="number" min="0" max="3600" value="<?= (int)($inst['site']['post_interval'] ?? 30) ?>"></label>
          <label class="field"><span class="field-l">在线统计窗口（秒）</span><input class="input" name="online_window" type="number" min="60" max="86400" value="<?= (int)($inst['site']['online_window'] ?? 300) ?>"></label>
          <label class="field"><span class="field-l">实时刷新间隔（秒）</span><input class="input" name="live_interval" type="number" min="0" max="300" value="<?= (int)($inst['site']['live_interval'] ?? 20) ?>"><span class="hint">前台自动刷新在线人数与新帖提示，0 为关闭</span></label>
        </div>
        <div class="form-foot"><a class="btn btn-ghost" href="<?= e(ua('install.php?step=1')) ?>">上一步</a><button class="btn btn-primary" type="submit">下一步：邮件配置</button></div>
      </form>
    </div>

<?php elseif ($step === 3): ?>
    <div class="card form-card">
      <h1 class="card-title">邮件配置（SMTP）</h1>
      <p class="hint">用于注册 / 找回密码的邮箱验证码。以 QQ 邮箱为例：主机 smtp.qq.com、端口 465、授权码在「设置 → 账户 → 开启 SMTP」后生成。</p>
      <form method="post" action="<?= e(ua('install.php?step=3')) ?>">
        <?= csrf_field() ?><input type="hidden" name="step" value="3">
        <div class="grid2">
          <label class="field"><span class="field-l">SMTP 主机</span><input class="input" name="smtp_host" value="<?= e((string)($inst['smtp']['smtp_host'] ?? 'smtp.qq.com')) ?>"></label>
          <label class="field"><span class="field-l">SMTP 端口</span><input class="input" name="smtp_port" type="number" min="1" max="65535" value="<?= (int)($inst['smtp']['smtp_port'] ?? 465) ?>"></label>
        </div>
        <label class="field"><span class="field-l">发信邮箱</span><input class="input" type="email" name="smtp_from" value="<?= e((string)($inst['smtp']['smtp_from'] ?? '')) ?>" placeholder="用于发信的邮箱地址"></label>
        <label class="field"><span class="field-l">授权码</span><input class="input" name="smtp_pass" value="<?= e((string)($inst['smtp']['smtp_pass'] ?? '')) ?>"></label>
        <div class="field"><span class="field-l">测试发信</span>
          <div class="code-row"><input class="input" type="email" name="to" id="mail-test-to" placeholder="收件邮箱，当场发一封测试邮件">
          <button class="btn btn-ghost" type="button" data-inst-test="mail">发送测试邮件</button></div>
          <span class="test-msg muted"></span>
        </div>
        <div class="form-foot">
          <a class="btn btn-ghost" href="<?= e(ua('install.php?step=2')) ?>">上一步</a>
          <span class="form-foot-btns">
            <button class="btn btn-ghost" type="submit" name="skip" value="1" onclick="return confirm('跳过后，注册 / 找回密码的验证码将无法发送（可稍后在后台补全配置）。确定跳过？')">跳过（有警告）</button>
            <button class="btn btn-primary" type="submit">下一步：AI 配置</button>
          </span>
        </div>
      </form>
    </div>

<?php elseif ($step === 4): ?>
    <div class="card form-card">
      <h1 class="card-title">AI 配置（内容审核）</h1>
      <p class="hint">用于举报内容的自动审核，任何 OpenAI 兼容接口均可。API 地址可填根地址、/v1 或完整 /chat/completions。</p>
      <form method="post" action="<?= e(ua('install.php?step=4')) ?>">
        <?= csrf_field() ?><input type="hidden" name="step" value="4">
        <label class="field"><span class="field-l">API 地址</span><input class="input" name="ai_url" value="<?= e((string)($inst['ai']['ai_url'] ?? '')) ?>" placeholder="https://api.openai.com"></label>
        <label class="field"><span class="field-l">API 密钥</span><input class="input" name="ai_key" value="<?= e((string)($inst['ai']['ai_key'] ?? '')) ?>"></label>
        <div class="grid2">
          <label class="field"><span class="field-l">模型名称</span><input class="input" name="ai_model" value="<?= e((string)($inst['ai']['ai_model'] ?? '')) ?>" placeholder="如 gpt-4o-mini"></label>
          <label class="field"><span class="field-l">失败重试次数</span><input class="input" name="ai_retries" type="number" min="1" max="10" value="<?= (int)($inst['ai']['ai_retries'] ?? 3) ?>"></label>
        </div>
        <div class="field"><span class="field-l">测试调用</span>
          <div class="code-row"><input class="input" value="今天天气不错，适合写代码。" readonly>
          <button class="btn btn-ghost" type="button" data-inst-test="ai">发送测试</button></div>
          <span class="test-msg muted"></span>
        </div>
        <div class="form-foot">
          <a class="btn btn-ghost" href="<?= e(ua('install.php?step=3')) ?>">上一步</a>
          <span class="form-foot-btns">
            <button class="btn btn-ghost" type="submit" name="skip" value="1" onclick="return confirm('跳过后，举报内容将不会被自动审核（可稍后在后台补全配置）。确定跳过？')">跳过（有警告）</button>
            <button class="btn btn-primary" type="submit">下一步：创建管理员</button>
          </span>
        </div>
      </form>
    </div>

<?php elseif ($step === 5): ?>
    <div class="card form-card">
      <h1 class="card-title">创建管理员</h1>
      <form method="post" action="<?= e(ua('install.php?step=5')) ?>">
        <?= csrf_field() ?><input type="hidden" name="step" value="5">
        <label class="field"><span class="field-l">管理员用户名</span><input class="input" name="name" required maxlength="20" value="<?= e((string)($_POST['name'] ?? '')) ?>" placeholder="2-20 位"></label>
        <label class="field"><span class="field-l">管理员邮箱</span><input class="input" type="email" name="email" required maxlength="60" value="<?= e((string)($_POST['email'] ?? '')) ?>"></label>
        <label class="field"><span class="field-l">密码</span><input class="input" type="password" name="pass" required minlength="6" maxlength="60" autocomplete="new-password"></label>
        <label class="field"><span class="field-l">确认密码</span><input class="input" type="password" name="pass2" required minlength="6" maxlength="60" autocomplete="new-password"></label>
        <div class="form-foot"><a class="btn btn-ghost" href="<?= e(ua('install.php?step=4')) ?>">上一步</a><button class="btn btn-primary" type="submit">完成安装</button></div>
      </form>
    </div>

<?php else: ?>
    <div class="card form-card done-card">
      <div class="done-ico">🎉</div>
      <h1 class="card-title">安装完成！</h1>
      <p>已写入配置文件、初始化数据文件并生成安装锁（data/lock/install.lock）。</p>
      <?php foreach ($warnings as $w): ?>
        <div class="flash flash-warn"><?= e($w) ?></div>
      <?php endforeach; ?>
      <div class="flash flash-warn">安全提醒：请立即通过 FTP <b>删除 install.php</b>，避免被他人重新安装。</div>
      <div class="form-foot"><span></span><a class="btn btn-primary" href="<?= e(u('p=home')) ?>">进入论坛</a></div>
    </div>
<?php endif; ?>
  </main>
  <footer class="footer"><span>Cube Minimalist Forum · 安装向导</span><span class="muted">纯 PHP · 文件存储 · 无数据库</span></footer>
</div>
<script src="<?= e(ua('assets/app.js?v=' . MF_VERSION)) ?>" defer></script>
</body>
</html>
