<?php
/**
 * 极简论坛 · v1.36.0 HTML 静态加速引擎
 *
 * 原理：游客看到的「首页 / 版块页 / 帖子页」是公开且完全相同的内容——把这些页面
 * 预先渲染成 .html 快照存进 data/static/g/，游客访问时直接返回快照文件，
 * 跳过全部内容文件 I/O 与页面渲染（慢盘共享主机上这是加载耗时的大头）。
 *
 * 双通道：
 *  · PHP 快速通道（零配置，任何服务器自动生效）——index.php 在渲染前检查快照，
 *    命中则直接吐文件，仍享有既有的 gzip / ETag / 304 / 统计 / 防火墙全套链路；
 *  · Apache 加速块（可选增项）——同意式写入站点根目录 .htaccess 的标记重写块，
 *    命中快照时 Apache 直接发送文件，PHP 完全不启动（LiteSpeed 同样识别）。
 *
 * 安全边界（硬性）：
 *  · 仅缓存「游客可见的公开页」；登录用户（mf_ul cookie）永远走 PHP 实时渲染；
 *  · 仅当「游客可浏览」开启且「协议访问门禁」关闭时工作（Apache 直出无法执行
 *    协议门禁判定，门禁开启即自动清空快照并拒绝开启）；
 *  · 快照内不含任何 CSRF 令牌（游客页面本就没有令牌表单，meta 标记亦被清空）；
 *  · 私密论坛（关闭游客浏览）开启瞬间自动清空全部快照。
 *
 * 保鲜：发帖 / 回帖 / 编辑 / 删除等写入自动删除对应快照（下次访问重新生成）；
 *       快照另有保鲜期（默认 6 小时），到期后由 PHP 全渲染并顺带重写。
 */
defined('APP') or exit('Forbidden');

const MFS_MARK_BEGIN = '# BEGIN Minimal Forum Static HTML';
const MFS_MARK_END = '# END Minimal Forum Static HTML';

/** 当前模式：'php'=PHP 动态优先（默认） | 'html'=HTML 静态优先 */
function mfs_mode(): string
{
    return (string)cfg('static_mode', 'php') === 'html' ? 'html' : 'php';
}

/** 是否处于 HTML 静态优先模式 */
function mfs_mode_on(): bool
{
    return mfs_mode() === 'html';
}

/** 功能可用前提：游客可浏览 + 协议访问门禁关闭（Apache 直出无法执行门禁判定） */
function mfs_capable(): bool
{
    return feat_on('guest_browse') && (int)cfg('doc_gate', 0) !== 1;
}

/** 引擎整体是否生效（静态优先 + 前提满足） */
function mfs_enabled(): bool
{
    return mfs_mode_on() && mfs_capable();
}

/** 快照保鲜期（秒），默认 6 小时 */
function mfs_ttl(): int
{
    return max(300, (int)cfg('static_ttl', 21600));
}

/** 快照目录 */
function mfs_dir(): string
{
    return DATA_DIR . '/static/g';
}

/** 确保快照目录就绪（含父级 data/static/ 的 Apache 放行文件）；就绪返回 true */
function mfs_dir_ready(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $d = mfs_dir();
    if (!is_dir($d)) {
        if (!@mkdir($d, 0775, true) && !is_dir($d)) {
            return $ok = false;
        }
        /* data/.htaccess 拒绝一切 Web 访问；快照必须能被 Apache 直接读取，
           在 data/static/ 层放行（仅此子树，不影响 data/ 其余敏感文件的拦截） */
        $g = DATA_DIR . '/static/.htaccess';
        if (!is_file($g)) {
            @file_put_contents($g, "<IfModule mod_authz_core.c>\n  Require all granted\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Allow from all\n</IfModule>\n");
        }
    }
    return $ok = is_dir($d) && is_writable($d);
}

/** 目标页 → 快照文件绝对路径（不在缓存范围返回 ''） */
function mfs_file(string $p, int $id): string
{
    if ($p === 'home') {
        return mfs_dir() . '/index.html';
    }
    if ($p === 'board' && $id > 0) {
        return mfs_dir() . '/board-' . $id . '.html';
    }
    if ($p === 'thread' && $id > 0) {
        return mfs_dir() . '/thread-' . $id . '.html';
    }
    return '';
}

