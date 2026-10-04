<?php
/**
 * 极简论坛 · 全员操作日志系统
 * - 所有用户（含游客）的注册/登录/发帖/回复/点赞/举报/申诉/后台管理等操作均记录在案
 * - 按天分文件：data/logs/log-YYYY-MM-DD.php（守卫前缀 + 每行一条 JSON，追加写入）
 * - 自动清理：按「保留天数」（后台可设）每天清理一次，并对日志目录总量设上限
 * - 后台「日志」页可按日期 / 用户名 / IP / 动作筛选并分页查看
 */
defined('APP') or exit('Forbidden');

/** 动作 → 中文标签（后台筛选下拉与表格展示用） */
const LOG_ACTIONS = [
    'install'              => '安装论坛',
    'update_apply'         => '安装更新包',
    'view'                 => '浏览页面',
    'login'                => '登录',
    'login_failed'         => '登录失败',
    'logout'               => '退出登录',
    'register'             => '注册账号',
    'code_send'            => '发送验证码',
    'pass_reset'           => '重置密码',
    'pass_change'          => '修改密码',
    'profile_save'         => '修改资料',
    'thread_new'           => '发布帖子',
    'reply_new'            => '发表回复',
    'thread_delete'        => '删除帖子',
    'reply_delete'         => '删除回复',
    'like'                 => '点赞',
    'unlike'               => '取消点赞',
    'report'               => '举报内容',
    'appeal'               => '提交申诉',
    'admin_save_basic'     => '后台·基本设置',
    'admin_save_mail'      => '后台·邮件设置',
    'admin_test_mail'      => '后台·测试发信',
    'admin_save_ai'        => '后台·AI 设置',
    'admin_test_ai'        => '后台·测试 AI',
    'admin_board_new'      => '后台·新建板块',
    'admin_board_save'     => '后台·保存板块',
    'admin_board_del'      => '后台·删除板块',
    'admin_board_move'     => '后台·板块排序',
    'admin_user_mute'      => '后台·禁言用户',
    'admin_user_ban'       => '后台·封禁账号',
    'admin_user_unban'     => '后台·解封账号',
    'admin_user_role'      => '后台·用户角色',
    'admin_thread_op'      => '后台·帖子操作',
    'admin_reply_delete'   => '后台·删除回复',
    'admin_queue_run'      => '后台·AI 审核',
    'admin_queue_restore'  => '后台·队列恢复',
    'admin_queue_delete'   => '后台·队列删除',
    'admin_manual_restore' => '后台·人工恢复',
    'admin_manual_delete'  => '后台·人工删除',
    'admin_ann_save'       => '后台·公告保存',
    'admin_ann_del'        => '后台·公告删除',
    'admin_save_theme'     => '后台·主题设置',
    'admin_save_monitor'   => '后台·监控设置',
    'admin_test_monitor'   => '后台·测试告警',
    'admin_repair_dirs'    => '后台·修复目录权限',
    'admin_backup'         => '后台·数据备份',
    'admin_backup_dl'      => '后台·下载备份',
    'admin_backup_del'     => '后台·删除备份',
    'admin_update'         => '后台·上传更新包',
    'admin_logs_settings'  => '后台·日志设置',
    'admin_logs_clear'     => '后台·清理日志',
    'ai_ok'                => 'AI·审核无问题',
    'ai_bad'               => 'AI·判定违规',
    'ai_failed'            => 'AI·审核失败',
    'storage_alert'        => '系统·存储告警',
    'sys_gc'               => '系统·自动清理',
    'sys_error'            => '系统·操作异常',
];

function log_label(string $action): string
{
    return LOG_ACTIONS[$action] ?? $action;
}

/**
 * 记录一条操作日志（任何失败都不影响主业务）
 * @param string   $action 动作标识（见 LOG_ACTIONS）
 * @param string   $detail 详情（自动截断 300 字）
 * @param int|null $uid    操作者 uid（默认取当前登录用户，游客为 0）
 * @param string   $name   操作者名称（默认当前用户名 / 游客）
 */
