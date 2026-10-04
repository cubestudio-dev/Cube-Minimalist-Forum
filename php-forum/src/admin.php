<?php
/**
 * 极简论坛 · 后台管理（仅管理员）
 * 标签页：基本 / 邮件 / AI / 板块 / 用户 / 内容 / AI 队列 / 人工待审 / 举报记录 / 公告 / 主题 / 日志 / 安全防护 / 监控 / 更新升级 / 系统
 */
defined('APP') or exit('Forbidden');

function page_admin(): void
{
    $me = require_admin();
    $tab = (string)($_GET['tab'] ?? 'basic');
    if (!preg_match('/^[a-z]{1,12}$/', $tab)) {
        $tab = 'basic';
    }
    layout_header('后台管理', 0);
    echo page_head('后台管理', '所有修改即时生效');

    $tabs = [
        'basic' => '基本', 'mail' => '邮件', 'ai' => 'AI', 'boards' => '板块', 'users' => '用户',
        'content' => '内容', 'queue' => 'AI 队列', 'manual' => '人工待审', 'reports' => '举报记录',
        'anns' => '公告', 'theme' => '主题', 'logs' => '日志', 'security' => '安全防护', 'monitor' => '监控', 'update' => '更新升级', 'system' => '系统',
    ];
    echo '<div class="admin-tabs">';
    foreach ($tabs as $k => $v) {
        echo '<a class="atab' . ($tab === $k ? ' on' : '') . '" href="' . e(u('p=admin&tab=' . $k)) . '">' . e($v) . '</a>';
    }
    echo '</div>';
    echo '<div class="admin-body">';

    switch ($tab) {
        case 'mail': admin_tab_mail(); break;
        case 'ai': admin_tab_ai(); break;
        case 'boards': admin_tab_boards(); break;
        case 'users': admin_tab_users(); break;
        case 'content': admin_tab_content(); break;
        case 'queue': admin_tab_queue(); break;
        case 'manual': admin_tab_manual(); break;
        case 'reports': admin_tab_reports(); break;
        case 'anns': admin_tab_anns(); break;
        case 'theme': admin_tab_theme(); break;
        case 'logs': admin_tab_logs(); break;
        case 'security': admin_tab_security(); break;
        case 'monitor': admin_tab_monitor(); break;
        case 'update': admin_tab_update(); break;
        case 'system': admin_tab_system($me); break;
        default: admin_tab_basic();
    }

    echo '</div>';
    layout_footer();
}

/* ---------------- 基本 ---------------- */
function admin_tab_basic(): void
{
    echo '<div class="card form-card"><form method="post" action="' . e(u('a=admin_save_basic')) . '">' . csrf_field() .
        '<label class="field"><span class="field-l">论坛名称</span><input class="input" name="site_name" required maxlength="30" value="' . e((string)cfg('site_name')) . '"></label>' .
        '<label class="field"><span class="field-l">论坛简介</span><input class="input" name="site_desc" maxlength="100" value="' . e((string)cfg('site_desc')) . '"></label>' .
        '<label class="field"><span class="field-l">站点地址（可选）</span><input class="input" name="site_url" type="url" maxlength="200" placeholder="https://" value="' . e((string)cfg('site_url')) . '"></label>' .
        '<label class="field"><span class="field-l">绑定域名（授权域名，可选）</span><input class="input" name="bind_domains" maxlength="500" placeholder="forum.example.com" value="' . e((string)cfg('bind_domains')) . '">' .
        '<span class="hint">留空不限制。填写后仅允许列表内的域名访问论坛，其他域名（他人恶意解析、镜像站、IP 直连）一律 301 跳转到第一个授权域名。多个域名用逗号分隔；带 www 与不带 www 是两个域名，需分别填写。万一把域名写错导致无法访问：通过 FTP 打开 data/config.php 删掉 bind_domains 一行即可恢复</span></label>' .
        '<div class="grid3">' .
        '<label class="field"><span class="field-l">每页帖子数</span><input class="input" name="per_page" type="number" min="5" max="100" value="' . (int)cfg('per_page', 20) . '"></label>' .
        '<label class="field"><span class="field-l">发帖间隔（秒）</span><input class="input" name="post_interval" type="number" min="0" max="3600" value="' . (int)cfg('post_interval', 30) . '"></label>' .
        '<label class="field"><span class="field-l">在线统计窗口（秒）</span><input class="input" name="online_window" type="number" min="60" max="86400" value="' . (int)cfg('online_window', 300) . '"></label>' .
        '</div>' .
        '<label class="field"><span class="field-l">实时刷新间隔（秒）</span><input class="input" name="live_interval" type="number" min="0" max="300" value="' . (int)cfg('live_interval', 20) . '">' .
        '<span class="hint">前台自动刷新在线人数、未读通知，并提示新帖 / 新回复；设为 0 关闭。页面切到后台时自动暂停，不产生无效流量</span></label>' .
        '</div>' .
        '<div class="form-foot"><span></span><button class="btn btn-primary" type="submit">保存基本设置</button></div></form></div>';
}

/* ---------------- 邮件 ---------------- */
function admin_tab_mail(): void
{
    echo '<div class="card form-card"><form id="mail-form">' .
        '<div class="grid2">' .
        '<label class="field"><span class="field-l">SMTP 主机</span><input class="input" name="smtp_host" required placeholder="smtp.qq.com" value="' . e((string)cfg('smtp_host')) . '"></label>' .
        '<label class="field"><span class="field-l">SMTP 端口</span><input class="input" name="smtp_port" type="number" min="1" max="65535" value="' . (int)cfg('smtp_port', 465) . '"><span class="hint">465 为 SSL；587 / 25 自动尝试 STARTTLS</span></label>' .
        '</div>' .
        '<label class="field"><span class="field-l">发信邮箱</span><input class="input" name="smtp_from" type="email" required value="' . e((string)cfg('smtp_from')) . '"></label>' .
        '<label class="field"><span class="field-l">授权码</span><input class="input" name="smtp_pass" value="' . e((string)cfg('smtp_pass')) . '" placeholder="邮箱服务商提供的 SMTP 授权码（非登录密码）"></label>' .
        '<div class="form-foot">' .
        '<button class="btn btn-primary" type="button" data-admin-save="mail">保存邮件设置</button>' .
        '<button class="btn btn-ghost" type="button" data-admin-test="mail">测试发信</button>' .
        '</div></form>' .
        '<div class="field"><span class="field-l">测试收件邮箱</span><div class="code-row"><input class="input" id="mail-test-to" type="email" placeholder="填写一个收件邮箱，当场发一封测试邮件">' .
        '<button class="btn btn-ghost" type="button" data-admin-test="mail" data-to="#mail-test-to">发送测试邮件</button></div>' .
        '<span class="test-msg muted"></span></div></div>';
}

/* ---------------- AI ---------------- */
function admin_tab_ai(): void
{
    echo '<div class="card form-card"><form id="ai-form">' .
        '<label class="field"><span class="field-l">API 地址（OpenAI 兼容）</span><input class="input" name="ai_url" required placeholder="https://api.openai.com 或 https://host/v1" value="' . e((string)cfg('ai_url')) . '"><span class="hint">支持填根地址、/v1 或完整 /chat/completions</span></label>' .
        '<label class="field"><span class="field-l">API 密钥</span><input class="input" name="ai_key" value="' . e((string)cfg('ai_key')) . '"></label>' .
        '<div class="grid2">' .
        '<label class="field"><span class="field-l">模型名称</span><input class="input" name="ai_model" placeholder="如 gpt-4o-mini / glm-4-flash" value="' . e((string)cfg('ai_model')) . '"></label>' .
        '<label class="field"><span class="field-l">失败重试次数</span><input class="input" name="ai_retries" type="number" min="1" max="10" value="' . (int)cfg('ai_retries', 3) . '"></label>' .
        '</div>' .
        '<div class="form-foot">' .
        '<button class="btn btn-primary" type="button" data-admin-save="ai">保存 AI 设置</button>' .
        '<button class="btn btn-ghost" type="button" data-admin-test="ai">测试调用</button>' .
        '</div>' .
        '<span class="test-msg muted"></span></form>' .
        '<p class="hint">AI 每次只处理举报队列中的一条（先进先出，随页面访问自动触发）；调用失败自动重试，仍失败则内容保持隐藏并通知管理员。</p></div>';
}

/* ---------------- 板块 ---------------- */
function admin_tab_boards(): void
{
    echo '<div class="card form-card"><h2 class="card-title">新建板块</h2>' .
        '<form method="post" action="' . e(u('a=admin_board_new')) . '" class="inline-form">' . csrf_field() .
        '<input class="input" name="name" required maxlength="20" placeholder="板块名称">' .
        '<input class="input" name="desc" maxlength="60" placeholder="一句话简介（可选）">' .
        '<button class="btn btn-primary" type="submit">创建</button></form></div>';

    $bs = board_all();
    echo '<div class="card form-card"><h2 class="card-title">板块列表（' . count($bs) . '）</h2><div class="admin-list">';
    if (!$bs) {
        echo empty_state('还没有板块');
    }
    foreach ($bs as $b) {
        $bid = (int)$b['id'];
        echo '<div class="admin-row"><form method="post" action="' . e(u('a=admin_board_save')) . '" class="inline-form">' .
            '<input type="hidden" name="id" value="' . $bid . '">' . csrf_field() .
            '<input class="input" name="name" value="' . e((string)$b['name']) . '" maxlength="20" required>' .
            '<input class="input" name="desc" value="' . e((string)$b['desc']) . '" maxlength="60">' .
            '<button class="btn btn-ghost btn-sm" type="submit">保存</button></form>' .
            '<span class="row-ops">' .
            '<form method="post" action="' . e(u('a=admin_board_move')) . '" class="inline"><input type="hidden" name="id" value="' . $bid . '"><input type="hidden" name="dir" value="up">' . csrf_field() . '<button class="btn btn-ghost btn-sm" type="submit" title="上移">↑</button></form>' .
            '<form method="post" action="' . e(u('a=admin_board_move')) . '" class="inline"><input type="hidden" name="id" value="' . $bid . '"><input type="hidden" name="dir" value="down">' . csrf_field() . '<button class="btn btn-ghost btn-sm" type="submit" title="下移">↓</button></form>' .
            '<form method="post" action="' . e(u('a=admin_board_del')) . '" class="inline" data-confirm="确认删除该板块？（板块下有帖子时无法删除）"><input type="hidden" name="id" value="' . $bid . '">' . csrf_field() . '<button class="btn btn-ghost btn-sm danger" type="submit">删除</button></form>' .
            '</span></div>';
    }
    echo '</div></div>';
}

