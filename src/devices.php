<?php
/**
 * 极简论坛 · 登录设备与会话安全（v1.20.0）
 * 新设备二次验证 / 设备一键管理 / 同时登录数量限制 / 新设备登录提醒
 *
 * 数据文件 data/devices.php：
 *   ['d' => [uid => [ ['fp','name','ua','ip','loc','created','last','sid'], ... ]],
 *    'k' => [uid => ['fps' => [fp => 过期时间], 'sids' => [sid => 过期时间]]]]
 *
 * 踢出设备的双保险：
 *   ① 直接删除服务端会话文件（sess_<sid>），会话立即失效；
 *   ② sid 与设备指纹同时记入 30 天黑名单：
 *      - sid 黑名单：即使极端权限场景下会话文件删除失败，下一次请求也会被 current_user() 强制登出；
 *      - 指纹黑名单：勾选「保持登录」的设备无法借助记住令牌静默重登（本机浏览器 Cookie 同时被清除）；
 *      - 黑名单内的设备用密码主动登录视为本人操作，直接放行并移出黑名单（不要求二次验证、不发提醒）。
 */
defined('APP') or exit('Forbidden');

/* ================= 基础识别 ================= */

/** 当前请求的设备指纹：User-Agent 的 SHA-256（同一浏览器内核升级后视为新设备，与业界惯例一致） */
function dev_fingerprint(): string
{
    return hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

/** UA → 友好设备名（浏览器 · 系统） */
function dev_ua_name(string $ua): string
{
    $ua = trim($ua);
    if ($ua === '') {
        return '未知设备';
    }
    if (stripos($ua, 'MicroMessenger') !== false) {
        $browser = '微信';
    } elseif (stripos($ua, 'MQQBrowser') !== false || stripos($ua, 'QQ/') !== false) {
        $browser = 'QQ';
    } elseif (preg_match('/Edg(e|A|iOS)?\//i', $ua)) {
        $browser = 'Edge';
    } elseif (preg_match('/OPR\/|Opera/i', $ua)) {
        $browser = 'Opera';
    } elseif (preg_match('/Firefox\/(\d+)/i', $ua, $m)) {
        $browser = 'Firefox ' . $m[1];
    } elseif (preg_match('/Chrome\/(\d+)/i', $ua, $m)) {
        $browser = 'Chrome ' . $m[1];
    } elseif (preg_match('/Version\/[\d.]+.*Safari/i', $ua) || stripos($ua, 'Safari') !== false) {
        $browser = 'Safari';
    } elseif (preg_match('/MSIE|Trident/i', $ua)) {
        $browser = 'IE';
    } elseif (preg_match('#^curl/#i', $ua)) {
        $browser = 'curl';
    } elseif (preg_match('#^Wget/#i', $ua)) {
        $browser = 'Wget';
    } else {
        $browser = '浏览器';
    }
    if (preg_match('/Android\s*([\d.]*)/i', $ua, $m)) {
        $os = 'Android' . (trim((string)$m[1]) !== '' ? ' ' . trim((string)$m[1]) : '');
    } elseif (stripos($ua, 'iPhone') !== false) {
        $os = 'iPhone';
    } elseif (stripos($ua, 'iPad') !== false) {
        $os = 'iPad';
    } elseif (preg_match('/Windows NT 10\.0/i', $ua)) {
        $os = 'Windows 10/11';
    } elseif (preg_match('/Windows NT 6\.1/i', $ua)) {
        $os = 'Windows 7';
    } elseif (preg_match('/Windows/i', $ua)) {
        $os = 'Windows';
    } elseif (preg_match('/Mac OS X/i', $ua)) {
        $os = 'macOS';
    } elseif (preg_match('/Linux|X11/i', $ua)) {
        $os = 'Linux';
    } else {
        $os = '';
    }
    return $browser . ($os !== '' ? ' · ' . $os : '');
}

/** 邮箱脱敏：3867342457@qq.com → 3*****7@qq.com（登录验证页展示用） */
function dev_mask_email(string $email): string
{
    $at = strpos($email, '@');
    if ($at === false || $at === 0) {
        return '注册邮箱';
    }
    $local = substr($email, 0, $at);
    $dom = substr($email, $at);
    if (u_strlen($local) <= 2) {
        return $local . '***' . $dom;
    }
    return substr($local, 0, 1) . '*****' . substr($local, -1) . $dom;
}

/* ================= IP 归属地 ================= */

/**
 * IP → 地点（纯 PHP，零依赖）：
 *  - 内网 / 保留地址 → 「本地网络」；
 *  - 公网 IP 走 ip-api.com 免费接口（中文，无需密钥），超时 3 秒；
 *  - 结果落盘缓存：成功 7 天、失败 1 天（失败也缓存，避免接口故障时反复请求拖慢登录）；
 *  - 缓存超 2000 条自动按时间淘汰一半；任何异常一律降级为「未知地区」。
 */
function dev_geo(string $ip): string
{
    $ip = trim($ip);
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return '未知地区';
    }
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return '本地网络';
    }
    $c = Store::read('geocache.php', []);
    $hit = is_array($c) ? ($c[$ip] ?? null) : null;
    if (is_array($hit)) {
        $ttl = !empty($hit['ok']) ? 7 * 86400 : 86400;
        if ((int)($hit['t'] ?? 0) > time() - $ttl) {
            return (string)($hit['loc'] ?? '未知地区');
        }
    }
    $loc = '未知地区';
    if (function_exists('file_get_contents') && ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => [
            'timeout' => 3,
            'header' => "User-Agent: MinimalForum/" . MF_VERSION . "\r\nConnection: close\r\n",
        ]]);
        $raw = @file_get_contents(
            'http://ip-api.com/json/' . rawurlencode($ip) . '?lang=zh-CN&fields=status,country,regionName,city',
            false,
            $ctx
        );
        if (is_string($raw) && $raw !== '') {
            $j = json_decode($raw, true);
            if (is_array($j) && ($j['status'] ?? '') === 'success') {
                $parts = [];
                foreach (['country', 'regionName', 'city'] as $k) {
                    $s = trim((string)($j[$k] ?? ''));
                    if ($s !== '' && !in_array($s, $parts, true)) {
                        $parts[] = $s;
                    }
                }
                if ($parts) {
                    $loc = implode(' ', $parts);
                }
            }
        }
    }
    $lk = Store::lock('geocache');
    $c = Store::read('geocache.php', []);
    if (!is_array($c)) {
        $c = [];
    }
    if (count($c) > 2000) {
        uasort($c, function ($a, $b) {
            return ((int)($a['t'] ?? 0)) <=> ((int)($b['t'] ?? 0));
        });
        $c = array_slice($c, (int)(count($c) / 2), null, true);
    }
    $c[$ip] = ['loc' => $loc, 't' => time(), 'ok' => $loc !== '未知地区' ? 1 : 0];
    Store::write('geocache.php', $c);
    Store::unlock($lk);
    return $loc;
}

