<?php
/**
 * 极简论坛 · 布局与公共组件
 */
defined('APP') or exit('Forbidden');

/**
 * v1.18.0：样式引入策略——会话首个页面直接内联全部 CSS（渲染不被样式表请求阻塞，
 * 跨网高延迟链路首屏少一个往返）；之后的页面引用强缓存的外链（浏览器零请求命中）。
 * 门禁页/协议中心等独立页面始终内联（访客多为冷会话，见 doc_layout_header）。
 */
function layout_css_html(bool $alwaysInline = false): string
{
    $href = e(u('p=asset&f=style.css&v=' . app_version()));
    $inline = false;
    if ($alwaysInline) {
        $inline = true;
    } elseif (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['css_inline_done'])) {
        $inline = true;
    }
    if (!$inline) {
        return '<link rel="stylesheet" href="' . $href . '" fetchpriority="high">';
    }
    $css = (string)@file_get_contents(dirname(DATA_DIR) . '/assets/style.css');
    if ($css === '') {
        /* 读不到样式表（异常部署）时不置会话标记，下个页面重试内联而非永久退化为外链 */
        return '<link rel="stylesheet" href="' . $href . '" fetchpriority="high">';
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['css_inline_done'] = 1;
    }
    /* </style 防逃逸与 custom_css 同规则；外链仍保留，作为缓存与回退通道 */
    return '<style id="mf-inline-css">' . str_ireplace('</style', '', $css) . '</style>' .
        '<link rel="preload" as="style" href="' . $href . '">';
}

/** <head> 公共段（论坛壳与协议独立页共用）：编码 / 视口 / 图标 / 主题初始化 / 样式
 *  $forceInlineCss=true 时样式必定内联（门禁/协议页访客多为冷会话，不依赖外链） */
function layout_head_common(string $title = '', bool $forceInlineCss = false): void
{
    $c = cfg();
    $siteName = (string)($c['site_name'] ?? 'Cube Minimalist Forum');
    $accent = (string)($c['theme_color'] ?? '');
    if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $accent)) {
        $accent = '#0f766e';
    }
    /* v1.21.0 SEO：标题模板 / 描述 / 关键词 / 收录策略 / OG / 自定义 head */
    $seoDesc = trim((string)($c['seo_description'] ?? ''));
    if ($seoDesc === '') {
        $seoDesc = trim((string)($c['site_desc'] ?? ''));
    }
    $tpl = trim((string)($c['seo_title_tpl'] ?? ''));
    if ($tpl === '') {
        $tpl = '{page} · {site}';
    }
    $pageTitle = $title !== '' ? str_replace(['{page}', '{site}'], [$title, $siteName], $tpl) : $siteName;
    /* v1.22.0 拓展：站点标题过滤器（插件可改写每页 <title> 文本） */
    $pageTitle = (string)mf_apply_filters('site_title', $pageTitle, $title);
    $siteIcon = (string)($c['site_icon'] ?? '');
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<title><?= e($pageTitle) ?></title>
<?php if ($seoDesc !== ''): ?><meta name="description" content="<?= e(cut_str($seoDesc, 160)) ?>">
<?php endif;
if (trim((string)($c['seo_keywords'] ?? '')) !== ''): ?><meta name="keywords" content="<?= e(trim((string)$c['seo_keywords'])) ?>">
<?php endif;
if (trim((string)($c['seo_robots'] ?? '')) !== ''): ?><meta name="robots" content="<?= e(trim((string)$c['seo_robots'])) ?>">
<?php endif;
if ((int)($c['seo_og'] ?? 1) === 1): ?>
<meta property="og:site_name" content="<?= e($siteName) ?>">
<meta property="og:title" content="<?= e($pageTitle) ?>">
<?php if ($seoDesc !== ''): ?><meta property="og:description" content="<?= e(cut_str($seoDesc, 160)) ?>">
<?php endif; ?>
<meta property="og:type" content="website">
<?php $siteUrl = rtrim(trim((string)($c['site_url'] ?? '')), '/');
if ($siteUrl !== '' && preg_match('#^https?://#i', $siteUrl)):
    $ogReq = (string)($_SERVER['REQUEST_URI'] ?? '/');
    if ($ogReq === '' || $ogReq[0] !== '/') {
        $ogReq = '/';
    } ?>
