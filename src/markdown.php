<?php
/**
 * 极简论坛 · 极简 Markdown 渲染（v1.10.0 扩展版）
 * 支持：标题(#)、加粗(**)、斜体(* 或 _)、删除线(~~)、高亮(==)、上标(^)、下标(~)、行内代码(`)、
 *       围栏代码块(```)、脚注([^1])、Emoji 短代码(:smile:)、链接([]())、图片(仅 https 外链，懒加载)、
 *       裸链接自动识别、有序/无序列表、任务列表(- [ ] / - [x])、多级引用(>)、表格(| 对齐可选)、分割线(---)
 * 安全：任何内嵌 HTML 先整体转义（防 XSS）；
 *       图片地址仅接受 https:// 外链；链接/图片地址均为转义后文本，无法闭合属性注入；
 *       已生成的 <code>/<a>/<img> 一律进入占位符保护，避免被后续语法规则破坏。
 */
defined('APP') or exit('Forbidden');

/** 内置 Emoji 短代码表（name => 字符）：前台表情选择器与 :name: 渲染共用同一套名称 */
const MD_EMOJI = [
    'smile' => '😄', 'laughing' => '😆', 'joy' => '😂', 'rofl' => '🤣', 'smiley' => '😃',
    'grin' => '😁', 'wink' => '😉', 'blush' => '😊', 'innocent' => '😇', 'upside_down' => '🙃',
    'relieved' => '😌', 'heart_eyes' => '😍', 'kissing_heart' => '😘', 'thinking' => '🤔', 'neutral' => '😐',
    'expressionless' => '😑', 'smirk' => '😏', 'unamused' => '😒', 'roll_eyes' => '🙄', 'pensive' => '😔',
    'cry' => '😢', 'sob' => '😭', 'angry' => '😠', 'rage' => '😡', 'scream' => '😱',
    'cold_sweat' => '😰', 'sleepy' => '😪', 'mask' => '😷', 'sunglasses' => '😎', 'nerd' => '🤓',
    'clown' => '🤪', 'star_struck' => '🤩', 'party' => '🥳', 'pleading' => '🥺', 'shushing' => '🤫',
    'zombie' => '🧟', 'ghost' => '👻', 'alien' => '👽', 'robot' => '🤖', 'skull' => '💀',
    'poop' => '💩', 'clown_face' => '🤡', 'eyes' => '👀', 'brain' => '🧠', 'hug' => '🤗',
    'thumbsup' => '👍', 'thumbsdown' => '👎', 'ok_hand' => '👌', 'v' => '✌️', 'wave' => '👋',
    'clap' => '👏', 'pray' => '🙏', 'muscle' => '💪', 'point_right' => '👉', 'point_left' => '👈',
    'point_up' => '☝️', 'point_down' => '👇', 'raised_hands' => '🙌', 'handshake' => '🤝', 'fist' => '✊',
    'bow' => '🙇', 'running' => '🏃', 'dancer' => '💃', 'couple' => '👫', 'family' => '👪',
    'heart' => '❤️', 'orange_heart' => '🧡', 'yellow_heart' => '💛', 'green_heart' => '💚', 'blue_heart' => '💙',
    'purple_heart' => '💜', 'black_heart' => '🖤', 'broken_heart' => '💔', 'heartpulse' => '💗', 'sparkling_heart' => '💖',
    'two_hearts' => '💕', 'revolving_hearts' => '💞', 'star' => '⭐', 'sparkles' => '✨', 'fire' => '🔥',
    'boom' => '💥', 'zap' => '⚡', 'rainbow' => '🌈', 'sunny' => '☀️', 'moon' => '🌙',
    'cloud' => '☁️', 'snowflake' => '❄️', 'umbrella' => '☔', 'gift' => '🎁', 'bell' => '🔔',
    'mega' => '📢', 'lock' => '🔒', 'key' => '🔑', 'bulb' => '💡', 'books' => '📚',
    'book' => '📖', 'memo' => '📝', 'pencil' => '✏️', 'calendar' => '📅', 'alarm_clock' => '⏰',
    'white_check_mark' => '✅', 'x' => '❌', 'question' => '❓', 'exclamation' => '❗', 'warning' => '⚠️',
    'no_entry' => '⛔', 'recycle' => '♻️', '100' => '💯', 'hot' => '🥵', 'cold_face' => '🥶',
    'coffee' => '☕', 'tea' => '🍵', 'beer' => '🍺', 'cake' => '🍰', 'apple' => '🍎',
    'watermelon' => '🍉', 'pizza' => '🍕', 'ice_cream' => '🍦', 'moon_cake' => '🥮', 'fish' => '🐟',
    'rice' => '🍚', 'noodles' => '🍜', 'bread' => '🍞', 'egg' => '🥚', 'popcorn' => '🍿',
    'cat' => '🐱', 'dog' => '🐶', 'mouse' => '🐭', 'rabbit' => '🐰', 'fox' => '🦊',
    'bear' => '🐻', 'panda' => '🐼', 'tiger' => '🐯', 'lion' => '🦁', 'cow' => '🐮',
    'pig' => '🐷', 'frog' => '🐸', 'chicken' => '🐤', 'penguin' => '🐧', 'owl' => '🦉',
    'bee' => '🐝', 'butterfly' => '🦋', 'snail' => '🐌', 'turtle' => '🐢', 'octopus' => '🐙',
    'whale' => '🐳', 'dolphin' => '🐬', 'blossom' => '🌸', 'rose' => '🌹', 'sunflower' => '🌻',
    'four_leaf_clover' => '🍀', 'seedling' => '🌱', 'cactus' => '🌵', 'palm_tree' => '🌴', 'computer' => '💻',
    'iphone' => '📱', 'camera' => '📷', 'headphones' => '🎧', 'music' => '🎵', 'guitar' => '🎸',
    'video_game' => '🎮', 'car' => '🚗', 'airplane' => '✈️', 'train' => '🚄', 'ship' => '🚢',
    'house' => '🏠', 'moneybag' => '💰', 'gem' => '💎', 'trophy' => '🏆', 'medal' => '🏅',
    'soccer' => '⚽', 'basketball' => '🏀', 'ping_pong' => '🏓', 'art' => '🎨', 'ticket' => '🎫',
    'balloon' => '🎈', 'tada' => '🎉', 'confetti_ball' => '🎊', 'crown' => '👑', 'eyeglasses' => '👓',
];

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

    // 1.5) 脚注引用 [^label] → 上标链接（定义项由 md_render 收集后在文末渲染）
    $fnPre = (string)($GLOBALS['MF_FN_PRE'] ?? 'fn-');
    $s = preg_replace_callback('/\[\^([a-zA-Z0-9_\-]{1,20})\]/', function ($m) use ($keep, $fnPre) {
        return $keep('<sup class="fn-ref" id="fnref-' . $fnPre . $m[1] . '"><a href="#fn-' . $fnPre . $m[1] . '">' . $m[1] . '</a></sup>');
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

    // 4) 高亮 / 加粗 / 删除线 / 下标 / 上标 / 斜体
    $s = preg_replace('/==([^=\n]+)==/', '<mark>$1</mark>', $s) ?? $s;
    $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s) ?? $s;
    $s = preg_replace('/~~([^~]+)~~/', '<del>$1</del>', $s) ?? $s;
    // 下标 ~x~（单个波浪线；删除线 ~~ 已先行消费，前后防粘连）
    $s = preg_replace('/(?<!~)~([^~\n]{1,32})~(?!~)/', '<sub>$1</sub>', $s) ?? $s;
    // 上标 ^x^
    $s = preg_replace('/\^([^^\n]{1,32})\^/', '<sup>$1</sup>', $s) ?? $s;
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

    // 5.5) Emoji 短代码：:name: → 表情字符（仅识别内置表内名称；时间 10:30 等不会误转换）
    $s = preg_replace_callback('/:([a-zA-Z0-9_\-]{2,30}):/', function ($m) {
        $e = MD_EMOJI[strtolower($m[1])] ?? null;
        return $e !== null ? $e : $m[0];
    }, $s) ?? $s;

    // 5.6) @ 提及高亮（v1.15.0）：@用户名 → <span class="mention">（与站内用户名同规则；@前不能是用户名字符，避免误伤邮箱）
    // 此时代码 / 链接 / 图片均已在占位符内，不会被误改；通知逻辑由 content.php mentions_notify() 在发布时处理
    $s = preg_replace(
        '/(?<![\x{4e00}-\x{9fa5}A-Za-z0-9_&])@([\x{4e00}-\x{9fa5}A-Za-z0-9_]{2,20})(?![\x{4e00}-\x{9fa5}A-Za-z0-9_])/u',
        '<span class="mention">@$1</span>',
        $s
    ) ?? $s;

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

    // 脚注：每次渲染分配独立 ID 前缀（避免同页多个富文本块的锚点冲突），并初始化定义收集器
    $GLOBALS['MF_FN_SEQ'] = (isset($GLOBALS['MF_FN_SEQ']) ? (int)$GLOBALS['MF_FN_SEQ'] : 0) + 1;
    $GLOBALS['MF_FN_PRE'] = 'fn' . $GLOBALS['MF_FN_SEQ'] . '-';
    $fndefs = [];

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
        // 脚注定义：[^label]: 说明文字（收集后在文末统一渲染脚注区）
        if (preg_match('/^\[\^([a-zA-Z0-9_\-]{1,20})\]:\s*(.*)$/', $t, $fm)) {
            $fndefs[$fm[1]] = $fm[2];
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
    // 文末脚注区（仅当正文里写了 [^label]: 定义时出现）
    if ($fndefs) {
        $items = '';
        foreach ($fndefs as $fk => $fv) {
            $items .= '<li id="fn-' . $GLOBALS['MF_FN_PRE'] . $fk . '">' . md_inline($fv)
                . ' <a href="#fnref-' . $GLOBALS['MF_FN_PRE'] . $fk . '" aria-label="返回正文">↩</a></li>';
        }
        $html[] = '<div class="md-fn"><ol>' . $items . '</ol></div>';
    }
    /* v1.22.0 拓展：渲染结果过滤器（插件可后处理全站 Markdown HTML；第二个参数为原文） */
    return (string)mf_apply_filters('md_html', implode("\n", $html), $text);
}
