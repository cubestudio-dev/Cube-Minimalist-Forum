<?php
/**
 * 极简论坛 · 扫码登录（v1.34.0）
 *
 * 流程（全设备通用，二维码由服务端 SVG 渲染，扫码端零依赖）：
 *  1) 登录页「扫码登录」页签向 a=qr_create 申请一次性令牌（POST + CSRF），
 *     返回绝对链接后前端以 <img src="index.php?p=qrimg&t=…"> 展示二维码；
 *  2) 已登录的设备（手机 / 平板等）扫码打开 p=qr&t=<token> 确认页，
 *     核对发起方设备与 IP、本机登录身份后一键确认（POST + CSRF）；
 *  3) 等待登录的设备每 2 秒轮询 a=qr_check（GET + CSRF 头），令牌被确认后
 *     即在本会话建立登录（复用 auth_login：会话重生成 + 设备登记 + 站点统计）；
 *  4) 令牌一次性消费（confirmed → done），默认 180 秒过期（后台 60~600 可调），
 *     每 IP 活跃令牌上限 8 个防刷，过期文件在创建时机会式清理。
 *
 * 存储与性能（延续 v1.32 / v1.33 的极致优化基调）：
 *  - 每个令牌一个独立小文件（data/qr/<40位hex>.json），确认 / 消费是单文件原子操作，
 *    无全局锁、无读改写风暴；目录自身保持极小（TTL 短 + 即时清理）；
 *  - 二维码 SVG 按需生成：每次登录尝试仅一次，微秒级 CPU，<img> 直接展示全设备可扫。
 *
 * 安全设计：
 *  - 令牌 160bit CSPRNG；轮询接口要求 CSRF 头，防第三方页面把受害者浏览器
 *    悄悄变成攻击者的已登录会话（登录 CSRF）；
 *  - 确认页展示发起方设备名与 IP，扫码人确认的是「谁要在哪里登录」；
 *  - 确认后建立会话前再次校验账号状态（封禁 / 注销拒绝）；
 *  - 与密码登录共用设备登记（新设备提醒 / 并发设备上限 / 踢出下线）；
 *    扫码确认本身来自「已登录的可信设备」，故不再触发新设备邮箱二次验证。
 */
defined('APP') or exit('Forbidden');

const MF_QR_REL = 'qr';

/** 令牌目录（懒创建 + 目录列表双保险） */
function qr_dir(): string
{
    $d = DATA_DIR . '/' . MF_QR_REL;
    if (!is_dir($d)) {
        Store::ensureDir(MF_QR_REL);
        @file_put_contents($d . '/index.html', ''); /* .htaccess 之外的第二道防列表 */
    }
    return $d;
}

/** 扫码端要直达的绝对链接（必须拼完整 URL；兼容子目录部署与演示网关） */
function qr_abs_url(string $t): string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        return '';
    }
    $path = (string)preg_replace('/index\.php$/', '', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    if ($path === '' || $path[0] !== '/') {
        $path = '/';
    }
    $url = (app_is_https() ? 'https://' : 'http://') . $host . $path
        . 'index.php?p=qr&t=' . rawurlencode($t);
    $dp = demo_port();
    return $dp !== '' ? $url . '&XTransformPort=' . $dp : $url;
}

