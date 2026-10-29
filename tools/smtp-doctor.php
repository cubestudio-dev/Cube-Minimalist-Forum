<?php
/**
 * Cube Minimalist Forum · SMTP 链路一键体检（v1.43.0 起随包分发）
 *
 * 用法：
 *   php tools/smtp-doctor.php                    # 最小体检（默认测 smtp.qq.com）
 *   php tools/smtp-doctor.php /path/to/forum     # 体检 + 读取论坛 SMTP 配置
 *   php tools/smtp-doctor.php /path/to/forum --fix     # 可修复项自动修复（需 root）
 *   php tools/smtp-doctor.php /path/to/forum --set-ip  # 自动解析并写入后台「直连 IP」
 *   php tools/smtp-doctor.php /path/to/forum --host smtp.163.com
 *
 * 设计目标：以「当前用户」复现论坛发信的完整链路（系统文件 → 域名解析 → TCP/TLS → SMTP），
 * 逐层给出 PASS / FAIL 与精确修复办法；DNS 组件故障（getaddrinfo failed 等）场景下
 * 能指出哪一层兜底可用。只做连接与握手探测，默认不发邮件、不做登录。
 */
if (PHP_SAPI !== 'cli') {
    exit("请在命令行运行本脚本：php tools/smtp-doctor.php\n");
}
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '1');

$ROOT = '';
$OPT_FIX = false;
$OPT_SETIP = false;
$OPT_HOST = '';
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--fix') {
        $OPT_FIX = true;
    } elseif ($a === '--set-ip') {
        $OPT_SETIP = true;
    } elseif (strpos($a, '--host=') === 0) {
        $OPT_HOST = substr($a, 7);
    } elseif (strpos($a, '--host ') === 0) {
        $OPT_HOST = substr($a, 7);
    } elseif ($a !== '' && $a[0] !== '-') {
        $ROOT = rtrim($a, '/');
    }
}

/* ---------- 输出助手 ---------- */
$ISSUES = 0;
function mfdoc_line(string $mark, string $msg): void
{
    echo str_pad($mark, 7) . $msg . "\n";
}
function mfdoc_ok(string $msg): void
{
    mfdoc_line('[ OK ]', $msg);
}
function mfdoc_bad(string $msg): void
{
    global $ISSUES;
    $ISSUES++;
    mfdoc_line('[FAIL]', $msg);
}
function mfdoc_warn(string $msg): void
{
    mfdoc_line('[WARN]', $msg);
}
function mfdoc_info(string $msg): void
{
    mfdoc_line('[ -- ]', $msg);
}
function mfdoc_head(string $t): void
{
    echo "\n== " . $t . " " . str_repeat('=', max(1, 62 - mb_strlen($t))) . "\n";
}

