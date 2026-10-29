<?php
/**
 * 极简论坛 · 动作层（全部 POST 业务操作，含管理员操作）
 * 统一入口：index.php?a=xxx，全部校验 CSRF；AJAX 动作返回 JSON
 */
defined('APP') or exit('Forbidden');

function handle_action(string $a): void
{
    // 备份下载为 GET 链接（管理后台专用），其余动作一律 POST
    if ($a === 'admin_backup_dl') {
        act_admin_backup_dl();
        return;
    }
    // 实时刷新数据源：GET 只读（无 CSRF，不写任何数据），浏览器轮询用
    if ($a === 'live') {
        act_live();
        return;
    }
    if ($a === 'sysmon_live') {
        act_sysmon_live();
        return;
    }
    /* v1.22.0 后台设置搜索：GET 只读 JSON（仅管理员，无 CSRF 需求，不写任何数据） */
    if ($a === 'admin_search') {
        act_admin_search();
        return;
    }
    /* v1.30.0 前台搜索预览：GET 只读 JSON（公开只读，跟随「站内搜索」「搜索预览」两个开关） */
    if ($a === 'search_suggest') {
        act_search_suggest();
        return;
    }
    /* v1.34.0 扫码登录轮询：GET + CSRF 头（确认态会为当前会话建立登录，详见 act_qr_check） */
    if ($a === 'qr_check') {
        act_qr_check();
        return;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        redirect(u('p=home'));
    }
    if (!csrf_ok()) {
        if (is_ajax()) {
            json_response(['ok' => false, 'msg' => '页面已过期，请刷新后重试']);
        }
        flash('err', '页面已过期，请刷新后重试');
        redirect(u('p=home'));
    }

    // 全局异常闸口：写入失败 / 环境异常不再白屏或静默丢失，而是给出可读原因并记入日志
    try {
        $GLOBALS['MF_ACTION'] = $a; /* v1.26.0：admin_tab_guard 按动作名分级把关 */
        route_action($a);
    } catch (Throwable $t) {
        $why = $t instanceof RuntimeException || $t instanceof LogicException ? $t->getMessage() : '服务器内部错误（' . cut_str($t->getMessage(), 120) . '）';
        if (Store::$writeFailures > 0 || stripos($why, '不可写') !== false || stripos($why, '写入失败') !== false) {
            $why .= '。可到后台「监控 → 环境自检」点「一键修复目录权限」；若无效，请通过 FTP 将 data/ 及其子目录权限设为 755 或 775（或确认磁盘未写满）。';
        }
        log_action('sys_error', '动作 ' . $a . ' 失败：' . cut_str($t->getMessage(), 200), 0, '系统');
        if (is_ajax()) {
            json_response(['ok' => false, 'msg' => '操作未完成：' . $why]);
        }
        flash('err', '操作未完成：' . $why);
        back_or(u('p=home'));
    }
}

/** 动作分发表（由 handle_action 的异常闸口包裹调用） */
function route_action(string $a): void
{
    switch ($a) {
        case 'send_code':        act_send_code(); return;
        case 'register':         act_register(); return;
        case 'login':            act_login(); return;
        case 'qr_create':        act_qr_create(); return;   /* v1.34.0 扫码登录：创建一次性令牌 */
        case 'qr_confirm':       act_qr_confirm(); return;  /* v1.34.0 扫码登录：扫码端确认 */
        case 'logout':           act_logout(); return;
        case 'dev_verify':       act_dev_verify(); return;
        case 'dev_cancel':       act_dev_cancel(); return;
        case 'dev_kick':         act_dev_kick(); return;
        case 'dev_kick_others':  act_dev_kick_others(); return;
        case 'dev_verify_toggle': act_dev_verify_toggle(); return;
        case 'forgot':           act_forgot(); return;
        case 'change_pass':      act_change_pass(); return;
        case 'profile_save':     act_profile_save(); return;
        case 'upload':           act_media_upload(); return;
        case 'upload_init':      act_upload_init(); return;    /* v1.47.0 大文件分块上传 */
        case 'upload_chunk':     act_upload_chunk(); return;   /* v1.47.0 分块接收 */
        case 'upload_finish':    act_upload_finish(); return;  /* v1.47.0 合并落位 */
        case 'upload_abort':     act_upload_abort(); return;   /* v1.47.0 中止清理 */
        case 'thread_edit':      act_thread_edit(); return;
        case 'reply_edit':       act_reply_edit(); return;
        case 'thread_new':       act_thread_new(); return;
        case 'reply_new':        act_reply_new(); return;
        case 'thread_delete':    act_thread_delete(); return;
        case 'reply_delete':     act_reply_delete(); return;
        case 'like':             act_like(); return;
        case 'report':           act_report(); return;
        case 'appeal':           act_appeal(); return;
        case 'notify_read':      act_notify_read(); return;
        case 'notify_read_all':  act_notify_read_all(); return;
        case 'account_delete':         act_account_delete(); return;
        case 'account_delete_confirm': act_account_delete_confirm(); return;
        case 'doc_agree':              act_doc_agree(); return;
        case 'api_token_new':         act_api_token_new(); return;
        case 'api_token_revoke':      act_api_token_revoke(); return;
        case 'admin_save_api':         admin_tab_guard(); act_admin_save_api(); return;
        case 'admin_plugin_toggle':    admin_tab_guard(); act_admin_plugin_toggle(); return;
        case 'admin_plugin_remove':    admin_tab_guard(); act_admin_plugin_remove(); return;
        case 'admin_plugin_install':   admin_tab_guard(); act_admin_plugin_install(); return;
        case 'admin_api_revoke':       admin_tab_guard(); act_admin_api_revoke(); return;
        case 'admin_save_docs':    admin_tab_guard(); act_admin_save_docs(); return;
        case 'admin_data_compress': admin_tab_guard(); act_admin_data_compress(); return;
        case 'admin_save_basic': admin_tab_guard(); act_admin_save_basic(); return;
        case 'admin_save_feat':  admin_tab_guard(); act_admin_save_feat(); return;
        case 'admin_save_qr':    admin_tab_guard(); act_admin_save_qr(); return;
        case 'admin_save_security': admin_tab_guard(); act_admin_save_security(); return;
        case 'admin_icon_upload': admin_tab_guard(); act_admin_icon_upload(); return;
        case 'admin_icon_del':    admin_tab_guard(); act_admin_icon_del(); return;
        case 'admin_save_mail':  admin_tab_guard(); act_admin_save_mail(); return;
        case 'admin_test_mail':  admin_tab_guard(); act_admin_test_mail(); return;
        case 'admin_save_ai':    admin_tab_guard(); act_admin_save_ai(); return;
        case 'admin_test_ai':    admin_tab_guard(); act_admin_test_ai(); return;
        /* v1.44.0 功能一键自检 + 自动修复（后台「监控」标签页）；v1.45.0 增加逐项步进实时进度动作 */
        case 'admin_selfcheck':  admin_tab_guard(); act_admin_selfcheck(); return;
        case 'admin_selffix':    admin_tab_guard(); act_admin_selffix(); return;
        case 'admin_sc_step':    admin_tab_guard(); act_admin_sc_step(); return;
        case 'admin_sc_finish':  admin_tab_guard(); act_admin_sc_finish(); return;
        case 'admin_sf_step':    admin_tab_guard(); act_admin_sf_step(); return;
        case 'admin_patrol_now': admin_tab_guard(); act_admin_patrol_now(); return;
        case 'admin_model_new':   admin_tab_guard(); act_admin_model_new(); return;
        case 'admin_model_save':  admin_tab_guard(); act_admin_model_save(); return;
        case 'admin_model_del':   admin_tab_guard(); act_admin_model_del(); return;
        case 'admin_model_move':  admin_tab_guard(); act_admin_model_move(); return;
        case 'admin_model_reset': admin_tab_guard(); act_admin_model_reset(); return;
        case 'admin_board_new':  admin_tab_guard(); act_admin_board_new(); return;
        case 'admin_board_save': admin_tab_guard(); act_admin_board_save(); return;
        case 'admin_board_del':  admin_tab_guard(); act_admin_board_del(); return;
        case 'admin_board_move': admin_tab_guard(); act_admin_board_move(); return;
        case 'admin_board_perm': admin_tab_guard(); act_admin_board_perm(); return;
        case 'admin_restore_defaults': admin_tab_guard(); act_admin_restore_defaults(); return;
        /* v1.36.0 HTML 静态加速 */
        case 'admin_static_mode':     admin_tab_guard(); act_admin_static_mode(); return;
        case 'admin_static_htaccess': admin_tab_guard(); act_admin_static_htaccess(); return;
        case 'admin_static_clear':    admin_tab_guard(); act_admin_static_clear(); return;
        /* v1.37.0 迁移搬家 */
        case 'admin_migrate_build':   admin_tab_guard(); act_admin_migrate_build(); return;
        case 'admin_migrate_delete':  admin_tab_guard(); act_admin_migrate_delete(); return;
        case 'admin_migrate_import':  admin_tab_guard(); act_admin_migrate_import(); return;
        case 'admin_user_mute':  admin_tab_guard(); act_admin_user_mute(); return;
        case 'admin_user_ban':   admin_tab_guard(); act_admin_user_ban(); return;
        case 'admin_user_unban': admin_tab_guard(); act_admin_user_unban(); return;
        case 'admin_user_role':  admin_tab_guard(); act_admin_user_role(); return;
        case 'admin_thread_op':  admin_tab_guard(); act_admin_thread_op(); return;
        case 'admin_reply_delete': admin_tab_guard(); act_admin_reply_delete(); return;
        case 'admin_queue_run':    admin_tab_guard(); act_admin_queue_run(); return;
        case 'admin_queue_restore': admin_tab_guard(); act_admin_queue_restore(); return;
        case 'admin_queue_delete':  admin_tab_guard(); act_admin_queue_delete(); return;
        case 'admin_manual_restore': admin_tab_guard(); act_admin_manual_restore(); return;
        case 'admin_manual_delete':  admin_tab_guard(); act_admin_manual_delete(); return;
        case 'admin_ann_save':  admin_tab_guard(); act_admin_ann_save(); return;
        case 'admin_ann_del':   admin_tab_guard(); act_admin_ann_del(); return;
        case 'admin_save_theme': admin_tab_guard(); act_admin_save_theme(); return;
        case 'admin_save_nav':   admin_tab_guard(); act_admin_save_nav(); return;
        case 'admin_save_monitor': admin_tab_guard(); act_admin_save_monitor(); return;
        case 'admin_test_monitor': admin_tab_guard(); act_admin_test_monitor(); return;
        case 'admin_repair_dirs': admin_tab_guard(); act_admin_repair_dirs(); return;
        case 'admin_envfix':      admin_tab_guard(); act_admin_envfix(); return; /* v1.38.0 运行环境 */
        case 'admin_opcache_ini': admin_tab_guard(); act_admin_opcache_ini(); return;
        case 'admin_robots_sync': admin_tab_guard(); act_admin_robots_sync(); return;
        case 'admin_file_download': admin_tab_guard(); act_admin_file_download(); return; /* v1.35.1 降级下载 */
        case 'admin_backup':    admin_tab_guard(); act_admin_backup(); return;
        case 'admin_backup_del': admin_tab_guard(); act_admin_backup_del(); return;
        case 'admin_update':        admin_tab_guard(); act_admin_update(); return;
        case 'admin_update_rollback': admin_tab_guard(); act_admin_update_rollback(); return;
        case 'admin_logs_settings': admin_tab_guard(); act_admin_logs_settings(); return;
        case 'admin_logs_clear':    admin_tab_guard(); act_admin_logs_clear(); return;
        case 'admin_fw_on':           admin_tab_guard(); act_admin_fw_on(); return;
        case 'admin_fw_save':         admin_tab_guard(); act_admin_fw_save(); return;
        case 'admin_fw_intel_save':   admin_tab_guard(); act_admin_fw_intel_save(); return;
        case 'admin_fw_intel_sync':   admin_tab_guard(); act_admin_fw_intel_sync(); return;
        case 'admin_fw_intel_import': admin_tab_guard(); act_admin_fw_intel_import(); return;
        case 'admin_fw_ban':          admin_tab_guard(); act_admin_fw_ban(); return;
        case 'admin_fw_unban':        admin_tab_guard(); act_admin_fw_unban(); return;
        case 'admin_fw_rule_add':     admin_tab_guard(); act_admin_fw_rule_add(); return;
        case 'admin_fw_rule_del':     admin_tab_guard(); act_admin_fw_rule_del(); return;
        case 'admin_fw_rule_toggle':  admin_tab_guard(); act_admin_fw_rule_toggle(); return;
        case 'admin_fw_log_clear':    admin_tab_guard(); act_admin_fw_log_clear(); return;
        case 'admin_fw_geo_batch':    admin_tab_guard(); act_admin_fw_geo_batch(); return;
        case 'admin_logs_export':     admin_tab_guard(); act_admin_logs_export(); return;
        case 'admin_save_attack':     admin_tab_guard(); act_admin_save_attack(); return;
        case 'admin_test_attack':     admin_tab_guard(); act_admin_test_attack(); return;
        case 'admin_save_seo':        admin_tab_guard(); act_admin_save_seo(); return;
        case 'admin_save_lang':       admin_tab_guard(); act_admin_save_lang(); return;
        case 'admin_save_storage':    admin_tab_guard(); act_admin_save_storage(); return;
        case 'admin_test_db':         require_admin(); act_admin_test_db(); return;
        case 'admin_storage_migrate': admin_tab_guard(); act_admin_storage_migrate(); return;
        case 'admin_storage_backup':  admin_tab_guard(); act_admin_storage_backup(); return;
        case 'admin_storage_optimize': admin_tab_guard(); act_admin_storage_optimize(); return;
        case 'admin_storage_check':   admin_tab_guard(); act_admin_storage_check(); return;
        case 'admin_daemon_start':    admin_tab_guard(); act_admin_daemon_start(); return;
        case 'admin_daemon_stop':     admin_tab_guard(); act_admin_daemon_stop(); return;
        case 'save_lang':             act_save_lang(); return;
        default:
            redirect(u('p=home'));
    }
}

/* ================= 实时刷新数据源（GET 只读） ================= */

/** 前台轮询：在线人数 / 未读通知 / 列表与帖子更新进度（顺手刷新在线心跳，仅浏览器 UA 计入） */
function act_live(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        json_response(['ok' => false, 'msg' => 'method']);
    }
    $tid = isset($_GET['tid']) && is_numeric($_GET['tid']) ? (int)$_GET['tid'] : 0;
    $out = live_snapshot($tid);
    $out['interval'] = max(0, (int)cfg('live_interval', 20));
    json_response($out);
}

/** 监控页轮询：CPU / 内存 / 磁盘 / 负载实时读数（仅管理员） */
function act_sysmon_live(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        json_response(['ok' => false, 'msg' => 'method']);
    }
    $u = current_user();
    if (!$u || empty($u['admin'])) {
        json_response(['ok' => false, 'msg' => '无权限']);
    }
    json_response(sysmon_live_payload());
}

/** AJAX 动作错误返回 */
function act_err(string $msg, bool $ajax = false): void
{
    if ($ajax || is_ajax()) {
        json_response(['ok' => false, 'msg' => $msg]);
    }
    flash('err', $msg);
    back_or(u('p=home'));
}

/**
 * v1.41.0 POST 完整性守卫：设置保存类动作专用。
 * 锚点字段 = 表单里正常提交时必然存在的控件名（文本输入 / hidden / CSRF 令牌）。
 * 缺任何一个都说明请求体被截断或整体丢弃（网络抖动、Cloudflare 隧道 / 代理异常、
 * post_max_size / max_input_vars 触顶等）——此时表单里未到达的字段会被 `?? 默认值`
 * 与 post_str 静默回退，照常保存会把用户的整页配置覆盖成默认（「部分配置时不时
 * 消失、回到默认」的根因防线）。宁可拒绝保存，绝不覆盖：
 * 一律报错终止，现有设置分毫不动，用户重试即可。
 */
function post_guard(string ...$keys): void
{
    $missing = array_values(array_filter($keys, fn (string $k): bool => !isset($_POST[$k])));
    if (!$missing) {
        return;
    }
    act_err('表单数据不完整（缺少 ' . count($missing) . ' 个字段'
        . (count($missing) <= 3 ? '：' . implode(' / ', $missing) : '，如 ' . implode(' / ', array_slice($missing, 0, 3)))
        . '），本次保存已放弃，现有设置未做任何改动。'
        . '多为网络抖动或服务器 POST 限制（post_max_size / max_input_vars）所致，请直接重试；'
        . '若反复出现，请到后台「监控 → 运行环境」体检并调大 POST 限制。');
}

function act_ok(string $msg, string $goto = ''): void
{
    if (is_ajax()) {
        json_response(['ok' => true, 'msg' => $msg]);
    }
    flash('ok', $msg);
    back_or($goto !== '' ? $goto : u('p=home'));
}

function admin_tab_guard(): array
{
    /* v1.26.0 角色分级把关：
       - 审核员可用：内容审核域动作（帖子操作 / 删回复 / AI 队列 / 人工待审）；
       - 仅超级管理员：角色指派；
       - 其余 admin_* 动作：管理员（与旧版一致）。 */
    static $modActions = [
        'admin_thread_op', 'admin_reply_delete',
        'admin_queue_run', 'admin_queue_restore', 'admin_queue_delete',
        'admin_manual_restore', 'admin_manual_delete',
    ];
    static $superActions = ['admin_user_role'];
    $a = (string)($GLOBALS['MF_ACTION'] ?? '');
    if (in_array($a, $superActions, true)) {
        return require_super();
    }
    if (in_array($a, $modActions, true)) {
        return require_staff();
    }
    return require_admin();
}

/** 后台动作完成后的回跳（v1.16.0）：优先回到操作前所在的完整后台页面（tab/筛选/分页不丢），
 *  配合前端滚动位置恢复，保存设置后不再"从头刷新、回到顶部" */
function admin_redirect(string $fallback = 'p=admin'): void
{
    $back = (string)($_SESSION['admin_back'] ?? '');
    if ($back !== '' && strpos($back, 'p=admin') === 0 && preg_match('/^[a-zA-Z0-9_=&%.\-]+$/', $back)) {
        redirect('index.php?' . $back);
    }
    redirect(u($fallback));
}

/** 后台保存三份协议（v1.16.0） */
function act_admin_save_docs(): void
{
    post_guard('csrf', 'doc_footer', 'doc_gate');
    $me = admin_tab_guard();
    $kv = [];
    $nowDoc = time();
    foreach (['doc_terms', 'doc_privacy', 'doc_disclaimer'] as $k) {
        $v = post_str($k, 20000);
        /* v1.18.0：内容变化且非空时戳记保存时间（协议中心/文档页展示「最后更新」） */
        if ($v !== '' && $v !== (string)cfg($k, '')) {
            $kv['doc_u_' . substr($k, 4)] = $nowDoc;
        }
        $kv[$k] = $v;
    }
    $kv['doc_gate'] = !empty($_POST['doc_gate']) ? 1 : 0;
    $kv['doc_footer'] = !empty($_POST['doc_footer']) ? 1 : 0;
    cfg_update($kv);
    $msg = '协议设置已保存'
        . ($kv['doc_gate'] ? '；访问门禁已开启，未同意的访客下次访问需先确认' : '');
    /* v1.36.0 静态加速安全联动：访问门禁开启后，Apache 直出快照会绕过门禁判定——
       立即清空全部快照（无文件可直出，全部回退 PHP 实时渲染并执行门禁） */
    if (!empty($kv['doc_gate'])) {
        mfs_clear_all();
        $msg .= '；静态快照已清空（门禁与快照直出不兼容）';
    }
    log_action('admin_save_docs', '协议设置已保存：'
        . '用户协议 ' . ($kv['doc_terms'] !== '' ? '已启用' : '未填写')
        . '，隐私政策 ' . ($kv['doc_privacy'] !== '' ? '已启用' : '未填写')
        . '，免责声明 ' . ($kv['doc_disclaimer'] !== '' ? '已启用' : '未填写')
        . '；访问门禁 ' . ($kv['doc_gate'] ? '开' : '关')
        . '，页脚入口 ' . ($kv['doc_footer'] ? '开' : '关'), (int)$me['id']);
    if (is_ajax()) {
        json_response(['ok' => true, 'msg' => $msg]);
    }
    flash('ok', $msg);
    admin_redirect('p=admin&tab=docs');
}

/** 后台保存开放 API 设置（v1.19.0）：总开关 / 访客调用 / 限速 / CORS / 条款 / 端点开关 / 调用日志 */
function act_admin_save_api(): void
{
    post_guard('csrf', 'api_enabled', 'api_rate_guest');
    $me = admin_tab_guard();
    $kv = [
        'api_enabled' => !empty($_POST['api_enabled']) ? 1 : 0,
        'api_guest'   => !empty($_POST['api_guest']) ? 1 : 0,
        'api_log'     => !empty($_POST['api_log']) ? 1 : 0,
        'api_rate_token' => min(10000, max(0, (int)post_str('api_rate_token', 6))),
        'api_rate_guest' => min(10000, max(0, (int)post_str('api_rate_guest', 6))),
        'api_terms'   => post_str('api_terms', 20000),
    ];
    /* CORS：空=同源；*=全部；其余原样保存（使用时逐个比对来源） */
    $cors = post_str('api_cors', 500);
    if ($cors === '*' || $cors === '') {
        $kv['api_cors'] = $cors;
    } else {
        $parts = [];
        foreach (preg_split('/[\s，,;；]+/u', $cors) ?: [] as $o) {
            $o = strtolower(rtrim(trim((string)$o), '/'));
            if ($o !== '' && preg_match('#^https?://[a-z0-9.\-]+(:\d+)?$#', $o)) {
                $parts[] = $o;
            }
        }
        $kv['api_cors'] = implode(',', array_unique($parts));
    }
    /* 全部端点开关：未出现在表单里的视为关闭（isset 语义，防旧表单漏保存） */
    $on = 0;
    foreach (api_defs()['endpoints'] as $ep => $def) {
        $k = api_ep_key((string)$ep);
        $v = !empty($_POST[$k]) ? 1 : 0;
        $kv[$k] = $v;
        $on += $v;
    }
    cfg_update($kv);
    $total = count(api_defs()['endpoints']);
    $msg = 'API 设置已保存：' . ($kv['api_enabled'] ? '开放中（' . $on . '/' . $total . ' 个端点）' : '已整体关闭')
        . '；访客调用 ' . ($kv['api_guest'] ? '允许' : '禁止')
        . '，限速 令牌' . $kv['api_rate_token'] . '/分钟、访客' . $kv['api_rate_guest'] . '/分钟';
    log_action('admin_save_api', $msg, (int)$me['id']);
    if (is_ajax()) {
        json_response(['ok' => true, 'msg' => $msg]);
    }
    flash('ok', $msg);
    admin_redirect('p=admin&tab=api');
}

/** 后台撤销某用户的 API 令牌（v1.19.0） */
function act_admin_api_revoke(): void
{
    $me = admin_tab_guard();
    $uid = (int)($_POST['uid'] ?? 0);
    $u = $uid > 0 ? user_by_id($uid) : null;
    if (!$u) {
        act_err('用户不存在');
    }
    api_token_revoke($uid);
    log_action('admin_api_revoke', '撤销了用户「' . (string)$u['name'] . '」的 API 令牌', (int)$me['id']);
    notify_add($uid, 'system', '您的 API 令牌已被管理员撤销', '出于站点安全管理，管理员撤销了您的开放 API 令牌。如非本人请求或需继续使用，请重新阅读条款后自行签发。', '');
    if (is_ajax()) {
        json_response(['ok' => true, 'msg' => '已撤销「' . (string)$u['name'] . '」的 API 令牌']);
    }
    flash('ok', '已撤销「' . (string)$u['name'] . '」的 API 令牌');
    admin_redirect('p=admin&tab=api');
}

/** 后台一键压缩存量数据文件（v1.16.0）：把仍是明文 JSON 的数据文件重写为 gzip 格式 */
function act_admin_data_compress(): void
{
    $me = admin_tab_guard();
    $before = 0;
    $after = 0;
    $n = 0;
    foreach (Store::scan('') as $rel) {
        if (!preg_match('/\.php$/', $rel) || strpos($rel, 'lock/') === 0) {
            continue;
        }
        $p = Store::path($rel);
        $raw = @file_get_contents($p);
        if (!is_string($raw) || $raw === '' || strpos($raw, DATA_GUARD) !== 0) {
            continue;
        }
        $body = substr($raw, strlen(DATA_GUARD));
        if (strlen($body) > 2 && substr($body, 0, 2) === "\x1f\x8b") {
            continue; // 已是 gzip
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            continue;
        }
        $before += strlen($raw);
        if (Store::write($rel, $data)) {
            $after += (int)@filesize(Store::path($rel));
            $n++;
        }
    }
    /* v1.17.0：同时压缩存量日志（昨天及更早的 log-*.php / fw-*.php） */
    [$ln, $lsaved] = log_compress_old(200);
    $n += $ln;
    $saved = max(0, $before - $after) + $lsaved;
    $msg = $n > 0
        ? '压缩完成：' . $n . ' 个文件（含日志 ' . $ln . ' 个），共节省 ' . round($saved / 1024, 1) . ' KB'
        : '所有数据与日志文件均已是压缩格式，无需处理';
    log_action('admin_data_compress', '数据压缩：' . $n . ' 个文件（日志 ' . $ln . '），节省 ' . round($saved / 1024, 1) . 'KB', (int)$me['id']);
    if (is_ajax()) {
        json_response(['ok' => true, 'msg' => $msg]);
    }
    flash('ok', $msg);
    admin_redirect('p=admin&tab=system');
}

/** 功能总开关拦截：关闭时 act_err（AJAX 返回 JSON / 普通请求 flash+回跳），不会继续执行 */
function feat_guard(string $k, string $msg): void
{
    if (!feat_on($k)) {
        act_err($msg);
    }
}

/* ================= 通用 ================= */

/** 发帖/回复间隔检查与占用（在 users 锁内完成检查+写入，防并发双发）
 * v1.16.0：被拒时把内容存入会话草稿，回跳后自动填回，不再让用户重打一遍 */
function flood_check(array $u, array $draft = []): bool
{
    $interval = max(0, (int)cfg('post_interval', 30));
    if ($interval <= 0) {
        return true;
    }
    $lk = Store::lock('users');
    $us = user_all();
    $wait = 0;
    foreach ($us as &$x) {
        if ((int)($x['id'] ?? 0) === (int)$u['id']) {
            $wait = (int)($x['last_post'] ?? 0) + $interval - time();
            if ($wait <= 0) {
                $x['last_post'] = time();
            }
        }
    }
    unset($x);
    if ($wait <= 0) {
        Store::write('users.php', $us);
    }
    Store::unlock($lk);
    if ($wait > 0) {
        if ($draft !== [] && !empty($draft['key'])) {
            $_SESSION['post_draft'] = ['key' => (string)$draft['key'], 'title' => (string)($draft['title'] ?? ''), 'content' => (string)($draft['content'] ?? ''), 'wait' => $wait, 'at' => time()];
        }
        flash('err', '发言太频繁，请 ' . $wait . ' 秒后再试；您写的内容已自动保留，回来自动填回，无需重打');
        return false;
    }
    return true;
}

