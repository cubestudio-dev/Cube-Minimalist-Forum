<?php
/**
 * 极简论坛 · 通用工具函数
 * 零依赖 · 纯 PHP · 无任何第三方库
 */
defined('APP') or exit('Forbidden');

/** HTML 转义输出 */
function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* ---------------- 演示网关适配 ----------------
 * 当通过带 XTransformPort 查询参数的网关访问时（沙箱演示环境），
 * 所有内部链接自动携带该参数以便网关转发。生产部署中无此参数，URL 保持干净。
 */
function demo_port(): string
{
    static $p = false;
    if ($p === false) {
        $v = $_GET['XTransformPort'] ?? ($_POST['XTransformPort'] ?? '');
        $p = is_string($v) && preg_match('/^\d{1,5}$/', $v) ? $v : '';
    }
    return $p;
}

/** 站内链接：u('p=thread&id=1') => index.php?p=thread&id=1 */
function u(string $qs = ''): string
{
    $url = 'index.php' . ($qs !== '' ? '?' . $qs : '');
    $p = demo_port();
    if ($p !== '') {
        $url .= (strpos($url, '?') === false ? '?' : '&') . 'XTransformPort=' . $p;
    }
    return $url;
}

/** 静态资源 / 跨入口链接（路径中可已含查询串） */
function ua(string $path): string
{
    $p = demo_port();
    if ($p === '') {
        return $path;
    }
    return $path . (strpos($path, '?') === false ? '?' : '&') . 'XTransformPort=' . $p;
}

/** 跳转（强制剥离换行符，杜绝任何输入拼入 Location 造成的响应拆分风险）
 *  v1.17.0：AJAX 表单提交时不再跳转，直接以 JSON 返回 Flash 消息——
 *  配合前端 form[data-ajax] 免刷新保存；所有动作共用此安全网，无需逐个改造 */
function redirect(string $url): void
{
    if (function_exists('is_ajax') && is_ajax()) {
        $f = take_flash();
        json_response([
            'ok'   => !$f || $f['t'] !== 'err',
            'msg'  => (string)($f['m'] ?? '操作完成'),
            'type' => (string)($f['t'] ?? 'ok'),
        ]);
    }
    header('Location: ' . str_replace(["\r", "\n", "\0"], '', (string)$url));
    exit;
}

/** 回到来源页（仅接受站内相对路径，且拒绝控制字符），否则回退。
 *  v1.28.0：POST['back'] 缺失时增加同源 Referer 兜底——后台表单几乎都不带 back 字段，
 *  此前动作出错一律回退到 $fallback（多数是前台首页），错误提示"跳到别的页面"展示；
 *  现在浏览器对同站表单提交自动携带 Referer，错误反馈回到提交前的当前页面。 */
function back_or(string $fallback): void
{
    $b = isset($_POST['back']) && is_string($_POST['back']) ? trim($_POST['back']) : '';
    $safe = $b !== '' && $b[0] === '/' && (!isset($b[1]) || $b[1] !== '/')
        && preg_match('#^/[A-Za-z0-9._~\-/?&=%:;\[\]@!$\'()*+,]+$#', $b);
    if ($safe) {
        redirect($b);
    }
    /* Referer 兜底：仅接受与本站同 host 的 index.php 入口（防开放跳转），query 同样过字符白名单；
       兼容子目录部署（保留 /index.php 前的目录前缀） */
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($ref !== '') {
        $rp = (string)(parse_url($ref, PHP_URL_PATH) ?: '');
        $rq = (string)(parse_url($ref, PHP_URL_QUERY) ?: '');
        $rh = parse_url($ref, PHP_URL_HOST);
        /* HTTP_HOST 含端口（如 localhost:3001 / 域名:8080），parse_url host 不含——比较前统一剥端口 */
        $myHost = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
        if (($rh === null || strcasecmp((string)$rh, $myHost) === 0)
            && str_ends_with($rp, '/index.php')
            && preg_match('#^[A-Za-z0-9._~\-/&=%+:;\[\]@!$\'()*+,]*$#', $rq)
            && $rq !== '' && str_contains($rq, 'p=')) {
            redirect(substr($rp, 0, -strlen('index.php')) . 'index.php?' . $rq);
        }
    }
    redirect($fallback);
}

