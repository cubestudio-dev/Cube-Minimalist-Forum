<?php
/**
 * 极简论坛 · 业务领域层
 * 板块 / 帖子 / 回复 / 点赞 / 举报 / AI 队列 / 通知 / 公告 / 在线统计
 * 列表页只读索引文件（threads/index.php），详情页才读具体帖子文件 —— 避免遍历大目录
 */
defined('APP') or exit('Forbidden');

/* ================= 板块 ================= */
function board_all(): array
{
    $bs = Store::readMemo('boards.php', []);
    usort($bs, function ($a, $b) {
        return [ (int)($a['sort'] ?? 0), (int)($a['id'] ?? 0) ] <=> [ (int)($b['sort'] ?? 0), (int)($b['id'] ?? 0) ];
    });
    return $bs;
}

function board_get(int $id): ?array
{
    foreach (board_all() as $b) {
        if ((int)($b['id'] ?? 0) === $id) {
            return $b;
        }
    }
    return null;
}

function board_name(int $id): string
{
    static $c = false;
    if ($c === false) {
        $c = [];
        foreach (board_all() as $b) {
            $c[(int)$b['id']] = (string)$b['name'];
        }
    }
    return $c[$id] ?? '未知板块';
}

function board_save(?int $id, string $name, string $desc): int
{
    $lk = Store::lock('boards');
    $bs = Store::read('boards.php', []);
    if ($id === null) {
        $id = 1;
        foreach ($bs as $b) {
            $id = max($id, (int)($b['id'] ?? 0) + 1);
        }
        $bs[] = ['id' => $id, 'name' => $name, 'desc' => $desc, 'sort' => count($bs)];
    } else {
        foreach ($bs as &$b) {
            if ((int)($b['id'] ?? 0) === $id) {
                $b['name'] = $name;
                $b['desc'] = $desc;
            }
        }
        unset($b);
    }
    Store::write('boards.php', $bs);
    Store::unlock($lk);
    return (int)$id;
}

/** 板块下仍有帖子时禁止删除 */
function board_delete(int $id): bool
{
    foreach (thread_index() as $t) {
        if ((int)($t['board'] ?? 0) === $id) {
            return false;
        }
    }
    $lk = Store::lock('boards');
    $bs = array_values(array_filter(Store::read('boards.php', []), function ($b) use ($id) {
        return (int)($b['id'] ?? 0) !== $id;
    }));
    Store::write('boards.php', $bs);
    Store::unlock($lk);
    return true;
}

function board_move(int $id, string $dir): void
{
    $lk = Store::lock('boards');
    $bs = Store::read('boards.php', []);
    usort($bs, function ($a, $b) {
        return [ (int)($a['sort'] ?? 0), (int)($a['id'] ?? 0) ] <=> [ (int)($b['sort'] ?? 0), (int)($b['id'] ?? 0) ];
    });
    foreach ($bs as $i => $b) {
        if ((int)($b['id'] ?? 0) === $id) {
            $j = $dir === 'up' ? $i - 1 : $i + 1;
            if (isset($bs[$j])) {
                $tmp = $bs[$i];
                $bs[$i] = $bs[$j];
                $bs[$j] = $tmp;
            }
            break;
        }
    }
    foreach ($bs as $i => &$b) {
        $b['sort'] = $i;
    }
    unset($b);
    Store::write('boards.php', $bs);
    Store::unlock($lk);
}

/* ================= 帖子 ================= */
function thread_index(): array
{
    $x = Store::readMemo('threads/index.php', []);
    return is_array($x) ? $x : [];
}

const INDEX_KEYS = ['board', 'author', 'title', 'created', 'replies', 'likes', 'pinned', 'locked', 'hidden', 'appealed', 'last_reply', 'last_reply_by', 'views'];

/** 在 threads 锁内同步索引字段 */
function sync_thread_index(int $tid, array $fields): void
{
    $idx = thread_index();
    foreach ($idx as &$t) {
        if ((int)($t['id'] ?? 0) === $tid) {
            foreach ($fields as $k => $v) {
                if (in_array($k, INDEX_KEYS, true)) {
                    $t[$k] = $v;
                }
            }
        }
    }
    unset($t);
    Store::write('threads/index.php', $idx);
}

function thread_create(int $board, string $title, string $content, int $author): int
{
    $lk = Store::lock('threads');
    $idx = thread_index();
    $id = 1;
    foreach ($idx as $t) {
        $id = max($id, (int)($t['id'] ?? 0) + 1);
    }
    $now = time();
    $idx[] = [
        'id' => $id, 'board' => $board, 'author' => $author, 'title' => $title,
        'created' => $now, 'replies' => 0, 'likes' => 0,
        'pinned' => false, 'locked' => false, 'hidden' => false, 'appealed' => false,
        'last_reply' => $now, 'last_reply_by' => $author,
    ];
    // 写入失败必须显式失败：不再静默丢帖（否则用户会跳到不存在的帖子页，误以为发帖成功但内容丢失）
    $fail = '';
    if (!Store::write('threads/index.php', $idx)) {
        $fail = '帖子索引写入失败';
    } elseif (!Store::write('threads/t' . $id . '.php', [
        'id' => $id, 'board' => $board, 'author' => $author, 'title' => $title, 'content' => $content,
        'created' => $now, 'likes' => [], 'pinned' => false, 'locked' => false, 'hidden' => false, 'appealed' => false,
    ])) {
        // 索引已写入但正文写入失败：回滚索引，避免出现“索引有但打不开”的幽灵帖
        $idx2 = array_values(array_filter($idx, function ($x) use ($id) {
            return (int)($x['id'] ?? 0) !== $id;
        }));
        Store::write('threads/index.php', $idx2);
        $fail = '帖子内容写入失败';
    }
    Store::unlock($lk);
    if ($fail !== '') {
        throw new RuntimeException($fail . ' —— ' . (Store::$lastWriteError ?? 'data/ 目录可能不可写，请检查目录权限或磁盘剩余空间'));
    }
    return $id;
}

function thread_get(int $id): ?array
{
    $t = Store::readMemo('threads/t' . $id . '.php');
    return is_array($t) ? $t : null;
}

function thread_save(int $id, array $fields): void
{
    $lk = Store::lock('threads');
    $t = thread_get($id);
    if ($t) {
        foreach ($fields as $k => $v) {
            $t[$k] = $v;
        }
        Store::write('threads/t' . $id . '.php', $t);
        sync_thread_index($id, $fields);
    }
    Store::unlock($lk);
}

function thread_delete(int $id): bool
{
    $lk = Store::lock('threads');
    $t = thread_get($id);
    if (!$t) {
        Store::unlock($lk);
        return false;
    }
    $idx = array_values(array_filter(thread_index(), function ($x) use ($id) {
        return (int)($x['id'] ?? 0) !== $id;
    }));
    Store::write('threads/index.php', $idx);
    Store::unlock($lk);
    Store::delete('threads/t' . $id . '.php');
    Store::delete('replies/t' . $id . '.php');
    reports_close_target('thread', $id);
    return true;
}

/* ---- 列表查询（只读索引） ---- */
function threads_visible(array $idx): array
{
    $admin = is_admin();
    $out = [];
    foreach ($idx as $t) {
        if (!empty($t['hidden']) && !$admin) {
            continue;
        }
        $out[] = $t;
    }
    return $out;
}

function threads_by_board(int $bid): array
{
    $out = [];
    foreach (threads_visible(thread_index()) as $t) {
        if ((int)($t['board'] ?? 0) === $bid) {
            $out[] = $t;
        }
    }
    usort($out, function ($a, $b) {
        // 置顶帖永远排最前，其余按发布时间倒序
        return [ !empty($b['pinned']), (int)$b['created'] ] <=> [ !empty($a['pinned']), (int)$a['created'] ];
    });
    return $out;
}

function threads_home(string $tab): array
{
    $all = threads_visible(thread_index());
    if ($tab === 'reply') {
        usort($all, function ($a, $b) {
            return (int)($b['last_reply'] ?? 0) <=> (int)($a['last_reply'] ?? 0);
        });
    } else {
        usort($all, function ($a, $b) {
            return (int)($b['created'] ?? 0) <=> (int)($a['created'] ?? 0);
        });
    }
    return $all;
}

