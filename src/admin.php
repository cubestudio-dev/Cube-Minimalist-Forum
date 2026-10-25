<?php
/**
 * 极简论坛 · 后台管理（仅管理员）
 * 标签页：基本 / 邮件 / AI / 板块 / 用户 / 内容 / AI 队列 / 人工待审 / 举报记录 / 公告 / 主题 / 日志 / 安全防护 / 监控 / 更新升级 / 系统
 */
defined('APP') or exit('Forbidden');

function page_admin(): void
{
    /* v1.27.0：审核员可进后台（仅内容审核类标签页），其余标签页仅超级管理员 */
    $me = require_staff();
    $tab = (string)($_GET['tab'] ?? (is_super() ? 'basic' : 'content'));
    if (!preg_match('/^[a-z]{1,12}$/', $tab)) {
        $tab = 'basic';
    }
    /* 角色可见范围：审核员仅限内容审核类标签页，越权访问重定向到其默认页 */
    if (!role_at_least($me, admin_tab_min_role($tab))) {
        flash('err', t('该页面仅超级管理员可访问'));
        redirect(u('p=admin&tab=content'));
    }
    /* v1.16.0：记录当前后台完整地址（tab/筛选/分页），动作保存后 admin_redirect 精确回跳；
       配合前端滚动位置恢复，保存后不再"从头刷新、回到顶部" */
    $_SESSION['admin_back'] = http_build_query($_GET);
    layout_header('后台管理', 0);
    echo page_head(t('后台管理'), t('所有修改即时生效'));
    mf_do_action('admin_head'); /* v1.22.0 拓展：插件向后台页面注入内容 */

    /* v1.22.0 后台设置搜索框：跨全部标签页即时检索设置项，回车/点击跳转并高亮定位
       v1.27.0：仅超级管理员可见（索引含大量审核员无权页面的条目） */
    if (is_super()) {
        echo '<div class="admin-search" id="adminSearch">';
        echo '<input class="input admin-search-input" id="adminSearchInput" type="search" placeholder="' . e(t('搜索设置：输入关键词，如「上限」「邮件」「语言」…')) . '" autocomplete="off" aria-label="' . e(t('搜索后台设置')) . '">';
        echo '<div class="admin-search-pop" id="adminSearchPop" hidden></div>';
        echo '</div>';
    }
    /* v1.27.0 分组导航：21 个标签页按职责归为 5 组，替代原平铺标签条
       v1.29.0 布局可设（后台「主题」页）：nav-flat = 平铺标签（分组横排铺在内容上方）；
       侧边模式可选 2 / 3 列（nav-cols-N，把五个分组摊进多列、降低导航总高） */
    $navLayout = (string)cfg('admin_nav_layout', 'side') === 'flat' ? 'flat' : 'side';
    $navCols = (int)cfg('admin_nav_cols', 1);
    if ($navCols < 1 || $navCols > 3) {
        $navCols = 1;
    }
    $shellCls = 'admin-shell';
    if ($navLayout === 'flat') {
        $shellCls .= ' nav-flat';
    } elseif ($navCols > 1) {
        $shellCls .= ' nav-cols-' . $navCols;
    }
    echo '<div class="' . $shellCls . '">';
    echo '<nav class="admin-nav" aria-label="' . e(t('后台导航')) . '">';
    /* v1.30.0 导航布局快捷设置：直接放在菜单栏顶部（仅超管可见），不用去「主题」页找 */
    if (is_super()) {
        echo admin_nav_quick($tab);
    }
    foreach (admin_tab_groups() as $gk => $gkTabs) {
        $links = '';
        foreach ($gkTabs as $k) {
            if (!role_at_least($me, admin_tab_min_role($k))) {
                continue; /* 审核员看不到无权标签页 */
            }
            $links .= '<a class="atab' . ($tab === $k ? ' on' : '') . '" href="' . e(u('p=admin&tab=' . $k)) . '">' . e(t(admin_tab_titles()[$k])) . '</a>';
        }
        if ($links !== '') {
            echo '<div class="nav-group"><div class="nav-group-t">' . e(t($gk)) . '</div>' . $links . '</div>';
        }
    }
    echo '</nav>';
    echo '<div class="admin-body">';

    switch ($tab) {
        case 'feat': admin_tab_feat(); break;
        case 'storage': admin_tab_storage(); break;
        case 'ext': admin_tab_ext(); break;
        case 'mail': admin_tab_mail(); break;
        case 'ai': admin_tab_ai(); break;
        case 'boards': admin_tab_boards(); break;
        case 'users': admin_tab_users(); break;
        case 'content': admin_tab_content(); break;
        case 'queue': admin_tab_queue(); break;
        case 'manual': admin_tab_manual(); break;
        case 'reports': admin_tab_reports(); break;
        case 'anns': admin_tab_anns(); break;
        case 'theme': admin_tab_theme(); break;
        case 'docs': admin_tab_docs(); break;
        case 'api': admin_tab_api(); break;
        case 'logs': admin_tab_logs(); break;
        case 'security': admin_tab_security(); break;
        case 'monitor': admin_tab_monitor(); break;
        case 'update': admin_tab_update(); break;
        case 'system': admin_tab_system($me); break;
        default: admin_tab_basic();
    }

    echo '</div>';
    echo '</div>';
    layout_footer();
}

/* ---------------- 基本 ---------------- */
function admin_tab_basic(): void
{
    echo '<div class="card form-card"><form method="post" action="' . e(u('a=admin_save_basic')) . '" data-ajax="1" data-ajax-live="brand">' . csrf_field() .
        '<label class="field"><span class="field-l">' . t('论坛名称') . '</span><input class="input" name="site_name" required maxlength="30" value="' . e((string)cfg('site_name')) . '"></label>' .
        '<label class="field"><span class="field-l">' . t('论坛简介') . '</span><input class="input" name="site_desc" maxlength="100" value="' . e((string)cfg('site_desc')) . '"></label>' .
        '<label class="field"><span class="field-l">' . t('站点地址（可选）') . '</span><input class="input" name="site_url" type="url" maxlength="200" placeholder="https://" value="' . e((string)cfg('site_url')) . '"></label>' .
        '<label class="field"><span class="field-l">' . t('绑定域名（授权域名，可选）') . '</span><input class="input" name="bind_domains" maxlength="500" placeholder="forum.example.com" value="' . e((string)cfg('bind_domains')) . '">' .
        '<span class="hint">留空不限制。填写后仅允许列表内的域名访问论坛，其他域名（他人恶意解析、镜像站、IP 直连）一律 301 跳转到第一个授权域名。多个域名用逗号分隔；带 www 与不带 www 是两个域名，需分别填写。万一把域名写错导致无法访问：通过 FTP 打开 data/config.php 删掉 bind_domains 一行即可恢复</span></label>' .
        '<div class="grid3">' .
        '<label class="field"><span class="field-l">' . t('每页帖子数') . '</span><input class="input" name="per_page" type="number" min="5" max="100" value="' . (int)cfg('per_page', 20) . '"></label>' .
        '<label class="field"><span class="field-l">' . t('发帖间隔（秒）') . '</span><input class="input" name="post_interval" type="number" min="0" max="3600" value="' . (int)cfg('post_interval', 30) . '"></label>' .
        '<label class="field"><span class="field-l">' . t('在线统计窗口（秒）') . '</span><input class="input" name="online_window" type="number" min="60" max="86400" value="' . (int)cfg('online_window', 300) . '"></label>' .
        '</div>' .
        '<label class="field"><span class="field-l">' . t('实时刷新间隔（秒）') . '</span><input class="input" name="live_interval" type="number" min="0" max="300" value="' . (int)cfg('live_interval', 20) . '">' .
        '<span class="hint">前台自动刷新在线人数、未读通知，并提示新帖 / 新回复；设为 0 关闭。页面切到后台时自动暂停，不产生无效流量</span></label>' .
        '<label class="field"><span class="field-l">' . t('Ping 测量间隔（秒）') . '</span><input class="input" name="ping_interval" type="number" min="0" max="300" value="' . (int)cfg('ping_interval', 15) . '">' .
        '<span class="hint">侧栏「Ping」每 N 秒测量一次浏览器到服务器的往返延迟（绿 &lt;100ms / 琥珀 &lt;300ms / 红 ≥300ms）；设为 0 关闭整个 Ping 显示。间隔越短数据越实时，但心跳请求也越频繁（5-300 秒）</span></label>' .
        '<div class="form-foot"><span></span><button class="btn btn-primary" type="submit">' . t('保存基本设置') . '</button></div></form>' .
        admin_restore_btn('site_name,site_desc,site_url,bind_domains,per_page,post_interval,online_window,live_interval,ping_interval') . '</div>' .
        admin_card_limits() .
        admin_card_seo() .
        admin_card_lang();
}

/* ---------------- v1.32.0 内容与限制卡片 ---------------- */
function admin_card_limits(): string
{
    return '<div class="card form-card"><form method="post" action="' . e(u('a=admin_save_basic')) . '" data-ajax="1">' . csrf_field() .
        '<h2 class="card-title">' . t('内容与限制') . '</h2>' .
        '<p class="hint">帖子 / 回复 / 昵称 / 签名的字数上限与上传行为全部可自定义：前台表单与保存动作同步生效，超出上限时给出明确错误而不是静默截断</p>' .
        '<div class="grid3">' .
        '<label class="field"><span class="field-l">' . t('标题最大字数') . '</span><input class="input" name="thread_title_max" type="number" min="4" max="200" value="' . (int)cfg('thread_title_max', 30) . '"></label>' .
        '<label class="field"><span class="field-l">' . t('正文最大字数') . '</span><input class="input" name="thread_len_max" type="number" min="100" max="200000" value="' . (int)cfg('thread_len_max', 1500) . '"></label>' .
        '<label class="field"><span class="field-l">' . t('回复最大字数') . '</span><input class="input" name="reply_len_max" type="number" min="100" max="200000" value="' . (int)cfg('reply_len_max', 1000) . '"></label>' .
        '<label class="field"><span class="field-l">' . t('昵称最大字数') . '</span><input class="input" name="name_max" type="number" min="2" max="30" value="' . (int)cfg('name_max', 20) . '"></label>' .
        '<label class="field"><span class="field-l">' . t('签名最大字数') . '</span><input class="input" name="sign_len_max" type="number" min="0" max="500" value="' . (int)cfg('sign_len_max', 60) . '"></label>' .
        '<label class="field"><span class="field-l">' . t('单帖上传文件数上限') . '</span><input class="input" name="upload_per_post" type="number" min="1" max="20" value="' . (int)cfg('upload_per_post', 4) . '"></label>' .
        '</div>' .
        '<div class="grid3">' .
        '<label class="field"><span class="field-l">' . t('图片大小上限（MB）') . '</span><input class="input" name="upload_img_max_mb" type="number" min="1" max="50" value="' . (int)cfg('upload_img_max_mb', 5) . '"></label>' .
        '<label class="field"><span class="field-l">' . t('附件大小上限（MB）') . '</span><input class="input" name="upload_file_max_mb" type="number" min="1" max="100" value="' . (int)cfg('upload_file_max_mb', 10) . '"></label>' .
        '<label class="field"><span class="field-l">' . t('允许的附件扩展名') . '</span><input class="input" name="upload_allow_ext" value="' . e((string)cfg('upload_allow_ext', 'zip pdf txt md csv doc docx xls xlsx ppt pptx')) . '">' .
        '<span class="hint">空格或逗号分隔（保存时自动统一为空格，v1.41.0 前用逗号会被无声吞掉导致白名单粘成一坨、附件上传被拒）；图片（png / jpg / gif / webp）始终允许，svg / 可执行 / 脚本类永远拒绝；上传总开关在「功能」页「允许上传图片 / 附件」</span></label>' .
        '</div>' .
        '<div class="grid3">' .
        '<label class="field"><span class="field-l">' . t('分块大小（MB）') . '</span><input class="input" name="upload_chunk_mb" type="number" min="1" max="16" value="' . max(1, min(16, (int)cfg('upload_chunk_mb', 4))) . '"></label>' .
        '<label class="field"><span class="field-l">' . t('上传并发块数') . '</span><input class="input" name="upload_conc" type="number" min="1" max="6" value="' . max(1, min(6, (int)cfg('upload_conc', 2))) . '"></label>' .
        '<label class="field"><span class="field-l">' . t('下载线程数') . '</span><input class="input" name="dl_threads" type="number" min="1" max="8" value="' . max(1, min(8, (int)cfg('dl_threads', 3))) . '"></label>' .
        '<label class="field"><span class="field-l">' . t('最大带宽（Mbps，0 不限）') . '</span><input class="input" name="dl_max_mbps" type="number" min="0" max="1000" step="0.1" value="' . e((string)max(0, (float)cfg('dl_max_mbps', 0))) . '">' .
        '<span class="hint">v1.47.0 大文件传输：超过分块大小的文件自动分块直传（绕开 post_max_size 硬顶、断点重试、逐块进度）；点击文本 / 压缩包类附件改为站内预览或多线程下载，不再跳转浏览器查看；最大带宽对每连接生效（多线程下载自动均分配额）</span></label>' .
        '</div>' .
        '<div class="form-foot"><span></span><button class="btn btn-primary" type="submit">' . t('保存内容与限制') . '</button></div></form>' .
        admin_restore_btn('thread_title_max,thread_len_max,reply_len_max,name_max,sign_len_max,upload_per_post,upload_img_max_mb,upload_file_max_mb,upload_allow_ext,upload_chunk_mb,upload_conc,dl_threads,dl_max_mbps') . '</div>';
}

/* ---------------- v1.21.0 SEO（搜索引擎展示）卡片 ---------------- */
function admin_card_seo(): string
{
    $robots = (string)cfg('seo_robots', '');
    $robSel = function (string $v) use ($robots): string {
        return '<option value="' . e($v) . '"' . ($robots === $v ? ' selected' : '') . '>' . e($v === '' ? '默认（index, follow）' : $v) . '</option>';
    };
    $smOn = (int)cfg('seo_sitemap', 0) === 1;
    /* v1.35.0 robots.txt 一键同步（同意式自改服务器文件：覆盖前先自动备份原文件） */
    $robFile = dirname(DATA_DIR) . '/robots.txt';
    $robRaw = is_file($robFile) ? (string)@file_get_contents($robFile) : '';
    $robRec = mf_robots_recommended(false);
    /* v1.35.1 预检站点根目录写权限：不可写时不展示写盘按钮，改走「下载 → FTP 上传」降级方案 */
    [$robFileW, $robRootW] = mf_root_write_check($robFile);
    $robManual = !$robFileW && !$robRootW;
    if (mf_robots_is_recommended($robRaw)) {
        $robSync = '<p class="hint" style="color:var(--ok)">✔ ' . t('站点根目录 robots.txt 已与推荐内容一致，无需同步') . '</p>';
    } elseif ($robManual) {
        $robSync = '<p class="hint" style="color:var(--danger)"><b>检测到站点根目录对 PHP 不可写——无法自动创建 / 覆盖 robots.txt（你的现有文件未被改动）。</b>点下方按钮下载推荐内容文件，通过 FTP / 主机面板上传到站点根目录（覆盖原文件；如需保留现有内容，请先自行备份）。</p>' .
            '<form method="post" action="' . e(u('a=admin_file_download')) . '" class="inline">' . csrf_field() .
            '<input type="hidden" name="what" value="robots">' .
            '<button class="btn btn-primary" type="submit">' . t('下载 robots.txt（推荐内容）') . '</button></form>';
    } elseif ($robRaw === '') {
        $robSync = '<form method="post" action="' . e(u('a=admin_robots_sync')) . '" class="inline" data-n="' . e(t('将在站点根目录创建 robots.txt（推荐内容见下方）。确认创建？')) . '">' . csrf_field() .
            '<button class="btn btn-primary" type="submit">' . t('一键同步 robots.txt（创建）') . '</button></form>' .
            '<p class="hint">站点根目录尚未创建 robots.txt。点击按钮即可按下方推荐内容自动创建；也可通过 FTP 自行上传。</p>';
    } else {
        $robSync = '<form method="post" action="' . e(u('a=admin_robots_sync')) . '" class="inline" data-n="' . e(t('站点根目录已存在 robots.txt，内容与推荐不一致。确认后：先自动备份原文件到 data/backup/，再覆盖为下方推荐内容。继续？')) . '">' . csrf_field() .
            '<button class="btn btn-primary" type="submit">' . t('一键同步 robots.txt（备份并覆盖）') . '</button></form>' .
            '<p class="hint">站点根目录已存在 robots.txt（' . strlen($robRaw) . ' 字节，与推荐内容不一致）。同步前会先把原文件自动备份到 data/backup/，自定义内容不会丢失；不想覆盖则保持现状即可。</p>';
    }
    return '<div class="card form-card"><h2 class="card-title">' . t('SEO · 搜索引擎展示') . '</h2>' .
        '<form method="post" action="' . e(u('a=admin_save_seo')) . '" data-ajax="1">' . csrf_field() .
        '<label class="field"><span class="field-l">' . t('标题模板') . '</span><input class="input" name="seo_title_tpl" maxlength="100" placeholder="{page} · {site}" value="' . e((string)cfg('seo_title_tpl', '{page} · {site}')) . '">' .
        '<span class="hint">{page} = 当前页面名，{site} = 论坛名称；首页仅显示 {site}。恢复默认请填 {page} · {site}</span></label>' .
        '<label class="field"><span class="field-l">' . t('站点描述（搜索结果摘要）') . '</span><textarea class="input" name="seo_description" rows="2" maxlength="200" placeholder="留空自动使用「论坛简介">' . e((string)cfg('seo_description', '')) . '</textarea></label>' .
        '<label class="field"><span class="field-l">' . t('关键词') . '</span><input class="input" name="seo_keywords" maxlength="200" placeholder="论坛, 社区, …（英文逗号分隔）" value="' . e((string)cfg('seo_keywords', '')) . '"></label>' .
        '<div class="grid3">' .
        '<label class="field"><span class="field-l">' . t('搜索引擎收录') . '</span><select class="input" name="seo_robots">' . $robSel('') . $robSel('noindex, follow') . $robSel('index, nofollow') . $robSel('noindex, nofollow') . '</select>' .
        '<span class="hint">noindex = 不收录；nofollow = 不顺着链接抓取</span></label>' .
        '<label class="field"><span class="field-l">' . t('Sitemap 站点地图') . '</span><select class="input" name="seo_sitemap"><option value="1"' . ($smOn ? ' selected' : '') . '>' . t('开启（index.php?p=sitemap）') . '</option><option value="0"' . (!$smOn ? ' selected' : '') . '>' . t('关闭') . '</option></select>' .
        '<span class="hint">自动生成 XML 站点地图供搜索引擎提交；仅含公开可浏览内容</span></label>' .
        '<label class="field"><span class="field-l">' . t('Sitemap 收录上限（条）') . '</span><input class="input" name="seo_sitemap_limit" type="number" min="10" max="5000" value="' . (int)cfg('seo_sitemap_limit', 500) . '"></label>' .
        '</div>' .
        '<label class="check"><input type="hidden" name="seo_og" value="0"><input type="checkbox" name="seo_og" value="1"' . ((int)cfg('seo_og', 1) === 1 ? ' checked' : '') . '>' . t(' 输出 Open Graph 社交分享标签（分享到微信 / QQ / X 等展示标题、描述与图标）') . '</label>' .
        '<label class="field"><span class="field-l">' . t('自定义 &lt;head&gt; 内容（可选）') . '</span><textarea class="input" name="seo_extra_head" rows="3" maxlength="2000" placeholder="站点验证、搜索控制台、统计代码等">' . e((string)cfg('seo_extra_head', '')) . '</textarea>' .
        '<span class="hint">原样插入每个页面 &lt;/head&gt; 之前；请只填写可信内容</span></label>' .
        '<div class="form-foot"><span class="muted">别忘了同步服务器的 robots.txt（下方一键同步；推荐内容见展开区）</span><button class="btn btn-primary" type="submit">' . t('保存 SEO 设置') . '</button></div>' .
        '<span class="test-msg muted"></span></form>' .
        admin_restore_btn('seo_title_tpl,seo_description,seo_keywords,seo_robots,seo_sitemap,seo_sitemap_limit,seo_og,seo_extra_head') .
        '<details><summary class="muted">robots.txt 推荐内容（点击展开；与「一键同步」写入内容一致）</summary><pre class="code-hint">' . e($robRec) . '</pre></details>' .
        '<div class="form-foot">' . $robSync . '</div></div>';
}

/* ---------------- v1.21.0 界面语言卡片 ---------------- */
function admin_card_lang(): string
{
    $def = (string)cfg('lang_default', 'zh-cn');
    $opts = '';
    foreach (MF_LANGS as $code => $name) {
        $opts .= '<option value="' . e($code) . '"' . ($def === $code ? ' selected' : '') . '>' . e($name) . '（' . e($code) . '）</option>';
    }
    $auto = (int)cfg('lang_auto_ip', 0) === 1;
    return '<div class="card form-card"><h2 class="card-title">' . t('界面语言') . '</h2>' .
        '<form method="post" action="' . e(u('a=admin_save_lang')) . '" data-ajax="1">' . csrf_field() .
        '<div class="grid2">' .
        '<label class="field"><span class="field-l">' . t('默认论坛语言') . '</span><select class="input" name="lang_default">' . $opts . '</select>' .
        '<span class="hint">访客首次访问时的界面语言</span></label>' .
        '<label class="field"><span class="field-l">' . t('按 IP 自动切换语言') . '</span><select class="input" name="lang_auto_ip"><option value="1"' . ($auto ? ' selected' : '') . '>' . t('开启（按访客 IP 归属国家自动匹配）') . '</option><option value="0"' . (!$auto ? ' selected' : '') . '>' . t('关闭（始终用默认语言）') . '</option></select>' .
        '<span class="hint">中国大陆 → 简中；港澳台 → 繁中；日本 → 日语；俄语区 → 俄语；其他 → 英语。用户主动选择的语言优先于自动判断；仅提交 IP 给免费归属地接口，结果缓存 30 天</span></label>' .
        '</div>' .
        '<p class="hint">前台核心界面（导航 / 登录注册 / 发帖回复 / 时间显示等）已支持五语切换；未翻译的深度条目自动以简体中文显示，用户可在「个人设置」长期保存自己的语言。后台管理界面保持中文。</p>' .
        '<div class="form-foot"><span></span><button class="btn btn-primary" type="submit">' . t('保存语言设置') . '</button></div>' .
        '<span class="test-msg muted"></span></form>' .
        admin_restore_btn('lang_default,lang_auto_ip') . '</div>';
}

/* ---------------- 功能总开关（v1.14.0，v1.15.0 扩展 @ 提及与 AI 开关） ---------------- */

/** 后台标签 → 中文名（导航与访问日志共用；v1.27.0 移除「等级」页） */
function admin_tab_titles(): array
{
    return [
        'basic' => '基本', 'feat' => '功能', 'storage' => '存储', 'mail' => '邮件', 'ai' => 'AI', 'boards' => '板块', 'users' => '用户',
        'content' => '内容', 'queue' => 'AI 队列', 'manual' => '人工待审', 'reports' => '举报记录',
        'anns' => '公告', 'theme' => '主题', 'ext' => '拓展', 'docs' => '协议', 'api' => '开放 API', 'logs' => '日志', 'security' => '安全防护', 'monitor' => '监控', 'update' => '更新升级', 'system' => '系统',
    ];
}

/** v1.27.0 后台分组导航：分组名 → 标签页列表（按使用频率与职责归组，共 21 页） */
function admin_tab_groups(): array
{
    return [
        '内容审核' => ['content', 'queue', 'manual', 'reports'],
        '用户与社区' => ['users', 'boards', 'anns', 'docs'],
        '功能与外观' => ['feat', 'theme', 'ext'],
        '站点设置' => ['basic', 'storage', 'mail', 'ai', 'api', 'security'],
        '运行与系统' => ['monitor', 'logs', 'update', 'system'],
    ];
}

/**
 * 各标签页的最低职员角色（v1.27.0 三级角色）：
 * 审核员（mod）仅可见内容审核域 4 页；其余全部需要超级管理员（super）；角色指派动作另由 require_super 把关。
 */
function admin_tab_min_role(string $tab): string
{
    static $mod = ['content' => 1, 'queue' => 1, 'manual' => 1, 'reports' => 1];
    return isset($mod[$tab]) ? 'mod' : 'super';
}

/**
 * 功能开关页（v1.27.0 重构：三卡分组，覆盖全部可开/关的社区能力）。
 * AI 两项直接绑定「AI」页原生配置键（ai_precheck / ai_autopilot），两处开关同一份，避免双开关不同步。
 */
function admin_feat_cards(): array
{
    return [
        '基础功能' => [
            'register'     => ['开放注册', '关闭后新用户无法注册，已有账号不受影响'],
            'guest_browse' => ['游客可浏览', '关闭后未登录访客只能看到登录 / 注册 / 找回密码页（私密论坛模式）'],
            'post'         => ['发帖', '关闭后所有人（含职员）都不能发新帖'],
            'reply'        => ['回复', '关闭后所有人（含职员）都不能回复'],
            'edit'         => ['内容编辑', '作者可在发布后 15 分钟内编辑自己的内容，职员不限时'],
            'del_own'      => ['删除自己的内容', '允许用户删除自己的帖子 / 回复；关闭后只能联系职员删除（v1.27.0）'],
            'signature'    => ['用户签名', '用户可在个人设置填写签名，展示在帖子 / 回复下方与个人主页'],
            'titles'       => ['会员头衔', '按发帖数自动晋升的展示性称号（新成员 → 社区元老，无权限含义）；关闭后不再显示头衔徽章（v1.27.0）'],
            'qr_login'     => ['扫码登录', '登录页出现「扫码登录」页签：已登录的手机等设备扫码确认后，待登录设备自动进入该账号；二维码有效期在下方「扫码登录 · 参数」配置（v1.34.0）'],
        ],
        '互动与社区' => [
            'like'    => ['点赞', ''],
            'report'  => ['举报', '隐藏前台举报入口；AI 审核队列与防火墙不受影响'],
            'mention' => ['@ 提及通知', '内容中 @用户名 时，系统自动给对方发送站内通知；被提及名字会高亮展示（v1.15.0）'],
            'search'  => ['站内搜索', ''],
            'search_suggest' => ['搜索预览', '搜索框输入时实时下拉显示匹配的帖子（标题优先，近 500 帖内），点击直达帖子；关闭后搜索仍可用但不显示预览（v1.30.0）'],
            'upload'  => ['允许上传图片 / 附件', '发帖 / 回复 / 编辑表单出现上传按钮：图片与附件存于 data/uploads，大小、类型与单帖数量在「基本设置 · 内容与限制」中配置（v1.32.0）'],
            'emoji'   => ['表情选择器', '仅隐藏输入框上方的表情按钮，Markdown 表情短代码仍可正常渲染'],
            'online'  => ['在线名单', '关闭后侧栏在线人数不再可点击查看名单页'],
        ],
        '界面与 AI' => [
            'motion'       => ['界面动效', '页面切换过渡、进场动画、导航进度条与链接预取：高延迟服务器上依然顺滑流畅（v1.23.0）'],
            'ai_precheck'  => ['AI 审查（发帖 / 编辑 / 用户名）', '每条帖子 / 回复发布后与内容编辑后都会交给 AI 审查；用户名在注册与修改时同步审查；违规自动隐藏 / 拒绝、通知作者可申诉；模型与严格程度在「AI」页配置'],
            'ai_autopilot' => ['AI 自主管理', 'AI 无人值守巡逻：自主封禁风险 IP / 解除误封 / 邮件警报管理员；间隔与红线在「安全防护」页配置'],
        ],
    ];
}

function admin_tab_feat(): void
{
    $cards = admin_feat_cards();
    $aiKeys = ['ai_precheck', 'ai_autopilot'];
    echo '<p class="hint" style="margin-bottom:14px">' . e(t('勾选即启用，取消勾选即关闭；保存后即时生效，被关闭的功能在前台隐藏入口、在后台直接拦截。')) . '</p>';
    /* 三卡共用一个表单：一次保存全部开关 */
    echo '<form method="post" action="' . e(u('a=admin_save_feat')) . '" data-ajax="1">' . csrf_field();
    $allKeys = [];
    foreach ($cards as $cardName => $items) {
        echo '<div class="card form-card"><h2 class="card-title">' . e(t($cardName)) . '</h2>';
        foreach ($items as $k => [$name, $desc]) {
            /* AI 两项的原生配置键不带 feat_ 前缀（还原默认注册表按原键登记） */
            $allKeys[] = in_array($k, $aiKeys, true) ? $k : 'feat_' . $k;
            $on = in_array($k, $aiKeys, true) ? (int)cfg($k, 1) === 1 : feat_on($k);
            echo '<label class="feat-row"><input type="hidden" name="feat_' . e($k) . '" value="0"><input type="checkbox" class="feat-check" name="feat_' . e($k) . '" value="1"' . ($on ? ' checked' : '') . '>' .
                '<span class="feat-txt"><b>' . e(t($name)) . '</b>' . ($desc !== '' ? '<span class="muted">' . e(t($desc)) . '</span>' : '') . '</span>' .
                '<span class="badge' . ($on ? ' badge-ok' : '') . '" data-feat-state="' . e($k) . '">' . e(t($on ? '开启' : '关闭')) . '</span></label>';
        }
        echo '</div>';
    }
    echo '<div class="card form-card"><div class="form-foot"><span class="muted">' . e(t('数据安全不受影响：关闭任何开关都不会删除数据，重新勾选即恢复')) . '</span>' .
        '<button class="btn btn-primary" type="submit">' . e(t('保存功能开关')) . '</button></div>' .
        '<span class="test-msg muted"></span></form>' .
        admin_restore_btn(implode(',', $allKeys)) . '</div>';

    /* v1.34.0 扫码登录 · 参数（总开关在上方「基础功能」的「扫码登录」） */
    echo '<div class="card form-card"><h2 class="card-title">' . e(t('扫码登录 · 参数')) . '</h2>' .
        '<form method="post" action="' . e(u('a=admin_save_qr')) . '" data-ajax="1">' . csrf_field() .
        '<label class="field"><span class="field-l">' . e(t('二维码有效期（秒）')) . '</span>' .
        '<input class="input input-sm" type="number" name="qr_ttl" min="60" max="600" step="10" value="' . (int)cfg('qr_ttl', 180) . '"></label>' .
        '<p class="hint">' . e(t('有效期 60～600 秒，过期后待登录设备需重新生成二维码；每次登录尝试生成一次性令牌，确认后立即作废。')) . '</p>' .
        '<div class="form-foot"><span class="muted">' . e(t('总开关在「功能总开关」的「扫码登录」')) . '</span>' .
        '<button class="btn btn-primary" type="submit">' . e(t('保存扫码设置')) . '</button></div>' .
        '<span class="test-msg muted"></span></form></div>';
}