/* ---------------- Flash 消息 ---------------- */
function flash(string $type, string $msg): void
{
    $_SESSION['flash'] = ['t' => $type, 'm' => $msg];
}

function take_flash(): ?array
{
    /* v1.32.0：会话早释放后渲染期取消息改走全局暂存（index.php 在关闭会话前暂存） */
    if (array_key_exists('MF_FLASH', $GLOBALS)) {
        $f = $GLOBALS['MF_FLASH'];
        unset($GLOBALS['MF_FLASH']);
        return is_array($f) && $f ? $f : null;
    }
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

function flash_render(): string
{
    $f = take_flash();
    if (!$f) {
        return '';
    }
    return '<div class="flash flash-' . e($f['t']) . '" role="alert">' . e($f['m']) . '</div>';
}

/* ---------------- CSRF ---------------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_ok(): bool
{
    $t = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($t) && $t !== '' && hash_equals(csrf_token(), $t);
}

function is_ajax(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
}

function json_response(array $d): void
{
    /* v1.23.0：会话缓存头接管后，JSON 数据端点显式声明不缓存
      （在线人数 / 未读数 / 监控读数 / 设置搜索索引等实时数据，杜绝浏览器启发式缓存） */
    header('Cache-Control: no-store');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------------- 表单取值 ---------------- */
function post_str(string $k, int $max = 0): string
{
    $v = isset($_POST[$k]) && is_string($_POST[$k]) ? trim($_POST[$k]) : '';
    if ($max > 0 && u_strlen($v) > $max) {
        $v = cut_str($v, $max);
    }
    return $v;
}

function get_int(string $k, int $def = 0): int
{
    return isset($_GET[$k]) && is_numeric($_GET[$k]) ? (int)$_GET[$k] : $def;
}

/* ---------------- 字符串（多字节安全） ---------------- */
function u_strlen(string $s): int
{
    if (function_exists('mb_strlen')) {
        return (int)mb_strlen($s, 'UTF-8');
    }
    $n = preg_match_all('/./us', $s);
    return $n === false ? strlen($s) : $n;
}

function cut_str(string $s, int $max): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $max, 'UTF-8');
    }
    preg_match_all('/./us', $s, $m);
    return implode('', array_slice($m[0] ?? [], 0, $max));
}

/** 小写化（mbstring 缺失时回退 strtolower，仅影响 ASCII 以外字符的大小写语义） */
function str_lower(string $s): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

/* ---------------- 校验 ---------------- */
function valid_name(string $n): bool
{
    /* v1.32.0：长度上限后台可自定义（2-30，默认 20）；正则字符集不变 */
    $max = max(2, min(30, (int)cfg('name_max', 20)));
    return (bool)preg_match('/^[\x{4e00}-\x{9fa5}A-Za-z0-9_]{2,' . $max . '}$/u', $n);
}

function valid_email(string $m): bool
{
    return (bool)filter_var($m, FILTER_VALIDATE_EMAIL);
}

/* ---------------- 表单旧值（校验失败回填） ---------------- */
function set_old(array $data): void
{
    $_SESSION['old'] = $data;
}

function old(string $k, string $def = ''): string
{
    $v = $_SESSION['old'][$k] ?? $def;
    return e(is_scalar($v) ? (string)$v : '');
}

function clear_old(): void
{
    unset($_SESSION['old']);
}

/* ---------------- 时间与体积 ---------------- */
function fmt_time(int $ts): string
{
    if ($ts <= 0) {
        return '-';
    }
    $d = time() - $ts;
    if ($d < 60) {
        return t('刚刚');
    }
    if ($d < 3600) {
        return t('{0} 分钟前', [(int)floor($d / 60)]);
    }
    if ($d < 86400) {
        return t('{0} 小时前', [(int)floor($d / 3600)]);
    }
    if ($d < 172800) {
        return t('昨天') . ' ' . date('H:i', $ts);
    }
    if (date('Y', $ts) === date('Y')) {
        return date('m-d H:i', $ts);
    }
    return date('Y-m-d', $ts);
}

