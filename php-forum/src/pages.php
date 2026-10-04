<?php
/**
 * 极简论坛 · 公开页面
 */
defined('APP') or exit('Forbidden');

/* ---------------- 首页 ---------------- */
function page_home(): void
{
    $tab = (($_GET['tab'] ?? '') === 'reply') ? 'reply' : 'new';
    $page = max(1, get_int('page', 1));
    $per = max(5, (int)cfg('per_page', 20));
    $u = current_user();

    layout_header('', 0);

    if (!$u) {
        echo '<div class="hero card"><div class="hero-txt"><b>欢迎来到' . e((string)cfg('site_name', 'Cube Minimalist Forum')) . '</b>' .
            '<p>' . e((string)cfg('site_desc', '')) . '</p></div>' .
            '<div class="hero-act"><a class="btn btn-primary" href="' . e(u('p=register')) . '">注册账号</a>' .
            '<a class="btn btn-ghost" href="' . e(u('p=login')) . '">登录</a></div></div>';
    }

    echo '<div class="list-head"><div class="tabs" role="tablist">' .
        '<a class="tab' . ($tab === 'new' ? ' on' : '') . '" href="' . e(u('p=home&tab=new')) . '">最新帖子</a>' .
        '<a class="tab' . ($tab === 'reply' ? ' on' : '') . '" href="' . e(u('p=home&tab=reply')) . '">最新回复</a>' .
        '</div>' .
        ($u ? '<a class="btn btn-primary btn-sm" href="' . e(u('p=new')) . '">+ 发帖</a>' : '') .
        '</div>';

    $all = threads_home($tab);
    $total = count($all);
    $rows = array_slice($all, ($page - 1) * $per, $per);

    echo '<div class="thread-list">';
    if (!$rows) {
        echo empty_state('还没有帖子，快来发第一帖吧');
    }
    foreach ($rows as $t) {
        echo thread_card($t, true);
    }
    echo '</div>';
    echo paginate($total, $per, $page, 'p=home&tab=' . $tab);
    layout_footer();
}

/* ---------------- 板块页 ---------------- */
function page_board(): void
{
    $bid = get_int('id', 0);
    $b = board_get($bid);
    if (!$b) {
        page_404();
        return;
    }
    $page = max(1, get_int('page', 1));
    $per = max(5, (int)cfg('per_page', 20));
    $u = current_user();

    layout_header((string)$b['name'], $bid);

    $act = $u
        ? '<a class="btn btn-primary btn-sm" href="' . e(u('p=new&id=' . $bid)) . '">+ 发帖</a>'
        : '';
    echo page_head((string)$b['name'], (string)$b['desc'], $act);

    $all = threads_by_board($bid);
    $total = count($all);
    $rows = array_slice($all, ($page - 1) * $per, $per);

    echo '<div class="thread-list">';
    if (!$rows) {
        echo empty_state('该板块还没有帖子');
    }
    foreach ($rows as $t) {
        echo thread_card($t, false);
    }
    echo '</div>';
    echo paginate($total, $per, $page, 'p=board&id=' . $bid);
    layout_footer();
}

