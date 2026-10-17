<?php
/**
 * 极简论坛 · 服务器终端（可选功能，默认关闭）
 *
 * 给没有 SSH 的部署环境（虚拟主机 / 云容器）提供一个浏览器端终端，
 * 用于查看日志、管理文件、排查环境等运维操作。
 *
 * 实现原理（零依赖 · 无第三方库 · 无 WebSocket）：
 *   每个管理员会话对应 data/term/<token>/ 下的一个常驻 sh 进程（循环脚本），
 *   PHP 与该进程通过文件通信：
 *     loop.sh.php  常驻循环脚本（首行 # 注释兼作 PHP 守卫，直接访问即退出）
 *     cmd.sh       PHP 原子写入的待执行命令；循环脚本拾取后在主 shell 内 source 执行
 *                  （source 使 cd / export 等状态跨命令保留，等同真实终端）
 *     cmd.run.sh   执行中标记（存在 = 上一条命令尚未结束，新命令自动排队）
 *     out.log.php  循环脚本全部 stdout/stderr 追加于此（守卫头防止被 Web 直接读取）
 *     cwd / pid    当前工作目录与进程号；meta.php 记录输出读取偏移与元信息
 *   前端交互：term_run 执行命令并等待输出（长轮询至超时）；超时未结束的命令转入
 *   后台继续执行，term_poll 持续拉取增量输出，与真实终端的使用感受一致。
 *
 * 安全设计：
 *   - 仅管理员可用，且必须在后台显式开启（默认关闭）；每条命令记入操作日志
 *   - 会话目录名 32 位随机 hex，无法被猜测；所有敏感文件带 PHP 守卫头
 *   - 结束进程前校验 /proc/<pid>/cmdline 身份，防止 pid 复用误伤其他进程
 *   - 输出日志 5MB 自动轮转、失控命令（>20MB）强制回收，防止写爆存储配额
 *   - 会话闲置 2 小时自动回收；单条命令长度 ≤ 2000 字符
 *   - 环境不支持（shell_exec 被禁 / Windows）时自动隐藏入口，不影响其他功能
 */

defined('APP') or exit('Forbidden');

const TERM_GUARD   = "<?php exit('Forbidden'); ?>\n";
const TERM_CMD_MAX = 2000;      // 单条命令长度上限（字符）
const TERM_MAX_OUT = 524288;    // 单次返回前端的输出上限（512KB）
const TERM_MAX_LOG = 5242880;   // 输出日志 5MB 触发轮转
const TERM_IDLE    = 7200;      // 会话闲置 2 小时自动回收
const TERM_MAX_SESS = 5;        // 最多同时保留的会话数

/* ---------------- 能力与开关 ---------------- */

/** 环境能力检测：类 Unix 系统 + shell_exec 可用（未被 disable_functions 禁用） */
function term_capable(): bool
{
    if (DIRECTORY_SEPARATOR !== '/' || !function_exists('shell_exec')) {
        return false;
    }
    $dis = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array('shell_exec', $dis, true);
}

/** 终端是否可用（后台开关 + 环境能力） */
function term_enabled(): bool
{
    return (string)cfg('term_enabled', '0') === '1' && term_capable();
}

/** 命令等待超时（秒，5~120，默认 20） */
function term_timeout(): int
{
    return max(5, min(120, (int)cfg('term_timeout', 20)));
}

/* ---------------- 会话目录与元信息 ---------------- */

function term_base(): string
{
    return DATA_DIR . '/term';
}

