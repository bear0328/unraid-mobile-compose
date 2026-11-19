#!/bin/bash
# install-compose-api.sh — unraid-mobile Compose 后端安装脚本(续 49.2)
#
# 在 unRAID 宿主上以 root 运行,幂等(重复执行安全)。
#
#   bash install-compose-api.sh <unRAID-apiKey>     # 直接给 key
#   bash install-compose-api.sh                     # 交互式输入 key(不回显)
#   bash install-compose-api.sh -y [apiKey]         # 跳过风险确认(自动化用)
#   bash install-compose-api.sh --local-only -y <key>
#         只用脚本同目录的本地文件,绝不走远程下载 —— Unraid OS 升级后的恢复/重装必用
#   UNRAID_API_KEY_FILE=/run/secrets/unraid_api_key bash install-compose-api.sh -y
#         从权限受控的文件读 key,避免把明文 key 放进命令行
#   UNRAID_HOST_ROOT=/tmp/fake-host bash install-compose-api.sh -y
#         模拟宿主根目录(仅测试):所有读写都落到该目录,不碰真实宿主
#
# ⚠️ 风险说明(续 57,运行前必读):
#   本脚本会修改 unRAID 开机脚本 /boot/config/go —— 它是系统启动时执行的核心
#   脚本,改错可能导致开机异常。保障措施:修改前自动备份为 go.unraid-mobile-bak,
#   追加内容仅数行(带【unraid-mobile】标记),卸载时按标记删除即可恢复。
#   脚本执行时会要求输入 YES 确认知悉此风险(-y 跳过)。
#
# 它做什么:
#   1. 检测 Compose Manager Plus(插件 ID compose.manager):已安装则跳过,未安装则从作者官方仓库安装
#      (--local-only 不联网,缺插件时会中止并提示先手动安装)
#   2. 写 /boot/config/plugins/unraid-mobile/apikey (flash 盘,存 sha256 哈希;FAT 权限由挂载控制)
#   3. 装 api.php/plugin-worker.php 正本到 /boot/config/plugins/unraid-mobile/
#      并 cp 到执行位置 /usr/local/emhttp/plugins/compose.manager/
#   4. 往 /boot/config/go 加恢复钩子(tmpfs 重启后重建执行位置)
#
# 容器侧还需一个挂载(脚本最后会再提示):
#   -v /var/run/php-fpm.sock:/hostrun/php-fpm.sock
#
# api.php/plugin-worker.php 来源: 优先用脚本同目录文件;否则从 RAW_URL 同目录下载并校验 sha256。
set -euo pipefail

# 【续 49.4】公开版默认从 GitHub raw 拉 api.php(tag 固定版本;仓已迁 unraid-mobile-compose)
RAW_URL="https://raw.githubusercontent.com/bear0328/unraid-mobile-compose/v1.4.8/compose-api/api.php"
# Compose Manager Plus 是 Pro 宿主后端唯一的第三方 Unraid 插件依赖;CA 插件本身不是依赖。
# 固定到已审核的上游提交(Plus 2026.11.13a),避免 main 分支变化绕过发布审核。
# 清单内的 txz 使用上游 release 固定版本 URL,并由插件机制校验 MD5。
COMPOSE_MANAGER_URL="https://raw.githubusercontent.com/mstrhakr/compose_plugin/d878de4a3e7bfdfdbe7fdb0ff3daf5d223445d42/compose.manager.plg"
# 测试用宿主根目录前缀。真实安装必须为空;模拟安装时所有宿主文件读写都进入该目录。
HOST_ROOT="${UNRAID_HOST_ROOT:-}"
[ "$HOST_ROOT" = "/" ] && HOST_ROOT=""
HOST_ROOT="${HOST_ROOT%/}"
host_path() { printf '%s%s' "$HOST_ROOT" "$1"; }

