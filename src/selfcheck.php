<?php
/**
 * 极简论坛 · 功能一键自检 + 自动修复（v1.44.0 引擎 / v1.45.0 实时进度升级 / v1.46.0 端到端测试）
 *
 * 把「后台一个个点测试、一条条敲命令排查」变成一次跑完：
 *   环境基线 / 读写 / 存储完整性与帖子索引 / 会话 / 程序文件完整性（manifest 校验）/
 *   注册端到端 / 登录端到端 / 发帖互动端到端 / 搜索 / AI 队列 / 静态快照；
 *   勾选远程项还会用管理员邮箱真实发一封测试邮件、真实调用一次 AI 审核（较慢）。
 * 发现的问题逐项给出结论与修复办法；可自动修复项支持一键修复（保守修复：先备份，绝不自动删数据）。
 *
 * v1.45.0：拆分为「目录（selfcheck_catalog）+ 逐项执行（selfcheck_run_item）」——
 *   前端逐项请求后端，检查进度 / 修复进度 / 清单 / 日志全程实时可视；
 *   selfcheck_run() 整跑接口保持不变（CLI / 旧调用方零改动），逐项失败不再拖垮整场。
 * v1.46.0：新增三个端到端（E2E）探针测试——注册 / 登录 / 发帖互动，
 *   用一次性探针账号真实走完用户旅程（即建即删不留痕，失败自动清理）;
 *   新增修复项 clean_probes（清理中断残留的探针账号与探针帖）。
 *
 * 报告落盘 data/selfcheck.php（后台「监控」标签页可回看上次结果与运行日志）。
 */
defined('APP') or exit('Forbidden');

/** 上次自检报告（无则 []） */
function selfcheck_last_read(): array
{
    $x = Store::read('selfcheck.php', []);
    return is_array($x) ? $x : [];
}

/** 保存自检报告 */
function selfcheck_last_write(array $rep): void
{
    Store::write('selfcheck.php', $rep);
}

/**
 * v1.45.0 自检项目目录（清单常驻显示 + 逐项执行的单一数据源）
 * @return array[] ['key','name','desc','remote']；顺序即执行顺序
 */
function selfcheck_catalog(): array
{
    return [
        ['key' => 'env', 'name' => '环境基线', 'remote' => false,
            'desc' => 'PHP 版本、必需函数（json / flock / fsockopen / mbstring）、磁盘剩余空间'],
        ['key' => 'rw', 'name' => '读写测试', 'remote' => false,
            'desc' => 'data/ 真实写入 → 读回 → 删除探针文件，验证存储层可写（发帖 / 注册 / 设置的基础）'],
        ['key' => 'store', 'name' => '存储完整性与帖子索引', 'remote' => false,
            'desc' => '核心数据文件可解析、抽样校验帖子可读、索引与 threads/ 目录一致性（帖子「消失」的常见根因）'],
        ['key' => 'sess', 'name' => '会话', 'remote' => false,
            'desc' => 'PHP 会话激活状态（登录态基础）'],
        ['key' => 'reg', 'name' => '注册端到端', 'remote' => false,
            'desc' => '探针账号真实走注册链路：用户名 / 邮箱校验 → 查重 → 创建 → 哈希核验 → 重名拦截反转 → 即时清理（注册开关关闭时自动跳过）'],
        ['key' => 'login', 'name' => '登录端到端', 'remote' => false,
            'desc' => '真实校验登录门闩：错误密码拒绝 → 正确密码通过 → 封禁标记生效 → 会话机制可用（不触碰管理员当前登录态）'],
        ['key' => 'post', 'name' => '发帖互动端到端', 'remote' => false,
            'desc' => '两个探针账号真实走发帖全流程：板块权限 → 发帖 → 索引同步 → 搜索命中 → 回帖 → 帖子 / 回复点赞 → 编辑 → 删帖，即建即删不留痕（无可用板块或功能关闭时自动跳过）'],
        ['key' => 'chunkup', 'name' => '分块上传端到端', 'remote' => false,
            'desc' => 'v1.47.0 大文件分块直传真实走一遍：预检 → 建会话 → 逐块上送（缺块拦截 / 大小逐字核对）→ 合并落位 → 内容 MD5 比对 → meta 边车 → 越权拒绝 → 中止清理 → 陈旧会话回收，即测即清（上传功能关闭时自动跳过）'],
        ['key' => 'media', 'name' => '附件出站与限速下载', 'remote' => false,
            'desc' => 'v1.47.0 附件点击交互与多线程下载的服务端链路：出站 MIME 白名单（文本类预览扩展名全覆盖，防「能传不能出」404）→ 点击形态（图片内联 / pdf 仅预览内联 / 其余直下）→ Range 边界（206 分段 / 越界 416）→ 带宽换算（Mbps→B/s、线程份额钳制）→ meta 边车还原真实文件名'],
        ['key' => 'manifest', 'name' => '程序文件完整性', 'remote' => false,
            'desc' => 'manifest.txt 逐文件 sha256 校验，专抓升级包没传全 / 文件被改 / 版本混合（Call to undefined function 类故障多半源于此）'],
        ['key' => 'search', 'name' => '站内搜索', 'remote' => false,
            'desc' => '内部真实跑一次检索与输入联想，验证搜索全链路（搜索栏无反应先看这里）'],
        ['key' => 'queue', 'name' => 'AI 审核队列', 'remote' => false,
            'desc' => 'AI 审核队列积压情况与自主管理状态（举报长期无人处理先看这里）'],
        ['key' => 'snapshot', 'name' => '静态快照', 'remote' => false,
            'desc' => '快照缓存页数；升级后旧快照会让游客看到旧版页面（页脚版本不对先看这里）'],
        ['key' => 'temp', 'name' => '临时文件与锁', 'remote' => false,
            'desc' => '陈旧锁文件与自检探针残留（进程中断残留可能拖慢写入）'],
        ['key' => 'mail', 'name' => 'SMTP 邮箱全链路', 'remote' => true,
            'desc' => '用管理员注册邮箱真实发一封测试邮件（模板渲染 → 四层回退连接 → 认证 → 发信）'],
        ['key' => 'ai', 'name' => 'AI 审核真实调用', 'remote' => true,
            'desc' => '真实调用一次 AI 审核无害测试内容，报告判定 / 理由 / 所用模型 / 耗时'],
    ];
}

/** 全部自检项 key（按执行顺序） */
function selfcheck_item_keys(): array
{
    $out = [];
    foreach (selfcheck_catalog() as $c) {
        $out[] = (string)$c['key'];
    }
    return $out;
}

/* ================= v1.46.0 端到端探针（注册 / 登录 / 发帖互动） =================
   原则：一次性探针账号即建即删、失败也要清理（try/catch + 显式清理）；
   探针带三重识别标记——用户名前缀 sc_e2e_、bio=selfcheck-probe、探针帖标题前缀【自检探针帖】，
   三重标记同时供 clean_probes 修复项兜底清理中断残留。 */

/**
 * 创建探针账号（bio 打标记；返回 id / name / email / pass 供链路校验）
 * $create=false 时仅生成凭据不建号（注册端到端专用：创建动作必须在链路内验证）
 */
function selfcheck_probe_spawn(string $tag, bool $create = true): array
{
    $name = 'sc_e2e_' . substr($tag, 0, 1) . bin2hex(random_bytes(3));
    $email = $name . '@selfcheck.local';
    $pass = 'Sc!' . bin2hex(random_bytes(8));
    $id = 0;
    if ($create) {
        $id = user_create($name, $email, password_hash($pass, PASSWORD_DEFAULT), false);
        user_update($id, ['bio' => 'selfcheck-probe']);
    }
    return ['id' => $id, 'name' => $name, 'email' => $email, 'pass' => $pass];
}

