#!/bin/bash
# unraid-mobile 一次性安装器:初始化 Web 凭据并安装现有宿主 compose-api。
# 真实模式通过 nsenter 在宿主执行;模拟模式把全部宿主写入限制在 HOST_ROOT 内。
set -euo pipefail
umask 077

info() { printf '[setup] %s\n' "$*"; }
die() { printf '[setup] ERROR: %s\n' "$*" >&2; exit 1; }

SIMULATE="${SETUP_SIMULATE:-0}"
HOST_ROOT="${HOST_ROOT:-/host}"
HOST_ROOT="${HOST_ROOT%/}"
APPDATA_DIR="${APPDATA_DIR:-/appdata}"
UPSTREAM="${UNRAID_UPSTREAM:-}"
SERVER_URL="${UNRAID_SERVER_URL:-$UPSTREAM}"
HOST_PLUGIN_DIR="/boot/config/plugins/unraid-mobile"
HOST_STAGE_DIR="${HOST_PLUGIN_DIR}/installer"
HOST_KEY_FILE="/var/run/unraid-mobile-setup-api-key.$$"

[ "${ALLOW_HOST_INSTALL:-}" = "YES" ] \
  || die "必须显式设置 ALLOW_HOST_INSTALL=YES;安装会修改宿主 /boot/config/go 并安装 Compose Manager Plus"
[ -n "$UPSTREAM" ] || die "UNRAID_UPSTREAM 不能为空"
case "$UPSTREAM" in
  http://*|https://*) ;;
  *) die "UNRAID_UPSTREAM 必须以 http:// 或 https:// 开头" ;;
esac
case "$UPSTREAM" in
  *$'\n'*|*$'\r'*|*' '*) die "UNRAID_UPSTREAM 不能包含空白或换行" ;;
esac
UPSTREAM="${UPSTREAM%/}"
SERVER_URL="${SERVER_URL%/}"
[ "$SIMULATE" = "0" ] || [ "$SIMULATE" = "1" ] || die "SETUP_SIMULATE 只能是 0 或 1"
if [ "$SIMULATE" = "1" ]; then
  [ "${SETUP_SIMULATION_ACK:-}" = "LOCAL_TEST_ONLY" ] \
    || die "模拟模式还需 SETUP_SIMULATION_ACK=LOCAL_TEST_ONLY,防止误用于真实宿主"
  [ -n "$HOST_ROOT" ] && [ "$HOST_ROOT" != "/" ] || die "模拟模式 HOST_ROOT 不能是真实根目录"
fi

API_KEY_FILE="/run/secrets/unraid_api_key"
DAV_SECRET_FILE="/run/secrets/dav_password"
LOG_SECRET_FILE="/run/secrets/log_password"
for secret_file in "$API_KEY_FILE" "$DAV_SECRET_FILE" "$LOG_SECRET_FILE"; do
  [ -r "$secret_file" ] || die "缺少 Compose secret: $secret_file"
  [ -s "$secret_file" ] || die "Compose secret 为空: $secret_file"
done

# 先做所有只读检查,尽量避免在环境明显不正确时留下半套安装。
[ -d "$HOST_ROOT/boot/config" ] || die "宿主 $HOST_ROOT/boot/config 不存在"
[ -f "$HOST_ROOT/boot/config/go" ] || die "宿主 $HOST_ROOT/boot/config/go 不存在"
[ -x "$HOST_ROOT/usr/local/sbin/plugin" ] || die "未找到 Unraid 官方插件命令"
[ -d "$HOST_ROOT/mnt/user" ] || die "未找到 Unraid /mnt/user"
[ -d "$HOST_ROOT/var/log" ] || die "未找到 Unraid /var/log"
if [ "$SIMULATE" = "0" ]; then
  [ -S "$HOST_ROOT/var/run/php-fpm.sock" ] || die "宿主 php-fpm.sock 不存在,compose-api 无法工作"
  nsenter -t 1 -m -u -i -n -p --root --wd=/ /bin/true \
    || die "无法进入宿主命名空间;setup 需要 pid: host 和显式授权的必要权限"
else
  info "模拟模式:跳过真实 php-fpm socket、nsenter 与 GraphQL 网络检查"
fi

# 在任何密码、插件或宿主文件写入之前验证上游和 key;失败保证现有环境不变。
if [ "$SIMULATE" = "0" ]; then
  GRAPHQL_WORK="$(mktemp -d)"
  trap 'rm -rf "$GRAPHQL_WORK"' EXIT
  printf 'Content-Type: application/json\nx-api-key: %s\n' "$(cat "$API_KEY_FILE")" \
    > "$GRAPHQL_WORK/headers"
  GRAPHQL_CODE="$(curl -sS --connect-timeout 5 --max-time 20 \
    -o "$GRAPHQL_WORK/response" -w '%{http_code}' \
    -H "@$GRAPHQL_WORK/headers" \
    --data '{"query":"query { info { os { hostname } } }"}' \
    "${UPSTREAM}/graphql")" || die "GraphQL 请求失败,请检查 UNRAID_UPSTREAM 和网络"
  [ "$GRAPHQL_CODE" = "200" ] || die "GraphQL 返回 HTTP $GRAPHQL_CODE"
  jq -e '.data.info.os.hostname | type == "string" and length > 0' "$GRAPHQL_WORK/response" >/dev/null \
    || die "GraphQL 未返回有效主机名,请检查 API key 权限"
  rm -rf "$GRAPHQL_WORK"
  trap - EXIT
