<?php
/**
 * 极简论坛 · 唯一入口（前端控制器）
 * 部署后首次访问会自动跳转到 install.php 安装向导
 */
define('APP', 1);
require __DIR__ . '/src/bootstrap.php';

/* v1.32.0 流量统计：在最外层加一个计数缓冲——FINAL 阶段拿到的长度 = 实际发送字节
   （gzip 启用即压缩后体积），供命中日志 fw_hit_append() 在响应收尾时计入流量统计 */
ob_start(function ($s, $ph) {
    if ($ph & PHP_OUTPUT_HANDLER_FINAL) {
        $GLOBALS['MF_OUT_BYTES'] = (int)($GLOBALS['MF_OUT_BYTES'] ?? 0) + strlen($s);
    }
    return $s;
});
/* v1.16.0 输出 gzip 压缩：HTML 文本传输体积约 -60%～-80%，
   客户端不支持时 ob_gzhandler 自动降级为不压缩，无需额外判断 */
if (!ob_start('ob_gzhandler')) {
    ob_start();
}

/* v1.32.0 命中日志：响应收尾追加一行（每请求一次微秒级追加，替代旧版每请求 2 次
   全局锁内读改写——高并发 / 慢磁盘下整站排队是「加载异常缓慢」的主因） */
register_shutdown_function('fw_hit_append');

/* 未安装 → 安装向导 */
if (!FileStore::exists('lock/install.lock')) {
    redirect(ua('install.php'));
}

/* 在线统计（最近 N 分钟有活动的会话数） */
online_tick();

/* v1.32.0 命中归并：随机 ~2% 请求触发（后台监控页打开时也确定性触发），
   把上一分钟及更早的命中日志聚合成请求 / PV / 流量统计 */
if (mt_rand(1, 50) <= 2) {
    fw_hits_merge();
}

/* 服务器监控：低频检查存储占用，超阈值自动邮件告警管理员（每小时至多一次） */
sysmon_tick();

/* 过期会话文件低频清理（部分主机 PHP 自带 GC 被关闭，防止 sessions 目录无限增长占用配额） */
sessions_gc();

/* 动作路由（POST 业务操作） */
if (isset($_GET['a'])) {
    $a = preg_replace('/[^a-z_]/', '', (string)$_GET['a']);
    handle_action((string)$a);
}

/* v1.19.0 开放 API：独立出站通道（自管令牌认证 / 限流 / CORS / JSON），
   在页面路由、私密模式与协议门禁之前处理——客户端不走浏览器会话，
   访客可见性由 api_handle() 按与前台完全相同的规则自行判定 */
if (isset($_GET['api']) && is_string($_GET['api']) && $_GET['api'] !== '') {
    require_once __DIR__ . '/src/api.php';
    api_handle(substr(preg_replace('/[^a-z0-9_.]/', '', strtolower((string)$_GET['api'])), 0, 60));
    exit; /* api_handle 内部必出站，此处防御性兜底 */
}

/* v1.15.0：写清浏览了什么页面与具体名称（首页 / 帖子《标题》 / 版块《名称》 / 用户「名」的个人主页 / 后台 · 标签页）
   v1.23.0：链接预取请求（X-MF-Prefetch 头）不是用户真实浏览，不计入
   v1.32.0：开放 API 请求（?api=）不是页面浏览，不再误记为「首页」 */
if ((int)cfg('log_views', 0) === 1 && !isset($_GET['a']) && !isset($_GET['api']) && empty($_SERVER['HTTP_X_MF_PREFETCH'])) {
    $vs = $_GET;
    unset($vs['XTransformPort']);
    $vn = view_page_name($vs);
    if ($vn !== '') {
        log_action('view', '浏览了 ' . $vn);
    }
}

/* 页面路由 */
$p = isset($_GET['p']) && is_string($_GET['p']) ? preg_replace('/[^a-z_]/', '', $_GET['p']) : 'home';
require_once __DIR__ . '/src/pages.php';
require_once __DIR__ . '/src/admin.php';

/* v1.32.0 命中上下文：供响应收尾的 fw_hit_append() 记录「谁在访问什么页面」
   （预取请求标 pf=1，不计入 PV；动作 / API 请求以 a: / api: 前缀区分） */
$GLOBALS['MF_HIT_UID'] = (function_exists('current_user') && current_user()) ? (int)current_user()['id'] : 0;
if (isset($_GET['a'])) {
    $GLOBALS['MF_HIT_P'] = 'a:' . preg_replace('/[^a-z_]/', '', (string)$_GET['a']);
    $GLOBALS['MF_HIT_PF'] = 0;
} elseif (isset($_GET['api'])) {
    $GLOBALS['MF_HIT_P'] = 'api:' . substr(preg_replace('/[^a-z0-9_.]/', '', strtolower((string)$_GET['api'])), 0, 30);
    $GLOBALS['MF_HIT_PF'] = 0;
} else {
    $GLOBALS['MF_HIT_P'] = ($p === '' ? 'home' : $p);
    $GLOBALS['MF_HIT_PF'] = empty($_SERVER['HTTP_X_MF_PREFETCH']) ? 0 : 1;
}

