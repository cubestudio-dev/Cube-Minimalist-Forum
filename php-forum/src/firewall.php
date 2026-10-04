<?php
/**
 * 极简论坛 · 防火墙与安全防护（v1.8.1）
 * 五道防线（纯 PHP / 文件存储 / 零依赖 / 无 cron）：
 *   1) IP 白名单      —— 管理员自填（支持 CIDR 网段），命中后跳过一切拦截
 *   2) 封禁名单        —— 手动封禁 + 自动策略封禁（单 IP / CIDR，可设时长或永久）
 *   3) 危险 IP 库      —— 从公开威胁情报源自动同步（Emerging Threats、Abuse.ch Feodo 等），
 *                          也支持把黑名单文本粘贴 / 上传导入；未同步时可用内置公开网段种子
 *   4) 限流            —— 每 IP 每分钟请求数限制，超限返回 429；可配「超限自动临时封禁」
 *   5) 自动策略引擎    —— 内置规则（空 UA / 脚本 UA / 扫描路径 / 注入特征 / 404 扫描）按
 *                          10 分钟滑动窗口累计风险分，达到阈值自动封禁；管理员可自定义规则
 * v1.8.1：Cloudflare CDN 适配 —— 自动识别 CF 官方网段（v4+v6），从 CF-Connecting-IP 取真实
 *          访客 IP（防伪造：仅当 TCP 对端确为 CF 边缘时才信任该头），限流 / 封禁 / 统计 / 归属地
 *          全部基于真实 IP，避免「限流误伤全站 / 封禁误封 CF 节点」；后台可一键查看当前链路。
 * v1.9.0：恶意爬虫与异常检测扩展（未知爬虫 UA / 伪造搜索引擎蜘蛛（PTR 反解验证） /
 *          敏感文件探测 / 伪造 CF 头 / 超长请求）；新增「攻击告警」——滑动窗口内拦截次数
 *          达阈值自动给全部管理员邮箱发告警（响应完成后发送，不拖慢站点；带冷却防轰炸）。
 *
 * 存储（data/ 下，JSON + 守卫前缀，与论坛其余数据同机制）：
 *   - fw_bans.php    封禁名单（键为 IP 或 CIDR）
 *   - fw_rules.php   自定义规则
 *   - fw_intel.php   危险 IP 库（同步 / 导入结果）
 *   - fw_state.php   访问统计 / 限流计数 / 风险分窗口（每请求原子读写，与 online.php 同模式）
 *   - fw_geo.php     IP 归属地缓存（ip-api.com 免费批量接口，无需 key）
 *   - logs/fw-日期.php  防火墙事件日志（独立于操作日志，每天限 2000 条，防恶意刷爆配额）
 */
defined('APP') or exit('Forbidden');

/* ================= 内置常量 ================= */

/** 内置公开威胁情报源（后台可开关、可追加自定义源） */
const FW_INTEL_SOURCES = [
    'et'        => ['name' => 'Emerging Threats 恶意 IP 封锁列表', 'url' => 'https://rules.emergingthreats.net/fwrules/emerging-Block-ips.txt'],
    'feodo'     => ['name' => 'Abuse.ch Feodo C&C 服务器 IP', 'url' => 'https://feodotracker.abuse.ch/downloads/ipblocklist.txt'],
    'blackbook' => ['name' => 'Blackbook 垃圾邮件与攻击源（较大）', 'url' => 'https://raw.githubusercontent.com/stamparm/blackbook/master/blackbook.txt'],
];

/** 脚本 / 攻击工具 UA 特征（大小写不敏感） */
const FW_RE_SCRIPT_UA = '/(curl\/|wget|python-requests|python-urllib|aiohttp|scrapy|go-http-client|java\/|libwww-perl|httpclient|okhttp|axios\/|node-fetch|got\/|phantomjs|headlesschrome|splash|sqlmap|nikto|nmap|masscan|zgrab|dirbuster|gobuster|wfuzz|hydra|medusa|acunetix|nessus|openvas|wpscan)/i';

/** 搜索引擎友好爬虫 UA 白名单（开启「脚本 UA 拦截」时自动放行） */
const FW_RE_SPIDER = '/(googlebot|bingbot|baiduspider|sogou|360spider|yandexbot|yandex\.com|duckduckbot|slurp|facebookexternalhit|twitterbot|applebot)/i';

/** 敏感路径 / 扫描特征（请求 URI） */
const FW_RE_SCAN = '#(/wp-admin|/wp-login|/wp-content|/wp-includes|/xmlrpc\.php|/\.env|/\.git|/\.svn|/phpmyadmin|/pma/|/adminer|/setup\.php|/actuator|/\.aws|/cgi-bin/|/vendor/phpunit|/solr/|/jmx-console|/HNAP1|/boaform|/GponForm|/manager/html|/\.DS_Store|\.sql$|\.sql\.bak|\.bak$|\.dump$|\.DS_Store$)#i';

/** 注入 / Webshell 特征（Query 与 URI；兼容 URL 编码形式 %20/%3C/%3D 等） */
const FW_RE_INJECT = '/(union(?:%20|%2[bdj]|[\s\/\*+])+select|select(?:%20|%2[bdj]|[\s\/\*+])+.{1,40}(?:%20|%2[bdj]|[\s\/\*+])+from|insert(?:%20|%2[bdj]|[\s\/\*+])+into|drop(?:%20|%2[bdj]|[\s\/\*+])+table|%3cscript|<script|%3csvg|<svg|(?:javascript|vbscript)%3a|(?:javascript|vbscript):|on(?:error|load|click|mouseover)(?:%3d|%09|%20)*=|(\.\.%2f){2,}|(\.\.\/){2,}|\.\.\\\\|base64_decode|eval\(|assert\(|system\(|passthru|shell_exec|%2fetc%2f(passwd|shadow)|\/etc\/(passwd|shadow)|php%3a%2f%2finput|php:\/\/input|file%3a%2f%2f|file:\/\/|\$\{jndi%3a|\$\{jndi:)/i';

/** 404 扫描判定：10 分钟窗口内 404 达到该次数即记为「扫描行为」（加分项） */
const FW_404_LIMIT = 8;

/** 未知爬虫 UA：自称 bot / spider / crawler / 采集器等，但不在搜索引擎白名单（独立开关 fw_r_bot_ua，轻分值） */
const FW_RE_BOT_UA = '/(bot\b|bot\/|bot;|bot\)|spider|crawler|scrap(?:e|er|ing)|slurp|archiver|semrush|ahrefs|mj12|petalbot|bytespider|serpstat|headless|phantomjs|selenium|puppeteer|playwright)/i';

/** 应用自身敏感文件探测：安装 / 更新残留、源码目录、数据目录、配置与打包文件（URI 匹配） */
const FW_RE_PROBE = '#(/install\.php|/update\.php|/src/[a-z_]+\.php|/data/(config|users|threads|replies|fw_|logs|backup)|/\.user\.ini|/web\.config|/php\.ini|/composer\.(json|lock)|/package(-lock)?\.json|/phpunit|\.sql(\.zip|\.gz|\.bz2)?$|\.(zip|rar|7z|tar|gz)$)#i';

/** 主流搜索引擎蜘蛛 → 官方 PTR 域名后缀（用于「声称是蜘蛛却验证不过」的伪造检测；无条目的声称不参与验证） */
const FW_SPIDER_PTR = [
    'googlebot'           => ['googlebot.com', 'google.com'],
    'bingbot'             => ['search.msn.com', 'bing.com'],
    'baiduspider'         => ['baidu.com', 'baidu.jp'],
    'sogou'               => ['sogou.com'],
    '360spider'           => ['so.com', '360.cn'],
    'yandexbot'           => ['yandex.com', 'yandex.ru', 'yandex.net'],
    'duckduckbot'         => ['duckduckgo.com'],
    'slurp'               => ['yahoo.com', 'inktomisearch.com', 'yahoo.net'],
    'facebookexternalhit' => ['facebook.com', 'fbsv.net'],
    'twitterbot'          => ['twttr.net', 'twitter.com'],
    'applebot'            => ['apple.com'],
];

/** 攻击告警判定窗口（分钟）：窗口内被拦截次数达到阈值即发邮件 */
const FW_ATK_WIN_MIN = 10;

/** 蜘蛛 PTR 验证：每分钟至多做这么多次 DNS 查询（超出预算的声称直接跳过不判伪，防被恶意拖慢） */
const FW_SPIDER_VERIFY_BUDGET = 10;

/* ================= 基础：IP 与工具 ================= */

/** 客户端真实 IP（三层识别）：
 *  ① Cloudflare 自动适配（默认开）：REMOTE_ADDR 属于 Cloudflare 官方网段时，取 CF-Connecting-IP
 *     （该头由 Cloudflare 边缘强制写入并覆盖外部伪造值，可信）；其次 CF-Connecting-IPv6；
 *     再退 X-Forwarded-For 首个合法 IP；全无则用 REMOTE_ADDR（CF 网段自身）。
 *  ② 反代 / 其他 CDN：开启「信任 X-Forwarded-For」后取链路第一个合法 IP；若同时存在
 *     CF-Connecting-IP（Cloudflare 套在宝塔等本机反代之前时该头会原样透传）优先取之——
 *     因 XFF 首段可被客户端伪造，而 CF-Connecting-IP 由 CF 覆写。
 *  ③ 直连：REMOTE_ADDR（默认，最安全，伪造不了）。
 */
