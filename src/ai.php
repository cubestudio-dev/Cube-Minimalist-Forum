<?php
/**
 * 极简论坛 · AI 内容审核客户端（OpenAI 兼容接口）
 * 零依赖：优先 curl，无 curl 时回退 stream context
 *
 * 多模型矩阵：
 *  - 后台可自由添加多个 API 密钥与模型（data/ai_models.php，备注 / 地址 / 密钥 / 模型名 / 启停 / 顺序）
 *  - 自动切换：某模型连续失败达到阈值（ai_fail_limit，默认 3）后，后续审核自动换下一个模型；
 *    所有模型都失败才返回失败（由调用方转入人工审核）；审核成功自动清零计数并恢复为主力
 *  - 全火力全开（ai_fullpower）：每条内容同时交给所有启用的模型审核，
 *    任一模型判定违规即违规、全部无问题才放行；任一模型成功返回即视为审核有效
 */
defined('APP') or exit('Forbidden');

/**
 * 固定审核提示词（程序内置，后台不可编辑，保证审核口径稳定可预期）。
 * 管理员仅可通过后台「AI → 审核严格程度」选择注入哪一段判定尺度（见 AI_STRICT_LEVELS）。
 */
const AI_SYSTEM_PROMPT = '你是论坛内容安全审核助手。请判断给定内容是否违规。违规包括：违法信息、色情低俗、人身攻击辱骂、暴力恐怖、赌博诈骗、垃圾广告、恶意灌水、泄露他人隐私。只输出一个 JSON 对象：{"violation": true, "reason": "不超过30字的理由"}，其中 violation 为 true 或 false，不要输出任何其他文字。';

/**
 * 审核严格程度 → 注入提示词的判定尺度（三档，后台可选；默认 standard）
 * 提示词主体固定，这里只改变「拿不准时怎么判」的尺度。
 */
const AI_STRICT_LEVELS = [
    'loose'    => '判定尺度：宽松。仅在内容明显、直接违反上述类别时才判违规；擦边表达、影射、情绪化吐槽、争议观点、营销软文嫌疑，只要未直接命中违规类别，一律判为不违规。',
    'standard' => '判定尺度：标准。内容直接命中上述任一类别才判违规；拿不准时判为不违规。',
    'strict'   => '判定尺度：严格。从严把关：软色情擦边、侮辱谩骂的变体字/谐音字/拼音缩写、变体引流导流、规避审查的拆字错字，也判为违规；只有明显健康无害的内容才判为不违规。',
];

/** 规范化严格程度值（非法值一律回退 standard） */
function ai_strict_norm(string $v): string
{
    return isset(AI_STRICT_LEVELS[$v]) ? $v : 'standard';
}

/** 当前生效的完整系统提示词 = 固定主体 + 严格程度尺度（$level 为空时读后台配置） */
function ai_system_prompt(?string $level = null): string
{
    $lv = ai_strict_norm((string)($level ?? cfg('ai_strict', 'standard')));
    return AI_SYSTEM_PROMPT . "\n" . AI_STRICT_LEVELS[$lv];
}

/* ================= 模型列表存储 ================= */

/** 规范化单个模型行 */
function ai_model_norm(array $m): array
{
    return [
        'id'    => (int)($m['id'] ?? 0),
        'name'  => cut_str(trim((string)($m['name'] ?? '')), 30),
        'url'   => cut_str(trim((string)($m['url'] ?? '')), 200),
        'key'   => cut_str(trim((string)($m['key'] ?? '')), 200),
        'model' => cut_str(trim((string)($m['model'] ?? '')), 100),
        'on'    => !empty($m['on']),
    ];
}

/** 模型行是否配置完整（地址 / 密钥 / 模型名齐全） */
function ai_model_ok(array $m): bool
{
    return $m['url'] !== '' && $m['key'] !== '' && $m['model'] !== '';
}

/** 模型显示名：备注为空时回落到模型名 */
function ai_model_label(array $m): string
{
    return $m['name'] !== '' ? $m['name'] : $m['model'];
}

/**
 * 读取模型列表。
 * 旧版单模型配置（config 中的 ai_url / ai_key / ai_model）为空列表时自动迁移为第一条并持久化——
 * 覆盖老版本升级、更新包未跑迁移脚本、全新安装三种场景，幂等安全。
 */
function ai_models_all(): array
{
    $list = Store::read('ai_models.php', []);
    if (is_array($list) && $list) {
        return array_values(array_map('ai_model_norm', $list));
    }
    $url = trim((string)cfg('ai_url', ''));
    $key = (string)cfg('ai_key', '');
    $model = trim((string)cfg('ai_model', ''));
    if ($url === '' || $key === '' || $model === '') {
        return [];
    }
    $list = [ai_model_norm(['id' => 1, 'name' => '', 'url' => $url, 'key' => $key, 'model' => $model, 'on' => true])];
    if (!Store::write('ai_models.php', $list)) {
        // 持久化失败（目录临时不可写等）：本次请求以内存版运行，下次访问重试
        error_log('[AI] 旧版单模型自动迁移写入 ai_models.php 失败');
    }
    return $list;
}

/** 保存整张模型列表 */
function ai_models_save(array $list): bool
{
    return Store::write('ai_models.php', array_values(array_map('ai_model_norm', $list)));
}

/** 启用且配置完整的模型（保持列表顺序） */
function ai_models_enabled(): array
{
    $out = [];
    foreach (ai_models_all() as $m) {
        if ($m['on'] && ai_model_ok($m)) {
            $out[] = $m;
        }
    }
    return $out;
}

/** 是否至少有一个可用模型（队列触发 / 前台提示用） */
function ai_ready(): bool
{
    return ai_models_enabled() !== [];
}

