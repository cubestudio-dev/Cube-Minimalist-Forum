#!/usr/bin/env bash
# =============================================================
#  Cube Minimalist Forum · 一键更新/修复脚本（v1.43.0 起随包分发）
#
#  用法：
#    bash update.sh                 # 自动定位论坛目录并执行
#    bash update.sh /path/to/forum  # 指定论坛目录
#    bash update.sh /path/to/forum -y   # 免确认
#
#  一条命令做完五件事：
#    1) 定位论坛目录（参数 > 常见路径扫描）
#    2) 自动备份程序文件（data/ 永不触碰）
#    3) 按清单+sha256 覆盖程序文件（含 tools/ 与本脚本）
#    4) 自动解析 SMTP 服务器 IP 并写入后台「直连 IP」（DNS 故障免疫）
#    5) 以论坛运行用户身份运行 tools/smtp-doctor.php 全链路体检
#
#  说明：后台「更新升级」页面上传 forum-update-any-to-*.zip 是等价方式（免 SSH）。
# =============================================================
set -u
APPDIR="$(cd "$(dirname "$0")" && pwd)"
YES=0
TARGET=""

for a in "$@"; do
  case "$a" in
    -y|--yes) YES=1 ;;
    -h|--help) sed -n '2,20p' "$0" | sed 's/^# \{0,2\}//'; exit 0 ;;
    *) TARGET="$a" ;;
  esac
done

say()  { printf '%s\n' "$*"; }
warn() { printf '[WARN] %s\n' "$*"; }
bad()  { printf '[FAIL] %s\n' "$*"; }
ok()   { printf '[ OK ] %s\n' "$*"; }

# ---------- 1. 定位论坛目录 ----------
if [ -z "$TARGET" ]; then
  CAND=""
  for root in /var/www /www/wwwroot /home /srv /opt /usr/share/nginx/html /data /app; do
    [ -d "$root" ] || continue
    HIT="$(find "$root" -maxdepth 4 -type f -name bootstrap.php -path '*/src/*' 2>/dev/null | while read -r b; do
      d="$(dirname "$(dirname "$b")")"
      [ -f "$d/index.php" ] && [ -d "$d/data" ] && printf '%s\n' "$d"
    done | head -n 5)"
    if [ -n "$HIT" ]; then
      N="$(printf '%s\n' "$HIT" | wc -l)"
      if [ "$N" -eq 1 ]; then CAND="$HIT"; break; fi
    fi
  done
  if [ -z "${CAND:-}" ]; then
    # 扫描目录恰好一个候选时使用；多个候选列出来让用户选
    ALL=""
    for root in /var/www /www/wwwroot /home /srv /opt /usr/share/nginx/html /data /app; do
      [ -d "$root" ] || continue
      ALL="$ALL$(find "$root" -maxdepth 4 -type f -name bootstrap.php -path '*/src/*' 2>/dev/null | while read -r b; do
        d="$(dirname "$(dirname "$b")")"
        [ -f "$d/index.php" ] && [ -d "$d/data" ] && printf '%s\n' "$d"
      done)"
    done
    N="$(printf '%s\n' "$ALL" | grep -c . 2>/dev/null || echo 0)"
    if [ "${N:-0}" -eq 1 ]; then
      CAND="$ALL"
    elif [ "${N:-0}" -gt 1 ]; then
      bad "发现多个论坛安装，请指定目录后重试："
      printf '%s\n' "$ALL" | sed 's/^/    /'
      exit 2
    fi
  fi
  TARGET="${CAND:-}"
fi

if [ -z "$TARGET" ]; then
  bad "未找到论坛目录。请显式指定：bash update.sh /论坛目录"
  exit 2
fi
if [ ! -f "$TARGET/index.php" ] || [ ! -f "$TARGET/src/bootstrap.php" ] || [ ! -d "$TARGET/data" ]; then
  bad "$TARGET 不像论坛目录（缺 index.php / src/bootstrap.php / data）"
  exit 2
fi

say "============================================================"
say " 论坛目录: $TARGET"
say " 更新来源: $APPDIR"
if [ "$YES" -ne 1 ]; then
  printf ' 回车确认开始，或 Ctrl+C 取消：'
  read -r _
fi

# ---------- PHP 二进制 ----------
PHP=""
for c in php php8.4 php8.3 php8.2 php8.1 php7.4 /usr/local/bin/php /usr/bin/php; do
  if command -v "$c" >/dev/null 2>&1 && "$c" -v >/dev/null 2>&1; then PHP="$c"; break; fi
