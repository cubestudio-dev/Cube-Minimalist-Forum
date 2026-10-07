<?php
/**
 * 极简论坛 · 开放 API（v1.19.0）
 * 面向第三方客户端 / 机器人 / 自定义前端的只读 + 受控写入接口。
 *
 * 设计原则（与论坛本体完全整合）：
 * - 同一套数据层与业务规则：发帖 / 回复 / 点赞 / 删除复用与网页端完全相同的
 *   校验链（功能开关、禁言、发言间隔、字数上限、AI 审核异步预检、操作日志、@ 通知）；
 * - 版主完全掌控：总开关 + 按端点开关 + 访客调用开关 + 双层限速 + CORS + 使用条款，全部在后台「API」页配置；
 * - 令牌（Token）认证：用户在「个人设置」阅读并同意使用条款后自助签发，
 *   服务端只存 SHA-256 哈希，泄露可自助重置；与浏览器会话完全解耦，天然免疫 CSRF；
 * - 统一响应：{"ok":true,"data":...} / {"ok":false,"error":{code,message}}，
 *   携配 X-RateLimit-* 限速头，错误码见 p=api_docs 开发者文档。
 *
 * 路由：index.php?api=<group.action>，由 index.php 在页面路由之前调用 api_handle()。
 */
defined('APP') or exit('Forbidden');

/* ================= 端点注册表（后台开关、限流类别、文档页三处共用） ================= */

/**
 * 全部端点定义。公开=访客免令牌可调（还需后台开「访客调用」与该端点开关）。
 * write=1 的端点为受控写入，要求令牌；在开启协议门禁的站点上还要求先同意社区协议（docs.agree）。
 */
function api_defs(): array
{
    return [
        'groups' => [
            'site'    => '站点信息',
            'content' => '内容读取',
            'write'   => '互动写入',
            'user'    => '用户与通知',
        ],
        'endpoints' => [
            /* ---- 站点信息（公开级） ---- */
            'site.info'          => ['site', 'GET',  '站点信息', '站名 / 简介 / 版本 / 板块数 / 帖子数 / 用户数 / 是否开放注册', 0],
            'stats'              => ['site', 'GET',  '站点统计', '帖子 / 回复 / 用户 / 今日新帖 / 在线人数等汇总统计', 0],
            'boards.list'        => ['site', 'GET',  '板块列表', '全部板块（id、名称、简介、帖子数）', 0],
            'announcements.list' => ['site', 'GET',  '公告列表', '平台公告，page 分页', 0],
            'online.list'        => ['site', 'GET',  '在线名单', '当前在线用户与访客数（受「在线名单」功能开关约束）', 0],
            /* ---- 内容读取（公开级，私密论坛模式下强制要求令牌） ---- */
            'threads.list'       => ['content', 'GET', '帖子列表', 'board（可选）、tab=new|reply、page、per_page（≤50）', 0],
            'threads.get'        => ['content', 'GET', '帖子详情', 'id、page（回复分页）；含正文 Markdown 与渲染 HTML', 0],
            'search.threads'     => ['content', 'GET', '搜索帖子', 'q（1-50 字）、page', 0],
            'search.users'       => ['content', 'GET', '搜索用户', 'q（1-50 字）；返回名字 / 简介 / 角色的公开匹配', 0],
            'users.get'          => ['content', 'GET', '用户资料', 'id 或 name；公开资料（不含邮箱）', 0],
            /* ---- 互动写入（令牌级；行为与网页端完全一致，含 AI 审核） ---- */
            'threads.create'     => ['write', 'POST', '发布帖子', 'board、title（≤30 字）、content（≤1500 字）', 1],
            'replies.create'     => ['write', 'POST', '发表回复', 'tid、content（≤1000 字）', 1],
            'likes.toggle'       => ['write', 'POST', '点赞 / 取消', 'type=t|r、tid、rid（回复时必填）', 1],
            'threads.delete'     => ['write', 'POST', '删除帖子', 'tid（仅作者或管理员）', 1],
            'replies.delete'     => ['write', 'POST', '删除回复', 'tid、rid（仅作者或管理员）', 1],
            /* ---- 用户与通知（令牌级） ---- */
            'me.info'            => ['user', 'GET',  '我的信息', '令牌所属账号资料、计数器与未读通知数', 2],
            'notifications.list' => ['user', 'GET',  '我的通知', 'page 分页；未读在前与前台一致', 2],
            'notifications.read'       => ['user', 'POST', '通知已读', 'id（单条标记已读）', 2],
            'notifications.read_all'   => ['user', 'POST', '全部已读', '无参数', 2],
            'docs.agree'               => ['user', 'POST', '同意社区协议', '开启协议门禁的站点，客户端发帖前需调用一次（留痕）', 2],
        ],
    ];
}

/** 端点开关配置键：threads.list → api_ep_threads_list */
function api_ep_key(string $ep): string
{
    return 'api_ep_' . str_replace('.', '_', $ep);
}