/* ================= 配置 ================= */

/** 同一账号最大同时在线设备数（后台「安全防护」页可设，1-50，默认 10） */
function dev_cfg_max(): int
{
    $n = (int)cfg('sess_max', 10);
    return $n >= 1 && $n <= 50 ? $n : 10;
}

/** 新设备二次验证是否对该用户生效：管理员总闸开 OR 用户个人开关开 */
function dev_verify_on(array $u): bool
{
    return (int)cfg('dev_verify', 0) === 1 || !empty($u['dev_verify']);
}

/* ================= 存储层 ================= */

/** 读取全部设备与黑名单数据（损坏 / 旧格式自动按空处理） */
function dev_all(): array
{
    $a = Store::read('devices.php', []);
    if (!is_array($a) || !isset($a['d']) || !is_array($a['d'])) {
        return ['d' => [], 'k' => []];
    }
    if (!isset($a['k']) || !is_array($a['k'])) {
        $a['k'] = [];
    }
    return $a;
}

/** 加锁读取最新数据 → 回调内原地修改 → 原子写回（串行化所有写操作，杜绝覆盖丢失） */
function dev_write(callable $fn): void
{
    $lk = Store::lock('devices');
    $all = dev_all();
    $fn($all);
    Store::write('devices.php', $all);
    Store::unlock($lk);
}

/** 某用户的设备列表（按最后活跃倒序） */
function dev_of(int $uid): array
{
    $a = dev_all();
    $list = is_array($a['d'][$uid] ?? null) ? $a['d'][$uid] : [];
    usort($list, function ($x, $y) {
        return ((int)($y['last'] ?? 0)) <=> ((int)($x['last'] ?? 0));
    });
    return $list;
}

/** 指纹是否为该用户的已知设备（已登记且未被踢出） */
function dev_known(int $uid, string $fp): bool
{
    foreach (dev_of($uid) as $d) {
        if (($d['fp'] ?? '') === $fp) {
            return true;
        }
    }
    return false;
}

/** 会话文件目录（处理 session.save_path 的 "N;/path" 分级格式） */
function dev_sess_dir(): string
{
    $p = (string)session_save_path();
    if ($p === '' || !is_dir($p)) {
        $p = sys_get_temp_dir();
    }
    if (preg_match('/^\d+;(.*)$/', $p, $m)) {
        $p = $m[1];
    }
    return rtrim((string)$p, '/');
}