/** 管理员删除内容时给作者的系统通知（含具体页面名称、举报情况、可申诉提示；v1.15.0 用标题代替编号） */
function delete_notify_body(string $kind, int $tid, int $rid): string
{
    $tTitle = cut_str((string)(thread_get($tid)['title'] ?? ''), 40);
    $no = $kind === 'thread' ? '您的帖子《' . $tTitle . '》' : '您在帖子《' . $tTitle . '》中的回复';
    $n = 0;
    $type = $kind === 'thread' ? 'thread' : 'reply';
    foreach (reports_all() as $r) {
        if ($r['type'] === $type && (int)($r['tid'] ?? 0) === $tid
            && ($kind === 'thread' || (int)($r['rid'] ?? 0) === $rid)
            && !in_array($r['status'], ['gone'], true)) {
            $n++;
        }
    }
    $rep = $n > 0 ? '该内容曾被举报 ' . $n . ' 次，并进入审核流程' : '该内容由管理员直接删除';
    return $no . ' 已被管理员删除。' . $rep . '。如对处理结果有异议，可在「公告与通知」中查看申诉说明，或直接联系管理员申诉。';
}

/* ================= 认证与账号 ================= */

function act_logout(): void
{
    $u = current_user();
    if ($u) {
        log_action('logout', '用户 ' . (string)$u['name'] . ' 退出登录');
    }
    unset($_SESSION['dev_pending']); // v1.20.0：退出时顺带清理未完成的新设备验证会话
    auth_logout();
    flash('ok', '已退出登录');
    redirect(u('p=home'));
}

/* ================= 注销账号（v1.16.0） ================= */

/** 用户自助注销 · 第一步：邮箱验证码校验（通过后进入 10 秒冷静期） */
function act_account_delete(): void
{
    $u = require_login('p=settings');
    $code = post_str('code', 6);
    $email = strtolower(trim((string)($u['email'] ?? '')));
    $err = '';
    if (!code_verify($email, 'delete', $code, $err)) {
        flash('err', $err !== '' ? $err : '验证码错误或已过期');
        redirect(u('p=settings'));
    }
    $_SESSION['delete_armed'] = time();
    flash('ok', '验证通过。请再想 10 秒——10 秒后「确认注销」按钮亮起，点击后立即生效且不可恢复');
    redirect(u('p=settings'));
}

/** 用户自助注销 · 第二步：10 秒冷静期结束后确认执行（服务端强制校验 10 秒） */
function act_account_delete_confirm(): void
{
    $u = require_login('p=settings');
    $armed = (int)($_SESSION['delete_armed'] ?? 0);
    if ($armed <= 0 || time() - $armed < 10) {
        flash('err', '请先完成邮箱验证码校验，并等满 10 秒冷静期');
        redirect(u('p=settings'));
    }
    unset($_SESSION['delete_armed']);
    $name = (string)$u['name'];
    $uid = (int)$u['id'];
    user_delete($uid);
    log_action('account_delete', '用户「' . $name . '」（#' . $uid . '）已自助注销；历史帖子保留，作者显示为「已注销」', $uid);
    foreach (user_all() as $au) {
        if (!empty($au['admin']) && (int)$au['id'] !== $uid) {
            notify_add((int)$au['id'], 'system', '有用户注销了账号', '用户「' . $name . '」（#' . $uid . '）已通过邮箱验证完成注销。其历史帖子保留，作者显示为「已注销」。', 'p=admin&tab=users');
        }
    }
    auth_logout();
    flash('ok', '您的账号已注销。历史帖子保留，作者显示为「已注销」。感谢您曾经的陪伴！');
    redirect(u('p=home'));
}

/* ================= 协议（v1.16.0） ================= */

/** 访客同意协议（协议门禁页 / 协议页表单）：同意后写一年 cookie（内容指纹，协议更新后需重新同意） */
function act_doc_agree(): void
{
    if (empty($_POST['agree'])) {
        flash('err', '请先阅读并勾选同意协议，才能继续访问');
        redirect(u('p=doc'));
    }
    $fp = doc_fingerprint();
    /* v1.18.0：cookie 记录「版本指纹.同意时间」，协议中心可向用户展示自己的确认时间；
       同时写一条站内日志，管理员可追溯谁在何时同意了哪个版本 */
    $now = time();
    setcookie('mf_doc', $fp . '.' . $now, ['expires' => $now + 31536000, 'path' => '/', 'samesite' => 'Lax', 'secure' => app_is_https()]);
    $_SESSION['doc_agreed'] = $fp;
    $_SESSION['doc_agreed_t'] = $now; // 会话也记时间，协议中心状态卡能显示「刚刚确认」
    $meAgree = current_user();
    log_action('doc_agree', '同意了站点协议（版本 ' . strtoupper(substr($fp, 0, 8)) . '，共 ' . count(doc_list()) . ' 份）', $meAgree ? (int)$meAgree['id'] : 0);
    flash('ok', '感谢您的确认，祝您浏览愉快');
    $next = (string)($_POST['next'] ?? '');
    if ($next !== '' && preg_match('/^[a-z_=&\d]+$/', $next)) {
        redirect(u($next));
    }
    redirect(u('p=home'));
}

/* ================= 开放 API 令牌（v1.19.0） ================= */

/** 用户签发 / 重置自己的 API 令牌：必须先阅读并同意使用条款；明文仅在跳回设置页后一次性展示 */
function act_api_token_new(): void
{
    $u = require_login('p=settings');
    if ((int)cfg('api_enabled', 0) !== 1) {
        act_err('本站当前未开放 API，无法签发令牌');
    }
    $reissue = api_token_of((int)$u['id']) !== null; // 已有令牌 = 重置（旧令牌立即失效）
    if (empty($_POST['agree'])) {
        act_err('请先阅读并勾选同意《开放 API 使用条款》，再签发令牌');
    }
    $plain = api_token_issue((int)$u['id'], api_terms_fp());
    $_SESSION['api_token_show'] = $plain; // 仅展示一次：读取后立即销毁
    log_action('api_token_' . ($reissue ? 'reset' : 'new'), ($reissue ? '重置' : '签发') . '了开放 API 令牌（条款版本 ' . api_terms_fp() . '）', (int)$u['id']);
    flash('ok', $reissue ? '令牌已重置，旧令牌已立即失效' : '令牌已签发，请立即复制保存（仅显示这一次）');
    redirect(u('p=settings#api'));
}

/** 用户撤销自己的 API 令牌 */
function act_api_token_revoke(): void
{
    $u = require_login('p=settings');
    if (api_token_of((int)$u['id']) === null) {
        act_err('您尚未签发 API 令牌');
    }
    api_token_revoke((int)$u['id']);
    log_action('api_token_revoke', '撤销了自己的开放 API 令牌', (int)$u['id']);
    flash('ok', 'API 令牌已撤销，使用该令牌的客户端将立即失去访问权');
    redirect(u('p=settings#api'));
}

function act_send_code(): void
{
    $purpose = post_str('purpose', 10);
    if (!in_array($purpose, ['register', 'reset', 'delete', 'devverify'], true)) {
        $purpose = 'reset';
    }
    if ($purpose === 'devverify') {
        /* v1.20.0 新设备登录验证码：只发给待验证会话的账号邮箱（忽略表单传入，防骚扰他人） */
        $p = $_SESSION['dev_pending'] ?? null;
        if (!is_array($p) || time() - (int)($p['time'] ?? 0) > 600) {
            act_err('没有待验证的登录，请重新登录', true);
        }
        $pu = user_by_id((int)($p['uid'] ?? 0));
        if (!$pu || !empty($pu['banned'])) {
            act_err('账号状态异常，请重新登录', true);
        }
        $email = strtolower(trim((string)($pu['email'] ?? '')));
        if (!valid_email($email)) {
            act_err('该账号未绑定有效邮箱，无法完成新设备验证，请联系管理员', true);
        }
    } elseif ($purpose === 'delete') {
        /* 注销账号验证码：仅限已登录用户，且只能发到自己绑定的邮箱（不接受外部邮箱，防骚扰） */
        $me = current_user();
        if (!$me) {
            act_err('请先登录', true);
        }
        $email = strtolower(trim((string)($me['email'] ?? '')));
        if (!valid_email($email)) {
            act_err('您的账号未绑定有效邮箱，无法自助注销，请联系管理员', true);
        }
    } else {
        $email = strtolower(post_str('email', 60));
    }
    if (!valid_email($email)) {
        act_err('邮箱格式不正确', true);
    }
    // 会话级限频：同一会话 60 秒内只能发一封（与邮箱级限频双重防护，防轮换邮箱滥用发信）
    $last = (int)($_SESSION['code_sent_at'] ?? 0);
    if ($last > 0 && time() - $last < 60) {
        act_err('发送过于频繁，请 ' . (60 - (time() - $last)) . ' 秒后再试', true);
    }
    if ($purpose === 'register' && user_by_email($email)) {
        act_err('该邮箱已被注册', true);
    }
    $err = '';
    if (!code_send($email, $purpose, $err)) {
        act_err($err, true);
    }
    $_SESSION['code_sent_at'] = time();
    $lbl = ['register' => '注册', 'reset' => '找回密码', 'delete' => '注销账号', 'devverify' => '新设备登录'][$purpose] ?? '验证';
    log_action('code_send', $lbl . '验证码 → ' . $email);
    json_response(['ok' => true, 'msg' => '验证码已发送，请查收邮箱（注意垃圾箱）']);
}

function act_register(): void
{
    feat_guard('register', '本站已关闭新用户注册');
    /* v1.32.0：昵称长度后台可自定义（2-30，默认 20） */
    $nameMax = max(2, min(30, (int)cfg('name_max', 20)));
    $name = post_str('name', $nameMax + 10);
    $email = strtolower(post_str('email', 60));
    $pass = (string)($_POST['pass'] ?? '');
    $pass2 = (string)($_POST['pass2'] ?? '');
    $code = post_str('code', 6);
    set_old(['name' => $name, 'email' => $email]);

    if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > $nameMax || !valid_name($name)) {
        flash('err', '用户名需 2-' . $nameMax . ' 位，仅限中文、字母、数字、下划线');
        redirect(u('p=register'));
    }
    if (user_by_name($name)) {
        flash('err', '用户名已被占用');
        redirect(u('p=register'));
    }
    /* v1.16.0：已启用协议时，注册必须勾选同意 */
    if (doc_list() !== [] && empty($_POST['doc_agree'])) {
        flash('err', '请先阅读并勾选同意相关协议后再注册');
        redirect(u('p=register'));
    }
    if (!valid_email($email)) {
        flash('err', '邮箱格式不正确');
        redirect(u('p=register'));
    }
    if (user_by_email($email)) {
        flash('err', '该邮箱已被注册');
        redirect(u('p=register'));
    }
    if (u_strlen($pass) < 6 || u_strlen($pass) > 60) {
        flash('err', '密码长度需 6-60 位');
        redirect(u('p=register'));
    }
    if ($pass !== $pass2) {
        flash('err', '两次输入的密码不一致');
        redirect(u('p=register'));
    }
    $err = '';
    if (!code_verify($email, 'register', $code, $err)) {
        flash('err', $err);
        redirect(u('p=register'));
    }
    /* v1.24.0 用户名 AI 审查：注册前同步审查，违规直接拒绝（用户名尚未占用，换一个即可）；AI 失败宁纵勿拉不阻塞 */
    $aiNote = '';
    if (!ai_name_check($name, $aiNote)) {
        flash('err', '用户名未通过审核：' . cut_str($aiNote, 60) . '。请更换用户名后重试');
        redirect(u('p=register'));
    }
    $uid = user_create($name, $email, password_hash($pass, PASSWORD_DEFAULT), false);
    $user = user_by_id($uid);
    if ($user) {
        auth_login($user, false);
    }
    log_action('register', '用户 ' . $name . '（#' . $uid . '）注册成功', $uid, $name);
    stats_bump('reg'); // v1.32.0 站点统计
    mf_do_action('user_register', (int)$uid, (string)$name); /* v1.22.0 拓展钩子 */
    clear_old();
    flash('ok', '注册成功，欢迎加入！');
    redirect(u('p=home'));
}

function act_login(): void
{
    $id = post_str('id', 60);
    $pass = (string)($_POST['pass'] ?? '');
    $remember = !empty($_POST['remember']);
    set_old(['id' => $id]);

    $u = $id !== '' ? (user_by_name($id, true) ?? user_by_email($id)) : null;
    if ($u && !empty($u['deleted'])) {
        log_action('login_failed', '已注销账号尝试登录：' . (string)$u['name']);
        flash('err', '该账号已注销');
        redirect(u('p=login'));
    }
    if (!$u || !password_verify($pass, (string)($u['pass'] ?? ''))) {
        log_action('login_failed', '凭据错误：' . $id);
        flash('err', '用户名或密码错误');
        redirect(u('p=login'));
    }
    if (!empty($u['banned'])) {
        log_action('login_failed', '账号已被封禁：' . (string)$u['name'] . '（#' . (int)$u['id'] . '）', (int)$u['id'], (string)$u['name']);
        flash('err', '该账号已被封禁，无法登录');
        redirect(u('p=login'));
    }

    /* v1.20.0 新设备二次验证：未知设备 + 功能开启 + 邮箱可用 → 先发验证码，验证通过才建立会话。
       回归设备（指纹在踢出黑名单内用密码重登）视为本人操作，直接放行。 */
    $fp = dev_fingerprint();
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $ip = fw_ip();
    $kickedHere = dev_is_kicked((int)$u['id'], $fp);
    $known = dev_known((int)$u['id'], $fp);
    if (!$known && !$kickedHere && dev_verify_on($u) && valid_email((string)($u['email'] ?? ''))) {
        $err = '';
        if (code_send((string)$u['email'], 'devverify', $err)) {
            $_SESSION['dev_pending'] = ['uid' => (int)$u['id'], 'remember' => $remember ? 1 : 0, 'time' => time()];
            log_action('dev_verify_sent', '新设备登录验证码已发送：「' . dev_ua_name($ua) . '」（IP ' . $ip . '）', (int)$u['id'], (string)$u['name']);
            clear_old();
            flash('ok', '检测到新设备登录，验证码已发送至注册邮箱 ' . dev_mask_email((string)$u['email']) . '，请输入验证码完成登录');
            redirect(u('p=login&dev=1'));
        }
        /* 邮件发送失败（如站点未配置 SMTP）降级放行，日志留痕——安全功能不可把用户挡在门外 */
        log_action('dev_verify_skip', '新设备二次验证邮件发送失败，已降级放行：' . $err, (int)$u['id'], (string)$u['name']);
    }

    auth_login($u, $remember);
    dev_login_register((int)$u['id'], $u, $ip, $kickedHere); // 登记设备（新设备自动发提醒；超出并发上限自动下线最早设备）
    log_action('login', '用户 ' . (string)$u['name'] . '（#' . (int)$u['id'] . '）登录成功' . ($remember ? '，保持登录 30 天' : '') . '（' . dev_ua_name($ua) . ' · IP ' . $ip . '）', (int)$u['id'], (string)$u['name']);
    stats_bump('login'); // v1.32.0 站点统计
    mf_do_action('user_login', (int)$u['id'], (string)$u['name']); /* v1.22.0 拓展钩子 */
    clear_old();
    flash('ok', '欢迎回来，' . (string)$u['name']);
    $next = (string)($_POST['next'] ?? '');
    if ($next !== '' && preg_match('/^[a-z_=&\d]+$/', $next)) {
        redirect(u($next));
    }
    redirect(u('p=home'));
}

/** v1.20.0：新设备邮箱验证码校验，通过后正式建立会话（验证码本身由 code_send/code_verify 全程管理） */
function act_dev_verify(): void
{
    $p = $_SESSION['dev_pending'] ?? null;
    if (!is_array($p) || time() - (int)($p['time'] ?? 0) > 600) {
        unset($_SESSION['dev_pending']);
        flash('err', '验证会话已超时，请重新登录');
        redirect(u('p=login'));
    }
    $u = user_by_id((int)($p['uid'] ?? 0));
    if (!$u || !empty($u['deleted']) || !empty($u['banned'])) {
        unset($_SESSION['dev_pending']);
        flash('err', '账号状态异常，请重新登录');
        redirect(u('p=login'));
    }
    $code = post_str('code', 6);
    $err = '';
    if (!code_verify((string)$u['email'], 'devverify', $code, $err)) {
        flash('err', $err);
        redirect(u('p=login&dev=1'));
    }
    $remember = !empty($p['remember']);
    unset($_SESSION['dev_pending']);
    auth_login($u, $remember);
    dev_login_register((int)$u['id'], $u, fw_ip(), false);
    log_action('dev_verified', '用户 ' . (string)$u['name'] . ' 通过新设备邮箱验证并登录（' . dev_ua_name((string)($_SERVER['HTTP_USER_AGENT'] ?? '')) . ' · IP ' . fw_ip() . '）', (int)$u['id'], (string)$u['name']);
    clear_old();
    flash('ok', '设备验证通过，欢迎回来，' . (string)$u['name']);
    redirect(u('p=home'));
}

/** v1.20.0：放弃本次新设备验证，返回普通登录 */
function act_dev_cancel(): void
{
    unset($_SESSION['dev_pending']);
    redirect(u('p=login'));
}

/** v1.20.0：下线指定设备（个人设置 → 登录设备） */
function act_dev_kick(): void
{
    $u = require_login('p=settings');
    $fp = post_str('fp', 64);
    if ($fp === '') {
        flash('err', '参数缺失');
        redirect(u('p=settings#devices'));
    }
    if (hash_equals(dev_fingerprint(), $fp)) {
        flash('err', '当前设备不能自行下线，请使用顶部菜单「退出登录」');
        redirect(u('p=settings#devices'));
    }
    $entry = null;
    if (dev_kick_fp((int)$u['id'], $fp, $entry)) {
        log_action('device_kick', '用户主动下线设备「' . (string)($entry['name'] ?? '?') . '」（IP ' . (string)($entry['ip'] ?? '-') . '）', (int)$u['id'], (string)$u['name']);
        flash('ok', '设备「' . (string)($entry['name'] ?? '?') . '」已下线');
    } else {
        flash('err', '设备不存在或已下线');
    }
    redirect(u('p=settings#devices'));
}

/** v1.20.0：一键下线除当前设备外的全部设备 */
function act_dev_kick_others(): void
{
    $u = require_login('p=settings');
    $n = dev_kick_others((int)$u['id']);
    log_action('device_kick', '用户一键下线其他全部设备（' . $n . ' 台）', (int)$u['id'], (string)$u['name']);
    flash('ok', $n > 0 ? '已下线 ' . $n . ' 台其他设备' : '没有其他在线设备');
    redirect(u('p=settings#devices'));
}

/** v1.20.0：个人「新设备二次验证」开关（与后台总闸相互独立，任一开启即生效） */
function act_dev_verify_toggle(): void
{
    $u = require_login('p=settings');
    $on = !empty($_POST['on']) ? 1 : 0;
    user_update((int)$u['id'], ['dev_verify' => $on]);
    log_action('dev_verify_toggle', '用户' . ($on ? '开启' : '关闭') . '了新设备二次验证', (int)$u['id'], (string)$u['name']);
    flash('ok', $on ? '已开启：新设备登录需输入邮箱验证码' : '已关闭：新设备登录不再需要验证码');
    redirect(u('p=settings#devices'));
}

/** v1.20.0：后台「安全防护」页 · 账号安全设置（二次验证总闸 + 同时在线设备上限） */
function act_admin_save_security(): void
{
    post_guard('csrf', 'sess_max');
    admin_tab_guard();
    $max = max(1, min(50, (int)($_POST['sess_max'] ?? 10)));
    /* checkbox 用 hidden(0)+checkbox(1) 组合提交：未勾选=标量'0'（empty→关），勾选=数组（non-empty→开） */
    $verify = array_key_exists('dev_verify', $_POST) ? (!empty($_POST['dev_verify']) ? 1 : 0) : 0;
    cfg_update(['dev_verify' => $verify, 'sess_max' => $max]);
    log_action('admin_save_security', '账号安全设置已保存（新设备二次验证总闸：' . ($verify ? '开' : '关') . ' · 同时在线设备上限：' . $max . ' 台）');
    act_ok('账号安全设置已保存', u('p=admin&tab=security'));
}

function act_forgot(): void
{
    $email = strtolower(post_str('email', 60));
    $pass = (string)($_POST['pass'] ?? '');
    $pass2 = (string)($_POST['pass2'] ?? '');
    $code = post_str('code', 6);
    set_old(['email' => $email]);

    $err = '';
    if (!code_verify($email, 'reset', $code, $err)) {
        flash('err', $err);
        redirect(u('p=forgot'));
    }
    $u = user_by_email($email);
    if (!$u) {
        flash('err', '该邮箱未注册');
        redirect(u('p=forgot'));
    }
    if (u_strlen($pass) < 6 || u_strlen($pass) > 60) {
        flash('err', '密码长度需 6-60 位');
        redirect(u('p=forgot'));
    }
    if ($pass !== $pass2) {
        flash('err', '两次输入的密码不一致');
        redirect(u('p=forgot'));
    }
    user_update((int)$u['id'], ['pass' => password_hash($pass, PASSWORD_DEFAULT), 'remember' => '']);
    log_action('pass_reset', '邮箱 ' . $email . ' 的密码已重置（用户「' . (string)$u['name'] . '」）', (int)$u['id'], (string)$u['name']);
    clear_old();
    flash('ok', '密码已重置，请使用新密码登录');
    redirect(u('p=login'));
}

function act_change_pass(): void
{
    $u = require_login();
    $email = (string)$u['email']; // 只允许改自己账号的密码
    $pass = (string)($_POST['pass'] ?? '');
    $pass2 = (string)($_POST['pass2'] ?? '');
    $code = post_str('code', 6);

    $err = '';
    if (!code_verify($email, 'reset', $code, $err)) {
        flash('err', $err);
        redirect(u('p=settings'));
    }
    if (u_strlen($pass) < 6 || u_strlen($pass) > 60) {
        flash('err', '密码长度需 6-60 位');
        redirect(u('p=settings'));
    }
    if ($pass !== $pass2) {
        flash('err', '两次输入的密码不一致');
        redirect(u('p=settings'));
    }
    user_update((int)$u['id'], ['pass' => password_hash($pass, PASSWORD_DEFAULT), 'remember' => '']);
    log_action('pass_change', '用户 ' . (string)$u['name'] . ' 修改了密码');
    flash('ok', '密码修改成功，建议退出后用新密码重新登录');
    redirect(u('p=settings'));
}

function act_profile_save(): void
{
    $u = require_login();
    $nameMax = max(2, min(30, (int)cfg('name_max', 20)));
    $name = post_str('name', $nameMax + 10);
    $bio = post_str('bio', 200);
    // 用户签名（v1.14.0）：单行纯文本，展示在帖子与回复下方；功能关闭时保留旧值不修改
    $sig = feat_on('signature') ? trim(str_replace(["\r", "\n", "\0"], ' ', post_str('sig', 600))) : (string)($u['sig'] ?? '');
    $smax = max(0, min(500, (int)cfg('sign_len_max', 60)));
    if ($sig !== '' && mb_strlen($sig) > $smax) {
        flash('err', '签名最长 ' . $smax . ' 字（当前 ' . mb_strlen($sig) . ' 字）');
        redirect(u('p=settings'));
    }
    if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > $nameMax || !valid_name($name)) {
        flash('err', '用户名需 2-' . $nameMax . ' 位，仅限中文、字母、数字、下划线');
        redirect(u('p=settings'));
    }
    $exist = user_by_name($name);
    if ($exist && (int)$exist['id'] !== (int)$u['id']) {
        flash('err', '新用户名已被占用');
        redirect(u('p=settings'));
    }
    /* v1.24.0 用户名 AI 审查：仅在真正改名时审查（沿用旧名无需重复消耗 AI 调用） */
    if ($name !== (string)($u['name'] ?? '')) {
        $aiNote = '';
        if (!ai_name_check($name, $aiNote)) {
            flash('err', '新用户名未通过审核：' . cut_str($aiNote, 60) . '。请更换后重试');
            redirect(u('p=settings'));
        }
    }
    user_update((int)$u['id'], ['name' => $name, 'bio' => $bio, 'sig' => $sig]);
    log_action('profile_save', '资料更新：用户名 ' . (string)$u['name'] . ' → ' . $name);
    flash('ok', '资料已更新');
    redirect(u('p=settings'));
}

/* ================= 帖子与回复 ================= */

/** v1.32.0：单帖附件数量守卫——统计正文里引用的站内上传文件数，超过后台上限则拒绝保存 */
function media_count_guard(string $content, string $what): void
{
    $cap = max(1, min(20, (int)cfg('upload_per_post', 4)));
    $n = (int)preg_match_all('/\]\([^)]*p=media[&)]/', $content);
    if ($n > $cap) {
        flash('err', $what . '内最多引用 ' . $cap . ' 个上传文件（当前 ' . $n . ' 个），请删除多余引用后再保存');
        redirect(u('p=' . ($what === '帖子' ? 'new' : 'home')));
    }
}

/** v1.47.0 上传预检（单发与分块共用）：扩展名白名单 / 拒绝清单 / 大小上限一次把关；
 *  返回 ['ext'=>小写扩展名, 'img'=>是否图片, 'max'=>字节上限]；不通过直接 act_err 出站 */
function mf_upload_preflight(string $name, int $size, bool $skipSize = false): array
{
    $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    $deny = ['php', 'php3', 'php5', 'php7', 'phtml', 'phar', 'cgi', 'pl', 'py', 'sh', 'bash', 'htaccess', 'svg', 'exe', 'bat', 'cmd', 'com', 'js', 'jse', 'vbs', 'html', 'htm', 'ini', 'dll', 'so'];
    $imgExt = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
    if ($ext === '' || in_array($ext, $deny, true)) {
        act_err('不支持的文件类型：.' . $ext);
    }
    $isImg = in_array($ext, $imgExt, true);
    $allow = preg_split('/[\s,，;；]+/u', trim((string)cfg('upload_allow_ext', 'zip pdf txt md csv doc docx xls xlsx ppt pptx'))) ?: [];
    $allow = array_values(array_filter(array_map(fn ($x) => strtolower(trim(ltrim((string)$x, '.'))), $allow)));
    if (!$isImg && !in_array($ext, $allow, true)) {
        act_err('不支持的附件类型：.' . $ext . '（后台「基本设置」可调整允许的扩展名）');
    }
    $max = ($isImg ? cfg_int('upload_img_max_mb', 5) : cfg_int('upload_file_max_mb', 10)) * 1048576; /* v1.48.2：空串/缺键静默跌 1MB 防御 */
    if (!$skipSize && ($size <= 0 || $size > $max)) {
        act_err('文件大小 ' . fmt_bytes($size) . ' 超过上限 ' . round($max / 1048576) . 'MB');
    }
    return ['ext' => $ext, 'img' => $isImg, 'max' => $max];
}