/** 硬移除探针账号行（user_delete 是软注销会留「已注销」条目，探针必须彻底移除不留痕） */
function selfcheck_probe_remove(int $id): void
{
    if ($id <= 0) {
        return;
    }
    $lk = Store::lock('users');
    $us = user_all();
    $n = count($us);
    $us = array_values(array_filter($us, static function ($u) use ($id): bool {
        return (int)($u['id'] ?? 0) !== $id;
    }));
    if (count($us) !== $n) {
        Store::write('users.php', $us);
    }
    Store::unlock($lk);
}

/** 注册链路（镜像 act_register 的数据层门闩；创建动作在本链路内完成，失败抛 RuntimeException，消息即步骤名） */
function selfcheck_reg_flow(array &$probe): void
{
    /* 1. 校验链：用户名规则 / 邮箱格式（act_register 同款 valid_name / valid_email） */
    if (!valid_name((string)$probe['name'])) {
        throw new RuntimeException('用户名校验');
    }
    if (!valid_email((string)$probe['email'])) {
        throw new RuntimeException('邮箱格式校验');
    }
    if (valid_email('not-an-email')) {
        throw new RuntimeException('非法邮箱拦截');
    }
    /* 2. 查重：创建前不应存在（act_register 拦「用户名已被占用 / 该邮箱已被注册」的依据） */
    if (user_by_name((string)$probe['name'])) {
        throw new RuntimeException('创建前重名探测');
    }
    if (user_by_email((string)$probe['email'])) {
        throw new RuntimeException('创建前邮箱查重');
    }
    /* 3. 真实创建（同 act_register：password_hash 入库）+ 探针标记（中断后 clean_probes 可兜底） */
    $uid = user_create((string)$probe['name'], (string)$probe['email'], password_hash((string)$probe['pass'], PASSWORD_DEFAULT), false);
    user_update($uid, ['bio' => 'selfcheck-probe']);
    $probe['id'] = $uid;
    $u = user_by_id($uid);
    if (!$u) {
        throw new RuntimeException('账号创建回读');
    }
    if ((string)($u['name'] ?? '') !== (string)$probe['name'] || str_lower((string)($u['email'] ?? '')) !== (string)$probe['email']) {
        throw new RuntimeException('回读字段比对');
    }
    /* 4. 密码哈希：必须可校验通过，且绝不是明文 */
    if (!password_verify((string)$probe['pass'], (string)($u['pass'] ?? ''))) {
        throw new RuntimeException('密码哈希校验');
    }
    if ((string)($u['pass'] ?? '') === (string)$probe['pass']) {
        throw new RuntimeException('密码明文存储检测');
    }
    /* 5. 查重反转：创建后必须能被查到（重名 / 邮箱注册从此被拦） */
    if (!user_by_name((string)$probe['name']) || !user_by_email((string)$probe['email'])) {
        throw new RuntimeException('创建后查重反转');
    }
}

/** 登录链路（镜像 act_login 的数据层门闩；刻意不调用 auth_login——它会 session_regenerate_id 重建管理员当前会话） */
function selfcheck_login_flow(array $probe): void
{
    /* 1. 凭据查找（act_login 同款：用户名优先，邮箱兜底） */
    $u = user_by_name((string)$probe['name'], true) ?? user_by_email((string)$probe['email']);
    if (!$u) {
        throw new RuntimeException('凭据查找');
    }
    /* 2. 错误密码必须拒绝（登录门闩 password_verify） */
    if (password_verify('wrong-' . bin2hex(random_bytes(6)), (string)($u['pass'] ?? ''))) {
        throw new RuntimeException('错误密码拒绝');
    }
    /* 3. 正确密码必须通过 */
    if (!password_verify((string)$probe['pass'], (string)($u['pass'] ?? ''))) {
        throw new RuntimeException('正确密码校验');
    }
    /* 4. 封禁门闩数据链路（act_login 拒绝 banned 账号的依据） */
    user_update((int)$u['id'], ['banned' => 1]);
    if (empty(user_by_id((int)$u['id'])['banned'])) {
        throw new RuntimeException('封禁标记生效');
    }
    user_update((int)$u['id'], ['banned' => 0]);
    if (!empty(user_by_id((int)$u['id'])['banned'])) {
        throw new RuntimeException('解封标记生效');
    }
    /* 5. 会话机制可用性（登录建立会话的基础；session_regenerate_id 为 auth_login 所用） */
    if (!function_exists('session_regenerate_id')) {
        throw new RuntimeException('会话机制（session_regenerate_id）');
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $k = 'sc_e2e_sess_' . bin2hex(random_bytes(2));
        $_SESSION[$k] = 'ok';
        if (($_SESSION[$k] ?? '') !== 'ok') {
            throw new RuntimeException('会话写入');
        }
        unset($_SESSION[$k]);
    }
}

/** 发帖互动链路（镜像 act_thread_new / act_reply_new / act_like 的数据层；探针 A 发帖，探针 B 回帖 / 点赞） */
function selfcheck_post_flow(array $a, array $b, array &$steps): int
{
    /* 1. 板块权限（act_thread_new 同款 board_can_post） */
    $bid = 0;
    $ua = user_by_id((int)$a['id']) ?: [];
    foreach (board_all() as $bd) {
        if (board_can_post((int)($bd['id'] ?? 0), $ua)) {
            $bid = (int)($bd['id'] ?? 0);
            break;
        }
    }
    if ($bid <= 0) {
        throw new RuntimeException('可用板块（板块权限均不允许普通用户发帖）');
    }
    $steps[] = '板块「' . board_name($bid) . '」权限通过';
    /* 2. 发帖 → 回读 → 索引同步 */
    $token = 'E2E' . strtoupper(bin2hex(random_bytes(3)));
    $title = '【自检探针帖】' . $token;
    $content = '这是功能一键自检的端到端探针帖（发帖 → 回帖 → 点赞 → 编辑 → 删除 全链路实测），将在数秒内自动删除。识别码 ' . $token;
    $tid = thread_create($bid, $title, $content, (int)$a['id']);
    if ($tid <= 0) {
        throw new RuntimeException('发帖创建');
    }
    $t = thread_get($tid);
    if (!$t || (string)($t['title'] ?? '') !== $title || (int)($t['author'] ?? 0) !== (int)$a['id']
        || strpos((string)($t['content'] ?? ''), $token) === false) {
        throw new RuntimeException('帖子回读比对');
    }
    $inIdx = false;
    foreach (thread_index() as $x) {
        if ((int)($x['id'] ?? 0) === $tid) {
            $inIdx = (int)($x['replies'] ?? -1) === 0;
            break;
        }
    }
    if (!$inIdx) {
        throw new RuntimeException('索引同步（replies=0）');
    }
    $steps[] = '发帖 #' . $tid . ' → 回读 → 索引同步';
    /* 3. 搜索命中（真实新数据进搜索窗口，act_thread_new 之后用户可搜到的依据） */
    $hit = false;
    foreach (search_threads($token) as $h) {
        if ((int)($h['t']['id'] ?? 0) === $tid) {
            $hit = true;
            break;
        }
    }
    if (!$hit) {
        throw new RuntimeException('搜索命中');
    }
    $steps[] = '搜索「' . $token . '」命中';
    /* 4. 回帖（act_reply_new 同款 reply_add）→ 计数与最后回复人同步 */
    $rid = reply_add($tid, '探针回复——' . $token, (int)$b['id']);
    if ($rid === null || $rid <= 0) {
        throw new RuntimeException('回帖创建');
    }
    if (count(reply_list($tid)) !== 1) {
        throw new RuntimeException('回帖列表回读');
    }
    $inIdx = null;
    foreach (thread_index() as $x) {
        if ((int)($x['id'] ?? 0) === $tid) {
            $inIdx = [(int)($x['replies'] ?? -1), (int)($x['last_reply_by'] ?? 0)];
            break;
        }
    }
    if (!$inIdx || $inIdx[0] !== 1 || $inIdx[1] !== (int)$b['id']) {
        throw new RuntimeException('回帖索引同步');
    }
    $steps[] = '回帖 #' . $rid . ' → 计数 / 最后回复人同步';
    /* 5. 点赞：B 赞帖 + A 赞回复（act_like 同款 like_toggle，双向各测一次） */
    [$okT, $nT] = like_toggle('t', $tid, 0, (int)$b['id']);
    if (!$okT || $nT !== 1) {
        throw new RuntimeException('帖子点赞');
    }
    [$okR, $nR] = like_toggle('r', $tid, $rid, (int)$a['id']);
    if (!$okR || $nR !== 1) {
        throw new RuntimeException('回复点赞');
    }
    if ((int)(thread_get($tid)['likes'] ?? [])[0] !== (int)$b['id']) {
        throw new RuntimeException('点赞数据回读');
    }
    $steps[] = '帖子 / 回复点赞 → 计数回读';
    /* 6. 编辑（act_thread_edit 同款 thread_save，标题与索引同步） */
    $title2 = $title . '（已编辑）';
    thread_save($tid, ['title' => $title2]);
    if ((string)(thread_get($tid)['title'] ?? '') !== $title2) {
        throw new RuntimeException('编辑回读');
    }
    $idxTitle = '';
    foreach (thread_index() as $x) {
        if ((int)($x['id'] ?? 0) === $tid) {
            $idxTitle = (string)($x['title'] ?? '');
            break;
        }
    }
    if ($idxTitle !== $title2) {
        throw new RuntimeException('编辑索引同步');
    }
    $steps[] = '编辑 → 正文 / 索引同步';
    /* 7. 删帖 → 正文 / 回复 / 索引三处全清（thread_delete 同款） */
    if (!thread_delete($tid)) {
        throw new RuntimeException('删帖');
    }
    if (thread_get($tid) !== null || is_file(Store::path('replies/t' . $tid . '.php'))) {
        throw new RuntimeException('删帖残留检查');
    }
    foreach (thread_index() as $x) {
        if ((int)($x['id'] ?? 0) === $tid) {
            throw new RuntimeException('删帖索引残留');
        }
    }
    $steps[] = '删帖 → 正文 / 回复 / 索引全清';
    return $tid;
}