function fw_ip(): string
{
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ip = $remote;

    /* ① Cloudflare 链路（自动识别，无需配置；fw_trust_cf 可关） */
    if ($remote !== '' && (int)cfg('fw_trust_cf', 1) === 1 && fw_is_cf_edge($remote)) {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_CF_CONNECTING_IPV6'] as $h) {
            $c = trim((string)($_SERVER[$h] ?? ''));
            if ($c !== '' && filter_var($c, FILTER_VALIDATE_IP)) {
                return $c;
            }
        }
        $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '';
    }

    /* ② 显式声明「在反代 / CDN 之后」 */
    if ((int)cfg('fw_trust_xff', 0) === 1) {
        $cf = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
            return $cf; // Cloudflare 套在本机反代之前：该头由 CF 覆写、经反代原样透传，比 XFF 首段可信
        }
        $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                $ip = $first;
            }
        }
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

/* ---- Cloudflare 官方网段（https://www.cloudflare.com/ips/，多年稳定；如 CF 官方调整，后台可用 fw_cf_ranges 覆盖） ---- */
const FW_CF_RANGES_V4 = [
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
    '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
    '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
    '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
];
const FW_CF_RANGES_V6 = [
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
    '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
];

/** IPv6 → 16 字节二进制（非法返回 null；IPv4 返回 null，v4 走 fw_cidr_range） */
function fw_ip6_bin(string $ip): ?string
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return null;
    }
    $b = @inet_pton($ip);
    return $b === false ? null : $b;
}

/** IPv6 是否落在 CIDR 网段内（如 2606:4700::/32；纯位运算，零依赖） */
function fw_cidr6_match(string $ip, string $cidr): bool
{
    $p = explode('/', $cidr, 2);
    $net = fw_ip6_bin(trim($p[0]));
    $bin = fw_ip6_bin($ip);
    if ($net === null || $bin === null || strlen($net) !== 16) {
        return false;
    }
    $bits = isset($p[1]) ? (int)$p[1] : 128;
    if ($bits < 0 || $bits > 128) {
        return false;
    }
    if ($bits === 0) {
        return true;
    }
    $full = intdiv($bits, 8);
    if ($full > 0 && substr($bin, 0, $full) !== substr($net, 0, $full)) {
        return false;
    }
    $rem = $bits % 8;
    if ($rem > 0 && $full < 16) {
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        if ((ord($bin[$full]) & $mask) !== (ord($net[$full]) & $mask)) {
            return false;
        }
    }
    return true;
}

/** IP 是否命中网段列表（v4 / v6 混合，逐个 CIDR 判断；列表小时线性扫描足够） */
function fw_ip_in_cidrs(string $ip, array $cidrs): bool
{
    if ($ip === '' || $cidrs === []) {
        return false;
    }
    $isV4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    $n = null;
    if ($isV4) {
        $n = fw_ip2n($ip);
        if ($n === null) {
            return false;
        }
    } elseif (fw_ip6_bin($ip) === null) {
        return false;
    }
    foreach ($cidrs as $c) {
        $c = trim((string)$c);
        if ($c === '') {
            continue;
        }
        if ($isV4) {
            $r = fw_cidr_range($c);
            if ($r !== null && $n >= $r[0] && $n <= $r[1]) {
                return true;
            }
        } elseif (fw_cidr6_match($ip, $c)) {
            return true;
        }
    }
    return false;
}

/** 当前连接是否来自 Cloudflare 边缘（REMOTE_ADDR 属于 CF 官方网段；后台可用 fw_cf_ranges 自定义网段覆盖） */
function fw_is_cf_edge(string $remote): bool
{
    if ($remote === '') {
        return false;
    }
    if (!isset($GLOBALS['FW_CF_RANGES_CACHE'])) {
        $custom = trim((string)cfg('fw_cf_ranges', ''));
        $GLOBALS['FW_CF_RANGES_CACHE'] = $custom !== ''
            ? array_values(array_filter(array_map('trim', preg_split('/[\s,，;；]+/u', $custom) ?: []), function ($s) {
                return $s !== '' && (fw_cidr_range($s) !== null || preg_match('#^[0-9a-fA-F:]+/\d{1,3}$#', $s) === 1);
            }))
            : array_merge(FW_CF_RANGES_V4, FW_CF_RANGES_V6);
    }
    return fw_ip_in_cidrs($remote, $GLOBALS['FW_CF_RANGES_CACHE']);
}

/** 当前请求链路信息（后台「真实 IP 识别」诊断卡用）：来源类型 + 各层 IP + 最终判定 */
function fw_link_info(): array
{
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $cfIp = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    $cfIp = filter_var($cfIp, FILTER_VALIDATE_IP) ? $cfIp : '';
    $xff = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    $isCf = $remote !== '' && fw_is_cf_edge($remote);
    if ($isCf) {
        $mode = 'cloudflare';
    } elseif ($cfIp !== '' && (int)cfg('fw_trust_xff', 0) === 1) {
        $mode = 'cf-proxy'; // CF 套在本机反代之前
    } elseif ((int)cfg('fw_trust_xff', 0) === 1 && $xff !== '') {
        $mode = 'xff';
    } else {
        $mode = 'direct';
    }
    return [
        'mode'     => $mode,       // cloudflare | cf-proxy | xff | direct
        'remote'   => $remote,     // TCP 层对端（直连=访客；代理=代理 IP）
        'cfip'     => $cfIp,       // CF-Connecting-IP 头（有值说明请求经过 Cloudflare）
        'xff'      => $xff,        // X-Forwarded-For 头
        'resolved' => fw_ip(),     // 最终识别出的客户端 IP
    ];
}

/** IPv4 → 无符号整数（64 位平台精确；IPv6 或非法返回 null） */
function fw_ip2n(string $ip): ?int
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return null;
    }
    $l = ip2long($ip);
    if ($l === false) {
        return null;
    }
    return (int)sprintf('%u', $l);
}

/** 解析 "a.b.c.d" 或 "a.b.c.d/e"（CIDR）为 [起始, 结束] 无符号区间；非法返回 null */
function fw_cidr_range(string $s): ?array
{
    $s = trim($s);
    $parts = explode('/', $s, 2);
    $ip = fw_ip2n($parts[0]);
    if ($ip === null) {
        return null;
    }
    if (count($parts) === 1) {
        return [$ip, $ip];
    }
    $bits = (int)$parts[1];
    if ($bits < 0 || $bits > 32) {
        return null;
    }
    if ($bits === 0) {
        return [0, 4294967295];
    }
    $mask = (-1 << (32 - $bits)) & 0xFFFFFFFF;
    $lo = $ip & $mask;
    $hi = $lo | ((~$mask) & 0xFFFFFFFF);
    return [$lo, $hi];
}

/** IP / 网段列表清洗：逗号 / 空白分隔，逐个校验单 IP 或 CIDR，去重 */
function fw_ip_list(string $raw): array
{
    $out = [];
    foreach (preg_split('/[\s,，;；]+/u', trim($raw)) ?: [] as $p) {
        $p = trim((string)$p);
        if ($p === '') {
            continue;
        }
        if (fw_cidr_range($p) !== null) {
            $out[] = $p;
        }
    }
    return array_values(array_unique($out));
}

/** 安全响应头（每个请求输出一次；仅在首个输出前调用） */
function fw_security_headers(): void
{
    if (!headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    }
}

/* ================= 运行状态（fw_state.php） ================= */

/**
 * 防火墙运行状态（fw_state.php）：本请求内的读缓存。
 * PHP 每请求内存重置，计数必须每请求落盘才跨请求有效（与 online.php 同模式；
 * 原子写 + 文件锁保证并发安全；文件保持小体积，见 fw_state_save 的清理逻辑）。
 */
function fw_state(): array
{
    if (!isset($GLOBALS['FW_STATE_CACHE'])) {
        $st = Store::read('fw_state.php', []);
        if (!is_array($st)) {
            $st = [];
        }
        $st += ['ips' => [], 'rl' => [], 'days' => [], 'ev' => [], 'atk' => [], 'atk_alert' => [], 'spv' => [], 'spv_m' => []];
        foreach (['ips', 'rl', 'days', 'ev', 'atk', 'atk_alert', 'spv', 'spv_m'] as $k) {
            if (!is_array($st[$k])) {
                $st[$k] = [];
            }
        }
        $GLOBALS['FW_STATE_CACHE'] = $st;
    }
    return $GLOBALS['FW_STATE_CACHE'];
}

