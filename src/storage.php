<?php
/**
 * 极简论坛 · 存储层
 *
 * v1.21.0 架构：
 *  - FileStore：文件存储引擎原语（数据以 JSON 形式存于 data/ 目录，扩展名 .php，带守卫前缀，
 *    即使被 Web 直接访问也不会泄露内容；原子写入 + 命名文件锁）
 *  - DbStore：数据库存储引擎（见 src/dbstore.php，SQLite / MySQL / PostgreSQL，键值与文件一一对应）
 *  - Store：对全站暴露的门面（与 v1.20.0 及之前完全相同的静态接口），
 *    按配置把「数据」路由到当前引擎；文件锁 / 会话 / 目录管理始终由文件系统承载，
 *    保证跨进程互斥的可靠性，与所选引擎无关。
 *
 * 安全设计（v1.21.0 加固）：
 *  - 所有相对路径经 cleanRel() 归一化：拒绝绝对路径 / 上级目录（..）/ 控制字符 / NUL，
 *    纵深防御任何调用侧拼接出的危险路径；
 *  - 写入前 fflush + fsync（函数可用时），掉电 / 进程崩溃不落半截数据；
 *  - data/.htaccess 拒绝一切 Web 访问（bootstrap 自动补建，nginx 用户由 README 指引拦截）。
 */
defined('APP') or exit('Forbidden');

/** 数据文件守卫前缀：直接访问时仅输出 Forbidden 并终止 */
const DATA_GUARD = "<?php exit('Forbidden'); ?>\n";

class FileStore
{
    /** 本次请求最后一次写入失败的描述（相对路径 + 原因），成功写入后不清除；null 表示尚无失败 */
    public static $lastWriteError = null;
    /** 本次请求累计写入失败次数（用于启动自检与友好错误提示） */
    public static $writeFailures = 0;

    /** 相对路径防御性归一化：仅允许站内生成的安全相对路径（反斜杠统一为斜杠） */
    private static function cleanRel(string $rel): string
    {
        $rel = str_replace('\\', '/', $rel);
        if ($rel === '' || $rel === '.') {
            return $rel;
        }
        if ($rel[0] === '/'
            || strpos($rel, '..') !== false
            || preg_match('/[\x00-\x1f\x7f]/', $rel)
            || preg_match('#^[a-z]:#i', $rel)) {
            return "\x00"; /* 不可能存在的路径：后续 is_file 等恒 false，写入恒失败并被记录 */
        }
        return $rel;
    }

    public static function path(string $rel): string
    {
        return DATA_DIR . '/' . self::cleanRel($rel);
    }

    public static function ensureDir(string $rel): void
    {
        if ($rel === '' || $rel === '.') {
            return;
        }
        if (!is_dir(self::path($rel))) {
            self::repairDir($rel);
        }
    }

    /**
     * 目录是否真正可写：以「实际创建并删除一个探针文件」为准。
     * is_writable() 在部分主机上不可靠（ACL / SELinux / 权限位与实际不符），真实探测才是唯一标准。
     */
    public static function dirWritable(string $rel): bool
    {
        $d = self::path($rel === '.' ? '.' : $rel);
        if (!is_dir($d)) {
            return false;
        }
        $probe = $d . '/.probe-' . (getmypid() ?: '0') . '-' . mt_rand(1000, 9999) . '.tmp';
        $fp = @fopen($probe, 'wb');
        if (!$fp) {
            return false;
        }
        @fwrite($fp, 'ok');
        fclose($fp);
        $ok = is_file($probe);
        @unlink($probe);
        return $ok;
    }

