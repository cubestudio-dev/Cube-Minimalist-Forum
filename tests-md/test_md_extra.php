<?php
define('APP', 'test');
require '/home/z/my-project/php-forum/src/markdown.php';
$p = 0;
$f = 0;
function chk(string $n, string $got, string $needle, bool $want = true): void
{
    global $p, $f;
    $has = strpos($got, $needle) !== false;
    if ($has === $want) {
        $p++;
        echo "PASS  {$n}\n";
    } else {
        $f++;
        echo "FAIL  {$n}\n  " . ($want ? '应包含' : '不应包含') . ": {$needle}\n  实际: {$got}\n";
    }
}

/* 场景1：单元格内代码含竖线（GFM 标准 \| 转义）→ 渲染为 code 且不切断单元格 */
$t1 = md_render("| 写法 | 说明 |\n|---|---|\n| `\\| 列1 \\| 列2 \\|` | 竖线示例 |");
chk('转义竖线-code完整', $t1, '<td style="text-align:left"><code>| 列1 | 列2 |</code></td>');
chk('转义竖线-第二列不受影响', $t1, '<td style="text-align:left">竖线示例</td>');

/* 场景2：纯文本转义竖线 → 恢复为字面 | 字符 */
$t2 = md_render("| 内容 | 说明 |\n|---|---|\n| a \\| b | 字面竖线 |");
chk('纯文本竖线还原', $t2, '<td style="text-align:left">a | b</td>');

/* 场景3：普通表格回归 */
$t3 = md_render("| a | b |\n|---|---|\n| 1 | 2 |");
chk('普通表格回归', $t3, '<td style="text-align:left">1</td>');

/* 场景4：对齐回归 */
chk('对齐回归', md_render("| A |\n|:---:|\n| x |"), '<th style="text-align:center">A</th>');

echo "\n===== 附加测试: {$p} 通过 / {$f} 失败 =====\n";
exit($f > 0 ? 1 : 0);