function threads_by_author(int $uid): array
{
    $out = [];
    foreach (threads_visible(thread_index()) as $t) {
        if ((int)($t['author'] ?? 0) === $uid) {
            $out[] = $t;
        }
    }
    usort($out, function ($a, $b) {
        return (int)($b['created'] ?? 0) <=> (int)($a['created'] ?? 0);
    });
    return $out;
}

/* ================= 回复 ================= */
function reply_list(int $tid): array
{
    $r = Store::read('replies/t' . $tid . '.php', []);
    return is_array($r) ? $r : [];
}

function reply_get(int $tid, int $rid): ?array
{
    foreach (reply_list($tid) as $r) {
        if ((int)($r['id'] ?? 0) === $rid) {
            return $r;
        }
    }
    return null;
}

function reply_add(int $tid, string $content, int $author): ?int
{
    $t = thread_get($tid);
    if (!$t || !empty($t['locked'])) {
        return null;
    }
    $lk = Store::lock('threads');
    $rs = reply_list($tid);
    $rid = 1;
    foreach ($rs as $r) {
        $rid = max($rid, (int)($r['id'] ?? 0) + 1);
    }
    $now = time();
    $rs[] = ['id' => $rid, 'author' => $author, 'content' => $content, 'created' => $now, 'likes' => [], 'hidden' => false, 'appealed' => false];
    if (!Store::write('replies/t' . $tid . '.php', $rs)) {
        Store::unlock($lk);
        throw new RuntimeException('回复写入失败 —— ' . (Store::$lastWriteError ?? 'data/ 目录可能不可写，请检查目录权限或磁盘剩余空间'));
    }
    sync_thread_index($tid, ['replies' => count($rs), 'last_reply' => $now, 'last_reply_by' => $author]);
    Store::unlock($lk);
    if ((int)$t['author'] !== $author) {
        user_bump((int)$t['author'], 'replies_recv', 1);
    }
    return $rid;
}

function reply_delete(int $tid, int $rid): bool
{
    $lk = Store::lock('threads');
    $rs = reply_list($tid);
    $found = null;
    $out = [];
    foreach ($rs as $r) {
        if ((int)($r['id'] ?? 0) === $rid) {
            $found = $r;
            continue;
        }
        $out[] = $r;
    }
    if ($found === null) {
        Store::unlock($lk);
        return false;
    }
    Store::write('replies/t' . $tid . '.php', $out);
    sync_thread_index($tid, ['replies' => count($out)]);
    Store::unlock($lk);
    $t = thread_get($tid);
    if ($t && (int)($found['author'] ?? 0) !== (int)$t['author']) {
        user_bump((int)$found['author'], 'replies_recv', -1);
    }
    reports_close_target('reply', $tid, $rid);
    return true;
}

function reply_save(int $tid, int $rid, array $fields): void
{
    $lk = Store::lock('threads');
    $rs = reply_list($tid);
    foreach ($rs as &$r) {
        if ((int)($r['id'] ?? 0) === $rid) {
            foreach ($fields as $k => $v) {
                $r[$k] = $v;
            }
        }
    }
    unset($r);
    Store::write('replies/t' . $tid . '.php', $rs);
    Store::unlock($lk);
}

/* ================= 点赞（仅赞，可取消，不影响排序） ================= */
/** @return array [bool 是否已赞, int 最新数量] */
function like_toggle(string $type, int $tid, int $rid, int $uid): array
{
    // 兼容 't'/'thread' 与 'r'/'reply' 两种写法
    if ($type === 't' || $type === 'thread') {
        $type = 'thread';
    } elseif ($type === 'r' || $type === 'reply') {
        $type = 'reply';
    } else {
        return [false, 0];
    }
    $lk = Store::lock('threads');
    if ($type === 'thread') {
        $t = thread_get($tid);
        if (!$t) {
            Store::unlock($lk);
            return [false, 0];
        }
        $likes = array_map('intval', $t['likes'] ?? []);
        $liked = in_array($uid, $likes, true);
        $likes = $liked ? array_values(array_diff($likes, [$uid])) : array_values(array_unique(array_merge($likes, [$uid])));
        $t['likes'] = $likes;
        Store::write('threads/t' . $tid . '.php', $t);
        sync_thread_index($tid, ['likes' => count($likes)]);
        Store::unlock($lk);
        if ((int)$t['author'] !== $uid) {
            user_bump((int)$t['author'], 'likes_recv', $liked ? -1 : 1);
        }
        return [!$liked, count($likes)];
    }
    // 回复点赞
    $rs = reply_list($tid);
    foreach ($rs as &$r) {
        if ((int)($r['id'] ?? 0) === $rid) {
            $likes = array_map('intval', $r['likes'] ?? []);
            $liked = in_array($uid, $likes, true);
            $likes = $liked ? array_values(array_diff($likes, [$uid])) : array_values(array_unique(array_merge($likes, [$uid])));
            $r['likes'] = $likes;
            $author = (int)($r['author'] ?? 0);
            Store::write('replies/t' . $tid . '.php', $rs);
            Store::unlock($lk);
            if ($author !== $uid) {
                user_bump($author, 'likes_recv', $liked ? -1 : 1);
            }
            return [!$liked, count($likes)];
        }
    }
    unset($r);
    Store::unlock($lk);
    return [false, 0];
}

/* ================= 浏览量 / 编辑（v1.14.0） ================= */

/** 浏览量 +1（每会话每帖至多计一次；爬虫/命令行 UA 不计数，与在线统计同标准；写入失败静默）
 * @return int 计数后的最新浏览量（供页面先计后渲染，避免首次浏览显示旧值） */
function thread_view_bump(int $tid): int
{
    $cur = function () use ($tid): int {
        $t = thread_get($tid);
        return $t ? (int)($t['views'] ?? 0) : 0;
    };
    $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '' || session_status() !== PHP_SESSION_ACTIVE
        || preg_match('/bot|crawl|spider|slurp|curl|wget|python|java|okhttp|httpclient|headless|monitor|pingdom|uptime/i', $ua)) {
        return $cur();
    }
    if (!isset($_SESSION['views']) || !is_array($_SESSION['views'])) {
        $_SESSION['views'] = [];
    }
    if (isset($_SESSION['views'][$tid])) {
        return $cur();
    }
    if (count($_SESSION['views']) > 500) { // 防会话数据无限膨胀
        $_SESSION['views'] = array_slice($_SESSION['views'], -250, null, true);
    }
    $_SESSION['views'][$tid] = 1;
    $lk = Store::lock('threads');
    $t = thread_get($tid);
    if ($t) {
        $t['views'] = (int)($t['views'] ?? 0) + 1;
        Store::write('threads/t' . $tid . '.php', $t);
        sync_thread_index($tid, ['views' => (int)$t['views']]);
        Store::unlock($lk);
        return (int)$t['views'];
    }
    Store::unlock($lk);
    return 0;
}

/** 可编辑时间窗（秒）：作者可在发帖 / 回复后 15 分钟内编辑自己的内容 */
const MF_EDIT_WINDOW = 900;

/** 帖子是否可由 $u 编辑：管理员不限时；作者需未锁定、未隐藏、无回复且在 15 分钟内 */
function thread_editable(array $t, ?array $u): bool
{
    if (!$u || !feat_on('edit')) {
        return false;
    }
    if (!empty($u['admin'])) {
        return true;
    }
    if ((int)($t['author'] ?? 0) !== (int)$u['id']) {
        return false;
    }
    if (!empty($t['locked']) || !empty($t['hidden']) || (int)($t['replies'] ?? 0) > 0) {
        return false;
    }
    return (time() - (int)($t['created'] ?? 0)) <= MF_EDIT_WINDOW;
}

/** 回复是否可由 $u 编辑：管理员不限时；作者需未隐藏、所在帖未锁定且在 15 分钟内 */
function reply_editable(array $r, ?array $u, array $t): bool
{
    if (!$u || !feat_on('edit')) {
        return false;
    }
    if (!empty($u['admin'])) {
        return true;
    }
    if ((int)($r['author'] ?? 0) !== (int)$u['id']) {
        return false;
    }
    if (!empty($r['hidden']) || !empty($t['locked'])) {
        return false;
    }
    return (time() - (int)($r['created'] ?? 0)) <= MF_EDIT_WINDOW;
}