    /**
     * 目录自愈（幂等）：依次尝试——
     * 1) 目录不存在 → 递归创建（0775，失败再 0777）
     * 2) 存在但写不进 → chmod 0775 → chmod 0777
     * 3) 路径被同名文件占据且父目录可写 → 移除该文件后重建目录
     * 4) 父目录可写 → 整体搬移重建（见 rebuildViaRename）：rename 只依赖父目录写权限，
     *    是救回「属主不是 PHP 运行账号」目录的最强手段，且能保留目录内已有数据
     * 5) 目录为空且父目录可写 → 删除空目录后重建（步骤 4 的简化兜底）
     * 每一步都用真实写探针验证。修复成功返回 true。
     */
    public static function repairDir(string $rel): bool
    {
        if ($rel === '' || $rel === '.') {
            return self::dirWritable('.');
        }
        $d = self::path($rel);
        // 路径被同名文件占据：父目录可写时移除后重建
        if (is_file($d)) {
            if (self::dirWritable(dirname($rel))) {
                @unlink($d);
            }
        }
        if (!is_dir($d)) {
            if (!@mkdir($d, 0775, true)) {
                @mkdir($d, 0777, true);
            }
        }
        if (self::dirWritable($rel)) {
            return true;
        }
        @chmod($d, 0775);
        if (self::dirWritable($rel)) {
            return true;
        }
        @chmod($d, 0777);
        if (self::dirWritable($rel)) {
            return true;
        }
        // 整体搬移重建（父目录可写即可）：救回属主不对的「非空」目录（如 FTP 解压产生的
        // threads/ 里有旧数据：chmod 因非属主而失败、rmdir 因非空而失败，rename 是唯一出路）
        if (self::dirWritable(dirname($rel)) && self::rebuildViaRename($rel)) {
            return true;
        }
        // 空目录重建（rename 不可用时的最后手段；仅空目录，无数据丢失风险）
        if (self::dirWritable(dirname($rel))) {
            $entries = @scandir($d);
            if (is_array($entries) && count(array_diff($entries, ['.', '..'])) === 0) {
                if (@rmdir($d)) {
                    if (!@mkdir($d, 0775, true)) {
                        @mkdir($d, 0777, true);
                    }
                    return self::dirWritable($rel);
                }
            }
        }
        return self::dirWritable($rel);
    }

    /**
     * 整体搬移重建：旧目录整体 rename 为 data/.broken-xxx（rename 只需要父目录写权限，
     * 不要求对本目录或其内部文件有任何权限）→ 原位新建属主正确的目录 → 把旧目录里的
     * 文件逐个读出写回（读通常不受属主限制；新文件属主即 PHP 运行账号）。
     * 读不出来的文件会保留在 .broken-xxx 中，可通过 FTP 找回，不会丢失。
     */
    private static function rebuildViaRename(string $rel): bool
    {
        $d = self::path($rel);
        $broken = self::path('.broken-' . str_replace('/', '-', $rel) . '-' . substr(md5((string)mt_rand()), 0, 6));
        if (!@rename($d, $broken)) {
            return false; // rename 失败则保持原状，走后续兜底 / 报错路径
        }
        $made = @mkdir($d, 0775, true) || @mkdir($d, 0777, true);
        if (!$made || !self::dirWritable($rel)) {
            // 新目录建不出 / 不可写（父目录权限突变等罕见情况）：还原现场，不留破坏
            if (is_dir($d) && count(array_diff(@scandir($d) ?: [], ['.', '..'])) === 0) {
                @rmdir($d);
            }
            @rename($broken, $d);
            return false;
        }
        // 把旧目录内的文件搬回新目录（data/ 内均为平铺文件，子目录跳过）
        $moved = 0;
        $lost = 0;
        foreach ((@scandir($broken) ?: []) as $f) {
            if ($f === '.' || $f === '..' || !is_file($broken . '/' . $f)) {
                continue;
            }
            $raw = @file_get_contents($broken . '/' . $f);
            if ($raw === false || @file_put_contents($d . '/' . $f, $raw) === false) {
                $lost++;
                continue;
            }
            $moved++;
            @unlink($broken . '/' . $f);
        }
        if ($lost > 0) {
            error_log('[Store] 目录自愈（' . $rel . '）：搬回 ' . $moved . ' 个文件，' . $lost
                . ' 个无法读取，已保留在 ' . basename($broken) . '（可通过 FTP 找回）');
        }
        // 旧目录已空则清掉；仍有读不出的文件则保留 .broken-xxx 供 FTP 恢复
        $left = @scandir($broken);
        if (is_array($left) && count(array_diff($left, ['.', '..'])) === 0) {
            @rmdir($broken);
        }
        return true;
    }

