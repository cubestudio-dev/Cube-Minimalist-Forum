#!/usr/bin/env bash
# =====================================================================
# 极简论坛 · 错误页接线一键开关（中性版）  mf-errpage.sh
# ---------------------------------------------------------------------
# 目标：把程序自带 error/ 六张零依赖静态品牌错误页（403/404/413/500/502/503）
#       接到 Web 服务器上，或一键切回服务器默认错误页。
#       不预设部署形态：物理机 / VPS / 新服务器 / 容器 / 面板 / 内网穿透全兼容。
#
# 各部署形态的行为：
#   · nginx（任何来源：发行版包、源码编译、OpenResty、面板托管）
#       → 自动定位配置目录并接线 error_page（本脚本的主路径）
#   · Apache / LiteSpeed 等读 .htaccess 的服务器
#       → 程序包内 .htaccess 的 ErrorDocument 已自动接管，本脚本告知后退出
#   · PHP 内置服务器（php -S）
#       → 无反代层可接线；站内品牌页（404/403/500）在 PHP 存活时始终生效，
#         status 会说明这一边界
#   · 内网穿透（frp / Cloudflare Tunnel / ngrok / natapp 等）
#       → 源站反代若为 nginx 照常接线；隧道/CDN 自身宕机时的错误页由
#         服务商在其边缘生成，属于网络架构边界，任何源站方案都无法覆盖，
#         status 会如实列出检测到的隧道层
#
# 用法（on/off 需要对配置目录的写权限，通常即 root/sudo）：
#   bash mf-errpage.sh on                 # 自动探测服务器、配置目录与论坛站点并启用
#   bash mf-errpage.sh on  <conf路径>      # 指定站点 conf 启用
#   bash mf-errpage.sh on  <conf路径> /bbs # 论坛装在子目录时加 URL 前缀
#   bash mf-errpage.sh off                # 一键切回服务器默认错误页
#   bash mf-errpage.sh status             # 环境体检 + 当前状态（只读，免权限）
#
# 安全机制：
#   · 只插入一行带 mf-errpage-hook 标记的 include，其余配置分毫不动；
#     off 只删带该标记的行，逐字节还原
#   · 改动前自动备份到配置目录旁 mf-errpage-backups/（不会被 nginx 加载、
#     不会被探测误判）
#   · nginx -t 校验不过 → 自动回滚全部改动，站点保持原样
#   · 幂等可重复执行；重复 on 可刷新子目录前缀
#   · 413 特性：nginx 对超大请求体可能先断开读端再回页，个别浏览器表现为
#     连接重置——这是 nginx 行为，其余五页无此问题
#
# 可用环境变量（可选，普通用户无需关心）：
#   MF_NGX_CONF_DIR  强制指定 nginx 配置目录（面板/容器/非常规布局时用）
#   MF_NGX_BIN       强制指定 nginx 可执行文件路径
#   MF_SKIP_RELOAD   1=只改文件不重载（调试用）
# =====================================================================

NGX_BIN="${MF_NGX_BIN:-nginx}"
NGX_DIR=""      # 解析出的配置目录
MAINCONF=""     # 主配置文件（供 reload -c 兜底）
SNIP=""         # snippet 文件路径
BDIR=""         # 备份目录
BAKS=""         # 本次运行的备份清单

say() { printf '%s\n' "$*"; }
ok()  { printf '✔ %s\n' "$*"; }
bad() { printf '✘ %s\n' "$*" >&2; }
die() { bad "$*"; exit 1; }

