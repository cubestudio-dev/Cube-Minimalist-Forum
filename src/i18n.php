<?php
/**
 * 极简论坛 · 多语言内核（v1.21.0）
 *
 * 设计：
 *  - 以「简体中文原文」为词条键：t('登录') 未命中词条时原样返回 ——
 *    任何未翻译的界面自动降级为简中，绝不出现裸词条键，升级零风险；
 *  - 语言包位于 src/lang/{lang}.php，返回 ['简中原文' => '译文']；
 *  - 生效顺序：用户主动切换（会话 / Cookie）> 用户资料偏好 > 按 IP 自动判断（后台可开）
 *    > 后台默认语言 > 简体中文；
 *  - IP 判断走 ip-api.com countryCode 字段（结果缓存 30 天，失败缓存 1 天，仅提交 IP 本身）；
 *  - 命令行（daemon.php 等）恒为默认语言。
 */
defined('APP') or exit('Forbidden');

/** 支持的语言（代码 => 显示名） */
const MF_LANGS = [
    'zh-cn' => '简体中文',
    'zh-tw' => '繁體中文',
    'en'    => 'English',
    'ja'    => '日本語',
    'ru'    => 'Русский',
];

/** 语言代码合法性 */
function is_lang(string $l): bool
{
    return isset(MF_LANGS[$l]);
}

/** 国家代码 → 语言代码（IP 自动判断映射表） */
function lang_by_country(string $cc): string
{
    $cc = strtoupper(trim($cc));
    if ($cc === '') {
        return 'zh-cn';
    }
    if (in_array($cc, ['CN', 'SG', 'MY'], true)) {
        return 'zh-cn';
    }
    if (in_array($cc, ['TW', 'HK', 'MO'], true)) {
        return 'zh-tw';
    }
    if ($cc === 'JP') {
        return 'ja';
    }
    if (in_array($cc, ['RU', 'BY', 'KZ', 'KG', 'UZ', 'TM', 'TJ', 'AM', 'AZ', 'MD'], true)) {
        return 'ru';
    }
    return 'en';
}

/** 当前请求的语言代码（i18n_init() 解析结果） */
function i18n_active(): string
{
    return $GLOBALS['I18N_LANG'] ?? 'zh-cn';
}

/** <html lang> 值 */
function lang_html(): string
{
    return i18n_active();
}

/** 语言包装载（zh-cn 即原文，无需文件） */
function i18n_dict(): array
{
    static $d = null;
    if (is_array($d)) {
        return $d;
    }
    $d = [];
    $lang = i18n_active();
    if ($lang !== 'zh-cn') {
        $file = __DIR__ . '/lang/' . $lang . '.php';
        if (is_file($file)) {
            $loaded = include $file;
            if (is_array($loaded)) {
                $d = $loaded;
            }
        }
    }
    return $d;
}

/**
 * 翻译：t('登录')；带占位符：t('{0} 分钟前', [5]) → 「5 分钟前」/「5 minutes ago」。
 * 未命中语言包时原样返回简中原文（安全降级）。
 */
function t(string $s, array $r = []): string
{
    $d = i18n_dict();
    $v = $d[$s] ?? $s;
    foreach ($r as $i => $rep) {
        $v = str_replace(['{' . $i . '}', '{ ' . $i . ' }'], (string)$rep, $v);
    }
    return $v;
}