    /** 清理自愈残留的 .broken-* 暂存目录：尽量清空内部文件后删除目录；
     *  仍删不掉的（属主 / 权限不允许）留在返回值里提示用户 FTP 处理 */
    public static function cleanupBrokenDirs(): array
    {
        $left = [];
        // 注意：scan() 只列文件；.broken-* 是目录，必须直接 scandir data/
        foreach ((@scandir(DATA_DIR) ?: []) as $f) {
            if ($f === '.' || $f === '..' || strpos($f, '.broken-') !== 0 || !is_dir(DATA_DIR . '/' . $f)) {
                continue;
            }
            $d = DATA_DIR . '/' . $f;
            @chmod($d, 0775); // 争取目录写权限（用户已在 FTP 修复权限时此步可省）
            $empty = true;
            foreach ((array)@scandir($d) as $e) {
                if ($e === '.' || $e === '..') {
                    continue;
                }
                if (@unlink($d . '/' . $e)) {
                    continue;
                }
                $empty = false;
            }
            if ($empty && @rmdir($d)) {
                continue;
            }
            $left[] = $f;
        }
        return $left;
    }

    public static function exists(string $rel): bool
    {
        return is_file(self::path($rel));
    }

    /** 读原始载荷字符串（守卫前缀剥离 + gzip 自动解压，不做 JSON 解码）；文件不存在 / 损坏返回 null */
    private static function readPayload(string $rel): ?string
    {
        $p = self::path($rel);
        if (!is_file($p)) {
            return null;
        }
        $fp = @fopen($p, 'rb');
        if (!$fp) {
            return null;
        }
        @flock($fp, LOCK_SH);
        $raw = stream_get_contents($fp);
        @flock($fp, LOCK_UN);
        fclose($fp);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        if (strpos($raw, DATA_GUARD) === 0) {
            $raw = substr($raw, strlen(DATA_GUARD));
        }
        /* v1.16.0 透明 gzip：新写入的数据文件为 guard + gzencode(JSON)；
           读时按 gzip 魔数自动解压，旧明文 JSON 依然直接兼容，无需迁移 */
        if (strlen($raw) > 2 && substr($raw, 0, 2) === "\x1f\x8b") {
            $dec = @gzdecode($raw);
            if (!is_string($dec)) {
                return null;
            }
            $raw = $dec;
        }
        return $raw;
    }

    /** v1.21.0 无损迁移用：读取文件内 JSON 字符串原文（不解码 / 不重编码，字节级原样） */
    public static function rawJson(string $rel): ?string
    {
        return self::readPayload($rel);
    }

    public static function read(string $rel, $def = null)
    {
        $raw = self::readPayload($rel);
        if ($raw === null) {
            return $def;
        }
        $v = json_decode($raw, true);
        return json_last_error() === JSON_ERROR_NONE ? $v : $def;
    }

    /** 取最后一次 PHP 警告的操作系统级原因（如 Permission denied），无则空串 */
    private static function osErr(): string
    {
        $e = error_get_last();
        if (!is_array($e) || empty($e['message'])) {
            return '';
        }
        $m = (string)$e['message'];
        $i = strrpos($m, ':');
        $short = $i !== false ? trim(substr($m, $i + 1)) : $m;
        return $short !== '' ? $short : $m;
    }