/* ================= 站内搜索（v1.14.0） ================= */

/** 关键词包含判断（大小写不敏感；无 mbstring 时回退 lowercase 比对，中日韩字符不受影响） */
function txt_contains(string $hay, string $needle): bool
{
    if ($needle === '') {
        return false;
    }
    if (function_exists('mb_stripos')) {
        return mb_stripos($hay, $needle, 0, 'UTF-8') !== false;
    }
    return stripos(str_lower($hay), str_lower($needle)) !== false;
}

/**
 * 站内搜索：最近 $scan 帖（按发布时间倒序）的标题 + 正文；最多返回 50 条
 * @return array [['t'=>索引行, 'snippet'=>摘要], ...]
 */
function search_threads(string $q, int $scan = 500): array
{
    $q = trim($q);
    if ($q === '') {
        return [];
    }
    $all = threads_visible(thread_index());
    usort($all, function ($a, $b) {
        return (int)($b['created'] ?? 0) <=> (int)($a['created'] ?? 0);
    });
    $all = array_slice($all, 0, max(1, $scan));
    $out = [];
    foreach ($all as $t) {
        $tid = (int)($t['id'] ?? 0);
        $title = (string)($t['title'] ?? '');
        $hit = txt_contains($title, $q);
        $snippet = '';
        $content = '';
        if (!$hit) {
            $c = thread_get($tid);
            if (!$c) {
                continue;
            }
            $content = (string)($c['content'] ?? '');
            if (!txt_contains($content, $q)) {
                continue;
            }
        }
        if ($snippet === '') {
            // 摘要：标题命中时也读正文取开头一段，帮助判断是否为目标帖
            if (!isset($c) || !is_array($c)) {
                $c = thread_get($tid);
            }
            if ($c) {
                $flat = trim((string)preg_replace('/\s+/u', ' ', (string)($c['content'] ?? '')));
                $snippet = cut_str($flat, 120) . (u_strlen($flat) > 120 ? '…' : '');
            }
        }
        unset($c);
        $out[] = ['t' => $t, 'snippet' => $snippet];
        if (count($out) >= 50) {
            break;
        }
    }
    return $out;
}

/* ================= 举报 / 审核队列 ================= */
function reports_all(): array
{
    $r = Store::read('reports.php', []);
    return is_array($r) ? $r : [];
}

function report_get(int $id): ?array
{
    foreach (reports_all() as $r) {
        if ((int)($r['id'] ?? 0) === $id) {
            return $r;
        }
    }
    return null;
}

function report_update(int $id, array $fields): void
{
    $lk = Store::lock('reports');
    $rs = reports_all();
    foreach ($rs as &$r) {
        if ((int)($r['id'] ?? 0) === $id) {
            foreach ($fields as $k => $v) {
                $r[$k] = $v;
            }
        }
    }
    unset($r);
    Store::write('reports.php', $rs);
    Store::unlock($lk);
}

/**
 * 新增举报：内容立即隐藏，进入 AI 待审队列（FIFO）
 */
function report_add(string $type, int $tid, int $rid, string $reason, int $reporter): int
{
    $t = thread_get($tid);
    $author = 0;
    if ($type === 'thread') {
        $author = (int)($t['author'] ?? 0);
    } else {
        $r = reply_get($tid, $rid);
        $author = (int)($r['author'] ?? 0);
    }
    $lk = Store::lock('reports');
    $rs = reports_all();
    $id = 1;
    foreach ($rs as $r) {
        $id = max($id, (int)($r['id'] ?? 0) + 1);
    }
    $rs[] = [
        'id' => $id, 'type' => $type, 'tid' => $tid, 'rid' => $rid, 'author' => $author,
        'reporter' => $reporter, 'reason' => $reason, 'created' => time(),
        'status' => 'pending', 'note' => '', 'handled' => 0,
    ];
    Store::write('reports.php', $rs);
    Store::unlock($lk);

    $qk = Store::lock('queue');
    $q = Store::read('queue.php', []);
    $q[] = $id;
    Store::write('queue.php', $q);
    Store::unlock($qk);

    // 立即隐藏
    if ($type === 'thread') {
        thread_save($tid, ['hidden' => true]);
    } else {
        reply_save($tid, $rid, ['hidden' => true]);
    }
    return $id;
}

function queue_list(): array
{
    $q = Store::read('queue.php', []);
    return is_array($q) ? $q : [];
}

/** FIFO 取队首 */
function queue_shift(): ?int
{
    $q = Store::read('queue.php', []);
    if (!$q) {
        return null;
    }
    $id = (int)array_shift($q);
    Store::write('queue.php', $q);
    return $id;
}

function queue_remove(int $rid): void
{
    $q = array_values(array_diff(Store::read('queue.php', []), [$rid]));
    Store::write('queue.php', $q);
}

/** 目标内容被删除时，关闭其未决举报并移出队列 */
function reports_close_target(string $type, int $tid, int $rid = 0): void
{
    $lk = Store::lock('reports');
    $rs = reports_all();
    $changed = false;
    foreach ($rs as &$r) {
        if ($r['type'] === $type && (int)($r['tid'] ?? 0) === $tid
            && ($type !== 'reply' || (int)($r['rid'] ?? 0) === $rid)
            && in_array($r['status'], ['pending', 'ai_bad', 'ai_failed', 'appealed'], true)) {
            $r['status'] = 'gone';
            $r['handled'] = time();
            $changed = true;
        }
    }
    unset($r);
    if ($changed) {
        Store::write('reports.php', $rs);
    }
    Store::unlock($lk);
    if ($changed) {
        foreach ($rs as $r) {
            if ($r['status'] === 'gone') {
                queue_remove((int)$r['id']);
            }
        }
    }
}

function report_target_content(array $rep, bool &$gone): string
{
    $gone = false;
    $t = thread_get((int)$rep['tid']);
    if (!$t) {
        $gone = true;
        return '';
    }
    if ($rep['type'] === 'thread') {
        return (string)$t['title'] . "\n" . (string)$t['content'];
    }
    $r = reply_get((int)$rep['tid'], (int)$rep['rid']);
    if (!$r) {
        $gone = true;
        return '';
    }
    return (string)$r['content'];
}

function report_target_set_hidden(array $rep, bool $hidden): void
{
    if ($rep['type'] === 'thread') {
        thread_save((int)$rep['tid'], ['hidden' => $hidden]);
    } else {
        reply_save((int)$rep['tid'], (int)$rep['rid'], ['hidden' => $hidden]);
    }
}

function report_target_set_appealed(array $rep): void
{
    if ($rep['type'] === 'thread') {
        thread_save((int)$rep['tid'], ['appealed' => true]);
    } else {
        reply_save((int)$rep['tid'], (int)$rep['rid'], ['appealed' => true]);
    }
}

function report_status_label(string $s): string
{
    $map = [
        'pending' => '待 AI 审核', 'ai_ok' => 'AI：无问题·已恢复', 'ai_bad' => 'AI：违规·已隐藏',
        'ai_failed' => 'AI 调用失败', 'appealed' => '已申诉·人工待审', 'manual_ok' => '人工：已恢复',
        'manual_bad' => '人工：已删除', 'gone' => '内容已删除',
    ];
    return $map[$s] ?? $s;
}

/** 队列当前待审条数 */
function queue_length(): int
{
    $q = Store::read('queue.php', []);
    return is_array($q) ? count($q) : 0;
}

/**
 * 队列单条审核结果落盘（v1.16.0 从 ai_process_queue 抽出，单条 / 并行火力共用）：
 * 失败 → ai_failed + 通知管理员；违规 → ai_bad 保持隐藏 + 通知双方（可申诉）；通过 → ai_ok 恢复展示。
 * @return string 人读提示
 */