/** v1.47.0 上传 meta 边车：原始文件名 / 大小 / 时间 / 上传者，与附件同目录；
 *  下载（p=media&dl=1）据此还原真实文件名；读不出边车时各处须静默降级 */
function mf_upload_meta_write(string $finalPath, string $origName, int $size, int $uid): void
{
    $meta = ['n' => cut_str($origName, 120), 's' => $size, 't' => time(), 'u' => $uid];
    @file_put_contents($finalPath . '.meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod($finalPath . '.meta.json', 0644);
}

/** v1.47.0 读取上传 meta 边车（静默降级：不存在 / 损坏一律返回 []） */
function mf_upload_meta_read(string $finalPath): array
{
    $j = @file_get_contents($finalPath . '.meta.json');
    if (!is_string($j) || $j === '') {
        return [];
    }
    $d = json_decode($j, true);
    return is_array($d) ? $d : [];
}

/** v1.32.0 图片 / 附件上传（发帖 / 回复 / 编辑表单的上传工具条走这里）：
 *  仅登录用户；「允许上传图片 / 附件」开关门控；图片做真实内容嗅探（防伪装扩展名）；
 *  存储为 data/uploads/年月/16位随机hex.扩展名（文件名不可猜测）；返回 Markdown 片段 */
function act_media_upload(): void
{
    $u = require_login();
    feat_guard('upload', '上传功能已关闭');
    if (empty($_FILES['file']) || !is_array($_FILES['file']) || !is_string($_FILES['file']['name'] ?? null)) {
        act_err('请选择要上传的文件（若已选择文件仍提示此错误，多半是整个请求超过服务器 post_max_size 被 PHP 丢弃——可在后台「监控 → 运行环境」一键调大后重试）');
    }
    $f = $_FILES['file'];
    if ((int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $code = (int)($f['error'] ?? -1);
        $why = [
            1 => '超过服务器 PHP 上传限制（upload_max_filesize）',
            2 => '超过表单声明的上传上限（MAX_FILE_SIZE）',
            3 => '上传中断，只收到部分文件',
            4 => '没有收到文件（若确认已选择文件，多半是整个请求超过 post_max_size 被 PHP 丢弃）',
            6 => '服务器临时目录不可用',
            7 => '服务器写入临时文件失败',
            8 => '上传被 PHP 扩展中断',
        ][$code] ?? '未知原因';
        $fix = ($code === 1 || $code === 2)
            ? '——可在后台「监控 → 运行环境」把 upload_max_filesize / post_max_size 一键调大后重试'
            : '';
        act_err('上传失败：' . $why . '（错误码 ' . $code . '）' . $fix);
    }
    $size = (int)($f['size'] ?? 0);
    $name = (string)$f['name'];
    $pf = mf_upload_preflight($name, $size);
    $ext = $pf['ext'];
    $isImg = $pf['img'];
    if ($isImg) {
        /* 图片内容嗅探：非真实图片（伪装扩展名 / 内嵌恶意载荷）一律拒绝 */
        $info = @getimagesize((string)$f['tmp_name']);
        if ($info === false || empty($info[2])) {
            act_err('图片内容校验失败：文件不是有效图片');
        }
    }
    $sub = date('Ym');
    $dir = DATA_DIR . '/uploads/' . $sub;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        act_err('存储目录创建失败：data/uploads/ 不可写（请通过 FTP / 面板检查目录权限与属主，或到后台「监控」页一键修复目录权限）');
    }
    $hex = bin2hex(random_bytes(8));
    $target = $dir . '/' . $hex . '.' . $ext;
    if (!@move_uploaded_file((string)$f['tmp_name'], $target)) {
        act_err('文件保存失败：' . $dir . ' 不可写或属主不对（请将 data/ 及其子目录权限设为 755，确保 PHP 运行账号可写，或到后台「监控」页一键修复）');
    }
    @chmod($target, 0644);
    mf_upload_meta_write($target, $name, $size, (int)$u['id']); /* v1.47.0 原始文件名随附件落盘 */
    /* 环境中立 URL（v1.32.0）：存储内容不带演示网关参数；渲染时 markdown 规则经 u() 重写 */
    $url = 'index.php?p=media&f=uploads/' . $sub . '/' . $hex . '.' . $ext;
    $md = $isImg
        ? '![' . cut_str((string)pathinfo($name, PATHINFO_FILENAME), 40) . '](' . $url . ')'
        : '[' . cut_str($name, 60) . '](' . $url . ')';
    log_action('media_upload', '上传' . ($isImg ? '图片' : '附件') . '：' . cut_str($name, 40) . '（' . fmt_bytes($size) . '）');
    json_response(['ok' => true, 'msg' => '上传成功', 'url' => $url, 'md' => $md, 'img' => $isImg]);
}

/* ================= v1.47.0 大文件分块上传 =================
   单发 multipart 受 post_max_size / upload_max_filesize 硬顶，大文件必然半路夭折；
   分块把一个大文件拆成一串小请求逐块上送，单块只有 MB 级，绕开全部整包限制：
   · init    建会话（token 目录 + meta + 顺手 GC 24h 前的陈旧会话）
   · chunk   逐块接收（索引命名落盘，支持并发乱序；X-CSRF-Token 头过闸）
   · finish  按索引合并 → 复用单发同款预检 / 图片嗅探 → 原子落位 + meta 边车
   · abort   中止即清（用户移除 chip / 关页面时调用；陈旧会话由 init GC 兜底）
   会话目录：data/uploads/tmp/<token>/（meta.json + 0000.chunk…）；token 绑定 uid，越权一律 403。 */

function mf_upload_tmp_dir(): string
{
    return DATA_DIR . '/uploads/tmp';
}

function mf_upload_session_dir(string $token): string
{
    return mf_upload_tmp_dir() . '/' . $token;
}

/** 校验分块会话存在且属于当前用户；失败抛 RuntimeException
 *  （HTTP 薄壳统一转 act_err 出站；自检端到端直接捕获断言） */
function mf_upload_session_guard(string $token, int $uid): array
{
    if (!preg_match('/^[a-f0-9]{24}$/', $token)) {
        throw new RuntimeException('上传会话无效（token 格式不对），请重新发起上传');
    }
    $dir = mf_upload_session_dir($token);
    $raw = @file_get_contents($dir . '/meta.json');
    $m = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($m) || !is_dir($dir)) {
        throw new RuntimeException('上传会话不存在或已过期（可能超过 24 小时被清理），请重新发起上传');
    }
    if ((int)($m['u'] ?? 0) !== (int)$uid) {
        throw new RuntimeException('无权操作该上传会话');
    }
    return ['dir' => $dir, 'meta' => $m];
}

/** 陈旧分块会话回收：init 时顺手清扫 24 小时前的残留目录（幂等、失败静默） */
function mf_upload_tmp_gc(): void
{
    $root = mf_upload_tmp_dir();
    if (!is_dir($root)) {
        return;
    }
    $cut = time() - 86400;
    foreach ((@scandir($root) ?: []) as $d) {
        if ($d === '.' || $d === '..') {
            continue;
        }
        $p = $root . '/' . $d;
        $mt = @filemtime($p);
        if ($mt !== false && $mt < $cut) {
            foreach ((@scandir($p) ?: []) as $f) {
                if ($f !== '.' && $f !== '..') {
                    @unlink($p . '/' . $f);
                }
            }
            @rmdir($p);
        }
    }
}

/* ================= v1.48.0 分块会话核心（与 HTTP 解耦，供 act_* 薄壳与自检端到端复用） =================
   v1.47.0 的 init / chunk / finish / abort 逻辑直连 act_*（依赖登录态与 CSRF），自检无法在
   数据层复现链路——v1.48.0 把核心抽成 mf_chunk_*（参数化、错误抛异常），act_* 退化为
   「登录 + CSRF + 调核心 + 异常转 act_err」薄壳；自检「分块上传端到端」项直接调用同一套
   核心函数真实建会话 / 逐块上送 / 合并落位，与线上行为同源。 */

/** 发起分块会话：预检（扩展名白名单 / 大小上限）→ GC 陈旧会话 → token 目录 + meta
 *  $chunkBytes=0 用后台「分块大小」配置；自检传小值（如 64KB）避免探针写出大文件。
 *  @return array{token:string,dir:string,chunk:int,conc:int,total:int,ext:string,img:bool} */
function mf_chunk_session_create(int $uid, string $name, int $size, int $chunkBytes = 0): array
{
    $pf = mf_upload_preflight($name, $size);
    mf_upload_tmp_gc();
    if (!is_dir(mf_upload_tmp_dir()) && !@mkdir(mf_upload_tmp_dir(), 0755, true)) {
        throw new RuntimeException('临时目录创建失败：data/uploads/tmp/ 不可写');
    }
    $token = bin2hex(random_bytes(12));
    $dir = mf_upload_session_dir($token);
    if (!@mkdir($dir, 0755, true)) {
        throw new RuntimeException('上传会话目录创建失败，请重试');
    }
    $chunk = $chunkBytes > 0 ? max(1, $chunkBytes) : cfg_int('upload_chunk_mb', 4, 1, 16) * 1048576; /* v1.48.2：空串/缺键防御 */
    if ($chunkBytes <= 0) {
        /* v1.48.1 关键修复：分块必须落在主机 post_max_size 之内。
           upload_chunk 用原始 octet-stream 请求体（不走 $_FILES），PHP 对超过
           post_max_size 的请求体会整包静默丢弃（$_POST 空、php://input 空）——
           每一块都必死，前台表现为反复重试后「上传失败」，即「上传不了」。
           后台配置只管体验偏好，主机硬限制是物理上限，此处一律钳制：
           取 90% 余量并对齐 64KB（post_max_size 语义为整请求体，留防护空间）。 */
        $postB = sysmon_memlimit_bytes((string)ini_get('post_max_size'));
        if ($postB > 0 && $chunk > $postB) {
            $chunk = max(65536, (int)(floor($postB * 0.9 / 65536) * 65536));
        }
    }
    $conc = max(1, min(6, (int)cfg('upload_conc', 2)));
    $total = (int)ceil($size / $chunk);
    file_put_contents($dir . '/meta.json', json_encode([
        'name' => cut_str($name, 200), 'size' => $size, 'ext' => $pf['ext'], 'img' => $pf['img'] ? 1 : 0,
        'u' => (int)$uid, 't' => time(), 'chunk' => $chunk, 'total' => $total,
    ], JSON_UNESCAPED_UNICODE), LOCK_EX);
    return ['token' => $token, 'dir' => $dir, 'chunk' => $chunk, 'conc' => $conc, 'total' => $total, 'ext' => $pf['ext'], 'img' => $pf['img']];
}

/** 逐块接收：索引命名原子落盘（.w 临时 + rename），支持并发乱序；大小逐字节核对 */
function mf_chunk_session_store(string $token, int $uid, int $idx, string $body): void
{
    $s = mf_upload_session_guard($token, $uid);
    $total = (int)($s['meta']['total'] ?? 0);
    $chunk = (int)($s['meta']['chunk'] ?? 0);
    if ($idx < 0 || $idx >= $total) {
        throw new RuntimeException('分块序号越界（' . $idx . ' / 共 ' . $total . ' 块）');
    }
    $expect = ($idx === $total - 1) ? ($s['meta']['size'] - $chunk * ($total - 1)) : $chunk;
    if (strlen($body) !== $expect) {
        throw new RuntimeException('分块大小不符（收到 ' . strlen($body) . ' B，应为 ' . $expect . ' B），请重试该块');
    }
    $target = $s['dir'] . '/' . sprintf('%04d', $idx) . '.chunk';
    $tmp = $target . '.w';
    if (@file_put_contents($tmp, $body) !== strlen($body) || !@rename($tmp, $target)) {
        throw new RuntimeException('分块写入失败：磁盘不可写或已满');
    }
}

/** 合并落位：逐块核对（缺块明确报错可续传）→ 流式合并 → 复用单发同款图片嗅探 →
 *  原子落位 + meta 边车（原始文件名）→ 清理会话目录
 *  @return array{url:string,md:string,img:bool,name:string,size:int,path:string} */
function mf_chunk_session_finish(string $token, int $uid): array
{
    $s = mf_upload_session_guard($token, $uid);
    $m = $s['meta'];
    $dir = $s['dir'];
    $total = (int)($m['total'] ?? 0);
    $chunk = (int)($m['chunk'] ?? 0);
    $size = (int)($m['size'] ?? 0);
    $ext = (string)($m['ext'] ?? '');
    $isImg = ((int)($m['img'] ?? 0)) === 1;
    /* 逐块核对：块数齐 + 每块大小精确（末块允许短）——任何一块缺失都明确报错可续传 */
    $missing = [];
    for ($i = 0; $i < $total; $i++) {
        $p = $dir . '/' . sprintf('%04d', $i) . '.chunk';
        $expect = ($i === $total - 1) ? ($size - $chunk * ($total - 1)) : $chunk;
        $sz = @filesize($p);
        if ($sz === false || (int)$sz !== (int)$expect) {
            $missing[] = $i;
        }
    }
    if ($missing) {
        throw new RuntimeException('还有 ' . count($missing) . ' 块未传完（第 ' . implode('、', array_slice($missing, 0, 8)) . ' 块'
            . (count($missing) > 8 ? ' 等' : '') . '），请重试 missing 提示对应的分块后重新完成');
    }
    /* 合并（流式，避免大文件二次进内存） */
    $assembled = $dir . '/assembled.bin';
    $out = @fopen($assembled, 'wb');
    if (!$out) {
        throw new RuntimeException('合并临时文件创建失败');
    }
    for ($i = 0; $i < $total; $i++) {
        $in = @fopen($dir . '/' . sprintf('%04d', $i) . '.chunk', 'rb');
        if (!$in) {
            fclose($out);
            throw new RuntimeException('读取第 ' . $i . ' 块失败');
        }
        while (!feof($in)) {
            $buf = fread($in, 262144);
            if ($buf === false || $buf === '') {
                break;
            }
            fwrite($out, $buf);
        }
        fclose($in);
    }
    fclose($out);
    clearstatcache(true, $assembled);
    if ((int)@filesize($assembled) !== $size) {
        @unlink($assembled);
        throw new RuntimeException('合并后大小与声明不符（' . (int)@filesize($assembled) . ' / ' . $size . ' B），请重新上传');
    }
    if ($isImg) {
        $info = @getimagesize($assembled);
        if ($info === false || empty($info[2])) {
            @unlink($assembled);
            throw new RuntimeException('图片内容校验失败：文件不是有效图片');
        }
    }
    $sub = date('Ym');
    $finalDir = DATA_DIR . '/uploads/' . $sub;
    if (!is_dir($finalDir) && !@mkdir($finalDir, 0755, true)) {
        throw new RuntimeException('存储目录创建失败：data/uploads/ 不可写');
    }
    $hex = bin2hex(random_bytes(8));
    $target = $finalDir . '/' . $hex . '.' . $ext;
    if (!@rename($assembled, $target)) {
        throw new RuntimeException('文件落位失败：' . $finalDir . ' 不可写');
    }
    @chmod($target, 0644);
    mf_upload_meta_write($target, (string)($m['name'] ?? ('file.' . $ext)), $size, (int)$uid);
    /* 清理会话目录（块文件 + meta） */
    foreach ((@scandir($dir) ?: []) as $f) {
        if ($f !== '.' && $f !== '..') {
            @unlink($dir . '/' . $f);
        }
    }
    @rmdir($dir);
    $url = 'index.php?p=media&f=uploads/' . $sub . '/' . $hex . '.' . $ext;
    $name = (string)($m['name'] ?? 'file');
    $md = $isImg
        ? '![' . cut_str((string)pathinfo($name, PATHINFO_FILENAME), 40) . '](' . $url . ')'
        : '[' . cut_str($name, 60) . '](' . $url . ')';
    return ['url' => $url, 'md' => $md, 'img' => $isImg, 'name' => $name, 'size' => $size, 'path' => $target];
}

/** 中止清理：删除会话目录全部内容；无效 token 幂等视为已清理；越权抛异常 */
function mf_chunk_session_abort(string $token, int $uid): void
{
    if (!preg_match('/^[a-f0-9]{24}$/', $token)) {
        return; /* 幂等：无效 token 视为已清理 */
    }
    $dir = mf_upload_session_dir($token);
    $raw = @file_get_contents($dir . '/meta.json');
    $m = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (is_array($m) && (int)($m['u'] ?? 0) !== (int)$uid) {
        throw new RuntimeException('无权操作该上传会话');
    }
    if (is_dir($dir)) {
        foreach ((@scandir($dir) ?: []) as $f) {
            if ($f !== '.' && $f !== '..') {
                @unlink($dir . '/' . $f);
            }
        }
        @rmdir($dir);
    }
}

/* ---------------- act_* 薄壳（登录 + CSRF + 核心 + 异常出站；HTTP 契约与 v1.47.0 完全一致） ---------------- */

function act_upload_init(): void
{
    $u = require_login();
    feat_guard('upload', '上传功能已关闭');
    $name = trim((string)($_POST['name'] ?? ''));
    $size = (int)($_POST['size'] ?? 0);
    if ($name === '' || $size <= 0) {
        act_err('缺少文件名或大小');
    }
    try {
        $s = mf_chunk_session_create((int)$u['id'], $name, $size);
    } catch (RuntimeException $e) {
        act_err($e->getMessage());
    }
    log_action('media_upload', '发起分块上传：' . cut_str($name, 40) . '（' . fmt_bytes($size) . '，' . $s['total'] . ' 块）');
    json_response(['ok' => true, 'token' => $s['token'], 'chunk' => $s['chunk'], 'conc' => $s['conc'], 'total' => $s['total']]);
}

function act_upload_chunk(): void
{
    $u = require_login();
    if (!csrf_ok()) {
        act_err('CSRF 校验失败，请刷新页面后重试');
    }
    $body = @file_get_contents('php://input');
    if ($body === false || $body === '') {
        act_err('分块内容为空');
    }
    try {
        mf_chunk_session_store((string)($_GET['token'] ?? ''), (int)$u['id'], (int)($_GET['idx'] ?? -1), $body);
    } catch (RuntimeException $e) {
        act_err($e->getMessage());
    }
    json_response(['ok' => true, 'idx' => (int)($_GET['idx'] ?? -1)]);
}

function act_upload_finish(): void
{
    $u = require_login();
    if (!csrf_ok()) {
        act_err('CSRF 校验失败，请刷新页面后重试');
    }
    try {
        $r = mf_chunk_session_finish((string)($_POST['token'] ?? ''), (int)$u['id']);
    } catch (RuntimeException $e) {
        act_err($e->getMessage());
    }
    log_action('media_upload', '分块上传完成：' . cut_str((string)$r['name'], 40) . '（' . fmt_bytes((int)$r['size']) . '）');
    json_response(['ok' => true, 'msg' => '上传成功', 'url' => $r['url'], 'md' => $r['md'], 'img' => $r['img']]);
}

function act_upload_abort(): void
{
    $u = require_login();
    if (!csrf_ok()) {
        act_err('CSRF 校验失败');
    }
    try {
        mf_chunk_session_abort((string)($_POST['token'] ?? ''), (int)$u['id']);
    } catch (RuntimeException $e) {
        act_err($e->getMessage());
    }
    json_response(['ok' => true]);
}

function act_thread_new(): void
{
    $u = require_login('p=new');
    feat_guard('post', '发帖功能已关闭');
    if (mute_left($u) > 0) {
        flash('err', '您已被禁言，剩余 ' . ceil(mute_left($u) / 60) . ' 分钟');
        redirect(u('p=home'));
    }
    $board = (int)($_POST['board'] ?? 0);
    if (!board_get($board)) {
        flash('err', '请选择有效板块');
        redirect(u('p=new'));
    }
    /* v1.25.0 板块发帖权限：所有人 / 仅职员 / 白名单用户 */
    if (!board_can_post($board, $u)) {
        flash('err', board_perm_reason($board, $u));
        redirect(u('p=new'));
    }
    /* v1.32.0 内容限制后台可自定义：超长给出明确错误而非静默截断 */
    $tmax = max(4, min(200, (int)cfg('thread_title_max', 30)));
    $lmax = max(100, min(200000, (int)cfg('thread_len_max', 1500)));
    $title = post_str('title', $tmax + 200);
    $content = post_str('content', 300000);
    if ($title === '') {
        flash('err', '标题不能为空');
        redirect(u('p=new'));
    }
    if (mb_strlen($title) > $tmax) {
        flash('err', '标题最长 ' . $tmax . ' 字（当前 ' . mb_strlen($title) . ' 字）');
        redirect(u('p=new'));
    }
    if ($content === '') {
        flash('err', '正文不能为空');
        redirect(u('p=new'));
    }
    if (mb_strlen($content) > $lmax) {
        flash('err', '正文最长 ' . $lmax . ' 字（当前 ' . mb_strlen($content) . ' 字）');
        redirect(u('p=new'));
    }
    media_count_guard($content, '帖子');
    if (!flood_check($u, ['key' => 'thread', 'title' => $title, 'content' => $content])) {
        redirect(u('p=new'));
    }
    $tid = thread_create($board, $title, $content, (int)$u['id']);
    user_bump((int)$u['id'], 'threads', 1);
    stats_bump('thread'); // v1.32.0 站点统计
    log_action('thread_new', '发布《' . cut_str($title, 40) . '》（板块：' . board_name($board) . '）');
    /* @ 提及通知：内容中 @到的人，系统自动给对方发送站内消息（v1.15.0） */
    if (feat_on('mention')) {
        mentions_notify($title . "\n" . $content, (int)$u['id'], $tid, 0, $title);
    }
    mf_do_action('post_created', thread_get($tid) ?? ['id' => $tid, 'board' => $board, 'title' => $title, 'author' => (int)$u['id']]); /* v1.22.0 拓展钩子 */
    /* 严全面·消息审核：v1.16.0 改为响应后异步预检，不再阻塞响应；
       v1.31.0：外呼前先终结 HTTP 响应（释放会话锁 + 冲刷输出 + FPM 立即收尾），
       发帖后跳转与本人后续页面不再被 AI 调用拖慢（详见 mf_response_finalize 注释） */
    register_shutdown_function(function () use ($tid, $u, $title, $content) {
        mf_response_finalize();
        ai_precheck_after_post('thread', $tid, 0, $title . "\n" . $content, (int)$u['id'], $title);
    });
    /* v1.28.0：提示与实际审查行为一致——未启用 AI / 无可用模型 / 职员免审时不再谎称「AI 正在审核」 */
    flash('ok', ai_precheck_applies((int)$u['id']) ? '发布成功，AI 正在后台审核内容' : '发布成功');
    redirect(u('p=thread&id=' . $tid));
}

function act_reply_new(): void
{
    $tid = (int)($_POST['tid'] ?? 0);
    $u = require_login('p=thread&id=' . $tid);
    feat_guard('reply', '回复功能已关闭');
    if (mute_left($u) > 0) {
        flash('err', '您已被禁言，剩余 ' . ceil(mute_left($u) / 60) . ' 分钟');
        redirect(u('p=thread&id=' . $tid));
    }
    /* v1.32.0：回复长度后台可自定义；超长明确报错 */
    $rmax = max(100, min(200000, (int)cfg('reply_len_max', 1000)));
    $content = post_str('content', 300000);
    if ($content === '') {
        flash('err', '回复内容不能为空');
        redirect(u('p=thread&id=' . $tid));
    }
    if (mb_strlen($content) > $rmax) {
        flash('err', '回复最长 ' . $rmax . ' 字（当前 ' . mb_strlen($content) . ' 字）');
        redirect(u('p=thread&id=' . $tid));
    }
    media_count_guard($content, '回复');
    // 先验锁帖 / 存在性，再占用发言间隔（避免被拒后白扣间隔）
    $t = thread_get($tid);
    if (!$t || !empty($t['locked'])) {
        flash('err', '该帖子已锁定或不存在，无法回复');
        redirect(u('p=thread&id=' . $tid));
    }
    if (!feat_on('reply')) {
        flash('err', '回复功能已关闭');
        redirect(u('p=thread&id=' . $tid));
    }
    if (!flood_check($u, ['key' => 'reply' . $tid, 'content' => $content])) {
        redirect(u('p=thread&id=' . $tid));
    }
    $rid = reply_add($tid, $content, (int)$u['id']);
    if ($rid === null) {
        flash('err', '该帖子已锁定或不存在，无法回复');
        redirect(u('p=home'));
    }
    stats_bump('reply'); // v1.32.0 站点统计
    log_action('reply_new', '在《' . cut_str((string)($t['title'] ?? ''), 40) . '》中发表回复');
    /* @ 提及通知（v1.15.0） */
    if (feat_on('mention')) {
        mentions_notify($content, (int)$u['id'], $tid, (int)$rid);
    }
    mf_do_action('reply_created', $tid, (int)$rid, (int)$u['id']); /* v1.22.0 拓展钩子 */
    /* 严全面·消息审核：v1.16.0 改为响应后异步预检，不再阻塞响应（同上；v1.31.0 外呼前先终结响应） */
    register_shutdown_function(function () use ($tid, $rid, $u, $content) {
        mf_response_finalize();
        ai_precheck_after_post('reply', $tid, $rid, $content, (int)$u['id']);
    });
    /* v1.28.0：提示与实际审查行为一致（同发帖） */
    flash('ok', ai_precheck_applies((int)$u['id']) ? '回复成功，AI 正在后台审核内容' : '回复成功');
    redirect(u('p=thread&id=' . $tid) . '#r' . $rid);
}

function act_thread_delete(): void
{
    $tid = (int)($_POST['tid'] ?? 0);
    $u = require_login();
    $t = thread_get($tid);
    if (!$t) {
        flash('err', '帖子不存在');
        redirect(u('p=home'));
    }
    $admin = !empty($u['admin']);
    if (!$admin && (int)$t['author'] !== (int)$u['id']) {
        flash('err', '只能删除自己的帖子');
        redirect(u('p=thread&id=' . $tid));
    }
    /* v1.27.0 删除自己的内容：受「功能 → 删除自己的内容」开关约束（职员不受限） */
    if (!$admin && !feat_on('del_own')) {
        flash('err', '删除自己的内容功能已关闭，可联系管理员处理');
        redirect(u('p=thread&id=' . $tid));
    }
    $author = (int)$t['author'];
    if (thread_delete($tid)) {
        user_bump($author, 'threads', -1);
        if ($admin && $author !== (int)$u['id']) {
            notify_add($author, 'delete', '您的帖子已被删除', delete_notify_body('thread', $tid, 0), '');
        }
        log_action('thread_delete', ($admin && $author !== (int)$u['id'] ? '管理员删除' : '作者删除') . '《' . (string)$t['title'] . '》');
        flash('ok', '帖子已删除');
    } else {
        flash('err', '删除失败');
    }
    redirect(u('p=home'));
}