/**
 * 请求参数 → 可缓存目标 ['p'=>..., 'id'=>...]（严格白名单：出现任何白名单外
 * 参数即视为个性化 / 不可缓存）。$ignore 为额外忽略的参数键（预生成请求用）。
 */
function mfs_cacheable(array $get, array $ignore = []): ?array
{
    $skip = array_merge(['XTransformPort'], $ignore);
    $p = isset($get['p']) && is_string($get['p']) ? preg_replace('/[^a-z_]/', '', $get['p']) : 'home';
    if ($p === '' || $p === 'home') {
        foreach ($get as $k => $v) {
            if (in_array($k, $skip, true) || $k === 'p' || ($k === 'tab' && $v === 'new') || ($k === 'page' && is_scalar($v) && (string)$v === '1')) {
                continue;
            }
            return null;
        }
        return ['p' => 'home', 'id' => 0];
    }
    if ($p === 'board' || $p === 'thread') {
        $id = isset($get['id']) && is_scalar($get['id']) && is_numeric($get['id']) ? (int)$get['id'] : 0;
        if ($id <= 0) {
            return null;
        }
        foreach ($get as $k => $v) {
            if (in_array($k, $skip, true) || $k === 'p' || ($k === 'id' && is_scalar($v) && (string)(int)$v === (string)$v)
                || ($p === 'board' && $k === 'page' && is_scalar($v) && (string)$v === '1')) {
                continue;
            }
            return null;
        }
        return ['p' => $p, 'id' => $id];
    }
    return null;
}

/** 当前请求是否应把渲染结果存为快照（游客 + GET + 静态优先 + 白名单目标） */
function mfs_should_capture(): bool
{
    if (!mfs_enabled() || defined('MF_STATIC_GEN') || !mfs_dir_ready()) {
        return false;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return false;
    }
    if (isset($_GET['a']) || isset($_GET['api']) || isset($_GET['fresh']) || isset($_GET['__sgen'])) {
        return false;
    }
    if (current_user()) {
        return false; /* 登录用户永远实时渲染 */
    }
    return mfs_cacheable($_GET) !== null;
}

/** 渲染前调用（index.php 页面分发前）：开启输出捕获 */
function mfs_capture_start(): void
{
    ob_start();
}

/** 页面分发后调用：把捕获到的完整 HTML 存为快照（仅 200 的完整 HTML 页） */
function mfs_capture_finish(string $p, int $id): void
{
    $html = @ob_get_clean();
    if (is_string($html) && $html !== '') {
        mfs_save($p, $id, $html);
    }
}

/** 保存快照：剥离会话级 meta 令牌 + 原子写入 + 生成时间标记 */
function mfs_save(string $p, int $id, string $html): bool
{
    $f = mfs_file($p, $id);
    if ($f === '' || trim($html) === '' || stripos(ltrim(substr($html, 0, 60)), '<!doctype html') !== 0) {
        return false;
    }
    $rc = http_response_code(); /* CLI 下未设置时返回 false，等同默认 200 */
    if ($rc !== false && (int)$rc !== 200) {
        return false; /* 404 / 302 等绝不缓存 */
    }
    /* 快照仅面向游客（游客页面无任何令牌表单）；清空 meta 令牌防止跨会话误用 */
    $html = preg_replace('/(<meta\s+name="csrf"\s+content=")[^"]*(")/i', '$1$2', $html) ?? $html;
    $tag = '<!-- MF-STATIC ' . MF_VERSION . ' ' . date('Y-m-d H:i') . " -->\n";
    $pos = strripos($html, '</body>');
    $html = $pos !== false ? substr($html, 0, $pos) . $tag . substr($html, $pos) : $html . $tag;
    $tmp = $f . '.tmp' . getmypid();
    if (@file_put_contents($tmp, $html) === false) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $f)) {
        @unlink($tmp);
        return false;
    }
    @touch($f);
    return true;
}