function ai_queue_settle(int $rid, array $rep, bool $ok, string $verdict, string $note, string $used): string
{
    $isThread = ($rep['type'] === 'thread');
    $tTitle = cut_str((string)(thread_get((int)$rep['tid'])['title'] ?? ''), 40);
    $no = $isThread ? '帖子《' . $tTitle . '》' : '帖子《' . $tTitle . '》中的回复';

    if (!$ok) {
        // 全部模型重试仍失败：保持隐藏，通知管理员人工处理
        report_update($rid, ['status' => 'ai_failed', 'note' => cut_str($note, 200), 'handled' => time()]);
        log_action('ai_failed', '举报 #' . $rid . '（' . $no . '）：' . cut_str($used . '：' . $note, 160) . '；内容保持隐藏，已通知管理员', 0, '系统');
        foreach (user_all() as $au) {
            if (!empty($au['admin'])) {
                notify_add((int)$au['id'], 'ai_admin', 'AI 审核失败', '举报 #' . $rid . '（' . $no . '）审核失败：' . cut_str($note, 120) . '。内容保持隐藏，请到后台「AI 待审队列」处理。', 'p=admin&tab=queue');
            }
        }
        return 'AI 调用失败：' . cut_str($note, 80) . '（内容保持隐藏，已通知管理员）';
    }
    if ($verdict === 'violation') {
        // 有问题：保持隐藏，通知被举报人（可申诉），同步告知举报人
        report_update($rid, ['status' => 'ai_bad', 'note' => cut_str($note, 200), 'handled' => time()]);
        log_action('ai_bad', '举报 #' . $rid . '：' . $no . ' 判定违规（' . cut_str($note, 80) . '），内容保持隐藏' . ($used !== '' ? '，审核方：' . $used : ''), 0, '系统');
        $reason = (string)($rep['reason'] ?? '');
        notify_add(
            (int)$rep['author'], 'ai_bad',
            '您的内容被举报，AI 审核判定违规',
            $no . ' 被举报（理由：' . ($reason !== '' ? $reason : '未填写') . '），AI 审核认为存在违规，内容已隐藏。如您认为审核有误，可在本条通知下方点击「申诉」，申诉后将由管理员人工复核。',
            'p=thread&id=' . (int)$rep['tid'], $rid, true
        );
        notify_add(
            (int)$rep['reporter'], 'report_result', '举报处理结果',
            '您举报的' . $no . '经 AI 审核判定违规，内容已隐藏，将等待用户申诉或管理员人工复核。感谢您的监督。',
            'p=thread&id=' . (int)$rep['tid']
        );
        return '审核完成：判定违规，内容保持隐藏，已通知被举报人';
    }
    // 没问题：恢复内容
    report_update($rid, ['status' => 'ai_ok', 'note' => cut_str($note, 200), 'handled' => time()]);
    report_target_set_hidden($rep, false);
    log_action('ai_ok', '举报 #' . $rid . '：' . $no . ' 未发现违规，已恢复展示' . ($used !== '' ? '，审核方：' . $used : ''), 0, '系统');
    notify_add(
        (int)$rep['reporter'], 'report_result', '举报处理结果',
        '您举报的' . $no . '经 AI 审核未发现违规，内容已恢复展示。感谢您的监督。',
        'p=thread&id=' . (int)$rep['tid']
    );
    return '审核完成：未发现违规，内容已恢复展示';
}

/** 并行火力失败计数统一结算：成功模型清零（顺带自动恢复），失败模型累计 */
function ai_multi_fail_settle(array $results, array $jobs): void
{
    $st = ai_state_read();
    $fail = $st['fail'];
    $limit = ai_fail_limit();
    foreach ($results as $i => $r) {
        $m = $jobs[$i]['model'] ?? null;
        if (!$m) {
            continue;
        }
        $id = (int)$m['id'];
        $label = ai_model_label($m);
        if (!empty($r['ok'])) {
            $wasTripped = (int)($fail[$id] ?? 0) >= $limit;
            $fail[$id] = 0;
            if ($wasTripped) {
                log_action('ai_model_recover', '模型「' . $label . '」审核成功，已从故障中自动恢复');
            }
            if ((int)$st['active'] === 0) {
                $st['active'] = $id;
            }
        } else {
            ai_fail_bump($fail, $id, $label, $limit);
        }
    }
    $st['fail'] = $fail;
    $st['last'] = time();
    ai_state_write($st);
}

/**
 * 并行火力队列处理（v1.16.0）：一次从队列取出 K 条（K = min(启用模型数, 队列长度)），
 * K 个模型通过 curl_multi 同刻并发、各审一条，吞吐提升 K 倍；结果逐条走同一套落盘/通知逻辑。
 * 前置条件：调用方已持有 ai 锁、已过间隔保护；模型 ≥2 时进入本路径（≥2 条并行各审一条，=1 条双模型竞速）。
 */
function ai_process_queue_parallel($lk, array $models, bool $manual): array
{
    $jobs = [];
    for ($i = 0; $i < count($models); $i++) {
        $rid = queue_shift();
        if ($rid === null) {
            break;
        }
        $rep = report_get($rid);
        if (!$rep || $rep['status'] !== 'pending') {
            continue;
        }
        $gone = false;
        $content = report_target_content($rep, $gone);
        if ($gone) {
            report_update($rid, ['status' => 'gone', 'handled' => time()]);
            continue;
        }
        $jobs[] = ['rid' => $rid, 'rep' => $rep, 'content' => $content, 'model' => $models[$i % count($models)]];
    }
    if (!$jobs) {
        return [false, $manual ? '当前队列为空' : ''];
    }
    if (count($jobs) === 1) {
        /* v1.18.0 并发竞速：仅剩 1 条且模型 ≥2 时，同时叫两个模型审同一条内容，
           先返回有效结果者胜出——单条内容的审核等待时间直接减半（代价是双倍令牌，仅并行火力模式启用） */
        $j = $jobs[0];
        if (count($models) >= 2) {
            $calls = [
                ['model' => $models[0], 'content' => $j['content']],
                ['model' => $models[1], 'content' => $j['content']],
            ];
            $results = ai_call_multi($calls);
            $raceJobs = [
                ['model' => $models[0]],
                ['model' => $models[1]],
            ];
            ai_multi_fail_settle($results, $raceJobs);
            $pick = !empty($results[0]['ok']) ? 0 : (!empty($results[1]['ok']) ? 1 : 0);
            $r = $results[$pick];
            $used = !empty($r['ok'])
                ? '模型「' . $r['label'] . '」（并发竞速，与「' . $results[1 - $pick]['label'] . '」同刻出发）'
                : '并发竞速 · 两个模型均调用失败（' . $r['label'] . ' 等）';
            $msg = ai_queue_settle((int)$j['rid'], $j['rep'], !empty($r['ok']), (string)$r['verdict'], (string)$r['note'], $used);
            return [true, $msg];
        }
        $verdict = '';
        $note = '';
        $used = '';
        $ok = ai_moderate($j['content'], $verdict, $note, [], $used);
        $msg = ai_queue_settle((int)$j['rid'], $j['rep'], $ok, $verdict, $note, $used);
        return [true, $msg];
    }
    $calls = [];
    foreach ($jobs as $j) {
        $calls[] = ['model' => $j['model'], 'content' => $j['content']];
    }
    $results = ai_call_multi($calls);
    ai_multi_fail_settle($results, $jobs);
    foreach ($jobs as $i => $j) {
        $r = $results[$i];
        $used = !empty($r['ok'])
            ? '模型「' . $r['label'] . '」（并行火力）'
            : '并行火力 · 模型「' . $r['label'] . '」调用失败';
        ai_queue_settle((int)$j['rid'], $j['rep'], !empty($r['ok']), (string)$r['verdict'], (string)$r['note'], $used);
    }
    $okN = count(array_filter($results, function ($r) { return !empty($r['ok']); }));
    return [true, '并行火力：本次同时审核 ' . count($jobs) . ' 条内容（' . $okN . ' 个模型成功返回）'];
}

/**
 * 单条队列处理（v1.18.0 从 ai_process_queue 抽出；不释放锁，由调用方统一管理）：
 * - 并行火力 + 模型≥2：多件同刻并发 / 单件双模型竞速
 * - 其余：标准单模型顺序调用
 * @return array [bool 是否处理了任务, string 提示]
 */
