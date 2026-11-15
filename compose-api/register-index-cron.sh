#!/bin/sh
# 【续 125】全盘索引 cron 注册/摘除(单一逻辑出处)
# 调用方:install-compose-api.sh(安装时)/ go 钩子(重启后 /var/spool/cron tmpfs 丢失重注册)
#        / api.php PUT indexcfg(开关/改整点后刷新)
# 逻辑:先无条件摘旧行;index-enabled flag 存在则按 index-hour(0-23,缺省 3)整点重注册
set -u
PLUGIN_DIR="/boot/config/plugins/unraid-mobile"
FLAG="${PLUGIN_DIR}/index-enabled"
HOUR_FILE="${PLUGIN_DIR}/index-hour"
BUILDER="${PLUGIN_DIR}/update-file-index.sh"

(crontab -l 2>/dev/null | grep -v 'update-file-index\.sh') | crontab - 2>/dev/null || true

if [ -f "$FLAG" ] && [ -f "$BUILDER" ]; then
    hour=$(cat "$HOUR_FILE" 2>/dev/null || echo '')
    case "$hour" in
        ''|*[!0-9]*) hour=3 ;;
    esac
    # 去掉前导零(部分 cron 实现对 08/09 敏感),>23 回退 3
    hour=$(printf '%d' "$hour" 2>/dev/null || echo 3)
    [ "$hour" -le 23 ] 2>/dev/null || hour=3
    (crontab -l 2>/dev/null; echo "0 $hour * * * $BUILDER # unraid-mobile 文件索引") | crontab - 2>/dev/null || true
fi
