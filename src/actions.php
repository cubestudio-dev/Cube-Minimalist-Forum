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
        case 'logout':           act_logout(); return;
        case 'forgot':           act_forgot(); return;
        case 'change_pass':      act_change_pass(); return;
        case 'profile_save':     act_profile_save(); return;
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
        case 'admin_api_revoke':       admin_tab_guard(); act_admin_api_revoke(); return;
        case 'admin_save_docs':    admin_tab_guard(); act_admin_save_docs(); return;
        case 'admin_data_compress': admin_tab_guard(); act_admin_data_compress(); return;
        case 'admin_save_basic': admin_tab_guard(); act_admin_save_basic(); return;
        case 'admin_save_feat':  admin_tab_guard(); act_admin_save_feat(); return;
        case 'admin_icon_upload': admin_tab_guard(); act_admin_icon_upload(); return;
        case 'admin_icon_del':    admin_tab_guard(); act_admin_icon_del(); return;
        case 'admin_save_mail':  admin_tab_guard(); act_admin_save_mail(); return;
        case 'admin_test_mail':  admin_tab_guard(); act_admin_test_mail(); return;
        case 'admin_save_ai':    admin_tab_guard(); act_admin_save_ai(); return;
        case 'admin_test_ai':    admin_tab_guard(); act_admin_test_ai(); return;
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
        case 'admin_save_monitor': admin_tab_guard(); act_admin_save_monitor(); return;
        case 'admin_test_monitor': admin_tab_guard(); act_admin_test_monitor(); return;
        case 'admin_repair_dirs': admin_tab_guard(); act_admin_repair_dirs(); return;
        case 'admin_backup':    admin_tab_guard(); act_admin_backup(); return;
        case 'admin_backup_del': admin_tab_guard(); act_admin_backup_del(); return;
        case 'admin_update':        admin_tab_guard(); act_admin_update(); return;
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
    if (!in_array($purpose, ['register', 'reset', 'delete'], true)) {
        $purpose = 'reset';
    }
    if ($purpose === 'delete') {
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
    $lbl = ['register' => '注册', 'reset' => '找回密码', 'delete' => '注销账号'][$purpose] ?? '验证';
    log_action('code_send', $lbl . '验证码 → ' . $email);
    json_response(['ok' => true, 'msg' => '验证码已发送，请查收邮箱（注意垃圾箱）']);
}

