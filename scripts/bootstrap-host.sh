#!/bin/bash
# 在 Unraid 宿主校验 API key 后,复用幂等安装器部署 compose-api。
set -euo pipefail
set +x
umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INSTALLER="${UNRAID_MOBILE_INSTALLER:-${SCRIPT_DIR}/../compose-api/install-compose-api.sh}"
UPSTREAM="${UNRAID_UPSTREAM:-http://127.0.0.1}"
API_KEY_FILE="${UNRAID_API_KEY_FILE:-}"
ASSUME_YES=0
LOCAL_ONLY=0
HOST_ROOT="${UNRAID_HOST_ROOT:-}"
[ "$HOST_ROOT" = "/" ] && HOST_ROOT=""
HOST_ROOT="${HOST_ROOT%/}"
host_path() { printf '%s%s' "$HOST_ROOT" "$1"; }

info() { printf '[bootstrap] %s\n' "$*"; }
die() { printf '[bootstrap] ERROR: %s\n' "$*" >&2; exit 1; }
usage() {
  cat <<'EOF'
用法: bash scripts/bootstrap-host.sh [选项]

选项:
  --api-key-file FILE  从权限受控的文件读取 unRAID API key(也可用 UNRAID_API_KEY_FILE)
  --upstream URL       unRAID webGui 地址,默认 http://127.0.0.1(也可用 UNRAID_UPSTREAM)
  --local-only         安装器仅使用同目录文件,不联网下载后端或插件
  -y, --yes            跳过安装器的风险确认;不传时仍由安装器要求输入 YES
  -h, --help           显示帮助

未提供 key 文件或 UNRAID_API_KEY 时,会交互式读取且不回显。
EOF
}

while [ $# -gt 0 ]; do
  case "$1" in
    --api-key-file)
      [ $# -ge 2 ] || die "--api-key-file 需要文件路径"
      API_KEY_FILE="$2"
      shift 2
      ;;
    --upstream)
      [ $# -ge 2 ] || die "--upstream 需要 URL"
      UPSTREAM="$2"
      shift 2
      ;;
    --local-only) LOCAL_ONLY=1; shift ;;
    -y|--yes) ASSUME_YES=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) die "未知参数: $1(用 --help 看用法);为避免进入进程列表,不接受位置参数 key" ;;
  esac
done

# 只读预检全部先于 GraphQL 和安装器;宿主修改仍只由既有安装器执行。
[ "$(id -u)" = "0" ] || die "需要 root 运行"
[ -f "$INSTALLER" ] || die "找不到安装器: $INSTALLER"
[ -f "$(host_path /boot/config/go)" ] || die "找不到 Unraid 开机脚本: $(host_path /boot/config/go)"
command -v curl >/dev/null 2>&1 || die "需要 curl"
command -v php >/dev/null 2>&1 || die "需要 php"
case "$UPSTREAM" in
  http://*|https://*) ;;
  *) die "upstream 必须以 http:// 或 https:// 开头" ;;
esac
case "$UPSTREAM" in
  *$'\n'*|*$'\r'*|*' '*) die "upstream 不能包含空白或换行" ;;
esac
UPSTREAM="${UPSTREAM%/}"

API_KEY=""
if [ -n "$API_KEY_FILE" ]; then
  [ -f "$API_KEY_FILE" ] && [ -r "$API_KEY_FILE" ] || die "API key 文件不可读: $API_KEY_FILE"
  [ -s "$API_KEY_FILE" ] || die "API key 文件为空: $API_KEY_FILE"
  API_KEY="$(cat "$API_KEY_FILE")"
elif [ -n "${UNRAID_API_KEY:-}" ]; then
  API_KEY="$UNRAID_API_KEY"
else
  read -r -s -p "输入 unRAID API 密钥(不回显): " API_KEY
  printf '\n'
fi
[ -n "$API_KEY" ] || die "API key 为空"

WORK_DIR="$(mktemp -d "${TMPDIR:-/tmp}/unraid-mobile-bootstrap.XXXXXX")"
cleanup() { rm -rf "$WORK_DIR"; }
trap cleanup EXIT
if [ -z "$API_KEY_FILE" ]; then
  API_KEY_FILE="$WORK_DIR/api-key"
  printf '%s' "$API_KEY" > "$API_KEY_FILE"
fi

# key 只经 header 文件传递;无效 key 在插件安装和风险确认之前直接中止。
printf 'Content-Type: application/json\nx-api-key: %s\n' "$API_KEY" > "$WORK_DIR/headers"
if ! GRAPHQL_CODE="$(curl -sS --connect-timeout 5 --max-time 20 \
  -o "$WORK_DIR/response" -w '%{http_code}' \
  -H "@$WORK_DIR/headers" \
  --data '{"query":"query { info { os { hostname } } }"}' \
  "${UPSTREAM}/graphql")"; then
  die "GraphQL 请求失败,请检查 upstream 和网络"
fi
[ "$GRAPHQL_CODE" = "200" ] || die "GraphQL 返回 HTTP $GRAPHQL_CODE"
php -r '$json = json_decode(stream_get_contents(STDIN), true); $host = $json["data"]["info"]["os"]["hostname"] ?? null; exit(is_string($host) && $host !== "" ? 0 : 1);' \
  < "$WORK_DIR/response" || die "GraphQL 未返回有效主机名,请检查 API key 权限"
rm -f "$WORK_DIR/headers" "$WORK_DIR/response"
info "GraphQL 与 API key 校验通过"

INSTALLER_ARGS=()
[ "$ASSUME_YES" = "1" ] && INSTALLER_ARGS+=("-y")
[ "$LOCAL_ONLY" = "1" ] && INSTALLER_ARGS+=("--local-only")
UNRAID_API_KEY_FILE="$API_KEY_FILE" bash "$INSTALLER" "${INSTALLER_ARGS[@]}"