    /** 单次完整写入（临时文件 + fflush/fsync + 原子 rename）；失败时通过 $err 返回人话原因 */
    private static function writeTmp(string $tmp, string $payload, string $p, string &$err): bool
    {
        $err = '';
        $fp = @fopen($tmp, 'wb');
        if (!$fp) {
            $os = self::osErr();
            $err = '无法创建临时文件' . ($os !== '' ? '（系统原因：' . $os . '）' : '');
            return false;
        }
        @flock($fp, LOCK_EX);
        $w = fwrite($fp, $payload);
        /* v1.21.0 持久化加固：fflush + fsync（PHP >= 8.1），确保数据在返回成功前已落盘，
           掉电 / 宿主异常崩溃不会留下「写成功标记但内容半截」的文件 */
        if ($w !== false) {
            @fflush($fp);
            if (function_exists('fsync')) {
                @fsync($fp);
            }
        }
        @flock($fp, LOCK_UN);
        fclose($fp);
        if ($w === false) {
            $os = self::osErr();
            $err = '临时文件写入失败' . ($os !== '' ? '（系统原因：' . $os . '）' : '（磁盘已满或被占用）');
            return false;
        }
        // Windows 下 rename() 不能覆盖已存在的目标，需先删除；其他系统直接原子替换
        if (DIRECTORY_SEPARATOR === '\\' && is_file($p)) {
            @unlink($p);
        }
        if (!@rename($tmp, $p)) {
            $os = self::osErr();
            $err = '文件落盘失败（rename）' . ($os !== '' ? '（系统原因：' . $os . '）' : '');
            return false;
        }
        return true;
    }

