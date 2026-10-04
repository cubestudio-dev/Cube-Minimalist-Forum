<?php
/**
 * 极简论坛 · 服务器性能监控
 * - 存储占用：程序本体 + data 数据目录（虚拟主机总配额一般 100MB，默认按此预警）
 * - 磁盘 / 负载 / PHP 运行信息：只读系统接口，无任何特殊扩展依赖
 * - 存储告警：数据占用超过阈值（默认 95MB）自动邮件通知全部管理员
 *   触发时机：随论坛访问检查（每小时至多一次），持续超限时每 6 小时提醒一次，回落即重置
 */
defined('APP') or exit('Forbidden');

const SYSMON_ALERT_COOLDOWN = 21600; // 告警邮件冷却：6 小时
const SYSMON_CHECK_INTERVAL = 3600;  // 随访问检查的间隔：1 小时

/** 程序本体体积（不含 data/）与数据目录体积，单位字节 */
function sysmon_sizes(): array
{
    static $c = null;
    if ($c !== null) {
        return $c;
    }
    $root = dirname(DATA_DIR);
    $app = 0;
    foreach (scandir($root) ?: [] as $f) {
        if ($f === '.' || $f === '..' || $f === 'data') {
            continue;
        }
        $app += size_of_path($root . '/' . $f);
    }
    $c = [$app, size_of_path(DATA_DIR)];
    return $c;
}

/** 磁盘信息：[总空间, 剩余空间] 字节；不可用时返回 [0, 0] */
function sysmon_disk(): array
{
    $root = dirname(DATA_DIR);
    $total = @disk_total_space($root);
    $free = @disk_free_space($root);
    return [(int)$total, (int)$free];
}

/** 系统负载：1/5/15 分钟，取不到返回 [0,0,0] */
function sysmon_load(): array
{
    if (function_exists('sys_getloadavg')) {
        $l = @sys_getloadavg();
        if (is_array($l) && count($l) >= 3) {
            return [round((float)$l[0], 2), round((float)$l[1], 2), round((float)$l[2], 2)];
        }
    }
    return [0, 0, 0];
}

/**
 * CPU 核心数：/proc/cpuinfo → nproc / getconf（exec 未被禁用时）→ 按 1 核处理。
 * 仅用于「按负载估算 CPU」的回退模式。
 */
function sysmon_cores(): int
{
    static $c = null;
    if ($c !== null) {
        return $c;
    }
    $cores = 0;
    if (@is_readable('/proc/cpuinfo')) {
        $raw = @file_get_contents('/proc/cpuinfo');
        if (is_string($raw) && preg_match_all('/^processor\s*:/m', $raw, $m)) {
            $cores = count($m[0]);
        }
    }
    if ($cores <= 0 && function_exists('exec')) {
        foreach (['nproc', 'getconf _NPROCESSORS_ONLN'] as $cmd) {
            $out = [];
            $rc = -1;
            @exec($cmd . ' 2>/dev/null', $out, $rc);
            if ($rc === 0 && (int)($out[0] ?? 0) > 0) {
                $cores = (int)$out[0];
                break;
            }
        }
    }
    $c = $cores > 0 ? $cores : 1;
    return $c;
}

/**
 * CPU 使用率：
 * 1) 首选 /proc/stat 双采样真实计算（约 0.2 秒窗口，结果缓存 3 秒）
 * 2) /proc 被主机限制时，退回「负载估算」：load1 / 核心数 × 100（上限 100%）
 * 3) 均不可用时 mode=na，前端显示“—”
 * 返回 ['pct' => ?float, 'mode' => 'proc'|'load'|'na']
 */