/** 自动切换阈值：连续失败达到该次数即把主力切到下一个模型（后台可调 1-20，默认 3） */
function ai_fail_limit(): int
{
    return max(1, min(20, (int)cfg('ai_fail_limit', 3)));
}

/* ================= 审核状态（失败计数 / 主力指针） ================= */

/** 读取 ai_state.php：last=上次审核时间，active=主力模型 id，fail={模型id: 连续失败次数} */
function ai_state_read(): array
{
    $s = Store::read('ai_state.php', []);
    if (!is_array($s)) {
        $s = [];
    }
    return [
        'last'   => (int)($s['last'] ?? 0),
        'active' => (int)($s['active'] ?? 0),
        'fail'   => is_array($s['fail'] ?? null) ? array_map('intval', $s['fail']) : [],
    ];
}

/** 写回审核状态（原子写） */
function ai_state_write(array $st): void
{
    Store::write('ai_state.php', [
        'last'   => (int)($st['last'] ?? 0),
        'active' => (int)($st['active'] ?? 0),
        'fail'   => is_array($st['fail'] ?? null) ? array_map('intval', $st['fail']) : [],
    ]);
}

/** 失败计数 +1；恰好达到阈值时写一条「自动切换」系统日志（$next 为空表示已无其他可用模型） */
function ai_fail_bump(array &$fail, int $id, string $label, int $limit, string $next = ''): void
{
    $prev = (int)($fail[$id] ?? 0);
    $fail[$id] = $prev + 1;
    if ($prev + 1 === $limit) {
        log_action('ai_model_switch', '模型「' . $label . '」连续失败 ' . $limit . ' 次，后续审核自动切换到下一模型'
            . ($next !== '' ? '「' . $next . '」' : '（当前无其他可用模型，全部失败将转人工处理）'));
    }
}

/* ================= HTTP 层 ================= */

/** 规范化 API 地址：兼容填 域名 / 域名/v1 / 完整 chat/completions 三种写法 */
function ai_endpoint(string $url): string
{
    $u = rtrim(trim($url), '/');
    if (substr($u, -17) === '/chat/completions') {
        return $u;
    }
    if (preg_match('/\/v\d+[a-z]*$/', $u)) {
        return $u . '/chat/completions';
    }
    return $u . '/v1/chat/completions';
}

function http_post_json(string $url, array $payload, array $headers, int $timeout, string &$err = ''): ?string
{
    /* 协议白名单：仅允许 http(s)，杜绝 file:// 等协议被 curl 支持带来的意外读取（与防火墙 fw_http_* 同标准） */
    if (!preg_match('#^https?://#i', trim($url))) {
        $err = 'API 地址必须以 http:// 或 https:// 开头';
        return null;
    }
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        $err = '请求编码失败';
        return null;
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
        ]);
        $res = curl_exec($ch);
        if ($res === false) {
            $err = 'curl: ' . curl_error($ch);
            curl_close($ch);
            return null;
        }
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code < 200 || $code >= 300) {
            $err = "HTTP {$code}：" . cut_str(strip_tags((string)$res), 160);
            return null;
        }
        return (string)$res;
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", array_merge(['Content-Type: application/json'], $headers)),
        'content' => $body,
        'timeout' => $timeout,
        'ignore_errors' => true,
    ]]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) {
        $err = 'HTTP 请求失败（缺少 curl 且 file_get_contents 不可用）';
        return null;
    }
    return $res;
}

/* ================= 单模型调用核心 ================= */

/**
 * 调用单个模型审核一段内容（含内部重试）
 * @param array  $m       模型行（url / key / model 必填）
 * @param string $verdict 输出 'violation' | 'ok'
 * @param string $note    输出理由（成功）或错误信息（失败）
 * @param array  $ov      可覆盖：retries（默认读 ai_retries 配置）、strict（默认读 ai_strict 配置）
 * @return bool 是否得到了有效结论
 */
function ai_call_model(array $m, string $content, string &$verdict, string &$note, array $ov = []): bool
{
    $verdict = '';
    $note = '';
    $retries = max(1, (int)($ov['retries'] ?? cfg('ai_retries', 3)));
    $strict = ($ov['strict'] ?? null) === null ? null : (string)$ov['strict'];

    $payload = [
        'model' => $m['model'],
        'temperature' => 0,
        'stream' => false,
        'max_tokens' => 300,
        'messages' => [
            ['role' => 'system', 'content' => ai_system_prompt($strict)],
            ['role' => 'user', 'content' => "待审核内容：\n" . cut_str($content, 1200)],
        ],
    ];
    $err = '';
    for ($i = 1; $i <= $retries; $i++) {
        $res = http_post_json(ai_endpoint($m['url']), $payload, ['Authorization: Bearer ' . $m['key']], 30, $err);
        if ($res !== null) {
            $j = json_decode($res, true);
            $text = is_array($j) ? ($j['choices'][0]['message']['content'] ?? '') : '';
            if (is_string($text) && $text !== '') {
                $mm = [];
                if (preg_match('/\{[^{}]*\}/s', $text, $mm)) {
                    $v = json_decode($mm[0], true);
                    if (is_array($v) && array_key_exists('violation', $v)) {
                        $verdict = !empty($v['violation']) ? 'violation' : 'ok';
                        $note = trim((string)($v['reason'] ?? ''));
                        return true;
                    }
                }
                $t = strtolower(preg_replace('/\s+/', '', $text));
                if (strpos($t, '"violation":true') !== false) {
                    $verdict = 'violation';
                    $note = cut_str($text, 60);
                    return true;
                }
                if (strpos($t, '"violation":false') !== false) {
                    $verdict = 'ok';
                    $note = cut_str($text, 60);
                    return true;
                }
                $err = 'AI 返回格式无法解析：' . cut_str($text, 120);
            } else {
                $msg = is_array($j) ? (string)($j['error']['message'] ?? json_encode($j, JSON_UNESCAPED_UNICODE)) : '响应非 JSON';
                $err = '响应异常：' . cut_str($msg, 160);
            }
        }
        if ($i < $retries) {
            sleep(1);
        }
    }
    $note = $err !== '' ? $err : 'AI 调用失败';
    return false;
}