/**
 * v1.45.0 逐项执行：只跑一个自检项（远程项在 remote=false 时给出 skip 说明）
 * @return array ['key','name','state'(pass|warn|fail|skip),'detail','fixable','fixkey']
 */
function selfcheck_run_item(string $key, bool $remote): array
{
    if (in_array($key, ['mail', 'ai'], true)) {
        @set_time_limit(120);
    } else {
        @set_time_limit(60);
    }
    switch ($key) {

        case 'env':
            /* 1. 环境基线 */
            $needFn = ['json_encode' => '数据存储', 'flock' => '文件锁', 'fsockopen' => 'SMTP 发信 / DNS 兜底', 'mb_strlen' => '中文处理（可自动回退）'];
            $missing = [];
            foreach ($needFn as $fn => $why) {
                if (!function_exists($fn)) {
                    $missing[] = $fn . '（' . $why . '）';
                }
            }
            $disk = function_exists('disk_free_space') ? (int)(@disk_free_space('.') / 1048576) : 0;
            if ($missing) {
                return ['key' => 'env', 'name' => '环境基线', 'state' => 'fail', 'detail' => '缺少必需函数：' . implode('、', $missing) . '。请在 php.ini 开启对应扩展（后台「运行环境 · PHP 扩展」可一键开启）'];
            }
            if ($disk > 0 && $disk < 20) {
                return ['key' => 'env', 'name' => '环境基线', 'state' => 'fail', 'detail' => '磁盘剩余空间仅 ' . $disk . ' MB，写入操作随时可能失败，请立即清理'];
            }
            return ['key' => 'env', 'name' => '环境基线', 'state' => 'pass', 'detail' => 'PHP ' . PHP_VERSION . '，必需函数齐全' . ($disk > 0 ? '，磁盘剩余 ' . ($disk > 1024 ? round($disk / 1024, 1) . ' GB' : $disk . ' MB') : '')];

        case 'rw':
            /* 2. 读写测试（真实写读删探针文件） */
            $probe = 'selfcheck-probe-' . bin2hex(random_bytes(4)) . '.tmp';
            $okW = false;
            $errW = '';
            try {
                $okW = Store::write($probe, 'selfcheck-' . time());
                if ($okW) {
                    $back = Store::read($probe, '');
                    if ($back === '' || strpos((string)$back, 'selfcheck-') !== 0) {
                        $okW = false;
                        $errW = '写入后读回内容不一致';
                    }
                    @unlink(Store::path($probe));
                }
            } catch (Throwable $e) {
                $okW = false;
                $errW = $e->getMessage();
            }
            return ['key' => 'rw', 'name' => '读写测试', 'state' => $okW ? 'pass' : 'fail', 'detail' => $okW ? 'data/ 写入 → 读回 → 删除 探针成功' : 'data/ 读写失败：' . $errW . '。可点「一键修复」尝试修复目录权限，或通过 FTP 将 data/ 及子目录权限设为 755/775', 'fixkey' => $okW ? '' : 'repair_dirs', 'fixable' => !$okW];

        case 'store':
            /* 3. 存储完整性与帖子索引一致性 */
            $coreBad = [];
            foreach (['users.php', 'boards.php', 'config.php', 'announcements.php'] as $cf) {
                if (is_file(Store::path($cf))) {
                    $v = Store::read($cf, null);
                    if (!is_array($v)) {
                        $coreBad[] = $cf;
                    }
                }
            }
            $idx = thread_index();
            $idxN = count($idx);
            $files = glob(Store::path('threads/t*.php')) ?: [];
            $fileN = count($files);
            $corrupt = 0;
            foreach (array_slice($files, 0, 200) as $tf) { /* 抽样最多 200 帖验证可解析（用正规读链：自动解压/兼容旧明文） */
                if (!preg_match('/t(\d+)\.php$/', (string)$tf, $mm)) {
                    continue;
                }
                if (thread_get((int)$mm[1]) === null) {
                    $corrupt++;
                }
            }
            if ($coreBad) {
                return ['key' => 'store', 'name' => '存储完整性', 'state' => 'fail', 'detail' => '核心数据文件无法解析：' . implode('、', $coreBad) . '。已建议先备份（data/backup/）；如为新装则属正常，可忽略', 'fixkey' => 'backup_damaged', 'fixable' => true];
            }
            if ($corrupt > 0) {
                return ['key' => 'store', 'name' => '存储完整性', 'state' => 'fail', 'detail' => '发现 ' . $corrupt . ' 个帖子数据文件疑似损坏（JSON 解析失败）。文件保留未动，请通过 data/backup/ 备份比对恢复'];
            }
            if ($idxN !== $fileN) {
                return ['key' => 'store', 'name' => '存储完整性与帖子索引', 'state' => 'warn', 'detail' => '索引记录 ' . $idxN . ' 条，但 threads/ 目录实际有 ' . $fileN . ' 个帖子文件——不一致会导致部分帖子在列表「消失」但详情页直链仍可访问（典型于更新中断 / 手工搬数据）。可一键重建索引修复', 'fixkey' => 'rebuild_index', 'fixable' => true];
            }
            return ['key' => 'store', 'name' => '存储完整性与帖子索引', 'state' => 'pass', 'detail' => '核心数据可解析；索引 ' . $idxN . ' 条与 threads/ 目录 ' . $fileN . ' 帖一致'];

        case 'sess':
            /* 4. 会话 */
            return ['key' => 'sess', 'name' => '会话', 'state' => session_status() === PHP_SESSION_ACTIVE ? 'pass' : 'warn', 'detail' => session_status() === PHP_SESSION_ACTIVE ? '会话已激活，登录态正常' : '会话未激活（当前请求无需会话即通过，一般无碍）'];

        case 'reg':
            /* 5. 注册端到端（探针账号真实走注册链路，结束即清理） */
            if (!feat_on('register')) {
                return ['key' => 'reg', 'name' => '注册端到端', 'state' => 'skip', 'detail' => '注册功能当前为关（后台「功能开关」）——真实注册链路无从测起，开启后可再测'];
            }
            $probe = selfcheck_probe_spawn('r', false);
            $err = '';
            try {
                selfcheck_reg_flow($probe);
            } catch (Throwable $e) {
                $err = $e->getMessage();
            }
            selfcheck_probe_remove((int)$probe['id']);
            if ($err !== '') {
                return ['key' => 'reg', 'name' => '注册端到端', 'state' => 'fail', 'detail' => '注册链路在「' . $err . '」一步中断（探针账号已即时清理，未留痕）。用户名 / 邮箱校验、查重、账号创建、密码哈希中至少一环失效——新用户将无法注册', 'fixkey' => 'clean_probes', 'fixable' => true];
            }
            if (user_by_name((string)$probe['name'])) {
                return ['key' => 'reg', 'name' => '注册端到端', 'state' => 'warn', 'detail' => '注册链路本身通过，但探针账号清理失败（可能 data/users.php 权限问题）。可「一键修复 → 清理自检探针残留」兜底', 'fixkey' => 'clean_probes', 'fixable' => true];
            }
            return ['key' => 'reg', 'name' => '注册端到端', 'state' => 'pass', 'detail' => '注册链路走通：用户名 / 邮箱校验 → 查重 → 创建 → 回读比对 → 哈希核验（非明文）→ 重名拦截反转，探针账号已即时清理'];

        case 'login':
            /* 6. 登录端到端（真实校验门闩；刻意不动管理员当前会话） */
            $probe = selfcheck_probe_spawn('l');
            $err = '';
            try {
                selfcheck_login_flow($probe);
            } catch (Throwable $e) {
                $err = $e->getMessage();
            }
            selfcheck_probe_remove((int)$probe['id']);
            if ($err !== '') {
                return ['key' => 'login', 'name' => '登录端到端', 'state' => 'fail', 'detail' => '登录链路在「' . $err . '」一步中断（探针账号已即时清理）。凭据查找 / 密码哈希 / 封禁门闩 / 会话机制中至少一环失效——用户将「登录不上」或「谁都登录得上」', 'fixkey' => 'clean_probes', 'fixable' => true];
            }
            return ['key' => 'login', 'name' => '登录端到端', 'state' => 'pass', 'detail' => '登录门闩全部有效：凭据查找（用户名 / 邮箱）→ 错误密码拒绝 → 正确密码通过 → 封禁标记生效可恢复 → 会话机制可用（本项刻意不重建会话，不触碰管理员当前登录态）'];

        case 'post':
            /* 7. 发帖互动端到端（探针 A 发帖 / 探针 B 回帖点赞，即建即删） */
            if (!feat_on('post')) {
                return ['key' => 'post', 'name' => '发帖互动端到端', 'state' => 'skip', 'detail' => '发帖功能当前为关（后台「功能开关」）——发帖链路无从测起，开启后可再测'];
            }
            $pa = selfcheck_probe_spawn('a');
            $pb = selfcheck_probe_spawn('b');
            $err = '';
            $steps = [];
            $tid = 0;
            try {
                $tid = selfcheck_post_flow($pa, $pb, $steps);
            } catch (Throwable $e) {
                $err = $e->getMessage();
            } finally {
                if ($tid > 0) {
                    thread_delete($tid); /* 幂等兜底：链路中断时也绝不让探针帖留痕 */
                }
                selfcheck_probe_remove((int)$pa['id']);
                selfcheck_probe_remove((int)$pb['id']);
            }
            if ($err !== '') {
                return ['key' => 'post', 'name' => '发帖互动端到端', 'state' => 'fail', 'detail' => '发帖全流程在「' . $err . '」一步中断（探针帖与探针账号已即时清理）。已完成：' . ($steps ? implode('；', $steps) . '；' : '') . '——发帖 / 回帖 / 点赞 / 编辑 / 删帖中至少一环失效，用户操作会在该步报错', 'fixkey' => 'clean_probes', 'fixable' => true];
            }
            $left = 0;
            foreach (thread_index() as $x) {
                if (strpos((string)($x['title'] ?? ''), '【自检探针帖】') === 0) {
                    $left++;
                }
            }
            if ($left > 0) {
                return ['key' => 'post', 'name' => '发帖互动端到端', 'state' => 'warn', 'detail' => '发帖全流程本身走通，但发现 ' . $left . ' 个探针帖残留（多为上次中断所致，对访客可见）。可「一键修复 → 清理自检探针残留」兜底', 'fixkey' => 'clean_probes', 'fixable' => true];
            }
            return ['key' => 'post', 'name' => '发帖互动端到端', 'state' => 'pass', 'detail' => '发帖全流程走通（真实数据）：' . implode('；', $steps) . '；2 个探针账号与探针帖已即时清理，全程不留痕'];

        case 'chunkup':
            /* v1.48.0 · 8. 分块上传端到端（与线上 act_* 同源的核心函数真实走全链路，即测即清） */
            if (!feat_on('upload')) {
                return ['key' => 'chunkup', 'name' => '分块上传端到端', 'state' => 'skip', 'detail' => '上传功能当前为关（后台「功能开关」）——分块链路无从测起，开启后可再测'];
            }
            $allow = array_values(array_filter(array_map(fn ($x) => strtolower(trim(ltrim((string)$x, '.'))), preg_split('/[\s,，;；]+/u', trim((string)cfg('upload_allow_ext', 'zip pdf txt md csv doc docx xls xlsx ppt pptx'))) ?: [])));
            $pext = '';
            foreach (['txt', 'md', 'csv', 'zip', 'pdf', 'doc'] as $x) {
                if (in_array($x, $allow, true)) {
                    $pext = $x;
                    break;
                }
            }
            if ($pext === '') {
                return ['key' => 'chunkup', 'name' => '分块上传端到端', 'state' => 'skip', 'detail' => '当前上传白名单不含文本 / 压缩类扩展名，探针无从上传——白名单加回 txt 等类型后可再测'];
            }
            $CH = 65536;
            $SIZE = $CH * 2 + 1234;
            $tag = bin2hex(random_bytes(3));
            $problems = [];
            $clean = [];
            $doneSteps = [];
            try {
                $s = mf_chunk_session_create(0, 'selfcheck-chunk-' . $tag . '.' . $pext, $SIZE, $CH);
                $clean[] = ['dir', $s['dir']];
                if ((int)$s['total'] !== 3) {
                    $problems[] = '分块总数计算不符（应为 3 块，实得 ' . $s['total'] . '）';
                }
                $doneSteps[] = '建会话（64KB×3 块）';
                $data = random_bytes($SIZE);
                mf_chunk_session_store($s['token'], 0, 0, substr($data, 0, $CH));
                mf_chunk_session_store($s['token'], 0, 1, substr($data, $CH, $CH));
                $doneSteps[] = '逐块上送 2/3';
                $okErr = false;
                try {
                    mf_chunk_session_finish($s['token'], 0);
                } catch (RuntimeException $e) {
                    $okErr = strpos($e->getMessage(), '块') !== false;
                }
                if (!$okErr) {
                    $problems[] = '缺块时合并未被拦截（断点续传保护失效——半成品文件会直接落位）';
                }
                $doneSteps[] = '缺块拦截';
                $rejected = false;
                try {
                    mf_chunk_session_store($s['token'], 0, 2, 'short');
                } catch (RuntimeException $e) {
                    $rejected = true;
                }
                if (!$rejected) {
                    $problems[] = '分块大小不符未被逐字核对（错块会静默混入合并）';
                }
                mf_chunk_session_store($s['token'], 0, 2, substr($data, $CH * 2));
                $fin = mf_chunk_session_finish($s['token'], 0);
                $clean[] = ['file', (string)$fin['path']];
                $clean[] = ['file', (string)$fin['path'] . '.meta.json'];
                $doneSteps[] = '合并落位';
                if (!is_file((string)$fin['path'])) {
                    $problems[] = '合并后文件不存在于 uploads 目录';
                } else {
                    if ((int)@filesize((string)$fin['path']) !== $SIZE) {
                        $problems[] = '落位文件大小不符（' . (int)@filesize((string)$fin['path']) . ' / ' . $SIZE . ' B）';
                    }
                    if (md5_file((string)$fin['path']) !== md5($data)) {
                        $problems[] = '合并内容 MD5 与逐块上送内容不一致（分块顺序 / 内容在链路中被破坏）';
                    }
                }
                $scMeta = mf_upload_meta_read((string)$fin['path']);
                if (!isset($scMeta['n']) || strpos((string)$scMeta['n'], 'selfcheck-chunk') !== 0) {
                    $problems[] = 'meta 边车缺失或原始文件名未落盘（下载将无法还原真实文件名）';
                }
                if (is_dir($s['dir'])) {
                    $problems[] = '完成后会话临时目录未清理（tmp 会话垃圾将堆积）';
                }
                $doneSteps[] = '内容 MD5 比对 + meta 边车';
                $s2 = mf_chunk_session_create(0, 'selfcheck-abort-' . $tag . '.' . $pext, $CH, $CH);
                $clean[] = ['dir', $s2['dir']];
                mf_chunk_session_store($s2['token'], 0, 0, str_repeat('A', $CH));
                mf_chunk_session_abort($s2['token'], 0);
                if (is_dir($s2['dir'])) {
                    $problems[] = '中止上传后会话目录未即时清理';
                }
                $doneSteps[] = '中止清理';
                $s3 = mf_chunk_session_create(0, 'selfcheck-x-' . $tag . '.' . $pext, $CH, $CH);
                $clean[] = ['dir', $s3['dir']];
                $denied = false;
                try {
                    mf_chunk_session_store($s3['token'], 999999, 0, str_repeat('A', $CH));
                } catch (RuntimeException $e) {
                    $denied = true;
                }
                if (!$denied) {
                    $problems[] = '越权 token 操作未被拒绝（会话归属校验失效——他人可接管 / 污染会话）';
                }
                $doneSteps[] = '越权拒绝';
                $old = mf_upload_tmp_dir() . '/scold' . $tag;
                @mkdir($old, 0755, true);
                @touch($old . '/0000.chunk', time() - 90000);
                @touch($old, time() - 90000);
                mf_upload_tmp_gc();
                if (is_dir($old)) {
                    $problems[] = '24 小时陈旧会话回收未生效（GC 失灵后 tmp 只进不出）';
                }
                $doneSteps[] = '陈旧会话回收';
            } catch (Throwable $e) {
                $problems[] = '链路异常中断：' . $e->getMessage();
            } finally {
                foreach ($clean as $c) {
                    $p = (string)$c[1];
                    if ($c[0] === 'file') {
                        @unlink($p);
                    } elseif (is_dir($p)) {
                        foreach ((@scandir($p) ?: []) as $f) {
                            if ($f !== '.' && $f !== '..') {
                                @unlink($p . '/' . $f);
                            }
                        }
                        @rmdir($p);
                    }
                }
            }
            if ($problems) {
                return ['key' => 'chunkup', 'name' => '分块上传端到端', 'state' => 'fail', 'detail' => implode('；', $problems) . '。已走通：' . ($doneSteps ? implode(' → ', $doneSteps) : '（无）') . '（探针文件已即时清理）'];
            }
            return ['key' => 'chunkup', 'name' => '分块上传端到端', 'state' => 'pass', 'detail' => '分块直传全链路走通（' . implode(' → ', $doneSteps) . '），合并内容 MD5 一致，探针文件已即时清理'];

        case 'media':
            /* v1.48.0 · 9. 附件出站与限速下载（服务端决策层全断言，探针即建即删） */
            $problems = [];
            $m = mf_media_mimes();
            $doneSteps = [];
            foreach (['png', 'jpg', 'jpeg', 'gif', 'webp'] as $x) {
                if (!isset($m[$x])) {
                    $problems[] = '图片类型 .' . $x . ' 不在出站 MIME 白名单（帖子内嵌图将 404）';
                }
            }
            foreach (['txt', 'md', 'csv', 'log', 'json', 'xml', 'yml', 'yaml', 'sql', 'srt', 'vtt', 'ics'] as $x) {
                if (!isset($m[$x])) {
                    $problems[] = '前端可预览扩展名 .' . $x . ' 不在服务端出站白名单（管理员加入上传白名单后会「能传不能出」——点击即 404）';
                }
            }
            foreach (['svg', 'html', 'htm', 'php', 'js'] as $x) {
                if (isset($m[$x])) {
                    $problems[] = '危险扩展名 .' . $x . ' 竟在出站白名单（可注入类型一律不得出站）';
                }
            }
            $doneSteps[] = '出站白名单';
            if (!mf_media_inline('png', false) || !mf_media_inline('jpg', true)) {
                $problems[] = '图片应始终内联渲染（<img> 直接显示）';
            }
            if (mf_media_inline('txt', false) || mf_media_inline('txt', true) || mf_media_inline('csv', false) || mf_media_inline('zip', false)) {
                $problems[] = '文本 / 压缩类附件应强制 attachment（点击直接下载，而不是跳到浏览器查看）';
            }
            if (!mf_media_inline('pdf', true) || mf_media_inline('pdf', false)) {
                $problems[] = 'pdf 应仅站内预览（pv=1）时内联，其余场景直接下载';
            }
            $doneSteps[] = '点击形态';
            $r = mf_media_range('bytes=0-0', 1000);
            if ($r['status'] !== 206 || $r['start'] !== 0 || $r['end'] !== 0) {
                $problems[] = 'Range 0-0 探测（多线程下载第一步）解析异常';
            }
            $r = mf_media_range('bytes=100-999', 1000);
            if ($r['status'] !== 206 || $r['start'] !== 100 || $r['end'] !== 999) {
                $problems[] = 'Range 中段解析异常（多线程分段将错位）';
            }
            $r = mf_media_range('bytes=-3', 1000);
            if ($r['status'] !== 206 || $r['start'] !== 997) {
                $problems[] = 'Range 末 N 字节（bytes=-N）解析异常';
            }
            $r = mf_media_range('bytes=0-99999', 1000);
            if ($r['status'] !== 206 || $r['end'] !== 999) {
                $problems[] = 'Range 越长截断异常（end 应钳到 size-1）';
            }
            $r = mf_media_range('bytes=1000-', 1000);
            if ($r['status'] !== 416) {
                $problems[] = 'Range 起点越界未返回 416';
            }
            $r = mf_media_range('', 1000);
            if ($r['status'] !== 200) {
                $problems[] = '无 Range 头应整文件 200 出站';
            }
            $doneSteps[] = 'Range 边界';
            if (mf_media_throttle_bps(0, null) !== 0) {
                $problems[] = '带宽 0 应为不限速';
            }
            if (mf_media_throttle_bps(2, null) !== 250000) {
                $problems[] = '2 Mbps 应换算为 250,000 B/s（1 Mbps = 125,000 B/s，兆比特 ≠ 兆字节）';
            }
            if (mf_media_throttle_bps(8, 2.0) !== 250000) {
                $problems[] = '多线程份额 th= 应把每连接钳到份额内（总带宽 ÷ 线程数双保险）';
            }
            if (mf_media_throttle_bps(0, 3.5) !== 437500) {
                $problems[] = '不限带宽时 th= 份额应生效';
            }
            if (mf_media_throttle_bps(2, 5.0) !== 250000) {
                $problems[] = '份额超过总上限时应仍按配置上限钳制';
            }
            $doneSteps[] = '带宽换算';
            $sub = date('Ym');
            $dirU = DATA_DIR . '/uploads/' . $sub;
            if (!is_dir($dirU) && !@mkdir($dirU, 0755, true)) {
                $problems[] = 'uploads 目录创建失败（附件上传功能将不可用）';
            } else {
                $hex = bin2hex(random_bytes(8));
                $pp = $dirU . '/' . $hex . '.txt';
                @file_put_contents($pp, 'selfcheck-media-probe-' . bin2hex(random_bytes(3)));
                $probeName = '自检探针附件-' . bin2hex(random_bytes(3)) . '.txt';
                mf_upload_meta_write($pp, $probeName, 26, 0);
                $pm = mf_upload_meta_read($pp);
                if (!is_array($pm) || (string)($pm['n'] ?? '') !== $probeName) {
                    $problems[] = 'meta 边车写读不一致（下载时无法还原中文等真实文件名）';
                }
                if (mf_upload_meta_read($pp . '.nonexistent') !== []) {
                    $problems[] = 'meta 边车缺失时应静默降级（返回空数组），而不是报错';
                }
                @unlink($pp);
                @unlink($pp . '.meta.json');
                $doneSteps[] = 'meta 边车（中文文件名还原）';
            }
            if ($problems) {
                return ['key' => 'media', 'name' => '附件出站与限速下载', 'state' => 'fail', 'detail' => implode('；', $problems) . '。已验证：' . implode(' → ', $doneSteps)];
            }
            return ['key' => 'media', 'name' => '附件出站与限速下载', 'state' => 'pass', 'detail' => '附件出站链路全部有效（' . implode(' → ', $doneSteps) . '）：点击即下载 / 预览、多线程分段、按 Mbps 限速、真实文件名还原均正常'];

        case 'manifest':
            /* 8. 程序文件完整性（manifest sha256 校验）——专抓「升级不完整 / 文件被改 / 版本混合」 */
            $mf = dirname(DATA_DIR) . '/manifest.txt';
            if (!is_file($mf)) {
                return ['key' => 'manifest', 'name' => '程序文件完整性', 'state' => 'skip', 'detail' => '未找到 manifest.txt（v1.43.0 起随包附带）——升级到 v1.44.0+ 后此项可逐文件校验官方 sha256'];
            }
            $miss = [];
            $diff = [];
            $n = 0;
            foreach (file($mf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ln) {
                $ln = trim((string)$ln);
                if ($ln === '' || $ln[0] === '#') {
                    continue;
                }
                $p = preg_split('/\s+/', $ln, 2);
                if (count($p) !== 2) {
                    continue;
                }
                [$sha, $rel] = $p;
                $rel = trim($rel);
                if ($rel === '' || strpos($rel, '..') !== false) {
                    continue;
                }
                $n++;
                $abs = dirname(DATA_DIR) . '/' . $rel;
                if (!is_file($abs)) {
                    if (count($miss) < 5) {
                        $miss[] = $rel;
                    }
                } elseif ((hash_file('sha256', $abs) ?: '') !== $sha) {
                    if (count($diff) < 5) {
                        $diff[] = $rel;
                    }
                }
            }
            if ($miss) {
                return ['key' => 'manifest', 'name' => '程序文件完整性', 'state' => 'fail', 'detail' => '缺失 ' . count($miss) . '+ 个官方程序文件（如 ' . implode('、', $miss) . '）——升级包未传全或被误删，功能随机报错（如 Call to undefined function）多半源于此。请重新上传官方更新包（后台「更新升级」）或完整包覆盖'];
            }
            if ($diff) {
                return ['key' => 'manifest', 'name' => '程序文件完整性', 'state' => 'warn', 'detail' => '以下文件与官方包 sha256 不一致：' . implode('、', $diff) . '。若是您自行改过属正常；否则可能是版本混合/传输损坏，建议重新上传官方更新包覆盖'];
            }
            /* v1.48.1：程序文件版本 vs 数据版本一致性——专抓「FTP 覆盖了文件但没装更新包」。
               此状态下资源缓存键（?v=）不前进，浏览器会继续用旧 app.js/style.css（immutable 一年缓存），
               前台随机出现「上传不了 / 点击交互失效」等新功能失灵；迁移脚本也没跑过。 */
            if ((string)MF_VERSION !== (string)app_version()) {
                return ['key' => 'manifest', 'name' => '程序文件完整性', 'state' => 'warn', 'detail' => '文件全部完好，但版本不一致：程序文件已为 v' . MF_VERSION . '，数据版本仍为 v' . app_version() . '（多半是 FTP 覆盖升级 / 搬站后未装更新包）。旧浏览器缓存会继续用旧 app.js 导致「上传不了 / 新功能失灵」——请到后台「更新升级」上传 forum-update-any-to-' . MF_VERSION . '.zip 完成安装，或让每位用户强制刷新（Ctrl+F5）'];
            }
            return ['key' => 'manifest', 'name' => '程序文件完整性', 'state' => 'pass', 'detail' => 'manifest.txt 全部 ' . $n . ' 个程序文件 sha256 与官方包逐一相符'];

        case 'search':
            /* 9. 搜索（内部真实跑一次检索与联想） */
            if (!feat_on('search')) {
                return ['key' => 'search', 'name' => '站内搜索', 'state' => 'skip', 'detail' => '「站内搜索」功能开关当前为关（后台「功能开关」可开启）——若前台找不到搜索栏，多半是这个开关关了'];
            }
            try {
                $hits = search_threads('论坛');
                $sug = feat_on('search_suggest') ? search_suggest_items('论') : [];
                return ['key' => 'search', 'name' => '站内搜索', 'state' => 'pass', 'detail' => '检索引擎跑通：示例关键词命中 ' . count($hits) . ' 帖' . (feat_on('search_suggest') ? '，输入联想返回 ' . count($sug) . ' 条' : '') . '（索引 ' . count(thread_index()) . ' 帖）。若某关键词搜不到：搜索范围是最近 500 帖，更老的帖子不在索引窗口内'];
            } catch (Throwable $e) {
                return ['key' => 'search', 'name' => '站内搜索', 'state' => 'fail', 'detail' => '搜索执行报错：' . cut_str($e->getMessage(), 160) . '。可尝试「一键修复 → 重建帖子索引」', 'fixkey' => 'rebuild_index', 'fixable' => true];
            }

        case 'queue':
            /* 10. AI 队列 / 自主管理 */
            $q = Store::read('queue.php', []);
            $qn = is_array($q) ? count($q) : -1;
            if ($qn < 0) {
                return ['key' => 'queue', 'name' => 'AI 审核', 'state' => 'warn', 'detail' => 'AI 审核队列文件不可解析（data/queue.php 损坏）。审核将按「无法审核」路径处理；可到 data/backup/ 找备份恢复'];
            }
            if ($qn > 0) {
                return ['key' => 'queue', 'name' => 'AI 审核队列', 'state' => 'warn', 'detail' => '队列中还有 ' . $qn . ' 条待 AI 审核。若长期不消化：检查「AI 模型」是否可用（本页勾选远程项可实测）、AI 自主管理是否开启（当前：' . (!empty(cfg('ai_autopilot', 0)) ? '开' : '关') . '）。队列也会在被访问时逐条触发消化，不会永久卡死'];
            }
            return ['key' => 'queue', 'name' => 'AI 审核队列', 'state' => 'pass', 'detail' => '队列为空；AI 自主管理' . (!empty(cfg('ai_autopilot', 0)) ? '已开启（守护' . (ai_daemon_alive() ? '存活' : '未随请求运行，访问时会自动拉起') . '）' : '未开启（举报内容进队列后等待 AI 或人工处理）')];

        case 'snapshot':
            /* 11. 静态快照 */
            if (mfs_enabled()) {
                $st = mfs_stats();
                return ['key' => 'snapshot', 'name' => '静态快照', 'state' => 'pass', 'detail' => '快照开启，缓存 ' . (int)($st[0] ?? 0) . ' 页。若升级后游客仍看到旧版页脚/旧内容，可「一键修复 → 清除快照」强制刷新', 'fixkey' => 'clear_snapshot', 'fixable' => (int)($st[0] ?? 0) > 0];
            }
            return ['key' => 'snapshot', 'name' => '静态快照', 'state' => 'skip', 'detail' => '未开启（纯动态直出，无缓存一致性问题）'];

        case 'temp':
            /* 12. 临时文件与陈旧锁 */
            $stale = 0;
            foreach (glob(Store::path('locks/*')) ?: [] as $lf) {
                if (@filemtime((string)$lf) !== false && (time() - (int)@filemtime((string)$lf)) > 3600) {
                    $stale++;
                }
            }
            return ['key' => 'temp', 'name' => '临时文件与锁', 'state' => $stale > 20 ? 'warn' : 'pass', 'detail' => $stale > 20 ? '发现 ' . $stale . ' 个超过 1 小时的陈旧锁文件（通常为进程中断残留，可能拖慢写入）。可「一键修复 → 清理」' : '锁目录健康' . ($stale ? "（{$stale} 个近期锁，属正常）" : ''), 'fixkey' => $stale > 20 ? 'clean_temp' : '', 'fixable' => $stale > 20];

        case 'mail':
            /* 13. SMTP 邮箱全链路（远程项：真实发信到管理员注册邮箱） */
            if (!$remote) {
                $cfgOk = trim((string)cfg('smtp_host', '')) !== '' && trim((string)cfg('smtp_from', '')) !== '';
                return ['key' => 'mail', 'name' => 'SMTP 邮箱（远程项）', 'state' => 'skip', 'detail' => $cfgOk ? '本次未勾选远程项，未实际发信。SMTP 已配置（' . cfg('smtp_host') . '）' : 'SMTP 未配置（后台「邮件设置」填写主机 / 发信邮箱 / 授权码后可用）'];
            }
            $host = trim((string)cfg('smtp_host', ''));
            $from = trim((string)cfg('smtp_from', ''));
            if ($host === '' || $from === '') {
                return ['key' => 'mail', 'name' => 'SMTP 邮箱全链路', 'state' => 'skip', 'detail' => 'SMTP 未配置——注册验证码 / 找回密码 / 告警邮件不可用。后台「邮件设置」配置后可再测'];
            }
            $me = current_user();
            $to = is_array($me) ? (string)($me['email'] ?? '') : '';
            if (!valid_email($to)) {
                foreach (user_all() as $au) {
                    if (!empty($au['admin']) && valid_email((string)($au['email'] ?? ''))) {
                        $to = (string)$au['email'];
                        break;
                    }
                }
            }
            if (!valid_email($to)) {
                return ['key' => 'mail', 'name' => 'SMTP 邮箱全链路', 'state' => 'warn', 'detail' => '已配置 SMTP，但找不到管理员的有效注册邮箱——请在后台「用户」里给管理员账号补一个真实邮箱'];
            }
            $inner = '<p style="margin:0 0 12px">这是一封 <b>功能一键自检 · SMTP 全链路测试邮件</b>。</p>'
                . '<div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:10px;padding:12px 14px;font-size:13px;line-height:1.7;color:#065f46">收到即代表：邮件模板渲染 → SMTP 连接（含 DNS 四层回退）→ 认证 → 发信 全链路正常。注册验证码 / 通知 / 告警等系统邮件均可正常送达。</div>';
            $t0 = microtime(true);
            [$ok, $err] = mail_send($to, (string)cfg('site_name', '论坛') . ' · 功能自检测试邮件', mail_template('功能自检 · SMTP 测试', $inner));
            $ms = (int)round((microtime(true) - $t0) * 1000);
            if ($ok) {
                return ['key' => 'mail', 'name' => 'SMTP 邮箱全链路', 'state' => 'pass', 'detail' => '测试邮件已真实发往管理员邮箱 ' . $to . '（耗时 ' . $ms . ' ms），请查收（含美化版式，垃圾箱也看一眼）'];
            }
            return ['key' => 'mail', 'name' => 'SMTP 邮箱全链路', 'state' => 'fail', 'detail' => '发信失败（' . $ms . ' ms）：' . $err . ' —— 报错含 getaddrinfo / 解析等字样时，属服务器 DNS 故障，v1.43.0+ 会自动四层回退；仍失败可在后台「邮件设置 → 直连 IP」填 SMTP 服务器 IP 兜底，或运行 tools/smtp-doctor.php 体检'];

        case 'ai':
            /* 14. AI 审核真实调用（远程项） */
            if (!$remote) {
                return ['key' => 'ai', 'name' => 'AI 审核（远程项）', 'state' => 'skip', 'detail' => ai_ready() ? '本次未勾选远程项，未实际调用 AI' : 'AI 模型未配置 / 未启用（后台「AI 审核」添加模型并启用后可用）'];
            }
            if (!ai_ready()) {
                return ['key' => 'ai', 'name' => 'AI 审核真实调用', 'state' => 'skip', 'detail' => 'AI 模型未配置 / 未启用——举报内容将只走人工复核。后台「AI 审核」添加并启用模型后可再测'];
            }
            $verdict = '';
            $note = '';
            $used = '';
            $t0 = microtime(true);
            $ok = false;
            $exm = '';
            try {
                $ok = ai_moderate('今天天气很好，山间茶馆的茉莉花茶很香，大家有空来喝茶聊天。', $verdict, $note, [], $used);
            } catch (Throwable $e) {
                $exm = $e->getMessage();
            }
            $ms = (int)round((microtime(true) - $t0) * 1000);
            if ($ok && $verdict === 'ok') {
                return ['key' => 'ai', 'name' => 'AI 审核真实调用', 'state' => 'pass', 'detail' => 'AI 链路正常（' . $ms . ' ms）：无害测试内容被判「通过」，判定模型 ' . $used . ($note !== '' ? '；理由：' . cut_str($note, 80) : '')];
            }
            if ($ok && $verdict === 'violation') {
                return ['key' => 'ai', 'name' => 'AI 审核真实调用', 'state' => 'warn', 'detail' => 'AI 链路通（' . $ms . ' ms），但把完全无害的测试内容判为「违规」' . ($note !== '' ? '，理由：' . cut_str($note, 120) : '') . '——审核严格程度可能过高（后台「AI 审核 → 审核严格程度」调低）或模型不稳定，建议换模型对比'];
            }
            return ['key' => 'ai', 'name' => 'AI 审核真实调用', 'state' => 'fail', 'detail' => 'AI 调用失败（' . $ms . ' ms）：' . ($exm !== '' ? $exm : ($note !== '' ? $note : '原因未知')) . ($used !== '' ? '（' . $used . '）' : '') . ' —— 常见：密钥失效 / 余额不足 / 接口地址写错 / 超时；逐条修改后在「AI 审核」标签页用单模型测试按钮定位'];
    }

    /* 未知 key 兜底（不应发生） */
    return ['key' => $key, 'name' => $key, 'state' => 'skip', 'detail' => '未知自检项'];
}

/**
 * 跑全部自检项（整跑接口：CLI / 旧调用方保持不变；HTTP 前端 v1.45.0 起改用逐项 selfcheck_run_item）
 * @param bool $remote 是否包含远程真实测试（SMTP 真发信到管理员邮箱 / AI 真调用一次审核）
 * @return array items[]: ['key','name','state'(pass|warn|fail|skip),'detail','fixable','fixkey','fixhint']
 */
function selfcheck_run(bool $remote): array
{
    @set_time_limit($remote ? 180 : 60);
    $it = [];
    foreach (selfcheck_item_keys() as $k) {
        $it[] = selfcheck_run_item($k, $remote);
    }

    /* 报告自动落盘（引擎层统一写入，CLI 与 HTTP 调用行为一致） */
    $sum = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'skip' => 0];
    foreach ($it as $x) {
        $st = (string)($x['state'] ?? 'warn');
        $sum[$st] = ($sum[$st] ?? 0) + 1;
    }
    selfcheck_last_write(['ts' => time(), 'remote' => $remote, 'items' => $it, 'sum' => $sum]);

    return $it;
}