<meta property="og:url" content="<?= e($siteUrl . e($ogReq)) ?>">
<?php if ($siteIcon !== '' && strpos($siteIcon, '/') === false && is_file(DATA_DIR . '/upload/' . $siteIcon)): ?>
<meta property="og:image" content="<?= e($siteUrl . '/' . u('p=icon&v=' . (int)@filemtime(DATA_DIR . '/upload/' . $siteIcon))) ?>">
<?php endif; endif; endif;
if (trim((string)($c['seo_extra_head'] ?? '')) !== ''): echo trim((string)$c['seo_extra_head']) . "\n"; endif;
if ($siteIcon !== '' && strpos($siteIcon, '/') === false && is_file(DATA_DIR . '/upload/' . $siteIcon)):
    $iv = (int)@filemtime(DATA_DIR . '/upload/' . $siteIcon);
    $iext = strtolower(pathinfo($siteIcon, PATHINFO_EXTENSION)); ?>
<link rel="icon" href="<?= e(u('p=icon&v=' . $iv)) ?>"<?= $iext === 'svg' ? ' type="image/svg+xml"' : '' ?>>
<?php if (in_array($iext, ['png', 'jpg', 'gif'], true)): ?>
<link rel="apple-touch-icon" href="<?= e(u('p=icon&v=' . $iv)) ?>">
<?php endif; ?>
<?php else: ?>
<link rel="icon" href="<?= e(u('p=asset&f=favicon.svg')) ?>" type="image/svg+xml">
<link rel="icon" href="<?= e(u('p=asset&f=favicon.ico')) ?>" sizes="32x32">
<link rel="apple-touch-icon" href="<?= e(u('p=asset&f=apple-touch-icon.png')) ?>">
<?php endif; ?>
<?php echo layout_css_html($forceInlineCss); ?>
<script>window.THEME_DEFAULT=<?= json_encode((string)($c['dark_default'] ?? 'system')) ?>;window.DEMO_PORT=<?= json_encode(demo_port()) ?>;window.MF_EMOJI=<?= feat_on('emoji') ? 'true' : 'false' ?>;</script>
<script>(function(){try{var d=localStorage.getItem('mf-theme')||window.THEME_DEFAULT||'system';if(d==='system'){d=window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}document.documentElement.setAttribute('data-theme',d);}catch(e){}})();</script>
<style>:root{--accent:<?= e($accent) ?>;}</style>
<?php if (!empty($c['custom_css'])): ?>
<style><?= str_ireplace('</style', '', (string)$c['custom_css']) ?></style>
<?php endif;
}

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
    // v1.18.0：Ping 测量间隔（秒）：后台可配，0 = 关闭，5~300
    $pingInt = max(0, min(300, (int)cfg('ping_interval', 15)));
    ?>
