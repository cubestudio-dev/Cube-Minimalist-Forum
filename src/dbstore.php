<?php
/**
 * 极简论坛 · 数据库存储引擎（v1.21.0）
 *
 * 支持 SQLite（默认，零配置）/ MySQL / MariaDB / PostgreSQL（PDO）。
 *
 * 数据模型：单表键值文档，键 = 文件模式下的相对路径（含 ".php" 后缀，一一对应），
 * 值 = JSON 字符串原文。因此两种引擎之间可以「字节级无损」双向迁移：
 *   文件 → 库：FileStore::rawJson() 读原文 → writeRaw() 存原文；
 *   库 → 文件：读原文 → FileStore::writeRaw() 写原文。
 *
 * 职责边界（与文件模式完全对齐的可靠性设计）：
 *  - 会话（PHP session）、命名锁（flock）、上传文件（upload/）、日志与备份包
 *    始终存放于文件系统，与所选引擎无关——跨进程互斥不依赖数据库可用性；
 *  - config.php 同样进库；但 storage_engine 这一个键以文件侧配置为准（引导决策键），
 *    引导时从库中读回配置会强制保留文件侧的引擎值，杜绝自锁。
 *
 * 并发安全：
 *  - 写入走 UPDATE→INSERT 重试（唯一键冲突自动重试），MySQL 打开 FOUND_ROWS 保证
 *    「同值更新」也能正确计数；
 *  - SQLite 开启 WAL + busy_timeout，读写互不阻塞。
 */
defined('APP') or exit('Forbidden');

/** 数据库连接配置键（固定存于文件侧 config.php：引导决策与连接都发生在读库之前） */
const DB_CONF_KEYS = ['db_driver', 'db_sqlite_path', 'db_host', 'db_port', 'db_name', 'db_user', 'db_pass', 'db_prefix'];

/** 从文件侧 config.php 读取数据库连接配置（不经过当前引擎路由，任何模式下都准确） */
function db_conf(): array
{
    $c = FileStore::read('config.php', []);
    if (!is_array($c)) {
        $c = [];
    }
    $out = [];
    foreach (DB_CONF_KEYS as $k) {
        $out[$k] = (string)($c[$k] ?? '');
    }
    return $out;
}

class DbStore
{
    /** @var PDO|null 当前连接 */
    private static ?PDO $pdo = null;
    /** 当前驱动类型：sqlite | mysql | pgsql */
    private static string $kind = '';
    /** 表名前缀（默认 mf_，可配置） */
    private static string $prefix = 'mf_';
    /** 最后一次连接 / 操作失败的描述 */
    public static string $lastConnError = '';
    /** 与 FileStore 对齐的写失败信息（供门面同步） */
    public static $lastWriteError = null;
    public static $writeFailures = 0;

    public static function ok(): bool
    {
        return self::$pdo instanceof PDO;
    }

    public static function kind(): string
    {
        return self::$kind;
    }

    public static function lastConnError(): string
    {
        return self::$lastConnError;
    }

    public static function recordWriteError(string $rel, string $why): void
    {
        self::$writeFailures++;
        self::$lastWriteError = $rel . '：' . $why;
        error_log('[DbStore] 写入失败 ' . $rel . ' - ' . $why);
    }