/* ---------------- 用户 ---------------- */
function admin_tab_users(): void
{
    $q = trim((string)($_GET['q'] ?? ''));
    echo '<form method="get" action="' . e(u('')) . '" class="search-bar">' .
        '<input type="hidden" name="p" value="admin"><input type="hidden" name="tab" value="users">' .
        '<input class="input" name="q" value="' . e($q) . '" placeholder="搜索用户名 / 邮箱">' .
        '<button class="btn btn-ghost" type="submit">搜索</button></form>';

    $us = user_all();
    if ($q !== '') {
        $ql = str_lower($q);
        $us = array_values(array_filter($us, function ($x) use ($ql) {
            return strpos(str_lower((string)$x['name']), $ql) !== false || strpos(str_lower((string)$x['email']), $ql) !== false;
        }));
    }
    usort($us, function ($a, $b) {
        return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
    });

    echo '<div class="card form-card"><h2 class="card-title">用户（' . count($us) . '）</h2><div class="admin-list">';
    foreach ($us as $x) {
        $uid = (int)$x['id'];
        $self = (int)current_user()['id'] === $uid;
        $status = !empty($x['banned']) ? '<span class="badge badge-danger">已封号</span>'
            : (mute_left($x) > 0 ? '<span class="badge badge-warn">禁言 ' . ceil(mute_left($x) / 60) . ' 分钟</span>'
                : '<span class="badge badge-ok">正常</span>');
        echo '<div class="admin-row admin-user"><div class="u-info">' .
            '<a class="u-name" href="' . e(u('p=user&id=' . $uid)) . '">' . e((string)$x['name']) . '</a>' .
            '<span class="muted">' . e((string)$x['email']) . '</span>' .
            '<span class="badge' . (!empty($x['admin']) ? ' badge-accent' : '') . '">' . e(role_name($x)) . '</span>' .
            $status . '</div>';
        if (!$self) {
            echo '<span class="row-ops">' .
                '<form method="post" action="' . e(u('a=admin_user_mute')) . '" class="inline-form">' .
                '<input type="hidden" name="uid" value="' . $uid . '">' . csrf_field() .
                '<select name="mins" class="input input-sm"><option value="10">10 分钟</option><option value="60">1 小时</option><option value="1440">1 天</option><option value="10080">7 天</option></select>' .
                '<button class="btn btn-ghost btn-sm" type="submit">禁言</button></form>';
            if (!empty($x['banned'])) {
                echo '<form method="post" action="' . e(u('a=admin_user_unban')) . '" class="inline"><input type="hidden" name="uid" value="' . $uid . '">' . csrf_field() . '<button class="btn btn-ghost btn-sm" type="submit">解封</button></form>';
            } else {
                echo '<form method="post" action="' . e(u('a=admin_user_ban')) . '" class="inline" data-confirm="确认封禁该账号？封禁后无法登录。"><input type="hidden" name="uid" value="' . $uid . '">' . csrf_field() . '<button class="btn btn-ghost btn-sm danger" type="submit">封号</button></form>';
            }
            echo '<form method="post" action="' . e(u('a=admin_user_role')) . '" class="inline"><input type="hidden" name="uid" value="' . $uid . '"><input type="hidden" name="to" value="' . (empty($x['admin']) ? '1' : '0') . '">' . csrf_field() .
                '<button class="btn btn-ghost btn-sm" type="submit">' . (empty($x['admin']) ? '设为管理员' : '取消管理员') . '</button></form>' .
                '</span>';
        } else {
            echo '<span class="muted">（当前账号）</span>';
        }
        echo '</div>';
    }
    echo '</div></div>';
}

/* ---------------- 内容 ---------------- */
function admin_tab_content(): void
{
    $q = trim((string)($_GET['q'] ?? ''));
    echo '<form method="get" action="' . e(u('')) . '" class="search-bar">' .
        '<input type="hidden" name="p" value="admin"><input type="hidden" name="tab" value="content">' .
        '<input class="input" name="q" value="' . e($q) . '" placeholder="搜索帖子标题 / 作者">' .
        '<button class="btn btn-ghost" type="submit">搜索</button></form>';

    $idx = thread_index();
    usort($idx, function ($a, $b) {
        return (int)($b['created'] ?? 0) <=> (int)($a['created'] ?? 0);
    });
    if ($q !== '') {
        $ql = str_lower($q);
        $idx = array_values(array_filter($idx, function ($t) use ($ql) {
            if (strpos(str_lower((string)$t['title']), $ql) !== false) {
                return true;
            }
            return strpos(str_lower(uname((int)$t['author'])), $ql) !== false;
        }));
    }

    echo '<div class="card form-card"><h2 class="card-title">帖子（' . count($idx) . '）</h2><div class="admin-list">';
    if (!$idx) {
        echo empty_state('没有帖子');
    }
    foreach ($idx as $t) {
        $tid = (int)$t['id'];
        $badges = (!empty($t['pinned']) ? '<span class="badge badge-accent">置顶</span>' : '') .
            (!empty($t['locked']) ? '<span class="badge">锁定</span>' : '') .
            (!empty($t['hidden']) ? '<span class="badge badge-warn">审核中</span>' : '');
        echo '<div class="admin-row"><div class="u-info">' .
            '<a class="u-name" href="' . e(u('p=thread&id=' . $tid)) . '">' . e((string)$t['title']) . '</a>' .
            '<span class="muted">' . e(board_name((int)$t['board'])) . ' · ' . e(uname((int)$t['author'])) . ' · ' . fmt_time((int)$t['created']) . '</span>' .
            $badges . '</div>' .
            '<span class="row-ops">' .
            '<form method="post" action="' . e(u('a=admin_thread_op')) . '" class="inline"><input type="hidden" name="tid" value="' . $tid . '"><input type="hidden" name="act" value="' . (empty($t['locked']) ? 'lock' : 'unlock') . '">' . csrf_field() . '<button class="btn btn-ghost btn-sm" type="submit">' . (empty($t['locked']) ? '锁定' : '解锁') . '</button></form>' .
            '<form method="post" action="' . e(u('a=admin_thread_op')) . '" class="inline"><input type="hidden" name="tid" value="' . $tid . '"><input type="hidden" name="act" value="' . (empty($t['pinned']) ? 'pin' : 'unpin') . '">' . csrf_field() . '<button class="btn btn-ghost btn-sm" type="submit">' . (empty($t['pinned']) ? '置顶' : '取消置顶') . '</button></form>' .
            '<form method="post" action="' . e(u('a=admin_thread_op')) . '" class="inline"><input type="hidden" name="tid" value="' . $tid . '"><input type="hidden" name="act" value="move"><select name="board" class="input input-sm">';
        foreach (board_all() as $b) {
            echo '<option value="' . (int)$b['id'] . '"' . ((int)$t['board'] === (int)$b['id'] ? ' selected' : '') . '>' . e((string)$b['name']) . '</option>';
        }
        echo '</select>' . csrf_field() . '<button class="btn btn-ghost btn-sm" type="submit">移版</button></form>' .
            '<form method="post" action="' . e(u('a=admin_thread_op')) . '" class="inline" data-confirm="确认删除该帖子？将通知作者。"><input type="hidden" name="tid" value="' . $tid . '"><input type="hidden" name="act" value="delete">' . csrf_field() . '<button class="btn btn-ghost btn-sm danger" type="submit">删除</button></form>' .
            '</span></div>';
    }
    echo '</div><p class="hint">删除回复请进入帖子页面，在对应回复处操作。</p></div>';
}

/* ---------------- AI 待审队列 ---------------- */
function admin_tab_queue(): void
{
    $q = queue_list();
    echo '<div class="list-head"><h2 class="card-title">AI 待审队列（' . count($q) . '）</h2>' .
        '<form method="post" action="' . e(u('a=admin_queue_run')) . '" class="inline">' . csrf_field() .
        '<button class="btn btn-primary btn-sm" type="submit">立即审核一条</button></form></div>';
    echo '<p class="hint">AI 随论坛访问自动处理队列，每次只处理一条（保护速率限制）；也可以在此手动触发。审核失败的内容会保持隐藏并通知管理员。</p>';
    echo '<div class="admin-list">';
    if (!$q) {
        echo empty_state('队列为空');
    }
    foreach ($q as $rid) {
        $rep = report_get((int)$rid);
        if (!$rep) {
            continue;
        }
        $gone = false;
        $preview = cut_str(str_replace("\n", ' ', report_target_content($rep, $gone)), 80);
        echo '<div class="card queue-item"><div class="notice-head"><b>#' . (int)$rep['id'] . ' · ' . ($rep['type'] === 'thread' ? '帖子' : '回复') . ' #' . (int)$rep['tid'] . ($rep['type'] === 'reply' ? ' / #' . (int)$rep['rid'] : '') . '</b>' .
            '<span class="muted">举报人 ' . e(uname((int)$rep['reporter'])) . ' · ' . fmt_time((int)$rep['created']) . '</span></div>' .
            '<p class="notice-body">理由：' . e((string)$rep['reason']) . '</p>' .
            '<p class="muted">内容预览：' . ($gone ? '（已被删除）' : e($preview)) . '</p>' .
            '<div class="notice-ops">' .
            '<a class="btn btn-ghost btn-sm" href="' . e(u('p=thread&id=' . (int)$rep['tid'])) . '">查看</a>' .
            '<form method="post" action="' . e(u('a=admin_queue_restore')) . '" class="inline"><input type="hidden" name="rid" value="' . (int)$rep['id'] . '">' . csrf_field() . '<button class="btn btn-ghost btn-sm" type="submit">直接恢复</button></form>' .
            '<form method="post" action="' . e(u('a=admin_queue_delete')) . '" class="inline" data-confirm="确认直接删除该内容？将通知作者。"><input type="hidden" name="rid" value="' . (int)$rep['id'] . '">' . csrf_field() . '<button class="btn btn-ghost btn-sm danger" type="submit">直接删除</button></form>' .
            '</div></div>';
    }
    echo '</div>';
}