/* ---------- 自包含解析器（与 mailer.php 同逻辑，独立函数名，不依赖论坛代码） ---------- */
function mfdoc_hosts_lookup(string $host): string
{
    $c = @file_get_contents('/etc/hosts');
    if (!is_string($c) || $c === '') {
        return '';
    }
    foreach (preg_split('/\r?\n/', $c) as $line) {
        $line = trim((string)preg_replace('/#.*$/', '', $line));
        if ($line === '') {
            continue;
        }
        $p = preg_split('/\s+/', $line);
        if (count($p) >= 2 && filter_var($p[0], FILTER_VALIDATE_IP) && in_array($host, array_slice($p, 1), true)) {
            return $p[0];
        }
    }
    return '';
}
function mfdoc_dns_servers(): array
{
    $srv = [];
    $c = @file_get_contents('/etc/resolv.conf');
    if (is_string($c) && $c !== '') {
        foreach (preg_split('/\r?\n/', $c) as $line) {
            $line = trim((string)preg_replace('/#.*$/', '', $line));
            if (preg_match('/^nameserver\s+([0-9a-fA-F:.]+)$/', $line, $m) && filter_var($m[1], FILTER_VALIDATE_IP)) {
                $srv[] = $m[1];
            }
        }
    }
    foreach (['223.5.5.5', '119.29.29.29', '114.114.114.114'] as $pub) {
        if (!in_array($pub, $srv, true)) {
            $srv[] = $pub;
        }
    }
    return array_slice($srv, 0, 4);
}
function mfdoc_dns_skip(string $pkt, int $off): int
{
    $n = strlen($pkt);
    while ($off < $n) {
        $len = ord($pkt[$off]);
        if ($len === 0) {
            return $off + 1;
        }
        if (($len & 0xC0) === 0xC0) {
            return $off + 2;
        }
        $off += $len + 1;
    }
    return $n;
}
function mfdoc_dns_query(string $host, float $timeout = 3.0): string
{
    if (!function_exists('fsockopen')) {
        return '';
    }
    $txid = random_bytes(2);
    $q = $txid . "\x01\x00\x00\x01\x00\x00\x00\x00\x00\x00";
    foreach (explode('.', $host) as $seg) {
        $q .= chr(strlen($seg)) . $seg;
    }
    $q .= "\x00\x00\x01\x00\x01";
    foreach (mfdoc_dns_servers() as $ns) {
        $fp = @fsockopen('udp://' . $ns, 53, $eno, $estr, $timeout);
        if (!$fp) {
            continue;
        }
        stream_set_timeout($fp, (int)ceil($timeout));
        @fwrite($fp, $q);
        $resp = (string)@fread($fp, 4096);
        fclose($fp);
        if (strlen($resp) < 12 || strncmp($resp, $txid, 2) !== 0) {
            continue;
        }
        $flags = (int)unpack('n', substr($resp, 2, 2))[1];
        if (($flags & 0x8000) === 0 || ($flags & 0x000F) !== 0) {
            continue;
        }
        $qd = (int)unpack('n', substr($resp, 4, 2))[1];
        $an = (int)unpack('n', substr($resp, 6, 2))[1];
        $off = 12;
        for ($i = 0; $i < $qd; $i++) {
            $off = mfdoc_dns_skip($resp, $off) + 4;
        }
        for ($i = 0; $i < $an && $off < strlen($resp); $i++) {
            $off = mfdoc_dns_skip($resp, $off);
            if ($off + 10 > strlen($resp)) {
                return '';
            }
            $m = unpack('ntype/nclass/nttl/ndlen', substr($resp, $off, 10));
            $off += 10;
            /* v1.48.2 修复：unpack 键名是 dlen（ndlen = 格式码 n + 键名 dlen），
               此前误用 $m['ndlen'] 取不到值（PHP 8 警告刷屏 + 层3 解析永远失败） */
            if ((int)$m['type'] === 1 && (int)$m['dlen'] === 4 && $off + 4 <= strlen($resp)) {
                return long2ip((int)unpack('N', substr($resp, $off, 4))[1]);
            }
            $off += (int)$m['dlen'];
        }
    }
    return '';
}
/** 连接并读 SMTP 问候横幅（只握手不登录） */
function mfdoc_probe(string $host, int $port, bool $secure, string $peer): array
{
    $ctx = stream_context_create(['ssl' => ['peer_name' => $peer, 'verify_peer' => true, 'verify_peer_name' => true]]);
    $remote = ($secure ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $eno, $estr, 8, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        return [false, '连接失败：' . trim($estr) . ' (' . $eno . ')'];
    }
    stream_set_timeout($fp, 6);
    $banner = fgets($fp, 1024);
    @fwrite($fp, "QUIT\r\n");
    fclose($fp);
    if ($banner === false) {
        return [false, '已连接但未读到 SMTP 问候（超时或非 SMTP 端口）'];
    }
    return [true, '已连接，问候：' . trim($banner)];
}

/* ---------- 开始体检 ---------- */
$who = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : (getenv('USER') ?: '?');
echo "Cube Minimalist Forum · SMTP 链路体检\n";
echo '时间 ' . date('Y-m-d H:i:s') . ' · 运行身份 ' . $who . ' · PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ")\n";
echo "提示：论坛发信以 web 进程用户身份运行，如 root 下全通而该身份有 FAIL，请用\n";
echo "     sudo -u <web用户> php tools/smtp-doctor.php 复测，即可复现论坛视角。\n";

