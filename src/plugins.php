<?php
/**
 * 极简论坛 · 拓展（插件）系统（v1.22.0）
 *
 * 设计：
 *  - 插件目录：data/plugins/<插件id>/（data/ 拒绝一切 Web 直访；数据库存储模式下仍驻留文件系统）；
 *  - 清单：plugin.json —— {"name":"必填","version":"1.0.0","author":"","desc":"","entry":"plugin.php","id":"可选"}
 *  - 插件代码 = 管理员手动安装的全信任代码（与官方更新包同级信任），加载失败自动跳过并写错误日志，绝不影响论坛运行；
 *  - 钩子按优先级（小者先）执行；过滤器把值依次传入回调并把返回值继续传给下一个回调；
 *  - 前台路由：index.php?p=plugin&pf=<插件id> —— 插件用 mf_register_route() 注册处理函数后即获得独立页面；
 *    静态资源：p=plugin&pf=<id>&file=style.css（扩展名白名单 + 类型表头，作为资源级端点先于门禁出站）。
 *
 * 插件可用 API（全局函数，加载即用）：
 *   mf_add_action(string $hook, callable $fn, int $prio = 10): void      订阅动作钩子
 *   mf_add_filter(string $tag, callable $fn, int $prio = 10): void       订阅过滤器
 *   mf_do_action(string $hook, mixed ...$args): void                     触发动作钩子（内核埋点调用）
 *   mf_apply_filters(string $tag, mixed $value, mixed ...$args): mixed   应用过滤器
 *   mf_register_route(string $pluginId, callable $fn): void              注册前台路由处理函数
 *   mf_plugin_cfg(string $pluginId, string $key = null, mixed $def = null): mixed  读插件配置
 *   mf_plugin_cfg_set(string $pluginId, array $kv): void                 写插件配置（整体合并保存）
 *   mf_log(string $pluginId, string $msg): void                          写系统日志（plugin.<id> 类型）
 *
 * 内置动作钩子（内核已埋点）：
 *   plugins_loaded()                          所有启用的插件加载完毕（bootstrap 末尾触发）
 *   page_head()                               前台 <head> 输出前（可 echo 自定义 meta/样式）
 *   page_footer()                             前台 </body> 前（可 echo 统计代码/悬浮组件）
 *   admin_head()                              后台 <head> 区（page_head 之后）
 *   user_register(int $uid, string $name)     用户注册成功
 *   user_login(int $uid, string $name)        用户登录成功
 *   post_created(array $thread)               发帖成功（含 id/board/title/author）
 *   reply_created(int $tid, int $rid, array $post)  回复成功
 *   thread_view(array $thread)                帖子详情页渲染时
 * 内置过滤器：
 *   site_title(标题, 页面名)                   页面 <title> 文本
 *   md_html(渲染后HTML, 原文)                  Markdown 渲染结果（帖子/回复/公告等全站生效）
 */
defined('APP') or exit('Forbidden');

/* ================= 插件发现与清单 ================= */

/** 插件根目录（文件系统常驻：跨引擎可用，与上传文件同策略） */
function plugins_dir(): string
{
    return DATA_DIR . '/plugins';
}

/** 插件 id 合法性（目录名 / 路由参数共用） */
function plugin_id_ok(string $id): bool
{
    return (bool)preg_match('/^[a-z0-9][a-z0-9_]{1,31}$/', $id);
}