/* ---------------- v1.21.0 存储引擎（文件 ⇄ 数据库，双向无损迁移） ---------------- */
function admin_tab_storage(): void
{
    $engine = (string)cfg('storage_engine', 'file');
    $isDb = $engine === 'db' && Store::engine() === 'db';
    $conf = db_conf();
    $drvAvail = class_exists('PDO') ? PDO::getAvailableDrivers() : [];
    $fstat = FileStore::stats();

    /* ---- 卡片 1：当前状态 ---- */
    echo '<div class="card form-card"><h2 class="card-title">' . t('存储引擎') . '</h2>';
    if ($isDb) {
        $dstat = DbStore::stats();
        echo '<p><span class="badge badge-ok">当前：数据库存储</span> <span class="muted">' . e(strtoupper(DbStore::kind()))
            . ($dstat !== [] ? ' · ' . e(DbStore::serverInfo()) : '') . ' · 数据表 ' . e(DbStore::table()) . '</span></p>' .
            '<p class="hint">数据行 ' . number_format($dstat['rows']) . ' 条 · 数据体积约 ' . e(fmt_bytes($dstat['bytes']))
            . '。会话 / 命名锁 / 上传文件 / 日志 / 备份包始终存放于文件系统（跨进程互斥不依赖数据库，可靠性更高）。</p>';
    } else {
        echo '<p><span class="badge badge-ok">当前：文件存储</span> <span class="muted">数据位于 data/ 目录</span></p>' .
            '<p class="hint">数据文件 ' . number_format($fstat['files']) . ' 个 · 占用约 ' . e(fmt_bytes($fstat['bytes']))
            . '（不含会话 / 上传 / 备份）。全部数据为带守卫前缀的 gzip 压缩 JSON，原子写入 + 文件锁。</p>';
    }
    echo '<p class="hint">两种引擎完全兼容：帖子、回复、用户、板块、公告、配置、防火墙规则、设备记录等全部业务数据可随时<b>无损双向迁移</b>（迁移前自动把源数据完整备份到 data/backup/）；引擎切换即时生效，随时可切回。</p></div>';

    /* ---- 卡片 2：数据库连接 ---- */
    $drvOpts = '';
    foreach ([['sqlite', 'SQLite（零配置，单文件库）'], ['mysql', 'MySQL / MariaDB'], ['pgsql', 'PostgreSQL']] as [$dv, $dn]) {
        $ok = in_array($dv, $drvAvail, true);
        $drvOpts .= '<option value="' . $dv . '"' . (($conf['db_driver'] ?: 'sqlite') === $dv ? ' selected' : '') . (!$ok ? ' disabled' : '') . '>'
            . e($dn . (!$ok ? '（PHP 未启用 pdo_' . $dv . ' 扩展）' : '')) . '</option>';
    }
    echo '<div class="card form-card"><h2 class="card-title">' . t('数据库连接') . '</h2>' .
        '<form method="post" action="' . e(u('a=admin_save_storage')) . '" data-ajax="1">' . csrf_field() .
        '<div class="grid2">' .
        '<label class="field"><span class="field-l">' . t('数据库类型') . '</span><select class="input" name="db_driver">' . $drvOpts . '</select></label>' .
        '<label class="field"><span class="field-l">' . t('表前缀') . '</span><input class="input" name="db_prefix" maxlength="32" placeholder="mf_" value="' . e($conf['db_prefix'] !== '' ? $conf['db_prefix'] : 'mf_') . '"></label>' .
        '</div>' .
        '<label class="field"><span class="field-l">' . t('SQLite 数据库文件（仅 SQLite 使用）') . '</span><input class="input" name="db_sqlite_path" maxlength="200" placeholder="db/forum.sqlite（相对 data/，留空用默认）" value="' . e($conf['db_sqlite_path']) . '"></label>' .
        '<div class="grid2">' .
        '<label class="field"><span class="field-l">' . t('主机（MySQL / PG）') . '</span><input class="input" name="db_host" maxlength="120" placeholder="127.0.0.1" value="' . e($conf['db_host']) . '"></label>' .
        '<label class="field"><span class="field-l">' . t('端口（可留空）') . '</span><input class="input" name="db_port" maxlength="5" placeholder="3306 / 5432" value="' . e($conf['db_port']) . '"></label>' .
        '</div>' .
        '<div class="grid2">' .
        '<label class="field"><span class="field-l">' . t('数据库名（MySQL / PG）') . '</span><input class="input" name="db_name" maxlength="64" value="' . e($conf['db_name']) . '"></label>' .
        '<label class="field"><span class="field-l">' . t('用户名') . '</span><input class="input" name="db_user" maxlength="64" value="' . e($conf['db_user']) . '"></label>' .
        '</div>' .
        '<label class="field"><span class="field-l">' . t('密码') . '</span><input class="input" name="db_pass" type="password" maxlength="128" value="' . e($conf['db_pass']) . '"></label>' .
        '<div class="form-foot">' .
        '<button class="btn btn-ghost" type="button" data-admin-test="db">测试连接（保存前可先验证）</button>' .
        '<button class="btn btn-primary" type="submit">' . t('保存连接信息') . '</button></div>' .
        '<span class="test-msg muted"></span></form>' .
        admin_restore_btn('db_driver,db_prefix,db_sqlite_path,db_host,db_port,db_name,db_user,db_pass') .
        '<p class="hint">连接信息保存在文件侧配置（data/config.php）——引擎引导发生在读取数据库之前，这样即使数据库不可用，论坛也能读取配置并回退文件模式继续运行。测试连接会自动建表（若尚不存在）。SQLite 请放在 data/db/ 目录下以获得 .htaccess 保护。还原默认不会改变当前存储引擎选择</p></div>';

    /* ---- 卡片 3：双向无损迁移 ---- */
    if (!$isDb) {
        $dirLabel = '文件 → 数据库';
        $dirVal = 'to_db';
        $dirHint = '把 data/ 下全部数据文件迁入上方配置的数据库，完成后自动切换为数据库存储。执行前请先保存连接信息并「测试连接」通过。';
    } else {
        $dirLabel = '数据库 → 文件';
        $dirVal = 'to_file';
        $dirHint = '把数据库内全部数据行迁回 data/ 文件，完成后自动切换回文件存储。';
    }
    echo '<div class="card form-card"><h2 class="card-title">迁移（' . e($dirLabel) . '）</h2>' .
        '<form method="post" action="' . e(u('a=admin_storage_migrate')) . '" data-confirm="确认执行迁移？系统会先把当前全部数据自动备份到 data/backup/，再逐条无损迁移并切换引擎。建议在低峰期操作。">' . csrf_field() .
        '<input type="hidden" name="direction" value="' . $dirVal . '">' .
        '<p class="hint">' . e($dirHint) . '</p>' .
        '<p class="hint">迁移范围：data/ 下除 sessions / locks / logs / backup / upload / db 之外的全部数据。迁移是幂等的——迁移窗口期的新发帖可在完成后再次执行同向迁移对齐；每次迁移的备份包都留在 data/backup/ 可随时找回。</p>' .
        '<div class="form-foot"><span></span><button class="btn btn-primary" type="submit">' . t('开始迁移') . '</button></div></form></div>';

    /* ---- 卡片 4：数据库管理（仅数据库模式，与文件模式管理能力对齐） ---- */
    if ($isDb) {
        echo '<div class="card form-card"><h2 class="card-title">' . t('数据库管理') . '</h2>' .
            '<div class="grid3">' .
            '<form method="post" action="' . e(u('a=admin_storage_backup')) . '">' . csrf_field() . '<button class="btn btn-ghost" type="submit">' . t('导出数据库备份（zip）') . '</button></form>' .
            '<form method="post" action="' . e(u('a=admin_storage_optimize')) . '">' . csrf_field() . '<button class="btn btn-ghost" type="submit">' . t('优化（回收空间 / 碎片整理）') . '</button></form>' .
            '<form method="post" action="' . e(u('a=admin_storage_check')) . '">' . csrf_field() . '<button class="btn btn-ghost" type="submit">' . t('完整性检查') . '</button></form>' .
            '</div>' .
            '<p class="hint">备份包（含全部数据行原文与恢复说明）存于 data/backup/，与文件模式的「一键备份」并列管理（保留最近 5 份）。「优化」：SQLite 执行 VACUUM / MySQL 执行 OPTIMIZE TABLE / PG 执行 VACUUM ANALYZE。</p></div>';
    }
}

/* ---------------- 邮件 ---------------- */
function admin_tab_mail(): void
{
    echo '<div class="card form-card"><form id="mail-form">' .
        '<div class="grid2">' .
        '<label class="field"><span class="field-l">' . t('SMTP 主机') . '</span><input class="input" name="smtp_host" required placeholder="smtp.qq.com" value="' . e((string)cfg('smtp_host')) . '"></label>' .
        '<label class="field"><span class="field-l">' . t('SMTP 端口') . '</span><input class="input" name="smtp_port" type="number" min="1" max="65535" value="' . (int)cfg('smtp_port', 465) . '"><span class="hint">465 为 SSL；587 / 25 自动尝试 STARTTLS</span></label>' .
        '</div>' .
        '<label class="field"><span class="field-l">' . t('直连 IP（可选）') . '</span><input class="input" name="smtp_direct_ip" placeholder="留空即可；服务器域名解析故障时填写，如 120.232.69.34" value="' . e((string)cfg('smtp_direct_ip', '')) . '"><span class="hint">DNS 故障兜底开关：填写后跳过域名解析直接连该 IP，证书校验仍按 SMTP 主机名进行，安全性不变。v1.43.0 起解析层已内置四层回退（域名 → hosts → 公共 DNS → 此字段），一般无需填写；IP 变化时请更新（tools/smtp-doctor.php 可自动获取）</span></label>' .
        '<label class="field"><span class="field-l">' . t('发信邮箱') . '</span><input class="input" name="smtp_from" type="email" required value="' . e((string)cfg('smtp_from')) . '"></label>' .
        '<label class="field"><span class="field-l">' . t('授权码') . '</span><input class="input" name="smtp_pass" value="' . e((string)cfg('smtp_pass')) . '" placeholder="邮箱服务商提供的 SMTP 授权码（非登录密码）"></label>' .
        '<div class="form-foot">' .
        '<button class="btn btn-primary" type="button" data-admin-save="mail">保存邮件设置</button>' .
        '<button class="btn btn-ghost" type="button" data-admin-test="mail">测试发信</button>' .
        '</div></form>' .
        '<div class="field"><span class="field-l">' . t('测试收件邮箱') . '</span><div class="code-row"><input class="input" id="mail-test-to" type="email" placeholder="填写一个收件邮箱，当场发一封测试邮件">' .
        '<button class="btn btn-ghost" type="button" data-admin-test="mail" data-to="#mail-test-to">发送测试邮件</button></div>' .
        '<span class="test-msg muted"></span></div>' .
        admin_restore_btn('smtp_host,smtp_port,smtp_direct_ip,smtp_from,smtp_pass') . '</div>';
}

/* ---------------- AI ---------------- */
function admin_tab_ai(): void
{
    $list = ai_models_all();
    $st = ai_state_read();
    $limit = ai_fail_limit();
    $active = (int)$st['active'];
    $strict = ai_strict_norm((string)cfg('ai_strict', 'standard'));
    $lvName = ['loose' => '宽松', 'standard' => '标准', 'strict' => '严格'][$strict];

    /* ---- 卡片 1：审核模型（多 API 密钥 / 多模型 + 自动切换状态） ---- */
    echo '<div class="card form-card"><h2 class="card-title">审核模型（' . count($list) . '）</h2>' .
        '<p class="hint">可自由添加多个 API 密钥与模型（OpenAI 兼容）。审核模式三选一：标准（按列表顺序调用，某模型连续失败达到「自动切换阈值」后自动换下一个模型，全部失败才转人工审核）；并行火力（多个模型同刻并发、各审队列中不同的一条，吞吐成倍提升）；全火力全开（每条内容同时交给所有启用的模型一起审核，任一违规即违规）。</p>' .
        '<details class="ai-model-add"' . ($list ? '' : ' open') . '><summary>' . ($list ? '＋ 添加模型' : '＋ 添加第一个模型') . '</summary>' .
        '<form method="post" action="' . e(u('a=admin_model_new')) . '" class="ai-model-form">' . csrf_field() .
        '<div class="grid2">' .
        '<label class="field"><span class="field-l">' . t('备注名称（可选）') . '</span><input class="input" name="name" maxlength="30" placeholder="如 GLM 主力 / 免费备用"></label>' .
        '<label class="field"><span class="field-l">' . t('模型名称') . '</span><input class="input" name="model" required maxlength="100" placeholder="如 gpt-4o-mini / glm-4-flash"></label>' .
        '</div>' .
        '<label class="field"><span class="field-l">' . t('API 地址（OpenAI 兼容）') . '</span><input class="input" name="url" required maxlength="200" placeholder="https://api.openai.com 或 https://host/v1"><span class="hint">支持填根地址、/v1 或完整 /chat/completions</span></label>' .
        '<label class="field"><span class="field-l">' . t('API 密钥') . '</span><input class="input" name="key" required maxlength="200"></label>' .
        '<label class="check"><input type="checkbox" name="on" value="1" checked>' . t(' 立即启用该模型') . '</label>' .
        '<div class="form-foot"><span></span><button class="btn btn-primary" type="submit">' . t('添加模型') . '</button></div>' .
        '</form></details>';

    if (!$list) {
        echo empty_state('还没有模型：添加一个即可开启 AI 自动审核');
    } else {
        echo '<div class="admin-list">';
        foreach ($list as $m) {
            $id = (int)$m['id'];
            $fails = (int)($st['fail'][$id] ?? 0);
            $tripped = $fails >= $limit;
            $isActive = $m['on'] && ai_model_ok($m) && $id === $active;
            $badge = !$m['on']
                ? '<span class="badge">已停用</span>'
                : ($isActive
                    ? '<span class="badge badge-ok">主力</span>'
                    : ($tripped
                        ? '<span class="badge badge-danger">连续失败 ' . $fails . ' 次 · 已切换</span>'
                        : ($fails > 0
                            ? '<span class="badge badge-warn">连续失败 ' . $fails . ' 次</span>'
                            : '<span class="badge badge-info">备用</span>')));
            echo '<div class="ai-model-row">' .
                '<div class="ai-model-head">' .
                '<span class="ai-model-name">' . e(ai_model_label($m)) . ' <code class="muted">' . e($m['model']) . '</code></span>' .
                $badge .
                '<span class="row-ops">' .
                '<button class="btn btn-ghost btn-sm" type="button" data-admin-test="ai" data-id="' . $id . '">测试</button>' .
                ($fails > 0
                    ? '<form method="post" action="' . e(u('a=admin_model_reset')) . '" class="inline"><input type="hidden" name="id" value="' . $id . '">' . csrf_field() . '<button class="btn btn-ghost btn-sm" type="submit" title="清零失败计数，恢复参与审核">清零</button></form>'
                    : '') .
                '<form method="post" action="' . e(u('a=admin_model_move')) . '" class="inline"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="dir" value="up">' . csrf_field() . '<button class="btn btn-ghost btn-sm" type="submit" title="上移">↑</button></form>' .
                '<form method="post" action="' . e(u('a=admin_model_move')) . '" class="inline"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="dir" value="down">' . csrf_field() . '<button class="btn btn-ghost btn-sm" type="submit" title="下移">↓</button></form>' .
                '<form method="post" action="' . e(u('a=admin_model_del')) . '" class="inline" data-confirm="确认删除该模型？其失败记录将一并清除"><input type="hidden" name="id" value="' . $id . '">' . csrf_field() . '<button class="btn btn-ghost btn-sm danger" type="submit">' . t('删除') . '</button></form>' .
                '</span></div>' .
                '<details><summary class="muted">编辑配置</summary>' .
                '<form method="post" action="' . e(u('a=admin_model_save')) . '" class="ai-model-form">' . csrf_field() .
                '<input type="hidden" name="id" value="' . $id . '">' .
                '<div class="grid2">' .
                '<label class="field"><span class="field-l">' . t('备注名称（可选）') . '</span><input class="input" name="name" maxlength="30" value="' . e($m['name']) . '"></label>' .
                '<label class="field"><span class="field-l">' . t('模型名称') . '</span><input class="input" name="model" required maxlength="100" value="' . e($m['model']) . '"></label>' .
                '</div>' .
                '<label class="field"><span class="field-l">' . t('API 地址') . '</span><input class="input" name="url" required maxlength="200" value="' . e($m['url']) . '"></label>' .
                '<label class="field"><span class="field-l">' . t('API 密钥') . '</span><input class="input" name="key" required maxlength="200" value="' . e($m['key']) . '"></label>' .
                '<label class="check"><input type="checkbox" name="on" value="1"' . ($m['on'] ? ' checked' : '') . '>' . t(' 启用该模型') . '</label>' .
                '<div class="form-foot"><span></span><button class="btn btn-ghost btn-sm" type="submit">' . t('保存修改') . '</button></div>' .
                '</form></details>' .
                '</div>';
        }
        echo '</div>' .
            '<p class="hint">列表顺序即审核优先级（↑↓ 调整）；「主力」= 上次审核成功、下次优先使用的模型；失败计数达到阈值后自动排到队尾，审核成功会自动恢复并清零计数。旧版后台填写的单模型配置已自动迁移为列表第一条。</p>';
    }
    echo '</div>';

    /* ---- 卡片 2：审核策略 ---- */
    echo '<div class="card form-card"><form id="ai-form">' .
        '<div class="grid2">' .
        '<label class="field"><span class="field-l">' . t('单模型重试次数') . '</span><input class="input" name="ai_retries" type="number" min="1" max="10" value="' . (int)cfg('ai_retries', 2) . '"><span class="hint">同一模型单次审核内的 HTTP 重试次数（1-10；v1.18.0 起默认 2，降低最坏情况的叠加等待）</span></label>' .
        '<label class="field"><span class="field-l">' . t('单次请求超时（秒）') . '</span><input class="input" name="ai_timeout" type="number" min="5" max="120" value="' . (int)cfg('ai_timeout', 20) . '"><span class="hint">等待 AI 返回的最长时间（5-120 秒；v1.18.0 起默认 20 秒）。模型平时响应慢就调大，想快速失败换模型就调小</span></label>' .
        '<label class="field"><span class="field-l">' . t('自动切换阈值（连续失败 N 次）') . '</span><input class="input" name="ai_fail_limit" type="number" min="1" max="20" value="' . $limit . '"><span class="hint">某模型连续失败达到该次数后，后续审核自动换下一个模型（默认 3）</span></label>' .
        '</div>' .
        '<label class="field"><span class="field-l">' . t('审核模式（火力）') . '</span>' .
        '<label class="check"><input type="radio" name="ai_mode" value="normal"' . (ai_mode() === 'normal' ? ' checked' : '') . '> <b>标准</b> —— 按模型顺序审核，失败自动切换（消耗最低）</label>' .
        '<label class="check"><input type="radio" name="ai_mode" value="parallel"' . (ai_mode() === 'parallel' ? ' checked' : '') . '> <b>并行火力</b> —— 队列一次取出多条，多个模型<b>同刻并发、各审一条</b>（模型有几个就同时审几条，吞吐成倍提升）；只剩一条时自动改为<b>双模型竞速</b>——两个模型同刻出发审同一条，先返回者胜出，单条等待时间约减半</label>' .
        '<label class="check"><input type="radio" name="ai_mode" value="fullpower"' . (ai_mode() === 'fullpower' ? ' checked' : '') . '> <b>全火力全开</b> —— 每条内容同时交给<b>所有启用的模型</b>一起审核：任一模型判定违规即判违规，全部无问题才放行（严判，API 消耗与启用模型数成正比）</label>' .
        '</label>' .
        '<label class="field"><span class="field-l">' . t('审核严格程度') . '</span><select name="ai_strict" class="input" style="max-width:100%">' .
        '<option value="loose"' . ($strict === 'loose' ? ' selected' : '') . '>' . t('宽松 —— 拿不准一律不违规（创作 / 交流型论坛友好）') . '</option>' .
        '<option value="standard"' . ($strict === 'standard' ? ' selected' : '') . '>' . t('标准 —— 直接命中违规类别才判违规（推荐）') . '</option>' .
        '<option value="strict"' . ($strict === 'strict' ? ' selected' : '') . '>' . t('严格 —— 擦边 / 变体字规避审查也判违规（合规要求高时用）') . '</option>' .
        '</select><span class="hint">发给 AI 的审核提示词由程序内置<b>固定</b>（保证审核口径稳定、可预期、不被误改），此处仅选择注入哪一档「判定尺度」，保存后对后续每次审核立即生效。</span></label>' .
        '<details style="margin-top:2px"><summary class="muted">查看审核提示词：固定主体 + 三档判定尺度（当前使用：' . e($lvName) . '）</summary>' .
        '<div class="prompt-box"><b>固定主体（每次审核都发送）</b><pre>' . e(AI_SYSTEM_PROMPT) . '</pre></div>' .
        '<div class="prompt-box' . ($strict === 'loose' ? ' on' : '') . '"><b>宽松' . ($strict === 'loose' ? '（当前）' : '') . '</b><pre>' . e(AI_STRICT_LEVELS['loose']) . '</pre></div>' .
        '<div class="prompt-box' . ($strict === 'standard' ? ' on' : '') . '"><b>标准' . ($strict === 'standard' ? '（当前）' : '') . '</b><pre>' . e(AI_STRICT_LEVELS['standard']) . '</pre></div>' .
        '<div class="prompt-box' . ($strict === 'strict' ? ' on' : '') . '"><b>严格' . ($strict === 'strict' ? '（当前）' : '') . '</b><pre>' . e(AI_STRICT_LEVELS['strict']) . '</pre></div>' .
        '</details>' .
                '<div class="form-foot">' .
        '<button class="btn btn-primary" type="button" data-admin-save="ai">保存 AI 设置</button>' .
        '<button class="btn btn-ghost" type="button" data-admin-test="ai">测试主力模型</button>' .
        '</div>' .
        '<span class="test-msg muted"></span></form>' .
        admin_restore_btn('ai_strict,ai_mode,ai_retries,ai_timeout,ai_fail_limit,ai_precheck,ai_autopilot,ai_patrol_interval,ai_patrol_ban_limit,ai_patrol_alert,ai_fullpower') .
        '<p class="hint">标准模式每次处理队列中的一条（先进先出，随页面访问自动触发）；并行火力模式每次并发处理多条；自动切换后仍全部失败时，内容保持隐藏并通知管理员人工处理（后台「AI 待审队列」）。发帖 AI 预检在下方勾选；AI 自主管理（无人值守巡逻）在「安全防护」页配置。</p></div>';

}



/* ---------------- 协议（v1.16.0） ---------------- */
function admin_tab_docs(): void
{
    $defs = [
        'doc_terms'      => ['用户协议', '注册与使用本站时双方的权利义务。启用后注册页强制勾选确认'],
        'doc_privacy'    => ['隐私政策', '说明本站收集哪些数据（IP 日志、Cookie、邮箱等）与用途。合规站点建议填写'],
        'doc_disclaimer' => ['免责声明', '本站对用户发布内容的免责说明。UGC 站点建议填写'],
    ];
    echo '<div class="card form-card"><h2 class="card-title">' . t('协议管理') . '</h2>' .
        '<p class="muted">填写内容后即自动启用该协议：出现在注册页确认、协议门禁与页脚入口（按下方开关）。支持直接输入多行纯文本；URL 会自动变成可点击链接。没有现成文案？点每栏的「填入内置模板」一键生成——内置模板中的【站名】【站长邮箱】【更新日期】三个占位符<b>请勿手动替换</b>，系统会自动用下方预填项的值填入。</p>' .
        '<p class="hint">前台展示：访客通过页脚或 <a href="' . e(u('p=doc')) . '">协议中心</a> 查看协议——协议中心与每份协议都有独立的完整大页面（自动生成目录、版本号与更新时间，支持打印 / 保存 PDF）。</p>' .
        '<form method="post" action="' . e(u('a=admin_save_docs')) . '" data-ajax="1">' . csrf_field();
    /* v1.24.0 内置模板预填项：站名 / 站长邮箱 / 更新日期自动替换模板占位符（请勿手动替换）；
       站长邮箱默认取 SMTP 发信邮箱，未配置 SMTP 时回退第一个管理员账号的邮箱 */
    $pfMail = (string)cfg('smtp_from', '');
    if ($pfMail === '') {
        foreach (user_all() as $au) {
            if (!empty($au['admin']) && empty($au['deleted']) && (string)($au['email'] ?? '') !== '') {
                $pfMail = (string)$au['email'];
                break;
            }
        }
    }
    echo '<div class="grid3" id="doc-prefill">' .
        '<label class="field"><span class="field-l">' . t('站名') . '</span><input class="input" id="doc-pf-site" type="text" value="' . e((string)cfg('site_name', '')) . '" placeholder="' . t('用于替换模板中的【站名】') . '"></label>' .
        '<label class="field"><span class="field-l">' . t('站长邮箱') . '</span><input class="input" id="doc-pf-mail" type="email" value="' . e($pfMail) . '" placeholder="' . t('用于替换模板中的【站长邮箱】') . '"></label>' .
        '<label class="field"><span class="field-l">' . t('更新日期') . '</span><input class="input" id="doc-pf-date" type="date" value="' . e(gmdate('Y-m-d', time() + 8 * 3600)) . '"><span class="hint">' . t('用于替换模板中的【更新日期】；修改预填项后重新点「填入内置模板」，内置模板即同步更新为新值') . '</span></label>' .
        '</div>';
    $tpls = doc_templates();
    foreach ($defs as $key => [$name, $desc]) {
        $tk = substr($key, 4); // 模板短键：doc_terms → terms（与 doc_templates()/前端占位节点一致）
        $uTs = doc_updated($tk);
        echo '<label class="field"><span class="field-l">' . e($name) . ($uTs > 0 ? ' <span class="muted" style="font-weight:400">（最后保存：' . gmdate('Y-m-d H:i', $uTs + 8 * 3600) . '）</span>' : '') . '</span>' .
            '<textarea class="input" name="' . $key . '" rows="6" placeholder="（留空 = 未启用）\n' . e($desc) . '" style="min-height:110px">' . e((string)cfg($key, '')) . '</textarea>' .
            '<span class="hint">' . e($desc) . '</span>' .
            '<span class="doc-tpl-row"><button class="btn btn-ghost btn-sm" type="button" data-doc-tpl="' . $tk . '">填入内置模板</button></span>' .
            '</label>';
    }
    echo '<label class="check"><input type="hidden" name="doc_gate" value="0"><input type="checkbox" name="doc_gate" value="1"' . (!empty(cfg('doc_gate', 0)) ? ' checked' : '') . '> <b>访问门禁</b> —— 开启后，未同意协议的访客打开网站任何页面时，都会先看到「请阅读并同意协议」页面，不同意无法进入（登录、注册、协议页除外；管理员不受限，避免把自己锁在门外）。协议内容每次修改后，已同意的用户需重新确认一次</label>' .
        '<label class="check"><input type="hidden" name="doc_footer" value="0"><input type="checkbox" name="doc_footer" value="1"' . (!empty(cfg('doc_footer', 0)) ? ' checked' : '') . '> <b>页脚入口</b> —— 在网站页脚显示「用户协议 / 隐私政策 / 免责声明」查看入口</label>' .
        '<div class="form-foot"><button class="btn btn-primary" type="submit">' . t('保存协议设置') . '</button></div>' .
        '</form>' .
        '<p class="hint">注册页的强制勾选自动生效：只要对应协议已填写，新用户注册时必须勾选「我已阅读并同意」才能提交。</p>';
    /* v1.18.0：同意记录机制说明（用户问"记住隐私协议是在哪儿弄的"，这里说清楚） */
    echo '<div class="card form-card"><h2 class="card-title">' . t('「同意协议」是如何记录的') . '</h2>' .
        '<p class="hint" style="font-size:13.5px;line-height:1.9">' .
        '· <b>当前协议版本号</b>：<b>' . e(strtoupper(substr(doc_fingerprint(), 0, 8))) . '</b>（由三份协议内容计算指纹生成，任何一份内容修改后版本号自动变化）<br>' .
        '· <b>记录位置</b>：访客点「同意」时，本站在其浏览器写入一年期 Cookie（<code>mf_doc</code>，值为「版本指纹.同意时间戳」），并同步写入其登录会话；<br>' .
        '· <b>如何判定已同意</b>：每次访问时比对 Cookie / 会话中的指纹与当前版本号，一致即放行；<br>' .
        '· <b>协议更新后</b>：指纹变化 → 全部旧同意自动失效，访客下次访问需重新确认；<br>' .
        '· <b>留痕</b>：每次同意都会写一条「协议」日志（谁、何时、哪个版本），可在「日志」页检索 <code>doc_agree</code>；<br>' .
        '· <b>豁免</b>：管理员账号不受门禁限制（避免把自己锁在门外）；访客可在前台「协议中心」查看自己的确认状态与时间。</p></div>';
    /* 内置模板（v1.17.0）：以 text/plain 节点随页携带，前端一键填入，不额外发请求 */
    foreach ($tpls as $key => $tpl) {
        echo '<script type="text/plain" id="doc-tpl-' . e($key) . '" hidden>' . e($tpl) . '</script>';
    }
    echo '</div>';
}