PLUGIN_CLI="$(host_path /usr/local/sbin/plugin)"
COMPOSE_PLUGIN_FILE="$(host_path /var/log/plugins/compose.manager.plg)"
# 【续 50 D4-1】下载的 api.php 做 sha256 校验(防下载源被篡改);改动 api.php 后必须同步更新此值
EXPECTED_API_SHA256="923197b8a2fe50648fe1c9c1c754ca6516251f32a5f7d8cf0e15a0b2563e21fb"
EXPECTED_WORKER_SHA256="af1e7ca0db4807feebc6ba564c9630ee8d41580a6787ee8c28df90ca4849b150"

PLUGIN_DIR="$(host_path /boot/config/plugins/unraid-mobile)"
EXEC_DIR="$(host_path /usr/local/emhttp/plugins/compose.manager)"
GO_FILE="$(host_path /boot/config/go)"
PHP_FPM_SOCK="$(host_path /var/run/php-fpm.sock)"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# 下载校验值:默认用上方内置值;同目录存在 SHA256SUMS 时以它为准。
# 以后发布新版只需更新 RAW_URL 的 tag 与 SHA256SUMS,不必再改本脚本里的哈希。
if [ -f "$SCRIPT_DIR/SHA256SUMS" ]; then
    _api_sum=$(awk '$2=="api.php" {print $1}' "$SCRIPT_DIR/SHA256SUMS")
    _worker_sum=$(awk '$2=="plugin-worker.php" {print $1}' "$SCRIPT_DIR/SHA256SUMS")
    [ -n "$_api_sum" ] && EXPECTED_API_SHA256="$_api_sum"
    [ -n "$_worker_sum" ] && EXPECTED_WORKER_SHA256="$_worker_sum"
    unset _api_sum _worker_sum
fi

info() { echo "[install] $*"; }
die()  { echo "[install] ERROR: $*" >&2; exit 1; }

ensure_compose_manager() {
    if [ -f "$COMPOSE_PLUGIN_FILE" ] && [ -d "$EXEC_DIR" ]; then
        info "检测到 compose.manager(Compose Manager Plus 或兼容旧版),跳过插件安装"
        return 0
    fi
    if [ -f "$COMPOSE_PLUGIN_FILE" ]; then
        die "Compose Manager Plus(compose.manager)安装记录存在,但运行目录 $EXEC_DIR 缺失。请先在 Community Applications 中修复/重装该插件,再运行本脚本。"
    fi
    if [ "$LOCAL_ONLY" = "1" ]; then
        die "--local-only 不会联网安装插件。请先通过 Community Applications 安装 Compose Manager Plus,再重跑本脚本。"
    fi
    [ -x "$PLUGIN_CLI" ] || die "未找到 Unraid 官方插件命令 $PLUGIN_CLI,无法安装 Compose Manager Plus"

    info "未检测到 Compose Manager Plus,将从作者官方仓库安装: $COMPOSE_MANAGER_URL"
    "$PLUGIN_CLI" install "$COMPOSE_MANAGER_URL" || die "Compose Manager Plus 安装失败;可在 Community Applications 搜索 Compose Manager Plus 手动安装后重试"
    [ -f "$COMPOSE_PLUGIN_FILE" ] && [ -d "$EXEC_DIR" ] \
        || die "Compose Manager Plus 安装命令已结束,但未检测到完整安装记录和运行目录"
    info "Compose Manager Plus 安装完成"
}

# ---------- 0. 环境校验(只读;所有系统修改都在风险确认之后) ----------
[ "$(id -u)" = "0" ] || die "需要 root 运行(要读写 /boot/config 和 /usr/local/emhttp)"
[ -S "$PHP_FPM_SOCK" ] || echo "[install] 警告: $PHP_FPM_SOCK 不存在,php-fpm 未运行?装完后 compose-api 会 502"

# ---------- 0.5 参数解析 ----------
# -y/--yes      跳过风险确认(自动化用)
# --local-only  只用脚本同目录的本地文件,绝不走远程下载。
#               Unraid OS 升级后的恢复/重装场景必须加它:RAW_URL 固定旧版本 tag,
#               走远程会把宿主上未发布的本地修复(如 indexBuildLockValid)覆盖回旧版。
ASSUME_YES=0
LOCAL_ONLY=0
while [ $# -gt 0 ]; do
    case "$1" in
        -y|--yes) ASSUME_YES=1; shift ;;
        --local-only) LOCAL_ONLY=1; shift ;;
        *) break ;;
    esac