function ai_process_one($lk, bool $manual): array
{
    $models = ai_models_enabled();
    if (ai_mode() === 'parallel' && count($models) >= 2) {
        $k = min(count($models), queue_length());
        if ($k >= 1) {
            return ai_process_queue_parallel($lk, $models, $manual);
        }
    }
    $rid = queue_shift();
    if ($rid === null) {
        return [false, $manual ? '当前队列为空' : ''];
    }
    $rep = report_get($rid);
    if (!$rep || $rep['status'] !== 'pending') {
        return [true, '该举报已不在待审状态'];
    }
    $gone = false;
    $content = report_target_content($rep, $gone);
    if ($gone) {
        report_update($rid, ['status' => 'gone', 'handled' => time()]);
        return [true, '原内容已被删除，举报已自动关闭'];
    }
    $verdict = '';
    $note = '';
    $used = '';
    $ok = ai_moderate($content, $verdict, $note, [], $used); // 审核状态（last/active/fail）由 ai_moderate 内部落盘
    $msg = ai_queue_settle($rid, $rep, $ok, $verdict, $note, $used);
    return [true, $msg];
}

/**
 * AI 审核队列：页面访问 / 后台手动触发（非阻塞锁防并发 + 调用间隔保护）。
 * v1.16.0：并行火力模式下一次并发审核 K 条（K = 启用模型数）。
 * v1.18.0：一次请求连续排空至多 3 条（后台手动 5 条，带时间预算），低流量站点不再
 *   “每访一页才审一条”，帖子「审核中」徽章消失得更快；超预算部分留给下次访问。
 * @return array [bool 是否处理了任务, string 提示]
 */
function ai_process_queue(bool $manual = false): array
{
    if (!ai_ready()) {
        return [false, $manual ? '请先到后台「AI」添加启用的模型（地址 / 密钥 / 模型名）' : ''];
    }
    $lk = Store::tryLock('ai');
    if (!$lk) {
        return [false, $manual ? '已有审核任务进行中，请稍后再试' : ''];
    }
    $t0 = time();
    $maxItems = $manual ? 5 : 3;
    $budget = $manual ? 120 : 30;
    $msgs = [];
    $handled = false;
    try {
        for ($n = 0; $n < $maxItems; $n++) {
            if ($n > 0 && (time() - $t0) >= $budget) {
                $left = queue_length();
                $msgs[] = $left > 0 ? '本批时间预算已用完，剩余 ' . $left . ' 条将在后续访问继续审核' : '';
                break;
            }
            if (!$manual && $n === 0) {
                $state = Store::read('ai_state.php', ['last' => 0]);
                if (time() - (int)($state['last'] ?? 0) < 3) {
                    Store::unlock($lk);
                    return [false, ''];
                }
            }
            $one = ai_process_one($lk, $manual);
            if (!$one[0]) {
                $msgs[] = (string)$one[1];
                break;
            }
            $handled = true;
            $msgs[] = (string)$one[1];
        }
    } catch (Throwable $ex) {
        $msgs[] = '审核异常：' . $ex->getMessage();
    }
    Store::unlock($lk);
    $msg = trim(implode('；', array_filter($msgs, function ($m) { return (string)$m !== ''; })));
    return [$handled, $msg];
}

/**
 * 发帖 / 回复 AI 预检（严全面模式·消息审核）：内容发布后立即交给 AI 主动审一遍（后台可开关，默认开）。
 *  - 判定违规 → 立即隐藏 + 生成系统举报记录（status=ai_bad，走既有申诉 / 复核体系）+ 通知作者；
 *  - AI 调用失败 → 宁纵勿枉：不隐藏，仅记日志等待举报或人工巡查；
 *  - 通过 → 记 ai_ok 日志（标注「预检」来源）。
 * @return string ''=无需提示；否则返回给发帖人的提示语（违规被隐藏）
 */
function ai_precheck_after_post(string $type, int $tid, int $rid, string $content, int $authorId, string $title = ''): string
{
    if ((int)cfg('ai_precheck', 1) !== 1 || !ai_ready()) {
        return '';
    }
    $verdict = '';
    $note = '';
    $used = '';
    $ok = ai_moderate($content, $verdict, $note, [], $used);
    $no = $type === 'thread' ? '帖子《' . cut_str((string)(thread_get($tid)['title'] ?? ''), 40) . '》' : '帖子《' . cut_str((string)(thread_get($tid)['title'] ?? ''), 40) . '》中的回复';
    if (!$ok) {
        log_action('ai_failed', '预检未完成（' . $no . '）：' . cut_str($note, 140) . '；内容正常展示，等待举报或人工复核', 0, 'AI 预检');
        return '';
    }
    if ($verdict !== 'violation') {
        log_action('ai_ok', '预检通过（' . $no . '）' . ($used !== '' ? '，审核方：' . $used : ''), 0, 'AI 预检');
        return '';
    }
    /* 违规：立即隐藏 + 系统举报记录（可申诉）+ 通知作者 */
    if ($type === 'thread') {
        thread_save($tid, ['hidden' => true]);
    } else {
        reply_save($tid, $rid, ['hidden' => true]);
    }
    $repId = report_add($type === 'thread' ? 'thread' : 'reply', $tid, $rid, 'AI 预检自动审核' . ($note !== '' ? '：' . cut_str($note, 100) : ''), 0);
    report_update($repId, ['status' => 'ai_bad', 'note' => cut_str($note, 200), 'handled' => time()]);
    log_action('ai_bad', '预检判定违规（' . $no . '）：' . cut_str($note, 80) . '，已自动隐藏' . ($used !== '' ? '，审核方：' . $used : ''), 0, 'AI 预检');
    notify_add(
        $authorId, 'ai_bad', '您发布的内容未通过 AI 预检',
        '您的' . ($type === 'thread' ? '帖子《' . cut_str($title, 20) . '》' : '回复') . '经 AI 预检判定存在违规（' . cut_str($note, 80) . '），已自动隐藏。如您认为审核有误，可在本条通知下方点击「申诉」，申诉后将由管理员人工复核。',
        'p=thread&id=' . $tid, $repId, true
    );
    return '内容已提交，但 AI 预检判定违规已自动隐藏：' . cut_str($note, 60) . '（可在通知中申诉）';
}


/* ================= @ 提及系统（v1.15.0） ================= */

/** 提取内容中的 @ 用户名候选（去重；与站内用户名同规则：中文/字母/数字/下划线 2-20 位） */
function mentions_extract(string $content): array
{
    if (!preg_match_all('/(?<![\\x{4e00}-\\x{9fa5}A-Za-z0-9_])@([\\x{4e00}-\\x{9fa5}A-Za-z0-9_]{2,20})/u', $content, $m)) {
        return [];
    }
    return array_values(array_unique($m[1]));
}

/**
 * @ 提及通知：内容中出现 @用户名 时，系统自动给对方发送站内通知。
 * - 仅通知真实存在的用户（含管理员），跳过作者本人；单条内容最多通知 10 人（防骚扰）
 * - 编辑内容时传入 $skip（旧内容的提及列表），只通知新增提及，避免重复轰炸
 * - 总开关：后台「功能」页 @ 提及通知（feat_mention，默认开）
 */
function mentions_notify(string $content, int $authorUid, int $tid, int $rid = 0, string $threadTitle = '', array $skip = []): void
{
    if (!feat_on('mention')) {
        return;
    }
    $names = mentions_extract($content);
    if (!$names) {
        return;
    }
    $skip = array_flip($skip);
    $author = uname($authorUid);
    $t = thread_get($tid);
    $tTitle = $threadTitle !== '' ? $threadTitle : (string)($t['title'] ?? '');
    $link = 'p=thread&id=' . $tid . ($rid > 0 ? '#r' . $rid : '');
    $where = $rid > 0 ? '的回复中' : '的帖子中';
    $summary = cut_str(trim(preg_replace('/\\s+/u', ' ', $content) ?? $content), 120);
    $n = 0;
    foreach ($names as $name) {
        if ($n >= 10) {
            break;
        }
        if (isset($skip[$name])) {
            continue;
        }
        $target = user_by_name($name);
        if (!$target || (int)$target['id'] === $authorUid) {
            continue;
        }
        notify_add((int)$target['id'], 'mention', $author . ' 在《' . cut_str($tTitle, 40) . '》' . $where . '提到了您', $summary, $link);
        $n++;
    }
    if ($n > 0) {
        log_action('mention', $author . ' 在《' . cut_str($tTitle, 40) . '》' . $where . '提及了 ' . $n . ' 位用户', $authorUid, $author);
    }
}