/* ---------------- 开放 API（v1.19.0） ---------------- */
function admin_tab_api(): void
{
    $defs = api_defs();
    $termsVal = (string)cfg('api_terms', '');
    $termsEmpty = $termsVal === '';
    if ($termsEmpty) {
        $termsVal = api_terms_template(); // 从未保存过 → 预填内置模板，保存即固化
    }
    echo '<div class="card form-card"><h2 class="card-title">' . t('开放 API') . '</h2>' .
        '<p class="muted">把论坛的能力开放给第三方：用户可在「个人设置 → 开放 API」阅读条款后签发自己的访问令牌，用它开发客户端、机器人或自定义界面。您在这里决定开放与否、开放哪些能力与限速额度；写入类端点与网页端接受<b>完全相同</b>的校验链（功能开关、禁言、发言间隔、字数上限、AI 审核、操作日志）。</p>' .
        '<p class="hint">开发者文档（自动反映下方开放状态）：<a href="' . e(u('p=api_docs')) . '">' . e(u('p=api_docs')) . '</a>。建议把该页链接发给想做客户端的用户。行为审计：「日志」页检索 <code>api_call</code>（写入类调用）与 <code>api_token</code>（签发 / 重置 / 撤销）。</p>' .
        '<form method="post" action="' . e(u('a=admin_save_api')) . '" data-ajax="1">' . csrf_field();

    echo '<label class="check"><input type="hidden" name="api_enabled" value="0"><input type="checkbox" name="api_enabled" value="1"' . ((int)cfg('api_enabled', 0) === 1 ? ' checked' : '') . '> <b>开放 API</b> —— 总开关。关闭后所有 API 请求返回 503，已签发的令牌保留（重新开启即恢复）</label>' .
        '<label class="check"><input type="hidden" name="api_guest" value="0"><input type="checkbox" name="api_guest" value="1"' . ((int)cfg('api_guest', 0) === 1 ? ' checked' : '') . '> <b>允许访客免令牌调用公开端点</b> —— 关闭时只有持令牌的注册用户能调用 API（更严格；私密论坛模式下此开关无效，一律要求令牌）</label>' .
        '<label class="check"><input type="hidden" name="api_log" value="0"><input type="checkbox" name="api_log" value="1"' . ((int)cfg('api_log', 0) === 1 ? ' checked' : '') . '> <b>记录写入类调用日志</b> —— 通过 API 发帖 / 回复 / 点赞 / 删除时写入「日志」（api_call）；读取类不记，防止日志爆量。认证失败一律记录（api_deny 由无效令牌触发，见日志页）</label>' .
        '<div class="grid3">' .
        '<label class="field"><span class="field-l">' . t('令牌限速（次 / 分钟）') . '</span><input class="input" name="api_rate_token" type="number" min="0" max="10000" value="' . (int)cfg('api_rate_token', 120) . '"></label>' .
        '<label class="field"><span class="field-l">' . t('访客限速（次 / 分钟）') . '</span><input class="input" name="api_rate_guest" type="number" min="0" max="10000" value="' . (int)cfg('api_rate_guest', 30) . '"></label>' .
        '<label class="field"><span class="field-l">' . t('跨域来源（CORS，可选）') . '</span><input class="input" name="api_cors" maxlength="500" placeholder="留空=同源；*=全部" value="' . e((string)cfg('api_cors', '')) . '"></label>' .
        '</div>' .
        '<p class="hint">限速填 0 = 不限制（不推荐）。CORS 供浏览器内运行的第三方前端调用：留空=仅同源可用（最安全）；填 <code>*</code> 允许任意网页调用；填具体来源如 <code>https://app.example.com</code>（多个用逗号分隔），只对这些站点的浏览器放行。</p>';

    echo '<h2 class="card-title" style="margin-top:18px">端点开放范围（' . count($defs['endpoints']) . ' 个端点）</h2>' .
        '<p class="hint">取消勾选 = 关闭该端点（调用返回 403）。写入类（标记 ✎）需要令牌；其余端点同时受「允许访客调用」开关约束。</p>';
    foreach ($defs['groups'] as $gk => $gname) {
        echo '<div class="api-ep-group"><b class="api-ep-group-name">' . e($gname) . '</b>';
        foreach ($defs['endpoints'] as $ep => $d) {
            if ($d[0] !== $gk) {
                continue;
            }
            $k = api_ep_key((string)$ep);
            echo '<label class="check api-ep"><input type="checkbox" name="' . $k . '" value="1"' . (api_ep_on((string)$ep) ? ' checked' : '') . '> ' .
                '<code>' . e($ep) . '</code> <span class="badge">' . e($d[1]) . '</span>' . ((int)$d[4] === 1 ? ' <span class="badge badge-warn" title="需要令牌且与网页端同规则">✎ 写入</span>' : '') .
                ' <span class="muted">' . e($d[2]) . ' — ' . e($d[3]) . '</span></label>';
        }
        echo '</div>';
    }

    echo '<h2 class="card-title" style="margin-top:18px">开放 API 使用条款</h2>' .
        '<p class="hint">用户在「个人设置」签发令牌前必须阅读并勾选同意本条款；签发时记录同意时间与条款版本指纹。' . ($termsEmpty ? '当前展示的是内置模板（尚未保存过），可直接修改或原样保存生效。' : '条款文本更新不会自动影响已签发的令牌；如需收紧政策，可在此说明并撤销相关令牌。') . '</p>' .
        '<label class="field"><textarea class="input" name="api_terms" rows="12" style="min-height:220px">' . e($termsVal) . '</textarea></label>' .
        '<div class="form-foot"><span class="muted">保存后立即生效，无需重启</span><button class="btn btn-primary" type="submit">' . t('保存 API 设置') . '</button></div>' .
        '</form>' .
        admin_restore_btn('api_enabled,api_guest,api_log,api_rate_token,api_rate_guest,api_cors') . '</div>';

    /* 已签发令牌（审计与管控） */
    $toks = api_tokens_all();
    ksort($toks);
    echo '<div class="card form-card"><h2 class="card-title">已签发的令牌（' . count($toks) . '）</h2>';
    if (!$toks) {
        echo empty_state('还没有用户签发 API 令牌');
    } else {
        echo '<div class="admin-list">';
        foreach ($toks as $tid => $rec) {
            $tu = user_by_id((int)$tid);
            $tname = $tu ? (string)$tu['name'] : ('已注销 #' . (int)$tid);
            echo '<div class="admin-row"><span><b>' . e($tname) . '</b> <span class="muted">· 签发于 ' . fmt_dt((int)($rec['created'] ?? 0)) .
                ' · 同意条款 ' . fmt_dt((int)($rec['agree'] ?? 0)) . '（条款版本 ' . e((string)($rec['agree_fp'] ?? '')) . '）' .
                ($tu && !empty($tu['banned']) ? ' · <span class="badge badge-warn">已封禁</span>' : '') . '</span></span>' .
                '<form method="post" action="' . e(u('a=admin_api_revoke')) . '" class="inline" data-confirm="确认撤销「' . e($tname) . '」的 API 令牌？其客户端将立即失去访问权。">' .
                '<input type="hidden" name="uid" value="' . (int)$tid . '">' . csrf_field() .
                '<button class="btn btn-ghost btn-sm danger" type="submit">' . t('撤销') . '</button></form></div>';
        }
        echo '</div>';
        echo '<p class="hint">服务端只保存令牌的 SHA-256 哈希，明文仅签发时向用户展示一次；用户可在「个人设置」自助重置 / 撤销，此处撤销适用于安全处置。</p>';
    }
    echo '</div>';

    /* 快速接入示例 */
    $base = trim((string)cfg('site_url', ''));
    if ($base === '') {
        $base = 'https://' . (string)($_SERVER['HTTP_HOST'] ?? 'your-forum.example.com');
    }
    $base = rtrim($base, '/');
    echo '<div class="card form-card"><h2 class="card-title">' . t('客户端接入示例') . '</h2>' .
        '<p class="hint" style="font-size:13px;line-height:1.8">curl "' . e($base) . '/index.php?api=threads.list&amp;per_page=10"<br>' .
        'curl -H "Authorization: Bearer mf_你的令牌" -X POST -H "Content-Type: application/json" -d \'{"board":1,"title":"来自客户端","content":"你好"}\' "' . e($base) . '/index.php?api=threads.create"</p>' .
        '<p class="hint">完整端点文档、参数、错误码与限速规则见 <a href="' . e(u('p=api_docs')) . '">开发者文档</a>（前台对登录用户开放）。</p></div>';
}

/* ---------------- 板块 ---------------- */
function admin_tab_boards(): void
{
    echo '<div class="card form-card"><h2 class="card-title">' . t('新建板块') . '</h2>' .
        '<form method="post" action="' . e(u('a=admin_board_new')) . '" class="inline-form">' . csrf_field() .
        '<input class="input" name="name" required maxlength="20" placeholder="板块名称">' .
        '<input class="input" name="desc" maxlength="60" placeholder="一句话简介（可选）">' .
        '<button class="btn btn-primary" type="submit">' . t('创建') . '</button></form></div>';

    $bs = board_all();
    echo '<div class="card form-card"><h2 class="card-title">板块列表（' . count($bs) . '）</h2><div class="admin-list">';
    if (!$bs) {
        echo empty_state('还没有板块');
    }
    foreach ($bs as $b) {
        $bid = (int)$b['id'];
        $perm = board_perm_of($b);
        echo '<div class="admin-row"><form method="post" action="' . e(u('a=admin_board_save')) . '" class="inline-form">' .
            '<input type="hidden" name="id" value="' . $bid . '">' . csrf_field() .
            '<input class="input" name="name" value="' . e((string)$b['name']) . '" maxlength="20" required>' .
            '<input class="input" name="desc" value="' . e((string)$b['desc']) . '" maxlength="60">' .
            '<button class="btn btn-ghost btn-sm" type="submit">' . t('保存') . '</button></form>' .
            '<span class="row-ops">' .
            '<form method="post" action="' . e(u('a=admin_board_move')) . '" class="inline"><input type="hidden" name="id" value="' . $bid . '"><input type="hidden" name="dir" value="up">' . csrf_field() . '<button class="btn btn-ghost btn-sm" type="submit" title="上移">↑</button></form>' .
            '<form method="post" action="' . e(u('a=admin_board_move')) . '" class="inline"><input type="hidden" name="id" value="' . $bid . '"><input type="hidden" name="dir" value="down">' . csrf_field() . '<button class="btn btn-ghost btn-sm" type="submit" title="下移">↓</button></form>' .
            '<form method="post" action="' . e(u('a=admin_board_del')) . '" class="inline" data-confirm="确认删除该板块？（板块下有帖子时无法删除）"><input type="hidden" name="id" value="' . $bid . '">' . csrf_field() . '<button class="btn btn-ghost btn-sm danger" type="submit">' . t('删除') . '</button></form>' .
            '</span>';
        /* v1.25.0 板块发帖权限面板（v1.27.0：所有人 / 仅职员 / 白名单用户） */
        echo '<details class="board-perm"><summary>' . t('发帖权限') . '：' . e(board_perm_label($bid)) . '</summary>' .
            '<form method="post" action="' . e(u('a=admin_board_perm')) . '" class="inline-form perm-form" data-perm-form>' . csrf_field() .
            '<input type="hidden" name="id" value="' . $bid . '">' .
            '<select class="input input-sm" name="perm_type" data-perm-type>' .
            '<option value="all"' . ($perm['type'] === 'all' ? ' selected' : '') . '>' . t('所有人') . '</option>' .
            '<option value="staff"' . ($perm['type'] === 'staff' ? ' selected' : '') . '>' . t('仅职员（审核员及以上）') . '</option>' .
            '<option value="users"' . ($perm['type'] === 'users' ? ' selected' : '') . '>' . t('白名单用户') . '</option>' .
            '</select>' .
            '<input class="input input-sm" name="perm_users" data-perm-users' . ($perm['type'] === 'users' ? '' : ' hidden') . ' value="' . e(implode('、', array_map('uname', $perm['uids']))) . '" placeholder="' . e(t('用户名，逗号分隔，如：张三,李四')) . '" style="min-width:220px">' .
            '<button class="btn btn-ghost btn-sm" type="submit">' . t('保存权限') . '</button>' .
            '<span class="hint">' . e(t('回复不受板块发帖权限限制；网页端与开放 API 同时生效；职员不受限制')) . '</span>' .
            '</form></details>';
        echo '</div>';
    }
    echo '</div></div>';
}

/* ---------------- 用户 ---------------- */
function admin_tab_users(): void
{
    $q = trim((string)($_GET['q'] ?? ''));
    echo '<form method="get" action="' . e(u('')) . '" class="search-bar">' .
        '<input type="hidden" name="p" value="admin"><input type="hidden" name="tab" value="users">' .
        '<input class="input" name="q" value="' . e($q) . '" placeholder="搜索用户名 / 邮箱">' .
        '<button class="btn btn-ghost" type="submit">' . t('搜索') . '</button></form>';

    $us = user_all();
    if ($q !== '') {
        $ql = str_lower($q);
        $us = array_values(array_filter($us, function ($x) use ($ql) {
            return strpos(str_lower((string)$x['name']), $ql) !== false || strpos(str_lower((string)$x['email']), $ql) !== false;
        }));
    }
    usort($us, function ($a, $b) {
        return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
    });

    echo '<div class="card form-card"><h2 class="card-title">用户（' . count($us) . '）</h2><div class="admin-list">';
    foreach ($us as $x) {
        $uid = (int)$x['id'];
        $self = (int)current_user()['id'] === $uid;
        $status = !empty($x['banned']) ? '<span class="badge badge-danger">已封号</span>'
            : (mute_left($x) > 0 ? '<span class="badge badge-warn">禁言 ' . ceil(mute_left($x) / 60) . ' 分钟</span>'
                : '<span class="badge badge-ok">正常</span>');
        echo '<div class="admin-row admin-user"><div class="u-info">' .
            '<a class="u-name" href="' . e(u('p=user&id=' . $uid)) . '">' . e((string)$x['name']) . '</a>' .
            '<span class="muted">' . e((string)$x['email']) . '</span>' .
            badge_for($x) .
            $status . '</div>';
        if (!$self) {
            echo '<span class="row-ops">' .
                '<form method="post" action="' . e(u('a=admin_user_mute')) . '" class="inline-form">' .
                '<input type="hidden" name="uid" value="' . $uid . '">' . csrf_field() .
                '<select name="mins" class="input input-sm"><option value="10">' . t('10 分钟') . '</option><option value="60">' . t('1 小时') . '</option><option value="1440">' . t('1 天') . '</option><option value="10080">' . t('7 天') . '</option></select>' .
                '<button class="btn btn-ghost btn-sm" type="submit">' . t('禁言') . '</button></form>';
            if (!empty($x['banned'])) {
                echo '<form method="post" action="' . e(u('a=admin_user_unban')) . '" class="inline"><input type="hidden" name="uid" value="' . $uid . '">' . csrf_field() . '<button class="btn btn-ghost btn-sm" type="submit">' . t('解封') . '</button></form>';
            } else {
                echo '<form method="post" action="' . e(u('a=admin_user_ban')) . '" class="inline" data-confirm="确认封禁该账号？封禁后无法登录。"><input type="hidden" name="uid" value="' . $uid . '">' . csrf_field() . '<button class="btn btn-ghost btn-sm danger" type="submit">' . t('封号') . '</button></form>';
            }
            if (is_super()) {
                /* v1.27.0 角色指派：仅超级管理员可见（普通用户 / 审核员 / 超级管理员，即坛主） */
                $cur = user_role($x);
                echo '<form method="post" action="' . e(u('a=admin_user_role')) . '" class="inline">' .
                    '<input type="hidden" name="uid" value="' . $uid . '">' .
                    '<select name="role" class="input input-sm" aria-label="' . e(t('用户角色')) . '">';
                foreach (MF_ROLES as $rk => $rv) {
                    echo '<option value="' . e($rk) . '"' . ($cur === $rk ? ' selected' : '') . '>' . e(t($rv[0])) . '</option>';
                }
                echo '</select>' . csrf_field() .
                    '<button class="btn btn-ghost btn-sm" type="submit">' . e(t('指派角色')) . '</button></form>';
            }
            echo '</span>';
        } else {
            echo '<span class="muted">（当前账号）</span>';
        }
        echo '</div>';
    }
    echo '</div></div>';
}

/* ---------------- 内容 ---------------- */
function admin_tab_content(): void
{
    $q = trim((string)($_GET['q'] ?? ''));
    echo '<form method="get" action="' . e(u('')) . '" class="search-bar">' .
        '<input type="hidden" name="p" value="admin"><input type="hidden" name="tab" value="content">' .
        '<input class="input" name="q" value="' . e($q) . '" placeholder="搜索帖子标题 / 作者">' .
        '<button class="btn btn-ghost" type="submit">' . t('搜索') . '</button></form>';

    $idx = thread_index();
    usort($idx, function ($a, $b) {
        return (int)($b['created'] ?? 0) <=> (int)($a['created'] ?? 0);
    });
    if ($q !== '') {
        $ql = str_lower($q);
        $idx = array_values(array_filter($idx, function ($t) use ($ql) {
            if (strpos(str_lower((string)$t['title']), $ql) !== false) {
                return true;
            }
            return strpos(str_lower(uname((int)$t['author'])), $ql) !== false;
        }));
    }

    echo '<div class="card form-card"><h2 class="card-title">帖子（' . count($idx) . '）</h2><div class="admin-list">';
    if (!$idx) {
        echo empty_state('没有帖子');
    }
    foreach ($idx as $t) {
        $tid = (int)$t['id'];
        $badges = (!empty($t['pinned']) ? '<span class="badge badge-accent">置顶</span>' : '') .
            (!empty($t['locked']) ? '<span class="badge">锁定</span>' : '') .
            (!empty($t['hidden']) ? '<span class="badge badge-warn">审核中</span>' : '');
        echo '<div class="admin-row"><div class="u-info">' .
            '<a class="u-name" href="' . e(u('p=thread&id=' . $tid)) . '">' . e((string)$t['title']) . '</a>' .
            '<span class="muted">' . e(board_name((int)$t['board'])) . ' · ' . e(uname((int)$t['author'])) . ' · ' . fmt_time((int)$t['created']) . '</span>' .
            $badges . '</div>' .
            '<span class="row-ops">' .
            '<form method="post" action="' . e(u('a=admin_thread_op')) . '" class="inline"><input type="hidden" name="tid" value="' . $tid . '"><input type="hidden" name="act" value="' . (empty($t['locked']) ? 'lock' : 'unlock') . '">' . csrf_field() . '<button class="btn btn-ghost btn-sm" type="submit">' . (empty($t['locked']) ? '锁定' : '解锁') . '</button></form>' .
            '<form method="post" action="' . e(u('a=admin_thread_op')) . '" class="inline"><input type="hidden" name="tid" value="' . $tid . '"><input type="hidden" name="act" value="' . (empty($t['pinned']) ? 'pin' : 'unpin') . '">' . csrf_field() . '<button class="btn btn-ghost btn-sm" type="submit">' . (empty($t['pinned']) ? '置顶' : '取消置顶') . '</button></form>' .
            '<form method="post" action="' . e(u('a=admin_thread_op')) . '" class="inline"><input type="hidden" name="tid" value="' . $tid . '"><input type="hidden" name="act" value="move"><select name="board" class="input input-sm">';
        foreach (board_all() as $b) {
            echo '<option value="' . (int)$b['id'] . '"' . ((int)$t['board'] === (int)$b['id'] ? ' selected' : '') . '>' . e((string)$b['name']) . '</option>';
        }
        echo '</select>' . csrf_field() . '<button class="btn btn-ghost btn-sm" type="submit">' . t('移版') . '</button></form>' .
            '<form method="post" action="' . e(u('a=admin_thread_op')) . '" class="inline" data-confirm="确认删除该帖子？将通知作者。"><input type="hidden" name="tid" value="' . $tid . '"><input type="hidden" name="act" value="delete">' . csrf_field() . '<button class="btn btn-ghost btn-sm danger" type="submit">' . t('删除') . '</button></form>' .
            '</span></div>';
    }
    echo '</div><p class="hint">删除回复请进入帖子页面，在对应回复处操作。</p></div>';
}

/* ---------------- AI 待审队列 ---------------- */
function admin_tab_queue(): void
{
    $q = queue_list();
    echo '<div class="list-head"><h2 class="card-title">AI 待审队列（' . count($q) . '）</h2>' .
        '<form method="post" action="' . e(u('a=admin_queue_run')) . '" class="inline">' . csrf_field() .
        '<button class="btn btn-primary btn-sm" type="submit">' . t('立即审核一条') . '</button></form></div>';
    echo '<p class="hint">AI 随论坛访问自动处理队列，每次只处理一条（保护速率限制）；也可以在此手动触发。审核失败的内容会保持隐藏并通知管理员。</p>';
    echo '<div class="admin-list">';
    if (!$q) {
        echo empty_state('队列为空');
    }
    foreach ($q as $rid) {
        $rep = report_get((int)$rid);
        if (!$rep) {
            continue;
        }
        $gone = false;
        $preview = cut_str(str_replace("\n", ' ', report_target_content($rep, $gone)), 80);
        echo '<div class="card queue-item"><div class="notice-head"><b>#' . (int)$rep['id'] . ' · ' . ($rep['type'] === 'thread' ? '帖子' : '回复') . ' #' . (int)$rep['tid'] . ($rep['type'] === 'reply' ? ' / #' . (int)$rep['rid'] : '') . '</b>' .
            '<span class="muted">举报人 ' . e(uname((int)$rep['reporter'])) . ' · ' . fmt_time((int)$rep['created']) . '</span></div>' .
            '<p class="notice-body">理由：' . e((string)$rep['reason']) . '</p>' .
            '<p class="muted">内容预览：' . ($gone ? '（已被删除）' : e($preview)) . '</p>' .
            '<div class="notice-ops">' .
            '<a class="btn btn-ghost btn-sm" href="' . e(u('p=thread&id=' . (int)$rep['tid'])) . '">查看</a>' .
            '<form method="post" action="' . e(u('a=admin_queue_restore')) . '" class="inline"><input type="hidden" name="rid" value="' . (int)$rep['id'] . '">' . csrf_field() . '<button class="btn btn-ghost btn-sm" type="submit">' . t('直接恢复') . '</button></form>' .
            '<form method="post" action="' . e(u('a=admin_queue_delete')) . '" class="inline" data-confirm="确认直接删除该内容？将通知作者。"><input type="hidden" name="rid" value="' . (int)$rep['id'] . '">' . csrf_field() . '<button class="btn btn-ghost btn-sm danger" type="submit">' . t('直接删除') . '</button></form>' .
            '</div></div>';
    }
    echo '</div>';
}

/* ---------------- 人工待审（已申诉 / AI 失败 / AI 判违规） ---------------- */
function admin_tab_manual(): void
{
    $list = [];
    foreach (reports_all() as $r) {
        if (in_array($r['status'], ['appealed', 'ai_bad', 'ai_failed'], true)) {
            $list[] = $r;
        }
    }
    usort($list, function ($a, $b) {
        return (int)($a['created'] ?? 0) <=> (int)($b['created'] ?? 0);
    });
    echo '<div class="list-head"><h2 class="card-title">人工待审（' . count($list) . '）</h2></div>';
    echo '<p class="hint">包含用户申诉内容与 AI 判定违规内容。有问题请删除（将通知作者），没问题请恢复。</p>';
    echo '<div class="admin-list">';
    if (!$list) {
        echo empty_state('没有待人工处理的内容');
    }
    foreach ($list as $r) {
        $gone = false;
        $preview = cut_str(str_replace("\n", ' ', report_target_content($r, $gone)), 100);
        echo '<div class="card queue-item"><div class="notice-head"><b>#' . (int)$r['id'] . ' · ' . ($r['type'] === 'thread' ? '帖子' : '回复') . ' #' . (int)$r['tid'] . '</b>' .
            '<span class="badge ' . ($r['status'] === 'appealed' ? 'badge-info' : 'badge-warn') . '">' . e(report_status_label((string)$r['status'])) . '</span></div>' .
            '<p class="notice-body">举报理由：' . e((string)$r['reason']) . '</p>' .
            (!empty($r['note']) ? '<p class="muted">AI 意见：' . e((string)$r['note']) . '</p>' : '') .
            '<p class="muted">内容预览：' . ($gone ? '（已被删除）' : e($preview)) . '</p>' .
            '<div class="notice-ops">' .
            '<a class="btn btn-ghost btn-sm" href="' . e(u('p=thread&id=' . (int)$r['tid'])) . '">查看</a>' .
            '<form method="post" action="' . e(u('a=admin_manual_restore')) . '" class="inline"><input type="hidden" name="rid" value="' . (int)$r['id'] . '">' . csrf_field() . '<button class="btn btn-primary btn-sm" type="submit">' . t('没问题，恢复') . '</button></form>' .
            '<form method="post" action="' . e(u('a=admin_manual_delete')) . '" class="inline" data-confirm="确认删除该内容？将通知作者。"><input type="hidden" name="rid" value="' . (int)$r['id'] . '">' . csrf_field() . '<button class="btn btn-ghost btn-sm danger" type="submit">' . t('有问题，删除') . '</button></form>' .
            '</div></div>';
    }
    echo '</div>';
}

/* ---------------- 举报记录 ---------------- */
function admin_tab_reports(): void
{
    $rs = reports_all();
    usort($rs, function ($a, $b) {
        return (int)($b['created'] ?? 0) <=> (int)($a['created'] ?? 0);
    });
    echo '<div class="list-head"><h2 class="card-title">举报记录（' . count($rs) . '）</h2></div>';
    echo '<div class="admin-list">';
    if (!$rs) {
        echo empty_state('暂无举报记录');
    }
    foreach ($rs as $r) {
        echo '<div class="admin-row"><div class="u-info">' .
            '<b>#' . (int)$r['id'] . ' · ' . ($r['type'] === 'thread' ? '帖子' : '回复') . ' #' . (int)$r['tid'] . '</b>' .
            '<span class="muted">举报人 ' . e(uname((int)$r['reporter'])) . ' · ' . fmt_time((int)$r['created']) . '</span>' .
            '<span class="badge">' . e(report_status_label((string)$r['status'])) . '</span>' .
            '</div><span class="muted r-reason">理由：' . e((string)$r['reason']) . '</span></div>';
    }
    echo '</div>';
}

/* ---------------- 公告 ---------------- */
function admin_tab_anns(): void
{
    $editId = get_int('edit', 0);
    $edit = $editId > 0 ? ann_get($editId) : null;
    echo '<div class="card form-card"><h2 class="card-title">' . ($edit ? '编辑公告 #' . $editId : '发布公告') . '</h2>' .
        '<form method="post" action="' . e(u('a=admin_ann_save')) . '">' . csrf_field() .
        '<input type="hidden" name="id" value="' . ($edit ? $editId : 0) . '">' .
        '<label class="field"><span class="field-l">' . t('标题') . '</span><input class="input" name="title" required maxlength="60" value="' . e((string)($edit['title'] ?? '')) . '"></label>' .
        '<label class="field"><span class="field-l">' . t('内容') . '</span><textarea class="input" name="content" rows="8" required maxlength="2000" placeholder="支持 Markdown：# 标题、**加粗**、- 列表、> 引用、| 表格 |、==高亮==、![图](https://...)">' . e((string)($edit['content'] ?? '')) . '</textarea></label>' .
        '<div class="form-foot"><span class="muted">支持 Markdown：标题 / 加粗 / 斜体 / 删除线 / 高亮 / 列表 / 任务列表 / 表格 / 引用 / 代码块 / 链接 / 图片（https 外链）</span><button class="btn btn-primary" type="submit">' . ($edit ? '保存修改' : '发布') . '</button></div></form></div>';

    $anns = ann_all();
    echo '<div class="card form-card"><h2 class="card-title">公告（' . count($anns) . '）</h2><div class="admin-list">';
    if (!$anns) {
        echo empty_state('暂无公告');
    }
    foreach ($anns as $a) {
        echo '<div class="admin-row"><div class="u-info"><b>' . e((string)$a['title']) . '</b>' .
            '<span class="muted">' . fmt_dt((int)$a['created']) . '</span></div>' .
            '<span class="row-ops">' .
            '<a class="btn btn-ghost btn-sm" href="' . e(u('p=admin&tab=anns&edit=' . (int)$a['id'])) . '">编辑</a>' .
            '<form method="post" action="' . e(u('a=admin_ann_del')) . '" class="inline" data-confirm="确认删除该公告？"><input type="hidden" name="id" value="' . (int)$a['id'] . '">' . csrf_field() . '<button class="btn btn-ghost btn-sm danger" type="submit">' . t('删除') . '</button></form>' .
            '</span></div>';
    }
    echo '</div></div>';
}

/* ---------------- 主题 ---------------- */
function admin_tab_theme(): void
{
    $cur = (string)cfg('theme_color', '#0f766e');
    $palette = ['#0f766e', '#047857', '#b45309', '#be123c', '#9f1239', '#57534e', '#1c1917'];
    $dark = (string)cfg('dark_default', 'system');
    /* 网站图标（v1.14.0）：上传后立即生效，存储于 data/upload/（data/ 目录禁止 Web 直访，仅经 p=icon 受控输出） */
    $icon = (string)cfg('site_icon', '');
    $hasIcon = $icon !== '' && strpos($icon, '/') === false && is_file(DATA_DIR . '/upload/' . $icon);
    echo '<div class="card form-card"><h2 class="card-title">' . t('网站图标（favicon）') . '</h2>';
    echo '<div class="icon-current"><span class="icon-preview"><img src="' . e($hasIcon ? u('p=icon&v=' . (int)@filemtime(DATA_DIR . '/upload/' . $icon)) : ua('assets/favicon.svg')) . '" alt="当前网站图标" width="48" height="48"></span>' .
        '<span class="muted">' . ($hasIcon ? '当前为自定义图标：' . e($icon) : '当前为默认图标') . '</span>';
    if ($hasIcon) {
        echo '<form method="post" action="' . e(u('a=admin_icon_del')) . '" class="inline" data-confirm="确认恢复默认图标？">' . csrf_field() .
            '<button class="btn btn-ghost btn-sm" type="submit">' . t('恢复默认') . '</button></form>';
    }
    echo '</div>';
    echo '<form method="post" action="' . e(u('a=admin_icon_upload')) . '" enctype="multipart/form-data">' . csrf_field() .
        '<label class="field"><span class="field-l">' . t('上传新图标') . '</span><input class="input" type="file" name="icon" accept=".png,.jpg,.jpeg,.webp,.gif,.ico,.svg" required>' .
        '<span class="hint">支持 PNG / JPG / WEBP / GIF / ICO / SVG；不超过 200KB，建议 256×256 以上；上传后浏览器可能需要强制刷新才能看到新图标</span></label>' .
        '<div class="form-foot"><span></span><button class="btn btn-primary" type="submit">' . t('上传图标') . '</button></div></form></div>';
    echo '<div class="card form-card"><h2 class="card-title">' . t('颜色与页脚') . '</h2><form method="post" action="' . e(u('a=admin_save_theme')) . '" data-ajax="1" data-ajax-live="accent">' . csrf_field() .
        '<div class="field"><span class="field-l">' . t('主题色') . '</span><div class="palette">';
    foreach ($palette as $p) {
        echo '<label class="swatch" style="background:' . e($p) . '"><input type="radio" name="theme_color" value="' . e($p) . '"' . (strcasecmp($cur, $p) === 0 ? ' checked' : '') . ' aria-label="' . e($p) . '"></label>';
    }
    echo '</div><input class="input" type="color" name="theme_color_custom" value="' . e(preg_match('/^#[0-9a-fA-F]{6}$/', $cur) ? $cur : '#0f766e') . '" style="max-width:120px"><span class="hint">色板或自定义颜色，保存后全局生效</span></div>' .
        '<label class="field"><span class="field-l">' . t('深色模式默认值') . '</span><select name="dark_default" class="input">' .
        '<option value="system"' . ($dark === 'system' ? ' selected' : '') . '>' . t('跟随系统') . '</option>' .
        '<option value="light"' . ($dark === 'light' ? ' selected' : '') . '>' . t('浅色') . '</option>' .
        '<option value="dark"' . ($dark === 'dark' ? ' selected' : '') . '>' . t('深色') . '</option>' .
        '</select><span class="hint">用户仍可在右上角手动切换，选择会被记住</span></label>' .
        '<label class="field"><span class="field-l">' . t('页脚文字（左侧，留空显示默认）') . '</span><input class="input" name="footer_text" maxlength="120" placeholder="默认：' . e((string)cfg('site_name')) . ' · Cube Minimalist Forum v' . e(app_version()) . '" value="' . e((string)cfg('footer_text', '')) . '"></label>' .
        '<label class="field"><span class="field-l">' . t('页脚备注（右侧，留空显示默认）') . '</span><input class="input" name="footer_note" maxlength="120" placeholder="默认：纯文字 · 文件存储 · 无数据库" value="' . e((string)cfg('footer_note', '')) . '"></label>' .
        '<label class="field"><span class="field-l">' . t('自定义样式（CSS）') . '</span><textarea class="input" name="custom_css" rows="6" placeholder="/* 追加到页面尾部的自定义 CSS */">' . e((string)cfg('custom_css')) . '</textarea></label>' .
        '<div class="form-foot"><span></span><button class="btn btn-primary" type="submit">' . t('保存主题设置') . '</button></div></form>' .
        admin_restore_btn('theme_color,dark_default,custom_css,footer_text,footer_note') . '</div>';
    echo admin_card_nav();
}

/* ---------------- v1.29.0 后台导航布局卡片（侧边 / 平铺 + 侧边列数） ---------------- */
function admin_card_nav(): string
{
    $layout = (string)cfg('admin_nav_layout', 'side') === 'flat' ? 'flat' : 'side';
    $cols = (int)cfg('admin_nav_cols', 1);
    if ($cols < 1 || $cols > 3) {
        $cols = 1;
    }
    return '<div class="card form-card"><h2 class="card-title">' . t('后台导航布局') . '</h2>' .
        '<form method="post" action="' . e(u('a=admin_save_nav')) . '">' . csrf_field() .
        '<div class="grid2">' .
        '<label class="field"><span class="field-l">' . t('布局方式') . '</span><select class="input" name="admin_nav_layout">' .
        '<option value="side"' . ($layout === 'side' ? ' selected' : '') . '>' . t('侧边导航（分组竖排在左侧，跟随滚动）') . '</option>' .
        '<option value="flat"' . ($layout === 'flat' ? ' selected' : '') . '>' . t('平铺标签（分组横排铺在内容上方，同 v1.26 之前）') . '</option>' .
        '</select><span class="hint">' . t('保存后立即生效；窄屏（≤880px）两种布局都自动转为分组横排') . '</span></label>' .
        '<label class="field"><span class="field-l">' . t('侧边导航列数') . '</span><select class="input" name="admin_nav_cols">' .
        '<option value="1"' . ($cols === 1 ? ' selected' : '') . '>' . t('单列（默认）') . '</option>' .
        '<option value="2"' . ($cols === 2 ? ' selected' : '') . '>' . t('两列') . '</option>' .
        '<option value="3"' . ($cols === 3 ? ' selected' : '') . '>' . t('三列') . '</option>' .
        '</select><span class="hint">' . t('仅「侧边导航」布局生效：把五个分组摊进多列，导航总高更短，多数屏幕不用滚动即可看全 21 个标签页') . '</span></label>' .
        '</div>' .
        '<div class="form-foot"><span></span><button class="btn btn-primary" type="submit">' . t('保存导航布局') . '</button></div></form>' .
        admin_restore_btn('admin_nav_layout,admin_nav_cols') . '</div>';
}

