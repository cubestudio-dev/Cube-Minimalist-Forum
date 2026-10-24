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
    define('MF_VERSION', '1.45.0');
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
require_once __DIR__ . '/stats.php';
require_once __DIR__ . '/firewall.php';
require_once __DIR__ . '/update.php';
require_once __DIR__ . '/selfcheck.php'; /* v1.44.0 功能一键自检 + 自动修复（后台监控页与自检动作共用） */
require_once __DIR__ . '/migrate.php';
require_once __DIR__ . '/sysmon.php';
require_once __DIR__ . '/envfix.php'; /* v1.38.0 运行环境：OS / 面板自动识别 + PHP 扩展一键开启 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/staticize.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/devices.php';
require_once __DIR__ . '/qrcodec.php';
require_once __DIR__ . '/qrlogin.php';
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
/* v1.41.0 启动自愈：config.php 缺失 / 损坏 / 空读时，从 last-good 备份自动恢复——
 * 介质抖动（ARM 盒子 SD 卡掉电丢写等）后整站配置不再「回默认」，本请求即恢复可用 */
if (!is_array($GLOBALS['CFG']) || !$GLOBALS['CFG']) {
    $lgCfg = Store::read('backup/config-last-good.php', []);
    if (is_array($lgCfg) && $lgCfg) {
        $GLOBALS['CFG'] = $lgCfg;
        if (Store::writable()) {
            Store::write('config.php', $lgCfg);
        }
        error_log('[MF] config.php 读取失败，已从 backup/config-last-good.php 自动恢复（' . count($lgCfg) . ' 个配置键）');
        if (empty($GLOBALS['ENV_WARN'])) {
            $GLOBALS['ENV_WARN'] = '检测到 data/config.php 缺失或损坏，已自动从最近一次完好备份恢复（' . count($lgCfg) . ' 个配置键）。'
                . '若存储介质反复出现此问题，建议尽快备份数据并更换存储介质。';
        }
    }
}
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

/* v1.22.0 拓展：加载全部启用插件（失败自动跳过；钩子从这里开始可订阅）
   v1.33.0 极致优化：资源端点（asset/ping/icon/media）不渲染页面、不触发任何插件钩子，
   跳过插件装载（目录扫描 + 逐个 require，慢磁盘上是纯浪费的每请求 I/O）；index.php 会为这些端点定义 MF_RESOURCE */
