<?php
/**
 * 极简论坛 · 迁移搬家（v1.37.0）
 * 在后台把当前站点的全部内容（帖子 / 回复 / 用户 / 版块 / 设置 / 附件 / 公告 /
 * 通知 / AI 与防火墙状态…）打包为一个「迁移包」；在新服务器的安装向导或已安装
 * 站点的后台一键导入，即可原样恢复，无需数据库、无需重新配置。
 *
 * 迁移包结构（zip 根目录）：
 *   migration.json          清单：{"type":"mf-migration","version":"x.y.z",...}
 *   其余条目                 相对 data/ 目录的原始文件（含 <?php exit 守卫，原样写入）
 *
 * 有意排除（打不进包，导入时也拒绝）：
 *   lock/ 安装锁 · locks/ 运行锁 · sessions/ 会话（换环境后作废）· backup/ 历史备份
 *   static/ 静态快照（新环境自动重建）· fwmin/ 限流窗口（瞬时状态）
 *   online.php 在线表 · codes.php 邮件验证码 —— 均为一次性运行时数据
 *
 * 安全边界：
 *   - 制作 / 下载 / 导入 / 删除全部仅限管理员（下载走后台鉴权直链）
 *   - 导入前自动把当前 data/ 完整备份为 pre-migrate-*.zip（备份失败即中止）
 *   - 拒绝绝对路径 / .. / 不安全条目；拒绝覆盖会话与锁；拒绝比程序更新的数据版本
 *   - 单包上限 32MB（MiniZip 全内存构建）；超大附件建议 FTP 搬运 data/media/
 *   - 数据库引擎下暂不支持（数据在 DB 中，请用数据库自带导出导入）
 */
defined('APP') or exit('Forbidden');

const MIG_MAX_BYTES = 33554432; /* 32MB：单迁移包内容上限（MiniZip 全内存构建的稳妥边界） */

/** 数据库引擎下不支持：数据不在文件系统 */
function mig_engine_ok(): bool
{
    return Store::$drv === 'file';
}

/* ================= 收集与构建 ================= */

/**
 * 递归收集要打进迁移包的文件（相对 data/ 路径 => 绝对路径）
 * @return array{files:array<string,string>,bytes:int}
 */
function mig_collect(bool $incMedia, bool $incLogs): array
{
    $files = [];
    $bytes = 0;
    $skipDirs = ['lock', 'locks', 'sessions', 'backup', 'static', 'fwmin'];
    $skipRoot = ['online.php', 'codes.php'];
    $walk = function (string $rel) use (&$walk, &$files, &$bytes, $skipDirs, $skipRoot, $incMedia, $incLogs): void {
        $abs = DATA_DIR . ($rel === '' ? '' : '/' . $rel);
        foreach ((@scandir($abs) ?: []) as $f) {
            if ($f === '.' || $f === '..' || $f === '.wtest' || $f === '.DS_Store') {
                continue;
            }
            $r = $rel === '' ? $f : $rel . '/' . $f;
            $a = $abs . '/' . $f;
            if (is_dir($a)) {
                if (strpos($f, '.broken-') === 0) {
                    continue;
                }
                if ($rel === '' && in_array($f, $skipDirs, true)) {
                    continue;
                }
                if ($rel === '' && $f === 'media' && !$incMedia) {
                    continue;
                }
                if ($rel === '' && $f === 'logs' && !$incLogs) {
                    continue;
                }
                $walk($r);
                continue;
            }
            /* 瞬时 / 垃圾文件不进包 */
            if ($rel === '' && in_array($f, $skipRoot, true)) {
                continue;
            }
            if (preg_match('/\.uptmp\d*$/', $f) || str_starts_with($f, '.')) {
                continue;
            }
            $sz = (int)@filesize($a);
            $files[$r] = $a;
            $bytes += $sz;
        }
    };
    $walk('');
    return ['files' => $files, 'bytes' => $bytes];
}

