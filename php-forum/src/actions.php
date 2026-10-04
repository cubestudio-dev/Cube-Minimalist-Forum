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
        case 'thread_new':       act_thread_new(); return;
        case 'reply_new':        act_reply_new(); return;
        case 'thread_delete':    act_thread_delete(); return;
        case 'reply_delete':     act_reply_delete(); return;
        case 'like':             act_like(); return;
        case 'report':           act_report(); return;
        case 'appeal':           act_appeal(); return;
        case 'notify_read':      act_notify_read(); return;
        case 'notify_read_all':  act_notify_read_all(); return;
        case 'admin_save_basic': admin_tab_guard(); act_admin_save_basic(); return;
        case 'admin_save_mail':  admin_tab_guard(); act_admin_save_mail(); return;
        case 'admin_test_mail':  admin_tab_guard(); act_admin_test_mail(); return;
        case 'admin_save_ai':    admin_tab_guard(); act_admin_save_ai(); return;
        case 'admin_test_ai':    admin_tab_guard(); act_admin_test_ai(); return;
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

/* ================= 通用 ================= */

/** 发帖/回复间隔检查与占用（在 users 锁内完成检查+写入，防并发双发） */
function flood_check(array $u): bool
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
        flash('err', '发言太频繁，请 ' . $wait . ' 秒后再试');
        return false;
    }
    return true;
}