/* ================= 通知 ================= */
function notify_file(int $uid): string
{
    return 'notify/u' . $uid . '.php';
}

function notify_list(int $uid): array
{
    $ns = Store::read(notify_file($uid), []);
    if (!is_array($ns)) {
        $ns = [];
    }
    // 定期清理：已读超过 30 天的通知
    $cut = time() - 2592000;
    $out = [];
    $changed = false;
    foreach ($ns as $n) {
        if (!empty($n['read']) && (int)($n['created'] ?? 0) < $cut) {
            $changed = true;
            continue;
        }
        $out[] = $n;
    }
    if ($changed) {
        Store::write(notify_file($uid), $out);
    }
    usort($out, function ($a, $b) {
        return (int)($b['created'] ?? 0) <=> (int)($a['created'] ?? 0);
    });
    return $out;
}

function notify_unread(int $uid): int
{
    $c = 0;
    foreach (Store::read(notify_file($uid), []) as $n) {
        if (is_array($n) && empty($n['read'])) {
            $c++;
        }
    }
    return $c;
}

function notify_add(int $uid, string $type, string $title, string $body, string $link = '', int $reportId = 0, bool $appealable = false): void
{
    if ($uid <= 0) {
        return;
    }
    $lk = Store::lock('notify');
    $ns = Store::read(notify_file($uid), []);
    if (!is_array($ns)) {
        $ns = [];
    }
    $id = 1;
    foreach ($ns as $n) {
        $id = max($id, (int)($n['id'] ?? 0) + 1);
    }
    $ns[] = [
        'id' => $id, 'type' => $type, 'title' => $title, 'body' => $body, 'link' => $link,
        'report' => $reportId, 'appealable' => $appealable, 'read' => false, 'created' => time(),
    ];
    if (count($ns) > 200) {
        $ns = array_slice($ns, -200);
    }
    Store::write(notify_file($uid), $ns);
    Store::unlock($lk);
}

function notify_set_read(int $uid, int $nid): void
{
    $lk = Store::lock('notify');
    $ns = Store::read(notify_file($uid), []);
    foreach ($ns as &$n) {
        if ((int)($n['id'] ?? 0) === $nid) {
            $n['read'] = true;
        }
    }
    unset($n);
    Store::write(notify_file($uid), $ns);
    Store::unlock($lk);
}

function notify_read_all(int $uid): void
{
    $lk = Store::lock('notify');
    $ns = Store::read(notify_file($uid), []);
    foreach ($ns as &$n) {
        $n['read'] = true;
    }
    unset($n);
    Store::write(notify_file($uid), $ns);
    Store::unlock($lk);
}

/** 申诉成功后，撤销该举报对应通知的「可申诉」状态 */
function notify_clear_appealable(int $uid, int $reportId): void
{
    $lk = Store::lock('notify');
    $ns = Store::read(notify_file($uid), []);
    foreach ($ns as &$n) {
        if ((int)($n['report'] ?? 0) === $reportId) {
            $n['appealable'] = false;
        }
    }
    unset($n);
    Store::write(notify_file($uid), $ns);
    Store::unlock($lk);
}

/* ================= 公告 ================= */
function ann_all(): array
{
    $a = Store::read('announcements.php', []);
    usort($a, function ($x, $y) {
        return (int)($y['created'] ?? 0) <=> (int)($x['created'] ?? 0);
    });
    return $a;
}

function ann_get(int $id): ?array
{
    foreach (Store::read('announcements.php', []) as $a) {
        if ((int)($a['id'] ?? 0) === $id) {
            return $a;
        }
    }
    return null;
}

function ann_save(?int $id, string $title, string $content, int $by): int
{
    $lk = Store::lock('ann');
    $as = Store::read('announcements.php', []);
    if ($id === null) {
        $id = 1;
        foreach ($as as $a) {
            $id = max($id, (int)($a['id'] ?? 0) + 1);
        }
        $as[] = ['id' => $id, 'title' => $title, 'content' => $content, 'by' => $by, 'created' => time(), 'updated' => 0];
    } else {
        foreach ($as as &$a) {
            if ((int)($a['id'] ?? 0) === $id) {
                $a['title'] = $title;
                $a['content'] = $content;
                $a['updated'] = time();
            }
        }
        unset($a);
    }
    Store::write('announcements.php', $as);
    Store::unlock($lk);
    return (int)$id;
}

function ann_delete(int $id): void
{
    $lk = Store::lock('ann');
    $as = array_values(array_filter(Store::read('announcements.php', []), function ($a) use ($id) {
        return (int)($a['id'] ?? 0) !== $id;
    }));
    Store::write('announcements.php', $as);
    Store::unlock($lk);
}

/* ================= 在线统计 =================
 * 记录格式：session_id => ['t' => 最后活跃时间戳, 'u' => 用户 uid（游客为 0）]
 * 兼容旧格式：session_id => 时间戳（视为游客）
 * 统计口径「N 人在线」：登录用户按 uid 去重（同账号多端 / 登录时会话重造都只算 1 人），游客按会话去重
 */
function online_count(): int
{
    $w = max(30, (int)cfg('online_window', 300));
    $cut = time() - $w;
    $uids = [];
    $guests = 0;
    foreach (Store::read('online.php', []) as $rec) {
        if (is_array($rec)) {
            if ((int)($rec['t'] ?? 0) < $cut) {
                continue;
            }
            $u = (int)($rec['u'] ?? 0);
            if ($u > 0) {
                $uids[$u] = true;
            } else {
                $guests++;
            }
        } elseif (is_numeric($rec) && (int)$rec >= $cut) { // 旧格式（v1.1 及之前）
            $guests++;
        }
    }
    return count($uids) + $guests;
}

function online_tick(): int
{
    // 爬虫 / 命令行客户端 / 空 UA 不计入（它们不回传 Cookie，每次请求都是新会话，会严重虚增人数）
    $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '' || preg_match('/bot|crawl|spider|slurp|curl|wget|python|java|okhttp|httpclient|headless|monitor|pingdom|uptime/i', $ua)) {
        return online_count();
    }
    $w = max(30, (int)cfg('online_window', 300));
    $now = time();
    $cut = $now - $w;
    $sid = session_id();
    if ($sid === '') {
        return online_count();
    }
    $u = current_user();
    $uid = $u ? (int)$u['id'] : 0;
    $lk = Store::lock('online');
    $o = Store::read('online.php', []);
    if (!is_array($o)) {
        $o = [];
    }
    foreach ($o as $k => $rec) {
        $ts = is_array($rec) ? (int)($rec['t'] ?? 0) : (int)$rec;
        $ku = is_array($rec) ? (int)($rec['u'] ?? 0) : 0;
        if ($ts < $cut) { // 过期
            unset($o[$k]);
            continue;
        }
        if ($uid > 0 && $ku === $uid && $k !== $sid) { // 同账号其他会话：合并为一人
            unset($o[$k]);
        }
    }
    // in = 本会话首次出现时间（进入网站时刻）：沿用已有记录，旧格式 / 新会话则取当前
    $prevIn = is_array($o[$sid] ?? null) ? (int)($o[$sid]['in'] ?? 0) : 0;
    $o[$sid] = ['t' => $now, 'u' => $uid, 'in' => $prevIn > 0 ? $prevIn : $now];
    Store::write('online.php', $o);
    Store::unlock($lk);
    return online_count();
}

/**
 * 在线详情（v1.10.0）：当前在线的注册用户与游客明细
 * - 注册用户按 uid 去重（最早进入时间 in / 最近活跃 t）；游客按会话逐个列出
 * @return array ['window'=>秒数, 'users'=>[['uid','name','in','t']], 'guests'=>[['in','t']]]
 */