/* ================= 并行火力（v1.16.0） ================= */

/**
 * 审核模式：
 *  - normal    标准：按模型顺序调用，失败自动切换（每条内容一个模型在审）
 *  - fullpower 全火力：所有启用模型同时审同一条内容（严判，任一违规即违规）
 *  - parallel  并行火力：队列一次取出 K 条（K = 启用模型数），K 个模型并行各审一条（吞吐 ×K）
 */
function ai_mode(): string
{
    $m = (string)cfg('ai_mode', '');
    if (!in_array($m, ['normal', 'fullpower', 'parallel'], true)) {
        /* 旧版本兼容：老开关 ai_fullpower=1 视为全火力 */
        $m = !empty(cfg('ai_fullpower', 0)) ? 'fullpower' : 'normal';
    }
    return $m;
}

/** 解析单次 AI 响应文本（与 ai_call_model 内联解析同一套规则，供并行批量调用复用） */
function ai_parse_reply(string $res, string &$verdict, string &$note): bool
{
    $verdict = '';
    $note = '';
    $j = json_decode($res, true);
    $text = is_array($j) ? ($j['choices'][0]['message']['content'] ?? '') : '';
    if (is_string($text) && $text !== '') {
        $mm = [];
        if (preg_match('/\{[^{}]*\}/s', $text, $mm)) {
            $v = json_decode($mm[0], true);
            if (is_array($v) && array_key_exists('violation', $v)) {
                $verdict = !empty($v['violation']) ? 'violation' : 'ok';
                $note = trim((string)($v['reason'] ?? ''));
                return true;
            }
        }
        $t = strtolower(preg_replace('/\s+/', '', $text));
        if (strpos($t, '"violation":true') !== false) {
            $verdict = 'violation';
            $note = cut_str($text, 60);
            return true;
        }
        if (strpos($t, '"violation":false') !== false) {
            $verdict = 'ok';
            $note = cut_str($text, 60);
            return true;
        }
        $note = 'AI 返回格式无法解析：' . cut_str($text, 120);
        return false;
    }
    $msg = is_array($j) ? (string)($j['error']['message'] ?? json_encode($j, JSON_UNESCAPED_UNICODE)) : '响应非 JSON';
    $note = '响应异常：' . cut_str($msg, 160);
    return false;
}

/**
 * 并行批量调用（curl_multi）：每个任务 = 一个模型审一条不同内容，真正的同刻并发。
 * jobs：[['model'=>模型行, 'content'=>待审内容], ...]
 * 返回与输入同序的结果数组：['ok'=>bool, 'verdict'=>'violation|ok', 'note'=>string, 'label'=>模型名]
 * curl 扩展不可用时自动降级为串行；失败计数沿用 fail 窗口（成功清零 / 失败累计）。
 */
function ai_call_multi(array $jobs): array
{
    $strict = (string)cfg('ai_strict', 'standard');
    if ($strict === '' || $strict === 'default') {
        $strict = 'standard';
    }
    $out = [];
    foreach ($jobs as $j) {
        $out[] = ['ok' => false, 'verdict' => '', 'note' => '', 'label' => ai_model_label($j['model'])];
    }
    if (!$jobs) {
        return $out;
    }

    $canMulti = function_exists('curl_multi_init');
    $chs = [];
    if ($canMulti) {
        $mh = curl_multi_init();
        foreach ($jobs as $i => $j) {
            $payload = [
                'model' => $j['model']['model'],
                'temperature' => 0,
                'stream' => false,
                'max_tokens' => 300,
                'messages' => [
                    ['role' => 'system', 'content' => ai_system_prompt($strict)],
                    ['role' => 'user', 'content' => "待审核内容：\n" . cut_str($j['content'], 1200)],
                ],
            ];
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $ch = curl_init(ai_endpoint($j['model']['url']));
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $j['model']['key']],
            ]);
            curl_multi_add_handle($mh, $ch);
            $chs[$i] = $ch;
        }
        /* 同刻并发执行 */
        do {
            curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 0.2);
            }
        } while ($running > 0);
        foreach ($chs as $i => $ch) {
            $res = curl_multi_getcontent($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $cerr = curl_error($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            if ($res === false || $res === null || $cerr !== '') {
                $out[$i]['note'] = $cerr !== '' ? 'curl: ' . $cerr : 'HTTP 请求失败';
                continue;
            }
            if ($code < 200 || $code >= 300) {
                $out[$i]['note'] = "HTTP {$code}：" . cut_str(strip_tags((string)$res), 160);
                continue;
            }
            $v = '';
            $n = '';
            if (ai_parse_reply((string)$res, $v, $n)) {
                $out[$i]['ok'] = true;
                $out[$i]['verdict'] = $v;
                $out[$i]['note'] = $n;
            } else {
                $out[$i]['note'] = $n;
            }
        }
        curl_multi_close($mh);
        return $out;
    }

    /* 无 curl_multi：串行降级（结果等价，只是不并发） */
    foreach ($jobs as $i => $j) {
        $payload = [
            'model' => $j['model']['model'],
            'temperature' => 0,
            'stream' => false,
            'max_tokens' => 300,
            'messages' => [
                ['role' => 'system', 'content' => ai_system_prompt($strict)],
                ['role' => 'user', 'content' => "待审核内容：\n" . cut_str($j['content'], 1200)],
            ],
        ];
        $err = '';
        $res = http_post_json(ai_endpoint($j['model']['url']), $payload, ['Authorization: Bearer ' . $j['model']['key']], 30, $err);
        if ($res === null) {
            $out[$i]['note'] = $err;
            continue;
        }
        $v = '';
        $n = '';
        if (ai_parse_reply($res, $v, $n)) {
            $out[$i]['ok'] = true;
            $out[$i]['verdict'] = $v;
            $out[$i]['note'] = $n;
        } else {
            $out[$i]['note'] = $n;
        }
    }
    return $out;
}

