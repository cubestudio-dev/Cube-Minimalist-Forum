<?php
/**
 * 极简论坛 · 运行环境引擎（v1.38.0）
 *
 * 面向「小皮面板（phpStudy）」等 Windows / Linux 主机环境的一键体检与修复：
 *  - 操作系统自动识别（Windows / Linux / macOS）→ 扩展加载指令写法自动区分
 *    （Windows: extension=php_xxx.dll；Linux: extension=xxx.so；OPcache 走 zend_extension）
 *  - 主机面板自动识别：小皮面板（phpStudy V8 / 2018 / 经典版，Win 与 Linux 同名目录靠系统区分）、
 *    宝塔（Linux / Windows）、XAMPP、WampServer、UPUPW、Laragon，其余归为「通用环境」；
 *    识别结果决定「开启扩展 / 重启生效」的专用指引文案
 *  - PHP 扩展体检：按论坛真实依赖分级（推荐 / 可选 / 性能；程序核心零硬依赖，
 *    缺扩展只降级相应能力——中文字符串处理、SMTP SSL、AI 外呼、扫码二维码等）
 *  - 一键开启缺失扩展：定位当前 SAPI 正在使用的 php.ini → 强制备份到 data/backup/
 *    → 优先取消注释已有行（保持面板原有排版），无现成行才追加标记块 → 原子写入
 *  - 上传限制体检：upload_max_filesize / post_max_size 过小时一键调大
 *    （32M / 64M，与迁移包 32MB 上限对齐；只调大、绝不调小）
 *  - php.ini 健康修复（v1.38.2）：自动发现「php_ 前缀缺 .dll/.so 后缀」等永远加载
 *    不到的扩展行（phpStudy 老模板常见，如 extension=php_mysqli）→ 改写为当前平台
 *    正确写法；指向不存在扩展文件的无效行（如 Windows 上的 sysvshm）与 PHP 8 已移除
 *    指令（如 track_errors，每次启动刷 Fatal）→ 自动注释；extension_dir 目录不存在
 *    时明确提示。修复「已启用行（哪怕写法错误）被静默跳过」的问题
 *  - php.ini 不可写或未加载：不写盘，改走「下载配置片段 → FTP / 面板编辑器粘贴」降级方案
 *    （延续 v1.35.1 规矩：预检先行，绝不产生必然失败的写盘）
 *
 * 安全边界：
 *  - 只修改 php.ini 这一个文件；修改前强制备份，备份失败即中止（绝不裸奔）；
 *  - 全部写盘动作仅限管理员（后台 admin_tab_guard + CSRF；安装向导内仅限未安装站点且带 CSRF）；
 *  - 修改后需在面板重启 PHP 生效——程序绝不尝试重启服务进程。
 */
defined('APP') or exit('Forbidden');

/** 当前是否为 Windows（PHP_OS_FAMILY 自 7.2 起可用，此处双保险写法） */
function efx_is_win(): bool
{
    if (defined('PHP_OS_FAMILY')) {
        return PHP_OS_FAMILY === 'Windows';
    }
    return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
}

/** 操作系统展示名（带 PHP_OS 原值，便于截图反馈排查） */
function efx_os_label(): string
{
    if (efx_is_win()) {
        return 'Windows（' . PHP_OS . '）';
    }
    if (defined('PHP_OS_FAMILY')) {
        if (PHP_OS_FAMILY === 'Darwin') {
            return 'macOS（Darwin）';
        }
        if (PHP_OS_FAMILY === 'Linux') {
            return 'Linux';
        }
    }
    return PHP_OS;
}

/**
 * 主机面板自动识别（特征路径段逐个匹配，大小写不敏感）。
 * 匹配来源：php.ini 路径 / extension_dir / DOCUMENT_ROOT / 脚本路径 / PHP 可执行文件路径。
 * 返回 ['id' => 面板代号, 'name' => 展示名（含系统）, 'root' => 面板根目录（识别到时）]
 */
