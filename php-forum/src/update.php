<?php
/**
 * 极简论坛 · 更新包系统
 * 在后台上传开发者发布的更新包（zip），即可完成升级 —— 无需重新安装、数据不丢。
 *
 * 更新包结构（zip 根目录）：
 *   update.json            清单：{"version":"x.y.z","from":"可选","date":"...","notes":[...]}
 *   update/migrate.php     可选：数据迁移脚本（以 $ctx = ['old'=>..,'new'=>..,'manifest'=>..] 被包含执行）
 *   其余文件/目录           按相对路径覆盖到程序目录（index.php、src/、assets/ 等）
 *
 * 安全边界：
 *   - 绝不覆盖 data/ 目录与 install.php（拒绝包含这些条目的更新包）
 *   - 拒绝绝对路径、..、盘符等不安全条目名
 *   - 应用前自动把当前程序文件备份到 data/backup/pre-update-*.zip（保留最近 3 份）
 *   - 版本号只前进不后退
 */
defined('APP') or exit('Forbidden');

/* ================= 当前版本 / 历史 ================= */

/** 当前论坛版本：优先取安装时写入配置的版本号（随更新包自动前进） */
function app_version(): string
{
    $v = (string)cfg('version', '');
    return $v !== '' ? $v : MF_VERSION;
}

function update_history(): array
{
    $h = Store::read('updates.php', []);
    return is_array($h) ? $h : [];
}

/* ================= 零依赖 ZIP 读取器 =================
 * 解析 Central Directory，支持 store(0) 与 deflate(8) 两种压缩方式。
 * 更新包与备份包体积都很小，整包读入内存即可，无需任何 PHP 扩展。
 */
class MiniZipRead
{
    /** @return array<string,string> 条目名 => 内容（自动跳过目录条目） */
    public static function read(string $path): array
    {
        $data = @file_get_contents($path);
        if ($data === false || strlen($data) < 22) {
            throw new RuntimeException('无法读取 zip 文件或文件为空');
        }
        $size = strlen($data);
        $eocd = false;
        $maxBack = min($size, 22 + 65535);
        for ($i = $size - 22; $i >= $size - $maxBack; $i--) {
            if (substr($data, $i, 4) === "\x50\x4b\x05\x06") {
                $eocd = $i;
                break;
            }
        }
        if ($eocd === false) {
            throw new RuntimeException('不是有效的 zip 文件');
        }
        $count = (int)unpack('v', substr($data, $eocd + 10, 2))[1];
        $cdOfs = (int)unpack('V', substr($data, $eocd + 16, 4))[1];
        $out = [];
        $p = $cdOfs;
        for ($n = 0; $n < $count; $n++) {
            if (substr($data, $p, 4) !== "\x50\x4b\x01\x02") {
                throw new RuntimeException('zip 目录已损坏');
            }
            $method = (int)unpack('v', substr($data, $p + 10, 2))[1];
            $csize  = (int)unpack('V', substr($data, $p + 20, 4))[1];
            $nlen   = (int)unpack('v', substr($data, $p + 28, 2))[1];
            $elen   = (int)unpack('v', substr($data, $p + 30, 2))[1];
            $clen   = (int)unpack('v', substr($data, $p + 32, 2))[1];
            $lho    = (int)unpack('V', substr($data, $p + 42, 4))[1];
            $name   = (string)substr($data, $p + 46, $nlen);
            $p += 46 + $nlen + $elen + $clen;
            if ($name === '' || substr($name, -1) === '/') {
                continue; // 目录条目
            }
            if (substr($data, $lho, 4) !== "\x50\x4b\x03\x04") {
                throw new RuntimeException('zip 条目损坏：' . $name);
            }
            $lnlen  = (int)unpack('v', substr($data, $lho + 26, 2))[1];
            $lelen  = (int)unpack('v', substr($data, $lho + 28, 2))[1];
            $raw    = (string)substr($data, $lho + 30 + $lnlen + $lelen, $csize);
            if ($method === 0) {
                $content = $raw;
            } elseif ($method === 8) {
                $content = @gzinflate($raw);
                if ($content === false) {
                    throw new RuntimeException('zip 条目解压失败：' . $name);
                }
            } else {
                throw new RuntimeException('不支持的压缩方式（条目 ' . $name . '），请用标准 zip（store/deflate）打包');
            }
            $out[$name] = $content;
        }
        return $out;
    }
}

/* ================= 程序文件备份 ================= */

/** 把当前程序文件（不含 data/）打包为 pre-update-*.zip，返回文件名 */
function program_backup_create(string &$name): bool
{
    $root = dirname(DATA_DIR);
    $files = [];
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $f) {
            if (!$f->isFile()) {
                continue;
            }
            $rel = ltrim(substr($f->getPathname(), strlen($root)), '/\\');
            if ($rel === '' || strncmp($rel, 'data/', 5) === 0) {
                continue;
            }
            $c = @file_get_contents($f->getPathname());
            if ($c !== false) {
                $files[] = [$rel, $c];
            }
        }
    } catch (Throwable $t) {
        return false;
    }
    $name = 'pre-update-' . date('Ymd-His') . '.zip';
    Store::ensureDir('backup');
    return MiniZip::create(Store::path('backup/' . $name), $files);
}