/** 端点是否开放（总开关之外的单端点开关，默认开） */
function api_ep_on(string $ep): bool
{
    return (int)cfg(api_ep_key($ep), 1) === 1;
}

/* ================= 令牌管理 ================= */

/** 读全部令牌：uid => ['hash','created','agree','agree_fp']（只存哈希，绝不存明文） */
function api_tokens_all(): array
{
    $t = Store::read('api_tokens.php', []);
    return is_array($t) ? $t : [];
}

/** 某用户的令牌记录；无则 null */
function api_token_of(int $uid): ?array
{
    $rec = api_tokens_all()[$uid] ?? null;
    return is_array($rec) ? $rec : null;
}

/** 签发 / 重置令牌：返回明文（仅此一次可见）；$agreeFp 为同意条款时的条款指纹 */
function api_token_issue(int $uid, string $agreeFp = ''): string
{
    $plain = 'mf_' . bin2hex(random_bytes(24));
    $lk = Store::lock('api_tokens');
    $all = api_tokens_all();
    $old = is_array($all[$uid] ?? null) ? $all[$uid] : [];
    $all[$uid] = [
        'hash'    => hash('sha256', $plain),
        'created' => empty($old['created']) || !empty($agreeFp) ? time() : (int)$old['created'],
        'agree'   => time(),
        'agree_fp'=> $agreeFp,
    ];
    Store::write('api_tokens.php', $all);
    Store::unlock($lk);
    return $plain;
}

/** 撤销令牌 */
function api_token_revoke(int $uid): void
{
    $lk = Store::lock('api_tokens');
    $all = api_tokens_all();
    unset($all[$uid]);
    Store::write('api_tokens.php', $all);
    Store::unlock($lk);
}

/** 从请求中提取令牌：Authorization: Bearer → X-API-Token → ?token=（兼容顺序） */
function api_token_extract(): string
{
    $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^\s*Bearer\s+([A-Za-z0-9_\-\.=]+)\s*$/i', $h, $m)) {
        return trim((string)$m[1]);
    }
    $x = (string)($_SERVER['HTTP_X_API_TOKEN'] ?? '');
    if ($x !== '') {
        return trim($x);
    }
    $q = isset($_GET['token']) && is_string($_GET['token']) ? trim($_GET['token']) : '';
    return $q;
}

/** 令牌 → 用户；无效 / 封禁 / 注销一律 null */
function api_token_user(string $plain): ?array
{
    if ($plain === '' || strlen($plain) > 128) {
        return null;
    }
    $hash = hash('sha256', $plain);
    foreach (api_tokens_all() as $uid => $rec) {
        if (is_array($rec) && !empty($rec['hash']) && hash_equals((string)$rec['hash'], $hash)) {
            $u = user_by_id((int)$uid);
            if ($u && empty($u['banned']) && empty($u['deleted'])) {
                return $u;
            }
            return null; // 令牌有效但账号异常：按未认证处理，绝不放行
        }
    }
    return null;
}

/* ================= 条款 ================= */

/** 内置使用条款模板（后台可改；【站名】占位符） */
function api_terms_template(): string
{
    return '【站名】开放 API 使用条款（模板，可自行修改后保存）

一、凭证安全
1. API 令牌（Token）等同于您的账号身份，请妥善保管，不得公开、转让或与他人共用；
2. 令牌泄露请立即在「个人设置」中重置；因保管不当造成的账号后果由持有者自行承担。

二、使用规范
1. 仅限将 API 用于开发与本站互补的客户端、机器人或集成工具；
2. 不得利用 API 批量注册、刷帖、刷赞、抓取全站数据或实施任何干扰本站正常运行的行为；
3. 必须遵守本站的速率限制；超出限制的请求将被暂时拒绝；
4. 通过 API 发布的内容与网页端接受完全相同的社区规范与 AI / 人工审核，违规内容将被隐藏并进入申诉流程。

三、内容与责任
1. 您通过 API 发布的一切内容的责任与网页端发布完全相同，由发布者承担；
2. 本站有权随时根据运营与安全需要，调整开放范围、限速额度或终止某个令牌 / 整体 API 的可用性；
3. API 以「现状」提供，本站不对其可用性、及时性作出担保。

四、变更
本条款更新后将公布于站内公告，继续使用 API 即视为接受更新后的条款。如有疑问请联系站长。';
}

/** 条款指纹（后台修改条款后文档页 / 同意记录随之更新版本） */
function api_terms_fp(): string
{
    return strtoupper(substr(md5((string)cfg('api_terms', '')), 0, 8));
}

/* ================= 限流（60 秒固定窗口，双轨：令牌 / 访客 IP） ================= */

/**
 * 计数一次并判定。返回 [bool 放行, int 剩余额度, int 窗口重置时间戳]。
 * $limit ≤ 0 表示不限制（后台可配 0 = 关闭限速）。
 */
