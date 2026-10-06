<?php
/**
 * 极简论坛 · 应用引导
 * 环境常量 / 会话 / 配置加载 / 类库装载
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

defined('APP') or define('APP', 1);

if (!defined('DATA_DIR')) {
    define('DATA_DIR', dirname(__DIR__) . '/data');
}
if (!defined('MF_VERSION')) {
    define('MF_VERSION', '1.15.0');
}
if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0755, true);
}
/* 运行错误日志移入 data/logs/（与 .htaccess 保护范围一致，避免被 Web 直接读取泄露路径） */
if (!is_dir(DATA_DIR . '/logs')) {
    @mkdir(DATA_DIR . '/logs', 0775, true);
}
ini_set('error_log', DATA_DIR . '/logs/error.log');
date_default_timezone_set('Asia/Shanghai');

/* 类库装载（顺序敏感） */
require_once __DIR__ . '/util.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/markdown.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/charts.php';
require_once __DIR__ . '/logs.php';
require_once __DIR__ . '/firewall.php';
require_once __DIR__ . '/update.php';
require_once __DIR__ . '/sysmon.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/content.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/actions.php';
require_once __DIR__ . '/pages.php';
require_once __DIR__ . '/admin.php';

/* 会话：保存路径置于 data/sessions（自包含、便于隔离与备份排除）；
   用 ensureDir 走自愈阶梯（建目录/修权限/整体搬移重建），属主异常时也能自动救回 */
$sdir = DATA_DIR . '/sessions';
if (!is_dir($sdir)) {
    Store::ensureDir('sessions');
}
if (is_dir($sdir) && is_writable($sdir)) {
    session_save_path($sdir);
}
session_name('MFSESS');
/* HTTPS 环境自动给会话 Cookie 加上 Secure（防降级窃听）；HTTP 站点保持兼容 */
$mfHttps = app_is_https();
/* 会话文件服务端存活期提为 30 天：PHP 默认 gc_maxlifetime=1440 秒（24 分钟），
   勾选「保持登录」的用户在宿主机 GC 运行后会话文件就被误删，导致凭空掉线。
   （未勾选保持登录的用户不受影响：会话 Cookie 本身仍是浏览器关闭即失效） */
@ini_set('session.gc_maxlifetime', (string)(30 * 86400));
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $mfHttps]);
@session_start();
unset($mfHttps);

/* 配置 */
$GLOBALS['CFG'] = Store::read('config.php', []);
if (!is_array($GLOBALS['CFG'])) {
    $GLOBALS['CFG'] = [];
}

/* 绑定域名守卫：后台配置了授权域名时，其他域名（恶意解析 / 镜像站 / IP 直连）一律 301 跳转到授权域名 */
bind_domain_guard();

/* 防火墙入口：安全响应头 → 白名单 → 封禁名单 → 危险 IP 库 → 限流 → 自动策略（详见 src/firewall.php） */
fw_guard();

/* 环境自检：数据目录不可写时置全局警告（页面顶部对管理员可见，写入类操作会给出明确错误） */
if (!Store::writable()) {
    $GLOBALS['ENV_WARN'] = 'data/ 目录不可写：发帖、注册、设置保存等写入类功能将无法使用。'
        . '请到后台「监控 → 环境自检」点「一键修复目录权限」尝试自动修复；若无效，请通过 FTP 将 data/ 目录（含子目录）权限设为 755 或 775（Windows 主机请给 IIS 用户授权）。';
}

/** 当前请求是否为 HTTPS（含反代透传场景；auth.php 会话续期也用它保持 Secure 一致性） */
function app_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443'
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/**
 * 功能总开关（v1.14.0）：后台「功能」页可勾选启停
 * 默认全开 —— 旧站升级零迁移、新装零配置；关闭的开关前后台双端拦截
 */
function feat_on(string $k, bool $def = true): bool
{
    return (int)cfg('feat_' . $k, $def ? 1 : 0) === 1;
}

/** 读取配置：cfg('site_name', 默认) 或 cfg() 取全部 */
function cfg(string $k = '', $def = null)
{
    $c = $GLOBALS['CFG'] ?? [];
    if ($k === '') {
        return $c;
    }
    return $c[$k] ?? $def;
}

/** 更新配置（加锁 + 原子写） */
function cfg_update(array $kv): void
{
    $lk = Store::lock('config');
    $c = Store::read('config.php', []);
    if (!is_array($c)) {
        $c = [];
    }
    foreach ($kv as $k => $v) {
        $c[$k] = $v;
    }
    Store::write('config.php', $c);
    $GLOBALS['CFG'] = $c;
    Store::unlock($lk);
}

/**
 * 绑定域名（授权域名）守卫
 * - 后台「基本设置 → 绑定域名」留空时不做任何限制（默认，兼容一切环境）
 * - 配置后：仅允许列表内域名访问；其他域名（他人恶意解析、镜像站、IP 直连）一律 301 永久
 *   跳转到第一个授权域名，原路径原样保留（搜索引擎权重自动归拢到授权域名）
 * - 命令行（php -l、计划任务脚本）与无 HTTP_HOST 的环境自动跳过
 * - 自救：万一把域名写错导致访问异常，通过 FTP 打开 data/config.php 删掉
 *   "bind_domains" 一行（或清空其值）即可恢复
 */
function bind_domain_guard(): void
{
    $raw = trim((string)cfg('bind_domains', ''));
    if ($raw === '' || PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) {
        return;
    }
    $allow = bind_domain_list($raw);
    if (!$allow) {
        return;
    }
    $cur = strtolower((string)parse_url('http://' . (string)$_SERVER['HTTP_HOST'], PHP_URL_HOST));
    $cur = rtrim($cur, '.'); // 兼容 FQDN 尾点写法（FORUM.COM. 与 forum.com 视为同一域名）
    if ($cur === '' || in_array($cur, $allow, true)) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443'
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    if ($uri === '' || $uri[0] !== '/') {
        $uri = '/'; // 防御性：Location 值必须是本站路径
    }
    header('Location: ' . ($https ? 'https' : 'http') . '://' . $allow[0] . $uri, true, 301);
    exit;
}

/** 解析授权域名配置：支持逗号 / 中文逗号 / 分号 / 空白分隔，逐个去协议头、去端口与路径、转小写、去重（旧版接口，供外部调用） */
function bind_domain_list(string $raw): array
{
    $out = [];
    $parts = preg_split('/[\s,，;；]+/u', trim($raw)) ?: [];
    foreach ($parts as $d) {
        $d = strtolower(trim((string)$d));
        if ($d === '') {
            continue;
        }
        $d = preg_replace('#^https?://#i', '', $d) ?? $d; // 容错：去掉误填的协议头
        $d = trim($d, '/');                               // 容错：去掉误填的尾斜杠
        $d = (string)preg_replace('/[\/?#:].*$/', '', $d); // 容错：去掉误填的端口与路径
        $d = rtrim($d, '.');                              // 容错：去掉 FQDN 尾点（FORUM.COM. 与 forum.com 统一）
        if ($d !== '' && preg_match('/^[a-z0-9.\-]+$/', $d)) {
            $out[] = $d;
        }
    }
    return array_values(array_unique($out));
}
