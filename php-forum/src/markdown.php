<?php
/**
 * 极简论坛 · 极简 Markdown 渲染（v1.7.0 扩展版）
 * 支持：标题(#)、加粗(**)、斜体(* 或 _)、删除线(~~)、高亮(==)、行内代码(`)、
 *       围栏代码块(```)、链接([]())、图片(仅 https 外链，懒加载)、裸链接自动识别、
 *       有序/无序列表、任务列表(- [ ] / - [x])、多级引用(>)、表格(| 对齐可选)、分割线(---)
 * 安全：任何内嵌 HTML 先整体转义（防 XSS）；
 *       图片地址仅接受 https:// 外链；链接/图片地址均为转义后文本，无法闭合属性注入；
 *       已生成的 <code>/<a>/<img> 一律进入占位符保护，避免被后续语法规则破坏。
 */
defined('APP') or exit('Forbidden');

function md_inline(string $s): string
{
    // 防御：剔除控制字符（含 \x01/\x03 占位符标记），杜绝用户输入干扰内部机制
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s) ?? $s;
    $ph = [];
    $keep = function (string $html) use (&$ph): string {
        $ph[] = $html;
        return "\x01" . (count($ph) - 1) . "\x01";
    };

    // 1) 行内代码最先保护（其中的 *、_、URL 等不再参与后续语法）
    $s = preg_replace_callback('/`([^`]+)`/', function ($m) use ($keep) {
        return $keep('<code>' . $m[1] . '</code>');
    }, $s) ?? $s;

    // 占位符安全检查：URL 若含引号实体（&quot;/&#039;）则放弃渲染，降为纯文本
    $url_dirty = function (string $u): bool {
        return stripos($u, '&quot;') !== false || stripos($u, '&#039;') !== false;
    };

    // 2) 图片：仅 https 外链，懒加载 + 不带来源引用
    $s = preg_replace_callback('/!\[([^\]]*)\]\((https:\/\/[^)\s]+)\)/i', function ($m) use ($keep, $url_dirty) {
        if ($url_dirty($m[2])) {
            return $m[0];
        }
        return $keep('<img src="' . $m[2] . '" alt="' . $m[1] . '" loading="lazy" referrerpolicy="no-referrer">');
    }, $s) ?? $s;

    // 3) 链接：http(s) 外链或站内相对路径
    $s = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^)\s]+|\/[^)\s]*)\)/', function ($m) use ($keep, $url_dirty) {
        if ($url_dirty($m[2])) {
            return $m[0];
        }
        return $keep('<a href="' . $m[2] . '" target="_blank" rel="nofollow noopener">' . $m[1] . '</a>');
    }, $s) ?? $s;

    // 4) 高亮 / 加粗 / 删除线 / 斜体
    $s = preg_replace('/==([^=\n]+)==/', '<mark>$1</mark>', $s) ?? $s;
    $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s) ?? $s;
    $s = preg_replace('/~~([^~]+)~~/', '<del>$1</del>', $s) ?? $s;
    $s = preg_replace('/\*([^*\s][^*]*)\*/', '<em>$1</em>', $s) ?? $s;
    // _斜体_：前后不能是字母/数字/下划线（避免破坏 URL、文件名与 snake_case）
    $s = preg_replace('/(?<![\p{L}\p{N}_])_([^_\s][^_]*)_(?![\p{L}\p{N}_])/u', '<em>$1</em>', $s) ?? $s;

    // 裸链接自动识别（此时 a/code/img 已进入占位符，不会重复嵌套）
    $s = preg_replace_callback('/(?<![\p{L}\p{N}\/@])(https?:\/\/[^\s\x01]+)/u', function ($m) use ($keep, $url_dirty) {
        if ($url_dirty($m[1])) {
            return $m[0];
        }
        $url = rtrim($m[1], '.,;:!?，。；：！？、）)】》');
        $rest = substr($m[1], strlen($url));
        return $keep('<a href="' . $url . '" target="_blank" rel="nofollow noopener">' . $url . '</a>') . $rest;
    }, $s) ?? $s;

    // 6) 还原占位符（上限 10 轮，防构造死循环）
    for ($i = 0; $i < 10 && strpos($s, "\x01") !== false; $i++) {
        $s = preg_replace_callback('/\x01(\d+)\x01/', function ($m) use ($ph) {
            return $ph[(int)$m[1]] ?? '';
        }, $s) ?? $s;
    }
    return $s;
}