<!DOCTYPE html>
<html lang="<?= e(lang_html()) ?>" data-theme="light">
<head>
<?php layout_head_common($title); ?>
<meta name="live-interval" content="<?= $liveInt ?>">
<meta name="ping-interval" content="<?= $pingInt ?>">
<meta name="monitor-interval" content="<?= max(0, min(300, (int)cfg('monitor_interval', 5))) ?>">
<script src="<?= e(u('p=asset&f=app.js&v=' . app_version())) ?>" defer></script>
<?php mf_do_action('page_head'); /* v1.22.0 拓展：插件自定义 <head> 输出（meta/样式等） */ ?>
</head>
<body>
<div class="shell">
  <header class="topbar">
    <div class="topbar-in">
      <button class="icon-btn nav-toggle" id="navToggle" aria-label="<?= e(t('板块导航')) ?>" aria-expanded="false">☰</button>
      <a class="brand" href="<?= e(u('p=home')) ?>"><span class="logo" aria-hidden="true"></span><b><?= e($siteName) ?></b></a>
      <span class="brand-desc"><?= e($siteDesc) ?></span>
      <nav class="top-links">
        <a class="nav-link" href="<?= e(u('p=announcements')) ?>"><?= e(t('公告')) ?><span class="dot" id="notifyDot"<?= $unread > 0 ? '' : ' hidden' ?> title="<?= $unread ?> 条未读通知" aria-label="<?= $unread ?> 条未读通知"></span></a>
      </nav>
      <div class="flex1"></div>
      <button class="icon-btn" id="themeToggle" title="<?= e(t('切换深色 / 浅色模式')) ?>" aria-label="<?= e(t('切换深色模式')) ?>">◐</button>
      <?php if ($u): ?>
        <a class="nav-link" href="<?= e(u('p=user&id=' . (int)$u['id'])) ?>"><?= e((string)$u['name']) ?></a>
        <?php if (!empty($u['admin'])): ?><a class="nav-link" href="<?= e(u('p=admin')) ?>"><?= e(t('后台')) ?></a><?php endif; ?>
        <a class="nav-link hide-sm" href="<?= e(u('p=settings')) ?>"><?= e(t('个人设置')) ?></a>
        <form method="post" action="<?= e(u('a=logout')) ?>" class="inline"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit"><?= e(t('退出')) ?></button></form>
      <?php else: ?>
        <a class="btn btn-ghost btn-sm" href="<?= e(u('p=login')) ?>"><?= e(t('登录')) ?></a>
        <?php if (feat_on('register')): ?><a class="btn btn-primary btn-sm" href="<?= e(u('p=register')) ?>"><?= e(t('注册')) ?></a><?php endif; ?>
      <?php endif; ?>
    </div>
  </header>
  <div class="wrap">
    <aside class="sidebar" id="sidebar">
      <div class="side-block">
        <div class="side-title"><?= e(t('板块')) ?></div>
        <a class="side-item<?= $boardId === 0 ? ' on' : '' ?>" href="<?= e(u('p=home')) ?>">
          <span class="side-name"><?= e(t('全部帖子')) ?></span><span class="side-desc"><?= e(t('最新帖与最新回复')) ?></span>
        </a>
        <?php foreach (board_all() as $b): ?>
          <a class="side-item<?= $boardId === (int)$b['id'] ? ' on' : '' ?>" href="<?= e(u('p=board&id=' . (int)$b['id'])) ?>">
            <span class="side-name"><?= e((string)$b['name']) ?></span>
            <span class="side-desc"><?= e((string)$b['desc']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php if (feat_on('search')): ?>
    <div class="side-block side-search">
      <form method="get" action="index.php" role="search">
        <input type="hidden" name="p" value="search">
        <?php if (demo_port() !== ''): ?><input type="hidden" name="XTransformPort" value="<?= e(demo_port()) ?>"><?php endif; ?>
        <input class="input input-sm" type="search" name="q" maxlength="50" placeholder="<?= e(t('搜索帖子…')) ?>" aria-label="<?= e(t('站内搜索')) ?>">
      </form>
    </div>
    <?php endif; ?>
      <div class="side-block side-online"><?php if (feat_on('online')): ?><a class="online-link" href="<?= e(u('p=online')) ?>" title="<?= e(t('查看在线名单')) ?>"><span class="live-dot" aria-hidden="true"></span><b id="onlineNum"><?= (int)$online ?></b>&nbsp;<?= e(t('人在线')) ?><span class="online-more" aria-hidden="true">›</span></a><?php else: ?><span class="online-link"><span class="live-dot" aria-hidden="true"></span><b><?= (int)$online ?></b>&nbsp;<?= e(t('人在线')) ?></span><?php endif; ?></div>
      <div class="side-block side-ping"<?php if ($pingInt > 0): ?> title="浏览器到服务器的往返延迟（每 <?= $pingInt ?> 秒自动测量，后台可调）"<?php else: ?> hidden<?php endif; ?>><span class="online-link"><span class="live-dot" aria-hidden="true"></span>Ping&nbsp;<b id="pingVal">--</b><span class="muted">&nbsp;ms</span></span></div>
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
        /* v1.21.0：默认文案随存储引擎变化（不再固定宣称「无数据库」） */
        $note = Store::engine() === 'db' ? '纯文字 · 数据库存储 · 高性能' : '纯文字 · 文件存储 · 零依赖';
    }
    /* v1.16.0：页脚协议入口（后台「协议」页开关） */
    $docLinks = [];
    if ((int)($c['doc_footer'] ?? 0) === 1) {
        foreach (doc_list() as $k => $d) {
            $docLinks[] = '<a href="' . e(u('p=doc&type=' . $k)) . '">' . e($d['title']) . '</a>';
        }
    }
    /* v1.19.0：开放 API 入口（API 开启时自动显示，客户端开发者从这里进文档） */
    if ((int)($c['api_enabled'] ?? 0) === 1) {
        $docLinks[] = '<a href="' . e(u('p=api_docs')) . '">' . e(t('开放 API')) . '</a>';
    }
    ?>
    </main>
  </div>
  <footer class="footer">
    <span><?= e($left) ?></span>
    <?php if ($docLinks): ?><span class="footer-docs"><?= implode(' · ', $docLinks) ?></span><?php endif; ?>
    <span class="muted"><?= e($note) ?></span>
  </footer>