function act_reply_delete(): void
{
    $tid = (int)($_POST['tid'] ?? 0);
    $rid = (int)($_POST['rid'] ?? 0);
    $u = require_login();
    $r = reply_get($tid, $rid);
    if (!$r) {
        flash('err', '回复不存在');
        redirect(u('p=thread&id=' . $tid));
    }
    if (empty($u['admin']) && (int)$r['author'] !== (int)$u['id']) {
        flash('err', '只能删除自己的回复');
        redirect(u('p=thread&id=' . $tid));
    }
    /* v1.27.0 删除自己的内容：受「功能 → 删除自己的内容」开关约束 */
    if (empty($u['admin']) && (int)$r['author'] === (int)$u['id'] && !feat_on('del_own')) {
        flash('err', '删除自己的内容功能已关闭，可联系管理员处理');
        redirect(u('p=thread&id=' . $tid));
    }
    reply_delete($tid, $rid);
    log_action('reply_delete', (!empty($u['admin']) && (int)$r['author'] !== (int)$u['id'] ? '管理员删除' : '作者删除') . '《' . cut_str((string)(thread_get($tid)['title'] ?? ''), 40) . '》中的回复');
    flash('ok', '回复已删除');
    redirect(u('p=thread&id=' . $tid));
}

/* ---------------- 编辑自己的内容（v1.14.0） ---------------- */

function act_thread_edit(): void
{
    $u = require_login();
    feat_guard('edit', '编辑功能已关闭');
    $tid = (int)($_POST['tid'] ?? 0);
    $t = thread_get($tid);
    if (!$t || !thread_editable($t, $u)) {
        act_err('无法编辑该帖子（可能已超过可编辑时间、已有回复、被锁定或功能已关闭）');
    }
    /* v1.32.0：长度后台可自定义，超长明确报错 */
    $tmax = max(4, min(200, (int)cfg('thread_title_max', 30)));
    $lmax = max(100, min(200000, (int)cfg('thread_len_max', 1500)));
    $title = post_str('title', $tmax + 200);
    $content = post_str('content', 300000);
    if ($title === '' || $content === '') {
        act_err('标题与正文不能为空');
    }
    if (mb_strlen($title) > $tmax) {
        act_err('标题最长 ' . $tmax . ' 字');
    }
    if (mb_strlen($content) > $lmax) {
        act_err('正文最长 ' . $lmax . ' 字（当前 ' . mb_strlen($content) . ' 字）');
    }
    media_count_guard($content, '帖子');
    thread_save($tid, ['title' => $title, 'content' => $content, 'edited' => time(), 'edited_by' => (int)$u['id']]);
    stats_bump('edit'); // v1.32.0 站点统计
    log_action('thread_edit', (($u['admin'] ?? false) && (int)$t['author'] !== (int)$u['id'] ? '管理员编辑' : '作者编辑') . '《' . cut_str($title, 40) . '》', (int)$u['id'], (string)$u['name']);
    /* @ 提及：编辑时只通知新增提及（旧内容已提及过的人不重复通知） */
    if (feat_on('mention')) {
        mentions_notify($title . "\n" . $content, (int)$u['id'], $tid, 0, $title, mentions_extract((string)($t['content'] ?? '')));
    }
    /* v1.24.0 编辑后 AI 审查：与发布预检同一套机制（违规自动隐藏可申诉）；
       管理员编辑他人内容不触发（管理员操作本身即人工审核，避免二次藏帖困扰） */
    if (!((!empty($u['admin'])) && (int)$t['author'] !== (int)$u['id'])) {
        register_shutdown_function(function () use ($tid, $u, $title, $content) {
            mf_response_finalize(); /* v1.31.0：外呼前先终结响应（同发帖） */
            ai_precheck_after_post('thread', $tid, 0, $title . "\n" . $content, (int)$u['id'], $title, 'edit');
        });
    }
    /* v1.28.0：提示与实际审查行为一致（同发帖；职员免审时不再谎称「AI 将复查」） */
    act_ok('帖子已更新' . (ai_precheck_applies((int)$u['id']) ? '，AI 将在后台复查内容' : ''), u('p=thread&id=' . $tid));
}

function act_reply_edit(): void
{
    $u = require_login();
    feat_guard('edit', '编辑功能已关闭');
    $tid = (int)($_POST['tid'] ?? 0);
    $rid = (int)($_POST['rid'] ?? 0);
    $t = thread_get($tid);
    $r = $t ? reply_get($tid, $rid) : null;
    if (!$t || !$r || !reply_editable($r, $u, $t)) {
        act_err('无法编辑该回复（可能已超过可编辑时间、被锁定或功能已关闭）');
    }
    $rmax = max(100, min(200000, (int)cfg('reply_len_max', 1000)));
    $content = post_str('content', 300000);
    if ($content === '') {
        act_err('回复内容不能为空');
    }
    if (mb_strlen($content) > $rmax) {
        act_err('回复最长 ' . $rmax . ' 字（当前 ' . mb_strlen($content) . ' 字）');
    }
    media_count_guard($content, '回复');
    reply_save($tid, $rid, ['content' => $content, 'edited' => time(), 'edited_by' => (int)$u['id']]);
    stats_bump('edit'); // v1.32.0 站点统计
    log_action('reply_edit', (($u['admin'] ?? false) && (int)$r['author'] !== (int)$u['id'] ? '管理员编辑' : '作者编辑') . '《' . cut_str((string)($t['title'] ?? ''), 40) . '》中的回复', (int)$u['id'], (string)$u['name']);
    /* @ 提及：编辑时只通知新增提及 */
    if (feat_on('mention')) {
        mentions_notify($content, (int)$u['id'], $tid, (int)$rid, '', mentions_extract((string)($r['content'] ?? '')));
    }
    /* v1.24.0 编辑后 AI 审查（同帖子编辑：管理员编辑他人回复不触发） */
    if (!((!empty($u['admin'])) && (int)$r['author'] !== (int)$u['id'])) {
        register_shutdown_function(function () use ($tid, $rid, $u, $content) {
            mf_response_finalize(); /* v1.31.0：外呼前先终结响应（同发帖） */
            ai_precheck_after_post('reply', $tid, $rid, $content, (int)$u['id'], '', 'edit');
        });
    }
    /* v1.28.0：提示与实际审查行为一致（同发帖） */
    act_ok('回复已更新' . (ai_precheck_applies((int)$u['id']) ? '，AI 将在后台复查内容' : ''), u('p=thread&id=' . $tid) . '#r' . $rid);
}

function act_like(): void
{
    $u = require_login();
    feat_guard('like', '点赞功能已关闭');
    // type/tid/rid 位于动作链接的查询串中，同时兼容表单隐藏域提交
    $type = (($_POST['type'] ?? $_GET['type'] ?? '') === 'r') ? 'r' : 't';
    $tid = (int)($_POST['tid'] ?? $_GET['tid'] ?? 0);
    $rid = (int)($_POST['rid'] ?? $_GET['rid'] ?? 0);
    if (!thread_get($tid) || ($type === 'r' && !reply_get($tid, $rid))) {
        act_err('内容不存在');
    }
    $likeRes = like_toggle($type, $tid, $rid, (int)$u['id']);
    $lt = '《' . cut_str((string)(thread_get($tid)['title'] ?? ''), 40) . '》' . ($type === 'r' ? '中的回复' : '');
    log_action(!empty($likeRes[0]) ? 'like' : 'unlike', $lt . (!empty($likeRes[0]) ? '（赞）' : '（取消赞）'));
    back_or(u('p=thread&id=' . $tid));
}

function act_report(): void
{
    $u = require_login();
    feat_guard('report', '举报功能已关闭');
    $type = ($_POST['type'] ?? '') === 'r' ? 'reply' : 'thread';
    $tid = (int)($_POST['tid'] ?? 0);
    $rid = (int)($_POST['rid'] ?? 0);
    $reason = post_str('reason', 200);
    if ($reason === '') {
        act_err('请填写举报理由');
    }
    $t = thread_get($tid);
    if (!$t) {
        act_err('内容不存在');
    }
    // 同一内容已有待审举报时不再接受重复举报（防止刷队列 / 反复隐藏）
    $typeKey = $type === 'reply' ? 'reply' : 'thread';
    foreach (reports_all() as $r) {
        if ($r['status'] === 'pending' && (string)$r['type'] === $typeKey
            && (int)($r['tid'] ?? 0) === $tid
            && ($typeKey === 'thread' || (int)($r['rid'] ?? 0) === $rid)) {
            act_err('该内容已在审核队列中，请耐心等待审核结果');
        }
    }
    if ($type === 'reply') {
        $r = reply_get($tid, $rid);
        if (!$r) {
            act_err('内容不存在');
        }
        if (!empty($r['appealed'])) {
            act_err('该内容已进入申诉流程，不能再被举报');
        }
        if ((int)$r['author'] === (int)$u['id']) {
            act_err('不能举报自己的内容');
        }
    } else {
        if (!empty($t['appealed'])) {
            act_err('该内容已进入申诉流程，不能再被举报');
        }
        if ((int)$t['author'] === (int)$u['id']) {
            act_err('不能举报自己的内容');
        }
    }
    report_add($type, $tid, $type === 'reply' ? $rid : 0, $reason, (int)$u['id']);
    log_action('report', ($type === 'reply' ? '《' . cut_str((string)($t['title'] ?? ''), 40) . '》中的回复' : '《' . cut_str((string)($t['title'] ?? ''), 40) . '》') . '，理由：' . $reason);
    act_ok('举报已提交，该内容已隐藏并进入审核队列');
}

function act_appeal(): void
{
    $u = require_login();
    $rid = (int)($_POST['report'] ?? 0);
    $rep = report_get($rid);
    if (!$rep || (int)$rep['author'] !== (int)$u['id']) {
        act_err('没有可申诉的内容');
    }
    if ($rep['status'] !== 'ai_bad') {
        act_err('该内容当前状态不支持申诉');
    }
    report_update($rid, ['status' => 'appealed', 'handled' => time()]);
    report_target_set_appealed($rep); // 标记「已申诉」，永久不能再被举报
    notify_clear_appealable((int)$u['id'], $rid);
    log_action('appeal', '对举报 #' . $rid . ' 提出申诉（《' . cut_str((string)(thread_get((int)$rep['tid'])['title'] ?? ''), 40) . '》' . ($rep['type'] === 'thread' ? '' : '中的回复') . '）');
    act_ok('已发送至管理员，将由人工复核');
}

function act_notify_read(): void
{
    $u = require_login();
    notify_set_read((int)$u['id'], (int)($_POST['id'] ?? 0));
    back_or(u('p=announcements&tab=notice'));
}

function act_notify_read_all(): void
{
    $u = require_login();
    notify_read_all((int)$u['id']);
    flash('ok', '全部通知已标记为已读');
    redirect(u('p=announcements&tab=notice'));
}

/* ================= 管理员：设置 ================= */

function act_admin_save_basic(): void
{
    /* v1.41.1 分组修复：本页实为两个独立表单（「基本设置」与「内容与限制」）共用同一保存动作——
     * ①守卫必须按「提交了哪一组锚点」分组，绝不能挂两段字段的并集：那样点任一表单都缺另一段的
     *   字段，正常保存必被误拦（v1.41.0 误杀实锤：点「保存基本设置」报缺 thread_title_max / upload_allow_ext）；
     * ②写入同样必须分组：旧版把两段 18 个键一把写入，另一段不在 POST 里的键被 `?? 默认值` 静默
     *   重置——「点一次『保存内容与限制』，基本设置整段回默认」（反之亦然），这正是「部分配置
     *   时不时回到默认」的高频根因之一（v1.41.0 之前即存在）。分组后两表单互不干扰。 */
    $isBasic = isset($_POST['site_name']);
    $isLimits = !$isBasic && isset($_POST['thread_title_max']);
    if ($isBasic) {
        post_guard('csrf', 'site_name', 'site_desc', 'site_url', 'bind_domains', 'per_page', 'post_interval', 'online_window', 'live_interval', 'ping_interval');
        if (post_str('site_name', 30) === '') {
            act_err('论坛名称不能为空');
        }
        /* 绑定域名：规范化清洗（去协议头 / 端口 / 路径、转小写、去重），仅保留合法域名字符 */
        $bd = implode(',', bind_domain_list(post_str('bind_domains', 500)));
        cfg_update([
            'site_name' => cut_str(post_str('site_name', 30), 30),
            'site_desc' => cut_str(post_str('site_desc', 100), 100),
            'site_url' => cut_str(post_str('site_url', 200), 200),
            'bind_domains' => cut_str($bd, 500),
            'per_page' => max(5, min(100, (int)($_POST['per_page'] ?? 20))),
            'post_interval' => max(0, min(3600, (int)($_POST['post_interval'] ?? 30))),
            'online_window' => max(60, min(86400, (int)($_POST['online_window'] ?? 300))),
            'live_interval' => max(0, min(300, (int)($_POST['live_interval'] ?? 20))),
            'ping_interval' => max(0, min(300, (int)($_POST['ping_interval'] ?? 15))), // v1.18.0：侧栏 Ping 测量间隔，0=关闭
        ]);
        log_action('admin_save_basic', '基本设置已保存' . ($bd !== '' ? '（绑定域名：' . $bd . '）' : ''));
        act_ok('基本设置已保存', u('p=admin&tab=basic'));
        return;
    }
    if (!$isLimits) {
        /* 两组锚点都没出现：请求体异常，按缺字段拒绝 */
        post_guard('csrf', 'site_name');
        return;
    }
    post_guard('csrf', 'thread_title_max', 'thread_len_max', 'reply_len_max', 'name_max', 'sign_len_max', 'upload_img_max_mb', 'upload_file_max_mb', 'upload_per_post', 'upload_allow_ext', 'upload_chunk_mb', 'upload_conc', 'dl_threads', 'dl_max_mbps');
    cfg_update([
        /* v1.32.0 内容与限制 */
        'thread_title_max' => max(4, min(200, (int)($_POST['thread_title_max'] ?? 30))),
        'thread_len_max' => max(100, min(200000, (int)($_POST['thread_len_max'] ?? 1500))),
        'reply_len_max' => max(100, min(200000, (int)($_POST['reply_len_max'] ?? 1000))),
        'name_max' => max(2, min(30, (int)($_POST['name_max'] ?? 20))),
        'sign_len_max' => max(0, min(500, (int)($_POST['sign_len_max'] ?? 60))),
        'upload_img_max_mb' => max(1, min(50, (int)($_POST['upload_img_max_mb'] ?? 5))),
        'upload_file_max_mb' => max(1, min(100, (int)($_POST['upload_file_max_mb'] ?? 10))),
        'upload_per_post' => max(1, min(20, (int)($_POST['upload_per_post'] ?? 4))),
        'upload_allow_ext' => cut_str(trim((string)preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', strtolower(trim((string)($_POST['upload_allow_ext'] ?? '')))))), 1000),
        /* v1.41.0：分隔符统一化 —— 逗号 / 中文逗号 / 分号 / 点号等一律转空格（旧版正则直接吞掉分隔符，
         * 「jpg,png,gif」保存后粘成一坨「jpgpnggif」，白名单失效导致附件上传全部被拒）；上限 200 → 1000 */
        /* v1.47.0 大文件传输：分块大小 / 上传并发 / 下载线程 / 带宽上限（Mbps，0=不限） */
        'upload_chunk_mb' => max(1, min(16, (int)($_POST['upload_chunk_mb'] ?? 4))),
        'upload_conc' => max(1, min(6, (int)($_POST['upload_conc'] ?? 2))),
        'dl_threads' => max(1, min(8, (int)($_POST['dl_threads'] ?? 3))),
        'dl_max_mbps' => max(0, min(1000, (float)($_POST['dl_max_mbps'] ?? 0))),
    ]);
    log_action('admin_save_limits', '内容与限制已保存');
    act_ok('内容与限制已保存', u('p=admin&tab=basic'));
}

/** 后台「功能」页：总开关保存（v1.14.0） */
function act_admin_save_feat(): void
{
    post_guard('csrf', 'feat_register');
    $keys = ['register', 'guest_browse', 'post', 'reply', 'edit', 'del_own', 'like', 'report', 'search', 'search_suggest', 'emoji', 'online', 'signature', 'mention', 'motion', 'titles', 'qr_login', 'upload'];
    $kv = [];
    $off = [];
    foreach ($keys as $k) {
        $v = !empty($_POST['feat_' . $k]) ? 1 : 0; /* v1.41.0：表单侧 hidden(0) 兜底，键恒在——POST 异常时守卫直接拒绝，不再静默全关 */
        $kv['feat_' . $k] = $v;
        if ($v === 0) {
            $off[] = $k;
        }
    }
    /* AI 两项直接写「AI」页的原生配置键（ai_precheck / ai_autopilot），与功能页同一份开关 */
    foreach (['ai_precheck', 'ai_autopilot'] as $k) {
        $v = !empty($_POST['feat_' . $k]) ? 1 : 0; /* v1.41.0：同上，hidden 兜底 */
        $kv[$k] = $v;
        if ($v === 0) {
            $off[] = $k;
        }
    }
    cfg_update($kv);
    /* v1.36.0 静态加速安全联动：关闭「游客可浏览」后论坛转为私密，游客快照必须立即清空 */
    if ((int)$kv['feat_guest_browse'] !== 1) {
        mfs_clear_all();
    }
    log_action('admin_save_feat', '功能开关更新：关闭 ' . ($off ? implode('、', $off) : '无（全部开启）'));
    act_ok('功能开关已保存' . ((int)$kv['feat_guest_browse'] !== 1 ? '（游客快照已清空）' : ''), u('p=admin&tab=feat'));
}

/** v1.34.0：扫码登录参数（有效期；总开关在功能开关列表内） */
function act_admin_save_qr(): void
{
    post_guard('csrf', 'qr_ttl');
    $ttl = (int)($_POST['qr_ttl'] ?? 180);
    $ttl = max(60, min(600, $ttl));
    cfg_update(['qr_ttl' => $ttl]);
    log_action('admin_save_qr', '扫码登录设置更新：二维码有效期 ' . $ttl . ' 秒');
    act_ok('扫码登录设置已保存', u('p=admin&tab=feat'));
}

/** 网站图标上传（v1.14.0）：严格类型校验 + 内容嗅探；SVG 另做脚本注入拒绝，输出时叠加 CSP sandbox */
function act_admin_icon_upload(): void
{
    if (empty($_FILES['icon']) || !is_array($_FILES['icon'])) {
        act_err('请选择图标文件');
    }
    $f = $_FILES['icon'];
    if ((int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $code = (int)($f['error'] ?? -1);
        $why = $code === 1 || $code === 2 ? '超过服务器 PHP 上传限制（upload_max_filesize）——可在后台「监控 → 运行环境」一键调大' : '服务器接收失败（错误码 ' . $code . '）';
        act_err('图标上传失败：' . $why);
    }
    if ((int)($f['size'] ?? 0) <= 0 || (int)$f['size'] > 200 * 1024) {
        act_err('图标大小需在 200KB 以内');
    }
    $ext = strtolower((string)pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'ico', 'svg'], true)) {
        act_err('仅支持 PNG / JPG / WEBP / GIF / ICO / SVG 格式');
    }
    $tmp = (string)($f['tmp_name'] ?? '');
    $data = @is_uploaded_file($tmp) ? (string)@file_get_contents($tmp) : '';
    if ($data === '') {
        act_err('读取上传文件失败，请重试');
    }
    if ($ext === 'svg') {
        /* SVG 安全校验：必须是 <svg> 文档，且拒绝脚本 / 事件属性 / 危险标签 / 实体（输出侧另有 CSP sandbox 双保险） */
        if (!preg_match('/<svg[\s>]/i', $data)
            || preg_match('/<(script|iframe|object|embed|foreignObject|use)[\s>]|on[a-z]+\s*=|javascript\s*:|<!DOCTYPE|<!ENTITY/i', $data)) {
            act_err('该 SVG 含脚本或结构不合规，已拒绝（请使用纯图形 SVG 或改用 PNG）');
        }
    } elseif ($ext === 'ico') {
        if (substr($data, 0, 4) !== "\x00\x00\x01\x00") {
            act_err('不是有效的 ICO 文件（内容与扩展名不符）');
        }
    } else {
        $info = @getimagesize($tmp);
        $want = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif'][$ext] ?? '';
        if ($info === false || (string)($info['mime'] ?? '') !== $want) {
            act_err('文件内容与扩展名不符，已拒绝');
        }
        $w = (int)($info[0] ?? 0);
        $h = (int)($info[1] ?? 0);
        if ($w < 16 || $h < 16 || $w > 1024 || $h > 1024) {
            act_err('图标尺寸需在 16×16 至 1024×1024 之间');
        }
    }
    Store::ensureDir('upload');
    $name = 'site-icon-' . bin2hex(random_bytes(4)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    if (!@file_put_contents(Store::path('upload/' . $name), $data)) {
        act_err('保存图标失败：data 目录不可写？');
    }
    $old = (string)cfg('site_icon', '');
    cfg_update(['site_icon' => $name]);
    if ($old !== '' && $old !== $name) {
        @unlink(Store::path('upload/' . $old)); // 替换旧图标，不残留
    }
    log_action('admin_icon', '网站图标已更新：' . $name . '（' . strlen($data) . ' 字节）');
    act_ok('网站图标已更新', u('p=admin&tab=theme'));
}

/** 恢复默认图标 */
function act_admin_icon_del(): void
{
    $old = (string)cfg('site_icon', '');
    if ($old === '') {
        act_err('当前已是默认图标');
    }
    @unlink(Store::path('upload/' . $old));
    cfg_update(['site_icon' => '']);
    log_action('admin_icon', '网站图标已恢复默认');
    act_ok('已恢复默认图标', u('p=admin&tab=theme'));
}

function act_admin_save_mail(): void
{
    post_guard('csrf', 'smtp_host', 'smtp_from');
    /* v1.43.0：直连 IP 按「提交了才更新」处理（与 ai 页同款纪律，避免任何旧表单误重置）；
       非空时必须是合法 IP，否则拒绝保存 */
    if (array_key_exists('smtp_direct_ip', $_POST)) {
        $directIp = cut_str(trim((string)$_POST['smtp_direct_ip']), 45);
        if ($directIp !== '' && !filter_var($directIp, FILTER_VALIDATE_IP)) {
            act_err('直连 IP 格式不正确（应如 120.232.69.34，留空表示不使用）');
        }
        cfg_update(['smtp_direct_ip' => $directIp]);
    }
    cfg_update([
        'smtp_host' => cut_str(post_str('smtp_host', 100), 100),
        'smtp_port' => max(1, min(65535, (int)($_POST['smtp_port'] ?? 465))),
        'smtp_from' => cut_str(post_str('smtp_from', 60), 60),
        'smtp_pass' => cut_str(post_str('smtp_pass', 100), 100),
    ]);
    $dip = (string)cfg('smtp_direct_ip', '');
    log_action('admin_save_mail', 'SMTP：' . (string)cfg('smtp_host') . ':' . (int)cfg('smtp_port') . ($dip !== '' ? '（直连 ' . $dip . '）' : ''));
    act_ok('邮件设置已保存', u('p=admin&tab=mail'));
}

function act_admin_test_mail(): void
{
    $ov = [
        'host' => post_str('smtp_host', 100),
        'port' => max(1, min(65535, (int)($_POST['smtp_port'] ?? 465))),
        'direct_ip' => trim((string)($_POST['smtp_direct_ip'] ?? (string)cfg('smtp_direct_ip', ''))),
        'from' => post_str('smtp_from', 60),
        'pass' => post_str('smtp_pass', 100),
    ];
    $to = post_str('to', 60);
    if (!valid_email($to)) {
        json_response(['ok' => false, 'msg' => '请填写有效的测试收件邮箱']);
    }
    $inner = '<p style="margin:0 0 12px">这是一封 <b>SMTP 配置测试邮件</b>。</p>'
        . '<div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:10px;padding:12px 14px;font-size:13px;line-height:1.7;color:#065f46">如果您收到了这封邮件（含本美化版式），说明邮件配置正确，验证码 / 通知 / 告警等系统邮件均会以此格式发送。</div>';
    [$ok, $err] = mail_send($to, (string)cfg('site_name', '论坛') . ' · SMTP 测试邮件', mail_template('SMTP 测试邮件', $inner), $ov);
    if ($ok) {
        log_action('admin_test_mail', '测试邮件已发送至 ' . $to);
    }
    json_response($ok ? ['ok' => true, 'msg' => '测试邮件已发送，请查收'] : ['ok' => false, 'msg' => $err]);
}

function act_admin_save_ai(): void
{
    post_guard('csrf', 'ai_mode', 'ai_timeout');
    /* v1.16.0：AI 页与「安全防护」页（严全面区块）共用本动作，各页只提交自己的字段，
       因此全部按"提交了才更新"处理，避免一页保存把另一页的设置重置为默认值 */
    $kv = [];
    if (isset($_POST['ai_retries'])) {
        $kv['ai_retries'] = max(1, min(10, (int)$_POST['ai_retries']));
    }
    if (isset($_POST['ai_timeout'])) {
        $kv['ai_timeout'] = max(5, min(120, (int)$_POST['ai_timeout']));
    }
    if (isset($_POST['ai_fail_limit'])) {
        $kv['ai_fail_limit'] = max(1, min(20, (int)$_POST['ai_fail_limit']));
    }
    if (isset($_POST['ai_mode'])) {
        $mode = (string)$_POST['ai_mode'];
        if (!in_array($mode, ['normal', 'fullpower', 'parallel'], true)) {
            $mode = 'normal';
        }
        $kv['ai_mode'] = $mode;
        $kv['ai_fullpower'] = $mode === 'fullpower' ? 1 : 0; /* 旧开关同步，兼容降级场景 */
    }
    if (isset($_POST['ai_strict'])) {
        $kv['ai_strict'] = ai_strict_norm((string)$_POST['ai_strict']);
    }
    /* checkbox 用 hidden(0)+checkbox(1) 组合提交，取消勾选也能落盘为 0 */
    foreach (['ai_precheck', 'ai_autopilot', 'ai_patrol_alert'] as $k) {
        if (array_key_exists($k, $_POST)) {
            $kv[$k] = !empty($_POST[$k]) ? 1 : 0;
        }
    }
    if (isset($_POST['ai_patrol_interval'])) {
        $kv['ai_patrol_interval'] = max(2, min(360, (int)$_POST['ai_patrol_interval']));
    }
    if (isset($_POST['ai_patrol_ban_limit'])) {
        $kv['ai_patrol_ban_limit'] = max(1, min(10, (int)$_POST['ai_patrol_ban_limit']));
    }
    if ($kv) {
        cfg_update($kv);
    }
    $parts = [];
    if (isset($kv['ai_mode'])) {
        $parts[] = '审核模式：' . ['normal' => '标准（单模型顺序）', 'fullpower' => '全火力全开（所有模型同审一条）', 'parallel' => '并行火力（多模型同刻各审一条）'][$kv['ai_mode']];
    }
    if (isset($kv['ai_retries']) || isset($kv['ai_fail_limit']) || isset($kv['ai_timeout'])) {
        $parts[] = '单模型重试 ' . (int)cfg('ai_retries', 2) . ' 次 / 单次超时 ' . (int)cfg('ai_timeout', 20) . ' 秒 / 自动切换阈值 ' . ai_fail_limit() . ' 次';
    }
    if (isset($kv['ai_strict'])) {
        $lvName = ['loose' => '宽松', 'standard' => '标准', 'strict' => '严格'][cfg('ai_strict', 'standard')];
        $parts[] = '审核严格程度：' . $lvName;
    }
    if (isset($kv['ai_precheck'])) {
        $parts[] = '发帖预检：' . ($kv['ai_precheck'] ? '开' : '关');
    }
    if (isset($kv['ai_autopilot'])) {
        $parts[] = 'AI 自主管理：' . ($kv['ai_autopilot'] ? '开' : '关') . '（巡逻间隔 ' . ai_patrol_interval() . ' 分钟，单轮封禁上限 ' . ai_patrol_ban_limit() . '，邮件警报：' . (!empty(cfg('ai_patrol_alert', 1)) ? '开' : '关') . '）';
    }
    if ($parts) {
        log_action('admin_save_ai', 'AI 设置：' . implode('；', $parts));
    }
    $backTab = (isset($_POST['ai_autopilot']) || isset($_POST['ai_patrol_interval'])) ? 'security' : 'ai';
    act_ok('AI 设置已保存', u('p=admin&tab=' . $backTab));
}

function act_admin_test_ai(): void
{
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        /* 未指定模型：测试当前主力（上次成功的模型），无主力则取第一个启用模型 */
        $st = ai_state_read();
        $id = (int)$st['active'];
        $list = ai_models_enabled();
        if (!$list) {
            json_response(['ok' => false, 'msg' => '暂无启用的模型，请先添加并启用']);
        }
        $ids = [];
        foreach ($list as $x) {
            $ids[] = (int)$x['id'];
        }
        if (!in_array($id, $ids, true)) {
            $id = $ids[0];
        }
    }
    $m = null;
    foreach (ai_models_all() as $x) {
        if ((int)$x['id'] === $id) {
            $m = $x;
            break;
        }
    }
    if ($m === null) {
        json_response(['ok' => false, 'msg' => '模型不存在，请刷新页面后重试']);
    }
    $msg = '';
    $ok = ai_test_model($m, $msg, ['retries' => 1, 'strict' => (string)cfg('ai_strict', 'standard')]);
    if ($ok) {
        log_action('admin_test_ai', 'AI 测试通过：' . ai_model_label($m) . '（' . $m['model'] . '）');
    }
    json_response(['ok' => $ok, 'msg' => $msg]);
}

/** v1.44.0 功能一键自检：跑全部测试项（remote=1 时含真实发信与 AI 真调用），返回 JSON 报告 */
function act_admin_selfcheck(): void
{
    $remote = !empty($_POST['remote']);
    $t0 = microtime(true);
    $items = selfcheck_run($remote); /* 引擎内已自动落盘 data/selfcheck.php */
    $n = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'skip' => 0];
    foreach ($items as $x) {
        $st = (string)($x['state'] ?? 'warn');
        $n[$st] = ($n[$st] ?? 0) + 1;
    }
    log_action('admin_selfcheck', '功能一键自检：通过 ' . $n['pass'] . ' / 警告 ' . $n['warn'] . ' / 失败 ' . $n['fail'] . ' / 跳过 ' . $n['skip'] . ($remote ? '（含远程真实测试）' : ''));
    json_response(['ok' => true, 'items' => $items, 'sum' => $n, 'ms' => (int)round((microtime(true) - $t0) * 1000)]);
}