function online_details(): array
{
    $w = max(30, (int)cfg('online_window', 300));
    $cut = time() - $w;
    $users = [];   // uid => ['uid','in','t']
    $guests = [];  // [['in','t']]
    foreach (Store::read('online.php', []) as $rec) {
        if (is_array($rec)) {
            $t = (int)($rec['t'] ?? 0);
            if ($t < $cut) {
                continue;
            }
            $in = (int)($rec['in'] ?? 0);
            if ($in <= 0 || $in > $t) {
                $in = $t; // 旧记录无 in 字段：以最近活跃时间近似
            }
            $u = (int)($rec['u'] ?? 0);
            if ($u > 0) {
                if (!isset($users[$u])) {
                    $users[$u] = ['uid' => $u, 'in' => $in, 't' => $t];
                } else {
                    // 同账号多会话：进入时间取最早，活跃时间取最近
                    $users[$u]['in'] = min((int)$users[$u]['in'], $in);
                    $users[$u]['t'] = max((int)$users[$u]['t'], $t);
                }
            } else {
                $guests[] = ['in' => $in, 't' => $t];
            }
        } elseif (is_numeric($rec) && (int)$rec >= $cut) { // 旧格式（v1.1 及之前）
            $guests[] = ['in' => (int)$rec, 't' => (int)$rec];
        }
    }
    foreach ($users as &$x) {
        $x['name'] = uname((int)$x['uid']);
    }
    unset($x);
    usort($users, function ($a, $b) {
        return (int)$b['t'] <=> (int)$a['t']; // 最近活跃在前
    });
    usort($guests, function ($a, $b) {
        return (int)$a['in'] <=> (int)$b['in']; // 先来的在前
    });
    return ['window' => $w, 'users' => $users, 'guests' => $guests];
}

/* ================= 会话文件清理 =================
 * 自托管 session 于 data/sessions，部分主机 PHP 垃圾回收被关闭（gc_probability=0），
 * 旧会话文件会持续累积侵蚀存储配额 —— 随访问低频清理（每小时至多一次），删除 30 天未活跃的会话文件。
 */
function sessions_gc(): void
{
    $st = Store::read('sysmon_state.php', []);
    if (!is_array($st) || time() - (int)($st['last_sess_gc'] ?? 0) < 3600) {
        return;
    }
    $lk = Store::tryLock('online');
    if (!$lk) {
        return;
    }
    try {
        $cut = time() - 30 * 86400;
        $n = 0;
        foreach (Store::scan('sessions') as $f) {
            if (strpos($f, 'sess_') !== 0) {
                continue;
            }
            $p = Store::path('sessions/' . $f);
            $m = @filemtime($p);
            if ($m !== false && $m < $cut) {
                @unlink($p);
                $n++;
            }
        }
        $st['last_sess_gc'] = time();
        if ($n > 0) {
            log_action('sys_gc', '清理过期会话文件 ' . $n . ' 个', 0, '系统');
        }
        Store::write('sysmon_state.php', $st);
    } catch (Throwable $t) {
        // 清理失败不影响主业务
    }
    Store::unlock($lk);
}

/* ================= 实时刷新（AJAX 轮询数据源） =================
 * 只读轻量 JSON：在线人数 / 未读通知 / 列表更新时间 / 指定帖回复进度。
 * 供前台每隔 N 秒（后台可配 live_interval，0=关闭）拉取，页面隐藏时自动暂停以省流量。
 */
function live_snapshot(int $tid = 0): array
{
    $out = [
        'ok'      => true,
        'online'  => online_count(),
        'unread'  => 0,
        'version' => app_version(),
    ];
    $u = current_user();
    if ($u) {
        $out['unread'] = notify_unread((int)$u['id']);
    }
    // 列表页：可见帖子的总数与最新活跃时间（发帖或回复都会推进），供“有新内容”提示条对比
    $latestTs = 0;
    $tcount = 0;
    $admin = $u && !empty($u['admin']);
    foreach (thread_index() as $t) {
        if (!empty($t['hidden']) && !$admin) {
            continue;
        }
        $tcount++;
        $ts = max((int)($t['created'] ?? 0), (int)($t['last_reply'] ?? 0));
        if ($ts > $latestTs) {
            $latestTs = $ts;
        }
    }
    $out['tcount'] = $tcount;
    $out['latest_ts'] = $latestTs;
    // 帖子页：返回该帖最新回复 id 与回复数，供“有新回复”提示对比（口径与页面渲染一致）
    if ($tid > 0) {
        $t = thread_get($tid);
        if ($t) {
            $maxRid = 0;
            $n = 0;
            foreach (reply_list($tid) as $r) {
                $maxRid = max($maxRid, (int)($r['id'] ?? 0));
                $n++;
            }
            $out['rid'] = $maxRid;
            $out['replies'] = $n;
        }
    }
    return $out;
}

/* ================= 一键备份（数据目录打包） ================= */
function backup_create(string &$name): bool
{
    $files = [];
    $root = rtrim(DATA_DIR, '/');
    $skip = ['locks/', 'sessions/', 'backup/', 'error.log'];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $f) {
        if (!$f->isFile()) {
            continue;
        }
        $rel = ltrim(substr($f->getPathname(), strlen($root)), '/\\');
        foreach ($skip as $s) {
            if (strpos($rel, $s) === 0) {
                continue 2;
            }
        }
        $c = @file_get_contents($f->getPathname());
        if ($c !== false) {
            $files[] = ['data/' . $rel, $c];
        }
    }
    $name = 'backup-' . date('Ymd-His') . '.zip';
    Store::ensureDir('backup');
    return MiniZip::create(Store::path('backup/' . $name), $files);
}

function backup_prune(int $keep = 5): void
{
    $fs = Store::scan('backup');
    if (count($fs) <= $keep) {
        return;
    }
    sort($fs);
    foreach (array_slice($fs, 0, count($fs) - $keep) as $f) {
        @unlink(Store::path('backup/' . $f));
    }
}

/* ================= 协议管理（v1.16.0） ================= */

/** 三份协议定义：key => [标题, 配置键]；body 为空 = 未启用 */
function doc_defs(): array
{
    return [
        'terms'      => ['用户协议', 'doc_terms'],
        'privacy'    => ['隐私政策', 'doc_privacy'],
        'disclaimer' => ['免责声明', 'doc_disclaimer'],
    ];
}

/**
 * v1.17.0 内置协议模板：后台「协议」页可一键填入后再修改。
 * 站长零文案也能立刻拥有合规基线；占位符【站长邮箱】【站名】提示需替换的位置。
 */