function sysmon_cpu(): array
{
    static $cache = null;
    static $cacheAt = 0;
    if ($cache !== null && time() - $cacheAt < 3) {
        return $cache;
    }
    $res = ['pct' => null, 'mode' => 'na'];
    if (@is_readable('/proc/stat')) {
        $a = @file_get_contents('/proc/stat');
        if (is_string($a) && $a !== '') {
            usleep(200000); // 200ms 采样窗口：太短会抖动，太长拖慢监控页
            $b = @file_get_contents('/proc/stat');
            if (is_string($b) && $b !== ''
                && preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $a, $m1)
                && preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $b, $m2)) {
                // 字段：user nice system idle iowait
                $total1 = (int)$m1[1] + (int)$m1[2] + (int)$m1[3] + (int)$m1[4] + (int)$m1[5];
                $total2 = (int)$m2[1] + (int)$m2[2] + (int)$m2[3] + (int)$m2[4] + (int)$m2[5];
                $idle1 = (int)$m1[4] + (int)$m1[5];
                $idle2 = (int)$m2[4] + (int)$m2[5];
                if ($total2 > $total1) {
                    $pct = round(($total2 - $total1 - ($idle2 - $idle1)) / ($total2 - $total1) * 100, 1);
                    $res = ['pct' => max(0.0, min(100.0, $pct)), 'mode' => 'proc'];
                }
            }
        }
    }
    if ($res['mode'] === 'na' && function_exists('sys_getloadavg')) {
        $l = @sys_getloadavg();
        if (is_array($l) && (float)$l[0] >= 0) {
            $est = min(100.0, max(0.0, (float)$l[0] / sysmon_cores() * 100));
            $res = ['pct' => round($est, 1), 'mode' => 'load'];
        }
    }
    $cache = $res;
    $cacheAt = time();
    return $res;
}

/** 解析 PHP memory_limit 之类的“K/M/G”缩写字符串为字节，解析不了返回 0 */
function sysmon_memlimit_bytes(string $v): int
{
    $v = strtoupper(trim($v));
    if ($v === '' || $v === '-1') {
        return 0;
    }
    if (!preg_match('/^(\d+)\s*([KMG]?)B?$/i', $v, $m)) {
        return 0;
    }
    $n = (int)$m[1];
    switch (strtoupper($m[2])) {
        case 'K': return $n * 1024;
        case 'M': return $n * 1048576;
        case 'G': return $n * 1073741824;
        default: return $n;
    }
}

/**
 * 内存使用：
 * 1) 首选 /proc/meminfo 的 MemTotal / MemAvailable（mode=host，整机内存）
 * 2) /proc 被限制时退回「PHP 进程内存」：memory_get_usage(true) / memory_limit（mode=proc）
 * 3) 均不可用时 mode=na
 * 返回 ['total' => 字节(0=未知), 'used' => 字节, 'pct' => ?float, 'mode' => 'host'|'proc'|'na']
 */
function sysmon_mem(): array
{
    if (@is_readable('/proc/meminfo')) {
        $raw = @file_get_contents('/proc/meminfo');
        if (is_string($raw) && preg_match('/^MemTotal:\s+(\d+)\s*kB/m', $raw, $t)) {
            $total = (int)$t[1] * 1024;
            $avail = 0;
            if (preg_match('/^MemAvailable:\s+(\d+)\s*kB/m', $raw, $av)) {
                $avail = (int)$av[1] * 1024;
            } elseif (preg_match('/^MemFree:\s+(\d+)\s*kB/m', $raw, $fr)) {
                // 老内核没有 MemAvailable，用 MemFree + Buffers + Cached 估算
                $buf = preg_match('/^Buffers:\s+(\d+)\s*kB/m', $raw, $b1) ? (int)$b1[1] * 1024 : 0;
                $cac = preg_match('/^Cached:\s+(\d+)\s*kB/m', $raw, $b2) ? (int)$b2[1] * 1024 : 0;
                $avail = (int)$fr[1] * 1024 + $buf + $cac;
            }
            $used = max(0, $total - $avail);
            return [
                'total' => $total,
                'used'  => $used,
                'pct'   => $total > 0 ? round($used / $total * 100, 1) : null,
                'mode'  => 'host',
            ];
        }
    }
    // 回退：仅 PHP 进程（共享主机 /proc 被限制时依然有参考价值）
    $used = (int)memory_get_usage(true);
    $lim = sysmon_memlimit_bytes((string)@ini_get('memory_limit'));
    return [
        'total' => $lim,
        'used'  => $used,
        'pct'   => $lim > 0 ? round(min(100.0, $used / $lim * 100), 1) : null,
        'mode'  => 'proc',
    ];
}