/** v1.44.0 自检报告一键修复：按提交的 keys 执行保守修复（先备份，绝不自动删数据） */
function act_admin_selffix(): void
{
    $allowed = ['repair_dirs', 'rebuild_index', 'clear_snapshot', 'clean_temp', 'backup_damaged', 'clean_probes'];
    $keys = [];
    foreach (explode(',', (string)($_POST['keys'] ?? '')) as $k) {
        $k = trim($k);
        if (in_array($k, $allowed, true)) {
            $keys[] = $k;
        }
    }
    if (!$keys) {
        json_response(['ok' => false, 'msg' => '没有可执行的修复项']);
    }
    $log = selffix_run($keys);
    $okAll = true;
    foreach ($log as $l) {
        if (empty($l['ok'])) {
            $okAll = false;
        }
    }
    log_action('admin_selffix', '自检一键修复：' . implode('；', array_map(static fn (array $l): string => $l['name'] . '——' . $l['msg'], $log)));
    json_response(['ok' => $okAll, 'log' => $log]);
}

/** v1.45.0 自检实时进度 · 逐项执行：前端每跑一项请求一次，进度条 / 清单 / 日志实时点亮 */
function act_admin_sc_step(): void
{
    $key = trim((string)($_POST['key'] ?? ''));
    if (!in_array($key, selfcheck_item_keys(), true)) {
        json_response(['ok' => false, 'msg' => '未知自检项']);
    }
    $remote = !empty($_POST['remote']);
    $t0 = microtime(true);
    try {
        $item = selfcheck_run_item($key, $remote);
    } catch (Throwable $e) {
        /* 单项异常不拖垮整场：按 fail 项返回，原因给到前端日志 */
        $item = ['key' => $key, 'name' => $key, 'state' => 'fail', 'detail' => '该项执行异常：' . cut_str($e->getMessage(), 160)];
    }
    json_response(['ok' => true, 'item' => $item, 'ms' => (int)round((microtime(true) - $t0) * 1000)]);
}

/** v1.45.0 自检实时进度 · 收尾落盘：前端汇总逐项结果提交（服务端按目录白名单重整，日志随报告存档可回看） */
function act_admin_sc_finish(): void
{
    $names = [];
    foreach (selfcheck_catalog() as $c) {
        $names[(string)$c['key']] = (string)$c['name'];
    }
    $fixKeys = ['repair_dirs', 'rebuild_index', 'clear_snapshot', 'clean_temp', 'backup_damaged'];
    $in = json_decode((string)($_POST['items'] ?? '[]'), true);
    if (!is_array($in)) {
        json_response(['ok' => false, 'msg' => '报告数据无效']);
    }
    $items = [];
    $seen = [];
    foreach ($in as $x) {
        if (!is_array($x)) {
            continue;
        }
        $k = (string)($x['key'] ?? '');
        if (!isset($names[$k]) || isset($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        $st = (string)($x['state'] ?? 'warn');
        if (!in_array($st, ['pass', 'warn', 'fail', 'skip'], true)) {
            $st = 'warn';
        }
        $fk = (string)($x['fixkey'] ?? '');
        $items[] = [
            'key'     => $k,
            'name'    => $names[$k],
            'state'   => $st,
            'detail'  => cut_str(trim((string)($x['detail'] ?? '')), 600),
            'fixable' => !empty($x['fixable']) && in_array($fk, $fixKeys, true),
            'fixkey'  => in_array($fk, $fixKeys, true) ? $fk : '',
        ];
    }
    if (!$items) {
        json_response(['ok' => false, 'msg' => '报告为空']);
    }
    $sum = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'skip' => 0];
    foreach ($items as $x) {
        $sum[$x['state']]++;
    }
    $logs = json_decode((string)($_POST['logs'] ?? '[]'), true);
    if (!is_array($logs)) {
        $logs = [];
    }
    $logs = array_slice(array_map(static fn ($l): string => cut_str((string)$l, 300), $logs), -200);
    selfcheck_last_write(['ts' => time(), 'remote' => !empty($_POST['remote']), 'items' => $items, 'sum' => $sum, 'logs' => $logs]);
    log_action('admin_selfcheck', '功能一键自检（实时模式）：通过 ' . $sum['pass'] . ' / 警告 ' . $sum['warn'] . ' / 失败 ' . $sum['fail'] . ' / 跳过 ' . $sum['skip'] . (!empty($_POST['remote']) ? '（含远程真实测试）' : ''));
    json_response(['ok' => true, 'sum' => $sum]);
}

/** v1.45.0 修复实时进度 · 逐项修复：一次只修一项，前端逐项推进「修复进度」并即时落日志 */
function act_admin_sf_step(): void
{
    $allowed = ['repair_dirs', 'rebuild_index', 'clear_snapshot', 'clean_temp', 'backup_damaged', 'clean_probes'];
    $k = trim((string)($_POST['key'] ?? ''));
    if (!in_array($k, $allowed, true)) {
        json_response(['ok' => false, 'msg' => '未知修复项']);
    }
    $log = selffix_run([$k]);
    $okAll = true;
    foreach ($log as $l) {
        if (empty($l['ok'])) {
            $okAll = false;
        }
    }
    log_action('admin_selffix', '自检一键修复（单项 ' . $k . '）：' . implode('；', array_map(static fn (array $l): string => $l['name'] . '——' . $l['msg'], $log)));
    json_response(['ok' => $okAll, 'log' => $log]);
}

/* ---------------- 管理员：AI 自主管理（严全面） ---------------- */

/** 手动巡逻一次（管理员点击按钮触发；即使无风险事件也强制巡，便于查看 AI 对当前态势的判断） */
function act_admin_patrol_now(): void
{
    $mat = ai_patrol_material();
    $msg = '';
    $ok = ai_patrol_go($mat, $msg);
    if ($ok) {
        log_action('admin_patrol', '管理员手动巡逻：' . cut_str($msg, 140), 0, '管理员');
    }
    json_response(['ok' => $ok, 'msg' => $msg]);
}

/* ---------------- 管理员：AI 模型列表 ---------------- */

/** 从表单收集模型行（新增 / 编辑共用） */
function admin_model_from_post(int $id): array
{
    return ai_model_norm([
        'id' => $id,
        'name' => post_str('name', 30),
        'url' => post_str('url', 200),
        'key' => post_str('key', 200),
        'model' => post_str('model', 100),
        'on' => isset($_POST['on']),
    ]);
}

function act_admin_model_new(): void
{
    $m = admin_model_from_post(0);
    if (!ai_model_ok($m)) {
        act_err('API 地址 / 密钥 / 模型名 均不能为空');
    }
    $list = ai_models_all();
    $maxId = 0;
    foreach ($list as $x) {
        $maxId = max($maxId, (int)$x['id']);
    }
    $m['id'] = $maxId + 1;
    $list[] = $m;
    ai_models_save($list);
    log_action('admin_model_new', '添加 AI 模型「' . ai_model_label($m) . '」（' . $m['model'] . '）' . ($m['on'] ? '' : '，未启用'));
    act_ok('模型已添加', u('p=admin&tab=ai'));
}

function act_admin_model_save(): void
{
    $id = (int)($_POST['id'] ?? 0);
    $list = ai_models_all();
    $idx = -1;
    foreach ($list as $i => $x) {
        if ((int)$x['id'] === $id) {
            $idx = $i;
            break;
        }
    }
    if ($idx < 0) {
        act_err('模型不存在');
    }
    $m = admin_model_from_post($id);
    if (!ai_model_ok($m)) {
        act_err('API 地址 / 密钥 / 模型名 均不能为空');
    }
    $list[$idx] = $m;
    ai_models_save($list);
    log_action('admin_model_save', '修改 AI 模型「' . ai_model_label($m) . '」（' . $m['model'] . '）' . ($m['on'] ? '' : '，已停用'));
    act_ok('模型已保存', u('p=admin&tab=ai'));
}

function act_admin_model_del(): void
{
    $id = (int)($_POST['id'] ?? 0);
    $list = ai_models_all();
    $del = null;
    foreach ($list as $i => $x) {
        if ((int)$x['id'] === $id) {
            $del = $x;
            unset($list[$i]);
            break;
        }
    }
    if ($del === null) {
        act_err('模型不存在');
    }
    ai_models_save($list);
    /* 同步清理失败状态；主力被删时指向剩余第一个启用模型 */
    $st = ai_state_read();
    unset($st['fail'][$id]);
    if ((int)$st['active'] === $id) {
        $st['active'] = 0;
        foreach (ai_models_enabled() as $x) {
            $st['active'] = (int)$x['id'];
            break;
        }
    }
    ai_state_write($st);
    log_action('admin_model_del', '删除 AI 模型「' . ai_model_label($del) . '」（' . $del['model'] . '）');
    act_ok('模型已删除', u('p=admin&tab=ai'));
}

function act_admin_model_move(): void
{
    $id = (int)($_POST['id'] ?? 0);
    $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
    $list = ai_models_all();
    $idx = -1;
    foreach ($list as $i => $x) {
        if ((int)$x['id'] === $id) {
            $idx = $i;
            break;
        }
    }
    if ($idx < 0) {
        act_err('模型不存在');
    }
    $to = $dir === 'up' ? $idx - 1 : $idx + 1;
    if ($to < 0 || $to >= count($list)) {
        act_err('已经到顶 / 底了');
    }
    $tmp = $list[$idx];
    $list[$idx] = $list[$to];
    $list[$to] = $tmp;
    ai_models_save($list);
    act_ok('顺序已调整', u('p=admin&tab=ai'));
}

function act_admin_model_reset(): void
{
    $id = (int)($_POST['id'] ?? 0);
    $m = null;
    foreach (ai_models_all() as $x) {
        if ((int)$x['id'] === $id) {
            $m = $x;
            break;
        }
    }
    if ($m === null) {
        act_err('模型不存在');
    }
    $st = ai_state_read();
    $had = (int)($st['fail'][$id] ?? 0);
    $st['fail'][$id] = 0;
    ai_state_write($st);
    if ($had > 0) {
        log_action('admin_model_reset', '手动清零模型「' . ai_model_label($m) . '」的失败计数（原连续失败 ' . $had . ' 次）');
    }
    act_ok('失败计数已清零，该模型恢复参与审核', u('p=admin&tab=ai'));
}

/* ================= 管理员：板块 ================= */

function act_admin_board_new(): void
{
    $name = post_str('name', 20);
    $desc = post_str('desc', 60);
    if ($name === '') {
        act_err('板块名称不能为空');
    }
    $bid = board_save(null, $name, $desc);
    log_action('admin_board_new', '新建板块「' . $name . '」（#' . $bid . '）');
    act_ok('板块已创建', u('p=admin&tab=boards'));
}

function act_admin_board_save(): void
{
    $id = (int)($_POST['id'] ?? 0);
    $name = post_str('name', 20);
    $desc = post_str('desc', 60);
    if (!board_get($id)) {
        act_err('板块不存在');
    }
    if ($name === '') {
        act_err('板块名称不能为空');
    }
    board_save($id, $name, $desc);
    log_action('admin_board_save', '保存板块「' . $name . '」（#' . $id . '）');
    act_ok('板块已保存', u('p=admin&tab=boards'));
}

function act_admin_board_del(): void
{
    $id = (int)($_POST['id'] ?? 0);
    $b = board_get($id);
    if (!board_delete($id)) {
        act_err('删除失败：板块下仍有帖子，请先移走或删除');
    }
    log_action('admin_board_del', '删除板块「' . (string)($b['name'] ?? $id) . '」（#' . $id . '）');
    act_ok('板块已删除', u('p=admin&tab=boards'));
}

function act_admin_board_move(): void
{
    $id = (int)($_POST['id'] ?? 0);
    board_move($id, ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down');
    log_action('admin_board_move', '板块「' . board_name($id) . '」排序' . (($_POST['dir'] ?? '') === 'up' ? '上移' : '下移'));
    back_or(u('p=admin&tab=boards'));
}

/* ================= v1.25.0 管理员：板块发帖权限（v1.27.0 简化） ================= */

function act_admin_board_perm(): void
{
    $id = (int)($_POST['id'] ?? 0);
    if (!board_get($id)) {
        act_err('板块不存在');
    }
    $type = (string)($_POST['perm_type'] ?? 'all');
    /* v1.27.0：all / staff / users 三选；旧版 admins / level 提交值同样按 staff 处理 */
    $type = in_array($type, ['all', 'users'], true) ? $type : 'staff';
    $perm = ['type' => $type, 'uids' => []];
    if ($type === 'users') {
        /* 白名单：输入用户名（逗号 / 空白分隔），保存时解析为用户 id；无效名字忽略 */
        $names = trim((string)($_POST['perm_users'] ?? ''));
        $parts = preg_split('/[\s,，、]+/u', $names) ?: [];
        $uids = [];
        $bad = [];
        foreach ($parts as $n) {
            $n = trim($n);
            if ($n === '') {
                continue;
            }
            $x = user_by_name($n);
            if ($x && empty($x['deleted'])) {
                $uids[] = (int)$x['id'];
            } else {
                $bad[] = $n;
            }
        }
        $perm['uids'] = array_values(array_unique($uids));
        if ($bad) {
            act_err('以下用户名不存在，已取消保存：' . cut_str(implode('、', $bad), 80));
        }
        if (!$perm['uids']) {
            act_err('白名单至少填写一个有效用户名');
        }
    }
    board_perm_save($id, $perm);
    $typeCn = ['all' => '所有人', 'staff' => '仅职员', 'users' => '白名单用户'][$type] ?? $type;
    log_action('admin_board_perm', '板块「' . board_name($id) . '」发帖权限设为：' . $typeCn . ($type === 'users' ? '（' . count($perm['uids']) . ' 人）' : ''));
    act_ok('板块发帖权限已保存', u('p=admin&tab=boards'));
}

/* ================= v1.25.0 管理员：还原默认设置 ================= */

function act_admin_restore_defaults(): void
{
    $raw = trim((string)($_POST['keys'] ?? ''));
    $want = array_values(array_filter(array_map('trim', explode(',', $raw)), function ($s) {
        return preg_match('/^[a-z0-9_]{2,60}$/', $s) === 1;
    }));
    if (!$want) {
        act_err('未指定要还原的设置项');
    }
    $defs = admin_cfg_defaults();
    $kv = [];
    $skipped = [];
    foreach ($want as $k) {
        if (!isset($defs[$k])) {
            $skipped[] = $k; /* 不在注册表中的键拒绝还原（防误传 / 恶意构造） */
            continue;
        }
        $kv[$k] = $defs[$k][0];
    }
    if (!$kv) {
        act_err('这些设置项不支持一键还原');
    }
    cfg_update($kv);
    /* v1.36.0 静态加速联动：还原可能改变安全前提或模式（游客浏览 / 门禁 / 模式本身） */
    if (isset($kv['feat_guest_browse']) && (int)$kv['feat_guest_browse'] !== 1) {
        mfs_clear_all();
    }
    if (isset($kv['doc_gate']) && (int)$kv['doc_gate'] === 1) {
        mfs_clear_all();
    }
    if (isset($kv['static_mode']) && (string)$kv['static_mode'] !== 'html') {
        $mfsE = '';
        mfs_htaccess_remove($mfsE);
        mfs_clear_all();
    }
    log_action('admin_restore_defaults', '还原 ' . count($kv) . ' 项设置为默认值：' . cut_str(implode(', ', array_keys($kv)), 160));
    act_ok('已还原 ' . count($kv) . ' 项设置为默认值' . ($skipped ? '（跳过不支持的 ' . count($skipped) . ' 项）' : ''), back_target());
}

/** 还原后回跳：优先回当前后台地址（session 里存的完整 tab/筛选） */
function back_target(): string
{
    $b = (string)($_SESSION['admin_back'] ?? '');
    return $b !== '' ? u('p=admin&' . $b) : u('p=admin');
}

/* ================= v1.26.0 管理员：回撤更新 ================= */

function act_admin_update_rollback(): void
{
    $bk = (string)($_POST['bk'] ?? '');
    if (!preg_match('/^pre-(update|rollback)-\d{8}-\d{6}\.zip$/', $bk)) {
        act_err('备份文件名不合法');
    }
    $path = Store::path('backup/' . $bk);
    if (!is_file($path)) {
        act_err('备份文件不存在（可能已被清理）');
    }
    $res = [];
    try {
        update_rollback($path, $res);
    } catch (RuntimeException $e) {
        act_err($e->getMessage());
    }
    act_ok('已回滚到 v' . $res['version'] . '（恢复 ' . $res['files'] . ' 个文件），回滚前状态已另存为 ' . $res['new_backup'], u('p=admin&tab=update'));
}

/* ================= 管理员：用户 ================= */

function act_admin_user_mute(): void
{
    $uid = (int)($_POST['uid'] ?? 0);
    $mins = max(1, min(43200, (int)($_POST['mins'] ?? 60)));
    $t = user_by_id($uid);
    if (!$t) {
        act_err('用户不存在');
    }
    if (!empty($t['admin'])) {
        act_err('不能对管理员账号禁言');
    }
    user_update($uid, ['mute_until' => time() + $mins * 60]);
    log_action('admin_user_mute', '禁言 ' . (string)$t['name'] . '（#' . $uid . '）' . $mins . ' 分钟');
    act_ok('已禁言 ' . (string)$t['name'] . ' ' . $mins . ' 分钟', u('p=admin&tab=users'));
}

function act_admin_user_ban(): void
{
    $uid = (int)($_POST['uid'] ?? 0);
    $t = user_by_id($uid);
    if (!$t || !empty($t['admin'])) {
        act_err('不能封禁管理员账号');
    }
    user_update($uid, ['banned' => 1]);
    log_action('admin_user_ban', '封禁 ' . (string)$t['name'] . '（#' . $uid . '）');
    act_ok('已封禁 ' . (string)$t['name'], u('p=admin&tab=users'));
}

function act_admin_user_unban(): void
{
    $uid = (int)($_POST['uid'] ?? 0);
    $t = user_by_id($uid);
    user_update($uid, ['banned' => 0, 'mute_until' => 0]);
    log_action('admin_user_unban', '解封 ' . (string)($t['name'] ?? '#' . $uid) . '（#' . $uid . '）');
    act_ok('账号已解禁', u('p=admin&tab=users'));
}

function act_admin_user_role(): void
{
    /* v1.26.0：角色指派（仅超级管理员）：普通用户 / 审核员 / 管理员 / 超级管理员 */
    $me = admin_tab_guard();
    $uid = (int)($_POST['uid'] ?? 0);
    $role = (string)($_POST['role'] ?? 'user');
    if (!isset(MF_ROLES[$role])) {
        act_err('未知角色');
    }
    if ($uid === (int)$me['id']) {
        act_err('不能修改自己的角色');
    }
    $t = user_by_id($uid);
    if (!$t) {
        act_err('用户不存在');
    }
    if ($role === 'super' && !empty($t['banned'])) {
        act_err('不能把已封禁的账号设为超级管理员，请先解封');
    }
    $old = user_role($t);
    if ($old === $role) {
        act_err('该用户已经是「' . t(MF_ROLES[$role][0]) . '」');
    }
    user_update($uid, role_sync_fields($role));
    log_action('admin_user_role', '用户「' . (string)$t['name'] . '」角色：' . t(MF_ROLES[$old][0]) . ' → ' . t(MF_ROLES[$role][0]));
    act_ok('角色已更新：' . (string)$t['name'] . ' → ' . t(MF_ROLES[$role][0]), u('p=admin&tab=users'));
}

/* ================= 管理员：内容 ================= */

function act_admin_thread_op(): void
{
    $tid = (int)($_POST['tid'] ?? 0);
    $act = (string)($_POST['act'] ?? '');
    $t = thread_get($tid);
    if (!$t) {
        act_err('帖子不存在');
    }
    switch ($act) {
        case 'lock':
            thread_save($tid, ['locked' => true]);
            log_action('admin_thread_op', '锁定《' . (string)$t['title'] . '》');
            act_ok('帖子已锁定', u('p=thread&id=' . $tid));
            break;
        case 'unlock':
            thread_save($tid, ['locked' => false]);
            log_action('admin_thread_op', '解锁《' . (string)$t['title'] . '》');
            act_ok('帖子已解锁', u('p=thread&id=' . $tid));
            break;
        case 'pin':
            thread_save($tid, ['pinned' => true]);
            log_action('admin_thread_op', '置顶《' . (string)$t['title'] . '》');
            act_ok('帖子已置顶', u('p=thread&id=' . $tid));
            break;
        case 'unpin':
            thread_save($tid, ['pinned' => false]);
            log_action('admin_thread_op', '取消置顶《' . (string)$t['title'] . '》');
            act_ok('已取消置顶', u('p=thread&id=' . $tid));
            break;
        case 'move':
            $b = (int)($_POST['board'] ?? 0);
            if (!board_get($b)) {
                act_err('目标板块不存在');
            }
            thread_save($tid, ['board' => $b]);
            log_action('admin_thread_op', '移动《' . (string)$t['title'] . '》到「' . board_name($b) . '」');
            act_ok('帖子已移动到「' . board_name($b) . '」', u('p=thread&id=' . $tid));
            break;
        case 'delete':
            $author = (int)$t['author'];
            $me = current_user();
            thread_delete($tid);
            user_bump($author, 'threads', -1);
            if ($author !== (int)($me['id'] ?? 0)) {
                notify_add($author, 'delete', '您的帖子已被删除', delete_notify_body('thread', $tid, 0), '');
            }
            log_action('admin_thread_op', '删除《' . (string)$t['title'] . '》并通知作者');
            act_ok('帖子已删除', u('p=admin&tab=content'));
            break;
        default:
            act_err('未知操作');
    }
}

function act_admin_reply_delete(): void
{
    $tid = (int)($_POST['tid'] ?? 0);
    $rid = (int)($_POST['rid'] ?? 0);
    $r = reply_get($tid, $rid);
    if (!$r) {
        act_err('回复不存在');
    }
    $author = (int)$r['author'];
    $me = current_user();
    reply_delete($tid, $rid);
    if ($author !== (int)($me['id'] ?? 0)) {
        notify_add($author, 'delete', '您的回复已被删除', delete_notify_body('reply', $tid, $rid), 'p=thread&id=' . $tid);
    }
    log_action('admin_reply_delete', '删除《' . cut_str((string)(thread_get($tid)['title'] ?? ''), 40) . '》中的回复（作者 ' . uname($author) . '）并通知作者');
    act_ok('回复已删除', u('p=thread&id=' . $tid));
}

/* ================= 管理员：审核队列与人工处理 ================= */

function act_admin_queue_run(): void
{
    [$done, $msg] = ai_process_queue(true);
    if ($msg === '') {
        flash('ok', '队列当前没有待审内容');
    } else {
        flash('ok', $msg);
    }
    log_action('admin_queue_run', $done ? '手动触发 AI 审核：' . $msg : '手动触发 AI 审核（队列为空）');
    admin_redirect('p=admin&tab=queue');
}

function act_admin_queue_restore(): void
{
    $rid = (int)($_POST['rid'] ?? 0);
    $rep = report_get($rid);
    if (!$rep || $rep['status'] !== 'pending') {
        act_err('该举报不在待审队列中');
    }
    report_update($rid, ['status' => 'manual_ok', 'note' => '管理员直接恢复', 'handled' => time()]);
    $gone = false;
    report_target_content($rep, $gone);
    if (!$gone) {
        report_target_set_hidden($rep, false);
    }
    queue_remove($rid);
    notify_add((int)$rep['reporter'], 'report_result', '举报处理结果', '您举报的内容经管理员复核未发现违规，已恢复展示。', 'p=thread&id=' . (int)$rep['tid']);
    log_action('admin_queue_restore', '举报 #' . $rid . ' 直接恢复展示');
    act_ok('内容已恢复展示', u('p=admin&tab=queue'));
}

function act_admin_queue_delete(): void
{
    $rid = (int)($_POST['rid'] ?? 0);
    $rep = report_get($rid);
    if (!$rep || $rep['status'] !== 'pending') {
        act_err('该举报不在待审队列中');
    }
    $me = current_user();
    if ($rep['type'] === 'thread') {
        $t = thread_get((int)$rep['tid']);
        if ($t) {
            thread_delete((int)$rep['tid']);
            user_bump((int)$t['author'], 'threads', -1);
            if ((int)$t['author'] !== (int)($me['id'] ?? 0)) {
                notify_add((int)$t['author'], 'delete', '您的帖子已被删除', delete_notify_body('thread', (int)$rep['tid'], 0), '');
            }
        }
    } else {
        $r = reply_get((int)$rep['tid'], (int)$rep['rid']);
        if ($r) {
            reply_delete((int)$rep['tid'], (int)$rep['rid']);
            if ((int)$r['author'] !== (int)($me['id'] ?? 0)) {
                notify_add((int)$r['author'], 'delete', '您的回复已被删除', delete_notify_body('reply', (int)$rep['tid'], (int)$rep['rid']), 'p=thread&id=' . (int)$rep['tid']);
            }
        }
    }
    queue_remove($rid);
    report_update($rid, ['status' => 'manual_bad', 'note' => '管理员直接删除', 'handled' => time()]);
    log_action('admin_queue_delete', '举报 #' . $rid . ' 直接删除内容并通知作者');
    act_ok('内容已删除并通知作者', u('p=admin&tab=queue'));
}

function act_admin_manual_restore(): void
{
    $rid = (int)($_POST['rid'] ?? 0);
    $rep = report_get($rid);
    if (!$rep || !in_array($rep['status'], ['appealed', 'ai_bad', 'ai_failed'], true)) {
        act_err('该内容不在人工待审列表中');
    }
    $gone = false;
    report_target_content($rep, $gone);
    if (!$gone) {
        report_target_set_hidden($rep, false);
    }
    report_update($rid, ['status' => 'manual_ok', 'note' => '二次审核未检测到问题', 'handled' => time()]);
    notify_add((int)$rep['author'], 'manual_ok', '内容已恢复', '您申诉 / 被举报的内容经管理员二次审核未检测到问题，已恢复展示。', 'p=thread&id=' . (int)$rep['tid']);
    log_action('admin_manual_restore', '举报 #' . $rid . ' 人工审核：无问题，已恢复');
    act_ok('二次审核未检测到问题，已恢复', u('p=admin&tab=manual'));
}

function act_admin_manual_delete(): void
{
    $rid = (int)($_POST['rid'] ?? 0);
    $rep = report_get($rid);
    if (!$rep || !in_array($rep['status'], ['appealed', 'ai_bad', 'ai_failed'], true)) {
        act_err('该内容不在人工待审列表中');
    }
    $me = current_user();
    if ($rep['type'] === 'thread') {
        $t = thread_get((int)$rep['tid']);
        if ($t) {
            thread_delete((int)$rep['tid']);
            user_bump((int)$t['author'], 'threads', -1);
            if ((int)$t['author'] !== (int)($me['id'] ?? 0)) {
                notify_add((int)$t['author'], 'delete', '您的帖子已被删除', delete_notify_body('thread', (int)$rep['tid'], 0), '');
            }
        }
    } else {
        $r = reply_get((int)$rep['tid'], (int)$rep['rid']);
        if ($r) {
            reply_delete((int)$rep['tid'], (int)$rep['rid']);
            if ((int)$r['author'] !== (int)($me['id'] ?? 0)) {
                notify_add((int)$r['author'], 'delete', '您的回复已被删除', delete_notify_body('reply', (int)$rep['tid'], (int)$rep['rid']), 'p=thread&id=' . (int)$rep['tid']);
            }
        }
    }
    report_update($rid, ['status' => 'manual_bad', 'note' => '人工审核认定违规', 'handled' => time()]);
    log_action('admin_manual_delete', '举报 #' . $rid . ' 人工审核：认定违规，已删除并通知作者');
    act_ok('内容已删除并通知作者', u('p=admin&tab=manual'));
}

/* ================= 管理员：公告 / 主题 / 备份 ================= */

function act_admin_ann_save(): void
{
    $me = require_admin();
    $id = (int)($_POST['id'] ?? 0);
    $title = post_str('title', 60);
    $content = post_str('content', 2000);
    if ($title === '' || $content === '') {
        act_err('标题和内容不能为空');
    }
    ann_save($id > 0 ? $id : null, $title, $content, (int)$me['id']);
    log_action('admin_ann_save', ($id > 0 ? '更新公告 #' . $id : '发布公告') . '《' . $title . '》');
    act_ok($id > 0 ? '公告已更新' : '公告已发布', u('p=admin&tab=anns'));
}

function act_admin_ann_del(): void
{
    $id = (int)($_POST['id'] ?? 0);
    $a = ann_get($id);
    ann_delete($id);
    log_action('admin_ann_del', '删除公告 #' . $id . '《' . (string)($a['title'] ?? '') . '》');
    act_ok('公告已删除', u('p=admin&tab=anns'));
}

/** 主题色决策：色板单选 vs 自定义取色器（v1.8.0 修复「换不了颜色」，独立成函数便于测试） */
function theme_pick_color(string $cur, string $radio, string $custom): string
{
    $color = $radio;
    // 取色器仅在“用户真的改了它”（与当前已保存色不同）时生效；
    // 否则取色器总是携带旧值，会覆盖色板选择，表现为「主题色怎么换都不生效」
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $custom) && strcasecmp($custom, $cur) !== 0) {
        $color = $custom;
    }
    if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) {
        // 色板与取色器都没给出有效新颜色：保持当前色不变（例如只改深色模式/页脚时不应重置颜色）
        $color = preg_match('/^#[0-9a-fA-F]{3,8}$/', $cur) ? $cur : '#0f766e';
    }
    return $color;
}