    /** 服务器描述（连接成功后可用）：如「SQLite 3.45」「MySQL 8.0.36」 */
    public static function serverInfo(): string
    {
        if (!self::ok()) {
            return '';
        }
        try {
            return (string)self::$pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (Throwable $t) {
            return '';
        }
    }

    public static function table(): string
    {
        return self::$prefix . 'store';
    }

    /**
     * 建立连接并确保表结构存在。$conf 取自 config（db_* 键）。
     * 任何失败返回 false（不抛出），原因存 $lastConnError。
     */
    public static function connect(array $conf): bool
    {
        self::$lastConnError = '';
        try {
            $driver = strtolower(trim((string)($conf['db_driver'] ?? 'sqlite')));
            if (!in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
                throw new RuntimeException('不支持的数据库类型：' . $driver);
            }
            if (!class_exists('PDO') || !in_array($driver, PDO::getAvailableDrivers(), true)) {
                throw new RuntimeException('PHP 未启用 pdo_' . $driver . ' 扩展（可在 php.ini 开启后重试）');
            }
            $opts = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 5,
            ];
            if ($driver === 'sqlite') {
                $path = trim((string)($conf['db_sqlite_path'] ?? ''));
                if ($path === '') {
                    $path = 'db/forum.sqlite';
                }
                if (!preg_match('#^(/|[a-z]:[\\\\/])#i', $path)) {
                    $path = DATA_DIR . '/' . ltrim($path, '/'); // 相对路径锚定在 data/ 下
                }
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true) || @mkdir($dir, 0777, true);
                }
                if (!is_dir($dir)) {
                    throw new RuntimeException('SQLite 数据目录创建失败：' . $dir);
                }
                $dsn = 'sqlite:' . $path;
                $pdo = new PDO($dsn, null, null, $opts);
                $pdo->exec('PRAGMA journal_mode=WAL');
                $pdo->exec('PRAGMA busy_timeout=5000');
                $pdo->exec('PRAGMA synchronous=NORMAL');
                $pdo->exec('PRAGMA foreign_keys=ON');
            } else {
                $host = trim((string)($conf['db_host'] ?? ''));
                $name = trim((string)($conf['db_name'] ?? ''));
                $user = (string)($conf['db_user'] ?? '');
                $pass = (string)($conf['db_pass'] ?? '');
                $port = (int)($conf['db_port'] ?? 0);
                if ($host === '' || $name === '') {
                    throw new RuntimeException('数据库主机与库名不能为空');
                }
                if ($driver === 'mysql') {
                    $dsn = 'mysql:host=' . $host . ($port > 0 ? ';port=' . $port : '')
                        . ';dbname=' . $name . ';charset=utf8mb4';
                    if (defined('PDO::MYSQL_ATTR_FOUND_ROWS')) {
                        $opts[PDO::MYSQL_ATTR_FOUND_ROWS] = true; // 同值更新 rowCount 也 > 0
                    }
                    if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
                        $opts[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4";
                    }
                } else {
                    $dsn = 'pgsql:host=' . $host . ($port > 0 ? ';port=' . $port : '') . ';dbname=' . $name;
                }
                $pdo = new PDO($dsn, $user, $pass, $opts);
            }