/** 保存防火墙状态（加锁 + 原子写 + 体积清理），并刷新本请求缓存 */
function fw_state_save(array $st): void
{
    $now = time();
    // 清理：IP 统计 7 天不活跃剔除 / 最多 2000 条；限流桶仅保留当前分钟；日统计 14 天
    foreach ($st['ips'] as $ip => $r) {
        if ((int)($r['l'] ?? 0) < $now - 7 * 86400) {
            unset($st['ips'][$ip]);
        }
    }
    if (count($st['ips']) > 2000) {
        uasort($st['ips'], function ($a, $b) {
            return (int)($b['l'] ?? 0) <=> (int)($a['l'] ?? 0);
        });
        $st['ips'] = array_slice($st['ips'], 0, 2000, true);
    }
    $minute = intdiv($now, 60);
    foreach ($st['rl'] as $ip => $r) {
        if (!is_array($r) || (int)($r['m'] ?? 0) !== $minute) {
            unset($st['rl'][$ip]);
        }
    }
    if (count($st['rl']) > 2000) {
        $st['rl'] = array_slice($st['rl'], 0, 2000, true);
    }
    $cut = date('Y-m-d', $now - 14 * 86400);
    foreach (array_keys($st['days']) as $d) {
        if ($d < $cut) {
            unset($st['days'][$d]);
        }
    }
    if (count($st['ev']) > 4000) {
        $st['ev'] = array_slice($st['ev'], 0, 4000, true);
    }
    // 蜘蛛 PTR 验证缓存：过期剔除 + 最多 500 条
    foreach (($st['spv'] ?? []) as $k => $v) {
        if (!is_array($v) || time() - (int)($v['ts'] ?? 0) > 30 * 86400) {
            unset($st['spv'][$k]);
        }
    }
    if (count($st['spv']) > 500) {
        uasort($st['spv'], function ($a, $b) {
            return (int)($b['ts'] ?? 0) <=> (int)($a['ts'] ?? 0);
        });
        $st['spv'] = array_slice($st['spv'], 0, 500, true);
    }
    $lk = Store::lock('fw_state', 3);
    // 锁内重读合并：另一请求可能刚写过（丢弃它未保存的？不——以本次快照+本请求增量为准，
    // 本函数由调用方在“读快照→修改→保存”的临界序列末尾调用，锁内最后重读一次做浅合并
    // 会破坏计数语义；论坛为小并发场景，锁序列内的快照已足够准确）
    Store::write('fw_state.php', $st);
    Store::unlock($lk);
    $GLOBALS['FW_STATE_CACHE'] = $st;
}

/** 请求计数 / 404 计数 / 拦截计数（读快照 → 修改 → 原子落盘） */
function fw_bump(string $ip, string $key = 'c'): void
{
    if ($ip === '') {
        return;
    }
    $st = fw_state();
    $d = date('Y-m-d');
    if (!isset($st['days'][$d])) {
        $st['days'][$d] = ['req' => 0, 'blocked' => 0, 'bans' => 0, 'ev' => 0];
    }
    if ($key === 'c') {
        $st['days'][$d]['req']++;
    } elseif ($key === 'blocked') {
        $st['days'][$d]['blocked']++;
    }
    if (!isset($st['ips'][$ip])) {
        $st['ips'][$ip] = ['c' => 0, 'f' => 0, 'l' => 0, 'ua' => '', 's' => 0, 'w' => 0, 'wl' => 0];
    }
    if ($key === 'c' || $key === 'blocked') {
        $st['ips'][$ip]['c']++;
        $st['ips'][$ip]['l'] = time();
        if ($st['ips'][$ip]['ua'] === '') {
            $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
            $st['ips'][$ip]['ua'] = cut_str($ua !== '' ? $ua : '(空 UA)', 60);
        }
    } elseif ($key === 'f') {
        $st['ips'][$ip]['f']++;
    }
    if ($key === 'blocked') {
        $st = fw_attack_track($st, $ip); // 攻击窗口统计 + 阈值判定（达到时在响应完成后自动发告警邮件）
    }
    fw_state_save($st);
}

/** 404 计数 + 扫描判定（pages.php 的 page_404 调用） */
function fw_bump_404(): void
{
    if ((int)cfg('fw_on', 1) !== 1) {
        return;
    }
    $ip = fw_ip();
    if ($ip === '' || fw_whitelisted($ip)) {
        return;
    }
    fw_bump($ip, 'f');
    // 10 分钟窗口内 404 超限 → 记扫描行为并加分（交给统一评分管线）
    $st = fw_state();
    $rec = $st['ips'][$ip] ?? [];
    if ((int)($rec['f'] ?? 0) >= FW_404_LIMIT && (int)cfg('fw_score_on', 1) === 1) {
        $st['ips'][$ip]['f'] = 0; // 清零避免同窗口反复触发
        fw_state_save($st);       // 先落盘清零，再加分（否则 score_add 内部快照会覆盖回旧值，后续每个 404 都重复加分）
        fw_score_add($ip, 60, '高频 404 扫描', false); // 不输出拦截页（当前正在渲染 404 页）
    }
}

/* ================= 白名单 ================= */

/** 白名单（支持 CIDR）：命中则跳过一切拦截；结果带请求级缓存 */
function fw_whitelisted(string $ip): bool
{
    static $cache = null, $list = null;
    if ($list === null) {
        $list = fw_ip_list((string)cfg('fw_whitelist', ''));
    }
    if ($cache === null) {
        $cache = [];
        foreach ($list as $item) {
            $r = fw_cidr_range($item);
            if ($r) {
                $cache[] = $r;
            }
        }
    }
    $n = fw_ip2n($ip);
    if ($n === null) {
        return false;
    }
    foreach ($cache as $r) {
        if ($n >= $r[0] && $n <= $r[1]) {
            return true;
        }
    }
    return false;
}

/* ================= 封禁名单 ================= */

/** 全部封禁（带请求级缓存，fw_ban/fw_unban 写入后自动失效）；顺带剔除已过期条目 */
function fw_bans_all(): array
{
    if (!isset($GLOBALS['FW_BANS_CACHE'])) {
        $bans = Store::read('fw_bans.php', []);
        if (!is_array($bans)) {
            $bans = [];
        }
        foreach ($bans as $k => $r) {
            if (!is_array($r)) {
                unset($bans[$k]);
            }
        }
        $GLOBALS['FW_BANS_CACHE'] = $bans;
    }
    return $GLOBALS['FW_BANS_CACHE'];
}

/** 是否被封禁：返回封禁记录（含 reason / until / kind），未封禁返回 null */
function fw_is_banned(string $ip): ?array
{
    $bans = fw_bans_all();
    $r = $bans[$ip] ?? null;
    if (is_array($r)) {
        return fw_ban_alive($r) ? $r : null;
    }
    // CIDR 匹配（封禁网段数量一般不大，线性即可）
    $n = fw_ip2n($ip);
    if ($n === null) {
        return null;
    }
    foreach ($bans as $key => $rec) {
        if (!is_array($rec) || strpos((string)$key, '/') === false) {
            continue;
        }
        $rg = fw_cidr_range((string)$key);
        if ($rg && $n >= $rg[0] && $n <= $rg[1] && fw_ban_alive($rec)) {
            $rec['via'] = $key;
            return $rec;
        }
    }
    return null;
}

function fw_ban_alive(array $r): bool
{
    $until = (int)($r['until'] ?? 0);
    return $until === 0 || $until > time();
}

/** 写入封禁；$hours=0 永久。$kind: manual 手动 / auto 策略自动 */
function fw_ban(string $ip, int $hours, string $reason, string $kind = 'manual', string $by = '系统'): bool
{
    if (fw_cidr_range($ip) === null || fw_whitelisted($ip)) {
        return false;
    }
    $lk = Store::lock('fw_bans');
    $bans = Store::read('fw_bans.php', []);
    if (!is_array($bans)) {
        $bans = [];
    }
    // 顺带清理已过期条目
    foreach ($bans as $k => $r) {
        if (is_array($r) && (int)($r['until'] ?? 0) > 0 && (int)$r['until'] < time()) {
            unset($bans[$k]);
        }
    }
    $bans[$ip] = [
        'until'  => $hours > 0 ? time() + $hours * 3600 : 0,
        'reason' => cut_str($reason, 120),
        'by'     => cut_str($by, 20),
        'time'   => time(),
        'kind'   => $kind === 'manual' ? 'manual' : 'auto',
    ];
    if (count($bans) > 3000) { // 上限保护：防配额被恶意灌爆
        uasort($bans, function ($a, $b) {
            return (int)($b['time'] ?? 0) <=> (int)($a['time'] ?? 0);
        });
        $bans = array_slice($bans, 0, 3000, true);
    }
    $ok = Store::write('fw_bans.php', $bans);
    Store::unlock($lk);
    $GLOBALS['FW_BANS_CACHE'] = $bans; // 同请求内立即可见
    if ($ok) {
        $st = fw_state();
        $d = date('Y-m-d');
        if (!isset($st['days'][$d])) {
            $st['days'][$d] = ['req' => 0, 'blocked' => 0, 'bans' => 0, 'ev' => 0];
        }
        $st['days'][$d]['bans']++;
        fw_state_save($st);
    }
    return $ok;
}

/** 解除封禁（支持单 IP 与 CIDR 键） */
function fw_unban(string $ip): bool
{
    $lk = Store::lock('fw_bans');
    $bans = Store::read('fw_bans.php', []);
    if (!is_array($bans) || !isset($bans[$ip])) {
        Store::unlock($lk);
        return false;
    }
    unset($bans[$ip]);
    $ok = Store::write('fw_bans.php', $bans);
    Store::unlock($lk);
    $GLOBALS['FW_BANS_CACHE'] = $bans; // 同请求内立即可见
    return $ok;
}

/* ================= 危险 IP 库（威胁情报） ================= */

/** 读库（带请求级缓存，同步/导入后自动失效）：['synced','total','ips'=>{ip:1},'nets'=>[[lo,hi],...升序]] */
function fw_intel_load(): array
{
    if (!isset($GLOBALS['FW_INTEL_CACHE'])) {
        $intel = Store::read('fw_intel.php', []);
        if (!is_array($intel)) {
            $intel = [];
        }
        $intel += ['synced' => 0, 'total' => 0, 'ips' => [], 'nets' => [], 'sources' => []];
        if (!is_array($intel['ips'])) {
            $intel['ips'] = [];
        }
        if (!is_array($intel['nets'])) {
            $intel['nets'] = [];
        }
        $GLOBALS['FW_INTEL_CACHE'] = $intel;
    }
    return $GLOBALS['FW_INTEL_CACHE'];
}

