<?php
/**
 * 极简论坛 · 站点统计（v1.32.0）
 * 聚合两类数据，供后台「监控」页展示完整的流量与使用统计：
 *  - 页面命中（合并自防火墙分钟命中日志 fw_hits_merge()）：PV / 出站流量（实际发送字节）
 *  - 稀疏事件（业务动作点直接 +1）：搜索 / 发帖 / 回复 / 编辑 / 注册 / 登录 / AI 调用与 token
 * 设计要点：
 *  - 高频 PV/流量不走「每请求读改写」——随命中日志每分钟批量合并，平时零写放大；
 *  - 稀疏事件频率低（发帖 / AI 调用等），锁内直写即可；
 *  - 任何失败静默，绝不影响主业务。
 */
defined('APP') or exit('Forbidden');

/** 参与统计的稀疏事件键 */
const STATS_KEYS = ['search', 'thread', 'reply', 'edit', 'reg', 'login', 'ai_calls', 'ai_tok', 'ai_ms'];

/** 统计键的中文说明（监控页展示与图表悬浮用） */
const STATS_LABELS = [
    'pv'       => '页面浏览',
    'bytes'    => '出站流量',
    'search'   => '站内搜索',
    'thread'   => '发布帖子',
    'reply'    => '发表回复',
    'edit'     => '内容编辑',
    'reg'      => '新注册',
    'login'    => '登录次数',
    'ai_calls' => 'AI 调用',
    'ai_tok'   => 'AI tokens',
    'ai_ms'    => 'AI 耗时',
];

function stats_read(): array
{
    $d = Store::read('stats.php', []);
    if (!is_array($d)) {
        $d = [];
    }
    if (!isset($d['days']) || !is_array($d['days'])) {
        $d['days'] = [];
    }
    if (!isset($d['total']) || !is_array($d['total'])) {
        $d['total'] = [];
    }
    return $d;
}

function stats_day_row(array &$days, string $d): array
{
    if (!isset($days[$d]) || !is_array($days[$d])) {
        $days[$d] = [];
    }
    return $days[$d];
}

/** 稀疏事件 +1（低频：业务动作点调用；锁内读改写，失败静默） */
function stats_bump(string $k, int $n = 1): void
{
    if ($n === 0 || !in_array($k, STATS_KEYS, true)) {
        return;
    }
    try {
        $lk = Store::lock('stats', 3);
        $s = stats_read();
        $d = date('Y-m-d');
        $row = stats_day_row($s['days'], $d);
        $row[$k] = ($row[$k] ?? 0) + $n;
        $s['days'][$d] = $row;
        $s['total'][$k] = ($s['total'][$k] ?? 0) + $n;
        Store::write('stats.php', $s, false);
        if ($lk) {
            Store::unlock($lk);
        }
        stats_prune();
    } catch (Throwable $t) {
        /* 统计失败静默 */
    }
}

/** 合并一批页面命中（fw_hits_merge 每分钟批量调用；pv / 出站字节） */
function stats_absorb(string $day, int $pv, int $bytes): void
{
    if ($pv === 0 && $bytes === 0) {
        return;
    }
    try {
        $lk = Store::lock('stats', 3);
        $s = stats_read();
        $row = stats_day_row($s['days'], $day);
        $row['pv'] = ($row['pv'] ?? 0) + $pv;
        $row['bytes'] = ($row['bytes'] ?? 0) + $bytes;
        $s['days'][$day] = $row;
        $s['total']['pv'] = ($s['total']['pv'] ?? 0) + $pv;
        $s['total']['bytes'] = ($s['total']['bytes'] ?? 0) + $bytes;
        Store::write('stats.php', $s, false);
        if ($lk) {
            Store::unlock($lk);
        }
    } catch (Throwable $t) {
        /* 统计失败静默 */
    }
}

/** 保留 60 天（随机 1/50 概率触发清理，摊薄成本） */
function stats_prune(int $keep = 60): void
{
    if (mt_rand(1, 50) !== 1) {
        return;
    }
    try {
        $s = stats_read();
        $cut = date('Y-m-d', time() - max(7, $keep) * 86400);
        $changed = false;
        foreach (array_keys($s['days']) as $d) {
            if ((string)$d < $cut) {
                unset($s['days'][$d]);
                $changed = true;
            }
        }
        if ($changed) {
            $lk = Store::lock('stats', 3);
            Store::write('stats.php', $s, false);
            if ($lk) {
                Store::unlock($lk);
            }
        }
    } catch (Throwable $t) {
        /* 静默 */
    }
}

/** 近 $n 天日序列（图表用）：[['d'=>'2026-10-12','pv'=>..,'bytes'=>.., 各事件键=>..], ...] */
function stats_days(int $n = 14): array
{
    $s = stats_read();
    $out = [];
    for ($i = max(1, $n) - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', time() - $i * 86400);
        $row = isset($s['days'][$d]) && is_array($s['days'][$d]) ? $s['days'][$d] : [];
        $r = ['d' => $d, 'pv' => (int)($row['pv'] ?? 0), 'bytes' => (int)($row['bytes'] ?? 0)];
        foreach (STATS_KEYS as $k) {
            $r[$k] = (int)($row[$k] ?? 0);
        }
        $out[] = $r;
    }
    return $out;
}

/**
 * 汇总卡数据：今日 / 昨日 / 近 7 天 / 累计
 * 键：pv bytes search thread reply edit reg login ai_calls ai_tok（ai_ms 不进卡）
 */
function stats_summary(): array
{
    $s = stats_read();
    $keys = ['pv', 'bytes', 'search', 'thread', 'reply', 'edit', 'reg', 'login', 'ai_calls', 'ai_tok'];
    $sum = function (array $row) use ($keys): array {
        $o = [];
        foreach ($keys as $k) {
            $o[$k] = (int)($row[$k] ?? 0);
        }
        return $o;
    };
    $today = date('Y-m-d');
    $yest = date('Y-m-d', time() - 86400);
    $w = array_fill_keys($keys, 0);
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', time() - $i * 86400);
        $row = isset($s['days'][$d]) && is_array($s['days'][$d]) ? $s['days'][$d] : [];
        foreach ($keys as $k) {
            $w[$k] += (int)($row[$k] ?? 0);
        }
    }
    return [
        'today'    => $sum(isset($s['days'][$today]) && is_array($s['days'][$today]) ? $s['days'][$today] : []),
        'yesterday'=> $sum(isset($s['days'][$yest]) && is_array($s['days'][$yest]) ? $s['days'][$yest] : []),
        'week'     => $w,
        'total'    => $sum($s['total']),
    ];
}
