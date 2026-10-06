<?php
/**
 * 极简论坛 · 唯一入口（前端控制器）
 * 部署后首次访问会自动跳转到 install.php 安装向导
 */
define('APP', 1);
require __DIR__ . '/src/bootstrap.php';

/* 未安装 → 安装向导 */
if (!Store::exists('lock/install.lock')) {
    redirect(ua('install.php'));
}

/* 在线统计（最近 N 分钟有活动的会话数） */
online_tick();

/* 服务器监控：低频检查存储占用，超阈值自动邮件告警管理员（每小时至多一次） */
sysmon_tick();

/* 过期会话文件低频清理（部分主机 PHP 自带 GC 被关闭，防止 sessions 目录无限增长占用配额） */
sessions_gc();

/* 动作路由（POST 业务操作） */
if (isset($_GET['a'])) {
    $a = preg_replace('/[^a-z_]/', '', (string)$_GET['a']);
    handle_action((string)$a);
}

/* 页面访问日志（后台可开关，记录所有人含游客；仅记录真实页面导航，AJAX 轮询与业务动作不计） */
if ((int)cfg('log_views', 0) === 1 && !isset($_GET['a'])) {
    $vs = $_GET;
    unset($vs['XTransformPort']);
    $vd = '页面 ' . (string)($vs['p'] ?? 'home');
    if (isset($vs['id']) && is_numeric($vs['id'])) {
        $vd .= ' #' . (int)$vs['id'];
    }
    if (isset($vs['tab']) && is_string($vs['tab'])) {
        $vd .= ' · ' . $vs['tab'];
    }
    log_action('view', $vd);
}

/* 页面路由 */
$p = isset($_GET['p']) && is_string($_GET['p']) ? preg_replace('/[^a-z_]/', '', $_GET['p']) : 'home';
require_once __DIR__ . '/src/pages.php';
require_once __DIR__ . '/src/admin.php';

/* 私密论坛模式（v1.14.0）：后台关闭「游客可浏览」时，未登录仅允许 登录 / 注册 / 找回密码 与图标资源 */
if (!feat_on('guest_browse') && !current_user()
    && !in_array($p === '' ? 'home' : $p, ['login', 'register', 'forgot', 'icon'], true)) {
    flash('err', '本论坛仅限注册用户浏览，请先登录');
    redirect(u('p=login'));
}

switch ($p === '' ? 'home' : $p) {
    case 'home':          page_home(); break;
    case 'board':         page_board(); break;
    case 'thread':        page_thread(); break;
    case 'new':           page_new(); break;
    case 'login':         page_login(); break;
    case 'register':      page_register(); break;
    case 'forgot':        page_forgot(); break;
    case 'user':          page_user(); break;
    case 'online':        page_online(); break;
    case 'settings':      page_settings(); break;
    case 'search':        page_search(); break;
    case 'edit':          page_edit(); break;
    case 'icon':          page_icon(); break;
    case 'notifications': page_announcements(); break;
    case 'announcements': page_announcements(); break;
    case 'admin':         page_admin(); break;
    default:              page_404();
}

/* AI 审核队列：随访问按需触发（每次至多处理一条，非阻塞锁防并发）
 * AI 自主管理（严全面）：常驻巡逻器不在线时，由访问惰性触发巡逻（有事件才调用 AI，零浪费） */
register_shutdown_function(function () {
    ai_process_queue(false);
    ai_autopilot_tick();
});