function efx_panel(): array
{
    $hay = [
        (string)php_ini_loaded_file(),
        (string)ini_get('extension_dir'),
        (string)($_SERVER['DOCUMENT_ROOT'] ?? ''),
        (string)($_SERVER['SCRIPT_FILENAME'] ?? ''),
        (string)($_SERVER['PHP_SELF'] ?? ''),
        PHP_BINARY,
        __DIR__,
    ];
    /* 特征段 → 面板代号（顺序敏感：具体在前、宽泛在后） */
    $needles = [
        'phpstudy_pro' => 'phpstudy',       // 小皮面板 V8（Windows 与 Linux 主目录同名）
        'phptutorial'  => 'phpstudy2018',   // phpStudy 2018
        'phpstudy'     => 'phpstudy_legacy',// phpStudy 经典版
        'upupw'        => 'upupw',
        'laragon'      => 'laragon',
        'www/server'   => 'bt',             // 宝塔 Linux（/www/server/php/...）
        'btsoft'       => 'bt_win',         // 宝塔 Windows（C:\BtSoft\...）
        'xampp'        => 'xampp',
        'wamp64'       => 'wamp',
        'wamp'         => 'wamp',
    ];
    $win = efx_is_win();
    $names = [
        'phpstudy'        => $win ? '小皮面板（phpStudy）· Windows' : '小皮面板 · Linux',
        'phpstudy2018'    => 'phpStudy 2018 · Windows',
        'phpstudy_legacy' => 'phpStudy（经典版）' . ($win ? ' · Windows' : ''),
        'upupw'           => 'UPUPW' . ($win ? ' · Windows' : ' · Linux'),
        'laragon'         => 'Laragon · Windows',
        'bt'              => '宝塔面板 · Linux',
        'bt_win'          => '宝塔面板 · Windows',
        'xampp'           => 'XAMPP' . ($win ? ' · Windows' : ''),
        'wamp'            => 'WampServer · Windows',
    ];
    foreach ($hay as $s) {
        if ($s === '') {
            continue;
        }
        foreach ($needles as $needle => $id) {
            $pos = stripos($s, $needle);
            if ($pos === false) {
                continue;
            }
            $root = substr($s, 0, $pos + strlen($needle));
            return ['id' => $id, 'name' => $names[$id] ?? '', 'root' => $root];
        }
    }
    return ['id' => '', 'name' => '', 'root' => ''];
}

/**
 * 面板专用指引（在面板里开启扩展 / 重启 PHP 的准确入口）。
 * 未识别到面板时按 Windows / Linux 给通用指引。
 */
function efx_panel_hint(): string
{
    $id = efx_panel()['id'];
    if (efx_is_win()) {
        switch ($id) {
            case 'phpstudy':
            case 'phpstudy2018':
            case 'phpstudy_legacy':
                return '小皮面板（Windows）：打开 phpStudy 面板 →「软件管理」→ 找到当前使用的 PHP 版本 →「扩展」页签勾选需要的扩展并保存（面板会自动重启对应服务）；或在「配置」页直接编辑 php.ini 后，到面板首页点「重启」。';
            case 'bt_win':
                return '宝塔面板（Windows）：软件商店 → PHP →「扩展」勾选安装；完成后重启该 PHP 版本。';
            default:
                return 'Windows 主机：编辑 php.ini（扩展写法 extension=php_xxx.dll），保存后重启 IIS 应用程序池 / Apache / nginx+php-cgi。';
        }
    }
    switch ($id) {
        case 'phpstudy':
        case 'phpstudy2018':
        case 'phpstudy_legacy':
            return '小皮面板（Linux）：面板左侧「软件商店」→ 已安装 → PHP →「设置」→「安装扩展」勾选安装；完成后回到「服务」页重启 PHP。';
        case 'bt':
            return '宝塔面板（Linux）：软件商店 → 已安装 → PHP →「设置」→「安装扩展」勾选安装；保存后点「服务」重启。';
        default:
            return 'Linux 主机：用包管理器安装对应扩展（如 apt install php-curl / php-gd），或使用主机商提供的扩展安装界面；完成后重启 php-fpm / Web 服务。';
    }
}

/** 修改 php.ini 后的生效方式提示 */
function efx_restart_hint(): string
{
    $id = efx_panel()['id'];
    if (in_array($id, ['phpstudy', 'phpstudy2018', 'phpstudy_legacy'], true)) {
        return efx_is_win()
            ? '在 phpStudy 面板首页点「重启」（或「软件管理 → PHP → 重启」）后生效。'
            : '在小皮面板「软件商店 → PHP → 服务」重启后生效。';
    }
    if ($id === 'bt' || $id === 'bt_win') {
        return '在宝塔面板重启对应 PHP 版本后生效。';
    }
    return efx_is_win() ? '重启 IIS / Apache / nginx（php-cgi）后生效。' : '重启 php-fpm / Web 服务后生效。';
}

/**
 * 扩展体检清单（按论坛真实依赖分级；程序核心零硬依赖，这里只列「有则更好」的项）：
 *  - rec  推荐：缺失时对应能力降级（中文字符串 / SMTP SSL / AI 外呼 / 扫码二维码）
 *  - opt  可选：第三方插件生态常用，程序本体不依赖
 *  - perf 性能：OPcache（状态展示，「性能体检」卡有专门的一键开启）
 */
function efx_ext_catalog(): array
{
    return [
        'mbstring' => ['level' => 'rec',  'why' => '中文字符串处理（长度 / 搜索 / 截断）'],
        'openssl'  => ['level' => 'rec',  'why' => 'SMTP SSL 发信（465 端口）'],
        'curl'     => ['level' => 'rec',  'why' => 'AI 审核 / 防火墙 IP 库 / 更新检查'],
        'gd'       => ['level' => 'rec',  'why' => '扫码登录二维码（PNG 图片）'],
        'zip'      => ['level' => 'opt',  'why' => '通用压缩（部分插件使用；程序自带 MiniZip 不依赖）'],
        'opcache'  => ['level' => 'perf', 'why' => '字节码缓存，整站提速'],
    ];
}

