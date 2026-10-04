<?php
/**
 * 极简论坛 · 布局与公共组件
 */
defined('APP') or exit('Forbidden');

function layout_header(string $title = '', int $boardId = 0): void
{
    $c = cfg();
    $siteName = (string)($c['site_name'] ?? 'Cube Minimalist Forum');
    $siteDesc = (string)($c['site_desc'] ?? '');
    $u = current_user();
    $unread = $u ? notify_unread((int)$u['id']) : 0;
    $online = online_count();
    $accent = (string)($c['theme_color'] ?? '');
    if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $accent)) {
        $accent = '#0f766e';
    }
    // 实时刷新间隔（秒）：后台可配，0 = 关闭；仅浏览器会轮询，页面隐藏时自动暂停
    $liveInt = max(0, (int)cfg('live_interval', 20));
    ?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<meta name="live-interval" content="<?= $liveInt ?>">
<meta name="monitor-interval" content="<?= max(0, min(300, (int)cfg('monitor_interval', 5))) ?>">
<title><?= e($title !== '' ? $title . ' · ' . $siteName : $siteName) ?></title>
<link rel="stylesheet" href="<?= e(ua('assets/style.css?v=' . app_version())) ?>">
<script>window.THEME_DEFAULT=<?= json_encode((string)($c['dark_default'] ?? 'system')) ?>;window.DEMO_PORT=<?= json_encode(demo_port()) ?>;</script>
<script>(function(){try{var d=localStorage.getItem('mf-theme')||window.THEME_DEFAULT||'system';if(d==='system'){d=window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}document.documentElement.setAttribute('data-theme',d);}catch(e){}})();</script>
<style>:root{--accent:<?= e($accent) ?>;}</style>
<?php if (!empty($c['custom_css'])): ?>
<style><?= str_ireplace('</style', '', (string)$c['custom_css']) ?></style>
<?php endif; ?>
</head>
<body>
<div class="shell">
  <header class="topbar">
    <div class="topbar-in">
      <button class="icon-btn nav-toggle" id="navToggle" aria-label="板块导航" aria-expanded="false">☰</button>
      <a class="brand" href="<?= e(u('p=home')) ?>"><span class="logo" aria-hidden="true"></span><b><?= e($siteName) ?></b></a>
      <span class="brand-desc"><?= e($siteDesc) ?></span>
      <nav class="top-links">
        <a class="nav-link" href="<?= e(u('p=announcements')) ?>">公告<span class="dot" id="notifyDot"<?= $unread > 0 ? '' : ' hidden' ?> title="<?= $unread ?> 条未读通知" aria-label="<?= $unread ?> 条未读通知"></span></a>
      </nav>
      <div class="flex1"></div>
      <button class="icon-btn" id="themeToggle" title="切换深色 / 浅色模式" aria-label="切换深色模式">◐</button>
      <?php if ($u): ?>
        <a class="nav-link" href="<?= e(u('p=user&id=' . (int)$u['id'])) ?>"><?= e((string)$u['name']) ?></a>
        <?php if (!empty($u['admin'])): ?><a class="nav-link" href="<?= e(u('p=admin')) ?>">后台</a><?php endif; ?>
        <a class="nav-link hide-sm" href="<?= e(u('p=settings')) ?>">个人设置</a>
        <form method="post" action="<?= e(u('a=logout')) ?>" class="inline"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">退出</button></form>
      <?php else: ?>
        <a class="btn btn-ghost btn-sm" href="<?= e(u('p=login')) ?>">登录</a>
        <a class="btn btn-primary btn-sm" href="<?= e(u('p=register')) ?>">注册</a>
      <?php endif; ?>
    </div>
  </header>
  <div class="wrap">
    <aside class="sidebar" id="sidebar">
      <div class="side-block">
        <div class="side-title">板块</div>
        <a class="side-item<?= $boardId === 0 ? ' on' : '' ?>" href="<?= e(u('p=home')) ?>">
          <span class="side-name">全部帖子</span><span class="side-desc">最新帖与最新回复</span>
        </a>
        <?php foreach (board_all() as $b): ?>
          <a class="side-item<?= $boardId === (int)$b['id'] ? ' on' : '' ?>" href="<?= e(u('p=board&id=' . (int)$b['id'])) ?>">
            <span class="side-name"><?= e((string)$b['name']) ?></span>
            <span class="side-desc"><?= e((string)$b['desc']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
      <div class="side-block side-online"><span class="live-dot" aria-hidden="true"></span><b id="onlineNum"><?= (int)$online ?></b>&nbsp;人在线</div>
    </aside>
    <div class="side-mask" id="sideMask" hidden></div>
    <main class="main" id="main">
<?php if (!empty($GLOBALS['ENV_WARN']) && $u && !empty($u['admin'])): ?>
<div class="flash flash-warn" role="alert"><b>环境警告：</b><?= e((string)$GLOBALS['ENV_WARN']) ?></div>
<?php endif; ?>
<?= flash_render() ?>
<?php
}