/** 命中危险 IP 库？单 IP 精确哈希 + 网段二分（仅 IPv4） */
function fw_intel_hit(string $ip): bool
{
    $intel = fw_intel_load();
    if ((int)$intel['total'] <= 0) {
        return false;
    }
    $n = fw_ip2n($ip);
    if ($n === null) {
        return false; // IPv6 暂不入库匹配
    }
    if (isset($intel['ips'][$ip])) {
        return true;
    }
    $nets = $intel['nets'];
    $lo = 0;
    $hi = count($nets) - 1;
    $found = -1;
    while ($lo <= $hi) { // 找 lo <= n 的最后一个网段
        $mid = intdiv($lo + $hi, 2);
        if ((int)$nets[$mid][0] <= $n) {
            $found = $mid;
            $lo = $mid + 1;
        } else {
            $hi = $mid - 1;
        }
    }
    for ($i = $found; $i >= 0 && $i > $found - 4; $i--) { // 邻近兜底（同 lo 多段）
        if ($n >= (int)$nets[$i][0] && $n <= (int)$nets[$i][1]) {
            return true;
        }
    }
    return false;
}

/** 解析黑名单文本：返回 [ips(哈希), nets(排序区间)]; 支持 IP / CIDR / a-b 区间 / # 注释 */
function fw_intel_parse(string $text, int $maxIps = 200000, int $maxNets = 60000): array
{
    $ips = [];
    $nets = [];
    $n = 0;
    foreach (explode("\n", str_replace("\r", '', $text)) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        // "1.2.3.4 - 1.2.3.9" / "1.2.3.4-1.2.3.9" 区间写法
        if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})\s*[-–]\s*(\d{1,3}(?:\.\d{1,3}){3})$/', $line, $m)) {
            $a = fw_ip2n($m[1]);
            $b = fw_ip2n($m[2]);
            if ($a !== null && $b !== null && $b >= $a && count($nets) < $maxNets) {
                $nets[] = [$a, $b];
            }
            continue;
        }
        $r = fw_cidr_range($line);
        if ($r === null) {
            continue;
        }
        if (strpos($line, '/') === false) {
            if (count($ips) < $maxIps) {
                $ips[$line] = 1;
            }
        } elseif (count($nets) < $maxNets) {
            $nets[] = $r;
        }
        if (++$n > $maxIps + $maxNets) {
            break; // 超大文件保护
        }
    }
    usort($nets, function ($a, $b) {
        return $a[0] <=> $b[0] ?: $a[1] <=> $b[1];
    });
    return [$ips, $nets];
}

/** 用后台配置的自定义源 URL 拉取（cURL 优先，回退 file_get_contents），失败返回空串 */
function fw_http_get(string $url, int $timeout = 20): string
{
    if (!preg_match('#^https?://#i', $url)) {
        return '';
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'CubeMinimalistForum/' . app_version(),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code === 200 && is_string($body)) ? $body : '';
    }
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'user_agent' => 'CubeMinimalistForum/' . app_version()]]);
    $body = @file_get_contents($url, false, $ctx);
    return is_string($body) ? $body : '';
}