function api_rate_hit(string $key, int $limit): array
{
    if ($limit <= 0) {
        return [true, -1, 0];
    }
    $w = intdiv(time(), 60) * 60;
    $lk = Store::lock('api_rate');
    $all = Store::read('api_rate.php', []);
    if (!is_array($all)) {
        $all = [];
    }
    /* 顺带清理过期窗口，文件不随时间无限增长 */
    foreach ($all as $k => $v) {
        if (!is_array($v) || (int)($v['w'] ?? 0) < $w) {
            unset($all[$k]);
        }
    }
    $cur = $all[$key] ?? null;
    if (!is_array($cur) || (int)($cur['w'] ?? 0) !== $w) {
        $cur = ['c' => 0, 'w' => $w];
    }
    $cur['c'] = (int)$cur['c'] + 1;
    $all[$key] = $cur;
    Store::write('api_rate.php', $all);
    Store::unlock($lk);
    return [(int)$cur['c'] <= $limit, max(0, $limit - (int)$cur['c']), $w + 60];
}

/** 限速响应头（放行与拒绝都输出，客户端可自适应） */
function api_rate_headers(int $limit, int $remain, int $reset): void
{
    if ($limit <= 0) {
        return;
    }
    header('X-RateLimit-Limit: ' . $limit);
    header('X-RateLimit-Remaining: ' . $remain);
    header('X-RateLimit-Reset: ' . $reset);
}

/* ================= CORS ================= */

/** 按后台配置输出 CORS 头；'' = 同源（不输出）；'*' = 全部；其他 = 逗号分隔来源白名单 */
function api_cors_out(): void
{
    $cfg = trim((string)cfg('api_cors', ''));
    if ($cfg === '') {
        return;
    }
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($cfg === '*') {
        header('Access-Control-Allow-Origin: *');
    } elseif ($origin !== '') {
        foreach (preg_split('/[\s,，;；]+/u', $cfg) ?: [] as $o) {
            $o = rtrim(trim((string)$o), '/');
            if ($o !== '' && strcasecmp($o, rtrim($origin, '/')) === 0) {
                header('Access-Control-Allow-Origin: ' . $o);
                header('Vary: Origin');
                break;
            }
        }
    }
}

/* ================= 协议门禁衔接 ================= */

/**
 * 写入类端点的协议检查：站点开启协议门禁时，令牌用户须先通过 docs.agree
 * （或管理员豁免 / 门禁未开）。网页端 Cookie 同意不迁移到 API 侧——客户端凭据独立留痕。
 */
function api_doc_required(array $user): bool
{
    if ((int)cfg('doc_gate', 0) !== 1 || doc_list() === [] || !empty($user['admin'])) {
        return false;
    }
    $all = Store::read('api_doc_agree.php', []);
    $rec = is_array($all) ? ($all[(int)$user['id']] ?? null) : null;
    return !(is_array($rec) && ($rec['fp'] ?? '') === doc_fingerprint());
}

/** 记录 API 载体的协议同意（docs.agree 端点） */
function api_doc_agree_write(array $user): void
{
    $lk = Store::lock('api_doc_agree');
    $all = Store::read('api_doc_agree.php', []);
    if (!is_array($all)) {
        $all = [];
    }
    $all[(int)$user['id']] = ['fp' => doc_fingerprint(), 't' => time()];
    Store::write('api_doc_agree.php', $all);
    Store::unlock($lk);
}

/* ================= 输出 ================= */