/** 扩展是否已加载（OPcache 按 Zend 扩展判定） */
function efx_ext_loaded(string $ext): bool
{
    if ($ext === 'opcache') {
        return extension_loaded('Zend OPcache') || function_exists('opcache_get_status');
    }
    return extension_loaded($ext);
}

/** 扩展加载指令的操作数（按系统自动区分写法；OPcache 是 Zend 扩展） */
function efx_operand(string $ext, bool $zend = false): string
{
    if ($zend) {
        return efx_is_win() ? 'php_opcache.dll' : 'opcache.so';
    }
    return efx_is_win() ? 'php_' . $ext . '.dll' : $ext . '.so';
}

/** 该扩展的加载指令行（按系统自动区分写法；OPcache 是 Zend 扩展） */
function efx_directive(string $ext): string
{
    $zend = $ext === 'opcache';
    return ($zend ? 'zend_extension=' : 'extension=') . efx_operand($ext, $zend);
}

/**
 * 扩展二进制文件是否随附：在 extension_dir 下找 php_xxx.dll / xxx.so。
 * extension_dir 为相对路径时，依次尝试「PHP 可执行文件同目录」组合（php -S / 常见 Windows 布局）。
 * 找不到返回 ''（表示「未随附」，只能通过面板扩展商店安装，不做无意义的一键写入）。
 */
function efx_ext_file(string $ext): string
{
    $dir = str_replace('\\', '/', trim((string)ini_get('extension_dir')));
    if ($dir === '') {
        return '';
    }
    $dirs = [$dir];
    if ($dir[0] !== '/' && !preg_match('#^[a-z]:/#i', $dir)) {
        $dirs[] = rtrim(str_replace('\\', '/', dirname(PHP_BINARY)), '/') . '/' . ltrim($dir, '/');
    }
    $cands = [];
    foreach (array_unique($dirs) as $d) {
        $d = rtrim($d, '/');
        $cands[] = efx_is_win()
            ? $d . '/php_' . $ext . '.dll'
            : $d . '/' . $ext . '.so';
        if ($ext === 'opcache' && !efx_is_win()) {
            $cands[] = $d . '/opcache.so';
        }
    }
    foreach (array_unique($cands) as $c) {
        if (@is_file($c)) {
            return $c;
        }
    }
    return '';
}

/** extension_dir（含相对路径回退）指向的目录是否真实存在——不存在时全部扩展必然加载失败 */
function efx_extdir_ok(): bool
{
    $dir = str_replace('\\', '/', trim((string)ini_get('extension_dir')));
    if ($dir === '') {
        return true; /* 无法判定时不报问题 */
    }
    if ($dir[0] !== '/' && !preg_match('#^[a-z]:/#i', $dir)) {
        $dir = rtrim(str_replace('\\', '/', dirname(PHP_BINARY)), '/') . '/' . $dir;
    }
    return @is_dir(rtrim($dir, '/'));
}

/** 当前 SAPI 正在使用的 php.ini 路径（未加载返回 ''） */
function efx_ini_path(): string
{
    $p = @php_ini_loaded_file();
    return is_string($p) && $p !== '' && @is_file($p) ? $p : '';
}

/** 该扩展的「已启用行」匹配式（行首无分号；兼容 extension= / zend_extension=、裸名 / php_ 前缀 / 后缀名） */
function efx_line_preg(string $ext, bool $commented): string
{
    $n = preg_quote($ext, '/');
    $lead = $commented ? '\s*;+\s*' : '\s*';
    return '/^' . $lead . '(?:zend_)?extension\s*=\s*(?:php_)?' . $n . '(?:\.(?:dll|so))?\s*$/i';
}

/** PHP 8 已移除的常见指令（phpStudy 等老模板残留；设置它们会在每次启动时刷 Fatal 日志） */
function efx_removed_directives(): array
{
    return [
        'track_errors', 'register_globals', 'magic_quotes_gpc', 'magic_quotes_runtime',
        'magic_quotes_sybase', 'allow_call_time_pass_reference', 'define_syslog_variables',
        'safe_mode', 'zend.ze1_compatibility_mode', 'session.bug_compat_42', 'session.bug_compat_warn',
    ];
}

/**
 * 解析一行（未注释的）扩展加载指令。
 * 返回 ['op'=>操作数, 'zend'=>bool, 'name'=>扩展名]；非扩展行 / 注释行返回 null。
 * 兼容：extension= / zend_extension=、裸名 / php_ 前缀、.dll / .so 后缀、行内注释。
 */
