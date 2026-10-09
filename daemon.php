<?php
/**
 * 极简论坛 · AI 自主管理常驻巡逻器（可选增强，非必需）
 *
 * 用途：在具备常驻进程能力的主机（云服务器 / 容器 / 有 SSH 的 VPS）上，
 *       以固定节奏 7×24 小时巡逻论坛（AI 自主管理·严全面模式），无需任何访客触发。
 * 无此能力的主机（虚拟主机等）无需任何操作：论坛自动退回「访客触发」巡逻模式，功能完全一致。
 *
 * 启动：php daemon.php                          （前台运行）
 * 后台：nohup php daemon.php > /dev/null 2>&1 &
 * 停止：kill <pid>                              （收到信号后 30 秒内优雅退出）
 *
 * 安全设计：
 *  - 单实例锁（flock）：重复启动直接退出，防止多开；
 *  - 心跳：每 60 秒写入 data/ai_patrol.php，后台实时显示「常驻巡逻器在线」；
 *          心跳中断 180 秒后，Web 端自动接管巡逻（无缝降级，互为备份）；
 *  - 节奏：每 30 秒醒来一次（检查退出信号 / 写心跳 / 处理举报队列），累计到巡逻间隔才真正巡逻；
 *  - 复用：巡逻本体与 Web 端共用同一套 ai_patrol_* 函数与文件锁，绝无并发冲突；
 *          sysmon / sessions_gc / 日志清理等惰性任务也被顺带接管，节奏更稳。
 */
if (PHP_SAPI !== 'cli') {
    exit('Forbidden');
}
define('APP', 1);
define('MF_DAEMON', 1);
require __DIR__ . '/src/bootstrap.php';

/* 单实例锁 */
if (!is_dir(DATA_DIR . '/locks')) {
    @mkdir(DATA_DIR . '/locks', 0775, true);
}
$lockFp = @fopen(DATA_DIR . '/locks/daemon.lock', 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    exit("已有巡逻器实例在运行（多开保护），如需重启请先停止旧进程\n");
}
@ftruncate($lockFp, 0);
@fwrite($lockFp, (string)getmypid());

/* 优雅退出（无 pcntl 扩展时以 Ctrl+C 终止即可） */
$stop = false;
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () use (&$stop) {
        $stop = true;
    });
    pcntl_signal(SIGINT, function () use (&$stop) {
        $stop = true;
    });
}

echo '[' . date('Y-m-d H:i:s') . "] AI 自主管理巡逻器已启动（版本 " . MF_VERSION . "，巡逻间隔 " . ai_patrol_interval() . " 分钟，每 30 秒巡更）\n";

$lastBeat = 0;
while (true) {
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }
    if ($stop) {
        break;
    }
    $now = time();

    /* v1.21.0 后台停止信号：后台「停止巡逻器」写入 data/daemon.stop，本进程 30 秒内检测到后
       优雅退出并自行删除信号文件（无需 kill / SSH，虚拟主机亦可远程控制） */
    if (is_file(DATA_DIR . '/daemon.stop')) {
        @unlink(DATA_DIR . '/daemon.stop');
        echo '[' . date('Y-m-d H:i:s') . "] 收到后台停止信号，巡逻器退出\n";
        break;
    }

    /* 心跳：每 60 秒一次（Web 端据此判断巡逻器是否在线） */
    if ($now - $lastBeat >= 60) {
        $lk = Store::tryLock('ai_patrol');
        if ($lk) {
            $s = ai_patrol_state();
            $s['daemon'] = $now;
            ai_patrol_state_save($s);
            Store::unlock($lk);
        }
        $lastBeat = $now;
    }

    /* 到点巡逻（总开关关闭时静默空转，只写心跳） */
    $s = ai_patrol_state();
    if (ai_autopilot_on() && $now - (int)($s['last'] ?? 0) >= ai_patrol_interval() * 60) {
        $lk = Store::tryLock('ai_patrol');
        if ($lk) {
            $s2 = ai_patrol_state();
            $s2['last'] = $now;
            ai_patrol_state_save($s2);
            Store::unlock($lk);
        }
        $mat = ai_patrol_material();
        if (!$mat['events']) {
            echo '[' . date('H:i:s') . "] 本轮无风险事件，零消耗跳过\n";
        } else {
            $msg = '';
            ai_patrol_go($mat, $msg);
            echo '[' . date('H:i:s') . '] ' . $msg . "\n";
        }
    }

    /* 举报队列随更（常驻模式下处理比 Web 触发更及时；内部自带节流与防并发锁） */
    ai_process_queue(false);

    /* 惰性任务接管：服务器监控（内部自带每小时节流）与会话清理 */
    sysmon_tick();
    sessions_gc();

    sleep(30);
}

@flock($lockFp, LOCK_UN);
@fclose($lockFp);
echo '[' . date('Y-m-d H:i:s') . "] 巡逻器已停止\n";