/**
 * 制作迁移包，存入 data/backup/，返回文件名；失败返回 null 且 $err 带原因
 */
function mig_build(bool $incMedia, bool $incLogs, string &$err = ''): ?string
{
    if (!mig_engine_ok()) {
        $err = '当前使用数据库引擎，数据不在文件系统中，暂不支持迁移包（请使用数据库自带的导出 / 导入）';
        return null;
    }
    $c = mig_collect($incMedia, $incLogs);
    if ($c['files'] === []) {
        $err = '没有可打包的数据（尚未安装或数据目录为空）';
        return null;
    }
    if ($c['bytes'] > MIG_MAX_BYTES) {
        $err = sprintf(
            '迁移内容共 %.1fMB，超过单包上限 32MB（通常是附件较多）。建议：制作时取消「包含附件」，'
                . '改用 FTP 把旧站 data/media/ 整目录搬到新站同位置；或分批清理旧附件后重试。',
            $c['bytes'] / 1048576
        );
        return null;
    }
    $replies = 0;
    foreach (thread_index() as $t) {
        $replies += (int)($t['replies'] ?? 0);
    }
    $m = [
        'type'      => 'mf-migration',
        'version'   => app_version(),
        'created'   => time(),
        'site'      => (string)cfg('site_name', ''),
        'counts'    => [
            'users'   => count(user_all()),
            'boards'  => count(board_all()),
            'threads' => count(thread_index()),
            'replies' => $replies,
        ],
        'files'     => count($c['files']),
        'bytes'     => $c['bytes'],
        'inc_media' => $incMedia ? 1 : 0,
        'inc_logs'  => $incLogs ? 1 : 0,
    ];
    $payload = [['migration.json', (string)json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]];
    foreach ($c['files'] as $rel => $abs) {
        $payload[] = [$rel, (string)@file_get_contents($abs)];
    }
    $name = 'migration-' . date('Ymd-His') . '.zip';
    if (!MiniZip::create(Store::path('backup/' . $name), $payload)) {
        $err = '写入迁移包失败（data/backup 不可写？）。可到「监控 → 环境自检」修复目录权限后重试';
        return null;
    }
    log_action('migrate_build', sprintf(
        '制作迁移包 %s：v%s，%d 用户 / %d 版块 / %d 帖子 / %d 文件（%.1fKB）%s%s',
        $name,
        $m['version'],
        $m['counts']['users'],
        $m['counts']['boards'],
        $m['counts']['threads'],
        $m['files'],
        $c['bytes'] / 1024,
        $incMedia ? '，含附件' : '，不含附件',
        $incLogs ? '，含日志' : ''
    ));
    return $name;
}

/* ================= 包管理 ================= */

/** data/backup/ 下的迁移包列表：[name, size, mtime]（文件名升序，即时间序） */
function mig_list(): array
{
    $out = [];
    foreach (Store::scan('backup') as $f) {
        if (preg_match('/^migration-\d{8}-\d{6}\.zip$/', (string)$f)) {
            $p = Store::path('backup/' . $f);
            $out[] = ['name' => (string)$f, 'size' => (int)@filesize($p), 'mtime' => (int)@filemtime($p)];
        }
    }
    return $out;
}

/** 删除迁移包（仅接受本程序生成的命名） */
function mig_delete(string $name): bool
{
    if (!preg_match('/^migration-\d{8}-\d{6}\.zip$/', $name)) {
        return false;
    }
    $p = Store::path('backup/' . basename($name));
    return is_file($p) && @unlink($p);
}

/* ================= 导入 ================= */