have() { command -v "$1" >/dev/null 2>&1; }
have_nginx() {
  case "$NGX_BIN" in
    */*) [ -x "$NGX_BIN" ] ;;
    *)  have "$NGX_BIN" ;;
  esac
}

# 二进制不在 PATH 时的兜底扫描（源码编译 / 面板安装的常见位置）
detect_nginx_bin() {
  have_nginx && return 0
  local d
  for d in /usr/sbin/nginx /usr/local/nginx/sbin/nginx \
           /usr/local/openresty/nginx/sbin/nginx /www/server/nginx/sbin/nginx \
           /opt/homebrew/bin/nginx /usr/local/bin/openresty; do
    if [ -x "$d" ]; then NGX_BIN="$d"; return 0; fi
  done
  return 1
}

# ---------------- 环境探测 ----------------

# 依次尝试：环境变量 → nginx -t 输出 → nginx -V 编译参数 → 常见路径扫描
detect_conf_dir() {
  if [ -n "${MF_NGX_CONF_DIR:-}" ]; then
    [ -f "$MF_NGX_CONF_DIR/nginx.conf" ] && {
      NGX_DIR="$MF_NGX_CONF_DIR"; MAINCONF="$MF_NGX_CONF_DIR/nginx.conf"; return 0; }
    NGX_DIR="$MF_NGX_CONF_DIR"; return 0   # 允许非常规布局（主配置不在 nginx.conf）
  fi
  have_nginx || return 1
  local p
  p=$("$NGX_BIN" -t 2>&1 | grep -m1 -oE 'configuration file +[^ ]+' | awk '{print $3}')
  [ -n "$p" ] || p=$("$NGX_BIN" -V 2>&1 | tr ' ' '\n' | sed -n 's/^--conf-path=//p' | head -1)
  if [ -n "$p" ]; then
    NGX_DIR=$(dirname "$p"); MAINCONF="$p"; return 0
  fi
  # 兜底：从 --prefix 推导（源码编译只给了 prefix、-V 无 conf-path 的场景）
  local pre
  pre=$("$NGX_BIN" -V 2>&1 | tr ' ' '\n' | sed -n 's/^--prefix=//p' | head -1)
  if [ -n "$pre" ] && [ -f "$pre/conf/nginx.conf" ]; then
    NGX_DIR="$pre/conf"; MAINCONF="$pre/conf/nginx.conf"; return 0
  fi
  local d
  for d in /etc/nginx /usr/local/nginx/conf /usr/local/openresty/nginx/conf \
           /usr/local/etc/nginx /opt/homebrew/etc/nginx /etc/openresty \
           /www/server/nginx/conf; do
    if [ -f "$d/nginx.conf" ]; then NGX_DIR="$d"; MAINCONF="$d/nginx.conf"; return 0; fi
  done
  return 1
}

# 依据是否显式传了站点 conf，定下 SNIP / BDIR
resolve_ctx() {  # $1=站点conf路径（可为空）
  if [ -z "$NGX_DIR" ] && [ -n "${1:-}" ]; then
    NGX_DIR=$(dirname "$1")
  fi
  [ -n "$NGX_DIR" ] || return 1
  SNIP="$NGX_DIR/snippets/mf-error-pages.conf"
  BDIR="$NGX_DIR/mf-errpage-backups"
  return 0
}

# 隧道客户端进程（只读探测，不做任何改动）
detect_tunnels() {
  have pgrep || return 0
  local n hits=""
  for n in frpc cloudflared ngrok natapp nps; do
    pgrep -x "$n" >/dev/null 2>&1 && hits="$hits $n"
  done
  [ -n "$hits" ] && printf '%s\n' "${hits# }"
  return 0
}

detect_php_builtin() {
  have pgrep || return 1
  pgrep -f '(php|php-fpm|static-php) +(-S|--server)' >/dev/null 2>&1
}

detect_apache() { have apache2 || have httpd; }

# ---------------- 备份 / 回滚 ----------------

backup() {  # $1=conf → 备份到独立目录并登记
  mkdir -p "$BDIR" 2>/dev/null || die "无法创建备份目录 $BDIR——请用 sudo 运行，或以有写权限的用户执行"
  local bak="$BDIR/$(basename "$1").bak-$(date +%Y%m%d-%H%M%S)-$$"
  cp -p "$1" "$bak" || die "备份失败：$1"
  BAKS="$BAKS|$bak:$1"
  ok "已备份 → $bak"
}

rollback() {
  local oldIFS="$IFS" pair bak conf
  IFS='|'
  for pair in $BAKS; do
    [ -z "$pair" ] && continue
    bak="${pair%%:*}"; conf="${pair#*:}"
    cp -p "$bak" "$conf" && bad "  已回滚 $conf ← $(basename "$bak")"
  done
  IFS="$oldIFS"
}

nginx_test() {
  if [ -n "$MAINCONF" ]; then "$NGX_BIN" -t -c "$MAINCONF" >/dev/null 2>&1
  else "$NGX_BIN" -t >/dev/null 2>&1; fi
}

gate_or_rollback() {
  if ! have_nginx; then
    [ "${MF_SKIP_RELOAD:-0}" = 1 ] && { ok "（测试模式：无 nginx 可执行文件，跳过 -t 校验）"; return 0; }
    die "找不到 nginx 命令，无法校验配置"
  fi
  if nginx_test; then ok "nginx -t 校验通过"; return 0; fi
  bad "nginx -t 未通过，自动回滚："
  rollback
  die "已全部回滚，站点配置保持原样（备份保留在 $BDIR）"
}

# reload 链：systemd → sysv service → init.d → nginx -s reload → 指定主配置重试
nginx_reload() {
  [ "${MF_SKIP_RELOAD:-0}" = 1 ] && { ok "（按 MF_SKIP_RELOAD=1 跳过 reload）"; return 0; }
  if systemctl reload nginx 2>/dev/null \
     || service nginx reload 2>/dev/null \
     || { [ -x /etc/init.d/nginx ] && /etc/init.d/nginx reload 2>/dev/null; } \
     || "$NGX_BIN" -s reload 2>/dev/null \
     || { [ -n "$MAINCONF" ] && "$NGX_BIN" -c "$MAINCONF" -s reload 2>/dev/null; }; then
    sleep 1   # 等 HUP 后旧 worker 退出，避免紧随其后的验证打到旧进程
    ok "nginx 已热加载，立即生效"
  else
    bad "自动 reload 未成功，请手动执行：nginx -s reload（或 systemctl reload nginx）"
  fi
}

# ---------------- 论坛站点探测 ----------------
# 判定依据：conf 中 root 指向的目录下存在 error/404.html（程序包自带，随版本在）

conf_roots() { awk 'tolower($1)=="root"{v=$2; gsub(/[";]/,"",v); print v}' "$1"; }

is_forum_conf() {
  local r
  while IFS= read -r r; do
    [ -n "$r" ] && [ -f "${r%/}/error/404.html" ] && return 0
  done < <(conf_roots "$1")
  return 1
}

list_forum_confs() {
  local f
  for f in "$NGX_DIR"/sites-enabled/* \
           "$NGX_DIR"/conf.d/*.conf \
           "$NGX_DIR"/vhost/*.conf \
           /www/server/panel/vhost/nginx/*.conf \
           "$NGX_DIR"/nginx.conf; do
    [ -f "$f" ] && is_forum_conf "$f" && echo "$f"
  done
}

forum_root_of() {
  local r
  while IFS= read -r r; do
    if [ -n "$r" ] && [ -f "${r%/}/error/404.html" ]; then echo "${r%/}"; return 0; fi
  done < <(conf_roots "$1")
  return 1
}

# ---------------- 接线核心 ----------------

write_snippet() {  # $1=URL 前缀（"" = 根目录安装）
  local pfx="$1"
  mkdir -p "$(dirname "$SNIP")" 2>/dev/null || die "无法创建 $(dirname "$SNIP")——请用 sudo 运行"
  cat > "$SNIP" <<EOF
# $MARK 由 mf-errpage.sh 生成 · 极简论坛自定义错误页（零依赖静态，PHP 宕机也可出站）
# 站点安装路径前缀：${pfx:-/}
error_page 403 $pfx/error/403.html;
error_page 404 $pfx/error/404.html;
error_page 413 $pfx/error/413.html;
error_page 500 $pfx/error/500.html;
error_page 502 $pfx/error/502.html;
error_page 503 $pfx/error/503.html;
EOF
}

insert_include() {  # $1=conf  $2=论坛 root 绝对路径
  local ln ins
  ln=$(awk -v r="$2" 'tolower($1)=="root"{v=$2; gsub(/[";]/,"",v); if (v==r){print NR; exit}}' "$1")
  [ -n "$ln" ] || die "未在 $1 找到论坛 root 行（$2）；请在对应 server 块内手动加一行：include $SNIP; #$MARK"
  ins="    include $SNIP; #$MARK"
  awk -v n="$ln" -v ins="$ins" 'NR==n{print; print ins; next} {print}' "$1" > "$1.mftmp" \
    && cat "$1.mftmp" > "$1" && rm -f "$1.mftmp"
  ok "已接线：$1 第 $ln 行后插入 include"
}

# ---------------- 子命令 ----------------

do_on() {
  local conf="${1:-}" pfx="${2:-/}"

  if ! detect_nginx_bin && [ -z "${MF_NGX_CONF_DIR:-}" ] && [ -z "$(list_forum_confs 2>/dev/null)" ]; then
    if detect_apache; then
      ok "检测到 Apache：程序包内 .htaccess 的 ErrorDocument 已自动接管错误页，无需本脚本"
      exit 0
    fi
    die "未检测到 nginx，也未发现 Apache（.htaccess 场景）。
    · 若用 PHP 内置服务器：站内品牌页（404/403/500）在 PHP 存活时始终生效，无需接线
    · 若经内网穿透直连源站：接线需源站有 nginx/Apache 反代层
    · 若 nginx 在容器/面板内：请设 MF_NGX_CONF_DIR 后重试，或进容器执行"
  fi

  detect_conf_dir || true
  resolve_ctx "$conf" || die "无法定位 nginx 配置目录——请设 MF_NGX_CONF_DIR，或直接指定站点 conf：mf-errpage.sh on <conf路径>"

  if [ -z "$conf" ]; then
    local cands n
    cands=$(list_forum_confs)
    [ -n "$cands" ] || die "未自动找到论坛站点（扫描 $NGX_DIR 下 sites-enabled / conf.d / vhost 及面板 vhost 目录，判定依据：root 目录下存在 error/404.html）。可手动指定：mf-errpage.sh on <conf路径> [子目录前缀]"
    n=$(printf '%s\n' "$cands" | wc -l)
    if [ "$n" -gt 1 ]; then
      bad "找到多个论坛站点 conf，请指定其一："
      printf '%s\n' "$cands" | sed 's/^/    · /' >&2
      exit 1
    fi
    conf="$cands"
  fi
  [ -f "$conf" ] || die "conf 不存在：$conf"
  [ -w "$conf" ] || die "对 $conf 无写权限——请用 sudo 运行，或以有写权限的用户执行"

  pfx="${pfx%/}"
  [ -n "$pfx" ] && [ "${pfx#/}" = "$pfx" ] && pfx="/$pfx"
  [ "$pfx" = "/" ] && pfx=""
  local froot; froot=$(forum_root_of "$conf")
  [ -n "$froot" ] || die "该 conf 未包含论坛站点（root 下无 error/404.html）：$conf"
  [ -f "$froot/error/502.html" ] || die "错误页不完整：$froot/error/ 缺 502.html"

  write_snippet "$pfx"
  ok "错误页 snippet：$SNIP（前缀 ${pfx:-/}）"

  if grep -qF "$MARK" "$conf"; then
    ok "站点已在启用状态（前缀已按本次参数刷新为 ${pfx:-/}）"
    nginx_reload
    return 0
  fi

  backup "$conf"
  insert_include "$conf" "$froot"
  gate_or_rollback
  nginx_reload
  local t; t=$(detect_tunnels)
  [ -n "$t" ] && say "提示：检测到隧道客户端（$t）运行中——源站接线已生效；隧道/CDN 自身宕机时的错误页由服务商生成，属架构边界"
  ok "完成：403/404/413/500/502/503 全部走品牌错误页（mf-errpage.sh off 可切回）"
}

do_off() {
  detect_nginx_bin 2>/dev/null || true
  detect_conf_dir || true
  resolve_ctx "${1:-}" || die "无法定位 nginx 配置目录——请设 MF_NGX_CONF_DIR，或指定站点 conf：mf-errpage.sh off <conf路径>"
  local found=0 f
  for f in $(list_forum_confs) "$@"; do
    [ -f "$f" ] || continue
    grep -qF "$MARK" "$f" || continue
    [ -w "$f" ] || die "对 $f 无写权限——请用 sudo 运行"
    backup "$f"
    awk -v m="$MARK" 'index($0,m)==0' "$f" > "$f.mftmp" \
      && cat "$f.mftmp" > "$f" && rm -f "$f.mftmp"
    found=1
    ok "已移除接线：$f"
  done
  [ "$found" = 1 ] || { ok "当前未启用，无需切换"; return 0; }
  rm -f "$SNIP" 2>/dev/null
  gate_or_rollback
  nginx_reload
  ok "完成：已切回服务器默认错误页"
}

do_status() {
  local c f on=0 t
  say "── mf-errpage 环境体检 ──"
  detect_nginx_bin \
    && say "nginx：$NGX_BIN" \
    || say "nginx：未检测到"
  detect_conf_dir \
    && say "配置目录：$NGX_DIR（主配置 ${MAINCONF:-未定位}）" \
    || say "配置目录：未能自动定位（可设 MF_NGX_CONF_DIR）"
  detect_apache && say "Apache：检测到（.htaccess 的 ErrorDocument 自动接管，无需本脚本）" || say "Apache：未检测到"
  t=$(detect_tunnels)
  if [ -n "$t" ]; then
    say "内网穿透：检测到 $t 运行中"
    say "         └ 层级说明：源站反代的错误页由本脚本接线；隧道/CDN 自身宕机页由服务商生成（架构边界）"
  else
    say "内网穿透：未检测到常见隧道客户端"
  fi
  if detect_php_builtin; then
    say "PHP 内置服务器：检测到运行中（无反代层可接线；站内品牌页在 PHP 存活时生效）"
  fi
  say ""
  say "── 论坛站点与接线 ──"
  if [ -n "$NGX_DIR" ]; then
    c=$(list_forum_confs)
    if [ -n "$c" ]; then
      say "论坛站点 conf："
      printf '%s\n' "$c" | sed 's/^/    · /'
    else
      say "论坛站点 conf：（未找到——root 目录下无 error/404.html？）"
    fi
    if [ -f "$SNIP" ]; then
      say "snippet：$SNIP（存在，前缀 $(awk '/前缀/{n=split($0,a,"："); print a[n]}' "$SNIP")）"
    else
      say "snippet：不存在（未启用）"
    fi
    for f in $c; do
      if grep -qF "$MARK" "$f"; then on=1; say "接线：$f ✔ 已启用"; else say "接线：$f ✘ 未启用"; fi
    done
    [ "$on" = 1 ] && say "总状态：✔ 自定义错误页已生效" || say "总状态：✘ 未启用（服务器默认页）"
  else
    say "nginx 配置目录未定位，无法检查接线状态（可设 MF_NGX_CONF_DIR 后重试）"
  fi
  say ""
  say "── 提醒 ──"
  say "· 上传 413：server 块的 client_max_body_size 与 PHP post_max_size / upload_max_filesize"
  say "  需 ≥ 论坛后台设置的附件上限；后台「监控」页上传链路自检可查运行时值"
  say "· PHP 层的 404/403/500 由论坛站内品牌页自答，与 nginx 层接线互不冲突"
}

usage() {
  sed -n '3,43p' "$0" | sed 's/^# \{0,1\}//'
}

MARK="mf-errpage-hook"
case "${1:-}" in
  on)     shift; do_on "$@" ;;
  off)    shift; do_off "$@" ;;
  status) do_status ;;
  help|-h|--help) usage ;;
  *)      usage; [ -n "${1:-}" ] && bad "未知命令：$1"
          exit 2 ;;
esac
