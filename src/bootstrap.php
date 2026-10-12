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
    define('MF_VERSION', '1.31.0');
}
if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0755, true);
}
/* v1.21.0 安全加固（幂等）：data/ 目录自带 Apache 拒绝一切 Web 访问规则；
   nginx 用户按 README 拦截 /data/；数据文件本身另有 PHP 守卫前缀双保险 */
$mfHt = DATA_DIR . '/.htaccess';
if (!is_file($mfHt)) {
    @file_put_contents($mfHt, "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
}
unset($mfHt);
/* 运行错误日志移入 data/logs/（与 .htaccess 保护范围一致，避免被 Web 直接读取泄露路径） */
if (!is_dir(DATA_DIR . '/logs')) {
    @mkdir(DATA_DIR . '/logs', 0775, true);
}
ini_set('error_log', DATA_DIR . '/logs/error.log');
date_default_timezone_set('Asia/Shanghai');

/* 类库装载（顺序敏感） */
require_once __DIR__ . '/util.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/dbstore.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/markdown.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/charts.php';
require_once __DIR__ . '/logs.php';
require_once __DIR__ . '/firewall.php';
require_once __DIR__ . '/update.php';
require_once __DIR__ . '/sysmon.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/devices.php';
require_once __DIR__ . '/content.php';
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/actions.php';
require_once __DIR__ . '/pages.php';
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/plugins.php';

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
/* v1.23.0 行云流水：接管会话缓存头。PHP 的 session_start 默认自动发
   "Cache-Control: no-store"——这是浏览器往返缓存（bfcache）的头号禁用原因，
   直接后果是「后退一页也要重新白屏等待整整一个往返」；高延迟服务器上尤其难受。
   改由布局层手动发 Cache-Control: private, no-cache：
   · private —— 禁止共享缓存误存个性化页面
   · no-cache —— 允许缓存但每次重验（无验证器时等效完整拉取，内容不陈旧）
   · 允许 bfcache —— 后退 / 前进瞬时完成，成为高延迟下最平滑的导航通道
   JSON 端点由 json_response() 统一发 no-store；静态资源沿用各自的强缓存头 */
@session_cache_limiter('');
@session_start();
unset($mfHttps);

/* 配置 */
$GLOBALS['CFG'] = Store::read('config.php', []);
if (!is_array($GLOBALS['CFG'])) {
    $GLOBALS['CFG'] = [];
}

/* v1.21.0 存储引擎引导：文件侧 config 的 storage_engine 是唯一决策键（
   引擎键不入库，杜绝「库说该用库 / 文件说该用文件」的引导自锁）。
   数据库连接失败时自动回退文件模式（读文件快照），并置全局醒目警告。 */
if ((string)cfg('storage_engine', 'file') === 'db') {
    if (Store::useDb((array)cfg())) {
        $dbCfg = DbStore::read('config.php', null);
        if (is_array($dbCfg) && $dbCfg) {
            $dbCfg['storage_engine'] = 'db';
            $GLOBALS['CFG'] = $dbCfg;
        }
    } else {
        $GLOBALS['ENV_WARN'] = '数据库连接失败，已自动回退到文件存储模式（读写暂走 data/ 文件快照）。'
            . '原因：' . DbStore::lastConnError() . '。'
            . '请到后台「存储」检查连接设置；恢复后如文件快照期间有新发帖，请重新执行一次「文件 → 数据库」迁移以对齐两侧数据。';
        error_log('[Storage] DB 引擎连接失败，回退文件模式：' . DbStore::lastConnError());
    }
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

/* v1.21.0 多语言：解析当前语言（用户选择 > 资料偏好 > IP 自动判断 > 后台默认），
   必须在会话启动与全部类库就绪后执行；t() 全程可用 */
i18n_init();

/* v1.22.0 拓展：加载全部启用插件（失败自动跳过；钩子从这里开始可订阅） */
plugins_load();

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

/**
 * v1.31.0 终结 HTTP 响应（幂等）：关机阶段做耗时外呼（AI 预检 / AI 队列 / 自动巡逻）前必须先走。
 * 此前的问题（用户实测「AI 审查时网站加载很慢」）：
 *  - 输出缓冲未冲刷：响应尾部（重定向 / JSON）滞留在 gzip 缓冲里，要等 AI 调用结束才送达；
 *  - 会话文件锁未释放：同一用户紧跟的下一个页面请求被会话锁阻塞到 AI 调用结束（表现为发帖
 *    /编辑后跳转卡住数秒甚至更久）；
 *  - FPM 下响应不结束：fastcgi 未收到"请求结束"，客户端连接与 worker 一直被占用。
 * 统一修复：释放会话锁 → 冲刷全部输出缓冲 → FPM 立即终结响应（fastcgi_finish_request），
 * 之后的 AI 外呼在"响应已完整送达"的空闲期进行，不再拖慢发帖人本人与站点其他访客。
 */
function mf_response_finalize(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @flush();
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    }
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