/* ================= 多模型编排 ================= */

/**
 * 全火力全开：所有启用模型全部参与（每模型 1 次调用，不做内部重试，控制总耗时），
 * 任一模型判定违规即违规；全部失败才算失败（调用方转人工）。
 * 成功返回的模型会清零失败计数（顺带充当故障模型的自动恢复探测）。
 */
function ai_moderate_full(array $list, string $content, string &$verdict, string &$note, string &$used, ?string $strict): bool
{
    $st = ai_state_read();
    $fail = $st['fail'];
    $limit = ai_fail_limit();
    $parts = [];
    $errs = [];
    $names = [];
    $flagged = null;
    $firstOkId = 0;
    $okCount = 0;

    foreach ($list as $m) {
        $id = (int)$m['id'];
        $label = ai_model_label($m);
        $names[] = $label;
        $v = '';
        $sn = '';
        if (ai_call_model($m, $content, $v, $sn, ['retries' => 1, 'strict' => $strict])) {
            $wasTripped = (int)($fail[$id] ?? 0) >= $limit;
            $fail[$id] = 0;
            if ($wasTripped) {
                log_action('ai_model_recover', '模型「' . $label . '」审核成功，已从故障中自动恢复');
            }
            if ($firstOkId === 0) {
                $firstOkId = $id;
            }
            $okCount++;
            $parts[] = $label . '：' . ($v === 'violation' ? '违规' : '无问题');
            if ($v === 'violation' && $flagged === null) {
                $flagged = ['label' => $label, 'note' => $sn];
            }
        } else {
            $next = '';
            foreach ($list as $n) {
                if ((int)$n['id'] !== $id && (int)($fail[(int)$n['id']] ?? 0) + 1 < $limit) {
                    $next = ai_model_label($n);
                    break;
                }
            }
            ai_fail_bump($fail, $id, $label, $limit, $next);
            $errs[] = '「' . $label . '」' . $sn;
        }
    }

    $st['fail'] = $fail;
    $st['last'] = time();
    if ($firstOkId > 0) {
        $st['active'] = $firstOkId;
    }
    ai_state_write($st);
    $used = '全火力（' . implode('、', $names) . '）';

    if ($flagged !== null) {
        $verdict = 'violation';
        $note = cut_str('「' . $flagged['label'] . '」判定违规：' . $flagged['note']
            . ($okCount > 1 ? '（' . implode('；', $parts) . '）' : ''), 200);
        return true;
    }
    if ($okCount > 0) {
        $verdict = 'ok';
        $note = cut_str($okCount . ' 个模型均判定无问题（' . implode('；', $parts) . '）', 200);
        return true;
    }
    $note = cut_str(implode('；', $errs), 400);
    return false;
}

/**
 * 审核一段内容（多模型自动切换）
 * @param string $verdict 输出 'violation' | 'ok'
 * @param string $note    输出：成功 = 结论说明；失败 = 各模型错误汇总
 * @param array  $ov      可覆盖：retries / strict / fullpower（缺省读后台配置）
 * @param string $used    输出：本次实际参与审核的模型说明（日志用）
 * @return bool 是否得到了有效结论（全部模型失败返回 false，由调用方转人工审核）
 */
function ai_moderate(string $content, string &$verdict = '', string &$note = '', array $ov = [], string &$used = ''): bool
{
    $verdict = '';
    $note = '';
    $used = '';
    $list = ai_models_enabled();
    if (!$list) {
        $note = 'AI 未配置完整：请到后台「AI」添加启用的模型（地址 / 密钥 / 模型名）';
        return false;
    }
    $strict = ($ov['strict'] ?? null) === null ? null : (string)$ov['strict'];

    if (!empty($ov['fullpower'])) {
        return ai_moderate_full($list, $content, $verdict, $note, $used, $strict);
    }

    $st = ai_state_read();
    $limit = ai_fail_limit();

    /* 排序：未达阈值的模型优先（主力模型置顶），已达阈值的排到队尾兜底（成功即自动恢复） */
    $good = [];
    $bad = [];
    foreach ($list as $m) {
        if ((int)($st['fail'][(int)$m['id']] ?? 0) >= $limit) {
            $bad[] = $m;
        } else {
            $good[] = $m;
        }
    }
    foreach ($good as $i => $m) {
        if ((int)$m['id'] === (int)$st['active'] && $i > 0) {
            array_splice($good, $i, 1);
            array_unshift($good, $m);
            break;
        }
    }
    $order = array_merge($good, $bad);

    $fail = $st['fail'];
    $errs = [];
    foreach ($order as $m) {
        $id = (int)$m['id'];
        $label = ai_model_label($m);
        $v = '';
        $sn = '';
        if (ai_call_model($m, $content, $v, $sn, ['strict' => $strict])) {
            $wasTripped = (int)($fail[$id] ?? 0) >= $limit;
            $fail[$id] = 0;
            $st['fail'] = $fail;
            $st['active'] = $id;
            $st['last'] = time();
            ai_state_write($st);
            if ($wasTripped) {
                log_action('ai_model_recover', '模型「' . $label . '」审核成功，已从故障中自动恢复并重新作为候选主力');
            }
            $verdict = $v;
            $note = $sn;
            $used = '模型「' . $label . '」（' . $m['model'] . '）';
            return true;
        }
        /* 计算切到哪个模型（顺序里下一个未达阈值的） */
        $next = '';
        foreach ($order as $n) {
            if ((int)$n['id'] !== $id && (int)($fail[(int)$n['id']] ?? 0) + 1 < $limit) {
                $next = ai_model_label($n);
                break;
            }
        }
        ai_fail_bump($fail, $id, $label, $limit, $next);
        $errs[] = '「' . $label . '」' . $sn;
    }

    $st['fail'] = $fail;
    $st['last'] = time();
    ai_state_write($st);
    $note = cut_str(implode('；', $errs), 400);
    $used = count($order) . ' 个模型全部失败';
    return false;
}