</div>
<div id="toast" class="toast" role="status" aria-live="polite"></div>
<?php mf_do_action('page_footer'); /* v1.22.0 拓展：插件自定义 </body> 前输出（统计代码/悬浮组件等） */ ?>
</body>
</html>
<?php
}

/* ---------------- v1.18.0：协议独立页布局（协议中心 / 协议门禁） ----------------
 * 用户反馈：门禁与协议查看长在论坛壳里（侧栏“全部帖子”还在旁边），不像个正式页面。
 * 这里给协议自己的完整界面：迷你顶栏 + 阅读版心，不加载论坛 app.js（保持极简、冷会话零额外请求）。
 * 样式始终内联：门禁页访客多为未同意协议的冷会话，不能依赖外链样式表。 */
function doc_layout_header(string $title, string $sub = ''): void
{
    $c = cfg();
    $siteName = (string)($c['site_name'] ?? 'Cube Minimalist Forum');
    $siteDesc = (string)($c['site_desc'] ?? '');
    $u = current_user();
?>
<!DOCTYPE html>
<html lang="<?= e(lang_html()) ?>" data-theme="light">
<head>
<?php layout_head_common($title, true); ?>
</head>
<body>
<div class="docshell">
  <header class="doc-topbar">
    <a class="brand" href="<?= e(u('p=home')) ?>"><span class="logo" aria-hidden="true"></span><b><?= e($siteName) ?></b></a>
    <span class="brand-desc"><?= e($siteDesc) ?></span>
    <span class="flex1"></span>
    <nav class="top-links">
      <a class="nav-link" href="<?= e(u('p=doc')) ?>">协议中心</a>
      <?php if ($u): ?>
        <a class="nav-link" href="<?= e(u('p=home')) ?>">进入论坛</a>
      <?php elseif (feat_on('register')): ?>
        <a class="nav-link" href="<?= e(u('p=login')) ?>">登录 / 注册</a>
      <?php else: ?>
        <a class="nav-link" href="<?= e(u('p=login')) ?>">登录</a>
      <?php endif; ?>
    </nav>
  </header>
  <div class="doc-page">
    <?= flash_render() ?>
    <div class="doc-hero">
      <h1><?= e($title) ?></h1>
      <?php if ($sub !== ''): ?><p><?= e($sub) ?></p><?php endif; ?>
    </div>
<?php
}

function doc_layout_footer(): void
{
    $c = cfg();
    $siteName = (string)($c['site_name'] ?? 'Cube Minimalist Forum');
?>
  </div>
  <footer class="footer">
    <span><?= e($siteName) ?> · 协议中心</span>
    <span class="muted">由 Cube Minimalist Forum v<?= e(app_version()) ?> 驱动</span>
  </footer>
</div>
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
            '<span class="muted hide-sm">浏览 ' . (int)($t['views'] ?? 0) . '</span>' .
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

/** 用户签名行（v1.14.0）：开关关闭或未设置时返回空串 */
function user_sig_line(int $uid): string
{
    if (!feat_on('signature') || $uid <= 0) {
        return '';
    }
    $sig = trim((string)(user_by_id($uid)['sig'] ?? ''));
    return $sig !== '' ? '<div class="user-sig" title="用户签名">— ' . e(cut_str($sig, 60)) . '</div>' : '';
}