function api_out(array $data, int $http = 200): void
{
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function api_err(string $code, string $msg, int $http): void
{
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => false, 'error' => ['code' => $code, 'message' => $msg]], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ================= 主入口 ================= */

/**
 * API 总入口（index.php 在页面路由之前调用）。必定 exit。
 * 流程：CORS/预检 → 总开关 → 方法校验 → 令牌认证 → 私密模式 → 端点开关 →
 *       公开/令牌判定 → 限流 → 协议检查（写入类）→ 分发 → JSON 出站。
 */
function api_handle(string $ep): void
{
    api_cors_out();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Token');
        header('Access-Control-Max-Age: 600');
        http_response_code(204);
        exit;
    }

    if ((int)cfg('api_enabled', 0) !== 1) {
        api_err('api_disabled', '本站未开放 API（管理员可在后台「API」页开启）', 503);
    }

    $defs = api_defs();
    $ep = strtolower($ep);
    if (!isset($defs['endpoints'][$ep])) {
        api_err('unknown_endpoint', '端点不存在，可用端点清单见开发者文档（' . (string)cfg('site_name', '论坛') . ' 的「开放 API」页）', 404);
    }
    [$group, $method] = [$defs['endpoints'][$ep][0], $defs['endpoints'][$ep][1]];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        api_err('method_not_allowed', '该端点要求 ' . $method . ' 请求', 405);
    }
    if (!api_ep_on($ep)) {
        api_err('endpoint_disabled', '该端点未对本站访客开放（管理员可在后台调整开放范围）', 403);
    }

    /* 认证：令牌 → 用户；无效令牌直接拒绝（不降级为访客，避免凭据错误时误判为游客行为） */
    $tokenRaw = api_token_extract();
    $user = null;
    if ($tokenRaw !== '') {
        $user = api_token_user($tokenRaw);
        if (!$user) {
            api_err('invalid_token', 'API 令牌无效或账号状态异常，请到「个人设置」重置令牌', 401);
        }
    }
    $isGuest = $user === null;
    $isPublic = ((int)$defs['endpoints'][$ep][4]) === 0;

    /* 私密论坛模式：未登录连站点信息都不可见，API 一致 */
    if ($isGuest && !feat_on('guest_browse')) {
        api_err('token_required', '本论坛仅限注册用户访问，请在「个人设置」签发 API 令牌后携带调用', 401);
    }
    if ($isGuest && !$isPublic) {
        api_err('token_required', '该端点需要 API 令牌（在「个人设置」→「开放 API」签发）', 401);
    }
    if ($isGuest && (int)cfg('api_guest', 0) !== 1) {
        api_err('token_required', '本站 API 仅对持令牌的注册用户开放（管理员可在后台允许访客调用公开端点）', 401);
    }

    /* 限流：令牌按用户计数，访客按 IP 计数 */
    $limit = $isGuest
        ? max(0, (int)cfg('api_rate_guest', 30))
        : max(0, (int)cfg('api_rate_token', 120));
    [$allow, $remain, $reset] = api_rate_hit($isGuest ? 'g:' . fw_ip() : 'u:' . (int)$user['id'], $limit);
    api_rate_headers($limit, $remain, $reset);
    if (!$allow) {
        header('Retry-After: ' . max(1, $reset - time()));
        api_err('rate_limited', '请求过于频繁，请稍后再试（每分钟 ' . $limit . ' 次）', 429);
    }

    /* 写入类端点：协议门禁衔接 */
    if ((int)$defs['endpoints'][$ep][4] === 1 && $user && api_doc_required($user)) {
        api_err('agreement_required', '本站已开启社区协议门禁：请先调用 docs.agree 端点，或在网页端阅读并同意社区协议后再发内容', 403);
    }

    /* 调用日志（v1.19.0，后台可开）：写入类必记 + 读取类不记，防日志爆量 */
    if ((int)cfg('api_log', 0) === 1 && (int)$defs['endpoints'][$ep][4] === 1) {
        log_action('api_call', '通过开放 API 调用 ' . $ep . '（IP：' . fw_ip() . '）', $user ? (int)$user['id'] : null);
    }

    $fn = 'api_ep_' . str_replace('.', '_', $ep);
    if (!function_exists($fn)) {
        api_err('server_error', '端点实现缺失（程序不完整，请重新上传更新包）', 500);
    }
    try {
        $fn($user);
    } catch (Throwable $t) {
        log_action('sys_error', 'API ' . $ep . ' 失败：' . cut_str($t->getMessage(), 200), $user ? (int)$user['id'] : null);
        api_err('server_error', '服务器处理该请求时出错，请稍后重试', 500);
    }
    api_err('server_error', '端点未产生响应', 500);
}

/* ================= 输入助手 ================= */

/** JSON 请求体 / 表单字段统一取值（Content-Type: application/json 与 form 二者兼容） */
function api_in(string $k, int $max = 0): string
{
    static $body = null;
    if ($body === null) {
        $body = [];
        $ct = (string)($_SERVER['CONTENT_TYPE'] ?? '');
        if (stripos($ct, 'application/json') !== false) {
            $raw = (string)@file_get_contents('php://input');
            $j = json_decode($raw, true);
            if (is_array($j)) {
                $body = $j;
            }
        }
    }
    $v = $body[$k] ?? ($_POST[$k] ?? ($_GET[$k] ?? ''));
    if (!is_scalar($v)) {
        return '';
    }
    $v = trim((string)$v);
    if ($max > 0 && u_strlen($v) > $max) {
        return ''; // 超长直接视为无效（API 不静默截断，与网页端截断行为不同，见文档）
    }
    return $v;
}

function api_in_int(string $k, int $def = 0): int
{
    $v = api_in($k);
    return is_numeric($v) && (string)(int)$v === $v ? (int)$v : $def;
}

/** 分页归一：page ≥1，per 1..50（默认 20） */
function api_page(): array
{
    $page = max(1, api_in_int('page', 1));
    $per = api_in_int('per_page', 20);
    $per = $per > 0 ? min(50, $per) : 20;
    return [$page, $per];
}