/** 扫描全部插件：返回 [id => ['dir'=>绝对路径,'name'=>..,'version'=>..,'author'=>..,'desc'=>..,'entry'=>..,'ok'=>bool,'err'=>..]] */
function plugins_discover(): array
{
    $out = [];
    $root = plugins_dir();
    if (!is_dir($root)) {
        return $out;
    }
    $names = @scandir($root) ?: [];
    foreach ($names as $n) {
        if ($n === '' || $n[0] === '.' || !plugin_id_ok($n)) {
            continue;
        }
        $dir = $root . '/' . $n;
        if (!is_dir($dir)) {
            continue;
        }
        $p = ['dir' => $dir, 'id' => $n, 'name' => $n, 'version' => '', 'author' => '', 'desc' => '', 'entry' => 'plugin.php', 'ok' => false, 'err' => ''];
        $mf = $dir . '/plugin.json';
        if (!is_file($mf)) {
            $p['err'] = '缺少 plugin.json 清单';
            $out[$n] = $p;
            continue;
        }
        $j = json_decode((string)@file_get_contents($mf), true);
        if (!is_array($j)) {
            $p['err'] = 'plugin.json 不是合法 JSON';
            $out[$n] = $p;
            continue;
        }
        $p['name'] = cut_str(trim((string)($j['name'] ?? $n)), 40);
        $p['version'] = cut_str(trim((string)($j['version'] ?? '')), 20);
        $p['author'] = cut_str(trim((string)($j['author'] ?? '')), 30);
        $p['desc'] = trim((string)($j['desc'] ?? ''));
        $entry = basename(trim((string)($j['entry'] ?? 'plugin.php')));
        if ($entry === '' || str_contains($entry, '.php') === false || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $entry)) {
            $p['err'] = '清单 entry 字段不合法';
            $out[$n] = $p;
            continue;
        }
        $p['entry'] = $entry;
        if (!is_file($dir . '/' . $entry)) {
            $p['err'] = '入口文件不存在：' . $entry;
            $out[$n] = $p;
            continue;
        }
        $p['ok'] = true;
        $out[$n] = $p;
    }
    ksort($out);
    return $out;
}

/** 已启用插件 id 列表 */
function plugins_enabled_ids(): array
{
    $e = cfg('plugins_enabled', []);
    return is_array($e) ? array_values(array_filter(array_map('strval', $e), 'plugin_id_ok')) : [];
}

/** 插件是否启用 */
function plugin_on(string $id): bool
{
    return in_array($id, plugins_enabled_ids(), true);
}

/* ================= 钩子注册表与 API ================= */

/** @internal 初始化注册表（幂等） */
function mf_hooks_boot(): void
{
    if (!isset($GLOBALS['MF_HOOKS']) || !is_array($GLOBALS['MF_HOOKS'])) {
        $GLOBALS['MF_HOOKS'] = ['a' => [], 'f' => []];
    }
    if (!isset($GLOBALS['MF_PLUGIN_ROUTES']) || !is_array($GLOBALS['MF_PLUGIN_ROUTES'])) {
        $GLOBALS['MF_PLUGIN_ROUTES'] = [];
    }
}

/** 订阅动作钩子（$prio 小者先执行；同优先级按注册顺序） */
function mf_add_action(string $hook, callable $fn, int $prio = 10): void
{
    mf_hooks_boot();
    $GLOBALS['MF_HOOKS']['a'][$hook][] = ['p' => $prio, 'fn' => $fn, 'seq' => count($GLOBALS['MF_HOOKS']['a'][$hook] ?? [])];
}

/** 订阅过滤器（回调返回值继续向后传递） */
function mf_add_filter(string $tag, callable $fn, int $prio = 10): void
{
    mf_hooks_boot();
    $GLOBALS['MF_HOOKS']['f'][$tag][] = ['p' => $prio, 'fn' => $fn, 'seq' => count($GLOBALS['MF_HOOKS']['f'][$tag] ?? [])];
}

/** @internal 按优先级排序 */
function mf_hooks_sorted(array $list): array
{
    usort($list, function ($a, $b) {
        return [$a['p'], $a['seq']] <=> [$b['p'], $b['seq']];
    });
    return $list;
}

/** 触发动作钩子（回调异常互不传染，逐个捕获写日志） */
function mf_do_action(string $hook, mixed ...$args): void
{
    mf_hooks_boot();
    foreach (mf_hooks_sorted($GLOBALS['MF_HOOKS']['a'][$hook] ?? []) as $h) {
        try {
            ($h['fn'])(...$args);
        } catch (Throwable $t) {
            error_log('[mf] action ' . $hook . ': ' . $t->getMessage());
        }
    }
}