/** 当前管理员的终端会话 token（32 位 hex，存于会话；须在 session_write_close 之前调用） */
function term_token(): string
{
    if (empty($_SESSION['term_token']) || !is_string($_SESSION['term_token'])
        || !preg_match('/^[0-9a-f]{32}$/', $_SESSION['term_token'])) {
        $_SESSION['term_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['term_token'];
}

/** 从会话中读取既有 token（只读，不创建） */
function term_token_existing(): string
{
    $t = isset($_SESSION['term_token']) && is_string($_SESSION['term_token']) ? $_SESSION['term_token'] : '';
    return preg_match('/^[0-9a-f]{32}$/', $t) ? $t : '';
}

function term_dir(string $token): string
{
    return term_base() . '/' . $token;
}

/** 读取会话元信息（guard + JSON） */
function term_meta_read(string $dir): array
{
    $raw = @file_get_contents($dir . '/meta.php');
    if (is_string($raw) && strpos($raw, TERM_GUARD) === 0) {
        $m = json_decode(substr($raw, strlen(TERM_GUARD)), true);
        if (is_array($m)) {
            return $m;
        }
    }
    return [];
}

function term_meta_write(string $dir, array $m): void
{
    @file_put_contents($dir . '/meta.php', TERM_GUARD . json_encode($m, JSON_UNESCAPED_UNICODE));
}

/** 当前工作目录（shell 维护的 cwd 文件；异常时回退程序根目录） */
function term_cwd(string $dir): string
{
    $root = dirname(DATA_DIR);
    $cwd = trim((string)@file_get_contents($dir . '/cwd'));
    if ($cwd === '' || !is_dir($cwd)) {
        return $root;
    }
    // 目录已不存在（被删除）时尝试逐级回退
    $probe = $cwd;
    while ($probe !== '/' && !is_dir($probe)) {
        $probe = dirname($probe);
    }
    return is_dir($probe) ? $probe : $root;
}

/* ---------------- 进程管理 ---------------- */

/** 循环 shell 是否存活（优先 /proc 身份校验，退化为 kill -0） */
function term_alive(string $dir): bool
{
    $pid = (int)trim((string)@file_get_contents($dir . '/pid'));
    if ($pid <= 0) {
        return false;
    }
    $cl = @file_get_contents('/proc/' . $pid . '/cmdline');
    if (is_string($cl) && $cl !== '') {
        return strpos($cl, 'loop.sh.php') !== false && strpos($cl, basename($dir)) !== false;
    }
    $out = @shell_exec('kill -0 ' . $pid . ' 2>/dev/null && echo ok');
    return trim((string)$out) === 'ok';
}

/** 结束会话进程（先校验身份再杀，防止 pid 复用误杀） */
function term_kill(string $dir): void
{
    if (!function_exists('shell_exec')) {
        return;
    }
    $pid = (int)trim((string)@file_get_contents($dir . '/pid'));
    if ($pid <= 0) {
        return;
    }
    $cl = @file_get_contents('/proc/' . $pid . '/cmdline');
    if (is_string($cl) && $cl !== '' && strpos($cl, 'loop.sh.php') === false) {
        return; // pid 已被其他进程复用，不动它
    }
    @shell_exec('kill ' . $pid . ' 2>/dev/null; sleep 0.3; kill -9 ' . $pid . ' 2>/dev/null');
}

/**
 * 启动（或恢复）会话的常驻循环 shell；幂等，存活时直接返回
 * @return bool 是否成功
 */
function term_spawn(string $dir): bool
{
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return false;
    }
    if (term_alive($dir)) {
        return true;
    }
    term_kill($dir); // 清理可能的残留进程
    if (!function_exists('shell_exec')) {
        return false;
    }
    $out = $dir . '/out.log.php';
    if (!is_file($out)) {
        @file_put_contents($out, TERM_GUARD);
    }
    $root = dirname(DATA_DIR);
    $cwd = term_cwd($dir);
    if (!is_dir($cwd)) {
        $cwd = $root;
    }
    $q = escapeshellarg($dir);
    $loop = $dir . '/loop.sh.php';
    // 首行 # 注释：对 sh 是注释，对 PHP 直接访问则触发 exit('Forbidden')
    $src = "# <?php exit('Forbidden'); ?>\n"
        . 'cd ' . escapeshellarg($cwd) . "\n"
        . "rm -f {$q}/cmd.run.sh 2>/dev/null\n"
        . "while :; do\n"
        . "  if [ -f {$q}/cmd.sh ]; then\n"
        . "    mv {$q}/cmd.sh {$q}/cmd.run.sh 2>/dev/null\n"
        . "    if [ -f {$q}/cmd.run.sh ]; then . {$q}/cmd.run.sh; fi\n"
        . "    rm -f {$q}/cmd.run.sh 2>/dev/null\n"
        . "  fi\n"
        . "  sleep 0.2\n"
        . "done\n";
    if (@file_put_contents($loop, $src) === false) {
        return false;
    }
    // 后台启动：输出全部重定向，PHP 侧 shell_exec 立即返回，循环进程以孤儿进程常驻
    @shell_exec('sh ' . escapeshellarg($loop) . ' >> ' . escapeshellarg($out)
        . ' 2>&1 < /dev/null & echo $! > ' . escapeshellarg($dir . '/pid.tmp')
        . ' && mv ' . escapeshellarg($dir . '/pid.tmp') . ' ' . escapeshellarg($dir . '/pid'));
    @file_put_contents($out, "\n[shell 会话已启动 " . date('H:i:s') . "]\n", FILE_APPEND);
    usleep(300000);
    return term_alive($dir);
}

/* ---------------- 输出读取 ---------------- */

/**
 * 从 out.log.php 的 $offset 起读取输出，最多等待 $waitMs 毫秒
 * @return array [输出原文, 新偏移, 是否出现完成标记]
 */
function term_read_from(string $out, int $offset, int $waitMs): array
{
    $buf = '';
    $deadline = microtime(true) + max(0, $waitMs) / 1000;
    while (true) {
        clearstatcache(true, $out);
        $sz = (int)@filesize($out);
        if ($offset > $sz) {
            $offset = $sz; // 文件被轮转重置过，偏移量随之修正
        }
        if ($sz > $offset) {
            $fh = @fopen($out, 'rb');
            if ($fh) {
                fseek($fh, $offset);
                $chunk = (string)fread($fh, min($sz - $offset, 1048576));
                fclose($fh);
                if ($chunk !== '') {
                    $offset += strlen($chunk);
                    $buf .= $chunk;
                    if (strpos($buf, '__MF_DONE_') !== false || strlen($buf) > 2097152) {
                        break;
                    }
                }
            }
        }
        if (microtime(true) >= $deadline) {
            break;
        }
        usleep(250000);
    }
    return [$buf, $offset, strpos($buf, '__MF_DONE_') !== false];
}

/** 拆出显示内容与退出码（按最后一个完成标记切分；标记本身及其后内容不显示） */
function term_split(string $raw): array
{
    $done = false;
    $exit = null;
    $pos = strrpos($raw, '__MF_DONE_');
    if ($pos !== false) {
        $end = strpos($raw, '__', $pos + 10);
        if ($end !== false) {
            $code = substr($raw, $pos + 10, $end - $pos - 10);
            if (preg_match('/^\d{1,3}$/', $code)) {
                $done = true;
                $exit = (int)$code;
                $raw = substr($raw, 0, $pos);
            }
        }
    }
    return [$raw, $done, $exit];
}

/* ---------------- 回收与轮转 ---------------- */

/** 递归删除会话目录（仅允许 data/term/ 前缀内的路径，防误删） */
function term_rrmdir(string $dir): void
{
    $base = term_base();
    if ($dir === $base || strpos($dir, $base . '/') !== 0 || strpos($dir, '..') !== false) {
        return;
    }
    foreach ((@scandir($dir) ?: []) as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $p = $dir . '/' . $f;
        if (is_dir($p) && !is_link($p)) {
            term_rrmdir($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($dir);
}

/** 低频回收：闲置超时会话删除；会话总数超限删最旧 */
function term_gc(): void
{
    $base = term_base();
    if (!is_dir($base)) {
        return;
    }
    $sessions = [];
    foreach ((@scandir($base) ?: []) as $d) {
        if ($d === '.' || $d === '..' || !preg_match('/^[0-9a-f]{32}$/', $d)) {
            continue;
        }
        $dir = $base . '/' . $d;
        if (!is_dir($dir)) {
            continue;
        }
        $m = term_meta_read($dir);
        if (!isset($m['last']) || time() - (int)$m['last'] > TERM_IDLE) {
            term_kill($dir);
            term_rrmdir($dir);
            continue;
        }
        $sessions[(int)$m['last']] = $dir;
    }
    while (count($sessions) > TERM_MAX_SESS) {
        ksort($sessions);
        $oldest = array_shift($sessions);
        term_kill($oldest);
        term_rrmdir($oldest);
    }
}

/** 输出日志轮转：5MB 且空闲时轮转；失控命令（4 倍上限）强制回收 */
function term_maybe_rotate(string $dir): void
{
    $out = $dir . '/out.log.php';
    if (!is_file($out)) {
        return;
    }
    clearstatcache(true, $out);
    $size = (int)@filesize($out);
    if ($size < TERM_MAX_LOG) {
        return;
    }
    $running = is_file($dir . '/cmd.run.sh') || is_file($dir . '/cmd.sh');
    if ($running && $size < TERM_MAX_LOG * 4) {
        return; // 命令执行中暂不轮转；仅在写爆风险时强制回收
    }
    term_kill($dir);
    if (is_file($dir . '/out.old.php')) {
        @unlink($dir . '/out.old.php');
    }
    @rename($out, $dir . '/out.old.php');
    @file_put_contents($out, TERM_GUARD);
    $m = term_meta_read($dir);
    $m['offset'] = strlen(TERM_GUARD);
    term_meta_write($dir, $m);
}

/* ---------------- AJAX 端点（动作层调用） ---------------- */

/** 输出强制转为合法 UTF-8（mbstring 缺失时交由 JSON 编译标志兑底） */
function term_utf8(string $s): string
{
    return function_exists('mb_convert_encoding') ? (string)mb_convert_encoding($s, 'UTF-8', 'UTF-8') : $s;
}

/** 读取当前管理员会话的终端目录（不存在时返回 ''） */
function term_my_dir(): string
{
    $t = term_token_existing();
    return $t !== '' ? term_dir($t) : '';
}

/**
 * GET 只读：终端状态 + 增量输出（长轮询，w=等待毫秒 0~5000）
 * 页面加载时调用一次同步历史输出；长命令未结束时前端持续轮询
 */
function term_poll(): void
{
    $me = current_user();
    if (!$me || empty($me['admin'])) {
        json_response(['ok' => false, 'msg' => '无权限']);
    }
    $wait = isset($_GET['w']) && is_numeric($_GET['w']) ? max(0, min(5000, (int)$_GET['w'])) : 2000;
    session_write_close(); // 只读长轮询，立即释放会话锁
    if (!term_enabled()) {
        json_response(['ok' => false, 'msg' => '终端功能未启用']);
    }
    $dir = term_my_dir();
    $out = $dir . '/out.log.php';
    $m = is_dir($dir) ? term_meta_read($dir) : [];
    $offset = max(strlen(TERM_GUARD), (int)($m['offset'] ?? strlen(TERM_GUARD)));
    $output = '';
    $done = true;
    $exit = null;
    if (is_file($out)) {
        [$raw, $newOff, $hasMarker] = term_read_from($out, $offset, $wait);
        if ($newOff > $offset) {
            $m['offset'] = $newOff;
            $m['last'] = time();
            term_meta_write($dir, $m);
        }
        [$output, $hasMarker, $exit] = term_split($raw);
        /* 终态判定：标记出现 = 已结束；标记缺失时以「是否仍有待执行命令」为准，
           shell 已死（exit / 被杀）也视为结束，避免前端无限轮询 */
        $running = is_file($dir . '/cmd.run.sh') || is_file($dir . '/cmd.sh');
        $done = $hasMarker || !$running || !term_alive($dir);
    }
    json_response([
        'ok' => true,
        'output' => term_utf8($output),
        'done' => $done,
        'exit' => $exit,
        'cwd' => is_dir($dir) ? term_cwd($dir) : '',
        'alive' => is_dir($dir) ? term_alive($dir) : false,
        'running' => is_dir($dir) && (is_file($dir . '/cmd.run.sh') || is_file($dir . '/cmd.sh')),
        'user' => (string)($m['user'] ?? ''),
        'host' => (string)($m['host'] ?? php_uname('n')),
    ]);
}

/**
 * POST：执行一条命令（CSRF 校验；长轮询等待输出，超时转后台继续）
 */
function term_run(): void
{
    $me = current_user();
    if (!$me || empty($me['admin'])) {
        json_response(['ok' => false, 'msg' => '无权限']);
    }
    $csrfOk = csrf_ok();
    $uid = (int)$me['id'];
    $name = (string)$me['name'];
    $token = term_token(); // 必须在关闭会话前确保 token 已写入会话
    session_write_close(); // 长轮询期间释放会话锁，不阻塞同会话其他请求
    if (!$csrfOk) {
        json_response(['ok' => false, 'msg' => '页面已过期，请刷新后重试']);
    }
    if (!term_enabled()) {
        json_response(['ok' => false, 'msg' => '终端功能未启用，请先在后台「终端」页开启']);
    }
    $cmd = isset($_POST['cmd']) && is_string($_POST['cmd']) ? trim($_POST['cmd']) : '';
    if ($cmd === '') {
        json_response(['ok' => false, 'msg' => '命令为空']);
    }
    if (u_strlen($cmd) > TERM_CMD_MAX) {
        json_response(['ok' => false, 'msg' => '命令过长（上限 ' . TERM_CMD_MAX . ' 字符）']);
    }

    term_gc();
    $dir = term_dir($token);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        json_response(['ok' => false, 'msg' => '无法创建终端会话目录（data/ 不可写？）']);
    }
    // 立即登记会话活跃时间，防止被并发 GC 误回收（长轮询窗口内 meta 必须存在）
    $m0 = term_meta_read($dir);
    $m0['last'] = time();
    term_meta_write($dir, $m0);
    term_maybe_rotate($dir);
    $queued = is_file($dir . '/cmd.sh') || is_file($dir . '/cmd.run.sh');
    if (!term_spawn($dir)) {
        json_response(['ok' => false, 'msg' => '无法启动 shell 进程：宿主环境可能限制了进程执行（proc_open / shell_exec），终端不可用']);
    }

    $out = $dir . '/out.log.php';
    clearstatcache(true, $out);
    $offset = max(strlen(TERM_GUARD), (int)@filesize($out)); // 先记偏移，再投递命令

    // 命令包装：结束后回显完成标记（携带退出码）并刷新 cwd 文件
    $script = $cmd . "\necho \"__MF_DONE_\$?__\"\npwd > " . escapeshellarg($dir . '/cwd') . "\n";
    $tmp = $dir . '/cmd.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $script) === false) {
        json_response(['ok' => false, 'msg' => '命令写入失败（data/term 不可写？）']);
    }
    @rename($tmp, $dir . '/cmd.sh'); // 原子替换，循环脚本只会看到完整命令

    log_action('term_cmd', $cmd, $uid, $name);

    [$raw, $newOff, $done] = term_read_from($out, $offset, term_timeout() * 1000);
    $m = term_meta_read($dir);
    $m['offset'] = $newOff;
    $m['last'] = time();
    if ((string)($m['user'] ?? '') === '') {
        $m['user'] = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
            ? (string)(posix_getpwuid(posix_geteuid())['name'] ?? '')
            : (string)@get_current_user();
        $m['host'] = php_uname('n');
    }
    term_meta_write($dir, $m);

    [$display, $hasMarker, $exit] = term_split($raw);
    /* 同 term_poll：标记缺失但已无待执行命令（或 shell 已死）时视为结束 */
    if (!$hasMarker) {
        $done = !(is_file($dir . '/cmd.run.sh') || is_file($dir . '/cmd.sh')) || !term_alive($dir);
    }
    json_response([
        'ok' => true,
        'output' => term_utf8($display),
        'done' => $done,
        'exit' => $exit,
        'cwd' => term_cwd($dir),
        'alive' => term_alive($dir),
        'running' => is_file($dir . '/cmd.run.sh') || is_file($dir . '/cmd.sh'),
        'queued' => $queued,
        'user' => (string)($m['user'] ?? ''),
        'host' => (string)($m['host'] ?? php_uname('n')),
    ]);
}

/**
 * POST：重置终端会话（结束进程 + 清空会话目录，下次执行命令自动重建）
 */
function term_reset(): void
{
    $me = current_user();
    if (!$me || empty($me['admin'])) {
        json_response(['ok' => false, 'msg' => '无权限']);
    }
    $csrfOk = csrf_ok();
    $uid = (int)$me['id'];
    $name = (string)$me['name'];
    session_write_close();
    if (!$csrfOk) {
        json_response(['ok' => false, 'msg' => '页面已过期，请刷新后重试']);
    }
    if (!term_enabled()) {
        json_response(['ok' => false, 'msg' => '终端功能未启用']);
    }
    $dir = term_my_dir();
    if (is_dir($dir)) {
        term_kill($dir);
        term_rrmdir($dir);
    }
    log_action('term_reset', '重启终端会话', $uid, $name);
    json_response(['ok' => true, 'msg' => '终端会话已重置']);
}