/**
 * PHP 快速通道：index.php 在页面分发前调用。命中游客 + GET + 白名单目标 + 新鲜快照
 * 时直接输出快照文件并结束——内容 I/O 与渲染全部跳过；gzip / ETag / 304 / 命中日志 /
 * 在线统计等既有链路照常工作（输出仍经过 index.php 顶层缓冲处理器）。
 */
function mfs_serve(): void
{
    if (!mfs_enabled() || defined('MF_STATIC_GEN') || !mfs_dir_ready()) {
        return;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
        || isset($_GET['a']) || isset($_GET['api']) || isset($_GET['fresh']) || isset($_GET['__sgen'])) {
        return;
    }
    if (current_user()) {
        return;
    }
    $tgt = mfs_cacheable($_GET);
    if (!$tgt) {
        return;
    }
    $f = mfs_file($tgt['p'], $tgt['id']);
    if ($f === '' || !is_file($f)) {
        return;
    }
    if (time() - (int)@filemtime($f) > mfs_ttl()) {
        return; /* 过期：走 PHP 全渲染，渲染后按需重写快照 */
    }
    $raw = @file_get_contents($f);
    if (!is_string($raw) || $raw === '') {
        return;
    }
    header('X-MF-Static: php');
    header('Vary: Cookie');
    echo $raw;
    exit;
}

/* ---------------- 失效与清理 ---------------- */

/** 内容写入后失效对应快照（静态优先关闭时零开销：仅一次内存配置读取） */
function mfs_invalidate(int $tid = 0, int $bid = 0, bool $home = true): void
{
    if (!mfs_mode_on()) {
        return;
    }
    $d = mfs_dir();
    if ($home) {
        @unlink($d . '/index.html');
    }
    if ($tid > 0) {
        @unlink($d . '/thread-' . $tid . '.html');
    }
    if ($bid > 0) {
        @unlink($d . '/board-' . $bid . '.html');
    }
}

/** 清空全部快照，返回删除数量 */
function mfs_clear_all(): int
{
    $d = mfs_dir();
    $n = 0;
    foreach ((glob($d . '/*.html') ?: []) as $f) {
        $base = basename((string)$f);
        /* 防御：仅删除快照命名模式内的文件 */
        if (preg_match('/^(index|board-[0-9]+|thread-[0-9]+)\.html$/', $base) && @unlink((string)$f)) {
            $n++;
        }
    }
    return $n;
}

/** 快照状态统计：[页数, 总字节, 最近生成时间] */
function mfs_stats(): array
{
    $d = mfs_dir();
    $n = 0;
    $bytes = 0;
    $last = 0;
    foreach ((glob($d . '/*.html') ?: []) as $f) {
        if (!preg_match('/^(index|board-[0-9]+|thread-[0-9]+)\.html$/', basename((string)$f))) {
            continue;
        }
        $n++;
        $bytes += (int)@filesize((string)$f);
        $last = max($last, (int)@filemtime((string)$f));
    }
    return [$n, $bytes, $last];
}

/* ---------------- .htaccess 加速块（同意式 · 可回滚 · 幂等） ---------------- */

/** 标记块内容（绝对路径按安装时环境烘焙；站点搬家后请重新写入） */
function mfs_htaccess_block(): string
{
    $abs = mfs_dir();
    $rel = 'data/static/g';
    $L = [];
    $L[] = MFS_MARK_BEGIN . ' (v' . MF_VERSION . ') 游客静态快照直出——登录用户 / POST / 无快照自动回退 PHP，请勿手工编辑本块';
    $L[] = '<IfModule mod_rewrite.c>';
    $L[] = 'RewriteEngine On';
    $grp = function (string $qs, string $file, string $rule) use (&$L, $abs, $rel): void {
        $L[] = 'RewriteCond %{HTTP_COOKIE} !(^|;\s*)mf_ul=1';
        $L[] = 'RewriteCond %{REQUEST_METHOD} =GET';
        $L[] = 'RewriteCond %{QUERY_STRING} !(__sgen=|fresh=1)';
        $L[] = 'RewriteCond %{QUERY_STRING} ' . $qs;
        $L[] = 'RewriteCond ' . $abs . '/' . $file . ' -f';
        $L[] = 'RewriteRule ' . $rule . ' ' . $rel . '/' . $file . ' [L]';
    };
    $grp('(^|&)p=thread&id=([0-9]+)(&|$)', 'thread-%2.html', '^index\.php$');
    $grp('(^|&)p=board&id=([0-9]+)(&|$)', 'board-%2.html', '^index\.php$');
    $grp('^(p=home(&tab=new)?|tab=new)?$', 'index.html', '^(index\.php)?$');
    $L[] = '</IfModule>';
    $L[] = '<IfModule mod_headers.c>';
    $L[] = '<FilesMatch "\.html$">';
    $L[] = '  Header set Cache-Control "no-cache, must-revalidate"';
    $L[] = '</FilesMatch>';
    $L[] = '</IfModule>';
    $L[] = MFS_MARK_END;
    return implode("\n", $L);
}