/**
 * v1.30.0 导航布局快捷设置：直接放在菜单栏（后台导航）顶部，超管随时一键切换，
 * 不必进「主题」页找卡片。服务端渲染的迷你表单（无 JS 可用）；
 * 保存动作复用 act_admin_save_nav（back 参数回当前标签页），改动写日志。
 */
function admin_nav_quick(string $tab): string
{
    $layout = (string)cfg('admin_nav_layout', 'side') === 'flat' ? 'flat' : 'side';
    $cols = (int)cfg('admin_nav_cols', 1);
    if ($cols < 1 || $cols > 3) {
        $cols = 1;
    }
    $btn = function (string $label, string $title, array $fields, bool $on): string {
        $h = '';
        foreach ($fields as $k => $v) {
            $h .= '<input type="hidden" name="' . e($k) . '" value="' . e((string)$v) . '">';
        }
        return '<form class="nq-f" method="post" action="' . e(u('a=admin_save_nav')) . '">' . csrf_field() . $h .
            '<button class="nq-b' . ($on ? ' on' : '') . '" type="submit" title="' . e($title) . '" aria-pressed="' . ($on ? 'true' : 'false') . '">' . e(t($label)) . '</button></form>';
    };
    $back = ['back' => $tab];
    $html = '<div class="nav-quick"><div class="nq-t">' . e(t('导航布局')) . '</div>';
    $html .= '<div class="nq-row">' .
        $btn('侧边', t('侧边导航（分组竖排在左侧，跟随滚动）'), ['admin_nav_layout' => 'side', 'admin_nav_cols' => $cols] + $back, $layout === 'side') .
        $btn('平铺', t('平铺标签（分组横排铺在内容上方，同 v1.26 之前）'), ['admin_nav_layout' => 'flat', 'admin_nav_cols' => $cols] + $back, $layout === 'flat') .
        '</div>';
    if ($layout === 'side') {
        $html .= '<div class="nq-row" title="' . e(t('侧边导航列数')) . '">';
        foreach ([1, 2, 3] as $n) {
            $html .= $btn((string)$n, t('侧边导航列数') . '：' . $n, ['admin_nav_layout' => 'side', 'admin_nav_cols' => $n] + $back, $cols === $n);
        }
        $html .= '</div>';
    }
    return $html . '</div>';
}

/* ---------------- 监控 ---------------- */

/** 环形进度 SVG（实时资源卡用），$pct 0-100 或 null（不可用，显示灰色虚线状态），$r 半径 */
function res_ring(?float $pct, int $r = 44): string
{
    $c = 2 * M_PI * $r;
    $na = $pct === null;
    $pct = $na ? 0.0 : max(0.0, min(100.0, (float)$pct));
    $off = round($c * (1 - $pct / 100), 1);
    $lvl = $na ? ' na' : ($pct >= 85 ? ' bad' : ($pct >= 60 ? ' warn' : ''));
    // fill="none" 必须内联：SVG 圆形的默认填充是黑色，若浏览器缓存了旧版样式表
    // （旧版监控页没有环形图规则），环形会被渲染成"纯黑色圆形"。内联属性不依赖外部 CSS，
    // 优先级低于样式表，正常加载时主题色/深色模式不受影响。
    return '<svg class="ring' . $lvl . '" viewBox="0 0 ' . (2 * $r + 8) . ' ' . (2 * $r + 8) . '" fill="none" role="img" aria-label="' . ($na ? '当前环境不可读' : '占用 ' . round($pct) . '%') . '">' .
        '<circle class="ring-bg" cx="' . ($r + 4) . '" cy="' . ($r + 4) . '" r="' . $r . '" fill="none"></circle>' .
        '<circle class="ring-fg" cx="' . ($r + 4) . '" cy="' . ($r + 4) . '" r="' . $r . '" fill="none" stroke-dasharray="' . round($c, 1) . '" stroke-dashoffset="' . $off . '"></circle>' .
        '</svg>';
}

/* ---------------- v1.32.0 流量与使用统计卡片 ---------------- */
function admin_card_traffic(): string
{
    $s = stats_summary();
    $days = stats_days(14);
    $n = function (int $v): string {
        return $v >= 1000000 ? round($v / 1000000, 1) . 'M' : ($v >= 10000 ? round($v / 1000, 1) . 'K' : (string)$v);
    };
    /* 指标行：今日 / 昨日 / 近 7 天 / 累计 */
    $rows = [
        ['页面浏览（PV）', fn ($a) => $n((int)$a['pv'])],
        ['出站流量', fn ($a) => fmt_bytes((int)$a['bytes'])],
        ['发帖 / 回复', fn ($a) => $n((int)$a['thread']) . ' / ' . $n((int)$a['reply'])],
        ['内容编辑', fn ($a) => $n((int)$a['edit'])],
        ['搜索次数', fn ($a) => $n((int)$a['search'])],
        ['注册 / 登录', fn ($a) => $n((int)$a['reg']) . ' / ' . $n((int)$a['login'])],
        ['AI 调用 / tokens', fn ($a) => $n((int)$a['ai_calls']) . ' / ' . $n((int)$a['ai_tok'])],
    ];
    $head = '<tr><th></th><th>今日</th><th>昨日</th><th>近 7 天</th><th>累计</th></tr>';
    $body = '';
    foreach ($rows as [$label, $fn]) {
        $body .= '<tr><td>' . $label . '</td><td><b>' . $fn($s['today']) . '</b></td><td>' . $fn($s['yesterday']) . '</td><td>' . $fn($s['week']) . '</td><td>' . $fn($s['total']) . '</td></tr>';
    }
    $avgMs = (int)$s['today']['ai_calls'] > 0 ? (int)round(stats_days(1)[0]['ai_ms'] / max(1, (int)$s['today']['ai_calls'])) : 0;
    $aiFoot = (int)$s['today']['ai_calls'] > 0 ? '今日平均单次耗时 ' . $avgMs . 'ms' : '';
    /* 近 14 天 PV 柱状趋势 */
    $pvParts = [];
    foreach ($days as $r) {
        $pvParts[substr($r['d'], 5)] = $r['pv'];
    }
    $pvChart = chart_bars_h($pvParts, ['label' => '近 14 天页面浏览（PV）', 'tip' => function ($l, $v) { return $l . '：PV ' . (int)$v; }]);
    return '<div class="card form-card"><h2 class="card-title">' . t('流量与使用统计') . '</h2>' .
        '<p class="hint">PV 与出站流量来自全站命中日志（每分钟自动聚合，含游客与 API 之外的页面浏览；出站流量为实际发送字节，含压缩收益）。防火墙限流拦截、AI 队列巡逻等高频内部请求不计入 PV</p>' .
        '<table class="log-table stats-table"><thead>' . $head . '</thead><tbody>' . $body . '</tbody></table>' .
        ($aiFoot !== '' ? '<p class="hint">' . $aiFoot . '</p>' : '') .
        ($pvChart !== '' ? '<h3 class="chart-title" style="margin-top:16px">近 14 天页面浏览趋势</h3>' . $pvChart : '') .
        '</div>';
}

function admin_tab_monitor(): void
{
    [$app, $data] = sysmon_sizes();
    [$dt, $df] = sysmon_disk();
    [$l1, $l5, $l15] = sysmon_load();
    $php = sysmon_php_info();
    [$threads, $withReplies, $users, $lf, $lb, $backups] = sysmon_content_stats();
    $total = $app + $data;
    $quota = max(10, (int)cfg('quota_mb', 100)) * 1048576;
    $th = max(1, (int)cfg('monitor_mb', 95));
    $thBytes = $th * 1048576;
    $pct = min(100, (int)round($total / $quota * 100));
    $thPct = min(100, (int)round($thBytes / $quota * 100));
    $meterCls = $total >= $thBytes ? ' bad' : ($total >= $thBytes * 0.8 ? ' warn' : '');
    $over = $total >= $thBytes;

    /* 存储构成图表（v1.11.0）：程序与 data/ 各子目录占比 */
    $storeParts = [
        '程序文件' => $app,
        '帖子数据' => size_of_path(Store::path('threads')),
        '回复数据' => size_of_path(Store::path('replies')),
        '日志'     => size_of_path(Store::path('logs')),
        '备份'     => size_of_path(Store::path('backup')),
        '会话'     => size_of_path(Store::path('sessions')),
    ];
    $storeParts['其他数据'] = max(0, $data - array_sum($storeParts));
    arsort($storeParts);
    foreach ($storeParts as $k => $v) {
        if ($v <= 0) {
            unset($storeParts[$k]);
        }
    }
    $storeChart = $storeParts
        ? '<h3 class="chart-title" style="margin-top:16px">存储构成（数据目录明细）</h3>' .
          chart_bars_h($storeParts, ['label' => '存储占用构成', 'fmt' => 'fmt_bytes', 'tip' => function ($l, $v) { return $l . '：' . fmt_bytes((int)$v); }])
        : '';
    $st = sysmon_state();
    $cpu = sysmon_cpu();
    $cpuPct = $cpu['pct'];
    $cpuNa = $cpuPct === null;
    $mem = sysmon_mem();
    $memNa = $mem['mode'] === 'na' || $mem['pct'] === null;
    $liveOn = max(0, (int)cfg('live_interval', 20));
    $monInt = (int)cfg('monitor_interval', 5);
    $monInt = $monInt === 1 ? 2 : max(0, min(300, $monInt));
    $monLbl = $monInt > 0 ? '每 ' . $monInt . ' 秒自动刷新' : '自动刷新已关闭';
    $loadStr = e($l1 . ' / ' . $l5 . ' / ' . $l15);

    /* v1.32.0：打开监控页时确定性归并一次命中日志（统计与 IP 排行新鲜）；流量与使用统计卡置顶 */
    fw_hits_merge();
    echo admin_card_traffic();

    echo '<div class="card form-card"><h2 class="card-title">实时资源 <span class="live-tag" aria-hidden="true"></span><span class="muted res-sub">' . e($monLbl) . '</span></h2>' .
        '<div class="res-grid">';

    // CPU 卡：芯片图标（核心脉冲动画）+ 使用率环形；/proc 被主机限制时按系统负载估算
    $cpuFoot = $cpu['mode'] === 'load'
        ? '主机限制 /proc · 按 ' . sysmon_cores() . ' 核负载估算'
        : ($cpuNa ? '当前环境不可读 · 负载 ' . $loadStr : '实际采样 · 负载 ' . $loadStr);
    echo '<div class="res-card" id="resCpu">' .
        '<div class="res-ico ico-cpu" aria-hidden="true"><i class="cpu-core"></i><i class="cpu-pin p1"></i><i class="cpu-pin p2"></i><i class="cpu-pin p3"></i><i class="cpu-pin p4"></i></div>' .
        res_ring($cpuNa ? null : (float)$cpuPct) .
        '<div class="res-val"><b id="resCpuVal">' . ($cpuNa ? '—' : $cpuPct . '<small>%</small>') . '</b><span>CPU 使用率</span></div>' .
        '<div class="res-foot muted" id="resCpuFoot">' . $cpuFoot . '</div></div>';

    // 内存卡：内存条图标（滑动填充动画）+ 使用率环形；/proc 被限制时显示本 PHP 进程占用
    $memFoot = $mem['mode'] === 'proc'
        ? ('本进程 ' . e(fmt_bytes((int)$mem['used'])) . ($mem['total'] > 0 ? ' / 上限 ' . e(fmt_bytes((int)$mem['total'])) : ''))
        : ($memNa ? '当前环境不可读' : e(fmt_bytes((int)$mem['used'])) . ' / ' . e(fmt_bytes((int)$mem['total'])));
    echo '<div class="res-card" id="resMem">' .
        '<div class="res-ico ico-mem" aria-hidden="true"><i class="mem-bar"></i><i class="mem-fill"></i></div>' .
        res_ring($memNa ? null : (float)$mem['pct']) .
        '<div class="res-val"><b id="resMemVal">' . ($memNa ? '—' : $mem['pct'] . '<small>%</small>') . '</b><span>内存使用率</span></div>' .
        '<div class="res-foot muted" id="resMemFoot">' . $memFoot . '</div></div>';

    // 磁盘卡：盘片图标（旋转弧线动画）+ 使用率环形（整块磁盘）
    $dPct = $dt > 0 ? round(($dt - $df) / $dt * 100, 1) : -1;
    echo '<div class="res-card" id="resDisk">' .
        '<div class="res-ico ico-disk" aria-hidden="true"><i class="disk-arc"></i><i class="disk-dot"></i></div>' .
        res_ring($dPct >= 0 ? (float)$dPct : null) .
        '<div class="res-val"><b id="resDiskVal">' . ($dPct >= 0 ? $dPct . '<small>%</small>' : '—') . '</b><span>磁盘使用率</span></div>' .
        '<div class="res-foot muted" id="resDiskFoot">' . ($dPct >= 0 ? '剩余 ' . e(fmt_bytes($df)) . ' / 共 ' . e(fmt_bytes($dt)) : 'disk_free_space 被禁用') . '</div></div>';

    echo '</div>';
    echo '<p class="hint">CPU 与内存读数优先来自系统接口（/proc）；被主机限制时 CPU 按系统负载估算、内存显示本 PHP 进程占用，不影响其他功能；磁盘为整块物理盘的用量。论坛自身占用见下方「存储占用」。</p></div>';

    echo '<div class="card form-card"><h2 class="card-title">' . t('存储占用') . '</h2>' .
        '<div class="flex" style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px">' .
        '<span>合计 <b>' . e(fmt_bytes($total)) . '</b> / 配额 ' . e(fmt_bytes($quota)) . '</span>' .
        '<span class="muted">程序 ' . e(fmt_bytes($app)) . ' · 数据 ' . e(fmt_bytes($data)) . '</span></div>' .
        '<div class="meter" role="img" aria-label="存储占用 ' . $pct . '%"><i class="' . trim($meterCls) . '" style="width:' . $pct . '%"></i></div>' .
        '<p class="hint">告警阈值 ' . e(fmt_bytes($thBytes)) . '（阈值线位于进度条 ' . $thPct . '% 处）；' .
        ($over
            ? '<b style="color:var(--danger)">当前已超过阈值！' . (!empty($st['last_alert_sent']) && $st['last_alert_sent'] > 0 ? '告警邮件已于 ' . e(fmt_dt((int)$st['last_alert'])) . ' 发送 ' . (int)$st['last_alert_sent'] . ' 位管理员。' : '尚未成功发送告警邮件，请检查邮件配置。') . '</b>'
            : '占用正常。') .
        '上次自动检查：' . ((int)($st['last_check'] ?? 0) > 0 ? e(fmt_dt((int)$st['last_check'])) : '尚无记录（随访问每小时检查一次）') . '</p>' .
        $storeChart . '</div>';

    echo '<div class="card form-card"><h2 class="card-title">' . t('服务器信息') . '</h2><div class="mon-grid">' .
        '<div class="mon-cell"><b>v' . e(app_version()) . '</b><span>论坛版本</span></div>' .
        '<div class="mon-cell"><b>' . e($php['version']) . '</b><span>PHP 版本（' . e($php['sapi']) . '）</span></div>' .
        '<div class="mon-cell"><b>' . ($liveOn > 0 ? $liveOn . 's' : '关闭') . '</b><span>前台实时刷新间隔</span></div>' .
        '<div class="mon-cell"><b>' . e($php['mem']) . '</b><span>PHP 内存限制</span></div>' .
        '<div class="mon-cell"><b>' . e($php['max_exec']) . 's</b><span>最大执行时间</span></div>' .
        '<div class="mon-cell"><b>' . e($php['upload']) . '</b><span>单文件上传限制</span></div>' .
        '<div class="mon-cell"><b>' . e($php['post']) . '</b><span>POST 体积限制</span></div>' .
        '<div class="mon-cell"><b>' . online_count() . '</b><span>当前在线</span></div>' .
        '</div>' .
        '<p class="hint">实时刷新（在线人数 / 新帖新回复提示）按此间隔轮询，页面切到后台时自动暂停；设为 0 可完全关闭以节省流量（后台「基本设置」中修改）。</p></div>';

    // 环境自检：定位“发不了帖 / 存不上数据”这类主机环境问题（真实写探针，逐目录检查）
    $dataW = Store::writable();
    $dirChecks = [
        '.'        => 'data/ 数据目录（关键）',
        'threads'  => 'data/threads/ 帖子目录',
        'replies'  => 'data/replies/ 回复目录',
        'sessions' => 'data/sessions/ 会话目录',
        'logs'     => 'data/logs/ 操作日志目录',
        'locks'    => 'data/locks/ 文件锁目录',
        'backup'   => 'data/backup/ 备份目录',
    ];
    $dirBad = [];
    $fnChecks = [
        'flock（文件锁）'       => function_exists('flock'),
        'json（数据存储）'      => function_exists('json_encode'),
        'mail（本机发信）'      => function_exists('mail'),
        'curl（AI / 告警）'     => function_exists('curl_init'),
        'mbstring（自动回退）'  => function_exists('mb_strlen'),
        'disk_free_space（磁盘统计）' => function_exists('disk_free_space'),
    ];
    $errLog = DATA_DIR . '/error.log';
    $errSize = is_file($errLog) ? (int)@filesize($errLog) : 0;
    $errTail = '';
    if ($errSize > 0) {
        $lines = @file($errLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines)) {
            $errTail = implode("\n", array_slice($lines, -12));
        }
    }
    echo '<div class="card form-card"><h2 class="card-title">' . t('环境自检') . '</h2><div class="mon-grid">';
    foreach ($dirChecks as $rel => $label) {
        $exists = is_dir(Store::path($rel));
        $ok = $exists ? Store::dirWritable($rel) : false;
        if (!$ok) {
            $dirBad[] = $rel;
        }
        $state = $ok ? '✔ 可写' : ($exists ? '✘ 不可写' : '未创建');
        echo '<div class="mon-cell' . ($ok ? '' : ' cell-bad') . '"><b>' . $state . '</b><span>' . e($label) . '</span></div>';
    }
    foreach ($fnChecks as $k => $ok) {
        echo '<div class="mon-cell"><b>' . ($ok ? '✔' : '—') . '</b><span>' . e($k) . '</span></div>';
    }
    echo '<div class="mon-cell"><b>' . ($errSize > 0 ? fmt_bytes($errSize) : '无记录') . '</b><span>error.log 运行错误日志</span></div>' .
        '</div>';
    if ($errTail !== '') {
        echo '<details class="err-box"><summary>查看最近的运行错误（最新 ' . min(12, substr_count($errTail, "\n") + 1) . ' 行）</summary><pre>' . e(cut_str($errTail, 4000)) . '</pre></details>';
        echo '<p class="hint">若发帖 / 回复等操作报错，这里通常能看到具体原因（目录权限、磁盘写满、函数被禁用等）。</p>';
    }
    if ($dirBad) {
        $badNames = [];
        foreach ($dirBad as $rel) {
            $badNames[] = $rel === '.' ? 'data/' : 'data/' . $rel . '/';
        }
        echo '<form method="post" action="' . e(u('a=admin_repair_dirs')) . '"><div class="form-foot">' . csrf_field() .
            '<button class="btn btn-primary" type="submit">' . t('一键修复目录权限') . '</button></div></form>' .
            '<p class="hint">不可写：<b>' . e(implode('、', $badNames)) . '</b>。一键修复会依次尝试：创建缺失目录 → 修改权限（0775/0777）→ 重建空的失效目录（可救回属主不对的目录）。若修复后仍不可写，说明目录属主不属于 PHP 运行账号，请通过 FTP / 主机面板将 data/ 及其全部子目录权限设为 755 或 775。</p>';
    }
    if (!$dataW) {
        echo '<p class="hint" style="color:var(--danger)"><b>data/ 目录不可写将导致无法发帖、无法注册、设置无法保存。</b>请先点上方「一键修复目录权限」；若无效，请通过 FTP / 主机面板将 data/ 及其全部子目录与文件的权限设为 755（部分主机需 775），确保 PHP 运行账号拥有写权限。</p>';
    }
    echo '</div>';

    /* v1.44.0 功能一键自检 · v1.45.0 实时进度升级 · v1.46.0 端到端测试：
       检测清单 14 项常驻（六态实时点亮）+「检查进度」条 +「修复进度」条 + 终端式运行日志（复制 / 下载 / 自动滚动）
       ——检查与修复全程逐项步进，不再黑盒转圈；报告与日志落盘 data/selfcheck.php 可回看。 */
    $scLast = selfcheck_last_read();
    $scSum = is_array($scLast['sum'] ?? null) ? $scLast['sum'] : [];
    $scLastItems = [];
    foreach ((is_array($scLast['items'] ?? null) ? $scLast['items'] : []) as $sx) {
        if (is_array($sx) && isset($sx['key'])) {
            $scLastItems[(string)$sx['key']] = $sx;
        }
    }
    $scCat = selfcheck_catalog();
    $scN = count($scCat);
    echo '<script>window.SC_CATALOG=' . json_encode($scCat, JSON_UNESCAPED_UNICODE) . ';window.SC_LAST_LOGS=' . json_encode(is_array($scLast['logs'] ?? null) ? array_slice(array_map('strval', $scLast['logs']), -200) : [], JSON_UNESCAPED_UNICODE) . ';</script>';
    echo '<div class="card form-card"><h2 class="card-title">' . t('功能一键自检') . '</h2>';
    echo '<p class="hint">一次跑完：环境基线 / 读写 / 存储完整性与帖子索引 / 会话 / <b>注册端到端 · 登录端到端 · 发帖互动端到端</b>（探针账号真实走完用户旅程，即建即删不留痕）/ 程序文件完整性（manifest 校验，专抓「升级不完整、文件缺失、Call to undefined function」类故障）/ 站内搜索 / AI 审核队列 / 静态快照，共 ' . $scN . ' 项。<b>逐项实时执行</b>：清单逐项点亮「检查进度」，可自动修复项支持「一键修复（修复进度逐项可视，修完自动复检）」，全过程日志随时复制 / 下载。远程项会<b>用管理员注册邮箱真实发一封测试邮件</b>、<b>真实调用一次 AI 审核</b>（较慢，数秒到数十秒）。</p>';
    if (!empty($scLast['ts'])) {
        echo '<p class="hint">上次自检：' . e(date('Y-m-d H:i', (int)$scLast['ts']))
            . ($scSum ? ' —— 通过 ' . (int)($scSum['pass'] ?? 0) . ' / 警告 ' . (int)($scSum['warn'] ?? 0) . ' / 失败 ' . (int)($scSum['fail'] ?? 0) . ' / 跳过 ' . (int)($scSum['skip'] ?? 0) : '')
            . '（结果已预填到下方清单，重新自检将覆盖）</p>';
    }
    /* 具体清单：全部项目常驻，状态列随检测实时点亮 */
    echo '<table class="log-table sc-table"><thead><tr><th style="width:46px">' . t('状态') . '</th><th style="width:172px">' . t('检测项') . '</th><th class="sc-desc-col">' . t('检测内容') . '</th><th style="width:40%">' . t('结果') . '</th></tr></thead><tbody id="scRows">';
    foreach ($scCat as $c) {
        $k = (string)$c['key'];
        $last = $scLastItems[$k] ?? null;
        $st = is_array($last) ? (string)($last['state'] ?? '') : '';
        $map = ['pass' => ['✔', 'sc-pass', '通过'], 'warn' => ['▲', 'sc-warn', '警告'], 'fail' => ['✘', 'sc-fail', '失败'], 'skip' => ['—', 'sc-skip', '跳过']];
        if ($st !== '' && isset($map[$st])) {
            $ic = '<span id="scIc-' . $k . '" class="sc-ic ' . $map[$st][1] . '">' . $map[$st][0] . '</span>';
            $dt = e(cut_str((string)($last['detail'] ?? ''), 260));
        } else {
            $ic = '<span id="scIc-' . $k . '" class="sc-ic sc-pend">○</span>';
            $dt = '<span class="muted">' . t('待检测') . '</span>';
        }
        echo '<tr id="scRow-' . $k . '"><td>' . $ic . '</td><td><b>' . e((string)$c['name']) . '</b>' . (!empty($c['remote']) ? ' <i class="sc-remote">' . t('远程') . '</i>' : '') . '</td>'
            . '<td class="muted sc-desc">' . e((string)$c['desc']) . '</td>'
            . '<td class="sc-detail" id="scDt-' . $k . '">' . $dt . '</td></tr>';
    }
    echo '</tbody></table>';
    /* 检查进度 + 修复进度 双进度条 */
    echo '<div class="sc-prog-row"><span class="sc-prog-tx" id="scProgTx">' . t('检查进度') . ' 0/' . $scN . '</span><div class="sc-prog"><i id="scProg"></i></div></div>';
    echo '<div class="sc-prog-row" id="sfProgRow" hidden><span class="sc-prog-tx" id="sfProgTx">' . t('修复进度') . ' 0/0</span><div class="sc-prog sc-prog-fix"><i id="sfProg"></i></div></div>';
    echo '<div class="sc-cur" id="scCur">' . t('就绪——点「开始自检」逐项实时检测，全过程在下方日志区可视。') . '</div>';
    /* 终端式运行日志：自动滚动 / 复制 / 下载 */
    echo '<div class="sc-console"><div class="sc-console-head"><b>' . t('运行日志') . '</b><span id="scLogN" class="muted"></span>'
        . '<span class="sc-console-btns"><label class="sc-auto"><input type="checkbox" id="scAuto" checked> ' . t('自动滚动') . '</label>'
        . '<button type="button" class="btn btn-ghost btn-sm" id="scLogCopy">' . t('复制日志') . '</button>'
        . '<button type="button" class="btn btn-ghost btn-sm" id="scLogDl">' . t('下载日志') . '</button></span></div>'
        . '<div class="sc-log" id="scLog"></div></div>';
    echo '<label class="hint" style="display:block;margin:10px 0 8px"><input type="checkbox" id="scRemote" checked> ' . t('包含远程真实测试（真实发一封测试邮件到管理员邮箱 + 真实调用一次 AI 审核）') . '</label>';
    echo '<div class="form-foot" style="gap:10px;align-items:center;flex-wrap:wrap">' .
        '<button class="btn btn-primary" type="button" id="scRun">' . t('开始自检') . '</button>' .
        '<button class="btn btn-ghost" type="button" id="scStop" hidden>' . t('停止') . '</button>' .
        '<button class="btn btn-primary" type="button" id="scFix" hidden>' . t('一键修复') . '</button>' .
        '<span id="scSum" class="sc-sum"></span></div>';
    echo '</div>';

    /* v1.38.0 运行环境 · PHP 扩展：自动区分 Windows / Linux 与主机面板（小皮面板 phpStudy / 宝塔 / XAMPP…），
       推荐扩展一键开启（同意式：先备份 php.ini；取消注释 / 追加标记块）+ 上传限制体检 + 面板专用指引 */
    $efx = efx_plan();
    $efxRecTotal = 0;
    $efxRecLoaded = 0;
    foreach ($efx['exts'] as $x) {
        if ($x['level'] === 'rec') {
            $efxRecTotal++;
            $efxRecLoaded += $x['loaded'] ? 1 : 0;
        }
    }
    $efxUpSug = $efx['up']['up_sug'] || $efx['up']['post_sug'];
    $efxIssues = $efx['ini_issues'];
    $efxExtDirBad = !empty($efx['extdir']['missing']);
    echo '<div class="card form-card"><h2 class="card-title">' . t('运行环境 · PHP 扩展') . '</h2><div class="mon-grid">' .
        '<div class="mon-cell"><b>' . e($efx['os']) . '</b><span>操作系统' . ($efx['panel']['name'] !== '' ? ' · ' . e($efx['panel']['name']) : '（未识别到常见面板）') . '</span></div>' .
        '<div class="mon-cell' . ($efxExtDirBad ? ' cell-bad' : '') . '"><b>' . ($efx['ini'] !== '' ? ($efx['ini_writable'] ? '✔ 可写' : '只读') : '未找到') . '</b><span>php.ini' . ($efx['ini'] !== '' ? ' · ' . e(cut_str($efx['ini'], 40)) : '（命令行或特殊加载）')
            . ($efxExtDirBad ? ' · ' . t('扩展目录不存在') . '（' . e(cut_str($efx['extdir']['dir'], 32)) . '）' : '') . '</span></div>' .
        '<div class="mon-cell' . ($efxUpSug ? ' cell-bad' : '') . '"><b>' . e($efx['up']['cur']) . ' / ' . e($efx['up']['post']) . '</b><span>上传 / POST 体积限制</span></div>' .
        '<div class="mon-cell' . ($efxRecLoaded < $efxRecTotal ? ' cell-bad' : '') . '"><b>' . $efxRecLoaded . ' / ' . $efxRecTotal . '</b><span>推荐扩展已启用</span></div>' .
        '</div>';
    echo '<table class="log-table" style="margin-top:10px"><thead><tr><th>扩展</th><th>用途（缺失时对应能力降级，程序核心零依赖）</th><th>状态</th></tr></thead><tbody>';
    foreach ($efx['exts'] as $x) {
        if ($x['loaded']) {
            $state = '<b style="color:#15803d">✔ ' . t('已启用') . '</b>';
        } elseif ($x['file'] !== '') {
            $state = '<b style="color:#b45309">● ' . t('未启用') . '</b>';
        } else {
            $state = '<span class="muted">✘ ' . t('未随附') . '</span>';
        }
        $lv = $x['level'] === 'opt' ? ' <span class="muted">（可选）</span>' : ($x['level'] === 'perf' ? ' <span class="muted">（性能）</span>' : '');
        echo '<tr><td><b>' . e($x['name']) . '</b>' . $lv . '</td><td class="muted">' . e(t($x['why'])) . '</td><td>' . $state . '</td></tr>';
    }
    echo '</tbody></table>';
    if ($efxIssues['count'] > 0) {
        echo '<p class="hint" style="margin:10px 0 0;color:#b45309"><b>⚠ ' . t('php.ini 体检发现问题') . '（' . (int)$efxIssues['count'] . '）：</b>'
            . e(implode('；', $efxIssues['notes'])) . ($efxIssues['count'] > count($efxIssues['notes']) ? ' 等' : '')
            . '——' . t('一键开启缺失扩展时会一并修复：写法错误的行改写为当前平台正确写法，无效行与 PHP 8 已移除指令自动注释（先备份）。') . '</p>';
    }
    if ($efx['fixable'] || $efxUpSug || $efxIssues['count'] > 0) {
        if ($efx['ini'] !== '' && $efx['ini_writable']) {
            echo '<form method="post" action="' . e(u('a=admin_envfix')) . '" style="margin-top:12px" data-n="' . e(t('确认写入 php.ini？将先自动备份原文件到 data/backup/（php-ini-bak-*.ini），然后取消注释或追加缺失扩展的加载行；写入后需重启 PHP 生效。')) . '">' . csrf_field();
            foreach ($efx['fixable'] as $n) {
                echo '<input type="hidden" name="exts[]" value="' . e($n) . '">';
            }
            if ($efxUpSug) {
                echo '<label class="hint" style="display:block;margin:0 0 8px"><input type="checkbox" name="upfix" value="1" checked> ' . t('同时调大上传限制') . '（' . e($efx['up']['cur']) . ' → 32M / ' . e($efx['up']['post']) . ' → 64M；迁移包 / 更新包上传需要）</label>';
            }
            echo '<button class="btn btn-primary" type="submit">'
                . ($efx['fixable'] ? t('一键开启缺失扩展') . '（' . count($efx['fixable']) . '）' : t('一键修复 php.ini 问题') . '（' . (int)$efxIssues['count'] . '）')
                . '</button></form>';
            echo '<p class="hint">只开启上表「未启用」项，绝不改动其他配置；写入前自动备份 php.ini 到 data/backup/（php-ini-bak-*.ini）。写入后 ' . e(efx_restart_hint()) . ' 然后刷新本页复检。</p>';
        } else {
            echo '<p class="hint" style="color:#b45309"><b>php.ini 未找到或不可写——未做任何修改。</b>已改用降级方案：点下方按钮下载配置片段（与一键写入内容同源），通过 FTP / 面板编辑器追加到当前 PHP 的 php.ini 末尾，然后重启 PHP。</p>';
            echo '<form method="post" action="' . e(u('a=admin_file_download')) . '" class="inline">' . csrf_field() .
                '<input type="hidden" name="what" value="envfix">' .
                '<button class="btn btn-primary" type="submit">' . t('下载 php.ini 配置片段（FTP 上传用）') . '</button></form>';
            echo '<p class="hint">' . e(efx_panel_hint()) . '</p>';
        }
    } else {
        echo '<p class="hint">扩展环境全部就绪：本程序核心零依赖即可运行，以上为增强项。如需上传较大迁移包 / 更新包，可在 php.ini 调整 upload_max_filesize / post_max_size。</p>';
    }
    if ($efx['nobundle']) {
        echo '<p class="hint">以下扩展当前 PHP 未随附二进制文件（写入 php.ini 也加载不了），请通过面板扩展商店 / 主机商安装后再看：' . e(implode('、', $efx['nobundle'])) . '。</p>';
    }
    echo '</div>';

    /* v1.41.2 上传链路自检：生产侧「图片 / 文件无法上传」的定位入口——
       按上传接口的真实判定顺序逐项探测（开关 → PHP 上传层 → 体积上限 → 临时目录 → 存储目录 → 白名单），
       哪一步 ✘ 就指认哪一环并给出修复办法 */
    $efxUp = $efx['upchain'];
    $efxUpBad = 0;
    foreach ($efxUp['steps'] as $s) {
        if (!$s['ok']) {
            $efxUpBad++;
        }
    }
    echo '<div class="card form-card"><h2 class="card-title">' . t('上传链路自检') . '</h2>';
    if ($efxUpBad > 0) {
        echo '<p class="hint" style="color:#b45309"><b>⚠ 发现 ' . (int)$efxUpBad . ' 处异常——「图片 / 文件无法上传」多半就是它们所致，按下方对应行的办法处理后刷新本页复检：</b></p>';
    } else {
        echo '<p class="hint" style="color:#15803d"><b>✔ 全部通过：服务器侧上传链路就绪。若前台仍无法上传，请重试一次并留意具体报错文案（v1.41.0 起报错会精确指出原因）。</b></p>';
    }
    echo '<table class="log-table"><thead><tr><th style="width:30px"></th><th>检查项</th><th>当前值</th><th>异常时的修复办法</th></tr></thead><tbody>';
    foreach ($efxUp['steps'] as $s) {
        echo '<tr><td>' . ($s['ok'] ? '<b style="color:#15803d">✔</b>' : '<b style="color:#dc2626">✘</b>') . '</td><td><b>' . e((string)$s['t']) . '</b></td><td>' . e((string)$s['v']) . '</td><td class="muted">' . ($s['fix'] !== '' ? e((string)$s['fix']) : '—') . '</td></tr>';
    }
    echo '</tbody></table>';
    echo '<p class="hint">探测顺序与发帖上传接口的判定完全一致；存储目录探针为真实「建目录 + 写入 + 删除」，不影响已有文件。图片（png / jpg / jpeg / gif / webp）不受白名单影响，恒可上传。</p></div>';

    /* v1.33.0 性能体检：OPcache 是弱 CPU / 共享主机整站缓慢的头号原因——
       未启用时每个请求都要重新编译全部 PHP 源码（30+ 文件），CPU 全耗在重复劳动上。
       提供一键创建 / 删除 .user.ini（LiteSpeed / CGI / FastCGI / FPM 类主机生效） */
    $opLoaded = extension_loaded('Zend OPcache') || function_exists('opcache_get_status');
    $opRaw = strtolower(trim((string)@ini_get('opcache.enable')));
    $opOn = $opLoaded && $opRaw !== '' && $opRaw !== '0' && $opRaw !== 'off' && $opRaw !== 'false';
    $userIni = dirname(DATA_DIR) . '/.user.ini';
    $userIniRaw = is_file($userIni) ? (string)@file_get_contents($userIni) : '';
    $userIniOurs = $userIniRaw !== '' && strpos($userIniRaw, 'opcache.enable') !== false;
    /* v1.35.1 预检站点根目录写权限：不可写时不展示写盘按钮，改走「下载合并文件 → FTP 上传」降级 */
    [$iniFileW, $iniRootW] = mf_root_write_check($userIni);
    $iniManual = !$iniFileW && !$iniRootW;
    $opHit = '';
    if ($opOn && function_exists('opcache_get_status')) {
        $ops = @opcache_get_status(false);
        if (is_array($ops) && isset($ops['opcache_statistics']['opcache_hit_rate'])) {
            $opHit = '（命中率 ' . round((float)$ops['opcache_statistics']['opcache_hit_rate'], 1) . '%）';
        }
    }
    echo '<div class="card form-card"><h2 class="card-title">' . t('性能体检') . '</h2><div class="mon-grid">' .
        '<div class="mon-cell' . ($opOn ? '' : ' cell-bad') . '"><b>' . ($opOn ? '✔ 已开启' : ($opLoaded ? '未启用' : '未安装')) . '</b><span>OPcache 字节码缓存</span></div>' .
        '<div class="mon-cell"><b>' . ($opOn ? '✔' : '—') . '</b><span>页面 gzip 压缩（程序自带）</span></div>' .
        '<div class="mon-cell"><b>✔</b><span>页面 ETag / 304 协商缓存（程序自带）</span></div>' .
        '</div>';
    if ($opOn) {
        echo '<p class="hint">OPcache 已启用' . e($opHit) . '：PHP 编译结果跨请求复用，弱 CPU 主机上省去每请求的重复编译。程序侧 gzip / ETag / 304 已内建，无需配置。</p>';
    } else {
        echo '<p class="hint"><b style="color:var(--danger)">OPcache 未启用：服务器每个请求都要重新编译全部 PHP 源码（本程序 30+ 个文件），弱 CPU / 共享主机上这往往是「整站加载缓慢、部分设备打不开」的头号原因。</b>' .
            ($opLoaded ? '当前 PHP 已带 OPcache 扩展但被配置关闭。' : '当前 PHP 未加载 OPcache 扩展。') .
            '可点击下方按钮在站点根目录创建 .user.ini 尝试开启——LiteSpeed / CGI / FastCGI / FPM 类主机生效（约 5 分钟内或重启 PHP 后）；Apache mod_php 会忽略该文件，此类主机请联系主机商在 php.ini 开启 opcache.enable=1。</p>';
        if ($userIniOurs) {
            echo '<p class="hint">.user.ini 已含 OPcache 配置。若长时间未生效，请等待 5 分钟（user_ini.cache_ttl）或重启 PHP，再刷新本页查看。' .
                ($iniManual ? '当前站点根目录对 PHP 不可写，如需移除请通过 FTP 直接编辑 / 删除该文件。' : '') . '</p>';
            if (!$iniManual) {
                echo '<form method="post" action="' . e(u('a=admin_opcache_ini')) . '" class="inline" data-n="' . e(t('确认移除 .user.ini 中的 OPcache 配置？仅移除 OPcache 相关行，其余内容保留不动；移除前自动备份原文件到 data/backup/。')) . '">' . csrf_field() .
                    '<button class="btn btn-primary" type="submit" name="op" value="del">' . t('移除 OPcache 配置（还原）') . '</button></form>';
            }
        } elseif ($iniManual) {
            /* v1.35.1 降级面板：根目录不可写 → 下载合并文件 + FTP 指引（不再展示必然失败的写盘按钮） */
            echo '<p class="hint" style="color:var(--danger)"><b>检测到站点根目录对 PHP 不可写——程序无法直接创建 / 修改 .user.ini（你的现有文件未被改动）。</b>已改用降级方案：点下方按钮下载合并好的完整文件' .
                ($userIniRaw !== '' ? '（站点根目录现有 .user.ini 的内容已原样合并在内）' : '') . '，再通过 FTP / 主机面板上传到站点根目录。</p>' .
                '<form method="post" action="' . e(u('a=admin_file_download')) . '" class="inline">' . csrf_field() .
                '<input type="hidden" name="what" value="userini">' .
                '<button class="btn btn-primary" type="submit">' . t('下载 .user.ini（已含 OPcache 配置）') . '</button></form>' .
                '<p class="hint">下载后通过 FTP / 主机面板上传到站点根目录' . ($userIniRaw !== '' ? '，覆盖原 .user.ini' : '') . '；若浏览器保存的文件名丢了开头的点，请重命名为 .user.ini（开头是一个英文句点）。LiteSpeed / CGI / FastCGI / FPM 主机约 5 分钟内生效（或重启 PHP），之后回到本页查看状态。</p>';
        } elseif ($userIniRaw !== '') {
            echo '<form method="post" action="' . e(u('a=admin_opcache_ini')) . '" class="inline" data-n="' . e(t('站点根目录已存在 .user.ini。确认后：先自动备份原文件到 data/backup/，再在文件末尾追加 OPcache 配置（你的现有内容完整保留）。确认追加？')) . '">' . csrf_field() .
                '<button class="btn btn-primary" type="submit" name="op" value="append">' . t('追加 OPcache 配置到现有 .user.ini') . '</button></form>' .
                '<p class="hint">站点根目录已存在 .user.ini（不含 opcache 配置）。点「追加」＝完整保留你的现有内容，仅在末尾追加 OPcache 配置（原文件先自动备份）；也可手动编辑该文件，加入一行：opcache.enable=1</p>';
        } else {
            echo '<form method="post" action="' . e(u('a=admin_opcache_ini')) . '" class="inline">' . csrf_field() .
                '<button class="btn btn-primary" type="submit" name="op" value="create">' . t('一键创建 .user.ini（开启 OPcache）') . '</button></form>';
        }
    }
    echo '</div>';

    /* v1.36.0 HTML 静态加速：坛主一键切换「PHP 动态优先 / HTML 静态优先」——
       游客直接浏览预生成的静态快照（首页 / 版块 / 帖子），登录用户与全部操作照常实时处理 */
    $sgModeOn = mfs_mode_on();
    $sgCap = mfs_capable();
    [$sgN, $sgBytes, $sgLast] = mfs_stats();
    $sgHtStat = mfs_htaccess_status();
    [$sgHtFW, $sgHtRW] = mf_root_write_check(dirname(DATA_DIR) . '/.htaccess');
    $sgHtManual = !$sgHtFW && !$sgHtRW;
    echo '<div class="card form-card"><h2 class="card-title">' . t('HTML 静态加速') . '</h2><div class="mon-grid">' .
        '<div class="mon-cell"><b>' . ($sgModeOn ? t('HTML 静态优先') : t('PHP 动态优先')) . '</b><span>当前模式</span></div>' .
        '<div class="mon-cell"><b>' . $sgN . ' 页 · ' . e(fmt_bytes($sgBytes)) . '</b><span>静态快照</span></div>' .
        '<div class="mon-cell"><b>' . ($sgLast > 0 ? e(fmt_time($sgLast)) : '—') . '</b><span>最近生成</span></div>' .
        '<div class="mon-cell"><b>' . ($sgHtStat === 'installed' ? '✔ 已安装' : ($sgHtStat === 'none' ? '— 无 .htaccess' : '未安装')) . '</b><span>.htaccess 加速块</span></div>' .
        '</div>';
    echo '<p class="hint">游客访问「首页 / 版块页 / 帖子页」时直接返回预生成的 HTML 快照，跳过内容读取与页面渲染——慢盘共享主机上加载速度量级提升。登录用户、发帖 / 回帖 / 管理 / 搜索等全部功能照常实时处理；内容更新自动作废对应快照（另有 6 小时保鲜期兜底）。仅缓存游客可见的公开页：关闭「游客可浏览」或开启「协议访问门禁」时自动停用并清空快照。Apache / LiteSpeed 由加速块直出（PHP 不启动）；其他服务器自动走 PHP 快速通道（同样跳过渲染，零配置）。</p>';
    if (!$sgCap) {
        echo '<p class="hint" style="color:var(--danger)"><b>前提不满足：</b>' .
            (!feat_on('guest_browse') ? '「游客可浏览」已关闭（私密论坛不缓存游客页面）。' : '') .
            ((int)cfg('doc_gate', 0) === 1 ? '「协议访问门禁」已开启（直出快照无法执行门禁判定）。' : '') .
            '请先调整后再切换模式。</p>';
    }
    if ($sgModeOn) {
        echo '<form method="post" action="' . e(u('a=admin_static_mode')) . '" class="inline" data-n="' . e(t('确认切换回 PHP 动态优先？将移除 .htaccess 加速块并清空全部静态快照，所有页面恢复实时渲染。')) . '">' . csrf_field() .
            '<button class="btn btn-ghost" type="submit" name="op" value="php">' . t('切换回 PHP 动态优先') . '</button></form>' .
            '<form method="post" action="' . e(u('a=admin_static_clear')) . '" class="inline" data-n="' . e(t('确认清空全部静态快照？游客将暂时回退 PHP 实时渲染，快照随访问自动重新生成。')) . '">' . csrf_field() .
            '<button class="btn btn-ghost" type="submit">' . t('清空静态快照') . '</button></form>';
        if ($sgCap) {
            echo '<button class="btn btn-primary" type="button" id="mf-sgen-btn">' . t('立即预生成全站静态页') . '</button>' .
                '<span class="hint" id="mf-sgen-out">按最近活跃顺序逐页生成，可随时关闭页面；未生成的会随游客访问自动补齐</span>' .
                '<script id="mf-sgen-list" type="application/json">' . json_encode(mfs_gen_list(), JSON_UNESCAPED_UNICODE) . '</script>';
        }
    } else {
        echo '<form method="post" action="' . e(u('a=admin_static_mode')) . '" class="inline" data-n="' . e(t('确认切换到 HTML 静态优先？游客将直接浏览预生成的静态快照（首页 / 版块 / 帖子），登录用户与发帖等操作照常实时；将尝试写入 .htaccess 加速块（原文件自动备份，可随时一键移除）。')) . '">' . csrf_field() .
            '<button class="btn btn-primary" type="submit" name="op" value="html"' . (!$sgCap ? ' disabled' : '') . '>' . t('切换到 HTML 静态优先') . '</button></form>';
    }
    if ($sgModeOn || $sgHtStat === 'installed') {
        if ($sgHtStat === 'installed') {
            echo '<form method="post" action="' . e(u('a=admin_static_htaccess')) . '" class="inline" data-n="' . e(t('确认重新写入 .htaccess 加速块？将按当前环境更新标记块（先自动备份原文件，幂等可重复执行）。')) . '">' . csrf_field() .
                '<button class="btn btn-ghost" type="submit" name="op" value="install">' . t('重新写入 .htaccess 加速块') . '</button></form>' .
                '<form method="post" action="' . e(u('a=admin_static_htaccess')) . '" class="inline" data-n="' . e(t('确认移除 .htaccess 加速块？移除后游客访问回退 PHP 实时渲染（不影响登录用户）；原文件先自动备份。')) . '">' . csrf_field() .
                '<button class="btn btn-ghost" type="submit" name="op" value="remove">' . t('移除 .htaccess 加速块') . '</button></form>';
        } elseif ($sgHtManual) {
            echo '<p class="hint" style="color:var(--danger)"><b>站点根目录对 PHP 不可写——无法自动写入 .htaccess。</b>不影响使用：PHP 快速通道已生效。需要 Apache 直出时，点下方按钮下载已合并加速块的完整文件，通过 FTP 上传到站点根目录并重命名为 .htaccess。</p>' .
                '<form method="post" action="' . e(u('a=admin_file_download')) . '" class="inline">' . csrf_field() .
                '<input type="hidden" name="what" value="htaccess">' .
                '<button class="btn btn-primary" type="submit">' . t('下载 .htaccess（已合并加速块，FTP 上传）') . '</button></form>';
        } else {
            echo '<form method="post" action="' . e(u('a=admin_static_htaccess')) . '" class="inline" data-n="' . e(t('确认写入 .htaccess 加速块？先自动备份原文件到 data/backup/，再在末尾追加标记块（幂等，可随时一键移除）。')) . '">' . csrf_field() .
                '<button class="btn btn-ghost" type="submit" name="op" value="install">' . t('安装 .htaccess 加速块') . '</button></form>';
        }
    }
    echo '</div>';

    echo '<div class="card form-card"><h2 class="card-title">' . t('内容规模') . '</h2><div class="mon-grid">' .
        '<div class="mon-cell"><b>' . $threads . '</b><span>帖子</span></div>' .
        '<div class="mon-cell"><b>' . $withReplies . '</b><span>有回复的帖</span></div>' .
        '<div class="mon-cell"><b>' . $users . '</b><span>用户</span></div>' .
        '<div class="mon-cell"><b>' . e(fmt_bytes($lb)) . '</b><span>操作日志（' . $lf . ' 个文件）</span></div>' .
        '<div class="mon-cell"><b>' . $backups . '</b><span>备份文件</span></div>' .
        '<div class="mon-cell"><b>' . ($php['curl'] ? '✔' : '—') . '</b><span>curl 扩展（AI 用）</span></div>' .
        '</div></div>';

    $on = (int)cfg('monitor_on', 1) === 1;
    $qmb = max(10, (int)cfg('quota_mb', 100));
    echo '<div class="card form-card"><h2 class="card-title">' . t('监控与告警设置') . '</h2>' .
        '<form method="post" action="' . e(u('a=admin_save_monitor')) . '">' . csrf_field() .
        '<div class="grid2">' .
        '<label class="field"><span class="field-l">' . t('自动告警') . '</span><select name="monitor_on" class="input">' .
        '<option value="1"' . ($on ? ' selected' : '') . '>' . t('开启') . '</option>' .
        '<option value="0"' . (!$on ? ' selected' : '') . '>' . t('关闭') . '</option></select></label>' .
        '<label class="field"><span class="field-l">' . t('告警阈值（MB）') . '</span><input class="input" name="monitor_mb" type="number" min="1" max="1048576" value="' . $th . '"><span class="hint">程序 + 数据总占用超过此值发告警邮件（当前总占用 ' . e(fmt_bytes($total)) . '）；虚拟主机小配额建议 80～95，云服务器 / 大磁盘可按需填 2048（2GB）、10240（10GB）等</span></label>' .
        '<label class="field"><span class="field-l">' . t('主机配额（MB）') . '</span><input class="input" name="quota_mb" type="number" min="10" max="1048576" value="' . $qmb . '"><span class="hint">主机空间总配额，仅用于存储进度条与百分比展示（不影响告警判定）；虚拟主机一般 100～1000，云服务器按实际磁盘填，支持到 1TB</span></label>' .
        '<label class="field"><span class="field-l">' . t('监控页实时刷新间隔（秒，0=关闭）') . '</span><input class="input" name="monitor_interval" type="number" min="0" max="300" value="' . $monInt . '"><span class="hint">实时资源卡的自动刷新频率，建议 3～30 秒；0 为关闭以节省流量</span></label>' .
        '</div>' .
        '<div class="form-foot"><button class="btn btn-ghost" type="button" data-admin-test="monitor">发送测试告警邮件</button>' .
        '<button class="btn btn-primary" type="submit">' . t('保存监控设置') . '</button></div>' .
        '<span class="test-msg muted"></span></form>' .
        admin_restore_btn('monitor_on,monitor_interval,monitor_mb,quota_mb') .
        '<p class="hint">总占用（程序 + 数据）超过「告警阈值」时自动给全部管理员发告警邮件；「主机配额」只用于存储进度条与百分比展示，按主机实际空间填写。检查随论坛访问进行（每小时至多一次）；持续超限时每 6 小时提醒一次，回落到阈值下后自动重置。告警通过后台「邮件」中配置的 SMTP 发送。</p></div>';
}

