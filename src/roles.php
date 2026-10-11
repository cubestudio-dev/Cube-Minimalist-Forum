<?php
/**
 * 极简论坛 · 职员角色体系（v1.26.0）
 * 与「成长等级」（levels.php）正交的两套体系：
 *   - 成长等级：按发帖数自动晋升 / 管理员手动指派，决定普通用户能用哪些功能；
 *   - 职员角色：站点管理团队的层级身份（普通用户 < 审核员 < 管理员 < 超级管理员），
 *     决定内容管理权与后台可见范围。
 *
 * 角色能力（固定层级，简单可靠）：
 *   普通用户   —— 正常参与（受成长等级权限矩阵约束）；
 *   审核员     —— 内容审核：前台管理操作（锁定 / 置顶 / 移版 / 删除）+ 后台「内容 / AI 队列 /
 *                 人工待审 / 举报记录」四个审核标签页；不能进设置与其他后台页；
 *   管理员     —— 全部后台（与旧版「管理员」一致），但不能指派 / 修改他人角色；
 *   超级管理员 —— 一切权限，含「用户」页的角色指派。
 *
 * 兼容：v1.25 及更早的「管理员」布尔字段（users.php 的 admin 键）视为超级管理员（即站长）；
 * 指派角色时自动同步 admin 布尔（管理员 / 超级管理员 = 1），保证全部旧 is_admin() 检查继续生效。
 */
defined('APP') or exit('Forbidden');

/** 角色定义：key => [名称, 层级]（层级越大权限越高） */
const MF_ROLES = [
    'user'  => ['普通用户', 0],
    'mod'   => ['审核员', 1],
    'admin' => ['管理员', 2],
    'super' => ['超级管理员', 3],
];

/** 用户当前角色 key：role 字段优先；缺省（旧数据）时 admin 布尔 = 超级管理员 */
function user_role(?array $u): string
{
    if (!$u || empty($u['id'])) {
        return 'user';
    }
    $r = (string)($u['role'] ?? '');
    if ($r !== '' && isset(MF_ROLES[$r])) {
        return $r;
    }
    return !empty($u['admin']) ? 'super' : 'user';
}

/** 角色层级比较：当前用户是否达到 $min 角色（user/mod/admin/super） */
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

/** 当前登录用户是否为超级管理员 */
function is_super(): bool
{
    return role_at_least(current_user(), 'super');
}

/** 页面内展示用角色名（旧 role_name 的角色化升级，带翻译） */
function role_name(array $u): string
{
    $r = user_role($u);
    return t((string)(MF_ROLES[$r][0] ?? '普通用户'));
}

/** 角色徽章（管理员 / 超级管理员 = accent，审核员 = info，普通用户 = 朴素） */
function role_badge(array $u): string
{
    $r = user_role($u);
    if ($r === 'user') {
        return '<span class="badge">' . e(t('普通用户')) . '</span>';
    }
    $cls = $r === 'mod' ? 'badge-info' : 'badge-accent';
    return '<span class="badge ' . $cls . '">' . e(t((string)MF_ROLES[$r][0])) . '</span>';
}

/** 指派角色时同步写入的字段：管理员 / 超级管理员保持 admin 布尔为 1（旧检查全兼容） */
function role_sync_fields(string $role): array
{
    if (!isset(MF_ROLES[$role])) {
        $role = 'user';
    }
    return [
        'role'  => $role,
        'admin' => ($role === 'admin' || $role === 'super') ? 1 : 0,
    ];
}
