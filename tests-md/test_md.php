<?php
/* md_render v1.7.0 单元测试（CLI，不依赖论坛运行时） */
define('APP', 'test');
require __DIR__ . '/../php-forum/src/markdown.php';

$pass = 0;
$fail = 0;
function t(string $name, string $got, string $expect): void
{
    global $pass, $fail;
    if ($got === $expect) {
        $pass++;
        echo "PASS  {$name}\n";
    } else {
        $fail++;
        echo "FAIL  {$name}\n  期望: {$expect}\n  实际: {$got}\n";
    }
}
function contains(string $name, string $got, string $needle): void
{
    global $pass, $fail;
    if (strpos($got, $needle) !== false) {
        $pass++;
        echo "PASS  {$name}\n";
    } else {
        $fail++;
        echo "FAIL  {$name}\n  应包含: {$needle}\n  实际: {$got}\n";
    }
}
function not_contains(string $name, string $got, string $needle): void
{
    global $pass, $fail;
    if (strpos($got, $needle) === false) {
        $pass++;
        echo "PASS  {$name}\n";
    } else {
        $fail++;
        echo "FAIL  {$name}\n  不应包含: {$needle}\n  实际: {$got}\n";
    }
}

/* ---- 旧功能回归 ---- */
t('标题', md_render('# 标题一'), '<h3>标题一</h3>');
t('加粗', md_inline('**粗**'), '<strong>粗</strong>');
t('斜体*', md_inline('*斜*'), '<em>斜</em>');
t('删除线', md_inline('~~删~~'), '<del>删</del>');
t('行内代码', md_inline('`code`'), '<code>code</code>');
t('链接', md_inline('[点](https://a.com)'), '<a href="https://a.com" target="_blank" rel="nofollow noopener">点</a>');
contains('无序列表', md_render("- 甲\n- 乙"), '<ul><li>甲</li><li>乙</li></ul>');
contains('有序列表', md_render("1. 甲\n2. 乙"), '<ol><li>甲</li><li>乙</li></ol>');
contains('分割线', md_render('---'), '<hr>');
contains('引用', md_render('> 引用文字'), '<blockquote>');

/* ---- 新功能：表格 ---- */
$tbl = md_render("| 名称 | 数量 |\n|---|---|\n| 苹果 | 3 |\n| 香蕉 | 5 |");
contains('表格-表头', $tbl, '<th style="text-align:left">名称</th>');
contains('表格-表体', $tbl, '<td style="text-align:left">苹果</td>');
contains('表格-thead', $tbl, '<table><thead>');
contains('表格-tbody', $tbl, '<tbody>');
contains('表格-对齐居中', md_render("| A | B |\n|:-:|---:|\n| a | b |"), '<th style="text-align:center">A</th>');
contains('表格-对齐右', md_render("| A | B |\n|:-:|---:|\n| a | b |"), '<th style="text-align:right">B</th>');
contains('表格-单元格加粗', md_render("| X |\n|---|\n| **粗** |"), '<td style="text-align:left"><strong>粗</strong></td>');
not_contains('表格-非表格不误判', md_render("| 只是普通竖线文字 |\n第二行不是分隔行"), '<table>');

/* ---- 新功能：图片 ---- */
$img = md_inline('![截图](https://example.com/a.png)');
contains('图片-渲染img', $img, '<img src="https://example.com/a.png"');
contains('图片-懒加载', $img, 'loading="lazy"');
contains('图片-无引用', $img, 'referrerpolicy="no-referrer"');
not_contains('图片-http拒绝', md_inline('![x](http://example.com/a.png)'), '<img');
not_contains('图片-js协议拒绝', md_inline('![x](javascript:alert(1))'), '<img');
contains('图片-块级渲染', md_render('![图](https://e.com/i.png)'), '<img');

