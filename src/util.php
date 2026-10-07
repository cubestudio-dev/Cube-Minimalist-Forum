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

/** 回到来源页（仅接受站内相对路径，且拒绝控制字符），否则回退 */
function back_or(string $fallback): void
{
    $b = isset($_POST['back']) && is_string($_POST['back']) ? trim($_POST['back']) : '';
    $safe = $b !== '' && $b[0] === '/' && (!isset($b[1]) || $b[1] !== '/')
        && preg_match('#^/[A-Za-z0-9._~\-/?&=%:;\[\]@!$\'()*+,]+$#', $b);
    if ($safe) {
        redirect($b);
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
    return (bool)preg_match('/^[\x{4e00}-\x{9fa5}A-Za-z0-9_]{2,20}$/u', $n);
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
        return '刚刚';
    }
    if ($d < 3600) {
        return floor($d / 60) . ' 分钟前';
    }
    if ($d < 86400) {
        return floor($d / 3600) . ' 小时前';
    }
    if ($d < 172800) {
        return '昨天 ' . date('H:i', $ts);
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