done
if [ -z "$PHP" ]; then
  for c in /www/server/php/*/bin/php; do
    if [ -x "$c" ]; then PHP="$c"; break; fi
  done
fi
if [ -z "$PHP" ]; then
  warn "未找到 php 命令：将只覆盖文件，跳过「直连 IP 自动配置」与「体检」"
  warn "后续可手动执行：php $TARGET/tools/smtp-doctor.php $TARGET --fix --set-ip"
fi

# ---------- 2. 备份 ----------
BKDIR="$TARGET/data/backup"
mkdir -p "$BKDIR" 2>/dev/null
BK="$BKDIR/pre-shellupdate-$(date +%Y%m%d-%H%M%S).tar.gz"
if tar czf "$BK" --exclude='./data' -C "$TARGET" . 2>/dev/null && [ -s "$BK" ]; then
  ok "已备份程序文件 → ${BK#"$TARGET"/}（data/ 不参与更新，永不受影响）"
  ls -1t "$BKDIR"/pre-shellupdate-*.tar.gz 2>/dev/null | tail -n +4 | xargs -r rm -f
else
  bad "备份失败（$BK），为安全起见本次不做任何变更。请检查 data/backup 可写性后重试。"
  exit 1
fi

# ---------- 3. 按清单覆盖 ----------
MANIFEST="$APPDIR/manifest.txt"
OWNER=""
[ -f "$TARGET/index.php" ] && OWNER="$(stat -c '%U:%G' "$TARGET/index.php" 2>/dev/null || true)"
if [ ! -f "$MANIFEST" ]; then
  bad "包内缺少 manifest.txt（$MANIFEST），无法安全覆盖。请重新解压完整更新包。"
  exit 1
fi
COPIED=0; SKIPPED=0
while read -r sum rel; do
  case "$rel" in ''|\#*) continue ;; esac
  SRC="$APPDIR/$rel"
  DST="$TARGET/$rel"
  if [ ! -f "$SRC" ]; then warn "包内缺文件，跳过: $rel"; SKIPPED=$((SKIPPED+1)); continue; fi
  ACTUAL="$(sha256sum "$SRC" 2>/dev/null | cut -d' ' -f1)"
  if [ -n "$sum" ] && [ "$ACTUAL" != "$sum" ]; then
    bad "校验不符（预期 $sum，实际 $ACTUAL），跳过: $rel —— 包可能损坏，请重新下载"; SKIPPED=$((SKIPPED+1)); continue
  fi
  mkdir -p "$(dirname "$DST")"
  if cp -f "$SRC" "$DST"; then COPIED=$((COPIED+1)); else bad "覆盖失败: $rel"; SKIPPED=$((SKIPPED+1)); fi
done < "$MANIFEST"
if [ -n "$OWNER" ] && [ "$(id -u)" = "0" ]; then
  while read -r _ rel; do
    case "$rel" in ''|\#*) continue ;; esac
    [ -f "$TARGET/$rel" ] && chown "$OWNER" "$TARGET/$rel" 2>/dev/null
  done < "$MANIFEST"
fi
ok "文件覆盖完成：$COPIED 个成功，$SKIPPED 个跳过"

# ---------- 4/5. 体检 + 直连 IP 自动配置 ----------
if [ -n "$PHP" ]; then
  DOCTOR="$TARGET/tools/smtp-doctor.php"
  if [ ! -f "$DOCTOR" ]; then
    warn "未找到 tools/smtp-doctor.php，跳过体检"
  else
    say ""
    say "---------------- 以 root/current 修复系统层 ----------------"
    "$PHP" "$DOCTOR" "$TARGET" --fix || true
    say ""
    say "---------------- 直连 IP 自动配置 ----------------"
    "$PHP" "$DOCTOR" "$TARGET" --set-ip || true
    say ""
    RUNUSER="$(stat -c '%U' "$TARGET/data" 2>/dev/null || echo '')"
    say "---------------- 论坛运行身份（$RUNUSER）全链路体检 ----------------"
    RAN=0
    if [ -n "$RUNUSER" ] && [ "$(id -u)" = "0" ] && [ "$RUNUSER" != "root" ] && [ "$RUNUSER" != "$(whoami 2>/dev/null || echo '')" ] && command -v sudo >/dev/null 2>&1; then
      if sudo -n -u "$RUNUSER" "$PHP" "$DOCTOR" "$TARGET"; then RAN=1; fi
    fi
    if [ "$RAN" -ne 1 ]; then
      "$PHP" "$DOCTOR" "$TARGET" || true
      [ -n "$RUNUSER" ] && [ "$RUNUSER" != "root" ] && warn "以上体检以 $(whoami 2>/dev/null) 身份运行；论坛实际以 $RUNUSER 发信，如需精确复现：sudo -u $RUNUSER $PHP $DOCTOR $TARGET"
    fi
  fi
fi

say ""
say "============================================================"
say " 更新完成。后台「邮件设置」新增「直连 IP（可选）」字段，"
say " 系统已尽力自动配置；如体检仍有 FAIL，按输出中的修复办法处理。"
say " 验证发信：论坛后台 → 邮件 → 发送测试邮件。"
say "============================================================"
exit 0