/** 应用过滤器（值为空时也照常传递，允许回调补默认值） */
function mf_apply_filters(string $tag, mixed $value, mixed ...$args): mixed
{
    mf_hooks_boot();
    foreach (mf_hooks_sorted($GLOBALS['MF_HOOKS']['f'][$tag] ?? []) as $h) {
        try {
            $value = ($h['fn'])($value, ...$args);
        } catch (Throwable $t) {
            error_log('[mf] filter ' . $tag . ': ' . $t->getMessage());
        }
    }
    return $value;
}

/** 注册前台路由（p=plugin&pf=<id>）：$fn(string $pluginId): void，内部自行输出页面 */
function mf_register_route(string $pluginId, callable $fn): void
{
    if (!plugin_id_ok($pluginId)) {
        return;
    }
    mf_hooks_boot();
    $GLOBALS['MF_PLUGIN_ROUTES'][$pluginId] = $fn;
}

/** 插件配置读取（存 data/plugins.cfg.php，走存储引擎；$key=null 返回整包） */
function mf_plugin_cfg(string $pluginId, ?string $key = null, mixed $def = null): mixed
{
    $all = Store::read('plugins.cfg.php', []);
    if (!is_array($all)) {
        $all = [];
    }
    $own = is_array($all[$pluginId] ?? null) ? $all[$pluginId] : [];
    if ($key === null) {
        return $own;
    }
    return array_key_exists($key, $own) ? $own[$key] : $def;
}

/** 插件配置写入（合并进该插件命名空间） */
function mf_plugin_cfg_set(string $pluginId, array $kv): void
{
    $lk = Store::lock('plugins_cfg', 3);
    $all = Store::read('plugins.cfg.php', []);
    if (!is_array($all)) {
        $all = [];
    }
    $own = is_array($all[$pluginId] ?? null) ? $all[$pluginId] : [];
    foreach ($kv as $k => $v) {
        $own[(string)$k] = $v;
    }
    $all[$pluginId] = $own;
    Store::write('plugins.cfg.php', $all);
    if ($lk) {
        Store::unlock($lk);
    }
}

/** 插件写系统日志（类型 plugin.<id>，后台日志页可见） */
function mf_log(string $pluginId, string $msg): void
{
    log_action(plugin_id_ok($pluginId) ? 'plugin.' . $pluginId : 'plugin', cut_str($msg, 200), 0, '插件');
}

/* ================= 加载 ================= */

/** 加载全部启用插件（bootstrap 末尾调用一次；失败自动跳过） */
function plugins_load(): void
{
    mf_hooks_boot();
    $GLOBALS['MF_PLUGINS_LOADED'] = [];
    if (is_dir(plugins_dir())) {
        $en = array_flip(plugins_enabled_ids());
        foreach (plugins_discover() as $id => $p) {
            if (!isset($en[$id]) || !$p['ok']) {
                continue;
            }
            try {
                require $p['dir'] . '/' . $p['entry'];
                $GLOBALS['MF_PLUGINS_LOADED'][$id] = true;
            } catch (Throwable $t) {
                error_log('[mf] plugin ' . $id . ' 加载失败: ' . $t->getMessage());
            }
        }
    }
    mf_do_action('plugins_loaded');
}

/* ================= 安装 / 卸载 ================= */

/**
 * 从上传的 zip 安装插件（仅管理员；MiniZipRead 零依赖解析）。
 * zip 结构：plugin.json 在根目录，或包在一个同名顶层目录里；其余条目按相对路径落盘。
 * @return [bool, string] 成功与否与说明（成功返回插件 id）
 */