/* 第 1 节：运行环境 */
mfdoc_head('第 1 节 · PHP 运行环境');
mfdoc_info('PHP ' . PHP_VERSION . ' · ' . PHP_SAPI . ' · ' . PHP_OS);
if (function_exists('fsockopen')) {
    mfdoc_ok('fsockopen 可用（发信必需）');
} else {
    mfdoc_bad('fsockopen 不可用：php.ini 可能 disable_functions 了它，移除后重启 PHP');
}
if (extension_loaded('openssl')) {
    mfdoc_ok('openssl 扩展已加载（SSL 465 / STARTTLS 必需）');
} else {
    mfdoc_bad('openssl 扩展未加载：465/587 加密发信不可用，请安装/启用 php openssl');
}

/* 第 2 节：系统解析文件 */
mfdoc_head('第 2 节 · 系统解析文件（当前身份可读性）');
$sysfiles = ['/etc/hosts', '/etc/resolv.conf', '/etc/nsswitch.conf'];
$needFix = [];
foreach ($sysfiles as $f) {
    if (!file_exists($f)) {
        mfdoc_warn($f . ' 不存在（hosts / resolv.conf 缺失属异常；nsswitch 缺失部分系统允许）');
        continue;
    }
    $perm = substr(sprintf('%o', fileperms($f)), -4);
    if (is_readable($f)) {
        mfdoc_ok($f . ' 可读（' . $perm . '）');
    } else {
        mfdoc_bad($f . ' 不可读（' . $perm . '）→ 修复：sudo chmod 644 ' . $f);
        $needFix[] = $f;
    }
}
$nss = is_readable('/etc/nsswitch.conf') ? (string)@file_get_contents('/etc/nsswitch.conf') : '';
if ($nss !== '') {
    foreach (explode("\n", $nss) as $l) {
        if (preg_match('/^hosts\s*:\s*(.+)$/', trim($l), $m)) {
            $order = trim($m[1]);
            if (stripos($order, 'files') !== false) {
                mfdoc_ok('nsswitch hosts 顺序：' . $order . '（含 files，/etc/hosts 对系统解析器生效）');
            } else {
                mfdoc_warn('nsswitch hosts 顺序：' . $order . '（不含 files —— /etc/hosts 对系统解析器无效！这正是「写了 hosts 也不生效」的常见原因；程序 v1.43.0 的手工 hosts 层不受影响）');
            }
            break;
        }
    }
}