/** 管理员删除内容时给作者的系统通知（含编号、举报情况、可申诉提示） */
function delete_notify_body(string $kind, int $tid, int $rid): string
{
    $no = $kind === 'thread' ? '帖子 #' . $tid : '帖子 #' . $tid . ' 中您的回复 #' . $rid;
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

function act_send_code(): void
{
    $email = strtolower(post_str('email', 60));
    $purpose = post_str('purpose', 10) === 'register' ? 'register' : 'reset';
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
    log_action('code_send', ($purpose === 'register' ? '注册' : '找回密码') . '验证码 → ' . $email);
    json_response(['ok' => true, 'msg' => '验证码已发送，请查收邮箱（注意垃圾箱）']);
}

function act_register(): void
{
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

    $u = $id !== '' ? (user_by_name($id) ?? user_by_email($id)) : null;
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
    log_action('pass_reset', '邮箱 ' . $email . ' 的密码已重置（用户 #' . (int)$u['id'] . '）', (int)$u['id'], (string)$u['name']);
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
    if (!valid_name($name)) {
        flash('err', '用户名需 2-20 位，仅限中文、字母、数字、下划线');
        redirect(u('p=settings'));
    }
    $exist = user_by_name($name);
    if ($exist && (int)$exist['id'] !== (int)$u['id']) {
        flash('err', '新用户名已被占用');
        redirect(u('p=settings'));
    }
    user_update((int)$u['id'], ['name' => $name, 'bio' => $bio]);
    log_action('profile_save', '资料更新：用户名 ' . (string)$u['name'] . ' → ' . $name);
    flash('ok', '资料已更新');
    redirect(u('p=settings'));
}

/* ================= 帖子与回复 ================= */

function act_thread_new(): void
{
    $u = require_login('p=new');
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
    $content = post_str('content', 1000);
    if ($title === '') {
        flash('err', '标题不能为空');
        redirect(u('p=new'));
    }
    if ($content === '') {
        flash('err', '正文不能为空');
        redirect(u('p=new'));
    }
    if (!flood_check($u)) {
        redirect(u('p=new'));
    }
    $tid = thread_create($board, $title, $content, (int)$u['id']);
    user_bump((int)$u['id'], 'threads', 1);
    log_action('thread_new', '发布《' . $title . '》（帖子 #' . $tid . '，板块：' . board_name($board) . '）');
    flash('ok', '发布成功');
    redirect(u('p=thread&id=' . $tid));
}

function act_reply_new(): void
{
    $tid = (int)($_POST['tid'] ?? 0);
    $u = require_login('p=thread&id=' . $tid);
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
    if (!flood_check($u)) {
        redirect(u('p=thread&id=' . $tid));
    }
    $rid = reply_add($tid, $content, (int)$u['id']);
    if ($rid === null) {
        flash('err', '该帖子已锁定或不存在，无法回复');
        redirect(u('p=home'));
    }
    log_action('reply_new', '在《' . (string)($t['title'] ?? '') . '》（帖子 #' . $tid . '）中回复 #' . $rid);
    flash('ok', '回复成功');
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
        log_action('thread_delete', ($admin && $author !== (int)$u['id'] ? '管理员删除' : '作者删除') . '《' . (string)$t['title'] . '》（帖子 #' . $tid . '）');
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
    log_action('reply_delete', (!empty($u['admin']) && (int)$r['author'] !== (int)$u['id'] ? '管理员删除' : '作者删除') . '帖子 #' . $tid . ' 中的回复 #' . $rid);
    flash('ok', '回复已删除');
    redirect(u('p=thread&id=' . $tid));
}

function act_like(): void
{
    $u = require_login();
    // type/tid/rid 位于动作链接的查询串中，同时兼容表单隐藏域提交
    $type = (($_POST['type'] ?? $_GET['type'] ?? '') === 'r') ? 'r' : 't';
    $tid = (int)($_POST['tid'] ?? $_GET['tid'] ?? 0);
    $rid = (int)($_POST['rid'] ?? $_GET['rid'] ?? 0);
    if (!thread_get($tid) || ($type === 'r' && !reply_get($tid, $rid))) {
        act_err('内容不存在');
    }
    $likeRes = like_toggle($type, $tid, $rid, (int)$u['id']);
    log_action(!empty($likeRes[0]) ? 'like' : 'unlike', ($type === 'r' ? '回复 #' . $tid . '/' . $rid : '帖子 #' . $tid) . (!empty($likeRes[0]) ? '（赞）' : '（取消赞）'));
    back_or(u('p=thread&id=' . $tid));
}

function act_report(): void
{
    $u = require_login();
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
    log_action('report', ($type === 'reply' ? '回复 #' . $tid . '/' . $rid : '帖子 #' . $tid) . '，理由：' . $reason);
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
    log_action('appeal', '对举报 #' . $rid . ' 提出申诉（' . ($rep['type'] === 'thread' ? '帖子 #' . (int)$rep['tid'] : '帖子 #' . (int)$rep['tid'] . ' 回复 #' . (int)$rep['rid']) . '）');
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
    ]);
    log_action('admin_save_basic', '基本设置已保存' . ($bd !== '' ? '（绑定域名：' . $bd . '）' : ''));
    act_ok('基本设置已保存', u('p=admin&tab=basic'));
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
    [$ok, $err] = mail_send($to, (string)cfg('site_name', '论坛') . ' · SMTP 测试邮件', "这是一封测试邮件。\r\n如果您收到了它，说明邮件配置正确。\r\n\r\n—— " . (string)cfg('site_name', '论坛'), $ov);
    if ($ok) {
        log_action('admin_test_mail', '测试邮件已发送至 ' . $to);
    }
    json_response($ok ? ['ok' => true, 'msg' => '测试邮件已发送，请查收'] : ['ok' => false, 'msg' => $err]);
}

function act_admin_save_ai(): void
{
    cfg_update([
        'ai_url' => cut_str(post_str('ai_url', 200), 200),
        'ai_key' => cut_str(post_str('ai_key', 200), 200),
        'ai_model' => cut_str(post_str('ai_model', 100), 100),
        'ai_retries' => max(1, min(10, (int)($_POST['ai_retries'] ?? 3))),
    ]);
    log_action('admin_save_ai', 'AI 模型：' . (string)cfg('ai_model'));
    act_ok('AI 设置已保存', u('p=admin&tab=ai'));
}