/* ---------------- 人工待审（已申诉 / AI 失败 / AI 判违规） ---------------- */
function admin_tab_manual(): void
{
    $list = [];
    foreach (reports_all() as $r) {
        if (in_array($r['status'], ['appealed', 'ai_bad', 'ai_failed'], true)) {
            $list[] = $r;
        }
    }
    usort($list, function ($a, $b) {
        return (int)($a['created'] ?? 0) <=> (int)($b['created'] ?? 0);
    });
    echo '<div class="list-head"><h2 class="card-title">人工待审（' . count($list) . '）</h2></div>';
    echo '<p class="hint">包含用户申诉内容与 AI 判定违规内容。有问题请删除（将通知作者），没问题请恢复。</p>';
    echo '<div class="admin-list">';
    if (!$list) {
        echo empty_state('没有待人工处理的内容');
    }
    foreach ($list as $r) {
        $gone = false;
        $preview = cut_str(str_replace("\n", ' ', report_target_content($r, $gone)), 100);
        echo '<div class="card queue-item"><div class="notice-head"><b>#' . (int)$r['id'] . ' · ' . ($r['type'] === 'thread' ? '帖子' : '回复') . ' #' . (int)$r['tid'] . '</b>' .
            '<span class="badge ' . ($r['status'] === 'appealed' ? 'badge-info' : 'badge-warn') . '">' . e(report_status_label((string)$r['status'])) . '</span></div>' .
            '<p class="notice-body">举报理由：' . e((string)$r['reason']) . '</p>' .
            (!empty($r['note']) ? '<p class="muted">AI 意见：' . e((string)$r['note']) . '</p>' : '') .
            '<p class="muted">内容预览：' . ($gone ? '（已被删除）' : e($preview)) . '</p>' .
            '<div class="notice-ops">' .
            '<a class="btn btn-ghost btn-sm" href="' . e(u('p=thread&id=' . (int)$r['tid'])) . '">查看</a>' .
            '<form method="post" action="' . e(u('a=admin_manual_restore')) . '" class="inline"><input type="hidden" name="rid" value="' . (int)$r['id'] . '">' . csrf_field() . '<button class="btn btn-primary btn-sm" type="submit">没问题，恢复</button></form>' .
            '<form method="post" action="' . e(u('a=admin_manual_delete')) . '" class="inline" data-confirm="确认删除该内容？将通知作者。"><input type="hidden" name="rid" value="' . (int)$r['id'] . '">' . csrf_field() . '<button class="btn btn-ghost btn-sm danger" type="submit">有问题，删除</button></form>' .
            '</div></div>';
    }
    echo '</div>';
}

/* ---------------- 举报记录 ---------------- */
function admin_tab_reports(): void
{
    $rs = reports_all();
    usort($rs, function ($a, $b) {
        return (int)($b['created'] ?? 0) <=> (int)($a['created'] ?? 0);
    });
    echo '<div class="list-head"><h2 class="card-title">举报记录（' . count($rs) . '）</h2></div>';
    echo '<div class="admin-list">';
    if (!$rs) {
        echo empty_state('暂无举报记录');
    }
    foreach ($rs as $r) {
        echo '<div class="admin-row"><div class="u-info">' .
            '<b>#' . (int)$r['id'] . ' · ' . ($r['type'] === 'thread' ? '帖子' : '回复') . ' #' . (int)$r['tid'] . '</b>' .
            '<span class="muted">举报人 ' . e(uname((int)$r['reporter'])) . ' · ' . fmt_time((int)$r['created']) . '</span>' .
            '<span class="badge">' . e(report_status_label((string)$r['status'])) . '</span>' .
            '</div><span class="muted r-reason">理由：' . e((string)$r['reason']) . '</span></div>';
    }
    echo '</div>';
}

/* ---------------- 公告 ---------------- */
function admin_tab_anns(): void
{
    $editId = get_int('edit', 0);
    $edit = $editId > 0 ? ann_get($editId) : null;
    echo '<div class="card form-card"><h2 class="card-title">' . ($edit ? '编辑公告 #' . $editId : '发布公告') . '</h2>' .
        '<form method="post" action="' . e(u('a=admin_ann_save')) . '">' . csrf_field() .
        '<input type="hidden" name="id" value="' . ($edit ? $editId : 0) . '">' .
        '<label class="field"><span class="field-l">标题</span><input class="input" name="title" required maxlength="60" value="' . e((string)($edit['title'] ?? '')) . '"></label>' .
        '<label class="field"><span class="field-l">内容</span><textarea class="input" name="content" rows="8" required maxlength="2000" placeholder="支持 Markdown：# 标题、**加粗**、- 列表、> 引用、| 表格 |、==高亮==、![图](https://...)">' . e((string)($edit['content'] ?? '')) . '</textarea></label>' .
        '<div class="form-foot"><span class="muted">支持 Markdown：标题 / 加粗 / 斜体 / 删除线 / 高亮 / 列表 / 任务列表 / 表格 / 引用 / 代码块 / 链接 / 图片（https 外链）</span><button class="btn btn-primary" type="submit">' . ($edit ? '保存修改' : '发布') . '</button></div></form></div>';

    $anns = ann_all();
    echo '<div class="card form-card"><h2 class="card-title">公告（' . count($anns) . '）</h2><div class="admin-list">';
    if (!$anns) {
        echo empty_state('暂无公告');
    }
    foreach ($anns as $a) {
        echo '<div class="admin-row"><div class="u-info"><b>' . e((string)$a['title']) . '</b>' .
            '<span class="muted">' . fmt_dt((int)$a['created']) . '</span></div>' .
            '<span class="row-ops">' .
            '<a class="btn btn-ghost btn-sm" href="' . e(u('p=admin&tab=anns&edit=' . (int)$a['id'])) . '">编辑</a>' .
            '<form method="post" action="' . e(u('a=admin_ann_del')) . '" class="inline" data-confirm="确认删除该公告？"><input type="hidden" name="id" value="' . (int)$a['id'] . '">' . csrf_field() . '<button class="btn btn-ghost btn-sm danger" type="submit">删除</button></form>' .
            '</span></div>';
    }
    echo '</div></div>';
}

/* ---------------- 主题 ---------------- */
function admin_tab_theme(): void
{
    $cur = (string)cfg('theme_color', '#0f766e');
    $palette = ['#0f766e', '#047857', '#b45309', '#be123c', '#57534e', '#1c1917'];
    $dark = (string)cfg('dark_default', 'system');
    echo '<div class="card form-card"><form method="post" action="' . e(u('a=admin_save_theme')) . '">' . csrf_field() .
        '<div class="field"><span class="field-l">主题色</span><div class="palette">';
    foreach ($palette as $p) {
        echo '<label class="swatch" style="background:' . e($p) . '"><input type="radio" name="theme_color" value="' . e($p) . '"' . (strcasecmp($cur, $p) === 0 ? ' checked' : '') . ' aria-label="' . e($p) . '"></label>';
    }
    echo '</div><input class="input" type="color" name="theme_color_custom" value="' . e(preg_match('/^#[0-9a-fA-F]{6}$/', $cur) ? $cur : '#0f766e') . '" style="max-width:120px"><span class="hint">色板或自定义颜色，保存后全局生效</span></div>' .
        '<label class="field"><span class="field-l">深色模式默认值</span><select name="dark_default" class="input">' .
        '<option value="system"' . ($dark === 'system' ? ' selected' : '') . '>跟随系统</option>' .
        '<option value="light"' . ($dark === 'light' ? ' selected' : '') . '>浅色</option>' .
        '<option value="dark"' . ($dark === 'dark' ? ' selected' : '') . '>深色</option>' .
        '</select><span class="hint">用户仍可在右上角手动切换，选择会被记住</span></label>' .
        '<label class="field"><span class="field-l">页脚文字（左侧，留空显示默认）</span><input class="input" name="footer_text" maxlength="120" placeholder="默认：' . e((string)cfg('site_name')) . ' · Cube Minimalist Forum v' . e(app_version()) . '" value="' . e((string)cfg('footer_text', '')) . '"></label>' .
        '<label class="field"><span class="field-l">页脚备注（右侧，留空显示默认）</span><input class="input" name="footer_note" maxlength="120" placeholder="默认：纯文字 · 文件存储 · 无数据库" value="' . e((string)cfg('footer_note', '')) . '"></label>' .
        '<label class="field"><span class="field-l">自定义样式（CSS）</span><textarea class="input" name="custom_css" rows="6" placeholder="/* 追加到页面尾部的自定义 CSS */">' . e((string)cfg('custom_css')) . '</textarea></label>' .
        '<div class="form-foot"><span></span><button class="btn btn-primary" type="submit">保存主题设置</button></div></form></div>';
}

/* ---------------- 监控 ---------------- */

/** 环形进度 SVG（实时资源卡用），$pct 0-100 或 null（不可用，显示灰色虚线状态），$r 半径 */
function res_ring(?float $pct, int $r = 44): string
{
    $c = 2 * M_PI * $r;
    $na = $pct === null;
    $pct = $na ? 0.0 : max(0.0, min(100.0, (float)$pct));
    $off = round($c * (1 - $pct / 100), 1);
    $lvl = $na ? ' na' : ($pct >= 85 ? ' bad' : ($pct >= 60 ? ' warn' : ''));
    // fill="none" 必须内联：SVG 圆形的默认填充是黑色，若浏览器缓存了旧版样式表
    // （旧版监控页没有环形图规则），环形会被渲染成"纯黑色圆形"。内联属性不依赖外部 CSS，
    // 优先级低于样式表，正常加载时主题色/深色模式不受影响。
    return '<svg class="ring' . $lvl . '" viewBox="0 0 ' . (2 * $r + 8) . ' ' . (2 * $r + 8) . '" fill="none" role="img" aria-label="' . ($na ? '当前环境不可读' : '占用 ' . round($pct) . '%') . '">' .
        '<circle class="ring-bg" cx="' . ($r + 4) . '" cy="' . ($r + 4) . '" r="' . $r . '" fill="none"></circle>' .
        '<circle class="ring-fg" cx="' . ($r + 4) . '" cy="' . ($r + 4) . '" r="' . $r . '" fill="none" stroke-dasharray="' . round($c, 1) . '" stroke-dashoffset="' . $off . '"></circle>' .
        '</svg>';
}