function doc_templates(): array
{
    return [
        'terms' => <<< 'TPL'
# 用户协议

> 欢迎使用【站名】！本协议是您与本站之间的约定，注册账号或使用本站服务，即表示您已阅读并同意本协议的全部内容。

## 一、账号与安全

1. 注册时请提供真实有效的邮箱地址，用于登录验证、系统通知与找回密码。
2. 妥善保管账号密码，因保管不善造成的损失由您自行承担；发现账号被盗请第一时间联系站长。
3. 账号仅限本人使用，不得出借、出租、转让或售卖。
4. 您可以随时注销账号。注销后您的登录凭据与邮箱信息将被清除，用户名将被释放供他人注册，您发布的内容按站方公示的方式处理。

## 二、社区规范

在本站，我们希望营造友善、真实、有序的交流氛围：

**欢迎**：正常交流讨论、分享经验与见解、提出建议与反馈。

**禁止**：

- 违反法律法规、危害国家安全、破坏社会稳定的内容；
- 辱骂攻击、人身威胁、恶意挑衅、人肉搜索等侵犯他人权益的行为；
- 色情低俗、暴力恐怖、赌博诈骗、侵权盗版、泄露他人隐私的内容；
- 恶意刷帖灌水、垃圾广告、营销引流等破坏社区秩序的行为。

违规内容将被隐藏或删除；情节严重的，将被禁言或封禁账号，并保留依法配合有关部门提供信息的权利。

## 三、内容的权利与使用

1. 您在本站发布的内容，著作权归您本人所有。
2. 为完成展示、缓存与备份等本站运行所必需的技术过程，您授权本站在站内范围内使用您发布的内容；该授权在您删除内容或注销账号后自动终止（已产生的备份除外）。
3. 您应对自己发布的内容独立承担责任；本站不对用户内容的真实性与适用性作任何担保。
4. 若您希望引用、转载本站内他人发布的内容，请先征得原作者同意。

## 四、服务说明

1. 本站基于"现状"免费提供。因系统维护、故障或不可抗力导致服务暂停或数据丢失的，本站将尽力恢复，但不承担赔偿责任。
2. 本站可能根据运营需要对功能进行调整，重大变更会提前在站内公告。
3. 本站有权修订本协议，修订后将在本页面公布并更新"生效日期"；若您继续使用本站，视为接受修订后的协议。

## 五、联系与争议

如对本协议有任何疑问、建议或投诉，请通过【站长邮箱】联系站长，我们将在合理期限内答复。本协议适用中华人民共和国法律。
TPL,
        'privacy' => <<< 'TPL'
# 隐私政策

> 更新日期：【更新日期】。您的隐私对我们很重要。本政策用尽量清楚的话向您说明：我们收集哪些信息、为什么收集、如何保护，以及您拥有哪些权利。

## 一、我们收集哪些信息

| 信息类型 | 具体内容 | 用途说明 |
| --- | --- | --- |
| 注册信息 | 用户名、邮箱 | 账号识别、登录、系统通知、找回密码 |
| 凭据信息 | 密码 | 仅以不可逆的加密散列保存，任何人（包括站长）都无法查看原文 |
| 访问信息 | IP 地址、浏览器标识（User-Agent）、访问时间与页面 | 安全防护：限流、封禁恶意访问、统计访问归属 |
| 操作信息 | 发帖、回复、点赞、举报等操作 | 维持社区秩序，供管理与溯源使用 |

其中，归属地查询仅在管理员于后台主动查询时进行，结果仅站长可见。

## 二、Cookie 与本地存储的使用

本站使用少量 Cookie 与浏览器本地存储来维持：

1. **登录状态**：未勾选"保持登录"时，会话在浏览器关闭后失效；勾选后最长保留 30 天。
2. **深浅色偏好**：记住您选择的外观主题。
3. **协议确认状态**：记录您已同意的协议版本，避免每个页面都重复弹出确认。

我们不会将 Cookie 用于跨站跟踪，也不会向任何第三方出售数据。

## 三、信息如何存储与保护

1. 全部数据保存在站长自有的服务器存储中，不依赖第三方云服务。
2. 数据文件与日志以压缩格式存储，日志文件按站长设置的保留天数自动清理。
3. 系统内置防火墙对异常访问进行识别与拦截，降低数据被恶意获取的风险。

## 四、保留期限

- 账号信息：保留至您注销账号为止。
- 发帖与回复：保留至您或站长删除为止。
- 访问与操作日志：按站长设置的保留天数自动清理。
- 服务器监控数据：仅保留最近数日。

## 五、您的权利

1. **查询与更正**：您可以随时在个人设置中查看、修改您的资料与密码。
2. **删除**：您可以删除自己发布的帖子与回复。
3. **注销**：您可以通过个人设置的"注销账号"入口（需邮箱验证码确认）注销账号；注销后您的凭据与邮箱信息将被清除，用户名将被释放。
4. **投诉**：如需处理与您相关的其他个人信息，请通过【站长邮箱】联系站长，我们将在合理期限内处理。

## 六、未成年人保护

本站不面向未满 14 周岁的未成年人提供服务。若我们发现有人在未获得监护人同意的情况下收集了未成年人的信息，将尽快删除相关数据。

## 七、政策更新与联系方式

本政策可能随功能调整而更新，更新后将在本页面公布并更新日期。如有任何疑问、建议或投诉，请联系【站长邮箱】。
TPL,
        'disclaimer' => <<< 'TPL'
# 免责声明

> 更新日期：【更新日期】。使用本站前，请您仔细阅读本声明。您继续访问或使用本站，即视为已理解并接受本声明的全部内容。

## 一、内容声明

1. 本站为用户自由交流的社区，用户发布的内容仅代表其个人观点，与本站立场无关。
2. 用户对其发布内容的真实性、合法性、准确性自行负责。因信任或使用此类内容而产生的任何直接或间接损失，本站不承担责任。

## 二、服务可用性

1. 本站尽力保障服务的连续与数据安全，但对因故障、攻击、不可抗力或第三方原因造成的数据丢失与服务中断，不承担赔偿责任。
2. 本站不承诺服务无错误、不中断，相关功能可能随时调整或中止。

## 三、外部链接

本站内容中可能出现指向第三方网站的链接，仅为方便访问而提供。其内容的真实性、合法性由来源方负责，本站不对其作任何担保。

## 四、交易与纠纷

本站不对用户之间在线上或线下达成的任何交易、约定或纠纷负责。请自行甄别风险，谨防诈骗。

## 五、侵权处理

如任何主体认为本站内存在侵犯其合法权益的内容，请通过【站长邮箱】提供权属证明与具体链接，核实后我们将及时处理（隐藏或删除）。
TPL,
    ];
}

/** 协议最后保存时间（后台保存时戳记）：key = terms|privacy|disclaimer，无记录返回 0 */
function doc_updated(string $k): int
{
    return (int)cfg('doc_u_' . $k, 0);
}

/** 已填写内容的协议列表：key => ['title'=>..,'body'=>..]（保持定义顺序） */
function doc_list(): array
{
    $out = [];
    foreach (doc_defs() as $k => [$title, $key]) {
        $body = trim((string)cfg($key, ''));
        if ($body !== '') {
            $out[$k] = ['title' => $title, 'body' => $body];
        }
    }
    return $out;
}

/** 协议内容指纹：任一协议内容变更后，已同意的 cookie 自动失效，需重新确认 */
function doc_fingerprint(): string
{
    $all = [];
    foreach (doc_defs() as $k => [$title, $key]) {
        $all[$k] = (string)cfg($key, '');
    }
    return md5((string)json_encode($all, JSON_UNESCAPED_UNICODE));
}

/** 当前访客是否已同意当前版本的协议（会话或一年期 cookie，内容指纹比对）
 * v1.18.0：cookie 值升级为「指纹.同意时间戳」，可向用户展示确认时间；兼容旧的纯指纹格式。 */
function doc_gate_passed(): bool
{
    $fp = doc_fingerprint();
    if (isset($_SESSION['doc_agreed']) && (string)$_SESSION['doc_agreed'] === $fp) {
        return true;
    }
    $c = (string)($_COOKIE['mf_doc'] ?? '');
    if ($c === '') {
        return false;
    }
    if ($c === $fp) {
        return true; // 旧版纯指纹 cookie，继续有效
    }
    $i = strrpos($c, '.');
    return $i !== false && substr($c, 0, $i) === $fp;
}

/** 当前访客的协议同意记录（协议中心 / 门禁页展示）：null=未同意；数组=时间/载体/版本 */
function doc_agree_record(): ?array
{
    $fp = doc_fingerprint();
    $ver = strtoupper(substr($fp, 0, 8));
    if (isset($_SESSION['doc_agreed']) && (string)$_SESSION['doc_agreed'] === $fp) {
        $t = (int)($_SESSION['doc_agreed_t'] ?? 0);
        return ['via' => '本次登录会话', 'time' => $t, 'ver' => $ver];
    }
    $c = (string)($_COOKIE['mf_doc'] ?? '');
    if ($c === '') {
        return null;
    }
    $t = 0;
    if (strrpos($c, '.') !== false) {
        $t = (int)substr($c, strrpos($c, '.') + 1);
    }
    if ($c === $fp || (strrpos($c, '.') !== false && substr($c, 0, strrpos($c, '.')) === $fp)) {
        return ['via' => '浏览器 Cookie（一年内免重复确认）', 'time' => $t, 'ver' => $ver];
    }
    return null;
}

/** 协议门禁是否生效：后台开关开启 + 至少一份协议已填写 + 当前访客尚未同意（管理员豁免，避免把自己锁在门外） */
function doc_gate_required(string $p): bool
{
    if ((int)cfg('doc_gate', 0) !== 1 || doc_list() === []) {
        return false;
    }
    $me = current_user();
    if ($me && !empty($me['admin'])) {
        return false;
    }
    if (doc_gate_passed()) {
        return false;
    }
    /* 登录 / 注册 / 找回密码 / 协议页 / 图标资源 / 登出 不做拦截（否则用户无法完成同意流程）
       v1.18.0：ping / asset 也放行——资源请求不该被门禁页接管（HTML 冒充 CSS 导致整站无样式）。
       主入口 index.php 已把这三个资源端点提前出站，这里是双保险。 */
    return !in_array($p, ['doc', 'login', 'register', 'forgot', 'icon', 'logout', 'ping', 'asset'], true);
}