function efx_parse_ext_line(string $ln): ?array
{
    if (!preg_match('/^\s*(zend_)?extension\s*=\s*(.+)$/i', $ln, $m)) {
        return null;
    }
    $op = trim($m[2]);
    $op = (string)preg_replace('/\s+[;#].*$/', '', $op); /* 去行内注释 */
    $op = trim($op, "\"'");
    if ($op === '') {
        return null;
    }
    $base = strtolower(basename(str_replace('\\', '/', $op)));
    $name = (string)preg_replace('/^(?:php_)?(.+?)(?:\.(?:dll|so))?$/i', '$1', $base);
    return ['op' => $op, 'zend' => ($m[1] ?? '') !== '', 'name' => $name];
}

/**
 * 扫描 ini 行，找出需要修复的问题（v1.38.2 php.ini 健康体检核心）：
 *  - PHP 8 已移除指令（仅 PHP >= 8 判定）→ 注释掉；
 *  - 「php_ 前缀缺后缀」等永远加载不到的扩展行 → 改写为当前平台正确写法
 *    （仅当扩展文件真实存在时改写，否则按无效行注释）；
 *  - 写法有效但指向的扩展文件不存在的无效行（如 Windows 上的 sysvshm）→ 注释掉。
 * 已正常加载的扩展（可能由 conf.d 片段等其他途径加载）绝不动。
 * 返回 ['fix' => [行下标 => 新行], 'note' => [描述, ...]]。
 */
function efx_ini_scan(array $lines): array
{
    $fix = [];
    $note = [];
    $tag = '[Cube Forum ' . MF_VERSION . ']';
    /* extension_dir 指向的目录不存在时：无法核实任何扩展文件是否存在——
       只处理「已移除指令」，扩展行一律不动（先修 extdir，再复检） */
    $extdirMissing = !efx_extdir_ok();
    foreach ($lines as $i => $ln) {
        $t = ltrim($ln);
        if ($t === '' || $t[0] === ';' || $t[0] === '#') {
            continue;
        }
        if (PHP_VERSION_ID >= 80000
            && preg_match('/^([A-Za-z0-9_.]+)\s*=/', $t, $km)
            && in_array(strtolower($km[1]), efx_removed_directives(), true)) {
            $fix[$i] = ';' . rtrim($ln) . "  ; $tag PHP 8 已移除该指令，自动注释（每次启动都在刷 Fatal 日志）";
            $note[] = $km[1] . '（PHP 8 已移除指令 → 注释）';
            continue;
        }
        $ext = efx_parse_ext_line($ln);
        if ($ext === null || $ext['name'] === '') {
            continue;
        }
        if ($extdirMissing) {
            continue; /* extdir 坏了无法核实文件存在性，扩展行一律不动 */
        }
        if (extension_loaded($ext['name'])
            || ($ext['zend'] && (extension_loaded('Zend OPcache') || function_exists('opcache_get_status')))) {
            continue; /* 已正常加载（可能来自其他配置片段），绝不动 */
        }
        $win = efx_is_win();
        $base = strtolower(basename(str_replace('\\', '/', $ext['op'])));
        $prefixed = strpos($base, 'php_') === 0;
        $goodSuffix = $win ? (substr($base, -4) === '.dll') : (substr($base, -3) === '.so');
        $file = efx_ext_file($ext['name']);
        if ($prefixed && !$goodSuffix) {
            /* 写法无效：php_ 前缀 + 无本平台后缀 → 解析成 php_php_xxx.dll / php_xxx.so，任何系统都加载不到 */
            if ($file !== '') {
                $line = ($ext['zend'] ? 'zend_extension=' : 'extension=') . efx_operand($ext['name'], $ext['zend']);
                $fix[$i] = $line . "  ; $tag 修正写法（原行 php_ 前缀缺后缀，永远加载不到）";
                $note[] = $ext['name'] . '（修正加载行写法 → ' . $line . '）';
            } else {
                $fix[$i] = ';' . rtrim($ln) . "  ; $tag 注释无效行：写法错误且扩展文件不存在，该行只会刷加载失败警告";
                $note[] = $ext['name'] . '（注释无效加载行：写法错误且扩展文件不存在）';
            }
        } elseif ($file === '' && $ext['name'] !== '') {
            /* 写法有效但指向的扩展文件不存在（如 Windows 上的 sysvshm / 未安装的扩展） */
            $fix[$i] = ';' . rtrim($ln) . "  ; $tag 注释无效行：扩展文件不存在，该行只会刷加载失败警告";
            $note[] = $ext['name'] . '（注释无效加载行：扩展文件不存在）';
        }
    }
    return ['fix' => $fix, 'note' => $note];
}

/** 在 ini 行数组中查找该扩展的行：0=已启用行下标，1=注释行下标，2=未出现；返回 [$active, $commented]（下标或 -1） */
function efx_find_lines(array $lines, string $ext): array
{
    $active = -1;
    $commented = -1;
    foreach ($lines as $i => $ln) {
        if ($active >= 0) {
            break;
        }
        if (preg_match(efx_line_preg($ext, false), $ln)) {
            $active = $i;
        } elseif ($commented < 0 && preg_match(efx_line_preg($ext, true), $ln)) {
            $commented = $i;
        }
    }
    return [$active, $commented];
}

