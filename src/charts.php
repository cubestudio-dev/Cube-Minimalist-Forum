<?php
/**
 * 极简论坛 · 后台统计图表（纯 PHP + CSS 手写，零 JS / 零第三方库）
 * - chart_bars_h()   横向条形图（Top N 排行：IP 请求榜 / 归属地分布 / 动作分布 / 事件类型 / 存储构成…）
 * - chart_trend_days() 近 N 天「请求 / 拦截」趋势柱状图（拦截以红色段叠加在请求柱底部）
 * 设计约束：只输出语义化 HTML + 内联宽度/高度百分比，配色走主题 CSS 变量（深浅色自动适配）；
 *           数值通过原生 title 属性悬停可见，并用 aria-label 提供无障碍描述。
 */
defined('APP') or exit('Forbidden');

/**
 * 横向条形图
 * @param array  $items [label => value]（调用方自行排好序、截好 Top N）
 * @param array  $opt   fmt: callable(int|string) 数据值展示格式（默认原样）；color: ''|'warn'|'danger' 条形颜色；label: 无障碍描述；tip: callable(label,value) 悬停提示
 */
function chart_bars_h(array $items, array $opt = []): string
{
    if (!$items) {
        return '';
    }
    $fmt = isset($opt['fmt']) && is_callable($opt['fmt']) ? $opt['fmt'] : null;
    $tip = isset($opt['tip']) && is_callable($opt['tip']) ? $opt['tip'] : null;
    $cls = in_array(($opt['color'] ?? ''), ['', 'warn', 'danger'], true) ? (string)($opt['color'] ?? '') : '';
    $max = 0;
    foreach ($items as $v) {
        $max = max($max, (float)$v);
    }
    if ($max <= 0) {
        $max = 1;
    }
    $aria = trim((string)($opt['label'] ?? '统计图表'));
    $html = '<div class="chart-bars" role="img" aria-label="' . e($aria) . '">';
    foreach ($items as $label => $val) {
        $val = (float)$val;
        $pct = (int)round($val / $max * 100);
        $pct = max(2, min(100, $pct));
        $show = $fmt ? (string)$fmt($val) : (string)(round($val, 1) == (int)$val ? (int)$val : round($val, 1));
        $l = (string)$label;
        $title = $tip ? (string)$tip($l, $val) : ($l . '：' . $show);
        $html .= '<div class="cb-row" title="' . e($title) . '">' .
            '<span class="cb-label" title="' . e($l) . '">' . e($l) . '</span>' .
            '<span class="cb-track"><i class="cb-fill' . ($cls !== '' ? ' ' . $cls : '') . '" style="width:' . $pct . '%"></i></span>' .
            '<span class="cb-val">' . e($show) . '</span>' .
            '</div>';
    }
    return $html . '</div>';
}

/**
 * 近 N 天「请求 / 拦截」趋势柱状图（数据缺纸的天自动补零）
 * @param array $series fw_daily_series() 的结果：[['d'=>'2026-10-01','req'=>n,'blocked'=>n],…] 旧→新
 * @return string HTML（含图例）；无任何数据时返回空串
 */
function chart_trend_days(array $series): string
{
    $series = array_values(array_filter($series, 'is_array'));
    if (!$series) {
        return '';
    }
    $max = 1;
    $totalReq = 0;
    $totalBlk = 0;
    foreach ($series as $r) {
        $max = max($max, (int)($r['req'] ?? 0));
        $totalReq += (int)($r['req'] ?? 0);
        $totalBlk += (int)($r['blocked'] ?? 0);
    }
    if ($totalReq <= 0 && $totalBlk <= 0) {
        return '';
    }
    $html = '<div class="chart-trend" role="img" aria-label="近 ' . count($series) . ' 天请求与拦截趋势，共请求 ' . $totalReq . ' 次、拦截 ' . $totalBlk . ' 次">';
    $xl = '<div class="ct-x">';
    foreach ($series as $r) {
        $req = max(0, (int)($r['req'] ?? 0));
        $blk = min($req, max(0, (int)($r['blocked'] ?? 0))); // 拦截通常已计入请求；封禁名单类硬拦截可能超出，做上限保护避免负高度
        $h = (int)round($req / $max * 100);
        $hb = $req > 0 ? (int)round($blk / $req * 100) : 0;
        $d = (string)($r['d'] ?? '');
        $tip = $d . '：请求 ' . $req . ' 次' . ($blk > 0 ? ' / 拦截 ' . $blk . ' 次' : '');
        $html .= '<div class="ct-col" title="' . e($tip) . '">' .
            '<div class="ct-bar" style="height:' . max(2, $h) . '%">' .
            ($blk > 0 ? '<i class="ct-blocked" style="height:' . $hb . '%"></i>' : '') .
            '</div></div>';
        $xl .= '<span' . ($blk > 0 ? ' class="has-blk" title="' . e($tip) . '"' : ' title="' . e($tip) . '"') . '>' . e(substr($d, 5)) . '</span>';
    }
    $html .= '</div>' . $xl . '</div>';
    $html .= '<div class="chart-legend"><span><i class="lg-req"></i>请求（峰值 ' . $max . '）</span>' .
        ($totalBlk > 0 ? '<span><i class="lg-blk"></i>拦截 ' . $totalBlk . '</span>' : '') .
        '<span class="muted">悬停柱子查看单日明细</span></div>';
    return $html;
}