/** .htaccess 标记块状态：installed=已安装 | absent=未安装 | none=无 .htaccess */
function mfs_htaccess_status(): string
{
    $f = dirname(DATA_DIR) . '/.htaccess';
    $raw = is_file($f) ? (string)@file_get_contents($f) : '';
    if ($raw === '') {
        return 'none';
    }
    return strpos($raw, MFS_MARK_BEGIN) !== false ? 'installed' : 'absent';
}

/** 安装 / 更新（幂等）：先备份，再替换或追加标记块。成功 true；失败 $err 带原因 */
function mfs_htaccess_install(string &$err = ''): bool
{
    $err = '';
    $f = dirname(DATA_DIR) . '/.htaccess';
    [$fileW, $rootW] = mf_root_write_check($f);
    if (!$fileW && !$rootW) {
        $err = '站点根目录对 PHP 不可写';
        return false;
    }
    $raw = is_file($f) ? (string)@file_get_contents($f) : '';
    if (strpos($raw, MFS_MARK_BEGIN) !== false) {
        /* 幂等：整块替换（绝对路径随当前环境更新） */
        $out = (string)preg_replace('/' . preg_quote(MFS_MARK_BEGIN, '/') . '.*?' . preg_quote(MFS_MARK_END, '/') . '\s*/s', '', $raw);
    } else {
        $out = $raw;
    }
    if (trim($raw) !== '') {
        if (mf_text_backup($f, 'htaccess-bak') === '') {
            $err = '备份失败（data/backup/ 不可写），已取消修改';
            return false;
        }
    }
    $new = rtrim($out) . "\n\n" . mfs_htaccess_block() . "\n";
    if (@file_put_contents($f, $new) === false) {
        /* v1.35.1 同款兜底：文件本身不可写但目录可写 → 删旧重建（unlink 只需目录写权限） */
        if (!is_file($f) || !@unlink($f) || @file_put_contents($f, $new) === false) {
            $err = '写入 .htaccess 失败（权限不足）';
            return false;
        }
    }
    return true;
}

/** 移除标记块：仅剥离块内内容，其余原样保留；文件因此变空才整体删除 */
function mfs_htaccess_remove(string &$err = ''): bool
{
    $err = '';
    $f = dirname(DATA_DIR) . '/.htaccess';
    if (!is_file($f)) {
        return true;
    }
    $raw = (string)@file_get_contents($f);
    if (strpos($raw, MFS_MARK_BEGIN) === false) {
        return true; /* 本来就没有，幂等成功 */
    }
    [$fileW, $rootW] = mf_root_write_check($f);
    if (!$fileW && !$rootW) {
        $err = '站点根目录对 PHP 不可写，请通过 FTP 编辑 .htaccess 删除标记块之间的内容';
        return false;
    }
    $out = trim((string)preg_replace('/' . preg_quote(MFS_MARK_BEGIN, '/') . '.*?' . preg_quote(MFS_MARK_END, '/') . '\s*/s', '', $raw));
    if (mf_text_backup($f, 'htaccess-bak') === '') {
        $err = '备份失败（data/backup/ 不可写），已取消修改';
        return false;
    }
    if ($out === '') {
        if (!@unlink($f)) {
            $err = '删除空 .htaccess 失败（权限不足）';
            return false;
        }
        return true;
    }
    if (@file_put_contents($f, $out . "\n") === false) {
        if (!@unlink($f) || @file_put_contents($f, $out . "\n") === false) {
            $err = '写入 .htaccess 失败（权限不足）';
            return false;
        }
    }
    return true;
}