/** 导入前把当前 data/ 完整备份（排除会话 / 快照 / 锁）；备份失败返回 false */
function mig_data_backup(string &$name): bool
{
    $files = [];
    $bytes = 0;
    $skipDirs = ['lock', 'locks', 'sessions', 'backup', 'static'];
    $walk = function (string $rel) use (&$walk, &$files, &$bytes, $skipDirs): void {
        $abs = DATA_DIR . ($rel === '' ? '' : '/' . $rel);
        foreach ((@scandir($abs) ?: []) as $f) {
            if ($f === '.' || $f === '..' || str_starts_with($f, '.')) {
                continue;
            }
            $r = $rel === '' ? $f : $rel . '/' . $f;
            $a = $abs . '/' . $f;
            if (is_dir($a)) {
                if ($rel === '' && in_array($f, $skipDirs, true)) {
                    continue;
                }
                $walk($r);
                continue;
            }
            if (preg_match('/\.uptmp\d*$/', $f)) {
                continue;
            }
            $files[$r] = $a;
            $bytes += (int)@filesize($a);
        }
    };
    $walk('');
    if ($files === []) {
        return true; /* 空站点无需备份（安装向导恢复模式） */
    }
    if ($bytes > MIG_MAX_BYTES * 2) {
        return false; /* 数据过大无法自动备份，交给上层给出 FTP 指引 */
    }
    $payload = [];
    foreach ($files as $rel => $abs) {
        $payload[] = [$rel, (string)@file_get_contents($abs)];
    }
    $name = 'pre-migrate-' . date('Ymd-His') . '.zip';
    return (bool)MiniZip::create(Store::path('backup/' . $name), $payload);
}

/** 清空全部会话文件（换环境后旧会话一律作废） */
function mig_wipe_sessions(): void
{
    foreach ((@scandir(DATA_DIR . '/sessions') ?: []) as $f) {
        if ($f === '.' || $f === '..' || $f === '.htaccess') {
            continue;
        }
        @unlink(DATA_DIR . '/sessions/' . $f);
    }
    if (isset($_SESSION)) {
        unset($_SESSION['uid']);
    }
}

/**
 * 从迁移包导入（覆盖当前 data/ 中对应文件）。
 * @param string $zipPath 上传的临时文件或 data/backup/ 内的迁移包绝对路径
 * @param array  $res     输出：['manifest'=>..,'files'=>int,'backup'=>string,'notes'=>string[]]
 * @throws RuntimeException 失败原因（任何失败都不会写入任何文件）
 */