/** 读令牌文件；仅接受 40 位 hex，杜绝任何路径拼接风险 */
function qr_load(string $t): ?array
{
    if (!preg_match('/^[0-9a-f]{40}$/', $t)) {
        return null;
    }
    $raw = @file_get_contents(qr_dir() . '/' . $t . '.json');
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

/** 原子写令牌（临时文件 + 原子替换，单文件 LOCK_EX；无全局锁） */
function qr_save(string $t, array $j): void
{
    $f = qr_dir() . '/' . $t . '.json';
    $tmp = $f . '.' . (getmypid() ?: '0') . '.tmp';
    if (@file_put_contents($tmp, json_encode($j, JSON_UNESCAPED_UNICODE), LOCK_EX) !== false) {
        @rename($tmp, $f);
    }
    @unlink($tmp); /* rename 成功时不存在，失败时清理 */
}

/**
 * 创建令牌（a=qr_create）：一次性 + TTL 后台可调 + 容量保护；
 * 同一次创建顺带机会式清理过期 / 已消费残留（目录常态极小，成本可忽略）
 */
function qr_create(string $ip, string $ua): array
{
    $ttl = max(60, min(600, (int)cfg('qr_ttl', 180)));
    $d = qr_dir();
    $now = time();
    $active = 0;
    $mine = 0;
    $entries = @scandir($d);
    if (is_array($entries)) {
        foreach ($entries as $f) {
            if (strlen($f) !== 45 || substr($f, -5) !== '.json') {
                continue;
            }
            $tk = qr_load(substr($f, 0, 40));
            if (!$tk || ($tk['e'] ?? 0) < $now) {
                @unlink($d . '/' . $f); /* 过期即清（含确认 / 消费后残留的令牌） */
                continue;
            }
            if (($tk['s'] ?? '') === 'd' && $now - (int)($tk['c'] ?? 0) > 300) {
                @unlink($d . '/' . $f);
                continue;
            }
            $active++;
            if (($tk['ip'] ?? '') === $ip) {
                $mine++;
            }
        }
    }
    if ($active > 800 || $mine >= 8) {
        return []; /* 全局 / 单 IP 容量保护 */
    }
    $t = bin2hex(random_bytes(20));
    qr_save($t, ['s' => 'w', 'u' => 0, 't' => $now, 'e' => $now + $ttl, 'c' => 0, 'ip' => $ip, 'ua' => cut_str($ua, 120)]);
    return ['t' => $t, 'ttl' => $ttl, 'url' => qr_abs_url($t)];
}

/** 扫码端确认：pending → confirmed（记录确认人与时间）；返回是否成功 */
function qr_confirm(string $t, int $uid, string $uname): bool
{
    $tk = qr_load($t);
    if (!$tk || ($tk['s'] ?? '') !== 'w' || ($tk['e'] ?? 0) < time()) {
        return false;
    }
    $tk['s'] = 'c';
    $tk['u'] = $uid;
    $tk['c'] = time();
    qr_save($t, $tk);
    log_action('qr_confirm', '已确认待登录设备：' . dev_ua_name((string)($tk['ua'] ?? '')) . '（IP ' . (string)($tk['ip'] ?? '-') . '）', $uid, $uname);
    return true;
}

/**
 * 等待端消费：confirmed → done（一次性语义，文件锁内读改写，仅此一处）
 * 成功返回确认用户 uid；令牌缺失 / 非确认态 / 已过期返回 null
 */
function qr_consume(string $t): ?int
{
    $f = qr_dir() . '/' . $t . '.json';
    if (!is_file($f)) {
        return null;
    }
    $fp = @fopen($f, 'c+');
    if (!$fp) {
        return null;
    }
    $uid = null;
    if (@flock($fp, LOCK_EX)) {
        $raw = stream_get_contents($fp);
        $tk = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($tk) && ($tk['s'] ?? '') === 'c' && ($tk['e'] ?? 0) >= time()) {
            $uid = (int)($tk['u'] ?? 0);
            $tk['s'] = 'd';
            $tk['c'] = time();
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($tk, JSON_UNESCAPED_UNICODE));
            fflush($fp);
        }
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    return $uid > 0 ? $uid : null;
}

/* ---------------- 动作端点（index.php?a=…） ---------------- */

