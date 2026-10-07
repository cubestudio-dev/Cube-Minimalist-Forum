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

    thread_view_bump($tid); // 浏览量 +1（每会话每帖至多计一次）
    $t['views'] = (int)(thread_get($tid)['views'] ?? ($t['views'] ?? 0)); // 先计后渲染：首次浏览即显示新值

    echo '<article class="card thread-art">';
    echo '<div class="art-head"><h1 class="art-title">' . e((string)$t['title']) . '</h1><div class="art-meta">' .
        '<a href="' . e(u('p=user&id=' . $author)) . '">' . e(uname($author)) . '</a>' .
        '<span>' . fmt_dt((int)$t['created']) . '</span>' .
        '<span class="muted">浏览 ' . (int)($t['views'] ?? 0) . '</span>' .
        (!empty($t['edited']) ? '<span class="muted" title="编辑于 ' . e(fmt_dt((int)$t['edited'])) . '">已编辑</span>' : '') .
        (!empty($t['pinned']) ? '<span class="badge badge-accent">置顶</span>' : '') .
        (!empty($t['locked']) ? '<span class="badge">锁定</span>' : '') .
        ($hidden ? '<span class="badge badge-warn">审核中</span>' : '') .
        (!empty($t['appealed']) ? '<span class="badge badge-info">已申诉</span>' : '') .
        '</div></div>';
    echo '<div class="md art-content">' . md_render((string)$t['content']) . '</div>';
    echo user_sig_line($author);

    // 操作行
    echo '<div class="art-ops">';
    if ($u) {
        if (feat_on('like')) {
            echo like_btn('t', $tid, 0, $t['likes'] ?? [], $uid);
        }
        if (feat_on('report')) {
            echo report_box('t', $tid, 0);
        }
        if (thread_editable($t, $u)) {
            echo '<a class="btn btn-ghost btn-sm" href="' . e(u('p=edit&type=thread&id=' . $tid)) . '">编辑</a>';
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
            (!empty($r['edited']) ? '<span class="muted" title="编辑于 ' . e(fmt_dt((int)$r['edited'])) . '">已编辑</span>' : '') .
            ($rhidden ? '<span class="badge badge-warn">审核中</span>' : '') .
            (!empty($r['appealed']) ? '<span class="badge badge-info">已申诉</span>' : '') .
            '<span class="muted">#' . $rid . '</span></div>';
        if ($rhidden && !$admin) {
            echo '<div class="md reply-content muted">该回复正在审核中，暂时无法查看。</div>';
        } else {
            echo '<div class="md reply-content">' . md_render((string)$r['content']) . '</div>';
            echo user_sig_line((int)$r['author']);
        }
        echo '<div class="reply-ops">';
        if ($u && !$rhidden) {
            if (feat_on('like')) {
                echo like_btn('r', $tid, $rid, $r['likes'] ?? [], $uid);
            }
            if (feat_on('report')) {
                echo report_box('r', $tid, $rid);
            }
            if (reply_editable($r, $u, $t)) {
                echo '<a class="btn btn-ghost btn-sm" href="' . e(u('p=edit&type=reply&id=' . $tid . '&rid=' . $rid)) . '">编辑</a>';
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
    } elseif (!feat_on('reply')) {
        echo '<div class="muted">回复功能已关闭。</div>';
    } elseif (!empty($t['locked'])) {
        echo '<div class="muted">帖子已被锁定，无法回复。</div>';
    } elseif (mute_left($u) > 0) {
        echo '<div class="muted">您已被禁言，剩余 ' . ceil(mute_left($u) / 60) . ' 分钟。</div>';
    } else {
        /* v1.16.0：发言间隔被拒时内容已存会话草稿，回跳后自动填回，用户不必重打 */
        $draft = $_SESSION['post_draft'] ?? null;
        $rd = (is_array($draft) && ($draft['key'] ?? '') === 'reply' . $tid) ? (string)($draft['content'] ?? '') : '';
        unset($_SESSION['post_draft']);
        echo '<form method="post" action="' . e(u('a=reply_new')) . '">' .
            '<input type="hidden" name="tid" value="' . $tid . '">' .
            csrf_field() . hidden_back() .
            '<textarea class="input" name="content" rows="4" maxlength="1000" required placeholder="友善回复（支持 Markdown，最多 1000 字；可用 @用户名 提及他人，对方会收到通知）">' . e($rd) . '</textarea>' .
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
    if (!feat_on('post')) {
        flash('err', '发帖功能已关闭');
        redirect(u('p=home'));
    }
    if (mute_left($u) > 0) {
        flash('err', '您已被禁言，剩余 ' . ceil(mute_left($u) / 60) . ' 分钟');
        redirect(u('p=home'));
    }
    $pre = get_int('id', 0);
    /* v1.16.0：发言间隔被拒时内容已存会话草稿，回跳后自动填回，用户不必重打 */
    $draft = $_SESSION['post_draft'] ?? null;
    $dt = (is_array($draft) && ($draft['key'] ?? '') === 'thread') ? (string)($draft['title'] ?? '') : '';
    $dc = (is_array($draft) && ($draft['key'] ?? '') === 'thread') ? (string)($draft['content'] ?? '') : '';
    unset($_SESSION['post_draft']);
    layout_header('发布帖子', 0);
    echo page_head('发布帖子', '标题最多 30 字，正文最多 1500 字，支持 Markdown 与 @用户名 提及（图片可用 https 外链，无附件）');
    echo '<div class="card form-card"><form method="post" action="' . e(u('a=thread_new')) . '">' .
        csrf_field() . hidden_back() .
        '<label class="field"><span class="field-l">板块</span><select name="board" class="input" required>';
    foreach (board_all() as $b) {
        echo '<option value="' . (int)$b['id'] . '"' . ($pre === (int)$b['id'] ? ' selected' : '') . '>' . e((string)$b['name']) . '</option>';
    }
    echo '</select></label>' .
        '<label class="field"><span class="field-l">标题 <span class="cnt"><span id="t-count">0</span>/30</span></span>' .
        '<input class="input" name="title" id="title-input" maxlength="30" required data-counter="#t-count" value="' . e($dt) . '" placeholder="一句话说清主题"></label>' .
        '<label class="field"><span class="field-l">正文 <span class="cnt"><span id="c-count">0</span>/1500</span></span>' .
        '<textarea class="input" name="content" id="content-input" rows="10" maxlength="1500" required data-counter="#c-count" placeholder="支持 Markdown：# 标题、**加粗**、`代码`、- 列表、> 引用、| 表格 |、==高亮==；@用户名 会通知对方">' . e($dc) . '</textarea></label>' .
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
    if (!feat_on('register')) {
        flash('err', '本站已关闭新用户注册');
        redirect(u('p=login'));
    }
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
        /* v1.16.0：已启用协议时强制勾选 */
        (doc_list() !== []
            ? '<label class="check doc-agree"><input type="checkbox" name="doc_agree" value="1" required> 我已阅读并同意 ' .
                implode('、', array_map(function ($d) { return '<a href="' . e(u('p=doc')) . '" target="_blank"><b>' . e($d['title']) . '</b></a>'; }, array_values(doc_list())))
            . '</label>'
            : '') .
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
        ((int)feat_on('signature') && trim((string)($p['sig'] ?? '')) !== '' ? '<p class="user-sig profile-sig">— ' . e(cut_str((string)$p['sig'], 60)) . '</p>' : '') .
        '<div class="stat-row">' .
        '<div class="stat"><b>' . count($myThreads) . '</b><span>帖子</span></div>' .
        '<div class="stat"><b>' . (int)($p['likes_recv'] ?? 0) . '</b><span>被赞</span></div>' .
        '<div class="stat"><b>' . (int)($p['replies_recv'] ?? 0) . '</b><span>被回复</span></div>' .
        '<div class="stat"><b>' . e(fmt_dt((int)($p['created'] ?? 0))) . '</b><span>加入时间</span></div>' .
        '<div class="stat"><b>' . max(1, (int)floor((time() - (int)($p['created'] ?? time())) / 86400) + 1) . ' 天</b><span>加入论坛</span></div>' .
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
        (feat_on('signature')
            ? '<label class="field"><span class="field-l">用户签名（可选）</span><input class="input" name="sig" maxlength="60" value="' . e((string)($u['sig'] ?? '')) . '" placeholder="将展示在你的帖子与回复下方"><span class="hint">单行纯文本，最多 60 字</span></label>'
            : '') .
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

    /* ---- v1.16.0：注销账号 ---- */
    $armed = (int)($_SESSION['delete_armed'] ?? 0);
    $left = $armed > 0 ? max(0, 10 - (time() - $armed)) : -1;
    echo '<div class="card form-card danger-zone" id="delete"><h2 class="card-title">注销账号</h2>' .
        '<p class="muted">注销后您的账号将无法登录，历史帖子保留、作者显示为「已注销」；用户名与邮箱会被释放，可被重新注册。<b>此操作不可恢复</b>，请谨慎操作。</p>' .
        '<p class="muted">流程：发送邮箱验证码 → 输入验证码确认身份 → 等待 10 秒冷静期 → 点击「确认注销」。</p>' .
        '<form method="post" action="' . e(u('a=account_delete')) . '">' . csrf_field() .
        '<div class="field"><span class="field-l">注册邮箱</span><input class="input" type="email" id="del-email" value="' . e((string)$u['email']) . '" readonly></div>' .
        '<div class="field"><span class="field-l">邮箱验证码</span>' .
        '<div class="code-row"><input class="input" name="code" required maxlength="6" inputmode="numeric" placeholder="6 位数字">' .
        '<button class="btn btn-ghost send-code" type="button" data-purpose="delete" data-email="#del-email">发送验证码</button></div>' .
        '<span class="code-msg muted"></span></div>' .
        '<div class="form-foot"><span></span><button class="btn danger" type="submit">验证身份</button></div></form>';
    if ($left >= 0) {
        echo '<form method="post" action="' . e(u('a=account_delete_confirm')) . '" class="delete-confirm">' . csrf_field() .
            '<p class="muted">身份已验证。' . ($left > 0
                ? '请在下方倒计时结束后确认（冷静期 <b id="del-count">' . $left . '</b> 秒）'
                : '冷静期已结束，确认后立即生效且不可恢复') . '</p>' .
            '<button class="btn danger" type="submit" id="del-go"' . ($left > 0 ? ' disabled' : '') . ' data-wait="' . $left . '">确认注销（10 秒后可点击）</button>' .
            '</form>';
    }
    echo '</div>';

    /* ---- v1.19.0：开放 API 令牌 ---- */
    $apiOn = (int)cfg('api_enabled', 0) === 1;
    $tokRec = api_token_of((int)$u['id']);
    $showTok = '';
    if (!empty($_SESSION['api_token_show']) && is_string($_SESSION['api_token_show'])) {
        $showTok = (string)$_SESSION['api_token_show'];
        unset($_SESSION['api_token_show']); // 明文仅展示一次
    }
    echo '<div class="card form-card" id="api"><h2 class="card-title">开放 API</h2>';
    if (!$apiOn) {
        echo '<p class="muted">本站暂未开放 API。开放后你可以在这里签发自己的访问令牌，用于第三方客户端、机器人或自定义界面。<a href="' . e(u('p=api_docs')) . '">了解开放 API</a></p>';
    } else {
        echo '<p class="muted">签发个人访问令牌（Token）后，即可用你的账号身份通过开放 API 开发客户端 / 机器人 / 自定义界面——发帖、回复、点赞、通知等能力与网页端完全一致。' .
            '<a href="' . e(u('p=api_docs')) . '">查看开发者文档与全部端点</a></p>';
        if ($showTok !== '') {
            echo '<div class="api-token-box"><b>你的令牌（仅显示这一次，请立即复制保存）</b>' .
                '<code id="api-token-val">' . e($showTok) . '</code>' .
                '<button class="btn btn-ghost btn-sm" type="button" id="api-token-copy">复制令牌</button>' .
                '<p class="hint">它是你的账号身份：不要公开、不要写进代码仓库；泄露请立即在下方「重置令牌」。</p></div>';
        }
        if ($tokRec) {
            echo '<p class="hint">当前状态：已签发（<code>••••••</code>出于安全不明文展示）· 签发于 ' . fmt_dt((int)($tokRec['created'] ?? 0)) .
                ' · 同意条款 ' . fmt_dt((int)($tokRec['agree'] ?? 0)) . '（条款版本 ' . e((string)($tokRec['agree_fp'] ?? '')) . '）</p>' .
                '<p class="hint">为安全起见令牌明文不再展示，只存哈希；忘记就重置，旧令牌立即失效。</p>';
        }
        echo '<form method="post" action="' . e(u('a=api_token_new')) . '">' . csrf_field() .
            '<details class="api-terms"' . ($tokRec ? '' : ' open') . '><summary>《开放 API 使用条款》（版本 ' . e(api_terms_fp()) . '）</summary>' .
            '<div class="md">' . md_render((string)cfg('api_terms', '') !== '' ? (string)cfg('api_terms', '') : api_terms_template()) . '</div></details>' .
            '<label class="check"><input type="checkbox" name="agree" value="1" required> 我已阅读并同意《开放 API 使用条款》，知晓令牌等同账号身份、须妥善保管</label>' .
            '<div class="form-foot"><span></span><button class="btn btn-primary" type="submit">' . ($tokRec ? '重置令牌（旧令牌立即失效）' : '签发令牌') . '</button></div></form>';
        if ($tokRec) {
            echo '<form method="post" action="' . e(u('a=api_token_revoke')) . '" data-confirm="确认撤销 API 令牌？使用该令牌的客户端将立即失去访问权。">' . csrf_field() .
                '<div class="form-foot"><span></span><button class="btn btn-ghost btn-sm danger" type="submit">撤销令牌</button></div></form>';
        }
    }
    echo '</div>';

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

/* ---------------- 在线详情（v1.10.0） ---------------- */

/** 逗逛时长文案：X 小时 Y 分 / X 分 Y 秒 / Y 秒 */
function online_dur(int $in): string
{
    $s = max(0, time() - $in);
    if ($s >= 3600) {
        return floor($s / 3600) . ' 小时 ' . floor(($s % 3600) / 60) . ' 分';
    }
    if ($s >= 60) {
        return floor($s / 60) . ' 分 ' . ($s % 60) . ' 秒';
    }
    return $s . ' 秒';
}

function page_online(): void
{
    if (!feat_on('online')) {
        flash('err', '在线名单已关闭');
        redirect(u('p=home'));
    }
    $d = online_details();
    $w = (int)$d['window'];
    $nu = count($d['users']);
    $ng = count($d['guests']);
    layout_header('当前在线', 0);
    echo page_head('当前在线', "最近 {$w} 秒内有活动：{$nu} 位注册用户 · {$ng} 位游客");

    /* 注册用户：点击进入个人主页 */
    echo '<div class="card online-card"><h2 class="card-title">注册用户（' . $nu . '）</h2><div class="online-list">';
    if (!$d['users']) {
        echo '<p class="muted" style="padding:6px 4px">当前没有注册用户在线</p>';
    }
    foreach ($d['users'] as $x) {
        echo '<a class="online-row" href="' . e(u('p=user&id=' . (int)$x['uid'])) . '">' .
            '<span class="avatar avatar-sm" aria-hidden="true">' . e(cut_str((string)$x['name'], 1)) . '</span>' .
            '<span class="online-name"><b>' . e((string)$x['name']) . '</b>' .
            '<span class="muted">已逛 ' . online_dur((int)$x['in']) . '</span></span>' .
            '<span class="muted online-last">活跃 ' . fmt_time((int)$x['t']) . '</span></a>';
    }
    echo '</div></div>';

    /* 游客：名称 = 游客 + 逗逛时长；时长相同的按 A/B/C 字母区分 */
    $durs = [];
    foreach ($d['guests'] as $i => $g) {
        $durs[online_dur((int)$g['in'])][] = $i;
    }
    $letters = [];
    foreach ($durs as $idxs) {
        if (count($idxs) > 1) {
            foreach ($idxs as $k => $i) {
                $letters[$i] = chr(65 + min($k, 25));
            }
        }
    }
    echo '<div class="card online-card"><h2 class="card-title">游客（' . $ng . '）</h2><div class="online-list">';
    if (!$d['guests']) {
        echo '<p class="muted" style="padding:6px 4px">当前没有游客在线</p>';
    }
    foreach ($d['guests'] as $i => $g) {
        $tag = isset($letters[$i]) ? ' <span class="badge badge-info online-tag">' . $letters[$i] . '</span>' : '';
        echo '<div class="online-row">' .
            '<span class="avatar avatar-sm avatar-guest" aria-hidden="true">客</span>' .
            '<span class="online-name"><b>游客' . $tag . '</b>' .
            '<span class="muted">已逛 ' . online_dur((int)$g['in']) . '</span></span>' .
            '<span class="muted online-last">活跃 ' . fmt_time((int)$g['t']) . '</span></div>';
    }
    echo '</div><p class="hint" style="margin:10px 4px 2px">游客按时长相同者标注 A / B / C 以便区分；刷新页面可查看最新名单。</p></div>';
    layout_footer();
}

/* ---------------- 编辑自己的内容（v1.14.0） ---------------- */
function page_edit(): void
{
    $u = require_login('p=edit');
    if (!feat_on('edit')) {
        flash('err', '编辑功能已关闭');
        redirect(u('p=home'));
    }
    $type = (($_GET['type'] ?? '') === 'reply') ? 'reply' : 'thread';
    $tid = get_int('id', 0);
    $t = thread_get($tid);
    if (!$t) {
        page_404();
        return;
    }
    layout_header('编辑内容', (int)$t['board']);
    if ($type === 'thread') {
        if (!thread_editable($t, $u)) {
            echo '<div class="card notice-card"><b>无法编辑该帖子</b><p>可能原因：已超过发布后 15 分钟的可编辑时间、帖子已有回复、帖子被锁定或正在审核中。（管理员不受时间限制）</p>' .
                '<a class="btn btn-primary btn-sm" href="' . e(u('p=thread&id=' . $tid)) . '">返回帖子</a></div>';
            layout_footer();
            return;
        }
        echo page_head('编辑帖子', '发布后 15 分钟内可编辑，已有回复后不可再编辑');
        echo '<div class="card form-card"><form method="post" action="' . e(u('a=thread_edit')) . '">' .
            csrf_field() .
            '<input type="hidden" name="tid" value="' . $tid . '">' .
            '<label class="field"><span class="field-l">标题</span><input class="input" name="title" maxlength="30" required value="' . e((string)$t['title']) . '"></label>' .
            '<label class="field"><span class="field-l">正文（最多 1500 字）</span><textarea class="input" name="content" rows="10" maxlength="1500" required>' . e((string)$t['content']) . '</textarea></label>' .
            '<div class="form-foot"><a class="btn btn-ghost" href="' . e(u('p=thread&id=' . $tid)) . '">取消</a>' .
            '<button class="btn btn-primary" type="submit">保存修改</button></div></form></div>';
    } else {
        $rid = get_int('rid', 0);
        $r = reply_get($tid, $rid);
        if (!$r || !reply_editable($r, $u, $t)) {
            echo '<div class="card notice-card"><b>无法编辑该回复</b><p>可能原因：已超过发布后 15 分钟的可编辑时间、帖子被锁定或回复正在审核中。（管理员不受时间限制）</p>' .
                '<a class="btn btn-primary btn-sm" href="' . e(u('p=thread&id=' . $tid)) . '">返回帖子</a></div>';
            layout_footer();
            return;
        }
        echo page_head('编辑回复', '发布后 15 分钟内可编辑');
        echo '<div class="card form-card"><form method="post" action="' . e(u('a=reply_edit')) . '">' .
            csrf_field() .
            '<input type="hidden" name="tid" value="' . $tid . '">' .
            '<input type="hidden" name="rid" value="' . $rid . '">' .
            '<label class="field"><span class="field-l">回复内容</span><textarea class="input" name="content" rows="6" maxlength="1000" required>' . e((string)$r['content']) . '</textarea></label>' .
            '<div class="form-foot"><a class="btn btn-ghost" href="' . e(u('p=thread&id=' . $tid)) . '#r' . $rid . '">取消</a>' .
            '<button class="btn btn-primary" type="submit">保存修改</button></div></form></div>';
    }
    layout_footer();
}

/* ---------------- 站内搜索（v1.14.0） ---------------- */
function page_search(): void
{
    if (!feat_on('search')) {
        flash('err', '站内搜索已关闭');
        redirect(u('p=home'));
    }
    $q = trim((string)($_GET['q'] ?? ''));
    $q = cut_str((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $q), 50);
    layout_header($q !== '' ? '搜索：' . $q : '站内搜索', 0);
    echo page_head('站内搜索', '搜索最近 500 帖的标题与正文');
    echo '<div class="card form-card"><form method="get" action="index.php">' .
        '<input type="hidden" name="p" value="search">' .
        (demo_port() !== '' ? '<input type="hidden" name="XTransformPort" value="' . demo_port() . '">' : '') .
        '<div class="code-row"><input class="input" type="search" name="q" maxlength="50" value="' . e($q) . '" placeholder="输入关键词，如：茶馆">' .
        '<button class="btn btn-primary" type="submit">搜索</button></div></form></div>';
    if ($q !== '') {
        /* v1.16.0：用户搜索（用户名 / 签名 / 简介） */
        $uhits = [];
        foreach (user_all() as $uu) {
            if (!empty($uu['deleted']) || !empty($uu['banned'])) {
                continue;
            }
            if (txt_contains((string)($uu['name'] ?? ''), $q) || txt_contains((string)($uu['sig'] ?? ''), $q) || txt_contains((string)($uu['bio'] ?? ''), $q)) {
                $uhits[] = $uu;
                if (count($uhits) >= 20) {
                    break;
                }
            }
        }
        if ($uhits) {
            echo '<div class="list-head"><span class="muted">找到 ' . count($uhits) . ' 位相关用户</span></div><div class="thread-list">';
            foreach ($uhits as $uu) {
                echo '<a class="thread-card" href="' . e(u('p=user&id=' . (int)$uu['id'])) . '"><div class="tc-main"><b>' . e((string)$uu['name']) . '</b>' .
                    '<span class="badge' . (!empty($uu['admin']) ? ' badge-accent' : '') . '">' . e(role_name($uu)) . '</span>' .
                    '<span class="muted">发帖 ' . (int)($uu['threads'] ?? 0) . ' · 回复 ' . (int)($uu['replies'] ?? 0) . '</span></div>' .
                    ((string)($uu['bio'] ?? '') !== '' || (string)($uu['sig'] ?? '') !== '' ? '<div class="tc-sub muted">' . e((string)($uu['bio'] ?? $uu['sig'])) . '</div>' : '') .
                    '</a>';
            }
            echo '</div>';
        }
        $hits = search_threads($q);
        $scan = min(500, count(threads_visible(thread_index())));
        echo '<div class="list-head"><span class="muted">在最近 ' . $scan . ' 帖中找到 ' . count($hits) . ' 条结果</span></div>';
        echo '<div class="thread-list">';
        if (!$hits) {
            echo empty_state('没有找到相关帖子，换个关键词试试');
        }
        foreach ($hits as $h) {
            echo thread_card($h['t'], true);
            if ((string)$h['snippet'] !== '') {
                echo '<p class="search-snippet muted">' . e((string)$h['snippet']) . '</p>';
            }
        }
        echo '</div>';
    }
    layout_footer();
}

/* ---------------- 网站图标输出（v1.14.0） ---------------- */
function page_icon(): void
{
    $f = (string)cfg('site_icon', '');
    $p = $f !== '' ? Store::path('upload/' . $f) : '';
    if ($f === '' || strpos($f, '/') !== false || !is_file($p)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'icon not found';
        exit;
    }
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', 'ico' => 'image/x-icon', 'svg' => 'image/svg+xml'][$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($p));
    header('Cache-Control: public, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    if ($ext === 'svg') {
        // 纵深防御：即使存在漏检的脚本，CSP sandbox 也使其无法执行
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
    }
    readfile($p);
    exit;
}

/* ---------------- v1.17.0：Ping 端点（侧栏延迟显示） ---------------- */
function page_ping(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['pong' => 1, 't' => time()]);
    exit;
}

/* ---------------- v1.17.0：静态资源端点（显式强缓存头） ----------------
 * PHP 内置服务器（php -S）不读 .htaccess，此前的 assets/*.js|css 是裸响应、
 * 无 Cache-Control，浏览器只能启发式缓存，二次访问也会重复下载，
 * 是“部分页面加载慢/卡”的直接原因之一；改由 PHP 出响应可保证在一切环境下都有
 * ETag + 一年期 immutable 强缓存（URL 自带版本号，升级自动失效），命中 If-None-Match 直接 304。 */
function page_asset(): void
{
    $f = (string)($_GET['f'] ?? '');
    $allow = [
        'app.js'                => ['text/javascript; charset=UTF-8', 'assets/app.js'],
        'style.css'             => ['text/css; charset=UTF-8', 'assets/style.css'],
        'favicon.svg'           => ['image/svg+xml', 'assets/favicon.svg'],
        'favicon.ico'           => ['image/x-icon', 'assets/favicon.ico'],
        'apple-touch-icon.png'  => ['image/png', 'assets/apple-touch-icon.png'],
    ];
    if (!isset($allow[$f])) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'asset not found';
        exit;
    }
    [$mime, $rel] = $allow[$f];
    $p = dirname(DATA_DIR) . '/' . $rel;
    if (!is_file($p)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'asset not found';
        exit;
    }
    $etag = '"' . app_version() . '-' . $f . '-' . (int)@filesize($p) . '"';
    header('ETag: ' . $etag);
    header('Cache-Control: public, max-age=31536000, immutable');
    header('X-Content-Type-Options: nosniff');
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($p));
    readfile($p);
    exit;
}

/* ---------------- 404 ---------------- */
function page_404(): void
{
    http_response_code(404);
    // 防火墙：高频 404 记为扫描行为（10 分钟窗口内超限自动加分 / 封禁）
    if (function_exists('fw_bump_404')) {
        fw_bump_404();
    }
    layout_header('页面不存在', 0);
    echo '<div class="card notice-card"><b>404 · 页面不存在</b><p>内容可能已被删除，或链接有误。</p>' .
        '<a class="btn btn-primary btn-sm" href="' . e(u('p=home')) . '">返回首页</a></div>';
    layout_footer();
}


/* ---------------- 协议（v1.16.0，v1.18.0 独立大页面重构） ---------------- */

/** 渲染协议正文：生成锚点章节 + TOC 数据；返回 [html, toc]（toc = [['id','text','lvl'],..]） */
function doc_render_body(string $body): array
{
    /* 首个一级标题由页面 hero 展示，正文里去掉避免重复 */
    $body = (string)preg_replace('/^#\s*[^\n]*\n+/', '', trim($body));
    $html = md_render($body);
    $toc = [];
    $n = 0;
    /* md_render 会把 # / ## / ### 降两级输出为 h3 / h4 / h5；首行 h3 已移除，这里处理 h4（章）与 h5（节） */
    $html = (string)preg_replace_callback('/<h([45])>(.*?)<\/h\1>/s', function ($m) use (&$toc, &$n) {
        $n++;
        $lvl = (int)$m[1];
        $id = 'doc-sec-' . $n;
        $text = trim(strip_tags($m[2]));
        if ($text !== '') {
            $toc[] = ['id' => $id, 'text' => $text, 'lvl' => $lvl];
        }
        return '<' . ($lvl === 4 ? 'h3' : 'h4') . ' class="' . ($lvl === 4 ? 'doc-sec' : 'doc-sub') . '" id="' . $id . '">' . $m[2] . '</' . ($lvl === 4 ? 'h3' : 'h4') . '>';
    }, $html) ?? $html;
    return [$html, $toc];
}

/** 协议摘要（协议中心卡片用）：去标题/引用/表格符号后的纯文本首段 */
function doc_summary(string $body, int $max = 110): string
{
    $body = (string)preg_replace('/^#\s*[^\n]*\n+/', '', trim($body));
    foreach (preg_split('/\n+/', $body) ?: [] as $line) {
        $t = trim($line);
        if ($t === '') {
            continue;
        }
        $t = preg_replace('/^[>\-*\d.\s|]+/u', '', $t) ?? $t;
        $t = str_replace(['**', '__', '`', '|'], '', $t);
        $t = trim($t);
        if ($t !== '') {
            return cut_str($t, $max);
        }
    }
    return '';
}

/** 当前访客的协议确认状态条（协议中心 / 门禁页共用；$gateOn=是否显示"未同意"警示态） */
function doc_status_card(bool $gateOn): string
{
    $rec = doc_agree_record();
    if ($rec === null) {
        if (!$gateOn) {
            return '';
        }
        return '<div class="doc-status"><span class="badge badge-warn">未同意</span>' .
            '<span>您尚未确认当前版本的站点协议。协议用于说明双方的权利义务与数据用途，确认后即可正常浏览。</span></div>';
    }
    $t = (int)$rec['time'] > 0 ? '确认于 ' . fmt_time((int)$rec['time']) . '（' . gmdate('Y-m-d H:i', (int)$rec['time'] + 8 * 3600) . '）' : '确认时间：更早（升级前已同意）';
    return '<div class="doc-status"><span class="badge badge-ok">已同意</span>' .
        '<span>协议版本 <b>v' . e($rec['ver']) . '</b> · ' . e($t) . '</span>' .
        '<span class="muted">记录载体：' . e($rec['via']) . '；协议内容更新后需重新确认</span></div>';
}

/** 协议同意表单（门禁页 / 文档页底部通用；$next=同意后回跳地址查询串） */
function doc_agree_form(string $next = '', string $btnText = '确认并继续'): string
{
    return '<div class="card form-card doc-agree-card no-print"><form method="post" action="' . e(u('a=doc_agree')) . '">' . csrf_field() .
        '<input type="hidden" name="next" value="' . e($next) . '">' .
        '<label class="check"><input type="checkbox" name="agree" value="1" required> <b>我已仔细阅读并同意以上协议</b></label>' .
        '<div class="form-foot"><span class="muted">确认会记录在本站 Cookie（一年内免重复确认）与登录会话中，协议内容更新后会再次提示</span>' .
        '<button class="btn btn-primary" type="submit">' . e($btnText) . '</button></div></form></div>';
}

/**
 * 协议门禁页（v1.18.0 重构）：独立全屏插屏，不再嵌在论坛壳里（此前侧栏"全部帖子"
 * 仍在旁边，用户误以为协议长在帖子页）。仅展示协议与同意表单，同意后回到原目标页。
 */
function page_doc_gate(): void
{
    $next = '';
    if (isset($_GET['p']) && preg_match('/^[a-z_]{1,20}$/', (string)$_GET['p'])) {
        $next = 'p=' . (string)$_GET['p'];
        if (isset($_GET['id']) && (int)$_GET['id'] > 0) {
            $next .= '&id=' . (int)$_GET['id'];
        }
    }
    $siteName = (string)(cfg('site_name') ?? 'Cube Minimalist Forum');
    doc_layout_header('欢迎使用 ' . $siteName, '在进入社区之前，请先阅读并同意以下协议（预计 3 分钟）');
    echo doc_status_card(true);
    echo '<div class="card doc-card gate-doc"><div class="doc-card-head"><h2>协议全文</h2>' .
        '<span class="doc-tag">' . count(doc_list()) . ' 份</span>' .
        '<span class="doc-meta"><span>点击标题可展开 / 折叠</span></span></div>';
    $first = true;
    foreach (doc_list() as $k => $d) {
        [$html] = doc_render_body($d['body']);
        echo '<details' . ($first ? ' open' : '') . '><summary>' . e($d['title']) . '<span class="muted" style="font-weight:400;font-size:12.5px">（' . u_strlen(trim((string)preg_replace('/^#\s*[^\n]*\n+/', '', $d['body']))) . ' 字）</span></summary>' .
            '<div class="gate-doc-body"><div class="md doc-body">' . $html . '</div>' .
            '<p><a class="btn btn-ghost btn-sm" href="' . e(u('p=doc&type=' . $k)) . '">独立大页面阅读 ' . e($d['title']) . ' ›</a></p></div></details>';
        $first = false;
    }
    echo '</div>';
    echo doc_agree_form($next, '同意并进入 ' . $siteName);
    echo '<div class="doc-status no-print"><span class="badge">暂不同意？</span>' .
        '<span>不同意协议将无法进入本站。您可以随时回到本页重新考虑；如有疑问请联系站长。</span></div>';
    doc_layout_footer();
}

/** 协议中心：p=doc（hub 总览）｜p=doc&type=terms|privacy|disclaimer（独立文档大页面，带 TOC / 版本 / 打印） */
function page_doc(): void
{
    $type = (string)($_GET['type'] ?? '');
    if (!preg_match('/^[a-z]{1,16}$/', $type) || !isset(doc_defs()[$type])) {
        $type = '';
    }
    $list = doc_list();
    $me = current_user();
    $gateOn = (int)cfg('doc_gate', 0) === 1 && !($me && !empty($me['admin'])) && !doc_gate_passed();

    /* ---- 文档大页面 ---- */
    if ($type !== '' && isset($list[$type])) {
        $d = $list[$type];
        $upd = doc_updated($type);
        [$html, $toc] = doc_render_body($d['body']);
        doc_layout_header($d['title'], '协议中心 · 本页由站长维护，最后更新：' . ($upd > 0 ? gmdate('Y-m-d H:i', $upd + 8 * 3600) : '未记录') . ' · 版本 ' . strtoupper(substr(doc_fingerprint(), 0, 8)));
        echo '<div class="doc-status no-print"><span class="doc-card-foot" style="padding:0;display:flex;gap:8px;flex-wrap:wrap">' .
            '<a class="btn btn-ghost btn-sm" href="' . e(u('p=doc')) . '">‹ 返回协议中心</a>' .
            '<button class="btn btn-ghost btn-sm" type="button" onclick="window.print()">打印 / 保存 PDF</button></span></div>';
        echo '<div class="doc-grid"><div class="doc-sheet"><div class="md doc-body">' . $html . '</div></div>';
        if ($toc) {
            echo '<aside class="doc-toc no-print" aria-label="目录"><div class="doc-toc-title">目 录</div>';
            foreach ($toc as $t) {
                echo '<a class="' . ((int)$t['lvl'] === 5 ? 'lvl3' : '') . '" href="#' . e((string)$t['id']) . '">' . e((string)$t['text']) . '</a>';
            }
            echo '</aside>';
        }
        echo '</div>';
        echo '<div style="height:14px"></div>';
        echo doc_status_card($gateOn);
        if ($gateOn) {
            echo doc_agree_form('p=doc&type=' . $type, '确认并继续浏览');
        }
        if (count($list) > 1) {
            echo '<div class="doc-status no-print"><span class="muted">其他协议：</span>';
            $links = [];
            foreach ($list as $k => $x) {
                if ($k !== $type) {
                    $links[] = '<a href="' . e(u('p=doc&type=' . $k)) . '">' . e($x['title']) . '</a>';
                }
            }
            echo implode(' · ', $links) . '</div>';
        }
        doc_layout_footer();
        return;
    }

    /* ---- 协议中心 hub ---- */
    doc_layout_header('协议中心', '这里汇总本站全部协议：您的权利义务、我们如何处理数据、以及双方的责任边界');
    echo doc_status_card($gateOn);
    if (!$list) {
        echo '<div class="card form-card">' . empty_state('站长还没有启用任何协议') . '</div>';
    }
    foreach ($list as $k => $d) {
        $upd = doc_updated($k);
        echo '<div class="doc-card"><div class="doc-card-head"><h2>' . e($d['title']) . '</h2><span class="doc-tag">已启用</span>' .
            '<span class="doc-meta"><span>' . u_strlen(trim((string)preg_replace('/^#\s*[^\n]*\n+/', '', $d['body']))) . ' 字</span>' .
            '<span>' . ($upd > 0 ? '更新于 ' . fmt_time($upd) : '更新时间未记录') . '</span></span></div>' .
            '<div class="doc-card-body"><p class="doc-card-summary">' . e(doc_summary($d['body'])) . '…</p></div>' .
            '<div class="doc-card-foot"><a class="btn btn-primary btn-sm" href="' . e(u('p=doc&type=' . $k)) . '">阅读全文</a></div></div>';
    }
    if ($gateOn) {
        echo doc_agree_form('p=doc', '确认并继续浏览');
    }
    doc_layout_footer();
}

/* ---------------- v1.19.0：开放 API · 开发者文档页 ----------------
 * 面向想给论坛做客户端 / 机器人 / 第三方前端的用户：全部端点、认证方式、
 * 限速规则、错误码与使用条款都在这一页，且自动反映后台的实时开放状态。 */
function page_api_docs(): void
{
    layout_header('开放 API · 开发者文档', 0);
    $me = current_user();
    $on = (int)cfg('api_enabled', 0) === 1;
    $defs = api_defs();

    if (!$on) {
        echo page_head('开放 API · 开发者文档');
        echo '<div class="card form-card">' . empty_state('本站暂未开放 API，敬请期待');
        if ($me && !empty($me['admin'])) {
            echo '<p class="hint" style="text-align:center">管理员可到 <a href="' . e(u('p=admin&tab=api')) . '">后台 · 开放 API</a> 开启并配置开放范围</p>';
        }
        echo '</div>';
        layout_footer();
        return;
    }

    $base = trim((string)cfg('site_url', ''));
    if ($base === '') {
        $https = app_is_https();
        $base = ($https ? 'https://' : 'http://') . (string)($_SERVER['HTTP_HOST'] ?? 'your-forum.example.com');
    }
    $base = rtrim($base, '/');
    $guestOk = (int)cfg('api_guest', 0) === 1;
    $rateTok = (int)cfg('api_rate_token', 120);
    $rateGuest = (int)cfg('api_rate_guest', 30);
    $terms = (string)cfg('api_terms', '');
    if ($terms === '') {
        $terms = api_terms_template();
    }

    echo page_head('开放 API · 开发者文档', '用一套 HTTP 接口，把' . (string)cfg('site_name', '论坛') . '装进你自己的客户端、机器人或自定义界面');

    /* 状态速览 */
    echo '<div class="card form-card"><h2 class="card-title">当前状态</h2><div class="api-status-row">' .
        '<span class="badge badge-accent">API 开放中</span>' .
        '<span class="badge">访客' . ($guestOk ? '可' : '不可') . '免令牌调用</span>' .
        '<span class="badge">令牌 ' . ($rateTok > 0 ? $rateTok . ' 次/分钟' : '不限速') . '</span>' .
        '<span class="badge">访客 ' . ($rateGuest > 0 ? $rateGuest . ' 次/分钟' : '不限速') . '</span>' .
        '<span class="badge">条款版本 ' . e(api_terms_fp()) . '</span>' .
        '</div>' .
        '<p class="hint">接口地址：<code>' . e($base) . '/index.php?api=<b>端点名</b></code> · 开放范围由站长随时调整，本文档实时反映最新状态。</p></div>';

    /* 快速开始 */
    echo '<div class="card form-card"><h2 class="card-title">快速开始</h2>' .
        '<p class="muted">三步接入：① 在<a href="' . e(u('p=settings#api')) . '">个人设置 → 开放 API</a> 阅读条款并签发令牌（仅显示一次，请保存好）→ ② 携带令牌调用接口 → ③ 用返回的 JSON 渲染你自己的界面。</p>' .
        '<p class="hint" style="line-height:2">读取帖子列表（访客可调时无需令牌）：<br>' .
        '<code class="api-code">curl "' . e($base) . '/index.php?api=threads.list&amp;per_page=10"</code><br>' .
        '携带令牌发帖（JSON 或表单均可）：<br>' .
        '<code class="api-code">curl -X POST "' . e($base) . '/index.php?api=threads.create" \<br>' .
        '&nbsp;&nbsp;-H "Authorization: Bearer <b>mf_你的令牌</b>" -H "Content-Type: application/json" \<br>' .
        '&nbsp;&nbsp;-d \'{"board":1,"title":"来自客户端","content":"**Markdown** 也支持"}\'</code></p>' .
        '<p class="hint">发帖 / 回复与网页端走完全相同的流程：发言间隔、AI 审核（违规自动隐藏并可申诉）、操作日志、@ 通知一应俱全；响应中的 <code>moderation:"queued"</code> 表示已进入后台审核。</p></div>';

    /* 认证 */
    echo '<div class="card form-card"><h2 class="card-title">认证方式</h2>' .
        '<p class="muted">令牌等同于你的账号身份，推荐用请求头携带（三种方式按优先级依次尝试）：</p>' .
        '<p class="hint" style="line-height:2">' .
        '① <code>Authorization: Bearer mf_xxxx</code>（推荐，任何 HTTP 库都支持）<br>' .
        '② <code>X-API-Token: mf_xxxx</code>（自定义头）<br>' .
        '③ <code>?token=mf_xxxx</code>（仅调试用：会进服务器日志与浏览器历史，不建议生产使用）</p>' .
        '<p class="hint">· 令牌在服务端只保存 SHA-256 哈希，泄露后请在「个人设置」重置（旧令牌立即失效）<br>' .
        '· 账号被封禁 / 注销后令牌即刻失效；管理员也可在后台撤销某个令牌<br>' .
        '· 令牌认证不走浏览器会话，没有 CSRF 风险，也不受「保持登录」影响</p></div>';

    /* 响应与错误 */
    echo '<div class="card form-card"><h2 class="card-title">响应格式与错误码</h2>' .
        '<p class="hint" style="line-height:2">成功：<code>{"ok": true, "data": { ... }}</code>（HTTP 200）<br>' .
        '失败：<code>{"ok": false, "error": {"code": "…", "message": "…"}}</code><br>' .
        '每次响应都携带 <code>X-RateLimit-Limit / Remaining / Reset</code> 头，超限返回 429 并附 <code>Retry-After</code>。</p>' .
        '<div class="admin-list">' .
        '<div class="admin-row"><code>api_disabled</code><span class="muted">503 · 本站未开放 API</span></div>' .
        '<div class="admin-row"><code>token_required</code><span class="muted">401 · 需要令牌（未带 / 端点非公开 / 私密论坛）</span></div>' .
        '<div class="admin-row"><code>invalid_token</code><span class="muted">401 · 令牌无效或账号状态异常</span></div>' .
        '<div class="admin-row"><code>endpoint_disabled</code><span class="muted">403 · 该端点被站长关闭</span></div>' .
        '<div class="admin-row"><code>unknown_endpoint</code><span class="muted">404 · 端点不存在</span></div>' .
        '<div class="admin-row"><code>method_not_allowed</code><span class="muted">405 · 请求方法不符（GET/POST）</span></div>' .
        '<div class="admin-row"><code>rate_limited</code><span class="muted">429 · 触发限速，按 Retry-After 等待</span></div>' .
        '<div class="admin-row"><code>agreement_required</code><span class="muted">403 · 站点开启协议门禁，先调 docs.agree</span></div>' .
        '<div class="admin-row"><code>flood_control</code><span class="muted">429 · 发言间隔中（与网页端同一条规则）</span></div>' .
        '<div class="admin-row"><code>muted</code><span class="muted">403 · 账号被禁言</span></div>' .
        '<div class="admin-row"><code>validation</code><span class="muted">400 · 参数缺失 / 超长 / 非法（API 不静默截断）</span></div>' .
        '<div class="admin-row"><code>not_found</code><span class="muted">404 · 内容不存在（含他人不可见的审核中内容）</span></div>' .
        '<div class="admin-row"><code>forbidden</code><span class="muted">403 · 无权操作该内容</span></div>' .
        '<div class="admin-row"><code>server_error</code><span class="muted">500 · 服务器内部错误</span></div>' .
        '</div></div>';

    /* 端点全表（按分组） */
    echo '<div class="card form-card"><h2 class="card-title">端点清单（' . count($defs['endpoints']) . ' 个）</h2>' .
        '<p class="hint">标记 <span class="badge badge-warn">✎ 写入</span> 的端点需要令牌，且在开启协议门禁的站点上需先调用 <code>docs.agree</code>；其余端点为只读公开端点（访客可用性见上方状态）。被站长关闭的端点在此标记为「未开放」。</p>';
    foreach ($defs['groups'] as $gk => $gname) {
        echo '<h3 class="api-group-title">' . e($gname) . '</h3><div class="admin-list">';
        foreach ($defs['endpoints'] as $ep => $d) {
            if ($d[0] !== $gk) {
                continue;
            }
            $write = (int)$d[4] === 1;
            $st = api_ep_on((string)$ep)
                ? ($write ? '<span class="badge badge-warn">✎ 写入 · 需令牌</span>' : '<span class="badge badge-accent">开放</span>')
                : '<span class="badge">未开放</span>';
            echo '<div class="admin-row api-doc-row"><span class="api-doc-main"><code>' . e($ep) . '</code> <span class="badge">' . e($d[1]) . '</span> ' . $st .
                '<br><span class="muted">' . e($d[3]) . '</span></span></div>';
        }
        echo '</div>';
    }
    echo '</div>';

    /* 条款 */
    echo '<div class="card form-card" id="terms"><h2 class="card-title">开放 API 使用条款</h2>' .
        '<p class="muted">签发令牌前需阅读并同意以下条款（版本 ' . e(api_terms_fp()) . '）：</p>' .
        '<details class="api-terms"><summary>展开阅读全文</summary><div class="md">' . md_render($terms) . '</div></details>' .
        ($me ? '<p class="hint">还没有令牌？到 <a href="' . e(u('p=settings#api')) . '">个人设置 → 开放 API</a> 一键签发。</p>'
             : '<p class="hint"><a href="' . e(u('p=login')) . '">登录</a> 后即可在「个人设置」签发令牌。</p>') .
        '</div>';
    layout_footer();
}

/** @ 提及补全数据源（v1.16.0）：登录用户可用，按关键词过滤用户名，JSON 输出 */
function page_mention_api(): void
{
    header('Content-Type: application/json; charset=utf-8');
    if (!current_user() || !feat_on('mention')) {
        json_response(['ok' => false, 'users' => []]);
    }
    $q = trim((string)($_GET['q'] ?? ''));
    $q = cut_str($q, 20);
    $out = [];
    foreach (user_all() as $uu) {
        if (!empty($uu['deleted']) || !empty($uu['banned'])) {
            continue;
        }
        $n = (string)($uu['name'] ?? '');
        if ($n === '') {
            continue;
        }
        if ($q !== '' && !txt_contains($n, $q)) {
            continue;
        }
        $out[] = $n;
        if (count($out) >= 20) {
            break;
        }
    }
    json_response(['ok' => true, 'users' => $out]);
}