function mig_import(string $zipPath, array &$res = []): void
{
    $entries = MiniZipRead::read($zipPath);

    if (isset($entries['update.json']) && !isset($entries['migration.json'])) {
        throw new RuntimeException('这是「更新包」不是迁移包：请到「更新升级」页上传（更新包只覆盖程序文件，迁移包才包含数据）');
    }
    if (!isset($entries['migration.json'])) {
        throw new RuntimeException('迁移包缺少 migration.json，不是有效的迁移包');
    }
    $m = json_decode((string)$entries['migration.json'], true);
    if (!is_array($m) || (string)($m['type'] ?? '') !== 'mf-migration'
        || empty($m['version']) || !is_string($m['version'])) {
        throw new RuntimeException('migration.json 格式不正确（type / version 字段缺失）');
    }
    if (version_compare($m['version'], MF_VERSION, '>')) {
        throw new RuntimeException('迁移包版本 v' . $m['version'] . ' 比当前程序 v' . MF_VERSION
            . ' 还新：请先把新服务器上的程序升级到同版本（用完整安装包部署），再导入迁移包');
    }
    /* 数据库引擎：文件导入后 Store 读到的仍是 DB 内容，等于没导 */
    if (!mig_engine_ok()) {
        throw new RuntimeException('当前使用数据库引擎，暂不支持迁移包导入（请使用数据库自带的导出 / 导入）');
    }

    /* ---- 校验全部条目（有任何不安全条目，整体拒绝，一个文件都不写） ---- */
    $copy = [];
    foreach ($entries as $name => $content) {
        $name = str_replace('\\', '/', (string)$name);
        if ($name === 'migration.json') {
            continue;
        }
        if ($name === '' || $name[0] === '/' || strpos($name, ':') !== false
            || preg_match('#(^|/)\.\.(/|$)#', $name) || preg_match('#(^|/)\.$#', $name)) {
            throw new RuntimeException('迁移包含不安全的文件路径：' . $name);
        }
        /* 有意排除项：导入也拒绝（会话 / 锁 / 备份 / 快照 / 安装锁一律不迁移） */
        foreach (['lock/', 'locks/', 'sessions/', 'backup/', 'static/'] as $bad) {
            if (strncmp($name, $bad, strlen($bad)) === 0) {
                throw new RuntimeException('迁移包含不允许导入的目录（' . $bad . '）：' . $name);
            }
        }
        if ($name === 'install.php' || $name === 'migration.json.bak') {
            throw new RuntimeException('迁移包含不允许导入的条目：' . $name);
        }
        $copy[$name] = (string)$content;
    }
    if (!$copy) {
        throw new RuntimeException('迁移包中没有可导入的数据文件');
    }

    /* ---- 导入前备份当前数据（已安装站点；全新安装无数据可备） ---- */
    $installed = FileStore::exists('lock/install.lock');
    $bkName = '';
    if ($installed) {
        if (!mig_data_backup($bkName)) {
            throw new RuntimeException('导入前备份失败（数据过大或 data/backup 不可写）。为保安全已中止导入；'
                . '大站请先通过 FTP 手动备份 data/ 目录，再重试导入');
        }
    }

    /* ---- 逐条原子写入 ---- */
    $applied = 0;
    foreach ($copy as $name => $content) {
        $target = DATA_DIR . '/' . $name;
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new RuntimeException('无法创建目录：data/' . $name . '（请检查 data/ 目录权限）');
        }
        $tmp = $target . '.uptmp' . getmypid();
        if (@file_put_contents($tmp, $content) === false || !@rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException('写入文件失败（权限不足？）：data/' . $name);
        }
        @chmod($target, 0644);
        $applied++;
    }

    /* ---- 善后：目录补齐 / Web 守卫 / 会话作废 / 版本对齐 / 静态加速 / 缓存 ---- */
    $notes = [];
    foreach (['threads', 'replies', 'notify', 'logs', 'media', 'sessions', 'backup', 'locks', 'lock'] as $d) {
        Store::ensureDir($d);
    }
    if (!is_file(DATA_DIR . '/.htaccess')) {
        @file_put_contents(DATA_DIR . '/.htaccess', "# 数据目录禁止一切 Web 访问（Apache）\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
    @file_put_contents(Store::path('lock/install.lock'), 'installed at ' . date('c') . " (migrate)\n");
    mig_wipe_sessions();
    if ((string)cfg('version', '') !== MF_VERSION) {
        cfg_update(['version' => MF_VERSION]);
        $notes[] = '数据版本已对齐当前程序 v' . MF_VERSION;
    }
    /* 静态加速：快照目录本来就不在包里，新环境重建加速块（失败则回退 PHP 模式） */
    if ((string)cfg('static_mode', 'php') === 'html') {
        $e2 = '';
        if (mfs_htaccess_install($e2)) {
            $notes[] = '.htaccess 加速块已在新环境重新安装';
        } else {
            cfg_update(['static_mode' => 'php']);
            $notes[] = '静态加速已暂时回退为 PHP 动态优先（' . cut_str($e2, 50) . '），可稍后在「监控」页重新开启';
        }
    }
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
    if ($installed && function_exists('log_action')) {
        log_action('migrate_import', sprintf(
            '导入迁移包（v%s，%s）：覆盖 %d 个数据文件；导入前备份 %s',
            (string)$m['version'],
            (string)($m['site'] ?? '未命名站点'),
            $applied,
            $bkName !== '' ? $bkName : '（空站免备份）'
        ));
    }
    $res = ['manifest' => $m, 'files' => $applied, 'backup' => $bkName, 'notes' => $notes];
}