/* ================= 测试调用 ================= */

/**
 * 「测试调用」：$m 为模型行（后台测试传已保存的行，安装向导传表单临时行）。
 * $ov 可覆盖 retries（后台测试用 1 次以便快速反馈）与 strict。
 */
function ai_test_model(array $m, string &$msg, array $ov = []): bool
{
    $m = ai_model_norm($m);
    if (!ai_model_ok($m)) {
        $msg = '请先填写完整的 API 地址 / 密钥 / 模型名';
        return false;
    }
    $verdict = '';
    $note = '';
    $ok = ai_call_model($m, '今天天气不错，适合写代码、看书。', $verdict, $note, $ov);
    $msg = $ok
        ? '「' . ai_model_label($m) . '」调用成功，判定：' . ($verdict === 'violation' ? '违规' : '无问题') . ($note !== '' ? '（' . $note . '）' : '')
        : '「' . ai_model_label($m) . '」调用失败：' . $note;
    return $ok;
}

/**
 * 旧版接口兼容（v1.11.0 及更早版本的 install.php / 第三方脚本使用）：
 * $ov = ['url', 'key', 'model', 'retries']，内部转单模型测试。
 */
function ai_test(array $ov, string &$msg): bool
{
    return ai_test_model([
        'url' => (string)($ov['url'] ?? ''),
        'key' => (string)($ov['key'] ?? ''),
        'model' => (string)($ov['model'] ?? ''),
        'name' => '待保存模型',
    ], $msg, ['retries' => max(1, (int)($ov['retries'] ?? 1))]);
}

/* ================= AI 自主管理（严全面模式 · 巡逻） ================= */

/**
 * AI 自主管理官固定提示词（后台不可编辑，保证判断口径稳定一致）。
 * AI 只输出 JSON 报告：态势总结 + 动作清单（封禁 / 解封 / 邮件警报）。
 * 所有动作在 ai_patrol_execute() 强制白名单校验后才会执行：
 * 只能封「本次材料中出现过的风险 IP」、保护名单与环回永久免疫、单轮限量、全部留痕可撤销。
 */
const AI_PATROL_SYSTEM_PROMPT = '你是论坛的 AI 自主管理官，负责无人值守时巡逻论坛安全。你会收到一份安全态势材料（今日统计、风险 IP、近期关键日志、当前封禁、保护名单）。请冷静、保守地决策：1) 封禁（ban）：仅限材料中出现且明显恶意的 IP（高频扫描、持续攻击、刷量灌水），宁缺毋滥；2) 解封（unban）：仅当判断为误封；3) 邮件警报（alert）：仅严重态势（持续攻击、封禁激增、审核服务异常）才提醒管理员，轻微事件不要发信。一切从简：没有把握就不动作。只输出一个 JSON 对象：{"assess":"不超过100字的态势总结","actions":[{"action":"ban","ip":"x.x.x.x","hours":24,"reason":"不超过30字"},{"action":"unban","ip":"x.x.x.x","reason":"不超过30字"},{"action":"alert","subject":"不超过20字","body":"不超过120字"}]}。actions 可为空数组（无动作），不要输出任何其他文字。';

/** AI 自主管理总开关（后台可勾选；需已配置启用模型才实际生效） */
function ai_autopilot_on(): bool
{
    return (int)cfg('ai_autopilot', 0) === 1 && ai_ready();
}

/** 巡逻间隔（分钟，2-360，默认 15）：常驻巡逻器按此节奏巡逻，Web 惰性触发同样遵守 */
function ai_patrol_interval(): int
{
    return max(2, min(360, (int)cfg('ai_patrol_interval', 15)));
}

/** 单轮封禁上限（1-10，默认 3）：AI 一轮巡逻最多封禁的 IP 数（红线，防滥杀） */
function ai_patrol_ban_limit(): int
{
    return max(1, min(10, (int)cfg('ai_patrol_ban_limit', 3)));
}

/** 巡逻状态（data/ai_patrol.php）：last=上次巡逻时间 report=最近报告 daemon=常驻巡逻器心跳 last_alert=上次警报时间 */
function ai_patrol_state(): array
{
    $s = Store::read('ai_patrol.php', []);
    return is_array($s) ? $s : [];
}

function ai_patrol_state_save(array $s): void
{
    Store::write('ai_patrol.php', $s);
}

/** 常驻巡逻器（daemon.php）是否在线：心跳 180 秒内视为在线（在线时 Web 惰性巡逻自动让位） */
function ai_daemon_alive(): bool
{
    $s = ai_patrol_state();
    return time() - (int)($s['daemon'] ?? 0) < 180;
}

/** 保护名单：管理员白名单 + 本机环回。名单内 IP 永久免疫 AI 封禁 */
function ai_patrol_protected(): array
{
    $ips = ['127.0.0.1' => true, '::1' => true];
    foreach (fw_ip_list((string)cfg('fw_whitelist', '')) as $item) {
        $ips[trim((string)$item)] = true;
    }
    return $ips;
}

/**
 * 收集巡逻材料（紧凑文本 + 候选风险 IP 集合）。数据全部取自防火墙状态与今日日志，零外部依赖。
 * @return array{text:string, ips:array<string,bool>, events:bool} events=false 表示当前无风险事件（巡逻零 token 跳过）
 */