/** 会话文件绝对路径（sid 已白名单净化） */
function dev_sess_file(string $sid): string
{
    $sid = preg_replace('/[^a-zA-Z0-9,\-]/', '', $sid) ?? '';
    return $sid !== '' ? dev_sess_dir() . '/sess_' . $sid : '';
}

/** 黑名单读取并顺带清理过期项（30 天自动解除） */
function dev_kicked(int $uid): array
{
    $a = dev_all();
    $k = is_array($a['k'][$uid] ?? null) ? $a['k'][$uid] : [];
    $fps = is_array($k['fps'] ?? null) ? $k['fps'] : [];
    $sids = is_array($k['sids'] ?? null) ? $k['sids'] : [];
    $now = time();
    $changed = false;
    foreach ($fps as $f => $exp) {
        if ((int)$exp < $now) {
            unset($fps[$f]);
            $changed = true;
        }
    }
    foreach ($sids as $s => $exp) {
        if ((int)$exp < $now) {
            unset($sids[$s]);
            $changed = true;
        }
    }
    if ($changed) {
        dev_write(function (array &$all) use ($uid, $fps, $sids): void {
            if (empty($fps) && empty($sids)) {
                unset($all['k'][$uid]);
            } else {
                $all['k'][$uid] = ['fps' => $fps, 'sids' => $sids];
            }
        });
    }
    return ['fps' => $fps, 'sids' => $sids];
}

/** 设备指纹是否被踢（remember 自动重登拦截用） */
function dev_is_kicked(int $uid, string $fp): bool
{
    return isset(dev_kicked($uid)['fps'][$fp]);
}

/** 当前会话 sid 是否被踢（每次请求会话校验用，双保险之二） */
function dev_sid_killed(int $uid): bool
{
    if (session_id() === '') {
        return false;
    }
    return isset(dev_kicked($uid)['sids'][session_id()]);
}

/* ================= 登记与踢出 ================= */

/**
 * 登录成功 / 记住令牌自动恢复后登记设备。
 * $returning：true = 回归设备（指纹在黑名单内用密码重新登录，或老设备），不发新设备提醒。
 * 新设备 → 站内通知提醒（时间 / IP / 地点 / 设备 + 改密码与踢出指引）+ 日志；
 * 超出并发上限 → 按「最后活跃」从旧到新自动下线多出的设备（当前设备永不自踢），并逐一通知。
 */