function act_register(): void
{
    feat_guard('register', '本站已关闭新用户注册');
    $name = post_str('name', 20);
    $email = strtolower(post_str('email', 60));
    $pass = (string)($_POST['pass'] ?? '');
    $pass2 = (string)($_POST['pass2'] ?? '');
    $code = post_str('code', 6);
    set_old(['name' => $name, 'email' => $email]);

    if (!valid_name($name)) {
        flash('err', '用户名需 2-20 位，仅限中文、字母、数字、下划线');
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
    $uid = user_create($name, $email, password_hash($pass, PASSWORD_DEFAULT), false);
    $user = user_by_id($uid);
    if ($user) {
        auth_login($user, false);
    }
    log_action('register', '用户 ' . $name . '（#' . $uid . '）注册成功', $uid, $name);
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
    auth_login($u, $remember);
    log_action('login', '用户 ' . (string)$u['name'] . '（#' . (int)$u['id'] . '）登录成功' . ($remember ? '，保持登录 30 天' : ''), (int)$u['id'], (string)$u['name']);
    clear_old();
    flash('ok', '欢迎回来，' . (string)$u['name']);
    $next = (string)($_POST['next'] ?? '');
    if ($next !== '' && preg_match('/^[a-z_=&\d]+$/', $next)) {
        redirect(u($next));
    }
    redirect(u('p=home'));
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
    $name = post_str('name', 20);
    $bio = post_str('bio', 200);
    // 用户签名（v1.14.0）：单行纯文本，展示在帖子与回复下方；开关关闭时保留旧值不修改
    $sig = feat_on('signature') ? trim(str_replace(["\r", "\n", "\0"], ' ', post_str('sig', 60))) : (string)($u['sig'] ?? '');
    if (!valid_name($name)) {
        flash('err', '用户名需 2-20 位，仅限中文、字母、数字、下划线');
        redirect(u('p=settings'));
    }
    $exist = user_by_name($name);
    if ($exist && (int)$exist['id'] !== (int)$u['id']) {
        flash('err', '新用户名已被占用');
        redirect(u('p=settings'));
    }
    user_update((int)$u['id'], ['name' => $name, 'bio' => $bio, 'sig' => $sig]);
    log_action('profile_save', '资料更新：用户名 ' . (string)$u['name'] . ' → ' . $name);
    flash('ok', '资料已更新');
    redirect(u('p=settings'));
}

/* ================= 帖子与回复 ================= */

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
    $title = post_str('title', 30);
    $content = post_str('content', 1500);
    if ($title === '') {
        flash('err', '标题不能为空');
        redirect(u('p=new'));
    }
    if ($content === '') {
        flash('err', '正文不能为空');
        redirect(u('p=new'));
    }
    if (!flood_check($u, ['key' => 'thread', 'title' => $title, 'content' => $content])) {
        redirect(u('p=new'));
    }
    $tid = thread_create($board, $title, $content, (int)$u['id']);
    user_bump((int)$u['id'], 'threads', 1);
    log_action('thread_new', '发布《' . cut_str($title, 40) . '》（板块：' . board_name($board) . '）');
    /* @ 提及通知：内容中 @到的人，系统自动给对方发送站内消息（v1.15.0） */
    mentions_notify($title . "\n" . $content, (int)$u['id'], $tid, 0, $title);
    /* 严全面·消息审核：v1.16.0 改为响应后异步预检（register_shutdown_function），
       发帖立即返回不再被 AI 调用阻塞（此前同步调用会让整个服务等待数秒）；
       判定违规后仍会自动隐藏 + 通知申诉，机制不变 */
    register_shutdown_function('ai_precheck_after_post', 'thread', $tid, 0, $title . "\n" . $content, (int)$u['id'], $title);
    flash('ok', '发布成功，AI 正在后台审核内容');
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
    $content = post_str('content', 1000);
    if ($content === '') {
        flash('err', '回复内容不能为空');
        redirect(u('p=thread&id=' . $tid));
    }
    // 先验锁帖 / 存在性，再占用发言间隔（避免被拒后白扣间隔）
    $t = thread_get($tid);
    if (!$t || !empty($t['locked'])) {
        flash('err', '该帖子已锁定或不存在，无法回复');
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
    log_action('reply_new', '在《' . cut_str((string)($t['title'] ?? ''), 40) . '》中发表回复');
    /* @ 提及通知：内容中 @到的人，系统自动给对方发送站内消息（v1.15.0） */
    mentions_notify($content, (int)$u['id'], $tid, (int)$rid);
    /* 严全面·消息审核：v1.16.0 改为响应后异步预检，不再阻塞响应（同上） */
    register_shutdown_function('ai_precheck_after_post', 'reply', $tid, $rid, $content, (int)$u['id']);
    flash('ok', '回复成功，AI 正在后台审核内容');
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
    $title = post_str('title', 30);
    $content = post_str('content', 1500);
    if ($title === '' || $content === '') {
        act_err('标题与正文不能为空');
    }
    thread_save($tid, ['title' => $title, 'content' => $content, 'edited' => time(), 'edited_by' => (int)$u['id']]);
    log_action('thread_edit', (($u['admin'] ?? false) && (int)$t['author'] !== (int)$u['id'] ? '管理员编辑' : '作者编辑') . '《' . cut_str($title, 40) . '》', (int)$u['id'], (string)$u['name']);
    /* @ 提及：编辑时只通知新增提及（旧内容已提及过的人不重复通知） */
    mentions_notify($title . "\n" . $content, (int)$u['id'], $tid, 0, $title, mentions_extract((string)($t['content'] ?? '')));
    act_ok('帖子已更新', u('p=thread&id=' . $tid));
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
    $content = post_str('content', 1000);
    if ($content === '') {
        act_err('回复内容不能为空');
    }
    reply_save($tid, $rid, ['content' => $content, 'edited' => time(), 'edited_by' => (int)$u['id']]);
    log_action('reply_edit', (($u['admin'] ?? false) && (int)$r['author'] !== (int)$u['id'] ? '管理员编辑' : '作者编辑') . '《' . cut_str((string)($t['title'] ?? ''), 40) . '》中的回复', (int)$u['id'], (string)$u['name']);
    /* @ 提及：编辑时只通知新增提及 */
    mentions_notify($content, (int)$u['id'], $tid, (int)$rid, '', mentions_extract((string)($r['content'] ?? '')));
    act_ok('回复已更新', u('p=thread&id=' . $tid) . '#r' . $rid);
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
}

/** 后台「功能」页：总开关保存（v1.14.0） */
function act_admin_save_feat(): void
{
    $keys = ['register', 'guest_browse', 'post', 'reply', 'edit', 'like', 'report', 'search', 'emoji', 'online', 'signature', 'mention'];
    $kv = [];
    $off = [];
    foreach ($keys as $k) {
        $v = isset($_POST['feat_' . $k]) ? 1 : 0;
        $kv['feat_' . $k] = $v;
        if ($v === 0) {
            $off[] = $k;
        }
    }
    /* AI 两项直接写「AI」页的原生配置键（ai_precheck / ai_autopilot），与功能页同一份开关 */
    foreach (['ai_precheck', 'ai_autopilot'] as $k) {
        $v = isset($_POST['feat_' . $k]) ? 1 : 0;
        $kv[$k] = $v;
        if ($v === 0) {
            $off[] = $k;
        }
    }
    cfg_update($kv);
    log_action('admin_save_feat', '功能开关更新：关闭 ' . ($off ? implode('、', $off) : '无（全部开启）'));
    act_ok('功能开关已保存', u('p=admin&tab=feat'));
}

/** 网站图标上传（v1.14.0）：严格类型校验 + 内容嗅探；SVG 另做脚本注入拒绝，输出时叠加 CSP sandbox */
function act_admin_icon_upload(): void
{
    if (empty($_FILES['icon']) || !is_array($_FILES['icon'])) {
        act_err('请选择图标文件');
    }
    $f = $_FILES['icon'];
    if ((int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        act_err('图标上传失败（错误码 ' . (int)($f['error'] ?? -1) . '，可能超过服务器上传限制）');
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
    cfg_update([
        'smtp_host' => cut_str(post_str('smtp_host', 100), 100),
        'smtp_port' => max(1, min(65535, (int)($_POST['smtp_port'] ?? 465))),
        'smtp_from' => cut_str(post_str('smtp_from', 60), 60),
        'smtp_pass' => cut_str(post_str('smtp_pass', 100), 100),
    ]);
    log_action('admin_save_mail', 'SMTP：' . (string)cfg('smtp_host') . ':' . (int)cfg('smtp_port'));
    act_ok('邮件设置已保存', u('p=admin&tab=mail'));
}

function act_admin_test_mail(): void
{
    $ov = [
        'host' => post_str('smtp_host', 100),
        'port' => max(1, min(65535, (int)($_POST['smtp_port'] ?? 465))),
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
    $me = require_admin();
    $uid = (int)($_POST['uid'] ?? 0);
    $to = ($_POST['to'] ?? '0') === '1';
    if ($uid === (int)$me['id']) {
        act_err('不能修改自己的角色');
    }
    if (!user_by_id($uid)) {
        act_err('用户不存在');
    }
    user_update($uid, ['admin' => $to ? 1 : 0]);
    log_action('admin_user_role', '用户「' . uname($uid) . '」' . ($to ? '设为管理员' : '取消管理员'));
    act_ok('角色已更新', u('p=admin&tab=users'));
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
    if (!preg_match('/^(backup|pre-update)-\d{8}-\d{6}\.zip$/', $name)) {
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
    if (!preg_match('/^(backup|pre-update)-\d{8}-\d{6}\.zip$/', $name)) {
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

/** 保存监控设置：自动告警开关 + 阈值 */
function act_admin_save_monitor(): void
{
    $on = !empty($_POST['monitor_on']) ? 1 : 0;
    $mb = max(1, min(999, (int)($_POST['monitor_mb'] ?? 95)));
    $mi = (int)($_POST['monitor_interval'] ?? 5);
    $mi = $mi === 1 ? 2 : max(0, min(300, $mi)); // 0=关闭，1 归一为 2（太频繁伤主机）
    cfg_update(['monitor_on' => $on, 'monitor_mb' => $mb, 'monitor_interval' => $mi]);
    log_action('admin_save_monitor', '存储告警：' . ($on ? '开启' : '关闭') . '，阈值 ' . $mb . 'MB，监控刷新间隔 ' . ($mi > 0 ? $mi . ' 秒' : '关闭'));
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