done
if [ "$ASSUME_YES" != "1" ]; then
    cat << 'EOF'

⚠️  风险说明 —— 运行前请阅读
本脚本会修改 unRAID 开机脚本 /boot/config/go(系统启动核心脚本),
用于重启后恢复 compose-api。保障措施:
  · 修改前自动备份为 /boot/config/go.unraid-mobile-bak
  · 仅追加数行(带【unraid-mobile】标记),不改动你已有的任何行
  · 缺少 Compose Manager Plus 时会从插件作者官方仓库安装;已安装则跳过
  · 卸载后端: 删除 /boot/config/plugins/unraid-mobile/ 及 go 里标记的行
  · 如不再需要本次自动安装的 Compose Manager Plus,需在 Unraid 插件管理中单独卸载
EOF
    read -r -p "已知晓上述风险,确认继续? 输入 YES 继续,其他任意输入中止: " CONFIRM
    [ "$CONFIRM" = "YES" ] || die "用户未确认,已中止(未做任何修改)"
fi

# ---------- 0.6 按需安装第三方插件(已安装则跳过) ----------
ensure_compose_manager

# ---------- 1. 拿 apiKey(参数 > 密钥文件 > 环境变量 > 交互输入) ----------
APIKEY="${1:-}"
if [ -z "$APIKEY" ] && [ -n "${UNRAID_API_KEY_FILE:-}" ]; then
    [ -r "$UNRAID_API_KEY_FILE" ] || die "apiKey 文件不可读: $UNRAID_API_KEY_FILE"
    APIKEY="$(cat "$UNRAID_API_KEY_FILE")"
fi
APIKEY="${APIKEY:-${UNRAID_API_KEY:-}}"
if [ -z "$APIKEY" ]; then
    read -r -s -p "输入 unRAID API 密钥(与 App 里填的 GraphQL key 相同,不回显): " APIKEY
    echo
fi
[ -n "$APIKEY" ] || die "apiKey 为空"

# ---------- 2. 写 key 文件 ----------
# 【续 60】存 `sha256:<hex>` 前缀格式而非明文 —— flash 备份/诊断包外泄不泄 key 本身;
# api.php 按前缀识别哈希(无前缀的旧明文格式也兼容)
mkdir -p "$PLUGIN_DIR"
# 【续 50 D4-1】umask 077 再创建,消除文件先 0644 后 chmod 600 的窗口期
(umask 077; printf 'sha256:%s' "$(printf '%s' "$APIKEY" | sha256sum | cut -d' ' -f1)" > "$PLUGIN_DIR/apikey")
chmod 600 "$PLUGIN_DIR/apikey" 2>/dev/null \
    || info "提示: /boot 可能是 FAT,无法设置逐文件 600;哈希内容有效,权限由挂载选项控制"
info "key 文件: $PLUGIN_DIR/apikey (sha256 哈希,不存明文)"

# ---------- 3. 装 api.php ----------
if [ -f "$SCRIPT_DIR/api.php" ]; then
    cp "$SCRIPT_DIR/api.php" "$PLUGIN_DIR/api.php"
    info "api.php 来自脚本同目录"
elif [ "$LOCAL_ONLY" = "1" ]; then
    die "--local-only: 脚本同目录没有 api.php,已中止(不从远程下载,避免旧版本覆盖本地修复)"
elif [ -n "$RAW_URL" ]; then
    curl -fsSL "$RAW_URL" -o "$PLUGIN_DIR/api.php" || die "下载 api.php 失败: $RAW_URL"
    # 【续 50 D4-1】sha256 校验,不符即中止(本地同目录提供的 api.php 不校验,便于开发迭代)
    (cd "$PLUGIN_DIR" && echo "$EXPECTED_API_SHA256  api.php" | sha256sum -c -) \
        || die "api.php sha256 校验不符(期望 $EXPECTED_API_SHA256),已中止"
    info "api.php 下载自 $RAW_URL (sha256 校验通过)"
