<?php
/**
 * 极简论坛 · 用户等级系统（v1.25.0）
 * 等级定义存储于 data/levels.php；用户等级 = 手动指派（users.php 的 level 键）优先，
 * 未指派（level=0 或缺键）时按发帖数自动匹配「满足 min 的最高等级」。
 * 管理员不受等级限制（一切权限恒通过）；内置 3 个默认等级不可删除。
 */
defined('APP') or exit('Forbidden');

/** 权限项定义（后台勾选矩阵）：key => [名称, 说明] */
const LEVEL_PERMS = [
    'post'     => ['发新帖', '不勾选则该等级用户无法发布新帖（各板块还可另设发帖权限，两者同时满足才能发）'],
    'reply'    => ['发表回复', '不勾选则该等级用户无法回复任何帖子'],
    'edit_own' => ['编辑自己的内容', '发布后 15 分钟内可编辑自己的帖子 / 回复（管理员不限时）'],
    'del_own'  => ['删除自己的内容', '允许该等级用户删除自己的帖子 / 回复'],
    'sig'      => ['使用个人签名', '不勾选则该等级用户的个人设置不显示签名输入框（已填写的签名保留但不再展示）'],
    'like'     => ['点赞', '不勾选则该等级用户无法点赞 / 取消点赞'],
];

/** 默认 3 个内置等级（built_in=1：可改名 / 改条件 / 改权限，不可删除） */
function levels_default(): array
{
    return [
        ['id' => 1, 'name' => '新手上路', 'min' => 0,  'built_in' => 1, 'interval' => 0,
         'perms' => ['post' => 1, 'reply' => 1, 'edit_own' => 1, 'del_own' => 0, 'sig' => 0, 'like' => 1]],
        ['id' => 2, 'name' => '活跃用户', 'min' => 10, 'built_in' => 1, 'interval' => 0,
         'perms' => ['post' => 1, 'reply' => 1, 'edit_own' => 1, 'del_own' => 1, 'sig' => 1, 'like' => 1]],
        ['id' => 3, 'name' => '资深用户', 'min' => 50, 'built_in' => 1, 'interval' => 0,
         'perms' => ['post' => 1, 'reply' => 1, 'edit_own' => 1, 'del_own' => 1, 'sig' => 1, 'like' => 1]],
    ];
}

/** 读全部等级；文件不存在 / 为空时初始化默认 3 等级并写回（旧站升级零迁移） */
function levels_all(): array
{
    static $memo = null;
    if (is_array($memo)) {
        return $memo;
    }
    $lv = Store::read('levels.php', []);
    if (!is_array($lv) || !$lv) {
        $lv = levels_default();
        levels_save($lv);
    }
    $memo = array_values(array_filter($lv, 'is_array'));
    return $memo;
}

/** 全量保存等级（加锁 + 原子写） */
function levels_save(array $all): void
{
    $lk = Store::lock('levels');
    Store::write('levels.php', array_values($all));
    Store::unlock($lk);
}

/** 等级按升级条件升序（min 相同按 id） */
function levels_sorted(): array
{
    $lv = levels_all();
    usort($lv, function ($a, $b) {
        return [(int)($a['min'] ?? 0), (int)($a['id'] ?? 0)] <=> [(int)($b['min'] ?? 0), (int)($b['id'] ?? 0)];
    });
    return $lv;
}

function level_get(int $id): ?array
{
    foreach (levels_all() as $l) {
        if ((int)($l['id'] ?? 0) === $id) {
            return $l;
        }
    }
    return null;
}

/** 等级序号（Lv N，按升级条件升序的序位，从 1 起） */
function level_no(int $id): int
{
    foreach (levels_sorted() as $i => $l) {
        if ((int)($l['id'] ?? 0) === $id) {
            return $i + 1;
        }
    }
    return 0;
}