/**
 * 执行自动修复（按 keys；保守修复：先备份，绝不自动删数据）
 * @param array $keys 允许执行的修复项
 * @return array log[]: 每项 ['name','ok','msg']
 */
function selffix_run(array $keys): array
{
    @set_time_limit(120);
    $log = [];

    /* 目录权限修复（读写失败时） */
    if (in_array('repair_dirs', $keys, true)) {
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
        $log[] = ['name' => '修复目录权限', 'ok' => !$failed, 'msg' => $failed
            ? $fixed . ' 个目录已恢复可写，仍不可写：' . implode('、', $failed) . '（属主不属于 PHP 运行账号，需 FTP/面板改权限 755/775）'
            : '全部 ' . $fixed . ' 个目录检查/修复为可写'];
        $broken = Store::cleanupBrokenDirs();
        if ($broken) {
            $log[] = ['name' => '清理 .broken-* 暂存', 'ok' => true, 'msg' => count($broken) . ' 个暂存目录待 FTP 确认后删除（数据已尝试搬回）'];
        }
    }

    /* 重建帖子索引 */
    if (in_array('rebuild_index', $keys, true)) {
        $lk = Store::lock('threads');
        $idx = [];
        $bad = 0;
        foreach (glob(Store::path('threads/t*.php')) ?: [] as $tf) {
            if (!preg_match('/t(\d+)\.php$/', (string)$tf, $mm)) {
                continue;
            }
            $id = (int)$mm[1];
            $t = thread_get($id);
            if (!$t) {
                $bad++;
                continue;
            }
            $row = ['id' => $id];
            foreach (INDEX_KEYS as $k) {
                if (array_key_exists($k, $t)) {
                    $row[$k] = $t[$k];
                }
            }
            $idx[] = $row;
        }
        usort($idx, fn ($a, $b) => (int)($b['created'] ?? 0) <=> (int)($a['created'] ?? 0));
        $okW = Store::write('threads/index.php', $idx);
        Store::unlock($lk);
        $log[] = ['name' => '重建帖子索引', 'ok' => (bool)$okW, 'msg' => $okW
            ? '已按 threads/ 目录实际文件重建索引：' . count($idx) . ' 条' . ($bad ? "（另有 {$bad} 个损坏文件未纳入，原样保留）" : '')
            : '索引写入失败：data/threads/ 可能不可写，请先修目录权限'];
    }

    /* 清除静态快照 */
    if (in_array('clear_snapshot', $keys, true)) {
        $n = mfs_clear_all();
        $log[] = ['name' => '清除静态快照', 'ok' => true, 'msg' => $n ? "已清除 {$n} 页快照，游客下次访问将重新按当前版本生成" : '没有需要清除的快照'];
    }

    /* 清理陈旧锁与自检探针残留 */
    if (in_array('clean_temp', $keys, true)) {
        $n = 0;
        foreach (glob(Store::path('locks/*')) ?: [] as $lf) {
            if (@filemtime((string)$lf) !== false && (time() - (int)@filemtime((string)$lf)) > 3600 && @unlink((string)$lf)) {
                $n++;
            }
        }
        foreach (glob(Store::path('selfcheck-probe-*.tmp')) ?: [] as $pf) {
            if (@unlink((string)$pf)) {
                $n++;
            }
        }
        $log[] = ['name' => '清理陈旧锁与探针残留', 'ok' => true, 'msg' => "已清理 {$n} 个文件"];
    }

    /* 损坏 JSON：备份（不自动改写数据，由管理员决定恢复方式） */
    if (in_array('backup_damaged', $keys, true)) {
        $n = 0;
        foreach (['users.php', 'boards.php', 'config.php', 'announcements.php', 'queue.php'] as $cf) {
            $p = Store::path($cf);
            if (is_file($p) && !is_array(Store::read($cf, null))) {
                $bdir = Store::path('backup');
                if (!is_dir($bdir)) {
                    @mkdir($bdir, 0775, true);
                }
                $dst = $bdir . '/damaged-' . $cf . '-' . date('Ymd-His');
                if (@copy($p, $dst)) {
                    $n++;
                }
            }
        }
        $log[] = ['name' => '备份损坏数据文件', 'ok' => true, 'msg' => $n ? "已将 {$n} 个损坏文件备份到 data/backup/damaged-*，请比对手工恢复" : '未发现需要备份的损坏文件'];
    }

    /* v1.46.0 清理自检探针残留（E2E 中断遗留的探针账号 / 探针帖；三重标记兜底定位） */
    if (in_array('clean_probes', $keys, true)) {
        $pids = [];
        foreach (user_all() as $u) {
            if (strpos((string)($u['bio'] ?? ''), 'selfcheck-probe') !== false
                || strpos((string)($u['name'] ?? ''), 'sc_e2e_') === 0) {
                $pids[] = (int)($u['id'] ?? 0);
            }
        }
        $tn = 0;
        foreach (thread_index() as $x) {
            $tid = (int)($x['id'] ?? 0);
            if ($tid <= 0) {
                continue;
            }
            $bad = strpos((string)($x['title'] ?? ''), '【自检探针帖】') === 0
                || in_array((int)($x['author'] ?? 0), $pids, true);
            if ($bad && thread_delete($tid)) {
                $tn++;
            }
        }
        foreach ($pids as $pid) {
            selfcheck_probe_remove($pid);
        }
        $log[] = ['name' => '清理自检探针残留', 'ok' => true, 'msg' => ($pids || $tn)
            ? '已删除 ' . $tn . ' 个探针帖与 ' . count($pids) . ' 个探针账号（上次自检中断的残留）'
            : '未发现探针残留（一切干净）'];
    }

    return $log;
}
