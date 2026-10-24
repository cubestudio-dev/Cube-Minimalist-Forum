<?php
/**
 * 极简论坛 · 功能一键自检 + 自动修复（v1.44.0 引擎 / v1.45.0 实时进度升级）
 *
 * 把「后台一个个点测试、一条条敲命令排查」变成一次跑完：
 *   环境基线 / 读写 / 存储完整性与帖子索引 / 会话 / 程序文件完整性（manifest 校验）/
 *   搜索 / AI 队列 / 静态快照；勾选远程项还会用管理员邮箱真实发一封测试邮件、
 *   真实调用一次 AI 审核（较慢）。
 * 发现的问题逐项给出结论与修复办法；可自动修复项支持一键修复（保守修复：先备份，绝不自动删数据）。
 *
 * v1.45.0：拆分为「目录（selfcheck_catalog）+ 逐项执行（selfcheck_run_item）」——
 *   前端逐项请求后端，检查进度 / 修复进度 / 清单 / 日志全程实时可视；
 *   selfcheck_run() 整跑接口保持不变（CLI / 旧调用方零改动），逐项失败不再拖垮整场。
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

        case 'manifest':
            /* 5. 程序文件完整性（manifest sha256 校验）——专抓「升级不完整 / 文件被改 / 版本混合」 */
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
            return ['key' => 'manifest', 'name' => '程序文件完整性', 'state' => 'pass', 'detail' => 'manifest.txt 全部 ' . $n . ' 个程序文件 sha256 与官方包逐一相符'];

        case 'search':
            /* 6. 搜索（内部真实跑一次检索与联想） */
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
            /* 7. AI 队列 / 自主管理 */
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
            /* 8. 静态快照 */
            if (mfs_enabled()) {
                $st = mfs_stats();
                return ['key' => 'snapshot', 'name' => '静态快照', 'state' => 'pass', 'detail' => '快照开启，缓存 ' . (int)($st[0] ?? 0) . ' 页。若升级后游客仍看到旧版页脚/旧内容，可「一键修复 → 清除快照」强制刷新', 'fixkey' => 'clear_snapshot', 'fixable' => (int)($st[0] ?? 0) > 0];
            }
            return ['key' => 'snapshot', 'name' => '静态快照', 'state' => 'skip', 'detail' => '未开启（纯动态直出，无缓存一致性问题）'];

        case 'temp':
            /* 9. 临时文件与陈旧锁 */
            $stale = 0;
            foreach (glob(Store::path('locks/*')) ?: [] as $lf) {
                if (@filemtime((string)$lf) !== false && (time() - (int)@filemtime((string)$lf)) > 3600) {
                    $stale++;
                }
            }
            return ['key' => 'temp', 'name' => '临时文件与锁', 'state' => $stale > 20 ? 'warn' : 'pass', 'detail' => $stale > 20 ? '发现 ' . $stale . ' 个超过 1 小时的陈旧锁文件（通常为进程中断残留，可能拖慢写入）。可「一键修复 → 清理」' : '锁目录健康' . ($stale ? "（{$stale} 个近期锁，属正常）" : ''), 'fixkey' => $stale > 20 ? 'clean_temp' : '', 'fixable' => $stale > 20];

        case 'mail':
            /* 11. SMTP 邮箱全链路（远程项：真实发信到管理员注册邮箱） */
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
            /* 12. AI 审核真实调用（远程项） */
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

    return $log;
}
