<?php
/**
 * 极简论坛 · 极简 SMTP 发信客户端
 * 纯 socket 实现，支持 SSL(465) / STARTTLS(587/25)，零外部依赖
 *
 * v1.43.0 连接层改造：DNS 故障免疫（四层回退）
 *   层0 直连 IP：后台「直连 IP」有值时优先使用（证书仍按 SMTP 主机名校验）
 *   层1 标准解析：stream_socket_client 域名连接（getaddrinfo，正常路径）
 *   层2 hosts 兜底：手工读 /etc/hosts 取 IP（绕开 nsswitch 顺序 / 文件权限差异）
 *   层3 公共 DNS：纯 PHP UDP 查询（resolv.conf 可用则先用其 nameserver），
 *       彻底绕开 getaddrinfo —— 服务器 DNS 组件故障（php_network_getaddresses:
 *       getaddrinfo failed / System error 等）也能发出邮件
 *
 * v1.44.0 回归修复：恢复 v1.42.0 的 mail_text_from_html / mail_template /
 *   mail_admins 三函数（v1.43.0 重写连接层时意外丢失，导致 Call to undefined
 *   function mail_template() 致命错误），并恢复 mail_send 的 HTML 正文自动
 *   multipart/alternative 双格式能力。四层回退连接层不变。
 *
 * 邮件格式：
 *   - 传入纯文本  → 按 text/plain 发送
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

/** 判断连接错误串是否属于「域名解析类」失败（可由 IP 直连救回） */
function mail_is_dns_error(string $estr): bool
{
    foreach ([
        'php_network_getaddresses', 'getaddrinfo',
        'name or service not known', 'temporary failure in name resolution',
        'no address associated with hostname', 'name does not resolve',
    ] as $needle) {
        if (stripos($estr, $needle) !== false) {
            return true;
        }
    }
    return false;
}

/** 手工解析 /etc/hosts（不依赖系统解析器：nsswitch 不含 files、文件权限异常等场景的救生索） */
function mail_hosts_lookup(string $host): string
{
    $c = @file_get_contents('/etc/hosts');
    if (!is_string($c) || $c === '') {
        return '';
    }
    foreach (preg_split('/\r?\n/', $c) as $line) {
        $line = trim((string)preg_replace('/#.*$/', '', $line));
        if ($line === '') {
            continue;
        }
        $p = preg_split('/\s+/', $line);
        if (count($p) >= 2 && filter_var($p[0], FILTER_VALIDATE_IP) && in_array($host, array_slice($p, 1), true)) {
            return $p[0];
        }
    }
    return '';
}

/** 组装 DNS 服务器清单：/etc/resolv.conf 可读则其 nameserver 优先，再补国内公共 DNS 兜底 */
function mail_dns_servers(): array
{
    $srv = [];
    $c = @file_get_contents('/etc/resolv.conf');
    if (is_string($c) && $c !== '') {
        foreach (preg_split('/\r?\n/', $c) as $line) {
            $line = trim((string)preg_replace('/#.*$/', '', $line));
            if (preg_match('/^nameserver\s+([0-9a-fA-F:.]+)$/', $line, $m)
                && filter_var($m[1], FILTER_VALIDATE_IP)) {
                $srv[] = $m[1];
            }
        }
    }
    foreach (['223.5.5.5', '119.29.29.29', '114.114.114.114'] as $pub) {
        if (!in_array($pub, $srv, true)) {
            $srv[] = $pub;
        }
    }
    return array_slice($srv, 0, 4);
}

/** DNS 报文指针跳读（含压缩指针） */
function mail_dns_skip(string $pkt, int $off): int
{
    $n = strlen($pkt);
    while ($off < $n) {
        $len = ord($pkt[$off]);
        if ($len === 0) {
            return $off + 1;
        }
        if (($len & 0xC0) === 0xC0) {
            return $off + 2;
        }
        $off += $len + 1;
    }
    return $n;
}

/** 纯 PHP UDP DNS A 记录查询：不经过 getaddrinfo / nsswitch / hosts，
 *  服务器解析组件坏掉时的终极自救；失败返回空串 */
