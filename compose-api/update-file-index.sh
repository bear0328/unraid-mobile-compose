#!/bin/sh
# 【续 123】全盘文件名索引构建(宿主 cron 低峰跑,或 api.php PUT reindex 显式触发)
# find /mnt/user 会唤醒休眠盘 —— 所以只在计划时段/用户显式触发时跑;
# 产物落 /mnt/cache(SSD 常转),搜索时只读索引文件,阵列休眠盘零接触。
# 用法: update-file-index.sh
# 安装: install-compose-api.sh 拷到 /boot/config/plugins/unraid-mobile/ 并经 go 钩子注册 cron
set -u

# 产物落 prod 容器的 appdata 目录(物理在 cache 池,SSD 常转),搜索时只读索引文件,阵列休眠盘零接触。
INDEX_DIR="/mnt/cache/appdata/unraid-mobile"
INDEX_FILE="${INDEX_DIR}/file-index.tsv"
TMP_FILE="${INDEX_FILE}.tmp.$$"
LOCK_FILE="${INDEX_DIR}/.index-building"

mkdir -p "$INDEX_DIR"

# 防并发(cron 与手动 reindex 撞车):锁已存在且对应进程还活着就直接退出
if [ -f "$LOCK_FILE" ]; then
    old_pid=$(cat "$LOCK_FILE" 2>/dev/null || echo '')
    if [ -n "$old_pid" ] && kill -0 "$old_pid" 2>/dev/null; then
        echo "index build already running (pid $old_pid), skip" >&2
        exit 0
    fi
fi
echo $$ > "$LOCK_FILE"
trap 'rm -f "$LOCK_FILE" "$TMP_FILE"' EXIT INT TERM

# %p\t%y:打印完整路径 + 类型(d/f/l…),再 sed 剥掉 /mnt/user 前缀,与 api.php searchFiles 的解析格式一致
# 【续 124 修复】顶层软链份额(strm/appdata/isos 等 cache-only 份额在 /mnt/user 里是 -> ../cache/* 的软链)
# find 默认不跟随软链,整棵树会漏;每个顶层软链单独一次 find -H 跟随(只跟命令行指定的这层,
# 不跟深层内层软链,防逃逸/环路;份额名含空格也安全)
{
    timeout 600 find /mnt/user -path /mnt/user/.trash -prune -o -printf '%p\t%y\n' 2>/dev/null
    for e in /mnt/user/* /mnt/user/.[!.]*; do
        [ "$e" = /mnt/user/.trash ] && continue
        [ -L "$e" ] || continue
        timeout 600 find -H "$e" -printf '%p\t%y\n' 2>/dev/null
    done
} | sed 's|^/mnt/user/\?||' > "$TMP_FILE"

# 全空(超时/异常)不覆盖旧索引,保留上次的可用副本
if [ ! -s "$TMP_FILE" ]; then
    echo "index build produced empty output, keep old index" >&2
    exit 1
fi

mv -f "$TMP_FILE" "$INDEX_FILE"
chmod a+r "$INDEX_FILE"
# 【续 124】sidecar meta:条数/构建时间/字节数,indexstatus 端点读它而不扫 142MB 主文件
entries=$(wc -l < "$INDEX_FILE")
size=$(wc -c < "$INDEX_FILE" | tr -d ' ')
printf '{"entries":%s,"builtAt":%s,"sizeBytes":%s}\n' "$entries" "$(date +%s)" "$size" > "${INDEX_FILE}.meta"
chmod a+r "${INDEX_FILE}.meta"
echo "index updated: $entries entries"