fi

mkdir -p "$HOST_ROOT/mnt/cache" "$APPDATA_DIR/config" "$APPDATA_DIR/nginx" "$APPDATA_DIR/setup"
chown -R 101:101 "$APPDATA_DIR/config" "$APPDATA_DIR/nginx"
chmod 0700 "$APPDATA_DIR/config" "$APPDATA_DIR/nginx" "$APPDATA_DIR/setup"

ensure_password_file() {
  local user="$1"
  local secret_file="$2"
  local output="$3"
  local tmp="${output}.tmp.$$"
  if [ -s "$output" ] && htpasswd -vi "$output" "$user" < "$secret_file" >/dev/null 2>&1; then
    chown 101:101 "$output"
    chmod 0600 "$output"
    info "$user 密码未变化,保留现有哈希"
    return 0
  fi
  htpasswd -niBC 12 "$user" < "$secret_file" > "$tmp" \
    || die "生成 $user 密码哈希失败"
  chown 101:101 "$tmp"
  chmod 0600 "$tmp"
  mv "$tmp" "$output"
  info "$user 密码哈希已生成(明文不写入宿主配置)"
}

ensure_password_file davuser "$DAV_SECRET_FILE" "$APPDATA_DIR/nginx/.davpasswd"
ensure_password_file loguser "$LOG_SECRET_FILE" "$APPDATA_DIR/nginx/.logpasswd"

# 先把经过镜像构建审核的安装资产放到宿主持久目录,再在宿主命名空间执行。
STAGE_DIR="${HOST_ROOT}${HOST_STAGE_DIR}"
mkdir -p "$STAGE_DIR"
# FAT /boot 不支持完整 Unix 元数据,只复制内容,不把 cp -a 的元数据错误变成安装失败。
cp -R /opt/unraid-mobile/compose-api/. "$STAGE_DIR/"

if [ "$SIMULATE" = "1" ]; then
  UNRAID_HOST_ROOT="$HOST_ROOT" \
  UNRAID_API_KEY_FILE="$API_KEY_FILE" \
    bash "$STAGE_DIR/install-compose-api.sh" -y
else
  # 原始 key 只放宿主 tmpfs(/var/run),绝不写 FAT flash;trap 覆盖普通失败清理。
  install -m 0600 "$API_KEY_FILE" "${HOST_ROOT}${HOST_KEY_FILE}"
  cleanup_host_key() { rm -f "${HOST_ROOT}${HOST_KEY_FILE}"; }
  trap cleanup_host_key EXIT
  nsenter -t 1 -m -u -i -n -p --root --wd=/ \
    /usr/bin/env "UNRAID_API_KEY_FILE=${HOST_KEY_FILE}" \
    /bin/bash "${HOST_STAGE_DIR}/install-compose-api.sh" -y
  cleanup_host_key
  trap - EXIT
fi

for required in \
  "/boot/config/plugins/unraid-mobile/api.php" \
  "/boot/config/plugins/unraid-mobile/plugin-worker.php" \
  "/boot/config/plugins/unraid-mobile/apikey" \
  "/usr/local/emhttp/plugins/compose.manager/api.php"; do
  [ -f "${HOST_ROOT}${required}" ] || die "安装后校验失败,缺少 $required"
done
[ "$(grep -c '^# >>> unraid-mobile compose-api hook >>>$' "$HOST_ROOT/boot/config/go")" = "1" ] \
  || die "/boot/config/go 中恢复钩子数量不是 1"
# /boot 是 FAT 时 chmod/stat 的 Unix 权限不可依赖;验证哈希内容而非 stat=600。
grep -Eq '^sha256:[0-9a-f]{64}$' "$HOST_ROOT/boot/config/plugins/unraid-mobile/apikey" \
  || die "宿主 apikey 不是有效的 sha256 哈希"

# 只在首次安装时生成 serverUrl;重复执行不覆盖用户后来在 UI 中保存的配置。
if [ ! -f "$APPDATA_DIR/config/settings.json" ]; then
  jq -n --arg serverUrl "$SERVER_URL" '{serverUrl: $serverUrl}' \
    > "$APPDATA_DIR/config/settings.json"
  chown 101:101 "$APPDATA_DIR/config/settings.json"
  chmod 0600 "$APPDATA_DIR/config/settings.json"
fi
if [ ! -f "$APPDATA_DIR/config/server-routes.json" ]; then
  jq -n '{version: 1, routes: []}' > "$APPDATA_DIR/config/server-routes.json"
  chown 101:101 "$APPDATA_DIR/config/server-routes.json"
  chmod 0600 "$APPDATA_DIR/config/server-routes.json"
fi
for protected_file in "$APPDATA_DIR/config/settings.json" "$APPDATA_DIR/config/app-backup.json" "$APPDATA_DIR/config/server-routes.json"; do
  if [ -f "$protected_file" ]; then
    chown 101:101 "$protected_file"
    chmod 0600 "$protected_file"
  fi
done

jq -n \
  --arg version "${SETUP_VERSION:-dev}" \
  --arg completedAt "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" \
  --argjson simulated "$([ "$SIMULATE" = "1" ] && printf true || printf false)" \
  '{status: "complete", version: $version, completedAt: $completedAt, simulated: $simulated}' \
  > "$APPDATA_DIR/setup/last-report.json"
chmod 0600 "$APPDATA_DIR/setup/last-report.json"
info "安装完成;未在报告中记录 API key、License 或访问密码"
