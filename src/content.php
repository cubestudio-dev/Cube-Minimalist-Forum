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
    $bs = Store::read('boards.php', []);
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
    $x = Store::read('threads/index.php', []);
    return is_array($x) ? $x : [];
}

const INDEX_KEYS = ['board', 'author', 'title', 'created', 'replies', 'likes', 'pinned', 'locked', 'hidden', 'appealed', 'last_reply', 'last_reply_by'];

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
    $t = Store::read('threads/t' . $id . '.php');
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

/**
 * AI 审核队列：每次调用只处理一条（先进先出），带非阻塞锁 + 调用间隔保护
 * 页面访问时自动触发（register_shutdown_function），后台也可手动触发
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
    try {
        $state = Store::read('ai_state.php', ['last' => 0]);
        if (!$manual && time() - (int)($state['last'] ?? 0) < 3) {
            Store::unlock($lk);
            return [false, ''];
        }
        $rid = queue_shift();
        if ($rid === null) {
            Store::unlock($lk);
            return [false, $manual ? '当前队列为空' : ''];
        }
        $rep = report_get($rid);
        if (!$rep || $rep['status'] !== 'pending') {
            Store::unlock($lk);
            return [true, '该举报已不在待审状态'];
        }
        $gone = false;
        $content = report_target_content($rep, $gone);
        if ($gone) {
            report_update($rid, ['status' => 'gone', 'handled' => time()]);
            Store::unlock($lk);
            return [true, '原内容已被删除，举报已自动关闭'];
        }

        $verdict = '';
        $note = '';
        $used = '';
        $ok = ai_moderate($content, $verdict, $note, [], $used); // 审核状态（last/active/fail）由 ai_moderate 内部落盘
        $isThread = ($rep['type'] === 'thread');
        $no = $isThread ? '帖子 #' . (int)$rep['tid'] : '帖子 #' . (int)$rep['tid'] . ' 中的回复 #' . (int)$rep['rid'];

        if (!$ok) {
            // 全部模型重试仍失败：保持隐藏，通知管理员人工处理
            report_update($rid, ['status' => 'ai_failed', 'note' => cut_str($note, 200), 'handled' => time()]);
            log_action('ai_failed', '举报 #' . $rid . '（' . $no . '）：' . cut_str($used . '：' . $note, 160) . '；内容保持隐藏，已通知管理员', 0, '系统');
            foreach (user_all() as $au) {
                if (!empty($au['admin'])) {
                    notify_add((int)$au['id'], 'ai_admin', 'AI 审核失败', '举报 #' . $rid . '（' . $no . '）审核失败：' . cut_str($note, 120) . '。内容保持隐藏，请到后台「AI 待审队列」处理。', 'p=admin&tab=queue');
                }
            }
            Store::unlock($lk);
            return [true, 'AI 调用失败：' . cut_str($note, 80) . '（内容保持隐藏，已通知管理员）'];
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
            Store::unlock($lk);
            return [true, '审核完成：判定违规，内容保持隐藏，已通知被举报人'];
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
        Store::unlock($lk);
        return [true, '审核完成：未发现违规，内容已恢复展示'];
    } catch (Throwable $ex) {
        Store::unlock($lk);
        return [false, '审核异常：' . $ex->getMessage()];
    }
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
    $no = $type === 'thread' ? '帖子 #' . $tid : '帖子 #' . $tid . ' 中的回复 #' . $rid;
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