function log_action(string $action, string $detail = '', ?int $uid = null, string $name = ''): void
{
    try {
        if (!is_dir(DATA_DIR . '/logs')) {
            Store::ensureDir('logs'); // 走自愈阶梯（建目录/修权限/整体搬移重建），避免属主异常时日志静默丢失
        }
        if ($uid === null) {
            $u = function_exists('current_user') ? current_user() : null;
            $uid = $u ? (int)$u['id'] : 0;
            $name = $u ? (string)$u['name'] : '游客';
        } elseif ($name === '') {
            $name = $uid > 0 ? uname($uid) : '游客';
        }
        $entry = [
            't'      => time(),
            'uid'    => $uid,
            'name'   => $name,
            'ip'     => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            'action' => $action,
            'detail' => cut_str($detail, 300),
        ];
        $f = DATA_DIR . '/logs/log-' . date('Y-m-d') . '.php';
        $lk = Store::lock('logs', 3);
        if (!is_file($f)) {
            @file_put_contents($f, DATA_GUARD);
        }
        @file_put_contents($f, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        Store::unlock($lk);

        // 每天第一次写日志时顺带执行一次自动清理（零成本巡检）
        $st = Store::read('log_state.php', []);
        if (!is_array($st) || (string)($st['pruned'] ?? '') !== date('Y-m-d')) {
            Store::write('log_state.php', ['pruned' => date('Y-m-d')]);
            log_prune(max(0, (int)cfg('log_days', 90)));
        }
    } catch (Throwable $t) {
        // 日志写入失败静默，不影响业务
    }
}

/** 列出全部日志文件名（新日期在前），形如 log-2026-10-03.php */
function log_files(): array
{
    $fs = array_values(array_filter(Store::scan('logs'), function ($f) {
        return (bool)preg_match('/^log-\d{4}-\d{2}-\d{2}\.php$/', $f);
    }));
    rsort($fs);
    return $fs;
}

/**
 * 清理过期日志（保留天数 0 = 永久保留；同时保证日志目录总量 ≤ 20MB，超出时从最旧删起）
 * @return int 删除的文件数
 */
function log_prune(int $days): int
{
    $files = log_files(); // 新→旧
    $deleted = 0;
    if ($days > 0) {
        $cut = date('Y-m-d', time() - $days * 86400);
        foreach ($files as $f) {
            $d = substr($f, 4, 10);
            if ($d < $cut && @unlink(Store::path('logs/' . $f))) {
                $deleted++;
            }
        }
    }
    // 总量保护：100MB 空间里给日志的上限 20MB
    $maxTotal = 20 * 1048576;
    $total = 0;
    $sizes = [];
    foreach (log_files() as $f) {
        $s = (int)@filesize(Store::path('logs/' . $f));
        $sizes[$f] = $s;
        $total += $s;
    }
    foreach (log_files() as $f) { // 从最旧开始删（log_files 新→旧，倒序遍历）
        if ($total <= $maxTotal) {
            break;
        }
        if (isset($sizes[$f]) && @unlink(Store::path('logs/' . $f))) {
            $total -= $sizes[$f];
            $deleted++;
        }
    }
    return $deleted;
}

/** 日志目录统计：[文件数, 总字节] */
function log_stats(): array
{
    $n = 0;
    $s = 0;
    foreach (log_files() as $f) {
        $n++;
        $s += (int)@filesize(Store::path('logs/' . $f));
    }
    return [$n, $s];
}

/**
 * 读取某天日志（最新在前，支持筛选与分页）
 * @param string $qUser   用户名包含（也支持完整 IP 精确匹配）
 * @param string $qAction 动作标识（空=全部）
 * @param int    $total   输出：筛选后总条数
 */
function log_read(string $date, int $per, int $page, string $qUser, string $qAction, int &$total = 0): array
{
    $total = 0;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return [];
    }
    $f = DATA_DIR . '/logs/log-' . $date . '.php';
    if (!is_file($f)) {
        return [];
    }
    $raw = (string)@file_get_contents($f);
    if ($raw === '') {
        return [];
    }
    if (strpos($raw, DATA_GUARD) === 0) {
        $raw = substr($raw, strlen(DATA_GUARD));
    }
    $out = [];
    foreach (explode("\n", $raw) as $ln) {
        $ln = trim($ln);
        if ($ln === '') {
            continue;
        }
        $j = json_decode($ln, true);
        if (!is_array($j) || !isset($j['action'])) {
            continue;
        }
        if ($qUser !== ''
            && stripos((string)($j['name'] ?? ''), $qUser) === false
            && (string)($j['ip'] ?? '') !== $qUser) {
            continue;
        }
        if ($qAction !== '' && (string)$j['action'] !== $qAction) {
            continue;
        }
        $out[] = $j;
    }
    $out = array_reverse($out);
    $total = count($out);
    $off = max(0, $page - 1) * max(1, $per);
    return array_slice($out, $off, max(1, $per));
}