/* ---- 新功能：任务列表 ---- */
$task = md_render("- [ ] 待办事项\n- [x] 已完成事项");
contains('任务-未完成', $task, '<li class="task"><input type="checkbox" disabled aria-label="任务状态">待办事项</li>');
contains('任务-已完成', $task, '<li class="task done"><input type="checkbox" disabled checked aria-label="任务状态">已完成事项</li>');

/* ---- 新功能：高亮 ---- */
t('高亮', md_inline('==重点=='), '<mark>重点</mark>');
not_contains('高亮-不跨行', md_inline("==第一行\n第二行=="), '<mark>');
contains('高亮-块级', md_render('前 ==中== 后'), '<mark>中</mark>');

/* ---- 新功能：裸链接自动识别（生产链路经 md_render 转义后 & 变为 &amp;）---- */
$auto = md_render('看这个 https://example.com/x?a=1&b=2 很棒');
contains('自动链接-生成a', $auto, '<a href="https://example.com/x?a=1&amp;b=2"');
contains('自动链接-中文标点保留', $auto, ' 很棒');
$auto2 = md_inline('访问 https://e.com/path. 完毕');
contains('自动链接-剥尾点', $auto2, 'href="https://e.com/path"');
contains('自动链接-句点保留', $auto2, '</a>. 完毕');
not_contains('自动链接-已有链接不嵌套', md_inline('[文字](https://a.com) https://b.com'), '<a href="https://a.com" target="_blank" rel="nofollow noopener"><a');

/* ---- 新功能：下划线斜体 ---- */
contains('下划线斜体', md_inline('这是 _斜体_ 文本'), '<em>斜体</em>');
not_contains('下划线不误伤URL', md_inline('https://e.com/my_file.txt'), '<em>');
not_contains('下划线不误伤文件名', md_inline('my_file_name.zip'), '<em>');

/* ---- 新功能：多级引用 ---- */
$quote2 = md_render("> 一级\n>> 二级");
contains('多级引用-嵌套', $quote2, '<blockquote><blockquote>');
not_contains('多级引用-无残留符号', $quote2, '&gt;');

/* ---- 安全回归 ---- */
not_contains('XSS-脚本转义', md_render('<script>alert(1)</script>'), '<script>');
not_contains('XSS-img-onerror', md_render('![x](https://e.com/a.png" onerror="alert(1))'), '<img');
not_contains('XSS-链接js协议', md_render('[点](javascript:alert(1))'), '<a href="javascript:');
contains('XSS-HTML显示原文', md_render('<b>加</b>'), '&lt;b&gt;加&lt;/b&gt;');
not_contains('XSS-占位符注入', md_render("\x010\x03"), "\x01");
not_contains('XSS-引号实体URL降级', md_render('[点](https://a.com&quot;x&quot;)'), '<a href="https://a.com&quot;x&quot;"');

/* ---- 综合场景 ---- */
$mix = md_render("# 更新公告\n\n**重要**：~~旧版~~ ==v1.7== 已发布\n\n| 功能 | 状态 |\n|---|:-:|\n| 表格 | ✅ |\n| 高亮 | ✅ |\n\n- [x] 表格\n- [ ] 更多格式\n\n> 详见 https://example.com/changelog");
foreach (['<h3>更新公告</h3>', '<strong>重要</strong>', '<del>旧版</del>', '<mark>v1.7</mark>', '<table>', '<td style="text-align:left">表格</td>', 'li class="task done"', '<blockquote>', 'href="https://example.com/changelog"'] as $needle) {
    contains('综合-含 ' . $needle, $mix, $needle);
}

/* ---- 代码块回归 ---- */
contains('围栏代码块', md_render("```php\necho 'hi';\n```"), '<pre><code>echo &#039;hi&#039;;</code></pre>');
not_contains('代码块内不解析语法', md_render("```\n**不解析**\n```"), '<strong>');

echo "\n========== 结果: {$pass} 通过 / {$fail} 失败 ==========\n";
exit($fail > 0 ? 1 : 0);
