<?php
/**
 * 极简论坛 · 极简 SMTP 发信客户端（v1.10.0 起支持 HTML 邮件）
 * 纯 socket 实现，支持 SSL(465) / STARTTLS(587/25)，零外部依赖
 *
 * 邮件格式：
 *   - 传入纯文本  → 按 text/plain 发送（与旧版完全一致）
 *   - 传入 HTML（mail_template() 生成）→ 自动构造 multipart/alternative，
 *     同时携带纯文本降级版与 HTML 版：支持的客户端显示美化版，
 *     不支持的客户端自动降级为纯文本，两全其美。
 */
defined('APP') or exit('Forbidden');

/**
 * HTML → 可读纯文本（multipart 降级部分用）：块级标签换行、去标签、还原实体、压缩空行
 */
function mail_text_from_html(string $html): string
{
    $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
    $html = str_replace(["\r\n", "\r"], "\n", $html);
    $html = preg_replace('#</(p|div|tr|li|h[1-6]|table|blockquote|section)>#i', "\n", $html) ?? $html;
    $html = preg_replace('#<(br|hr)\s*/?>#i', "\n", $html) ?? $html;
    $text = trim(strip_tags($html));
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
    // 去除每行首尾多余空白（表格单元格换行产生的缩进）
    $lines = array_map('trim', explode("\n", $text));
    return trim(implode("\n", $lines));
}

/**
 * 系统邮件 HTML 模板：页头（站点名 + 主题色条）+ 标题 + 正文 + 页脚（时间 + 免责）
 * 全部内联样式（兼容主流邮件客户端），$inner 为已转义/可信 HTML 片段
 */
function mail_template(string $title, string $inner, array $opts = []): string
{
    $site = cut_str((string)cfg('site_name', '论坛'), 40);
    $accent = (string)cfg('theme_color', '');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
        $accent = '#0f766e';
    }
    $note = trim((string)($opts['note'] ?? ''));
    $time = date('Y-m-d H:i');
    $inner = trim($inner);
    $noteHtml = $note !== ''
        ? '<div style="margin:18px 0 0;background:#f4f4f5;border:1px solid #e5e5ea;border-radius:10px;padding:12px 14px;font-size:13px;line-height:1.7;color:#52525b">' . $note . '</div>'
        : '';
    return '<!DOCTYPE html>'
        . '<html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:24px 12px;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,\'PingFang SC\',\'Hiragino Sans GB\',\'Microsoft YaHei\',\'Segoe UI\',Roboto,sans-serif;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse"><tr><td align="center">'
        . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="width:560px;max-width:100%;border-collapse:separate;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06)">'
        // 页头
        . '<tr><td style="background:' . $accent . ';padding:18px 28px">'
        . '<span style="color:#ffffff;font-size:16px;font-weight:700;letter-spacing:.5px">' . htmlspecialchars($site, ENT_QUOTES, 'UTF-8') . '</span>'
        . '<span style="float:right;color:rgba(255,255,255,.75);font-size:12px;padding-top:3px">系统邮件</span>'
        . '</td></tr>'
        // 正文
        . '<tr><td style="padding:26px 28px 8px">'
        . '<h1 style="margin:0 0 16px;font-size:19px;line-height:1.4;color:#18181b">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<div style="font-size:14px;line-height:1.8;color:#3f3f46;word-break:break-word">' . $inner . '</div>'
        . $noteHtml
        . '</td></tr>'
        // 页脚
        . '<tr><td style="padding:18px 28px 22px;border-top:1px solid #f0f0f2;margin-top:12px">'
        . '<p style="margin:0;font-size:12px;line-height:1.7;color:#a1a1aa">此邮件由 ' . htmlspecialchars($site, ENT_QUOTES, 'UTF-8') . ' 系统自动发送，请勿直接回复。'
        . ($note !== '' ? '' : '<br>如非本人操作，请忽略本邮件。') . '</p>'
        . '<p style="margin:6px 0 0;font-size:12px;color:#c4c4cc">' . $time . '</p>'
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/**
 * 发送邮件（正文为纯文本按 text/plain；正文为 HTML 自动 multipart/alternative 双格式）
 * @return array [bool 成功, string 失败原因]
 */