/* 第 3 节：目标与四层解析 */
$host = $OPT_HOST !== '' ? $OPT_HOST : 'smtp.qq.com';
$port = 465;
$booted = false;
$cfgHost = '';
$cfgPort = 0;
$cfgIp = '';
if ($ROOT !== '' && is_file($ROOT . '/src/bootstrap.php') && is_dir($ROOT . '/data')) {
    /* 引导论坛（CLI 安全）：读取 SMTP 配置，供第 3/4/5 节与 --set-ip 使用 */
    try {
        if (!defined('APP')) {
            define('APP', 1);
        }
        if (!defined('DATA_DIR')) {
            define('DATA_DIR', $ROOT . '/data');
        }
        require $ROOT . '/src/bootstrap.php';
        $booted = true;
        $cfgHost = trim((string)cfg('smtp_host', ''));
        $cfgPort = (int)cfg('smtp_port', 465);
        $cfgIp = trim((string)cfg('smtp_direct_ip', ''));
        if ($OPT_HOST === '' && $cfgHost !== '') {
            $host = $cfgHost; /* 默认用论坛配置的主机 */
        }
        if ($cfgPort > 0 && $OPT_HOST === '') {
            $port = $cfgPort;
        }
    } catch (Throwable $e) {
        mfdoc_warn('论坛配置读取失败（不影响系统层体检）：' . $e->getMessage());
    }
}
if ($booted) {
    mfdoc_info('论坛配置：主机 ' . ($cfgHost !== '' ? $cfgHost : '（未配置）') . ' · 端口 ' . $port . ' · 直连IP ' . ($cfgIp !== '' ? $cfgIp : '（空）'));
}
mfdoc_head('第 3 节 · 域名解析四层（目标 ' . $host . '）');
/* 层A getaddrinfo（系统解析器） */
$ga = @gethostbyname($host);
$gaOk = ($ga !== $host && filter_var($ga, FILTER_VALIDATE_IP));
if ($gaOk) {
    mfdoc_ok('层1 系统解析器（getaddrinfo）：' . $ga);
} else {
    mfdoc_bad('层1 系统解析器（getaddrinfo）失败 —— 这正是「php_network_getaddresses: getaddrinfo failed: System error」的来源');
}
/* 层B /etc/hosts 手工解析 */
$hIp = mfdoc_hosts_lookup($host);
if ($hIp !== '') {
    mfdoc_ok('层2 /etc/hosts 手工解析：' . $hIp . '（程序回退层可直接使用——只要文件可读，不受 nsswitch 顺序影响）');
} else {
    mfdoc_info('层2 /etc/hosts 手工解析：无 ' . $host . ' 记录');
}
/* 层C 纯 PHP UDP 公共 DNS */
$srvs = mfdoc_dns_servers();
$uIp = mfdoc_dns_query($host);
if ($uIp !== '') {
    mfdoc_ok('层3 纯 PHP UDP DNS：' . $uIp . '（nameserver ' . implode(' / ', array_slice($srvs, 0, 3)) . '）');
} else {
    mfdoc_bad('层3 纯 PHP UDP DNS 失败（nameserver ' . implode(' / ', array_slice($srvs, 0, 3)) . '）—— UDP 53 出站被防火墙拦截或全部 nameserver 不可达');
}
$bestIp = $uIp !== '' ? $uIp : ($hIp !== '' ? $hIp : ($gaOk ? $ga : ''));

/* 第 4 节：连接层 */
mfdoc_head('第 4 节 · SMTP 连接（' . $host . ':' . $port . ($port === 465 ? ' SSL' : ' STARTTLS') . '）');
$secure = ($port === 465);
/* 4a 域名直连（正常路径） */
[$okA, $msgA] = mfdoc_probe($host, $port, $secure, $host);
if ($okA) {
    mfdoc_ok('路径A 域名直连：' . $msgA);
} else {
    mfdoc_info('路径A 域名直连：' . $msgA);
}
/* 4b IP + peer_name（IP 直连路径，证书仍按域名校验） */
$okB = false;
if ($bestIp !== '') {
    [$okB, $msgB] = mfdoc_probe($bestIp, $port, $secure, $host);
    if ($okB) {
        mfdoc_ok('路径B IP直连 ' . $bestIp . '（peer_name=' . $host . '）：' . $msgB . ' ——「直连 IP」兜底可用');
    } else {
        mfdoc_bad('路径B IP直连 ' . $bestIp . '：' . $msgB . ' —— IP 也连不上说明端口/防火墙问题，与 DNS 无关');
    }
} else {
    mfdoc_warn('路径B 跳过：没有任何一层能拿到 ' . $host . ' 的 IP');
}

/* 第 5 节：结论 */
mfdoc_head('第 5 节 · 结论与修复建议');
$udpWorks = ($uIp !== '');
if ($gaOk) {
    mfdoc_ok('结论：系统解析正常，论坛发信链路健康，无需任何操作。');
} elseif ($udpWorks || $hIp !== '') {
    mfdoc_warn('结论：系统解析器故障，但兜底层可用（' . ($udpWorks ? '公共 DNS' : '/etc/hosts') . '）。');
    mfdoc_ok('程序 v1.43.0 内置四层回退（域名 → hosts → 公共 DNS → 直连 IP），升级后无需配置即可自动绕行。');
    mfdoc_info('可选根治：检查 resolv.conf / nsswitch.conf / 防火墙；或运行 --fix 让本脚本自动修复可修复项。');
} elseif ($okB) {
    mfdoc_warn('结论：所有 DNS 层均故障，仅 IP 直连可用。');
    mfdoc_bad('请在论坛后台「邮件设置 → 直连 IP」填入 ' . $bestIp . '（证书校验仍按 ' . $host . '，安全不变），或运行 --set-ip 自动写入。');
} else {
    mfdoc_bad('结论：解析与连接全部失败。请检查服务器出站网络（UDP 53 与 TCP ' . $port . ' 是否被防火墙拦截）。');
}