/* ---------------- 帖子页 ---------------- */
function page_thread(): void
{
    $tid = get_int('id', 0);
    $t = thread_get($tid);
    if (!$t) {
        page_404();
        return;
    }
    $u = current_user();
    $uid = $u ? (int)$u['id'] : 0;
    $admin = is_admin();
    $author = (int)$t['author'];
    $hidden = !empty($t['hidden']);

    layout_header((string)$t['title'], (int)$t['board']);
    echo '<nav class="crumb"><a href="' . e(u('p=board&id=' . (int)$t['board'])) . '">' . e(board_name((int)$t['board'])) . '</a><span>/</span>正文</nav>';

    if ($hidden && !$admin) {
        echo '<div class="card notice-card"><b>该内容正在审核中</b><p>此帖子被举报后已暂时隐藏，等待审核结果。审核完成后将自动恢复展示或通知作者申诉。</p></div>';
        layout_footer();
        return;
    }

    echo '<article class="card thread-art">';
    echo '<div class="art-head"><h1 class="art-title">' . e((string)$t['title']) . '</h1><div class="art-meta">' .
        '<a href="' . e(u('p=user&id=' . $author)) . '">' . e(uname($author)) . '</a>' .
        '<span>' . fmt_dt((int)$t['created']) . '</span>' .
        (!empty($t['pinned']) ? '<span class="badge badge-accent">置顶</span>' : '') .
        (!empty($t['locked']) ? '<span class="badge">锁定</span>' : '') .
        ($hidden ? '<span class="badge badge-warn">审核中</span>' : '') .
        (!empty($t['appealed']) ? '<span class="badge badge-info">已申诉</span>' : '') .
        '</div></div>';
    echo '<div class="md art-content">' . md_render((string)$t['content']) . '</div>';

    // 操作行
    echo '<div class="art-ops">';
    if ($u) {
        echo like_btn('t', $tid, 0, $t['likes'] ?? [], $uid);
        if (!$admin) {
            echo report_box('t', $tid, 0);
        }
        if ($author === $uid) {
            echo '<form method="post" action="' . e(u('a=thread_delete')) . '" class="inline" data-confirm="确认删除自己的这条帖子？此操作不可恢复。">' .
                '<input type="hidden" name="tid" value="' . $tid . '">' . csrf_field() . hidden_back() .
                '<button class="btn btn-ghost btn-sm danger" type="submit">删除</button></form>';
        }
    } else {
        echo '<span class="muted">登录后可点赞、举报、回复</span>';
    }
    echo '</div>';

    // 管理员操作
    if ($admin) {
        echo '<div class="admin-ops"><span class="muted">管理：</span>';
        echo '<form method="post" action="' . e(u('a=admin_thread_op')) . '" class="inline">' .
            '<input type="hidden" name="tid" value="' . $tid . '"><input type="hidden" name="act" value="' . (empty($t['locked']) ? 'lock' : 'unlock') . '">' .
            csrf_field() . hidden_back() .
            '<button class="btn btn-ghost btn-sm" type="submit">' . (empty($t['locked']) ? '锁定' : '解锁') . '</button></form>';
        echo '<form method="post" action="' . e(u('a=admin_thread_op')) . '" class="inline">' .
            '<input type="hidden" name="tid" value="' . $tid . '"><input type="hidden" name="act" value="' . (empty($t['pinned']) ? 'pin' : 'unpin') . '">' .
            csrf_field() . hidden_back() .
            '<button class="btn btn-ghost btn-sm" type="submit">' . (empty($t['pinned']) ? '置顶' : '取消置顶') . '</button></form>';
        echo '<form method="post" action="' . e(u('a=admin_thread_op')) . '" class="inline">' .
            '<input type="hidden" name="tid" value="' . $tid . '"><input type="hidden" name="act" value="move">' .
            '<select name="board" class="input input-sm" aria-label="移动到板块">';
        foreach (board_all() as $b) {
            echo '<option value="' . (int)$b['id'] . '"' . ((int)$t['board'] === (int)$b['id'] ? ' selected' : '') . '>' . e((string)$b['name']) . '</option>';
        }
        echo '</select>' . csrf_field() . hidden_back() .
            '<button class="btn btn-ghost btn-sm" type="submit">移版</button></form>';
        echo '<form method="post" action="' . e(u('a=admin_thread_op')) . '" class="inline" data-confirm="确认删除该帖子？将通知作者。">' .
            '<input type="hidden" name="tid" value="' . $tid . '"><input type="hidden" name="act" value="delete">' .
            csrf_field() . hidden_back() .
            '<button class="btn btn-ghost btn-sm danger" type="submit">删除</button></form>';
        echo '</div>';
    }
    echo '</article>';

    // 回复区
    $replies = reply_list($tid);
    echo '<div class="replies-head">回复（' . count($replies) . '）</div>';
    echo '<div class="reply-list">';
    if (!$replies) {
        echo empty_state('还没有回复');
    }
    foreach ($replies as $r) {
        $rid = (int)$r['id'];
        $rhidden = !empty($r['hidden']);
        echo '<div class="card reply-item" id="r' . $rid . '">';
        echo '<div class="reply-meta"><a href="' . e(u('p=user&id=' . (int)$r['author'])) . '">' . e(uname((int)$r['author'])) . '</a>' .
            '<span class="muted">' . fmt_time((int)$r['created']) . '</span>' .
            ($rhidden ? '<span class="badge badge-warn">审核中</span>' : '') .
            (!empty($r['appealed']) ? '<span class="badge badge-info">已申诉</span>' : '') .
            '<span class="muted">#' . $rid . '</span></div>';
        if ($rhidden && !$admin) {
            echo '<div class="md reply-content muted">该回复正在审核中，暂时无法查看。</div>';
        } else {
            echo '<div class="md reply-content">' . md_render((string)$r['content']) . '</div>';
        }
        echo '<div class="reply-ops">';
        if ($u && !$rhidden) {
            echo like_btn('r', $tid, $rid, $r['likes'] ?? [], $uid);
            if (!$admin) {
                echo report_box('r', $tid, $rid);
            }
            if ((int)$r['author'] === $uid) {
                echo '<form method="post" action="' . e(u('a=reply_delete')) . '" class="inline" data-confirm="确认删除自己的这条回复？">' .
                    '<input type="hidden" name="tid" value="' . $tid . '"><input type="hidden" name="rid" value="' . $rid . '">' .
                    csrf_field() . hidden_back() .
                    '<button class="btn btn-ghost btn-sm danger" type="submit">删除</button></form>';
            } elseif ($admin) {
                echo '<form method="post" action="' . e(u('a=admin_reply_delete')) . '" class="inline" data-confirm="确认删除该回复？将通知作者。">' .
                    '<input type="hidden" name="tid" value="' . $tid . '"><input type="hidden" name="rid" value="' . $rid . '">' .
                    csrf_field() . hidden_back() .
                    '<button class="btn btn-ghost btn-sm danger" type="submit">删除</button></form>';
            }
        }
        echo '</div></div>';
    }
    echo '</div>';

    // 回复表单
    if ($hidden && !$admin) {
        layout_footer();
        return;
    }
    echo '<div class="card reply-form-card">';
    if (!$u) {
        echo '<div class="login-cta">登录后即可回复 <a class="btn btn-primary btn-sm" href="' . e(u('p=login')) . '">登录</a> <a class="btn btn-ghost btn-sm" href="' . e(u('p=register')) . '">注册</a></div>';
    } elseif (!empty($t['locked'])) {
        echo '<div class="muted">帖子已被锁定，无法回复。</div>';
    } elseif (mute_left($u) > 0) {
        echo '<div class="muted">您已被禁言，剩余 ' . ceil(mute_left($u) / 60) . ' 分钟。</div>';
    } else {
        echo '<form method="post" action="' . e(u('a=reply_new')) . '">' .
            '<input type="hidden" name="tid" value="' . $tid . '">' .
            csrf_field() . hidden_back() .
            '<textarea class="input" name="content" rows="4" maxlength="1000" required placeholder="友善回复（支持 Markdown，最多 1000 字）"></textarea>' .
            '<div class="form-foot"><span class="muted">支持 Markdown</span>' .
            '<button class="btn btn-primary" type="submit">回复</button></div></form>';
    }
    echo '</div>';
    layout_footer();
}