/**
 * v1.41.2 上传链路自检：按 act_media_upload 的真实判定顺序逐项探测，
 * 每步给出当前值，失败步给出精确定位与修复建议——生产侧「图片 / 文件无法上传」
 * 的定位入口（哪一步 ✘ 就处理哪一环）。
 * PHP 的 HTTP 上传解析层无法在页面请求内真正模拟，故以 file_uploads 开关 +
 * 临时目录真实写探针等价判定；存储目录直接做「建年月子目录 + 写入 + 删除」真实探针。
 * @return array{ok:bool, steps:list<array{ok:bool,t:string,v:string,fix:string}>}
 */
function efx_upload_chain(): array
{
    $steps = [];
    /* ① 论坛功能开关（关闭时发帖页不渲染上传工具条，接口直接拒绝） */
    $on = function_exists('feat_on') ? feat_on('upload') : true;
    $steps[] = [
        'ok'  => $on,
        't'   => '论坛上传功能开关',
        'v'   => $on ? '开启' : '关闭',
        'fix' => $on ? '' : '后台「功能开关」勾选「允许上传图片 / 附件」并保存——关闭状态下发帖页没有上传按钮，接口返回「上传功能已关闭」',
    ];
    /* ② PHP 上传总开关：Off 时 PHP 直接丢弃全部上传，$_FILES 恒为空 */
    $fu = strtolower(trim((string)ini_get('file_uploads')));
    $fuOk = $fu === '1' || $fu === 'on' || $fu === 'true';
    $steps[] = [
        'ok'  => $fuOk,
        't'   => 'PHP 上传总开关 file_uploads',
        'v'   => $fuOk ? 'On' : (((string)ini_get('file_uploads') !== '') ? (string)ini_get('file_uploads') : '-'),
        'fix' => $fuOk ? '' : 'php.ini 设 file_uploads=On 并重启 PHP——Off 时 PHP 丢弃所有上传，前端会提示「请选择要上传的文件」',
    ];
    /* ③ 单文件上限：默认 2M 连手机照片都放不下 */
    $upB = sysmon_memlimit_bytes((string)ini_get('upload_max_filesize'));
    $upH = (string)ini_get('upload_max_filesize') ?: '-';
    $upOk = $upB >= 8 * 1048576;
    $steps[] = [
        'ok'  => $upOk,
        't'   => '单文件上限 upload_max_filesize',
        'v'   => $upH,
        'fix' => $upOk ? '' : '过小——本页上方「一键修复」可调大到 32M（或 php.ini 手工改后重启 PHP）；超限时上传报「错误码 1 / 2」',
    ];
    /* ④ 整请求上限：必须 ≥ 单文件上限，否则 PHP 把 $_FILES 与全部 POST 一起静默丢弃 */
    $postB = sysmon_memlimit_bytes((string)ini_get('post_max_size'));
    $postH = (string)ini_get('post_max_size') ?: '-';
    $postOk = $postB >= max(8 * 1048576, $upB);
    $steps[] = [
        'ok'  => $postOk,
        't'   => '整请求上限 post_max_size',
        'v'   => $postH . (($postB > 0 && $upB > 0 && $postB < $upB) ? '（小于单文件上限！）' : ''),
        'fix' => $postOk ? '' : '必须 ≥ upload_max_filesize——整请求超限时「选了文件仍提示未收到文件」；本页「一键修复」可调大到 64M',
    ];
    /* ⑤ 上传临时目录真实写探针：PHP 收到文件先落这里，不可写报「错误码 6 / 7」 */
    $tmp = trim((string)ini_get('upload_tmp_dir'));
    if ($tmp === '') {
        $tmp = sys_get_temp_dir();
    }
    $tmpNote = $tmp;
    $tmpOk = false;
    if (!is_dir($tmp)) {
        $tmpNote .= '（目录不存在）';
    } else {
        $probe = rtrim($tmp, '/\\') . '/.mf-up-probe-' . (getmypid() ?: '0') . '.tmp';
        $tmpOk = @file_put_contents($probe, 'probe') !== false;
        if ($tmpOk) {
            @unlink($probe);
        } else {
            $tmpNote .= '（不可写）';
        }
    }
    $steps[] = [
        'ok'  => $tmpOk,
        't'   => '上传临时目录可写',
        'v'   => $tmpNote,
        'fix' => $tmpOk ? '' : 'php.ini 的 upload_tmp_dir 应指向存在且 PHP 运行账号可写的目录（未设置时用系统临时目录），修正后重启 PHP',
    ];
    /* ⑥ 存储目录真实写探针：等价于 act_media_upload 的落盘动作（建年月子目录 + 写入 + 删除） */
    $dir = DATA_DIR . '/uploads/' . date('Ym');
    $dirOk = is_dir($dir) || @mkdir($dir, 0755, true);
    if ($dirOk) {
        $probe = $dir . '/.mf-up-probe-' . (getmypid() ?: '0') . '.tmp';
        $dirOk = @file_put_contents($probe, 'probe') !== false;
        if ($dirOk) {
            @unlink($probe);
        }
    }
    $steps[] = [
        'ok'  => $dirOk,
        't'   => '存储目录 data/uploads 可写',
        'v'   => ($dirOk ? '可写（' : '不可写（') . cut_str($dir, 40) . '）',
        'fix' => $dirOk ? '' : '最终存储位置写不进——Linux 上多为权限 / 属主不对（目录 755、属主与 PHP 运行账号一致），「监控」页一键修复目录权限或 FTP 手工修正',
    ];
    /* ⑦ 白名单解析预览（与上传接口同一套切分逻辑；图片 png/jpg/jpeg/gif/webp 恒放行） */
    $allow = preg_split('/[\s,，;；]+/u', trim((string)cfg('upload_allow_ext', 'zip pdf txt md csv doc docx xls xlsx ppt pptx'))) ?: [];
    $allow = array_values(array_filter(array_map(fn ($x) => strtolower(trim(ltrim((string)$x, '.'))), $allow)));
    $allowOk = count($allow) > 0;
    $steps[] = [
        'ok'  => $allowOk,
        't'   => '附件扩展名白名单（' . count($allow) . ' 项）',
        'v'   => $allowOk ? implode(' ', array_slice($allow, 0, 14)) . (count($allow) > 14 ? ' …' : '') : '（空）',
        'fix' => $allowOk ? '' : '为空时所有非图片附件都会被拒（提示「不支持的附件类型」；png / jpg / jpeg / gif / webp 不受影响）——后台「内容与限制」填写，空格或逗号分隔',
    ];
    $allOk = true;
    foreach ($steps as $s) {
        $allOk = $allOk && $s['ok'];
    }
    return ['ok' => $allOk, 'steps' => $steps];
}