function ai_patrol_material(): array
{
    $now = time();
    $w = intdiv($now, 600);
    $st = fw_state();
    $cand = [];   // 候选风险 IP：材料中出现过的才允许 AI 封禁（红线）
    $rows = [];   // [score, line]
    foreach ((array)($st['ips'] ?? []) as $ip => $r) {
        if (!is_array($r) || (int)($r['w'] ?? 0) !== $w) {
            continue; // 仅当前 10 分钟窗口的活跃 IP
        }
        $s = (int)($r['s'] ?? 0);
        $f = (int)($r['f'] ?? 0);
        if ($s <= 0 && $f < 5) {
            continue;
        }
        $last = max(0, $now - (int)($r['l'] ?? 0));
        $cand[(string)$ip] = true;
        $rows[] = [$s, $ip . ' req=' . (int)($r['c'] ?? 0) . ' 404=' . $f . ' score=' . $s . ' last=' . $last . 's ua=' . cut_str((string)($r['ua'] ?? ''), 40)];
    }
    usort($rows, function ($a, $b) {
        return $b[0] <=> $a[0];
    });
    $ipLines = array_column($rows, 1);
    $ipLines = array_slice($ipLines, 0, 15);

    /* 今日关键日志尾部（只关注安全相关动作，最多 25 条，文件尾读避免大文件全载） */
    $watch = [
        'fw_block' => '拦截', 'fw_ratelimit' => '限流', 'fw_auto_ban' => '自动封禁', 'fw_ban' => '封禁', 'fw_unban' => '解封',
        'report' => '举报', 'ai_ok' => 'AI通过', 'ai_bad' => 'AI违规', 'ai_failed' => 'AI失败',
        'ai_patrol' => '巡逻', 'ai_patrol_ban' => 'AI封禁', 'ai_patrol_unban' => 'AI解封', 'ai_patrol_alert' => 'AI警报',
        'user_ban' => '封号', 'user_mute' => '禁言', 'thread_new' => '发帖', 'reply_new' => '回帖',
    ];
    $logs = [];
    $hasRiskLog = false;
    $f = DATA_DIR . '/logs/log-' . date('Y-m-d') . '.php';
    if (is_file($f)) {
        $fp = @fopen($f, 'r');
        if ($fp) {
            $sz = (int)@filesize($f);
            @fseek($fp, max(0, $sz - 16384));
            if ($sz > 16384) {
                @fgets($fp); // 跳过截断的半行
            }
            while (($ln = @fgets($fp)) !== false) {
                $ln = trim($ln);
                if ($ln === '' || strpos($ln, '{') !== 0) {
                    continue;
                }
                $j = json_decode($ln, true);
                if (!is_array($j) || !isset($watch[(string)$j['action']])) {
                    continue;
                }
                if (in_array((string)$j['action'], ['fw_block', 'fw_ratelimit', 'fw_auto_ban', 'report', 'ai_bad', 'ai_failed', 'user_ban'], true)) {
                    $hasRiskLog = true;
                }
                $ipj = (string)($j['ip'] ?? '');
                if ($ipj !== '' && in_array((string)$j['action'], ['fw_block', 'fw_ratelimit', 'fw_auto_ban', 'report'], true)) {
                    $cand[$ipj] = true; // 涉事 IP 也进候选（AI 可基于完整证据链封禁）
                }
                $logs[] = date('H:i', (int)($j['t'] ?? $now)) . ' [' . $watch[(string)$j['action']] . '] ' . cut_str((string)($j['name'] ?? ''), 12) . '(' . $ipj . '): ' . cut_str((string)($j['detail'] ?? ''), 80);
            }
            @fclose($fp);
        }
        $logs = array_slice($logs, -25);
    }

    /* 当前封禁概要（最多 3 条） */
    $bans = fw_bans_all();
    $banLines = [];
    $i = 0;
    foreach ($bans as $ip => $r) {
        if ($i++ >= 3) {
            break;
        }
        $left = (int)($r['until'] ?? 0) > 0 ? '剩' . max(1, (int)ceil(((int)$r['until'] - $now) / 3600)) . 'h' : '永久';
        $banLines[] = $ip . ' ' . $left . ' ' . cut_str((string)($r['reason'] ?? ''), 40);
    }

    $d = (array)(($st['days'] ?? [])[date('Y-m-d')] ?? []);
    $prot = ai_patrol_protected();
    $txt = '[今日统计] 请求=' . (int)($d['req'] ?? 0) . ' 拦截=' . (int)($d['blocked'] ?? 0) . ' 封禁=' . (int)($d['bans'] ?? 0)
        . "\n[风险IP]（当前10分钟窗口" . count($ipLines) . "个）\n" . implode("\n", $ipLines)
        . "\n[关键日志]（今日最近" . count($logs) . "条）\n" . implode("\n", $logs)
        . "\n[封禁中] 共" . count($bans) . "条" . ($banLines ? "\n" . implode("\n", $banLines) : '')
        . "\n[保护名单·禁止封禁] " . cut_str(implode(', ', array_keys($prot)), 200);

    $events = $rows !== [] || $hasRiskLog || (int)($d['blocked'] ?? 0) > 0 || (int)($d['bans'] ?? 0) > 0;
    return ['text' => $txt, 'ips' => $cand, 'events' => $events];
}

/**
 * 巡逻调用（多模型自动切换编排，与内容审核共用失败计数与主力指针）。
 * 成功时 $report = ['assess'=>..., 'actions'=>[...]]，$used = 审核方模型描述。
 */