function dev_login_register(int $uid, array $user, string $ip, bool $returning): void
{
    $fp = dev_fingerprint();
    $ua = cut_str((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 200);
    $now = time();
    $loc = dev_geo($ip); // 网络查询放锁外
    $sid = session_id();
    $isNew = false;
    $evicted = [];

    dev_write(function (array &$all) use ($uid, $fp, $ua, $ip, $loc, $now, $sid, $returning, &$isNew, &$evicted): void {
        $mine = is_array($all['d'][$uid] ?? null) ? $all['d'][$uid] : [];

        /* 清理僵尸条目：非当前设备、10 分钟无活跃且会话文件已不存在的（会话被 GC 或手动清理过） */
        $sdir = dev_sess_dir();
        $mine = array_values(array_filter($mine, function (array $d) use ($now, $sdir, $fp): bool {
            if (($d['fp'] ?? '') === $fp) {
                return true;
            }
            if ($now - (int)($d['last'] ?? 0) < 600) {
                return true;
            }
            $f = dev_sess_file((string)($d['sid'] ?? ''));
            return $f === '' ? true : @file_exists($f);
        }));

        $found = false;
        foreach ($mine as &$d) {
            if (($d['fp'] ?? '') === $fp) {
                $found = true;
                $d['last'] = $now;
                $d['ip'] = $ip;
                $d['loc'] = $loc;
                $d['sid'] = $sid;
                $d['ua'] = $ua;
                $d['name'] = dev_ua_name($ua);
                break;
            }
        }
        unset($d);
        if (!$found) {
            $isNew = true;
            $mine[] = ['fp' => $fp, 'name' => dev_ua_name($ua), 'ua' => $ua, 'ip' => $ip, 'loc' => $loc,
                'created' => $now, 'last' => $now, 'sid' => $sid];
        }

        /* 同时登录数量限制：当前设备无条件保留，其余按「最后活跃」新→旧保留 max-1 台，超出的自动下线 */
        $max = dev_cfg_max();
        if (count($mine) > $max) {
            $curEntry = null;
            $rest = [];
            foreach ($mine as $d) {
                if (($d['fp'] ?? '') === $fp && $curEntry === null) {
                    $curEntry = $d;
                } else {
                    $rest[] = $d;
                }
            }
            usort($rest, function ($x, $y) {
                return ((int)($y['last'] ?? 0)) <=> ((int)($x['last'] ?? 0));
            });
            $mine = array_slice($rest, 0, max(0, $max - 1));
            foreach (array_slice($rest, max(0, $max - 1)) as $d) {
                $sidK = preg_replace('/[^a-zA-Z0-9,\-]/', '', (string)($d['sid'] ?? '')) ?? '';
                if ($sidK !== '') {
                    @unlink($sdir . '/sess_' . $sidK);
                    $all['k'][$uid]['sids'][$sidK] = $now + 30 * 86400;
                }
                $all['k'][$uid]['fps'][(string)($d['fp'] ?? '')] = $now + 30 * 86400;
                $evicted[] = $d;
            }
            if ($curEntry !== null) {
                $mine[] = $curEntry;
            }
        }

        /* 回归设备（密码登录）移出指纹黑名单 */
        if ($returning || !$isNew) {
            unset($all['k'][$uid]['fps'][$fp]);
            if (isset($all['k'][$uid]) && empty($all['k'][$uid]['fps']) && empty($all['k'][$uid]['sids'])) {
                unset($all['k'][$uid]);
            }
        }

        $all['d'][$uid] = $mine;
    });

    foreach ($evicted as $d) {
        notify_add($uid, 'device_kick', '一台设备已被自动下线',
            '您在「' . (string)($d['name'] ?? '未知设备') . '」登录的设备（IP ' . (string)($d['ip'] ?? '-') . '，'
            . (string)($d['loc'] ?? '未知地区') . '）因本站同时在线设备数已达上限（' . dev_cfg_max() . ' 台）被系统自动下线。'
            . '如果不是本人操作，请尽快修改密码并检查账号安全。',
            'p=settings#devices');
        log_action('device_kick', '系统自动下线设备「' . (string)($d['name'] ?? '?') . '」（IP ' . (string)($d['ip'] ?? '-') . '）：超出同时在线上限', $uid, (string)($user['name'] ?? ''));
    }

    if ($isNew && !$returning) {
        $uname = (string)($user['name'] ?? '');
        notify_add($uid, 'device_new', '新设备登录提醒',
            '您的账号于 ' . date('Y-m-d H:i') . ' 在新设备「' . dev_ua_name($ua) . '」上登录'
            . '（IP ' . $ip . '，' . $loc . '）。如果不是本人操作，请尽快修改密码，'
            . '并到「个人设置 → 登录设备」将该设备下线。',
            'p=settings#devices');
        log_action('device_new', '新设备登录「' . dev_ua_name($ua) . '」（IP ' . $ip . ' · ' . $loc . '）', $uid, $uname);
    }
}

/**
 * 踢出指定设备（用户手动 / 后台）：
 * 删会话文件 + sid/指纹进黑名单 30 天 + 移除登记。返回是否找到并踢出；$entry 回传设备信息。
 */
function dev_kick_fp(int $uid, string $fp, ?array &$entry = null): bool
{
    $ok = false;
    dev_write(function (array &$all) use ($uid, $fp, &$ok, &$entry): void {
        foreach (($all['d'][$uid] ?? []) as $i => $d) {
            if (($d['fp'] ?? '') === $fp) {
                $entry = $d;
                $sid = preg_replace('/[^a-zA-Z0-9,\-]/', '', (string)($d['sid'] ?? '')) ?? '';
                if ($sid !== '') {
                    @unlink(dev_sess_dir() . '/sess_' . $sid);
                    $all['k'][$uid]['sids'][$sid] = time() + 30 * 86400;
                }
                $all['k'][$uid]['fps'][$fp] = time() + 30 * 86400;
                unset($all['d'][$uid][$i]);
                $all['d'][$uid] = array_values($all['d'][$uid]);
                if (isset($all['k'][$uid]) && empty($all['k'][$uid]['fps']) && empty($all['k'][$uid]['sids'])) {
                    unset($all['k'][$uid]);
                }
                $ok = true;
                break;
            }
        }
    });
    return $ok;
}

/** 一键下线其他全部设备，返回下线数量 */
function dev_kick_others(int $uid): int
{
    $cur = dev_fingerprint();
    $n = 0;
    foreach (dev_of($uid) as $d) {
        if (($d['fp'] ?? '') !== $cur) {
            $entry = null;
            if (dev_kick_fp($uid, (string)($d['fp'] ?? ''), $entry)) {
                $n++;
            }
        }
    }
    return $n;
}
