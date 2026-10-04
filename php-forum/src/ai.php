<?php
/**
 * 极简论坛 · AI 内容审核客户端（OpenAI 兼容接口）
 * 零依赖：优先 curl，无 curl 时回退 stream context
 */
defined('APP') or exit('Forbidden');

const AI_SYSTEM_PROMPT = '你是论坛内容安全审核助手。请判断给定内容是否违规。违规包括：违法信息、色情低俗、人身攻击辱骂、暴力恐怖、赌博诈骗、垃圾广告、恶意灌水、泄露他人隐私。拿不准时判为不违规。只输出一个 JSON 对象：{"violation": true, "reason": "不超过30字的理由"}，其中 violation 为 true 或 false，不要输出任何其他文字。';

function ai_ready(): bool
{
    return cfg('ai_url', '') !== '' && cfg('ai_key', '') !== '' && cfg('ai_model', '') !== '';
}

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

/**
 * 审核一段内容
 * @param string $verdict 输出 'violation' | 'ok'
 * @param string $note    输出理由（成功）或错误信息（失败）
 */
function ai_moderate(string $content, string &$verdict = '', string &$note = '', array $ov = []): bool
{
    $url = trim((string)($ov['url'] ?? cfg('ai_url', '')));
    $key = (string)($ov['key'] ?? cfg('ai_key', ''));
    $model = trim((string)($ov['model'] ?? cfg('ai_model', '')));
    $retries = max(1, (int)($ov['retries'] ?? cfg('ai_retries', 3)));
    if ($url === '' || $key === '' || $model === '') {
        $note = 'AI 未配置完整（地址 / 密钥 / 模型）';
        return false;
    }

    $payload = [
        'model' => $model,
        'temperature' => 0,
        'stream' => false,
        'max_tokens' => 300,
        'messages' => [
            ['role' => 'system', 'content' => AI_SYSTEM_PROMPT],
            ['role' => 'user', 'content' => "待审核内容：\n" . cut_str($content, 1200)],
        ],
    ];
    $err = '';
    for ($i = 1; $i <= $retries; $i++) {
        $res = http_post_json(ai_endpoint($url), $payload, ['Authorization: Bearer ' . $key], 30, $err);
        if ($res !== null) {
            $j = json_decode($res, true);
            $text = is_array($j) ? ($j['choices'][0]['message']['content'] ?? '') : '';
            if (is_string($text) && $text !== '') {
                $m = [];
                if (preg_match('/\{[^{}]*\}/s', $text, $m)) {
                    $v = json_decode($m[0], true);
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

/** 安装向导 / 后台的「测试调用」 */
function ai_test(array $ov, string &$msg): bool
{
    $verdict = '';
    $note = '';
    $ok = ai_moderate('今天天气不错，适合写代码、看书。', $verdict, $note, $ov);
    $msg = $ok
        ? '调用成功，模型判定：' . ($verdict === 'violation' ? '违规' : '无问题') . ($note !== '' ? '（' . $note . '）' : '')
        : '调用失败：' . $note;
    return $ok;
}