function ai_patrol_call(array $mat, array &$report, string &$used): bool
{
    $report = [];
    $used = '';
    $list = ai_models_enabled();
    if (!$list) {
        $report = 'AI 未配置完整：请到后台「AI」添加启用的模型';
        return false;
    }
    $st = ai_state_read();
    $limit = ai_fail_limit();

    /* 排序：未达阈值的模型优先（主力置顶），已达阈值的排到队尾兜底（成功即自动恢复） */
    $good = [];
    $bad = [];
    foreach ($list as $m) {
        if ((int)($st['fail'][(int)$m['id']] ?? 0) >= $limit) {
            $bad[] = $m;
        } else {
            $good[] = $m;
        }
    }
    foreach ($good as $i => $m) {
        if ((int)$m['id'] === (int)$st['active'] && $i > 0) {
            array_splice($good, $i, 1);
            array_unshift($good, $m);
            break;
        }
    }
    $order = array_merge($good, $bad);

    $fail = $st['fail'];
    $errs = [];
    $payload = [
        'model' => '',
        'temperature' => 0,
        'stream' => false,
        'max_tokens' => 500,
        'messages' => [
            ['role' => 'system', 'content' => AI_PATROL_SYSTEM_PROMPT],
            ['role' => 'user', 'content' => "论坛安全态势材料：\n" . $mat['text']],
        ],
    ];
    foreach ($order as $m) {
        $id = (int)$m['id'];
        $label = ai_model_label($m);
        $payload['model'] = $m['model'];
        $err = '';
        $res = http_post_json(ai_endpoint($m['url']), $payload, ['Authorization: Bearer ' . $m['key']], 30, $err);
        if ($res !== null) {
            $j = json_decode($res, true);
            $text = is_array($j) ? ($j['choices'][0]['message']['content'] ?? '') : '';
            if (is_string($text) && preg_match('/\{[\s\S]*\}/s', $text, $mm)) {
                $v = json_decode($mm[0], true);
                if (is_array($v) && array_key_exists('assess', $v)) {
                    $wasTripped = (int)($fail[$id] ?? 0) >= $limit;
                    $fail[$id] = 0;
                    $st['fail'] = $fail;
                    $st['active'] = $id;
                    $st['last'] = time();
                    ai_state_write($st);
                    if ($wasTripped) {
                        log_action('ai_model_recover', '模型「' . $label . '」巡逻成功，已从故障中自动恢复并重新作为候选主力');
                    }
                    $acts = (array)($v['actions'] ?? []);
                    $report = ['assess' => cut_str((string)($v['assess'] ?? ''), 200), 'actions' => $acts];
                    $used = '模型「' . $label . '」（' . $m['model'] . '）';
                    return true;
                }
            }
            $sn = trim((string)$text) !== '' ? cut_str($text, 120) : '响应不含有效 JSON 报告';
        } else {
            $sn = cut_str($err, 120);
        }
        $next = '';
        foreach ($order as $n) {
            if ((int)$n['id'] !== $id && (int)($fail[(int)$n['id']] ?? 0) + 1 < $limit) {
                $next = ai_model_label($n);
                break;
            }
        }
        ai_fail_bump($fail, $id, $label, $limit, $next);
        $errs[] = '「' . $label . '」' . $sn;
    }
    $st['fail'] = $fail;
    $st['last'] = time();
    ai_state_write($st);
    $report = cut_str(implode('；', $errs), 300);
    $used = count($order) . ' 个模型全部失败';
    return false;
}

/**
 * AI 邮件警报（发给全部管理员，30 分钟节流；后台未开启则拒绝）
 */
function ai_patrol_alert(array &$s, string $subject, string $body, string $assess, array &$rows): void
{
    if ((int)cfg('ai_patrol_alert', 1) !== 1) {
        $rows[] = ['alert', '', '已跳过：管理员未开启 AI 邮件警报', false];
        return;
    }
    if (time() - (int)($s['last_alert'] ?? 0) < 1800) {
        $rows[] = ['alert', '', '已跳过：30 分钟内已发送过警报（节流）', false];
        return;
    }
    $sent = 0;
    foreach (user_all() as $au) {
        if (empty($au['admin'])) {
            continue;
        }
        $to = (string)($au['email'] ?? '');
        if (!valid_email($to)) {
            continue;
        }
        [$ok] = mail_send($to, '【AI 自主管理】' . cut_str($subject !== '' ? $subject : '论坛安全态势警报', 30), 'AI 巡逻态势：' . $assess . "\n\n" . cut_str($body, 300) . "\n\n—— 极简论坛 AI 自主管理官");
        if ($ok) {
            $sent++;
        }
    }
    if ($sent > 0) {
        $s['last_alert'] = time();
        log_action('ai_patrol_alert', 'AI 自主管理邮件警报已发送（' . $sent . ' 位管理员）：' . cut_str($subject, 40), 0, 'AI 巡逻');
        $rows[] = ['alert', '', '已发送给 ' . $sent . ' 位管理员：' . cut_str($subject, 40), true];
    } else {
        $rows[] = ['alert', '', '发送失败：无有效管理员邮箱或邮件服务不可用', false];
    }
}

/**
 * 执行 AI 动作清单（白名单强校验，全部留痕）：
 *  - ban：IP 必须在本次材料候选中、格式合法、非保护名单、未封禁中；单轮 ≤ ai_patrol_ban_limit；时长 1-72h
 *  - unban：仅可解「自动策略 / AI」类封禁（管理员手动封禁 AI 无权解除）；单轮 ≤ 2
 *  - alert：30 分钟节流 + 后台开关
 */