else
    die "脚本同目录没有 api.php,且 RAW_URL 未配置(发布前限制:请把 api.php 放到脚本旁边)"
fi
php -l "$PLUGIN_DIR/api.php" > /dev/null || die "api.php 语法检查失败,不安装"
cp "$PLUGIN_DIR/api.php" "$EXEC_DIR/api.php"
info "api.php: 正本 $PLUGIN_DIR/api.php → 执行位置 $EXEC_DIR/api.php"

# ---------- 3.1 装 plugin-worker.php ----------
if [ -f "$SCRIPT_DIR/plugin-worker.php" ]; then
    cp "$SCRIPT_DIR/plugin-worker.php" "$PLUGIN_DIR/plugin-worker.php"
    info "plugin-worker.php 来自脚本同目录"
elif [ "$LOCAL_ONLY" = "1" ]; then
    die "--local-only: 脚本同目录没有 plugin-worker.php,已中止(不从远程下载)"
elif [ -n "$RAW_URL" ]; then
    WORKER_RAW_URL="${RAW_URL%/*}/plugin-worker.php"
    curl -fsSL "$WORKER_RAW_URL" -o "$PLUGIN_DIR/plugin-worker.php" || die "下载 plugin-worker.php 失败: $WORKER_RAW_URL"
    (cd "$PLUGIN_DIR" && echo "$EXPECTED_WORKER_SHA256  plugin-worker.php" | sha256sum -c -) \
        || die "plugin-worker.php sha256 校验不符(期望 $EXPECTED_WORKER_SHA256),已中止"
    info "plugin-worker.php 下载自 $WORKER_RAW_URL (sha256 校验通过)"
else
    die "脚本同目录没有 plugin-worker.php,且 RAW_URL 未配置"
fi
php -l "$PLUGIN_DIR/plugin-worker.php" > /dev/null || die "plugin-worker.php 语法检查失败,不安装"
chmod 0755 "$PLUGIN_DIR/plugin-worker.php" 2>/dev/null \
    || info "提示: /boot 可能是 FAT,跳过逐文件执行位;执行位置仍会设置 0755"
cp "$PLUGIN_DIR/plugin-worker.php" "$EXEC_DIR/plugin-worker.php"
chmod 0755 "$EXEC_DIR/plugin-worker.php"
info "plugin-worker.php: 正本 $PLUGIN_DIR/plugin-worker.php → 执行位置 $EXEC_DIR/plugin-worker.php"

# ---------- 3.5 装 update-status.php(续 68.2:pull 成功后回写更新徽章缓存) ----------
# 老版本发布物里没有这个文件,下载失败只警告不中止(徽章回写失效,主流程不受影响)
if [ -f "$SCRIPT_DIR/update-status.php" ]; then
    cp "$SCRIPT_DIR/update-status.php" "$PLUGIN_DIR/update-status.php"
    info "update-status.php 来自脚本同目录"
elif [ "$LOCAL_ONLY" = "1" ]; then
    echo "[install] 提示: --local-only 且同目录无 update-status.php,跳过(徽章即时回写不可用,其余功能正常)"
elif [ -n "$RAW_URL" ]; then
    US_RAW_URL="${RAW_URL%/*}/update-status.php"
    if curl -fsSL "$US_RAW_URL" -o "$PLUGIN_DIR/update-status.php"; then
        info "update-status.php 下载自 $US_RAW_URL"
    else
        rm -f "$PLUGIN_DIR/update-status.php"
        echo "[install] 警告: update-status.php 下载失败($US_RAW_URL),徽章即时回写不可用,其余功能正常"
    fi
fi
if [ -f "$PLUGIN_DIR/update-status.php" ]; then
    php -l "$PLUGIN_DIR/update-status.php" > /dev/null || die "update-status.php 语法检查失败,不安装"
    cp "$PLUGIN_DIR/update-status.php" "$EXEC_DIR/update-status.php"
    info "update-status.php: 正本 $PLUGIN_DIR/update-status.php → 执行位置 $EXEC_DIR/update-status.php"
fi