function admin_tab_monitor(): void
{
    [$app, $data] = sysmon_sizes();
    [$dt, $df] = sysmon_disk();
    [$l1, $l5, $l15] = sysmon_load();
    $php = sysmon_php_info();
    [$threads, $withReplies, $users, $lf, $lb, $backups] = sysmon_content_stats();
    $total = $app + $data;
    $quota = max(10, (int)cfg('quota_mb', 100)) * 1048576;
    $th = max(1, (int)cfg('monitor_mb', 95));
    $thBytes = $th * 1048576;
    $pct = min(100, (int)round($total / $quota * 100));
    $thPct = min(100, (int)round($thBytes / $quota * 100));
    $meterCls = $total >= $thBytes ? ' bad' : ($total >= $thBytes * 0.8 ? ' warn' : '');
    $over = $total >= $thBytes;
    $st = sysmon_state();
    $cpu = sysmon_cpu();
    $cpuPct = $cpu['pct'];
    $cpuNa = $cpuPct === null;
    $mem = sysmon_mem();
    $memNa = $mem['mode'] === 'na' || $mem['pct'] === null;
    $liveOn = max(0, (int)cfg('live_interval', 20));
    $monInt = (int)cfg('monitor_interval', 5);
    $monInt = $monInt === 1 ? 2 : max(0, min(300, $monInt));
    $monLbl = $monInt > 0 ? '每 ' . $monInt . ' 秒自动刷新' : '自动刷新已关闭';
    $loadStr = e($l1 . ' / ' . $l5 . ' / ' . $l15);

    echo '<div class="card form-card"><h2 class="card-title">实时资源 <span class="live-tag" aria-hidden="true"></span><span class="muted res-sub">' . e($monLbl) . '</span></h2>' .
        '<div class="res-grid">';

    // CPU 卡：芯片图标（核心脉冲动画）+ 使用率环形；/proc 被主机限制时按系统负载估算
    $cpuFoot = $cpu['mode'] === 'load'
        ? '主机限制 /proc · 按 ' . sysmon_cores() . ' 核负载估算'
        : ($cpuNa ? '当前环境不可读 · 负载 ' . $loadStr : '实际采样 · 负载 ' . $loadStr);
    echo '<div class="res-card" id="resCpu">' .
        '<div class="res-ico ico-cpu" aria-hidden="true"><i class="cpu-core"></i><i class="cpu-pin p1"></i><i class="cpu-pin p2"></i><i class="cpu-pin p3"></i><i class="cpu-pin p4"></i></div>' .
        res_ring($cpuNa ? null : (float)$cpuPct) .
        '<div class="res-val"><b id="resCpuVal">' . ($cpuNa ? '—' : $cpuPct . '<small>%</small>') . '</b><span>CPU 使用率</span></div>' .
        '<div class="res-foot muted" id="resCpuFoot">' . $cpuFoot . '</div></div>';

    // 内存卡：内存条图标（滑动填充动画）+ 使用率环形；/proc 被限制时显示本 PHP 进程占用
    $memFoot = $mem['mode'] === 'proc'
        ? ('本进程 ' . e(fmt_bytes((int)$mem['used'])) . ($mem['total'] > 0 ? ' / 上限 ' . e(fmt_bytes((int)$mem['total'])) : ''))
        : ($memNa ? '当前环境不可读' : e(fmt_bytes((int)$mem['used'])) . ' / ' . e(fmt_bytes((int)$mem['total'])));
    echo '<div class="res-card" id="resMem">' .
        '<div class="res-ico ico-mem" aria-hidden="true"><i class="mem-bar"></i><i class="mem-fill"></i></div>' .
        res_ring($memNa ? null : (float)$mem['pct']) .
        '<div class="res-val"><b id="resMemVal">' . ($memNa ? '—' : $mem['pct'] . '<small>%</small>') . '</b><span>内存使用率</span></div>' .
        '<div class="res-foot muted" id="resMemFoot">' . $memFoot . '</div></div>';

    // 磁盘卡：盘片图标（旋转弧线动画）+ 使用率环形（整块磁盘）
    $dPct = $dt > 0 ? round(($dt - $df) / $dt * 100, 1) : -1;
    echo '<div class="res-card" id="resDisk">' .
        '<div class="res-ico ico-disk" aria-hidden="true"><i class="disk-arc"></i><i class="disk-dot"></i></div>' .
        res_ring($dPct >= 0 ? (float)$dPct : null) .
        '<div class="res-val"><b id="resDiskVal">' . ($dPct >= 0 ? $dPct . '<small>%</small>' : '—') . '</b><span>磁盘使用率</span></div>' .
        '<div class="res-foot muted" id="resDiskFoot">' . ($dPct >= 0 ? '剩余 ' . e(fmt_bytes($df)) . ' / 共 ' . e(fmt_bytes($dt)) : 'disk_free_space 被禁用') . '</div></div>';

    echo '</div>';
    echo '<p class="hint">CPU 与内存读数优先来自系统接口（/proc）；被主机限制时 CPU 按系统负载估算、内存显示本 PHP 进程占用，不影响其他功能；磁盘为整块物理盘的用量。论坛自身占用见下方「存储占用」。</p></div>';

    echo '<div class="card form-card"><h2 class="card-title">存储占用</h2>' .
        '<div class="flex" style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px">' .
        '<span>合计 <b>' . e(fmt_bytes($total)) . '</b> / 配额 ' . (int)cfg('quota_mb', 100) . 'MB</span>' .
        '<span class="muted">程序 ' . e(fmt_bytes($app)) . ' · 数据 ' . e(fmt_bytes($data)) . '</span></div>' .
        '<div class="meter" role="img" aria-label="存储占用 ' . $pct . '%"><i class="' . trim($meterCls) . '" style="width:' . $pct . '%"></i></div>' .
        '<p class="hint">告警阈值 ' . $th . 'MB（阈值线位于进度条 ' . $thPct . '% 处）；' .
        ($over
            ? '<b style="color:var(--danger)">当前已超过阈值！' . (!empty($st['last_alert_sent']) && $st['last_alert_sent'] > 0 ? '告警邮件已于 ' . e(fmt_dt((int)$st['last_alert'])) . ' 发送 ' . (int)$st['last_alert_sent'] . ' 位管理员。' : '尚未成功发送告警邮件，请检查邮件配置。') . '</b>'
            : '占用正常。') .
        '上次自动检查：' . ((int)($st['last_check'] ?? 0) > 0 ? e(fmt_dt((int)$st['last_check'])) : '尚无记录（随访问每小时检查一次）') . '</p></div>';

    echo '<div class="card form-card"><h2 class="card-title">服务器信息</h2><div class="mon-grid">' .
        '<div class="mon-cell"><b>v' . e(app_version()) . '</b><span>论坛版本</span></div>' .
        '<div class="mon-cell"><b>' . e($php['version']) . '</b><span>PHP 版本（' . e($php['sapi']) . '）</span></div>' .
        '<div class="mon-cell"><b>' . ($liveOn > 0 ? $liveOn . 's' : '关闭') . '</b><span>前台实时刷新间隔</span></div>' .
        '<div class="mon-cell"><b>' . e($php['mem']) . '</b><span>PHP 内存限制</span></div>' .
        '<div class="mon-cell"><b>' . e($php['max_exec']) . 's</b><span>最大执行时间</span></div>' .
        '<div class="mon-cell"><b>' . e($php['upload']) . '</b><span>单文件上传限制</span></div>' .
        '<div class="mon-cell"><b>' . e($php['post']) . '</b><span>POST 体积限制</span></div>' .
        '<div class="mon-cell"><b>' . online_count() . '</b><span>当前在线</span></div>' .
        '</div>' .
        '<p class="hint">实时刷新（在线人数 / 新帖新回复提示）按此间隔轮询，页面切到后台时自动暂停；设为 0 可完全关闭以节省流量（后台「基本设置」中修改）。</p></div>';

    // 环境自检：定位“发不了帖 / 存不上数据”这类主机环境问题（真实写探针，逐目录检查）
    $dataW = Store::writable();
    $dirChecks = [
        '.'        => 'data/ 数据目录（关键）',
        'threads'  => 'data/threads/ 帖子目录',
        'replies'  => 'data/replies/ 回复目录',
        'sessions' => 'data/sessions/ 会话目录',
        'logs'     => 'data/logs/ 操作日志目录',
        'locks'    => 'data/locks/ 文件锁目录',
        'backup'   => 'data/backup/ 备份目录',
    ];
    $dirBad = [];
    $fnChecks = [
        'flock（文件锁）'       => function_exists('flock'),
        'json（数据存储）'      => function_exists('json_encode'),
        'mail（本机发信）'      => function_exists('mail'),
        'curl（AI / 告警）'     => function_exists('curl_init'),
        'mbstring（自动回退）'  => function_exists('mb_strlen'),
        'disk_free_space（磁盘统计）' => function_exists('disk_free_space'),
    ];
    $errLog = DATA_DIR . '/error.log';
    $errSize = is_file($errLog) ? (int)@filesize($errLog) : 0;
    $errTail = '';
    if ($errSize > 0) {
        $lines = @file($errLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines)) {
            $errTail = implode("\n", array_slice($lines, -12));
        }
    }
    echo '<div class="card form-card"><h2 class="card-title">环境自检</h2><div class="mon-grid">';
    foreach ($dirChecks as $rel => $label) {
        $exists = is_dir(Store::path($rel));
        $ok = $exists ? Store::dirWritable($rel) : false;
        if (!$ok) {
            $dirBad[] = $rel;
        }
        $state = $ok ? '✔ 可写' : ($exists ? '✘ 不可写' : '未创建');
        echo '<div class="mon-cell' . ($ok ? '' : ' cell-bad') . '"><b>' . $state . '</b><span>' . e($label) . '</span></div>';
    }
    foreach ($fnChecks as $k => $ok) {
        echo '<div class="mon-cell"><b>' . ($ok ? '✔' : '—') . '</b><span>' . e($k) . '</span></div>';
    }
    echo '<div class="mon-cell"><b>' . ($errSize > 0 ? fmt_bytes($errSize) : '无记录') . '</b><span>error.log 运行错误日志</span></div>' .
        '</div>';
    if ($errTail !== '') {
        echo '<details class="err-box"><summary>查看最近的运行错误（最新 ' . min(12, substr_count($errTail, "\n") + 1) . ' 行）</summary><pre>' . e(cut_str($errTail, 4000)) . '</pre></details>';
        echo '<p class="hint">若发帖 / 回复等操作报错，这里通常能看到具体原因（目录权限、磁盘写满、函数被禁用等）。</p>';
    }
    if ($dirBad) {
        $badNames = [];
        foreach ($dirBad as $rel) {
            $badNames[] = $rel === '.' ? 'data/' : 'data/' . $rel . '/';
        }
        echo '<form method="post" action="' . e(u('a=admin_repair_dirs')) . '"><div class="form-foot">' . csrf_field() .
            '<button class="btn btn-primary" type="submit">一键修复目录权限</button></div></form>' .
            '<p class="hint">不可写：<b>' . e(implode('、', $badNames)) . '</b>。一键修复会依次尝试：创建缺失目录 → 修改权限（0775/0777）→ 重建空的失效目录（可救回属主不对的目录）。若修复后仍不可写，说明目录属主不属于 PHP 运行账号，请通过 FTP / 主机面板将 data/ 及其全部子目录权限设为 755 或 775。</p>';
    }
    if (!$dataW) {
        echo '<p class="hint" style="color:var(--danger)"><b>data/ 目录不可写将导致无法发帖、无法注册、设置无法保存。</b>请先点上方「一键修复目录权限」；若无效，请通过 FTP / 主机面板将 data/ 及其全部子目录与文件的权限设为 755（部分主机需 775），确保 PHP 运行账号拥有写权限。</p>';
    }
    echo '</div>';

    echo '<div class="card form-card"><h2 class="card-title">内容规模</h2><div class="mon-grid">' .
        '<div class="mon-cell"><b>' . $threads . '</b><span>帖子</span></div>' .
        '<div class="mon-cell"><b>' . $withReplies . '</b><span>有回复的帖</span></div>' .
        '<div class="mon-cell"><b>' . $users . '</b><span>用户</span></div>' .
        '<div class="mon-cell"><b>' . e(fmt_bytes($lb)) . '</b><span>操作日志（' . $lf . ' 个文件）</span></div>' .
        '<div class="mon-cell"><b>' . $backups . '</b><span>备份文件</span></div>' .
        '<div class="mon-cell"><b>' . ($php['curl'] ? '✔' : '—') . '</b><span>curl 扩展（AI 用）</span></div>' .
        '</div></div>';

    $on = (int)cfg('monitor_on', 1) === 1;
    echo '<div class="card form-card"><h2 class="card-title">监控与告警设置</h2>' .
        '<form method="post" action="' . e(u('a=admin_save_monitor')) . '">' . csrf_field() .
        '<div class="grid2">' .
        '<label class="field"><span class="field-l">自动告警</span><select name="monitor_on" class="input">' .
        '<option value="1"' . ($on ? ' selected' : '') . '>开启</option>' .
        '<option value="0"' . (!$on ? ' selected' : '') . '>关闭</option></select></label>' .
        '<label class="field"><span class="field-l">告警阈值（MB）</span><input class="input" name="monitor_mb" type="number" min="1" max="999" value="' . $th . '"></label>' .
        '<label class="field"><span class="field-l">监控页实时刷新间隔（秒，0=关闭）</span><input class="input" name="monitor_interval" type="number" min="0" max="300" value="' . $monInt . '"><span class="hint">实时资源卡的自动刷新频率，建议 3～30 秒；0 为关闭以节省流量</span></label>' .
        '</div>' .
        '<div class="form-foot"><button class="btn btn-ghost" type="button" data-admin-test="monitor">发送测试告警邮件</button>' .
        '<button class="btn btn-primary" type="submit">保存监控设置</button></div>' .
        '<span class="test-msg muted"></span></form>' .
        '<p class="hint">总占用（程序 + 数据）超过阈值时自动给全部管理员发告警邮件。检查随论坛访问进行（每小时至多一次）；持续超限时每 6 小时提醒一次，回落到阈值下后自动重置。告警通过后台「邮件」中配置的 SMTP 发送。</p></div>';
}