/** 帖子公开字段（列表级，只用索引不读正文） */
function api_thread_row(array $t): array
{
    return [
        'id'         => (int)$t['id'],
        'board'      => (int)$t['board'],
        'board_name' => board_name((int)$t['board']),
        'title'      => (string)$t['title'],
        'author_id'  => (int)$t['author'],
        'author'     => uname((int)$t['author']),
        'created'    => (int)($t['created'] ?? 0),
        'replies'    => (int)($t['replies'] ?? 0),
        'likes'      => count(is_array($t['likes'] ?? null) ? $t['likes'] : []),
        'views'      => (int)($t['views'] ?? 0),
        'last_reply' => (int)($t['last_reply'] ?? 0),
        'pinned'     => !empty($t['pinned']),
        'locked'     => !empty($t['locked']),
        'hidden'     => !empty($t['hidden']),
        'url'        => 'index.php?p=thread&id=' . (int)$t['id'],
    ];
}

/** 用户公开资料（绝不包含邮箱 / 密码 / 令牌） */
function api_user_public(array $u): array
{
    return [
        'id'        => (int)$u['id'],
        'name'      => (string)$u['name'],
        'bio'       => (string)($u['bio'] ?? ''),
        'sig'       => (string)($u['sig'] ?? ''),
        'admin'     => !empty($u['admin']),
        'banned'    => !empty($u['banned']),
        'deleted'   => !empty($u['deleted']),
        'created'   => (int)($u['created'] ?? 0),
        'threads'   => (int)($u['threads'] ?? 0),
        'likes_recv'=> (int)($u['likes_recv'] ?? 0),
        'replies_recv'=> (int)($u['replies_recv'] ?? 0),
        'url'       => 'index.php?p=user&id=' . (int)$u['id'],
    ];
}

/* ================= 端点实现：站点信息 ================= */

function api_ep_site_info(?array $u): void
{
    $threads = threads_visible(thread_index());
    api_out(['data' => [
        'site_name'   => (string)cfg('site_name', ''),
        'site_desc'   => (string)cfg('site_desc', ''),
        'version'     => app_version(),
        'api_version' => 1,
        'register_open' => feat_on('register'),
        'guest_browse'  => feat_on('guest_browse'),
        'boards'      => count(board_all()),
        'threads'     => count($threads),
        'users'       => count(user_all()),
        'online'      => online_count(),
        'time'        => time(),
    ]]);
}

function api_ep_stats(?array $u): void
{
    $threads = threads_visible(thread_index());
    $replies = 0;
    $today = 0;
    $mid = strtotime('today');
    foreach ($threads as $t) {
        $replies += (int)($t['replies'] ?? 0);
        if ((int)($t['created'] ?? 0) >= $mid) {
            $today++;
        }
    }
    $latest = $threads ? api_thread_row($threads[0]) : null;
    api_out(['data' => [
        'threads'     => count($threads),
        'replies'     => $replies,
        'users'       => count(user_all()),
        'threads_today' => $today,
        'online'      => online_count(),
        'online_window' => max(30, (int)cfg('online_window', 300)),
        'latest_thread' => $latest,
        'time'        => time(),
    ]]);
}

function api_ep_boards_list(?array $u): void
{
    $out = [];
    foreach (board_all() as $b) {
        $n = 0;
        foreach (threads_visible(thread_index()) as $t) {
            if ((int)($t['board'] ?? 0) === (int)$b['id']) {
                $n++;
            }
        }
        $out[] = ['id' => (int)$b['id'], 'name' => (string)$b['name'], 'desc' => (string)$b['desc'], 'threads' => $n];
    }
    api_out(['data' => ['boards' => $out]]);
}

function api_ep_announcements_list(?array $u): void
{
    [$page, $per] = api_page();
    $all = ann_all();
    $total = count($all);
    $slice = array_slice($all, ($page - 1) * $per, $per);
    $out = [];
    foreach ($slice as $a) {
        $out[] = [
            'id'      => (int)$a['id'],
            'title'   => (string)$a['title'],
            'content' => (string)$a['content'],
            'by'      => uname((int)$a['by']),
            'created' => (int)$a['created'],
            'updated' => (int)($a['updated'] ?? 0),
        ];
    }
    api_out(['data' => ['total' => $total, 'page' => $page, 'per_page' => $per, 'announcements' => $out]]);
}

function api_ep_online_list(?array $u): void
{
    if (!feat_on('online')) {
        api_err('endpoint_disabled', '本站未开放在线名单', 403);
    }
    $w = max(30, (int)cfg('online_window', 300));
    $cut = time() - $w;
    $uids = [];
    $guests = 0;
    foreach (Store::read('online.php', []) as $rec) {
        if (!is_array($rec) || (int)($rec['t'] ?? 0) < $cut) {
            continue;
        }
        $id = (int)($rec['u'] ?? 0);
        if ($id > 0) {
            $uids[$id] = max((int)($uids[$id] ?? 0), (int)$rec['t']);
        } else {
            $guests++;
        }
    }
    $users = [];
    foreach ($uids as $id => $t) {
        $row = user_by_id($id);
        if ($row) {
            $users[] = ['id' => $id, 'name' => (string)$row['name'], 'admin' => !empty($row['admin']), 'seen' => $t];
        }
    }
    usort($users, fn($a, $b) => $b['seen'] <=> $a['seen']);
    api_out(['data' => ['online' => count($users) + $guests, 'users' => $users, 'guests' => $guests, 'window' => $w]]);
}