/**
 * 全量体检计划：系统 / 面板 / php.ini / 扩展状态 / 上传限制。
 * fixable   = 未启用且扩展文件随附 → 可一键开启
 * nobundle  = 未启用且扩展文件未随附 → 只能面板安装（展示 + 指引）
 */
function efx_plan(): array
{
    $panel = efx_panel();
    $ini = efx_ini_path();
    $exts = [];
    $fixable = [];
    $nobundle = [];
    foreach (efx_ext_catalog() as $name => $meta) {
        $loaded = efx_ext_loaded($name);
        $file = $loaded ? '' : efx_ext_file($name);
        $exts[] = [
            'name'      => $name,
            'level'     => $meta['level'],
            'why'       => $meta['why'],
            'loaded'    => $loaded,
            'file'      => $file,
            'directive' => efx_directive($name),
        ];
        if (!$loaded) {
            if ($file !== '') {
                $fixable[] = $name;
            } else {
                $nobundle[] = $name;
            }
        }
    }
    $upBytes = sysmon_memlimit_bytes((string)ini_get('upload_max_filesize'));
    $postBytes = sysmon_memlimit_bytes((string)ini_get('post_max_size'));

    /* v1.38.2 php.ini 健康体检：坏写法 / 无效行 / 已移除指令 + extension_dir 存在性 */
    $extdir = str_replace('\\', '/', trim((string)ini_get('extension_dir')));
    $issues = ['count' => 0, 'notes' => []];
    if ($ini !== '') {
        $rawIni = (string)@file_get_contents($ini);
        if ($rawIni !== '') {
            $scan = efx_ini_scan(preg_split('/\R/', $rawIni) ?: []);
            $issues = ['count' => count($scan['note']), 'notes' => array_slice($scan['note'], 0, 8)];
        }
    }

    return [
        'os'           => efx_os_label(),
        'win'          => efx_is_win(),
        'panel'        => $panel,
        'ini'          => $ini,
        'ini_writable' => $ini !== '' && (is_writable($ini) || is_writable(dirname($ini))),
        'exts'         => $exts,
        'fixable'      => $fixable,
        'nobundle'     => $nobundle,
        'ini_issues'   => $issues,
        'extdir'       => ['dir' => $extdir, 'missing' => $extdir !== '' && !@is_dir($extdir)],
        'up'           => [
            'cur'      => (string)ini_get('upload_max_filesize') ?: '-',
            'post'     => (string)ini_get('post_max_size') ?: '-',
            'up_sug'   => $upBytes > 0 && $upBytes < 8 * 1048576,
            'post_sug' => $postBytes > 0 && $postBytes < 16 * 1048576,
        ],
        'upchain'      => efx_upload_chain(),
    ];
}