/* ---------------- 系统 ---------------- */
function admin_tab_system(array $me): void
{
    [$appSize, $dataSize] = sysmon_sizes();
    $threads = count(thread_index());
    $users = count(user_all());
    // 与监控页同口径：数索引中 replies > 0 的帖子（旧逻辑数 threads/ 目录文件数，等于帖子总数）
    $withReplies = 0;
    foreach (thread_index() as $t) {
        if ((int)($t['replies'] ?? 0) > 0) {
            $withReplies++;
        }
    }
    $backups = array_reverse(Store::scan('backup'));

    echo '<div class="card form-card"><h2 class="card-title">系统信息</h2><div class="stat-row">' .
        '<div class="stat"><b>v' . e(app_version()) . '</b><span>论坛版本</span></div>' .
        '<div class="stat"><b>' . e(PHP_VERSION) . '</b><span>PHP 版本</span></div>' .
        '<div class="stat"><b>' . e(fmt_bytes($appSize)) . '</b><span>程序体积</span></div>' .
        '<div class="stat"><b>' . e(fmt_bytes($dataSize)) . '</b><span>数据占用</span></div>' .
        '<div class="stat"><b>' . $threads . '</b><span>帖子总数</span></div>' .
        '<div class="stat"><b>' . $withReplies . '</b><span>有回复的帖</span></div>' .
        '<div class="stat"><b>' . $users . '</b><span>用户总数</span></div>' .
        '</div>' .
        '<p class="hint">程序本体限制 ≤ 10MB；数据目录随使用增长，建议定期「一键备份」并下载留存；升级请到「更新升级」上传更新包。</p></div>';

    echo '<div class="card form-card"><h2 class="card-title">数据备份</h2>' .
        '<form method="post" action="' . e(u('a=admin_backup')) . '" class="inline">' . csrf_field() .
        '<button class="btn btn-primary" type="submit">一键备份数据目录</button></form>' .
        '<p class="hint">打包 data/ 目录（不含缓存与本次备份），保留最近 5 份，存于 data/backup/。</p>' .
        '<div class="admin-list">';
    if (!$backups) {
        echo empty_state('还没有备份');
    }
    foreach ($backups as $b) {
        echo '<div class="admin-row"><div class="u-info"><b>' . e($b) . '</b>' .
            '<span class="muted">' . e(fmt_bytes((int)@filesize(Store::path('backup/' . $b)))) . (strpos($b, 'pre-update-') === 0 ? ' · 更新前自动备份' : '') . '</span></div>' .
            '<span class="admin-row-actions">' .
            '<a class="btn btn-ghost btn-sm" href="' . e(u('a=admin_backup_dl&id=' . e($b))) . '">下载</a>' .
            '<form method="post" action="' . e(u('a=admin_backup_del')) . '" class="inline" data-confirm="确认删除该备份包？删除后不可恢复。">' . csrf_field() . hidden_back() .
            '<input type="hidden" name="id" value="' . e($b) . '">' .
            '<button class="btn btn-danger btn-sm" type="submit">删除</button></form>' .
            '</span></div>';
    }
    echo '</div></div>';
}

