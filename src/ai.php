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