/* ================= 端点实现：内容读取 ================= */

function api_ep_threads_list(?array $u): void
{
    [$page, $per] = api_page();
    $tab = api_in('tab') === 'reply' ? 'reply' : 'new';
    $board = api_in_int('board', 0);
    if ($board > 0) {
        if (!board_get($board)) {
            api_err('not_found', '板块不存在', 404);
        }
        $all = threads_by_board($board);
    } else {
        $all = threads_home($tab);
    }
    /* 非管理员看不到审核中内容；作者视角在列表 API 中不展开（保持轻量） */
    $total = count($all);
    $slice = array_slice($all, ($page - 1) * $per, $per);
    api_out(['data' => [
        'total' => $total, 'page' => $page, 'per_page' => $per, 'tab' => $tab, 'board' => $board,
        'threads' => array_map('api_thread_row', $slice),
    ]]);
}

function api_ep_threads_get(?array $u): void
{
    $tid = api_in_int('id', 0);
    $t = thread_get($tid);
    if (!$t) {
        api_err('not_found', '帖子不存在', 404);
    }
    $meId = $u ? (int)$u['id'] : 0;
    $isAdmin = $u && !empty($u['admin']);
    if (!empty($t['hidden']) && !$isAdmin && $meId !== (int)$t['author']) {
        api_err('not_found', '帖子不存在或审核中', 404);
    }
    [$page, $per] = api_page();
    $reps = reply_list($tid);
    $total = count($reps);
    $slice = array_slice($reps, ($page - 1) * $per, $per);
    $out = [];
    foreach ($slice as $r) {
        if (!empty($r['hidden']) && !$isAdmin && $meId !== (int)$r['author']) {
            continue;
        }
        $out[] = [
            'id'     => (int)$r['id'],
            'author_id' => (int)$r['author'],
            'author' => uname((int)$r['author']),
            'content' => (string)$r['content'],
            'content_html' => md_render((string)$r['content']),
            'created' => (int)$r['created'],
            'likes'  => count(is_array($r['likes'] ?? null) ? $r['likes'] : []),
            'hidden' => !empty($r['hidden']),
        ];
    }
    $row = api_thread_row($t);
    $row['content'] = (string)$t['content'];
    $row['content_html'] = md_render((string)$t['content']);
    api_out(['data' => ['thread' => $row, 'total' => $total, 'page' => $page, 'per_page' => $per, 'replies' => $out]]);
}

function api_ep_search_threads(?array $u): void
{
    if (!feat_on('search')) {
        api_err('endpoint_disabled', '本站未开放搜索', 403);
    }
    $q = api_in('q', 50);
    if ($q === '') {
        api_err('validation', '缺少搜索关键词 q', 400);
    }
    [$page, $per] = api_page();
    $all = search_threads($q);
    $total = count($all);
    $slice = array_slice($all, ($page - 1) * $per, $per);
    api_out(['data' => ['q' => $q, 'total' => $total, 'page' => $page, 'per_page' => $per,
        'threads' => array_map('api_thread_row', $slice)]]);
}

function api_ep_search_users(?array $u): void
{
    $q = api_in('q', 50);
    if ($q === '') {
        api_err('validation', '缺少搜索关键词 q', 400);
    }
    $out = [];
    foreach (user_all() as $row) {
        if (!empty($row['deleted'])) {
            continue;
        }
        if (txt_contains((string)($row['name'] ?? ''), $q) || txt_contains((string)($row['bio'] ?? ''), $q) || txt_contains((string)($row['sig'] ?? ''), $q)) {
            if (count($out) >= 20) {
                break;
            }
            $out[] = api_user_public($row);
        }
    }
    api_out(['data' => ['q' => $q, 'total' => count($out), 'users' => $out]]);
}

function api_ep_users_get(?array $u): void
{
    $row = null;
    $id = api_in_int('id', 0);
    $name = api_in('name', 20);
    if ($id > 0) {
        $row = user_by_id($id);
    } elseif ($name !== '') {
        $row = user_by_name($name);
    }
    if (!$row || !empty($row['deleted'])) {
        api_err('not_found', '用户不存在', 404);
    }
    api_out(['data' => ['user' => api_user_public($row)]]);
}

/* ================= 端点实现：互动写入（与网页端同一校验链） ================= */