function fmt_dt(int $ts): string
{
    return $ts > 0 ? date('Y-m-d H:i', $ts) : '-';
}

function fmt_bytes(int $n): string
{
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $v = (float)$n;
    while ($v >= 1024 && $i < count($u) - 1) {
        $v /= 1024;
        $i++;
    }
    return round($v, $i === 0 ? 0 : 1) . ' ' . $u[$i];
}

/** 递归统计目录体积；$skip 为路径前缀黑名单 */
function size_of_path(string $path, array $skip = []): int
{
    if (is_file($path)) {
        return (int)@filesize($path);
    }
    if (!is_dir($path)) {
        return 0;
    }
    $sum = 0;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $f) {
            if (!$f->isFile()) {
                continue;
            }
            $p = $f->getPathname();
            foreach ($skip as $s) {
                if (strpos($p, $s) === 0) {
                    continue 2;
                }
            }
            $sum += (int)$f->getSize();
        }
    } catch (Throwable $t) {
        return $sum;
    }
    return $sum;
}

/* ---------------- 分页 ---------------- */
function paginate(int $total, int $per, int $cur, string $qsBase, string $param = 'page'): string
{
    $pages = (int)ceil($total / max(1, $per));
    if ($pages <= 1) {
        return '';
    }
    $cur = max(1, min($pages, $cur));
    /* v1.16.0：$param 支持同页多个独立分页列表（如安全防护页的访问统计 ippage 与事件日志 fwpage），
       修复此前生成链接固定为 page= 与读取参数不一致导致的"点第 2 页仍停在第 1 页" */
    $item = function (int $n, string $label, bool $on = false, bool $dis = false) use ($qsBase, $param): string {
        if ($dis) {
            return '<span class="pg pg-dis">' . $label . '</span>';
        }
        return '<a class="pg' . ($on ? ' on' : '') . '" href="' . e(u($qsBase . '&' . $param . '=' . $n)) . '">' . $label . '</a>';
    };
    $html = '<nav class="pager" aria-label="分页">';
    $html .= $item($cur - 1, '‹', false, $cur <= 1);
    $win = [];
    for ($i = $cur - 2; $i <= $cur + 2; $i++) {
        if ($i >= 1 && $i <= $pages) {
            $win[] = $i;
        }
    }
    if ($win && $win[0] > 1) {
        $html .= $item(1, '1');
        if ($win[0] > 2) {
            $html .= '<span class="pg-dots">…</span>';
        }
    }
    foreach ($win as $n) {
        $html .= $item($n, (string)$n, $n === $cur);
    }
    if ($win && end($win) < $pages) {
        if (end($win) < $pages - 1) {
            $html .= '<span class="pg-dots">…</span>';
        }
        $html .= $item($pages, (string)$pages);
    }
    $html .= $item($cur + 1, '›', false, $cur >= $pages);
    return $html . '</nav>';
}

/* ---------------- 小组件 ---------------- */
function empty_state(string $msg): string
{
    return '<div class="empty"><span class="empty-ico">◦</span>' . e($msg) . '</div>';
}

function hidden_back(): string
{
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    // 仅保留安全的 URL 字符（防换行 / 控制字符进入回跳表单，再进 Location 头）
    $uri = preg_replace('/[^\x21-\x7e]/', '', $uri);
    return '<input type="hidden" name="back" value="' . e($uri !== '' ? $uri : u('p=home')) . '">';
}

/* ---------------- v1.35.0 性能体检 / SEO：服务器文件安全自改（同意式） ---------------- */

/** 把 $file 备份到 data/backup/<prefix>-<时间>.txt，返回备份绝对路径（失败返回 ''） */
function mf_text_backup(string $file, string $prefix): string
{
    $dir = DATA_DIR . '/backup';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $dest = $dir . '/' . $prefix . '-' . date('Ymd-His') . '.txt';
    return @copy($file, $dest) ? $dest : '';
}

/** robots.txt 推荐内容（显示与「一键同步」共用同一份来源，杜绝两处口径不一致）。
 *  $forSync=true 用于实际写盘：site_url 未配置为有效 http(s) 地址时省略 Sitemap 行，避免写入无效链接。 */