/* v1.18.0：CSS / JS / Ping / 图标属于「资源」，不是「内容页」——
   必须先于私密模式与协议门禁出站。否则开启协议门禁后，未同意协议的访客
   连样式表请求都会被门禁页接管（HTML 冒充 CSS），整站裸奔无样式。 */
if ($p === 'asset') {
    page_asset();
}
if ($p === 'ping') {
    page_ping();
}
if ($p === 'icon') {
    page_icon();
}
/* v1.21.0 SEO 站点地图：资源级端点，先于私密模式与协议门禁出站（仅含公开内容，后台可开关） */
if ($p === 'sitemap') {
    page_sitemap();
}
/* v1.32.0 附件 / 图片出站：文件名随机不可猜测，走扩展名白名单 + 目录防逃逸，
   资源级端点先于门禁出站（帖子内嵌图片必须能加载） */
if ($p === 'media') {
    page_media();
}
/* v1.22.0 拓展：插件静态资源（扩展名白名单 + 目录防逃逸），资源级端点先于门禁出站 */
if ($p === 'plugin' && isset($_GET['file']) && is_string($_GET['file']) && $_GET['file'] !== '') {
    plugin_asset(preg_replace('/[^a-z0-9_]/', '', (string)($_GET['pf'] ?? '')), (string)$_GET['file']);
}

/* 私密论坛模式（v1.14.0）：后台关闭「游客可浏览」时，未登录仅允许 登录 / 注册 / 找回密码 /
   协议页（协议门禁页需要能查看协议，否则同开会锁死访客）；资源端点已在上方先行放行 */
if (!feat_on('guest_browse') && !current_user()
    && !in_array($p === '' ? 'home' : $p, ['login', 'register', 'forgot', 'icon', 'doc', 'ping', 'asset'], true)) {
    flash('err', '本论坛仅限注册用户浏览，请先登录');
    redirect(u('p=login'));
}

/* v1.16.0 协议门禁：后台开启且未同意协议的访客，先确认协议才能进入（登录/注册/协议页除外） */
if (doc_gate_required($p === '' ? 'home' : $p)) {
    page_doc_gate();
    exit;
}

/* v1.32.0 会话早释放（页面渲染性能）：前台 GET 页面渲染前关闭会话——渲染期间不再独占
   会话文件锁，同一用户的并发请求（预取 / Ping / 搜索预览 / 快速连续点击）不再被
   当前页渲染串行阻塞。安全性：渲染所需状态（登录态 / CSRF 令牌 / 语言 / 协议确认）
   均已就绪，仅剩的渲染期会话写点（Flash 消息 / CSS 内联标记 / 浏览量去重）已分别改为
   全局暂存 / Cookie 承载；后台页面与 POST 动作保持完整会话生命周期不受影响。 */
if (!isset($_GET['a']) && !isset($_GET['api']) && $p !== 'admin'
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    csrf_token();                        // 预热：渲染期表单令牌已固化，关闭后不再变化
    $GLOBALS['MF_FLASH'] = take_flash(); // 渲染期提示消息改走全局暂存（take_flash 已适配）
    mf_session_release();
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
    case 'icon':          page_icon(); break; /* 已在上方资源段先行出站，此处仅为兜底 */
    case 'notifications': page_announcements(); break;
    case 'announcements': page_announcements(); break;
    case 'doc':           page_doc(); break;
    case 'api_docs':      page_api_docs(); break;
    case 'mention_api':   page_mention_api(); break;
    case 'ping':          page_ping(); break; /* 已在上方资源段先行出站，此处仅为兜底 */
    case 'asset':         page_asset(); break; /* 已在上方资源段先行出站，此处仅为兜底 */
    case 'admin':         page_admin(); break;
    case 'plugin':        page_plugin(); break; /* v1.22.0 拓展：插件前台路由 p=plugin&pf=<id> */
    default:              page_404();
}

/* AI 审核队列：随访问按需触发（每次至多处理一条，非阻塞锁防并发）
 * AI 自主管理（严全面）：常驻巡逻器不在线时，由访问惰性触发巡逻（有事件才调用 AI，零浪费）
 * v1.17.0：先冲刷输出 + 释放会话文件锁，再跑 AI ——
 * 此前 AI 审核在关机阶段执行时会一直持有当前用户的会话锁，同一用户接下来的
 * 页面请求会被会话锁阻塞到 AI 调用结束，表现为“部分页面打开很慢/卡”。
 * v1.31.0：统一改走 mf_response_finalize()（幂等）——在 v1.17.0 基础上增加
 * FPM fastcgi_finish_request：响应立即真正终结，AI 外呼完全不在请求生命周期内。 */
register_shutdown_function(function () {
    mf_response_finalize();
    ai_process_queue(false);
    ai_autopilot_tick();
});