function act_admin_test_ai(): void
{
    $ov = [
        'url' => post_str('ai_url', 200),
        'key' => post_str('ai_key', 200),
        'model' => post_str('ai_model', 100),
        'retries' => max(1, min(10, (int)($_POST['ai_retries'] ?? 3))),
    ];
    $msg = '';
    $ok = ai_test($ov, $msg);
    if ($ok) {
        log_action('admin_test_ai', 'AI 测试通过：' . $ov['model']);
    }
    json_response(['ok' => $ok, 'msg' => $msg]);
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
    log_action('admin_board_move', '板块 #' . $id . ' 排序' . (($_POST['dir'] ?? '') === 'up' ? '上移' : '下移'));
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
    log_action('admin_user_role', '用户 #' . $uid . ' ' . ($to ? '设为管理员' : '取消管理员'));
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
            log_action('admin_thread_op', '锁定帖子 #' . $tid . '《' . (string)$t['title'] . '》');
            act_ok('帖子已锁定', u('p=thread&id=' . $tid));
            break;
        case 'unlock':
            thread_save($tid, ['locked' => false]);
            log_action('admin_thread_op', '解锁帖子 #' . $tid . '《' . (string)$t['title'] . '》');
            act_ok('帖子已解锁', u('p=thread&id=' . $tid));
            break;
        case 'pin':
            thread_save($tid, ['pinned' => true]);
            log_action('admin_thread_op', '置顶帖子 #' . $tid . '《' . (string)$t['title'] . '》');
            act_ok('帖子已置顶', u('p=thread&id=' . $tid));
            break;
        case 'unpin':
            thread_save($tid, ['pinned' => false]);
            log_action('admin_thread_op', '取消置顶帖子 #' . $tid);
            act_ok('已取消置顶', u('p=thread&id=' . $tid));
            break;
        case 'move':
            $b = (int)($_POST['board'] ?? 0);
            if (!board_get($b)) {
                act_err('目标板块不存在');
            }
            thread_save($tid, ['board' => $b]);
            log_action('admin_thread_op', '移动帖子 #' . $tid . ' 到「' . board_name($b) . '」');
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
            log_action('admin_thread_op', '删除帖子 #' . $tid . '《' . (string)$t['title'] . '》并通知作者');
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
    log_action('admin_reply_delete', '删除帖子 #' . $tid . ' 中的回复 #' . $rid . '（作者 ' . uname($author) . '）并通知作者');
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
    redirect(u('p=admin&tab=queue'));
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

function act_admin_save_theme(): void
{
    $color = post_str('theme_color', 9);
    $custom = post_str('theme_color_custom', 9);
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $custom)) {
        $color = $custom;
    }
    if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) {
        $color = '#0f766e';
    }
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
        redirect(u('p=admin&tab=system'));
    }
    $p = Store::path('backup/' . $name);
    if (!is_file($p)) {
        flash('err', '备份文件不存在');
        redirect(u('p=admin&tab=system'));
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
        redirect(u('p=admin&tab=system'));
    }
    $p = Store::path('backup/' . $name);
    if (!is_file($p)) {
        flash('err', '备份文件不存在');
        redirect(u('p=admin&tab=system'));
    }
    if (!@unlink($p)) {
        flash('err', '删除失败：文件被占用或目录不可写，请检查 data/backup/ 权限');
        redirect(u('p=admin&tab=system'));
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
    redirect(u('p=admin&tab=monitor'));
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
        redirect(u('p=admin&tab=update'));
    }
    @unlink($tmp);
    flash('ok', '更新成功：v' . $res['from'] . ' → v' . $res['version'] . '，共应用 ' . $res['files'] . ' 个文件'
        . ($res['backup'] !== '' ? '；更新前程序已备份为 ' . $res['backup'] : ''));
    redirect(u('p=admin&tab=update'));
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
    redirect(u('p=admin&tab=logs'));
}