/** 只保留最近 $keep 份 pre-update 备份 */
function program_backup_prune(int $keep = 3): void
{
    $fs = array_values(array_filter(Store::scan('backup'), function ($f) {
        return (bool)preg_match('/^pre-update-\d{8}-\d{6}\.zip$/', $f);
    }));
    if (count($fs) <= $keep) {
        return;
    }
    sort($fs);
    foreach (array_slice($fs, 0, count($fs) - $keep) as $f) {
        @unlink(Store::path('backup/' . $f));
    }
}

/* ================= 更新应用 ================= */

/**
 * 从已上传的 zip 文件应用更新
 * @param array $res 输出：['version','from','files','backup','migrate','notes']
 * @throws RuntimeException 失败原因（文件已做部分处理时会说明）
 */
function update_apply(string $zipPath, array &$res): void
{
    $entries = MiniZipRead::read($zipPath);

    if (!isset($entries['update.json'])) {
        throw new RuntimeException('更新包缺少 update.json，不是有效的更新包（完整安装包请解压后 FTP 上传，而不是在此上传）');
    }
    $m = json_decode($entries['update.json'], true);
    if (!is_array($m) || empty($m['version']) || !is_string($m['version'])) {
        throw new RuntimeException('update.json 格式不正确（缺少 version 字段）');
    }
    $new = trim($m['version']);
    if (!preg_match('/^\d+\.\d+(\.\d+)?/', $new)) {
        throw new RuntimeException('版本号格式应为 x.y.z，收到：' . $new);
    }
    $cur = app_version();
    if (version_compare($new, $cur, '<=')) {
        throw new RuntimeException('更新包版本 v' . $new . ' 不高于当前版本 v' . $cur . '，无需更新');
    }

    /* ---- 校验全部条目路径（有任何不安全条目直接整体拒绝） ---- */
    $copy = [];
    $migrate = null;
    foreach ($entries as $name => $content) {
        $name = str_replace('\\', '/', (string)$name);
        if ($name === 'update.json') {
            continue;
        }
        if ($name === '' || $name[0] === '/' || strpos($name, ':') !== false
            || preg_match('#(^|/)\.\.(/|$)#', $name) || preg_match('#(^|/)\.$#', $name)) {
            throw new RuntimeException('更新包含不安全的文件路径：' . $name);
        }
        if ($name === 'install.php' || strncmp($name, 'data/', 5) === 0) {
            throw new RuntimeException('更新包不允许覆盖 install.php 或 data/ 数据目录（条目：' . $name . '）');
        }
        if (strncmp($name, 'update/', 7) === 0) {
            if ($name === 'update/migrate.php') {
                $migrate = $content;
            }
            continue; // update/ 目录仅作为脚本执行，不复制到程序
        }
        $copy[$name] = $content;
    }
    if (!$copy && $migrate === null) {
        throw new RuntimeException('更新包中没有可应用的文件');
    }

    /* ---- 应用前备份当前程序 ---- */
    $bkName = '';
    if (!program_backup_create($bkName)) {
        throw new RuntimeException('更新前备份失败：data/backup 不可写，已中止更新');
    }

    /* ---- 逐条原子写入 ---- */
    $root = dirname(DATA_DIR);
    $applied = 0;
    foreach ($copy as $name => $content) {
        $target = $root . '/' . $name;
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new RuntimeException('无法创建目录：' . $name . '（请检查程序目录权限）');
        }
        $tmp = $target . '.uptmp' . getmypid();
        if (@file_put_contents($tmp, $content) === false || !@rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException('写入文件失败（权限不足？）：' . $name);
        }
        @chmod($target, 0644);
        $applied++;
    }

    /* ---- 执行迁移脚本 ---- */
    $migMsg = '';
    if ($migrate !== null) {
        Store::ensureDir('locks');
        $migFile = Store::path('locks/.migrate-' . bin2hex(random_bytes(4)) . '.php');
        if (@file_put_contents($migFile, $migrate) === false) {
            $migMsg = '迁移脚本写入失败（data 目录不可写？）';
        } else {
            $ctx = ['old' => $cur, 'new' => $new, 'manifest' => $m, 'data_dir' => DATA_DIR];
            try {
                include $migFile;
                $migMsg = '迁移脚本已执行';
            } catch (Throwable $ex) {
                $migMsg = '迁移脚本出错：' . $ex->getMessage();
            }
            @unlink($migFile);
        }
    }

    /* ---- 版本号前进 + 历史 + 日志 ---- */
    $notes = '';
    if (isset($m['notes']) && is_array($m['notes'])) {
        $notes = implode("\n", array_map('strval', $m['notes']));
    } elseif (isset($m['notes']) && is_string($m['notes'])) {
        $notes = $m['notes'];
    }
    cfg_update(['version' => $new]);
    $h = update_history();
    $h[] = [
        'version' => $new, 'from' => $cur, 'time' => time(),
        'files' => $applied, 'notes' => cut_str($notes, 500),
    ];
    Store::write('updates.php', $h);
    program_backup_prune(3);
    if (function_exists('log_action')) {
        log_action('update_apply', 'v' . $cur . ' → v' . $new . '，应用 ' . $applied . ' 个文件'
            . ($migMsg !== '' ? '；' . $migMsg : '') . '；备份：' . $bkName);
    }
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
    $res = ['version' => $new, 'from' => $cur, 'files' => $applied, 'backup' => $bkName, 'migrate' => $migMsg, 'notes' => $notes];
}