/* ---------------- 发帖页 ---------------- */
function page_new(): void
{
    $u = require_login('p=new');
    if (mute_left($u) > 0) {
        flash('err', '您已被禁言，剩余 ' . ceil(mute_left($u) / 60) . ' 分钟');
        redirect(u('p=home'));
    }
    $pre = get_int('id', 0);
    layout_header('发布帖子', 0);
    echo page_head('发布帖子', '标题最多 30 字，正文最多 1000 字，支持 Markdown（图片可用 https 外链，无附件）');
    echo '<div class="card form-card"><form method="post" action="' . e(u('a=thread_new')) . '">' .
        csrf_field() . hidden_back() .
        '<label class="field"><span class="field-l">板块</span><select name="board" class="input" required>';
    foreach (board_all() as $b) {
        echo '<option value="' . (int)$b['id'] . '"' . ($pre === (int)$b['id'] ? ' selected' : '') . '>' . e((string)$b['name']) . '</option>';
    }
    echo '</select></label>' .
        '<label class="field"><span class="field-l">标题 <em class="cnt"><i id="t-count">0</i>/30</em></span>' .
        '<input class="input" name="title" id="title-input" maxlength="30" required data-counter="#t-count" placeholder="一句话说清主题"></label>' .
        '<label class="field"><span class="field-l">正文 <em class="cnt"><i id="c-count">0</i>/1000</em></span>' .
        '<textarea class="input" name="content" id="content-input" rows="10" maxlength="1000" required data-counter="#c-count" placeholder="支持 Markdown：# 标题、**加粗**、`代码`、- 列表、> 引用、| 表格 |、==高亮=="></textarea></label>' .
        '<div class="form-foot"><span class="muted">两次发帖间隔不低于 ' . (int)cfg('post_interval', 30) . ' 秒</span>' .
        '<button class="btn btn-primary" type="submit">发布</button></div></form></div>';
    layout_footer();
}