/** 在行数组中写入 key=value（已有启用行改值；仅有注释行改写为启用；都没有则追加；只调大不调小由调用方判断） */
function efx_ini_set_line(array &$lines, string $key, string $value): void
{
    $pat = '/^\s*' . preg_quote($key, '/') . '\s*=/i';
    foreach ($lines as $i => $ln) {
        if (!preg_match($pat, $ln)) {
            continue;
        }
        $lines[$i] = $key . '=' . $value;
        return;
    }
    $lines[] = $key . '=' . $value;
}

/**
 * 一键应用：开启缺失扩展 + （可选）调大上传限制。
 * 流程：读 ini → 逐项处理（优先取消注释已有行，保持面板原排版；无现成行进入追加块）
 * → 强制备份到 data/backup/php-ini-bak-*.ini（失败即中止、原文件不动）→ 原子写入。
 * $iniOverride 仅供自动化测试注入目标 ini（生产恒用当前 SAPI 的 php.ini）。
 * 返回 ['ok','err','enabled','skipped','up','backup']；enabled 为「扩展（方式）」描述数组。
 */
function efx_apply(array $names, bool $fixUpload = true, ?string $iniOverride = null): array
{
    $res = ['ok' => false, 'err' => '', 'enabled' => [], 'skipped' => [], 'up' => [], 'fixed' => [], 'backup' => ''];
    $ini = $iniOverride !== null && @is_file($iniOverride) ? $iniOverride : efx_ini_path();
    if ($ini === '') {
        $res['err'] = '未找到当前 PHP 正在使用的 php.ini（可能为命令行参数加载）。请按面板指引在面板中开启扩展，或下载配置片段后手动放置。';
        return $res;
    }
    [$fw, $rw] = mf_root_write_check($ini);
    if (!$fw && !$rw) {
        $res['err'] = 'php.ini 对 PHP 不可写（' . $ini . '）。未做任何修改。请改用面板扩展开关，或下载配置片段后通过 FTP / 面板编辑器上传。';
        return $res;
    }
    $raw = (string)@file_get_contents($ini);
    if ($raw === '' && @filesize($ini) > 0) {
        $res['err'] = 'php.ini 读取失败（权限不足）。未做任何修改。请通过面板编辑器操作。';
        return $res;
    }
    $lines = preg_split('/\R/', $raw) ?: [];
    $appended = [];
    /* v1.38.2：php.ini 健康修复（坏写法改写 / 无效行与已移除指令注释）——
       与扩展开启共用同一套备份与原子写；即使不缺扩展也执行（一键修复 php.ini） */
    $scan = efx_ini_scan($lines);
    foreach ($scan['fix'] as $i => $newLine) {
        $lines[$i] = $newLine;
    }
    $res['fixed'] = $scan['note'];
    foreach ($names as $ext) {
        if (efx_ext_loaded($ext)) {
            continue;
        }
        if (efx_ext_file($ext) === '') {
            $res['skipped'][] = $ext;
            continue;
        }
        [$active, $commented] = efx_find_lines($lines, $ext);
        if ($active >= 0) {
            continue; // 已有启用行（理论上不会走到：fixable 只含未启用项，防御性保留）
        }
        if ($commented >= 0) {
            $lines[$commented] = preg_replace('/^\s*;+\s*/', '', $lines[$commented]);
            $res['enabled'][] = $ext . '（取消注释已有行）';
        } else {
            $appended[] = efx_directive($ext);
            $res['enabled'][] = $ext . '（追加加载行）';
        }
    }
    /* 上传限制：只调大、绝不调小（迁移包 / 更新包上传常被 2M 默认值卡住） */
    if ($fixUpload) {
        $plan = efx_plan();
        if ($plan['up']['up_sug']) {
            efx_ini_set_line($lines, 'upload_max_filesize', '32M');
            $res['up'][] = 'upload_max_filesize ' . $plan['up']['cur'] . ' → 32M';
        }
        if ($plan['up']['post_sug']) {
            efx_ini_set_line($lines, 'post_max_size', '64M');
            $res['up'][] = 'post_max_size ' . $plan['up']['post'] . ' → 64M';
        }
    }
    if (!$res['enabled'] && !$res['up'] && !$res['fixed']) {
        $res['ok'] = true; // 无可做修改（可能已被并行处理），视为成功但无动作
        $res['err'] = '没有需要修改的项目（缺失扩展可能已启用，或上传限制已达标，php.ini 无坏行）。';
        return $res;
    }
    /* 强制备份：失败即中止，原文件未动 */
    Store::ensureDir('backup');
    $bakRel = 'backup/php-ini-bak-' . date('Ymd-His') . '.ini';
    if (@file_put_contents(Store::path($bakRel), $raw) === false) {
        $res['err'] = '备份 php.ini 失败（data/backup/ 不可写），已中止修改——原文件未做任何改动。请先到「环境自检」修复目录权限后重试。';
        return $res;
    }
    $res['backup'] = $bakRel;
    $body = implode("\n", $lines);
    if ($appended) {
        $body = rtrim($body) . "\n\n; ---- [Cube Minimalist Forum " . MF_VERSION . "] one-click enable (auto backup: " . basename($bakRel) . ") ----\n"
            . implode("\n", $appended) . "\n";
    }
    /* 原子写：tmp + rename，失败回退直接写；仍失败则中止（备份已在，可手动恢复） */
    $tmp = $ini . '.mftmp-' . getmypid();
    $done = false;
    if (@file_put_contents($tmp, $body) !== false) {
        if (!@rename($tmp, $ini)) {
            @unlink($tmp);
            $done = @file_put_contents($ini, $body) !== false;
        } else {
            $done = true;
        }
    }
    if (!$done) {
        $done = @file_put_contents($ini, $body) !== false;
    }
    if (!$done) {
        $res['err'] = '写入 php.ini 失败（写入过程被拦截）。未生效；原文件备份在 data/backup/' . basename($bakRel) . '。请改用面板扩展开关，或下载配置片段手动上传。';
        return $res;
    }
    $res['ok'] = true;
    return $res;
}