/** a=qr_create（POST + CSRF，游客可用）：功能关闭 / 超限给明确错误 */
function act_qr_create(): void
{
    if (!feat_on('qr_login')) {
        json_response(['ok' => false, 'msg' => t('扫码登录已关闭')]);
    }
    $j = qr_create(fw_ip(), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if (!$j) {
        json_response(['ok' => false, 'msg' => t('二维码创建过于频繁，请稍后再试')]);
    }
    json_response(['ok' => true, 't' => $j['t'], 'ttl' => $j['ttl'], 'url' => $j['url']]);
}

/**
 * a=qr_check（GET + CSRF 头）：等待端轮询。
 * 全站唯一一个「GET 但会建立本会话登录」的端点——因此必须核验 CSRF 头：
 * 令牌虽然不可猜，但若不校验来源页令牌，第三方页面可以让受害者浏览器
 * 悄悄消费一个已确认令牌（登录 CSRF），这里从根上拒绝。
 */
function act_qr_check(): void
{
    if (!feat_on('qr_login')) {
        json_response(['ok' => true, 's' => 'expired']);
    }
    if (!csrf_ok()) {
        json_response(['ok' => false, 'msg' => t('页面已过期，请刷新后重试')]);
    }
    $t = isset($_GET['t']) && is_string($_GET['t']) ? $_GET['t'] : '';
    $tk = qr_load($t);
    if (!$tk || ($tk['e'] ?? 0) < time()) {
        json_response(['ok' => true, 's' => 'expired']);
    }
    $s = (string)($tk['s'] ?? 'w');
    if ($s === 'w') {
        json_response(['ok' => true, 's' => 'wait', 'left' => max(0, (int)$tk['e'] - time())]);
    }
    if ($s === 'd') {
        /* 已被消费：稍晚到达的轮询保持幂等（等待端通常已在跳转中） */
        json_response(['ok' => true, 's' => 'ok', 'url' => u('p=home')]);
    }
    /* s === 'c'：扫码端已确认 → 一次性消费并为当前会话建立登录 */
    $uid = qr_consume($t);
    if (!$uid) {
        json_response(['ok' => true, 's' => 'expired']);
    }
    $u = user_by_id($uid);
    if (!$u || !empty($u['deleted']) || !empty($u['banned'])) {
        json_response(['ok' => true, 's' => 'expired']);
    }
    auth_login($u, false);
    dev_login_register((int)$u['id'], $u, fw_ip(), false);
    log_action('qr_login', '用户 ' . (string)$u['name'] . '（#' . (int)$u['id'] . '）扫码登录成功'
        . '（' . dev_ua_name((string)($_SERVER['HTTP_USER_AGENT'] ?? '')) . ' · IP ' . fw_ip() . '）', (int)$u['id'], (string)$u['name']);
    stats_bump('login'); /* v1.32.0 站点统计 */
    mf_do_action('user_login', (int)$u['id'], (string)$u['name']); /* v1.22.0 拓展钩子 */
    json_response(['ok' => true, 's' => 'ok', 'url' => u('p=home')]);
}

/** a=qr_confirm（POST + CSRF，需登录）：扫码端一键确认 */
function act_qr_confirm(): void
{
    $u = require_login();
    if (!feat_on('qr_login')) {
        flash('err', t('扫码登录已关闭'));
        redirect(u('p=home'));
    }
    $t = post_str('t', 64);
    $tk = qr_load($t);
    if (!$tk || ($tk['e'] ?? 0) < time() || ($tk['s'] ?? '') !== 'w') {
        flash('err', t('二维码无效或已过期，请回到待登录设备刷新后重试'));
        redirect(u('p=qr&t=' . rawurlencode($t)));
    }
    qr_confirm($t, (int)$u['id'], (string)$u['name']);
    flash('ok', t('已确认，请回到待登录设备查看'));
    redirect(u('p=qr&t=' . rawurlencode($t)));
}

/* ---------------- 二维码渲染（内置 MIT 编码器见 src/qrcodec.php） ---------------- */

/** 自动选版本生成二维码矩阵（容量不足返回 null；E_USER_ERROR 转异常捕获） */
function qr_make(string $text, int $ecl): ?object
{
    set_error_handler(static function (int $no, string $str): bool {
        if ($no === E_USER_ERROR || $no === E_ERROR) {
            throw new RuntimeException($str);
        }
        return false; /* 其余错误走 PHP 原生处理 */
    });
    try {
        for ($tn = 1; $tn <= 12; $tn++) {
            try {
                $qr = new MfQrCode();
                $qr->setTypeNumber($tn);
                $qr->setErrorCorrectLevel($ecl);
                $qr->addData($text);
                $qr->make();
                return $qr;
            } catch (RuntimeException $e) {
                /* 容量不足 → 下一版本号 */
            }
        }
    } finally {
        restore_error_handler();
    }
    return null;
}

/** 生成二维码 SVG（M 级纠错；静态区 2 模块；白底深色码保证可扫性） */
function qr_svg(string $text): string
{
    $qr = qr_make($text, MFQR_ERROR_CORRECT_LEVEL_M);
    if (!$qr) {
        return '';
    }
    $n = (int)$qr->getModuleCount();
    $qz = 2;
    $dim = $n + $qz * 2;
    $path = '';
    for ($r = 0; $r < $n; $r++) {
        for ($c = 0; $c < $n; $c++) {
            if ($qr->isDark($r, $c)) {
                $path .= 'M' . ($c + $qz) . ' ' . ($r + $qz) . 'h1v1h-1z';
            }
        }
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . ($dim * 4) . '" height="' . ($dim * 4)
        . '" viewBox="0 0 ' . $dim . ' ' . $dim . '" shape-rendering="crispEdges" role="img" aria-label="QR">'
        . '<rect width="' . $dim . '" height="' . $dim . '" fill="#ffffff"/>'
        . '<path d="' . $path . '" fill="#18181b"/></svg>';
}