/** 监控页实时数据（管理员 AJAX 轮询用）：CPU / 内存 / 磁盘 / 负载 + 论坛自身占用 */
function sysmon_live_payload(): array
{
    [$app, $data] = sysmon_sizes();
    [$dt, $df] = sysmon_disk();
    $cpu = sysmon_cpu();
    $mem = sysmon_mem();
    return [
        'ok'       => true,
        'cpu'      => $cpu['pct'],
        'cpu_mode' => $cpu['mode'],
        'cores'    => sysmon_cores(),
        'load'     => sysmon_load(),
        'mem'      => ['used' => $mem['used'], 'total' => $mem['total'], 'pct' => $mem['pct']],
        'mem_mode' => $mem['mode'],
        'disk'     => ['total' => $dt, 'free' => $df, 'pct' => $dt > 0 ? round(($dt - $df) / $dt * 100, 1) : -1],
        'app'      => $app,
        'data'     => $data,
        'online'   => online_count(),
        'time'     => time(),
    ];
}

/** PHP 运行关键参数 */
function sysmon_php_info(): array
{
    return [
        'version'   => PHP_VERSION,
        'sapi'      => PHP_SAPI,
        'mem'       => (string)@ini_get('memory_limit') ?: '-',
        'max_exec'  => (string)@ini_get('max_execution_time') ?: '-',
        'upload'    => (string)@ini_get('upload_max_filesize') ?: '-',
        'post'      => (string)@ini_get('post_max_size') ?: '-',
        'disk_free' => function_exists('disk_free_space'),
        'curl'      => function_exists('curl_init'),
        'mbstring'  => function_exists('mb_strlen'),
    ];
}

/** 内容规模统计：[帖子, 有回复的帖, 用户, 日志文件数, 日志字节, 备份数] */
function sysmon_content_stats(): array
{
    $threads = count(thread_index());
    // "有回复的帖"：数索引中 replies > 0 的帖子（旧逻辑数 threads/ 目录文件数，实际等于帖子总数，口径错误）
    $withReplies = 0;
    foreach (thread_index() as $t) {
        if ((int)($t['replies'] ?? 0) > 0) {
            $withReplies++;
        }
    }
    [$lf, $lb] = log_stats();
    $backups = count(array_filter(Store::scan('backup'), function ($f) {
        return (bool)preg_match('/^(backup|pre-update)-\d{8}-\d{6}\.zip$/', $f);
    }));
    return [$threads, $withReplies, count(user_all()), $lf, $lb, $backups];
}

/** 告警状态读写（data/sysmon_state.php） */
function sysmon_state(): array
{
    $s = Store::read('sysmon_state.php', []);
    return is_array($s) ? $s : [];
}

function sysmon_state_save(array $s): void
{
    Store::write('sysmon_state.php', $s);
}

/**
 * 存储告警：超过阈值时给全部管理员发邮件
 * 随访问触发（index.php），带检查间隔与冷却时间；后台也可手动触发
 * @param bool $manual 手动触发（跳过检查间隔，仍受冷却约束；$force 时连冷却也跳过）
 * @param bool $force  强制立即发送（测试按钮）
 * @return array [string 级别 ok/alert/quiet/error, string 提示]
 */