function mf_robots_recommended(bool $forSync = false): string
{
    $site = rtrim(trim((string)cfg('site_url', '')), '/');
    $ok = $site !== '' && stripos($site, 'http') === 0 && strpos($site, '你的域名') === false;
    $lines = [
        '# 极简论坛 robots.txt —— 控制爬虫抓取频率，保护后台与私有页面',
        'User-agent: *',
        'Crawl-delay: 10',
        'Disallow: /install.php',
        'Disallow: /index.php?p=admin',
        'Disallow: /index.php?p=login',
        'Disallow: /index.php?p=register',
        'Disallow: /index.php?p=settings',
        'Disallow: /index.php?p=notifications',
        'Disallow: /index.php?a=',
        'Allow: /',
        '',
        '# 数据目录（Apache 已由 .htaccess 拦截；nginx 请参考 README 配置）',
        'Disallow: /data/',
        'Disallow: /src/',
    ];
    if ($ok) {
        $lines[] = 'Sitemap: ' . $site . '/index.php?p=sitemap';
    } elseif (!$forSync) {
        /* 仅展示口径：site_url 无效时显示占位示例（展示层自行 e() 转义，这里输出原文） */
        $lines[] = 'Sitemap: ' . (string)cfg('site_url', 'https://你的域名') . '/index.php?p=sitemap';
    }
    return implode("\n", $lines) . "\n";
}

/** 服务器上的 robots.txt 是否已是推荐内容（兼容带/不带 Sitemap 行两种口径，忽略空行与行尾空白） */
function mf_robots_is_recommended(string $raw): bool
{
    $n = mf_robots_norm($raw);
    if ($n === '') {
        return false;
    }
    return $n === mf_robots_norm(mf_robots_recommended(true))
        || $n === mf_robots_norm(mf_robots_recommended(false));
}

/** robots.txt 比较口径：忽略行尾空白与空行差异，仅比较有效行 */
function mf_robots_norm(string $raw): string
{
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $ln) {
        $ln = rtrim($ln);
        if ($ln !== '') {
            $out[] = $ln;
        }
    }
    return implode("\n", $out);
}

/** v1.35.1 合并后的 .user.ini 内容：「追加」动作实际写入的内容与「下载合并文件」降级方案共用同一来源，
 *  杜绝两处口径不一致。$raw = 原文件内容（不存在 / 不可读时传 ''）；
 *  原内容保留 + OPcache 标记块；原内容为空时返回与「一键创建」完全相同的模板。
 *  注意：标记块 / 注释头字符串与 act_admin_opcache_ini 的「还原（del）」逐行移除逻辑严格对应，勿单独改动。 */
function mf_userini_merged(string $raw = ''): string
{
    if (trim($raw) === '') {
        return "; Minimal Forum v" . app_version() . " 性能体检：开启 OPcache 字节码缓存\n"
            . "; LiteSpeed / CGI / FastCGI / FPM 生效；Apache mod_php 忽略此文件（需主机商在 php.ini 开启）\n"
            . "opcache.enable=1\nopcache.revalidate_freq=2\n";
    }
    return $raw . "\n; ---- Minimal Forum OPcache（性能体检追加 v" . app_version() . "）----\n"
        . "opcache.enable=1\nopcache.revalidate_freq=2\n"
        . "; ---- Minimal Forum OPcache 追加结束 ----\n";
}

/** v1.35.1 站点根目录写入权限判定（.user.ini / robots.txt 共用）：
 *  文件存在时看文件本身是否可写（属主 / 权限），并兜底看所在目录（目录可写即可 unlink 后重建）；
 *  文件不存在时只看目录。返回 [文件可写, 目录可写]。 */
function mf_root_write_check(string $file): array
{
    $root = dirname($file);
    $rootW = is_writable($root);
    $fileW = is_file($file) ? is_writable($file) : $rootW;
    return [$fileW, $rootW];
}

/** v1.35.1 以附件形式下发文本内容（站点根目录不可写时的降级方案：
 *  下载合并 / 推荐内容文件，由管理员通过 FTP / 主机面板上传到站点根目录） */
function mf_text_download(string $content, string $name): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . strlen($content));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo $content;
    exit;
}