/** 当前用户生效的等级行；无等级数据时返回 null */
function user_level(?array $u): ?array
{
    if (!$u || empty($u['id'])) {
        return null;
    }
    /* 管理员展示为最高等级（判定处 level_can 对管理员恒 true，不受此影响） */
    if (!empty($u['admin'])) {
        $sorted = levels_sorted();
        return $sorted ? $sorted[count($sorted) - 1] : null;
    }
    $assigned = (int)($u['level'] ?? 0);
    if ($assigned > 0 && ($l = level_get($assigned))) {
        return $l; /* 管理员手动指派优先 */
    }
    $posts = max(0, (int)($u['threads'] ?? 0));
    $hit = null;
    foreach (levels_sorted() as $l) {
        if ($posts >= (int)($l['min'] ?? 0)) {
            $hit = $l; /* 满足条件的最高等级（sorted 升序，不断覆盖） */
        }
    }
    if ($hit === null) {
        $sorted = levels_sorted();
        $hit = $sorted[0] ?? null; /* 兜底：最低等级 */
    }
    return $hit;
}

/** 等级权限判定：管理员恒 true；游客恒 false；其余看等级行 perms 勾选 */
function level_can(?array $u, string $perm): bool
{
    if ($u === null) {
        return false;
    }
    if (!empty($u['admin'])) {
        return true;
    }
    $l = user_level($u);
    if (!$l) {
        return false;
    }
    $perms = is_array($l['perms'] ?? null) ? $l['perms'] : [];
    return !empty($perms[$perm]);
}

/** 等级发帖间隔覆盖（秒；0 = 使用全局「发帖间隔」） */
function level_interval(?array $u): int
{
    if (!$u || !empty($u['admin'])) {
        return 0;
    }
    $l = user_level($u);
    if (!$l) {
        return 0;
    }
    return max(0, min(3600, (int)($l['interval'] ?? 0)));
}

/** 等级徽章（管理员优先显示管理员徽章）；无等级数据时显示「注册用户」 */
function level_badge(?array $u): string
{
    if (!$u || empty($u['id'])) {
        return '';
    }
    if (!empty($u['admin'])) {
        return '<span class="badge badge-accent">' . e(role_name($u)) . '</span>';
    }
    $l = user_level($u);
    if (!$l) {
        return '<span class="badge">' . e(role_name($u)) . '</span>';
    }
    $no = level_no((int)$l['id']);
    return '<span class="badge badge-info" title="' . e((string)($l['name'] ?? '')) . '">Lv' . $no . ' ' . e((string)($l['name'] ?? '')) . '</span>';
}

/** 按用户 id 取徽章（带请求级缓存；帖子页回复列表逐条渲染时避免重复查询） */
function level_badge_by_uid(int $uid): string
{
    static $c = [];
    if (!isset($c[$uid])) {
        $c[$uid] = $uid > 0 ? level_badge(user_by_id($uid)) : '';
    }
    return $c[$uid];
}

/**
 * 个人设置页的等级进度卡（当前等级 / 发帖数 / 距下一级还差多少帖；管理员显示说明文字）
 */
function level_progress_html(array $u): string
{
    if (!empty($u['admin'])) {
        return '<p class="hint">管理员账号不受等级限制，拥有全部权限。</p>';
    }
    $l = user_level($u);
    if (!$l) {
        return '';
    }
    $no = level_no((int)$l['id']);
    $posts = max(0, (int)($u['threads'] ?? 0));
    $manual = (int)($u['level'] ?? 0) > 0;
    $html = '<p class="lv-now"><span class="badge badge-info">Lv' . $no . ' ' . e((string)($l['name'] ?? '')) . '</span>' .
        '<span class="muted"> 已发帖 ' . $posts . ' 篇' . ($manual ? '（管理员指派）' : '') . '</span></p>';
    /* 距下一级 */
    $sorted = levels_sorted();
    $next = null;
    foreach ($sorted as $x) {
        if ((int)($x['min'] ?? 0) > $posts && (int)($x['id'] ?? 0) !== (int)($l['id'] ?? 0)) {
            $next = $x;
            break;
        }
    }
    if ($next) {
        $need = max(1, (int)$next['min'] - $posts);
        $pct = (int)$next['min'] > 0 ? min(100, (int)floor($posts / (int)$next['min'] * 100)) : 100;
        $html .= '<div class="lv-progress"><div class="lv-bar"><i style="width:' . $pct . '%"></i></div>' .
            '<span class="hint">再发 ' . $need . ' 帖升级为「' . e((string)($next['name'] ?? '')) . '」（Lv' . level_no((int)$next['id']) . '，需 ' . (int)$next['min'] . ' 帖）</span></div>';
    } else {
        $html .= '<p class="hint">已是最高等级，感谢你的持续活跃！</p>';
    }
    return $html;
}