/* ---- 自动修复：--fix ---- */
if ($OPT_FIX) {
    mfdoc_head('自动修复 --fix');
    $isRoot = function_exists('posix_geteuid') ? (posix_geteuid() === 0) : (getmyuid() === 0);
    foreach ($needFix as $f) {
        if (!$isRoot) {
            mfdoc_warn('无 root 权限，请手动执行：sudo chmod 644 ' . $f);
            continue;
        }
        if (@chmod($f, 0644) && is_readable($f)) {
            mfdoc_ok('已修复权限：chmod 644 ' . $f);
        } else {
            mfdoc_bad('修复失败：chmod 644 ' . $f);
        }
    }
    if ($needFix === []) {
        mfdoc_info('系统文件权限无需修复');
    }
    if ($bestIp !== '' && $hIp === '' && $isRoot && is_writable('/etc/hosts')) {
        $bak = '/etc/hosts.mf-bak-' . date('Ymd-His');
        if (@copy('/etc/hosts', $bak) && @file_put_contents('/etc/hosts', $bestIp . "\t" . $host . " # mf-doctor\n", FILE_APPEND) !== false) {
            mfdoc_ok('已写入 /etc/hosts：' . $bestIp . ' ' . $host . '（备份 ' . $bak . '）');
        } else {
            mfdoc_bad('写入 /etc/hosts 失败');
        }
    } elseif ($hIp !== '') {
        mfdoc_info('/etc/hosts 已有记录，无需写入');
    }
}

/* ---- 自动配置：--set-ip ---- */
if ($OPT_SETIP) {
    mfdoc_head('自动配置 --set-ip（写入后台「直连 IP」）');
    if (!$booted) {
        mfdoc_bad('未指定论坛目录（或目录无效），无法写入配置。用法：php tools/smtp-doctor.php /论坛目录 --set-ip');
    } elseif ($cfgHost === '') {
        mfdoc_info('论坛尚未配置 SMTP 主机，跳过直连 IP 写入（先在后台「邮件设置」配置主机）。');
    } elseif ($bestIp === '') {
        mfdoc_bad('当前环境无法解析出 ' . $host . ' 的 IP，未写入。请人工在后台填写。');
    } else {
        $before = (string)cfg('smtp_direct_ip', '');
        if ($before === $bestIp) {
            mfdoc_info('直连 IP 已是 ' . $bestIp . '，无需变更');
        } else {
            cfg_update(['smtp_direct_ip' => $bestIp]);
            /* root 身份写入后把属主还给 data/ 目录属主，避免 web 进程后续无法覆盖 */
            if (function_exists('posix_geteuid') && posix_geteuid() === 0 && function_exists('chown')) {
                $duid = @fileowner(DATA_DIR);
                $cfgFile = DATA_DIR . '/config.php';
                if ($duid !== false && @fileowner($cfgFile) !== $duid) {
                    @chown($cfgFile, $duid);
                }
            }
            mfdoc_ok('已写入论坛配置 smtp_direct_ip = ' . $bestIp . '（原值：' . ($before !== '' ? $before : '空') . '）');
            mfdoc_info('该 IP 由 ' . ($uIp !== '' ? '公共 DNS 实时解析' : ($hIp !== '' ? '/etc/hosts 记录' : '系统解析器（getaddrinfo）')) . ' 得来；SMTP 服务商更换 IP 时请重新运行本命令或后台手改。');
        }
    }
}

mfdoc_head('体检结束');
echo "ISSUES={$ISSUES}\n";
exit($ISSUES > 0 ? 1 : 0);