function act_admin_save_theme(): void
{
    post_guard('csrf', 'theme_color', 'footer_text');
    $cur = (string)cfg('theme_color', '#0f766e');
    $color = theme_pick_color($cur, post_str('theme_color', 9), post_str('theme_color_custom', 9));
    $dark = (string)($_POST['dark_default'] ?? 'system');
    if (!in_array($dark, ['system', 'light', 'dark'], true)) {
        $dark = 'system';
    }
    cfg_update([
        'theme_color' => $color,
        'dark_default' => $dark,
        'footer_text' => cut_str(post_str('footer_text', 120), 120),
        'footer_note' => cut_str(post_str('footer_note', 120), 120),
        'custom_css' => cut_str(post_str('custom_css', 10000), 10000),
    ]);
    log_action('admin_save_theme', '主题色 ' . $color . '，深色默认 ' . $dark . '，页脚' . (post_str('footer_text', 120) !== '' ? '已自定义' : '默认'));
    act_ok('主题设置已保存', u('p=admin&tab=theme'));
}

/** 后台导航布局（v1.29.0）：平铺 / 侧边 + 侧边列数 1~3；结构性变更走整页回跳，保存即生效
 *  v1.30.0：支持 back 参数——导航栏快捷设置保存后回当前标签页（back 必须是合法标签名，否则回主题页） */
function act_admin_save_nav(): void
{
    post_guard('csrf', 'admin_nav_layout', 'admin_nav_cols');
    $layout = (string)($_POST['admin_nav_layout'] ?? 'side') === 'flat' ? 'flat' : 'side';
    $cols = (int)($_POST['admin_nav_cols'] ?? 1);
    if ($cols < 1 || $cols > 3) {
        $cols = 1;
    }
    cfg_update(['admin_nav_layout' => $layout, 'admin_nav_cols' => $cols]);
    log_action('admin_save_nav', '后台导航布局：' . ($layout === 'flat' ? '平铺标签' : '侧边导航（' . $cols . ' 列）'));
    $back = (string)($_POST['back'] ?? '');
    if (!isset(admin_tab_titles()[$back])) {
        $back = 'theme';
    }
    act_ok('导航布局已保存，立即生效', u('p=admin&tab=' . $back));
}

function act_admin_backup(): void
{
    $name = '';
    if (!backup_create($name)) {
        act_err('备份失败：数据目录不可写？');
    }
    backup_prune(5);
    log_action('admin_backup', '数据备份：' . $name);
    act_ok('备份完成：' . $name, u('p=admin&tab=system'));
}

function act_admin_backup_dl(): void
{
    $u = require_admin();
    $name = (string)($_GET['id'] ?? '');
    if (!preg_match('/^(backup|pre-update|pre-rollback|pre-migrate|migration)-\d{8}-\d{6}\.zip$/', $name)) {
        flash('err', '无效的备份文件名');
        admin_redirect('p=admin&tab=system');
    }
    $p = Store::path('backup/' . $name);
    if (!is_file($p)) {
        flash('err', '备份文件不存在');
        admin_redirect('p=admin&tab=system');
    }
    log_action('admin_backup_dl', '下载备份：' . $name, (int)$u['id'], (string)$u['name']);
    header('Content-Type: application/zip');
    header('Content-Length: ' . (string)filesize($p));
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: no-store');
    readfile($p);
    exit;
}

/** 删除备份包（POST id；文件名严格白名单，仅限 data/backup/ 下的合法备份） */
function act_admin_backup_del(): void
{
    $u = require_admin();
    $name = (string)($_POST['id'] ?? '');
    if (!preg_match('/^(backup|pre-update|pre-rollback|pre-migrate|migration)-\d{8}-\d{6}\.zip$/', $name)) {
        flash('err', '无效的备份文件名');
        admin_redirect('p=admin&tab=system');
    }
    $p = Store::path('backup/' . $name);
    if (!is_file($p)) {
        flash('err', '备份文件不存在');
        admin_redirect('p=admin&tab=system');
    }
    if (!@unlink($p)) {
        flash('err', '删除失败：文件被占用或目录不可写，请检查 data/backup/ 权限');
        admin_redirect('p=admin&tab=system');
    }
    log_action('admin_backup_del', '删除备份：' . $name, (int)$u['id'], (string)$u['name']);
    act_ok('备份已删除：' . $name, u('p=admin&tab=system'));
}

/* ================= 管理员：监控 ================= */

/** 保存监控设置：自动告警开关 + 阈值 + 主机配额（v1.39.0 起支持到 1TB） */
function act_admin_save_monitor(): void
{
    post_guard('csrf', 'monitor_mb', 'quota_mb');
    $on = !empty($_POST['monitor_on']) ? 1 : 0;
    $mb = max(1, min(1048576, (int)($_POST['monitor_mb'] ?? 95)));
    $qmb = max(10, min(1048576, (int)($_POST['quota_mb'] ?? 100)));
    $mi = (int)($_POST['monitor_interval'] ?? 5);
    $mi = $mi === 1 ? 2 : max(0, min(300, $mi)); // 0=关闭，1 归一为 2（太频繁伤主机）
    cfg_update(['monitor_on' => $on, 'monitor_mb' => $mb, 'monitor_interval' => $mi, 'quota_mb' => $qmb]);
    log_action('admin_save_monitor', '存储告警：' . ($on ? '开启' : '关闭') . '，阈值 ' . fmt_bytes($mb * 1048576) . '，主机配额 ' . fmt_bytes($qmb * 1048576) . '，监控刷新间隔 ' . ($mi > 0 ? $mi . ' 秒' : '关闭'));
    act_ok('监控设置已保存', u('p=admin&tab=monitor'));
}

/** 一键修复目录权限：对 data/ 全部关键目录跑自愈阶梯（建目录/修权限/整体搬移重建/重建空目录），并回报告果 */
function act_admin_repair_dirs(): void
{
    $dirs = ['.', 'threads', 'replies', 'sessions', 'logs', 'locks', 'backup'];
    $fixed = 0;
    $failed = [];
    foreach ($dirs as $d) {
        if (Store::repairDir($d)) {
            $fixed++;
        } else {
            $failed[] = $d === '.' ? 'data/' : 'data/' . $d . '/';
        }
    }
    // 清理自愈过程中改名暂存的 .broken-* 目录（数据已搬回的会自动删除）
    $broken = Store::cleanupBrokenDirs();
    if ($failed) {
        $msg = '修复完成：' . $fixed . ' 个目录可写，仍有 ' . count($failed) . ' 个不可写：' . implode('、', $failed)
            . '。这些目录的属主不属于 PHP 运行账号，请通过 FTP / 主机面板将其权限改为 755 或 775（必要时同时修改属主）。';
        log_action('admin_repair_dirs', '一键修复目录权限：部分失败（' . implode('、', $failed) . '）');
        flash('err', $msg);
    } else {
        log_action('admin_repair_dirs', '一键修复目录权限：全部 ' . $fixed . ' 个目录检查/修复为可写'
            . ($broken ? '；' . count($broken) . ' 个 .broken-* 暂存目录待 FTP 清理' : ''));
        $extra = $broken
            ? '另有 ' . count($broken) . ' 个 .broken-* 暂存目录（内有无法自动搬回的旧文件，数据未丢）建议通过 FTP 确认后删除。'
            : '如仍无法发帖，请重试一次发帖看是否已恢复。';
        flash('ok', '检查/修复完成：' . $fixed . ' 个目录均已可写。' . $extra);
    }
    admin_redirect('p=admin&tab=monitor');
}

/** v1.38.0 运行环境：一键开启缺失扩展 / 调大上传限制
 *  同意式自改（延续 v1.35 规矩）：前端确认弹窗 → efx_apply 强制备份 php.ini 到 data/backup/
 *  → 取消注释 / 追加标记块 → 原子写入；失败不落盘，成功后给出面板重启指引。
 *  传入扩展名经白名单与「当前缺失且扩展文件随附」双重过滤，不能开启任意指令。 */
function act_admin_envfix(): void
{
    $back = 'p=admin&tab=monitor';
    $names = [];
    foreach ((array)($_POST['exts'] ?? []) as $n) {
        if (is_string($n) && preg_match('/^[a-z0-9_]{2,20}$/', $n)) {
            $names[] = $n;
        }
    }
    $plan = efx_plan();
    $names = array_values(array_intersect($names, $plan['fixable'])); /* 只允许当前缺失且可自动开启的 */
    $upfix = isset($_POST['upfix']) && ($plan['up']['up_sug'] || $plan['up']['post_sug']);
    $hasIssues = !empty($plan['ini_issues']['count']); /* v1.38.2：php.ini 坏行也可一键修复 */
    if (!$names && !$upfix && !$hasIssues) {
        flash('err', '没有需要修改的项目：缺失扩展已全部启用，上传限制已达标，php.ini 也无坏行。');
        admin_redirect($back);
    }
    $res = efx_apply($names, $upfix);
    if ($res['ok']) {
        log_action('admin_envfix', '一键开启 PHP 扩展：' . (implode('、', $names) !== '' ? implode('、', $names) : '无')
            . ($res['fixed'] ? '；php.ini 修复 ' . count($res['fixed']) . ' 处' : '')
            . ($res['up'] ? '；上传限制 ' . implode(' / ', $res['up']) : '')
            . ($res['backup'] !== '' ? '（php.ini 备份：' . basename($res['backup']) . '）' : ''));
        flash('ok', efx_result_msg($res));
    } else {
        log_action('admin_envfix', '一键开启失败：' . cut_str((string)$res['err'], 160));
        flash('err', (string)$res['err']);
    }
    admin_redirect($back);
}

/** v1.33.0 性能体检：一键创建站点根目录 .user.ini（开启 OPcache）
 *  LiteSpeed / CGI / FastCGI / FPM 类主机读取该文件；Apache mod_php 忽略（提示文案已说明）。
 *  v1.35.0 同意式自改：
 *  - create：仅当站点根无 .user.ini 时创建；
 *  - append：已存在他人 .user.ini（不含 opcache）时，先自动备份原文件到 data/backup/，再在末尾追加（原内容保留）；
 *  - del（还原）：逐行移除 OPcache 相关行、其余内容原样保留，仅当文件因此变空时才整体删除。 */
function act_admin_opcache_ini(): void
{
    admin_tab_guard();
    $op = (string)($_POST['op'] ?? '');
    $f = dirname(DATA_DIR) . '/.user.ini';
    $back = u('p=admin&tab=monitor');
    if ($op === 'create') {
        $raw = is_file($f) ? (string)@file_get_contents($f) : '';
        if (strpos($raw, 'opcache.enable') !== false) {
            act_ok('.user.ini 已包含 opcache 配置，无需重复创建', $back);
        }
        if (trim($raw) !== '') {
            /* v1.35.0：存在他人 .user.ini 时不再拒绝，改走「追加」路径（原内容保留） */
            flash('err', '站点根目录已存在 .user.ini（不含 opcache 配置）。请改用「追加 OPcache 配置到现有 .user.ini」——完整保留你的现有内容，仅在末尾追加；或手动编辑该文件加入一行：opcache.enable=1');
            admin_redirect($back);
        }
        /* v1.35.1 预检写权限：根目录不可写时不写盘，直接引导走「下载合并文件 → FTP 上传」降级方案 */
        [$fileW, $rootW] = mf_root_write_check($f);
        if (!$rootW) {
            flash('err', '创建失败：站点根目录对 PHP 不可写，未做任何修改。已为你准备好完整文件：请用本页「下载 .user.ini（已含 OPcache 配置）」按钮下载，通过 FTP / 主机面板上传到站点根目录（若保存的文件名丢了开头的点，请重命名为 .user.ini）。');
            admin_redirect($back);
        }
        if (@file_put_contents($f, mf_userini_merged('')) === false) {
            flash('err', '创建失败：站点根目录写入异常。未做任何修改。请用本页「下载 .user.ini（已含 OPcache 配置）」按钮下载后通过 FTP 上传，内容一行核心配置：opcache.enable=1');
            admin_redirect($back);
        }
        log_action('admin_opcache_ini', '创建 .user.ini 开启 OPcache（性能体检）');
        act_ok('已创建 .user.ini（opcache.enable=1）。LiteSpeed / CGI / FastCGI / FPM 主机约 5 分钟内生效（或重启 PHP）；Apache mod_php 会忽略此文件，需联系主机商在 php.ini 开启。稍后回到「监控 → 性能体检」查看状态。', $back);
    }
    if ($op === 'append') {
        /* v1.35.0：同意式追加——先自动备份原文件，再在末尾追加 OPcache 配置，原内容一字不动 */
        $raw = is_file($f) ? (string)@file_get_contents($f) : '';
        if (strpos($raw, 'opcache.enable') !== false) {
            act_ok('.user.ini 已包含 opcache 配置，无需追加', $back);
        }
        if (trim($raw) === '') {
            flash('err', '.user.ini 不存在或为空，请改用「一键创建 .user.ini（开启 OPcache）」');
            admin_redirect($back);
        }
        /* v1.35.1 预检写权限：文件可写（直接追加）或目录可写（可 unlink 后重建）才继续；
           两者都不可写时不备份不写盘，改走「下载合并文件 → FTP 上传」降级方案 */
        [$fileW, $rootW] = mf_root_write_check($f);
        if (!$fileW && !$rootW) {
            flash('err', '追加失败：站点根目录对 PHP 不可写（你的 .user.ini 原样未动，未做任何修改）。已为你合并好完整文件：请用本页「下载 .user.ini（已含 OPcache 配置）」按钮下载（原内容 + OPcache 配置），通过 FTP / 主机面板上传到站点根目录覆盖原文件；或手动编辑该文件加入一行：opcache.enable=1');
            admin_redirect($back);
        }
        $bak = mf_text_backup($f, 'user-ini-bak');
        if ($bak === '') {
            flash('err', '追加中止：备份原文件失败（data/backup/ 不可写），为保安全未做任何修改。请检查 data/backup/ 目录权限后重试。');
            admin_redirect($back);
        }
        $content = mf_userini_merged($raw);
        /* 文件本身不可写但目录可写：unlink 只需目录写权限——删旧重建即可绕开属主 / 权限异常 */
        $ok = $fileW
            ? @file_put_contents($f, $content) !== false
            : (@unlink($f) && @file_put_contents($f, $content) !== false);
        if (!$ok) {
            flash('err', '追加失败：站点根目录写入异常，未做任何修改（你的 .user.ini 原样未动，原文件备份仍在 data/backup/' . basename($bak) . '）。请用本页「下载 .user.ini（已含 OPcache 配置）」按钮下载后通过 FTP 上传覆盖。');
            admin_redirect($back);
        }
        log_action('admin_opcache_ini', '追加 OPcache 配置到现有 .user.ini（原内容保留，原文件备份：' . basename($bak) . '）');
        act_ok('已追加 OPcache 配置到现有 .user.ini（你的原内容完整保留，原文件备份为 data/backup/' . basename($bak) . '）。LiteSpeed / CGI / FastCGI / FPM 主机约 5 分钟内生效（或重启 PHP），稍后刷新「性能体检」查看状态。', $back);
    }
    if ($op === 'del') {
        $raw = is_file($f) ? (string)@file_get_contents($f) : '';
        if (trim($raw) === '' || strpos($raw, 'opcache.enable') === false) {
            act_ok('未发现本工具写入的 OPcache 配置，未做任何改动', $back);
        }
        /* v1.35.1 预检：文件与目录均不可写时无法还原，不备份不写盘，给 FTP 手动指引 */
        [$fileW, $rootW] = mf_root_write_check($f);
        if (!$fileW && !$rootW) {
            flash('err', '还原中止：站点根目录对 PHP 不可写，未做任何修改。请通过 FTP 手动编辑 .user.ini 移除 opcache 相关行（或删除整个文件）。');
            admin_redirect($back);
        }
        $bak = mf_text_backup($f, 'user-ini-bak');
        /* v1.35.0：逐行移除 OPcache 相关行（标记块 / 旧版注释头 / opcache.* 配置行），
           其余内容（他人自建配置）原样保留；仅当移除后文件为空时才删除整个文件。 */
        $out = [];
        $skip = false;
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $ln) {
            $t = ltrim($ln);
            if (strpos($t, '; ---- Minimal Forum OPcache') === 0) {
                $skip = !$skip;
                continue;
            }
            if ($skip) {
                continue;
            }
            if (strpos($t, '; Minimal Forum v') === 0 || strpos($t, '; LiteSpeed / CGI / FastCGI') === 0) {
                continue;
            }
            if (preg_match('/^\s*opcache\.(enable|revalidate_freq)\s*=/', $ln)) {
                continue;
            }
            $out[] = $ln;
        }
        $bakName = $bak !== '' ? basename($bak) : '失败';
        /* 文件本身不可写但目录可写：unlink 只需目录写权限——删旧重建绕开属主 / 权限异常 */
        $canRecreate = $fileW || $rootW;
        if (trim(implode("\n", $out)) === '') {
            if ($canRecreate && @unlink($f)) {
                log_action('admin_opcache_ini', '移除 OPcache 配置：.user.ini 仅含 OPcache 相关内容，已整体删除（原文件备份：' . $bakName . '）');
                act_ok('已移除 OPcache 配置：.user.ini 只含 OPcache 相关内容，已整体删除（原文件备份为 data/backup/' . $bakName . '）。', $back);
            }
            flash('err', '删除失败：站点根目录写入异常，未做修改。请通过 FTP 删除 .user.ini');
            admin_redirect($back);
        }
        if (!$canRecreate || @file_put_contents($f, implode("\n", $out)) === false) {
            flash('err', '还原失败：站点根目录写入异常，未做任何修改。请通过 FTP 手动编辑 .user.ini 移除 opcache 相关行');
            admin_redirect($back);
        }
        log_action('admin_opcache_ini', '移除 OPcache 配置：其余自建内容完整保留（原文件备份：' . $bakName . '）');
        act_ok('已移除 OPcache 配置，.user.ini 其余内容完整保留（原文件备份为 data/backup/' . $bakName . '）。', $back);
    }
    admin_redirect($back);
}

/** v1.35.0 robots.txt 一键同步（SEO 设置）：同意式写盘——覆盖前先自动备份原文件到 data/backup/
 *  v1.35.1：预检站点根目录写权限，不可写时改走「下载推荐内容 → FTP 上传」降级方案，不再盲试写盘 */
function act_admin_robots_sync(): void
{
    admin_tab_guard();
    $f = dirname(DATA_DIR) . '/robots.txt';
    $rec = mf_robots_recommended(true);
    $raw = is_file($f) ? (string)@file_get_contents($f) : '';
    $back = u('p=admin&tab=basic');
    if (mf_robots_is_recommended($raw)) {
        act_ok('服务器 robots.txt 已是推荐内容，无需同步', $back);
    }
    /* v1.35.1 预检写权限：文件可写（直接覆盖）或目录可写（可 unlink 后重建）才继续；
       两者都不可写时不备份不写盘，引导走「下载 robots.txt → FTP 上传」降级方案 */
    [$fileW, $rootW] = mf_root_write_check($f);
    if (!$fileW && !$rootW) {
        flash('err', '同步失败：站点根目录对 PHP 不可写（原 robots.txt 原样未动，未做任何修改）。已为你准备好推荐内容文件：请用本页「下载 robots.txt（推荐内容）」按钮下载，通过 FTP / 主机面板上传到站点根目录覆盖原文件（如需保留原内容，先自行下载备份）。');
        admin_redirect($back);
    }
    if ($raw !== '') {
        $bak = mf_text_backup($f, 'robots-bak');
        if ($bak === '') {
            flash('err', '同步中止：备份原文件失败（data/backup/ 不可写），为保安全未做任何修改。请检查 data/backup/ 目录权限后重试。');
            admin_redirect($back);
        }
        /* 文件本身不可写但目录可写：unlink 只需目录写权限——删旧重建绕开属主 / 权限异常 */
        $ok = $fileW
            ? @file_put_contents($f, $rec) !== false
            : (@unlink($f) && @file_put_contents($f, $rec) !== false);
        if (!$ok) {
            flash('err', '同步失败：站点根目录写入异常，未做任何修改（原 robots.txt 原样未动，原文件备份仍在 data/backup/' . basename($bak) . '）。请用本页「下载 robots.txt（推荐内容）」按钮下载后通过 FTP 上传覆盖。');
            admin_redirect($back);
        }
        log_action('admin_robots_sync', '覆盖 robots.txt 为推荐内容（原文件备份：' . basename($bak) . '）');
        act_ok('已同步 robots.txt（原文件备份为 data/backup/' . basename($bak) . '）。爬虫规则将在搜索引擎下次抓取时生效。', $back);
    }
    if (@file_put_contents($f, $rec) === false) {
        flash('err', '创建失败：站点根目录写入异常，未做任何修改。请用本页「下载 robots.txt（推荐内容）」按钮下载后通过 FTP 上传到站点根目录。');
        admin_redirect($back);
    }
    log_action('admin_robots_sync', '创建 robots.txt（推荐内容）');
    act_ok('已在站点根目录创建 robots.txt（推荐内容）。爬虫规则将在搜索引擎下次抓取时生效。', $back);
}

/** v1.35.1 降级下载：站点根目录不可写时，下载合并 / 推荐内容文件，
 *  由管理员通过 FTP / 主机面板上传到站点根目录（附件响应，不落地 data/，不留临时文件） */
