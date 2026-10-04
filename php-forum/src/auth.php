<?php
/**
 * 极简论坛 · 用户 / 会话 / 邮箱验证码
 */
defined('APP') or exit('Forbidden');

/* ---------------- 用户读写 ---------------- */
function user_all(): array
{
    $us = Store::read('users.php', []);
    return is_array($us) ? $us : [];
}

function user_by_id(int $id): ?array
{
    foreach (user_all() as $u) {
        if ((int)($u['id'] ?? 0) === $id) {
            return $u;
        }
    }
    return null;
}

function user_by_name(string $n): ?array
{
    $n = strtolower(trim($n));
    if ($n === '') {
        return null;
    }
    foreach (user_all() as $u) {
        if (strtolower((string)($u['name'] ?? '')) === $n) {
            return $u;
        }
    }
    return null;
}

function user_by_email(string $m): ?array
{
    $m = strtolower(trim($m));
    if ($m === '') {
        return null;
    }
    foreach (user_all() as $u) {
        if (strtolower((string)($u['email'] ?? '')) === $m) {
            return $u;
        }
    }
    return null;
}

/** 在 users 锁内更新某用户字段 */
function user_update(int $id, array $fields): void
{
    $lk = Store::lock('users');
    $us = user_all();
    foreach ($us as &$u) {
        if ((int)($u['id'] ?? 0) === $id) {
            foreach ($fields as $k => $v) {
                $u[$k] = $v;
            }
        }
    }
    unset($u);
    Store::write('users.php', $us);
    Store::unlock($lk);
}

/** 计数器增减（被赞数/被回复数/帖子数） */
function user_bump(int $id, string $key, int $delta): void
{
    if ($id <= 0) {
        return;
    }
    $lk = Store::lock('users');
    $us = user_all();
    foreach ($us as &$u) {
        if ((int)($u['id'] ?? 0) === $id) {
            $u[$key] = max(0, (int)($u[$key] ?? 0) + $delta);
        }
    }
    unset($u);
    Store::write('users.php', $us);
    Store::unlock($lk);
}

function user_create(string $name, string $email, string $passHash, bool $admin = false): int
{
    $lk = Store::lock('users');
    $us = user_all();
    $id = 1;
    foreach ($us as $x) {
        $id = max($id, (int)($x['id'] ?? 0) + 1);
    }
    $us[] = [
        'id' => $id, 'name' => $name, 'email' => $email, 'pass' => $passHash, 'bio' => '',
        'admin' => $admin, 'created' => time(), 'banned' => 0, 'mute_until' => 0,
        'last_post' => 0, 'likes_recv' => 0, 'replies_recv' => 0, 'threads' => 0, 'remember' => '',
    ];
    Store::write('users.php', $us);
    Store::unlock($lk);
    return $id;
}

/** 页面内展示用用户名（带请求级缓存） */
function uname(int $id): string
{
    static $c = [];
    if (!isset($c[$id])) {
        $u = user_by_id($id);
        $c[$id] = $u ? (string)$u['name'] : '已注销';
    }
    return $c[$id];
}

function role_name(array $u): string
{
    return !empty($u['admin']) ? '管理员' : '注册用户';
}

function mute_left(array $u): int
{
    return max(0, (int)($u['mute_until'] ?? 0) - time());
}

/* ---------------- 会话 ---------------- */
function current_user(): ?array
{
    static $u = false;
    if ($u !== false) {
        return $u;
    }
    $u = null;
    if (!empty($_SESSION['uid'])) {
        $cand = user_by_id((int)$_SESSION['uid']);
        if ($cand && !empty($cand['banned'])) {
            auth_logout();
            flash('err', '账号已被封禁，会话已终止');
        } elseif ($cand) {
            $u = $cand;
        }
    }
    if ($u === null && !empty($_COOKIE['mf_remember']) && is_string($_COOKIE['mf_remember']) && strlen($_COOKIE['mf_remember']) === 64) {
        $hash = hash('sha256', $_COOKIE['mf_remember']);
        foreach (user_all() as $cand) {
            if (!empty($cand['remember']) && hash_equals((string)$cand['remember'], $hash)) {
                if (empty($cand['banned'])) {
                    $u = $cand;
                    $_SESSION['uid'] = (int)$cand['id'];
                }
                break;
            }
        }
    }
    return $u;
}

