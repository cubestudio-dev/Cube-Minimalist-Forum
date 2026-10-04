<?php
/**
 * 极简论坛 · 极简 SMTP 发信客户端
 * 纯 socket 实现，支持 SSL(465) / STARTTLS(587/25)，零外部依赖
 */
defined('APP') or exit('Forbidden');

/**
 * 发送纯文本邮件
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
    $headers .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
    $payload = $headers . "\r\n" . chunk_split(base64_encode($body));
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