function ai_patrol_execute(array &$s, array $report, array $cand, array &$rows): void
{
    $bans = 0;
    $unbans = 0;
    foreach ((array)($report['actions'] ?? []) as $a) {
        if (!is_array($a)) {
            continue;
        }
        $act = (string)($a['action'] ?? '');
        $ip = trim((string)($a['ip'] ?? ''));
        $reason = cut_str((string)($a['reason'] ?? ''), 60);
        if ($act === 'ban') {
            if ($bans >= ai_patrol_ban_limit()) {
                $rows[] = ['ban', $ip, '已拒绝：本轮封禁已达上限（' . ai_patrol_ban_limit() . ' 个）', false];
                continue;
            }
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                $rows[] = ['ban', $ip, '已拒绝：IP 格式非法', false];
                continue;
            }
            if (!isset($cand[$ip])) {
                $rows[] = ['ban', $ip, '已拒绝：该 IP 不在本次风险材料中（AI 红线：禁止凭空封人）', false];
                continue;
            }
            if (isset(ai_patrol_protected()[$ip]) || fw_whitelisted($ip)) {
                $rows[] = ['ban', $ip, '已拒绝：保护名单免疫', false];
                continue;
            }
            if (fw_is_banned($ip) !== null) {
                $rows[] = ['ban', $ip, '已跳过：该 IP 已在封禁中', false];
                continue;
            }
            $hours = max(1, min(72, (int)($a['hours'] ?? 24)));
            if (fw_ban($ip, $hours, 'AI 自主管理：' . $reason, 'auto', 'AI 巡逻')) {
                $bans++;
                log_action('ai_patrol_ban', 'AI 自主封禁 IP ' . $ip . ' ' . $hours . ' 小时：' . $reason, 0, 'AI 巡逻');
                fw_bump($ip, 'blocked');
                $rows[] = ['ban', $ip, $hours . ' 小时：' . $reason, true];
            } else {
                $rows[] = ['ban', $ip, '封禁写入失败', false];
            }
        } elseif ($act === 'unban') {
            if ($unbans >= 2) {
                $rows[] = ['unban', $ip, '已拒绝：本轮解封已达上限（2 个）', false];
                continue;
            }
            $rec = fw_is_banned($ip);
            if ($rec === null) {
                $rows[] = ['unban', $ip, '已跳过：该 IP 未在封禁中', false];
                continue;
            }
            if ((string)($rec['kind'] ?? 'manual') === 'manual') {
                $rows[] = ['unban', $ip, '已拒绝：管理员手动封禁，AI 无权解除', false];
                continue;
            }
            if (fw_unban($ip)) {
                $unbans++;
                log_action('ai_patrol_unban', 'AI 自主解封 IP ' . $ip . '：' . ($reason !== '' ? $reason : '判定为误封'), 0, 'AI 巡逻');
                $rows[] = ['unban', $ip, ($reason !== '' ? $reason : '判定为误封'), true];
            } else {
                $rows[] = ['unban', $ip, '解封失败', false];
            }
        } elseif ($act === 'alert') {
            ai_patrol_alert($s, (string)($a['subject'] ?? ''), (string)($a['body'] ?? ''), (string)($report['assess'] ?? ''), $rows);
        } else {
            $rows[] = [$act, $ip, '未知动作，已忽略', false];
        }
    }
}

/**
 * 执行一轮巡逻（锁防并发；材料由调用方传入）。成功/失败均落盘报告与日志。
 * @return bool 是否完成了一次有效巡逻（含「调用失败」的报告落盘）
 */
function ai_patrol_go(array $mat, string &$msg): bool
{
    $msg = '';
    $lk = Store::tryLock('ai_patrol');
    if (!$lk) {
        $msg = '已有巡逻任务进行中';
        return false;
    }
    try {
        $report = [];
        $used = '';
        $ok = ai_patrol_call($mat, $report, $used);
        $now = time();
        $s = ai_patrol_state();
        $s['last'] = $now;
        if (!$ok) {
            $s['report'] = ['time' => $now, 'assess' => is_string($report) ? $report : '巡逻调用失败', 'used' => $used, 'rows' => [], 'ok' => false];
            ai_patrol_state_save($s);
            log_action('ai_patrol', '巡逻调用失败：' . cut_str(is_string($report) ? $report : '', 140) . '（' . $used . '）', 0, 'AI 巡逻');
            $msg = '巡逻调用失败：' . cut_str(is_string($report) ? $report : '', 80);
            return false;
        }
        $rows = [];
        ai_patrol_execute($s, $report, (array)($mat['ips'] ?? []), $rows);
        $s['report'] = ['time' => $now, 'assess' => (string)$report['assess'], 'used' => $used, 'rows' => $rows, 'ok' => true];
        ai_patrol_state_save($s);
        $nAct = 0;
        foreach ($rows as $r) {
            if (!empty($r[3])) {
                $nAct++;
            }
        }
        log_action('ai_patrol', '巡逻完成：' . cut_str((string)$report['assess'], 160) . '；执行动作 ' . $nAct . '/' . count($rows) . ' 项（' . $used . '）', 0, 'AI 巡逻');
        $msg = '巡逻完成：' . cut_str((string)$report['assess'], 80);
        return true;
    } catch (Throwable $ex) {
        $msg = '巡逻异常：' . $ex->getMessage();
        return false;
    } finally {
        Store::unlock($lk);
    }
}


/**
 * Web 惰性巡逻挂点（index.php 请求收尾调用）：开关开 + 常驻巡逻器不在线 + 到点 + 有风险事件 才真正调用 AI
 */
function ai_autopilot_tick(): void
{
    if (!ai_autopilot_on() || ai_daemon_alive()) {
        return;
    }
    $s = ai_patrol_state();
    if (time() - (int)($s['last'] ?? 0) < ai_patrol_interval() * 60) {
        return;
    }
    $mat = ai_patrol_material();
    $s['last'] = time();
    ai_patrol_state_save($s);
    if (!$mat['events']) {
        return; // 无风险事件：零 token 跳过
    }
    $msg = '';
    ai_patrol_go($mat, $msg);
}