/* ---------------- 登录 ---------------- */
function page_login(): void
{
    layout_header('登录', 0);
    $next = (string)($_GET['next'] ?? '');
    echo '<div class="auth-wrap"><div class="card auth-card">';
    echo page_head('登录', '用户名或邮箱 + 密码');
    echo '<form method="post" action="' . e(u('a=login')) . '">' .
        csrf_field() .
        '<input type="hidden" name="next" value="' . e($next) . '">' .
        '<label class="field"><span class="field-l">用户名或邮箱</span><input class="input" name="id" required maxlength="60" value="' . old('id') . '" autocomplete="username"></label>' .
        '<label class="field"><span class="field-l">密码</span><input class="input" type="password" name="pass" required autocomplete="current-password"></label>' .
        '<label class="check"><input type="checkbox" name="remember" value="1"> 保持登录 30 天</label>' .
        '<button class="btn btn-primary btn-block" type="submit">登录</button></form>' .
        '<div class="auth-foot"><a href="' . e(u('p=register')) . '">没有账号？注册</a><a href="' . e(u('p=forgot')) . '">忘记密码？</a></div>' .
        '</div></div>';
    layout_footer();
}

/* ---------------- 注册 ---------------- */
function page_register(): void
{
    layout_header('注册', 0);
    echo '<div class="auth-wrap"><div class="card auth-card">';
    echo page_head('注册账号', '用户名 / 邮箱均需唯一，邮箱验证码 5 分钟内有效');
    echo '<form method="post" action="' . e(u('a=register')) . '">' . csrf_field() .
        '<label class="field"><span class="field-l">用户名</span><input class="input" name="name" required maxlength="20" value="' . old('name') . '" placeholder="2-20 位：中文、字母、数字、下划线"></label>' .
        '<label class="field"><span class="field-l">邮箱</span><input class="input" type="email" name="email" id="reg-email" required maxlength="60" value="' . old('email') . '" placeholder="用于接收验证码，登录也可使用"></label>' .
        '<div class="field"><span class="field-l">邮箱验证码</span>' .
        '<div class="code-row"><input class="input" name="code" required maxlength="6" inputmode="numeric" placeholder="6 位数字">' .
        '<button class="btn btn-ghost send-code" type="button" data-purpose="register" data-email="#reg-email">发送验证码</button></div>' .
        '<span class="code-msg muted"></span></div>' .
        '<label class="field"><span class="field-l">密码</span><input class="input" type="password" name="pass" required minlength="6" maxlength="60" autocomplete="new-password" placeholder="至少 6 位"></label>' .
        '<label class="field"><span class="field-l">确认密码</span><input class="input" type="password" name="pass2" required minlength="6" maxlength="60" autocomplete="new-password"></label>' .
        '<button class="btn btn-primary btn-block" type="submit">注册并登录</button></form>' .
        '<div class="auth-foot"><a href="' . e(u('p=login')) . '">已有账号？登录</a></div>' .
        '</div></div>';
    layout_footer();
}

/* ---------------- 忘记 / 重置密码 ---------------- */
function page_forgot(): void
{
    layout_header('找回密码', 0);
    echo '<div class="auth-wrap"><div class="card auth-card">';
    echo page_head('找回密码', '通过注册邮箱验证码设置新密码');
    echo '<form method="post" action="' . e(u('a=forgot')) . '">' . csrf_field() .
        '<label class="field"><span class="field-l">注册邮箱</span><input class="input" type="email" name="email" id="fp-email" required maxlength="60" value="' . old('email') . '"></label>' .
        '<div class="field"><span class="field-l">邮箱验证码</span>' .
        '<div class="code-row"><input class="input" name="code" required maxlength="6" inputmode="numeric" placeholder="6 位数字">' .
        '<button class="btn btn-ghost send-code" type="button" data-purpose="reset" data-email="#fp-email">发送验证码</button></div>' .
        '<span class="code-msg muted"></span></div>' .
        '<label class="field"><span class="field-l">新密码</span><input class="input" type="password" name="pass" required minlength="6" maxlength="60" autocomplete="new-password"></label>' .
        '<label class="field"><span class="field-l">确认新密码</span><input class="input" type="password" name="pass2" required minlength="6" maxlength="60" autocomplete="new-password"></label>' .
        '<button class="btn btn-primary btn-block" type="submit">重置密码</button></form>' .
        '<div class="auth-foot"><a href="' . e(u('p=login')) . '">返回登录</a></div>' .
        '</div></div>';
    layout_footer();
}