function mail_dns_query(string $host, float $timeout = 3.0): string
{
    if (!function_exists('fsockopen') || !filter_var($host, FILTER_VALIDATE_DOMAIN)) {
        return '';
    }
    $txid = random_bytes(2);
    $q = $txid . "\x01\x00\x00\x01\x00\x00\x00\x00\x00\x00"; // RD=1，1 条 question
    foreach (explode('.', $host) as $seg) {
        $q .= chr(strlen($seg)) . $seg;
    }
    $q .= "\x00\x00\x01\x00\x01"; // Type A + Class IN

    foreach (mail_dns_servers() as $ns) {
        $fp = @fsockopen('udp://' . $ns, 53, $eno, $estr, $timeout);
        if (!$fp) {
            continue;
        }
        stream_set_timeout($fp, (int)ceil($timeout));
        @fwrite($fp, $q);
        $resp = (string)@fread($fp, 4096); // UDP 应答单个数据报，一次读够
        fclose($fp);
        if (strlen($resp) < 12 || strncmp($resp, $txid, 2) !== 0) {
            continue;
        }
        $flags = (int)unpack('n', substr($resp, 2, 2))[1];
        if (($flags & 0x8000) === 0 || ($flags & 0x000F) !== 0) {
            continue; // 非应答或 RCODE != 0
        }
        $qd = (int)unpack('n', substr($resp, 4, 2))[1];
        $an = (int)unpack('n', substr($resp, 6, 2))[1];
        $off = 12;
        for ($i = 0; $i < $qd; $i++) {
            $off = mail_dns_skip($resp, $off) + 4;
        }
        for ($i = 0; $i < $an && $off < strlen($resp); $i++) {
            $off = mail_dns_skip($resp, $off);
            if ($off + 10 > strlen($resp)) {
                return '';
            }
            $m = unpack('ntype/nclass/nttl/ndlen', substr($resp, $off, 10));
            $off += 10;
            if ((int)$m['type'] === 1 && (int)$m['ndlen'] === 4 && $off + 4 <= strlen($resp)) {
                return long2ip((int)unpack('N', substr($resp, $off, 4))[1]);
            }
            $off += (int)$m['ndlen'];
        }
    }
    return '';
}

/**
 * 建立到 SMTP 服务器的连接（四层回退，DNS 故障免疫）
 * 证书校验始终按 SMTP 主机名（peer_name）进行，IP 直连不影响 TLS 安全性
 * @return array [resource|false, string 错误信息]
 */
function mail_connect(string $host, int $port, bool $secure, string $directIp = ''): array
{
    $ctx = stream_context_create(['ssl' => [
        'peer_name' => $host,
        'verify_peer' => true,
        'verify_peer_name' => true,
    ]]);
    $open = function (string $target) use ($ctx, $port, $secure): array {
        $remote = ($secure ? 'ssl://' : 'tcp://') . $target . ':' . $port;
        $fp = @stream_socket_client($remote, $eno, $estr, 12, STREAM_CLIENT_CONNECT, $ctx);
        return $fp ? [$fp, ''] : [false, trim($estr) . ' (' . $eno . ')'];
    };

    $tries = [];
    /* 层0：后台直连 IP（配置了就优先用） */
    if ($directIp !== '' && filter_var($directIp, FILTER_VALIDATE_IP)) {
        [$fp, $err] = $open($directIp);
        if ($fp) {
            return [$fp, ''];
        }
        $tries[] = '直连IP ' . $directIp . '：' . $err;
    }
    /* 层1：标准域名连接 */
    [$fp, $err1] = $open($host);
    if ($fp) {
        return [$fp, ''];
    }
    $tries[] = '域名 ' . $host . '：' . $err1;
    if (!mail_is_dns_error($err1)) {
        // 非 DNS 类失败（端口不通 / 防火墙 / 服务异常），换 IP 无意义
        return [false, '连接失败：' . $err1];
    }
    /* 层2：手工读 /etc/hosts */
    $ip = mail_hosts_lookup($host);
    if ($ip !== '' && $ip !== $directIp) {
        [$fp, $err] = $open($ip);
        if ($fp) {
            return [$fp, ''];
        }
        $tries[] = 'hosts记录 ' . $ip . '：' . $err;
    }
    /* 层3：纯 PHP UDP 公共 DNS 查询 */
    $ip = mail_dns_query($host);
    if ($ip !== '' && $ip !== $directIp) {
        [$fp, $err] = $open($ip);
        if ($fp) {
            return [$fp, ''];
        }
        $tries[] = '公共DNS ' . $ip . '：' . $err;
    }
    return [false, '连接失败：服务器域名解析异常，已依次尝试（' . implode('；', $tries)
        . '）。可在后台「邮件设置 → 直连 IP」填入 SMTP 服务器 IP 兜底；或在服务器运行 tools/smtp-doctor.php 一键体检修复。'];
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
    $directIp = trim((string)($ov['direct_ip'] ?? cfg('smtp_direct_ip', '')));

    if ($host === '' || $from === '') {
        return [false, 'SMTP 未配置（主机或发信邮箱为空）'];
    }
    if (!function_exists('fsockopen')) {
        return [false, '服务器缺少 fsockopen，无法发信'];
    }

    $secure = ($port === 465);
    [$fp, $cerr] = mail_connect($host, $port, $secure, $directIp);
    if (!$fp) {
        return [false, $cerr];
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