/* ---------------- 系统 ---------------- */
function admin_tab_system(array $me): void
{
    [$appSize, $dataSize] = sysmon_sizes();
    $threads = count(thread_index());
    $users = count(user_all());
    // 与监控页同口径：数索引中 replies > 0 的帖子（旧逻辑数 threads/ 目录文件数，等于帖子总数）
    $withReplies = 0;
    foreach (thread_index() as $t) {
        if ((int)($t['replies'] ?? 0) > 0) {
            $withReplies++;
        }
    }
    $backups = array_reverse(Store::scan('backup'));

    echo '<div class="card form-card"><h2 class="card-title">' . t('系统信息') . '</h2><div class="stat-row">' .
        '<div class="stat"><b>v' . e(app_version()) . '</b><span>论坛版本</span></div>' .
        '<div class="stat"><b>' . e(PHP_VERSION) . '</b><span>PHP 版本</span></div>' .
        '<div class="stat"><b>' . e(fmt_bytes($appSize)) . '</b><span>程序体积</span></div>' .
        '<div class="stat"><b>' . e(fmt_bytes($dataSize)) . '</b><span>数据占用</span></div>' .
        '<div class="stat"><b>' . $threads . '</b><span>帖子总数</span></div>' .
        '<div class="stat"><b>' . $withReplies . '</b><span>有回复的帖</span></div>' .
        '<div class="stat"><b>' . $users . '</b><span>用户总数</span></div>' .
        '</div>' .
        '<p class="hint">程序本体限制 ≤ 10MB；数据目录随使用增长，建议定期「一键备份」并下载留存；升级请到「更新升级」上传更新包。</p></div>';

    echo '<div class="card form-card"><h2 class="card-title">' . t('数据备份') . '</h2>' .
        '<form method="post" action="' . e(u('a=admin_backup')) . '" class="inline">' . csrf_field() .
        '<button class="btn btn-primary" type="submit">' . t('一键备份数据目录') . '</button></form>' .
        '<p class="hint">打包 data/ 目录（不含缓存与本次备份），保留最近 5 份，存于 data/backup/。</p>' .
        '<div class="admin-list">';
    if (!$backups) {
        echo empty_state('还没有备份');
    }
    foreach ($backups as $b) {
        echo '<div class="admin-row"><div class="u-info"><b>' . e($b) . '</b>' .
            '<span class="muted">' . e(fmt_bytes((int)@filesize(Store::path('backup/' . $b)))) . (strpos($b, 'pre-update-') === 0 ? ' · 更新前自动备份' : '') . '</span></div>' .
            '<span class="admin-row-actions">' .
            '<a class="btn btn-ghost btn-sm" href="' . e(u('a=admin_backup_dl&id=' . e($b))) . '">下载</a>' .
            '<form method="post" action="' . e(u('a=admin_backup_del')) . '" class="inline" data-confirm="确认删除该备份包？删除后不可恢复。">' . csrf_field() . hidden_back() .
            '<input type="hidden" name="id" value="' . e($b) . '">' .
            '<button class="btn btn-danger btn-sm" type="submit">' . t('删除') . '</button></form>' .
            '</span></div>';
    }
    echo '</div></div>';
    /* v1.37.0 迁移搬家：制作 / 管理 / 导入迁移包 */
    $migPkgs = mig_list();
    echo '<div class="card form-card"><h2 class="card-title">' . t('迁移搬家') . '</h2>' .
        '<p class="hint">' . t('把整站内容（帖子 / 回复 / 用户 / 版块 / 设置，可选附件与日志）打包为迁移包：新服务器在安装向导选「从迁移包恢复」即可原样就位；已装好的站可在下方导入覆盖。迁移包含全部用户数据，请妥善保管，不要存放在网站目录内。') . '</p>' .
        '<form method="post" action="' . e(u('a=admin_migrate_build')) . '" class="inline-form">' . csrf_field() .
        '<label class="check"><input type="checkbox" name="inc_media" value="1" checked> ' . t('包含附件（上传的图片 / 文件）') . '</label>' .
        '<label class="check"><input type="checkbox" name="inc_logs" value="1"> ' . t('包含运行日志') . '</label>' .
        '<button class="btn btn-primary" type="submit" data-confirm="' . t('开始制作迁移包？内容较多时可能需要数十秒。') . '">' . t('制作迁移包') . '</button></form>' .
        '<p class="hint">' . t('单包上限 32MB；附件较多时可取消「包含附件」，改用 FTP 把 data/media/ 整目录搬到新站同位置，功能不受影响。') . '</p>' .
        '<div class="admin-list">';
    if (!mig_engine_ok()) {
        echo '<p class="hint">' . t('当前为数据库引擎：数据存于数据库中，迁移包暂不适用（请用数据库自带导出 / 导入）。') . '</p>';
    } elseif (!$migPkgs) {
        echo empty_state(t('还没有迁移包'));
    }
    foreach ($migPkgs as $p) {
        echo '<div class="admin-row"><div class="u-info"><b>' . e($p['name']) . '</b>' .
            '<span class="muted">' . e(fmt_bytes($p['size'])) . ' · ' . e(date('Y-m-d H:i', $p['mtime'])) . '</span></div>' .
            '<span class="admin-row-actions">' .
            '<a class="btn btn-ghost btn-sm" href="' . e(u('a=admin_backup_dl&id=' . $p['name'])) . '">' . t('下载') . '</a>' .
            '<form method="post" action="' . e(u('a=admin_migrate_delete')) . '" class="inline" data-confirm="' . t('确认删除该迁移包？') . '">' . csrf_field() . hidden_back() .
            '<input type="hidden" name="name" value="' . e($p['name']) . '">' .
            '<button class="btn btn-danger btn-sm" type="submit">' . t('删除') . '</button></form>' .
            '</span></div>';
    }
    echo '</div>' .
        '<form method="post" action="' . e(u('a=admin_migrate_import')) . '" enctype="multipart/form-data" class="inline-form">' . csrf_field() .
        '<label class="field"><span class="field-l">' . t('导入迁移包（zip）') . '</span><input class="input" type="file" name="f" accept=".zip"></label>';
    if ($migPkgs) {
        echo '<label class="field"><span class="field-l">' . t('或选择已上传到 data/backup/ 的迁移包（FTP 上传大包后在此导入）') . '</span>' .
            '<select class="input" name="pick" style="max-width:340px"><option value="">' . t('— 不使用，从上传文件导入 —') . '</option>';
        foreach ($migPkgs as $p) {
            echo '<option value="' . e($p['name']) . '">' . e($p['name']) . '（' . e(fmt_bytes($p['size'])) . '）</option>';
        }
        echo '</select></label>';
    }
    echo '<label class="check"><input type="checkbox" name="confirm" value="1"> ' . t('我确认：导入将覆盖当前全部数据（帖子 / 用户 / 设置）') . '</label>' .
        '<div class="form-foot"><button class="btn btn-danger" type="submit" data-confirm="' . t('确定导入迁移包？当前站点数据将被完整覆盖，导入前会自动备份到 data/backup/；完成后所有用户需重新登录。') . '">' . t('导入并覆盖') . '</button></div>' .
        '</form></div>';
    /* v1.16.0 存量数据一键压缩 */
    echo '<div class="card form-card"><h2 class="card-title">' . t('数据占用压缩') . '</h2>' .
        '<p class="muted">v1.16.0 起新写入的数据文件均为 gzip 压缩格式（中文数据体积约省一半以上）；此按钮可把存量明文数据一次性转为压缩格式。</p>' .
        '<form method="post" action="' . e(u('a=admin_data_compress')) . '" class="inline-form">' . csrf_field() .
        '<button class="btn btn-primary" type="submit" data-confirm="遍历并压缩所有数据文件？数据内容不会变化，仅重新压缩存储。">一键压缩存量数据</button>' .
        '</form></div>';

}