/** IP → 国家代码（独立轻量缓存 data/lang_geo.php；仅供语言自动判断，仅提交 IP 本身） */
function lang_geo_country(string $ip): string
{
    $ip = trim($ip);
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)
        || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return '';
    }
    $c = Store::read('lang_geo.php', []);
    $hit = is_array($c) ? ($c[$ip] ?? null) : null;
    if (is_array($hit)) {
        $ttl = !empty($hit['ok']) ? 30 * 86400 : 86400;
        if ((int)($hit['t'] ?? 0) > time() - $ttl) {
            return (string)($hit['cc'] ?? '');
        }
    }
    $cc = '';
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => [
            'timeout' => 3,
            'header'  => "User-Agent: MinimalForum/" . MF_VERSION . "\r\nConnection: close\r\n",
        ]]);
        $raw = @file_get_contents(
            'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,countryCode',
            false,
            $ctx
        );
        if (is_string($raw) && $raw !== '') {
            $j = json_decode($raw, true);
            if (is_array($j) && ($j['status'] ?? '') === 'success') {
                $cc = strtoupper(trim((string)($j['countryCode'] ?? '')));
            }
        }
    }
    $lk = Store::lock('lang_geo');
    $c = Store::read('lang_geo.php', []);
    if (!is_array($c)) {
        $c = [];
    }
    $c[$ip] = ['cc' => $cc, 'ok' => $cc !== '' ? 1 : 0, 't' => time()];
    if (count($c) > 2000) { // 超限按时间淘汰一半
        uasort($c, function ($a, $b) {
            return (int)($a['t'] ?? 0) <=> (int)($b['t'] ?? 0);
        });
        $c = array_slice($c, (int)(count($c) / 2), null, true);
    }
    Store::write('lang_geo.php', $c);
    Store::unlock($lk);
    return $cc;
}

/** 客户端真实 IP（与防火墙同源逻辑的轻量版，仅用于语言判断） */
function lang_client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        $v = (string)($_SERVER[$k] ?? '');
        if ($v !== '') {
            $v = trim(explode(',', $v)[0]);
            if (filter_var($v, FILTER_VALIDATE_IP)) {
                return $v;
            }
        }
    }
    return '';
}

/**
 * 语言解析（bootstrap 末尾调用一次）：
 * 会话/Cookie（用户主动切换）> 用户资料偏好 > IP 自动判断（后台开启时）> 后台默认语言。
 * ?lang=xx 显式携带时立即写入会话与 Cookie（语言切换动作与下拉均使用）。
 */
function i18n_init(): void
{
    $lang = '';
    /* 0) URL 显式切换（跟随请求立即生效，会话 + Cookie 双写） */
    if (isset($_GET['lang']) && is_string($_GET['lang']) && is_lang($_GET['lang'])) {
        $lang = $_GET['lang'];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['lang'] = $lang;
        }
        if (!headers_sent()) {
            setcookie('mf_lang', $lang, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true]);
        }
    }
    /* 1) 会话 / Cookie（用户主动选择，优先级最高） */
    if ($lang === '' && session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['lang']) && is_lang((string)$_SESSION['lang'])) {
        $lang = (string)$_SESSION['lang'];
    }
    if ($lang === '' && isset($_COOKIE['mf_lang']) && is_lang((string)$_COOKIE['mf_lang'])) {
        $lang = (string)$_COOKIE['mf_lang'];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['lang'] = $lang; // Cookie 命中后回填会话，减少依赖
        }
    }
    /* 2) 用户资料偏好 */
    if ($lang === '' && function_exists('current_user')) {
        $u = current_user();
        if (is_array($u) && isset($u['lang']) && is_lang((string)$u['lang'])) {
            $lang = (string)$u['lang'];
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['lang'] = $lang;
            }
        }
    }
    /* 3) 按 IP 自动判断（后台开启时；仅对未主动选择的访客） */
    if ($lang === '' && (int)cfg('lang_auto_ip', 0) === 1 && PHP_SAPI !== 'cli') {
        $cc = lang_geo_country(lang_client_ip());
        if ($cc !== '') {
            $lang = lang_by_country($cc);
        }
    }
    /* 4) 后台默认语言 */
    if ($lang === '') {
        $def = (string)cfg('lang_default', 'zh-cn');
        $lang = is_lang($def) ? $def : 'zh-cn';
    }
    $GLOBALS['I18N_LANG'] = $lang;
}