if (!defined('MF_RESOURCE')) {
    plugins_load();
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

/**
 * v1.33.0 判断响应头是否已声明（headers_list 不区分大小写，逐条前缀匹配）
 */
function mf_header_sent(string $name): bool
{
    $name = strtolower($name) . ':';
    foreach ((headers_list() ?: []) as $h) {
        if (strpos(strtolower((string)$h), $name) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * v1.33.0 HTTP 终结输出处理器（替代 ob_gzhandler，把三类开销收敛到响应收尾一次完成）：
 *  1) gzip：Accept-Encoding 支持时整页压缩（zlib.output_compression 开启 / 已声明编码时自动让位，不重复压缩）；
 *  2) Content-Length：整页收齐后定长发送——慢速设备立即显示进度、连接可复用；
 *     此前 ob_gzhandler 逐块压缩无法预知总长，连接只能靠关闭来标界；
 *  3) HTML ETag / 304：按最终 HTML 内容指纹协商，重复访问 / 返回 / 刷新直接 304 零正文传输——
 *     高延迟链路上省掉整页字节数，是“部分设备打不开”场景里最直接的减负手段。
 * 仅当正文为完整 HTML（doctype/<html 开头）且本次请求无致命错误时才参与协商缓存；
 * JSON / 重定向 / 资源出站（自带 Content-Length / Content-Encoding）自动透传不受影响。
 * v1.42.0 修复「图片 / 附件上传成功却无法查看」：此前 gzip 分支不检查调用方是否已
 * 手工声明 Content-Length——page_media() / page_asset() 等字节精确出站先设了定长，
 * 碰上可压缩字节流（GIF 图片、txt / md / csv 附件等）时被压缩发送，实际字节数小于
 * 已声明的 Content-Length → 浏览器按声明长度等待剩余字节直至超时 / 截断，表现正好是
 * 「部分图片（GIF）与文本附件传上去却打不开」。现在：调用方显式声明 Content-Length
 * 即视为字节精确响应，绝不 gzip（定长与压缩在语义上互斥）。
 * 调用方：index.php 最外层 ob_start('mf_http_out')；内层所有页面输出先收齐再统一终结。
 */
function mf_http_out(string $chunk, int $ph): string
{
    static $buf = '';
    if (!($ph & PHP_OUTPUT_HANDLER_FINAL)) {
        $buf .= $chunk;
        return '';
    }
    $raw = $buf . $chunk;
    $buf = '';
    if (headers_sent()) {
        return $raw; // 头已发出（极端场景），原样透传
    }
    /* 致命错误产生的残页不参与缓存协商（error_get_last 在关机阶段仍可读到致命信息） */
    $el = error_get_last();
    $fatal = is_array($el) && in_array((int)($el['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
    $head = ltrim(substr($raw, 0, 200));
    $isHtml = !$fatal && $raw !== '' && (stripos($head, '<!doctype html') === 0 || stripos($head, '<html') === 0);
    if ($isHtml && !mf_header_sent('ETag')) {
        $etag = '"' . md5($raw) . '"';
        header('ETag: ' . $etag);
        header('Vary: Accept-Encoding');
        $inm = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($inm !== '') {
            $core = trim($etag, '"'); // 比较统一用裸值（客户端可能带 W/ 前缀或引号）
            foreach (explode(',', $inm) as $cand) {
                $cand = trim(trim((string)$cand), "\"'");
                if (strncmp($cand, 'W/', 2) === 0) {
                    $cand = trim(substr($cand, 2), "\"'");
                }
                if ($cand === '*' || $cand === $core) {
                    http_response_code(304);
                    return '';
                }
            }
        }
    }
    $body = $raw;
    $zlibOn = (bool)@ini_get('zlib.output_compression');
    /* v1.42.0：调用方已手工声明 Content-Length（媒体 / 资源 / 下载出站）= 字节精确响应，
       绝不 gzip——定长声明与压缩改写在语义上互斥，强行压缩必然造成 Content-Length 与
       实际发送字节不一致（浏览器挂起等待 / 响应被截断，附件图片「传上去却打不开」） */
    if (!$zlibOn && !mf_header_sent('Content-Encoding') && !mf_header_sent('Content-Length')
        && stripos((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip') !== false) {
        $gz = @gzencode($body, 6);
        if ($gz !== false && strlen($gz) < strlen($body)) {
            $body = (string)$gz;
            header('Content-Encoding: gzip');
            if (!mf_header_sent('Vary')) {
                header('Vary: Accept-Encoding');
            }
        }
    }
    if (!$zlibOn && !mf_header_sent('Content-Length')) {
        header('Content-Length: ' . (string)strlen($body));
    }
    return $body;
}

/**
 * v1.32.0 会话早释放（幂等）：GET 页面渲染前调用——关闭会话并释放文件锁，
 * 渲染期间同一用户的并发请求（预取 / Ping / 搜索预览 / 快速连点）不再被阻塞。
 * 与 mf_response_finalize() 的区别：本函数只关会话不动输出缓冲（渲染还要继续输出）。
 * 关闭后 $_SESSION 数组在内存中仍可读（登录态 / CSRF / 协议确认照常生效），
 * 但写入不再持久——渲染期的会话写点必须先迁移（Flash → 全局暂存，CSS 标记与
 * 浏览量去重 → Cookie），index.php 的调用点已保证这些迁移先于本函数完成。
 */
function mf_session_release(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }
}

/** 更新配置（加锁 + 原子写 + 空读防御 + last-good 备份）
 *  v1.41.0：「配置时不时回到默认」第二道根因防线——读到空配置时绝不照常写回：
 *  介质抖动 / 文件损坏会让本请求读到空数组，若照常合并写回，瞬时读失败就被永久固化成全站配置丢失；
 *  保护性拒绝本次写入并留痕告警，磁盘上的原文件分毫不动。 */
function cfg_update(array $kv): void
{
    $lk = Store::lock('config');
    $c = Store::read('config.php', []);
    if (!is_array($c)) {
        $c = [];
    }
    if ($c === [] && Store::exists('config.php')) {
        Store::unlock($lk);
        error_log('[MF] config.php 读取异常（存在但读出为空 / 损坏），已拒绝本次配置写入以防配置被清空');
        if (empty($GLOBALS['ENV_WARN'])) {
            $GLOBALS['ENV_WARN'] = 'config.php 读取异常（文件存在但内容为空 / 损坏）：为保护现有配置，刚才的设置保存已被拒绝、写入未执行。'
                . '请检查存储介质或从 data/backup/config-last-good.php 恢复。';
        }
        if (function_exists('log_action')) {
            log_action('cfg_guard', 'config.php 读取异常（存在但读出为空），已拒绝本次配置写入——现有设置未改动；请检查存储介质或从 data/backup/ 恢复');
        }
        return;
    }
    foreach ($kv as $k => $v) {
        $c[$k] = $v;
    }
    Store::write('config.php', $c);
    Store::write('backup/config-last-good.php', $c); /* v1.41.0 last-good 备份：启动自愈数据源 */
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

/**
 * v1.42.0 自制错误页 · 致命错误兜底：致命错误（E_ERROR / E_PARSE / E_CORE / E_COMPILE）
 * 发生时渲染与全站一致的 500 错误页，替代 PHP 白屏 / 服务器默认错误页。
 * 安全边界：仅在「尚未向浏览器发出任何字节」时接管（否则状态码不可改，静默让位避免二次破坏）；
 * CLI / 普通警告 / 已有输出一律不介入。残页缓冲先丢弃，保证输出就是完整的错误页。
 */
register_shutdown_function('mf_fatal_page');

function mf_fatal_page(): void
{
    $e = error_get_last();
    if (!is_array($e) || !in_array((int)($e['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    if (PHP_SAPI === 'cli' || defined('MF_FATAL_PAGE_SENT')) {
        return;
    }
    /* 丢弃可能残留的半截页面（ob_end_clean 不触发 mf_http_out 的 FINAL 阶段，安全） */
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    if (headers_sent()) {
        return; // 字节已出站（极端场景），接管只会更糟
    }
    define('MF_FATAL_PAGE_SENT', 1);
    @http_response_code(500);
    @header('Content-Type: text/html; charset=utf-8');
    @header('Cache-Control: no-store');
    $ver = MF_VERSION;
    $msg = htmlspecialchars((string)($e['message'] ?? ''), ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>500 · 服务器开小差了</title></head>'
        . '<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
        . 'background:#f6f6f7;font:15px/1.8 system-ui,-apple-system,\'Segoe UI\',Roboto,\'PingFang SC\',\'Microsoft YaHei\',sans-serif;color:#1c1c1f">'
        . '<div style="max-width:460px;margin:24px;padding:40px 36px;background:#fff;border-radius:14px;'
        . 'box-shadow:0 10px 40px -18px rgba(0,0,0,.25);text-align:center">'
        . '<svg width="64" height="64" viewBox="0 0 64 64" fill="none" aria-hidden="true">'
        . '<circle cx="32" cy="32" r="27" stroke="#0f766e" stroke-width="3"/>'
        . '<path d="M20 40c3-7 8-10 12-10s9 3 12 10" stroke="#0f766e" stroke-width="3" stroke-linecap="round"/>'
        . '<circle cx="24" cy="25" r="2.6" fill="#0f766e"/><circle cx="40" cy="25" r="2.6" fill="#0f766e"/></svg>'
        . '<div style="font-size:38px;font-weight:700;letter-spacing:.04em;color:#0f766e;margin-top:10px">500</div>'
        . '<h1 style="font-size:18px;margin:10px 0 8px">服务器开小差了</h1>'
        . '<p style="color:#75757e;font-size:13px;margin:0 0 4px">页面处理时遇到程序错误，请稍后刷新重试。</p>'
        . '<p style="color:#a0a0a8;font-size:12px;margin:0 0 18px;word-break:break-all">错误信息：' . $msg . '</p>'
        . '<p style="color:#75757e;font-size:13px;margin:0 0 18px">若反复出现，请联系站长检查服务器错误日志（data/logs/error.log）。</p>'
        . '<a href="/" style="display:inline-block;padding:8px 22px;border-radius:8px;background:#0f766e;color:#fff;'
        . 'font-size:14px;text-decoration:none">返回首页</a> '
        . '<a href="javascript:location.reload()" style="display:inline-block;padding:8px 22px;border-radius:8px;'
        . 'border:1px solid #e5e5ea;color:#1c1c1f;font-size:14px;text-decoration:none">刷新重试</a>'
        . '<p style="color:#a0a0a8;font-size:12px;margin:18px 0 0">Cube Minimalist Forum · v' . $ver . '</p>'
        . '</div></body></html>';
}
