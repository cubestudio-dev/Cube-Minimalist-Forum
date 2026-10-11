<?php
/**
 * 极简论坛 · 职员角色与会员头衔（v1.27.0）
 * v1.27.0 起移除「成长等级」系统（levels.php 已删除），简化为两套轻量体系：
 *   - 职员角色：站点团队的层级身份（普通用户 < 审核员 < 超级管理员），决定管理权与后台可见范围；
 *   - 会员头衔：纯展示的社区称号（按发帖数自动晋升，无任何权限含义），可在「功能」页一键开关。
 *
 * 角色能力（固定层级，简单可靠）：
 *   普通用户   —— 正常参与讨论；
 *   审核员     —— 内容审核：前台管理操作（锁定 / 置顶 / 移版 / 删除）+ 后台「内容 / AI 队列 /
 *                 人工待审 / 举报记录」四个审核标签页；不能进设置与其他后台页；
 *   超级管理员 —— 坛主，一切权限，含「用户」页的角色指派。
 *
 * 兼容（零迁移）：
 *   - v1.26 的 role='admin'（管理员）自动视为超级管理员（权限不丢，站长可再调整为审核员）；
 *   - v1.25 及更早的「管理员」布尔字段（users.php 的 admin 键）视为超级管理员（即站长）；
 *   - 指派角色时同步 admin 布尔（审核员 / 超级管理员 = 1），旧 is_admin() 检查继续生效。
 */
defined('APP') or exit('Forbidden');

/** 角色定义：key => [名称, 层级]（层级越大权限越高；admin 为 v1.26 历史值，读取时映射为 super） */
const MF_ROLES = [
    'user'  => ['普通用户', 0],
    'mod'   => ['审核员', 1],
    'super' => ['超级管理员', 2],
];

/** 会员头衔（纯展示，按发帖数自动晋升；功能页「会员头衔」开关控制是否显示） */
const MF_TITLES = [
    ['min' => 0,   'name' => '新成员'],
    ['min' => 10,  'name' => '正式成员'],
    ['min' => 50,  'name' => '活跃成员'],
    ['min' => 200, 'name' => '资深成员'],
    ['min' => 500, 'name' => '社区元老'],
];

/** 用户当前角色 key：role 字段优先（admin 历史值映射 super）；缺省（旧数据）时 admin 布尔 = 超级管理员 */
function user_role(?array $u): string
{
    if (!$u || empty($u['id'])) {
        return 'user';
    }
    $r = (string)($u['role'] ?? '');
    if ($r === 'admin') {
        return 'super'; /* v1.26 历史值：管理员并入超级管理员 */
    }
    if ($r !== '' && isset(MF_ROLES[$r])) {
        return $r;
    }
    return !empty($u['admin']) ? 'super' : 'user';
}

/** 角色层级比较：当前用户是否达到 $min 角色（user/mod/super） */
function role_at_least(?array $u, string $min): bool
{
    if (!isset(MF_ROLES[$min])) {
        return false;
    }
    $have = user_role($u);
    return (int)MF_ROLES[$have][1] >= (int)MF_ROLES[$min][1];
}

/** 当前登录用户是否为审核团队（审核员及以上；游客恒否） */
function is_mod(): bool
{
    return role_at_least(current_user(), 'mod');
}

/** 当前登录用户是否为超级管理员（坛主） */
function is_super(): bool
{
    return role_at_least(current_user(), 'super');
}

/** 页面内展示用角色名（带翻译） */
function role_name(array $u): string
{
    $r = user_role($u);
    return t((string)(MF_ROLES[$r][0] ?? '普通用户'));
}

/** 角色徽章（超级管理员 / 审核员 = 主题色，普通用户 = 朴素） */
function role_badge(array $u): string
{
    $r = user_role($u);
    if ($r === 'user') {
        return '<span class="badge">' . e(t('普通用户')) . '</span>';
    }
    return '<span class="badge badge-accent">' . e(t((string)MF_ROLES[$r][0])) . '</span>';
}

/** 指派角色时同步写入的字段：审核员 / 超级管理员保持 admin 布尔为 1（旧检查全兼容） */
function role_sync_fields(string $role): array
{
    if (!isset(MF_ROLES[$role])) {
        $role = 'user';
    }
    return [
        'role'  => $role,
        'admin' => $role === 'user' ? 0 : 1,
    ];
}

/* ================= 会员头衔（v1.27.0，替代成长等级） ================= */

/** 用户当前头衔（按发帖数自动匹配；管理员账号同样按发帖数展示，头衔无权限含义） */
function user_title(array $u): array
{
    $posts = max(0, (int)($u['threads'] ?? 0));
    $hit = MF_TITLES[0];
    foreach (MF_TITLES as $x) {
        if ($posts >= (int)$x['min']) {
            $hit = $x; /* 升序遍历，不断覆盖为满足条件的最高头衔 */
        }
    }
    return $hit;
}

/** 下一头衔（用于个人设置页的成长提示；已到顶返回 null） */
function next_title(array $u): ?array
{
    $posts = max(0, (int)($u['threads'] ?? 0));
    foreach (MF_TITLES as $x) {
        if ((int)$x['min'] > $posts) {
            return $x;
        }
    }
    return null;
}

/**
 * 用户徽章（前台列表 / 帖子页 / 后台用户列表统一入口，替代旧 level_badge）：
 * 职员（审核员 / 超级管理员）显示角色徽章；普通用户在「会员头衔」开启时显示头衔徽章。
 */
function badge_for(?array $u): string
{
    if (!$u || empty($u['id'])) {
        return '';
    }
    if (user_role($u) !== 'user') {
        return role_badge($u);
    }
    if (!feat_on('titles')) {
        return '';
    }
    $t = user_title($u);
    return '<span class="badge badge-info" title="' . e(t('会员头衔')) . '">' . e(t((string)$t['name'])) . '</span>';
}

/** 按用户 id 取徽章（带请求级缓存；帖子页回复列表逐条渲染时避免重复查询） */
function badge_for_uid(int $uid): string
{
    static $c = [];
    if (!isset($c[$uid])) {
        $c[$uid] = $uid > 0 ? badge_for(user_by_id($uid)) : '';
    }
    return $c[$uid];
}

/** 个人设置页的头衔说明（当前头衔 / 发帖数 / 距下一头衔还差多少帖） */
function title_progress_html(array $u): string
{
    if (!feat_on('titles')) {
        return '<p class="hint">会员头衔功能未开启。</p>';
    }
    $t = user_title($u);
    $posts = max(0, (int)($u['threads'] ?? 0));
    $html = '<p class="lv-now"><span class="badge badge-info">' . e(t((string)$t['name'])) . '</span>' .
        '<span class="muted"> ' . e(t('已发帖')) . ' ' . $posts . ' ' . e(t('篇')) . '</span></p>';
    $next = next_title($u);
    if ($next) {
        $need = max(1, (int)$next['min'] - $posts);
        $pct = (int)$next['min'] > 0 ? min(100, (int)floor($posts / (int)$next['min'] * 100)) : 100;
        $html .= '<div class="lv-progress"><div class="lv-bar"><i style="width:' . $pct . '%"></i></div>' .
            '<span class="hint">' . e(t('再发')) . ' ' . $need . ' ' . e(t('帖升级为「')) . e(t((string)$next['name'])) . '」</span></div>';
    } else {
        $html .= '<p class="hint">' . e(t('已是最高头衔，感谢你的持续活跃！')) . '</p>';
    }
    return $html;
}