# ---------- 3.6 装 update-file-index.sh + register-index-cron.sh(续 123-125:全盘文件名索引,搜索不唤盘) ----------
# 老版本发布物里没有这两个文件,下载失败只警告不中止(索引搜索不可用,其余功能正常)
for f in update-file-index.sh register-index-cron.sh; do
    if [ -f "$SCRIPT_DIR/$f" ]; then
        cp "$SCRIPT_DIR/$f" "$PLUGIN_DIR/$f"
        info "$f 来自脚本同目录"
    elif [ "$LOCAL_ONLY" = "1" ]; then
        echo "[install] 提示: --local-only 且同目录无 $f,跳过(索引搜索不可用,其余功能正常)"
    else
        if curl -fsSL "${RAW_URL%/*}/$f" -o "$PLUGIN_DIR/$f"; then
            info "$f 下载自 ${RAW_URL%/*}/$f"
        else
            rm -f "$PLUGIN_DIR/$f"
            echo "[install] 警告: $f 下载失败(${RAW_URL%/*}/$f),索引搜索不可用,其余功能正常"
        fi
    fi
    if [ -f "$PLUGIN_DIR/$f" ]; then
        chmod +x "$PLUGIN_DIR/$f" 2>/dev/null \
            || info "提示: /boot 可能是 FAT,$f 的执行位由挂载选项控制"
    fi
done
if [ -f "$PLUGIN_DIR/update-file-index.sh" ]; then
    # 【续 130】索引自动构建默认【不开】:不再 touch index-enabled flag,
    # 需要每日定时构建的用户在设置页自行打开;手动重建(App 内按钮)任何时候可用,
    # scope=index 搜索也不再以 flag 为条件(只读索引文件,零唤盘)
    # 【续 125】本机立即注册 cron(不必等重启走 go 钩子);脚本内以 flag 为条件,无 flag 即不注册;整点读 index-hour(缺省凌晨 3 点)
    if [ -n "$HOST_ROOT" ]; then
        info "模拟宿主模式:跳过当前系统 cron 注册(go 钩子仍按真实 Unraid 路径生成)"
    elif [ -f "$PLUGIN_DIR/register-index-cron.sh" ]; then
        sh "$PLUGIN_DIR/register-index-cron.sh" || true
    fi
    info "update-file-index.sh: $PLUGIN_DIR/(每日自动构建默认关,设置页打开后 cron 每天整点构建,缺省 03:00;App 内可随时手动重建)"
fi

# ---------- 4. go 钩子(幂等:先清旧行再追加) ----------
# 原始备份只在首次安装创建;每次运行的前置状态另存 run-bak,避免重装覆盖真正的原始 go。
if [ ! -e "$GO_FILE.unraid-mobile-bak" ]; then
    cp -p "$GO_FILE" "$GO_FILE.unraid-mobile-bak" 2>/dev/null \
        || cp "$GO_FILE" "$GO_FILE.unraid-mobile-bak"
fi
# 历史兼容清理只匹配本项目旧钩子的完整路径/命令,不用宽泛关键词误删用户自己的 go 行。
sed -i.unraid-mobile-run-bak \
    -e '/# >>> unraid-mobile compose-api hook >>>/,/# <<< unraid-mobile compose-api hook <<</d' \
    -e '/【unraid-mobile/d' \
    -e '/tmpfs,重启后 api\.php 丢失/d' \
    -e '/^cp \/boot\/config\/plugins\/unraid-mobile\/api\.php \/usr\/local\/emhttp\/plugins\/compose\.manager\/api\.php.*$/d' \
    -e '/^cp \/boot\/config\/plugins\/unraid-mobile\/update-status\.php \/usr\/local\/emhttp\/plugins\/compose\.manager\/update-status\.php.*$/d' \
    -e '/^cp \/boot\/config\/plugins\/unraid-mobile\/plugin-worker\.php \/usr\/local\/emhttp\/plugins\/compose\.manager\/plugin-worker\.php.*$/d' \
    -e '/^chmod 0755 \/usr\/local\/emhttp\/plugins\/compose\.manager\/plugin-worker\.php.*$/d' \
    -e '/^rm -f \/boot\/config\/plugins\/compose\.manager\/projects\/\*\/\.op-running$/d' \
    -e '/^\/boot\/config\/plugins\/unraid-mobile\/update-file-index\.sh/d' \
    -e '/^\/boot\/config\/plugins\/unraid-mobile\/register-index-cron\.sh >\/dev\/null 2>&1 || true$/d' \
    "$GO_FILE"