/* ---------------- 管理员预生成（强制游客视角渲染） ---------------- */

/** 全站预生成目标清单：首页 + 全部版块 + 全部帖子（按最近活跃排序） */
function mfs_gen_list(): array
{
    $out = [['p' => 'home', 'id' => 0]];
    foreach (board_all() as $b) {
        $out[] = ['p' => 'board', 'id' => (int)$b['id']];
    }
    $ts = thread_index();
    usort($ts, function ($a, $b) {
        return (int)($b['last_reply'] ?? $b['created'] ?? 0) <=> (int)($a['last_reply'] ?? $a['created'] ?? 0);
    });
    foreach ($ts as $t) {
        $out[] = ['p' => 'thread', 'id' => (int)($t['id'] ?? 0)];
    }
    return $out;
}

/** 以游客视角渲染单个目标页（管理员预生成专用；不影响浏览量 / 视图日志） */
function mfs_gen_render(array $tgt): string
{
    $savedGet = $_GET;
    $ng = ['p' => $tgt['p']];
    if ($tgt['id'] > 0) {
        $ng['id'] = $tgt['id'];
    }
    $port = function_exists('demo_port') ? demo_port() : '';
    if ($port !== '') {
        $ng['XTransformPort'] = $port;
    }
    $_GET = $ng;
    /* 强制游客身份：current_user() 对该全局键优先（见 auth.php），渲染完毕即还原 */
    $GLOBALS['MF_USER_OVERRIDE'] = null;
    $GLOBALS['MF_FLASH'] = null;
    if (!defined('MF_STATIC_GEN')) {
        define('MF_STATIC_GEN', 1);
    }
    ob_start();
    try {
        switch ($tgt['p']) {
            case 'home':
                page_home();
                break;
            case 'board':
                page_board();
                break;
            case 'thread':
                page_thread();
                break;
        }
    } catch (Throwable $e) {
        error_log('[MFS] 预生成渲染异常 p=' . $tgt['p'] . ' id=' . $tgt['id'] . '：' . $e->getMessage());
    }
    $html = (string)ob_get_clean();
    unset($GLOBALS['MF_USER_OVERRIDE'], $GLOBALS['MF_FLASH']);
    $_GET = $savedGet;
    return $html;
}

/** 预生成端点（index.php 最前段调用）：管理员 + CSRF 头，单页渲染并落快照，返回 JSON */
function mfs_gen_handle(): void
{
    $u = current_user();
    if (!$u || empty($u['admin'])) {
        json_response(['ok' => false, 'msg' => '需要管理员身份']);
    }
    $t = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($t === '' || !hash_equals(csrf_token(), $t)) {
        json_response(['ok' => false, 'msg' => 'CSRF 校验失败，请刷新后台后重试']);
    }
    if (!mfs_mode_on()) {
        json_response(['ok' => false, 'msg' => '请先切换到 HTML 静态优先模式']);
    }
    if (!mfs_dir_ready()) {
        json_response(['ok' => false, 'msg' => '快照目录不可写（data/static/）']);
    }
    $g = $_GET;
    unset($g['__sgen'], $g['csrf']);
    $tgt = mfs_cacheable($g, ['__sgen', 'csrf']);
    if (!$tgt) {
        json_response(['ok' => false, 'msg' => '不支持的目标页']);
    }
    $t0 = microtime(true);
    $html = mfs_gen_render($tgt);
    $ms = (int)round((microtime(true) - $t0) * 1000);
    $ok = mfs_save($tgt['p'], $tgt['id'], $html);
    json_response(['ok' => $ok, 'p' => $tgt['p'], 'id' => $tgt['id'], 'bytes' => strlen($html), 'ms' => $ms, 'msg' => $ok ? '' : '渲染或保存失败']);
}