/** 一次性结果提示文案（后台 flash 与安装向导横幅共用） */
function efx_result_msg(array $res): string
{
    if (!$res['ok']) {
        return (string)$res['err'];
    }
    $msg = '已写入 php.ini';
    if ($res['fixed']) {
        $msg .= '：修复 ' . count($res['fixed']) . ' 处（' . implode('、', array_slice($res['fixed'], 0, 5)) . (count($res['fixed']) > 5 ? ' 等' : '') . '）';
    }
    if ($res['enabled']) {
        $msg .= ($res['fixed'] ? '；开启 ' : '：开启 ') . implode('、', $res['enabled']);
    }
    if ($res['up']) {
        $msg .= ($res['fixed'] || $res['enabled'] ? '；' : '：') . '上传限制已调大（' . implode(' / ', $res['up']) . '）';
    }
    if ($res['backup'] !== '') {
        $msg .= '。原文件已备份为 data/backup/' . basename($res['backup']) . '（如需回退，FTP 覆盖回去即可）';
    }
    $msg .= '。' . efx_restart_hint();
    if ($res['skipped']) {
        $msg .= '另有未能自动开启：' . implode('、', $res['skipped']) . '（扩展文件未随附，请在面板扩展商店安装）。';
    }
    return $msg;
}

/** 降级方案：生成 php.ini 追加片段（FTP 上传 / 面板编辑器粘贴用），内容与一键写入同源 */
function efx_snippet(): string
{
    $plan = efx_plan();
    $out = [];
    $out[] = '; Cube Minimalist Forum ' . MF_VERSION . ' · 运行环境配置片段（生成于 ' . date('Y-m-d H:i') . '）';
    $out[] = '; 系统环境：' . $plan['os'] . ($plan['panel']['name'] !== '' ? ' · 面板：' . $plan['panel']['name'] : '');
    $out[] = '; 用法：把下面未注释的行追加到当前 PHP 正在使用的 php.ini 末尾（面板编辑器或 FTP 均可），然后重启 PHP。';
    $out[] = '; 当前 php.ini：' . ($plan['ini'] !== '' ? $plan['ini'] : '未找到（请向主机商 / 面板确认路径）');
    $out[] = '';
    /* v1.38.2：php.ini 已发现问题时的手工修复指引（片段只能追加，改写 / 注释坏行需手动） */
    $raw = $plan['ini'] !== '' ? (string)@file_get_contents($plan['ini']) : '';
    if ($raw !== '') {
        $scan = efx_ini_scan(preg_split('/\R/', $raw) ?: []);
        foreach ($scan['note'] as $n) {
            $out[] = '; [体检] 发现并建议修复：' . $n . '（追加无法修复坏行，请按描述注释 / 改写原行）';
        }
        if ($scan['note']) {
            $out[] = '';
        }
    }
    if ($plan['extdir']['missing']) {
        $out[] = '; [体检] extension_dir 指向的目录不存在：' . $plan['extdir']['dir'] . ' —— 这是全部扩展加载失败的隐形根因，请改为真实的 ext 目录（如小皮面板 H:\\phpstudy_pro\\Extensions\\php\\phpX.Y.Znts\\ext）';
        $out[] = '';
    }
    foreach ($plan['exts'] as $x) {
        if ($x['loaded']) {
            continue;
        }
        if ($x['file'] !== '') {
            $out[] = $x['directive'];
        } else {
            $out[] = '; ' . $x['name'] . '：扩展文件未随附，请先在面板扩展商店安装对应扩展';
        }
    }
    if ($plan['up']['up_sug']) {
        $out[] = 'upload_max_filesize=32M';
    }
    if ($plan['up']['post_sug']) {
        $out[] = 'post_max_size=64M';
    }
    $out[] = '';
    $out[] = '; 重启 PHP 后回到 后台「监控 → 运行环境」刷新即可看到状态更新。';
    return implode("\n", $out) . "\n";
}