    public static function write(string $rel, $data): bool
    {
        self::ensureDir(dirname($rel));
        $p = self::path($rel);
        $tmp = $p . '.' . (getmypid() ?: 'x') . '.tmp';
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            self::recordWriteError($rel, '内容编码失败（可能包含无效 UTF-8 字符）');
            return false;
        }
        /* v1.16.0 数据占用优化：统一 gzip 压缩落盘（中文 JSON 体积约 -50%～-70%，
           直接缓解 100MB 配额压力）；读侧按魔数自动解压，旧明文完全兼容 */
        $bin = @gzencode($json, 6);
        $payload = DATA_GUARD . (is_string($bin) && $bin !== '' ? $bin : $json);
        $err = '';
        $ok = self::writeTmp($tmp, $payload, $p, $err);
        if (!$ok) {
            // 首次失败：先尝试目录自愈（建目录 / 修权限 / 重建空目录），再重试一次
            $firstErr = $err;
            if (self::repairDir(dirname($rel)) && self::writeTmp($tmp, $payload, $p, $err)) {
                return true;
            }
            @unlink($tmp);
            $dirRel = dirname($rel);
            $os = self::osErr();
            if (!is_dir(self::path($dirRel))) {
                $why = '目录不存在且自动修复未成功' . ($os !== '' ? '（系统原因：' . $os . '）' : '');
            } elseif (!self::dirWritable($dirRel)) {
                $why = '目录不可写，自动修复未成功' . ($os !== '' ? '（系统原因：' . $os . '）' : '')
                    . '；可到后台「监控 → 环境自检」点一键修复，或通过 FTP 将 data/ 及其子目录权限设为 755 / 775';
            } else {
                $why = $err !== '' ? $err : $firstErr;
            }
            self::recordWriteError($rel, $why);
            return false;
        }
        return true;
    }

    /**
     * v1.21.0 无损迁移用：把「JSON 字符串原文」直接写入数据文件（不重新编码），
     * 与 rawJson() 配对实现 文件 ⇄ 数据库 的字节级无损往返。
     */
    public static function writeRaw(string $rel, string $json): bool
    {
        self::ensureDir(dirname($rel));
        $p = self::path($rel);
        $tmp = $p . '.' . (getmypid() ?: 'x') . '.tmp';
        $bin = @gzencode($json, 6);
        $payload = DATA_GUARD . (is_string($bin) && $bin !== '' ? $bin : $json);
        $err = '';
        if (self::writeTmp($tmp, $payload, $p, $err)) {
            return true;
        }
        if (self::repairDir(dirname($rel)) && self::writeTmp($tmp, $payload, $p, $err)) {
            return true;
        }
        @unlink($tmp);
        self::recordWriteError($rel, $err !== '' ? $err : '写入失败');
        return false;
    }

    /** 记录写失败详情：供发帖 / 回复等操作给出可读错误，而非静默丢失 */
    public static function recordWriteError(string $rel, string $why): void
    {
        self::$writeFailures++;
        self::$lastWriteError = $rel . '：' . $why;
        error_log('[Store] 写入失败 ' . $rel . ' - ' . $why);
    }

    /** 数据目录整体是否可写（真实写探针，is_writable 在部分主机不可靠） */
    public static function writable(): bool
    {
        return is_dir(DATA_DIR) && self::dirWritable('.');
    }

    public static function delete(string $rel): void
    {
        $p = self::path($rel);
        if (is_file($p)) {
            @unlink($p);
        }
    }

    /** 阻塞式命名锁（带超时），返回锁句柄或 false（锁不可用时降级放行，不阻断业务）
     *  v1.21.0：重试加入随机抖动，多进程同时等待同一把锁时避免「惊群」空转 */
    public static function lock(string $name, int $timeout = 5)
    {
        self::ensureDir('locks');
        $fp = @fopen(self::path('locks/' . $name . '.lock'), 'c');
        if (!$fp) {
            return false;
        }
        $deadline = microtime(true) + $timeout;
        while (true) {
            if (@flock($fp, LOCK_EX | LOCK_NB)) {
                return $fp;
            }
            if (microtime(true) >= $deadline) {
                fclose($fp);
                return false;
            }
            usleep(50000 + mt_rand(0, 30000));
        }
    }

    public static function unlock($fp): void
    {
        if ($fp) {
            @flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /** 非阻塞尝试锁：拿到返回句柄，拿不到立即返回 false */
    public static function tryLock(string $name)
    {
        self::ensureDir('locks');
        $fp = @fopen(self::path('locks/' . $name . '.lock'), 'c');
        if ($fp && @flock($fp, LOCK_EX | LOCK_NB)) {
            return $fp;
        }
        if ($fp) {
            fclose($fp);
        }
        return false;
    }

    /** 列出某目录下所有文件名（不含子目录） */
    public static function scan(string $dirRel): array
    {
        $d = self::path($dirRel);
        if (!is_dir($d)) {
            return [];
        }
        $out = [];
        foreach (scandir($d) ?: [] as $f) {
            if ($f !== '.' && $f !== '..' && is_file($d . '/' . $f)) {
                $out[] = $f;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * v1.21.0：数据文件清单（递归，跳过会话 / 锁 / 日志 / 备份 / 上传 / 数据库等文件系统专属目录）。
     * 仅收录 .php 数据文件 —— 论坛全部业务数据（帖子 / 用户 / 配置等）均为 .php 守卫文件；
     * 非数据文件（index.html、lock/install.lock 安装标记等）始终属于文件系统。
     * 返回 [相对路径 => ['size'=>字节,'mtime'=>时间戳]]，供存储引擎迁移与统计使用。
     */
    public static function inventory(array $skipDirs = ['sessions', 'locks', 'logs', 'backup', 'upload', 'db']): array
    {
        $out = [];
        $walk = function (string $dir, string $relPrefix) use (&$walk, &$out, $skipDirs): void {
            foreach ((@scandir(DATA_DIR . ($dir === '' ? '' : '/' . $dir)) ?: []) as $f) {
                if ($f === '.' || $f === '..' || $f[0] === '.') {
                    continue;
                }
                $rel = $dir === '' ? $f : $dir . '/' . $f;
                $full = DATA_DIR . '/' . $rel;
                if (is_dir($full)) {
                    if (!in_array($rel, $skipDirs, true) && strpos($rel, '.broken-') !== 0) {
                        $walk($rel, $relPrefix);
                    }
                    continue;
                }
                if (is_file($full) && substr($f, -4) === '.php') {
                    $out[$rel] = ['size' => (int)@filesize($full), 'mtime' => (int)@filemtime($full)];
                }
            }
        };
        $walk('', '');
        ksort($out);
        return $out;
    }

    /** v1.21.0：数据体积汇总（['files'=>N,'bytes'=>B]），跳过文件系统专属目录 */
    public static function stats(array $skipDirs = ['sessions', 'locks', 'logs', 'backup', 'upload', 'db']): array
    {
        $files = 0;
        $bytes = 0;
        foreach (self::inventory($skipDirs) as $i) {
            $files++;
            $bytes += (int)$i['size'];
        }
        return ['files' => $files, 'bytes' => $bytes];
    }
}

/**
 * 极简 ZIP 生成器（零依赖）
 * 用于「一键备份数据目录」与更新前自动备份，不依赖 ZipArchive 扩展。
 * v1.17.0：逐条尝试 deflate 压缩（gzdeflate），压不小再原样存储（method 0）——
 * 数据目录以中文 JSON 文本为主，备份包体积通常 -50%～-80%。
 */
class MiniZip
{
    /**
     * @param array $files [[条目名, 内容字符串], ...]
     */
    public static function create(string $outPath, array $files): bool
    {
        $local = '';
        $central = '';
        $offset = 0;
        $n = 0;
        $now = getdate();
        $dtime = (($now['hours'] & 0x1f) << 11) | (($now['minutes'] & 0x3f) << 5) | (int)($now['seconds'] / 2);
        $ddate = ((max(0, $now['year'] - 1980) & 0x7f) << 9) | (($now['mon'] & 0xf) << 5) | ($now['mday'] & 0x1f);

        foreach ($files as $f) {
            $name = str_replace('\\', '/', (string)$f[0]);
            $data = (string)$f[1];
            if ($name === '') {
                continue;
            }
            $crc = crc32($data);
            $sz = strlen($data);
            $method = 0;
            $payload = $data;
            if ($sz > 0) {
                $def = @gzdeflate($data, 6);
                if (is_string($def) && $def !== '' && strlen($def) < $sz) {
                    $method = 8;
                    $payload = $def;
                }
            }
            $csize = strlen($payload);
            $head = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, $method, $dtime, $ddate, $crc, $csize, $sz, strlen($name), 0) . $name;
            $local .= $head . $payload;
            /* 中央目录 17 个字段：sig / made / need / flags / method / time / date /
               crc / csize / usize / namelen / extralen / commentlen / diskstart / intattr / extattr / offset */
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, $method, $dtime, $ddate, $crc, $csize, $sz, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
            $offset += strlen($head) + $csize;
            $n++;
        }
        $eocd = pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($central), $offset, 0);
        $dir = dirname($outPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return (bool)@file_put_contents($outPath, $local . $central . $eocd);
    }
}

/* ================================================================
 * v1.21.0 全站存储门面：与 v1.20.0 及之前完全一致的静态接口。
 * 数据读写按当前引擎路由（file / db）；文件锁、目录管理与 scan 恒走文件系统
 * （会话、锁、备份、上传与日志始终存放于文件系统，与所选引擎无关）。
 * ================================================================ */
class Store
{
    /** 当前数据引擎：'file' | 'db'（bootstrap 按配置初始化；DB 连接失败自动回退 file） */
    public static string $drv = 'file';

    /** 兼容旧代码的写失败信息（来自当前引擎驱动） */
    public static $lastWriteError = null;
    public static $writeFailures = 0;

    /** 请求级读取缓存：rel => 数据；write() 成功后自动同步，取锁时全部失效 */
    private static array $memo = [];

    /* ---------- 引擎切换（bootstrap 调用） ---------- */

    /**
     * 尝试启用数据库引擎：连接成功返回 true 并把 $drv 置为 'db'；
     * 失败返回 false（$drv 保持 'file'，错误原因在 DbStore::lastErr()）。
     */
    public static function useDb(array $conf): bool
    {
        if (!class_exists('DbStore')) {
            return false;
        }
        if (DbStore::connect($conf)) {
            self::$drv = 'db';
            return true;
        }
        return false;
    }

    /** 数据库引擎是否在线（文件引擎恒 true） */
    public static function dbReady(): bool
    {
        return self::$drv === 'db' && DbStore::ok();
    }

    /** 引擎内部名（file / db），供后台展示 */
    public static function engine(): string
    {
        return self::$drv;
    }

    private static function syncDrvErrs(): void
    {
        if (self::$drv === 'db') {
            self::$lastWriteError = DbStore::$lastWriteError;
            self::$writeFailures = DbStore::$writeFailures;
        } else {
            self::$lastWriteError = FileStore::$lastWriteError;
            self::$writeFailures = FileStore::$writeFailures;
        }
    }

    /* ---------- 数据读写（按引擎路由，带请求级缓存） ---------- */

    public static function readMemo(string $rel, $def = null)
    {
        if (!array_key_exists($rel, self::$memo)) {
            self::$memo[$rel] = self::read($rel, $def);
        }
        return self::$memo[$rel];
    }

    public static function read(string $rel, $def = null)
    {
        $v = self::$drv === 'db' ? DbStore::read($rel, $def) : FileStore::read($rel, $def);
        self::syncDrvErrs();
        return $v;
    }

    public static function write(string $rel, $data): bool
    {
        $ok = self::$drv === 'db' ? DbStore::write($rel, $data) : FileStore::write($rel, $data);
        self::syncDrvErrs();
        if ($ok) {
            self::$memo[$rel] = $data; // 写后同步缓存，同请求内后续读免 IO
        }
        return $ok;
    }

    public static function delete(string $rel): void
    {
        if (self::$drv === 'db') {
            DbStore::delete($rel);
        } else {
            FileStore::delete($rel);
        }
        self::syncDrvErrs();
        unset(self::$memo[$rel]);
    }

    public static function exists(string $rel): bool
    {
        return self::$drv === 'db' ? DbStore::exists($rel) : FileStore::exists($rel);
    }

    /** 清空请求级缓存（取锁时调用——进入临界区后必须读最新值） */
    public static function memoFlushAll(): void
    {
        self::$memo = [];
    }

    /* ---------- 命名锁：恒走文件系统（跨进程互斥的可靠性不随引擎变化） ---------- */

    public static function lock(string $name, int $timeout = 5)
    {
        self::memoFlushAll();
        return FileStore::lock($name, $timeout);
    }

    public static function unlock($fp): void
    {
        FileStore::unlock($fp);
    }

    public static function tryLock(string $name)
    {
        self::memoFlushAll();
        return FileStore::tryLock($name);
    }

    /* ---------- 文件系统专属操作：恒走文件系统 ---------- */

    public static function path(string $rel): string
    {
        return FileStore::path($rel);
    }

    public static function ensureDir(string $rel): void
    {
        FileStore::ensureDir($rel);
    }

    public static function dirWritable(string $rel): bool
    {
        return FileStore::dirWritable($rel);
    }

    public static function repairDir(string $rel): bool
    {
        return FileStore::repairDir($rel);
    }

    public static function cleanupBrokenDirs(): array
    {
        return FileStore::cleanupBrokenDirs();
    }

    /** 文件名清单（恒走文件系统；现有全部调用点均为会话 / 日志 / 备份等 fs 目录） */
    public static function scan(string $dirRel): array
    {
        return FileStore::scan($dirRel);
    }

    /** 存储整体健康：文件引擎=数据目录真实可写；数据库引擎=连接可用 */
    public static function writable(): bool
    {
        return self::$drv === 'db' ? DbStore::ok() : FileStore::writable();
    }

    public static function recordWriteError(string $rel, string $why): void
    {
        if (self::$drv === 'db') {
            DbStore::recordWriteError($rel, $why);
        } else {
            FileStore::recordWriteError($rel, $why);
        }
        self::syncDrvErrs();
    }
}