/* ---------------- 操作日志 ---------------- */
function admin_tab_logs(): void
{
    [$lf, $lb] = log_stats();
    echo '<div class="card form-card"><div class="list-head"><h2 class="card-title">日志设置</h2>' .
        '<span class="muted">共 ' . $lf . ' 个日志文件 · ' . e(fmt_bytes($lb)) . '</span></div>' .
        '<form method="post" action="' . e(u('a=admin_logs_settings')) . '" class="inline-form">' . csrf_field() .
        '<label class="field"><span class="field-l">日志保留天数（0 = 永久）</span><input class="input" type="number" name="log_days" min="0" max="3650" value="' . (int)cfg('log_days', 90) . '" style="max-width:170px"></label>' .
        '<label class="field"><span class="field-l">记录页面访问（含游客）</span><select class="input" name="log_views" style="max-width:170px">' .
        '<option value="0"' . ((int)cfg('log_views', 0) === 0 ? ' selected' : '') . '>关闭</option>' .
        '<option value="1"' . ((int)cfg('log_views', 0) === 1 ? ' selected' : '') . '>开启</option></select></label>' .
        '<button class="btn btn-primary" type="submit">保存设置</button></form>' .
        '<form method="post" action="' . e(u('a=admin_logs_clear')) . '" class="inline" data-confirm="确认清理过期日志？">' . csrf_field() .
        '<button class="btn btn-ghost btn-sm" type="submit">立即清理过期日志</button></form>' .
        '<p class="hint">日志按天存放于 data/logs/（守卫保护，无法被直接访问）；超过保留天数的文件会在每天首次写日志时自动清理；日志总量上限 20MB，超出时从最旧删起。</p></div>';

    $files = log_files();
    $date = (string)($_GET['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = $files ? substr($files[0], 4, 10) : date('Y-m-d');
    }
    $qUser = trim((string)($_GET['quser'] ?? ''));
    $qAction = (string)($_GET['qaction'] ?? '');
    $page = max(1, get_int('page', 1));
    $per = 50;
    $total = 0;
    $rows = log_read($date, $per, $page, $qUser, $qAction, $total);

    echo '<div class="card form-card"><h2 class="card-title">查询日志</h2>' .
        '<form method="get" action="' . e(u('')) . '" class="inline-form">' .
        '<input type="hidden" name="p" value="admin"><input type="hidden" name="tab" value="logs">' .
        '<select name="date" class="input input-sm">';
    $opts = [$date => true, date('Y-m-d') => true];
    foreach ($files as $f) {
        $opts[substr($f, 4, 10)] = true;
    }
    foreach (array_keys($opts) as $d) {
        echo '<option value="' . e($d) . '"' . ($d === $date ? ' selected' : '') . '>' . e($d) . '</option>';
    }
    echo '</select>' .
        '<input class="input input-sm" name="quser" value="' . e($qUser) . '" placeholder="用户名或 IP">' .
        '<select name="qaction" class="input input-sm"><option value="">全部动作</option>';
    foreach (LOG_ACTIONS as $k => $v) {
        echo '<option value="' . e($k) . '"' . ($k === $qAction ? ' selected' : '') . '>' . e($v) . '</option>';
    }
    echo '</select>' .
        '<button class="btn btn-ghost btn-sm" type="submit">筛选</button></form>';

    echo '<div class="table-wrap"><table class="log-table"><thead><tr><th>时间</th><th>用户</th><th>动作</th><th>详情</th><th>IP</th></tr></thead><tbody>';
    if (!$rows) {
        echo '<tr><td colspan="5">' . empty_state('该日期暂无日志') . '</td></tr>';
    }
    foreach ($rows as $r) {
        $act = (string)$r['action'];
        $cls = strpos($act, 'admin_') === 0 || $act === 'update_apply' || $act === 'install' ? ' badge-accent'
            : ($act === 'login_failed' || $act === 'ai_bad' || $act === 'report' ? ' badge-warn' : '');
        echo '<tr>' .
            '<td class="nowrap">' . date('H:i:s', (int)($r['t'] ?? 0)) . '</td>' .
            '<td class="nowrap">' . ((int)($r['uid'] ?? 0) > 0
                ? '<a href="' . e(u('p=user&id=' . (int)$r['uid'])) . '">' . e((string)($r['name'] ?? '')) . '</a>'
                : e((string)($r['name'] ?? '游客'))) . '</td>' .
            '<td class="nowrap"><span class="badge' . $cls . '">' . e(log_label($act)) . '</span></td>' .
            '<td class="log-detail">' . e((string)($r['detail'] ?? '')) . '</td>' .
            '<td class="muted nowrap">' . e((string)($r['ip'] ?? '')) . '</td>' .
            '</tr>';
    }
    echo '</tbody></table></div>';
    echo paginate($total, $per, $page, 'p=admin&tab=logs&date=' . urlencode($date) . '&quser=' . urlencode($qUser) . '&qaction=' . urlencode($qAction));
    echo '</div>';
}

/* ---------------- 更新升级 ---------------- */
function admin_tab_update(): void
{
    $cur = app_version();
    $his = update_history();
    echo '<div class="card form-card"><h2 class="card-title">当前版本</h2><div class="stat-row">' .
        '<div class="stat"><b>v' . e($cur) . '</b><span>当前版本</span></div>' .
        '<div class="stat"><b>v' . e(MF_VERSION) . '</b><span>程序内置版本</span></div>' .
        '<div class="stat"><b>' . count($his) . '</b><span>已安装更新包</span></div>' .
        '</div>' .
        '<p class="hint">「程序内置版本」表示当前部署的代码版本；「当前版本」会随更新包安装自动前进。若通过更新包升级，二者可以不同（以「当前版本」为准，数据不会丢失）。</p></div>';

    echo '<div class="card form-card"><h2 class="card-title">上传更新包</h2>' .
        '<form method="post" action="' . e(u('a=admin_update')) . '" enctype="multipart/form-data" data-confirm="确认安装该更新包？安装前会自动备份当前程序文件。">' . csrf_field() .
        '<label class="field"><span class="field-l">更新包（.zip，由开发者提供，内含 update.json）</span><input class="input" type="file" name="pkg" accept=".zip" required></label>' .
        '<div class="form-foot"><span></span><button class="btn btn-primary" type="submit">上传并安装更新</button></div></form>' .
        '<p class="hint">安装流程：① 自动备份当前程序文件到 data/backup/ → ② 覆盖变更文件 → ③ 执行数据迁移脚本 → ④ 版本号前进。<b>data/ 数据目录永远不会被更新包改动</b>；完成后刷新页面即可，<b>无需重新安装、数据不丢</b>。若 php.ini 限制了上传大小，请调大 upload_max_filesize / post_max_size。</p></div>';

    echo '<div class="card form-card"><h2 class="card-title">更新历史</h2><div class="admin-list">';
    if (!$his) {
        echo empty_state('还没有安装过更新包（完整安装包部署的站点从当前版本直接开始）');
    }
    foreach (array_reverse($his) as $h) {
        echo '<div class="card queue-item"><div class="notice-head"><b>v' . e((string)($h['from'] ?? '?')) . ' → v' . e((string)($h['version'] ?? '?')) . '</b>' .
            '<span class="muted">' . e(fmt_dt((int)($h['time'] ?? 0))) . ' · ' . (int)($h['files'] ?? 0) . ' 个文件</span></div>' .
            ((string)($h['notes'] ?? '') !== '' ? '<p class="notice-body" style="white-space:pre-wrap">' . e((string)$h['notes']) . '</p>' : '') .
            '</div>';
    }
    echo '</div></div>';
}

/* ================= 安全防护（防火墙） ================= */

/** 安全防护总览（防火墙 / 限流 / 策略 / 危险 IP 库 / 访问统计 / 封禁 / 事件日志） */
function admin_tab_security(): void
{
    // 访问统计已随每次计数原子落盘（fw_state_save），直接读 fw_state.php 即为最新数据
    $on = (int)cfg('fw_on', 1) === 1;
    $st = Store::read('fw_state.php', []);
    if (!is_array($st)) {
        $st = [];
    }
    $days = is_array($st['days'] ?? null) ? $st['days'] : [];
    $today = $days[date('Y-m-d')] ?? ['req' => 0, 'blocked' => 0, 'bans' => 0];
    $yKey = date('Y-m-d', time() - 86400);
    $yday = $days[$yKey] ?? ['req' => 0, 'blocked' => 0, 'bans' => 0];
    $bans = function_exists('fw_bans_all') ? fw_bans_all() : [];
    $intel = function_exists('fw_intel_load') ? fw_intel_load() : ['synced' => 0, 'total' => 0, 'sources' => []];
    $rlOn = (int)cfg('fw_rl_on', 1) === 1;
    $scOn = (int)cfg('fw_score_on', 1) === 1;

    /* ---- 总览 ---- */
    echo '<div class="card form-card"><h2 class="card-title">防护总览</h2><div class="stat-row">' .
        '<div class="stat"><b>' . (int)($today['req'] ?? 0) . '</b><span>今日请求</span></div>' .
        '<div class="stat"><b style="color:var(--danger)">' . (int)($today['blocked'] ?? 0) . '</b><span>今日拦截</span></div>' .
        '<div class="stat"><b>' . (int)($today['bans'] ?? 0) . '</b><span>今日新增封禁</span></div>' .
        '<div class="stat"><b>' . (int)($yday['req'] ?? 0) . '</b><span>昨日请求</span></div>' .
        '<div class="stat"><b>' . count($bans) . '</b><span>封禁名单</span></div>' .
        '<div class="stat"><b>' . (int)$intel['total'] . '</b><span>危险库条数</span></div>' .
        '</div>' .
        '<form method="post" action="' . e(u('a=admin_fw_save')) . '" class="inline-form">' . csrf_field() .
        '<label class="field" style="max-width:260px"><span class="field-l">防火墙总开关</span><select name="fw_on" class="input">' .
        '<option value="1"' . ($on ? ' selected' : '') . '>开启（推荐）</option>' .
        '<option value="0"' . (!$on ? ' selected' : '') . '>关闭（仅统计不拦截）</option></select></label>' .
        '<button class="btn btn-primary" type="submit">保存总开关</button></form>' .
        '<p class="hint">五道防线依次执行：白名单 → 封禁名单 → 危险 IP 库 → 限流 → 自动策略。已登录的管理员不受拦截；危险 IP 库数据来自公开威胁情报源，误判时可把对方 IP 加入白名单。</p></div>';

    /* ---- 防护设置（限流 / 策略 / 白名单 / 代理） ---- */
    echo '<div class="card form-card"><h2 class="card-title">限流与自动策略</h2>' .
        '<form method="post" action="' . e(u('a=admin_fw_save')) . '">' . csrf_field();
    echo '<div class="grid2">';
    echo '<label class="field"><span class="field-l">访问限流</span><select name="fw_rl_on" class="input">' .
        '<option value="1"' . ($rlOn ? ' selected' : '') . '>开启</option><option value="0"' . (!$rlOn ? ' selected' : '') . '>关闭</option></select></label>';
    echo '<label class="field"><span class="field-l">每 IP 每分钟请求上限</span><input class="input" type="number" name="fw_rl_pm" min="5" max="10000" value="' . (int)cfg('fw_rl_pm', 60) . '"><span class="hint">超限返回 429；登录管理员不受限</span></label>';
    $rlBan = (int)cfg('fw_rl_ban_min', 0);
    echo '<label class="field"><span class="field-l">超限自动临时封禁（分钟，0=只限流不封）</span><input class="input" type="number" name="fw_rl_ban_min" min="0" max="1440" value="' . $rlBan . '"></label>';
    echo '<label class="field"><span class="field-l">自动策略引擎</span><select name="fw_score_on" class="input">' .
        '<option value="1"' . ($scOn ? ' selected' : '') . '>开启</option><option value="0"' . (!$scOn ? ' selected' : '') . '>关闭</option></select></label>';
    echo '<label class="field"><span class="field-l">自动封禁风险阈值</span><input class="input" type="number" name="fw_score_threshold" min="20" max="10000" value="' . (int)cfg('fw_score_threshold', 100) . '"><span class="hint">10 分钟窗口内风险分累计达到阈值即自动封禁</span></label>';
    echo '<label class="field"><span class="field-l">自动封禁时长（小时）</span><input class="input" type="number" name="fw_auto_ban_hours" min="1" max="720" value="' . (int)cfg('fw_auto_ban_hours', 24) . '"></label>';
    echo '</div>';
    echo '<div class="field"><span class="field-l">内置策略规则（可单独开关）</span><div class="check-grid">';
    $rules = [
        'fw_r_empty_ua'  => ['空 UA 请求（+40 分）', 1],
        'fw_r_script_ua' => ['脚本 / 攻击工具 UA（+50 分）', 1],
        'fw_r_scan_path' => ['敏感路径探测 wp-admin/.env 等（+80 分）', 1],
        'fw_r_inject'    => ['SQL 注入 / XSS / 目录穿越特征（+90 分）', 1],
        'fw_spider_allow' => ['放行搜索引擎蜘蛛 UA（不误伤收录）', 1],
    ];
    foreach ($rules as $k => $v) {
        $val = (int)cfg($k, $v[1]) === 1;
        echo '<label class="check"><input type="checkbox" name="' . $k . '" value="1"' . ($val ? ' checked' : '') . '> ' . e($v[0]) . '</label>';
    }
    echo '</div></div>';
    echo '<label class="field"><span class="field-l">IP 白名单（逗号或换行分隔，支持网段 1.2.3.0/24；命中后跳过一切拦截）</span>' .
        '<textarea class="input" name="fw_whitelist" rows="2" placeholder="如：203.0.113.7, 198.51.100.0/24">' . e((string)cfg('fw_whitelist', '')) . '</textarea>' .
        '<span class="hint">你当前的 IP：<b>' . e(fw_ip()) . '</b>（建议加入白名单，避免自己被误拦）</span></label>';

    /* ---- 真实 IP 识别（CDN / 反代适配，v1.8.1） ---- */
    $link = fw_link_info();
    $modeMap = [
        'cloudflare' => ['Cloudflare CDN', 'badge-ok'],
        'cf-proxy'   => ['Cloudflare → 本机反代', 'badge-ok'],
        'xff'        => ['经代理（XFF）', 'badge-warn'],
        'direct'     => ['直连', 'badge-ok'],
    ];
    [$modeName, $modeBadge] = $modeMap[$link['mode']] ?? [$link['mode'], ''];
    echo '<div class="field"><span class="field-l">真实 IP 识别（当前请求链路诊断）</span>' .
        '<div class="admin-list" style="margin:6px 0 4px">' .
        '<div class="admin-row"><div class="u-info"><b>' . e($modeName) . '</b>' .
        '<span class="muted">REMOTE_ADDR（TCP 对端）=' . e($link['remote'] !== '' ? $link['remote'] : '—') .
        ($link['cfip'] !== '' ? '；CF-Connecting-IP=' . e($link['cfip']) : '') .
        ($link['xff'] !== '' ? '；X-Forwarded-For=' . e(cut_str($link['xff'], 80)) : '') . '</span></div>' .
        '<span class="badge ' . $modeBadge . '">识别 IP：' . e($link['resolved'] !== '' ? $link['resolved'] : '未知') . '</span></div>' .
        '</div></div>';
    echo '<label class="check"><input type="checkbox" name="fw_trust_cf" value="1"' . ((int)cfg('fw_trust_cf', 1) === 1 ? ' checked' : '') . '> Cloudflare CDN 适配（推荐开启：自动识别 Cloudflare 官方网段，从 CF-Connecting-IP 取真实访客 IP）</label>' .
        '<span class="hint">开启后，仅当请求的 TCP 对端确属 Cloudflare 官方网段（v4 + IPv6 共 22 条，内置）才信任 CF-Connecting-IP 头，直连伪造无效——限流、封禁、访问统计、IP 归属地全部基于真实 IP，避免「同节点访客被集体限流 / 自动封禁误封 Cloudflare 节点导致整站 403」。未使用 Cloudflare 时此开关无任何影响。</span>' .
        '<label class="check" style="margin-top:8px"><input type="checkbox" name="fw_trust_xff" value="1"' . ((int)cfg('fw_trust_xff', 0) === 1 ? ' checked' : '') . '> 站点在反向代理 / 其他 CDN 之后（信任 X-Forwarded-For）</label>' .
        '<span class="hint">宝塔反代、其他不带真实 IP 头的 CDN 等场景开启（从 X-Forwarded-For 首段取 IP）；套在 Cloudflare 之前时会自动优先用更可信的 CF-Connecting-IP。直接暴露的服务器请勿开启，否则可被伪造头绕过限流。</span>' .
        '<label class="field" style="margin-top:8px"><span class="field-l">Cloudflare 网段覆盖（可选，留空 = 使用程序内置 CF 官方网段）</span>' .
        '<textarea class="input" name="fw_cf_ranges" rows="2" placeholder="如 Cloudflare 官方调整网段，可在此填入：104.16.0.0/13, 2606:4700::/32">' . e((string)cfg('fw_cf_ranges', '')) . '</textarea>' .
        '<span class="hint">逗号 / 空白分隔，支持 IPv4 与 IPv6 CIDR；仅当 CF 官方发布新网段而程序未跟进时才需要填写。</span></label>';
    echo '<details style="margin-top:6px"><summary class="muted">Cloudflare 接入推荐配置（点开查看）</summary>' .
        '<div class="hint" style="margin-top:8px;line-height:1.9">' .
        '① <b>SSL/TLS 模式</b>：Cloudflare 面板设为「完全（严格）」，源站配有效证书，全链路 HTTPS；<br>' .
        '② <b>缓存</b>：论坛页面带登录态，请勿添加「缓存 HTML」的规则（默认只缓存 css/js/图片，保持默认即可）；<br>' .
        '③ <b>Rocket Loader / Email 混淆</b>：建议关闭（可能干扰发帖表单与邮件显示）；<br>' .
        '④ <b>速率限制 / WAF</b>：可按需在 CF 面板加一层，但建议阈值放宽，程序内置防火墙已按真实 IP 限流；<br>' .
        '⑤ <b>「我正在被攻击」模式</b>：开启后人机验证可能拦截发帖与登录，仅在遭遇攻击时短期使用；<br>' .
        '⑥ 本程序无需任何 CF 侧配置即可取到真实 IP——装上本版本即自动生效，后台上方诊断卡可随时核验。</div></details>';
    echo '<div class="form-foot"><span></span><button class="btn btn-primary" type="submit">保存防护设置</button></div></form></div>';

    /* ---- 危险 IP 库 ---- */
    $synced = (int)$intel['synced'];
    $stale = $synced > 0 && $synced < time() - max(1, (int)cfg('fw_intel_hours', 24)) * 3600;
    echo '<div class="card form-card"><h2 class="card-title">危险 IP 库（威胁情报）</h2>' .
        '<div class="mon-grid">' .
        '<div class="mon-cell' . ((int)$intel['total'] > 0 ? '' : ' cell-bad') . '"><b>' . (int)$intel['total'] . '</b><span>已入库条数</span></div>' .
        '<div class="mon-cell"><b>' . ($synced > 0 ? e(fmt_dt($synced)) : '未同步') . '</b><span>上次同步' . ($stale ? '（已过期，建议同步）' : '') . '</span></div>' .
        '<div class="mon-cell"><b>每 ' . (int)cfg('fw_intel_hours', 24) . ' 小时</b><span>自动同步间隔（打开本页时惰性触发）</span></div>' .
        '</div>';
    // 各源状态
    $sources = is_array($intel['sources'] ?? null) ? $intel['sources'] : [];
    if ($sources) {
        echo '<div class="admin-list">';
        foreach ($sources as $s) {
            $ok = !empty($s['ok']) && (int)($s['count'] ?? 0) >= 0 && (string)($s['err'] ?? '') === '';
            echo '<div class="admin-row"><div class="u-info"><b>' . e((string)($s['name'] ?? '源')) . '</b>' .
                '<span class="muted">' . (!empty($s['ok']) ? ((string)($s['err'] ?? '') !== '' ? e($s['err']) : '成功 · ' . (int)($s['count'] ?? 0) . ' 条') : '失败：' . e((string)($s['err'] ?? '未知'))) . '</span></div>' .
                '<span class="badge' . (!empty($s['ok']) ? ' badge-ok' : ' badge-warn') . '">' . (!empty($s['ok']) ? '正常' : '异常') . '</span></div>';
        }
        echo '</div>';
    }
    echo '<form method="post" action="' . e(u('a=admin_fw_intel_save')) . '">' . csrf_field() . '<div class="grid2">';
    echo '<label class="field"><span class="field-l">危险 IP 库拦截</span><select name="fw_intel_on" class="input">' .
        '<option value="1"' . ((int)cfg('fw_intel_on', 1) === 1 ? ' selected' : '') . '>开启</option><option value="0"' . ((int)cfg('fw_intel_on', 1) !== 1 ? ' selected' : '') . '>关闭</option></select></label>';
    echo '<label class="field"><span class="field-l">自动同步间隔（小时）</span><input class="input" type="number" name="fw_intel_hours" min="1" max="168" value="' . (int)cfg('fw_intel_hours', 24) . '"></label>';
    foreach (['et' => 1, 'feodo' => 1, 'blackbook' => 0] as $sk => $def) {
        echo '<label class="check"><input type="checkbox" name="fw_src_' . $sk . '" value="1"' . ((int)cfg('fw_src_' . $sk, $def) === 1 ? ' checked' : '') . '> ' . e(FW_INTEL_SOURCES[$sk]['name']) . '</label>';
    }
    echo '</div>' .
        '<label class="field"><span class="field-l">自定义威胁情报源（每行一个 URL，最多 5 条，返回 IP/CIDR 纯文本列表）</span>' .
        '<textarea class="input" name="fw_intel_custom" rows="2" placeholder="https://...">' . e((string)cfg('fw_intel_custom', '')) . '</textarea></label>' .
        '<div class="form-foot"><span></span><button class="btn btn-primary" type="submit">保存库设置</button></div></form>';
    echo '<form method="post" action="' . e(u('a=admin_fw_intel_sync')) . '" class="inline" data-confirm="立即从公开威胁情报源同步危险 IP 库？">' . csrf_field() .
        '<button class="btn btn-ghost" type="submit">立即同步</button></form> ';
    echo '<details style="margin-top:10px"><summary class="muted">手动导入黑名单（粘贴或上传 txt）</summary>' .
        '<form method="post" action="' . e(u('a=admin_fw_intel_import')) . '" enctype="multipart/form-data" style="margin-top:8px">' . csrf_field() .
        '<label class="field"><span class="field-l">黑名单文本（每行一个 IP / CIDR / IP 区间，# 开头为注释，最多 5 万行）</span>' .
        '<textarea class="input" name="list" rows="4" placeholder="1.2.3.4&#10;5.6.7.0/24&#10;8.8.8.8-8.8.8.16"></textarea></label>' .
        '<label class="field"><span class="field-l">或上传 .txt 文件（≤ 2MB）</span><input class="input" type="file" name="file" accept=".txt,.csv,text/plain"></label>' .
        '<div class="form-foot"><span class="muted">导入与现有库合并，不清空</span><button class="btn btn-ghost" type="submit">导入</button></div></form></details>';
    echo '<p class="hint">内置种子：库为空时会启用少量公开的常见恶意网段种子兜底；完整库依赖同步（主机需能访问外网，cURL 或 file_get_contents 任一可用即可）。</p></div>';

    /* ---- 访问统计 ---- */
    $ips = is_array($st['ips'] ?? null) ? $st['ips'] : [];
    $sort = (string)($_GET['ssort'] ?? 'time') === 'req' ? 'req' : 'time';
    uasort($ips, function ($a, $b) use ($sort) {
        if ($sort === 'req') {
            return (int)($b['c'] ?? 0) <=> (int)($a['c'] ?? 0) ?: (int)($b['l'] ?? 0) <=> (int)($a['l'] ?? 0);
        }
        return (int)($b['l'] ?? 0) <=> (int)($a['l'] ?? 0) ?: (int)($b['c'] ?? 0) <=> (int)($a['c'] ?? 0);
    });
    $ipPage = max(1, get_int('ippage', 1));
    $ipPer = 50;
    $ipTotal = count($ips);
    $ipRows = array_slice($ips, ($ipPage - 1) * $ipPer, $ipPer, true);
    echo '<div class="card form-card"><div class="list-head"><h2 class="card-title">访问统计（每个 IP 的请求次数与归属地）</h2>' .
        '<span class="muted">共 ' . $ipTotal . ' 个活跃 IP · ' .
        '<a class="fw-link' . ($sort === 'time' ? ' on' : '') . '" href="' . e(u('p=admin&tab=security&ssort=time')) . '">最近活跃</a> · ' .
        '<a class="fw-link' . ($sort === 'req' ? ' on' : '') . '" href="' . e(u('p=admin&tab=security&ssort=req')) . '">请求最多</a></span></div>';
    echo '<div class="table-wrap"><table class="log-table"><thead><tr>' .
        '<th>IP</th><th>请求数</th><th>404</th><th>最近活动</th><th>UA / 归属地</th><th>状态</th><th>操作</th>' .
        '</tr></thead><tbody>';
    if (!$ipRows) {
        echo '<tr><td colspan="7">' . empty_state('暂无访问数据（随访问自动统计）') . '</td></tr>';
    }
    foreach ($ipRows as $ip => $r) {
        $banned = fw_is_banned((string)$ip);
        $wl = !empty($r['wl']) || fw_whitelisted((string)$ip);
        $geo = fw_geo_get((string)$ip);
        $state = $banned ? '<span class="badge badge-warn">封禁中</span>'
            : ($wl ? '<span class="badge badge-accent">白名单</span>'
                : '<span class="badge badge-ok">正常</span>');
        $banLeft = $banned ? ((int)($banned['until'] ?? 0) > 0 ? '至 ' . date('m-d H:i', (int)$banned['until']) : '永久') : '';
        echo '<tr>' .
            '<td class="nowrap"><b>' . e((string)$ip) . '</b></td>' .
            '<td>' . (int)($r['c'] ?? 0) . '</td>' .
            '<td>' . ((int)($r['f'] ?? 0) > 0 ? '<span class="muted" style="color:var(--warn)">' . (int)$r['f'] . '</span>' : '0') . '</td>' .
            '<td class="nowrap muted">' . ((int)($r['l'] ?? 0) > 0 ? e(fmt_time((int)$r['l'])) : '-') . '</td>' .
            '<td class="log-detail">' . e((string)($r['ua'] ?? '')) . '<br><span class="muted" data-fw-geo-for="' . e((string)$ip) . '">' . ($geo ? e(fw_geo_label($geo)) : '归属地未查询') . '</span></td>' .
            '<td class="nowrap">' . $state . ($banned ? '<br><span class="muted">' . e($banLeft) . '</span>' : '') . '</td>' .
            '<td class="nowrap">';
        if ($banned) {
            echo '<form method="post" action="' . e(u('a=admin_fw_unban')) . '" class="inline">' . csrf_field() .
                '<input type="hidden" name="ip" value="' . e((string)$ip) . '">' .
                '<button class="btn btn-ghost btn-sm" type="submit">解封</button></form>';
        } else {
            echo '<form method="post" action="' . e(u('a=admin_fw_ban')) . '" class="inline" data-confirm="封禁 IP ' . e((string)$ip) . ' 24 小时？">' . csrf_field() .
                '<input type="hidden" name="ip" value="' . e((string)$ip) . '"><input type="hidden" name="dur" value="1440"><input type="hidden" name="reason" value="后台手动封禁">' .
                '<button class="btn btn-ghost btn-sm danger" type="submit">封禁24h</button></form>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<button class="btn btn-ghost btn-sm" type="button" data-fw-geo>查询本页归属地（免key接口，未查询到的才会请求）</button> ';
    echo paginate($ipTotal, $ipPer, $ipPage, 'p=admin&tab=security&ssort=' . $sort);
    echo '<p class="hint">统计随访问自动聚合（每 60 秒落盘一次）；7 天不活跃的 IP 自动移除统计。归属地通过 ip-api.com 免费接口批量查询并缓存 30 天。</p></div>';

    /* ---- 封禁管理 ---- */
    echo '<div class="card form-card"><h2 class="card-title">手动封禁</h2>' .
        '<form method="post" action="' . e(u('a=admin_fw_ban')) . '" class="inline-form">' . csrf_field() .
        '<label class="field"><span class="field-l">IP 或网段</span><input class="input" name="ip" required placeholder="如 1.2.3.4 或 5.6.7.0/24" style="max-width:220px"></label>' .
        '<label class="field"><span class="field-l">时长</span><select name="dur" class="input" style="max-width:150px">';
    foreach ([30 => '30 分钟', 60 => '1 小时', 360 => '6 小时', 1440 => '24 小时', 10080 => '7 天', 43200 => '30 天', 0 => '永久'] as $m => $lbl) {
        echo '<option value="' . $m . '"' . ($m === 1440 ? ' selected' : '') . '>' . e($lbl) . '</option>';
    }
    echo '</select></label>' .
        '<label class="field"><span class="field-l">理由（记录用）</span><input class="input" name="reason" maxlength="100" placeholder="如：恶意刷帖" style="max-width:260px"></label>' .
        '<button class="btn btn-danger" type="submit">立即封禁</button></form>';
    echo '<div class="admin-list" style="margin-top:10px">';
    if (!$bans) {
        echo empty_state('当前没有封禁记录');
    }
    foreach ($bans as $key => $b) {
        $until = (int)($b['until'] ?? 0);
        $expired = $until > 0 && $until < time();
        echo '<div class="admin-row"><div class="u-info"><b>' . e((string)$key) . '</b>' .
            '<span class="muted">' . e((string)($b['kind'] ?? 'manual') === 'auto' ? '自动' : '手动') . ' · ' . e((string)($b['by'] ?? '')) . ' · ' . e(fmt_dt((int)($b['time'] ?? 0))) . ' · ' . e((string)($b['reason'] ?? '')) . '</span></div>' .
            '<span class="row-ops">' .
            ($expired ? '<span class="badge">已过期</span>' : '<span class="badge badge-warn">' . ($until > 0 ? e(fmt_dt($until)) . ' 解除' : '永久') . '</span>') .
            '<form method="post" action="' . e(u('a=admin_fw_unban')) . '" class="inline">' . csrf_field() .
            '<input type="hidden" name="ip" value="' . e((string)$key) . '">' .
            '<button class="btn btn-ghost btn-sm" type="submit">移除</button></form></span></div>';
    }
    echo '</div></div>';

    /* ---- 自定义规则 ---- */
    $rulesAll = fw_rules_all();
    echo '<div class="card form-card"><h2 class="card-title">自定义封禁规则（内置策略机器人之外的补充）</h2>' .
        '<form method="post" action="' . e(u('a=admin_fw_rule_add')) . '" class="inline-form">' . csrf_field() .
        '<label class="field"><span class="field-l">规则名</span><input class="input" name="name" required maxlength="30" placeholder="如：拦截恶意爬虫" style="max-width:180px"></label>' .
        '<label class="field"><span class="field-l">匹配对象</span><select name="type" class="input" style="max-width:130px"><option value="ua">User-Agent</option><option value="uri">请求路径</option><option value="query">查询参数</option></select></label>' .
        '<label class="field"><span class="field-l">匹配方式</span><select name="mode" class="input" style="max-width:120px"><option value="text">包含文本</option><option value="regex">正则</option></select></label>' .
        '<label class="field"><span class="field-l">匹配内容</span><input class="input" name="pattern" required maxlength="120" placeholder="如 scrapy 或 (bot|spider)" style="max-width:220px"></label>' .
        '<label class="field"><span class="field-l">命中动作</span><select name="action" class="input" style="max-width:150px"><option value="score">加分（计入风险分）</option><option value="ban">直接封禁</option></select></label>' .
        '<label class="field"><span class="field-l">分值 / 封禁时长(h)</span><input class="input" type="number" name="val" min="1" max="720" value="50" style="max-width:110px"></label>' .
        '<button class="btn btn-primary" type="submit">添加规则</button></form>';
    echo '<div class="admin-list" style="margin-top:10px">';
    if (!$rulesAll) {
        echo empty_state('还没有自定义规则（内置四条策略已默认启用，见上方「限流与自动策略」）');
    }
    foreach ($rulesAll as $r) {
        echo '<div class="admin-row"><div class="u-info"><b>' . e((string)($r['name'] ?? '')) . '</b>' .
            '<span class="muted">' . e(strtoupper((string)($r['type'] ?? ''))) . ' · ' . (($r['mode'] ?? '') === 'regex' ? '正则' : '包含') . '「' . e((string)($r['pattern'] ?? '')) . '」 · ' .
            (($r['action'] ?? '') === 'ban' ? '直接封禁 ' . (int)($r['ban_hours'] ?? 24) . 'h' : '+' . (int)($r['score'] ?? 0) . ' 分') . '</span></div>' .
            '<span class="row-ops">' .
            '<form method="post" action="' . e(u('a=admin_fw_rule_toggle')) . '" class="inline">' . csrf_field() .
            '<input type="hidden" name="id" value="' . e((string)($r['id'] ?? '')) . '">' .
            '<button class="btn btn-ghost btn-sm" type="submit">' . (!empty($r['on']) ? '停用' : '启用') . '</button></form>' .
            '<form method="post" action="' . e(u('a=admin_fw_rule_del')) . '" class="inline" data-confirm="删除该规则？">' . csrf_field() .
            '<input type="hidden" name="id" value="' . e((string)($r['id'] ?? '')) . '">' .
            '<button class="btn btn-ghost btn-sm danger" type="submit">删除</button></form></span></div>';
    }
    echo '</div></div>';

    /* ---- 防火墙事件日志 ---- */
    $files = fw_event_files();
    $date = (string)($_GET['fwdate'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = $files ? substr($files[0], 3, 10) : date('Y-m-d');
    }
    $qip = trim((string)($_GET['fwq'] ?? ''));
    $fwPage = max(1, get_int('fwpage', 1));
    $fwTotal = 0;
    $rows = fw_events_read($date, 50, $fwPage, $qip, $fwTotal);
    echo '<div class="card form-card"><div class="list-head"><h2 class="card-title">防火墙日志（拦截 / 封禁 / 评分事件）</h2>' .
        '<span class="muted">每天至多 2000 条，保留 ' . (int)cfg('fw_log_keep', 14) . ' 天</span></div>' .
        '<form method="get" action="' . e(u('')) . '" class="inline-form">' .
        '<input type="hidden" name="p" value="admin"><input type="hidden" name="tab" value="security">' .
        '<select name="fwdate" class="input input-sm">';
    $opts = [$date => true, date('Y-m-d') => true];
    foreach ($files as $f) {
        $opts[substr($f, 3, 10)] = true;
    }
    foreach (array_keys($opts) as $d) {
        echo '<option value="' . e($d) . '"' . ($d === $date ? ' selected' : '') . '>' . e($d) . '</option>';
    }
    echo '</select>' .
        '<input class="input input-sm" name="fwq" value="' . e($qip) . '" placeholder="按 IP 筛选">' .
        '<button class="btn btn-ghost btn-sm" type="submit">筛选</button> ' .
        '<a class="btn btn-ghost btn-sm" href="' . e(u('p=admin&tab=security')) . '">重置</a></form>';
    echo '<form method="post" action="' . e(u('a=admin_fw_log_clear')) . '" class="inline" data-confirm="清空 ' . e($date) . ' 的防火墙日志？">' . csrf_field() .
        '<input type="hidden" name="date" value="' . e($date) . '">' .
        '<button class="btn btn-ghost btn-sm danger" type="submit">清空该日日志</button></form>';
    echo '<div class="table-wrap"><table class="log-table"><thead><tr><th>时间</th><th>IP</th><th>事件</th><th>规则 / 原因</th><th>请求</th><th>UA</th></tr></thead><tbody>';
    if (!$rows) {
        echo '<tr><td colspan="6">' . empty_state('该日期暂无防火墙事件') . '</td></tr>';
    }
    $actNames = [
        'block' => '拦截·封禁名单', 'intel_block' => '拦截·危险IP库', 'ratelimit' => '限流·429',
        'score' => '策略·风险分', 'auto_ban' => '策略·自动封禁',
    ];
    foreach ($rows as $r) {
        $act = (string)($r['act'] ?? '');
        $cls = $act === 'auto_ban' ? ' badge-warn' : ($act === 'score' ? ' badge-accent' : '');
        echo '<tr>' .
            '<td class="nowrap">' . date('H:i:s', (int)($r['t'] ?? 0)) . '</td>' .
            '<td class="nowrap"><b>' . e((string)($r['ip'] ?? '')) . '</b></td>' .
            '<td class="nowrap"><span class="badge' . $cls . '">' . e($actNames[$act] ?? $act) . '</span></td>' .
            '<td class="log-detail">' . e((string)($r['rule'] ?? '')) . (isset($r['detail']) && $r['detail'] !== '' ? '<br><span class="muted">' . e((string)$r['detail']) . '</span>' : '') .
                ((int)($r['score'] ?? 0) > 0 ? ' <span class="muted">+' . (int)$r['score'] . '</span>' : '') . '</td>' .
            '<td class="log-detail">' . e((string)($r['m'] ?? '')) . ' ' . e((string)($r['uri'] ?? '')) . '</td>' .
            '<td class="log-detail muted">' . e((string)($r['ua'] ?? '')) . '</td>' .
            '</tr>';
    }
    echo '</tbody></table></div>';
    echo paginate($fwTotal, 50, $fwPage, 'p=admin&tab=security&fwdate=' . urlencode($date) . '&fwq=' . urlencode($qip));
    echo '</div>';
}