cat >> "$GO_FILE" << 'EOF'
# >>> unraid-mobile compose-api hook >>>
# 【unraid-mobile】compose-api 恢复钩子(install-compose-api.sh 安装)
# /usr/local/emhttp 是 tmpfs,重启后 api.php 丢失,从 flash 正本恢复
# 恢复不再静默:先 mkdir -p 目标目录(插件尚未装上时也能恢复),失败写
# /boot/config/plugins/unraid-mobile/restore-error.log —— 该文件存在即代表上次启动恢复有异常,
# 跑 scripts/verify-host-compat.sh 复核,或按下文提示直接 cp 正本回去。
_ume=/usr/local/emhttp/plugins/compose.manager
_umb=/boot/config/plugins/unraid-mobile
_uml="$_umb/restore-error.log"
: > "$_uml"
mkdir -p "$_ume" 2>>"$_uml"
for _umf in api.php plugin-worker.php; do
  if [ -f "$_umb/$_umf" ]; then
    cp "$_umb/$_umf" "$_ume/$_umf" 2>>"$_uml" || echo "cp $_umf rc=$?" >>"$_uml"
  else
    echo "missing 正本 $_umf" >>"$_uml"
  fi
done
# update-status.php 可选(老发布物没有),有则恢复,缺了不记错误
[ -f "$_umb/update-status.php" ] && cp "$_umb/update-status.php" "$_ume/update-status.php" 2>>"$_uml"
chmod 0755 "$_ume/plugin-worker.php" 2>>"$_uml"
[ -s "$_uml" ] || rm -f "$_uml"
unset _ume _umb _uml _umf
# 清 .op-running 残留锁(续 88 2026-08-08):projects 在 flash 盘持久,宿主重启/进程被杀后残留会让该栈永久 409
rm -f /boot/config/plugins/compose.manager/projects/*/.op-running
# 【unraid-mobile】文件索引 cron(续 123-125):/var/spool/cron 是 tmpfs,重启重注册;脚本内以 index-enabled flag 为条件、读 index-hour 整点(设置页可配)
/boot/config/plugins/unraid-mobile/register-index-cron.sh >/dev/null 2>&1 || true
# <<< unraid-mobile compose-api hook <<<
EOF
info "go 钩子已更新(旧备份: $GO_FILE.unraid-mobile-bak)"

# ---------- 5. 完成提示 ----------
cat << 'EOF'

[install] 完成。Compose 后端已就绪。

容器侧还需要一个挂载(如果还没加):
  -v /var/run/php-fpm.sock:/hostrun/php-fpm.sock
加完重建容器,App 的 容器/VM → Compose tab 即可看到栈列表。

go 文件备份: /boot/config/go.unraid-mobile-bak(如需还原直接覆盖回去)
恢复排错: 重启后若 compose-api 502/404,先看
      /boot/config/plugins/unraid-mobile/restore-error.log(存在即恢复有异常),
      再跑 scripts/verify-host-compat.sh 做全项体检。
Unraid OS 升级后恢复: 把本目录整个拷到宿主,加 --local-only 重跑本脚本;
      不要在同目录没有 api.php 时走远程分支(RAW_URL 是固定旧版本 tag,会覆盖本地修复)。
卸载: 删掉 /boot/config/plugins/unraid-mobile/、
      /usr/local/emhttp/plugins/compose.manager/api.php、update-status.php 和 plugin-worker.php、
      /boot/config/go 里【unraid-mobile】标记的行、
      以及 crontab 里 update-file-index.sh 那行(crontab -l 查看)。
索引: 每天 03:17 自动构建全盘文件名索引(会唤盘,故排凌晨低峰);
      App 全局搜索里也可手动「重建索引」。
EOF