/** POST JSON 请求（cURL 优先，回退 file_get_contents 流上下文），失败返回空串 */
function fw_http_post(string $url, string $payload, int $timeout = 15): string
{
    if (!preg_match('#^https?://#i', $url) || $payload === '') {
        return '';
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT      => 'CubeMinimalistForum/' . app_version(),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code === 200 && is_string($body)) ? $body : '';
    }
    $ctx = stream_context_create(['http' => [
        'timeout'    => $timeout,
        'method'     => 'POST',
        'header'     => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content'    => $payload,
        'user_agent' => 'CubeMinimalistForum/' . app_version(),
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    return is_string($body) ? $body : '';
}

/**
 * 同步危险 IP 库：内置源（按后台开关）+ 自定义源 → 解析合并 → 写 fw_intel.php
 * @return array 摘要 ['synced','total','ips','nets','sources'=>{key:[ok,name,err,count]}]
 */
function fw_intel_sync(bool $manual = true): array
{
    $t0 = microtime(true);
    $ips = [];
    $nets = [];
    $srcStat = [];
    // 内置源
    foreach (FW_INTEL_SOURCES as $key => $meta) {
        $on = (int)cfg('fw_src_' . $key, $key === 'blackbook' ? 0 : 1) === 1;
        if (!$on) {
            $srcStat[$key] = ['ok' => true, 'name' => $meta['name'], 'err' => '已停用', 'count' => 0];
            continue;
        }
        $body = fw_http_get($meta['url']);
        if ($body === '' || strlen($body) > 6 * 1048576) {
            $srcStat[$key] = ['ok' => false, 'name' => $meta['name'], 'err' => $body === '' ? '拉取失败（网络受限或超时）' : '响应超过 6MB，已放弃', 'count' => 0];
            continue;
        }
        [$si, $sn] = fw_intel_parse($body);
        $ips += $si;
        $nets = array_merge($nets, $sn);
        $srcStat[$key] = ['ok' => true, 'name' => $meta['name'], 'err' => '', 'count' => count($si) + count($sn)];
    }
    // 自定义源（每行一个 URL，最多 5 条）
    $custom = array_slice(explode("\n", str_replace("\r", '', (string)cfg('fw_intel_custom', ''))), 0, 5);
    foreach ($custom as $i => $u) {
        $u = trim($u);
        if ($u === '') {
            continue;
        }
        $key = 'custom' . ($i + 1);
        $body = fw_http_get($u);
        if ($body === '') {
            $srcStat[$key] = ['ok' => false, 'name' => $u, 'err' => '拉取失败', 'count' => 0];
            continue;
        }
        [$si, $sn] = fw_intel_parse($body);
        $ips += $si;
        $nets = array_merge($nets, $sn);
        $srcStat[$key] = ['ok' => true, 'name' => $u, 'err' => '', 'count' => count($si) + count($sn)];
    }
    usort($nets, function ($a, $b) {
        return $a[0] <=> $b[0] ?: $a[1] <=> $b[1];
    });
    $total = count($ips) + count($nets);
    $intel = [
        'synced'  => time(),
        'total'   => $total,
        'ips'     => $ips,
        'nets'    => $nets,
        'sources' => $srcStat,
        'took'    => round(microtime(true) - $t0, 1),
    ];
    Store::write('fw_intel.php', $intel);
    $GLOBALS['FW_INTEL_CACHE'] = $intel; // 同请求内立即可见
    log_action('fw_intel_sync', ($manual ? '手动' : '自动') . '同步危险 IP 库：' . $total . ' 条（IP ' . count($ips) . ' · 网段 ' . count($nets) . '），耗时 ' . $intel['took'] . 's', 0, '系统');
    return $intel;
}

/** 手动导入黑名单文本：合并进现有库（不去重已存在的，按键天然去重 IP） */
function fw_intel_import(string $text, string $by): int
{
    if (cut_str($text, 200000) !== $text) {
        $text = cut_str($text, 200000);
    }
    [$si, $sn] = fw_intel_parse($text);
    if (!$si && !$sn) {
        return 0;
    }
    $intel = fw_intel_load();
    $intel['ips'] += $si;
    $intel['nets'] = array_merge($intel['nets'], $sn);
    usort($intel['nets'], function ($a, $b) {
        return $a[0] <=> $b[0] ?: $a[1] <=> $b[1];
    });
    $intel['total'] = count($intel['ips']) + count($intel['nets']);
    $intel['sources']['manual'] = ['ok' => true, 'name' => '手动导入（' . $by . '）', 'err' => '', 'count' => count($si) + count($sn)];
    Store::write('fw_intel.php', $intel);
    $GLOBALS['FW_INTEL_CACHE'] = $intel; // 同请求内立即可见
    return count($si) + count($sn);
}

/* ================= 限流 ================= */

/** 每 IP 每分钟计数（读快照 → 修改 → 原子落盘），返回 [当前分钟计数, 阈值] */
function fw_rl_add(string $ip): array
{
    $pm = max(5, (int)cfg('fw_rl_pm', 60));
    $st = fw_state();
    $m = intdiv(time(), 60);
    $r = isset($st['rl'][$ip]) && is_array($st['rl'][$ip]) ? $st['rl'][$ip] : ['m' => 0, 'c' => 0];
    if ((int)$r['m'] !== $m) {
        $r = ['m' => $m, 'c' => 0];
    }
    $r['c']++;
    $st['rl'][$ip] = $r;
    fw_state_save($st);
    return [(int)$r['c'], $pm];
}

/**
 * 验证「声称是主流搜索引擎蜘蛛」的 IP：PTR 反解 → 官方域名后缀匹配 → 正向解析回原 IP（防自设 PTR 冒充）。
 * 结果按 IP 缓存 30 天（fw_state.spv）；DNS 查询受每分钟预算限制（超预算返回 null = 无法判定，不误判）。
 * @return bool true=验证通过 / false=验证失败（伪造）/ null=无法验证（无 PTR、函数缺失、预算耗尽等）
 */
function fw_spider_verify(string $ip, string $ua): ?bool
{
    if (!function_exists('gethostbyaddr') || !function_exists('gethostbyname')) {
        return null;
    }
    // 找出声称的蜘蛛类型（无官方 PTR 映射的不验证）
    $map = null;
    foreach (FW_SPIDER_PTR as $name => $domains) {
        if (stripos($ua, $name) !== false) {
            $map = $domains;
            break;
        }
    }
    if ($map === null) {
        return null;
    }
    $st = fw_state();
    $v = is_array($st['spv'][$ip] ?? null) ? $st['spv'][$ip] : null;
    if ($v !== null && time() - (int)($v['ts'] ?? 0) < 30 * 86400) {
        return !empty($v['ok']);
    }
    // DNS 预算：每分钟至多 FW_SPIDER_VERIFY_BUDGET 次，防止恶意批量伪造拖慢站点
    $m = intdiv(time(), 60);
    $b = is_array($st['spv_m'] ?? null) ? $st['spv_m'] : [];
    if ((int)($b['m'] ?? -1) !== $m) {
        $b = ['m' => $m, 'c' => 0];
    }
    if ((int)$b['c'] >= FW_SPIDER_VERIFY_BUDGET) {
        return null;
    }
    $b['c']++;
    $st['spv_m'] = $b;
    fw_state_save($st);

    $ok = false;
    $host = @gethostbyaddr($ip);
    if (is_string($host) && $host !== '' && strcasecmp($host, $ip) !== 0) {
        $h = str_lower($host);
        foreach ($map as $d) {
            $d = str_lower($d);
            if ($h === $d || substr($h, -strlen($d) - 1) === '.' . $d) {
                // 正向确认：PTR 域名必须解析回原 IP（防攻击者自设 PTR 冒充）
                $fwd = @gethostbyname($host);
                $ok = ($fwd !== $host && strcasecmp($fwd, $ip) === 0);
                break;
            }
        }
    }
    $st = fw_state(); // 保存过预算计数，重读后再写入缓存
    $st['spv'][$ip] = ['ok' => $ok ? 1 : 0, 'ts' => time()];
    fw_state_save($st);
    return $ok;
}

/* ================= 攻击告警（多次拦截 → 自动邮件通知管理员） ================= */

/**
 * 攻击窗口统计 + 阈值判定（在 fw_bump 的 blocked 计数后调用）。
 * 窗口：FW_ATK_WIN_MIN 分钟滑动窗口（按分钟分桶存 fw_state.atk）；
 * 达到阈值且不在冷却期：立即占位冷却标记（并发请求不重复发信），实际发信注册到
 * register_shutdown_function（响应完成后再发，绝不拖慢/阻塞当前请求）。
 * @return array 更新后的状态（由调用方统一落盘，本函数不自行写盘以免覆盖计数）
 */
function fw_attack_track(array $st, string $ip): array
{
    if ((int)cfg('fw_atk_alert_on', 1) !== 1) {
        return $st;
    }
    $now = time();
    $m = intdiv($now, 60);
    $atk = is_array($st['atk'] ?? null) ? $st['atk'] : [];
    $atk[$m] = (int)($atk[$m] ?? 0) + 1;
    foreach (array_keys($atk) as $mm) {
        if ((int)$mm < $m - FW_ATK_WIN_MIN) { // 只保留窗口 + 1 桶余量
            unset($atk[$mm]);
        }
    }
    $st['atk'] = $atk;
    $th = max(5, (int)cfg('fw_atk_alert_n', 20));
    $sum = 0;
    for ($i = 0; $i < FW_ATK_WIN_MIN; $i++) {
        $sum += (int)($atk[$m - $i] ?? 0);
    }
    if ($sum < $th) {
        return $st;
    }
    $cool = max(5, (int)cfg('fw_atk_alert_cool', 30)) * 60;
    $al = is_array($st['atk_alert'] ?? null) ? $st['atk_alert'] : [];
    if (!empty($al['last']) && $now - (int)$al['last'] < $cool) {
        return $st; // 冷却期内不重复发信
    }
    $st['atk_alert'] = ['last' => $now, 'peak' => $sum, 'ip' => $ip];
    if (empty($GLOBALS['FW_ATK_MAIL_QUEUED'])) {
        $GLOBALS['FW_ATK_MAIL_QUEUED'] = true;
        register_shutdown_function(function () use ($ip, $sum) {
            fw_attack_mail($ip, $sum);
        });
    }
    return $st;
}

/** 关机阶段：给全部管理员发送攻击告警邮件（含今日拦截统计 / 事件最多来源 IP Top5 / 最近事件摘要） */
function fw_attack_mail(string $triggerIp, int $peak): void
{
    try {
        $site = cut_str(str_replace(["\r", "\n"], ' ', (string)cfg('site_name', '论坛')), 40);
        $th = max(5, (int)cfg('fw_atk_alert_n', 20));
        // 从今天的防火墙日志尾部聚合事件最多的来源 IP 与最近事件
        $top = [];
        $recent = [];
        $f = DATA_DIR . '/logs/fw-' . date('Y-m-d') . '.php';
        if (is_file($f)) {
            $raw = (string)@file_get_contents($f);
            if (strpos($raw, DATA_GUARD) === 0) {
                $raw = substr($raw, strlen(DATA_GUARD));
            }
            $lines = array_filter(array_map('trim', explode("\n", $raw)));
            foreach (array_slice($lines, -600) as $ln) { // 只看最近的 600 条
                $j = json_decode($ln, true);
                if (!is_array($j) || empty($j['ip'])) {
                    continue;
                }
                $ip2 = (string)$j['ip'];
                $top[$ip2] = ($top[$ip2] ?? 0) + 1;
                if (count($recent) < 8) {
                    $recent[] = $j;
                }
            }
            arsort($top);
            $top = array_slice($top, 0, 5, true);
        }
        $st = fw_state();
        $today = is_array($st['days'][date('Y-m-d')] ?? null) ? $st['days'][date('Y-m-d')] : [];
        $bans = count(fw_bans_all());
        $rows = '<tr><td style="padding:6px 12px;border:1px solid #e5e5ea">今日拦截</td><td style="padding:6px 12px;border:1px solid #e5e5ea"><b>' . (int)($today['blocked'] ?? 0) . '</b> 次</td></tr>'
            . '<tr><td style="padding:6px 12px;border:1px solid #e5e5ea">今日请求</td><td style="padding:6px 12px;border:1px solid #e5e5ea"><b>' . (int)($today['req'] ?? 0) . '</b> 次</td></tr>'
            . '<tr><td style="padding:6px 12px;border:1px solid #e5e5ea">今日新增封禁</td><td style="padding:6px 12px;border:1px solid #e5e5ea"><b>' . (int)($today['bans'] ?? 0) . '</b> 次</td></tr>'
            . '<tr><td style="padding:6px 12px;border:1px solid #e5e5ea">当前封禁名单</td><td style="padding:6px 12px;border:1px solid #e5e5ea"><b>' . $bans . '</b> 条</td></tr>';
        $inner = '<p style="margin:0 0 14px">检测到持续攻击行为：最近 <b>' . FW_ATK_WIN_MIN . ' 分钟</b>内防火墙拦截已达 <b style="color:#b91c1c">' . $peak . '</b> 次（阈值 ' . $th . ' 次）。</p>'
            . '<p style="margin:0 0 8px"><b>概况</b></p>'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;margin:0 0 16px">' . $rows . '</table>';
        if ($top) {
            $inner .= '<p style="margin:0 0 8px"><b>事件最多的来源 IP</b>（今日防火墙日志统计）</p>'
                . '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;margin:0 0 16px">';
            foreach ($top as $ip2 => $c) {
                $inner .= '<tr><td style="padding:6px 12px;border:1px solid #e5e5ea;font-family:ui-monospace,Menlo,Consolas,monospace">' . htmlspecialchars($ip2, ENT_QUOTES, 'UTF-8') . '</td><td style="padding:6px 12px;border:1px solid #e5e5ea">' . $c . ' 条事件</td></tr>';
            }
            $inner .= '</table>';
        }
        if ($recent) {
            $inner .= '<p style="margin:0 0 8px"><b>最近事件</b>（最多 8 条，新→旧）</p>'
                . '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:12px;margin:0 0 16px">';
            foreach ($recent as $j) {
                $inner .= '<tr><td style="padding:5px 10px;border:1px solid #e5e5ea;white-space:nowrap">' . date('H:i:s', (int)($j['t'] ?? 0)) . '</td>'
                    . '<td style="padding:5px 10px;border:1px solid #e5e5ea;font-family:ui-monospace,Menlo,Consolas,monospace">' . htmlspecialchars((string)($j['ip'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td style="padding:5px 10px;border:1px solid #e5e5ea">' . htmlspecialchars((string)($j['act'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td style="padding:5px 10px;border:1px solid #e5e5ea">' . htmlspecialchars((string)($j['rule'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td style="padding:5px 10px;border:1px solid #e5e5ea;word-break:break-all">' . htmlspecialchars((string)($j['m'] ?? '') . ' ' . (string)($j['uri'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td></tr>';
            }
            $inner .= '</table>';
        }
        $inner .= '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:12px 14px;font-size:13px;line-height:1.8;color:#1e40af">'
            . '<b>处置建议：</b>到后台「安全防护」查看防火墙日志与访问统计，确认攻击特征并封禁 / 调整策略；若为 CC 攻击，可在 Cloudflare 面板临时开启「我正在被攻击」模式（程序已按真实 IP 限流，不会误伤全站）。</div>';
        $inner .= '<p style="margin:14px 0 0;font-size:12px;color:#71717a">本次触发来源 IP：' . htmlspecialchars($triggerIp, ENT_QUOTES, 'UTF-8') . '；冷却 ' . max(5, (int)cfg('fw_atk_alert_cool', 30)) . ' 分钟内不重复发送。</p>';
        [$sent, $admins, $err] = mail_admins(
            "【攻击告警】{$site} 最近" . FW_ATK_WIN_MIN . "分钟被拦截 {$peak} 次",
            mail_template('攻击告警', $inner)
        );
        // 记录发送结果（供后台攻击告警卡片展示）
        $st2 = fw_state();
        $al = is_array($st2['atk_alert'] ?? null) ? $st2['atk_alert'] : [];
        $al['sent'] = $sent;
        $al['admins'] = $admins;
        $al['err'] = $sent > 0 ? '' : cut_str($err, 120);
        $st2['atk_alert'] = $al;
        fw_state_save($st2);
        if ($sent > 0) {
            log_action('attack_alert', "最近 " . FW_ATK_WIN_MIN . " 分钟拦截 {$peak} 次（阈值 {$th}），攻击告警邮件已发送 {$sent}/{$admins} 位管理员", 0, '系统');
        } else {
            log_action('attack_alert', "拦截 {$peak} 次达到告警阈值，但邮件发送失败" . ($err !== '' ? '：' . cut_str($err, 100) : '（请检查后台 SMTP 配置与管理员邮箱）'), 0, '系统');
        }
    } catch (Throwable $t) {
        // 告警失败绝不影响业务
    }
}

/** 测试攻击告警邮件（后台按钮；不影响真实告警冷却计时）@return [bool, string] */
function fw_attack_test_mail(): array
{
    $site = cut_str(str_replace(["\r", "\n"], ' ', (string)cfg('site_name', '论坛')), 40);
    $th = max(5, (int)cfg('fw_atk_alert_n', 20));
    $inner = '<p style="margin:0 0 12px">这是一封 <b>攻击告警测试邮件</b>。当站点遭遇持续攻击时，会向全部管理员邮箱发送本格式的告警邮件。</p>'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;margin:0 0 14px">'
        . '<tr><td style="padding:6px 12px;border:1px solid #e5e5ea;width:110px">触发条件</td><td style="padding:6px 12px;border:1px solid #e5e5ea">' . FW_ATK_WIN_MIN . ' 分钟窗口内防火墙拦截次数达到阈值（限流 429 / 封禁名单 / 危险 IP 库 / 自动策略自动封禁均计入）</td></tr>'
        . '<tr><td style="padding:6px 12px;border:1px solid #e5e5ea">邮件内容</td><td style="padding:6px 12px;border:1px solid #e5e5ea">今日拦截与请求统计、事件最多的来源 IP Top5、最近事件摘要与处置建议</td></tr>'
        . '<tr><td style="padding:6px 12px;border:1px solid #e5e5ea">当前配置</td><td style="padding:6px 12px;border:1px solid #e5e5ea">阈值 <b>' . $th . '</b> 次 / ' . FW_ATK_WIN_MIN . ' 分钟，冷却 ' . max(5, (int)cfg('fw_atk_alert_cool', 30)) . ' 分钟</td></tr>'
        . '</table>';
    [$sent, $admins, $err] = mail_admins("【攻击告警·测试】{$site} 告警通道正常", mail_template('攻击告警 · 通道测试', $inner));
    return $sent > 0 ? [true, ''] : [false, $err !== '' ? $err : '无管理员邮箱或未配置 SMTP'];
}

/* ================= 自动策略引擎 ================= */

/** 自定义规则列表 */
function fw_rules_all(): array
{
    $r = Store::read('fw_rules.php', []);
    return is_array($r) ? $r : [];
}

function fw_rules_save(array $rules): void
{
    Store::write('fw_rules.php', $rules);
}

/** 单条自定义规则匹配（mode: regex 正则 / text 纯文本包含；type: ua/uri/query） */
function fw_rule_match(array $r, string $ua, string $uri, string $query): bool
{
    $type = (string)($r['type'] ?? 'ua');
    $subject = $type === 'uri' ? $uri : ($type === 'query' ? $query : $ua);
    if ($subject === '') {
        return false;
    }
    $pat = (string)($r['pattern'] ?? '');
    if ($pat === '') {
        return false;
    }
    if (($r['mode'] ?? 'text') === 'regex') {
        $ok = @preg_match('#' . str_replace('#', '\#', $pat) . '#i', $subject);
        return $ok === 1;
    }
    return stripos($subject, $pat) !== false;
}

/** 给某 IP 的 10 分钟风险窗口加分；达到阈值自动封禁；$deny=false 时不立即输出拦截页（供 404 页面内调用） */
function fw_score_add(string $ip, int $score, string $why, bool $deny = true): void
{
    if ($score <= 0 || (int)cfg('fw_score_on', 1) !== 1) {
        return;
    }
    $st = fw_state();
    if (!isset($st['ips'][$ip])) {
        $st['ips'][$ip] = ['c' => 0, 'f' => 0, 'l' => 0, 'ua' => '', 's' => 0, 'w' => 0, 'wl' => 0];
    }
    $w = intdiv(time(), 600);
    if ((int)($st['ips'][$ip]['w'] ?? 0) !== $w) {
        $st['ips'][$ip]['w'] = $w;
        $st['ips'][$ip]['s'] = 0;
    }
    $st['ips'][$ip]['s'] += $score;
    $st['ips'][$ip]['l'] = time();
    $now = (int)$st['ips'][$ip]['s'];
    fw_state_save($st);
    $th = max(20, (int)cfg('fw_score_threshold', 100));
    fw_event('score', $ip, $why, '风险分 +' . $score . '（窗口累计 ' . $now . '/' . $th . '）', $score);
    if ($now >= $th) {
        $hours = max(1, (int)cfg('fw_auto_ban_hours', 24));
        $ok = fw_ban($ip, $hours, '自动策略：' . $why . '（风险分 ' . $now . '）', 'auto');
        if ($ok) {
            log_action('fw_auto_ban', 'IP ' . $ip . ' 触发自动封禁 ' . $hours . ' 小时：' . $why . '（风险分 ' . $now . '）', 0, '系统');
            fw_event('auto_ban', $ip, $why, '自动封禁 ' . $hours . ' 小时', $now);
            fw_bump($ip, 'blocked'); // 自动封禁计入拦截统计（供攻击告警窗口与今日拦截数使用）
            if ($deny) {
                fw_deny_page(403, '检测到异常访问行为，您的 IP 已被自动限制访问', '原因：' . $why);
            }
        }
    }
}

/** 策略评估（每个放行的请求在限流之后调用） */
function fw_score_tick(string $ip): void
{
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $query = (string)($_SERVER['QUERY_STRING'] ?? '');
    $hits = [];
    $score = 0;
    if ((int)cfg('fw_r_empty_ua', 1) === 1 && trim($ua) === '') {
        $score += 40;
        $hits[] = '空 UA';
    }
    if ((int)cfg('fw_r_script_ua', 1) === 1 && trim($ua) !== ''
        && ((int)cfg('fw_spider_allow', 1) !== 1 || !preg_match(FW_RE_SPIDER, $ua))
        && preg_match(FW_RE_SCRIPT_UA, $ua)) {
        $score += 50;
        $hits[] = '脚本 UA';
    }
    if ((int)cfg('fw_r_scan_path', 1) === 1 && preg_match(FW_RE_SCAN, $uri)) {
        $score += 80;
        $hits[] = '敏感路径探测';
    }
    if ((int)cfg('fw_r_inject', 1) === 1 && ($query !== '' || $uri !== '') && (preg_match(FW_RE_INJECT, $query) || preg_match(FW_RE_INJECT, $uri))) {
        $score += 90;
        $hits[] = '注入 / 攻击特征';
    }
    // 伪造搜索引擎蜘蛛：声称是主流蜘蛛，但 PTR 反解验证不通过（每 IP 只验证一次，结果缓存 30 天；
    // 真蜘蛛 IP 必有官方 PTR，验证通过不扣分；无法验证（无 PTR / DNS 预算耗尽）不误判）
    if ((int)cfg('fw_r_fake_spider', 1) === 1 && trim($ua) !== '' && preg_match(FW_RE_SPIDER, $ua)) {
        if (fw_spider_verify($ip, $ua) === false) {
            $score += 80;
            $hits[] = '伪造搜索引擎蜘蛛（PTR 验证失败）';
        }
    }
    // 未知爬虫 UA：自称 bot / spider / crawler / 采集器等，但不在搜索引擎白名单（SEO 采集器 / 监控器等）
    if ((int)cfg('fw_r_bot_ua', 1) === 1 && trim($ua) !== ''
        && ((int)cfg('fw_spider_allow', 1) !== 1 || !preg_match(FW_RE_SPIDER, $ua))
        && preg_match(FW_RE_BOT_UA, $ua)) {
        $score += 25;
        $hits[] = '未知爬虫 UA';
    }
    // 敏感文件 / 安装残留探测：install.php、更新脚本、源码目录、数据目录、配置与打包文件等
    if ((int)cfg('fw_r_probe', 1) === 1 && preg_match(FW_RE_PROBE, $uri)) {
        $score += 50;
        $hits[] = '敏感文件探测';
    }
    // 伪造 Cloudflare 头：带 CF-Connecting-IP 但 TCP 对端不是 CF 边缘（未开反代模式时该头不应出现）
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ((int)cfg('fw_r_spoof_cf', 1) === 1 && (int)cfg('fw_trust_xff', 0) !== 1
        && trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '')) !== ''
        && !fw_is_cf_edge($remote)) {
        $score += 50;
        $hits[] = '伪造 CF-Connecting-IP 头';
    }
    // 超长请求：URL / 查询串 / UA 明显超出正常浏览器与论坛业务范围，多为扫描器特征
    if ((int)cfg('fw_r_long_req', 1) === 1
        && (strlen($uri) > 2048 || strlen($query) > 2048 || strlen($ua) > 512)) {
        $score += 30;
        $hits[] = '超长请求（URL/UA 异常）';
    }
    // 自定义规则
    $rules = fw_rules_all();
    foreach ($rules as $r) {
        if (!is_array($r) || empty($r['on'])) {
            continue;
        }
        if (fw_rule_match($r, $ua, $uri, $query)) {
            $name = (string)($r['name'] ?? '自定义规则');
            if (($r['action'] ?? 'score') === 'ban') {
                $hours = max(1, (int)($r['ban_hours'] ?? 24));
                if (fw_ban($ip, $hours, '规则「' . $name . '」命中', 'auto')) {
                    log_action('fw_auto_ban', 'IP ' . $ip . ' 命中规则「' . $name . '」→ 封禁 ' . $hours . ' 小时', 0, '系统');
                    fw_event('auto_ban', $ip, $name, '规则直接封禁 ' . $hours . ' 小时', 0);
                    fw_bump($ip, 'blocked'); // 自动封禁计入拦截统计（供攻击告警窗口与今日拦截数使用）
                    fw_deny_page(403, '访问行为触发了本站安全规则，您的 IP 已被限制访问', '规则：' . $name);
                }
                return;
            }
            $score += max(1, (int)($r['score'] ?? 10));
            $hits[] = $name;
        }
    }
    if ($score > 0) {
        fw_score_add($ip, $score, implode(' + ', $hits));
    }
}

/* ================= 防火墙事件日志 ================= */

/** 记录防火墙事件（data/logs/fw-日期.php；同 IP 同动作每分钟至多 2 条防刷爆；每天至多 2000 条） */
function fw_event(string $action, string $ip, string $rule, string $detail = '', int $score = 0): void
{
    try {
        $st = fw_state();
        $d = date('Y-m-d');
        if (!isset($st['days'][$d])) {
            $st['days'][$d] = ['req' => 0, 'blocked' => 0, 'bans' => 0, 'ev' => 0];
        }
        $st['days'][$d]['ev']++;
        if ((int)$st['days'][$d]['ev'] > 2000) {
            fw_state_save($st);
            return; // 每日上限
        }
        // 节流：同 IP 同动作每分钟至多 2 条（计数随状态落盘，跨请求生效）
        $m = intdiv(time(), 60);
        $k = $ip . '|' . $action;
        $slot = $st['ev'][$k] ?? null;
        if (is_array($slot) && (int)$slot['m'] === $m) {
            if ((int)$slot['c'] >= 2) {
                return;
            }
            $st['ev'][$k]['c']++;
        } else {
            $st['ev'][$k] = ['m' => $m, 'c' => 1];
        }
        fw_state_save($st);
        if (!is_dir(DATA_DIR . '/logs')) {
            Store::ensureDir('logs');
        }
        $entry = [
            't'      => time(),
            'ip'     => $ip,
            'ua'     => cut_str(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 80),
            'm'      => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
            'uri'    => cut_str((string)($_SERVER['REQUEST_URI'] ?? ''), 160),
            'act'    => $action,
            'rule'   => cut_str($rule, 60),
            'detail' => cut_str($detail, 160),
            'score'  => $score,
        ];
        $f = DATA_DIR . '/logs/fw-' . $d . '.php';
        $lk = Store::lock('fwlogs', 2);
        if (!is_file($f)) {
            @file_put_contents($f, DATA_GUARD);
        }
        @file_put_contents($f, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        Store::unlock($lk);
        // 每天首次写事件时顺带清理过期防火墙日志（保留天数与总量上限）
        $p = Store::read('fw_prune.php', []);
        if (!is_array($p) || (string)($p['day'] ?? '') !== $d) {
            Store::write('fw_prune.php', ['day' => $d]);
            fw_events_prune();
        }
    } catch (Throwable $t) {
        // 日志失败静默
    }
}

/** 列出防火墙日志文件（新→旧），形如 fw-2026-10-04.php */
function fw_event_files(): array
{
    $fs = array_values(array_filter(Store::scan('logs'), function ($f) {
        return (bool)preg_match('/^fw-\d{4}-\d{2}-\d{2}\.php$/', $f);
    }));
    rsort($fs);
    return $fs;
}

/** 读取某天防火墙事件（最新在前，IP 筛选 + 分页） */
function fw_events_read(string $date, int $per, int $page, string $qip, int &$total = 0): array
{
    $total = 0;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return [];
    }
    $f = DATA_DIR . '/logs/fw-' . $date . '.php';
    if (!is_file($f)) {
        return [];
    }
    $raw = (string)@file_get_contents($f);
    if (strpos($raw, DATA_GUARD) === 0) {
        $raw = substr($raw, strlen(DATA_GUARD));
    }
    $out = [];
    foreach (explode("\n", $raw) as $ln) {
        $ln = trim($ln);
        if ($ln === '') {
            continue;
        }
        $j = json_decode($ln, true);
        if (!is_array($j) || !isset($j['act'])) {
            continue;
        }
        if ($qip !== '' && (string)($j['ip'] ?? '') !== $qip) {
            continue;
        }
        $out[] = $j;
    }
    $out = array_reverse($out);
    $total = count($out);
    $off = max(0, $page - 1) * max(1, $per);
    return array_slice($out, $off, max(1, $per));
}

/** 防火墙日志清理：保留天数（默认 14）+ 总量 ≤ 5MB */
function fw_events_prune(): int
{
    $files = fw_event_files();
    $deleted = 0;
    $days = max(1, (int)cfg('fw_log_keep', 14));
    $cut = date('Y-m-d', time() - $days * 86400);
    foreach ($files as $f) {
        if (substr($f, 3, 10) < $cut && @unlink(Store::path('logs/' . $f))) {
            $deleted++;
        }
    }
    $total = 0;
    $sizes = [];
    foreach (fw_event_files() as $f) {
        $s = (int)@filesize(Store::path('logs/' . $f));
        $sizes[$f] = $s;
        $total += $s;
    }
    foreach (array_reverse(fw_event_files()) as $f) { // 最旧在前
        if ($total <= 5 * 1048576) {
            break;
        }
        if (isset($sizes[$f]) && @unlink(Store::path('logs/' . $f))) {
            $total -= $sizes[$f];
            $deleted++;
        }
    }
    return $deleted;
}

/* ================= IP 归属地（ip-api.com 免费批量接口，无需 key） ================= */

function fw_geo_cache_read(): array
{
    $g = Store::read('fw_geo.php', []);
    return is_array($g) ? $g : [];
}

/** 判定归属地缓存条目是否为空（历史版本曾把查询失败的行也缓存成全空条目，导致永久显示「未知」） */
function fw_geo_entry_empty(array $r): bool
{
    return (string)($r['c'] ?? '') === ''
        && (string)($r['r'] ?? '') === ''
        && (string)($r['city'] ?? '') === ''
        && (string)($r['isp'] ?? '') === '';
}

/** 取归属地缓存文案；未命中或空条目（视为未查询）返回 null */
function fw_geo_get(string $ip): ?array
{
    $g = fw_geo_cache_read();
    $r = $g[$ip] ?? null;
    if (!is_array($r) || fw_geo_entry_empty($r)) {
        return null;
    }
    return $r;
}

/** 归属地失败重试节流表：{ip: 最早可重试时间戳}，避免对失败 IP / 不可达数据源每页都反复请求 */
function fw_geo_miss_read(): array
{
    $m = Store::read('fw_geo_miss.php', []);
    return is_array($m) ? $m : [];
}

/** 归属地展示文案 */
function fw_geo_label(array $r): string
{
    $parts = array_filter([(string)($r['city'] ?? ''), (string)($r['r'] ?? ''), (string)($r['c'] ?? '')], function ($x) {
        return $x !== '';
    });
    $s = implode(' ', array_slice($parts, 0, 3));
    $isp = trim((string)($r['isp'] ?? ''));
    if ($isp !== '') {
        $s .= ' · ' . cut_str($isp, 16);
    }
    return $s !== '' ? $s : '未知';
}

/**
 * 批量查询归属地（每批 ≤ 60 个），结果并入缓存（保留最近 3000 条）
 * 主源：ip-api.com 免费批量接口（HTTP，免 key，cURL / file_get_contents 双通道）
 * 备源：主源整体不可用或全部失败时，逐个查询 ipwho.is（HTTPS，免 key，单请求最多 8 个）
 * 失败的 IP 不再写缓存（旧版会把失败行缓存成空条目 → 永久显示「未知」），改为记入节流表稍后自动重试
 * @return array {geo: {ip: {c,r,city,isp}}, msg: string} geo 仅含本次成功结果；msg 为给管理员的诊断提示（可为空）
 */
function fw_geo_lookup(array $ips): array
{
    $ips = array_values(array_filter($ips, function ($x) {
        return is_string($x) && filter_var($x, FILTER_VALIDATE_IP) !== false;
    }));
    if (!$ips) {
        return ['geo' => [], 'msg' => ''];
    }
    $ips = array_slice(array_unique($ips), 0, 60);
    $cache = fw_geo_cache_read();
    $miss = fw_geo_miss_read();
    $now = time();
    $need = [];
    foreach ($ips as $ip) {
        $c = $cache[$ip] ?? null;
        // 无缓存 / 已过期 / 全空条目（历史污染）→ 都需要（重新）查询
        if (!is_array($c) || fw_geo_entry_empty($c) || $now - (int)($c['ts'] ?? 0) > 30 * 86400) {
            if ((int)($miss[$ip] ?? 0) > $now) {
                continue; // 失败节流中，本次先不重试
            }
            $need[] = $ip;
        }
    }
    $msg = '';
    if ($need) {
        $okCount = 0;
        // 主源：ip-api.com 批量
        $body = fw_http_post('http://ip-api.com/batch?fields=query,status,country,regionName,city,isp&lang=zh-CN', json_encode($need), 15);
        $list = is_string($body) && $body !== '' ? json_decode($body, true) : null;
        if (is_array($list)) {
            foreach ($list as $row) {
                if (!is_array($row) || empty($row['query'])) {
                    continue;
                }
                $ip = (string)$row['query'];
                if ((string)($row['status'] ?? '') !== 'success') {
                    // 查询失败（保留段 / 限流等）：不缓存，30 分钟内不重试
                    $miss[$ip] = $now + 1800;
                    continue;
                }
                $cache[$ip] = [
                    'c'    => cut_str((string)($row['country'] ?? ''), 20),
                    'r'    => cut_str((string)($row['regionName'] ?? ''), 20),
                    'city' => cut_str((string)($row['city'] ?? ''), 20),
                    'isp'  => cut_str((string)($row['isp'] ?? ''), 30),
                    'ts'   => $now,
                ];
                $okCount++;
            }
        }
        if ($okCount === 0) {
            // 主源不可用（无 curl、外网不通、被限流等）→ 备源逐个查询，单请求最多 8 个避免拖慢页面
            $fb = 0;
            foreach (array_slice($need, 0, 8) as $ip) {
                $b = fw_http_get('https://ipwho.is/' . rawurlencode($ip), 10);
                $j = is_string($b) && $b !== '' ? json_decode($b, true) : null;
                if (!is_array($j) || empty($j['success'])) {
                    $miss[$ip] = $now + 1800;
                    continue;
                }
                $cache[$ip] = [
                    'c'    => cut_str((string)($j['country'] ?? ''), 20),
                    'r'    => cut_str((string)($j['region'] ?? ''), 20),
                    'city' => cut_str((string)($j['city'] ?? ''), 20),
                    'isp'  => cut_str((string)(is_array($j['connection'] ?? null) ? ($j['connection']['isp'] ?? '') : ''), 30),
                    'ts'   => $now,
                ];
                $okCount++;
                $fb++;
            }
            if ($okCount === 0) {
                foreach ($need as $ip) {
                    $miss[$ip] = $now + 3600;
                }
                $msg = '归属地数据源暂时连不上（服务器当前访问不了 ip-api.com / ipwho.is，请检查主机外网连通性），稍后再点一次即可';
            } else {
                $msg = '主源暂不可用，已用备用源查到 ' . $fb . ' 个（其余稍后自动补查）';
            }
        } elseif ($okCount < count($need)) {
            $msg = '查询完成：成功 ' . $okCount . ' 个，' . (count($need) - $okCount) . ' 个暂查不到（30 分钟后自动重试）';
        }
    }
    // 缓存上限
    if (count($cache) > 3000) {
        uasort($cache, function ($a, $b) {
            return (int)($b['ts'] ?? 0) <=> (int)($a['ts'] ?? 0);
        });
        $cache = array_slice($cache, 0, 3000, true);
    }
    $lk = Store::lock('fw_geo', 3);
    Store::write('fw_geo.php', $cache);
    Store::unlock($lk);
    // 失败节流表：清理已过期项，最多保留 300 条
    $missBefore = count($miss);
    $miss = array_filter($miss, function ($t) {
        return (int)$t > time();
    });
    if (count($miss) > 300) {
        arsort($miss);
        $miss = array_slice($miss, 0, 300, true);
    }
    if (count($miss) !== $missBefore || $miss) {
        $lk = Store::lock('fw_geo_miss', 3);
        Store::write('fw_geo_miss.php', $miss);
        Store::unlock($lk);
    }
    $out = [];
    foreach ($ips as $ip) {
        if (isset($cache[$ip]) && is_array($cache[$ip]) && !fw_geo_entry_empty($cache[$ip])) {
            $out[$ip] = $cache[$ip];
        }
    }
    return ['geo' => $out, 'msg' => $msg];
}

/* ================= 拦截页面 ================= */

/** 拒绝访问页（自带内联样式，不依赖站点资源） */
function fw_deny_page(int $code, string $msg, string $detail = ''): void
{
    if (!headers_sent()) {
        http_response_code($code);
        if ($code === 429) {
            header('Retry-After: 60');
        }
        header('Content-Type: text/html; charset=utf-8');
    }
    $site = cut_str((string)cfg('site_name', '本站'), 30);
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<meta name="robots" content="noindex"><title>' . ($code === 429 ? '请求过于频繁' : '访问受限') . '</title></head>';
    echo '<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f6f6f7;font:15px/1.8 system-ui,-apple-system,\'Segoe UI\',Roboto,\'PingFang SC\',\'Microsoft YaHei\',sans-serif;color:#1c1c1f">';
    echo '<div style="max-width:420px;margin:24px;padding:36px 32px;background:#fff;border-radius:14px;box-shadow:0 10px 40px -18px rgba(0,0,0,.25);text-align:center">';
    echo '<div style="font-size:44px;line-height:1">' . ($code === 429 ? '⏳' : '⛔') . '</div>';
    echo '<h1 style="font-size:18px;margin:14px 0 8px">' . e($msg) . '</h1>';
    if ($detail !== '') {
        echo '<p style="color:#75757e;margin:0 0 6px">' . e($detail) . '</p>';
    }
    if ($code === 403) {
        echo '<p style="color:#75757e;font-size:13px;margin:0">如果你是本站管理员，请通过服务器后台检查并解除封禁（后台 → 安全防护），或把你的 IP 加入防火墙白名单。</p>';
    } else {
        echo '<p style="color:#75757e;font-size:13px;margin:0">请稍后再试；如持续出现请联系站长。</p>';
    }
    echo '<p style="color:#a0a0a8;font-size:12px;margin:16px 0 0">' . e($site) . ' · Cube Minimalist Forum</p>';
    echo '</div></body></html>';
    exit;
}

/* ================= 入口守卫（bootstrap 调用） ================= */

/**
 * 防火墙入口：安全响应头 → 白名单 → 封禁 → 危险 IP 库 → 限流 → 自动策略
 * 未安装 / CLI / 拿不到 IP 时直接放行；总开关关闭时仅做统计。
 */
function fw_guard(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    fw_security_headers();
    if (!Store::exists('lock/install.lock')) {
        return; // 安装向导不设防
    }
    $ip = fw_ip();
    if ($ip === '') {
        return; // 拿不到合法 IP 不乱拦（极端环境兜底）
    }
    fw_bump($ip, 'c');
    // 白名单：跳过一切拦截
    if (fw_whitelisted($ip)) {
        $st = fw_state();
        if (isset($st['ips'][$ip])) {
            $st['ips'][$ip]['wl'] = 1;
            fw_state_save($st);
        }
        return;
    }
    // 已登录的管理员跳过拦截（避免管理操作被限流 / 策略误伤；访问统计照常记录）
    if (function_exists('current_user')) {
        $mu = current_user();
        if ($mu !== null && !empty($mu['admin'])) {
            return;
        }
    }
    if ((int)cfg('fw_on', 1) !== 1) {
        return;
    }
    // 1) 封禁名单
    $b = fw_is_banned($ip);
    if ($b !== null) {
        fw_bump($ip, 'blocked');
        $until = (int)($b['until'] ?? 0);
        $via = (string)($b['via'] ?? $ip);
        fw_event('block', $ip, '封禁名单（' . $via . '）', '封禁理由：' . (string)($b['reason'] ?? ''));
        fw_deny_page(403, '您的 IP 已被限制访问本站', '理由：' . (string)($b['reason'] ?? '未填写') . ($until > 0 ? '；解除时间：' . date('Y-m-d H:i', $until) : '；永久封禁'));
    }
    // 2) 危险 IP 库
    if ((int)cfg('fw_intel_on', 1) === 1 && fw_intel_hit($ip)) {
        fw_bump($ip, 'blocked');
        fw_event('intel_block', $ip, '危险 IP 库', '命中公开威胁情报黑名单');
        fw_deny_page(403, '您的 IP 位于公开威胁情报黑名单，已被拒绝访问', '如为误判，管理员可在后台「安全防护」将您的 IP 加入白名单。');
    }
    // 3) 限流
    if ((int)cfg('fw_rl_on', 1) === 1) {
        [$cnt, $pm] = fw_rl_add($ip);
        if ($cnt > $pm) {
            fw_bump($ip, 'blocked');
            $banMin = max(0, (int)cfg('fw_rl_ban_min', 0));
            if ($banMin > 0 && $cnt > $pm + max(10, intdiv($pm, 2))) { // 超限较多才升级为临时封禁
                if (fw_ban($ip, max(1, (int)ceil($banMin / 60)), '限流自动封禁：每分钟请求 ' . $cnt . ' 次（阈值 ' . $pm . '）', 'auto')) {
                    log_action('fw_auto_ban', 'IP ' . $ip . ' 超限自动封禁 ' . $banMin . ' 分钟', 0, '系统');
                }
                fw_deny_page(403, '请求过于频繁，您的 IP 已被临时封禁', '每分钟最多 ' . $pm . ' 次请求，本次 ' . $cnt . ' 次。');
            }
            fw_event('ratelimit', $ip, '限流', '每分钟 ' . $cnt . ' 次请求（阈值 ' . $pm . '）');
            fw_deny_page(429, '请求过于频繁，请稍后再试', '每分钟最多 ' . $pm . ' 次请求。');
        }
    }
    // 4) 自动策略评分
    if ((int)cfg('fw_score_on', 1) === 1) {
        fw_score_tick($ip);
    }
}

/* 统计已在每次计数时原子落盘（fw_state_save），无需请求结束兜底 */
