<?php
/**
 * 极简论坛 · 极简 Markdown 渲染
 * 支持：标题(###)、加粗、斜体、删除线、行内代码、围栏代码块、链接、有序/无序列表、引用、分割线
 * 明确不支持：图片（降级为替代文字）、任何内嵌 HTML（先整体转义，天然防 XSS）
 */
defined('APP') or exit('Forbidden');

function md_inline(string $s): string
{
    $s = preg_replace('/!\[([^\]]*)\]\(([^)]*)\)/', '$1', $s) ?? $s; // 图片降级为文字
    $s = preg_replace_callback('/`([^`]+)`/', function ($m) {
        return '<code>' . $m[1] . '</code>';
    }, $s) ?? $s;
    $s = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^)\s]+|\/[^)\s]*)\)/', function ($m) {
        return '<a href="' . $m[2] . '" target="_blank" rel="nofollow noopener">' . $m[1] . '</a>';
    }, $s) ?? $s;
    $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s) ?? $s;
    $s = preg_replace('/\*([^*\s][^*]*)\*/', '<em>$1</em>', $s) ?? $s;
    $s = preg_replace('/~~([^~]+)~~/', '<del>$1</del>', $s) ?? $s;
    return $s;
}

function md_render(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
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
            $html[] = '<blockquote>' . md_inline(implode('<br>', $quote)) . '</blockquote>';
            $quote = [];
        }
    };
    $flushAll = function () use ($flushPara, $flushList, $flushQuote) {
        $flushPara();
        $flushList();
        $flushQuote();
    };

    foreach ($lines as $ln) {
        $t = trim($ln);
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
        if (preg_match('/^&gt;\s?(.*)$/', $t, $m)) {
            $flushPara();
            $flushList();
            $quote[] = $m[1];
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
            $list .= '<li>' . md_inline($m[1]) . '</li>';
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
            $list .= '<li>' . md_inline($m[1]) . '</li>';
            continue;
        }
        $flushList();
        $flushQuote();
        $para[] = $t;
    }
    $flushAll();
    return implode("\n", $html);
}