function is_admin(): bool
{
    $u = current_user();
    return $u !== null && !empty($u['admin']);
}

function auth_login(array $user, bool $remember): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$user['id'];
    if ($remember) {
        $tok = bin2hex(random_bytes(32));
        user_update((int)$user['id'], ['remember' => hash('sha256', $tok)]);
        setcookie('mf_remember', $tok, ['expires' => time() + 2592000, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    }
}

function auth_logout(): void
{
    if (!empty($_SESSION['uid'])) {
        user_update((int)$_SESSION['uid'], ['remember' => '']);
    }
    unset($_SESSION['uid']);
    setcookie('mf_remember', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
}

/** 需登录；$next 传回跳目标查询串（如 'p=new'） */
function require_login(string $next = ''): array
{
    $u = current_user();
    if (!$u) {
        flash('err', '请先登录');
        redirect(u('p=login' . ($next !== '' ? '&next=' . urlencode($next) : '')));
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if (empty($u['admin'])) {
        flash('err', '无权访问后台');
        redirect(u('p=home'));
    }
    return $u;
}

/* ---------------- 邮箱验证码（注册 / 改密 / 找回共用） ----------------
 * 规则：6 位数字；有效期 5 分钟；同邮箱 60 秒限发一次；错误 5 次作废；一次性使用
 */
function code_send(string $email, string $purpose, string &$err = ''): bool
{
    $email = strtolower(trim($email));
    if (!valid_email($email)) {
        $err = '邮箱格式不正确';
        return false;
    }
    $lk = Store::lock('codes');
    $all = Store::read('codes.php', []);
    foreach ($all as $m => $c) {
        if (!is_array($c) || ($c['expires'] ?? 0) < time()) {
            unset($all[$m]);
        }
    }
    $c = $all[$email] ?? null;
    if (is_array($c) && ($c['sent'] ?? 0) > time() - 60) {
        $err = '发送过于频繁，请 ' . (60 - (time() - (int)$c['sent'])) . ' 秒后再试';
        Store::unlock($lk);
        return false;
    }
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $all[$email] = ['code' => $code, 'purpose' => $purpose, 'expires' => time() + 300, 'sent' => time(), 'attempts' => 0];
    Store::write('codes.php', $all);
    Store::unlock($lk);

    $site = (string)cfg('site_name', '论坛');
    [$ok, $smtpErr] = mail_send(
        $email,
        $site . ' · 邮箱验证码',
        "您的验证码是：{$code}\r\n\r\n验证码 5 分钟内有效，请勿泄露给他人。\r\n若非本人操作，请忽略本邮件。\r\n\r\n—— {$site}"
    );
    if (!$ok) {
        $err = '邮件发送失败：' . $smtpErr;
        return false;
    }
    return true;
}

function code_verify(string $email, string $purpose, string $code, string &$err = ''): bool
{
    $email = strtolower(trim($email));
    $code = trim($code);
    $lk = Store::lock('codes');
    $all = Store::read('codes.php', []);
    $c = $all[$email] ?? null;
    $fail = function (string $msg) use (&$all, $email, $lk): bool {
        if ($msg !== '__keep__') {
            Store::write('codes.php', $all);
        }
        Store::unlock($lk);
        return false;
    };
    if (!is_array($c) || ($c['purpose'] ?? '') !== $purpose) {
        $err = '请先获取验证码';
        return $fail('');
    }
    if (($c['expires'] ?? 0) < time()) {
        unset($all[$email]);
        $err = '验证码已过期，请重新获取';
        return $fail('');
    }
    if (($c['attempts'] ?? 0) >= 5) {
        unset($all[$email]);
        $err = '错误次数过多，请重新获取验证码';
        return $fail('');
    }
    if (!is_string($code) || $code === '' || !hash_equals((string)$c['code'], $code)) {
        $all[$email]['attempts'] = ($c['attempts'] ?? 0) + 1;
        $left = 5 - (int)$all[$email]['attempts'];
        $err = '验证码错误' . ($left > 0 ? '，还可尝试 ' . $left . ' 次' : '，次数已用完');
        return $fail('');
    }
    unset($all[$email]); // 一次性使用
    Store::write('codes.php', $all);
    Store::unlock($lk);
    return true;
}