/* ---------------- 个人主页 ---------------- */
function page_user(): void
{
    $uid = get_int('id', 0);
    $p = user_by_id($uid);
    if (!$p) {
        page_404();
        return;
    }
    $self = current_user() && (int)current_user()['id'] === $uid;
    // 帖子数直接从数据资源（threads/index.php 索引）统计，与下方列表同源：
    // 旧版计数器（users.php 的 threads 字段）在历史静默写失败/删帖边界下可能虚高，
    // 出现"显示发了 N 个帖子、列表却是空的"的不一致，故不再信任计数器
    $myThreads = threads_by_author($uid);
    layout_header((string)$p['name'], 0);
    echo '<div class="card profile-card"><div class="profile-top">' .
        '<span class="avatar" aria-hidden="true">' . e(cut_str((string)$p['name'], 1)) . '</span>' .
        '<div class="profile-info"><b>' . e((string)$p['name']) . '</b>' .
        '<span class="badge' . (!empty($p['admin']) ? ' badge-accent' : '') . '">' . e(role_name($p)) . '</span>' .
        ($self ? '<a class="btn btn-ghost btn-sm" href="' . e(u('p=settings')) . '">编辑资料</a>' : '') .
        '</div></div>' .
        '<p class="profile-bio">' . ($p['bio'] !== '' ? e((string)$p['bio']) : '<span class="muted">这个人很懒，什么都没写</span>') . '</p>' .
        '<div class="stat-row">' .
        '<div class="stat"><b>' . count($myThreads) . '</b><span>帖子</span></div>' .
        '<div class="stat"><b>' . (int)($p['likes_recv'] ?? 0) . '</b><span>被赞</span></div>' .
        '<div class="stat"><b>' . (int)($p['replies_recv'] ?? 0) . '</b><span>被回复</span></div>' .
        '<div class="stat"><b>' . e(fmt_dt((int)($p['created'] ?? 0))) . '</b><span>加入时间</span></div>' .
        '</div></div>';

    echo page_head('TA 的帖子');
    $page = max(1, get_int('page', 1));
    $per = max(5, (int)cfg('per_page', 20));
    $rows = array_slice($myThreads, ($page - 1) * $per, $per);
    echo '<div class="thread-list">';
    if (!$rows) {
        echo empty_state('还没有发过帖子');
    }
    foreach ($rows as $t) {
        echo thread_card($t, true);
    }
    echo '</div>';
    echo paginate(count($myThreads), $per, $page, 'p=user&id=' . $uid);
    layout_footer();
}

/* ---------------- 个人设置 ---------------- */
function page_settings(): void
{
    $u = require_login('p=settings');
    layout_header('个人设置', 0);
    echo page_head('个人设置', '修改用户名、简介，或通过邮箱验证码修改密码');
    echo '<div class="card form-card"><h2 class="card-title">基本资料</h2>' .
        '<form method="post" action="' . e(u('a=profile_save')) . '">' . csrf_field() .
        '<label class="field"><span class="field-l">用户名</span><input class="input" name="name" required maxlength="20" value="' . e((string)$u['name']) . '"></label>' .
        '<label class="field"><span class="field-l">个人简介</span><textarea class="input" name="bio" rows="3" maxlength="200" placeholder="一句话介绍自己">' . e((string)($u['bio'] ?? '')) . '</textarea></label>' .
        '<div class="form-foot"><span class="muted">注册邮箱：' . e((string)$u['email']) . '（不可修改）</span>' .
        '<button class="btn btn-primary" type="submit">保存</button></div></form></div>';

    echo '<div class="card form-card"><h2 class="card-title">修改密码</h2><p class="muted">修改密码需要邮箱验证码（发送至注册邮箱），修改成功后建议重新登录。</p>' .
        '<form method="post" action="' . e(u('a=change_pass')) . '">' . csrf_field() .
        '<div class="field"><span class="field-l">注册邮箱</span><input class="input" type="email" id="cp-email" value="' . e((string)$u['email']) . '" readonly></div>' .
        '<div class="field"><span class="field-l">邮箱验证码</span>' .
        '<div class="code-row"><input class="input" name="code" required maxlength="6" inputmode="numeric" placeholder="6 位数字">' .
        '<button class="btn btn-ghost send-code" type="button" data-purpose="reset" data-email="#cp-email">发送验证码</button></div>' .
        '<span class="code-msg muted"></span></div>' .
        '<label class="field"><span class="field-l">新密码</span><input class="input" type="password" name="pass" required minlength="6" maxlength="60" autocomplete="new-password"></label>' .
        '<label class="field"><span class="field-l">确认新密码</span><input class="input" type="password" name="pass2" required minlength="6" maxlength="60" autocomplete="new-password"></label>' .
        '<div class="form-foot"><span></span><button class="btn btn-primary" type="submit">修改密码</button></div></form></div>';
    layout_footer();
}