function layout_footer(): void
{
    $c = cfg();
    // 页脚可自定义：后台「主题」页配置；留空则使用默认文案
    $left = trim((string)($c['footer_text'] ?? ''));
    if ($left === '') {
        $left = (string)($c['site_name'] ?? '') . ' · Cube Minimalist Forum v' . app_version();
    }
    $note = trim((string)($c['footer_note'] ?? ''));
    if ($note === '') {
        $note = '纯文字 · 文件存储 · 无数据库';
    }
    ?>
    </main>
  </div>
  <footer class="footer">
    <span><?= e($left) ?></span>
    <span class="muted"><?= e($note) ?></span>
  </footer>
</div>
<script src="<?= e(ua('assets/app.js?v=' . app_version())) ?>" defer></script>
</body>
</html>
<?php
}

/* ---------------- 公共内容组件 ---------------- */

/** 帖子卡片（列表页通用，只使用索引字段） */
function thread_card(array $t, bool $withBoard = true): string
{
    $tid = (int)$t['id'];
    $badges = '';
    if (!empty($t['pinned'])) {
        $badges .= '<span class="badge badge-accent">置顶</span>';
    }
    if (!empty($t['locked'])) {
        $badges .= '<span class="badge">锁定</span>';
    }
    if (!empty($t['hidden'])) {
        $badges .= '<span class="badge badge-warn">审核中</span>';
    }
    $board = '';
    if ($withBoard) {
        $bn = board_name((int)$t['board']);
        $board = '<a class="tcard-board" href="' . e(u('p=board&id=' . (int)$t['board'])) . '">' . e($bn) . '</a>';
    }
    return '<article class="tcard" data-tid="' . $tid . '" data-lr="' . max((int)($t['created'] ?? 0), (int)($t['last_reply'] ?? 0)) . '">' .
        '<div class="tcard-main">' .
          '<a class="tcard-title" href="' . e(u('p=thread&id=' . $tid)) . '">' . e((string)$t['title']) . '</a>' .
          '<div class="tcard-meta">' .
            '<a class="tcard-author" href="' . e(u('p=user&id=' . (int)$t['author'])) . '">' . e(uname((int)$t['author'])) . '</a>' .
            $board .
            '<span>' . fmt_time((int)$t['created']) . '</span>' .
            '<span class="muted">回复 ' . (int)($t['replies'] ?? 0) . '</span>' .
            '<span class="muted">赞 ' . (int)($t['likes'] ?? 0) . '</span>' .
          '</div>' .
        '</div>' .
        '<div class="tcard-side">' . $badges . '</div>' .
      '</article>';
}

/** 点赞按钮（帖子和回复通用） */
function like_btn(string $type, int $tid, int $rid, array $likes, int $uid): string
{
    $liked = in_array($uid, array_map('intval', $likes), true);
    $qs = 'a=like&type=' . $type . '&tid=' . $tid . ($rid > 0 ? '&rid=' . $rid : '');
    return '<form method="post" action="' . e(u($qs)) . '" class="inline">' .
        csrf_field() . hidden_back() .
        '<button class="like-btn' . ($liked ? ' liked' : '') . '" type="submit" title="' . ($liked ? '取消点赞' : '点赞') . '">' .
        ($liked ? '♥' : '♡') . ' <span>' . count($likes) . '</span></button></form>';
}

/** 举报折叠框（帖子和回复通用） */
function report_box(string $type, int $tid, int $rid): string
{
    $qs = 'a=report';
    return '<details class="report-box">' .
        '<summary>举报</summary>' .
        '<form method="post" action="' . e(u($qs)) . '" data-confirm="确认举报该内容？内容将立即隐藏并进入审核。">' .
        '<input type="hidden" name="type" value="' . e($type) . '">' .
        '<input type="hidden" name="tid" value="' . $tid . '">' .
        '<input type="hidden" name="rid" value="' . $rid . '">' .
        csrf_field() . hidden_back() .
        '<textarea name="reason" rows="2" maxlength="200" required placeholder="请填写举报理由（必填）"></textarea>' .
        '<button class="btn btn-danger btn-sm" type="submit">提交举报</button>' .
        '</form></details>';
}

/** 通用卡片头 */
function page_head(string $title, string $sub = '', string $extra = ''): string
{
    return '<div class="page-head"><div><h1 class="page-title">' . e($title) . '</h1>' .
        ($sub !== '' ? '<p class="page-sub">' . e($sub) . '</p>' : '') .
        '</div>' . $extra . '</div>';
}