function md_render(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    // 防御：剔除控制字符（含 \x01/\x03 内部占位符标记），杜绝用户输入干扰渲染机制
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? $text;
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    // 围栏代码块先摘出为占位符
    $blocks = [];
    $text = preg_replace_callback('/```[a-zA-Z0-9]*\n(.*?)```/s', function ($m) use (&$blocks) {
        $blocks[] = '<pre><code>' . trim($m[1], "\n") . '</code></pre>';
        return "\n\x03" . (count($blocks) - 1) . "\x03\n";
    }, $text) ?? $text;

    $lines = explode("\n", $text);
    $html = [];
    $para = [];
    $list = '';
    $listType = '';
    $quote = [];

    $flushPara = function () use (&$para, &$html) {
        if ($para) {
            $html[] = '<p>' . md_inline(implode('<br>', $para)) . '</p>';
            $para = [];
        }
    };
    $flushList = function () use (&$list, &$listType, &$html) {
        if ($list !== '') {
            $html[] = $list . '</' . $listType . '>';
            $list = '';
            $listType = '';
        }
    };
    $flushQuote = function () use (&$quote, &$html) {
        if ($quote) {
            $depth = 1;
            $buf = [];
            foreach ($quote as $q) {
                $depth = max($depth, (int)$q['d']);
                $buf[] = $q['t'];
            }
            $html[] = str_repeat('<blockquote>', $depth) . md_inline(implode('<br>', $buf)) . str_repeat('</blockquote>', $depth);
            $quote = [];
        }
    };
    $flushAll = function () use ($flushPara, $flushList, $flushQuote) {
        $flushPara();
        $flushList();
        $flushQuote();
    };
    // 列表项渲染：支持任务列表前缀 [ ] / [x]
    $renderLi = function (string $item) use (&$list): void {
        if (preg_match('/^\[([ xX])\]\s+(.*)$/', $item, $tk)) {
            $done = strtolower($tk[1]) === 'x';
            $list .= '<li class="task' . ($done ? ' done' : '') . '"><input type="checkbox" disabled' . ($done ? ' checked' : '') . ' aria-label="任务状态">' . md_inline($tk[2]) . '</li>';
            return;
        }
        $list .= '<li>' . md_inline($item) . '</li>';
    };
    // 表格单元格解析：去掉首尾 | 后按 | 切分；\| 为转义竖线（GFM 标准），不参与切分
    $rowCells = function (string $row): array {
        if (substr($row, 0, 1) === '|') {
            $row = substr($row, 1);
        }
        if (substr($row, -1) === '|') {
            $row = substr($row, 0, -1);
        }
        $row = str_replace('\\|', "\x02", $row);
        return array_map(function ($c) {
            return trim(str_replace("\x02", '|', $c));
        }, explode('|', $row));
    };

    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        $t = trim($lines[$i]);
        if ($t === '') {
            $flushAll();
            continue;
        }
        if (preg_match('/^\x03(\d+)\x03$/', $t, $m)) {
            $flushAll();
            $html[] = $blocks[(int)$m[1]] ?? '';
            continue;
        }
        if (preg_match('/^(#{1,4})\s+(.*)$/', $t, $m)) {
            $flushAll();
            $lv = strlen($m[1]) + 2; // # -> h3，避免与页面标题层级冲突
            $html[] = '<h' . $lv . '>' . md_inline($m[2]) . '</h' . $lv . '>';
            continue;
        }
        if (preg_match('/^(-{3,}|\*{3,})$/', $t)) {
            $flushAll();
            $html[] = '<hr>';
            continue;
        }
        /* 表格：表头行 + 分隔行（:--- 左对齐 / :---: 居中 / ---: 右对齐）+ 数据行 */
        if (
            preg_match('/^\|.+\|$/', $t) && $i + 1 < $n && preg_match('/^\|.+\|$/', trim($lines[$i + 1]))
        ) {
            $sepCells = $rowCells(trim($lines[$i + 1]));
            $isSep = $sepCells && count($sepCells) > 0;
            foreach ($sepCells as $sc) {
                if (!preg_match('/^:?-+:?$/', $sc)) {
                    $isSep = false;
                    break;
                }
            }
            if ($isSep) {
                $flushAll();
                $aligns = array_map(function ($sc) {
                    if (substr($sc, 0, 1) === ':' && substr($sc, -1) === ':') {
                        return 'center';
                    }
                    if (substr($sc, -1) === ':') {
                        return 'right';
                    }
                    return 'left';
                }, $sepCells);
                $heads = $rowCells($t);
                $thead = '<tr>';
                foreach ($heads as $ci => $hc) {
                    $al = $aligns[$ci] ?? 'left';
                    $thead .= '<th style="text-align:' . $al . '">' . md_inline($hc) . '</th>';
                }
                $thead .= '</tr>';
                $body = '';
                $j = $i + 2;
                for (; $j < $n; $j++) {
                    $rt = trim($lines[$j]);
                    if (!preg_match('/^\|.+\|$/', $rt)) {
                        break;
                    }
                    $cells = $rowCells($rt);
                    $body .= '<tr>';
                    foreach ($heads as $ci => $unused) {
                        $val = $cells[$ci] ?? '';
                        $al = $aligns[$ci] ?? 'left';
                        $body .= '<td style="text-align:' . $al . '">' . md_inline($val) . '</td>';
                    }
                    $body .= '</tr>';
                }
                $html[] = '<table><thead>' . $thead . '</thead>' . ($body !== '' ? '<tbody>' . $body . '</tbody>' : '') . '</table>';
                $i = $j - 1;
                continue;
            }
        }
        /* 引用：支持多级（> 与 >>），行首连续 &gt; 全部剥离并计深度 */
        if (preg_match('/^((?:&gt;\s?)+)(.*)$/', $t, $m)) {
            $flushPara();
            $flushList();
            $quote[] = ['d' => substr_count($m[1], '&gt;'), 't' => $m[2]];
            continue;
        }
        if (preg_match('/^[-*]\s+(.*)$/', $t, $m)) {
            $flushPara();
            $flushQuote();
            if ($list === '' || $listType !== 'ul') {
                $flushList();
                $list = '<ul>';
                $listType = 'ul';
            }
            $renderLi($m[1]);
            continue;
        }
        if (preg_match('/^\d+\.\s+(.*)$/', $t, $m)) {
            $flushPara();
            $flushQuote();
            if ($list === '' || $listType !== 'ol') {
                $flushList();
                $list = '<ol>';
                $listType = 'ol';
            }
            $renderLi($m[1]);
            continue;
        }
        $flushList();
        $flushQuote();
        $para[] = $t;
    }
    $flushAll();
    return implode("\n", $html);
}