/* ---------------- 操作日志 ---------------- */
function admin_tab_logs(): void
{
    [$lf, $lb] = log_stats();
    echo '<div class="card form-card"><div class="list-head"><h2 class="card-title">' . t('日志设置') . '</h2>' .
        '<span class="muted">共 ' . $lf . ' 个日志文件 · ' . e(fmt_bytes($lb)) . '</span></div>' .
        '<form method="post" action="' . e(u('a=admin_logs_settings')) . '" class="inline-form" data-ajax="1">' . csrf_field() .
        '<label class="field"><span class="field-l">' . t('日志保留天数（0 = 永久）') . '</span><input class="input" type="number" name="log_days" min="0" max="3650" value="' . (int)cfg('log_days', 90) . '" style="max-width:170px"></label>' .
        '<label class="field"><span class="field-l">' . t('记录页面访问（含游客）') . '</span><select class="input" name="log_views" style="max-width:170px">' .
        '<option value="0"' . ((int)cfg('log_views', 0) === 0 ? ' selected' : '') . '>' . t('关闭') . '</option>' .
        '<option value="1"' . ((int)cfg('log_views', 0) === 1 ? ' selected' : '') . '>' . t('开启') . '</option></select></label>' .
        '<button class="btn btn-primary" type="submit">' . t('保存设置') . '</button></form>' .
        admin_restore_btn('log_views,log_days') .
        '<form method="post" action="' . e(u('a=admin_logs_clear')) . '" class="inline" data-confirm="确认清理过期日志？">' . csrf_field() .
        '<button class="btn btn-ghost btn-sm" type="submit">' . t('立即清理过期日志') . '</button></form>' .
        '<form method="post" action="' . e(u('a=admin_logs_export')) . '" class="inline" data-confirm="将导出全部操作日志、防火墙事件与运行错误为 TXT 文件（日志较多时可能需要数十秒），继续？">' . csrf_field() .
        '<button class="btn btn-primary btn-sm" type="submit">' . t('⬇ 一键导出全部日志（TXT）') . '</button></form>' .
        '<p class="hint">日志按天存放于 data/logs/（守卫保护，无法被直接访问）；超过保留天数的文件会在每天首次写日志时自动清理；日志总量上限 20MB，超出时从最旧删起。导出内容包含：全部操作日志、全部防火墙事件日志、运行错误日志（尾部 200 行），流式生成不占内存，TXT 可直接用记事本 / Excel 打开。</p></div>';

    $files = log_files();
    $date = (string)($_GET['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = $files ? substr($files[0], 4, 10) : date('Y-m-d');
    }
    $qUser = trim((string)($_GET['quser'] ?? ''));
    $qAction = (string)($_GET['qaction'] ?? '');
    $page = max(1, get_int('page', 1));
    $per = 50;
    $total = 0;
    $rows = log_read($date, $per, $page, $qUser, $qAction, $total);

    echo '<div class="card form-card"><h2 class="card-title">' . t('查询日志') . '</h2>' .
        '<form method="get" action="' . e(u('')) . '" class="inline-form">' .
        '<input type="hidden" name="p" value="admin"><input type="hidden" name="tab" value="logs">' .
        '<select name="date" class="input input-sm">';
    $opts = [$date => true, date('Y-m-d') => true];
    foreach ($files as $f) {
        $opts[substr($f, 4, 10)] = true;
    }
    foreach (array_keys($opts) as $d) {
        echo '<option value="' . e($d) . '"' . ($d === $date ? ' selected' : '') . '>' . e($d) . '</option>';
    }
    echo '</select>' .
        '<input class="input input-sm" name="quser" value="' . e($qUser) . '" placeholder="用户名或 IP">' .
        '<select name="qaction" class="input input-sm"><option value="">' . t('全部动作') . '</option>';
    foreach (LOG_ACTIONS as $k => $v) {
        echo '<option value="' . e($k) . '"' . ($k === $qAction ? ' selected' : '') . '>' . e($v) . '</option>';
    }
    echo '</select>' .
        '<button class="btn btn-ghost btn-sm" type="submit">' . t('筛选') . '</button></form>';

    $logActStats = log_action_stats($date, 10);
    if ($logActStats) {
        $logActItems = [];
        foreach ($logActStats as $a => $nn) {
            $logActItems[log_label($a)] = (int)$nn;
        }
        echo '<h3 class="chart-title" style="margin-top:16px">动作分布 Top ' . count($logActItems) . '（' . e($date) . '）</h3>';
        echo chart_bars_h($logActItems, ['label' => '当日操作日志动作分布', 'tip' => function ($l, $v) { return $l . '：' . $v . ' 次'; }]);
    }

    echo '<div class="table-wrap" style="margin-top:' . ($logActStats ? '14' : '0') . 'px"><table class="log-table"><thead><tr><th>时间</th><th>用户</th><th>动作</th><th>详情</th><th>IP</th></tr></thead><tbody>';
    if (!$rows) {
        echo '<tr><td colspan="5">' . empty_state('该日期暂无日志') . '</td></tr>';
    }
    foreach ($rows as $r) {
        $act = (string)$r['action'];
        $cls = strpos($act, 'admin_') === 0 || $act === 'update_apply' || $act === 'install' ? ' badge-accent'
            : ($act === 'login_failed' || $act === 'ai_bad' || $act === 'report' ? ' badge-warn' : '');
        echo '<tr>' .
            '<td class="nowrap">' . date('H:i:s', (int)($r['t'] ?? 0)) . '</td>' .
            '<td class="nowrap">' . ((int)($r['uid'] ?? 0) > 0
                ? '<a href="' . e(u('p=user&id=' . (int)$r['uid'])) . '">' . e((string)($r['name'] ?? '')) . '</a>'
                : e((string)($r['name'] ?? '游客'))) . '</td>' .
            '<td class="nowrap"><span class="badge' . $cls . '">' . e(log_label($act)) . '</span></td>' .
            '<td class="log-detail">' . e((string)($r['detail'] ?? '')) . '</td>' .
            '<td class="muted nowrap">' . e((string)($r['ip'] ?? '')) . '</td>' .
            '</tr>';
    }
    echo '</tbody></table></div>';
    echo paginate($total, $per, $page, 'p=admin&tab=logs&date=' . urlencode($date) . '&quser=' . urlencode($qUser) . '&qaction=' . urlencode($qAction));
    echo '</div>';
}

/* ---------------- 更新升级 ---------------- */
function admin_tab_update(): void
{
    $cur = app_version();
    $his = update_history();
    /* v1.28.0：查看更新页时顺手清理堆积的程序备份（此前只在安装更新 / 回滚时清理，
       CLI 或旧版创建的历史备份会一直堆积，系统页备份列表被拉得很长） */
    if (function_exists('program_backup_prune')) {
        program_backup_prune(3);
        $bks = program_backup_list();
    } else {
        $bks = [];
    }
    echo '<div class="card form-card"><h2 class="card-title">' . t('当前版本') . '</h2><div class="stat-row">' .
        '<div class="stat"><b>v' . e($cur) . '</b><span>当前版本</span></div>' .
        '<div class="stat"><b>v' . e(MF_VERSION) . '</b><span>程序内置版本</span></div>' .
        '<div class="stat"><b>' . count($his) . '</b><span>已安装更新包</span></div>' .
        '</div>' .
        '<p class="hint">「程序内置版本」表示当前部署的代码版本；「当前版本」会随更新包安装自动前进。若通过更新包升级，二者可以不同（以「当前版本」为准，数据不会丢失）。</p>' .
        /* v1.48.1 版本一致性警示：两个版本不一致 = 升级只做了一半。
           典型场景：FTP 覆盖了程序文件但没装更新包（数据版本没推进）——
           资源缓存键、自检 manifest、迁移脚本都停留在旧版本，随机出现「上传不了 / 功能异常」。 */
        ((string)MF_VERSION !== (string)$cur
            ? '<p class="hint" style="color:#b45309">⚠ 版本不一致：程序文件已为 v' . e(MF_VERSION) . '，数据版本仍为 v' . e($cur) . '。请在本页上传 v' . e(MF_VERSION) . ' 的官方更新包（forum-update-any-to-' . e(MF_VERSION) . '.zip）完成升级；安装后两个版本会一致，前台缓存也会自动刷新。</p>'
            : '') .
        '</div>';

    echo '<div class="card form-card"><h2 class="card-title">' . t('上传更新包') . '</h2>' .
        '<form method="post" action="' . e(u('a=admin_update')) . '" enctype="multipart/form-data" data-confirm="确认安装该更新包？安装前会自动备份当前程序文件。">' . csrf_field() .
        '<label class="field"><span class="field-l">' . t('更新包（.zip，由开发者提供，内含 update.json）') . '</span><input class="input" type="file" name="pkg" accept=".zip" required></label>' .
        '<div class="form-foot"><span></span><button class="btn btn-primary" type="submit">' . t('上传并安装更新') . '</button></div></form>' .
        '<p class="hint">安装流程：① 自动备份当前程序文件到 data/backup/ → ② 覆盖变更文件 → ③ 执行数据迁移脚本 → ④ 版本号前进。<b>data/ 数据目录永远不会被更新包改动</b>；完成后刷新页面即可，<b>无需重新安装、数据不丢</b>。若 php.ini 限制了上传大小，请调大 upload_max_filesize / post_max_size。</p></div>';

    echo '<div class="card form-card"><h2 class="card-title">' . t('更新历史') . '</h2><div class="admin-list">';
    if (!$his) {
        echo empty_state('还没有安装过更新包（完整安装包部署的站点从当前版本直接开始）');
    }
    foreach (array_reverse($his) as $h) {
        echo '<div class="card queue-item"><div class="notice-head"><b>v' . e((string)($h['from'] ?? '?')) . ' → v' . e((string)($h['version'] ?? '?')) . '</b>' .
            '<span class="muted">' . e(fmt_dt((int)($h['time'] ?? 0))) . ' · ' . (int)($h['files'] ?? 0) . ' 个文件</span></div>' .
            ((string)($h['notes'] ?? '') !== '' ? '<p class="notice-body" style="white-space:pre-wrap">' . e((string)$h['notes']) . '</p>' : '') .
            '</div>';
    }
    echo '</div></div>';

    /* ================= v1.26.0 回撤更新（$bks 已在函数开头取得并完成清理） ================= */
    echo '<div class="card form-card"><h2 class="card-title">' . t('回撤更新') . '</h2>';
    echo '<p class="hint">' . t('每次安装更新包前，程序都会自动把当时的完整程序文件备份到 data/backup/（保留最近 3 份）。若新版本出现问题，可在下方一键回滚：只覆盖程序文件并把版本号回退，帖子 / 用户 / 设置等 data/ 数据不受影响。回滚前会先把当前状态另存一份备份（新版程序可用它再恢复回来；若回滚目标版本较旧、没有回撤功能，重新上传更新包即可回到新版。更新包附带的数据迁移脚本无法自动回退，官方更新包目前均不含迁移脚本）。') . '</p>';
    if (!$bks) {
        echo empty_state(t('还没有可回滚的更新备份（通过「上传更新包」升级过之后，这里会出现更新前的版本）'));
    } else {
        echo '<div class="admin-list">';
        foreach ($bks as $b) {
            echo '<div class="admin-row"><div class="u-info"><span class="badge badge-info">v' . e($b['version'] !== '' ? $b['version'] : '?') . '</span>' .
                '<span class="muted">' . e($b['file']) . ' · ' . e(fmt_dt($b['time'])) . ' · ' . e($b['size_h']) . '</span></div>' .
                '<form method="post" action="' . e(u('a=admin_update_rollback')) . '" class="inline" data-confirm="' . e(t('确认回滚到该版本？当前程序文件会先自动备份，再被覆盖为该备份的版本。')) . '">' . csrf_field() .
                '<input type="hidden" name="bk" value="' . e($b['file']) . '">' .
                '<button class="btn btn-ghost btn-sm" type="submit">' . t('回滚到此版本') . '</button></form></div>';
        }
        echo '</div>';
    }
    echo '</div>';
}

/* ================= 安全防护（防火墙） ================= */

/** 安全防护总览（防火墙 / 限流 / 策略 / 危险 IP 库 / 访问统计 / 封禁 / 事件日志） */
function admin_tab_security(): void
{
    /* ================= v1.20.0：账号安全（登录设备 / 二次验证 / 并发上限） ================= */
    echo '<div class="card form-card"><h2 class="card-title">' . t('账号安全 · 登录设备管理') . '</h2>' .
        '<form data-admin-save="security">' .
        '<b class="ai-sub">新设备登录防护与同时在线设备数量</b>' .
        '<label class="check"><input type="hidden" name="dev_verify" value="0"><input type="checkbox" name="dev_verify" value="1"' . ((int)cfg('dev_verify', 0) === 1 ? ' checked' : '') . '> <b>新设备二次验证（全站总闸）</b> —— 开启后，所有用户在新设备登录时都必须先输入邮箱验证码（发送至注册邮箱，5 分钟有效）；关闭时用户仍可在「个人设置 → 登录设备」自行开启。验证码邮件无法发送时（如 SMTP 未配置）自动降级放行并留痕，不会把用户挡在门外</label>' .
        '<div class="grid2">' .
        '<label class="field"><span class="field-l">' . t('同一账号同时在线设备上限（台）') . '</span><input class="input" name="sess_max" type="number" min="1" max="50" value="' . dev_cfg_max() . '"><span class="hint">1-50，默认 10；超出时最早登录的设备被自动下线，当前正在使用的设备永远保留</span></label>' .
        '</div>' .
        '<p class="hint">每次登录都会记录设备名称、IP 与归属地（用户可在「个人设置 → 登录设备」查看并一键下线任意设备）；新设备登录成功后系统自动发送站内提醒，引导用户修改密码与下线设备。被下线的设备 30 天内无法借助「保持登录」静默重登，但用密码主动登录视为本人操作。</p>' .
        '<div class="form-foot"><span></span><button class="btn btn-primary" type="button" data-admin-save="security">保存账号安全设置</button></div>' .
        '<span class="test-msg muted"></span></form>' .
        admin_restore_btn('dev_verify,sess_max') . '</div>';

    /* ================= v1.16.0：AI 自主防护（严全面模式）—— 从「AI」页移入本页（本质是安全防护功能） ================= */
    echo '<div class="card form-card ai-patrol-card"><h2 class="card-title">' . t('AI 自主防护（严全面模式）') . '</h2>' .
        '<form>' .
        '<b class="ai-sub">无人值守时让 AI 全自主值守论坛</b>' .
        '<label class="check"><input type="hidden" name="ai_autopilot" value="0"><input type="checkbox" name="ai_autopilot" value="1"' . (!empty(cfg('ai_autopilot')) ? ' checked' : '') . '> <b>AI 自主管理</b> —— 授权 AI 无人值守时自主巡逻论坛：分析风险 IP 与关键日志，<b>自主封禁恶意 IP / 解除误封 / 邮件警报管理员</b>。所有动作强制白名单校验：只能封本次材料中出现过的风险 IP、保护名单免疫、单轮封禁≤上限、管理员手动封禁不可解，全部留痕可撤销。巡逻节奏：常驻巡逻器（php daemon.php）在线时按间隔主动巡，否则由访客访问惰性触发（有风险事件才调用 AI，零浪费）</label>' .
        '<div class="grid2">' .
        '<label class="field"><span class="field-l">' . t('巡逻间隔（分钟）') . '</span><input class="input" name="ai_patrol_interval" type="number" min="2" max="360" value="' . ai_patrol_interval() . '"><span class="hint">每次巡逻至少间隔（2-360，默认 15）；间隔越短响应越快，无事件时零消耗</span></label>' .
        '<label class="field"><span class="field-l">' . t('单轮封禁上限（个）') . '</span><input class="input" name="ai_patrol_ban_limit" type="number" min="1" max="10" value="' . ai_patrol_ban_limit() . '"><span class="hint">AI 一轮巡逻最多封禁的 IP 数（1-10，默认 3，红线防滥杀）</span></label>' .
        '</div>' .
        '<label class="check"><input type="hidden" name="ai_patrol_alert" value="0"><input type="checkbox" name="ai_patrol_alert" value="1"' . (!empty(cfg('ai_patrol_alert', 1)) ? ' checked' : '') . '> <b>允许 AI 发邮件警报</b> —— 巡逻发现严重态势（持续攻击、封禁激增、审核服务异常）时给管理员发邮件（30 分钟节流，轻微事件不打扰）</label>' .
        '<div class="form-foot"><button class="btn btn-primary" type="button" data-admin-save="ai">保存自主防护设置</button></div>' .
        '<span class="test-msg muted"></span></form>' .
        admin_restore_btn('ai_autopilot,ai_patrol_interval,ai_patrol_ban_limit,ai_patrol_alert') .
        '<p class="hint">「发帖 AI 预检」（内容审核开关）在「AI」页与「功能」页配置。</p></div>';


/* ---- 卡片 3：巡逻状态与最近报告 ---- */
    $ps = ai_patrol_state();
    $alive = ai_daemon_alive();
    $prep = is_array($ps['report'] ?? null) ? $ps['report'] : null;
    $lastT = (int)($ps['last'] ?? 0);
    if ($alive) {
        $modeBadge = '<span class="badge badge-ok">🟢 常驻巡逻器在线</span>';
        $modeHint = 'daemon.php 正在值守，按巡逻间隔主动巡逻，无需访客触发';
    } elseif (!empty(cfg('ai_autopilot')) && ai_ready()) {
        $modeBadge = '<span class="badge badge-warn">🟡 访客触发模式</span>';
        $modeHint = '可选增强：在能常驻进程的主机运行 php daemon.php 可开启 7×24 常驻巡逻（响应更快）；当前由访客访问惰性触发巡逻，效果一致';
    } else {
        $modeBadge = '<span class="badge">⚪ 未启用</span>';
        $modeHint = '在上方勾选「AI 自主管理」并保存后开始巡逻';
    }
    echo '<div class="card form-card ai-patrol-card"><h2 class="card-title">' . t('AI 自主管理 · 巡逻状态与最近报告') . '</h2>' .
        '<div class="ai-patrol-head">' . $modeBadge .
        '<span class="hint">上次巡逻：' . ($lastT > 0 ? e(date('m-d H:i', $lastT)) . '（' . e(fmt_time($lastT)) . '）' : '尚未巡逻')
        . ' · 间隔 ' . ai_patrol_interval() . ' 分钟 · 单轮封禁上限 ' . ai_patrol_ban_limit() . '</span>' .
        '<button class="btn btn-ghost btn-sm" type="button" data-admin-patrol="now">立即巡逻一次</button></div>' .
        '<p class="hint">' . e($modeHint) . '</p>';
    if ($prep === null) {
        echo empty_state('巡逻器还没有产出报告，点击「立即巡逻一次」查看 AI 对当前态势的判断');
    } else {
        echo '<div class="ai-patrol-report">' .
            '<div class="report-head"><b>' . ($prep['ok'] ? '态势总结' : '巡逻异常') . '</b>' .
            '<span class="muted">' . e(date('Y-m-d H:i:s', (int)($prep['time'] ?? 0))) . ' · ' . e((string)($prep['used'] ?? '')) . '</span></div>' .
            '<p class="assess">' . e((string)($prep['assess'] ?? '')) . '</p>';
        $rows = (array)($prep['rows'] ?? []);
        if ($rows) {
            $actName = ['ban' => '封禁', 'unban' => '解封', 'alert' => '邮件警报'];
            echo '<table class="patrol-rows"><thead><tr><th>动作</th><th>对象</th><th>结果</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $a = (string)($r[0] ?? '');
                echo '<tr><td>' . e($actName[$a] ?? $a) . '</td><td>' . e((string)($r[1] ?? '')) . '</td><td>' .
                    '<span class="badge ' . (!empty($r[3]) ? 'badge-ok">已执行' : 'badge-warn">已拦截') . '</span> ' . e((string)($r[2] ?? '')) . '</td></tr>';
            }
            echo '</tbody></table>';
        } elseif (!empty($prep['ok'])) {
            echo '<p class="hint">本轮无需执行任何动作（AI 判定态势平稳）</p>';
        }
        echo '</div>';
    }
    echo '<p class="hint">AI 封禁的 IP 可在后台「防火墙」中随时手动解封；巡逻决策与动作全部留痕于系统日志（AI·自主巡逻 / AI·自主封禁 等）。</p></div>';

    /* ================= v1.21.0：常驻巡逻器（daemon.php）后台直达控制 ================= */
    $dAlive = ai_daemon_alive();
    $phpBin = (string)cfg('daemon_php_bin', '');
    if ($phpBin === '' && defined('PHP_BINARY') && PHP_BINARY !== '') {
        $phpBin = PHP_BINARY; // 当前 CLI/CGI 的 PHP 可执行文件路径作为合理默认
    }
    echo '<div class="card form-card"><h2 class="card-title">' . t('常驻巡逻器（daemon.php）') . '</h2>' .
        '<div class="ai-patrol-head">' .
        ($dAlive
            ? '<span class="badge badge-ok">🟢 在线</span><span class="hint">正在值守，每 60 秒写一次心跳</span>'
            : '<span class="badge">⚪ 不在线</span><span class="hint">论坛自动退回「访客触发」巡逻模式，功能完全一致</span>') .
        '</div>' .
        '<form method="post" action="' . e(u('a=admin_daemon_start')) . '" data-confirm="尝试在本机后台启动 daemon.php？需要主机允许 PHP 创建常驻进程（虚拟主机通常不允许）。">' . csrf_field() .
        '<label class="field"><span class="field-l">' . t('PHP 命令行路径') . '</span><input class="input" name="php_bin" maxlength="200" placeholder="如 /usr/bin/php 或 C:\\php\\php.exe" value="' . e($phpBin) . '">' .
        '<span class="hint">SSH 执行 which php 可查；保存后用于「一键启动」。主机不允许创建进程时，请手动执行：nohup php daemon.php &gt;/dev/null 2&gt;&amp;1 &amp;</span></label>' .
        '<div class="form-foot">' .
        '<button class="btn btn-primary" type="submit"' . ($dAlive ? ' disabled title="巡逻器已在线"' : '') . '>一键启动</button>' .
        '</div></form>' .
        '<form method="post" action="' . e(u('a=admin_daemon_stop')) . '" data-confirm="发送停止信号？巡逻器会在 30 秒内优雅退出（正在处理的任务不受影响）。">' . csrf_field() .
        '<div class="form-foot"><span></span><button class="btn btn-ghost" type="submit"' . (!$dAlive ? ' disabled title="巡逻器不在线"' : '') . '>停止巡逻器</button></div></form>' .
        admin_restore_btn('daemon_php_bin') .
        '<p class="hint">启动原理：后台以「nohup php daemon.php」在服务器上创建常驻进程；虚拟主机等无法常驻的环境会给出明确提示，不影响论坛运行。停止原理：写入 data/daemon.stop 信号文件，巡逻器 30 秒内检测到后优雅退出并自行删除信号。</p></div>';

    // 访问统计已随每次计数原子落盘（fw_state_save），直接读 fw_state.php 即为最新数据
    $on = (int)cfg('fw_on', 1) === 1;
    $st = Store::read('fw_state.php', []);
    if (!is_array($st)) {
        $st = [];
    }
    $days = is_array($st['days'] ?? null) ? $st['days'] : [];
    $today = $days[date('Y-m-d')] ?? ['req' => 0, 'blocked' => 0, 'bans' => 0];
    $yKey = date('Y-m-d', time() - 86400);
    $yday = $days[$yKey] ?? ['req' => 0, 'blocked' => 0, 'bans' => 0];
    $bans = function_exists('fw_bans_all') ? fw_bans_all() : [];
    $intel = function_exists('fw_intel_load') ? fw_intel_load() : ['synced' => 0, 'total' => 0, 'sources' => []];
    $rlOn = (int)cfg('fw_rl_on', 1) === 1;
    $scOn = (int)cfg('fw_score_on', 1) === 1;

    /* ---- 总览 ---- */
    $trendHtml = chart_trend_days(fw_daily_series($st, 14));
    $trendBlock = $trendHtml !== ''
        ? '<h3 class="chart-title">近 14 天请求 / 拦截趋势</h3>' . $trendHtml
        : '<p class="muted" style="margin:12px 0 0">暂无趋势数据（随访问自动聚合，最多保留 14 天）</p>';
    echo '<div class="card form-card"><h2 class="card-title">' . t('防护总览') . '</h2><div class="stat-row">' .
        '<div class="stat"><b>' . (int)($today['req'] ?? 0) . '</b><span>今日请求</span></div>' .
        '<div class="stat"><b style="color:var(--danger)">' . (int)($today['blocked'] ?? 0) . '</b><span>今日拦截</span></div>' .
        '<div class="stat"><b>' . (int)($today['bans'] ?? 0) . '</b><span>今日新增封禁</span></div>' .
        '<div class="stat"><b>' . (int)($yday['req'] ?? 0) . '</b><span>昨日请求</span></div>' .
        '<div class="stat"><b>' . count($bans) . '</b><span>封禁名单</span></div>' .
        '<div class="stat"><b>' . (int)$intel['total'] . '</b><span>危险库条数</span></div>' .
        '</div>' .
        '<div class="chart-block">' . $trendBlock . '</div>' .
        '<form method="post" action="' . e(u('a=admin_fw_on')) . '" class="inline-form">' . csrf_field() .
        '<label class="field" style="max-width:260px"><span class="field-l">' . t('防火墙总开关') . '</span><select name="fw_on" class="input">' .
        '<option value="1"' . ($on ? ' selected' : '') . '>' . t('开启（推荐）') . '</option>' .
        '<option value="0"' . (!$on ? ' selected' : '') . '>' . t('关闭（仅统计不拦截）') . '</option></select></label>' .
        '<button class="btn btn-primary" type="submit">' . t('保存总开关') . '</button></form>' .
        admin_restore_btn('fw_on') .
        '<p class="hint">此表单<b>只切换总开关</b>，不会改动下方「限流与自动策略 / 白名单 / 真实 IP 识别」等任何其他设置。五道防线依次执行：白名单 → 封禁名单 → 危险 IP 库 → 限流 → 自动策略。已登录的管理员不受拦截；危险 IP 库数据来自公开威胁情报源，误判时可把对方 IP 加入白名单。</p></div>';

    /* ---- 攻击告警（多次拦截 → 自动邮件通知管理员，v1.9.0） ---- */
    $atkOn = (int)cfg('fw_atk_alert_on', 1) === 1;
    $atkN = max(5, (int)cfg('fw_atk_alert_n', 20));
    $atkCool = max(5, (int)cfg('fw_atk_alert_cool', 30));
    $al = is_array($st['atk_alert'] ?? null) ? $st['atk_alert'] : [];
    if (!empty($al['last'])) {
        $atkLast = '上次触发：' . e(fmt_dt((int)$al['last'])) . '（' . (int)($al['peak'] ?? 0) . ' 次拦截）'
            . (isset($al['sent']) ? '，已发送 ' . (int)$al['sent'] . ' 位管理员'
                . ((string)($al['err'] ?? '') !== '' ? '；上次失败原因：' . e(cut_str((string)$al['err'], 80)) : '') : '') . '。';
    } else {
        $atkLast = '尚未触发过。';
    }
    echo '<div class="card form-card"><h2 class="card-title">' . t('攻击告警（自动邮件）') . '</h2>' .
        '<form method="post" action="' . e(u('a=admin_save_attack')) . '" data-ajax="1">' . csrf_field() .
        '<div class="grid2">' .
        '<label class="field"><span class="field-l">' . t('攻击告警邮件') . '</span><select name="fw_atk_alert_on" class="input">' .
        '<option value="1"' . ($atkOn ? ' selected' : '') . '>' . t('开启（推荐）') . '</option>' .
        '<option value="0"' . (!$atkOn ? ' selected' : '') . '>' . t('关闭') . '</option></select></label>' .
        '<label class="field"><span class="field-l">触发阈值（' . FW_ATK_WIN_MIN . ' 分钟内被拦截次数）</span><input class="input" type="number" name="fw_atk_alert_n" min="5" max="10000" value="' . $atkN . '"></label>' .
        '<label class="field"><span class="field-l">' . t('告警冷却（分钟）') . '</span><input class="input" type="number" name="fw_atk_alert_cool" min="5" max="1440" value="' . $atkCool . '"></label>' .
        '</div>' .
        '<div class="form-foot"><button class="btn btn-ghost" type="button" data-admin-test="attack">发送测试告警邮件</button>' .
        '<button class="btn btn-primary" type="submit">' . t('保存攻击告警设置') . '</button></div>' .
        '<span class="test-msg muted"></span></form>' .
        admin_restore_btn('fw_atk_alert_on,fw_atk_alert_n,fw_atk_alert_cool') .
        '<p class="hint">开启后，' . FW_ATK_WIN_MIN . ' 分钟窗口内防火墙拦截（限流 429 / 封禁名单 / 危险 IP 库 / 自动策略自动封禁均计入）累计达到阈值，即自动向全部管理员邮箱发送告警：含今日拦截与请求统计、事件最多的来源 IP Top5、最近事件摘要与处置建议；冷却期内不重复发送，实际发信在响应完成后进行（绝不拖慢站点）。' . $atkLast .
        '邮件经后台「邮件」页配置的 SMTP 发送，收件人为全部管理员账号的邮箱。</p></div>';

    /* ---- 防护设置（限流 / 策略 / 白名单 / 代理） ---- */
    echo '<div class="card form-card"><h2 class="card-title">' . t('限流与自动策略') . '</h2>' .
        '<form method="post" action="' . e(u('a=admin_fw_save')) . '" data-ajax="1">' . csrf_field() .
        '<input type="hidden" name="fw_on" value="' . ($on ? '1' : '0') . '">';
    echo '<div class="grid2">';
    echo '<label class="field"><span class="field-l">' . t('访问限流') . '</span><select name="fw_rl_on" class="input">' .
        '<option value="1"' . ($rlOn ? ' selected' : '') . '>' . t('开启') . '</option><option value="0"' . (!$rlOn ? ' selected' : '') . '>' . t('关闭') . '</option></select></label>';
    echo '<label class="field"><span class="field-l">' . t('每 IP 每分钟请求上限') . '</span><input class="input" type="number" name="fw_rl_pm" min="5" max="10000" value="' . (int)cfg('fw_rl_pm', 60) . '"><span class="hint">超限返回 429；登录管理员不受限</span></label>';
    $rlBan = (int)cfg('fw_rl_ban_min', 0);
    echo '<label class="field"><span class="field-l">' . t('超限自动临时封禁（分钟，0=只限流不封）') . '</span><input class="input" type="number" name="fw_rl_ban_min" min="0" max="1440" value="' . $rlBan . '"></label>';
    echo '<label class="field"><span class="field-l">' . t('自动策略引擎') . '</span><select name="fw_score_on" class="input">' .
        '<option value="1"' . ($scOn ? ' selected' : '') . '>' . t('开启') . '</option><option value="0"' . (!$scOn ? ' selected' : '') . '>' . t('关闭') . '</option></select></label>';
    echo '<label class="field"><span class="field-l">' . t('自动封禁风险阈值') . '</span><input class="input" type="number" name="fw_score_threshold" min="20" max="10000" value="' . (int)cfg('fw_score_threshold', 100) . '"><span class="hint">10 分钟窗口内风险分累计达到阈值即自动封禁</span></label>';
    echo '<label class="field"><span class="field-l">' . t('自动封禁时长（小时）') . '</span><input class="input" type="number" name="fw_auto_ban_hours" min="1" max="720" value="' . (int)cfg('fw_auto_ban_hours', 24) . '"></label>';
    echo '</div>';
    echo '<div class="field"><span class="field-l">' . t('内置策略规则（可单独开关）') . '</span><div class="check-grid">';
    $rules = [
        'fw_r_empty_ua'  => ['空 UA 请求（+40 分）', 1],
        'fw_r_script_ua' => ['脚本 / 攻击工具 UA（+50 分）', 1],
        'fw_r_scan_path' => ['敏感路径探测 wp-admin/.env 等（+80 分）', 1],
        'fw_r_inject'    => ['SQL 注入 / XSS / 目录穿越特征（+90 分）', 1],
        'fw_r_fake_spider' => ['伪造搜索引擎蜘蛛：自称 Googlebot / Baiduspider 等但 PTR 反解验证不通过（+80 分）', 1],
        'fw_r_bot_ua'      => ['未知爬虫 UA：自称 bot / spider / 采集器但非搜索引擎，如 SEO 采集器（+25 分）', 1],
        'fw_r_probe'       => ['敏感文件探测：install.php / 源码目录 / 数据目录 / 配置与打包文件（+50 分）', 1],
        'fw_r_spoof_cf'    => ['伪造 CF-Connecting-IP 头（带该头但连接并非来自 Cloudflare，+50 分）', 1],
        'fw_r_long_req'    => ['超长请求：URL / UA 异常过长，多为扫描器特征（+30 分）', 1],
        'fw_spider_allow' => ['放行搜索引擎蜘蛛 UA（不误伤收录；真蜘蛛 IP 另有 PTR 验证，伪造必被识破）', 1],
    ];
    foreach ($rules as $k => $v) {
        $val = (int)cfg($k, $v[1]) === 1;
        echo '<label class="check"><input type="checkbox" name="' . $k . '" value="1"' . ($val ? ' checked' : '') . '> ' . e($v[0]) . '</label>';
    }
    echo '</div></div>';
    echo '<label class="field"><span class="field-l">' . t('IP 白名单（逗号或换行分隔，支持网段 1.2.3.0/24；命中后跳过一切拦截）') . '</span>' .
        '<textarea class="input" name="fw_whitelist" rows="2" placeholder="如：203.0.113.7, 198.51.100.0/24">' . e((string)cfg('fw_whitelist', '')) . '</textarea>' .
        '<span class="hint">你当前的 IP：<b>' . e(fw_ip()) . '</b>（建议加入白名单，避免自己被误拦）</span></label>';

    /* ---- 真实 IP 识别（CDN / 反代适配，v1.8.1） ---- */
    $link = fw_link_info();
    $modeMap = [
        'cloudflare' => ['Cloudflare CDN', 'badge-ok'],
        'cf-proxy'   => ['Cloudflare → 本机反代', 'badge-ok'],
        'xff'        => ['经代理（XFF）', 'badge-warn'],
        'direct'     => ['直连', 'badge-ok'],
    ];
    [$modeName, $modeBadge] = $modeMap[$link['mode']] ?? [$link['mode'], ''];
    echo '<div class="field"><span class="field-l">' . t('真实 IP 识别（当前请求链路诊断）') . '</span>' .
        '<div class="admin-list" style="margin:6px 0 4px">' .
        '<div class="admin-row"><div class="u-info"><b>' . e($modeName) . '</b>' .
        '<span class="muted">REMOTE_ADDR（TCP 对端）=' . e($link['remote'] !== '' ? $link['remote'] : '—') .
        ($link['cfip'] !== '' ? '；CF-Connecting-IP=' . e($link['cfip']) : '') .
        ($link['xff'] !== '' ? '；X-Forwarded-For=' . e(cut_str($link['xff'], 80)) : '') . '</span></div>' .
        '<span class="badge ' . $modeBadge . '">识别 IP：' . e($link['resolved'] !== '' ? $link['resolved'] : '未知') . '</span></div>' .
        '</div></div>';
    echo '<label class="check"><input type="checkbox" name="fw_trust_cf" value="1"' . ((int)cfg('fw_trust_cf', 1) === 1 ? ' checked' : '') . '>' . t(' Cloudflare CDN 适配（推荐开启：自动识别 Cloudflare 官方网段，从 CF-Connecting-IP 取真实访客 IP）') . '</label>' .
        '<span class="hint">开启后，仅当请求的 TCP 对端确属 Cloudflare 官方网段（v4 + IPv6 共 22 条，内置）才信任 CF-Connecting-IP 头，直连伪造无效——限流、封禁、访问统计、IP 归属地全部基于真实 IP，避免「同节点访客被集体限流 / 自动封禁误封 Cloudflare 节点导致整站 403」。未使用 Cloudflare 时此开关无任何影响。</span>' .
        '<label class="check" style="margin-top:8px"><input type="checkbox" name="fw_trust_xff" value="1"' . ((int)cfg('fw_trust_xff', 0) === 1 ? ' checked' : '') . '>' . t(' 站点在反向代理 / 其他 CDN 之后（信任 X-Forwarded-For）') . '</label>' .
        '<span class="hint">宝塔反代、其他不带真实 IP 头的 CDN 等场景开启（从 X-Forwarded-For 首段取 IP）；套在 Cloudflare 之前时会自动优先用更可信的 CF-Connecting-IP。直接暴露的服务器请勿开启，否则可被伪造头绕过限流。</span>' .
        '<label class="field" style="margin-top:8px"><span class="field-l">' . t('Cloudflare 网段覆盖（可选，留空 = 使用程序内置 CF 官方网段）') . '</span>' .
        '<textarea class="input" name="fw_cf_ranges" rows="2" placeholder="如 Cloudflare 官方调整网段，可在此填入：104.16.0.0/13, 2606:4700::/32">' . e((string)cfg('fw_cf_ranges', '')) . '</textarea>' .
        '<span class="hint">逗号 / 空白分隔，支持 IPv4 与 IPv6 CIDR；仅当 CF 官方发布新网段而程序未跟进时才需要填写。</span></label>';
    echo '<details style="margin-top:6px"><summary class="muted">Cloudflare 接入推荐配置（点开查看）</summary>' .
        '<div class="hint" style="margin-top:8px;line-height:1.9">' .
        '① <b>SSL/TLS 模式</b>：Cloudflare 面板设为「完全（严格）」，源站配有效证书，全链路 HTTPS；<br>' .
        '② <b>缓存</b>：论坛页面带登录态，请勿添加「缓存 HTML」的规则（默认只缓存 css/js/图片，保持默认即可）；<br>' .
        '③ <b>Rocket Loader / Email 混淆</b>：建议关闭（可能干扰发帖表单与邮件显示）；<br>' .
        '④ <b>速率限制 / WAF</b>：可按需在 CF 面板加一层，但建议阈值放宽，程序内置防火墙已按真实 IP 限流；<br>' .
        '⑤ <b>「我正在被攻击」模式</b>：开启后人机验证可能拦截发帖与登录，仅在遭遇攻击时短期使用；<br>' .
        '⑥ 本程序无需任何 CF 侧配置即可取到真实 IP——装上本版本即自动生效，后台上方诊断卡可随时核验。</div></details>';
    echo '<div class="form-foot"><span></span><button class="btn btn-primary" type="submit">' . t('保存防护设置') . '</button></div></form>' .
        admin_restore_btn('fw_rl_on,fw_rl_pm,fw_rl_ban_min,fw_score_on,fw_score_threshold,fw_auto_ban_hours,fw_r_empty_ua,fw_r_script_ua,fw_r_scan_path,fw_r_inject,fw_r_fake_spider,fw_r_bot_ua,fw_r_probe,fw_r_spoof_cf,fw_r_long_req,fw_spider_allow,fw_whitelist,fw_trust_cf,fw_trust_xff,fw_cf_ranges') . '</div>';

    /* ---- 危险 IP 库 ---- */
    $synced = (int)$intel['synced'];
    $stale = $synced > 0 && $synced < time() - max(1, (int)cfg('fw_intel_hours', 24)) * 3600;
    echo '<div class="card form-card"><h2 class="card-title">' . t('危险 IP 库（威胁情报）') . '</h2>' .
        '<div class="mon-grid">' .
        '<div class="mon-cell' . ((int)$intel['total'] > 0 ? '' : ' cell-bad') . '"><b>' . (int)$intel['total'] . '</b><span>已入库条数</span></div>' .
        '<div class="mon-cell"><b>' . ($synced > 0 ? e(fmt_dt($synced)) : '未同步') . '</b><span>上次同步' . ($stale ? '（已过期，建议同步）' : '') . '</span></div>' .
        '<div class="mon-cell"><b>每 ' . (int)cfg('fw_intel_hours', 24) . ' 小时</b><span>自动同步间隔（打开本页时惰性触发）</span></div>' .
        '</div>';
    // 各源状态
    $sources = is_array($intel['sources'] ?? null) ? $intel['sources'] : [];
    if ($sources) {
        echo '<div class="admin-list">';
        foreach ($sources as $s) {
            $ok = !empty($s['ok']) && (int)($s['count'] ?? 0) >= 0 && (string)($s['err'] ?? '') === '';
            echo '<div class="admin-row"><div class="u-info"><b>' . e((string)($s['name'] ?? '源')) . '</b>' .
                '<span class="muted">' . (!empty($s['ok']) ? ((string)($s['err'] ?? '') !== '' ? e($s['err']) : '成功 · ' . (int)($s['count'] ?? 0) . ' 条') : '失败：' . e((string)($s['err'] ?? '未知'))) . '</span></div>' .
                '<span class="badge' . (!empty($s['ok']) ? ' badge-ok' : ' badge-warn') . '">' . (!empty($s['ok']) ? '正常' : '异常') . '</span></div>';
        }
        echo '</div>';
    }
    echo '<form method="post" action="' . e(u('a=admin_fw_intel_save')) . '" data-ajax="1">' . csrf_field() . '<div class="grid2">';
    echo '<label class="field"><span class="field-l">' . t('危险 IP 库拦截') . '</span><select name="fw_intel_on" class="input">' .
        '<option value="1"' . ((int)cfg('fw_intel_on', 1) === 1 ? ' selected' : '') . '>' . t('开启') . '</option><option value="0"' . ((int)cfg('fw_intel_on', 1) !== 1 ? ' selected' : '') . '>' . t('关闭') . '</option></select></label>';
    echo '<label class="field"><span class="field-l">' . t('自动同步间隔（小时）') . '</span><input class="input" type="number" name="fw_intel_hours" min="1" max="168" value="' . (int)cfg('fw_intel_hours', 24) . '"></label>';
    foreach (['et' => 1, 'feodo' => 1, 'blackbook' => 0] as $sk => $def) {
        echo '<label class="check"><input type="checkbox" name="fw_src_' . $sk . '" value="1"' . ((int)cfg('fw_src_' . $sk, $def) === 1 ? ' checked' : '') . '> ' . e(FW_INTEL_SOURCES[$sk]['name']) . '</label>';
    }
    echo '</div>' .
        '<label class="field"><span class="field-l">' . t('自定义威胁情报源（每行一个 URL，最多 5 条，返回 IP/CIDR 纯文本列表）') . '</span>' .
        '<textarea class="input" name="fw_intel_custom" rows="2" placeholder="https://...">' . e((string)cfg('fw_intel_custom', '')) . '</textarea></label>' .
        '<div class="form-foot"><span></span><button class="btn btn-primary" type="submit">' . t('保存库设置') . '</button></div></form>' .
        admin_restore_btn('fw_intel_on,fw_intel_hours,fw_intel_custom,fw_src_et,fw_src_feodo,fw_src_blackbook');
    echo '<form method="post" action="' . e(u('a=admin_fw_intel_sync')) . '" class="inline" data-confirm="立即从公开威胁情报源同步危险 IP 库？">' . csrf_field() .
        '<button class="btn btn-ghost" type="submit">' . t('立即同步') . '</button></form> ';
    echo '<details style="margin-top:10px"><summary class="muted">手动导入黑名单（粘贴或上传 txt）</summary>' .
        '<form method="post" action="' . e(u('a=admin_fw_intel_import')) . '" enctype="multipart/form-data" style="margin-top:8px">' . csrf_field() .
        '<label class="field"><span class="field-l">' . t('黑名单文本（每行一个 IP / CIDR / IP 区间，# 开头为注释，最多 5 万行）') . '</span>' .
        '<textarea class="input" name="list" rows="4" placeholder="1.2.3.4&#10;5.6.7.0/24&#10;8.8.8.8-8.8.8.16"></textarea></label>' .
        '<label class="field"><span class="field-l">' . t('或上传 .txt 文件（≤ 2MB）') . '</span><input class="input" type="file" name="file" accept=".txt,.csv,text/plain"></label>' .
        '<div class="form-foot"><span class="muted">导入与现有库合并，不清空</span><button class="btn btn-ghost" type="submit">' . t('导入') . '</button></div></form></details>';
    echo '<p class="hint">内置种子：库为空时会启用少量公开的常见恶意网段种子兜底；完整库依赖同步（主机需能访问外网，cURL 或 file_get_contents 任一可用即可）。</p></div>';

    /* ---- 访问统计 ---- */
    $ips = is_array($st['ips'] ?? null) ? $st['ips'] : [];
    $sort = (string)($_GET['ssort'] ?? 'time') === 'req' ? 'req' : 'time';
    uasort($ips, function ($a, $b) use ($sort) {
        if ($sort === 'req') {
            return (int)($b['c'] ?? 0) <=> (int)($a['c'] ?? 0) ?: (int)($b['l'] ?? 0) <=> (int)($a['l'] ?? 0);
        }
        return (int)($b['l'] ?? 0) <=> (int)($a['l'] ?? 0) ?: (int)($b['c'] ?? 0) <=> (int)($a['c'] ?? 0);
    });
    $ipPage = max(1, get_int('ippage', 1));
    $ipPer = 50;
    $ipTotal = count($ips);
    $ipRows = array_slice($ips, ($ipPage - 1) * $ipPer, $ipPer, true);

    /* 图表：请求最多的 IP / 归属地分布（v1.11.0，纯读缓存不发网络请求） */
    $ipTop = [];
    $ipTmp = $ips;
    uasort($ipTmp, function ($a, $b) {
        return (int)($b['c'] ?? 0) <=> (int)($a['c'] ?? 0);
    });
    $n = 0;
    foreach ($ipTmp as $ipC => $rC) {
        if ($n++ >= 10) {
            break;
        }
        $ipTop[(string)$ipC] = (int)($rC['c'] ?? 0);
    }
    unset($ipTmp);
    $geoTop = array_slice(fw_geo_distribution($ips), 0, 8, true);

    /* v1.17.0 新增图表：客户端分布（解析 UA）与 IP 状态分布（正常 / 封禁 / 白名单） */
    $uaTop = [];
    $stTop = ['正常' => 0, '封禁中' => 0, '白名单' => 0];
    foreach ($ips as $ipS => $rS) {
        $uaTop[fw_ua_family((string)($rS['ua'] ?? ''))] =
            ($uaTop[fw_ua_family((string)($rS['ua'] ?? ''))] ?? 0) + (int)($rS['c'] ?? 0);
        $bS = fw_is_banned((string)$ipS);
        $wlS = !empty($rS['wl']) || fw_whitelisted((string)$ipS);
        if ($bS) {
            $stTop['封禁中']++;
        } elseif ($wlS) {
            $stTop['白名单']++;
        } else {
            $stTop['正常']++;
        }
    }
    arsort($uaTop);
    $uaTop = array_slice($uaTop, 0, 8, true);
    $stTop = array_filter($stTop);

    echo '<div class="card form-card wide-card"><div class="list-head"><h2 class="card-title">' . t('访问统计（每个 IP 的请求次数与归属地）') . '</h2>' .
        '<span class="muted">共 ' . $ipTotal . ' 个活跃 IP · ' .
        '<a class="fw-link' . ($sort === 'time' ? ' on' : '') . '" href="' . e(u('p=admin&tab=security&ssort=time')) . '">最近活跃</a> · ' .
        '<a class="fw-link' . ($sort === 'req' ? ' on' : '') . '" href="' . e(u('p=admin&tab=security&ssort=req')) . '">请求最多</a></span></div>';
    if ($ipTop || $geoTop || $uaTop || $stTop) {
        echo '<div class="chart-grid2">';
        echo '<div><h3 class="chart-title">请求最多的 IP' . (count($ipTop) >= 10 ? ' Top 10' : '') . '</h3>' .
            ($ipTop
                ? chart_bars_h($ipTop, ['label' => '请求最多的 IP 排行', 'tip' => function ($l, $v) { return $l . '：共 ' . $v . ' 次请求'; }])
                : empty_state('暂无数据')) . '</div>';
        echo '<div><h3 class="chart-title">归属地请求分布</h3>' .
            ($geoTop
                ? chart_bars_h($geoTop, ['label' => '各归属地请求次数分布', 'tip' => function ($l, $v) { return $l . '：' . $v . ' 次请求'; }])
                : empty_state('归属地尚未查询：点下方「查询本页归属地」后自动生成分布图')) . '</div>';
        echo '<div><h3 class="chart-title">客户端分布（按请求次数）</h3>' .
            ($uaTop
                ? chart_bars_h($uaTop, ['label' => '各浏览器与客户端的请求次数分布', 'tip' => function ($l, $v) { return $l . '：' . $v . ' 次请求'; }])
                : empty_state('暂无数据')) . '</div>';
        echo '<div><h3 class="chart-title">IP 状态分布（按数量）</h3>' .
            ($stTop
                ? chart_bars_h($stTop, ['label' => '正常 / 封禁中 / 白名单 IP 数量分布', 'tip' => function ($l, $v) { return $l . '：' . $v . ' 个 IP'; }])
                : empty_state('暂无数据')) . '</div>';
        echo '</div>';
    }

    /* v1.17.0：单列长表改为响应式多列卡片网格——一屏可见十几个 IP，不再需要无尽下滚 */
    echo '<div class="ip-grid">';
    if (!$ipRows) {
        echo empty_state('暂无访问数据（随访问自动统计）');
    }
    foreach ($ipRows as $ip => $r) {
        $banned = fw_is_banned((string)$ip);
        $wl = !empty($r['wl']) || fw_whitelisted((string)$ip);
        $geo = fw_geo_get((string)$ip);
        $state = $banned ? '<span class="badge badge-warn">封禁中</span>'
            : ($wl ? '<span class="badge badge-accent">白名单</span>'
                : '<span class="badge badge-ok">正常</span>');
        $banLeft = $banned ? ((int)($banned['until'] ?? 0) > 0 ? '至 ' . date('m-d H:i', (int)$banned['until']) : '永久') : '';
        $uaTxt = trim((string)($r['ua'] ?? ''));
        echo '<div class="ip-card' . ($banned ? ' is-banned' : '') . '">' .
            '<div class="ip-card-top"><b class="ip-card-ip">' . e((string)$ip) . '</b>' . $state . '</div>' .
            '<div class="ip-card-nums">请求 <b>' . (int)($r['c'] ?? 0) . '</b>' .
            ' · 404 <b' . ((int)($r['f'] ?? 0) > 0 ? ' class="ip-404"' : '') . '>' . (int)($r['f'] ?? 0) . '</b>' .
            ' · ' . ((int)($r['l'] ?? 0) > 0 ? e(fmt_time((int)$r['l'])) : '无活动') .
            ($banned ? ' · <span class="muted">' . e($banLeft) . '</span>' : '') . '</div>' .
            '<div class="ip-card-ua" title="' . e($uaTxt) . '">' . ($uaTxt !== '' ? e(cut_str($uaTxt, 60)) : 'UA 未知') . '</div>' .
            '<div class="ip-card-geo muted" data-fw-geo-for="' . e((string)$ip) . '">' . ($geo ? e(fw_geo_label($geo)) : '归属地未查询') . '</div>' .
            '<div class="ip-card-ops">';
        if ($banned) {
            echo '<form method="post" action="' . e(u('a=admin_fw_unban')) . '" class="inline">' . csrf_field() .
                '<input type="hidden" name="ip" value="' . e((string)$ip) . '">' .
                '<button class="btn btn-ghost btn-sm" type="submit">' . t('解封') . '</button></form>';
        } else {
            echo '<form method="post" action="' . e(u('a=admin_fw_ban')) . '" class="inline" data-confirm="封禁 IP ' . e((string)$ip) . ' 24 小时？">' . csrf_field() .
                '<input type="hidden" name="ip" value="' . e((string)$ip) . '"><input type="hidden" name="dur" value="1440"><input type="hidden" name="reason" value="后台手动封禁">' .
                '<button class="btn btn-ghost btn-sm danger" type="submit">' . t('封禁24h') . '</button></form>';
        }
        echo '</div></div>';
    }
    echo '</div>';
    echo '<button class="btn btn-ghost btn-sm" type="button" data-fw-geo>查询本页归属地（免key接口，未查询到的才会请求）</button> ';
    echo '<span class="muted" id="fw-geo-msg"></span>';
    echo paginate($ipTotal, $ipPer, $ipPage, 'p=admin&tab=security&ssort=' . $sort, 'ippage');
    echo '<p class="hint">统计随访问自动聚合（每 60 秒落盘一次）；7 天不活跃的 IP 自动移除统计。归属地通过 ip-api.com 免费接口批量查询并缓存 30 天（主源连不上时自动改用备用源，查不到的 IP 稍后重试，不会留下错误的「未知」记录）。</p></div>';

    /* ---- 封禁管理 ---- */
    echo '<div class="card form-card"><h2 class="card-title">' . t('手动封禁') . '</h2>' .
        '<form method="post" action="' . e(u('a=admin_fw_ban')) . '" class="inline-form">' . csrf_field() .
        '<label class="field"><span class="field-l">' . t('IP 或网段') . '</span><input class="input" name="ip" required placeholder="如 1.2.3.4 或 5.6.7.0/24" style="max-width:220px"></label>' .
        '<label class="field"><span class="field-l">' . t('时长') . '</span><select name="dur" class="input" style="max-width:150px">';
    foreach ([30 => '30 分钟', 60 => '1 小时', 360 => '6 小时', 1440 => '24 小时', 10080 => '7 天', 43200 => '30 天', 0 => '永久'] as $m => $lbl) {
        echo '<option value="' . $m . '"' . ($m === 1440 ? ' selected' : '') . '>' . e($lbl) . '</option>';
    }
    echo '</select></label>' .
        '<label class="field"><span class="field-l">' . t('理由（记录用）') . '</span><input class="input" name="reason" maxlength="100" placeholder="如：恶意刷帖" style="max-width:260px"></label>' .
        '<button class="btn btn-danger" type="submit">' . t('立即封禁') . '</button></form>';
    echo '<div class="admin-list" style="margin-top:10px">';
    if (!$bans) {
        echo empty_state('当前没有封禁记录');
    }
    foreach ($bans as $key => $b) {
        $until = (int)($b['until'] ?? 0);
        $expired = $until > 0 && $until < time();
        echo '<div class="admin-row"><div class="u-info"><b>' . e((string)$key) . '</b>' .
            '<span class="muted">' . e((string)($b['kind'] ?? 'manual') === 'auto' ? '自动' : '手动') . ' · ' . e((string)($b['by'] ?? '')) . ' · ' . e(fmt_dt((int)($b['time'] ?? 0))) . ' · ' . e((string)($b['reason'] ?? '')) . '</span></div>' .
            '<span class="row-ops">' .
            ($expired ? '<span class="badge">已过期</span>' : '<span class="badge badge-warn">' . ($until > 0 ? e(fmt_dt($until)) . ' 解除' : '永久') . '</span>') .
            '<form method="post" action="' . e(u('a=admin_fw_unban')) . '" class="inline">' . csrf_field() .
            '<input type="hidden" name="ip" value="' . e((string)$key) . '">' .
            '<button class="btn btn-ghost btn-sm" type="submit">' . t('移除') . '</button></form></span></div>';
    }
    echo '</div></div>';

    /* ---- 自定义规则 ---- */
    $rulesAll = fw_rules_all();
    echo '<div class="card form-card"><h2 class="card-title">' . t('自定义封禁规则（内置策略机器人之外的补充）') . '</h2>' .
        '<form method="post" action="' . e(u('a=admin_fw_rule_add')) . '" class="inline-form">' . csrf_field() .
        '<label class="field"><span class="field-l">' . t('规则名') . '</span><input class="input" name="name" required maxlength="30" placeholder="如：拦截恶意爬虫" style="max-width:180px"></label>' .
        '<label class="field"><span class="field-l">' . t('匹配对象') . '</span><select name="type" class="input" style="max-width:130px"><option value="ua">User-Agent</option><option value="uri">' . t('请求路径') . '</option><option value="query">' . t('查询参数') . '</option></select></label>' .
        '<label class="field"><span class="field-l">' . t('匹配方式') . '</span><select name="mode" class="input" style="max-width:120px"><option value="text">' . t('包含文本') . '</option><option value="regex">' . t('正则') . '</option></select></label>' .
        '<label class="field"><span class="field-l">' . t('匹配内容') . '</span><input class="input" name="pattern" required maxlength="120" placeholder="如 scrapy 或 (bot|spider)" style="max-width:220px"></label>' .
        '<label class="field"><span class="field-l">' . t('命中动作') . '</span><select name="action" class="input" style="max-width:150px"><option value="score">' . t('加分（计入风险分）') . '</option><option value="ban">' . t('直接封禁') . '</option></select></label>' .
        '<label class="field"><span class="field-l">' . t('分值 / 封禁时长(h)') . '</span><input class="input" type="number" name="val" min="1" max="720" value="50" style="max-width:110px"></label>' .
        '<button class="btn btn-primary" type="submit">' . t('添加规则') . '</button></form>';
    echo '<div class="admin-list" style="margin-top:10px">';
    if (!$rulesAll) {
        echo empty_state('还没有自定义规则（内置四条策略已默认启用，见上方「限流与自动策略」）');
    }
    foreach ($rulesAll as $r) {
        echo '<div class="admin-row"><div class="u-info"><b>' . e((string)($r['name'] ?? '')) . '</b>' .
            '<span class="muted">' . e(strtoupper((string)($r['type'] ?? ''))) . ' · ' . (($r['mode'] ?? '') === 'regex' ? '正则' : '包含') . '「' . e((string)($r['pattern'] ?? '')) . '」 · ' .
            (($r['action'] ?? '') === 'ban' ? '直接封禁 ' . (int)($r['ban_hours'] ?? 24) . 'h' : '+' . (int)($r['score'] ?? 0) . ' 分') . '</span></div>' .
            '<span class="row-ops">' .
            '<form method="post" action="' . e(u('a=admin_fw_rule_toggle')) . '" class="inline">' . csrf_field() .
            '<input type="hidden" name="id" value="' . e((string)($r['id'] ?? '')) . '">' .
            '<button class="btn btn-ghost btn-sm" type="submit">' . (!empty($r['on']) ? '停用' : '启用') . '</button></form>' .
            '<form method="post" action="' . e(u('a=admin_fw_rule_del')) . '" class="inline" data-confirm="删除该规则？">' . csrf_field() .
            '<input type="hidden" name="id" value="' . e((string)($r['id'] ?? '')) . '">' .
            '<button class="btn btn-ghost btn-sm danger" type="submit">' . t('删除') . '</button></form></span></div>';
    }
    echo '</div></div>';

    /* ---- 防火墙事件日志 ---- */
    $files = fw_event_files();
    $date = (string)($_GET['fwdate'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = $files ? substr($files[0], 3, 10) : date('Y-m-d');
    }
    $qip = trim((string)($_GET['fwq'] ?? ''));
    $fwPage = max(1, get_int('fwpage', 1));
    $fwTotal = 0;
    $rows = fw_events_read($date, 50, $fwPage, $qip, $fwTotal);
    echo '<div class="card form-card"><div class="list-head"><h2 class="card-title">' . t('防火墙日志（拦截 / 封禁 / 评分事件）') . '</h2>' .
        '<span class="muted">每天至多 2000 条，保留 ' . (int)cfg('fw_log_keep', 14) . ' 天</span></div>' .
        '<form method="get" action="' . e(u('')) . '" class="inline-form">' .
        '<input type="hidden" name="p" value="admin"><input type="hidden" name="tab" value="security">' .
        '<select name="fwdate" class="input input-sm">';
    $opts = [$date => true, date('Y-m-d') => true];
    foreach ($files as $f) {
        $opts[substr($f, 3, 10)] = true;
    }
    foreach (array_keys($opts) as $d) {
        echo '<option value="' . e($d) . '"' . ($d === $date ? ' selected' : '') . '>' . e($d) . '</option>';
    }
    echo '</select>' .
        '<input class="input input-sm" name="fwq" value="' . e($qip) . '" placeholder="按 IP 筛选">' .
        '<button class="btn btn-ghost btn-sm" type="submit">' . t('筛选') . '</button> ' .
        '<a class="btn btn-ghost btn-sm" href="' . e(u('p=admin&tab=security')) . '">重置</a></form>';
    echo '<form method="post" action="' . e(u('a=admin_fw_log_clear')) . '" class="inline" data-confirm="清空 ' . e($date) . ' 的防火墙日志？">' . csrf_field() .
        '<input type="hidden" name="date" value="' . e($date) . '">' .
        '<button class="btn btn-ghost btn-sm danger" type="submit">' . t('清空该日日志') . '</button></form>';
    $actNames = [
        'block' => '拦截·封禁名单', 'intel_block' => '拦截·危险IP库', 'ratelimit' => '限流·429',
        'score' => '策略·风险分', 'auto_ban' => '策略·自动封禁',
    ];
    $fwActStats = fw_act_stats($date);
    if ($fwActStats) {
        $fwActItems = [];
        foreach ($fwActStats as $a => $nn) {
            $fwActItems[$actNames[$a] ?? $a] = (int)$nn;
        }
        echo '<h3 class="chart-title" style="margin-top:16px">事件类型分布（' . e($date) . '）</h3>';
        echo chart_bars_h($fwActItems, ['label' => '防火墙事件类型分布', 'color' => 'warn', 'tip' => function ($l, $v) { return $l . '：' . $v . ' 次'; }]);
    }
    echo '<div class="table-wrap" style="margin-top:14px"><table class="log-table"><thead><tr><th>时间</th><th>IP</th><th>事件</th><th>规则 / 原因</th><th>请求</th><th>UA</th></tr></thead><tbody>';
    if (!$rows) {
        echo '<tr><td colspan="6">' . empty_state('该日期暂无防火墙事件') . '</td></tr>';
    }
    foreach ($rows as $r) {
        $act = (string)($r['act'] ?? '');
        $cls = $act === 'auto_ban' ? ' badge-warn' : ($act === 'score' ? ' badge-accent' : '');
        echo '<tr>' .
            '<td class="nowrap">' . date('H:i:s', (int)($r['t'] ?? 0)) . '</td>' .
            '<td class="nowrap"><b>' . e((string)($r['ip'] ?? '')) . '</b></td>' .
            '<td class="nowrap"><span class="badge' . $cls . '">' . e($actNames[$act] ?? $act) . '</span></td>' .
            '<td class="log-detail">' . e((string)($r['rule'] ?? '')) . (isset($r['detail']) && $r['detail'] !== '' ? '<br><span class="muted">' . e((string)$r['detail']) . '</span>' : '') .
                ((int)($r['score'] ?? 0) > 0 ? ' <span class="muted">+' . (int)$r['score'] . '</span>' : '') . '</td>' .
            '<td class="log-detail">' . e((string)($r['m'] ?? '')) . ' ' . e((string)($r['uri'] ?? '')) . '</td>' .
            '<td class="log-detail muted">' . e((string)($r['ua'] ?? '')) . '</td>' .
            '</tr>';
    }
    echo '</tbody></table></div>';
    echo paginate($fwTotal, 50, $fwPage, 'p=admin&tab=security&fwdate=' . urlencode($date) . '&fwq=' . urlencode($qip), 'fwpage');
    echo '</div>';
}

/* ================= v1.22.0 后台设置搜索 ================= */

/* ================= v1.25.0 配置默认值注册表（「还原默认」功能的数据源） ================= */

/**
 * 全部可还原的配置项注册表：key => [默认值, 标签]
 * 仅收「设置类」配置；引擎选择（storage_engine）、版本号（version）、插件状态（plugins_enabled）、
 * 协议内容（doc_u_*）、端点开关（api_ep_*）等有专门管理入口或属数据态的键不在还原范围。
 */
function admin_cfg_defaults(): array
{
    return [
        /* 基本设置 */
        'site_name'      => ['极简论坛', '论坛名称'],
        'site_desc'      => ['', '论坛简介'],
        'site_url'       => ['', '站点地址'],
        'bind_domains'   => ['', '绑定域名'],
        'per_page'       => [20, '每页帖子数'],
        'post_interval'  => [30, '发帖间隔（秒）'],
        'online_window'  => [300, '在线统计窗口（秒）'],
        'live_interval'  => [20, '实时刷新间隔（秒）'],
        'ping_interval'  => [15, 'Ping 测量间隔（秒）'],
        /* 内容与限制（v1.32.0） */
        'thread_title_max' => [30, '帖子标题最大字数'],
        'thread_len_max'   => [1500, '帖子正文最大字数'],
        'reply_len_max'    => [1000, '回复最大字数'],
        'name_max'         => [20, '用户昵称最大字数'],
        'sign_len_max'     => [60, '签名最大字数'],
        'upload_img_max_mb'  => [5, '图片大小上限（MB）'],
        'upload_file_max_mb' => [10, '附件大小上限（MB）'],
        'upload_per_post'    => [4, '单帖上传文件数上限'],
        'upload_allow_ext'   => ['zip pdf txt md csv doc docx xls xlsx ppt pptx', '允许的附件扩展名'],
        /* 大文件传输（v1.47.0） */
        'upload_chunk_mb'  => [4, '分块大小（MB）'],
        'upload_conc'      => [2, '上传并发块数'],
        'dl_threads'       => [3, '下载线程数'],
        'dl_max_mbps'      => [0, '最大带宽（Mbps，0 不限）'],
        /* SEO */
        'seo_title_tpl'    => ['{page} · {site}', 'SEO 标题模板'],
        'seo_description'  => ['', 'SEO 站点描述'],
        'seo_keywords'     => ['', 'SEO 关键词'],
        'seo_robots'       => ['', '搜索引擎收录'],
        'seo_sitemap'      => [0, 'Sitemap 站点地图'],
        'seo_sitemap_limit'=> [500, 'Sitemap 收录上限'],
        'seo_og'           => [1, 'Open Graph 标签'],
        'seo_extra_head'   => ['', '自定义 head 内容'],
        /* v1.36.0 HTML 静态加速 */
        'static_mode' => ['php', 'HTML 静态加速模式（php=动态优先 / html=静态优先）'],
        'static_ttl'  => [21600, '静态快照保鲜期（秒）'],
        /* 语言 */
        'lang_default' => ['zh-cn', '默认论坛语言'],
        'lang_auto_ip' => [0, '按 IP 自动切换语言'],
        /* 功能总开关（默认全开） */
        'feat_register' => [1, '开放注册'], 'feat_guest_browse' => [1, '游客可浏览'],
        'feat_post' => [1, '发帖'], 'feat_reply' => [1, '回复'],
        'feat_edit' => [1, '内容编辑'], 'feat_del_own' => [1, '删除自己的内容'],
        'feat_like' => [1, '点赞'], 'feat_report' => [1, '举报'],
        'feat_search' => [1, '搜索'], 'feat_search_suggest' => [1, '搜索预览'], 'feat_emoji' => [1, '表情'],
        'feat_upload' => [1, '允许上传图片 / 附件'],
        'feat_online' => [1, '在线名单'], 'feat_signature' => [1, '用户签名'],
        'feat_mention' => [1, '@ 提及通知'], 'feat_motion' => [1, '界面动效'],
        'feat_titles' => [1, '会员头衔'],
        'feat_qr_login' => [1, '扫码登录'], 'qr_ttl' => [180, '扫码登录有效期（秒）'],
        /* 邮件 */
        'smtp_host' => ['', 'SMTP 主机'], 'smtp_port' => [465, 'SMTP 端口'],
        'smtp_from' => ['', '发信邮箱'], 'smtp_pass' => ['', 'SMTP 授权码'],
        /* AI（模型列表是数据不是配置键，不参与还原） */
        'ai_strict' => ['standard', 'AI 审核严格程度'],
        'ai_mode' => ['', 'AI 审核模式'], 'ai_retries' => [2, 'AI 单模型重试次数'],
        'ai_timeout' => [20, 'AI 超时（秒）'], 'ai_precheck' => [1, 'AI 审查'],
        'ai_autopilot' => [0, 'AI 自主管理'], 'ai_patrol_interval' => [15, 'AI 巡逻间隔（分钟）'],
        'ai_patrol_ban_limit' => [3, 'AI 单轮封禁上限'], 'ai_patrol_alert' => [1, 'AI 巡逻告警邮件'],
        'ai_fail_limit' => [3, 'AI 失败重试上限'], 'ai_fullpower' => [0, 'AI 严全面模式'],
        /* 主题 */
        'theme_color' => ['#0f766e', '主题色'], 'dark_default' => ['system', '深色模式默认值'],
        'custom_css' => ['', '自定义样式 CSS'], 'footer_text' => ['', '页脚文字'], 'footer_note' => ['', '页脚备注'],
        /* 后台导航布局（v1.29.0） */
        'admin_nav_layout' => ['side', '后台导航布局'], 'admin_nav_cols' => [1, '后台导航列数'],
        /* 日志 */
        'log_views' => [0, '记录页面访问'], 'log_days' => [90, '日志保留天数'],
        /* 安全防护 */
        'fw_on' => [1, '防火墙总开关'],
        'dev_verify' => [0, '新设备二次验证总闸'], 'sess_max' => [10, '同时在线设备上限'],
        'fw_rl_on' => [1, '访问限流'], 'fw_rl_pm' => [60, '每 IP 每分钟请求上限'],
        'fw_rl_ban_min' => [0, '超限自动临时封禁（分钟）'],
        'fw_score_on' => [1, '自动策略引擎'], 'fw_score_threshold' => [100, '自动封禁风险阈值'],
        'fw_auto_ban_hours' => [24, '自动封禁时长（小时）'], 'fw_log_keep' => [14, '防火墙日志保留天数'],
        'fw_atk_alert_on' => [1, '攻击告警邮件'], 'fw_atk_alert_n' => [20, '告警触发阈值'],
        'fw_atk_alert_cool' => [30, '告警冷却（分钟）'],
        'fw_trust_xff' => [0, '信任 X-Forwarded-For'], 'fw_trust_cf' => [1, 'Cloudflare 网段识别'],
        'fw_cf_ranges' => ['', 'Cloudflare 网段覆盖'], 'fw_spider_allow' => [1, '常见搜索引擎放行'],
        'fw_whitelist' => ['', 'IP 白名单'],
        'fw_intel_on' => [1, '危险 IP 库拦截'], 'fw_intel_hours' => [24, '情报自动同步间隔（小时）'],
        'fw_intel_custom' => ['', '自定义威胁情报源'],
        'fw_src_et' => [1, '情报源：Emerging Threats'], 'fw_src_feodo' => [1, '情报源：Feodo Tracker'],
        'fw_src_blackbook' => [0, '情报源：Blackbook'],
        /* 内置策略规则（默认全部开启） */
        'fw_r_empty_ua' => [1, '空 UA 请求'], 'fw_r_script_ua' => [1, '脚本 / 攻击工具 UA'],
        'fw_r_scan_path' => [1, '敏感路径探测'], 'fw_r_inject' => [1, '注入 / XSS 特征'],
        'fw_r_fake_spider' => [1, '伪造搜索引擎蜘蛛'], 'fw_r_bot_ua' => [1, '未知爬虫 UA'],
        'fw_r_probe' => [1, '敏感文件探测'], 'fw_r_spoof_cf' => [1, '伪造 CF 头'],
        'fw_r_long_req' => [1, '超长请求'],
        'daemon_php_bin' => ['', '巡逻器 PHP 路径'],
        /* 监控 */
        'monitor_on' => [1, '存储占用监控'], 'monitor_interval' => [5, '监控刷新间隔（秒）'],
        'monitor_mb' => [95, '占用告警阈值（MB）'], 'quota_mb' => [100, '主机配额（MB）'],
        /* 开放 API */
        'api_enabled' => [0, 'API 总开关'], 'api_guest' => [0, '访客 API'],
        'api_rate_guest' => [30, '访客限速（次 / 分钟）'], 'api_rate_token' => [120, '令牌限速（次 / 分钟）'],
        'api_cors' => ['', 'API 跨域来源'], 'api_log' => [0, 'API 调用日志'],
        /* 存储连接参数（不含 storage_engine：引擎切换是显式迁移操作，不参与一键还原） */
        'db_driver' => ['sqlite', '数据库类型'], 'db_prefix' => ['mf_', '表前缀'],
        'db_sqlite_path' => ['', 'SQLite 数据库文件'], 'db_host' => ['', '数据库主机'],
        'db_port' => ['', '数据库端口'], 'db_name' => ['', '数据库名'],
        'db_user' => ['', '数据库用户名'], 'db_pass' => ['', '数据库密码'],
    ];
}

/** 「还原默认」按钮（放在卡片 </form> 之后；提交到统一还原动作，后端按注册表白名单校验） */
function admin_restore_btn(string $csv): string
{
    return '<div class="restore-row"><form method="post" action="' . e(u('a=admin_restore_defaults')) . '" class="inline" data-ajax="1" data-confirm="' . e(t('确认把这组设置全部还原为默认值？当前填写的内容将被覆盖')) . '">' . csrf_field() .
        '<input type="hidden" name="keys" value="' . e($csv) . '">' .
        '<button class="btn btn-ghost btn-sm" type="submit">' . t('↺ 还原默认') . '</button>' .
        '<span class="test-msg muted"></span></form></div>';
}

/**
 * 设置项全局索引（与各标签页卡片同步维护；card 供前端按前缀匹配定位卡片，动态尾部（N）不写入）。
 * 每项：[tab, card 卡片标题前缀, label 设置项名, kw 补充关键词（含英文/别名）]
 */
function admin_search_index(): array
{
    $i = [
        /* 基本 */
        ['basic', '基本设置', '论坛名称', 'site name 站名 网站名'],
        ['basic', '基本设置', '论坛简介', 'site desc 描述 简介'],
        ['basic', '基本设置', '站点地址（可选）', 'site url 网址 域名'],
        ['basic', '基本设置', '绑定域名（授权域名，可选）', 'bind domains 防镜像 恶意解析 301'],
        ['basic', '基本设置', '每页帖子数', 'per page 分页 每页条数'],
        ['basic', '基本设置', '发帖间隔（秒）', 'post interval 发帖频率 防灌水'],
        ['basic', '基本设置', '在线统计窗口（秒）', 'online window 在线人数'],
        ['basic', '基本设置', '实时刷新间隔（秒）', 'live interval 自动刷新 轮询'],
        ['basic', '基本设置', 'Ping 测量间隔（秒）', 'ping 延迟 心跳'],
        ['basic', '内容与限制', '标题最大字数', '标题长度 字数限制 title max'],
        ['basic', '内容与限制', '正文最大字数', '正文长度 内容限制 字数 content'],
        ['basic', '内容与限制', '回复最大字数', '回复长度 字数 reply'],
        ['basic', '内容与限制', '昵称最大字数', '用户名长度 昵称 name'],
        ['basic', '内容与限制', '签名最大字数', '签名长度 signature'],
        ['basic', '内容与限制', '允许上传图片 / 附件（总开关在功能页）', 'upload 上传 图片 附件 传文件'],
        ['basic', '内容与限制', '图片大小上限（MB）', '图片大小 上传限制 image size'],
        ['basic', '内容与限制', '附件大小上限（MB）', '附件大小 上传限制 file size'],
        ['basic', '内容与限制', '允许的附件扩展名', '附件类型 扩展名 白名单 ext'],
        ['basic', '内容与限制', '单帖上传文件数上限', '附件数量 单帖上限'],
        ['basic', '还原默认', '各设置卡片下方的「↺ 还原默认」按钮，把该组设置恢复为程序默认值', 'restore default 还原默认 恢复默认 复位 还原此组默认'],
        ['basic', 'SEO · 搜索引擎展示', '标题模板', 'seo title {page} {site}'],
        ['basic', 'SEO · 搜索引擎展示', '站点描述（搜索结果摘要）', 'seo description 摘要 meta'],
        ['basic', 'SEO · 搜索引擎展示', '关键词', 'seo keywords'],
        ['basic', 'SEO · 搜索引擎展示', '搜索引擎收录', 'robots noindex nofollow 收录'],
        ['basic', 'SEO · 搜索引擎展示', 'Sitemap 站点地图', 'sitemap xml 站点地图'],
        ['basic', 'SEO · 搜索引擎展示', 'Sitemap 收录上限（条）', 'sitemap limit'],
        ['basic', 'SEO · 搜索引擎展示', 'Open Graph 社交分享标签', 'og 分享 微信'],
        ['basic', 'SEO · 搜索引擎展示', '自定义 <head> 内容（可选）', 'extra head 验证 统计代码'],
        ['basic', 'SEO · 搜索引擎展示', 'robots.txt 一键同步', 'robots 同步 爬虫 备份 覆盖 sync'],
        ['basic', 'SEO · 搜索引擎展示', '站点根目录不可写降级：下载 robots.txt 后 FTP 上传', 'robots 下载 ftp 不可写 降级 手动 上传'],
        ['basic', '界面语言', '默认论坛语言', 'language 语言 简中 繁中 英 日 俄'],
        ['basic', '界面语言', '按 IP 自动切换语言', 'language auto geo 自动语言'],
        /* 功能 */
        ['feat', '功能总开关', '开放注册', 'register 注册开关'],
        ['feat', '功能总开关', '游客可浏览', 'guest browse 私密模式 登录可见'],
        ['feat', '功能总开关', '发帖 / 回复开关', 'post reply 发帖 回复'],
        ['feat', '功能总开关', '内容编辑', 'edit 编辑'],
        ['feat', '功能总开关', '点赞 / 举报 / 搜索 / 表情', 'like report search emoji'],
        ['feat', '功能总开关', '搜索预览', 'search suggest 预览 联想 下拉 搜索框'],
        ['feat', '功能总开关', '在线名单 / 用户签名 / @ 提及通知', 'online signature mention 提及'],
        ['feat', '功能总开关', '界面动效', 'motion 动画 过渡 进度条 预取 流畅'],
        ['feat', '功能总开关', 'AI 审查（发帖 / 编辑 / 用户名）', 'ai precheck 审核 预检 编辑审查 用户名审查'],
        ['feat', '功能总开关', 'AI 自主管理', 'ai autopilot 巡逻'],
        ['feat', '功能总开关', '扫码登录（二维码）', 'qr login 扫码 二维码 qrcode 登录码 手机扫码'],
        ['feat', '扫码登录 · 参数', '二维码有效期（秒）', 'qr ttl 有效期 过期 扫码 二维码'],
        /* 存储 */
        ['storage', '存储引擎', '当前存储引擎', 'storage engine 文件 数据库 切换'],
        ['storage', '数据库连接', '数据库类型', 'sqlite mysql postgres pg 驱动'],
        ['storage', '数据库连接', 'SQLite 数据库文件', 'sqlite 路径 文件'],
        ['storage', '数据库连接', '主机 / 端口（MySQL / PG）', 'host port 数据库'],
        ['storage', '数据库连接', '数据库名 / 用户名 / 密码', 'database user pass'],
        ['storage', '数据库连接', '表前缀', 'table prefix'],
        ['storage', '数据库连接', '测试连接', 'test connection 连接测试'],
        ['storage', '迁移', '文件 ⇄ 数据库 无损迁移', 'migrate 迁移 备份 双向'],
        ['storage', '数据库管理', '数据库备份 / 优化 / 完整性检查', 'backup optimize vacuum integrity'],
        /* 邮件 */
        ['mail', '邮件发送设置', 'SMTP 主机 / 端口', 'smtp host port 邮件服务器'],
        ['mail', '邮件发送设置', '发信邮箱 / SMTP 用户名', 'from 邮箱 账号'],
        ['mail', '邮件发送设置', 'SMTP 授权码 / 密码', 'smtp pass 授权码'],
        ['mail', '邮件发送设置', '加密方式（SSL / TLS）', 'smtp secure ssl tls 465 587'],
        ['mail', '邮件发送设置', '测试收件邮箱', 'test mail 发信测试'],
        /* AI */
        ['ai', '审核模型', 'API 地址（OpenAI 兼容）', 'api url base openai'],
        ['ai', '审核模型', 'API 密钥', 'api key 密钥'],
        ['ai', '审核模型', '模型名称', 'model 模型'],
        ['ai', '审核模型', '审核严格程度', 'strict 严格'],
        ['ai', '审核模型', '审核模式（火力）', '火力 双模型 竞速 并行'],
        ['ai', '审核模型', '单模型重试次数 / 超时', 'retry timeout'],
        ['ai', 'AI 自主防护（严全面模式）', '自动封禁风险阈值', 'auto ban score 阈值'],
        ['ai', 'AI 自主防护（严全面模式）', '巡逻间隔（分钟）', 'patrol interval'],
        ['ai', 'AI 自主防护（严全面模式）', '单轮封禁上限（个）', 'ban limit'],
        ['ai', 'AI 待审队列', 'AI 队列处理', 'queue 队列'],
        /* 板块 */
        ['boards', '新建板块', '板块名称 / 简介', 'board new 创建板块'],
        ['boards', '板块列表', '板块排序 / 编辑 / 删除', 'board sort move'],
        ['boards', '板块发帖权限', '所有人 / 仅职员 / 白名单用户', 'board perm 发帖权限 白名单 受邀 准入 职员'],
        /* 用户 */
        ['users', '用户', '用户搜索 / 封禁 / 禁言 / 角色指派（普通用户 / 审核员 / 超级管理员，仅超级管理员）', 'user search ban admin role 审核员 moderator 角色 坛主'],
        /* 功能（v1.27.0 开关索引） */
        ['feat', '删除自己的内容', '允许用户删除自己的帖子 / 回复', 'del_own delete 删除'],
        ['feat', '会员头衔', '按发帖数自动晋升的展示性称号（新成员 / 正式成员 / 活跃成员 / 资深成员 / 社区元老）', 'title 头衔 称号 成员'],
        /* 内容 */
        ['content', '帖子', '帖子搜索 / 置顶 / 锁定 / 删除', 'thread pin lock delete'],
        ['content', '举报记录', '举报处理', 'report'],
        ['content', '人工待审', '待审内容处理', 'manual review'],
        ['content', 'AI 待审队列', 'AI 审核队列', 'ai queue'],
        /* 公告 */
        ['anns', '公告', '发布公告 / 编辑公告', 'announcement 公告'],
        /* 主题 */
        ['theme', '颜色与页脚', '主题色', 'theme color 颜色'],
        ['theme', '颜色与页脚', '深色模式默认值', 'dark mode 深色'],
        ['theme', '颜色与页脚', '自定义样式（CSS）', 'custom css 样式'],
        ['theme', '颜色与页脚', '页脚文字 / 页脚备注', 'footer 文案'],
        ['theme', '后台导航布局', '布局方式', 'nav layout 侧边 平铺 sidebar flat 导航'],
        ['theme', '后台导航布局', '侧边导航列数', 'columns 多列 列数 两列 三列'],
        ['theme', '网站图标（favicon）', '上传新图标', 'favicon icon 图标'],
        /* 拓展 */
        ['ext', '拓展（插件）', '启用 / 停用 / 删除 / 安装插件', 'plugin 插件 拓展 扩展 enable disable install'],
        ['ext', '拓展（插件）', '开发者指南', 'developer api 钩子 hook 开发'],
        /* 协议 */
        ['docs', '协议管理', '用户协议 / 隐私政策 / 免责声明', 'doc 协议 privacy'],
        ['docs', '协议模板预填项', '站名 / 站长邮箱 / 更新日期（自动替换模板占位符）', 'doc 预填 模板 站名 邮箱 更新日期 自动替换 doc-prefill'],
        ['docs', '「同意协议」是如何记录的', '协议门禁与同意留痕', 'gate 门禁 记录'],
        /* 开放 API */
        ['api', '开放 API', 'API 总开关 / 令牌签发', 'api token enable'],
        ['api', '开放 API', '访客限速 / 令牌限速', 'rate limit 限速'],
        ['api', '开放 API', '跨域来源（CORS，可选）', 'cors 跨域'],
        ['api', '已签发的令牌', '令牌管理 / 吊销', 'token revoke'],
        ['api', '客户端接入示例', '接入文档', 'docs 接入'],
        /* 日志 */
        ['logs', '日志设置', '记录页面访问（含游客）', 'log views 访问日志'],
        ['logs', '日志设置', '日志保留天数', 'log keep 保留'],
        ['logs', '查询日志', '系统日志查询', 'log query 查询'],
        ['logs', '数据占用压缩', '日志与备份压缩', 'compress 压缩 gzip'],
        /* 安全防护 */
        ['security', '防护总览', '防火墙总开关 / 今日拦截', 'firewall on 拦截'],
        ['security', '账号安全 · 登录设备管理', '新设备二次验证总闸', 'device verify 二次验证 登录设备'],
        ['security', '账号安全 · 登录设备管理', '同一账号同时在线设备上限（台）', 'session max 同时登录 上限'],
        ['security', '限流与自动策略', '访问限流', 'rate limit 限流'],
        ['security', '限流与自动策略', '每 IP 每分钟请求上限', 'per ip limit'],
        ['security', '限流与自动策略', '超限自动临时封禁（分钟）', 'auto ban 临时封禁'],
        ['security', '限流与自动策略', '自动策略引擎 / 内置策略规则', 'score auto policy 评分'],
        ['security', '限流与自动策略', '自动封禁时长 / 风险阈值', 'auto ban hours'],
        ['security', '手动封禁', 'IP 或网段封禁', 'ban ip cidr 手动封禁'],
        ['security', 'IP 白名单', '白名单（跳过拦截）', 'whitelist 白名单'],
        ['security', '危险 IP 库（威胁情报）', '危险 IP 库拦截', 'threat intel 威胁情报 黑名单'],
        ['security', '危险 IP 库（威胁情报）', '自定义威胁情报源', 'intel url 情报源'],
        ['security', '危险 IP 库（威胁情报）', '自动同步间隔 / 黑名单文本', 'sync interval blacklist'],
        ['security', '自定义封禁规则', '规则名 / 匹配方式 / 匹配内容', 'custom rule 自定义规则 正则'],
        ['security', '自定义封禁规则', '匹配对象 / 命中动作 / 分值', 'rule target action score'],
        ['security', '攻击告警（自动邮件）', '攻击告警邮件', 'attack alert 攻击告警'],
        ['security', '攻击告警（自动邮件）', '触发阈值（10 分钟内被拦截次数）', 'alert threshold 阈值'],
        ['security', '攻击告警（自动邮件）', '告警冷却（分钟）', 'alert cool 冷却'],
        ['security', '常驻巡逻器（daemon.php）', '巡逻器启动 / 停止 / 心跳', 'daemon 巡逻器 后台进程'],
        ['security', '常驻巡逻器（daemon.php）', 'PHP 命令行路径', 'php path cli'],
        ['security', '真实 IP 识别', 'Cloudflare 网段覆盖', 'cf ip range 真实ip'],
        ['security', '访问统计', 'IP 请求次数与归属地', 'access stats 统计 归属地'],
        ['security', '防火墙日志', '拦截 / 封禁 / 评分事件', 'fw log 事件'],
        /* 监控 */
        ['monitor', '监控与告警设置', '存储占用告警', 'monitor disk alert 占用'],
        ['monitor', '监控与告警设置', '告警阈值（MB）/ 自动告警', 'threshold 告警'],
        ['monitor', '监控与告警设置', '监控页实时刷新间隔', 'monitor interval'],
        ['monitor', '环境自检', '目录权限 / 一键修复', 'env check 修复 权限'],
        ['monitor', '运行环境', 'PHP 扩展一键开启（mbstring / openssl / curl / gd / zip / opcache），自动区分 Windows / Linux', '扩展 php.ini 小皮 phpstudy 宝塔 mbstring openssl curl gd zip opcache 一键 开启 windows linux 运行环境'],
        ['monitor', '运行环境', 'php.ini 格式体检：自动发现并修复「php_ 前缀缺后缀」等写法错误、无效扩展行与 PHP 8 已移除指令（如 track_errors）', 'php.ini 格式 体检 坏行 修正 注释 track_errors 无效 修复 php_ 前缀'],
        ['monitor', '运行环境', '上传限制体检：upload_max_filesize / post_max_size 过小一键调大（迁移包 / 更新包上传需要）', '上传 限制 upload post 2m 迁移包 更新包 调大 32m 64m'],
        ['monitor', '运行环境', '上传链路自检：功能开关 / file_uploads / 单文件与整请求上限 / 上传临时目录 / data/uploads 存储目录 / 扩展名白名单逐项真实探测，无法上传一键定位', '上传 自检 无法上传 上传失败 定位 file_uploads 临时目录 存储目录 权限 白名单 探针'],
        ['monitor', '运行环境', '小皮面板 / 宝塔面板自动识别与重启指引；php.ini 不可写时下载配置片段 FTP 上传', '小皮 面板 phpstudy 宝塔 xampp wamp upupw 指引 片段 下载 ftp 重启'],
        ['monitor', '性能体检', 'OPcache / .user.ini / gzip / ETag 304', 'opcache 性能 缓存 慢 加速'],
        ['monitor', '性能体检', '追加 OPcache 配置到现有 .user.ini（同意式，原内容保留并自动备份）', 'opcache 追加 user.ini 同意 备份'],
        ['monitor', '性能体检', '站点根目录不可写降级：下载 .user.ini 后 FTP 上传', 'user.ini 下载 ftp 不可写 降级 手动 上传 opcache'],
        ['monitor', 'HTML 静态加速', 'PHP 动态优先 / HTML 静态优先一键切换，游客快照直出', 'html 静态 快照 加速 缓存 游客 直出 切换 模式'],
        ['monitor', 'HTML 静态加速', '立即预生成全站静态页 / 清空快照 / 保鲜期', '预生成 生成 静态 清空 快照 保鲜'],
        ['monitor', 'HTML 静态加速', '.htaccess 加速块：同意式安装 / 重新写入 / 移除 / 不可写时下载合并文件', 'htaccess apache 重写 直出 安装 移除 下载 ftp 加速块'],
        ['monitor', '存储占用', '存储空间统计', 'disk usage'],
        ['monitor', '实时资源', 'CPU / 内存 / 负载', 'cpu memory load 资源'],
        ['monitor', '服务器信息', 'PHP / 系统版本', 'server php info'],
        /* 更新升级 */
        ['update', '当前版本', '版本号 / 检查更新', 'version 检查更新'],
        ['update', '上传更新包', '更新包（.zip）', 'update zip 升级包'],
        ['update', '更新历史', '历史版本记录', 'update history'],
        ['update', '数据备份', '手动备份 / 自动备份', 'backup 备份'],
        ['update', '回撤更新', '回滚到更新前的版本（升级出错时一键还原程序文件）', 'rollback 回滚 回撤 恢复 还原 更新出问题 降级'],
        /* 系统 */
        ['system', '系统信息', '程序版本 / 数据统计', 'system info 系统'],
        ['system', '注销账号管理', '注销请求', 'delete account 注销'],
        /* v1.37.0 迁移搬家 */
        ['system', '迁移搬家', '制作迁移包：整站内容（帖子 / 用户 / 设置 / 附件）打包下载，用于搬迁服务器', 'migrate 迁移 搬家 迁移包 打包 导出 备份 新服务器'],
        ['system', '导入迁移包', '已装站点上传迁移包覆盖恢复；大包可 FTP 上传到 data/backup 后选择导入', '导入 恢复 覆盖 迁移包 上传 ftp'],
        ['system', '从迁移包恢复安装', '新服务器安装向导内选择迁移包，跳过 6 步向导一键就位', '安装 恢复 迁移包 搬迁 部署 就位'],
    ];
    return $i;
}

/* ================= v1.22.0 拓展（插件）管理页 ================= */

function admin_tab_ext(): void
{
    $disc = plugins_discover();
    $en = plugins_enabled_ids();
    $routes = $GLOBALS['MF_PLUGIN_ROUTES'] ?? [];
    echo '<div class="card form-card"><h2 class="card-title">' . t('拓展（插件）') . '</h2>' .
        '<p class="hint">插件存放于 <code>data/plugins/&lt;插件id&gt;/</code>（该目录拒绝 Web 直访，数据库模式下也始终留在文件系统）。安装 = 上传 zip 包（含 plugin.json 清单）；启用后<b>下一个请求起生效</b>，停用立即失效；加载失败的插件会跳过并写入错误日志，绝不影响论坛运行。<b>插件代码拥有与论坛程序同级的全部权限，请只安装可信来源的插件。</b></p>';
    if (!$disc) {
        echo '<p class="muted">尚未安装任何插件。把开发者提供的插件 zip 包在下方上传，或通过 FTP 将插件目录放入 data/plugins/ 后刷新本页。</p>';
    }
    foreach ($disc as $id => $p) {
        $on = in_array($id, $en, true);
        echo '<div class="plugin-row' . ($on ? ' plugin-on' : '') . '">';
        echo '<div class="plugin-info"><b>' . e($p['name']) . '</b>' .
            ($p['version'] !== '' ? ' <span class="muted">v' . e($p['version']) . '</span>' : '') .
            ($p['author'] !== '' ? ' <span class="muted">· ' . e($p['author']) . '</span>' : '') .
            '<br><span class="muted">' . ($p['desc'] !== '' ? e(cut_str($p['desc'], 160)) : e($id)) . '</span>' .
            '<br><span class="muted" style="font-size:12px">id：' . e($id) . ' · data/plugins/' . e($id) . '/' . ($p['ok'] ? '' : ' · <b class="badge badge-warn">' . e($p['err']) . '</b>') . '</span></div>';
        echo '<div class="plugin-ops">';
        if ($p['ok']) {
            echo '<span class="badge ' . ($on ? 'badge-ok' : '') . '">' . e(t($on ? '已启用' : '已停用')) . '</span> ';
            if ($on && isset($routes[$id])) {
                echo '<a class="btn btn-ghost" href="' . e(u('p=plugin&pf=' . $id)) . '">' . e(t('前台入口')) . '</a> ';
            }
            echo '<form class="inline" method="post" action="' . e(u('a=admin_plugin_toggle')) . '" data-confirm="' . e(t($on ? '停用该插件？' : '启用该插件？')) . '">' . csrf_field() .
                '<input type="hidden" name="pf" value="' . e($id) . '"><button class="btn" type="submit">' . e(t($on ? '停用' : '启用')) . '</button></form> ';
            echo '<form class="inline" method="post" action="' . e(u('a=admin_plugin_remove')) . '" data-confirm="' . e(t('彻底删除该插件及其全部文件？此操作不可恢复！')) . '">' . csrf_field() .
                '<input type="hidden" name="pf" value="' . e($id) . '"><button class="btn btn-danger" type="submit">' . e(t('删除')) . '</button></form>';
        } else {
            echo '<form class="inline" method="post" action="' . e(u('a=admin_plugin_remove')) . '" data-confirm="' . e(t('删除该损坏的插件目录？')) . '">' . csrf_field() .
                '<input type="hidden" name="pf" value="' . e($id) . '"><button class="btn btn-danger" type="submit">' . e(t('删除')) . '</button></form>';
        }
        echo '</div></div>';
    }
    echo '</div>';

    echo '<div class="card form-card"><h2 class="card-title">' . t('安装插件') . '</h2>' .
        '<form method="post" action="' . e(u('a=admin_plugin_install')) . '" enctype="multipart/form-data" data-confirm="' . e(t('确认安装该插件包？插件代码将以论坛程序同级权限运行，请确保来源可信。')) . '">' . csrf_field() .
        '<label class="field"><span class="field-l">' . e(t('插件包（.zip，内含 plugin.json）')) . '</span><input class="input" type="file" name="pfzip" accept=".zip" required></label>' .
        '<div class="form-foot"><span class="muted">' . e(t('限 8MB / 300 个文件；安装后需在上方列表点击「启用」')) . '</span><button class="btn btn-primary" type="submit">' . e(t('上传并安装')) . '</button></div></form></div>';

    $ex = <<<'PHPEX'
<?php
/** 插件入口：data/plugins/hello/plugin.php */
$cfg = mf_plugin_cfg('hello');            // 读插件配置（data/plugins.cfg.php）
mf_plugin_cfg_set('hello', ['views' => (int)($cfg['views'] ?? 0)]);

mf_add_action('page_footer', function () { // 每页页脚注入一行小字
    echo '<div style="text-align:center;color:#999;font-size:12px">Powered by hello 插件</div>';
}, 20);

mf_add_filter('site_title', function ($title, $page) { // 改写每页 <title>
    return $title;
}, 10);

mf_add_action('post_created', function (array $thread) {   // 发帖事件
    mf_log('hello', '新帖子：' . ($thread['title'] ?? ''));
}, 10);

mf_register_route('hello', function (string $id) {         // 独立页面 p=plugin&pf=hello
    layout_header('Hello 插件');
    echo page_head('Hello 插件', '第一个插件示例');
    echo '<div class="card"><h2 class="card-title">It works!</h2><p>这是由插件渲染的页面。</p></div>';
    layout_footer();
});
PHPEX;
    echo '<div class="card form-card"><h2 class="card-title">' . t('开发者指南') . '</h2>' .
        '<p class="hint">一个插件 = data/plugins/ 下的一个目录，最少两个文件：<code>plugin.json</code>（清单）与入口 PHP（默认 <code>plugin.php</code>）。入口文件在论坛完全初始化后执行（配置 / 会话 / 数据库 / 多语言全部就绪），可直接使用全部论坛函数。</p>' .
        '<p><b>plugin.json 清单：</b></p><pre class="code-hint">{
  "name": "示例插件",
  "version": "1.0.0",
  "author": "作者名",
  "desc": "一句话说明插件做什么",
  "entry": "plugin.php",
  "id": "hello"
}</pre>' .
        '<p><b>可用 API：</b></p><pre class="code-hint">mf_add_action(钩子名, 回调, 优先级=10)      // 订阅动作钩子
mf_add_filter(过滤器名, 回调, 优先级=10)    // 订阅过滤器（返回值向后传递）
mf_do_action(钩子名, ...参数)               // 触发动作（内核埋点已调用）
mf_apply_filters(过滤器名, 值, ...参数)     // 应用过滤器
mf_register_route(插件id, 回调)             // 注册前台独立页面 p=plugin&pf=<id>
mf_plugin_cfg(插件id, 键=null, 默认=null)   // 读插件配置
mf_plugin_cfg_set(插件id, [键=>值])         // 写插件配置
mf_log(插件id, 消息)                        // 写系统日志（后台日志页可见）</pre>' .
        '<p><b>动作钩子：</b><code>plugins_loaded</code> 插件全部加载完 · <code>page_head</code> 前台 &lt;head&gt; 内 · <code>page_footer</code> 前台 &lt;/body&gt; 前 · <code>admin_head</code> 后台 &lt;head&gt; 区 · <code>user_register(uid, 昵称)</code> 注册成功 · <code>user_login(uid, 昵称)</code> 登录成功 · <code>post_created(帖子数组)</code> 发帖成功 · <code>reply_created(帖子id, 回复id, 作者id)</code> 回复成功 · <code>thread_view(帖子数组)</code> 帖子页渲染</p>' .
        '<p><b>过滤器：</b><code>site_title(标题, 页面名)</code> 每页 &lt;title&gt; · <code>md_html(渲染后HTML, 原文)</code> 全站 Markdown 渲染结果</p>' .
        '<p><b>静态资源：</b>插件目录下的文件不对外直访（data/ 受保护），放 <code>assets/</code> 里通过 <code>index.php?p=plugin&amp;pf=&lt;id&gt;&amp;file=style.css</code> 受控出站（扩展名白名单）。</p>' .
        '<p><b>完整示例（hello）：</b></p><pre class="code-hint">' . e($ex) . '</pre></div>';
}