function sysmon_storage_alert(bool $manual = false, bool $force = false): array
{
    $on = (int)cfg('monitor_on', 1) === 1;
    if (!$on && !$force) {
        return ['quiet', '存储告警未开启'];
    }
    $threshold = max(1, (int)cfg('monitor_mb', 95)) * 1048576;
    [$app, $data] = sysmon_sizes();
    $total = $app + $data;
    $st = sysmon_state();
    $now = time();
    $over = $total >= $threshold;

    // 未超限：如已回落到阈值以下，重置冷却，静默返回
    if (!$over) {
        if (!empty($st['alerting'])) {
            $st['alerting'] = 0;
            $st['last_check'] = $now;
            sysmon_state_save($st);
        } elseif (!$manual) {
            $st['last_check'] = $now;
            sysmon_state_save($st);
        }
        return ['ok', '存储占用 ' . fmt_bytes($total) . '，未达告警阈值 ' . (int)cfg('monitor_mb', 95) . 'MB'];
    }

    // 冷却期内不重复发送（手动强制除外）
    if (!$force && !empty($st['last_alert']) && $now - (int)$st['last_alert'] < SYSMON_ALERT_COOLDOWN) {
        if (!$manual) {
            $st['last_check'] = $now;
            sysmon_state_save($st);
        }
        return ['quiet', '已超限，告警邮件冷却中（上次发送：' . fmt_dt((int)$st['last_alert']) . '）'];
    }

    // 发送告警邮件
    $mb = round($total / 1048576, 1);
    $th = (int)cfg('monitor_mb', 95);
    $site = (string)cfg('site_name', '论坛');
    $pct = min(100, (int)round($total / (100 * 1048576) * 100));
    $inner = '<p style="margin:0 0 14px">站点总占用已达 <b style="color:#b45309">' . $mb . 'MB</b>，超过告警阈值 <b>' . $th . 'MB</b>（虚拟主机配额通常为 100MB，请尽快清理）。</p>'
        . '<div style="margin:0 0 14px;background:#f4f4f5;border-radius:8px;height:14px;overflow:hidden"><div style="width:' . $pct . '%;height:100%;background:' . ($pct >= 95 ? '#dc2626' : '#f59e0b') . '"></div></div>'
        . '<p style="margin:0 0 8px"><b>占用明细</b></p>'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;margin:0 0 16px">'
        . '<tr><td style="padding:6px 12px;border:1px solid #e5e5ea">程序本体</td><td style="padding:6px 12px;border:1px solid #e5e5ea"><b>' . fmt_bytes($app) . '</b></td></tr>'
        . '<tr><td style="padding:6px 12px;border:1px solid #e5e5ea">数据目录</td><td style="padding:6px 12px;border:1px solid #e5e5ea"><b>' . fmt_bytes($data) . '</b>（其中操作日志 ' . fmt_bytes(sysmon_content_stats()[4]) . '）</td></tr>'
        . '<tr><td style="padding:6px 12px;border:1px solid #e5e5ea">总占用 / 100MB 配额</td><td style="padding:6px 12px;border:1px solid #e5e5ea"><b>' . $pct . '%</b></td></tr>'
        . '</table>'
        . '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 14px;font-size:13px;line-height:1.9;color:#713f12">'
        . '<b>建议操作</b>（后台「系统 / 日志 / 监控」页）：<br>'
        . '1. 清理过期操作日志（日志页「立即清理」或调低保留天数）；<br>'
        . '2. 下载并删除旧的数据备份与更新前备份（保留最近 1-2 份即可）；<br>'
        . '3. 删除不再需要的测试内容；<br>'
        . '4. 如持续紧张，导出数据后联系主机服务商扩容。</div>';
    $body = mail_template('存储告警', $inner, ['note' => '本邮件由论坛监控系统自动发送（阈值 ' . $th . 'MB，冷却 ' . round(SYSMON_ALERT_COOLDOWN / 3600) . ' 小时）。']);
    $sent = 0;
    $lastErr = '';
    $admins = 0;
    foreach (user_all() as $au) {
        if (empty($au['admin'])) {
            continue;
        }
        $admins++;
        $to = (string)($au['email'] ?? '');
        if (!valid_email($to)) {
            continue;
        }
        [$ok, $err] = mail_send($to, "【存储告警】{$site} 占用 {$mb}MB，已超过 {$th}MB", $body);
        if ($ok) {
            $sent++;
        } else {
            $lastErr = $err;
        }
    }
    $st['last_alert'] = $now;
    $st['last_alert_size'] = $total;
    $st['last_alert_sent'] = $sent;
    $st['last_check'] = $now;
    $st['alerting'] = 1;
    sysmon_state_save($st);

    if ($sent > 0) {
        log_action('storage_alert', "存储占用 {$mb}MB 超过阈值 {$th}MB，告警邮件已发送 {$sent}/{$admins} 位管理员", 0, '系统');
        return ['alert', "已超限（{$mb}MB / 阈值 {$th}MB），告警邮件已发送 {$sent}/{$admins} 位管理员"];
    }
    log_action('storage_alert', "存储占用 {$mb}MB 超过阈值 {$th}MB，但告警邮件发送失败" . ($lastErr !== '' ? '：' . cut_str($lastErr, 120) : '（SMTP 未配置或无管理员邮箱）'), 0, '系统');
    return ['error', '已超限但告警邮件发送失败' . ($lastErr !== '' ? '：' . cut_str($lastErr, 100) : '（请检查后台邮件配置与管理员邮箱）')];
}

/** 随访问的低频检查入口（index.php 调用） */
function sysmon_tick(): void
{
    $st = sysmon_state();
    if (time() - (int)($st['last_check'] ?? 0) < SYSMON_CHECK_INTERVAL) {
        return;
    }
    // 非阻塞锁防并发重复检查
    $lk = Store::tryLock('sysmon');
    if (!$lk) {
        return;
    }
    try {
        sysmon_storage_alert(false);
    } catch (Throwable $t) {
        // 监控绝不影响主业务
    }
    Store::unlock($lk);
}