function act_admin_file_download(): void
{
    admin_tab_guard();
    $what = (string)($_POST['what'] ?? '');
    if ($what === 'userini') {
        $f = dirname(DATA_DIR) . '/.user.ini';
        $raw = is_file($f) && is_readable($f) ? (string)@file_get_contents($f) : '';
        /* 已含 OPcache 配置：下发当前原文件内容（上传回去即还原）；
           否则下发合并内容（原内容保留 + OPcache 标记块），与「追加」写入内容同源 */
        $content = strpos($raw, 'opcache.enable') !== false ? $raw : mf_userini_merged($raw);
        mf_text_download($content, '.user.ini');
    }
    /* v1.36.0 静态加速：下载已合并加速块的 .htaccess（站点根不可写时的降级方案，
       内容与「安装加速块」写入内容同源；下载后请重命名为 .htaccess 上传到站点根目录） */
    if ($what === 'htaccess') {
        $f = dirname(DATA_DIR) . '/.htaccess';
        $raw = is_file($f) && is_readable($f) ? (string)@file_get_contents($f) : '';
        $base = $raw;
        if (strpos($raw, MFS_MARK_BEGIN) !== false) {
            $base = trim((string)preg_replace('/' . preg_quote(MFS_MARK_BEGIN, '/') . '.*?' . preg_quote(MFS_MARK_END, '/') . '\s*/s', '', $raw));
        }
        $content = trim($base) === '' ? mfs_htaccess_block() . "\n" : rtrim($base) . "\n\n" . mfs_htaccess_block() . "\n";
        mf_text_download($content, 'htaccess.txt');
    }
    /* v1.38.0 运行环境：php.ini 不可写 / 未找到时的降级方案——下载配置片段（与一键写入同源），
       FTP / 面板编辑器追加到当前 PHP 的 php.ini 末尾后重启 */
    if ($what === 'envfix') {
        mf_text_download(efx_snippet(), 'php-ini-snippet.txt');
    }
    mf_text_download(mf_robots_recommended(true), 'robots.txt');
}

/* ================= 管理员：HTML 静态加速（v1.36.0） ================= */

/** 模式切换：op=html 静态优先（游客快照直出）/ 其余 = PHP 动态优先（默认） */
function act_admin_static_mode(): void
{
    $back = u('p=admin&tab=monitor');
    if ((string)($_POST['op'] ?? '') === 'html') {
        if (!feat_on('guest_browse')) {
            flash('err', '无法开启：当前已关闭「游客可浏览」（私密论坛），游客静态快照与此模式互斥。请先到「功能」页开启游客浏览。');
            admin_redirect($back);
        }
        if ((int)cfg('doc_gate', 0) === 1) {
            flash('err', '无法开启：已开启协议访问门禁——Apache 直出快照无法执行门禁判定。请先在「协议」页关闭访问门禁。');
            admin_redirect($back);
        }
        if (!mfs_dir_ready()) {
            flash('err', '无法开启：快照目录创建失败（data/static/ 不可写）。请到「监控 → 环境自检」修复目录权限后重试。');
            admin_redirect($back);
        }
        cfg_update(['static_mode' => 'html']);
        $msg = 'HTML 静态优先已开启：游客开始浏览静态快照（首次访问自动生成），PHP 快速通道即刻生效';
        $err = '';
        if (mfs_htaccess_install($err)) {
            $msg .= '；.htaccess 加速块已写入（原文件自动备份到 data/backup/），Apache / LiteSpeed 下命中快照时 PHP 完全不启动';
        } else {
            $msg .= '；.htaccess 加速块未写入（' . $err . '）——不影响使用，PHP 快速通道已生效，也可用下方「下载 .htaccess」手动上传';
        }
        log_action('admin_static_mode', '切换到 HTML 静态优先（游客快照直出）');
        act_ok($msg, $back);
    }
    cfg_update(['static_mode' => 'php']);
    $err = '';
    $msg = '已切回 PHP 动态优先（全部页面实时渲染），静态快照已清空';
    if (mfs_htaccess_remove($err)) {
        $msg .= '，.htaccess 加速块已移除';
    } else {
        $msg .= '；.htaccess 加速块移除失败：' . $err;
    }
    mfs_clear_all();
    log_action('admin_static_mode', '切回 PHP 动态优先');
    act_ok($msg, $back);
}

/** .htaccess 加速块手动安装 / 更新 / 移除（同意式，幂等，先备份） */
function act_admin_static_htaccess(): void
{
    $back = u('p=admin&tab=monitor');
    $err = '';
    if ((string)($_POST['op'] ?? '') === 'remove') {
        if (mfs_htaccess_remove($err)) {
            log_action('admin_static_htaccess', '移除 .htaccess 加速块');
            act_ok('.htaccess 加速块已移除（原文件备份在 data/backup/）。游客访问回退为 PHP 动态渲染。', $back);
        }
        flash('err', '移除失败：' . $err . ' 也可通过 FTP 编辑站点根目录 .htaccess，删除标记块之间的内容。');
        admin_redirect($back);
    }
    if (!mfs_dir_ready()) {
        flash('err', '快照目录尚未就绪（data/static/ 不可写）：加速块写入后也需要它存放快照，请先修复目录权限。');
        admin_redirect($back);
    }
    if (mfs_htaccess_install($err)) {
        log_action('admin_static_htaccess', '写入 / 更新 .htaccess 加速块');
        act_ok('.htaccess 加速块已写入（原文件备份在 data/backup/）。游客命中快照时 Apache / LiteSpeed 直接发送文件，PHP 不启动；其他服务器自动走 PHP 快速通道。', $back);
    }
    flash('err', '写入失败：' . $err . ' 可改用「下载 .htaccess（已合并加速块）」按钮，下载后重命名为 .htaccess 通过 FTP 上传到站点根目录。');
    admin_redirect($back);
}

/** 清空全部静态快照 */
function act_admin_static_clear(): void
{
    $n = mfs_clear_all();
    log_action('admin_static_clear', '清空静态快照：' . $n . ' 个文件');
    act_ok('已清空 ' . $n . ' 个静态快照。游客访问将回退 PHP 实时渲染，快照随访问自动重新生成。', u('p=admin&tab=monitor'));
}

/* ================= 管理员：迁移搬家（v1.37.0） ================= */

/** 制作迁移包（选项：含附件 / 含日志），完成后在系统页列表下载 */
function act_admin_migrate_build(): void
{
    $u = admin_tab_guard();
    $incMedia = !empty($_POST['inc_media']);
    $incLogs = !empty($_POST['inc_logs']);
    $err = '';
    $name = mig_build($incMedia, $incLogs, $err);
    if ($name === null) {
        log_action('migrate_build_fail', '迁移包制作失败：' . $err, (int)$u['id'], (string)$u['name']);
        flash('err', '制作失败：' . $err);
        admin_redirect('p=admin&tab=system');
    }
    flash('ok', '迁移包已生成：' . $name . '（在下方列表点「下载」保存到本地，新服务器安装向导或本页导入时使用）');
    admin_redirect('p=admin&tab=system');
}

/** 删除迁移包 */
function act_admin_migrate_delete(): void
{
    $u = admin_tab_guard();
    $name = (string)($_POST['name'] ?? '');
    if (!mig_delete($name)) {
        flash('err', '删除失败：无效的迁移包文件名或文件不存在');
        admin_redirect('p=admin&tab=system');
    }
    log_action('migrate_delete', '删除迁移包：' . $name, (int)$u['id'], (string)$u['name']);
    flash('ok', '迁移包已删除：' . $name);
    admin_redirect('p=admin&tab=system');
}

/**
 * 导入迁移包（已安装站点）：上传 zip 或选择 FTP 上传到 data/backup/ 的已有包。
 * 成功后原会话作废，需用迁移包内（原站）管理员账号重新登录。
 */