            $pfx = strtolower(trim((string)($conf['db_prefix'] ?? 'mf_')));
            if (!preg_match('/^[a-z0-9_]{0,32}$/', $pfx)) {
                $pfx = 'mf_';
            }
            self::$pdo = $pdo;
            self::$kind = $driver;
            self::$prefix = $pfx !== '' ? $pfx : 'mf_';
            self::ensureSchema();
            return true;
        } catch (Throwable $t) {
            self::$pdo = null;
            self::$kind = '';
            self::$lastConnError = $t->getMessage();
            return false;
        }
    }

    /** 建表（幂等，三引擎各自的类型方言） */
    private static function ensureSchema(): void
    {
        $t = self::table();
        if (self::$kind === 'mysql') {
            $sql = "CREATE TABLE IF NOT EXISTS `{$t}` ("
                . "`k` VARCHAR(191) NOT NULL, "
                . "`v` MEDIUMTEXT NOT NULL, "
                . "`len` INT UNSIGNED NOT NULL DEFAULT 0, "
                . "`mtime` INT UNSIGNED NOT NULL DEFAULT 0, "
                . "`ctime` INT UNSIGNED NOT NULL DEFAULT 0, "
                . "PRIMARY KEY (`k`)"
                . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin";
        } elseif (self::$kind === 'pgsql') {
            $sql = "CREATE TABLE IF NOT EXISTS {$t} ("
                . "k VARCHAR(191) PRIMARY KEY, "
                . "v TEXT NOT NULL, "
                . "len BIGINT NOT NULL DEFAULT 0, "
                . "mtime BIGINT NOT NULL DEFAULT 0, "
                . "ctime BIGINT NOT NULL DEFAULT 0)";
        } else {
            $sql = "CREATE TABLE IF NOT EXISTS {$t} ("
                . "k TEXT PRIMARY KEY, "
                . "v TEXT NOT NULL, "
                . "len INTEGER NOT NULL DEFAULT 0, "
                . "mtime INTEGER NOT NULL DEFAULT 0, "
                . "ctime INTEGER NOT NULL DEFAULT 0)";
        }
        self::$pdo->exec($sql);
    }

    /* ================= 数据读写（与 FileStore 接口对齐） ================= */

    public static function read(string $rel, $def = null)
    {
        if (!self::ok()) {
            return $def;
        }
        try {
            $st = self::$pdo->prepare('SELECT v FROM ' . self::table() . ' WHERE k = ?');
            $st->execute([$rel]);
            $row = $st->fetch();
            if (!is_array($row) || !isset($row['v'])) {
                return $def;
            }
            $v = json_decode((string)$row['v'], true);
            return json_last_error() === JSON_ERROR_NONE ? $v : $def;
        } catch (Throwable $t) {
            self::$lastConnError = $t->getMessage();
            error_log('[DbStore] 读取失败 ' . $rel . ' - ' . $t->getMessage());
            return $def;
        }
    }

    /** 写入（数组自动编码）；UPDATE→INSERT 重试保证并发下唯一键竞争不丽数据 */
    public static function write(string $rel, $data): bool
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            self::recordWriteError($rel, '内容编码失败（可能包含无效 UTF-8 字符）');
            return false;
        }
        return self::writeRaw($rel, $json);
    }

    /** 写入 JSON 原文（迁移通道，与文件内容字节一致） */
    public static function writeRaw(string $rel, string $json): bool
    {
        if (!self::ok()) {
            self::recordWriteError($rel, '数据库未连接');
            return false;
        }
        $len = strlen($json);
        $now = time();
        try {
            for ($i = 0; $i < 3; $i++) {
                try {
                    $st = self::$pdo->prepare('UPDATE ' . self::table() . ' SET v = ?, len = ?, mtime = ? WHERE k = ?');
                    $st->execute([$json, $len, $now, $rel]);
                    if ($st->rowCount() > 0) {
                        return true;
                    }
                    $ins = self::$pdo->prepare('INSERT INTO ' . self::table() . ' (k, v, len, mtime, ctime) VALUES (?, ?, ?, ?, ?)');
                    $ins->execute([$rel, $json, $len, $now, $now]);
                    return true;
                } catch (PDOException $e) {
                    // 并发下另一进程刚插入同键：退避后重试 UPDATE
                    usleep(30000);
                }
            }
            self::recordWriteError($rel, '写入重试 3 次仍未成功（并发冲突）');
            return false;
        } catch (Throwable $t) {
            self::$lastConnError = $t->getMessage();
            self::recordWriteError($rel, $t->getMessage());
            return false;
        }
    }

    public static function delete(string $rel): void
    {
        if (!self::ok()) {
            return;
        }
        try {
            $st = self::$pdo->prepare('DELETE FROM ' . self::table() . ' WHERE k = ?');
            $st->execute([$rel]);
        } catch (Throwable $t) {
            error_log('[DbStore] 删除失败 ' . $rel . ' - ' . $t->getMessage());
        }
    }

    public static function exists(string $rel): bool
    {
        if (!self::ok()) {
            return false;
        }
        try {
            $st = self::$pdo->prepare('SELECT 1 FROM ' . self::table() . ' WHERE k = ?');
            $st->execute([$rel]);
            return (bool)$st->fetch();
        } catch (Throwable $t) {
            return false;
        }
    }

    /** 全部键（按字典序），供迁移 / 统计 / 备份 */
    public static function keysAll(): array
    {
        if (!self::ok()) {
            return [];
        }
        try {
            return self::$pdo->query('SELECT k FROM ' . self::table() . ' ORDER BY k')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $t) {
            return [];
        }
    }

    /** 单键原文（迁移回文件用），不存在返回 null */
    public static function rawJson(string $rel): ?string
    {
        if (!self::ok()) {
            return null;
        }
        try {
            $st = self::$pdo->prepare('SELECT v FROM ' . self::table() . ' WHERE k = ?');
            $st->execute([$rel]);
            $row = $st->fetch();
            return is_array($row) && isset($row['v']) ? (string)$row['v'] : null;
        } catch (Throwable $t) {
            return null;
        }
    }

    /* ================= 后台管理：统计 / 备份 / 优化 / 完整性检查 ================= */

    /** 行数与数据总字节 */
    public static function stats(): array
    {
        $out = ['rows' => 0, 'bytes' => 0];
        if (!self::ok()) {
            return $out;
        }
        try {
            $r = self::$pdo->query('SELECT COUNT(*) AS c, COALESCE(SUM(len), 0) AS b FROM ' . self::table())->fetch();
            if (is_array($r)) {
                $out['rows'] = (int)$r['c'];
                $out['bytes'] = (int)$r['b'];
            }
        } catch (Throwable $t) {
            // 表可能尚未建立
        }
        return $out;
    }

    /** 引擎维护优化：SQLite=VACUUM；MySQL=OPTIMIZE TABLE；PG=VACUUM (ANALYZE)。返回人话结果 */
    public static function optimize(): string
    {
        if (!self::ok()) {
            return '数据库未连接';
        }
        try {
            if (self::$kind === 'sqlite') {
                self::$pdo->exec('VACUUM');
                return 'SQLite 已执行 VACUUM：重建数据文件、回收空闲页';
            }
            if (self::$kind === 'mysql') {
                self::$pdo->exec('OPTIMIZE TABLE `' . self::table() . '`');
                return 'MySQL 已执行 OPTIMIZE TABLE：整理表碎片';
            }
            self::$pdo->exec('VACUUM ANALYZE');
            return 'PostgreSQL 已执行 VACUUM ANALYZE：回收死元组并更新统计';
        } catch (Throwable $t) {
            return '优化失败：' . $t->getMessage();
        }
    }

    /** 完整性检查：SQLite=PRAGMA integrity_check；MySQL=CHECK TABLE；PG=基础探测。返回人话结果 */
    public static function integrityCheck(): string
    {
        if (!self::ok()) {
            return '数据库未连接';
        }
        try {
            if (self::$kind === 'sqlite') {
                $r = self::$pdo->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN) ?: [];
                return $r && $r[0] === 'ok' ? 'SQLite 完整性检查通过（ok）' : 'SQLite 检查结果：' . implode('；', array_slice($r, 0, 5));
            }
            if (self::$kind === 'mysql') {
                $st = self::$pdo->query('CHECK TABLE `' . self::table() . '`');
                $rows = $st ? $st->fetchAll() : [];
                $status = [];
                foreach ($rows as $row) {
                    $status[] = (string)($row['Msg_type'] ?? '') . ':' . (string)($row['Msg_text'] ?? '');
                }
                return 'MySQL 检查结果：' . ($status ? implode('；', $status) : '无返回');
            }
            $n = self::$pdo->query('SELECT COUNT(*) FROM ' . self::table())->fetchColumn();
            return 'PostgreSQL 连接与数据表访问正常（共 ' . (int)$n . ' 行）';
        } catch (Throwable $t) {
            return '检查失败：' . $t->getMessage();
        }
    }

    /* ================= 双向无损迁移 ================= */

    /**
     * 文件 → 数据库。
     * 流程：① 源侧全部数据文件先打备份包到 data/backup/；
     *      ② 逐文件读 JSON 原文写入库（字节级一致）；
     *      ③ 校验行数与键一一对应。
     * 返回 ['ok'=>bool,'total'=>n,'done'=>n,'errors'=>[...],'backup'=>路径]
     */
    public static function migrateFromFiles(): array
    {
        $log = ['ok' => false, 'total' => 0, 'done' => 0, 'errors' => [], 'backup' => ''];
        if (!self::ok()) {
            $log['errors'][] = '数据库未连接，无法迁移';
            return $log;
        }
        $inv = FileStore::inventory();
        $log['total'] = count($inv);
        if ($log['total'] === 0) {
            $log['errors'][] = '文件模式没有任何数据文件可迁移';
            return $log;
        }

        /* ① 迁移前备份：源侧文件原样入包 */
        $stamp = date('Ymd-His');
        $zipName = 'pre-migrate-to-db-' . $stamp . '.zip';
        $files = [];
        foreach ($inv as $rel => $meta) {
            $raw = @file_get_contents(FileStore::path($rel));
            if ($raw !== false) {
                $files[] = [$rel, $raw];
            }
        }
        $files[] = ['__manifest.txt', "Cube Minimalist Forum 迁移备份（文件 → 数据库）\r\n时间：" . date('Y-m-d H:i:s')
            . "\r\n文件数：" . $log['total'] . "\r\n目标：DbStore（" . self::$kind . "）\r\n\r\n本包为迁移前源数据完整快照，可用于手工恢复。"];
        if (!MiniZip::create(FileStore::path('backup/' . $zipName), $files)) {
            $log['errors'][] = '迁移前备份包创建失败（data/backup/ 不可写？）——为保安全已中止迁移';
            return $log;
        }
        $log['backup'] = $zipName;

        /* ② 逐文件写入（键 = 相对路径，值 = JSON 原文） */
        foreach ($inv as $rel => $meta) {
            $raw = FileStore::rawJson($rel);
            if ($raw === null) {
                $log['errors'][] = $rel . '：读取失败';
                continue;
            }
            if (!self::writeRaw($rel, $raw)) {
                $log['errors'][] = $rel . '：写入数据库失败' . (self::$lastConnError !== '' ? '（' . self::$lastConnError . '）' : '');
                continue;
            }
            $log['done']++;
        }

        /* ③ 校验：库中数据键应与源文件清单一致 */
        $keys = array_values(array_filter(self::keysAll(), function ($k) {
            return is_string($k) && substr($k, -4) === '.php';
        }));
        $miss = array_diff(array_keys($inv), $keys);
        foreach (array_slice($miss, 0, 10) as $m) {
            $log['errors'][] = $m . '：校验缺失（库中不存在该键）';
        }
        $log['ok'] = $log['done'] === $log['total'] && count($log['errors']) === 0;
        return $log;
    }

    /**
     * 数据库 → 文件。
     * 流程：① 库内全部行先导出备份包（db-dump.json + manifest）；
     *      ② 逐行写回文件（FileStore::writeRaw 原文直写，字节级一致）；
     *      ③ 校验文件存在。
     */
    public static function migrateToFiles(): array
    {
        $log = ['ok' => false, 'total' => 0, 'done' => 0, 'errors' => [], 'backup' => ''];
        if (!self::ok()) {
            $log['errors'][] = '数据库未连接，无法迁移';
            return $log;
        }
        $keys = array_values(array_filter(self::keysAll(), function ($k) {
            return is_string($k) && substr($k, -4) === '.php';
        }));
        $log['total'] = count($keys);
        if ($log['total'] === 0) {
            $log['errors'][] = '数据库中没有可迁移的数据行';
            return $log;
        }

        /* ① 迁移前备份：库内全部原文导出为 JSON */
        $dump = [];
        foreach ($keys as $k) {
            $v = self::rawJson($k);
            if ($v !== null) {
                $dump[$k] = $v;
            }
        }
        $stamp = date('Ymd-His');
        $zipName = 'pre-migrate-to-file-' . $stamp . '.zip';
        $files = [[
            'db-dump.json',
            json_encode($dump, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], [
            '__manifest.txt',
            "Cube Minimalist Forum 迁移备份（数据库 → 文件）\r\n时间：" . date('Y-m-d H:i:s')
                . "\r\n数据行：" . $log['total'] . "\r\n来源：DbStore（" . self::$kind . "）\r\n\r\n"
                . "db-dump.json 结构：{键: JSON 原文}，可用于手工恢复。",
        ]];
        if (!MiniZip::create(FileStore::path('backup/' . $zipName), $files)) {
            $log['errors'][] = '迁移前备份包创建失败（data/backup/ 不可写？）——为保安全已中止迁移';
            return $log;
        }
        $log['backup'] = $zipName;

        /* ② 逐行写回文件 */
        foreach ($dump as $rel => $json) {
            if (!FileStore::writeRaw($rel, $json)) {
                $log['errors'][] = $rel . '：写回文件失败' . (FileStore::$lastWriteError ? '（' . FileStore::$lastWriteError . '）' : '');
                continue;
            }
            $log['done']++;
        }

        /* ③ 校验 */
        foreach (array_slice(array_keys($dump), 0, 500) as $rel) {
            if (!FileStore::exists($rel)) {
                $log['errors'][] = $rel . '：校验缺失（文件未落盘）';
            }
        }
        $log['ok'] = $log['done'] === $log['total'] && count($log['errors']) === 0;
        return $log;
    }
}