/** 发言间隔预判（与 flood_check 同规则；API 场景要返回精确等待秒，且不写会话草稿） */
function api_flood_wait(array $u): int
{
    $interval = max(0, (int)cfg('post_interval', 30));
    if ($interval <= 0) {
        return 0;
    }
    return max(0, (int)($u['last_post'] ?? 0) + $interval - time());
}

function api_ep_threads_create(?array $u): void
{
    if (!feat_on('post')) {
        api_err('endpoint_disabled', '本站已关闭发帖功能', 403);
    }
    $mute = mute_left($u);
    if ($mute > 0) {
        api_err('muted', '您已被禁言，剩余 ' . ceil($mute / 60) . ' 分钟', 403);
    }
    $board = api_in_int('board', 0);
    $title = api_in('title', 30);
    $content = api_in('content', 1500);
    if ($board <= 0 || !board_get($board)) {
        api_err('validation', 'board 参数无效：板块不存在', 400);
    }
    if ($title === '') {
        api_err('validation', 'title 不能为空（≤30 字）', 400);
    }
    if ($content === '') {
        api_err('validation', 'content 不能为空（≤1500 字，超长请自行裁剪）', 400);
    }
    $wait = api_flood_wait($u);
    if ($wait > 0) {
        header('Retry-After: ' . $wait);
        api_err('flood_control', '发言太频繁，请 ' . $wait . ' 秒后再试', 429);
    }
    if (!flood_check($u)) { // 极小概率并发窗口二次校验；通过即占用本次间隔
        header('Retry-After: ' . max(1, (int)cfg('post_interval', 30)));
        api_err('flood_control', '发言太频繁，请稍后再试', 429);
    }
    $tid = thread_create($board, $title, $content, (int)$u['id']);
    user_bump((int)$u['id'], 'threads', 1);
    log_action('thread_new', '通过开放 API 发布《' . cut_str($title, 40) . '》（板块：' . board_name($board) . '）');
    mentions_notify($title . "\n" . $content, (int)$u['id'], $tid, 0, $title);
    register_shutdown_function('ai_precheck_after_post', 'thread', $tid, 0, $title . "\n" . $content, (int)$u['id'], $title);
    api_out(['data' => [
        'id' => $tid,
        'url' => 'index.php?p=thread&id=' . $tid,
        'moderation' => 'queued',
        'message' => '发布成功，AI 正在后台审核内容（违规会被自动隐藏，可在站内申诉）',
    ]]);
}

function api_ep_replies_create(?array $u): void
{
    if (!feat_on('reply')) {
        api_err('endpoint_disabled', '本站已关闭回复功能', 403);
    }
    $mute = mute_left($u);
    if ($mute > 0) {
        api_err('muted', '您已被禁言，剩余 ' . ceil($mute / 60) . ' 分钟', 403);
    }
    $tid = api_in_int('tid', 0);
    $content = api_in('content', 1000);
    if ($content === '') {
        api_err('validation', 'content 不能为空（≤1000 字）', 400);
    }
    $t = thread_get($tid);
    if (!$t || !empty($t['locked'])) {
        api_err('not_found', '该帖子已锁定或不存在，无法回复', 404);
    }
    $wait = api_flood_wait($u);
    if ($wait > 0) {
        header('Retry-After: ' . $wait);
        api_err('flood_control', '发言太频繁，请 ' . $wait . ' 秒后再试', 429);
    }
    if (!flood_check($u)) {
        header('Retry-After: ' . max(1, (int)cfg('post_interval', 30)));
        api_err('flood_control', '发言太频繁，请稍后再试', 429);
    }
    $rid = reply_add($tid, $content, (int)$u['id']);
    if ($rid === null) {
        api_err('not_found', '该帖子已锁定或不存在，无法回复', 404);
    }
    log_action('reply_new', '通过开放 API 在《' . cut_str((string)($t['title'] ?? ''), 40) . '》中发表回复');
    mentions_notify($content, (int)$u['id'], $tid, (int)$rid);
    register_shutdown_function('ai_precheck_after_post', 'reply', $tid, $rid, $content, (int)$u['id']);
    api_out(['data' => [
        'id' => $rid, 'thread_id' => $tid,
        'url' => 'index.php?p=thread&id=' . $tid . '#r' . $rid,
        'moderation' => 'queued',
        'message' => '回复成功，AI 正在后台审核内容',
    ]]);
}

function api_ep_likes_toggle(?array $u): void
{
    if (!feat_on('like')) {
        api_err('endpoint_disabled', '本站已关闭点赞功能', 403);
    }
    $type = api_in('type') === 'r' ? 'r' : 't';
    $tid = api_in_int('tid', 0);
    $rid = api_in_int('rid', 0);
    if (!thread_get($tid) || ($type === 'r' && !reply_get($tid, $rid))) {
        api_err('not_found', '内容不存在', 404);
    }
    [$liked, $count] = like_toggle($type, $tid, $rid, (int)$u['id']);
    log_action($liked ? 'like' : 'unlike', '通过开放 API 对《' . cut_str((string)(thread_get($tid)['title'] ?? ''), 40) . '》' . ($type === 'r' ? '中的回复' : '') . ($liked ? '（赞）' : '（取消赞）'));
    api_out(['data' => ['liked' => $liked, 'likes' => $count]]);
}