function act_admin_migrate_import(): void
{
    $u = admin_tab_guard();
    if (empty($_POST['confirm'])) {
        flash('err', '请先勾选「我确认覆盖当前全部数据」再执行导入');
        admin_redirect('p=admin&tab=system');
    }
    $path = '';
    $pick = (string)($_POST['pick'] ?? '');
    if ($pick !== '') {
        /* 大包降级通道：FTP 上传到 data/backup/ 后在此选择导入 */
        if (!preg_match('/^migration-\d{8}-\d{6}\.zip$/', $pick)) {
            flash('err', '无效的迁移包文件名');
            admin_redirect('p=admin&tab=system');
        }
        $path = Store::path('backup/' . $pick);
        if (!is_file($path)) {
            flash('err', '所选迁移包不存在：' . $pick);
            admin_redirect('p=admin&tab=system');
        }
    } else {
        if (empty($_FILES['f']) || !is_array($_FILES['f'])
            || (int)($_FILES['f']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file((string)($_FILES['f']['tmp_name'] ?? ''))) {
            flash('err', '请选择迁移包文件（zip）。若提示超出上传限制，可改用 FTP 上传到 data/backup/ 后从列表选择导入');
            admin_redirect('p=admin&tab=system');
        }
        $path = (string)$_FILES['f']['tmp_name'];
    }
    $res = [];
    try {
        mig_import($path, $res);
    } catch (RuntimeException $e) {
        flash('err', '导入中止：' . $e->getMessage());
        admin_redirect('p=admin&tab=system');
    } catch (Throwable $e) {
        flash('err', '导入中止：迁移包无法读取（' . cut_str($e->getMessage(), 80) . '）');
        admin_redirect('p=admin&tab=system');
    }
    $m = $res['manifest'];
    log_action('migrate_import_ok', sprintf(
        '迁移导入完成：v%s「%s」，%d 文件已覆盖%s',
        (string)($m['version'] ?? '?'),
        (string)($m['site'] ?? ''),
        (int)$res['files'],
        $res['backup'] !== '' ? '；导入前备份 ' . $res['backup'] : ''
    ));
    $msg = sprintf(
        '迁移完成：「%s」的数据已就位（%d 帖 / %d 用户），当前会话已作废。请使用原站账号登录',
        (string)($m['site'] ?? '未命名站点'),
        (int)($m['counts']['threads'] ?? 0),
        (int)($m['counts']['users'] ?? 0)
    );
    foreach ($res['notes'] as $n) {
        $msg .= '；' . $n;
    }
    flash('ok', $msg);
    redirect(u('p=login'));
}

/** 手动发送测试 / 真实告警邮件（force 跳过冷却） */
function act_admin_test_monitor(): void
{
    [$level, $msg] = sysmon_storage_alert(true, true);
    log_action('admin_test_monitor', '手动触发存储告警检查：' . $msg);
    json_response(['ok' => $level === 'alert' || $level === 'ok', 'msg' => $msg]);
}

/* ================= 管理员：更新包 / 日志 ================= */

/** 上传并安装更新包（zip） */
function act_admin_update(): void
{
    if (empty($_FILES['pkg']) || !is_array($_FILES['pkg'])) {
        act_err('请选择更新包 zip 文件');
    }
    $f = $_FILES['pkg'];
    if ((int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $map = [
            UPLOAD_ERR_INI_SIZE => '文件超过服务器上传限制（upload_max_filesize）',
            UPLOAD_ERR_FORM_SIZE => '文件超过表单大小限制',
            UPLOAD_ERR_PARTIAL => '文件仅上传了一部分，请重试',
            UPLOAD_ERR_NO_FILE => '请选择更新包 zip 文件',
        ];
        act_err($map[(int)$f['error']] ?? '上传失败（错误码 ' . (int)$f['error'] . '）');
    }
    if (!preg_match('/\.zip$/i', (string)($f['name'] ?? ''))) {
        act_err('更新包必须是 .zip 文件');
    }
    if ((int)($f['size'] ?? 0) > 15 * 1048576) {
        act_err('更新包超过 15MB，请确认文件是否正确');
    }
    $tmp = Store::path('locks/.update-' . bin2hex(random_bytes(4)) . '.zip');
    $saved = @is_uploaded_file($f['tmp_name']) && @move_uploaded_file($f['tmp_name'], $tmp);
    if (!$saved) {
        $saved = @rename((string)$f['tmp_name'], $tmp) || @copy((string)$f['tmp_name'], $tmp);
    }
    if (!$saved) {
        act_err('保存上传文件失败：data 目录不可写？');
    }
    $res = [];
    try {
        update_apply($tmp, $res);
    } catch (Throwable $ex) {
        @unlink($tmp);
        log_action('admin_update', '更新失败：' . $ex->getMessage());
        flash('err', '更新失败：' . $ex->getMessage());
        admin_redirect('p=admin&tab=update');
    }
    @unlink($tmp);
    flash('ok', '更新成功：v' . $res['from'] . ' → v' . $res['version'] . '，共应用 ' . $res['files'] . ' 个文件'
        . ($res['backup'] !== '' ? '；更新前程序已备份为 ' . $res['backup'] : ''));
    admin_redirect('p=admin&tab=update');
}

/** 日志设置：保留天数 + 页面访问日志开关 */
function act_admin_logs_settings(): void
{
    $days = max(0, min(3650, (int)($_POST['log_days'] ?? 90)));
    $views = !empty($_POST['log_views']) ? 1 : 0;
    cfg_update(['log_days' => $days, 'log_views' => $views]);
    log_action('admin_logs_settings', '日志保留 ' . ($days > 0 ? $days . ' 天' : '永久') . '；页面访问日志：' . ($views ? '开' : '关'));
    act_ok('日志设置已保存', u('p=admin&tab=logs'));
}

/** 立即清理过期日志 */
function act_admin_logs_clear(): void
{
    $n = log_prune(max(0, (int)cfg('log_days', 90)));
    log_action('admin_logs_clear', '手动清理，删除 ' . $n . ' 个过期日志文件');
    if ($n > 0) {
        flash('ok', '已清理 ' . $n . ' 个过期日志文件');
    } else {
        flash('ok', '没有需要清理的日志文件');
    }
    admin_redirect('p=admin&tab=logs');
}

/** 一键导出全部日志为 TXT（操作日志 + 防火墙事件 + 错误日志尾部），流式下载 */
function act_admin_logs_export(): void
{
    $u = admin_tab_guard();
    log_action('admin_logs_export', '导出全部日志为 TXT（操作日志 + 防火墙事件 + 错误日志尾部）', (int)($u['id'] ?? 0), (string)($u['name'] ?? ''));
    log_export_txt(); // 内部直接流式输出并 exit，不占用内存
}

/** 保存攻击告警设置（阈值 / 冷却 / 开关；独立接口，不影响其他防护设置） */
function act_admin_save_attack(): void
{
    post_guard('csrf', 'fw_atk_alert_n');
    $on = !empty($_POST['fw_atk_alert_on']) ? 1 : 0;
    $n = max(5, min(10000, (int)($_POST['fw_atk_alert_n'] ?? 20)));
    $cool = max(5, min(1440, (int)($_POST['fw_atk_alert_cool'] ?? 30)));
    cfg_update(['fw_atk_alert_on' => $on, 'fw_atk_alert_n' => $n, 'fw_atk_alert_cool' => $cool]);
    log_action('admin_save_attack', '攻击告警：' . ($on ? '开启' : '关闭') . '，阈值 ' . $n . ' 次/' . FW_ATK_WIN_MIN . ' 分钟，冷却 ' . $cool . ' 分钟');
    act_ok('攻击告警设置已保存', u('p=admin&tab=security'));
}

/** 测试攻击告警邮件（AJAX，不占用真实告警冷却） */
function act_admin_test_attack(): void
{
    [$ok, $err] = fw_attack_test_mail();
    log_action('admin_test_attack', '测试攻击告警邮件：' . ($ok ? '已发送' : '失败：' . cut_str($err, 100)));
    json_response(['ok' => $ok, 'msg' => $ok ? '测试邮件已发送，请查收管理员邮箱' : ('发送失败：' . $err)]);
}

/* ================= 管理员：防火墙（安全防护） ================= */

/** 保存防火墙总开关（总览页独立小表单：只动总开关本身，绝不触碰限流 / 策略 / 白名单 / CF 适配等其他设置） */
function act_admin_fw_on(): void
{
    $on = array_key_exists('fw_on', $_POST) ? (!empty($_POST['fw_on']) ? 1 : 0) : (int)cfg('fw_on', 1);
    cfg_update(['fw_on' => $on]);
    log_action('fw_save', '防护总开关：' . ($on ? '开' : '关') . '（其余防护设置保持不变）');
    act_ok('总开关已保存，其余防护设置未改动', u('p=admin&tab=security'));
}

/** 保存防护设置：总开关 / 限流 / 自动策略 / 白名单 / 反代识别（完整表单；未提交的字段保留原值） */
function act_admin_fw_save(): void
{
    $kv = [
        // 旧 bug：总开关小表单也提交到这里，缺字段全部按默认值覆盖；现在缺省字段一律回退当前值，不再误重置
        'fw_on'              => array_key_exists('fw_on', $_POST) ? (!empty($_POST['fw_on']) ? 1 : 0) : (int)cfg('fw_on', 1),
        'fw_rl_on'           => !empty($_POST['fw_rl_on']) ? 1 : 0,
        'fw_rl_pm'           => max(5, min(10000, (int)($_POST['fw_rl_pm'] ?? 60))),
        'fw_rl_ban_min'      => max(0, min(1440, (int)($_POST['fw_rl_ban_min'] ?? 0))),
        'fw_score_on'        => !empty($_POST['fw_score_on']) ? 1 : 0,
        'fw_score_threshold' => max(20, min(10000, (int)($_POST['fw_score_threshold'] ?? 100))),
        'fw_auto_ban_hours'  => max(1, min(720, (int)($_POST['fw_auto_ban_hours'] ?? 24))),
        'fw_r_empty_ua'      => !empty($_POST['fw_r_empty_ua']) ? 1 : 0,
        'fw_r_script_ua'     => !empty($_POST['fw_r_script_ua']) ? 1 : 0,
        'fw_r_scan_path'     => !empty($_POST['fw_r_scan_path']) ? 1 : 0,
        'fw_r_inject'        => !empty($_POST['fw_r_inject']) ? 1 : 0,
        'fw_spider_allow'    => !empty($_POST['fw_spider_allow']) ? 1 : 0,
        'fw_r_fake_spider'   => !empty($_POST['fw_r_fake_spider']) ? 1 : 0,
        'fw_r_bot_ua'        => !empty($_POST['fw_r_bot_ua']) ? 1 : 0,
        'fw_r_probe'         => !empty($_POST['fw_r_probe']) ? 1 : 0,
        'fw_r_spoof_cf'      => !empty($_POST['fw_r_spoof_cf']) ? 1 : 0,
        'fw_r_long_req'      => !empty($_POST['fw_r_long_req']) ? 1 : 0,
        'fw_trust_cf'        => !empty($_POST['fw_trust_cf']) ? 1 : 0,
        'fw_trust_xff'       => !empty($_POST['fw_trust_xff']) ? 1 : 0,
    ];
    // Cloudflare 官方网段覆盖（可选）：逗号 / 空白分隔，v4 CIDR 或 v6 CIDR；留空用内置官方网段
    $cfRanges = implode(',', fw_cf_range_list(post_str('fw_cf_ranges', 600)));
    $kv['fw_cf_ranges'] = $cfRanges;
    // 白名单清洗：仅保留合法 IP / CIDR
    $wl = implode(',', fw_ip_list(post_str('fw_whitelist', 500)));
    $kv['fw_whitelist'] = $wl;
    cfg_update($kv);
    log_action('fw_save', '防护设置：总开关' . ($kv['fw_on'] ? '开' : '关') . '，限流 ' . ($kv['fw_rl_on'] ? $kv['fw_rl_pm'] . ' 次/分钟' : '关')
        . '，策略阈值 ' . $kv['fw_score_threshold'] . ' 分/' . $kv['fw_auto_ban_hours'] . 'h，白名单 ' . ($wl !== '' ? count(explode(',', $wl)) . ' 条' : '空')
        . '，CF 适配 ' . ($kv['fw_trust_cf'] ? '开' : '关') . '，XFF ' . ($kv['fw_trust_xff'] ? '开' : '关'));
    act_ok('防护设置已保存', u('p=admin&tab=security'));
}

/** Cloudflare 网段列表清洗：逗号 / 空白分隔，逐个校验 v4 CIDR（fw_cidr_range）或 v6 CIDR，去重 */
function fw_cf_range_list(string $raw): array
{
    $out = [];
    foreach (preg_split('/[\s,，;；]+/u', trim($raw)) ?: [] as $p) {
        $p = trim((string)$p);
        if ($p === '') {
            continue;
        }
        if (fw_cidr_range($p) !== null || preg_match('#^[0-9a-fA-F:]+/\d{1,3}$#', $p) === 1) {
            $out[] = $p;
        }
    }
    return array_values(array_unique($out));
}

/** 保存危险 IP 库设置：开关 / 自动同步间隔 / 内置源开关 / 自定义源 */
function act_admin_fw_intel_save(): void
{
    $kv = [
        'fw_intel_on'    => !empty($_POST['fw_intel_on']) ? 1 : 0,
        'fw_intel_hours' => max(1, min(168, (int)($_POST['fw_intel_hours'] ?? 24))),
        'fw_src_et'        => !empty($_POST['fw_src_et']) ? 1 : 0,
        'fw_src_feodo'     => !empty($_POST['fw_src_feodo']) ? 1 : 0,
        'fw_src_blackbook' => !empty($_POST['fw_src_blackbook']) ? 1 : 0,
    ];
    // 自定义源：每行一个 URL，最多 5 条，仅接受 http(s)
    $custom = [];
    foreach (array_slice(explode("\n", str_replace("\r", '', post_str('fw_intel_custom', 600))), 0, 5) as $u) {
        $u = trim($u);
        if ($u !== '' && preg_match('#^https?://[^\s\'"<>]+$#i', $u)) {
            $custom[] = $u;
        }
    }
    $kv['fw_intel_custom'] = implode("\n", $custom);
    cfg_update($kv);
    log_action('fw_save', '危险 IP 库设置：拦截' . ($kv['fw_intel_on'] ? '开' : '关') . '，同步间隔 ' . $kv['fw_intel_hours'] . 'h，自定义源 ' . count($custom) . ' 条');
    act_ok('危险 IP 库设置已保存', u('p=admin&tab=security'));
}

/** 立即同步危险 IP 库 */
function act_admin_fw_intel_sync(): void
{
    $r = fw_intel_sync(true);
    $ok = 0;
    $fail = [];
    foreach ((array)($r['sources'] ?? []) as $s) {
        if (!empty($s['ok'])) {
            $ok++;
        } else {
            $fail[] = (string)($s['name'] ?? '源') . '：' . (string)($s['err'] ?? '失败');
        }
    }
    $msg = '同步完成：' . (int)$r['total'] . ' 条（IP ' . count($r['ips']) . ' · 网段 ' . count($r['nets']) . '），' . $ok . ' 个源成功'
        . ($fail ? '；失败：' . implode('；', $fail) : '');
    flash(strpos($msg, '失败') === false ? 'ok' : 'err', $msg);
    admin_redirect('p=admin&tab=security');
}

/** 导入黑名单文本 / 上传 txt */
function act_admin_fw_intel_import(): void
{
    $me = require_admin();
    $text = post_str('list', 200000);
    if (isset($_FILES['file']) && is_array($_FILES['file']) && (int)($_FILES['file']['error'] ?? 4) === UPLOAD_ERR_OK) {
        $f = $_FILES['file'];
        if ((int)($f['size'] ?? 0) <= 2 * 1048576 && @is_uploaded_file((string)$f['tmp_name'])
            && preg_match('/\.(txt|csv|list)$/i', (string)($f['name'] ?? ''))) {
            $up = (string)@file_get_contents((string)$f['tmp_name']);
            if ($up !== '') {
                $text = cut_str($text . "\n" . $up, 200000);
            }
        } else {
            flash('err', '上传文件无效（仅支持 ≤2MB 的 .txt / .csv / .list）');
            admin_redirect('p=admin&tab=security');
        }
    }
    $text = trim($text);
    if ($text === '') {
        flash('err', '请粘贴黑名单文本或上传 txt 文件');
        admin_redirect('p=admin&tab=security');
    }
    $n = fw_intel_import($text, (string)$me['name']);
    log_action('fw_intel_import', '手动导入黑名单：' . $n . ' 条有效记录', (int)$me['id']);
    if ($n > 0) {
        flash('ok', '导入成功：新增 ' . $n . ' 条（IP / 网段），已合并进危险 IP 库');
    } else {
        flash('err', '没有解析到有效的 IP / 网段（每行一个，支持 CIDR 与区间）');
    }
    admin_redirect('p=admin&tab=security');
}

/** 手动封禁（单 IP 或 CIDR） */
function act_admin_fw_ban(): void
{
    $me = require_admin();
    $ip = trim(post_str('ip', 45));
    if (fw_cidr_range($ip) === null) {
        act_err('IP 或网段格式不正确：' . $ip);
    }
    if (strcasecmp($ip, fw_ip()) === 0 || fw_whitelisted($ip)) {
        act_err('不能封禁你当前的 IP 或白名单内的 IP（如确需封禁请先调整白名单）');
    }
    $dur = (int)($_POST['dur'] ?? 1440);
    $dur = $dur < 0 ? 1440 : min(43200, $dur);
    $reason = cut_str(post_str('reason', 100), 100);
    $hours = $dur > 0 ? (int)ceil($dur / 60) : 0;
    if (fw_ban($ip, $hours, $reason !== '' ? $reason : '手动封禁', 'manual', (string)$me['name'])) {
        log_action('fw_ban', '封禁 ' . $ip . ($hours > 0 ? '（' . $hours . ' 小时）' : '（永久）') . '：' . $reason, (int)$me['id']);
        act_ok('已封禁 ' . $ip . ($hours > 0 ? '，时长 ' . $hours . ' 小时' : '（永久）'), u('p=admin&tab=security'));
    }
    act_err('封禁失败：数据目录不可写？');
}

/** 解除封禁 */
function act_admin_fw_unban(): void
{
    $me = require_admin();
    $ip = trim(post_str('ip', 45));
    if (fw_unban($ip)) {
        log_action('fw_unban', '解除封禁：' . $ip, (int)$me['id']);
        act_ok('已解除封禁：' . $ip, u('p=admin&tab=security'));
    }
    act_err('未找到该封禁记录：' . $ip);
}

/** 新增自定义规则 */
function act_admin_fw_rule_add(): void
{
    $name = cut_str(post_str('name', 30), 30);
    $type = (string)($_POST['type'] ?? 'ua');
    $mode = (string)($_POST['mode'] ?? 'text');
    $pattern = cut_str(post_str('pattern', 120), 120);
    $action = (string)($_POST['action'] ?? 'score') === 'ban' ? 'ban' : 'score';
    $val = max(1, min(720, (int)($_POST['val'] ?? 50)));
    if ($name === '' || $pattern === '') {
        act_err('规则名与匹配内容不能为空');
    }
    if (!in_array($type, ['ua', 'uri', 'query'], true)) {
        $type = 'ua';
    }
    if (!in_array($mode, ['text', 'regex'], true)) {
        $mode = 'text';
    }
    if ($mode === 'regex' && @preg_match('#' . str_replace('#', '\#', $pattern) . '#i', '') === false) {
        act_err('正则表达式无效，请检查语法');
    }
    $rules = fw_rules_all();
    $rules[] = [
        'id'        => bin2hex(random_bytes(4)),
        'name'      => $name,
        'type'      => $type,
        'mode'      => $mode,
        'pattern'   => $pattern,
        'action'    => $action,
        'score'     => $action === 'score' ? $val : 0,
        'ban_hours' => $action === 'ban' ? $val : 0,
        'on'        => 1,
        'time'      => time(),
    ];
    fw_rules_save($rules);
    log_action('fw_rule_add', '新增规则「' . $name . '」（' . $type . ' · ' . $mode . ' · ' . $action . '）');
    act_ok('规则已添加并启用', u('p=admin&tab=security'));
}

/** 删除自定义规则 */
function act_admin_fw_rule_del(): void
{
    $id = trim((string)($_POST['id'] ?? ''));
    $rules = fw_rules_all();
    $kept = [];
    $found = '';
    foreach ($rules as $r) {
        if ((string)($r['id'] ?? '') === $id) {
            $found = (string)($r['name'] ?? $id);
            continue;
        }
        $kept[] = $r;
    }
    if ($found !== '') {
        fw_rules_save($kept);
        log_action('fw_rule_del', '删除规则「' . $found . '」');
        act_ok('规则已删除', u('p=admin&tab=security'));
    }
    act_err('规则不存在');
}

/** 启停自定义规则 */
function act_admin_fw_rule_toggle(): void
{
    $id = trim((string)($_POST['id'] ?? ''));
    $rules = fw_rules_all();
    $msg = '';
    foreach ($rules as &$r) {
        if ((string)($r['id'] ?? '') === $id) {
            $r['on'] = empty($r['on']) ? 1 : 0;
            $msg = (string)$r['name'] . ($r['on'] ? ' 已启用' : ' 已停用');
            break;
        }
    }
    unset($r);
    if ($msg !== '') {
        fw_rules_save($rules);
        act_ok($msg, u('p=admin&tab=security'));
    }
    act_err('规则不存在');
}

/** 清空某日防火墙日志 */
function act_admin_fw_log_clear(): void
{
    $date = (string)($_POST['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        flash('err', '日期无效');
        admin_redirect('p=admin&tab=security');
    }
    $f = Store::path('logs/fw-' . $date . '.php');
    $ok = is_file($f) ? @unlink($f) : true;
    log_action('fw_log_clear', '清空防火墙日志：' . $date . ($ok ? '' : '（删除失败，请检查权限）'));
    if ($ok) {
        flash('ok', '已清空 ' . $date . ' 的防火墙日志');
    } else {
        flash('err', '删除失败：文件被占用或目录不可写');
    }
    redirect(u('p=admin&tab=security&fwdate=' . urlencode($date)));
}

/** 批量查询 IP 归属地（AJAX，POST ips=JSON 数组） */
function act_admin_fw_geo_batch(): void
{
    require_admin();
    $raw = (string)($_POST['ips'] ?? '[]');
    $arr = json_decode($raw, true);
    if (!is_array($arr)) {
        $arr = [];
    }
    $arr = array_slice(array_filter(array_map('strval', $arr), function ($x) {
        return filter_var($x, FILTER_VALIDATE_IP) !== false;
    }), 0, 60);
    if (!$arr) {
        json_response(['ok' => false, 'msg' => '没有有效的 IP']);
    }
    $r = fw_geo_lookup($arr);
    $out = [];
    foreach (($r['geo'] ?? []) as $ip => $g) {
        $out[$ip] = fw_geo_label($g);
    }
    json_response(['ok' => true, 'geo' => $out, 'msg' => (string)($r['msg'] ?? '')]);
}

/* ================= v1.21.0 存储引擎 / SEO / 语言 / 巡逻器控制 ================= */

/** 保存 SEO 设置（搜索引擎展示：标题模板 / 描述 / 关键词 / 收收 / Sitemap / OG / 自定义 head） */
function act_admin_save_seo(): void
{
    post_guard('csrf', 'seo_title_tpl', 'seo_robots');
    $me = admin_tab_guard();
    $robotsOk = ['', 'noindex, follow', 'index, nofollow', 'noindex, nofollow'];
    $robots = post_str('seo_robots', 30);
    if (!in_array($robots, $robotsOk, true)) {
        $robots = '';
    }
    $head = post_str('seo_extra_head', 2000);
    /* 自定义 head 仅防「提前闭合 head」的布局破坏；内容本身与自定义 CSS 同级信任（仅管理员可写） */
    $head = str_ireplace('</head', '', $head);
    $kv = [
        'seo_title_tpl'     => post_str('seo_title_tpl', 100),
        'seo_description'   => post_str('seo_description', 200),
        'seo_keywords'      => post_str('seo_keywords', 200),
        'seo_robots'        => $robots,
        'seo_sitemap'       => !empty($_POST['seo_sitemap']) ? 1 : 0,
        'seo_sitemap_limit' => min(5000, max(10, (int)post_str('seo_sitemap_limit', 6))),
        'seo_og'            => !empty($_POST['seo_og']) ? 1 : 0,
        'seo_extra_head'    => $head,
    ];
    cfg_update($kv);
    log_action('admin_save_seo', 'SEO 设置已保存：收录 ' . ($robots !== '' ? $robots : '默认')
        . '，Sitemap ' . ($kv['seo_sitemap'] ? '开' : '关'), (int)$me['id']);
    act_ok('SEO 设置已保存');
}

/** 保存界面语言设置（默认语言 / 按 IP 自动切换） */
function act_admin_save_lang(): void
{
    post_guard('csrf', 'lang', 'lang_default');
    $me = admin_tab_guard();
    $def = (string)($_POST['lang_default'] ?? 'zh-cn');
    if (!is_lang($def)) {
        $def = 'zh-cn';
    }
    $kv = [
        'lang_default' => $def,
        'lang_auto_ip' => !empty($_POST['lang_auto_ip']) ? 1 : 0,
    ];
    cfg_update($kv);
    log_action('admin_save_lang', '语言设置已保存：默认 ' . $def . '，IP 自动切换 ' . ($kv['lang_auto_ip'] ? '开' : '关'), (int)$me['id']);
    act_ok('语言设置已保存');
}

/** 用户自助保存界面语言（个人设置 / 语言切换）：写入资料 + 会话 + Cookie（三处长期生效） */
function act_save_lang(): void
{
    $u = require_login();
    $lang = (string)($_POST['lang'] ?? ($_GET['lang'] ?? ''));
    if (!is_lang($lang)) {
        act_err('不支持的语言');
    }
    user_update((int)$u['id'], ['lang' => $lang]);
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['lang'] = $lang;
    }
    if (!headers_sent()) {
        setcookie('mf_lang', $lang, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true]);
    }
    log_action('lang_save', '界面语言切换为 ' . $lang, (int)$u['id']);
    act_ok('界面语言已保存：' . (MF_LANGS[$lang] ?? $lang));
}

/** 保存数据库连接信息（永远写文件侧 config；绝不改动 storage_engine 引擎键） */
function act_admin_save_storage(): void
{
    post_guard('csrf', 'db_driver', 'db_host');
    $me = admin_tab_guard();
    $driver = strtolower(post_str('db_driver', 10));
    if (!in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
        $driver = 'sqlite';
    }
    $pfx = strtolower(post_str('db_prefix', 32));
    if ($pfx !== '' && !preg_match('/^[a-z0-9_]{1,32}$/', $pfx)) {
        act_err('表前缀仅限字母、数字、下划线');
    }
    $path = post_str('db_sqlite_path', 200);
    if (strpos($path, '..') !== false || $path !== '' && !preg_match('#^[a-z0-9_\-./]{1,200}$#i', $path)) {
        act_err('SQLite 路径不合法（相对 data/ 填写，如 db/forum.sqlite）');
    }
    $port = preg_replace('/[^0-9]/', '', post_str('db_port', 5));
    $kv = [
        'db_driver'       => $driver,
        'db_sqlite_path'  => $path,
        'db_host'         => post_str('db_host', 120),
        'db_port'         => $port,
        'db_name'         => post_str('db_name', 64),
        'db_user'         => post_str('db_user', 64),
        'db_pass'         => (string)($_POST['db_pass'] ?? ''),
        'db_prefix'       => $pfx !== '' ? $pfx : 'mf_',
    ];
    /* 连接信息必须存文件侧（引擎引导发生在读库之前）；DB 模式下也同步进库一份供展示 */
    $lk = FileStore::lock('config');
    $c = FileStore::read('config.php', []);
    if (!is_array($c)) {
        $c = [];
    }
    /* v1.41.0 空读防御：config.php 存在却读出空——介质抖动 / 文件损坏，照常写回会把瞬时读失败固化成全站配置清空 */
    if ($c === [] && FileStore::exists('config.php')) {{
        FileStore::unlock($lk);
        act_err('config.php 读取异常（文件存在但内容为空 / 损坏），已放弃本次写入以防配置被清空——请检查 data/config.php，或从 data/backup/ 恢复备份');
    }}
    foreach ($kv as $k => $v) {
        $c[$k] = $v;
    }
    FileStore::write('config.php', $c);
        FileStore::write('backup/config-last-good.php', $c); /* v1.41.0 last-good 备份：启动自愈数据源 */
    FileStore::unlock($lk);
    foreach ($kv as $k => $v) {
        $GLOBALS['CFG'][$k] = $v;
    }
    log_action('admin_save_storage', '数据库连接信息已保存：' . $driver
        . ($driver === 'sqlite' ? ' / ' . ($path !== '' ? $path : 'db/forum.sqlite') : ' / ' . $kv['db_host'] . '/' . $kv['db_name']), (int)$me['id']);
    act_ok('数据库连接信息已保存（引擎切换请使用下方「迁移」）');
}

/** 测试数据库连接（AJAX，表单内当前值优先于已保存值） */
function act_admin_test_db(): void
{
    require_admin();
    if (!csrf_ok()) {
        json_response(['ok' => false, 'msg' => '页面已过期，请刷新后重试']);
    }
    $saved = db_conf();
    $conf = [
        'db_driver'      => strtolower(post_str('db_driver', 10)) ?: ($saved['db_driver'] ?: 'sqlite'),
        'db_sqlite_path' => post_str('db_sqlite_path', 200) !== '' ? post_str('db_sqlite_path', 200) : $saved['db_sqlite_path'],
        'db_host'        => post_str('db_host', 120) !== '' ? post_str('db_host', 120) : $saved['db_host'],
        'db_port'        => post_str('db_port', 5) !== '' ? post_str('db_port', 5) : $saved['db_port'],
        'db_name'        => post_str('db_name', 64) !== '' ? post_str('db_name', 64) : $saved['db_name'],
        'db_user'        => post_str('db_user', 64) !== '' ? post_str('db_user', 64) : $saved['db_user'],
        'db_pass'        => isset($_POST['db_pass']) && (string)$_POST['db_pass'] !== '' ? (string)$_POST['db_pass'] : $saved['db_pass'],
        'db_prefix'      => post_str('db_prefix', 32) !== '' ? post_str('db_prefix', 32) : ($saved['db_prefix'] ?: 'mf_'),
    ];
    if (!DbStore::connect($conf)) {
        json_response(['ok' => false, 'msg' => '连接失败：' . DbStore::lastConnError()]);
    }
    $st = DbStore::stats();
    $names = ['sqlite' => 'SQLite', 'mysql' => 'MySQL / MariaDB', 'pgsql' => 'PostgreSQL'];
    json_response(['ok' => true, 'msg' => '连接成功：' . ($names[$conf['db_driver']] ?? $conf['db_driver'])
        . ' ' . DbStore::serverInfo() . ' · 数据表 ' . DbStore::table() . ' 已就绪（' . number_format($st['rows']) . ' 行数据）']);
}

/** 存储引擎迁移（文件 ⇄ 数据库，自动备份 + 校验 + 切换引擎） */
function act_admin_storage_migrate(): void
{
    $me = admin_tab_guard();
    $dir = (string)($_POST['direction'] ?? '');
    $curFile = (string)cfg('storage_engine', 'file') !== 'db';
    if ($dir === 'to_db') {
        if (!$curFile) {
            act_err('当前已是数据库存储模式');
        }
        $migLk = Store::tryLock('storage_migrate');
        if (!$migLk) {
            act_err('已有一次迁移正在进行，请稍候');
        }
        $conf = db_conf();
        if (!DbStore::connect($conf)) {
            Store::unlock($migLk);
            act_err('数据库连接失败：' . DbStore::lastConnError() . '（请先到上方保存并测试连接信息）');
        }
        $log = DbStore::migrateFromFiles();
        Store::unlock($migLk);
        $msg = '迁移完成：' . $log['done'] . '/' . $log['total'] . ' 个数据文件已入库（备份：' . $log['backup'] . '）';
        if (!$log['ok']) {
            $detail = implode('；', array_slice($log['errors'], 0, 5));
            act_err('迁移未完全成功（引擎未切换）：' . $detail . ($log['done'] > 0 ? '。已迁移 ' . $log['done'] . ' 条可重试续迁。' : ''));
        }
        /* 切换引擎：引擎键只写文件侧 config（引导决策键） */
        $lk = FileStore::lock('config');
        $c = FileStore::read('config.php', []);
        if (!is_array($c)) {
            $c = [];
        }
    /* v1.41.0 空读防御：config.php 存在却读出空——介质抖动 / 文件损坏，照常写回会把瞬时读失败固化成全站配置清空 */
        if ($c === [] && FileStore::exists('config.php')) {{
            FileStore::unlock($lk);
            act_err('config.php 读取异常（文件存在但内容为空 / 损坏），已放弃本次写入以防配置被清空——请检查 data/config.php，或从 data/backup/ 恢复备份');
        }}
        $c['storage_engine'] = 'db';
        FileStore::write('config.php', $c);
                FileStore::write('backup/config-last-good.php', $c); /* v1.41.0 last-good 备份：启动自愈数据源 */
        FileStore::unlock($lk);
        $GLOBALS['CFG']['storage_engine'] = 'db';
        log_action('admin_storage_migrate', '存储引擎 文件 → 数据库（' . DbStore::kind() . '）：' . $log['done'] . ' 个文件，备份 ' . $log['backup'], (int)$me['id']);
        act_ok($msg . '，已切换为数据库存储');
    } elseif ($dir === 'to_file') {
        if ($curFile) {
            act_err('当前已是文件存储模式');
        }
        if (!DbStore::ok()) {
            act_err('数据库未连接（异常状态，请检查连接）');
        }
        $log = DbStore::migrateToFiles();
        $msg = '迁移完成：' . $log['done'] . '/' . $log['total'] . ' 行已写回文件（备份：' . $log['backup'] . '）';
        if (!$log['ok']) {
            act_err('迁移未完全成功（引擎未切换）：' . implode('；', array_slice($log['errors'], 0, 5)));
        }
        $lk = FileStore::lock('config');
        $c = FileStore::read('config.php', []);
        if (!is_array($c)) {
            $c = [];
        }
    /* v1.41.0 空读防御：config.php 存在却读出空——介质抖动 / 文件损坏，照常写回会把瞬时读失败固化成全站配置清空 */
        if ($c === [] && FileStore::exists('config.php')) {{
            FileStore::unlock($lk);
            act_err('config.php 读取异常（文件存在但内容为空 / 损坏），已放弃本次写入以防配置被清空——请检查 data/config.php，或从 data/backup/ 恢复备份');
        }}
        $c['storage_engine'] = 'file';
        FileStore::write('config.php', $c);
                FileStore::write('backup/config-last-good.php', $c); /* v1.41.0 last-good 备份：启动自愈数据源 */
        FileStore::unlock($lk);
        $GLOBALS['CFG']['storage_engine'] = 'file';
        log_action('admin_storage_migrate', '存储引擎 数据库 → 文件：' . $log['done'] . ' 行，备份 ' . $log['backup'], (int)$me['id']);
        act_ok($msg . '，已切换为文件存储');
    } else {
        act_err('未知的迁移方向');
    }
}

/** 数据库备份导出（zip：全部数据行原文 + 恢复说明，保留最近 5 份） */
function act_admin_storage_backup(): void
{
    $me = admin_tab_guard();
    if (!DbStore::ok()) {
        act_err('当前不是数据库存储模式或数据库未连接');
    }
    $keys = array_values(array_filter(DbStore::keysAll(), function ($k) {
        return is_string($k) && substr($k, -4) === '.php';
    }));
    if (!$keys) {
        act_err('数据库中没有数据行');
    }
    $files = [];
    foreach ($keys as $k) {
        $v = DbStore::rawJson($k);
        if ($v !== null) {
            $files[] = [$k, $v];
        }
    }
    $name = 'db-' . date('Ymd-His') . '.zip';
    $files[] = ['__manifest.txt', "Cube Minimalist Forum 数据库备份\r\n时间：" . date('Y-m-d H:i:s')
        . "\r\n数据行：" . count($files) . "\r\n来源：" . strtoupper(DbStore::kind()) . ' / 表 ' . DbStore::table()
        . "\r\n\r\n恢复方式：后台「存储」切回文件模式时可直接使用，或解包后按「文件名 => JSON 原文」写回 data/ 目录。"];
    if (!MiniZip::create(FileStore::path('backup/' . $name), $files)) {
        act_err('备份包创建失败（data/backup/ 不可写？）');
    }
    /* 保留最近 5 份 db-*.zip */
    $zips = array_values(array_filter(Store::scan('backup'), function ($f) {
        return strpos($f, 'db-') === 0 && substr($f, -4) === '.zip';
    }));
    sort($zips);
    while (count($zips) > 5) {
        @unlink(FileStore::path('backup/' . array_shift($zips)));
    }
    log_action('admin_storage_backup', '数据库备份：' . $name . '（' . count($keys) . ' 行）', (int)$me['id']);
    act_ok('数据库备份完成：' . $name, u('p=admin&tab=storage'));
}

/** 数据库维护优化（VACUUM / OPTIMIZE TABLE / VACUUM ANALYZE） */
function act_admin_storage_optimize(): void
{
    $me = admin_tab_guard();
    if (!DbStore::ok()) {
        act_err('当前不是数据库存储模式或数据库未连接');
    }
    $msg = DbStore::optimize();
    log_action('admin_storage_optimize', $msg, (int)$me['id']);
    act_ok($msg, u('p=admin&tab=storage'));
}

/** 数据库完整性检查 */
function act_admin_storage_check(): void
{
    require_admin();
    if (!DbStore::ok()) {
        act_err('当前不是数据库存储模式或数据库未连接');
    }
    act_ok(DbStore::integrityCheck(), u('p=admin&tab=storage'));
}

/** 一键启动常驻巡逻器（尝试在本机后台拉起 daemon.php） */
function act_admin_daemon_start(): void
{
    $me = admin_tab_guard();
    $bin = trim((string)($_POST['php_bin'] ?? ''));
    if ($bin !== '') {
        /* 仅允许常规路径字符，杜绝命令注入；同时持久化到文件侧配置供下次使用 */
        if (!preg_match('#^[A-Za-z0-9_\-./:\\\\ ]{1,200}$#', $bin)) {
            act_err('PHP 路径含非常规字符，请检查');
        }
        $lk = FileStore::lock('config');
        $c = FileStore::read('config.php', []);
        if (!is_array($c)) {
            $c = [];
        }
    /* v1.41.0 空读防御：config.php 存在却读出空——介质抖动 / 文件损坏，照常写回会把瞬时读失败固化成全站配置清空 */
        if ($c === [] && FileStore::exists('config.php')) {{
            FileStore::unlock($lk);
            act_err('config.php 读取异常（文件存在但内容为空 / 损坏），已放弃本次写入以防配置被清空——请检查 data/config.php，或从 data/backup/ 恢复备份');
        }}
        $c['daemon_php_bin'] = $bin;
        FileStore::write('config.php', $c);
                FileStore::write('backup/config-last-good.php', $c); /* v1.41.0 last-good 备份：启动自愈数据源 */
        FileStore::unlock($lk);
        $GLOBALS['CFG']['daemon_php_bin'] = $bin;
    }
    if ($bin === '') {
        $bin = (string)cfg('daemon_php_bin', '');
    }
    if ($bin === '') {
        $bin = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
    }
    if (ai_daemon_alive()) {
        act_ok('巡逻器已在线，无需重复启动', u('p=admin&tab=security'));
    }
    if (!function_exists('proc_open') && !function_exists('shell_exec')) {
        act_err('主机未开放 proc_open / shell_exec，无法从后台启动。请在 SSH 手动执行：nohup ' . $bin . ' daemon.php >/dev/null 2>&1 &');
    }
    $root = dirname(DATA_DIR);
    $isWin = DIRECTORY_SEPARATOR === '\\';
    $cmd = $isWin
        ? 'start /B "" ' . escapeshellarg($bin) . ' daemon.php'
        : '(cd ' . escapeshellarg($root) . ' && nohup ' . escapeshellarg($bin) . ' daemon.php > /dev/null 2>&1 &)';
    $started = false;
    if (function_exists('proc_open')) {
        $spec = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'e']];
        $pipes = [];
        $proc = @proc_open($cmd, $spec, $pipes, $isWin ? $root : null);
        if (is_resource($proc)) {
            foreach ($pipes as $pp) {
                @fclose($pp);
            }
            @proc_close($proc); // 命令以 & 结尾，立即返回
            $started = true;
        }
    }
    if (!$started && function_exists('shell_exec')) {
        @shell_exec($cmd);
        $started = true;
    }
    log_action('admin_daemon_start', '尝试启动常驻巡逻器（PHP：' . $bin . '）', (int)$me['id']);
    if ($started) {
        act_ok('启动命令已发出。巡逻器每 60 秒写一次心跳，约 1 分钟后本页会显示「在线」；若未上线请检查 PHP 路径或查看 data/logs/error.log', u('p=admin&tab=security'));
    }
    act_err('启动失败：主机可能禁止创建常驻进程。请在 SSH 手动执行：nohup ' . $bin . ' daemon.php >/dev/null 2>&1 &');
}

/** 停止常驻巡逻器（写入信号文件，daemon 30 秒内优雅退出并自行删除信号） */
function act_admin_daemon_stop(): void
{
    $me = admin_tab_guard();
    if (!ai_daemon_alive()) {
        @unlink(DATA_DIR . '/daemon.stop'); // 清理可能残留的旧信号
        act_ok('巡逻器当前不在线', u('p=admin&tab=security'));
    }
    @file_put_contents(DATA_DIR . '/daemon.stop', (string)time());
    log_action('admin_daemon_stop', '发送巡逻器停止信号', (int)$me['id']);
    act_ok('已发送停止信号，巡逻器将在 30 秒内优雅退出', u('p=admin&tab=security'));
}

/* ================= v1.22.0 拓展（插件）管理 + 后台设置搜索 ================= */

/** 后台设置搜索索引（GET JSON，仅管理员；索引与 admin_tab_ext 同步维护） */
function act_admin_search(): void
{
    if (!current_user() || empty(current_user()['admin'])) {
        json_response(['ok' => false, 'msg' => 'Forbidden']);
        return;
    }
    header('Cache-Control: no-store');
    json_response(['ok' => true, 'items' => admin_search_index()]);
}

/** v1.30.0 前台搜索预览：输入联想（最多 8 条：标题命中优先，正文命中兜底；只读、不写日志） */
function act_search_suggest(): void
{
    header('Cache-Control: no-store');
    if (!feat_on('search') || !feat_on('search_suggest')) {
        json_response(['ok' => false, 'msg' => 'search disabled']);
        return;
    }
    $q = trim((string)($_GET['q'] ?? ''));
    $q = cut_str((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $q), 50);
    $items = [];
    foreach (search_suggest_items($q) as $t) {
        $items[] = [
            'id'    => (int)($t['id'] ?? 0),
            'title' => (string)($t['title'] ?? ''),
            'board' => board_name((int)($t['board'] ?? 0)),
        ];
    }
    json_response(['ok' => true, 'items' => $items]);
}

/** 启用 / 停用插件 */
function act_admin_plugin_toggle(): void
{
    $me = admin_tab_guard();
    $id = (string)($_POST['pf'] ?? '');
    if (!plugin_id_ok($id)) {
        act_err('插件 id 不合法');
        return;
    }
    $all = plugins_discover();
    if (!isset($all[$id]) || !$all[$id]['ok']) {
        act_err('插件不存在或清单不完整：' . $id);
        return;
    }
    $en = plugins_enabled_ids();
    $on = !in_array($id, $en, true);
    $new = $on ? array_merge($en, [$id]) : array_values(array_diff($en, [$id]));
    cfg_update(['plugins_enabled' => $new]);
    log_action('plugin.toggle', ($on ? '启用' : '停用') . '插件「' . $all[$id]['name'] . '」（' . $id . '）', (int)$me['id'], (string)$me['name']);
    act_ok('已' . ($on ? '启用' : '停用') . '插件「' . $all[$id]['name'] . '」' . ($on ? '，立即生效' : ''), u('p=admin&tab=ext'));
}

/** 删除插件（连同目录；已启用的先停用） */
function act_admin_plugin_remove(): void
{
    $me = admin_tab_guard();
    $id = (string)($_POST['pf'] ?? '');
    if (!plugin_id_ok($id)) {
        act_err('插件 id 不合法');
        return;
    }
    $en = array_values(array_diff(plugins_enabled_ids(), [$id]));
    cfg_update(['plugins_enabled' => $en]);
    if (!plugin_remove($id)) {
        act_err('删除失败：目录不存在或不可写（可尝试通过 FTP 删除 data/plugins/' . $id . '）');
        return;
    }
    log_action('plugin.remove', '删除插件「' . $id . '」及其目录', (int)$me['id'], (string)$me['name']);
    act_ok('插件「' . $id . '」已删除', u('p=admin&tab=ext'));
}

/** 安装插件（上传 zip；MiniZipRead 零依赖解析，zip-slip 全量校验） */
function act_admin_plugin_install(): void
{
    $me = admin_tab_guard();
    if (empty($_FILES['pfzip']) || !is_array($_FILES['pfzip'])) {
        act_err('请选择插件 zip 包');
        return;
    }
    $f = $_FILES['pfzip'];
    if ((int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        act_err('上传失败（错误码 ' . (int)($f['error'] ?? -1) . '，可能超过服务器上传限制）');
        return;
    }
    if ((int)($f['size'] ?? 0) <= 0 || (int)$f['size'] > 8 * 1024 * 1024) {
        act_err('插件包大小需在 8MB 以内');
        return;
    }
    $ext = strtolower((string)pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext !== 'zip') {
        act_err('仅支持 .zip 插件包');
        return;
    }
    $tmp = (string)($f['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp)) {
        act_err('读取上传文件失败，请重试');
        return;
    }
    [$ok, $msg] = plugin_install_zip($tmp);
    if (!$ok) {
        act_err('安装失败：' . $msg);
        return;
    }
    if (!is_dir(plugins_dir())) {
        @mkdir(plugins_dir(), 0755, true);
    }
    log_action('plugin.install', '安装插件「' . $msg . '」（zip 包 ' . (int)$f['size'] . ' 字节）', (int)$me['id'], (string)$me['name']);
    act_ok('插件「' . $msg . '」安装成功，请在列表中启用', u('p=admin&tab=ext'));
}