function mail_send(string $to, string $subject, string $body, array $ov = []): array
{
    $host = trim((string)($ov['host'] ?? cfg('smtp_host', '')));
    $port = (int)($ov['port'] ?? cfg('smtp_port', 465));
    $from = trim((string)($ov['from'] ?? cfg('smtp_from', '')));
    $pass = (string)($ov['pass'] ?? cfg('smtp_pass', ''));

    if ($host === '' || $from === '') {
        return [false, 'SMTP 未配置（主机或发信邮箱为空）'];
    }
    if (!function_exists('fsockopen')) {
        return [false, '服务器缺少 fsockopen，无法发信'];
    }

    $secure = ($port === 465);
    $remote = ($secure ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $eno, $estr, 12);
    if (!$fp) {
        return [false, '连接失败：' . $estr . ' (' . $eno . ')'];
    }
    stream_set_timeout($fp, 12);

    $last = '';
    $cmd = function (string $c, string $expect) use ($fp, &$last): array {
        if ($c !== '') {
            if (@fwrite($fp, $c . "\r\n") === false) {
                return [false, ''];
            }
        }
        $resp = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $resp .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $last = trim($resp);
        $code = (int)substr($resp, 0, 3);
        return [$expect === '' ? true : strpos($expect, (string)$code) !== false, $resp];
    };

    [$ok] = $cmd('', '220');
    if (!$ok) {
        fclose($fp);
        return [false, '服务器问候异常：' . $last];
    }
    [$ok] = $cmd('EHLO forum', '250');
    if (!$ok) {
        fclose($fp);
        return [false, 'EHLO 失败：' . $last];
    }
    if (!$secure && strpos($last, 'STARTTLS') !== false) {
        [$ok] = $cmd('STARTTLS', '220');
        if (!$ok || !@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return [false, 'TLS 升级失败'];
        }
        [$ok] = $cmd('EHLO forum', '250');
        if (!$ok) {
            fclose($fp);
            return [false, 'EHLO(TLS) 失败：' . $last];
        }
    }
    [$ok] = $cmd('AUTH LOGIN', '334');
    if (!$ok) {
        fclose($fp);
        return [false, '服务器不支持 AUTH LOGIN：' . $last];
    }
    [$ok] = $cmd(base64_encode($from), '334');
    if (!$ok) {
        fclose($fp);
        return [false, '登录用户名被拒绝：' . $last];
    }
    [$ok] = $cmd(base64_encode($pass), '235');
    if (!$ok) {
        fclose($fp);
        return [false, '认证失败，请检查邮箱与授权码：' . $last];
    }
    [$ok] = $cmd('MAIL FROM:<' . $from . '>', '250');
    if (!$ok) {
        fclose($fp);
        return [false, 'MAIL FROM 被拒：' . $last];
    }
    [$ok] = $cmd('RCPT TO:<' . $to . '>', '250');
    if (!$ok) {
        fclose($fp);
        return [false, '收件人被拒（' . $to . '）：' . $last];
    }
    [$ok] = $cmd('DATA', '354');
    if (!$ok) {
        fclose($fp);
        return [false, 'DATA 被拒：' . $last];
    }

    $siteName = (string)cfg('site_name', 'Forum');
    $headers = 'From: =?UTF-8?B?' . base64_encode($siteName) . "?= <{$from}>\r\n";
    $headers .= "To: <{$to}>\r\n";
    $headers .= 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n";
    $headers .= 'Date: ' . date('r') . "\r\n";
    $headers .= 'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . $host . ">\r\n";

    /* HTML 正文 → multipart/alternative（纯文本降级 + HTML）；纯文本 → 原行为不变 */
    $isHtml = (bool)preg_match('/^\s*<(?:!doctype|html|table|div|p|section)\b/i', $body);
    if ($isHtml) {
        $boundary = 'mf_' . bin2hex(random_bytes(9));
        $headers .= "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $payloadBody = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode(mail_text_from_html($body))) . "\r\n"
            . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($body)) . "\r\n"
            . "--{$boundary}--\r\n";
    } else {
        $headers .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
        $payloadBody = chunk_split(base64_encode($body));
    }
    $payload = $headers . "\r\n" . $payloadBody;
    $payload = str_replace("\r\n.", "\r\n..", $payload); // 点填充
    @fwrite($fp, $payload . "\r\n.\r\n");

    $resp = '';
    while (($line = fgets($fp, 1024)) !== false) {
        $resp .= $line;
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }
    $ok = (int)substr($resp, 0, 3) === 250;
    @fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return $ok ? [true, ''] : [false, '邮件被拒：' . trim($resp)];
}

/**
 * 给全部管理员邮箱发信（存储告警 / 攻击告警等系统通知共用）
 * @return array [int 成功份数, int 管理员总数, string 最后失败原因]
 */
function mail_admins(string $subject, string $body): array
{
    $sent = 0;
    $total = 0;
    $lastErr = 'SMTP 未配置或无管理员邮箱';
    foreach (user_all() as $au) {
        if (empty($au['admin'])) {
            continue;
        }
        $total++;
        $to = (string)($au['email'] ?? '');
        if (!valid_email($to)) {
            continue;
        }
        [$ok, $err] = mail_send($to, $subject, $body);
        if ($ok) {
            $sent++;
        } else {
            $lastErr = $err;
        }
    }
    return [$sent, $total, $lastErr];
}