function api_ep_threads_delete(?array $u): void
{
    $tid = api_in_int('tid', 0);
    $t = thread_get($tid);
    if (!$t) {
        api_err('not_found', '帖子不存在', 404);
    }
    $admin = !empty($u['admin']);
    if (!$admin && (int)$t['author'] !== (int)$u['id']) {
        api_err('forbidden', '只能删除自己的帖子', 403);
    }
    $author = (int)$t['author'];
    if (!thread_delete($tid)) {
        api_err('server_error', '删除失败，请稍后重试', 500);
    }
    user_bump($author, 'threads', -1);
    if ($admin && $author !== (int)$u['id']) {
        notify_add($author, 'delete', '您的帖子已被删除', delete_notify_body('thread', $tid, 0), '');
    }
    log_action('thread_delete', ($admin && $author !== (int)$u['id'] ? '管理员' : '作者') . '通过开放 API 删除《' . (string)$t['title'] . '》');
    api_out(['data' => ['deleted' => true, 'id' => $tid]]);
}

function api_ep_replies_delete(?array $u): void
{
    $tid = api_in_int('tid', 0);
    $rid = api_in_int('rid', 0);
    $r = reply_get($tid, $rid);
    if (!$r) {
        api_err('not_found', '回复不存在', 404);
    }
    $admin = !empty($u['admin']);
    if (!$admin && (int)$r['author'] !== (int)$u['id']) {
        api_err('forbidden', '只能删除自己的回复', 403);
    }
    reply_delete($tid, $rid);
    log_action('reply_delete', ($admin && (int)$r['author'] !== (int)$u['id'] ? '管理员' : '作者') . '通过开放 API 删除《' . cut_str((string)(thread_get($tid)['title'] ?? ''), 40) . '》中的回复');
    api_out(['data' => ['deleted' => true, 'id' => $rid, 'thread_id' => $tid]]);
}

/* ================= 端点实现：用户与通知 ================= */

function api_ep_me_info(?array $u): void
{
    $pub = api_user_public($u);
    $pub['email'] = (string)$u['email'];
    $pub['mute_until'] = (int)($u['mute_until'] ?? 0);
    $pub['notifications_unread'] = notify_unread((int)$u['id']);
    $pub['token'] = [
        'created' => (int)(api_token_of((int)$u['id'])['created'] ?? 0),
        'terms_version' => api_terms_fp(),
    ];
    api_out(['data' => ['me' => $pub]]);
}

function api_ep_notifications_list(?array $u): void
{
    [$page, $per] = api_page();
    $all = notify_list((int)$u['id']);
    $total = count($all);
    $slice = array_slice($all, ($page - 1) * $per, $per);
    $out = [];
    foreach ($slice as $n) {
        $out[] = [
            'id' => (int)$n['id'], 'type' => (string)$n['type'], 'title' => (string)$n['title'],
            'body' => (string)$n['body'], 'link' => (string)($n['link'] ?? ''),
            'read' => !empty($n['read']), 'created' => (int)$n['created'],
        ];
    }
    api_out(['data' => ['total' => $total, 'page' => $page, 'per_page' => $per,
        'unread' => notify_unread((int)$u['id']), 'notifications' => $out]]);
}

function api_ep_notifications_read(?array $u): void
{
    $id = api_in_int('id', 0);
    if ($id <= 0) {
        api_err('validation', '缺少通知 id', 400);
    }
    notify_set_read((int)$u['id'], $id);
    api_out(['data' => ['read' => $id, 'unread' => notify_unread((int)$u['id'])]]);
}

function api_ep_notifications_read_all(?array $u): void
{
    notify_read_all((int)$u['id']);
    api_out(['data' => ['read_all' => true, 'unread' => 0]]);
}

function api_ep_docs_agree(?array $u): void
{
    if ((int)cfg('doc_gate', 0) !== 1 || doc_list() === []) {
        api_out(['data' => ['agreed' => true, 'required' => false,
            'message' => '本站未开启协议门禁，无需此操作']]);
    }
    api_doc_agree_write($u);
    log_action('doc_agree', '通过开放 API 同意社区协议（版本 ' . strtoupper(substr(doc_fingerprint(), 0, 8)) . '，客户端载体）', (int)$u['id']);
    api_out(['data' => [
        'agreed' => true,
        'required' => true,
        'doc_version' => strtoupper(substr(doc_fingerprint(), 0, 8)),
        'message' => '已记录您通过 API 客户端同意社区协议（可在站内协议中心查看协议全文）',
    ]]);
}