function plugin_install_zip(string $zipPath): array
{
    try {
        $items = MiniZipRead::read($zipPath);
    } catch (RuntimeException $e) {
        return [false, '无法读取 zip：' . $e->getMessage()];
    }
    unset($items['']);
    /* 找清单：根目录的 plugin.json，或唯一顶层目录下的 plugin.json */
    $prefix = '';
    if (isset($items['plugin.json'])) {
        $prefix = '';
    } else {
        $tops = [];
        foreach (array_keys($items) as $k) {
            $slash = strpos($k, '/');
            if ($slash !== false) {
                $tops[substr($k, 0, $slash)] = true;
            }
        }
        $tops = array_keys($tops);
        if (count($tops) === 1 && isset($items[$tops[0] . '/plugin.json'])) {
            $prefix = $tops[0] . '/';
        } else {
            return [false, 'zip 中找不到 plugin.json 清单（根目录或单一顶层目录内）'];
        }
    }
    $j = json_decode((string)$items[$prefix . 'plugin.json'], true);
    if (!is_array($j)) {
        return [false, 'plugin.json 不是合法 JSON'];
    }
    $id = strtolower(trim((string)($j['id'] ?? '')));
    if ($id === '') {
        $id = strtolower(trim($prefix) !== '' ? rtrim($prefix, '/') : 'plugin');
    }
    if (!plugin_id_ok($id)) {
        return [false, '插件 id「' . cut_str($id, 40) . '」不合法（需 2-32 位小写字母/数字/下划线，字母开头）'];
    }
    $dir = plugins_dir() . '/' . $id;
    if (is_dir($dir)) {
        return [false, '插件目录已存在：data/plugins/' . $id . '（请先删除再安装，或在清单里换一个 id）'];
    }
    /* 逐条目校验 + 落盘（防 zip-slip / 控制字符；限制体积与数量） */
    $total = 0;
    $count = 0;
    $files = [];
    foreach ($items as $name => $content) {
        $rel = (string)substr((string)$name, strlen($prefix));
        if ($rel === '') {
            continue;
        }
        if (strlen($rel) > 200 || preg_match('/[\x00-\x1f]/', $rel) || str_contains($rel, '\\')) {
            return [false, 'zip 含不安全条目名：' . cut_str($name, 40)];
        }
        $parts = explode('/', $rel);
        $depth = 0;
        foreach ($parts as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..') {
                return [false, 'zip 含不安全路径：' . cut_str($name, 40)];
            }
            $depth++;
        }
        if ($depth > 6) {
            return [false, 'zip 目录层级过深：' . cut_str($name, 40)];
        }
        $total += strlen((string)$content);
        $count++;
        if ($total > 4 * 1024 * 1024 || $count > 300) {
            return [false, '插件包过大（限 4MB / 300 个文件）'];
        }
        $files[$rel] = (string)$content;
    }
    if (!isset($files['plugin.json'])) {
        return [false, '清单未包含在插件包内'];
    }
    if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return [false, '无法创建插件目录（检查 data/ 可写性）'];
    }
    foreach ($files as $rel => $content) {
        $dest = $dir . '/' . $rel;
        $d = dirname($dest);
        if (!is_dir($d)) {
            @mkdir($d, 0755, true);
        }
        if (@file_put_contents($dest, $content) === false) {
            return [false, '写入失败：' . $rel];
        }
    }
    return [true, $id];
}

/** 删除插件目录（含全部文件；已启用的先停用） */
function plugin_remove(string $id): bool
{
    if (!plugin_id_ok($id)) {
        return false;
    }
    $dir = plugins_dir() . '/' . $id;
    $root = plugins_dir();
    if (!is_dir($dir) || strpos(realpath($dir), (string)realpath($root)) !== 0) {
        return false; // 路径逃逸防护
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    return @rmdir($dir);
}

/** 前台插件静态资源出站（p=plugin&pf=<id>&file=xxx；扩展名白名单，资源级端点调用） */
function plugin_asset(string $id, string $file): void
{
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $types = [
        'css' => 'text/css; charset=UTF-8', 'js' => 'application/javascript; charset=UTF-8',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'webp' => 'image/webp',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'txt' => 'text/plain; charset=UTF-8', 'json' => 'application/json; charset=UTF-8',
    ];
    if (!plugin_id_ok($id) || !isset($types[$ext])) {
        http_response_code(404);
        exit;
    }
    $base = basename(str_replace('\\', '/', $file));
    $f = plugins_dir() . '/' . $id . '/' . $base;
    $root = (string)realpath(plugins_dir());
    $real = (string)realpath($f);
    if ($real === '' || $root === '' || strpos($real, $root) !== 0 || !is_file($real)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . $types[$ext]);
    header('Cache-Control: public, max-age=300');
    header('Content-Length: ' . (string)filesize($real));
    readfile($real);
    exit;
}