/* ---------------- 公告与通知 ---------------- */
function page_announcements(): void
{
    layout_header('公告与通知', 0);
    $u = current_user();
    $tab = (($_GET['tab'] ?? '') === 'notice' && $u) ? 'notice' : 'ann';
    $unread = $u ? notify_unread((int)$u['id']) : 0;

    echo '<div class="list-head"><div class="tabs">' .
        '<a class="tab' . ($tab === 'ann' ? ' on' : '') . '" href="' . e(u('p=announcements&tab=ann')) . '">平台公告</a>' .
        ($u ? '<a class="tab' . ($tab === 'notice' ? ' on' : '') . '" href="' . e(u('p=announcements&tab=notice')) . '">我的通知' . ($unread > 0 ? '（' . $unread . '）' : '') . '</a>' : '') .
        '</div>' .
        ($tab === 'notice' && $unread > 0
            ? '<form method="post" action="' . e(u('a=notify_read_all')) . '" class="inline">' . csrf_field() . '<button class="btn btn-ghost btn-sm" type="submit">全部已读</button></form>'
            : '') .
        '</div>';

    if ($tab === 'ann') {
        $anns = ann_all();
        if (!$anns) {
            echo empty_state('暂无公告');
        }
        foreach ($anns as $a) {
            echo '<article class="card ann-item"><div class="ann-head"><b>' . e((string)$a['title']) . '</b>' .
                '<span class="muted">' . e(uname((int)$a['by'])) . ' · ' . fmt_dt((int)$a['created']) .
                (!empty($a['updated']) ? '（编辑于 ' . fmt_time((int)$a['updated']) . '）' : '') . '</span></div>' .
                '<div class="md">' . md_render((string)$a['content']) . '</div></article>';
        }
    } else {
        require_login('p=announcements&tab=notice');
        $ns = notify_list((int)$u['id']);
        echo '<div class="notice-list">';
        if (!$ns) {
            echo empty_state('暂无通知');
        }
        foreach ($ns as $n) {
            $nid = (int)$n['id'];
            $appealable = !empty($n['appealable']) && (int)($n['report'] ?? 0) > 0;
            // 申诉仅当举报仍处于「AI 判定违规」状态时可用
            if ($appealable) {
                $rep = report_get((int)$n['report']);
                $appealable = $rep && $rep['status'] === 'ai_bad';
            }
            echo '<div class="card notice-item' . (empty($n['read']) ? ' unread' : '') . '">' .
                '<div class="notice-head"><b>' . e((string)$n['title']) . '</b><span class="muted">' . fmt_time((int)$n['created']) . '</span></div>' .
                '<p class="notice-body">' . e((string)$n['body']) . '</p>' .
                '<div class="notice-ops">';
            if (!empty($n['link'])) {
                echo '<a class="btn btn-ghost btn-sm" href="' . e(u((string)$n['link'])) . '">查看相关内容</a>';
            }
            if ($appealable) {
                echo '<form method="post" action="' . e(u('a=appeal')) . '" class="inline" data-confirm="确认申诉？申诉后将由管理员人工复核，该内容将永久不能再被举报。">' .
                    '<input type="hidden" name="report" value="' . (int)$n['report'] . '">' . csrf_field() . hidden_back() .
                    '<button class="btn btn-primary btn-sm" type="submit">申诉</button></form>';
            }
            if (empty($n['read'])) {
                echo '<form method="post" action="' . e(u('a=notify_read')) . '" class="inline">' .
                    '<input type="hidden" name="id" value="' . $nid . '">' . csrf_field() . hidden_back() .
                    '<button class="btn btn-ghost btn-sm" type="submit">标记已读</button></form>';
            }
            echo '</div></div>';
        }
        echo '</div>';
    }
    layout_footer();
}

/* ---------------- 404 ---------------- */
function page_404(): void
{
    http_response_code(404);
    layout_header('页面不存在', 0);
    echo '<div class="card notice-card"><b>404 · 页面不存在</b><p>内容可能已被删除，或链接有误。</p>' .
        '<a class="btn btn-primary btn-sm" href="' . e(u('p=home')) . '">返回首页</a></div>';
    layout_footer();
}
